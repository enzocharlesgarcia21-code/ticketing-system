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
    $promote_id = (int)$_POST['id'];
    
    // Check if user is an IT employee
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND department = 'IT' AND role = 'employee'");
    $check_stmt->bind_param("i", $promote_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        $update_stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $update_stmt->bind_param("i", $promote_id);
        
        if ($update_stmt->execute()) {
            security_regenerate_authenticated_session();
            unset($_SESSION['csrf_token']);
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
