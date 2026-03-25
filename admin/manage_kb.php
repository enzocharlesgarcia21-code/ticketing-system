<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/kb_media.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
$subCategoryColRes = $conn->query("SHOW COLUMNS FROM knowledge_base LIKE 'sub_category'");
if ($subCategoryColRes && $subCategoryColRes->num_rows === 0) {
    $conn->query("ALTER TABLE knowledge_base ADD COLUMN sub_category VARCHAR(255) NULL AFTER category");
}
kb_ensure_image_path_column_supports_multiple($conn);

// Handle Form Submission (Add/Delete)
$success_msg = '';
$error_msg = '';

if (empty($_SESSION['kb_add_submission_token'])) {
    $_SESSION['kb_add_submission_token'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
}

// 1. Add New Article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $submitted_token = trim((string) ($_POST['submission_token'] ?? ''));
    $session_token = trim((string) ($_SESSION['kb_add_submission_token'] ?? ''));
    $title = trim($_POST['title']);
    $category = trim($_POST['category']);
    $sub_category = trim((string) ($_POST['sub_category'] ?? ''));
    $content = trim($_POST['content']);
    $visible_to_sales = 1;

    if ($submitted_token === '' || $session_token === '' || !hash_equals($session_token, $submitted_token)) {
        $error_msg = "This article form has expired. Please reopen the form and try again.";
    }

    if (strcasecmp($category, 'Others') === 0 && $sub_category === '') {
        $error_msg = "Sub-category is required for Others.";
    }
    if (strcasecmp($category, 'Others') !== 0) {
        $sub_category = null;
    }

    if ($error_msg === '' && !empty($title) && !empty($category)) {
        
        $image_path = null;
        $image_upload_error = '';
        $stored_image_paths = kb_store_uploaded_images($_FILES['image'] ?? null, $image_upload_error);

        if ($stored_image_paths === false) {
            $error_msg = $image_upload_error;
        } elseif (is_array($stored_image_paths) && count($stored_image_paths) > 0) {
            $image_path = kb_encode_image_paths($stored_image_paths);
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

        $stmt = $conn->prepare("INSERT INTO knowledge_base (title, category, sub_category, content, image_path, article_links, article_presentation, article_video, visible_to_sales, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssssi", $title, $category, $sub_category, $content, $image_path, $links_json, $presentation_path, $video_content, $visible_to_sales);

        if ($error_msg !== '') {
            // Preserve the upload error already set above.
        } elseif ($stmt->execute()) {
            $_SESSION['kb_add_submission_token'] = bin2hex(random_bytes(16));
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

            header("Location: manage_kb.php?msg=added");
            exit();
        } else {
            $error_msg = "Error adding article: " . $conn->error;
        }
    } else {
        if ($error_msg === '') {
            $error_msg = "Title and category are required.";
        }
    }
}

// 2. Delete Article
// Deletion is now handled by delete_kb.php

// 2.5 Check for Update Success Message
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $success_msg = "Article updated successfully!";
}
if (isset($_GET['msg']) && $_GET['msg'] === 'added') {
    $success_msg = "Article added successfully!";
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
$categories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support', 'Others'];
$category_meta = [
    'Documentation' => ['icon' => 'fa-file-lines', 'tone' => 'teal'],
    'Email' => ['icon' => 'fa-envelope', 'tone' => 'sand'],
    'Hardware' => ['icon' => 'fa-screwdriver-wrench', 'tone' => 'violet'],
    'Internet Concerns' => ['icon' => 'fa-globe', 'tone' => 'blue'],
    'Procurement' => ['icon' => 'fa-cart-shopping', 'tone' => 'emerald'],
    'Software' => ['icon' => 'fa-desktop', 'tone' => 'sky'],
    'Technical Support' => ['icon' => 'fa-headset', 'tone' => 'mint'],
    'Others' => ['icon' => 'fa-folder', 'tone' => 'slate'],
    'Uncategorized' => ['icon' => 'fa-folder-open', 'tone' => 'slate'],
];
$category_aliases = [
    'network issue' => 'Internet Concerns',
    'hardware issue' => 'Hardware',
    'software issue' => 'Software',
    'email problem' => 'Email',
    'account access' => 'Technical Support',
];
$articles_by_category = [];
foreach ($categories as $category_name) {
    $articles_by_category[$category_name] = [];
}
foreach ($articles as $article_row) {
    $category_name = trim((string) ($article_row['category'] ?? ''));
    if ($category_name === '') {
        $category_name = 'Uncategorized';
    }
    $category_lookup_key = strtolower($category_name);
    if (isset($category_aliases[$category_lookup_key])) {
        $category_name = $category_aliases[$category_lookup_key];
        $article_row['category'] = $category_name;
    }
    if ($category_name !== 'Uncategorized' && !in_array($category_name, $categories, true)) {
        $category_name = 'Others';
    }
    if (!isset($articles_by_category[$category_name])) {
        $articles_by_category[$category_name] = [];
    }
    $articles_by_category[$category_name][] = $article_row;
}
$category_order = $categories;
if (!empty($articles_by_category['Uncategorized'])) {
    $category_order[] = 'Uncategorized';
}
$default_open_category = '';
foreach ($category_order as $category_name) {
    if (!empty($articles_by_category[$category_name])) {
        $default_open_category = $category_name;
        break;
    }
}
$recent_articles = $articles;
$recent_articles_per_page = 4;
$recent_articles_total = count($recent_articles);
$recent_articles_total_pages = max(1, (int) ceil($recent_articles_total / $recent_articles_per_page));
$recent_articles_page = isset($_GET['recent_page']) ? (int) $_GET['recent_page'] : 1;
if ($recent_articles_page < 1) {
    $recent_articles_page = 1;
}
if ($recent_articles_page > $recent_articles_total_pages) {
    $recent_articles_page = $recent_articles_total_pages;
}
$recent_articles_offset = ($recent_articles_page - 1) * $recent_articles_per_page;
$recent_articles_page_items = array_slice($recent_articles, $recent_articles_offset, $recent_articles_per_page);
$recent_articles_query = $_GET;
unset($recent_articles_query['recent_page']);
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
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.16)),
                url('../assets/img/kbkb.jpg');
            background-repeat: no-repeat;
            background-position: center top;
            background-attachment: fixed;
            background-size: cover;
        }

        .kb-wrapper {
            padding: 28px 36px 48px;
            max-width: 1160px;
            margin: 0 auto;
        }

        .kb-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .page-title {
            font-size: 28px;
            font-weight: 800;
            color: #172033;
            letter-spacing: -0.03em;
            margin: 0;
        }

        .btn-add-article {
            background: #166534;
            color: white;
            border: none;
            padding: 15px 28px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: transform 0.2s, box-shadow 0.2s, filter 0.2s;
            box-shadow: 0 10px 24px rgba(13, 93, 34, 0.28);
        }

        .btn-add-article:hover {
            transform: translateY(-2px);
            filter: brightness(0.98);
            box-shadow: 0 14px 28px rgba(13, 93, 34, 0.32);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 18px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.78);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 18px 20px;
            border-radius: 20px;
            border: 1px solid rgba(216, 221, 241, 0.9);
            box-shadow: 0 14px 30px rgba(80, 71, 123, 0.08);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 36px rgba(80, 71, 123, 0.12);
        }

        .stat-card.green-accent { box-shadow: inset 0 -3px 0 #18b48f, 0 14px 30px rgba(80, 71, 123, 0.08); }
        .stat-card.blue-accent { box-shadow: inset 0 -3px 0 #5793ff, 0 14px 30px rgba(80, 71, 123, 0.08); }
        .stat-card.purple-accent { box-shadow: inset 0 -3px 0 #a370ff, 0 14px 30px rgba(80, 71, 123, 0.08); }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .stat-icon.green { background: #ECFDF5; color: #059669; }
        .stat-icon.blue { background: #EFF6FF; color: #2563EB; }
        .stat-icon.purple { background: #F5F3FF; color: #7C3AED; }

        .stat-info h3 {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #111827;
        }

        .stat-info p {
            margin: 2px 0 0;
            font-size: 14px;
            color: #5f6980;
            font-weight: 500;
        }

        .kb-section-card {
            background: rgba(255, 255, 255, 0.74);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(225, 227, 245, 0.95);
            border-radius: 22px;
            box-shadow: 0 18px 42px rgba(92, 84, 132, 0.1);
            overflow: hidden;
        }

        .kb-table-card {
            margin-bottom: 26px;
            min-height: 520px;
        }

        .kb-section-header,
        .kb-table-header {
            padding: 22px 28px;
            border-bottom: 1px solid rgba(228, 231, 245, 0.95);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .kb-section-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .kb-section-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #607089 0%, #4d5970 100%);
            color: #fff;
            font-size: 17px;
        }

        .kb-table-title {
            font-size: 17px;
            font-weight: 700;
            color: #1f2937;
        }

        .kb-section-pill {
            font-size: 13px;
            color: #6B7280;
            background: rgba(243, 244, 246, 0.92);
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 500;
        }

        .kb-list-head {
            display: grid;
            grid-template-columns: minmax(0, 2.2fr) minmax(150px, 1fr) 120px 150px 190px;
            gap: 18px;
            padding: 16px 28px;
            background: rgba(249, 250, 253, 0.95);
            font-weight: 600;
            color: #667085;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .kb-list-body,
        .kb-article-stack {
            padding: 18px;
        }

        .kb-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 0 18px 18px;
            flex-wrap: wrap;
        }

        .kb-pagination-summary {
            font-size: 14px;
            color: #667085;
            font-weight: 500;
        }

        .kb-pagination-links {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .kb-page-link {
            min-width: 40px;
            height: 40px;
            padding: 0 15px;
            border-radius: 999px;
            border: 1px solid #d7e2ea;
            background: #ffffff;
            color: #475467;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .kb-page-link:hover {
            transform: translateY(-1px);
            border-color: #cbd5e1;
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.08);
        }

        .kb-page-link.is-active {
            background: #166534;
            border-color: #166534;
            color: #fff;
            box-shadow: 0 10px 18px rgba(22, 101, 52, 0.22);
        }

        .kb-page-link.is-disabled {
            opacity: 0.45;
            pointer-events: none;
            box-shadow: none;
        }

        .kb-page-link:first-child,
        .kb-page-link:last-child {
            min-width: 110px;
            padding: 0 18px;
        }

        .kb-categories-card {
            margin-top: 8px;
        }

        .kb-category-grid-admin {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            padding: 18px;
        }

        .kb-category-tile {
            width: 100%;
            text-align: left;
            border: 1px solid rgba(224, 228, 241, 0.95);
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.76);
            padding: 18px 20px;
            cursor: pointer;
            box-shadow: 0 10px 26px rgba(66, 58, 99, 0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
        }

        .kb-category-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(66, 58, 99, 0.11);
            border-color: rgba(120, 140, 210, 0.45);
        }

        .kb-category-tile.is-active {
            border-color: rgba(92, 132, 255, 0.36);
            background: rgba(245, 247, 255, 0.92);
            box-shadow: 0 16px 32px rgba(88, 105, 182, 0.14);
        }

        .kb-category-tile-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }

        .tone-teal .kb-category-tile-icon,
        .tone-teal.badge-category {
            background: linear-gradient(135deg, #6fd0c3 0%, #4099a0 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-sand .kb-category-tile-icon,
        .tone-sand.badge-category {
            background: linear-gradient(135deg, #f0d48f 0%, #d9b764 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-violet .kb-category-tile-icon,
        .tone-violet.badge-category {
            background: linear-gradient(135deg, #b895ff 0%, #8668f1 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-blue .kb-category-tile-icon,
        .tone-blue.badge-category {
            background: linear-gradient(135deg, #74b7ff 0%, #4f86ff 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-emerald .kb-category-tile-icon,
        .tone-emerald.badge-category {
            background: linear-gradient(135deg, #5fd1b4 0%, #1ca57d 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-sky .kb-category-tile-icon,
        .tone-sky.badge-category {
            background: linear-gradient(135deg, #7eb9ff 0%, #5d8fff 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-mint .kb-category-tile-icon,
        .tone-mint.badge-category {
            background: linear-gradient(135deg, #6fd8da 0%, #43b4c5 100%);
            color: #fff;
            border-color: transparent;
        }

        .tone-slate .kb-category-tile-icon,
        .tone-slate.badge-category {
            background: linear-gradient(135deg, #b5bfd0 0%, #7d8ca5 100%);
            color: #fff;
            border-color: transparent;
        }

        .kb-category-tile-name {
            margin: 0 0 4px;
            font-size: 19px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.25;
        }

        .kb-category-tile-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #68738a;
            font-size: 13px;
            font-weight: 500;
        }

        .kb-category-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 22px 28px;
            border-bottom: 1px solid rgba(228, 231, 245, 0.95);
            flex-wrap: wrap;
        }

        .kb-category-panel-title {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .kb-category-panel-title i {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #65748d 0%, #465267 100%);
            color: #fff;
            font-size: 17px;
        }

        .kb-view-panel {
            min-height: 100%;
            display: flex;
            flex-direction: column;
        }

        .kb-view-panel.is-hidden {
            display: none;
        }

        .kb-item {
            display: grid;
            grid-template-columns: minmax(0, 2.2fr) minmax(150px, 1fr) 120px 150px 190px;
            gap: 18px;
            align-items: center;
            padding: 16px 20px;
            margin-bottom: 0;
            background: rgba(255, 255, 255, 0.55);
            border-top: 1px solid rgba(231, 233, 244, 0.95);
        }

        .kb-item:hover {
            background: rgba(255, 255, 255, 0.78);
        }

        .kb-col-label {
            display: none;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6B7280;
            margin-bottom: 6px;
        }

        .article-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 15px;
            line-height: 1.45;
        }

        .badge-category {
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
        }

        .kb-item .badge-category {
            background: rgba(243, 244, 246, 0.96);
            color: #475467;
            border: 1px solid rgba(217, 222, 231, 0.95);
            box-shadow: none;
        }

        .meta-text {
            color: #667085;
            font-size: 14px;
        }

        .actions-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .action-btn {
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
            min-width: 92px;
            box-shadow: 0 10px 18px rgba(76, 86, 120, 0.08);
        }

        .kb-empty-state {
            text-align: center;
            padding: 80px 40px;
            color: #9CA3AF;
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
            background-color: #ECFDF5;
            color: #166534;
            border: 1px solid #BBF7D0;
        }

        .btn-edit:hover {
            background-color: #D1FAE5;
            border-color: #86EFAC;
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
            padding: 28px;
            border-radius: 16px;
            width: min(90vw, 1080px);
            max-width: 1080px;
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
            margin-bottom: 20px;
            padding-bottom: 16px;
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
            margin-bottom: 20px;
        }

        .kb-modal-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 400px;
            gap: 24px;
            align-items: stretch;
        }

        .kb-modal-main-column {
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        .kb-modal-side-column {
            min-width: 0;
            padding-left: 2px;
            border-left: 1px solid #E5E7EB;
        }
        .kb-modal-side-column::before {
            content: none;
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
            min-height: 132px;
            resize: vertical;
            font-family: inherit;
            line-height: 1.5;
            overflow-y: hidden;
        }
        .kb-create-content {
            height: 240px;
            min-height: 240px;
            max-height: 240px;
            resize: none;
            overflow-y: auto !important;
            overflow-x: hidden;
            scrollbar-gutter: stable;
            word-break: break-word;
        }
        .kb-content-group {
            flex: 1 1 auto;
        }
        .kb-image-preview-grid {
            margin-top: 10px;
            display: none;
            gap: 10px;
            flex-wrap: wrap;
        }
        .kb-image-preview-item {
            position: relative;
            width: 120px;
            height: 120px;
            overflow: visible;
        }
        .kb-image-preview-item img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid #D1D5DB;
            background: #F8FAFC;
        }
        .kb-image-preview-remove {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 24px;
            height: 24px;
            padding: 0;
            border: 2px solid #fff;
            border-radius: 999px;
            background: #EF4444;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
            z-index: 2;
        }
        .kb-image-dropzone {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 120px;
            padding: 24px 18px;
            border: 1.5px dashed #C7D2DA;
            border-radius: 16px;
            background: linear-gradient(180deg, #FCFEFD 0%, #F8FBFA 100%);
            color: #4B5563;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }
        .kb-image-dropzone:hover,
        .kb-image-dropzone.is-active {
            border-color: #166534;
            background: #F0FDF4;
            box-shadow: 0 0 0 4px rgba(22, 101, 52, 0.08);
        }
        .kb-image-dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .kb-image-dropzone-icon {
            font-size: 24px;
            color: #1F8A57;
        }
        .kb-image-dropzone-title {
            font-size: 15px;
            font-weight: 700;
            color: #374151;
        }
        .kb-image-dropzone-subtitle,
        .kb-image-dropzone-note {
            font-size: 13px;
            color: #6B7280;
        }

        .btn-submit {
            background-color: #166534;
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 10px;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            font-size: 16px;
            transition: background-color 0.2s;
            box-shadow: 0 4px 6px -1px rgba(22, 101, 52, 0.22);
        }

        .btn-submit:hover {
            background-color: #14532D;
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(22, 101, 52, 0.32);
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
            .kb-category-grid-admin {
                grid-template-columns: 1fr;
            }
            .modal-content {
                margin: 20px;
                width: calc(100% - 40px);
                max-width: calc(100% - 40px);
            }
            .kb-modal-form-grid {
                grid-template-columns: 1fr;
                gap: 22px;
            }
            .kb-modal-side-column {
                padding-left: 0;
                border-left: none;
                border-top: 1px solid #E5E7EB;
                padding-top: 22px;
            }
        }

        /* Resources Styles */
        .resources-wrapper {
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .resources-title {
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
        .kb-side-submit {
            margin-top: 4px;
        }
        .kb-main-submit-wrap {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-top: 8px;
            padding-top: 0;
            border-top: none;
            align-items: center;
        }
        .kb-main-submit-wrap .btn-submit {
            width: 320px;
            max-width: 100%;
            flex: 0 0 auto;
            margin: 0 auto;
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
        .kb-visible-toggle{
            display:flex;
            align-items:center;
            gap:10px;
            margin:0;
        }
        .kb-visible-toggle input[type="checkbox"]{
            width:18px !important;
            height:18px !important;
            min-height:0 !important;
            padding:0 !important;
            margin:0 !important;
            accent-color:#10B981;
            flex:0 0 auto;
        }

        @media (max-width: 1080px) {
            .kb-table-card {
                min-height: auto;
            }

            .kb-list-head {
                display: none;
            }

            .kb-item {
                grid-template-columns: 1fr;
                gap: 14px;
                align-items: start;
            }

            .kb-col-label {
                display: block;
            }

            .actions-cell {
                justify-content: flex-start;
            }

            .kb-pagination {
                flex-direction: column;
                align-items: stretch;
            }

            .kb-pagination-links {
                justify-content: center;
            }
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

        <div class="kb-table-card kb-section-card">
            <?php if (count($articles) > 0): ?>
                <div class="kb-view-panel" data-articles-view="recent">
                    <div class="kb-category-panel-head">
                        <h3 class="kb-category-panel-title">
                            <i class="fas fa-file-lines"></i>
                            Recent Articles
                        </h3>
                    </div>

                    <div class="kb-list-head">
                        <div>Article Title</div>
                        <div>Category</div>
                        <div>Views</div>
                        <div>Created Date</div>
                        <div style="text-align:center;">Actions</div>
                    </div>

                    <div class="kb-article-stack">
                        <?php foreach ($recent_articles_page_items as $row): ?>
                            <?php $row_meta = $category_meta[$row['category']] ?? $category_meta['Uncategorized']; ?>
                            <div class="kb-item">
                                <div>
                                    <div class="kb-col-label">Article Title</div>
                                    <div class="article-title"><?= htmlspecialchars($row['title']) ?></div>
                                </div>
                                <div>
                                    <div class="kb-col-label">Category</div>
                                    <span class="badge-category tone-<?= htmlspecialchars($row_meta['tone']) ?>">
                                        <?= htmlspecialchars($row['category']) ?>
                                    </span>
                                </div>
                                <div class="meta-text">
                                    <div class="kb-col-label">Views</div>
                                    <i class="far fa-eye" style="margin-right: 5px;"></i>
                                    <?= isset($row['views']) ? number_format($row['views']) : '0' ?>
                                </div>
                                <div class="meta-text">
                                    <div class="kb-col-label">Created Date</div>
                                    <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                </div>
                                <div>
                                    <div class="kb-col-label">Actions</div>
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
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($recent_articles_total_pages > 1): ?>
                        <?php
                        $recent_articles_start = $recent_articles_offset + 1;
                        $recent_articles_end = min($recent_articles_offset + $recent_articles_per_page, $recent_articles_total);
                        $recent_prev_query = $recent_articles_query;
                        $recent_prev_query['recent_page'] = max(1, $recent_articles_page - 1);
                        $recent_next_query = $recent_articles_query;
                        $recent_next_query['recent_page'] = min($recent_articles_total_pages, $recent_articles_page + 1);
                        ?>
                        <div class="kb-pagination">
                            <div class="kb-pagination-summary">
                                Showing <?= $recent_articles_start ?>-<?= $recent_articles_end ?> of <?= $recent_articles_total ?> recent articles
                            </div>
                            <div class="kb-pagination-links">
                                <a
                                    href="?<?= htmlspecialchars(http_build_query($recent_prev_query), ENT_QUOTES, 'UTF-8') ?>"
                                    class="kb-page-link<?= $recent_articles_page <= 1 ? ' is-disabled' : '' ?>"
                                >&lsaquo; Previous</a>
                                <?php for ($page = 1; $page <= $recent_articles_total_pages; $page++): ?>
                                    <?php $recent_page_query = $recent_articles_query; ?>
                                    <?php $recent_page_query['recent_page'] = $page; ?>
                                    <a
                                        href="?<?= htmlspecialchars(http_build_query($recent_page_query), ENT_QUOTES, 'UTF-8') ?>"
                                        class="kb-page-link<?= $page === $recent_articles_page ? ' is-active' : '' ?>"
                                    >
                                        <?= $page ?>
                                    </a>
                                <?php endfor; ?>
                                <a
                                    href="?<?= htmlspecialchars(http_build_query($recent_next_query), ENT_QUOTES, 'UTF-8') ?>"
                                    class="kb-page-link<?= $recent_articles_page >= $recent_articles_total_pages ? ' is-disabled' : '' ?>"
                                >Next &rsaquo;</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php foreach ($category_order as $category_name): ?>
                    <?php $category_articles = $articles_by_category[$category_name] ?? []; ?>
                    <?php if (empty($category_articles)) continue; ?>
                    <?php $panel_meta = $category_meta[$category_name] ?? $category_meta['Uncategorized']; ?>
                    <div class="kb-view-panel is-hidden" data-articles-view="<?= htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="kb-category-panel-head">
                            <h3 class="kb-category-panel-title">
                                <i class="fas <?= htmlspecialchars($panel_meta['icon']) ?>"></i>
                                <?= htmlspecialchars($category_name) ?>
                            </h3>
                        </div>

                        <div class="kb-list-head">
                            <div>Article Title</div>
                            <div>Category</div>
                            <div>Views</div>
                            <div>Created Date</div>
                            <div style="text-align:center;">Actions</div>
                        </div>

                        <div class="kb-article-stack">
                            <?php foreach ($category_articles as $row): ?>
                                <?php $row_meta = $category_meta[$row['category']] ?? $category_meta['Uncategorized']; ?>
                                <div class="kb-item">
                                    <div>
                                        <div class="kb-col-label">Article Title</div>
                                        <div class="article-title"><?= htmlspecialchars($row['title']) ?></div>
                                    </div>
                                    <div>
                                        <div class="kb-col-label">Category</div>
                                        <span class="badge-category tone-<?= htmlspecialchars($row_meta['tone']) ?>">
                                            <?= htmlspecialchars($row['category']) ?>
                                        </span>
                                    </div>
                                    <div class="meta-text">
                                        <div class="kb-col-label">Views</div>
                                        <i class="far fa-eye" style="margin-right: 5px;"></i>
                                        <?= isset($row['views']) ? number_format($row['views']) : '0' ?>
                                    </div>
                                    <div class="meta-text">
                                        <div class="kb-col-label">Created Date</div>
                                        <?= date('M d, Y', strtotime($row['created_at'])) ?>
                                    </div>
                                    <div>
                                        <div class="kb-col-label">Actions</div>
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
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="kb-list-body">
                    <div class="kb-empty-state">
                        <div style="font-size: 56px; margin-bottom: 20px; color: #E5E7EB;"><i class="fas fa-book-open"></i></div>
                        <div style="font-size: 18px; font-weight: 700; color: #374151;">No articles found</div>
                        <div style="font-size: 15px; margin-top: 8px;">Get started by creating your first article.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (count($articles) > 0): ?>
            <div class="kb-section-card kb-categories-card">
                <div class="kb-section-header">
                    <div class="kb-section-title-wrap">
                        <span class="kb-section-icon" style="background: linear-gradient(135deg, #4aa99b 0%, #3f8d86 100%);">
                            <i class="fas fa-folder"></i>
                        </span>
                        <div class="kb-table-title">Categories</div>
                    </div>
                </div>

                <div class="kb-category-grid-admin">
                    <?php foreach ($category_order as $category_name): ?>
                        <?php $category_articles = $articles_by_category[$category_name] ?? []; ?>
                        <?php if (empty($category_articles)) continue; ?>
                        <?php $meta = $category_meta[$category_name] ?? $category_meta['Uncategorized']; ?>
                        <button
                            type="button"
                            class="kb-category-tile tone-<?= htmlspecialchars($meta['tone']) ?>"
                            data-category-target="<?= htmlspecialchars($category_name, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <span class="kb-category-tile-icon">
                                <i class="fas <?= htmlspecialchars($meta['icon']) ?>"></i>
                            </span>
                            <div>
                                <div class="kb-category-tile-name"><?= htmlspecialchars($category_name) ?></div>
                                <div class="kb-category-tile-meta">
                                    <span><?= count($category_articles) ?> Article<?= count($category_articles) === 1 ? '' : 's' ?></span>
                                </div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

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
            <input type="hidden" name="submission_token" value="<?= htmlspecialchars((string) ($_SESSION['kb_add_submission_token'] ?? '')) ?>">
            <div class="kb-modal-form-grid">
                <div class="kb-modal-main-column">
                    <div class="form-group">
                        <label class="form-label">Article Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. How to reset password">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control" id="kb-category-select" required>
                            <option value=""disabled selected hidden>Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input
                            type="text"
                            name="sub_category"
                            id="kb-sub-category"
                            class="form-control"
                            placeholder="Enter category (e.g. Printer)"
                            style="display:none; margin-top: 12px;"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Upload Image (Optional)</label>
                        <label class="kb-image-dropzone" id="kb-image-dropzone">
                            <input type="file" name="image[]" id="kb-image-input" class="form-control" accept="image/*" multiple onchange="previewAddImage(this)">
                            <div class="kb-image-dropzone-icon"><i class="fas fa-folder-open"></i></div>
                            <div class="kb-image-dropzone-title">Drag &amp; drop images here,</div>
                            <div class="kb-image-dropzone-subtitle">or click to upload</div>
                            <div class="kb-image-dropzone-note">You can upload multiple images.</div>
                        </label>
                        <div id="add-image-preview" class="kb-image-preview-grid"></div>
                    </div>

                    <div class="form-group kb-content-group">
                        <label class="form-label">Content</label>
                        <textarea name="content" class="form-control kb-create-content" rows="6" placeholder="Write article content here..."></textarea>
                    </div>

                </div>

                <div class="kb-modal-side-column">
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

                </div>
            </div>

            <div class="kb-main-submit-wrap">
                <button type="submit" name="add_article" class="btn-submit" id="kb-publish-btn">Publish Article</button>
            </div>
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

document.addEventListener('DOMContentLoaded', function () {
    const tiles = document.querySelectorAll('[data-category-target]');
    const panels = document.querySelectorAll('[data-articles-view]');
    const recentPanel = document.querySelector('[data-articles-view="recent"]');
    if (!tiles.length || !panels.length || !recentPanel) return;

    function showArticlesView(target) {
        panels.forEach(function (panel) {
            panel.classList.toggle('is-hidden', panel.getAttribute('data-articles-view') !== target);
        });
    }

    showArticlesView('recent');

    tiles.forEach(function (tile) {
        tile.addEventListener('click', function () {
            const wasActive = tile.classList.contains('is-active');
            const target = tile.getAttribute('data-category-target');

            if (wasActive) {
                tiles.forEach(function (item) {
                    item.classList.remove('is-active');
                });
                showArticlesView('recent');
                return;
            }

            tiles.forEach(function (item) {
                item.classList.toggle('is-active', item === tile);
            });
            showArticlesView(target);
        });
    });
});

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
    const kbImageInput = document.getElementById('kb-image-input');
    const kbImageDropzone = document.getElementById('kb-image-dropzone');
    const kbCategorySelect = document.getElementById('kb-category-select');
    const kbSubCategory = document.getElementById('kb-sub-category');
    let addImageDataTransfer = new DataTransfer();
    
    function openModal() {
        addModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function previewAddImage(input) {
        const previewDiv = document.getElementById('add-image-preview');
        if (!previewDiv) return;

        previewDiv.innerHTML = '';

        if (input.files && input.files.length > 0) {
            addImageDataTransfer = new DataTransfer();
            Array.from(input.files).forEach(function(file) {
                addImageDataTransfer.items.add(file);
            });
        }

        if (addImageDataTransfer.files && addImageDataTransfer.files.length > 0) {
            previewDiv.style.display = 'flex';

            Array.from(addImageDataTransfer.files).forEach(function(file, index) {
                if (!file.type || file.type.indexOf('image/') !== 0) {
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    const item = document.createElement('div');
                    item.className = 'kb-image-preview-item';

                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = '';

                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'kb-image-preview-remove';
                    removeBtn.type = 'button';
                    removeBtn.textContent = 'x';
                    removeBtn.setAttribute('aria-label', 'Remove image');
                    removeBtn.onclick = function() {
                        const nextFiles = new DataTransfer();
                        Array.from(addImageDataTransfer.files).forEach(function(existingFile, existingIndex) {
                            if (existingIndex !== index) {
                                nextFiles.items.add(existingFile);
                            }
                        });
                        addImageDataTransfer = nextFiles;
                        input.files = addImageDataTransfer.files;
                        previewAddImage(input);
                    };

                    item.appendChild(img);
                    item.appendChild(removeBtn);
                    previewDiv.appendChild(item);
                };
                reader.readAsDataURL(file);
            });
        } else {
            previewDiv.style.display = 'none';
        }
    }

    function mergeDroppedImages(fileList) {
        if (!kbImageInput || !fileList || !fileList.length) return;
        const nextFiles = new DataTransfer();

        Array.from(addImageDataTransfer.files || []).forEach(function(file) {
            nextFiles.items.add(file);
        });

        Array.from(fileList).forEach(function(file) {
            if (file.type && file.type.indexOf('image/') === 0) {
                nextFiles.items.add(file);
            }
        });

        addImageDataTransfer = nextFiles;
        kbImageInput.files = addImageDataTransfer.files;
        previewAddImage(kbImageInput);
    }

    if (kbImageDropzone && kbImageInput) {
        ['dragenter', 'dragover'].forEach(function(eventName) {
            kbImageDropzone.addEventListener(eventName, function(event) {
                event.preventDefault();
                kbImageDropzone.classList.add('is-active');
            });
        });

        ['dragleave', 'dragend', 'drop'].forEach(function(eventName) {
            kbImageDropzone.addEventListener(eventName, function(event) {
                event.preventDefault();
                if (eventName !== 'drop') {
                    kbImageDropzone.classList.remove('is-active');
                }
            });
        });

        kbImageDropzone.addEventListener('drop', function(event) {
            kbImageDropzone.classList.remove('is-active');
            const droppedFiles = event.dataTransfer ? event.dataTransfer.files : null;
            if (droppedFiles && droppedFiles.length) {
                mergeDroppedImages(droppedFiles);
            }
        });
    }

    function syncSubCategoryVisibility() {
        if (!kbCategorySelect || !kbSubCategory) return;
        const isOthers = kbCategorySelect.value === 'Others';
        kbSubCategory.style.display = isOthers ? 'block' : 'none';
        kbSubCategory.required = isOthers;
        if (!isOthers) {
            kbSubCategory.value = '';
        }
    }

    if (kbCategorySelect) {
        kbCategorySelect.addEventListener('change', syncSubCategoryVisibility);
        syncSubCategoryVisibility();
    }

    const kbAddForm = document.querySelector('#addArticleModal form');
    const kbPublishBtn = document.getElementById('kb-publish-btn');
    if (kbAddForm && kbPublishBtn) {
        kbAddForm.addEventListener('submit', function() {
            kbPublishBtn.disabled = true;
            kbPublishBtn.textContent = 'Publishing...';
        });
    }

    function closeModal() {
        addModal.style.display = 'none';
        document.body.style.overflow = '';
        // Reset preview
        const previewDiv = document.getElementById('add-image-preview');
        if (previewDiv) {
            previewDiv.style.display = 'none';
            previewDiv.innerHTML = '';
        }
        addImageDataTransfer = new DataTransfer();
        document.querySelector('#addArticleModal form').reset();
        syncCustomCategoryVisibility();
        if (kbPublishBtn) {
            kbPublishBtn.disabled = false;
            kbPublishBtn.textContent = 'Publish Article';
        }
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
    if (urlParams.get('msg') === 'deleted' || urlParams.get('msg') === 'added') {
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
            title: urlParams.get('msg') === 'added' ? 'Article added' : 'Article deleted'
        });
        
        // Clean URL to prevent showing toast again on refresh
        const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: newUrl}, '', newUrl);
    }

    function autoResizeTextarea(textarea) {
        if (!textarea) return;
        if (textarea.classList.contains('kb-create-content')) return;
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    document.querySelectorAll('textarea.form-control').forEach(function(textarea) {
        autoResizeTextarea(textarea);
        textarea.addEventListener('input', function() {
            autoResizeTextarea(textarea);
        });
    });
</script>

</body>
</html>
