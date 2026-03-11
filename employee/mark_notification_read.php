<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    exit;
}

if (isset($_POST['id'])) {
    csrf_validate();
    $id = (int)$_POST['id'];
    $user_id = $_SESSION['user_id'];
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $id AND user_id = $user_id");
}
?>
