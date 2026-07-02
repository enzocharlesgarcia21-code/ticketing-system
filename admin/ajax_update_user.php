<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/user_permissions.php';
require_once '../includes/activity_logger.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin' || !user_permissions_can_manage($conn)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the super admin can edit users.']);
    exit;
}

csrf_validate();

function edit_user_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function edit_user_has_column(mysqli $conn, string $column): bool
{
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    $res = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($safe) . "'");
    return $res && $res->num_rows > 0;
}

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$companyInput = trim((string) ($_POST['company'] ?? ''));
$department = trim((string) ($_POST['department'] ?? ''));

if ($id <= 0) edit_user_json_error('Invalid user id.');
if ($name === '') edit_user_json_error('Name is required.');
if (preg_match('/\d/', $name) || !preg_match("/^(?=.{2,100}$)[A-Za-z][A-Za-z .,'-]*[A-Za-z.]$/", $name)) {
    edit_user_json_error('Please enter a valid name using letters only.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    edit_user_json_error('Please enter a valid email address.');
}

$domain = '@' . strtolower((string) substr(strrchr($email, '@') ?: '', 1));
$allowedDomains = [
    '@gpsci.net',
    '@farmasee.ph',
    '@gmail.com',
    '@leads-eh.com',
    '@leads-farmex.com',
    '@leadsagri.com',
    '@leadsanimalhealth.com',
    '@leadsav.com',
    '@leadstech-corp.com',
    '@lingapleads.org',
    '@malvedaholdings.com',
    '@primestocks.ph',
    '@malvedaproperties.com'
];
if (!in_array($domain, $allowedDomains, true)) {
    edit_user_json_error('Invalid email domain.');
}

$companyOptions = ticket_request_company_options();
$company = ticket_normalize_company($companyInput);
if ($company === '' || !array_key_exists($company, $companyOptions)) {
    edit_user_json_error('Invalid subsidiary selected.');
}

$departmentMap = ticket_company_group_map();
$departmentCompany = '';
if ($company === '@leadsagri.com' || $company === 'LAPC') {
    $departmentCompany = $company;
} elseif ($company === '@malvedaholdings.com' || $company === 'MHC') {
    $departmentCompany = $company;
}
$allowedDepartments = array_values(array_map('strval', $departmentCompany !== '' ? ($departmentMap[$departmentCompany] ?? []) : []));
if (count($allowedDepartments) > 0) {
    if ($department === '') {
        edit_user_json_error('Department is required.');
    }
    if (!in_array($department, $allowedDepartments, true)) {
        edit_user_json_error('Invalid department selected for this subsidiary.');
    }
} else {
    $department = '';
}

$existingStmt = $conn->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
if (!$existingStmt) edit_user_json_error('System error.', 500);
$existingStmt->bind_param("i", $id);
$existingStmt->execute();
$existingRes = $existingStmt->get_result();
$existing = $existingRes ? $existingRes->fetch_assoc() : null;
$existingStmt->close();
if (!$existing) edit_user_json_error('User not found.', 404);

$dupEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
if (!$dupEmail) edit_user_json_error('System error.', 500);
$dupEmail->bind_param("si", $email, $id);
$dupEmail->execute();
$dupEmailRes = $dupEmail->get_result();
$dupEmailRow = $dupEmailRes ? $dupEmailRes->fetch_assoc() : null;
$dupEmail->close();
if ($dupEmailRow) edit_user_json_error('This email is already used by another account.');

$dupName = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND id <> ? LIMIT 1");
if (!$dupName) edit_user_json_error('System error.', 500);
$dupName->bind_param("si", $name, $id);
$dupName->execute();
$dupNameRes = $dupName->get_result();
$dupNameRow = $dupNameRes ? $dupNameRes->fetch_assoc() : null;
$dupName->close();
if ($dupNameRow) edit_user_json_error('This name is already used by another account.');

$username = substr($email, 0, strpos($email, '@'));
$sets = ['name = ?', 'email = ?', 'company = ?', 'department = ?'];
$types = 'ssss';
$params = [$name, $email, $company, $department];
if (edit_user_has_column($conn, 'full_name')) {
    $sets[] = 'full_name = ?';
    $types .= 's';
    $params[] = $name;
}
if (edit_user_has_column($conn, 'username')) {
    $sets[] = 'username = ?';
    $types .= 's';
    $params[] = $username;
}
$types .= 'i';
$params[] = $id;

$sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
$update = $conn->prepare($sql);
if (!$update) edit_user_json_error('System error.', 500);
$bind = [$types];
foreach ($params as $k => $p) $bind[] = &$params[$k];
call_user_func_array([$update, 'bind_param'], $bind);
if (!$update->execute()) {
    $update->close();
    edit_user_json_error('Failed to update user.', 500);
}
$update->close();

if ((int) ($_SESSION['user_id'] ?? 0) === $id) {
    $_SESSION['name'] = $name;
    $_SESSION['email'] = $email;
    $_SESSION['company'] = $company;
    $_SESSION['department'] = $department;
}

activity_log($conn, $id, 'PROFILE_UPDATED', 'Profile information updated by super admin', 'Admin Management', $id);

echo json_encode([
    'ok' => true,
    'message' => 'User updated successfully.',
    'user' => [
        'id' => $id,
        'name' => $name,
        'email' => $email,
        'department' => $department,
    ],
]);
