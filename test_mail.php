<?php
require_once __DIR__ . '/includes/notification_service.php';

function test_mail_debug_log(array $context): void
{
    $logPath = __DIR__ . '/uploads/email_debug.log';
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => 'smtp_test_mail',
    ] + $context;
    @file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function test_mail_mask(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '(empty)';
    }
    if (strpos($value, '@') !== false) {
        [$local, $domain] = explode('@', $value, 2);
        return substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
    }
    return $value;
}

function test_mail_password_status(): string
{
    $password = readSmtpConfigValue('SMTP_PASSWORD');
    if ($password === '') {
        $password = readSmtpConfigValue('GMAIL_APP_PASSWORD');
    }
    $password = preg_replace('/\s+/', '', $password) ?? $password;
    return $password === ''
        ? 'Missing'
        : 'Loaded (' . strlen($password) . ' characters after removing spaces)';
}

$to = trim((string) ($_POST['to'] ?? $_GET['to'] ?? readSmtpConfigValue('SMTP_USERNAME')));
$sent = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $sent = false;
        $error = 'Please enter a valid recipient email address.';
    } else {
        $subject = 'Helpdesk SMTP Test - ' . date('Y-m-d H:i:s');
        $mail = notif_email_simple('SMTP Test', [
            'This is a test email from the ticketing system.',
            'Sender: ' . readSmtpConfigValue('SMTP_USERNAME'),
            'Host: ' . readSmtpConfigValue('SMTP_HOST'),
            'Port: ' . readSmtpConfigValue('SMTP_PORT'),
            'Encryption: ' . (readSmtpConfigValue('SMTP_ENCRYPTION') ?: readSmtpConfigValue('SMTP_SECURE')),
        ], 'Open Helpdesk', notif_base_url() . '/ticketing/index.php');

        $sent = notif_email_send([$to], $subject, (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''));
        $error = $sent ? '' : (function_exists('smtp_last_error') ? smtp_last_error() : 'Email send failed.');
    }

    test_mail_debug_log([
        'recipient' => $to,
        'smtp_host' => readSmtpConfigValue('SMTP_HOST'),
        'smtp_port' => readSmtpConfigValue('SMTP_PORT'),
        'smtp_encryption' => readSmtpConfigValue('SMTP_ENCRYPTION') ?: readSmtpConfigValue('SMTP_SECURE'),
        'smtp_username' => readSmtpConfigValue('SMTP_USERNAME'),
        'smtp_from_email' => readSmtpConfigValue('SMTP_FROM_EMAIL'),
        'smtp_password_loaded' => readSmtpConfigValue('SMTP_PASSWORD') !== '' || readSmtpConfigValue('GMAIL_APP_PASSWORD') !== '',
        'success' => (bool) $sent,
        'error' => $error,
    ]);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SMTP Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 720px; margin: 40px auto; line-height: 1.5; }
        input, button { font: inherit; padding: 10px; }
        input { width: 360px; max-width: 100%; }
        button { cursor: pointer; }
        .ok { color: #166534; font-weight: 700; }
        .fail { color: #b91c1c; font-weight: 700; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        .panel { border: 1px solid #e5e7eb; padding: 16px; border-radius: 8px; margin: 16px 0; }
    </style>
</head>
<body>
    <h1>SMTP Test</h1>
    <div class="panel">
        <div>Host: <code><?= htmlspecialchars(readSmtpConfigValue('SMTP_HOST') ?: '(empty)', ENT_QUOTES, 'UTF-8') ?></code></div>
        <div>Port: <code><?= htmlspecialchars(readSmtpConfigValue('SMTP_PORT') ?: '(empty)', ENT_QUOTES, 'UTF-8') ?></code></div>
        <div>Encryption: <code><?= htmlspecialchars((readSmtpConfigValue('SMTP_ENCRYPTION') ?: readSmtpConfigValue('SMTP_SECURE')) ?: '(empty)', ENT_QUOTES, 'UTF-8') ?></code></div>
        <div>Username: <code><?= htmlspecialchars(test_mail_mask(readSmtpConfigValue('SMTP_USERNAME')), ENT_QUOTES, 'UTF-8') ?></code></div>
        <div>From: <code><?= htmlspecialchars(test_mail_mask(readSmtpConfigValue('SMTP_FROM_EMAIL')), ENT_QUOTES, 'UTF-8') ?></code></div>
        <div>Password: <code><?= htmlspecialchars(test_mail_password_status(), ENT_QUOTES, 'UTF-8') ?></code></div>
    </div>

    <?php if ($sent === true): ?>
        <p class="ok">Test email sent successfully. Check the recipient inbox and spam folder.</p>
    <?php elseif ($sent === false): ?>
        <p class="fail">Test email failed: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post">
        <label>
            Recipient email<br>
            <input type="email" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <button type="submit">Send Test Email</button>
    </form>

    <p>Results are also logged in <code>uploads/email_debug.log</code>.</p>
</body>
</html>
