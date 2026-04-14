<?php
require_once '../config/database.php';
require_once '../includes/kb_media.php';

// Protect page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

// Validate ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: knowledge_base.php");
    exit();
}

$article_id = (int)$_GET['id'];
// 1. Fetch Article
$stmt = $conn->prepare("SELECT * FROM knowledge_base WHERE id = ?");
$stmt->bind_param("i", $article_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: knowledge_base.php");
    exit();
}

$article = $result->fetch_assoc();
$registered_view = kb_register_article_view($conn, $article_id);
if ($registered_view) {
    $article['views'] = (int) ($article['views'] ?? 0) + 1;
}
$article_image_urls = kb_resolve_asset_urls($article['image_path'] ?? '');
$back_url = 'knowledge_base.php';

// Fetch Related Articles
$relatedStmt = $conn->prepare("
    SELECT k.id, k.title, k.category, k.views 
    FROM kb_related_articles r 
    JOIN knowledge_base k ON r.related_article_id = k.id 
    WHERE r.article_id = ?
");
$relatedStmt->bind_param("i", $article_id);
$relatedStmt->execute();
$relatedResult = $relatedStmt->get_result();
$relatedArticles = [];
while ($row = $relatedResult->fetch_assoc()) {
    $relatedArticles[] = $row;
}

function renderArticleContent($text) {
    // 1. Escape HTML for safety
    $text = htmlspecialchars($text);
    
    // 2. Bold (**text**)
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    
    // 3. Process lines for Headers and Lists
    $lines = explode("\n", $text);
    $output = '';
    $inList = false;
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        if (empty($trimmed)) {
            if ($inList) {
                $output .= "</ul>";
                $inList = false;
            }
            // Optional: Add <br> for empty lines if you want to preserve spacing
            // $output .= "<br>"; 
            continue;
        }
        
        // H1 (# Title)
        if (strpos($trimmed, '# ') === 0) {
            if ($inList) { $output .= "</ul>"; $inList = false; }
            $output .= "<h1>" . substr($trimmed, 2) . "</h1>";
        }
        // H2 (## Title)
        elseif (strpos($trimmed, '## ') === 0) {
            if ($inList) { $output .= "</ul>"; $inList = false; }
            $output .= "<h2>" . substr($trimmed, 3) . "</h2>";
        }
        // List Item (- Item)
        elseif (strpos($trimmed, '- ') === 0) {
            if (!$inList) {
                $output .= "<ul>";
                $inList = true;
            }
            $output .= "<li>" . substr($trimmed, 2) . "</li>";
        }
        // Normal Text
        else {
            if ($inList) { $output .= "</ul>"; $inList = false; }
            $output .= "<p>" . $trimmed . "</p>";
        }
    }
    
    if ($inList) {
        $output .= "</ul>";
    }
    
    return $output;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($article['title']) ?> | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #F9FAFB;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .article-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 40px;
            padding: 7px 19px;
            color: #ffffff;
            text-decoration: none;
            font-weight: 800;
            margin-bottom: 30px;
            border: 1px solid rgba(189, 223, 179, 0.62);
            border-radius: 999px;
            background: #4a8f58;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.1);
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
            font-size: 13px;
        }

        .back-link:hover {
            background: #5a9d67;
            border-color: rgba(213, 238, 204, 0.7);
            transform: translateY(-1px);
        }
        .back-link i {
            color: #f6cf4a;
        }

        .article-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #E5E7EB;
            overflow: hidden;
        }

        .article-header {
            padding: 40px 40px 30px;
            border-bottom: 1px solid #F3F4F6;
            background-color: #FFFFFF;
        }

        .article-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .category-badge {
            background-color: #E8F5E9;
            color: #1B5E20;
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-info {
            color: #9CA3AF;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .article-title {
            font-size: 32px;
            font-weight: 800;
            color: #111827;
            line-height: 1.3;
            margin: 0;
        }

        .article-content {
            padding: 40px;
            color: #374151;
            line-height: 1.8;
            font-size: 16px;
        }

        /* Typography for content */
        .article-content h1, 
        .article-content h2, 
        .article-content h3 {
            color: #111827;
            margin-top: 1.5em;
            margin-bottom: 0.75em;
            font-weight: 700;
        }

        .article-content h2 { font-size: 24px; }
        .article-content h3 { font-size: 20px; }

        .article-content p {
            margin-bottom: 1.5em;
        }

        .article-content ul, 
        .article-content ol {
            margin-bottom: 1.5em;
            padding-left: 1.5em;
        }

        .article-content li {
            margin-bottom: 0.5em;
        }

        .article-content code {
            background-color: #F3F4F6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9em;
            color: #C026D3;
        }

        .article-content pre {
            background-color: #1F2937;
            color: #F9FAFB;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            margin-bottom: 1.5em;
        }

        .article-content blockquote {
            border-left: 4px solid #1B5E20;
            background-color: #F9FAFB;
            padding: 16px 20px;
            margin: 0 0 1.5em 0;
            font-style: italic;
            color: #4B5563;
        }

        .article-content img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1em 0;
        }

        .article-image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }

        .article-image-gallery img {
            width: 100%;
            max-height: 500px;
            object-fit: cover;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin: 0;
        }

        .article-image-link {
            display: block;
            text-decoration: none;
        }

        .article-image-link img {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: zoom-in;
        }

        .article-image-link:hover img {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.16);
        }

        .article-lightbox {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 28px;
            z-index: 9999;
        }

        .article-lightbox.is-open {
            display: flex;
        }

        .article-lightbox-stage {
            max-width: min(1100px, calc(100vw - 140px));
            max-height: calc(100vh - 110px);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
            touch-action: none;
        }

        .article-lightbox-image {
            max-width: 100%;
            max-height: 100%;
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
            transform-origin: center center;
            transition: transform 0.15s ease;
            user-select: none;
            -webkit-user-drag: none;
        }

        .article-lightbox-close,
        .article-lightbox-nav {
            position: absolute;
            border: none;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            backdrop-filter: blur(8px);
        }

        .article-lightbox-close {
            top: 24px;
            right: 24px;
            width: 42px;
            height: 42px;
            font-size: 24px;
            line-height: 1;
        }

        .article-lightbox-nav {
            top: 50%;
            transform: translateY(-50%);
            width: 52px;
            height: 52px;
            font-size: 30px;
            line-height: 1;
        }

        .article-lightbox-prev {
            left: 24px;
        }

        .article-lightbox-next {
            right: 24px;
        }

        .article-lightbox-counter {
            position: absolute;
            left: 50%;
            bottom: 24px;
            transform: translateX(-50%);
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        @media (max-width: 768px) {
            .article-header, .article-content {
                padding: 24px;
            }
            .article-title {
                font-size: 24px;
            }
            .article-lightbox {
                padding: 16px;
            }
            .article-lightbox-stage {
                max-width: calc(100vw - 32px);
                max-height: calc(100vh - 120px);
            }
            .article-lightbox-image {
                max-width: 100%;
                max-height: 100%;
            }
            .article-lightbox-nav {
                width: 42px;
                height: 42px;
                font-size: 24px;
            }
            .article-lightbox-prev {
                left: 12px;
            }
            .article-lightbox-next {
                right: 12px;
            }
            .article-lightbox-close {
                top: 12px;
                right: 12px;
            }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <?php include '../includes/employee_navbar.php'; ?>

    <div class="article-container">
        
        <a href="<?= htmlspecialchars($back_url) ?>" class="back-link" aria-label="Back to Category Articles">
            <i class="fas fa-arrow-left"></i>
            <span>Back</span>
        </a>

        <article class="article-card">
            <div class="article-header">
                <div class="article-meta">
                    <span class="category-badge">
                        <?= htmlspecialchars($article['category']) ?>
                    </span>
                    <span class="meta-info">
                        <i class="far fa-calendar"></i>
                        <?= date('M d, Y', strtotime($article['created_at'])) ?>
                    </span>
                    <span class="meta-info">
                        <i class="far fa-eye"></i>
                        <?= number_format($article['views']) ?> views
                    </span>
                </div>
                <h1 class="article-title">
                    <?= htmlspecialchars($article['title']) ?>
                </h1>
            </div>

            <div class="article-content">
                <?php if (!empty($article_image_urls)): ?>
                    <div class="article-image-gallery">
                        <?php foreach ($article_image_urls as $index => $article_image_url): ?>
                            <a href="<?= htmlspecialchars($article_image_url) ?>" class="article-image-link" data-article-image="<?= htmlspecialchars($article_image_url) ?>" data-article-index="<?= (int) $index ?>">
                                <img src="<?= htmlspecialchars($article_image_url) ?>" alt="<?= htmlspecialchars($article['title']) ?>">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?= renderArticleContent($article['content']) ?>
            </div>

            <!-- Additional Resources -->
            <?php 
                $links = json_decode($article['article_links'] ?? '[]', true);
                $has_resources = !empty($links) || !empty($article['article_presentation']) || !empty($article['article_video']);
            ?>

            <?php if ($has_resources): ?>
                <div class="article-resources" style="padding: 30px; border-top: 1px solid #E5E7EB; background-color: #FAFAFA; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
                    <h2 style="font-size: 20px; font-weight: 700; color: #111827; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-paperclip" style="color: #10B981;"></i> Additional Resources
                    </h2>

                    <!-- Reference Links -->
                    <?php if (!empty($links)): ?>
                        <div style="margin-bottom: 25px;">
                            <h3 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 12px;">Reference Links</h3>
                            <ul style="list-style: none; padding: 0; display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($links as $link): ?>
                                    <li>
                                        <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" style="color: #059669; text-decoration: none; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; background: white; border: 1px solid #E5E7EB; border-radius: 8px; transition: all 0.2s;">
                                            <i class="fas fa-external-link-alt" style="font-size: 14px;"></i>
                                            <?= htmlspecialchars($link['label']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Presentation -->
                    <?php if (!empty($article['article_presentation'])): ?>
                        <?php
                            $pres_path = $article['article_presentation'];
                            $pres_ext = strtolower(pathinfo($pres_path, PATHINFO_EXTENSION));
                            
                            // Only allow preview for browser-supported formats
                            $can_preview = in_array($pres_ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif']);
                            
                            // Check for Office files to show appropriate icon/label
                            $is_office = in_array($pres_ext, ['ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx']);
                            $icon_class = 'fas fa-file-alt';
                            $icon_color = '#6B7280';
                            $bg_color = '#F3F4F6';
                            $text_color = '#374151';
                            
                            if ($is_office) {
                                if (in_array($pres_ext, ['ppt', 'pptx'])) {
                                    $icon_class = 'fas fa-file-powerpoint';
                                    $icon_color = '#EA580C'; // Orange
                                    $bg_color = '#FFF7ED';
                                    $text_color = '#9A3412';
                                } elseif (in_array($pres_ext, ['doc', 'docx'])) {
                                    $icon_class = 'fas fa-file-word';
                                    $icon_color = '#2563EB'; // Blue
                                    $bg_color = '#EFF6FF';
                                    $text_color = '#1E40AF';
                                } elseif (in_array($pres_ext, ['xls', 'xlsx'])) {
                                    $icon_class = 'fas fa-file-excel';
                                    $icon_color = '#16A34A'; // Green
                                    $bg_color = '#F0FDF4';
                                    $text_color = '#166534';
                                }
                            } elseif ($pres_ext === 'pdf') {
                                $icon_class = 'fas fa-file-pdf';
                                $icon_color = '#DC2626'; // Red
                                $bg_color = '#FEF2F2';
                                $text_color = '#991B1B';
                            }
                        ?>
                        <div style="margin-bottom: 25px;">
                            <h3 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 12px;">Presentation</h3>
                            <div style="display: flex; align-items: center; justify-content: space-between; background-color: white; border: 1px solid #E5E7EB; padding: 12px 20px; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 36px; height: 36px; background: <?= $bg_color ?>; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                        <i class="<?= $icon_class ?>" style="font-size: 18px; color: <?= $icon_color ?>;"></i>
                                    </div>
                                    <div style="display: flex; flex-direction: column;">
                                        <span style="font-weight: 600; color: <?= $text_color ?>; font-size: 14px;">Presentation File</span>
                                        <span style="font-size: 12px; color: #6B7280; text-transform: uppercase;"><?= htmlspecialchars($pres_ext) ?> File</span>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <?php if ($can_preview): ?>
                                        <a href="../<?= htmlspecialchars($article['article_presentation']) ?>" target="_blank" rel="noopener" style="text-decoration: none; color: #4B5563; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 6px; background: #F3F4F6; transition: all 0.2s;">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    <?php elseif (in_array($pres_ext, ['ppt', 'pptx'])): ?>
                                        <?php 
                                            $host = $_SERVER['HTTP_HOST'] ?? '';
                                            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

                                            $is_local_host = false;
                                            if ($host === 'localhost' || strpos($host, 'localhost:') === 0 || strpos($host, '127.') === 0 || $host === '[::1]') {
                                                $is_local_host = true;
                                            } elseif (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host)) {
                                                $is_local_host = true;
                                            }

                                            $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
                                            $base_path = rtrim(dirname(dirname($script_name)), "/\\");
                                            if ($base_path === '/' || $base_path === '\\') {
                                                $base_path = '';
                                            }

                                            $absUrl = $scheme . '://' . $host . $base_path . '/' . ltrim($article['article_presentation'], '/');
                                            $officeUrl = 'https://view.officeapps.live.com/op/view.aspx?src=' . rawurlencode($absUrl);
                                        ?>
                                        <?php if (!$is_local_host): ?>
                                            <a href="<?= htmlspecialchars($officeUrl) ?>" target="_blank" rel="noopener" style="text-decoration: none; color: #4B5563; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 6px; background: #F3F4F6; transition: all 0.2s;">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php else: ?>
                                            <a href="../<?= htmlspecialchars($article['article_presentation']) ?>" target="_blank" rel="noopener" style="text-decoration: none; color: #4B5563; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 6px; background: #F3F4F6; transition: all 0.2s;">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <a href="../<?= htmlspecialchars($article['article_presentation']) ?>" download class="btn-download-file" style="text-decoration: none; color: <?= $icon_color ?>; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; background: <?= $bg_color ?>; transition: all 0.2s;">
                                        <i class="fas fa-download"></i> 
                                        <span>Download</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Video -->
                    <?php if (!empty($article['article_video'])): ?>
                        <div>
                            <h3 style="font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 12px;">Video </h3>
                            <div style="border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); background: black;">
                                <?php if (strpos($article['article_video'], 'uploads/') === 0): ?>
                                    <video controls style="width: 100%; display: block;">
                                        <source src="../<?= htmlspecialchars($article['article_video']) ?>" type="video/mp4">
                                        Your browser does not support the video tag.
                                    </video>
                                <?php else: ?>
                                    <?php
                                        $url = $article['article_video'];
                                        $video_id = '';
                                        // Extract YouTube ID (simple regex)
                                        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $url, $matches)) {
                                            $video_id = $matches[1];
                                        }
                                    ?>
                                    <?php if ($video_id): ?>
                                        <div style="position: relative; padding-bottom: 56.25%; height: 0;">
                                            <iframe src="https://www.youtube.com/embed/<?= htmlspecialchars($video_id) ?>" 
                                                    style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;" 
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                                    allowfullscreen>
                                            </iframe>
                                        </div>
                                    <?php else: ?>
                                        <div style="padding: 20px; text-align: center; color: white;">
                                            <a href="<?= htmlspecialchars($url) ?>" target="_blank" style="color: #10B981;">Watch Video on External Site</a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Related Articles -->
            <?php if (!empty($relatedArticles)): ?>
                <div class="related-articles-section" style="padding: 30px; border-top: 1px solid #E5E7EB; background-color: white; border-radius: 0 0 16px 16px;">
                    <h3 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 20px;">Related Articles</h3>
                    <div class="related-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px;">
                        <?php foreach ($relatedArticles as $rel): ?>
                            <a href="view_article.php?id=<?= $rel['id'] ?>" class="related-card" style="display: block; text-decoration: none; padding: 15px; border: 1px solid #E5E7EB; border-radius: 8px; transition: all 0.2s; background: #F9FAFB;">
                                <div style="font-size: 12px; font-weight: 600; color: #059669; text-transform: uppercase; margin-bottom: 8px;">
                                    <?= htmlspecialchars($rel['category']) ?>
                                </div>
                                <div style="font-size: 15px; font-weight: 600; color: #111827; margin-bottom: 6px; line-height: 1.4;">
                                    <?= htmlspecialchars($rel['title']) ?>
                                </div>
                                <div style="font-size: 13px; color: #6B7280;">
                                    <i class="far fa-eye"></i> <?= number_format($rel['views']) ?> views
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <style>
                    .related-card:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                        border-color: #D1FAE5;
                        background: white;
                    }
                </style>
            <?php endif; ?>
        </article>

    </div>

    <div class="article-lightbox" id="articleLightbox" aria-hidden="true">
        <button type="button" class="article-lightbox-close" id="articleLightboxClose" aria-label="Close image viewer">&times;</button>
        <button type="button" class="article-lightbox-nav article-lightbox-prev" id="articleLightboxPrev" aria-label="Previous image">&#8249;</button>
        <div class="article-lightbox-stage" id="articleLightboxStage">
            <img src="" alt="" class="article-lightbox-image" id="articleLightboxImage">
        </div>
        <button type="button" class="article-lightbox-nav article-lightbox-next" id="articleLightboxNext" aria-label="Next image">&#8250;</button>
        <div class="article-lightbox-counter" id="articleLightboxCounter"></div>
    </div>

    <script>
    (function () {
        var links = Array.prototype.slice.call(document.querySelectorAll('[data-article-image]'));
        var lightbox = document.getElementById('articleLightbox');
        var stage = document.getElementById('articleLightboxStage');
        var lightboxImage = document.getElementById('articleLightboxImage');
        var counter = document.getElementById('articleLightboxCounter');
        var prevBtn = document.getElementById('articleLightboxPrev');
        var nextBtn = document.getElementById('articleLightboxNext');
        var closeBtn = document.getElementById('articleLightboxClose');
        var currentIndex = 0;
        var currentScale = 1;

        if (!links.length || !lightbox || !stage || !lightboxImage || !counter || !prevBtn || !nextBtn || !closeBtn) {
            return;
        }

        function resetZoom() {
            currentScale = 1;
            lightboxImage.style.transform = 'scale(1)';
            stage.scrollTop = 0;
            stage.scrollLeft = 0;
        }

        function applyZoom(nextScale) {
            currentScale = Math.max(1, Math.min(4, nextScale));
            lightboxImage.style.transform = 'scale(' + currentScale + ')';
        }

        function renderImage() {
            var activeLink = links[currentIndex];
            if (!activeLink) return;
            var src = activeLink.getAttribute('data-article-image') || '';
            lightboxImage.src = src;
            lightboxImage.alt = activeLink.querySelector('img') ? (activeLink.querySelector('img').alt || '') : '';
            counter.textContent = (currentIndex + 1) + ' / ' + links.length;
            resetZoom();
        }

        function openLightbox(index) {
            currentIndex = index;
            renderImage();
            lightbox.classList.add('is-open');
            lightbox.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            lightbox.classList.remove('is-open');
            lightbox.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            resetZoom();
        }

        function showPrev() {
            currentIndex = (currentIndex - 1 + links.length) % links.length;
            renderImage();
        }

        function showNext() {
            currentIndex = (currentIndex + 1) % links.length;
            renderImage();
        }

        links.forEach(function (link, index) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                openLightbox(index);
            });
        });

        prevBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            showPrev();
        });

        nextBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            showNext();
        });

        stage.addEventListener('wheel', function (event) {
            event.preventDefault();
            var delta = event.deltaY < 0 ? 0.2 : -0.2;
            applyZoom(currentScale + delta);
        }, { passive: false });

        lightboxImage.addEventListener('dblclick', function (event) {
            event.preventDefault();
            applyZoom(currentScale > 1 ? 1 : 2);
        });

        closeBtn.addEventListener('click', function () {
            closeLightbox();
        });

        lightbox.addEventListener('click', function (event) {
            if (event.target === lightbox) {
                closeLightbox();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (!lightbox.classList.contains('is-open')) return;
            if (event.key === 'Escape') closeLightbox();
            if (event.key === 'ArrowLeft') showPrev();
            if (event.key === 'ArrowRight') showNext();
        });
    })();
    </script>

    <script src="../js/employee-dashboard.js"></script>
</body>
</html>

