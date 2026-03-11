<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

if (isset($_GET['id'])) {
    $article_id = (int)$_GET['id'];
    
    // First get the image path to delete the file if it exists
    $stmt = $conn->prepare("SELECT image_path, article_presentation, article_video FROM knowledge_base WHERE id = ?");
    $stmt->bind_param("i", $article_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (!empty($row['image_path']) && file_exists('../' . $row['image_path'])) {
            unlink('../' . $row['image_path']);
        }
        // Delete Presentation
        if (!empty($row['article_presentation']) && file_exists('../' . $row['article_presentation'])) {
            unlink('../' . $row['article_presentation']);
        }
        // Delete Video (if uploaded)
        if (!empty($row['article_video']) && strpos($row['article_video'], 'uploads/') === 0 && file_exists('../' . $row['article_video'])) {
            unlink('../' . $row['article_video']);
        }
    }
    
    // Now delete the record
    $delete_stmt = $conn->prepare("DELETE FROM knowledge_base WHERE id = ?");
    $delete_stmt->bind_param("i", $article_id);
    
    if ($delete_stmt->execute()) {
        header("Location: manage_kb.php?msg=deleted");
        exit();
    } else {
        header("Location: manage_kb.php?error=Error deleting article");
        exit();
    }
} else {
    header("Location: manage_kb.php");
    exit();
}
