<?php
$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "ticketing_system";

// Set PHP timezone (server-side)
date_default_timezone_set('Asia/Manila');

$conn = mysqli_connect($host, $user, $pass, $db, 3306);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!$conn) {
    die("Connection Failed: " . mysqli_connect_error());
}

// Align MySQL session timezone with PHP timezone (UTC+08:00 for Asia/Manila)
@mysqli_query($conn, "SET time_zone = '+08:00'");
