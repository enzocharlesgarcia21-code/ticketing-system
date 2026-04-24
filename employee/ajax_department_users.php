<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$company = ticket_normalize_company(trim((string) ($_GET['company'] ?? '')));
$department = trim((string) ($_GET['department'] ?? ''));

if ($company === '' || $department === '') {
    echo json_encode(['ok' => true, 'users' => []]);
    exit;
}

if (!ticket_is_valid_company($company) || !ticket_is_valid_group_for_company($company, $department)) {
    echo json_encode(['ok' => true, 'users' => []]);
    exit;
}

$currentDepartmentKey = ticket_department_key_from_value((string) ($_SESSION['department'] ?? ''));
$requestedDepartmentKey = ticket_department_key_from_value($department);
if ($currentDepartmentKey === '' || $requestedDepartmentKey === '' || $currentDepartmentKey !== $requestedDepartmentKey) {
    echo json_encode(['ok' => true, 'users' => []]);
    exit;
}

$users = ticket_find_department_user_options($conn, $company, $department);

echo json_encode([
    'ok' => true,
    'users' => array_values($users),
]);
