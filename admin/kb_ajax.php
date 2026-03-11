<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json');

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

csrf_validate();

$action = $_POST['action'] ?? '';

if ($action === 'fetch') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, title, category, content, image_path, created_at, updated_at FROM knowledge_base WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Article not found']);
    }
    $stmt->close();
}
elseif ($action === 'update') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if ($id <= 0 || empty($title) || empty($category) || empty($content)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }

    $image_path = null;
    $has_image_upload = false;
    $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

    // Handle Image Upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/kb_images/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $new_filename = uniqid('kb_', true) . '.' . $file_extension;
        $target_file = $upload_dir . $new_filename;
        
        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            $image_path = 'uploads/kb_images/' . $new_filename;
            $has_image_upload = true;
        }
    }

    if ($has_image_upload) {
        $stmt = $conn->prepare("UPDATE knowledge_base SET title = ?, category = ?, content = ?, image_path = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $category, $content, $image_path, $id);
    } elseif ($remove_image) {
        $null_path = null;
        $stmt = $conn->prepare("UPDATE knowledge_base SET title = ?, category = ?, content = ?, image_path = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $title, $category, $content, $null_path, $id);
    } else {
        $stmt = $conn->prepare("UPDATE knowledge_base SET title = ?, category = ?, content = ? WHERE id = ?");
        $stmt->bind_param("sssi", $title, $category, $content, $id);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Article updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
}
else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>
