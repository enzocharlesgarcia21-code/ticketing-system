<?php
require_once '../config/database.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

if (isset($_GET['id'])) {
    $promote_id = (int)$_GET['id'];
    
    // Check if user is an IT employee
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND department = 'IT' AND role = 'employee'");
    $check_stmt->bind_param("i", $promote_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        $update_stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $update_stmt->bind_param("i", $promote_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['admin_added'] = true;
        } else {
            $_SESSION['error_message'] = "Error promoting user.";
        }
    } else {
        $_SESSION['error_message'] = "Invalid user selected or user is not eligible.";
    }
}

header("Location: create_admin.php");
exit();
