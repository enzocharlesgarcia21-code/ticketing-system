<?php
require_once '../config/database.php';

$colRes = $conn->query("SHOW COLUMNS FROM knowledge_base LIKE 'visible_to_sales'");
if ($colRes && $colRes->num_rows === 0) {
    $conn->query("ALTER TABLE knowledge_base ADD COLUMN visible_to_sales TINYINT(1) NOT NULL DEFAULT 1");
}

$fixedCategories = [
    'Documentation',
    'Email',
    'Hardware',
    'Internet Concerns',
    'Procurement',
    'Software',
    'Technical Support',
    'Others',
];

function kb_normalize_category_name(string $category): string
{
    $category = trim($category);
    $category = preg_replace('/\s+Issues?$/i', '', $category);
    $category = preg_replace('/\s+Issue$/i', '', $category);
    $category = preg_replace('/\s+Problem$/i', '', $category);
    $category = preg_replace('/\s+Access$/i', ' Login & Account', $category);
    return trim($category);
}

function kb_category_label(string $category): string
{
    $normalized = kb_normalize_category_name($category);
    $key = strtolower($normalized);
    if ($key === 'documentation') return 'Documentation';
    if ($key === 'email') return 'Email';
    if ($key === 'internet concerns' || $key === 'network') return 'Internet Concerns';
    if ($key === 'software') return 'Software';
    if ($key === 'hardware') return 'Hardware';
    if ($key === 'procurement') return 'Procurement';
    if ($key === 'technical support') return 'Technical Support';
    return $normalized !== '' ? $normalized : 'Documentation';
}

function kb_category_aliases(string $category): array
{
    $category = kb_category_label($category);
    $map = [
        'Documentation' => ['Documentation'],
        'Email' => ['Email', 'Email Problem'],
        'Hardware' => ['Hardware', 'Hardware Issue', 'Hardware Issues'],
        'Internet Concerns' => ['Internet Concerns', 'Network', 'Network Issue', 'Network Issues'],
        'Procurement' => ['Procurement'],
        'Software' => ['Software', 'Software Issue', 'Software Issues'],
        'Technical Support' => ['Technical Support'],
    ];

    $aliases = $map[$category] ?? [$category];
    return array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($value) {
        return $value !== '';
    })));
}

function kb_category_icon_class(string $category): string
{
    $key = strtolower(kb_normalize_category_name($category));
    if (strpos($key, 'documentation') !== false) return 'fa-book-open';
    if (strpos($key, 'software') !== false) return 'fa-laptop';
    if (strpos($key, 'hardware') !== false) return 'fa-screwdriver-wrench';
    if (strpos($key, 'network') !== false || strpos($key, 'internet') !== false) return 'fa-globe';
    if (strpos($key, 'email') !== false) return 'fa-envelope';
    if (strpos($key, 'procurement') !== false) return 'fa-cart-shopping';
    if (strpos($key, 'technical') !== false) return 'fa-headset';
    if (strpos($key, 'other') !== false) return 'fa-folder';
    return 'fa-folder';
}

function kb_is_standard_category(string $category): bool
{
    static $standard = [
        'Documentation',
        'Email',
        'Hardware',
        'Internet Concerns',
        'Procurement',
        'Software',
        'Technical Support',
    ];

    return in_array(kb_category_label($category), $standard, true);
}

function kb_excerpt_text(string $text, int $maxLen = 140): string
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
    header("Location: knowledge_base.php");
    exit();
}

$articles = [];
$query = "SELECT * FROM knowledge_base";
$params = [];
$types = '';

if ($search !== '') {
    $query .= " WHERE (title LIKE ? OR content LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $types = 'ss';
}

