<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}
csrf_validate();

$ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
if (!$ticketId || $ticketId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid ticket']);
    exit;
}

$stmt = $conn->prepare('UPDATE employee_tickets SET is_read = 1 WHERE id = ?');
$stmt->bind_param('i', $ticketId);
$ok = $stmt->execute();
$stmt->close();
echo json_encode(['ok' => $ok]);
