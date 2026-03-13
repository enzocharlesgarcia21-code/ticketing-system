<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 60);
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;

$sql = "SELECT id, name, email FROM users WHERE department = 'IT' AND role = 'employee'";
$params = [];
$types = '';
if ($q !== '') {
    $term = $q . '%';
    $sql .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = $term;
    $params[] = $term;
    $types .= 'ss';
}
$sql .= " ORDER BY name ASC LIMIT ?";
$params[] = $limit;
$types .= 'i';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$bind = [];
$bind[] = $types;
foreach ($params as $k => $p) {
    $bind[] = &$params[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$res = $stmt->get_result();

$employees = [];
while ($row = $res->fetch_assoc()) {
    $employees[] = [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'employees' => $employees]);