$query .= " ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rowCategory = trim((string) ($row['category'] ?? ''));
        $matches = false;
        if ($category === 'Others') {
            $matches = $rowCategory !== '' && !kb_is_standard_category($rowCategory);
        } else {
            $matches = in_array($rowCategory, kb_category_aliases($category), true);
        }
        if ($matches) {
            $articles[] = $row;
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($category) ?> | Knowledge Base</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            background-color: #F9FAFB;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .sales-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background:
                linear-gradient(0deg, rgba(20, 42, 23, 0.16), rgba(20, 42, 23, 0.16)),
                radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.05), transparent 38%),
                linear-gradient(135deg, #214f2a 0%, #1a4726 48%, #183f22 100%);
            border-bottom: 4px solid #d6a329;
            box-shadow: 0 14px 34px rgba(6, 24, 12, 0.22);
        }

        .sales-topbar-inner {
            width: 100%;
            margin: 0 auto;
            padding: 8px 22px 9px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-sizing: border-box;
        }

        .sales-brand-block {
            display: flex;
            align-items: center;
            gap: 18px;
            min-width: 0;
        }

        .sales-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 54px;
            height: 54px;
            flex: 0 0 54px;
        }

        .sales-logo img {
            height: 100%;
            width: 100%;
            object-fit: contain;
            background-color: #ffffff;
            padding: 8px;
            border-radius: 999px;
            box-shadow: 0 8px 18px rgba(6, 24, 12, 0.22);
            display: block;
            box-sizing: border-box;
        }

        .sales-brand-divider {
            width: 1px;
            height: 40px;
            background: rgba(233, 219, 174, 0.58);
            flex: 0 0 1px;
        }

        .sales-brand {
            display: flex;
            flex-direction: column;
            line-height: 1.08;
            align-items: flex-start;
            text-align: left;
        }

        .sales-brand-title {
            font-weight: 700;
            letter-spacing: 0.01em;
            color: #f8f6ee;
            font-size: 19px;
            text-shadow: 0 1px 0 rgba(0, 0, 0, 0.12);
        }

        .sales-brand-subtitle {
            font-size: 14px;
            font-weight: 600;
            color: #e5bf59;
            margin-top: 4px;
        }

        .sales-nav-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex: 0 0 auto;
        }

        .sales-nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 42px;
            padding: 0 20px;
            color: #f8f6ee;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 0.01em;
            border-radius: 999px;
            border: 1px solid rgba(232, 223, 193, 0.34);
            background: rgba(255, 255, 255, 0.02);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
            transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.2s;
            white-space: nowrap;
        }

        .sales-nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #f6cf62;
            border-color: rgba(229, 191, 89, 0.55);
            transform: translateY(-1px);
        }

        .sales-nav-link-icon {
            color: #f6cf62;
            font-size: 16px;
            line-height: 1;
        }

        .kb-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 40px 20px;
            box-sizing: border-box;
        }

        .category-shell {
            max-width: 980px;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 28px;
            padding: 4px 18px;
            color: #ffffff;
            text-decoration: none;
            font-weight: 800;
            margin-bottom: 18px;
            border: 1px solid rgba(201, 232, 194, 0.54);
            border-radius: 999px;
            background: #3d7a47;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
            font-size: 12px;
            transition: background 0.2s, border-color 0.2s, transform 0.2s;
        }

        .back-link:hover {
            background: #4b8a55;
            border-color: rgba(220, 245, 215, 0.62);
            transform: translateY(-1px);
        }
        .back-link i {
            color: #f6cf4a;
        }

        .category-header {
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 18px;
            padding: 24px 26px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
            margin-bottom: 24px;
            width: 100%;
            box-sizing: border-box;
        }

        .category-title-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }

        .category-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: #F0FDF4;
            color: #1B5E20;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex: 0 0 auto;
        }

        .category-title {
            margin: 0;
            color: #111827;
            font-size: 30px;
            font-weight: 800;
        }

        .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            width: 100%;
            box-sizing: border-box;
        }

        .search-group {
            position: relative;
            flex: 1;
            min-width: 280px;
            width: 100%;
            box-sizing: border-box;
        }

        .search-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 16px;
        }

        .search-input {
            width: 100%;
            padding: 14px 14px 14px 48px;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            font-size: 16px;
            background: white;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            box-sizing: border-box;
        }

        .search-input:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.1);
        }

        .search-btn {
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid #E5E7EB;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .search-btn {
            background: #1B5E20;
            color: white;
            border-color: #1B5E20;
        }

        .articles-container {
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
            width: 100%;
            box-sizing: border-box;
        }

        .articles-title {
            margin: 0 0 18px;
            color: #111827;
            font-size: 22px;
            font-weight: 800;
        }

        .article-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
        }

        .article-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #E5E7EB;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: inherit;
            overflow: hidden;
        }

        .article-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.1);
            border-color: #1B5E20;
        }

        .article-card-body {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .article-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #E8F5E9;
            color: #1B5E20;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            align-self: flex-start;
        }

        .article-title {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            line-height: 1.35;
        }

        .article-preview {
            margin: 0;
            color: #6B7280;
            font-size: 14px;
            line-height: 1.7;
            flex: 1;
        }

        .article-footer {
            padding: 16px 22px;
            border-top: 1px solid #F3F4F6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #FAFAFA;
            color: #6B7280;
            font-size: 13px;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            border: 1px dashed #E5E7EB;
            border-radius: 16px;
            color: #6B7280;
        }

        .empty-state i {
            font-size: 42px;
            color: #D1D5DB;
            margin-bottom: 12px;
        }

        @media (max-width: 768px) {
            .sales-topbar {
                min-height: 80px;
            }

            .sales-topbar-inner {
                padding: 16px;
                display: grid;
                grid-template-columns: auto auto;
                grid-template-areas:
                    "logo title"
                    "subtitle subtitle"
                    "nav nav";
                justify-content: center;
                align-items: center;
                row-gap: 6px;
                column-gap: 10px;
            }

            .sales-logo img {
                height: 44px;
                width: 44px;
                padding: 4px;
            }

            .sales-brand-block {
                display: contents;
            }

            .sales-brand-divider {
                display: none;
            }

            .sales-topbar {
                min-height: auto;
            }

            .sales-topbar-inner {
                padding: 8px 12px;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .sales-brand-block {
                gap: 8px;
                align-items: center;
            }

            .sales-logo {
                width: 40px;
                height: 40px;
                flex: 0 0 40px;
            }

            .sales-logo img {
                height: 100%;
                width: 100%;
                padding: 4px;
            }

            .sales-brand-divider {
                height: 28px;
            }

            .sales-brand-title {
                font-size: 18px;
                font-weight: 600;
                line-height: 1.3;
                text-align: left;
            }

            .sales-brand-subtitle {
                font-size: 14px;
                color: #FACC15;
                margin-top: 4px;
                text-align: left;
            }

            .sales-nav-right {
                width: 100%;
                justify-content: stretch;
            }

            .sales-nav-link {
                width: 100%;
                justify-content: center;
            }

            .category-title {
                font-size: 24px;
            }

            .search-group {
                min-width: 100%;
            }

            .search-btn,
            .article-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>
    <header class="sales-topbar">
        <div class="sales-topbar-inner">
            <div class="sales-brand-block">
                <div class="sales-logo">
                    <img src="../assets/img/UPDATEDlogo.png?v=2" alt="Leads Agri Logo">
                </div>
                <div class="sales-brand-divider" aria-hidden="true"></div>
                <div class="sales-brand">
                    <div class="sales-brand-title">Leads Agri Helpdesk</div>
                    <div class="sales-brand-subtitle">Knowledge Base</div>
                </div>
            </div>
            <div class="sales-nav-right">
                <a class="sales-nav-link" href="/ticketing/sales/request_ticket.php">
                    <span>Submit Ticket</span>
                    <span class="sales-nav-link-icon" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                </a>
            </div>
        </div>
    </header>

    <div class="kb-container">
        <div class="category-shell">
            <a href="knowledge_base.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>

            <div class="category-header">
                <div class="category-title-row">
                    <div class="category-icon">
                        <i class="fas <?= htmlspecialchars(kb_category_icon_class($category)) ?>"></i>
                    </div>
                    <h1 class="category-title"><?= htmlspecialchars($category) ?></h1>
                </div>

                <form method="GET" class="search-form" id="categorySearchForm">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                    <div class="search-group">
                        <i class="fas fa-search"></i>
                        <input
                            type="text"
                            id="categorySearchInput"
                            name="search"
                            class="search-input"
                            placeholder="Search articles in <?= htmlspecialchars($category) ?>..."
                            autocomplete="off"
                            value="<?= htmlspecialchars($search) ?>"
                        >
                    </div>
                </form>
            </div>

            <div class="articles-container">
                <h2 class="articles-title" id="articlesCountTitle"><?= count($articles) ?> Articles</h2>

                <div id="categoryArticlesResults" aria-live="polite">
                    <?php if (!empty($articles)): ?>
                        <div class="article-grid">
                            <?php foreach ($articles as $article): ?>
                                <a href="view_article.php?id=<?= (int) $article['id'] ?>" class="article-card">
                                    <div class="article-card-body">
                                        <span class="article-badge">
                                            <i class="fas <?= htmlspecialchars(kb_category_icon_class((string) $article['category'])) ?>"></i>
                                            <?= htmlspecialchars($category) ?>
                                        </span>
                                        <h3 class="article-title"><?= htmlspecialchars((string) $article['title']) ?></h3>
                                        <p class="article-preview">
                                            <?= htmlspecialchars(kb_excerpt_text((string) $article['content'], 140)) ?>
                                        </p>
                                    </div>
                                    <div class="article-footer">
                                        <span><i class="far fa-eye"></i> <?= number_format((int) ($article['views'] ?? 0)) ?> views</span>
                                        <span>Read Article <i class="fas fa-arrow-right"></i></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <p>No articles found in this category yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>
    <script>
        (function () {
            var form = document.getElementById('categorySearchForm');
            var input = document.getElementById('categorySearchInput');
            var results = document.getElementById('categoryArticlesResults');
            var countTitle = document.getElementById('articlesCountTitle');
            if (!form || !input || !results || !countTitle) return;

            var category = <?= json_encode($category, JSON_UNESCAPED_UNICODE) ?>;
            var timer = null;
            var requestId = 0;

            function setUrl(searchValue) {
                var url = new URL(window.location.href);
                url.searchParams.set('category', category);
                if (searchValue) {
                    url.searchParams.set('search', searchValue);
                } else {
                    url.searchParams.delete('search');
                }
                window.history.replaceState({}, '', url.toString());
            }

            function renderLoading() {
                results.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Searching articles...</p></div>';
            }

            function runSearch() {
                var q = String(input.value || '').trim();
                requestId += 1;
                var currentRequest = requestId;
                renderLoading();

                var url = new URL('ajax_category_articles_search.php', window.location.href);
                url.searchParams.set('category', category);
                url.searchParams.set('search', q);

                fetch(url.toString(), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (currentRequest !== requestId) return;
                        if (!data || !data.ok) {
                            results.innerHTML = '<div class="empty-state"><i class="fas fa-book-open"></i><p>Unable to load articles right now.</p></div>';
                            return;
                        }
                        results.innerHTML = data.html || '';
                        countTitle.textContent = String(data.count || 0) + ' Articles';
                        setUrl(q);
                    })
                    .catch(function () {
                        if (currentRequest !== requestId) return;
                        results.innerHTML = '<div class="empty-state"><i class="fas fa-book-open"></i><p>Unable to load articles right now.</p></div>';
                    });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearTimeout(timer);
                runSearch();
            });

            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(runSearch, 250);
            });

        })();
    </script>
</body>
</html>
