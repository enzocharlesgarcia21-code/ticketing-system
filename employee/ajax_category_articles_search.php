<?php
require_once '../config/database.php';
require_once '../includes/kb_media.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

kb_ensure_article_views_table($conn);

$fixedCategories = [
    'HR',
    'IT',
    'Accounting',
    'Marketing',
    'Admin & Legal',
    'Management',
    'Technical',
    'Diagnostics / Lingap',
];

function kb_category_label_ajax(string $category): string
{
    $normalized = trim((string) $category);
    $key = strtolower($normalized);
    $aliases = [
        'technical support' => 'IT',
        'hardware' => 'IT',
        'hardware issue' => 'IT',
        'hardware issues' => 'IT',
        'software' => 'IT',
        'software issue' => 'IT',
        'software issues' => 'IT',
        'email' => 'IT',
        'email problem' => 'IT',
        'internet concerns' => 'IT',
        'network' => 'IT',
        'network issue' => 'IT',
        'network issues' => 'IT',
        'printer' => 'IT',
        'documentation' => 'Admin & Legal',
        'documentations' => 'Admin & Legal',
        'procurement' => 'Admin & Legal',
        'others' => 'Management',
    ];
    return $aliases[$key] ?? ($normalized !== '' ? $normalized : 'IT');
}

function kb_category_aliases_ajax(string $category): array
{
    $category = kb_category_label_ajax($category);
    $map = [
        'IT' => ['IT', 'Technical Support', 'Hardware', 'Hardware Issue', 'Hardware Issues', 'Software', 'Software Issue', 'Software Issues', 'Email', 'Email Problem', 'Internet Concerns', 'Network', 'Network Issue', 'Network Issues', 'Printer'],
        'Admin & Legal' => ['Admin & Legal', 'Documentation', 'Documentations', 'Procurement'],
        'Management' => ['Management', 'Others'],
    ];

    $aliases = $map[$category] ?? [$category];
    return array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($value) {
        return $value !== '';
    })));
}

function kb_category_icon_class_ajax(string $category): string
{
    $key = strtolower(trim($category));
    if ($key === 'hr') return 'fa-users';
    if ($key === 'it') return 'fa-desktop';
    if ($key === 'accounting') return 'fa-calculator';
    if ($key === 'marketing') return 'fa-bullhorn';
    if ($key === 'admin & legal') return 'fa-scale-balanced';
    if ($key === 'management') return 'fa-briefcase';
    if ($key === 'technical') return 'fa-screwdriver-wrench';
    if ($key === 'diagnostics / lingap') return 'fa-stethoscope';
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
$categoryAliases = kb_category_aliases_ajax($category);
$placeholders = implode(',', array_fill(0, count($categoryAliases), '?'));
$query = "SELECT knowledge_base.*, " . kb_unique_views_count_sql('knowledge_base.id') . " AS views FROM knowledge_base WHERE category IN ($placeholders)";
$params = $categoryAliases;
$types = str_repeat('s', count($categoryAliases));

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
