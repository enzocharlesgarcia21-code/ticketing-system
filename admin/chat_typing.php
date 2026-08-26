<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

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

$ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
$action = trim((string) ($_POST['action'] ?? 'status'));
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');

if ($ticketId <= 0 || $currentUserId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Ticket ID']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        t.user_id,
        t.assigned_user_id,
        t.assigned_to,
        t.assigned_department,
        t.assigned_group,
        t.assigned_company,
        t.company,
        t.department,
        t.description,
        t.status,
        t.started_at,
        requester.email AS created_by_email,
        requester.department AS user_department,
        handler.name AS assigned_to_name
    FROM employee_tickets t
    LEFT JOIN users requester ON requester.id = t.user_id
    LEFT JOIN users handler ON handler.id = t.assigned_to
    WHERE t.id = ? LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to check chat access']);
    exit;
}
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$res = $stmt->get_result();
$ticket = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
    exit;
}

$userContext = ticket_build_user_context($conn, $currentUserId, $_SESSION);
$ticket = ticket_chat_apply_effective_handler($ticket);
$canChat = $isAdmin || ticket_user_can_chat($ticket, $currentUserId, $userContext) || ticket_user_matches_requester($ticket, $currentUserId, $userContext);

if (!$canChat) {
    http_response_code(403);
    echo json_encode(['error' => 'You are not allowed to access this chat.']);
    exit;
}

$threadId = ticket_chat_current_thread_id($conn, $ticketId);

if ($action === 'update') {
    $upsert = $conn->prepare("
        INSERT INTO ticket_chat_typing (ticket_id, chat_thread_id, user_id, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE updated_at = NOW()
    ");
    if ($upsert) {
        $upsert->bind_param("iii", $ticketId, $threadId, $currentUserId);
        $upsert->execute();
        $upsert->close();
    }
    echo json_encode(['success' => true, 'typing' => false]);
    exit;
}

if ($action === 'clear') {
    $clear = $conn->prepare("DELETE FROM ticket_chat_typing WHERE ticket_id = ? AND chat_thread_id = ? AND user_id = ?");
    if ($clear) {
        $clear->bind_param("iii", $ticketId, $threadId, $currentUserId);
        $clear->execute();
        $clear->close();
    }
    echo json_encode(['success' => true, 'typing' => false]);
    exit;
}

$status = $conn->prepare("
    SELECT 1
    FROM ticket_chat_typing
    WHERE ticket_id = ?
      AND chat_thread_id = ?
      AND user_id <> ?
      AND updated_at >= (NOW() - INTERVAL 4 SECOND)
    LIMIT 1
");
$typing = false;
if ($status) {
    $status->bind_param("iii", $ticketId, $threadId, $currentUserId);
    $status->execute();
    $statusRes = $status->get_result();
    $typing = (bool) ($statusRes && $statusRes->fetch_row());
    $status->close();
}

echo json_encode(['success' => true, 'typing' => $typing]);
