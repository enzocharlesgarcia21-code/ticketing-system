<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

csrf_validate();

ticket_ensure_assignment_columns($conn);
ticket_ensure_chat_tables($conn);

$current_user_id = $_SESSION['user_id'];
$is_admin = (($_SESSION['role'] ?? '') === 'admin');
$userContext = ticket_build_user_context($conn, $current_user_id, $_SESSION);
$current_user_email = strtolower(trim((string) ($userContext['email'] ?? '')));

function admin_clear_hr_chat_reminder(mysqli $conn, int $userId, int $ticketId): void
{
    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ?
          AND ticket_id = ?
          AND type = 'hr_chat_pending'
          AND is_read = 0
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("ii", $userId, $ticketId);
    $stmt->execute();
    $stmt->close();
}

if (isset($_POST['action']) && $_POST['action'] === 'conversations') {
    $sql = "
        SELECT
            t.id,
            t.subject,
            t.category,
            t.user_id,
            t.assigned_user_id,
            t.assigned_to,
            t.status,
            t.assigned_company,
            t.assigned_group,
            t.assigned_department,
            t.company,
            assignee.name AS assignee_name,
            assignee.email AS assignee_email,
            assignee.department AS assignee_department,
            handler.name AS assigned_to_name,
            MAX(tm.created_at) AS last_message_time,
            COALESCE(SUM(CASE WHEN tm.id IS NOT NULL AND tm.is_read = 0 AND tm.sender_id <> ? THEN 1 ELSE 0 END), 0) AS unread_count,
            SUBSTRING_INDEX(GROUP_CONCAT(tm.message ORDER BY tm.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_message,
            SUBSTRING_INDEX(GROUP_CONCAT(u.name ORDER BY tm.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_sender_name,
            MAX(t.created_at) AS ticket_created_at
        FROM employee_tickets t
        LEFT JOIN ticket_messages tm ON t.id = tm.ticket_id
        LEFT JOIN users u ON tm.sender_id = u.id
        LEFT JOIN users requester ON t.user_id = requester.id
        LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
        LEFT JOIN users handler ON handler.id = t.assigned_to
    ";
    $params = [$current_user_id];
    $types = 'i';

    if ($is_admin) {
        $sql .= " WHERE EXISTS (SELECT 1 FROM ticket_messages tm2 WHERE tm2.ticket_id = t.id) ";
    } else {
        $sql .= " WHERE (t.user_id = ? OR t.assigned_to = ? ";
        $params[] = $current_user_id;
        $types .= 'i';
        $params[] = $current_user_id;
        $types .= 'i';
        if ($current_user_email !== '') {
            $sql .= " OR LOWER(COALESCE(NULLIF(t.requester_email, ''), requester.email, '')) = ? ";
            $params[] = $current_user_email;
            $types .= 's';
        }
        $sql .= ") ";
    }

    $sql .= "
        GROUP BY t.id, t.subject, t.category, t.user_id, t.assigned_user_id, t.assigned_to, t.status, t.assigned_company, t.assigned_group, t.assigned_department, t.company, assignee.name, assignee.email, assignee.department, handler.name
        HAVING COUNT(tm.id) > 0
        ORDER BY COALESCE(MAX(tm.created_at), MAX(t.created_at)) DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to prepare query: ' . (string) $conn->error]);
        exit;
    }
    if ($types !== '') {
        $bind = [];
        $bind[] = $types;
        foreach ($params as $k => $p) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $ticketRow = ticket_chat_apply_effective_handler($r);
        $canChat = ticket_user_can_chat($ticketRow, $current_user_id, $userContext);
        $category = trim((string) ($r['category'] ?? ''));
        $assignedCompany = strtolower(trim((string) ($r['assigned_company'] ?? '')));
        $assignedGroup = trim((string) ($r['assigned_group'] ?? ($r['assigned_department'] ?? '')));
        $subjectDisplay = ($assignedCompany === '@leadsagri.com'
            && $assignedGroup === 'HR'
            && in_array($category, ['Leave Concern', 'Others'], true))
            ? $category
            : (string) ($r['subject'] ?? '');
        $rows[] = [
            'id' => (int) $r['id'],
            'subject' => (string) $r['subject'],
            'subject_display' => $subjectDisplay,
            'status' => (string) ($r['status'] ?? ''),
            'last_message_time' => (string) $r['last_message_time'],
            'ticket_created_at' => (string) $r['ticket_created_at'],
            'unread_count' => $canChat ? (int) $r['unread_count'] : 0,
            'last_message' => $canChat ? (string) $r['last_message'] : '',
            'last_sender_name' => $canChat ? (string) $r['last_sender_name'] : '',
            'can_chat' => $canChat,
            'chat_locked_message' => $canChat ? '' : "You can't message. This ticket is already assigned."
        ];
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_POST['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Ticket ID']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];

// Access Control: only requester and assigned user
$ticket = null;
$check = $conn->prepare("
    SELECT
        t.user_id,
        t.assigned_user_id,
        t.assigned_to,
        t.status,
        t.assigned_department,
        t.assigned_group,
        t.assigned_company,
        t.company,
        assignee.name AS assignee_name,
        assignee.email AS assignee_email,
        assignee.department AS assignee_department,
        handler.name AS assigned_to_name
    FROM employee_tickets t
    LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
    LEFT JOIN users handler ON handler.id = t.assigned_to
    WHERE t.id = ? LIMIT 1
");
if ($check) {
    $check->bind_param("i", $ticket_id);
    $check->execute();
    $res = $check->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $check->close();
}
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
    exit;
}
$ticket = ticket_chat_apply_effective_handler($ticket);
$requesterId = (int) ($ticket['user_id'] ?? 0);
$handlerId = ticket_chat_effective_handler_id($ticket);
$handlerName = trim((string) ($ticket['assigned_to_name'] ?? ''));
if (!ticket_user_can_chat($ticket, $current_user_id, $userContext)) {
    http_response_code(403);
    echo json_encode([
        'error' => ($handlerId > 0
            ? ('This ticket is already assigned to ' . ($handlerName !== '' ? $handlerName : 'another IT staff') . '.')
            : 'You are not allowed to access this chat.')
    ]);
    exit;
}

$canManageChat = ticket_user_can_chat($ticket, $current_user_id, $userContext);
$isRequester = ticket_user_matches_requester($ticket, $current_user_id, $userContext);
$isCurrentAssignee = ((int) ($ticket['assigned_to'] ?? 0) === $current_user_id)
    || ((int) ($ticket['assigned_user_id'] ?? 0) === $current_user_id);
$hasSentInConversation = false;
$msgPermStmt = $conn->prepare("SELECT id FROM ticket_messages WHERE ticket_id = ? AND sender_id = ? LIMIT 1");
if ($msgPermStmt) {
    $msgPermStmt->bind_param("ii", $ticket_id, $current_user_id);
    $msgPermStmt->execute();
    $msgPermRes = $msgPermStmt->get_result();
    $hasSentInConversation = (bool) ($msgPermRes && $msgPermRes->fetch_assoc());
    $msgPermStmt->close();
}
$canDeleteAnyMessage = $is_admin || $canManageChat || $isRequester || $isCurrentAssignee || $hasSentInConversation;

$mark = $conn->prepare("UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_id <> ? AND is_read = 0");
if ($mark) {
    $mark->bind_param("ii", $ticket_id, $current_user_id);
    $mark->execute();
    $mark->close();
}
admin_clear_hr_chat_reminder($conn, (int) $current_user_id, (int) $ticket_id);

// Fetch messages
$stmt = $conn->prepare("
    SELECT tm.id, tm.ticket_id, tm.sender_id, tm.message, tm.message_group_id, tm.attachment_stored_name, tm.attachment_original_name, tm.created_at, u.name as sender_name, u.role as sender_role
    FROM ticket_messages tm
    JOIN users u ON tm.sender_id = u.id
    WHERE tm.ticket_id = ?
    ORDER BY tm.created_at ASC
");

$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $isMine = ((int) $row['sender_id'] === $current_user_id);
    $messages[] = [
        'id' => $row['id'],
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'message_group_id' => (string) ($row['message_group_id'] ?? ''),
        'message' => $row['message'], // XSS protection should be handled on frontend or here. JSON is safe, but rendering needs care.
        'attachment' => !empty($row['attachment_stored_name']) ? [
            'stored_name' => (string) $row['attachment_stored_name'],
            'original_name' => (string) ($row['attachment_original_name'] ?? $row['attachment_stored_name']),
            'is_image' => ticket_chat_attachment_is_image((string) $row['attachment_stored_name']),
        ] : null,
        'created_at' => date('H:i', strtotime($row['created_at'])),
        'is_me' => $isMine,
        'can_edit' => ($is_admin || $isMine),
        'can_delete' => $canDeleteAnyMessage
    ];
}

echo json_encode($messages, JSON_UNESCAPED_UNICODE);
?>
