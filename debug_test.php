<?php
// TEMPORARY DEBUG FILE - REMOVE AFTER USE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo '<pre>';
echo "PHP Version: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n\n";

// Test autoloader
$rootDir = __DIR__;
$autoloadPath = $rootDir . '/vendor/autoload.php';
echo "Autoload path: $autoloadPath\n";
echo "Autoload exists: " . (file_exists($autoloadPath) ? 'YES' : 'NO') . "\n\n";

// Test .env loading
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    echo "Autoloader loaded.\n";
    if (class_exists(\Dotenv\Dotenv::class)) {
        $dotenv = \Dotenv\Dotenv::createUnsafeImmutable($rootDir);
        $dotenv->safeLoad();
        echo ".env loaded.\n";
    } else {
        echo "Dotenv class NOT found.\n";
    }
}

echo "\n--- Environment Variables ---\n";
echo "DB_HOST: " . (getenv('DB_HOST') ?: '(not set)') . "\n";
echo "DB_NAME: " . (getenv('DB_NAME') ?: '(not set)') . "\n";
echo "DB_USER: " . (getenv('DB_USER') ?: '(not set)') . "\n";
echo "DB_PORT: " . (getenv('DB_PORT') ?: '(not set)') . "\n";
echo "DB_PASS: " . (getenv('DB_PASS') !== false ? '(set)' : '(not set)') . "\n\n";

// Test DB connection
$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ticketing_system';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT');
$port = ($port === false || $port === '' || !ctype_digit((string)$port)) ? 3307 : (int)$port;

echo "Connecting to: $host:$port / DB: $db / User: $user\n";
$conn = mysqli_connect($host, $user, $pass, $db, $port);
if (!$conn) {
    echo "DB Connection FAILED: " . mysqli_connect_error() . "\n";
} else {
    echo "DB Connection: SUCCESS\n";
    mysqli_close($conn);
}

// Test PHPMailer
echo "\n--- PHPMailer Check ---\n";
echo "vendor/phpmailer/phpmailer/src/PHPMailer.php exists: " . (file_exists($rootDir . '/vendor/phpmailer/phpmailer/src/PHPMailer.php') ? 'YES' : 'NO') . "\n";
echo "PHPMailer class loadable: ";
try {
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        echo "YES\n";
    } else {
        echo "NO (class not found)\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n--- Required Extensions ---\n";
foreach (['mysqli', 'mbstring', 'openssl', 'ctype', 'filter', 'hash'] as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? 'loaded' : 'MISSING') . "\n";
}

echo '</pre>';
echo '<p style="color:red"><b>DELETE this file after debugging!</b></p>';
