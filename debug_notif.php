<?php
// ============================================================
// NOTIFICATION + FEEDBACK DIAGNOSTIC SCRIPT
// Visit this URL on the live server to diagnose issues.
// REMOVE this file after diagnosis is complete.
// ============================================================
ini_set('display_errors', 1);
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
$port = ($port === false || $port === '' || !ctype_digit((string) $port)) ? 3307 : (int) $port;

$conn = mysqli_connect($host, $user, $pass, $db, $port);

header('Content-Type: text/plain; charset=utf-8');

// ---- VERSION STAMP ----
echo "=== debug_notif.php v2026-05-07 ===\n\n";
echo "DB Host: $host  DB Name: $db  Port: $port\n";
echo "PHP: " . PHP_VERSION . "\n\n";

if (!$conn) {
    echo "DB CONNECTION FAILED: " . mysqli_connect_error() . "\n";
    exit;
}
echo "DB Connection: OK\n\n";

// ---- COLUMN CHECK: notifications ----
echo "=== notifications table columns ===\n";
$r = $conn->query("DESCRIBE notifications");
$notifCols = [];
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $notifCols[] = $row['Field'];
        echo "  " . $row['Field'] . " " . $row['Type'] . "\n";
    }
} else {
    echo "  DESCRIBE FAILED: " . $conn->error . "\n";
}

$hasTitle      = in_array('title',       $notifCols);
$hasActionType = in_array('action_type', $notifCols);
echo "\ntitle column:       " . ($hasTitle      ? "EXISTS" : "MISSING") . "\n";
echo "action_type column: " . ($hasActionType ? "EXISTS" : "MISSING") . "\n\n";

// ---- COLUMN CHECK: employee_tickets (relevant) ----
echo "=== employee_tickets relevant columns ===\n";
$r2 = $conn->query("DESCRIBE employee_tickets");
$etCols = [];
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        $etCols[] = $row['Field'];
    }
}
$etCheck = ['requester_email','requester_name','feedback_status','assigned_group',
            'assigned_company','assigned_user_id','assigned_to','resolved_at'];
foreach ($etCheck as $c) {
    echo "  $c: " . (in_array($c, $etCols) ? "EXISTS" : "MISSING") . "\n";
}
echo "\n";

// ---- COLUMN CHECK: users ----
echo "=== users relevant columns ===\n";
$r3 = $conn->query("DESCRIBE users");
$uCols = [];
if ($r3) { while ($row = $r3->fetch_assoc()) { $uCols[] = $row['Field']; } }
$uCheck = ['full_name','username','force_password_change'];
foreach ($uCheck as $c) {
    echo "  $c: " . (in_array($c, $uCols) ? "EXISTS" : "MISSING") . "\n";
}
echo "\n";

// ---- TEST: basic notification INSERT ----
echo "=== Test: notification INSERT (user_id=1, ticket_id=1) ===\n";
$testMsg  = "DEBUG_TEST notification " . date('Y-m-d H:i:s');
$testType = 'debug_test';

if ($hasTitle && $hasActionType) {
    $ins = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type) VALUES (1, 1, ?, ?, ?, ?)");
    if (!$ins) {
        echo "  PREPARE FAILED (full): " . $conn->error . "\n";
        // try fallback
        $ins = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (1, 1, ?, ?)");
        if (!$ins) {
            echo "  PREPARE FAILED (fallback): " . $conn->error . "\n";
        } else {
            $ins->bind_param("ss", $testMsg, $testType);
            $ok = $ins->execute();
            echo "  Fallback INSERT: " . ($ok ? "OK" : "FAILED: " . $ins->error) . "\n";
            $ins->close();
        }
    } else {
        $emptyTitle = '';
        $ins->bind_param("ssss", $emptyTitle, $testMsg, $testType, $emptyTitle);
        $ok = $ins->execute();
        echo "  Full INSERT: " . ($ok ? "OK" : "FAILED: " . $ins->error) . "\n";
        $ins->close();
    }
} else {
    // no title/action_type — use fallback
    $ins = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (1, 1, ?, ?)");
    if (!$ins) {
        echo "  PREPARE FAILED (basic): " . $conn->error . "\n";
    } else {
        $ins->bind_param("ss", $testMsg, $testType);
        $ok = $ins->execute();
        echo "  Basic INSERT: " . ($ok ? "OK" : "FAILED: " . $ins->error) . "\n";
        $ins->close();
    }
}

