<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
require_once '../includes/csrf.php';

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Ensure sales visibility column exists
$colRes = $conn->query("SHOW COLUMNS FROM knowledge_base LIKE 'visible_to_sales'");
if ($colRes && $colRes->num_rows === 0) {
    $conn->query("ALTER TABLE knowledge_base ADD COLUMN visible_to_sales TINYINT(1) NOT NULL DEFAULT 1");
}

// Handle Form Submission (Add/Delete)
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}

// 1. Add New Article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $title = trim($_POST['title']);
    $category = trim($_POST['category']);
    $content = trim($_POST['content']);
    $visible_to_sales = isset($_POST['visible_to_sales']) ? 1 : 0;

    if (!empty($title) && !empty($category) && !empty($content)) {
        
        // Handle Image Upload
        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['image']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_filename = uniqid() . '.' . $ext;
                $upload_path = '../uploads/kb_images/' . $new_filename;
                
                if (!is_dir('../uploads/kb_images')) {
                    mkdir('../uploads/kb_images', 0777, true);
                }

                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                    $image_path = 'uploads/kb_images/' . $new_filename;
                }
            }
        }

        // Handle Reference Links
        $links_json = null;
        if (isset($_POST['link_labels']) && isset($_POST['link_urls'])) {
            $links = [];
            foreach ($_POST['link_labels'] as $index => $label) {
                if (!empty($label) && !empty($_POST['link_urls'][$index])) {
                    $links[] = [
                        'label' => trim($label),
                        'url' => trim($_POST['link_urls'][$index])
                    ];
                }
            }
            if (!empty($links)) {
                $links_json = json_encode($links);
            }
        }

        // Handle Presentation Upload
        $presentation_path = null;
        if (isset($_FILES['presentation']) && $_FILES['presentation']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['ppt', 'pptx'];
            $filename = $_FILES['presentation']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_filename = uniqid() . '_ppt.' . $ext;
                $upload_path = '../uploads/kb_resources/' . $new_filename;
                
                if (!is_dir('../uploads/kb_resources')) {
                    mkdir('../uploads/kb_resources', 0777, true);
                }

                if (move_uploaded_file($_FILES['presentation']['tmp_name'], $upload_path)) {
                    $presentation_path = 'uploads/kb_resources/' . $new_filename;
                }
            }
        }

        // Handle Video (URL or Upload)
        $video_content = null;
        $video_type = $_POST['video_type'] ?? 'none';
        
        if ($video_type === 'url' && !empty($_POST['video_url'])) {
            $video_content = trim($_POST['video_url']);
        } elseif ($video_type === 'upload' && isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['mp4'];
            $filename = $_FILES['video_file']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_filename = uniqid() . '_video.' . $ext;
                $upload_path = '../uploads/kb_resources/' . $new_filename;
                
                if (!is_dir('../uploads/kb_resources')) {
                    mkdir('../uploads/kb_resources', 0777, true);
                }

                if (move_uploaded_file($_FILES['video_file']['tmp_name'], $upload_path)) {
                    $video_content = 'uploads/kb_resources/' . $new_filename;
                }
            }
        }

        $stmt = $conn->prepare("INSERT INTO knowledge_base (title, category, content, image_path, article_links, article_presentation, article_video, visible_to_sales, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssssi", $title, $category, $content, $image_path, $links_json, $presentation_path, $video_content, $visible_to_sales);
        
        if ($stmt->execute()) {
            $new_article_id = $conn->insert_id;
            
            // Handle Related Articles
            if (isset($_POST['related_articles']) && is_array($_POST['related_articles'])) {
                $rel_stmt = $conn->prepare("INSERT INTO kb_related_articles (article_id, related_article_id) VALUES (?, ?)");
                foreach ($_POST['related_articles'] as $rel_id) {
                    $rel_id = (int)$rel_id;
                    if ($rel_id > 0) {
                        $rel_stmt->bind_param("ii", $new_article_id, $rel_id);
                        $rel_stmt->execute();
                    }
                }
            }

            $success_msg = "Article added successfully!";
        } else {
            $error_msg = "Error adding article: " . $conn->error;
        }
    } else {
        $error_msg = "All fields are required.";
    }
}

