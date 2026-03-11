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
    $remove_id = (int)$_GET['id'];

    // Prevent removing yourself
    if ($remove_id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = "You cannot remove your own admin privileges.";
    } else {
        // Verify target is an IT admin
        $check_admin = $conn->prepare("SELECT id FROM users WHERE id = ? AND department = 'IT' AND role = 'admin'");
        $check_admin->bind_param("i", $remove_id);
        $check_admin->execute();

        if ($check_admin->get_result()->num_rows > 0) {
            $demote_stmt = $conn->prepare("UPDATE users SET role = 'employee' WHERE id = ?");
            $demote_stmt->bind_param("i", $remove_id);
            
            if ($demote_stmt->execute()) {
                $_SESSION['admin_removed'] = true;
            } else {
                $_SESSION['error_message'] = "Error removing admin privileges.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid user selected.";
        }
    }
}

header("Location: create_admin.php");
exit();
