<?php
require_once '../config/database.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header("Location: dashboard.php");
    exit();
}

$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . (string) $_SERVER['QUERY_STRING']) : '';
header("Location: ../employee/employee_login.php" . $qs);
exit();
?>

