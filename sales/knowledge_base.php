<?php
require_once '../config/database.php';

$colRes = $conn->query("SHOW COLUMNS FROM knowledge_base LIKE 'visible_to_sales'");
if ($colRes && $colRes->num_rows === 0) {
    $conn->query("ALTER TABLE knowledge_base ADD COLUMN visible_to_sales TINYINT(1) NOT NULL DEFAULT 1");
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$articleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function kb_excerpt(string $text, int $maxLen = 160): string
{
    $t = preg_replace('/\s+/', ' ', trim($text));
    if ($t === null) $t = '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t) <= $maxLen) return $t;
        return mb_substr($t, 0, $maxLen) . '...';
    }
    if (strlen($t) <= $maxLen) return $t;
    return substr($t, 0, $maxLen) . '...';
}

$article = null;
if ($articleId > 0) {
    $stmt = $conn->prepare("SELECT id, title, category, content, created_at FROM knowledge_base WHERE id = ? AND visible_to_sales = 1 LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $articleId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $article = $res->fetch_assoc();
        }
        $stmt->close();
    }
}

$articles = [];
if ($article === null) {
    $baseSql = "SELECT id, title, category, content, created_at FROM knowledge_base WHERE visible_to_sales = 1";
    $params = [];
    $types = "";
    if ($q !== '') {
        $term = '%' . $q . '%';
        $baseSql .= " AND (title LIKE ? OR content LIKE ? OR category LIKE ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types .= "sss";
    }
    $baseSql .= " ORDER BY created_at DESC";

    if ($types !== '') {
        $stmt = $conn->prepare($baseSql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $articles[] = $row;
            }
            $stmt->close();
        }
    } else {
        $res = $conn->query($baseSql);
        while ($res && ($row = $res->fetch_assoc())) {
            $articles[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Base | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f3f4f6 url('../assets/img/leadss.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            margin: 0;
        }
        .sales-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(90deg, #1B5E20, #14532d);
            border-bottom: 3px solid #FBBF24;
            min-height: 96px;
        }
        .sales-topbar-inner {
            max-width: 100%;
            width: 100%;
            margin: 0 auto;
            padding: 22px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            position: relative;
            box-sizing: border-box;
        }
        .sales-logo {
            position: absolute;
            left: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sales-logo img {
            height: 56px;
            width: 56px;
            object-fit: contain;
            background-color: #ffffff;
            padding: 6px;
            border-radius: 50%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
            display: block;
        }
        .sales-brand {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
            align-items: center;
            text-align: center;
        }
        .sales-brand-title {
            font-weight: 800;
            letter-spacing: 0.2px;
            color: #ffffff;
            font-size: 24px;
        }
        .sales-brand-subtitle {
            font-size: 18px;
            font-weight: 700;
            color: #FDE68A;
            margin-top: 3px;
        }
        .sales-nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
            position: absolute;
            right: 24px;
        }
        .sales-nav-link {
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            transition: background 0.2s, color 0.2s, border-color 0.2s;
            white-space: nowrap;
        }
        .sales-nav-link:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #FDE68A;
            border-color: rgba(253, 230, 138, 0.65);
        }
        .kb-container {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 24px 40px;
        }
        .kb-shell {
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 18px;
            box-shadow: 0 10px 25px rgba(2, 6, 23, 0.12);
            padding: 22px;
        }
        .kb-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }
        .kb-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }
        .kb-search {
            flex: 1;
            max-width: 560px;
            position: relative;
        }
        .kb-search input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: #ffffff;
        }
        .kb-search i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .kb-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .kb-btn {
            border: 1px solid #e2e8f0;
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            padding: 10px 14px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 13px;
            white-space: nowrap;
        }
        .kb-btn:hover { background: #e2e8f0; }
        .kb-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .kb-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 2px 6px rgba(2, 6, 23, 0.06);
            display: flex;
            flex-direction: column;
            min-height: 180px;
        }
        .kb-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .kb-card-link:focus-visible {
            outline: 3px solid rgba(34, 197, 94, 0.35);
            outline-offset: 3px;
            border-radius: 18px;
        }
        .kb-card-link:hover .kb-card {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(2, 6, 23, 0.12);
            border-color: #cbd5e1;
        }
        .kb-card-title {
            font-weight: 800;
            color: #0f172a;
            font-size: 16px;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .kb-card-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        .kb-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .kb-desc {
            color: #475569;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 16px;
        }
        .kb-card-footer {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .kb-readmore {
            color: #1B5E20;
            font-weight: 800;
            text-decoration: none;
            font-size: 13px;
        }
        .kb-readmore:hover { text-decoration: underline; }
        .kb-date {
            color: #94a3b8;
            font-size: 12px;
            font-weight: 700;
        }
        .kb-empty {
            padding: 40px 12px;
            text-align: center;
            color: #64748b;
        }
        .kb-article {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 2px 6px rgba(2, 6, 23, 0.06);
        }
        .kb-article h1 {
            font-size: 22px;
            margin: 0 0 10px;
            color: #0f172a;
        }
        .kb-article .kb-article-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .kb-article .kb-article-content {
            color: #334155;
            line-height: 1.7;
            font-size: 14px;
            white-space: pre-wrap;
        }
        @media (max-width: 1024px) {
            .kb-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sales-topbar { min-height: 80px; }
            .sales-topbar-inner { padding: 18px 16px; }
            .sales-logo { left: 20px; }
            .sales-logo img { height: 44px; width: 44px; padding: 4px; }
            .sales-nav-right { right: 16px; }
            .sales-brand-title { font-size: 20px; }
            .sales-brand-subtitle { font-size: 14px; }
            .kb-container { padding: 0 16px 30px; }
            .kb-header { flex-direction: column; align-items: stretch; }
            .kb-actions { justify-content: flex-end; }
            .kb-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="sales-topbar">
        <div class="sales-topbar-inner">
            <div class="sales-logo">
                <img src="../assets/img/logo.png" alt="Leads Agri Logo">
            </div>
            <div class="sales-brand">
                <div class="sales-brand-title">Leads Agri Helpdesk</div>
                <div class="sales-brand-subtitle">Knowledge Base</div>
            </div>
            <div class="sales-nav-right">
                <a class="sales-nav-link" href="/ticketing/sales/request_ticket.php">Submit Ticket</a>
            </div>
        </div>
    </header>

    <div class="kb-container">
        <div class="kb-shell">
            <?php if ($article !== null): ?>
                <div class="kb-header">
                    <div class="kb-title">Help Center</div>
                    <div class="kb-actions">
                        <a class="kb-btn" href="/ticketing/sales/knowledge_base.php">Back</a>
                    </div>
                </div>
                <div class="kb-article">
                    <h1><?= htmlspecialchars((string) $article['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div class="kb-article-meta">
                        <span class="kb-pill"><i class="fas fa-tag"></i><?= htmlspecialchars((string) $article['category'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="kb-date"><?= htmlspecialchars(date('M d, Y', strtotime((string) $article['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="kb-article-content"><?= htmlspecialchars((string) $article['content'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php else: ?>
                <form method="GET" class="kb-header">
                    <div class="kb-title">Help Center</div>
                    <div class="kb-search">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search articles...">
                    </div>
                    <div class="kb-actions">
                        <?php if ($q !== ''): ?>
                            <a class="kb-btn" href="/ticketing/sales/knowledge_base.php">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if (count($articles) === 0): ?>
                    <div class="kb-empty">
                        <div style="font-size: 42px; margin-bottom: 10px; color: #cbd5e1;"><i class="fas fa-book-open"></i></div>
                        <div style="font-weight: 800; color: #0f172a; margin-bottom: 6px;">No articles found.</div>
                        <div>Try a different search, or submit a ticket and we’ll help you.</div>
                    </div>
                <?php else: ?>
                    <div class="kb-grid">
                        <?php foreach ($articles as $a): ?>
                            <?php
                                $excerpt = kb_excerpt((string) ($a['content'] ?? ''), 160);
                            ?>
                            <a class="kb-card-link" href="/ticketing/sales/knowledge_base.php?id=<?= (int) $a['id']; ?>">
                                <div class="kb-card">
                                    <div class="kb-card-title"><?= htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="kb-card-meta">
                                        <span class="kb-pill"><i class="fas fa-tag"></i><?= htmlspecialchars((string) $a['category'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="kb-desc"><?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="kb-card-footer">
                                        <span class="kb-readmore">Read More</span>
                                        <span class="kb-date"><?= htmlspecialchars(date('M d, Y', strtotime((string) $a['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
