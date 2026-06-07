<?php
header('Content-Type: text/plain; charset=utf-8');

echo "Ticketing System Runtime Check\n";
echo "============================\n\n";

echo 'PHP_VERSION=' . PHP_VERSION . "\n";
echo 'PHP_VERSION_ID=' . PHP_VERSION_ID . "\n";
echo 'SAPI=' . PHP_SAPI . "\n";
echo 'MYSQLI=' . (extension_loaded('mysqli') ? 'yes' : 'no') . "\n";
echo 'MYSQLND=' . (function_exists('mysqli_fetch_all') ? (stripos(mysqli_get_client_info(), 'mysqlnd') !== false ? 'yes' : 'no') : 'unknown') . "\n";
echo 'OPENSSL=' . (extension_loaded('openssl') ? 'yes' : 'no') . "\n";
echo 'RANDOM_BYTES=' . (function_exists('random_bytes') ? 'yes' : 'no') . "\n";
echo 'HASH_EQUALS=' . (function_exists('hash_equals') ? 'yes' : 'no') . "\n";

echo "\nPaths\n";
echo 'ROOT=' . __DIR__ . "\n";
echo 'VENDOR_AUTOLOAD=' . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'present' : 'missing') . "\n";
echo 'DOTENV_FILE=' . (file_exists(__DIR__ . '/.env') ? 'present' : 'missing') . "\n";

echo "\nComposer\n";
$composerLoaded = false;
if (PHP_VERSION_ID >= 70205 && file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $composerLoaded = true;
        echo "AUTOLOAD=ok\n";
    } catch (Throwable $e) {
        echo 'AUTOLOAD=failed: ' . get_class($e) . ' - ' . $e->getMessage() . "\n";
    }
} else {
    echo "AUTOLOAD=skipped\n";
}

echo "\nEnv\n";
try {
    require_once __DIR__ . '/config/env.php';
    echo 'ENV_LOADER=ok' . "\n";
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT');
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    echo 'ENV_DB_HOST=' . ($host !== false && $host !== '' ? 'set' : 'missing') . "\n";
    echo 'ENV_DB_PORT=' . ($port !== false && $port !== '' ? 'set' : 'missing') . "\n";
    echo 'ENV_DB_NAME=' . ($name !== false && $name !== '' ? 'set' : 'missing') . "\n";
    echo 'ENV_DB_USER=' . ($user !== false && $user !== '' ? 'set' : 'missing') . "\n";
} catch (Throwable $e) {
    echo 'ENV_LOADER=failed: ' . get_class($e) . ' - ' . $e->getMessage() . "\n";
}

echo "\nDatabase\n";
try {
    require_once __DIR__ . '/config/database.php';
    echo 'DATABASE_BOOTSTRAP=ok' . "\n";
    echo 'DB_CONNECTED=' . ((isset($conn) && $conn instanceof mysqli) ? 'yes' : 'no') . "\n";
    if (isset($conn) && $conn instanceof mysqli) {
        echo 'DB_SERVER_INFO=' . $conn->server_info . "\n";
        $probe = $conn->query('SELECT 1 AS ok');
        echo 'DB_QUERY=' . ($probe ? 'ok' : 'failed') . "\n";
        if ($probe instanceof mysqli_result) {
            $probe->free();
        }
    }
} catch (Throwable $e) {
    echo 'DATABASE_BOOTSTRAP=failed: ' . get_class($e) . ' - ' . $e->getMessage() . "\n";
}

echo "\nPage Includes\n";
$checks = [
    'csrf.php' => __DIR__ . '/includes/csrf.php',
    'rate_limit.php' => __DIR__ . '/includes/rate_limit.php',
    'ticket_assignment.php' => __DIR__ . '/includes/ticket_assignment.php',
    'notification_service.php' => __DIR__ . '/includes/notification_service.php',
    'conference_booking.php' => __DIR__ . '/includes/conference_booking.php',
];
foreach ($checks as $label => $path) {
    try {
        require_once $path;
        echo $label . '=ok' . "\n";
    } catch (Throwable $e) {
        echo $label . '=failed: ' . get_class($e) . ' - ' . $e->getMessage() . "\n";
    }
}
