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

if (!isset($_POST['ticket_id']) || !isset($_POST['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Parameters']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];
$message = trim($_POST['message']);
$sender_id = $_SESSION['user_id'];
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

$ticket = null;
$ticketStmt = $conn->prepare("SELECT id, user_id, assigned_user_id, assigned_to, assigned_department, assigned_group, assigned_company, company, subject, priority, status FROM employee_tickets WHERE id = ? LIMIT 1");
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
    $reloadStmt = $conn->prepare("SELECT id, user_id, assigned_user_id, assigned_to, assigned_department, assigned_group, assigned_company, company, subject, priority, status FROM employee_tickets WHERE id = ? LIMIT 1");
    if ($reloadStmt) {
        $reloadStmt->bind_param("i", $ticket_id);
        $reloadStmt->execute();
        $reloadRes = $reloadStmt->get_result();
        $reloadedTicket = $reloadRes ? $reloadRes->fetch_assoc() : null;
        $reloadStmt->close();
        if ($reloadedTicket) $ticket = $reloadedTicket;
    }
}
$handlerId = (int) ($ticket['assigned_to'] ?? 0);
$fallbackAssigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
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

// Insert Message
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_read) VALUES (?, ?, ?, 0)");
$stmt->bind_param("iis", $ticket_id, $sender_id, $message);

if ($stmt->execute()) {
    $stmt->close();
    if ($sender_id === $handlerId) {
        ticket_promote_status_on_first_handler_reply($conn, $ticket_id, $sender_id);
    }
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
        $recipientId = $handlerId > 0 ? $handlerId : $fallbackAssigneeId;
    } else {
        $recipientId = $requesterId;
    }

    exit;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>
