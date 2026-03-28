<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';
require_once '../includes/ticket_assignment.php';

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
        'send_credentials' => "TINYINT(1) NOT NULL DEFAULT 0",
        'force_password_change' => "TINYINT(1) NOT NULL DEFAULT 0",
    ];
    foreach ($cols as $col => $ddl) {
        $colRes = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
        if (!$colRes || $colRes->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $col $ddl");
        }
    }
}

function json_error(string $msg, int $code = 400, ?string $errorCode = null): void
{
    http_response_code($code);
    $payload = ['ok' => false, 'error' => $msg];
    if ($errorCode !== null) {
        $payload['error_code'] = $errorCode;
    }
    echo json_encode($payload);
    exit;
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$domain = trim((string) ($_POST['domain'] ?? '@leadsagri.com'));
$password = (string) ($_POST['password'] ?? '');
$send_credentials = isset($_POST['send_credentials']) ? 1 : 0;
$force_password_change = isset($_POST['force_password_change']) ? 1 : 0;
$department = trim((string) ($_POST['department'] ?? ''));

if ($fullName === '') {
    json_error('Full name is required.', 400, 'name_required');
}

if (preg_match('/\d/', $fullName)) {
    json_error('Full name must not contain numbers.', 400, 'name_has_number');
}

if (!preg_match("/^(?=.{2,100}$)[A-Za-z][A-Za-z .,'-]*[A-Za-z.]$/", $fullName)) {
    json_error('Please enter a valid full name using letters only.', 400, 'name_invalid');
}

if ($username === '') {
    json_error('Email is required.', 400, 'email_required');
}

if (preg_match('/\s/', $username)) {
    json_error('Email must not contain spaces.', 400, 'email_has_spaces');
}

if (strpos($username, '@') !== false) {
    $parts = explode('@', $username, 2);
    $username = trim((string) ($parts[0] ?? ''));
    $parsedDomain = '@' . strtolower(trim((string) ($parts[1] ?? '')));
    if ($parsedDomain !== '@') {
        $domain = $parsedDomain;
    }
}

$username = strtolower(trim($username));
if ($username === '') {
    json_error('Email is required.', 400, 'email_required');
}

if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/', $username)) {
    json_error('Please enter a valid email address.', 400, 'email_invalid');
}

if (strpos($username, '..') !== false) {
    json_error('Please enter a valid email address.', 400, 'email_invalid');
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
    '@primestocks.ph',
    '@malvedaproperties.com'
];
if (!in_array($domain, $allowedDomains, true)) {
    json_error('Invalid domain selected.', 400, 'domain_invalid');
}

$noDepartmentDomains = [
    '@farmasee.ph',
    '@malvedaproperties.com',
    '@lingapleads.org',
    '@leads-eh.com',
    '@leadsanimalhealth.com',
    '@leadsav.com',
];

$email = $username . $domain;
if (preg_match('/\s/', $email)) {
    json_error('Email must not contain spaces.', 400, 'email_has_spaces');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please enter a valid email address.', 400, 'email_invalid');
}

if (in_array($domain, $noDepartmentDomains, true)) {
    $department = '';
} elseif ($department === '') {
    json_error('Department is required.', 400, 'department_required');
}

if (trim($password) === '') {
    json_error('Password is required.', 400, 'password_required');
}

ensure_users_columns($conn);

$nameExistsStmt = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
if (!$nameExistsStmt) {
    json_error('System error. Please try again.', 500, 'system_error');
}
$nameExistsStmt->bind_param("s", $fullName);
$nameExistsStmt->execute();
$nameExistsRes = $nameExistsStmt->get_result();
$nameExistsRow = $nameExistsRes ? $nameExistsRes->fetch_assoc() : null;
$nameExistsStmt->close();
if ($nameExistsRow && isset($nameExistsRow['id'])) {
    json_error('This full name is already registered. Please check the existing user list.', 400, 'name_exists');
}

$existsStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
if (!$existsStmt) {
    json_error('System error. Please try again.', 500, 'system_error');
}
$existsStmt->bind_param("s", $email);
$existsStmt->execute();
$existsRes = $existsStmt->get_result();
$existsRow = $existsRes ? $existsRes->fetch_assoc() : null;
$existsStmt->close();
if ($existsRow && isset($existsRow['id'])) {
    json_error('This email is already registered. Please use another email address.', 400, 'email_exists');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$role = 'employee';
$company = ticket_notification_company_key($domain);
$company = $company !== '' ? $company : $domain;
$otp = '000000';
$verified = 1;

$insert = $conn->prepare("
    INSERT INTO users (name, full_name, username, email, company, department, password, role, otp_code, is_verified, send_credentials, force_password_change)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$insert) {
    json_error('System error. Please try again.', 500, 'system_error');
}
$insert->bind_param(
    "sssssssssiii",
    $fullName,
    $fullName,
    $username,
    $email,
    $company,
    $department,
    $passwordHash,
    $role,
    $otp,
    $verified,
    $send_credentials,
    $force_password_change
);

if (!$insert->execute()) {
    $insert->close();
    json_error('Failed to create user.', 500, 'create_failed');
}
$newUserId = (int) $insert->insert_id;
$insert->close();

if ($send_credentials === 1) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/ajax_create_user.php'))), '/');
    $rootPath = preg_replace('#/admin$#', '', $basePath);
    $loginUrl = ($host !== '')
        ? ($scheme . '://' . $host . ($rootPath !== '' ? $rootPath : '') . '/employee/employee_login.php')
        : '../employee/employee_login.php';

    $subject = 'Your Leads Agri Helpdesk Account';
    $htmlBody = "
        <div style='font-family:Arial, sans-serif; color:#334155; line-height:1.6'>
            <h2 style='margin:0 0 12px 0; color:#0f172a;'>Your account has been created</h2>
            <p style='margin:0 0 14px 0;'>Hello <strong>" . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
            <p style='margin:0 0 14px 0;'>Your Leads Agri Helpdesk account is ready. Please use the credentials below to sign in:</p>
            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;margin:0 0 14px 0;'>
                <div><strong>Email:</strong> " . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</div>
                <div><strong>Temporary Password:</strong> " . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . "</div>
            </div>
            <p style='margin:0 0 14px 0;'>Login URL: <a href='" . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . "</a></p>
            " . ($force_password_change === 1 ? "<p style='margin:0;'>You will be required to change your password the first time you log in.</p>" : "") . "
        </div>
    ";
    $textBody = "Your account has been created.\n\n"
        . "Email: $email\n"
        . "Temporary Password: $password\n"
        . "Login URL: $loginUrl\n";
    if ($force_password_change === 1) {
        $textBody .= "You will be required to change your password the first time you log in.\n";
    }

    $emailSent = sendSmtpEmail([$email], $subject, $htmlBody, $textBody);
    if (!$emailSent) {
        error_log('User credentials email failed | userId=' . (string) $newUserId . ' | email=' . $email);
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'User created successfully',
    'user' => [
        'id' => $newUserId,
        'name' => $fullName,
        'email' => $email,
        'department' => $department,
        'role' => $role,
        'send_credentials' => $send_credentials,
        'force_password_change' => $force_password_change
    ]
]);
