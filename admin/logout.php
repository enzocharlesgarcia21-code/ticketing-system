<?php
require_once '../config/database.php';
require_once '../includes/activity_logger.php';
require_once '../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_validate();

$logoutUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($logoutUserId > 0) {
    activity_log($conn, $logoutUserId, 'LOGOUT', 'Logged out', 'Authentication');
    $stmt = $conn->prepare("UPDATE users SET last_logout_at = NOW(), is_online = 0 WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $logoutUserId);
        $stmt->execute();
        $stmt->close();
    }
}

/* Unset all session variables */
$_SESSION = [];

/* Destroy the session */
security_clear_session();

/* Prevent back button cache */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* Redirect to admin login */
header("Location: ../index.php");
exit();
?>
