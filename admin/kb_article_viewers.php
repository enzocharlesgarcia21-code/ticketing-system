<?php
require_once '../config/database.php';
require_once '../includes/kb_media.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$article_id = isset($_GET['article_id']) ? (int) $_GET['article_id'] : 0;
if ($article_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid article.'
    ]);
    exit;
}

kb_ensure_article_views_table($conn);

$article_stmt = $conn->prepare("SELECT id, title FROM knowledge_base WHERE id = ? LIMIT 1");
if (!$article_stmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load article.'
    ]);
    exit;
}

$article_stmt->bind_param("i", $article_id);
$article_stmt->execute();
$article_result = $article_stmt->get_result();
$article = $article_result ? $article_result->fetch_assoc() : null;
$article_stmt->close();

if (!$article) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Article not found.'
    ]);
    exit;
}

$viewers = [];
$viewer_stmt = $conn->prepare("
    SELECT
        u.id,
        u.name,
        u.email,
        u.company,
        u.department,
        u.role,
        MAX(v.viewed_at) AS viewed_at
    FROM kb_article_views v
    INNER JOIN users u ON u.id = v.user_id
    WHERE v.article_id = ?
      AND v.user_id IS NOT NULL
    GROUP BY u.id, u.name, u.email, u.company, u.department, u.role
    ORDER BY viewed_at DESC, u.name ASC
");

if (!$viewer_stmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load viewers.'
    ]);
    exit;
}

$viewer_stmt->bind_param("i", $article_id);
$viewer_stmt->execute();
$viewer_result = $viewer_stmt->get_result();
if ($viewer_result) {
    while ($row = $viewer_result->fetch_assoc()) {
        $viewers[] = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'company' => (string) ($row['company'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'viewed_at' => $row['viewed_at'] ? date('M d, Y h:i A', strtotime((string) $row['viewed_at'])) : ''
        ];
    }
}
$viewer_stmt->close();

echo json_encode([
    'ok' => true,
    'article' => [
        'id' => (int) $article['id'],
        'title' => (string) $article['title']
    ],
    'viewers' => $viewers
]);