// ---- TEST: fetch notifications query ----
echo "\n=== Test: fetch notifications (user_id=1) ===\n";
$fetchQuery = "SELECT n.id, n.ticket_id, n.message, n.type, n.is_read, n.created_at"
    . ($hasTitle       ? ", n.title"       : ", '' AS title")
    . ($hasActionType  ? ", n.action_type" : ", '' AS action_type")
    . " FROM notifications n WHERE n.user_id = 1 ORDER BY n.created_at DESC LIMIT 5";

$fr = $conn->query($fetchQuery);
if (!$fr) {
    echo "  QUERY FAILED: " . $conn->error . "\n";
} else {
    $rows = [];
    while ($row = $fr->fetch_assoc()) { $rows[] = $row; }
    echo "  Rows returned: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo "  id=" . $row['id'] . " type=" . $row['type'] . " msg=" . substr($row['message'], 0, 50) . "\n";
    }
}

// ---- TEST: count query (no JOIN) ----
echo "\n=== Test: unread count for user_id=1 ===\n";
$cr = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = 1 AND is_read = 0 AND type <> 'chat_message' AND type <> 'debug_test'");
if (!$cr) {
    echo "  COUNT FAILED: " . $conn->error . "\n";
} else {
    $cnt = $cr->fetch_assoc();
    echo "  Unread count: " . $cnt['count'] . "\n";
}

// ---- TEST: notif_ticket_data prepare ----
echo "\n=== Test: notif_ticket_data queries ===\n";
// Primary query (uses requester_email)
$hasRequesterEmail = in_array('requester_email', $etCols);
$hasAssignedGroup  = in_array('assigned_group', $etCols);

if ($hasRequesterEmail && $hasAssignedGroup) {
    $s = $conn->prepare("SELECT t.id, t.user_id, t.subject, t.status, t.assigned_user_id, t.assigned_department, t.assigned_group, t.assigned_company, t.requester_name, t.requester_email, COALESCE(NULLIF(TRIM(t.requester_name),''), creator.name) AS creator_name, COALESCE(NULLIF(TRIM(t.requester_email),''), creator.email) AS creator_email FROM employee_tickets t LEFT JOIN users creator ON creator.id = t.user_id WHERE t.id = 1 LIMIT 1");
    echo "  Primary query prepare: " . ($s ? "OK" : "FAILED: " . $conn->error) . "\n";
    if ($s) $s->close();
} else {
    echo "  Primary query: SKIPPED (missing requester_email=" . ($hasRequesterEmail?'y':'n') . " assigned_group=" . ($hasAssignedGroup?'y':'n') . ")\n";
}

// Fallback query
$s2 = $conn->prepare("SELECT t.id, t.user_id, t.subject, t.status, t.assigned_user_id, t.assigned_department, t.assigned_company, NULL AS requester_name, NULL AS requester_email, creator.name AS creator_name, creator.email AS creator_email FROM employee_tickets t LEFT JOIN users creator ON creator.id = t.user_id WHERE t.id = 1 LIMIT 1");
echo "  Fallback query prepare: " . ($s2 ? "OK" : "FAILED: " . $conn->error) . "\n";
if ($s2) $s2->close();

// Ultra-minimal fallback
$s3 = $conn->prepare("SELECT t.id, t.user_id, t.subject, t.status, NULL AS assigned_user_id, NULL AS assigned_department, NULL AS assigned_company, NULL AS requester_name, NULL AS requester_email, creator.name AS creator_name, creator.email AS creator_email FROM employee_tickets t LEFT JOIN users creator ON creator.id = t.user_id WHERE t.id = 1 LIMIT 1");
echo "  Ultra-minimal query prepare: " . ($s3 ? "OK" : "FAILED: " . $conn->error) . "\n";
if ($s3) $s3->close();

