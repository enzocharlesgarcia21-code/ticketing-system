<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';

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
$sendInitiation = (int) (($_POST['send_initiation'] ?? '0') === '1');
$sendInvitation = (int) (($_POST['send_invitation'] ?? '0') === '1');

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

$allowedDomains = ['@leadsagri.com'];
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
$department = '';
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

if ($sendInitiation || $sendInvitation) {
    $loginUrl = (isset($_SERVER['HTTP_HOST']) ? ('http://' . $_SERVER['HTTP_HOST'] . '/ticketing/employee/employee_login.php') : 'employee_login.php');
    $subject = 'Leads Agri Helpdesk Account';
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    $html = "
        <div style='font-family:Inter,Arial,sans-serif; color:#111827; line-height:1.5'>
            <h2 style='margin:0 0 12px 0; color:#1B5E20'>Welcome to Leads Agri Helpdesk</h2>
            <p style='margin:0 0 12px 0'>Hello <strong>$safeName</strong>,</p>
            <p style='margin:0 0 12px 0'>Your account has been created.</p>
            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin:0 0 12px 0'>
                <div><strong>Email:</strong> $safeEmail</div>
                <div><strong>Password:</strong> $safePassword</div>
            </div>
            <p style='margin:0 0 12px 0'>Login here: <a href='$safeUrl' style='color:#1B5E20; font-weight:700'>$safeUrl</a></p>
            <p style='margin:0'>Please change your password after logging in.</p>
        </div>
    ";
    $text = "Welcome to Leads Agri Helpdesk\n\nEmail: $email\nPassword: $password\nLogin: $loginUrl\n\nPlease change your password after logging in.\n";
    sendSmtpEmail([$email], $subject, $html, $text);
}

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

