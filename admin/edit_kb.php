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

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $title = trim($_POST['title']);
    $category = trim($_POST['category']);
    $content = trim($_POST['content']);
    $remove_image = isset($_POST['remove_image']) && $_POST['remove_image'] == '1';

    if (!empty($title) && !empty($category) && !empty($content)) {
        $image_path = $article['image_path']; // Keep existing image by default

        // Handle Image Removal
        if ($remove_image) {
            if ($image_path && file_exists('../' . $image_path)) {
                unlink('../' . $image_path);
            }
            $image_path = null;
        }

        // Handle New Image Upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/kb_images/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid('kb_', true) . '.' . $file_extension;
            $target_file = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                // Delete old image if exists and not already removed
                if (!$remove_image && $article['image_path'] && file_exists('../' . $article['image_path'])) {
                    unlink('../' . $article['image_path']);
                }
                $image_path = 'uploads/kb_images/' . $new_filename;
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

        $update_stmt = $conn->prepare("UPDATE knowledge_base SET title = ?, category = ?, content = ?, image_path = ?, article_links = ?, article_presentation = ?, article_video = ? WHERE id = ?");
        $update_stmt->bind_param("sssssssi", $title, $category, $content, $image_path, $links_json, $presentation_path, $video_content, $article_id);
        
        if ($update_stmt->execute()) {
            
            header("Location: manage_kb.php?msg=updated");
            exit();
        } else {
            $error_msg = "Error updating article: " . $conn->error;
        }
    } else {
        $error_msg = "All fields are required.";
    }
}

// Pre-defined categories
$categories = ['Network Issue', 'Hardware Issue', 'Software Issue', 'Email Problem', 'Account Access', 'Technical Support', 'Other'];
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
            max-width: 900px;
            width: 95%;
            margin: 0 auto;
        }
        .edit-card {
            background: white;
            padding: 40px;
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
        textarea.form-control {
            min-height: 400px;
            resize: vertical;
            line-height: 1.6;
            font-family: inherit;
        }
        .btn-save {
            background-color: #3B82F6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-save:hover {
            background-color: #2563EB;
        }
        .current-image-preview {
            position: relative;
            display: inline-block;
            margin-top: 10px;
        }
        .current-image-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
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
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
            padding: 25px;
            margin-top: 30px;
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .resources-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            padding-bottom: 15px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            align-items: center;
            gap: 10px;
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
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #E5E7EB;
            display: flex;
            justify-content: flex-end;
            align-items: center;
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
                <div class="form-group">
                    <label class="form-label">Article Title</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($article['title']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control" required>
                        <option value=""disabled selected hidden>Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $article['category'] === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Current Image</label>
                    <?php if ($article['image_path']): ?>
                        <div class="current-image-preview" id="image-preview-container">
                            <img src="../<?= htmlspecialchars($article['image_path']) ?>" alt="Article Image">
                            <button type="button" class="btn-remove-image" onclick="markImageRemoved()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <input type="hidden" name="remove_image" id="remove_image_input" value="0">
                        <div id="image-removed-msg" style="display: none; color: #EF4444; font-size: 14px; margin-top: 5px;">Image will be removed on save.</div>
                    <?php else: ?>
                        <div style="color: #6B7280; font-style: italic; font-size: 14px;">No image uploaded</div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 15px;">
                        <label class="form-label">Update Image (Optional)</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Content</label>
                    <textarea name="content" class="form-control" required><?= htmlspecialchars($article['content']) ?></textarea>
                </div>

                <!-- New Resources Section -->
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
    function markImageRemoved() {
        document.getElementById('image-preview-container').style.display = 'none';
        document.getElementById('remove_image_input').value = '1';
        document.getElementById('image-removed-msg').style.display = 'block';
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
</script>

</body>
</html>
