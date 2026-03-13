<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$department = trim((string) ($_GET['department'] ?? ''));
$role = trim((string) ($_GET['role'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 50);
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;

$where = [];
$params = [];
$types = '';

if ($q !== '') {
    $term = '%' . $q . '%';
    $where[] = "(name LIKE ? OR email LIKE ?)";
    $params[] = $term;
    $params[] = $term;
    $types .= 'ss';
}

if ($department !== '' && $department !== 'all') {
    $where[] = "department = ?";
    $params[] = $department;
    $types .= 's';
}

if ($role !== '' && $role !== 'all') {
    $where[] = "role = ?";
    $params[] = $role;
    $types .= 's';
}

$sql = "SELECT id, name, department, role FROM users";
if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY name ASC LIMIT ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$params[] = $limit;
$types .= 'i';

$bind = [];
$bind[] = $types;
foreach ($params as $k => $p) {
    $bind[] = &$params[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bind);

$stmt->execute();
$res = $stmt->get_result();
$users = [];
while ($row = $res->fetch_assoc()) {
    $users[] = [
        'id' => (int) ($row['id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'department' => (string) ($row['department'] ?? ''),
        'role' => (string) ($row['role'] ?? ''),
    ];
}
$stmt->close();

echo json_encode(['ok' => true, 'users' => $users]);

