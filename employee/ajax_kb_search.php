<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit();
}

$search = trim((string) ($_GET['search'] ?? ''));

function kb_excerpt_ajax(string $text, int $maxLen = 160): string
{
    $t = preg_replace('/\s+/', ' ', trim($text));
    if ($t === null) {
        $t = '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t) <= $maxLen) {
            return $t;
        }
        return mb_substr($t, 0, $maxLen) . '...';
    }

    if (strlen($t) <= $maxLen) {
        return $t;
    }

    return substr($t, 0, $maxLen) . '...';
}

function kb_category_label_ajax(string $category): string
{
    $category = trim((string) $category);
    $key = strtolower($category);
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
    return $aliases[$key] ?? ($category !== '' ? $category : 'IT');
}

$results = [];
if ($search !== '') {
    $stmt = $conn->prepare("
        SELECT id, title, category, content, created_at
        FROM knowledge_base
        WHERE title LIKE ?
           OR content LIKE ?
           OR category LIKE ?
        ORDER BY created_at DESC
    ");
    if ($stmt) {
        $term = '%' . $search . '%';
        $stmt->bind_param("sss", $term, $term, $term);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $results[] = $row;
        }
        $stmt->close();
    }
}

ob_start();
if (empty($results)) {
    ?>
    <div class="no-results">
        <div class="no-results-icon"><i class="fas fa-book-open"></i></div>
        <div class="no-results-text">No articles found.</div>
        <div class="no-results-sub">Try a different keyword or browse the categories below.</div>
    </div>
    <?php
} else {
    ?>
    <div class="kb-grid">
        <?php foreach ($results as $article): ?>
            <div class="kb-card">
                <div class="kb-card-body">
                    <span class="kb-category-badge"><?= htmlspecialchars(kb_category_label_ajax((string) ($article['category'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                    <h3 class="kb-card-title"><?= htmlspecialchars((string) ($article['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="kb-card-preview"><?= htmlspecialchars(kb_excerpt_ajax((string) ($article['content'] ?? ''), 160), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="kb-card-footer">
                    <span class="kb-views">
                        <i class="fas fa-calendar"></i>
                        <?= !empty($article['created_at']) ? htmlspecialchars(date('M d, Y', strtotime((string) $article['created_at'])), ENT_QUOTES, 'UTF-8') : '' ?>
                    </span>
                    <a href="view_article.php?id=<?= (int) ($article['id'] ?? 0) ?>" class="read-more-btn">
                        Read More <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

echo json_encode([
    'ok' => true,
    'count' => count($results),
    'html' => ob_get_clean(),
], JSON_UNESCAPED_UNICODE);
