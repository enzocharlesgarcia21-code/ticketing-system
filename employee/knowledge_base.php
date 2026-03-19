<?php
require_once '../config/database.php';

// Protect page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

// 1. Handle Search
$search = trim((string) ($_GET['search'] ?? ''));

function kb_excerpt(string $text, int $maxLen = 160): string
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
    return 'fa-folder';
}

// 2. Fetch categories with article counts
$categoryCards = [];
$categoryMap = [];
$fixedCategories = [
    'Documentation',
    'Email',
    'Hardware',
    'Internet Concerns',
    'Procurement',
    'Software',
    'Technical Support',
];
$categoryCounts = [];
$catStmt = $conn->prepare("
    SELECT category, COUNT(*) AS total_articles
    FROM knowledge_base
    WHERE category IS NOT NULL AND category <> ''
    GROUP BY category
    ORDER BY category ASC
");
if ($catStmt) {
    $catStmt->execute();
    $catResult = $catStmt->get_result();
    $categoryIndex = 1;
    while ($row = $catResult->fetch_assoc()) {
        $rawCategory = trim((string) ($row['category'] ?? ''));
        if ($rawCategory === '') {
            continue;
        }
        $normalizedCategory = kb_category_label($rawCategory);
        if (!isset($categoryCounts[$normalizedCategory])) {
            $categoryCounts[$normalizedCategory] = 0;
        }
        $categoryCounts[$normalizedCategory] += (int) ($row['total_articles'] ?? 0);
    }
    $catStmt->close();
}

foreach ($fixedCategories as $fixedCategory) {
    $categoryCards[] = [
        'id' => $categoryIndex,
        'raw' => $fixedCategory,
        'label' => $fixedCategory,
        'icon' => kb_category_icon_class($fixedCategory),
        'total_articles' => (int) ($categoryCounts[$fixedCategory] ?? 0),
    ];
    $categoryMap[$categoryIndex] = $fixedCategory;
    $categoryIndex++;
}

// 3. Most visited articles for homepage section
$mostVisitedArticles = [];
$mostVisitedStmt = $conn->prepare("
    SELECT id, title, category, created_at, COALESCE(views, 0) AS views
    FROM knowledge_base
    ORDER BY COALESCE(views, 0) DESC, created_at DESC
    LIMIT 3
");
if ($mostVisitedStmt) {
    $mostVisitedStmt->execute();
    $mostVisitedResult = $mostVisitedStmt->get_result();
    while ($row = $mostVisitedResult->fetch_assoc()) {
        $mostVisitedArticles[] = $row;
    }
    $mostVisitedStmt->close();
}

$searchResults = [];
if ($search !== '') {
    $searchStmt = $conn->prepare("
        SELECT id, title, category, content, created_at, COALESCE(views, 0) AS views
        FROM knowledge_base
        WHERE title LIKE ?
           OR content LIKE ?
           OR category LIKE ?
        ORDER BY created_at DESC
    ");
    if ($searchStmt) {
        $term = '%' . $search . '%';
        $searchStmt->bind_param("sss", $term, $term, $term);
        $searchStmt->execute();
        $searchResult = $searchStmt->get_result();
        while ($searchResult && ($row = $searchResult->fetch_assoc())) {
            $searchResults[] = $row;
        }
        $searchStmt->close();
    }
}

$showHomeSections = ($search === '');

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Base | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Knowledge Base Specific Styles */
        body {
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.16)),
                url('../assets/img/kbkb.jpg');
            background-repeat: no-repeat;
            background-position: center top;
            background-attachment: fixed;
            background-size: cover;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .kb-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        /* Header Section */
        .kb-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .kb-title {
            color: #1B5E20;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 24px;
        }

        /* Search & Filter Form */
        .search-filter-wrapper {
            display: flex;
            gap: 16px;
            justify-content: center;
            align-items: stretch;
            max-width: 980px;
            margin: 0 auto;
            flex-wrap: wrap;
        }

        .search-input-group {
            position: relative;
            flex: 1;
            min-width: 0;
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 16px;
        }

        .search-input {
            width: 100%;
            min-height: 58px;
            padding: 16px 18px 16px 52px;
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

        .most-visited-section,
        .results-section {
            max-width: 980px;
            margin: -18px auto 36px;
        }

        .results-section.is-loading {
            opacity: 0.65;
            transition: opacity 0.2s ease;
        }

        .most-visited-title,
        .results-title {
            margin: 0 0 14px;
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            text-align: left;
        }

        .most-visited-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid #E5E7EB;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        }

        .most-visited-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            text-decoration: none;
            color: inherit;
            border-bottom: 1px solid #E5E7EB;
            transition: background 0.2s ease;
        }

        .most-visited-item:last-child {
            border-bottom: none;
        }

        .most-visited-item:hover {
            background: #F8FAFC;
        }

        .most-visited-main {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            min-width: 0;
        }

        .most-visited-icon {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            background: #E8F5E9;
            color: #1B5E20;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex: 0 0 auto;
            margin-top: 2px;
        }

        .most-visited-content {
            min-width: 0;
        }

        .most-visited-heading {
            margin: 0 0 8px;
            color: #1F2937;
            font-size: 18px;
            font-weight: 600;
            line-height: 1.35;
        }

        .most-visited-tag {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            background: #EEF2F0;
            color: #4B5563;
            font-size: 13px;
            font-weight: 500;
        }

        .most-visited-date {
            color: #6B7280;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            flex: 0 0 auto;
            padding-top: 2px;
        }

        .categories-section {
            max-width: 980px;
            margin: 0 auto 36px;
        }

        .results-section {
            margin-top: -18px;
        }

        .results-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .results-count {
            color: #6B7280;
            font-size: 14px;
            font-weight: 600;
        }

        .categories-title {
            margin: 0 0 14px;
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            text-align: left;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .category-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            text-decoration: none;
            color: #111827;
            cursor: pointer;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .category-card:hover {
            border-color: #1B5E20;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            transform: translateY(-2px);
        }

        .category-card.active {
            border-color: #1B5E20;
            background: #E8F5E9;
        }

        .category-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #1B5E20;
            background: #F0FDF4;
            flex: 0 0 auto;
        }

        .category-info h4 {
            margin: 0 0 4px;
            color: #1F2937;
            font-size: 18px;
            font-weight: 600;
            line-height: 1.2;
        }

        .category-info p {
            margin: 0;
            color: #6B7280;
            font-size: 14px;
            font-weight: 500;
        }

        .articles-heading {
            max-width: 980px;
            margin: 0 auto 18px;
            color: #111827;
            font-size: 20px;
            font-weight: 700;
            text-align: left;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            color: #1B5E20;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
        }

        .back-btn:hover {
            text-decoration: underline;
        }

        /* Grid Layout */
        .kb-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 30px;
        }

        /* Card Styles */
        .kb-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #E5E7EB;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            height: 100%;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .kb-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.1);
            border-color: #1B5E20;
        }

        .kb-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1B5E20, #4CAF50);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .kb-card:hover::before {
            opacity: 1;
        }

        .kb-card-body {
            padding: 24px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .kb-category-badge {
            display: inline-flex;
            align-items: center;
            background-color: #E8F5E9;
            color: #1B5E20;
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
            align-self: flex-start;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kb-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .kb-card-preview {
            color: #6B7280;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .kb-card-footer {
            padding: 20px 24px;
            border-top: 1px solid #F3F4F6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #FAFAFA;
        }

        .kb-views {
            font-size: 13px;
            color: #9CA3AF;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .read-more-btn {
            color: #1B5E20;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: gap 0.2s;
        }

        .read-more-btn:hover {
            gap: 10px;
        }

        /* Empty State */
        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 16px;
            border: 1px dashed #E5E7EB;
        }

        .no-results-icon {
            font-size: 48px;
            color: #D1D5DB;
            margin-bottom: 16px;
        }

        .no-results-text {
            color: #374151;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .no-results-sub {
            color: #6B7280;
            font-size: 14px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .kb-header {
                margin-bottom: 30px;
            }
            .kb-title {
                font-size: 24px;
            }
            .search-input-group {
                min-width: 100%;
            }
            .most-visited-section {
                margin-top: -8px;
            }
            .results-section {
                margin-top: -8px;
            }
            .most-visited-item {
                flex-direction: column;
            }
            .most-visited-date {
                padding-top: 0;
                padding-left: 44px;
            }
            .categories-section {
                margin-top: 0;
            }
            .category-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <!-- Top Navigation -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="kb-container">
        
        <!-- Search & Filter Header -->
        <div class="kb-header">
            <h1 class="kb-title">Knowledge Base</h1>
            
            <form method="GET" class="search-filter-wrapper" id="kbSearchForm">
                <div class="search-input-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search for articles, guides, or solutions..." 
                           value="<?= htmlspecialchars($search) ?>"
                           autocomplete="off">
                </div>
            </form>
        </div>

        <div class="results-section<?= $showHomeSections ? '' : ' is-active' ?>" id="kbResultsSection"<?= $showHomeSections ? ' style="display:none;"' : '' ?>>
                <div class="results-meta">
                    <h2 class="results-title">Search Results</h2>
                    <div class="results-count" id="kbResultsCount"><?= number_format(count($searchResults)) ?> article<?= count($searchResults) === 1 ? '' : 's' ?> found</div>
                </div>

                <div id="kbResultsContent">
                    <?php if (empty($searchResults)): ?>
                        <div class="no-results">
                            <div class="no-results-icon"><i class="fas fa-book-open"></i></div>
                            <div class="no-results-text">No articles found.</div>
                            <div class="no-results-sub">Try a different keyword or browse the categories below.</div>
                        </div>
                    <?php else: ?>
                        <div class="kb-grid">
                            <?php foreach ($searchResults as $searchArticle): ?>
                                <div class="kb-card">
                                    <div class="kb-card-body">
                                        <span class="kb-category-badge"><?= htmlspecialchars(kb_category_label((string) $searchArticle['category'])) ?></span>
                                        <h3 class="kb-card-title"><?= htmlspecialchars((string) $searchArticle['title']) ?></h3>
                                        <p class="kb-card-preview"><?= htmlspecialchars(kb_excerpt((string) ($searchArticle['content'] ?? ''), 160)) ?></p>
                                    </div>
                                    <div class="kb-card-footer">
                                        <span class="kb-views">
                                            <i class="fas fa-calendar"></i>
                                            <?= !empty($searchArticle['created_at']) ? date('M d, Y', strtotime((string) $searchArticle['created_at'])) : '' ?>
                                        </span>
                                        <a href="view_article.php?id=<?= (int) $searchArticle['id'] ?>" class="read-more-btn">
                                            Read More <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php if (!empty($mostVisitedArticles)): ?>
            <div class="most-visited-section" id="kbMostVisitedSection"<?= $showHomeSections ? '' : ' style="display:none;"' ?>>
                <h2 class="most-visited-title">Most Visited Articles</h2>
                <div class="most-visited-card">
                    <?php foreach ($mostVisitedArticles as $visitedArticle): ?>
                        <a href="view_article.php?id=<?= (int) $visitedArticle['id'] ?>" class="most-visited-item">
                            <div class="most-visited-main">
                                <div class="most-visited-icon">
                                    <i class="fas fa-file-lines"></i>
                                </div>
                                <div class="most-visited-content">
                                    <h3 class="most-visited-heading"><?= htmlspecialchars($visitedArticle['title']) ?></h3>
                                    <span class="most-visited-tag"><?= htmlspecialchars(kb_category_label((string) $visitedArticle['category'])) ?></span>
                                </div>
                            </div>
                            <div class="most-visited-date">
                                <?= !empty($visitedArticle['created_at']) ? date('M d, Y', strtotime((string) $visitedArticle['created_at'])) : '' ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="categories-section">
            <h2 class="categories-title">Categories</h2>
            <div class="category-grid">
                <?php foreach ($categoryCards as $categoryCard): ?>
                    <a
                        href="category_articles.php?category=<?= urlencode($categoryCard['raw']) ?>"
                        class="category-card"
                    >
                        <div class="category-icon">
                            <i class="fas <?= htmlspecialchars($categoryCard['icon']) ?>"></i>
                        </div>
                        <div class="category-info">
                            <h4><?= htmlspecialchars($categoryCard['label']) ?></h4>
                            <p><?= number_format((int) $categoryCard['total_articles']) ?> Articles</p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <script src="../js/employee-dashboard.js"></script>
    <script>
    (function () {
        var form = document.getElementById('kbSearchForm');
        var input = form ? form.querySelector('input[name="search"]') : null;
        var resultsSection = document.getElementById('kbResultsSection');
        var resultsContent = document.getElementById('kbResultsContent');
        var resultsCount = document.getElementById('kbResultsCount');
        var mostVisitedSection = document.getElementById('kbMostVisitedSection');
        var timer = null;
        var lastFetchedValue = input ? input.value.trim() : '';
        var controller = null;

        if (!form || !input || !resultsSection || !resultsContent || !resultsCount) return;

        function updateUrl(value) {
            var url = new URL(window.location.href);
            if (value) {
                url.searchParams.set('search', value);
            } else {
                url.searchParams.delete('search');
            }
            window.history.replaceState({}, '', url.toString());
        }

        function setHomeVisible(isVisible) {
            if (!mostVisitedSection) return;
            mostVisitedSection.style.display = isVisible ? '' : 'none';
        }

        function fetchResults(force) {
            var currentValue = input.value.trim();

            if (!force && currentValue === lastFetchedValue) {
                return;
            }

            if (controller) {
                controller.abort();
            }

            if (currentValue === '') {
                lastFetchedValue = '';
                updateUrl('');
                resultsSection.style.display = 'none';
                resultsSection.classList.remove('is-loading');
                setHomeVisible(true);
                return;
            }

            controller = new AbortController();
            lastFetchedValue = currentValue;
            updateUrl(currentValue);
            resultsSection.style.display = '';
            resultsSection.classList.add('is-loading');
            setHomeVisible(false);

            fetch('ajax_kb_search.php?search=' + encodeURIComponent(currentValue), {
                signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error('Search failed');
                    }
                    resultsCount.textContent = data.count + ' article' + (data.count === 1 ? '' : 's') + ' found';
                    resultsContent.innerHTML = data.html;
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') return;
                    resultsCount.textContent = '0 articles found';
                    resultsContent.innerHTML = '<div class="no-results"><div class="no-results-icon"><i class="fas fa-book-open"></i></div><div class="no-results-text">Search is unavailable.</div><div class="no-results-sub">Please try again in a moment.</div></div>';
                })
                .finally(function () {
                    resultsSection.classList.remove('is-loading');
                });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            fetchResults(true);
        });

        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fetchResults(false);
            }, 350);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(timer);
                fetchResults(true);
            }
        });
    })();
    </script>
</body>
</html>
