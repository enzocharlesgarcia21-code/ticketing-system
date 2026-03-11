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

if (session_status() === PHP_SESSION_NONE) {
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
