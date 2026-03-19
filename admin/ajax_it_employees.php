<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 3);
$page = (int) ($_GET['page'] ?? 1);
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$baseWhere = " FROM users WHERE department = 'IT' AND role = 'employee'";
$params = [];
$types = '';
if ($q !== '') {
    $term = $q . '%';
    $baseWhere .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = $term;
    $params[] = $term;
    $types .= 'ss';
}

$countSql = "SELECT COUNT(*) AS total" . $baseWhere;
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

if ($types !== '') {
    $bind0 = [];
    $bind0[] = $types;
    foreach ($params as $k => $p) {
        $bind0[] = &$params[$k];
    }
    call_user_func_array([$countStmt, 'bind_param'], $bind0);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
$countRow = $countRes ? $countRes->fetch_assoc() : null;
$countStmt->close();
$total = (int) ($countRow['total'] ?? 0);
$totalPages = $limit > 0 ? (int) ceil($total / $limit) : 1;
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$sql = "SELECT id, name, email" . $baseWhere . " ORDER BY name ASC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$params2 = $params;
$types2 = $types;
$params2[] = $limit;
$types2 .= 'i';
$params2[] = $offset;
$types2 .= 'i';

$bind = [];
$bind[] = $types2;
foreach ($params2 as $k => $p) {
    $bind[] = &$params2[$k];
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

echo json_encode([
    'ok' => true,
    'employees' => $employees,
    'total_employees' => $total,
    'page' => $page,
    'limit' => $limit,
    'total_pages' => $totalPages
]);
