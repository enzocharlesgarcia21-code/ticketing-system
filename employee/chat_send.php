<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

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

ticket_ensure_chat_tables($conn);
ticket_ensure_assignment_columns($conn);

if (!isset($_POST['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Parameters']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];
$message = trim((string) ($_POST['message'] ?? ''));
$sender_id = $_SESSION['user_id'];
$attachmentUpload = ticket_chat_store_attachment($_FILES['attachment'] ?? []);

if (empty($attachmentUpload['ok'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => (string) ($attachmentUpload['error'] ?? 'Unable to upload attachment')]);
    exit;
}
if ($message === '' && empty($attachmentUpload['has_file'])) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

function normalize_domain(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === '') {
        return '';
    }
    if ($v[0] !== '@') {
        $v = '@' . $v;
    }
    return $v;
}

function is_lapc_hr_ticket(array $ticket): bool
{
    $company = normalize_domain((string) ($ticket['assigned_company'] ?? ($ticket['company'] ?? '')));
    $group = trim((string) ($ticket['assigned_group'] ?? ($ticket['assigned_department'] ?? '')));
    return $company === '@leadsagri.com' && strcasecmp($group, 'HR') === 0;
}

$ticket = null;
$ticketStmt = $conn->prepare("
    SELECT
        t.id,
        t.user_id,
        t.assigned_user_id,
        t.assigned_to,
        t.assigned_department,
        t.assigned_group,
        t.assigned_company,
        t.company,
        t.subject,
        t.priority,
        t.status,
        COALESCE(NULLIF(t.requester_email, ''), requester.email) AS requester_email
    FROM employee_tickets t
    LEFT JOIN users requester ON requester.id = t.user_id
    WHERE t.id = ?
    LIMIT 1
");
if ($ticketStmt) {
    $ticketStmt->bind_param("i", $ticket_id);
    $ticketStmt->execute();
    $ticketRes = $ticketStmt->get_result();
    $ticket = $ticketRes ? $ticketRes->fetch_assoc() : null;
    $ticketStmt->close();
}
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
    exit;
}

$requesterId = (int) ($ticket['user_id'] ?? 0);
$userContext = ticket_build_user_context($conn, $sender_id, $_SESSION);
$isHandlerCandidate = ticket_user_is_handler_candidate($ticket, $sender_id, $userContext);
$ticketWasUnassigned = empty($ticket['assigned_to']);
if ($sender_id !== $requesterId && $ticketWasUnassigned && $isHandlerCandidate) {
    ticket_claim_first_handler_on_reply($conn, $ticket_id, $sender_id);
    $reloadStmt = $conn->prepare("
        SELECT
            t.id,
            t.user_id,
            t.assigned_user_id,
            t.assigned_to,
            t.assigned_department,
            t.assigned_group,
            t.assigned_company,
            t.company,
            t.subject,
            t.priority,
            t.status,
            COALESCE(NULLIF(t.requester_email, ''), requester.email) AS requester_email
        FROM employee_tickets t
        LEFT JOIN users requester ON requester.id = t.user_id
        WHERE t.id = ?
        LIMIT 1
    ");
    if ($reloadStmt) {
        $reloadStmt->bind_param("i", $ticket_id);
        $reloadStmt->execute();
        $reloadRes = $reloadStmt->get_result();
        $reloadedTicket = $reloadRes ? $reloadRes->fetch_assoc() : null;
        $reloadStmt->close();
        if ($reloadedTicket) $ticket = $reloadedTicket;
    }
}
$handlerId = ticket_chat_effective_handler_id($ticket);
$fallbackAssigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
if (!ticket_user_can_chat($ticket, $sender_id, $userContext)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to send messages']);
    exit;
}

if ($sender_id !== $requesterId && $sender_id !== $handlerId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to send messages']);
    exit;
}

// Insert Message
$storedAttachment = !empty($attachmentUpload['has_file']) ? (string) ($attachmentUpload['stored_name'] ?? '') : null;
$originalAttachment = !empty($attachmentUpload['has_file']) ? (string) ($attachmentUpload['original_name'] ?? '') : null;
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, attachment_stored_name, attachment_original_name, is_read) VALUES (?, ?, ?, ?, ?, 0)");
$stmt->bind_param("iisss", $ticket_id, $sender_id, $message, $storedAttachment, $originalAttachment);

if ($stmt->execute()) {
    $stmt->close();
    if ($sender_id === $handlerId) {
        ticket_promote_status_on_first_handler_reply($conn, $ticket_id, $sender_id);
    }
    ticket_record_chat_activity($conn, $ticket_id);
    echo json_encode([
        'success' => true,
        'attachment' => !empty($attachmentUpload['has_file']) ? [
            'stored_name' => $storedAttachment,
            'original_name' => $originalAttachment,
            'is_image' => !empty($attachmentUpload['is_image']),
        ] : null
    ]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }

    $recipientIds = [];
    if ($sender_id === $requesterId) {
        if (is_lapc_hr_ticket($ticket)) {
            $recipientIds = ticket_find_assignee_ids(
                $conn,
                (string) (($ticket['assigned_company'] ?? '') !== '' ? $ticket['assigned_company'] : ($ticket['company'] ?? '')),
                (string) (($ticket['assigned_group'] ?? '') !== '' ? $ticket['assigned_group'] : ($ticket['assigned_department'] ?? ''))
            );
        }
        if (count($recipientIds) === 0) {
            $recipientIds[] = $handlerId > 0 ? $handlerId : $fallbackAssigneeId;
        }
    } else {
        $recipientIds[] = $requesterId;
    }

    $recipientIds = array_values(array_filter(notif_unique_user_ids($recipientIds), static function ($id) use ($sender_id) {
        return (int) $id > 0 && (int) $id !== (int) $sender_id;
    }));

    if (count($recipientIds) > 0) {
        $senderInfo = notif_user_contact($conn, (int) $sender_id);
        $senderName = trim((string) ($senderInfo['name'] ?? ''));
        if ($senderName === '') {
            $senderName = 'Someone';
        }
        $ticketNumber = notif_ticket_number((int) $ticket_id);
        $messagePreview = trim($message);
        if ($messagePreview === '' && !empty($attachmentUpload['has_file'])) {
            $messagePreview = '[Attachment] ' . $originalAttachment;
        }
        if (function_exists('mb_strlen')) {
            if (mb_strlen($messagePreview) > 80) {
                $messagePreview = mb_substr($messagePreview, 0, 80) . '...';
            }
        } else {
            if (strlen($messagePreview) > 80) {
                $messagePreview = substr($messagePreview, 0, 80) . '...';
            }
        }
        foreach ($recipientIds as $recipientId) {
            notif_insert_system(
                $conn,
                (int) $recipientId,
                (int) $ticket_id,
                $senderName . ' sent a chat message on ticket #' . $ticketNumber . ': ' . $messagePreview,
                'chat_message',
                5,
                'update'
            );
        }

    }

    $clearHrReminder = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND ticket_id = ? AND type = 'hr_chat_pending' AND is_read = 0");
    if ($clearHrReminder) {
        $clearHrReminder->bind_param("ii", $sender_id, $ticket_id);
        $clearHrReminder->execute();
        $clearHrReminder->close();
    }

    exit;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>
