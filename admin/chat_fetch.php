<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json');

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

if (isset($_POST['action']) && $_POST['action'] === 'conversations') {
    $sql = "
        SELECT
            t.id,
            t.subject,
            MAX(tm.created_at) AS last_message_time,
            COALESCE(SUM(CASE WHEN tm.id IS NOT NULL AND tm.is_read = 0 AND tm.sender_id <> ? THEN 1 ELSE 0 END), 0) AS unread_count,
            SUBSTRING_INDEX(GROUP_CONCAT(tm.message ORDER BY tm.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_message,
            SUBSTRING_INDEX(GROUP_CONCAT(u.name ORDER BY tm.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_sender_name,
            MAX(t.created_at) AS ticket_created_at
        FROM employee_tickets t
        LEFT JOIN ticket_messages tm ON t.id = tm.ticket_id
        LEFT JOIN users u ON tm.sender_id = u.id
    ";
    $params = [$current_user_id];
    $types = 'i';

    $sql .= " WHERE (t.user_id = ? OR t.assigned_user_id = ?) ";
    $params[] = $current_user_id;
    $types .= 'i';
    $params[] = $current_user_id;
    $types .= 'i';

    $sql .= "
        GROUP BY t.id, t.subject
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
        $rows[] = [
            'id' => (int) $r['id'],
            'subject' => (string) $r['subject'],
            'last_message_time' => (string) $r['last_message_time'],
            'ticket_created_at' => (string) $r['ticket_created_at'],
            'unread_count' => (int) $r['unread_count'],
            'last_message' => (string) $r['last_message'],
            'last_sender_name' => (string) $r['last_sender_name']
        ];
    }
    echo json_encode($rows);
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
$check = $conn->prepare("SELECT user_id, assigned_user_id FROM employee_tickets WHERE id = ? LIMIT 1");
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
$requesterId = (int) ($ticket['user_id'] ?? 0);
$assigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
if ($current_user_id !== $requesterId && ($assigneeId <= 0 || $current_user_id !== $assigneeId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

$mark = $conn->prepare("UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_id <> ? AND is_read = 0");
if ($mark) {
    $mark->bind_param("ii", $ticket_id, $current_user_id);
    $mark->execute();
    $mark->close();
}

// Fetch messages
$stmt = $conn->prepare("
    SELECT tm.id, tm.ticket_id, tm.sender_id, tm.message, tm.created_at, u.name as sender_name, u.role as sender_role
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
    $messages[] = [
        'id' => $row['id'],
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'message' => $row['message'], // XSS protection should be handled on frontend or here. JSON is safe, but rendering needs care.
        'created_at' => date('H:i', strtotime($row['created_at'])),
        'is_me' => ($row['sender_id'] == $current_user_id)
    ];
}

echo json_encode($messages);
?>
