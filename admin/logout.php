<?php
require_once '../config/database.php';

/* Unset all session variables */
$_SESSION = [];

/* Destroy the session */
session_destroy();

/* Prevent back button cache */
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

/* Redirect to admin login */
header("Location: ../index.php");
exit();
?>
