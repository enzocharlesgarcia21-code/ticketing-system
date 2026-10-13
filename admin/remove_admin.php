<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/user_permissions.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin' || !user_permissions_can_manage($conn)) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
csrf_validate();

if (isset($_POST['id'])) {
    $remove_id = (int)$_POST['id'];

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
                security_regenerate_authenticated_session();
                unset($_SESSION['csrf_token']);
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
