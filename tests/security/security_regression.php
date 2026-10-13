<?php

$root = dirname(__DIR__, 2);
$failures = [];
$passes = 0;

function security_test_source(string $relative): string
{
    global $root, $failures;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($source)) {
        $failures[] = 'Missing test target: ' . $relative;
        return '';
    }
    return $source;
}

function security_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
    } else {
        $failures[] = $message;
    }
}

// IDOR: denial must occur before attachment and metadata serialization.
$details = security_test_source('employee/get_ticket_details.php');
$denial = strpos($details, "if (!\$hasAccess && !\$taskReadOnlyAccess)");
$forbidden = strpos($details, 'http_response_code(403)', $denial === false ? 0 : $denial);
$attachments = strpos($details, "\$row['attachments']", $denial === false ? 0 : $denial);
security_assert($denial !== false && $forbidden !== false && $attachments !== false && $forbidden < $attachments, 'IDOR denial must precede attachment serialization.');
security_assert(strpos(substr($details, (int) $denial, 500), 'exit;') !== false, 'IDOR denial must terminate the request.');
security_assert(strpos($details, 'ticket_user_has_task_read_access') !== false, 'My Task history access must use an explicit server-side authorization check.');
security_assert(strpos($details, "\$row['can_update_tab'] = false", $denial) !== false && strpos($details, "\$row['can_claim_ticket'] = false", $denial) !== false, 'Historical task access must remain read-only.');

// Authorization and CSRF regression checks for privileged mutations.
foreach (['admin/add_admin.php', 'admin/remove_admin.php'] as $file) {
    $source = security_test_source($file);
    security_assert(strpos($source, 'user_permissions_can_manage') !== false, $file . ' must require super-admin authorization.');
    security_assert(strpos($source, "REQUEST_METHOD'] !== 'POST'") !== false && strpos($source, 'csrf_validate()') !== false, $file . ' must be POST-only with CSRF validation.');
}
foreach (['admin/delete_kb.php', 'admin/mark_ticket_read.php', 'admin/fetch_notifications.php', 'employee/fetch_notifications.php', 'employee/set_view_mode.php', 'employee/logout.php', 'admin/logout.php'] as $file) {
    $source = security_test_source($file);
    security_assert(strpos($source, 'REQUEST_METHOD') !== false && strpos($source, "'POST'") !== false && strpos($source, 'csrf_validate()') !== false, $file . ' must be POST-only with CSRF validation.');
}

// OTPs: random_int + one-way hashes; no legacy plaintext verification/display.
$authSecurity = security_test_source('includes/auth_security.php');
security_assert(strpos($authSecurity, 'random_int(100000, 999999)') !== false, 'OTP generation must use random_int().');
security_assert(strpos($authSecurity, 'password_hash($otp, PASSWORD_DEFAULT)') !== false && strpos($authSecurity, 'password_verify($otp') !== false, 'OTP values must be stored and checked using one-way password hashes.');
security_assert(strpos($authSecurity, 'attempts = attempts + 1') !== false && strpos($authSecurity, 'cooldownSeconds') !== false, 'OTP attempts and resend cooldowns must be enforced.');
$otpFiles = '';
foreach (['employee/register.php', 'employee/forgot_password.php', 'employee/verify_otp.php', 'employee/verify_reset_otp.php'] as $file) $otpFiles .= security_test_source($file);
security_assert(strpos($otpFiles, 'rand(100000, 999999)') === false, 'Authentication flows must not use rand() for OTPs.');
security_assert(strpos(security_test_source('employee/verify_reset_otp.php'), 'SELECT reset_otp') === false, 'SMTP failure must never retrieve or display a reset OTP.');

// Uploads must use private storage and an authorization-enforcing controller.
$private = security_test_source('includes/private_attachments.php');
$download = security_test_source('download_attachment.php');
security_assert(strpos($private, 'PRIVATE_UPLOAD_DIR') !== false && strpos($private, 'ticketing_private_uploads') !== false, 'Private attachment storage must prefer a path outside the project web root.');
security_assert(strpos($download, "http_response_code(403)") !== false && strpos($download, 'ticket_user_matches_requester') !== false, 'Attachment downloads must enforce ticket authorization.');
security_assert(strpos(security_test_source('uploads/.htaccess'), 'download_attachment.php') !== false, 'Legacy public attachments must be routed through the authenticated controller.');
require_once $root . '/includes/private_attachments.php';
security_assert(private_attachment_safe_name('../../config/env.php') === '', 'Attachment names must reject path traversal.');
security_assert(private_attachment_safe_name('ticket-file_123.pdf') === 'ticket-file_123.pdf', 'Safe stored attachment names must remain usable.');
foreach (['employee/request_ticket.php', 'sales/request_ticket.php', 'includes/ticket_assignment.php'] as $file) {
    $source = security_test_source($file);
    security_assert(strpos($source, 'private_attachment_storage_dir()') !== false && strpos($source, 'finfo') !== false, $file . ' must use private storage and server-side MIME checks.');
}

// Endpoint rate-limit coverage.
$rateTargets = [
    'employee/employee_login.php' => 'login',
    'employee/register.php' => 'registration',
    'employee/forgot_password.php' => 'password_reset_request',
    'employee/verify_reset_otp.php' => 'password_reset_verify',
    'employee/reset_password.php' => 'password_reset_complete',
    'employee/verify_otp.php' => 'email_verify_attempt',
    'sales/request_ticket.php' => 'guest_ticket_submit',
];
foreach ($rateTargets as $file => $scope) {
    security_assert(strpos(security_test_source($file), "'" . $scope . "'") !== false, $file . ' is missing its rate-limit scope.');
}

// Operational scripts must be CLI-only.
foreach (glob($root . '/cron/*.php') ?: [] as $path) {
    security_assert(strpos((string) file_get_contents($path), 'security_require_cli()') !== false, basename($path) . ' must reject HTTP execution.');
}
foreach (glob($root . '/tools/*.php') ?: [] as $path) {
    security_assert(strpos((string) file_get_contents($path), "PHP_SAPI !== 'cli'") !== false, basename($path) . ' must reject HTTP execution.');
}

if ($failures) {
    fwrite(STDERR, "Security regression failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo 'Security regression tests passed: ' . $passes . PHP_EOL;
