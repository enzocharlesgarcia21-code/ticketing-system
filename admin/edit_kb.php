<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/kb_media.php';

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
kb_ensure_image_path_column_supports_multiple($conn);

$success_msg = '';
$error_msg = '';
$article = null;

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: manage_kb.php");
    exit();
}

$article_id = (int)$_GET['id'];

// Fetch Article Details
$stmt = $conn->prepare("SELECT * FROM knowledge_base WHERE id = ?");
$stmt->bind_param("i", $article_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: manage_kb.php?error=Article not found");
    exit();
}

$article = $result->fetch_assoc();
$article_image_paths = kb_extract_image_paths($article['image_path'] ?? null);
$article_image_urls = kb_resolve_asset_urls($article['image_path'] ?? null, '../');
$kb_department_categories = [
    'HR',
    'IT',
    'Accounting',
    'Marketing',
    'Admin & Legal',
    'Management',
    'Technical',
    'Diagnostics / Lingap',
];
$kb_default_ticket_categories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software'];
$kb_ticket_categories_by_department = [
    'Admin & Legal' => [
        'Phone Plan / Simcard',
        'FleetCard Request',
        'Supplies',
    ],
    'HR' => [
        'Attendance & Timekeeping',
        'Certificate of Employment',
        'Certificate of Leave',
        'Leave Concern',
        'Medical Cash Advance',
        'Request for Company Property',
        'SSS Sickness and Benefit Concern',
        'Training Request',
        'Others',
    ],
    'IT' => [
        'Documentation',
        'Email',
        'Hardware',
        'Internet Concerns',
        'Procurement',
        'SAP',
        'Software',
    ],
    'Marketing' => [
        'Marketing Request',
    ],
    'Accounting' => $kb_default_ticket_categories,
    'Management' => $kb_default_ticket_categories,
    'Technical' => $kb_default_ticket_categories,
    'Diagnostics / Lingap' => $kb_default_ticket_categories,
];

