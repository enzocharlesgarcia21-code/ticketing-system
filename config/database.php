<?php

require_once __DIR__ . '/env.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ticketing_system';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$port = getenv('DB_PORT');
if ($port === false || $port === '' || !ctype_digit((string) $port)) {
    $port = 3306;
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
