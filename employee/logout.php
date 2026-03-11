<?php
require_once '../config/database.php';

/* Unset all session variables */
$_SESSION = [];

/* Destroy session */
session_destroy();

/* Prevent back button caching */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* Redirect to login */
header("Location: ../index.php");
exit();
?>