// 2. Delete Article
// Deletion is now handled by delete_kb.php

// 2.5 Check for Update Success Message
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $success_msg = "Article updated successfully!";
}
if (isset($_GET['error'])) {
    $error_msg = htmlspecialchars($_GET['error']);
}

// 3. Fetch Articles & Calculate Stats
$result = $conn->query("SELECT * FROM knowledge_base ORDER BY created_at DESC");
$articles = [];
$total_views = 0;
$unique_categories = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $articles[] = $row;
        $total_views += isset($row['views']) ? (int)$row['views'] : 0;
        if (!empty($row['category'])) {
            $unique_categories[$row['category']] = true;
        }
    }
}

$total_articles = count($articles);
$categories_count = count($unique_categories);

// Pre-defined categories
$categories = ['Network Issue', 'Hardware Issue', 'Software Issue', 'Email Problem', 'Account Access', 'Technical Support', 'Other'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Knowledge Base | Admin</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <!-- Using Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background-color: #F3F4F6; /* Ensure background is light gray */
        }

        .kb-wrapper {
            padding: 20px 40px 40px; /* Reduced top padding */
            max-width: 1250px; /* Adjusted width */
            margin: 0 auto;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            border-bottom: 3px solid transparent;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-card.green-accent { border-bottom-color: #10B981; }
        .stat-card.blue-accent { border-bottom-color: #3B82F6; }
        .stat-card.purple-accent { border-bottom-color: #8B5CF6; }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.green { background: #ECFDF5; color: #059669; }
        .stat-icon.blue { background: #EFF6FF; color: #2563EB; }
        .stat-icon.purple { background: #F5F3FF; color: #7C3AED; }

        .stat-info h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }

        .stat-info p {
            margin: 0;
            font-size: 13px;
            color: #6B7280;
            font-weight: 500;
        }

        /* Page Header */
        .kb-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 26px; /* Increased size */
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.5px;
        }

        .btn-add-article {
            background-color: #10B981;
            color: white;
            border: none;
            padding: 14px 28px; /* Larger button */
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);
        }

        .btn-add-article:hover {
            background-color: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px -1px rgba(16, 185, 129, 0.4);
        }

        /* Table Card */
        .kb-table-card {
            background: white;
            border-radius: 16px; /* More rounded */
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 10px 15px -3px rgba(0, 0, 0, 0.05); /* Stronger shadow */
            border: 1px solid #E5E7EB;
            overflow: hidden;
            margin-bottom: 40px; /* Spacing at bottom */
        }

        .kb-table-header {
            padding: 20px 30px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #FFFFFF;
        }

        .kb-table-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .kb-table {
            width: 100%;
            border-collapse: collapse;
        }

        .kb-table th, .kb-table td {
            padding: 20px 30px; /* Increased padding */
            text-align: left;
            border-bottom: 1px solid #F3F4F6;
        }

        .kb-table th {
            background-color: #F0FDF4; /* Green tint */
            font-weight: 600;
            color: #065F46; /* Darker green text */
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kb-table tr:last-child td {
            border-bottom: none;
        }

        .kb-table tr:hover {
            background-color: #F9FAFB;
        }

        .kb-table tr {
            cursor: pointer;
        }

        .article-title {
            font-weight: 600;
            color: #111827;
            font-size: 15px;
        }

        .badge-category {
            background-color: #ECFDF5;
            color: #059669;
            padding: 6px 12px;
            border-radius: 6px; /* Slightly less rounded */
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            border: 1px solid #D1FAE5;
        }

        .meta-text {
            color: #6B7280;
            font-size: 14px;
        }

        .actions-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
            flex: 1;
        }

        .btn-delete {
            background-color: #FEF2F2;
            color: #B91C1C;
            border: 1px solid #FEE2E2;
        }

        .btn-delete:hover {
            background-color: #FEE2E2;
            border-color: #FECACA;
        }

        .btn-edit {
            background-color: #EFF6FF;
            color: #2563EB;
            border: 1px solid #DBEAFE;
        }

        .btn-edit:hover {
            background-color: #DBEAFE;
            border-color: #BFDBFE;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-success {
            background-color: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .alert-error {
            background-color: #FEE2E2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6); /* Darker overlay */
            z-index: 1000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease-out;
            backdrop-filter: blur(4px); /* Stronger blur */
        }

        .modal-content {
            background: white;
            padding: 35px;
            border-radius: 16px;
            width: 100%;
            max-width: 650px; /* Slightly wider */
            max-height: 85vh; /* Limit height */
            overflow-y: auto; /* Enable scrolling */
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }

        /* Tab Styles */
        .modal-tabs {
            display: flex;
            border-bottom: 1px solid #E5E7EB;
            margin-bottom: 20px;
        }

        .modal-tab {
            padding: 10px 20px;
            font-weight: 600;
            color: #6B7280;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .modal-tab.active {
            color: #10B981;
            border-bottom-color: #10B981;
        }

        .modal-tab:hover:not(.active) {
            color: #374151;
        }

        /* Preview Content Styles */
        .preview-content {
            background: #F9FAFB;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
            display: none; /* Hidden by default */
        }

        .preview-content h1, .preview-content h2, .preview-content h3 {
            margin-top: 0;
            color: #111827;
        }

        .preview-content p {
            line-height: 1.6;
            color: #374151;
            margin-bottom: 1em;
        }

        .preview-content ul, .preview-content ol {
            padding-left: 20px;
            margin-bottom: 1em;
        }
        
        .preview-content li {
            margin-bottom: 0.5em;
        }

        /* Loading Spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #10B981;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
            vertical-align: middle;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #E5E7EB;
        }

        .modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #9CA3AF;
            cursor: pointer;
            transition: color 0.2s;
            padding: 5px;
            line-height: 1;
            border-radius: 50%;
        }

        .close-modal:hover {
            color: #111827;
            background-color: #F3F4F6;
        }

        /* Form Styles in Modal */
        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 14px;
            border: 1px solid #D1D5DB;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: #10B981;
            outline: none;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        textarea.form-control {
            min-height: 180px;
            resize: vertical;
            font-family: inherit;
            line-height: 1.5;
        }

        .btn-submit {
            background-color: #10B981;
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 10px;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            font-size: 16px;
            transition: background-color 0.2s;
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
        }

        .btn-submit:hover {
            background-color: #059669;
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(16, 185, 129, 0.3);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .kb-wrapper {
                padding: 20px;
            }
            .modal-content {
                margin: 20px;
                max-width: calc(100% - 40px);
            }
        }

        /* Resources Styles */
        .resources-wrapper {
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .resources-title {
            grid-column: 1 / -1; /* Make title span full width */
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .resource-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #F3F4F6;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
            margin-bottom: 0; /* Override previous margin */
        }
        .resource-item:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-color: #E5E7EB;
        }
        .resource-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .video-toggles {
            display: flex;
            background: #F3F4F6;
            padding: 4px;
            border-radius: 8px;
            width: fit-content;
            margin-bottom: 15px;
        }
        .video-toggle-btn {
            padding: 6px 14px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #6B7280;
            transition: all 0.2s;
        }
        .video-toggle-btn.active {
            background-color: white;
            color: #10B981;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        .link-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }
        .btn-add-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #10B981;
            background: rgba(16, 185, 129, 0.1);
            padding: 8px 14px;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-add-link:hover {
            background: rgba(16, 185, 129, 0.2);
        }
        .btn-remove-link {
            background: none; 
            border: none; 
            color: #EF4444; 
            cursor: pointer;
            padding: 5px;
        }

        /* Related Articles Styles */
        .search-results-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #D1D5DB;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .search-result-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #F3F4F6;
            color: #374151;
            font-size: 14px;
        }
        .search-result-item:hover {
            background-color: #F9FAFB;
            color: #10B981;
        }
        .selected-articles-list {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .selected-article-item {
            background: #EFF6FF;
            border: 1px solid #DBEAFE;
            color: #1E40AF;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .remove-related-btn {
            background: none;
            border: none;
            color: #EF4444;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 0;
            display: flex;
            align-items: center;
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="kb-wrapper">
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="kb-page-header">
            <div class="page-title">Knowledge Base</div>
            <button class="btn-add-article" onclick="openModal()">
                <i class="fas fa-plus"></i> Add New Article
            </button>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card green-accent">
                <div class="stat-icon green">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_articles ?></h3>
                    <p>Total Articles</p>
                </div>
            </div>
            <div class="stat-card blue-accent">
                <div class="stat-icon blue">
                    <i class="far fa-eye"></i>
                </div>
                <div class="stat-info">
                    <h3><?= number_format($total_views) ?></h3>
                    <p>Total Views</p>
                </div>
            </div>
            <div class="stat-card purple-accent">
                <div class="stat-icon purple">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $categories_count ?></h3>
                    <p>Categories</p>
                </div>
            </div>
        </div>

        <!-- LIST TABLE -->
        <div class="kb-table-card">
            <div class="kb-table-header">
                <div class="kb-table-title">Published Articles</div>
                <div style="font-size: 13px; color: #6B7280; background: #F3F4F6; padding: 4px 10px; border-radius: 20px;">
                    Showing <strong><?= $total_articles ?></strong> articles
                </div>
            </div>

            <table class="kb-table">
                <thead>
                    <tr>
                        <th width="40%">Article Title</th>
                        <th width="20%">Category</th>
                        <th width="15%">Views</th>
                        <th width="15%">Created Date</th>
                        <th width="15%" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($articles) > 0): ?>
                        <?php foreach($articles as $row): ?>
                            <tr>
                                <td>
                                    <div class="article-title"><?= htmlspecialchars($row['title']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-category">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </span>
                                </td>
                                <td class="meta-text">
                                    <i class="far fa-eye" style="margin-right: 5px;"></i> 
                                    <?= isset($row['views']) ? number_format($row['views']) : '0' ?>
                                </td>
                                <td class="meta-text">
                                    <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="actions-cell">
                                        <a href="edit_kb.php?id=<?= $row['id'] ?>" class="action-btn btn-edit">
                                            <i class="fas fa-pencil-alt"></i> Edit
                                        </a>
                                        <a href="javascript:void(0)" 
                                           class="action-btn btn-delete"
                                           onclick="confirmDelete(<?= $row['id'] ?>)">
                                            <i class="fas fa-trash-alt"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 80px 40px; color: #9CA3AF;">
                                <div style="font-size: 56px; margin-bottom: 20px; color: #E5E7EB;"><i class="fas fa-book-open"></i></div>
                                <div style="font-size: 18px; font-weight: 700; color: #374151;">No articles found</div>
                                <div style="font-size: 15px; margin-top: 8px;">Get started by creating your first article.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Add Article Modal -->
<div id="addArticleModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-plus-circle" style="color: #10B981;"></i>
                Add New Article
            </div>
            <button class="close-modal" onclick="closeModal()">&times;</button>
        </div>
        
        <form method="POST" action="" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="add">
            
            <div class="form-group">
                <label class="form-label">Article Title</label>
                <input type="text" name="title" class="form-control" required placeholder="e.g. How to reset password">
            </div>

            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control" required>
                    <option value=""disabled selected hidden>Select Category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="visible_to_sales" value="1" checked>
                    Visible to Sales users
                </label>
            </div>

            <div class="form-group">
                <label class="form-label">Article Image (Optional)</label>
                <input type="file" name="image" class="form-control" accept="image/*" onchange="previewAddImage(this)">
                <div id="add-image-preview" style="margin-top: 10px; display: none;">
                    <img id="add-image-preview-img" src="" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 1px solid #ddd;">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Content</label>
                <textarea name="content" class="form-control" required placeholder="Write article content here..."></textarea>
            </div>

            <!-- New Resources Section -->
            <div class="resources-wrapper">
                <div class="resources-title">
                    <i class="fas fa-paperclip" style="color: #10B981;"></i> Additional Resources
                </div>

                <div class="resource-item">
                    <label class="resource-label">Reference Links</label>
                    <div id="link-container">
                        <div class="link-row">
                            <input type="text" name="link_labels[]" class="form-control" placeholder="Link Label" style="flex: 1;">
                            <input type="url" name="link_urls[]" class="form-control" placeholder="URL" style="flex: 2;">
                            <button type="button" class="btn-remove-link" onclick="removeLink(this)"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <button type="button" class="btn-add-link" onclick="addLink()"><i class="fas fa-plus"></i> Add Link</button>
                </div>

                <div class="resource-item">
                    <label class="resource-label">Presentation (PPT/PPTX)</label>
                    <input type="file" name="presentation" class="form-control" accept=".ppt, .pptx">
                    <small style="color: #6B7280; display: block; margin-top: 5px;">Supported formats: .ppt, .pptx</small>
                </div>

                <div class="resource-item">
                    <label class="resource-label">Video </label>
                    <input type="hidden" name="video_type" id="video_type_input" value="upload">
                    
                    <div class="video-toggles-wrapper" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
                        <div class="video-toggles">
                            <button type="button" class="video-toggle-btn active" onclick="setVideoType('upload', this)">Upload Video</button>
                            <button type="button" class="video-toggle-btn" onclick="setVideoType('url', this)">YouTube URL</button>
                        </div>
                        <button type="button" id="btn-remove-video" onclick="removeVideo()" style="background:none; border:none; color: #EF4444; font-size: 13px; font-weight: 500; cursor: pointer; display: none; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 6px; transition: background 0.2s;">
                            <i class="fas fa-trash-alt"></i> Remove Video
                        </button>
                    </div>
                    
                    <div id="video-upload-input">
                        <input type="file" name="video_file" class="form-control" accept=".mp4" onchange="updateRemoveButtonVisibility()">
                        <small style="color: #6B7280; display: block; margin-top: 5px;">Supported format: .mp4</small>
                    </div>
                    
                    <div id="video-url-input" style="display: none;">
                        <input type="url" name="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=..." oninput="updateRemoveButtonVisibility()">
                    </div>
                </div>


            </div>

            <button type="submit" name="add_article" class="btn-submit">Publish Article</button>
        </form>
    </div>
</div>

<script>
function addLink() {
    const container = document.getElementById('link-container');
    const div = document.createElement('div');
    div.className = 'link-row';
    div.innerHTML = `
        <input type="text" name="link_labels[]" class="form-control" placeholder="Link Label" style="flex: 1;">
        <input type="url" name="link_urls[]" class="form-control" placeholder="URL" style="flex: 2;">
        <button type="button" class="btn-remove-link" onclick="removeLink(this)"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(div);
}

function removeLink(btn) {
    btn.closest('.link-row').remove();
}

function setVideoType(type, btn) {
    document.getElementById('video_type_input').value = type;
    document.querySelectorAll('.video-toggle-btn').forEach(b => b.classList.remove('active'));
    if(btn) btn.classList.add('active');
    
    document.getElementById('video-url-input').style.display = (type === 'url') ? 'block' : 'none';
    document.getElementById('video-upload-input').style.display = (type === 'upload') ? 'block' : 'none';
    
    updateRemoveButtonVisibility();
}

function updateRemoveButtonVisibility() {
    const type = document.getElementById('video_type_input').value;
    const removeBtn = document.getElementById('btn-remove-video');
    if (!removeBtn) return;

    let hasContent = false;
    let btnText = 'Remove Video';

    if (type === 'upload') {
        btnText = 'Remove Video';
        const fileInput = document.querySelector('input[name="video_file"]');
        if (fileInput && fileInput.files.length > 0) {
            hasContent = true;
        }
    } else if (type === 'url') {
        btnText = 'Remove Link';
        const urlInput = document.querySelector('input[name="video_url"]');
        if (urlInput && urlInput.value.trim() !== '') {
            hasContent = true;
        }
    }

    removeBtn.innerHTML = '<i class="fas fa-trash-alt"></i> ' + btnText;
    removeBtn.style.display = hasContent ? 'inline-flex' : 'none';
}

function removeVideo() {
    const type = document.getElementById('video_type_input').value;
    
    if (type === 'upload') {
        const fileInput = document.querySelector('input[name="video_file"]');
        if (fileInput) fileInput.value = '';
    } else if (type === 'url') {
        const urlInput = document.querySelector('input[name="video_url"]');
        if (urlInput) urlInput.value = '';
    }
    
    updateRemoveButtonVisibility();
}

let searchTimeout;

function searchRelatedArticles(input) {
    clearTimeout(searchTimeout);
    const resultsDiv = document.getElementById('related-search-results');
    const query = input.value.trim();
    
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }

    searchTimeout = setTimeout(() => {
        fetch('search_kb_articles.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                resultsDiv.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(article => {
                        // Check if already selected
                        if (!document.querySelector(`input[name="related_articles[]"][value="${article.id}"]`)) {
                            const div = document.createElement('div');
                            div.className = 'search-result-item';
                            div.textContent = article.title;
                            div.onclick = () => addRelatedArticle(article.id, article.title);
                            resultsDiv.appendChild(div);
                        }
                    });
                    resultsDiv.style.display = resultsDiv.children.length > 0 ? 'block' : 'none';
                } else {
                    resultsDiv.style.display = 'none';
                }
            })
            .catch(err => {
                console.error('Error fetching articles:', err);
                resultsDiv.style.display = 'none';
            });
    }, 300);
}

function addRelatedArticle(id, title) {
    const container = document.getElementById('selected-related-articles');
    // Check if already exists (double check)
    if (document.querySelector(`input[name="related_articles[]"][value="${id}"]`)) return;

    const div = document.createElement('div');
    div.className = 'selected-article-item';
    div.innerHTML = `
        <input type="hidden" name="related_articles[]" value="${id}">
        <span>${title}</span>
        <button type="button" class="remove-related-btn" onclick="this.parentElement.remove()">&times;</button>
    `;
    container.appendChild(div);
    
    document.getElementById('related-search-input').value = '';
    document.getElementById('related-search-results').style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.search-results-dropdown') && !e.target.closest('#related-search-input')) {
        const dropdown = document.getElementById('related-search-results');
        if (dropdown) dropdown.style.display = 'none';
    }
});
</script>

<script src="../js/admin.js"></script>
<script>
    // Add Modal Logic
    const addModal = document.getElementById('addArticleModal');
    
    function openModal() {
        addModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function previewAddImage(input) {
        const previewDiv = document.getElementById('add-image-preview');
        const previewImg = document.getElementById('add-image-preview-img');
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewDiv.style.display = 'block';
            }
            reader.readAsDataURL(input.files[0]);
        } else {
            previewDiv.style.display = 'none';
        }
    }

    function closeModal() {
        addModal.style.display = 'none';
        document.body.style.overflow = '';
        // Reset preview
        document.getElementById('add-image-preview').style.display = 'none';
        document.querySelector('#addArticleModal form').reset();
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        if (event.target == addModal) {
            closeModal();
        }
    }

    // SweetAlert2 Delete Confirmation
    function confirmDelete(id) {
        Swal.fire({
            title: 'Delete this article?',
            text: "This action cannot be undone.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            reverseButtons: true, // Typically better UX to have primary action on right or distinct
            customClass: {
                popup: 'swal2-rounded'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'delete_kb.php?id=' + id;
            }
        });
    }

    // Check for success message in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('msg') === 'deleted') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: false,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer)
                toast.addEventListener('mouseleave', Swal.resumeTimer)
            }
        });

        Toast.fire({
            icon: 'success',
            title: 'Article deleted'
        });
        
        // Clean URL to prevent showing toast again on refresh
        const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: newUrl}, '', newUrl);
    }
</script>

</body>
</html>
