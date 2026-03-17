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
$company = trim((string) ($_GET['company'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 50);
$page = (int) ($_GET['page'] ?? 1);
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;
$page = $page < 1 ? 1 : $page;
$offset = ($page - 1) * $limit;
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

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
    $deptKey = strtoupper($department);
    $aliasMap = [
        'ACCOUNTING' => ['ACCOUNTING'],
        'ADMIN' => ['ADMIN', 'ADMINISTRATION'],
        'E-COMM' => ['E-COMM', 'E-COMMERCE', 'ECOMM', 'E COMMERCE'],
        'HR' => ['HR', 'HUMAN RESOURCE', 'HUMAN RESOURCES', 'HUMAN RESOURCE AND TRANSFORMATION'],
        'IT' => ['IT'],
        'LINGAP' => ['LINGAP', 'DIAGNOSTICS / LINGAP', 'DIAGNOSTICS/LINGAP'],
        'MARKETING' => ['MARKETING'],
        'SUPPLY CHAIN' => ['SUPPLY CHAIN', 'LOGISTICS'],
        'TECHNICAL' => ['TECHNICAL'],
    ];
    $aliases = $aliasMap[$deptKey] ?? [$deptKey];
    $placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $where[] = "UPPER(department) IN ($placeholders)";
    foreach ($aliases as $a) {
        $params[] = strtoupper($a);
        $types .= 's';
    }
}

if ($role !== '' && $role !== 'all') {
    $where[] = "role = ?";
    $params[] = $role;
    $types .= 's';
}

if ($company !== '' && $company !== 'all') {
    if (strpos($company, '@') === 0) {
        $where[] = "LOWER(email) LIKE ?";
        $params[] = '%' . strtolower($company);
        $types .= 's';
    } else {
        $where[] = "company = ?";
        $params[] = $company;
        $types .= 's';
    }
}

$countSql = "SELECT COUNT(*) AS total FROM users";
$sql = "SELECT id, name, email, department, role FROM users";
if (count($where) > 0) {
    $whereSql = " WHERE " . implode(" AND ", $where);
    $countSql .= $whereSql;
    $sql .= $whereSql;
}
$sql .= " ORDER BY (id = ?) DESC, name ASC LIMIT ? OFFSET ?";

$totalUsers = 0;
if ($countStmt = $conn->prepare($countSql)) {
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
    $totalUsers = (int) ($countRow['total'] ?? 0);
    $countStmt->close();
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$params[] = $currentUserId;
$types .= 'i';
$params[] = $limit;
$types .= 'i';
$params[] = $offset;
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
        'email' => (string) ($row['email'] ?? ''),
        'department' => (string) ($row['department'] ?? ''),
        'role' => (string) ($row['role'] ?? ''),
    ];
}
$stmt->close();

$totalPages = $limit > 0 ? (int) ceil($totalUsers / $limit) : 1;
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;

echo json_encode([
    'ok' => true,
    'users' => $users,
    'total_users' => $totalUsers,
    'page' => $page,
    'limit' => $limit,
    'total_pages' => $totalPages
]);
