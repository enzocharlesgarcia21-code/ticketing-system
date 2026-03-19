<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

$fixedCategories = [
    'Documentation',
    'Email',
    'Hardware',
    'Internet Concerns',
    'Procurement',
    'Software',
    'Technical Support',
];

function kb_category_icon_class_ajax(string $category): string
{
    $key = strtolower(trim($category));
    if (strpos($key, 'documentation') !== false) return 'fa-book-open';
    if (strpos($key, 'software') !== false) return 'fa-laptop';
    if (strpos($key, 'hardware') !== false) return 'fa-screwdriver-wrench';
    if (strpos($key, 'network') !== false || strpos($key, 'internet') !== false) return 'fa-globe';
    if (strpos($key, 'email') !== false) return 'fa-envelope';
    if (strpos($key, 'procurement') !== false) return 'fa-cart-shopping';
    if (strpos($key, 'technical') !== false) return 'fa-headset';
    return 'fa-folder';
}

function kb_excerpt_text_ajax(string $text, int $maxLen = 140): string
{
    $clean = preg_replace('/\s+/', ' ', trim(strip_tags($text)));
    if ($clean === null) {
        $clean = '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean) <= $maxLen) {
            return $clean;
        }
        return mb_substr($clean, 0, $maxLen) . '...';
    }

    if (strlen($clean) <= $maxLen) {
        return $clean;
    }

    return substr($clean, 0, $maxLen) . '...';
}

$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

if ($category === '' || !in_array($category, $fixedCategories, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid category']);
    exit();
}

$articles = [];
$query = "SELECT * FROM knowledge_base WHERE category = ?";
$params = [$category];
$types = 's';

if ($search !== '') {
    $query .= " AND (title LIKE ? OR content LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $types .= 'ss';
}

$query .= " ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $articles[] = $row;
    }
    $stmt->close();
}

ob_start();
if (!empty($articles)) {
    ?>
    <div class="article-grid">
        <?php foreach ($articles as $article): ?>
            <a href="view_article.php?id=<?= (int) $article['id'] ?>" class="article-card">
                <div class="article-card-body">
                    <span class="article-badge">
                        <i class="fas <?= htmlspecialchars(kb_category_icon_class_ajax((string) ($article['category'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"></i>
                        <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <h3 class="article-title"><?= htmlspecialchars((string) $article['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="article-preview"><?= htmlspecialchars(kb_excerpt_text_ajax((string) $article['content'], 140), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="article-footer">
                    <span><i class="far fa-eye"></i> <?= number_format((int) ($article['views'] ?? 0)) ?> views</span>
                    <span>Read Article <i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
} else {
    ?>
    <div class="empty-state">
        <i class="fas fa-book-open"></i>
        <p>No matching articles found in this category.</p>
    </div>
    <?php
}

echo json_encode([
    'ok' => true,
    'count' => count($articles),
    'html' => ob_get_clean(),
], JSON_UNESCAPED_UNICODE);
