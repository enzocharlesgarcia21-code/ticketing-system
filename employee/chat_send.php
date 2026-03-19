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

if (!isset($_POST['ticket_id']) || !isset($_POST['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Parameters']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];
$message = trim($_POST['message']);
$sender_id = $_SESSION['user_id'];

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

function normalize_domain(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === '') return '';
    if ($v[0] !== '@') $v = '@' . $v;
    return $v;
}

$ticket = null;
$ticketStmt = $conn->prepare("SELECT id, user_id, assigned_user_id, subject, priority FROM employee_tickets WHERE id = ? LIMIT 1");
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
$assigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
if ($sender_id !== $requesterId && ($assigneeId <= 0 || $sender_id !== $assigneeId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

// Insert Message
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_read) VALUES (?, ?, ?, 0)");
$stmt->bind_param("iis", $ticket_id, $sender_id, $message);

if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => true]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }

    $recipientId = 0;
    if ($sender_id === $requesterId) {
        $recipientId = $assigneeId;
    } else {
        $recipientId = $requesterId;
    }

    exit;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>
