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
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

function chat_uploaded_files_from_field(string $field): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }
    $raw = $_FILES[$field];
    if (!is_array($raw['name'] ?? null)) {
        return [$raw];
    }
    $files = [];
    $count = count($raw['name']);
    for ($i = 0; $i < $count; $i++) {
        $files[] = [
            'name' => $raw['name'][$i] ?? '',
            'type' => $raw['type'][$i] ?? '',
            'tmp_name' => $raw['tmp_name'][$i] ?? '',
            'error' => $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $raw['size'][$i] ?? 0,
        ];
    }
    return $files;
}

function chat_store_uploaded_attachments(): array
{
    $files = chat_uploaded_files_from_field('attachments');
    if (count($files) === 0) {
        $files = chat_uploaded_files_from_field('attachment');
    }

    $stored = [];
    foreach ($files as $file) {
        $upload = ticket_chat_store_attachment($file);
        if (empty($upload['ok'])) {
            return ['ok' => false, 'error' => (string) ($upload['error'] ?? 'Unable to upload attachment')];
        }
        if (!empty($upload['has_file'])) {
            $stored[] = $upload;
        }
    }

    return ['ok' => true, 'attachments' => $stored];
}

$attachmentUpload = chat_store_uploaded_attachments();
$attachmentUploads = $attachmentUpload['attachments'] ?? [];
$hasAttachments = count($attachmentUploads) > 0;

if (empty($attachmentUpload['ok'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => (string) ($attachmentUpload['error'] ?? 'Unable to upload attachment')]);
    exit;
}
if ($message === '' && !$hasAttachments) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
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
$autoProgressStatusChanged = false;
$handlerId = ticket_chat_effective_handler_id($ticket);
$fallbackAssigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
if (ticket_chat_is_closed_by_status($ticket)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => ticket_chat_closed_status_message($ticket)]);
    exit;
}
if (!$is_admin && !ticket_user_can_chat($ticket, $sender_id, $userContext)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to send messages']);
    exit;
}
if ($is_admin && !ticket_user_can_chat($ticket, $sender_id, $userContext)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to send messages']);
    exit;
}
if ($sender_id !== $requesterId && $sender_id !== $handlerId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not allowed to send messages']);
    exit;
}

$messageGroupId = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : uniqid('chat_', true);
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, message_group_id, attachment_stored_name, attachment_original_name, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
    exit;
}
$insertRows = $hasAttachments ? $attachmentUploads : [null];
$insertedAll = true;
foreach ($insertRows as $index => $upload) {
    $rowMessage = ((int) $index === 0) ? $message : '';
    $storedAttachment = is_array($upload) ? (string) ($upload['stored_name'] ?? '') : null;
    $originalAttachment = is_array($upload) ? (string) ($upload['original_name'] ?? '') : null;
    $stmt->bind_param("iissss", $ticket_id, $sender_id, $rowMessage, $messageGroupId, $storedAttachment, $originalAttachment);
    if (!$stmt->execute()) {
        $insertedAll = false;
        break;
    }
}
$stmt->close();

if ($insertedAll) {
    if ($sender_id === $handlerId) {
        if (ticket_promote_status_on_first_handler_reply($conn, $ticket_id, $sender_id)) {
            $autoProgressStatusChanged = true;
        }
    }
    ticket_record_chat_activity($conn, $ticket_id);
    $responseAttachments = array_map(static function ($upload) {
        return [
            'stored_name' => (string) ($upload['stored_name'] ?? ''),
            'original_name' => (string) ($upload['original_name'] ?? ''),
            'is_image' => !empty($upload['is_image']),
        ];
    }, $attachmentUploads);
    echo json_encode([
        'success' => true,
        'attachments' => $responseAttachments,
        'attachment' => $responseAttachments[0] ?? null
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

    if ($autoProgressStatusChanged) {
        $senderInfoForStatus = notif_user_contact($conn, (int) $sender_id);
        $statusUpdatedBy = trim((string) ($senderInfoForStatus['department'] ?? ''));
        if ($statusUpdatedBy === '') {
            $statusUpdatedBy = trim((string) ($senderInfoForStatus['name'] ?? ''));
        }
        notif_send_ticket_status_update(
            $conn,
            (int) $ticket_id,
            'Open',
            'In Progress',
            $statusUpdatedBy
        );
    }

    $recipientId = 0;
    if ($sender_id === $requesterId) {
        $recipientId = $handlerId > 0 ? $handlerId : $fallbackAssigneeId;
    } else {
        $recipientId = $requesterId;
    }

    $clearHrReminder = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND ticket_id = ? AND type = 'hr_chat_pending' AND is_read = 0");
    if ($clearHrReminder) {
        $clearHrReminder->bind_param("ii", $sender_id, $ticket_id);
        $clearHrReminder->execute();
        $clearHrReminder->close();
    }

    if ($recipientId > 0 && $recipientId !== (int) $sender_id) {
        $senderInfo = notif_user_contact($conn, (int) $sender_id);
        $senderName = trim((string) ($senderInfo['name'] ?? ''));
        if ($senderName === '') {
            $senderName = 'Someone';
        }
        $ticketNumber = notif_ticket_number((int) $ticket_id);
        $messagePreview = trim($message);
        if ($messagePreview === '' && $hasAttachments) {
            $messagePreview = count($attachmentUploads) === 1
                ? '[Attachment] ' . (string) ($attachmentUploads[0]['original_name'] ?? '')
                : '[Attachments] ' . count($attachmentUploads) . ' files';
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

    exit;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>
