<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    header('Location: employee_login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'employee')));
if (!in_array($mode, ['employee', 'manager'], true)) {
    $mode = 'employee';
}

$company = trim((string) ($_SESSION['company'] ?? ''));
$department = trim((string) ($_SESSION['department'] ?? ''));
$region = trim((string) ($_SESSION['region'] ?? ''));
$hasRegionColumn = false;
$regionColumnRes = $conn->query("SHOW COLUMNS FROM users LIKE 'region'");
if ($regionColumnRes && $regionColumnRes->num_rows > 0) {
    $hasRegionColumn = true;
}

$stmt = $conn->prepare("SELECT company, department" . ($hasRegionColumn ? ", region" : "") . " FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) {
        $company = trim((string) ($row['company'] ?? $company));
        $department = trim((string) ($row['department'] ?? $department));
        $region = $hasRegionColumn ? trim((string) ($row['region'] ?? $region)) : $region;
        $_SESSION['company'] = $company;
        $_SESSION['department'] = $department;
        $_SESSION['region'] = $region;
    }
}

$eligible = ticket_normalize_company($company) === '@leadsagri.com'
    && strcasecmp($department, 'Sales') === 0
    && $region !== '';

$_SESSION['employee_view_mode'] = ($eligible && $mode === 'manager') ? 'manager' : 'employee';

$fallback = $_SESSION['employee_view_mode'] === 'manager' ? 'dashboard.php' : 'dashboard.php';
$returnTo = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
$path = $fallback;
if ($returnTo !== '') {
    $parts = parse_url($returnTo);
    $basename = basename((string) ($parts['path'] ?? ''));
    $allowed = ['dashboard.php', 'my_tickets.php', 'my_task.php', 'request_ticket.php', 'feedback.php', 'knowledge_base.php', 'analytics.php', 'sales_submitted_tickets.php', 'my_profile.php'];
    if (in_array($basename, $allowed, true)) {
        $path = $basename;
    }
}

if ($_SESSION['employee_view_mode'] === 'manager' && in_array($path, ['my_tickets.php', 'my_task.php', 'request_ticket.php', 'feedback.php', 'knowledge_base.php'], true)) {
    $path = 'dashboard.php';
}
if ($_SESSION['employee_view_mode'] === 'employee' && $path === 'sales_submitted_tickets.php') {
    $path = 'dashboard.php';
}

header('Location: ' . $path);
exit;
