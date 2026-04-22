<?php
// TEMPORARY DEBUG FILE - REMOVE AFTER USE
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$rootDir = __DIR__;
require_once $rootDir . '/vendor/autoload.php';
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createUnsafeImmutable($rootDir)->safeLoad();
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = getenv('DB_NAME') ?: 'ticketing_system';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT');
$port = ($port === false || $port === '' || !ctype_digit((string)$port)) ? 3307 : (int)$port;

$conn = mysqli_connect($host, $user, $pass, $db, $port);

echo '<pre>';

if (!$conn) {
    echo "DB FAILED: " . mysqli_connect_error() . "\n";
    exit;
}

echo "DB: OK\n\n";

// Check tables exist
$required_tables = ['users', 'employee_tickets', 'notifications', 'it_employees', 'departments'];
echo "--- Table Check ---\n";
foreach ($required_tables as $table) {
    $r = $conn->query("SHOW TABLES LIKE '$table'");
    echo "$table: " . ($r && $r->num_rows > 0 ? 'EXISTS' : 'MISSING') . "\n";
}

// Check columns on employee_tickets
echo "\n--- employee_tickets columns (relevant) ---\n";
$cols_to_check = ['feedback_status', 'resolved_at', 'force_password_change', 'auto_escalated_high_at', 'auto_escalated_critical_at'];
$res = $conn->query("SHOW COLUMNS FROM employee_tickets");
$existing = [];
if ($res) {
    while ($row = $res->fetch_assoc()) { $existing[] = $row['Field']; }
}
foreach ($cols_to_check as $c) {
    echo "$c: " . (in_array($c, $existing) ? 'EXISTS' : 'MISSING') . "\n";
}

// Check columns on users
echo "\n--- users columns (relevant) ---\n";
$cols_to_check_u = ['force_password_change', 'company', 'department', 'is_verified'];
$res2 = $conn->query("SHOW COLUMNS FROM users");
$existing2 = [];
if ($res2) {
    while ($row = $res2->fetch_assoc()) { $existing2[] = $row['Field']; }
}
foreach ($cols_to_check_u as $c) {
    echo "$c: " . (in_array($c, $existing2) ? 'EXISTS' : 'MISSING') . "\n";
}

// Test a prepare() like dashboard does
echo "\n--- Prepare Test (dashboard queries) ---\n";
$stmts = [
    "SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status <> 'Closed'",
    "SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Open'",
    "SELECT id, subject, category, status, created_at FROM employee_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
    "SELECT company FROM users WHERE id = ?",
];
foreach ($stmts as $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        echo "FAIL: " . $conn->error . "\n  SQL: " . substr($sql, 0, 60) . "\n";
    } else {
        echo "OK:   " . substr($sql, 0, 60) . "\n";
        $stmt->close();
    }
}

// ALTER TABLE permission test
echo "\n--- ALTER TABLE permission ---\n";
$testCol = '__debug_test_col__';
$r1 = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE '$testCol'");
if (!$r1 || $r1->num_rows === 0) {
    $ok = $conn->query("ALTER TABLE employee_tickets ADD COLUMN $testCol TINYINT(1) NULL");
    if ($ok) {
        $conn->query("ALTER TABLE employee_tickets DROP COLUMN $testCol");
        echo "ALTER TABLE: ALLOWED\n";
    } else {
        echo "ALTER TABLE: DENIED - " . $conn->error . "\n";
    }
} else {
    $conn->query("ALTER TABLE employee_tickets DROP COLUMN $testCol");
    echo "ALTER TABLE: ALLOWED (cleaned up leftover)\n";
}

echo '</pre>';
echo '<p style="color:red"><b>DELETE this file after debugging!</b></p>';
