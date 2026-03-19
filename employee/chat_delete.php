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

if (!isset($_POST['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Ticket ID']);
    exit;
}

$ticket_id = (int) $_POST['ticket_id'];
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);

$ticket = null;
$stmt = $conn->prepare("SELECT user_id, assigned_user_id FROM employee_tickets WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $stmt->close();
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

$delete = $conn->prepare("DELETE FROM ticket_messages WHERE ticket_id = ?");
if (!$delete) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to prepare delete']);
    exit;
}

$delete->bind_param("i", $ticket_id);
if (!$delete->execute()) {
    $delete->close();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete chat']);
    exit;
}
$delete->close();

echo json_encode(['success' => true]);
?>
