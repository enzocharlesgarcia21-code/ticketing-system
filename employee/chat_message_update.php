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
ticket_ensure_chat_tables($conn);
ticket_ensure_assignment_columns($conn);

$ticket_id = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
$message_id = isset($_POST['message_id']) ? (int) $_POST['message_id'] : 0;
$new_message = trim((string) ($_POST['message'] ?? ''));
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

if ($ticket_id <= 0 || $message_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$msgStmt = $conn->prepare("
    SELECT id, sender_id, message, attachment_stored_name, edited_at
    FROM ticket_messages
    WHERE id = ? AND ticket_id = ?
    LIMIT 1
");
if (!$msgStmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare message lookup']);
    exit;
}
$msgStmt->bind_param("ii", $message_id, $ticket_id);
$msgStmt->execute();
$msgRes = $msgStmt->get_result();
$messageRow = $msgRes ? $msgRes->fetch_assoc() : null;
$msgStmt->close();

if (!$messageRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Message not found']);
    exit;
}

$isMine = ((int) ($messageRow['sender_id'] ?? 0) === $current_user_id);
if (!$isMine) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

if (!empty($messageRow['edited_at'])) {
    http_response_code(409);
    echo json_encode(['error' => 'This message has already been edited.']);
    exit;
}

$hasAttachment = trim((string) ($messageRow['attachment_stored_name'] ?? '')) !== '';
if ($new_message === '' && !$hasAttachment) {
    http_response_code(400);
    echo json_encode(['error' => 'Message cannot be empty']);
    exit;
}

$old_message = (string) ($messageRow['message'] ?? '');
if ($new_message === $old_message) {
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'message' => $new_message,
        'is_edited' => !empty($messageRow['edited_at'] ?? null),
    ]);
    exit;
}

$upd = $conn->prepare("UPDATE ticket_messages SET message = ?, edited_at = NOW() WHERE id = ? AND ticket_id = ? LIMIT 1");
if (!$upd) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare update']);
    exit;
}
$upd->bind_param("sii", $new_message, $message_id, $ticket_id);
if (!$upd->execute()) {
    $upd->close();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update message']);
    exit;
}
$upd->close();

ticket_chat_record_message_edit($conn, $message_id, $ticket_id, $current_user_id, $old_message);

echo json_encode([
    'success' => true,
    'message_id' => $message_id,
    'message' => $new_message,
    'is_edited' => true,
]);
exit;
?>
