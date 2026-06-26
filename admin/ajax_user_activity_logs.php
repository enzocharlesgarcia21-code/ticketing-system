<?php
require_once '../config/database.php';
require_once '../includes/activity_logger.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

activity_logs_ensure_table($conn);

$userId = (int) ($_GET['user_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = (int) ($_GET['limit'] ?? 10);
if (!in_array($limit, [10, 25, 50], true)) $limit = 10;
$offset = ($page - 1) * $limit;
$type = trim((string) ($_GET['type'] ?? 'all'));
$module = trim((string) ($_GET['module'] ?? 'all'));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid user id']);
    exit;
}

$hasSuperAdminCol = false;
$superRes = $conn->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
if ($superRes && $superRes->num_rows > 0) $hasSuperAdminCol = true;

$hasFullNameCol = false;
$fullNameRes = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
if ($fullNameRes && $fullNameRes->num_rows > 0) $hasFullNameCol = true;

$displayNameExpr = $hasFullNameCol
    ? "COALESCE(NULLIF(full_name,''), NULLIF(name,''), '')"
    : "COALESCE(NULLIF(name,''), '')";

$userSql = "SELECT id, {$displayNameExpr} AS display_name, email, department, role, created_at, last_seen_at, last_logout_at, COALESCE(is_online, 0) AS is_online";
if ($hasSuperAdminCol) {
    $userSql .= ", COALESCE(is_super_admin, 0) AS is_super_admin";
} else {
    $userSql .= ", 0 AS is_super_admin";
}
$userSql .= " FROM users WHERE id = ? LIMIT 1";

$userStmt = $conn->prepare($userSql);
if (!$userStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userRes = $userStmt->get_result();
$user = $userRes ? $userRes->fetch_assoc() : null;
$userStmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'User not found']);
    exit;
}

function activity_api_format_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('M d, Y g:i A', $ts) : '-';
}

function activity_api_status(array $user): array
{
    $lastSeen = trim((string) ($user['last_seen_at'] ?? ''));
    if ($lastSeen === '') return ['label' => 'Never opened', 'state' => 'never'];
    $lastSeenTs = strtotime($lastSeen);
    if (!$lastSeenTs) return ['label' => 'Unknown', 'state' => 'never'];
    $lastLogout = trim((string) ($user['last_logout_at'] ?? ''));
    $lastLogoutTs = $lastLogout !== '' ? strtotime($lastLogout) : false;
    $loggedOut = $lastLogoutTs !== false && $lastLogoutTs >= $lastSeenTs;
    $seconds = max(0, time() - $lastSeenTs);
    if ((int) ($user['is_online'] ?? 0) === 1 && !$loggedOut && $seconds <= 120) {
        return ['label' => 'Online', 'state' => 'online'];
    }
    if ($seconds < 60) return ['label' => 'Just now', 'state' => 'recent'];
    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) return ['label' => $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago', 'state' => 'recent'];
    $hours = (int) floor($minutes / 60);
    if ($hours < 24) return ['label' => $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago', 'state' => 'away'];
    $days = (int) floor($hours / 24);
    return ['label' => $days . ' day' . ($days === 1 ? '' : 's') . ' ago', 'state' => 'offline'];
}

$where = ['user_id = ?'];
$params = [$userId];
$types = 'i';

if ($type !== '' && $type !== 'all') {
    $where[] = 'activity_type = ?';
    $params[] = strtoupper($type);
    $types .= 's';
}
if ($module !== '' && $module !== 'all') {
    $where[] = 'module_name = ?';
    $params[] = $module;
    $types .= 's';
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo !== '') {
    $where[] = 'created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($search !== '') {
    $where[] = 'activity_description LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$whereSql = ' WHERE ' . implode(' AND ', $where);

$countSql = 'SELECT COUNT(*) AS total FROM activity_logs' . $whereSql;
$total = 0;
if ($countStmt = $conn->prepare($countSql)) {
    $bind = [$types];
    foreach ($params as $k => $p) $bind[] = &$params[$k];
    call_user_func_array([$countStmt, 'bind_param'], $bind);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $total = (int) (($countRes ? $countRes->fetch_assoc() : [])['total'] ?? 0);
    $countStmt->close();
}

$logsSql = 'SELECT id, activity_type, activity_description, module_name, reference_id, ip_address, created_at FROM activity_logs' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
$logsParams = $params;
$logsTypes = $types . 'ii';
$logsParams[] = $limit;
$logsParams[] = $offset;

$items = [];
if ($logsStmt = $conn->prepare($logsSql)) {
    $bind = [$logsTypes];
    foreach ($logsParams as $k => $p) $bind[] = &$logsParams[$k];
    call_user_func_array([$logsStmt, 'bind_param'], $bind);
    $logsStmt->execute();
    $logsRes = $logsStmt->get_result();
    while ($row = $logsRes ? $logsRes->fetch_assoc() : null) {
        if (!$row) break;
        $items[] = [
            'id' => (int) $row['id'],
            'activity_type' => (string) $row['activity_type'],
            'activity_description' => (string) $row['activity_description'],
            'module_name' => (string) $row['module_name'],
            'reference_id' => (string) ($row['reference_id'] ?? ''),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'created_at_display' => activity_api_format_datetime((string) $row['created_at']),
        ];
    }
    $logsStmt->close();
}

$firstLogin = '';
$lastLogin = '';
if ($loginStmt = $conn->prepare("SELECT MIN(created_at) AS first_login, MAX(created_at) AS last_login FROM activity_logs WHERE user_id = ? AND activity_type = 'LOGIN'")) {
    $loginStmt->bind_param("i", $userId);
    $loginStmt->execute();
    $loginRes = $loginStmt->get_result();
    $loginRow = $loginRes ? $loginRes->fetch_assoc() : [];
    $firstLogin = (string) ($loginRow['first_login'] ?? '');
    $lastLogin = (string) ($loginRow['last_login'] ?? '');
    $loginStmt->close();
}

$status = activity_api_status($user);
$role = (string) ($user['role'] ?? '');
$roleLabel = ((int) ($user['is_super_admin'] ?? 0) === 1) ? 'Super Admin' : ($role === 'admin' ? 'Admin' : 'Employee');

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int) $user['id'],
        'name' => (string) ($user['display_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'department' => (string) ($user['department'] ?? ''),
        'role' => $roleLabel,
        'status_label' => $status['label'],
        'status_state' => $status['state'],
    ],
    'summary' => [
        'account_created' => activity_api_format_datetime((string) ($user['created_at'] ?? '')),
        'first_login' => activity_api_format_datetime($firstLogin),
        'last_login' => activity_api_format_datetime($lastLogin),
        'last_active' => activity_api_format_datetime((string) ($user['last_seen_at'] ?? '')),
    ],
    'logs' => $items,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => max(1, (int) ceil($total / $limit)),
    ],
]);

