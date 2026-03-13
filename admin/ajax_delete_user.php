<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

csrf_validate();

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    json_error('Invalid user id.');
}

$currentId = (int) ($_SESSION['user_id'] ?? 0);
if ($currentId > 0 && $id === $currentId) {
    json_error('You cannot delete your own account.');
}

$check = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
if (!$check) {
    json_error('System error.', 500);
}
$check->bind_param("i", $id);
$check->execute();
$res = $check->get_result();
$row = $res ? $res->fetch_assoc() : null;
$check->close();
if (!$row) {
    json_error('User not found.', 404);
}

if (($row['role'] ?? '') === 'admin') {
    json_error('Cannot delete an admin user.');
}

$del = $conn->prepare("DELETE FROM users WHERE id = ?");
if (!$del) {
    json_error('System error.', 500);
}
$del->bind_param("i", $id);
if (!$del->execute()) {
    $del->close();
    json_error('Failed to delete user.', 500);
}
$del->close();

echo json_encode(['ok' => true, 'message' => 'User deleted']);