function kb_sorted_links_json($labels, $urls) {
    $links = [];
    if (is_array($labels) && is_array($urls)) {
        foreach ($labels as $index => $label) {
            $label = trim((string) $label);
            $url = trim((string) ($urls[$index] ?? ''));
            if ($label !== '' && $url !== '') {
                $links[] = [
                    'label' => $label,
                    'url' => $url,
                ];
            }
        }
    }

    if (count($links) === 0) {
        return null;
    }

    usort($links, function ($a, $b) {
        return strcmp(($a['label'] ?? '') . '|' . ($a['url'] ?? ''), ($b['label'] ?? '') . '|' . ($b['url'] ?? ''));
    });

    return json_encode($links);
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $title = trim($_POST['title']);
    $category = trim($_POST['category']);
    $sub_category = trim((string) ($_POST['sub_category'] ?? ''));
    $content = trim($_POST['content']);
    $visible_to_sales = 1;

    if (!empty($title) && !empty($category) && !empty($sub_category)) {
        $original_category = trim((string) ($article['category'] ?? ''));
        $original_sub_category = trim((string) ($article['sub_category'] ?? ''));
        $allowed_categories = $kb_department_categories;
        if ($original_category !== '' && !in_array($original_category, $allowed_categories, true)) {
            $allowed_categories[] = $original_category;
        }
        if (!in_array($category, $allowed_categories, true)) {
            $error_msg = "Please select a valid department.";
        }
        if ($error_msg === '') {
            $allowed_sub_categories = $kb_ticket_categories_by_department[$category] ?? $kb_default_ticket_categories;
            if ($original_sub_category !== '' && !in_array($original_sub_category, $allowed_sub_categories, true)) {
                $allowed_sub_categories[] = $original_sub_category;
            }
            if (!in_array($sub_category, $allowed_sub_categories, true)) {
                $error_msg = "Please select a valid category for the selected department.";
            }
        }

        $original_image_path = $article['image_path'];
        $original_image_paths = kb_extract_image_paths($original_image_path);
        $removed_existing_images = json_decode((string) ($_POST['remove_existing_images'] ?? '[]'), true);
        if (!is_array($removed_existing_images)) {
            $removed_existing_images = [];
        }
        $removed_existing_images = kb_extract_image_paths($removed_existing_images);
        $kept_existing_images = array_values(array_filter($original_image_paths, function ($path) use ($removed_existing_images) {
            return !in_array($path, $removed_existing_images, true);
        }));

        $image_upload_error = '';
        $stored_image_paths = kb_store_uploaded_images($_FILES['image'] ?? null, $image_upload_error);
        if ($stored_image_paths === false) {
            $error_msg = $image_upload_error;
        }
        $new_image_paths = is_array($stored_image_paths) ? $stored_image_paths : [];
        $image_path = kb_encode_image_paths(array_merge($kept_existing_images, $new_image_paths));

        // Handle Reference Links
        $links_json = kb_sorted_links_json($_POST['link_labels'] ?? [], $_POST['link_urls'] ?? []);
        $original_links_json = kb_sorted_links_json(
            array_column((array) json_decode($article['article_links'] ?? '[]', true), 'label'),
            array_column((array) json_decode($article['article_links'] ?? '[]', true), 'url')
        );

        // Handle Presentation
        $presentation_path = $article['article_presentation'];
        if (isset($_POST['remove_presentation']) && $_POST['remove_presentation'] == '1') {
             if ($presentation_path && file_exists('../' . $presentation_path)) {
                unlink('../' . $presentation_path);
            }
            $presentation_path = null;
        }
        if (isset($_FILES['presentation']) && $_FILES['presentation']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['ppt', 'pptx'];
            $filename = $_FILES['presentation']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                // Delete old
                 if ($presentation_path && file_exists('../' . $presentation_path)) {
                    unlink('../' . $presentation_path);
                }
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

        // Handle Video
        $video_content = $article['article_video'];
        $video_type = $_POST['video_type'] ?? 'none';
        
        if ($video_type === 'url') {
            if (!empty($_POST['video_url'])) {
                 if ($video_content && strpos($video_content, 'uploads/') === 0 && file_exists('../' . $video_content)) {
                    unlink('../' . $video_content);
                 }
                 $video_content = trim($_POST['video_url']);
            }
        } elseif ($video_type === 'upload') {
            if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
                 $allowed = ['mp4'];
                $filename = $_FILES['video_file']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                     if ($video_content && strpos($video_content, 'uploads/') === 0 && file_exists('../' . $video_content)) {
                        unlink('../' . $video_content);
                     }
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
        } elseif ($video_type === 'remove') {
            if ($video_content && strpos($video_content, 'uploads/') === 0 && file_exists('../' . $video_content)) {
                unlink('../' . $video_content);
            }
            $video_content = null;
        }

        $original_title = trim((string) ($article['title'] ?? ''));
        $original_content = trim((string) ($article['content'] ?? ''));
        $original_presentation_path = $article['article_presentation'];
        $original_video_content = $article['article_video'];
        $original_visible_to_sales = isset($article['visible_to_sales']) ? (int) $article['visible_to_sales'] : 1;

        $has_changes = (
            $title !== $original_title ||
            $category !== $original_category ||
            $sub_category !== $original_sub_category ||
            $content !== $original_content ||
            (string) $image_path !== (string) $original_image_path ||
            (string) $links_json !== (string) $original_links_json ||
            (string) $presentation_path !== (string) $original_presentation_path ||
            (string) $video_content !== (string) $original_video_content ||
            (int) $visible_to_sales !== (int) $original_visible_to_sales
        );

        $update_stmt = $conn->prepare("UPDATE knowledge_base SET title = ?, category = ?, sub_category = ?, content = ?, image_path = ?, article_links = ?, article_presentation = ?, article_video = ?, visible_to_sales = ? WHERE id = ?");
        $update_stmt->bind_param("ssssssssii", $title, $category, $sub_category, $content, $image_path, $links_json, $presentation_path, $video_content, $visible_to_sales, $article_id);
        
        if ($error_msg !== '') {
            // Preserve the upload error already set above.
        } elseif (!$has_changes) {
            if (!empty($new_image_paths)) {
                kb_delete_uploaded_file($new_image_paths);
            }
            $error_msg = "No changes were made.";
        } elseif ($update_stmt->execute()) {
            if (!empty($removed_existing_images)) {
                kb_delete_uploaded_file($removed_existing_images);
            }
            header("Location: manage_kb.php?msg=updated");
            exit();
        } else {
            if (!empty($new_image_paths)) {
                kb_delete_uploaded_file($new_image_paths);
            }
            $error_msg = "Error updating article: " . $conn->error;
        }
    } else {
        $error_msg = "Title, department, and category are required.";
    }
}

// Pre-defined categories
$categories = $kb_department_categories;
$current_category = trim((string) ($article['category'] ?? ''));
$is_legacy_category = ($current_category !== '' && !in_array($current_category, $categories, true));
$selected_category = $current_category;
$current_sub_category = trim((string) ($article['sub_category'] ?? ''));
$selected_sub_category = $current_sub_category;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Article | Admin</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #F3F4F6;
            color: #1F2937;
        }
        .edit-container {
            padding: 40px;
            max-width: 1180px;
            width: 95%;
            margin: 0 auto;
        }
        .edit-card {
            background: white;
            padding: 34px 34px 28px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid #E5E7EB;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-back {
            background-color: white;
            color: #374151;
            border: 1px solid #D1D5DB;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .btn-back:hover {
            background-color: #10B981;
            border-color: #059669;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.4);
        }
        .form-group {
            margin-bottom: 30px;
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
            padding: 12px 16px;
            border: 1px solid #D1D5DB;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .form-control:focus {
            border-color: #3B82F6;
            outline: none;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .custom-category-field {
            margin-top: 12px;
        }
        textarea.form-control {
            min-height: 240px;
            resize: none;
            line-height: 1.6;
            font-family: inherit;
            overflow-y: auto;
        }
        .kb-editor-shell {
            border: 1px solid #D1D5DB;
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .kb-editor-shell:focus-within {
            border-color: #3B82F6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .kb-editor-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            padding: 10px 12px;
            background: #F8FAFC;
            border-bottom: 1px solid #E5E7EB;
        }
        .kb-editor-btn {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: #1F2937;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
        }
        .kb-editor-btn:hover {
            background: #E5E7EB;
            color: #111827;
        }
        .kb-editor-separator {
            width: 1px;
            height: 28px;
            background: #D1D5DB;
            margin: 0 4px;
        }
        .kb-editor-content {
            min-height: 260px;
            padding: 18px 20px;
            border: none;
            border-radius: 0;
            box-shadow: none;
            line-height: 1.6;
            overflow-y: auto;
        }
        .kb-editor-content:empty::before {
            content: attr(data-placeholder);
            color: #9CA3AF;
        }
        .kb-edit-content-input {
            display: none;
        }
        .kb-edit-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) 400px;
            gap: 24px;
            align-items: start;
        }
        .kb-edit-main-column {
            min-width: 0;
        }
        .kb-edit-side-column {
            min-width: 0;
            padding-left: 24px;
            border-left: 1px solid #E5E7EB;
        }
        .btn-save {
            background-color: #166534;
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s, box-shadow 0.2s;
            width: 320px;
            max-width: 100%;
            box-shadow: 0 4px 6px -1px rgba(22, 101, 52, 0.22);
        }
        .btn-save:hover {
            background-color: #14532D;
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(22, 101, 52, 0.32);
        }
        .current-image-preview-grid,
        .kb-image-preview-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .current-image-preview,
        .kb-image-preview-item {
            position: relative;
            width: 120px;
            height: 96px;
            border-radius: 10px;
        }
        .current-image-preview img,
        .kb-image-preview-item img {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: 10px;
            border: 1px solid #E5E7EB;
            object-fit: cover;
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
        .btn-remove-image {
            position: absolute;
            top: -10px;
            right: -10px;
            background: #EF4444;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            padding: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 16px rgba(0,0,0,0.18);
            z-index: 2;
            line-height: 1;
        }
        .btn-remove-image i {
            pointer-events: none;
            font-size: 12px;
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
            transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
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
        .kb-image-selection-note {
            margin-top: 10px;
            font-size: 13px;
            color: #6B7280;
        }
        .current-image-preview-empty {
            color: #6B7280;
            font-style: italic;
            font-size: 14px;
        }
        .alert-error {
            background-color: #FEE2E2;
            color: #991B1B;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #FECACA;
        }

        /* New Resource Section Styles */
        .resources-wrapper {
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px;
            margin-top: 0;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .resources-title {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
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
            border: 1px solid #E5E7EB;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        
        .resource-item:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-color: #E5E7EB;
        }

        .resource-label {
            font-size: 15px;
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
            border-radius: 10px;
            width: fit-content;
            margin-bottom: 20px;
            gap: 2px;
        }

        .video-toggle-btn {
            padding: 10px 24px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #6B7280;
            transition: all 0.2s;
        }

        .video-toggle-btn.active {
            background-color: white;
            color: #2563EB;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            font-weight: 600;
        }

        .video-input-container {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            padding: 20px;
            border-radius: 10px;
        }

        .current-file-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background-color: #EFF6FF;
            color: #1E40AF;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 10px;
            border: 1px solid #DBEAFE;
        }

        .btn-remove-file {
            background: none;
            border: none;
            color: #EF4444;
            cursor: pointer;
            font-size: 14px;
            padding: 2px;
            display: flex;
            align-items: center;
        }
        
        .btn-remove-file:hover {
            color: #B91C1C;
        }
        
        .link-row {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
        }
        
        .link-label-input {
            flex: 1;
        }
        
        .link-url-input {
            flex: 2;
        }
        
        .btn-remove-link {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            border: 1px solid #FECACA;
            background: #FEF2F2;
            color: #EF4444;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-remove-link:hover {
            background: #FEE2E2;
            border-color: #FCA5A5;
        }
        
        .btn-add-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #10B981;
            background: white;
            padding: 10px 18px;
            border-radius: 8px;
            border: 1px solid #10B981;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-add-link:hover {
            background: #ECFDF5;
        }

        .form-actions {
            margin-top: 14px;
            display: flex;
            justify-content: center;
            align-items: center;
            padding-top: 0;
            border-top: none;
        }

        /* Custom File Button */
        .custom-file-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: white;
            border: 1px solid #D1D5DB;
            color: #374151;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .custom-file-btn:hover {
            background-color: #F9FAFB;
            border-color: #9CA3AF;
        }

        .custom-file-input {
            display: none;
        }

        .file-name-display {
            margin-left: 10px;
            font-size: 13px;
            color: #6B7280;
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
        @media (max-width: 980px) {
            .edit-container {
                padding: 24px;
            }
            .edit-card {
                padding: 24px 22px;
            }
            .kb-edit-form-grid {
                grid-template-columns: 1fr;
                gap: 22px;
            }
            .kb-edit-side-column {
                padding-left: 0;
                border-left: none;
                border-top: 1px solid #E5E7EB;
                padding-top: 22px;
            }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="admin-page">
    <?php include '../includes/admin_navbar.php'; ?>

    <div class="edit-container">
        <div class="page-header">
            <a href="manage_kb.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <div class="page-title">
                <i class="fas fa-edit" style="color: #3B82F6;"></i> Edit Article
            </div>
        </div>

        <?php if ($error_msg): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div class="edit-card">
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <div class="kb-edit-form-grid">
                    <div class="kb-edit-main-column">
                        <div class="form-group">
                            <label class="form-label">Article Title</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($article['title']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <select name="category" class="form-control" id="kb-edit-category-select" required>
                                <option value="" disabled selected hidden>Select Department</option>
                                <?php if ($is_legacy_category): ?>
                                    <option value="<?= htmlspecialchars($current_category, ENT_QUOTES, 'UTF-8') ?>" selected>
                                        <?= htmlspecialchars($current_category, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>" <?= $selected_category === $cat ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="sub_category" class="form-control" id="kb-edit-sub-category-select" required>
                                <option value="" disabled selected hidden>Select department first</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Upload Image (Optional)</label>
                            <div id="current-image-section">
                                <?php if (!empty($article_image_urls)): ?>
                                    <div class="current-image-preview-grid" id="image-preview-container">
                                        <?php foreach ($article_image_urls as $index => $image_url): ?>
                                            <div class="current-image-preview" data-image-path="<?= htmlspecialchars($article_image_paths[$index] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                <img src="<?= htmlspecialchars($image_url) ?>" alt="Article Image">
                                                <button type="button" class="btn-remove-image" onclick="removeExistingImage(this)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="current-image-preview-empty" id="image-preview-empty">No image uploaded</div>
                                <?php endif; ?>
                                <input type="hidden" name="remove_existing_images" id="remove_existing_images" value="[]">
                            </div>
                            
                            <div style="margin-top: 12px;">
                                <label class="kb-image-dropzone" id="kb-image-dropzone">
                                    <input type="file" name="image[]" id="kb-image-input" class="form-control" accept="image/*" multiple>
                                    <div class="kb-image-dropzone-icon"><i class="fas fa-folder-open"></i></div>
                                    <div class="kb-image-dropzone-title">Drag &amp; drop images here,</div>
                                    <div class="kb-image-dropzone-subtitle">or click to upload</div>
                                    <div class="kb-image-dropzone-note">You can upload multiple images.</div>
                                </label>
                                <div class="kb-image-selection-note" id="kb-image-selection-note"></div>
                                <div id="new-image-preview" class="kb-image-preview-grid" style="display:none;"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Content</label>
                            <div class="kb-editor-shell" id="kb-edit-editor-shell">
                                <div class="kb-editor-toolbar" role="toolbar" aria-label="Article content formatting">
                                    <button type="button" class="kb-editor-btn" data-editor-action="bold" title="Bold" aria-label="Bold"><strong>B</strong></button>
                                    <button type="button" class="kb-editor-btn" data-editor-action="italic" title="Italic" aria-label="Italic"><em>I</em></button>
                                    <button type="button" class="kb-editor-btn" data-editor-action="underline" title="Underline" aria-label="Underline"><u>U</u></button>
                                    <button type="button" class="kb-editor-btn" data-editor-action="heading" title="Heading" aria-label="Heading"><i class="fas fa-font"></i></button>
                                    <span class="kb-editor-separator" aria-hidden="true"></span>
                                    <button type="button" class="kb-editor-btn" data-editor-action="bulleted" title="Bullet list" aria-label="Bullet list"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" class="kb-editor-btn" data-editor-action="numbered" title="Numbered list" aria-label="Numbered list"><i class="fas fa-list-ol"></i></button>
                                    <button type="button" class="kb-editor-btn" data-editor-action="quote" title="Paragraph quote" aria-label="Paragraph quote"><i class="fas fa-paragraph"></i></button>
                                </div>
                                <div class="kb-editor-content" id="kb-edit-editor-content" contenteditable="true" data-placeholder="Write article content here..." aria-label="Article content"></div>
                                <textarea name="content" id="kb-edit-content-input" class="kb-edit-content-input"><?= htmlspecialchars($article['content']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="kb-edit-side-column">
                        <div class="resources-wrapper">
                    <div class="resources-title">
                        <i class="fas fa-paperclip" style="color: #10B981;"></i> Additional Resources
                    </div>

                    <!-- Reference Links -->
                    <?php $links = json_decode($article['article_links'] ?? '[]', true); ?>
                    <div class="resource-item">
                        <label class="resource-label">Reference Links</label>
                        <div id="link-container">
                            <?php if (!empty($links)): ?>
                                <?php foreach ($links as $link): ?>
                                    <div class="link-row">
                                        <input type="text" name="link_labels[]" class="form-control link-label-input" placeholder="Link Label" value="<?= htmlspecialchars($link['label']) ?>">
                                        <input type="url" name="link_urls[]" class="form-control link-url-input" placeholder="URL" value="<?= htmlspecialchars($link['url']) ?>">
                                        <button type="button" class="btn-remove-link" onclick="removeLink(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn-add-link" onclick="addLink()"><i class="fas fa-plus"></i> Add Link</button>
                    </div>

                    <!-- Presentation -->
                    <div class="resource-item">
                        <label class="resource-label">Presentation (PPT/PPTX)</label>
                        <?php if ($article['article_presentation']): ?>
                            <div id="presentation-preview" class="current-file-badge">
                                <i class="fas fa-file-powerpoint" style="color: #E05333;"></i>
                                <span><?= basename($article['article_presentation']) ?></span>
                                <button type="button" class="btn-remove-file" onclick="removePresentation()" title="Remove Presentation">
                                    <i class="fas fa-times"></i>
                                </button>
                                <input type="hidden" name="remove_presentation" id="remove_presentation" value="0">
                            </div>
                            <div style="margin-top: 10px; display: flex; align-items: center;">
                                <label class="custom-file-btn">
                                    <i class="fas fa-upload" style="color: #6B7280;"></i> Replace File
                                    <input type="file" name="presentation" class="custom-file-input" accept=".ppt, .pptx" onchange="updateFileName(this, 'pres-filename')">
                                </label>
                                <span id="pres-filename" class="file-name-display"></span>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; align-items: center;">
                                <label class="custom-file-btn">
                                    <i class="fas fa-upload" style="color: #6B7280;"></i> Choose File
                                    <input type="file" name="presentation" class="custom-file-input" accept=".ppt, .pptx" onchange="updateFileName(this, 'pres-filename')">
                                </label>
                                <span id="pres-filename" class="file-name-display"></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Video Tutorial -->
                    <div class="resource-item">
                        <label class="resource-label">Video</label>
                        <?php
                            $video_type = 'upload'; // Default to upload if none
                            $video_url_val = '';
                            if ($article['article_video']) {
                                if (strpos($article['article_video'], 'uploads/') === 0) {
                                    $video_type = 'upload';
                                } else {
                                    $video_type = 'url';
                                    $video_url_val = $article['article_video'];
                                }
                            }
                        ?>
                        <input type="hidden" name="video_type" id="video_type_input" value="<?= $video_type ?>">
                        
                        <div class="video-toggles-wrapper" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px;">
                            <div class="video-toggles">
                                <button type="button" class="video-toggle-btn <?= ($video_type === 'upload') ? 'active' : '' ?>" onclick="setVideoType('upload', this)">Upload Video</button>
                                <button type="button" class="video-toggle-btn <?= ($video_type === 'url') ? 'active' : '' ?>" onclick="setVideoType('url', this)">YouTube URL</button>
                            </div>
                            <button type="button" id="btn-remove-video" onclick="removeVideo()" style="background:none; border:none; color: #EF4444; font-size: 13px; font-weight: 500; cursor: pointer; display: none; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 6px; transition: background 0.2s;">
                                <i class="fas fa-trash-alt"></i> Remove Video
                            </button>
                        </div>

                        <div id="video-upload-input" class="video-input-container" style="display: <?= ($video_type === 'upload') ? 'block' : 'none' ?>;">
                            <?php if ($article['article_video'] && strpos($article['article_video'], 'uploads/') === 0): ?>
                                <div class="current-file-badge">
                                    <i class="fas fa-video" style="color: #3B82F6;"></i>
                                    <span><?= basename($article['article_video']) ?></span>
                                </div>
                                <div style="margin-top: 10px; display: flex; align-items: center;">
                                    <label class="custom-file-btn">
                                        <i class="fas fa-upload" style="color: #6B7280;"></i> Replace Video
                                        <input type="file" name="video_file" class="custom-file-input" accept=".mp4" onchange="handleFileChange(this, 'video-filename')">
                                    </label>
                                    <span id="video-filename" class="file-name-display"></span>
                                </div>
                            <?php else: ?>
                                <div style="display: flex; align-items: center;">
                                    <label class="custom-file-btn">
                                        <i class="fas fa-upload" style="color: #6B7280;"></i> Choose Video
                                        <input type="file" name="video_file" class="custom-file-input" accept=".mp4" onchange="handleFileChange(this, 'video-filename')">
                                    </label>
                                    <span id="video-filename" class="file-name-display"></span>
                                </div>
                            <?php endif; ?>
                            <small style="color: #9CA3AF; display: block; margin-top: 8px; font-size: 12px;">Supported format: .mp4</small>
                        </div>

                        <div id="video-url-input" class="video-input-container" style="display: <?= ($video_type === 'url') ? 'block' : 'none' ?>;">
                            <input type="url" name="video_url" class="form-control" placeholder="https://www.youtube.com/watch?v=..." value="<?= htmlspecialchars($video_url_val) ?>" oninput="updateRemoveButtonVisibility()">
                        </div>
                    </div>

                    
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../js/admin.js"></script>
<script>
    const kbEditDepartmentCategories = <?= json_encode($kb_ticket_categories_by_department, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const kbEditCurrentSubCategory = <?= json_encode($selected_sub_category, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const kbEditDepartmentSelect = document.getElementById('kb-edit-category-select');
    const kbEditSubCategorySelect = document.getElementById('kb-edit-sub-category-select');

    function syncEditSubCategoryOptions(preferredValue) {
        if (!kbEditDepartmentSelect || !kbEditSubCategorySelect) return;

        const selectedDepartment = kbEditDepartmentSelect.value || '';
        const categoryOptions = kbEditDepartmentCategories[selectedDepartment] || [];
        const targetValue = preferredValue || '';
        kbEditSubCategorySelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.hidden = true;
        placeholder.textContent = selectedDepartment ? 'Select Category' : 'Select department first';
        kbEditSubCategorySelect.appendChild(placeholder);

        categoryOptions.forEach(function(categoryName) {
            const option = document.createElement('option');
            option.value = categoryName;
            option.textContent = categoryName;
            option.selected = categoryName === targetValue;
            kbEditSubCategorySelect.appendChild(option);
        });

        if (targetValue && !categoryOptions.includes(targetValue)) {
            const legacyOption = document.createElement('option');
            legacyOption.value = targetValue;
            legacyOption.textContent = targetValue;
            legacyOption.selected = true;
            kbEditSubCategorySelect.appendChild(legacyOption);
        }

        kbEditSubCategorySelect.disabled = !selectedDepartment;
    }

    if (kbEditDepartmentSelect) {
        kbEditDepartmentSelect.addEventListener('change', function() {
            syncEditSubCategoryOptions('');
        });
        syncEditSubCategoryOptions(kbEditCurrentSubCategory);
    }

    const removedExistingImages = new Set();

    function syncRemovedExistingImages() {
        const hiddenInput = document.getElementById('remove_existing_images');
        if (hiddenInput) {
            hiddenInput.value = JSON.stringify(Array.from(removedExistingImages));
        }
    }

    function removeExistingImage(button) {
        const previewItem = button ? button.closest('.current-image-preview') : null;
        if (!previewItem) return;

        const imagePath = previewItem.getAttribute('data-image-path') || '';
        if (imagePath) {
            removedExistingImages.add(imagePath);
            syncRemovedExistingImages();
        }

        previewItem.remove();

        const previewContainer = document.getElementById('image-preview-container');
        const emptyState = document.getElementById('image-preview-empty');
        if (previewContainer && previewContainer.children.length === 0 && emptyState) {
            emptyState.style.display = 'block';
        }
    }

    function addLink() {
        const container = document.getElementById('link-container');
        const div = document.createElement('div');
        div.className = 'link-row';
        div.innerHTML = `
            <input type="text" name="link_labels[]" class="form-control link-label-input" placeholder="Link Label">
            <input type="url" name="link_urls[]" class="form-control link-url-input" placeholder="URL">
            <button type="button" class="btn-remove-link" onclick="removeLink(this)"><i class="fas fa-times"></i></button>
        `;
        container.appendChild(div);
    }

    function removeLink(btn) {
        btn.closest('.link-row').remove();
    }
    
    function removePresentation() {
        const preview = document.getElementById('presentation-preview');
        if (preview) preview.style.display = 'none';
        document.getElementById('remove_presentation').value = '1';
    }

    // Initialize on load
    document.addEventListener('DOMContentLoaded', function() {
        updateRemoveButtonVisibility();
    });

    function setVideoType(type, btn) {
        // Update hidden input
        document.getElementById('video_type_input').value = type;

        // Update active button state
        document.querySelectorAll('.video-toggle-btn').forEach(b => b.classList.remove('active'));
        if (btn) {
            btn.classList.add('active');
        }

        // Show/Hide sections
        document.getElementById('video-url-input').style.display = (type === 'url') ? 'block' : 'none';
        document.getElementById('video-upload-input').style.display = (type === 'upload') ? 'block' : 'none';
        
        // Update remove button visibility based on new type
        updateRemoveButtonVisibility();
    }

    function handleFileChange(input, displayId) {
        updateFileName(input, displayId);
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
            // Check for saved file (badge)
            const savedBadge = document.querySelector('#video-upload-input .current-file-badge');
            // Check for new file selection
            const fileInput = document.querySelector('input[name="video_file"]');
            const hasNewFile = fileInput && fileInput.files.length > 0;
            
            if (savedBadge || hasNewFile) {
                hasContent = true;
            }
        } else if (type === 'url') {
            btnText = 'Remove Link';
            // Check for URL value
            const urlInput = document.querySelector('input[name="video_url"]');
            if (urlInput && urlInput.value.trim() !== '') {
                hasContent = true;
            }
        }

        // Update button text and visibility
        removeBtn.innerHTML = '<i class="fas fa-trash-alt"></i> ' + btnText;
        removeBtn.style.display = hasContent ? 'inline-flex' : 'none';
    }

    function removeVideo() {
        // Removed confirm dialog as requested
        
        // Update hidden input to 'remove'
        document.getElementById('video_type_input').value = 'remove';
        
        // Deactivate toggles
        document.querySelectorAll('.video-toggle-btn').forEach(b => b.classList.remove('active'));
        
        // Hide inputs
        document.getElementById('video-url-input').style.display = 'none';
        document.getElementById('video-upload-input').style.display = 'none';
        
        // Clear inputs
        const urlInput = document.querySelector('input[name="video_url"]');
        if (urlInput) urlInput.value = '';
        
        const fileInput = document.querySelector('input[name="video_file"]');
        if (fileInput) fileInput.value = '';
        
        // Update filename displays
        const fileDisplay = document.getElementById('video-filename');
        if (fileDisplay) fileDisplay.textContent = '';
        
        // Hide remove button itself
        const removeBtn = document.getElementById('btn-remove-video');
        if (removeBtn) {
            removeBtn.style.display = 'none';
        }
    }

    function updateFileName(input, displayId) {
        const display = document.getElementById(displayId);
        if (input.files && input.files.length > 0) {
            display.textContent = input.files[0].name;
        } else {
            display.textContent = '';
        }
    }

    const kbImageInput = document.getElementById('kb-image-input');
    const kbImageDropzone = document.getElementById('kb-image-dropzone');
    const kbImageSelectionNote = document.getElementById('kb-image-selection-note');
    const kbNewImagePreview = document.getElementById('new-image-preview');
    let editImageDataTransfer = new DataTransfer();

    function updateKbImageSelectionNote() {
        if (!kbImageSelectionNote || !kbImageInput) return;
        const imageCount = kbImageInput.files ? kbImageInput.files.length : 0;
        kbImageSelectionNote.textContent = imageCount > 0
            ? imageCount + (imageCount === 1 ? ' new image selected' : ' new images selected')
            : '';
    }

    function previewEditImages(input) {
        if (!kbNewImagePreview) return;

        kbNewImagePreview.innerHTML = '';

        if (input.files && input.files.length > 0) {
            editImageDataTransfer = new DataTransfer();
            Array.from(input.files).forEach(function(file) {
                if (file.type && file.type.indexOf('image/') === 0) {
                    editImageDataTransfer.items.add(file);
                }
            });
            input.files = editImageDataTransfer.files;
        }

        if (!editImageDataTransfer.files || !editImageDataTransfer.files.length) {
            kbNewImagePreview.style.display = 'none';
            updateKbImageSelectionNote();
            return;
        }

        kbNewImagePreview.style.display = 'flex';
        Array.from(editImageDataTransfer.files).forEach(function(file, index) {
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
                    Array.from(editImageDataTransfer.files).forEach(function(existingFile, existingIndex) {
                        if (existingIndex !== index) {
                            nextFiles.items.add(existingFile);
                        }
                    });
                    editImageDataTransfer = nextFiles;
                    kbImageInput.files = editImageDataTransfer.files;
                    previewEditImages(kbImageInput);
                };

                item.appendChild(img);
                item.appendChild(removeBtn);
                kbNewImagePreview.appendChild(item);
            };
            reader.readAsDataURL(file);
        });

        updateKbImageSelectionNote();
    }

    function mergeDroppedEditImages(fileList) {
        if (!kbImageInput || !fileList || !fileList.length) return;
        const nextFiles = new DataTransfer();

        Array.from(editImageDataTransfer.files || []).forEach(function(file) {
            nextFiles.items.add(file);
        });

        Array.from(fileList).forEach(function(file) {
            if (file.type && file.type.indexOf('image/') === 0) {
                nextFiles.items.add(file);
            }
        });

        editImageDataTransfer = nextFiles;
        kbImageInput.files = editImageDataTransfer.files;
        previewEditImages(kbImageInput);
    }

    if (kbImageInput) {
        kbImageInput.addEventListener('change', function() {
            previewEditImages(kbImageInput);
        });
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
            if (!droppedFiles || !droppedFiles.length) return;
            mergeDroppedEditImages(droppedFiles);
        });
    }

    function autoResizeTextarea(textarea) {
        if (!textarea) return;
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
    }

    document.querySelectorAll('textarea.form-control').forEach(function(textarea) {
        autoResizeTextarea(textarea);
        textarea.addEventListener('input', function() {
            autoResizeTextarea(textarea);
        });
    });

    function setupEditKbContentEditor() {
        const editorShell = document.getElementById('kb-edit-editor-shell');
        const toolbar = editorShell ? editorShell.querySelector('.kb-editor-toolbar') : null;
        const editor = document.getElementById('kb-edit-editor-content');
        const contentInput = document.getElementById('kb-edit-content-input');
        if (!editorShell || !toolbar || !editor || !contentInput) return;

        editor.innerHTML = contentInput.value || '';

        function syncEditorContent() {
            contentInput.value = editor.innerHTML.trim();
        }

        function runEditorCommand(command, value) {
            editor.focus();
            document.execCommand(command, false, value || null);
            syncEditorContent();
        }

        toolbar.addEventListener('mousedown', function(event) {
            if (event.target.closest('[data-editor-action]')) {
                event.preventDefault();
            }
        });

        toolbar.addEventListener('click', function(event) {
            const button = event.target.closest('[data-editor-action]');
            if (!button) return;

            const action = button.getAttribute('data-editor-action');
            switch (action) {
                case 'bold':
                    runEditorCommand('bold');
                    break;
                case 'italic':
                    runEditorCommand('italic');
                    break;
                case 'underline':
                    runEditorCommand('underline');
                    break;
                case 'heading':
                    runEditorCommand('formatBlock', '<h2>');
                    break;
                case 'bulleted':
                    runEditorCommand('insertUnorderedList');
                    break;
                case 'numbered':
                    runEditorCommand('insertOrderedList');
                    break;
                case 'quote':
                    runEditorCommand('formatBlock', '<blockquote>');
                    break;
            }
        });

        editor.addEventListener('input', syncEditorContent);
        editor.addEventListener('blur', syncEditorContent);

        const form = editor.closest('form');
        if (form) {
            form.addEventListener('submit', syncEditorContent);
        }
    }

    setupEditKbContentEditor();

</script>

</body>
</html>
