<?php

require_once __DIR__ . '/env.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ticketing_system';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$port = getenv('DB_PORT');
if ($port === false || $port === '' || !ctype_digit((string) $port)) {
    $port = 3307;
} else {
    $port = (int) $port;
}

date_default_timezone_set('Asia/Manila');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');

    session_start();
}

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    error_log(mysqli_connect_error());
    die("Database connection failed.");
}

mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+08:00'");

if (PHP_SAPI !== 'cli' && isset($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'employee')) {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $currentScript = basename($scriptName);
    $allowedScripts = ['employee_login.php', 'force_password_change.php', 'logout.php'];

    if ($userId > 0) {
        $stmt = mysqli_prepare($conn, "SELECT force_password_change FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            $_SESSION['force_password_change'] = (int) ($row['force_password_change'] ?? 0);
        }
    }

    if ((int) ($_SESSION['force_password_change'] ?? 0) === 1 && !in_array($currentScript, $allowedScripts, true)) {
        $rootPath = preg_replace('#/(employee|admin|includes)(/.*)?$#', '', $scriptName);
        $forcePath = rtrim((string) $rootPath, '/') . '/employee/force_password_change.php';
        if ($forcePath === '/employee/force_password_change.php') {
            $forcePath = 'force_password_change.php';
        }

        $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower((string) $_SERVER['HTTP_ACCEPT']), 'application/json') !== false);

        if ($isAjax) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
            }
            echo json_encode([
                'ok' => false,
                'error' => 'Password change required.',
                'redirect' => $forcePath
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . $forcePath);
        exit;
    }
}

// Auto-migrate missing columns that cause dashboard crashes
(function (mysqli $conn): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;

    // employee_tickets.feedback_status
    $r = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE 'feedback_status'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE employee_tickets ADD COLUMN feedback_status ENUM('pending','submitted','skipped') NULL DEFAULT 'pending' AFTER status");
    }
    if ($r instanceof mysqli_result) { $r->free(); }

    // ticket_feedback table
    $r2 = $conn->query("SHOW TABLES LIKE 'ticket_feedback'");
    if ($r2 && $r2->num_rows === 0) {
        $conn->query("
            CREATE TABLE ticket_feedback (
                id INT(11) NOT NULL AUTO_INCREMENT,
                ticket_id INT(11) NOT NULL,
                requestor_id INT(11) DEFAULT NULL,
                assignee_id INT(11) DEFAULT NULL,
                rating TINYINT(1) NOT NULL,
                comment TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY ticket_id (ticket_id),
                KEY assignee_id (assignee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    if ($r2 instanceof mysqli_result) { $r2->free(); }
})($conn);