// ---- TEST: admin/update_ticket old_stmt query ----
echo "\n=== Test: admin update_ticket old_stmt prepare ===\n";
if ($hasRequesterEmail) {
    $os = $conn->prepare("SELECT user_id, requester_email, status, assigned_department, assigned_company, assigned_group, assigned_user_id, assigned_to, company, admin_note FROM employee_tickets WHERE id = 1");
    echo "  Old_stmt query: " . ($os ? "OK" : "FAILED: " . $conn->error) . "\n";
    if ($os) $os->close();
} else {
    echo "  Old_stmt query: SKIPPED (requester_email missing)\n";
    $os = $conn->prepare("SELECT user_id, status, assigned_department, assigned_company, assigned_group, assigned_user_id, assigned_to, company, admin_note FROM employee_tickets WHERE id = 1");
    echo "  Fallback old_stmt (no requester_email): " . ($os ? "OK" : "FAILED: " . $conn->error) . "\n";
    if ($os) $os->close();
}

// ---- TEST: recent status_update notifications ----
echo "\n=== Recent status_update notifications in DB (last 10) ===\n";
$rn = $conn->query("SELECT id, user_id, ticket_id, message, type, is_read, created_at FROM notifications WHERE type IN ('status_update','ticket_closed') ORDER BY created_at DESC LIMIT 10");
if (!$rn) {
    echo "  QUERY FAILED: " . $conn->error . "\n";
} else {
    $cnt = 0;
    while ($row = $rn->fetch_assoc()) {
        $cnt++;
        echo "  id=" . $row['id'] . " user_id=" . $row['user_id'] . " ticket_id=" . $row['ticket_id'] . " is_read=" . $row['is_read'] . " msg=" . substr($row['message'], 0, 60) . " [" . $row['created_at'] . "]\n";
    }
    if ($cnt === 0) echo "  (none found — notifications are NOT being inserted for status changes)\n";
}

// ---- TEST: recent resolved/closed tickets with feedback_status ----
echo "\n=== Recent resolved/closed tickets ===\n";
if (in_array('feedback_status', $etCols)) {
    $rt = $conn->query("SELECT id, user_id, status, feedback_status, resolved_at FROM employee_tickets WHERE status IN ('Resolved','Closed') ORDER BY id DESC LIMIT 10");
} else {
    $rt = $conn->query("SELECT id, user_id, status, NULL AS feedback_status, NULL AS resolved_at FROM employee_tickets WHERE status IN ('Resolved','Closed') ORDER BY id DESC LIMIT 10");
}
if (!$rt) {
    echo "  QUERY FAILED: " . $conn->error . "\n";
} else {
    $cnt = 0;
    while ($row = $rt->fetch_assoc()) {
        $cnt++;
        echo "  ticket#" . $row['id'] . " user=" . $row['user_id'] . " status=" . $row['status'] . " feedback_status=" . $row['feedback_status'] . " resolved_at=" . $row['resolved_at'] . "\n";
    }
    if ($cnt === 0) echo "  (no resolved/closed tickets)\n";
}

// ---- TEST: ALTER TABLE permissions ----
echo "\n=== Test: ALTER TABLE permission ===\n";
$alterTest = $conn->query("ALTER TABLE notifications ADD COLUMN _debug_tmp_col TINYINT NULL");
if ($alterTest) {
    echo "  ALTER TABLE: PERMITTED (DB user can add columns)\n";
    $conn->query("ALTER TABLE notifications DROP COLUMN _debug_tmp_col");
} else {
    echo "  ALTER TABLE: DENIED - " . $conn->error . "\n";
    echo "  *** This means notif_ensure_title_column() CANNOT add missing columns! ***\n";
}

echo "\n=== DONE ===\n";
$conn->close();
