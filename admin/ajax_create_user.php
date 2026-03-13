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

function ensure_users_columns(mysqli $conn): void
{
    $cols = [
        'full_name' => "VARCHAR(255) NULL",
        'username' => "VARCHAR(255) NULL",
    ];
    foreach ($cols as $col => $ddl) {
        $colRes = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
        if (!$colRes || $colRes->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col $ddl");
        }
    }
}

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$domain = trim((string) ($_POST['domain'] ?? '@leadsagri.com'));
$password = (string) ($_POST['password'] ?? '');

if ($fullName === '') {
    json_error('Full name is required.');
}

if ($username === '') {
    json_error('Username is required.');
}

$username = strtolower($username);
if (!preg_match('/^[a-z0-9._-]{2,64}$/', $username)) {
    json_error('Username must be 2–64 characters and contain only letters, numbers, dot, underscore, or hyphen.');
}

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
    '@primestocks.ph'
];
if (!in_array($domain, $allowedDomains, true)) {
    json_error('Invalid domain selected.');
}

$email = $username . $domain;
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Invalid email generated from username/domain.');
}

if (trim($password) === '') {
    json_error('Password is required.');
}

ensure_users_columns($conn);

$existsStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
if (!$existsStmt) {
    json_error('System error. Please try again.', 500);
}
$existsStmt->bind_param("s", $email);
$existsStmt->execute();
$existsRes = $existsStmt->get_result();
$existsRow = $existsRes ? $existsRes->fetch_assoc() : null;
$existsStmt->close();
if ($existsRow && isset($existsRow['id'])) {
    json_error('Email already exists.');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$role = 'employee';
$company = '';
$department = trim((string) ($_POST['department'] ?? ''));
$otp = '000000';
$verified = 1;

$insert = $conn->prepare("
    INSERT INTO users (name, full_name, username, email, company, department, password, role, otp_code, is_verified)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$insert) {
    json_error('System error. Please try again.', 500);
}
$insert->bind_param(
    "sssssssssi",
    $fullName,
    $fullName,
    $username,
    $email,
    $company,
    $department,
    $passwordHash,
    $role,
    $otp,
    $verified
);

if (!$insert->execute()) {
    $insert->close();
    json_error('Failed to create user.', 500);
}
$newUserId = (int) $insert->insert_id;
$insert->close();

echo json_encode([
    'ok' => true,
    'message' => 'User created successfully',
    'user' => [
        'id' => $newUserId,
        'name' => $fullName,
        'department' => $department,
        'role' => $role
    ]
]);
