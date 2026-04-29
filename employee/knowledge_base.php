<?php
require_once '../config/database.php';
require_once '../includes/kb_media.php';

// Protect page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

kb_ensure_article_views_table($conn);

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

function kb_category_label(string $category): string
{
    $normalized = trim($category);
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
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }

    return $normalized !== '' ? $normalized : 'IT';
}

function kb_category_meta(string $category): array
{
    $label = kb_category_label($category);
    $map = [
        'HR' => ['icon' => 'fa-users', 'tone' => 'teal'],
        'IT' => ['icon' => 'fa-desktop', 'tone' => 'sky'],
        'Accounting' => ['icon' => 'fa-calculator', 'tone' => 'emerald'],
        'Marketing' => ['icon' => 'fa-bullhorn', 'tone' => 'violet'],
        'Admin & Legal' => ['icon' => 'fa-gavel', 'tone' => 'sand'],
        'Management' => ['icon' => 'fa-briefcase', 'tone' => 'blue'],
        'Technical' => ['icon' => 'fa-screwdriver-wrench', 'tone' => 'mint'],
        'Diagnostics / Lingap' => ['icon' => 'fa-heart-pulse', 'tone' => 'slate'],
        'Uncategorized' => ['icon' => 'fa-folder-open', 'tone' => 'slate'],
    ];

    return $map[$label] ?? $map['Uncategorized'];
}

function kb_is_standard_category(string $category): bool
{
    static $standard = [
        'HR',
        'IT',
        'Accounting',
        'Marketing',
        'Admin & Legal',
        'Management',
        'Technical',
        'Diagnostics / Lingap',
    ];

    return in_array(kb_category_label($category), $standard, true);
}

function kb_category_aliases(string $category): array
{
    $label = kb_category_label($category);
    $map = [
        'IT' => ['IT', 'Technical Support', 'Hardware', 'Hardware Issue', 'Hardware Issues', 'Software', 'Software Issue', 'Software Issues', 'Email', 'Email Problem', 'Internet Concerns', 'Network', 'Network Issue', 'Network Issues', 'Printer'],
        'Admin & Legal' => ['Admin & Legal', 'Documentation', 'Documentations', 'Procurement'],
        'Management' => ['Management', 'Others'],
    ];
    $aliases = $map[$label] ?? [$label];

    return array_values(array_unique(array_filter(array_map('trim', $aliases))));
}

function kb_ticket_categories_for_department(string $department): array
{
    $defaultCategories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support'];
    $map = [
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
            'Technical Support',
        ],
        'Marketing' => [
            'Marketing Request',
        ],
    ];

    return $map[$department] ?? $defaultCategories;
}

function kb_ticket_category_icon(string $category): string
{
    $map = [
        'Attendance & Timekeeping' => 'fa-clock',
        'Certificate of Employment' => 'fa-id-card',
        'Certificate of Leave' => 'fa-file-signature',
        'Documentation' => 'fa-file-lines',
        'Email' => 'fa-envelope',
        'FleetCard Request' => 'fa-gas-pump',
        'Hardware' => 'fa-desktop',
        'Internet Concerns' => 'fa-globe',
        'Leave Concern' => 'fa-calendar-days',
        'Marketing Request' => 'fa-bullhorn',
        'Medical Cash Advance' => 'fa-hand-holding-medical',
        'Others' => 'fa-folder',
        'Phone Plan / Simcard' => 'fa-sim-card',
        'Procurement' => 'fa-cart-shopping',
        'Request for Company Property' => 'fa-box-open',
        'SAP' => 'fa-database',
        'Software' => 'fa-download',
        'SSS Sickness and Benefit Concern' => 'fa-heart-pulse',
        'Supplies' => 'fa-boxes-stacked',
        'Technical Support' => 'fa-headset',
        'Training Request' => 'fa-chalkboard-user',
    ];

    return $map[$category] ?? kb_category_meta($category)['icon'];
}

function kb_others_subcategory_name(array $row): string
{
    $rawCategory = trim((string) ($row['category'] ?? ''));
    $subCategory = trim((string) ($row['sub_category'] ?? ''));
    if (strcasecmp($rawCategory, 'Others') === 0) {
        return $subCategory;
    }
    if ($rawCategory !== '' && !kb_is_standard_category($rawCategory)) {
        return $rawCategory;
    }
    return '';
}

// 2. Fetch categories with article counts
$categoryCards = [];
$categoryMap = [];
$fixedCategories = [
    'IT',
    'HR',
    'Accounting',
    'Marketing',
    'Admin & Legal',
    'Management',
    'Diagnostics / Lingap',
    'Technical',
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
        if (!kb_is_standard_category($normalizedCategory)) {
            $normalizedCategory = 'Uncategorized';
        }
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
        'icon' => kb_category_meta($fixedCategory)['icon'],
        'tone' => kb_category_meta($fixedCategory)['tone'],
        'total_articles' => (int) ($categoryCounts[$fixedCategory] ?? 0),
    ];
    $categoryMap[$categoryIndex] = $fixedCategory;
    $categoryIndex++;
}

// 3. Most recent articles for homepage section
$mostVisitedArticles = [];
$mostVisitedStmt = $conn->prepare("
    SELECT id, title, category, created_at, " . kb_unique_views_count_sql('knowledge_base.id') . " AS views
    FROM knowledge_base
    ORDER BY created_at DESC, id DESC
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
        SELECT id, title, category, content, created_at, " . kb_unique_views_count_sql('knowledge_base.id') . " AS views
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

$selectedCategory = trim((string) ($_GET['category'] ?? ''));
$selectedSubCategory = trim((string) ($_GET['sub'] ?? ''));
$activeCategory = ($selectedCategory !== '' && in_array($selectedCategory, $fixedCategories, true)) ? $selectedCategory : '';
$showCategoryView = ($activeCategory !== '');
$showHomeSections = ($search === '' && !$showCategoryView);
$othersSubcategories = [];
$categoryArticles = [];
$categoryViewTitle = $activeCategory;
$departmentRecentArticles = [];
$departmentCategoryCards = [];

if ($showCategoryView) {
    $viewStmt = $conn->prepare("
        SELECT id, title, category, sub_category, content, created_at, " . kb_unique_views_count_sql('knowledge_base.id') . " AS views
        FROM knowledge_base
        ORDER BY created_at DESC, id DESC
    ");
    if ($viewStmt) {
        $viewStmt->execute();
        $viewResult = $viewStmt->get_result();
        $subCategoryCounts = [];
        while ($viewResult && ($row = $viewResult->fetch_assoc())) {
            $rowCategory = trim((string) ($row['category'] ?? ''));
            if ($activeCategory === 'Others') {
                $subName = kb_others_subcategory_name($row);
                if ($selectedSubCategory === '') {
                    if ($subName !== '') {
                        if (!isset($subCategoryCounts[$subName])) {
                            $subCategoryCounts[$subName] = 0;
                        }
                        $subCategoryCounts[$subName]++;
                    }
                } elseif ($subName !== '' && strcasecmp($subName, $selectedSubCategory) === 0) {
                    $categoryArticles[] = $row;
                }
            } elseif (in_array($rowCategory, kb_category_aliases($activeCategory), true)) {
                $categoryArticles[] = $row;
            }
        }
        $viewStmt->close();

        if ($activeCategory === 'Others' && $selectedSubCategory === '') {
            ksort($subCategoryCounts, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($subCategoryCounts as $subName => $totalArticles) {
                $othersSubcategories[] = [
                    'name' => $subName,
                    'total_articles' => (int) $totalArticles,
                ];
            }
        }
    }

    if ($activeCategory === 'Others' && $selectedSubCategory !== '') {
        $categoryViewTitle = $selectedSubCategory;
    }

    $departmentRecentArticles = array_slice($categoryArticles, 0, 3);
    $departmentBreakdown = array_fill_keys(kb_ticket_categories_for_department($activeCategory), 0);
    foreach ($categoryArticles as $articleForBreakdown) {
        $rawBreakdownCategory = trim((string) ($articleForBreakdown['category'] ?? ''));
        $rawBreakdownSubCategory = trim((string) ($articleForBreakdown['sub_category'] ?? ''));
        if ($rawBreakdownSubCategory !== '' && isset($departmentBreakdown[$rawBreakdownSubCategory])) {
            $departmentBreakdown[$rawBreakdownSubCategory]++;
            continue;
        }
        $breakdownLabel = $rawBreakdownCategory !== '' ? kb_category_label($rawBreakdownCategory) : $activeCategory;
        foreach (array_keys($departmentBreakdown) as $ticketCategory) {
            if (strcasecmp($rawBreakdownCategory, $ticketCategory) === 0 || strcasecmp($breakdownLabel, $ticketCategory) === 0) {
                $departmentBreakdown[$ticketCategory]++;
                continue 2;
            }
        }
        if ($rawBreakdownCategory !== '' && !kb_is_standard_category($rawBreakdownCategory) && !isset($departmentBreakdown[$rawBreakdownCategory])) {
            $departmentBreakdown[$rawBreakdownCategory] = 0;
        }
    }
    foreach ($departmentBreakdown as $breakdownLabel => $breakdownTotal) {
        $departmentCategoryCards[] = [
            'label' => $breakdownLabel,
            'total_articles' => (int) $breakdownTotal,
            'icon' => kb_ticket_category_icon($breakdownLabel),
        ];
    }
}

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
                linear-gradient(rgba(255, 255, 255, 0.62), rgba(255, 255, 255, 0.72)),
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
            padding: 64px 20px 56px;
        }

        /* Header Section */
        .kb-header {
            text-align: center;
            margin-bottom: 46px;
        }

        .kb-title {
            color: #1B5E20;
            font-size: 30px;
            font-weight: 500;
            margin-bottom: 34px;
        }

        .kb-breadcrumb {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: -20px 0 24px;
            color: #244263;
            font-size: 13px;
            font-weight: 500;
        }

        .kb-breadcrumb a {
            color: #244263;
            text-decoration: none;
        }

        .kb-breadcrumb a:hover {
            color: #1B5E20;
        }

        .kb-breadcrumb .active {
            color: #1B5E20;
            font-weight: 700;
        }

        .kb-breadcrumb i {
            color: #6B7280;
            font-size: 10px;
        }

        .department-back-row {
            margin: 0 0 18px;
            text-align: left;
        }

        .department-back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 26px;
            border-radius: 999px;
            background: #2f8b49;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 10px 22px rgba(47, 139, 73, 0.18);
            transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }

        .department-back-btn i {
            color: #f4c430;
            font-size: 15px;
        }

        .department-back-btn:hover {
            background: #27763e;
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(47, 139, 73, 0.24);
        }

        /* Search & Filter Form */
        .search-filter-wrapper {
            display: flex;
            gap: 16px;
            justify-content: center;
            align-items: stretch;
            max-width: 920px;
            margin: 0 auto;
            flex-wrap: wrap;
        }

        .department-search {
            max-width: 620px;
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
            min-height: 60px;
            padding: 16px 18px 16px 54px;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            font-size: 18px;
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

        .most-visited-section {
            display: none !important;
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
            background:
                linear-gradient(rgba(255, 255, 255, 0.88), rgba(255, 255, 255, 0.92)),
                url('../assets/img/kbkb.jpg') center / cover no-repeat;
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
            background: rgba(248, 250, 252, 0.76);
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

        .home-departments {
            max-width: 920px;
        }

        .department-view {
            max-width: 920px;
            margin: 0 auto 36px;
        }

        .department-list {
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(226, 232, 240, 0.96);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
            margin-bottom: 14px;
        }

        .department-article {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 16px 18px;
            color: #1F2937;
            text-decoration: none;
            border-bottom: 1px solid #E5E7EB;
        }

        .department-article:last-child {
            border-bottom: 0;
        }

        .department-article:hover {
            background: rgba(248, 250, 252, 0.78);
        }

        .department-article-main {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            min-width: 0;
        }

        .department-article-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #16713A;
            background: #EAF7ED;
            flex: 0 0 auto;
        }

        .department-article-title {
            margin: 0 0 6px;
            color: #1F2937;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.25;
        }

        .department-article-date {
            color: #64748B;
            font-size: 13px;
            white-space: nowrap;
            flex: 0 0 auto;
        }

        .department-categories {
            margin-top: 10px;
        }

        .department-categories .category-card {
            min-height: 66px;
            padding: 14px 18px;
            border-radius: 10px;
            justify-content: space-between;
        }

        .department-categories .category-main {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .department-categories .category-icon {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            font-size: 17px;
        }

        .department-categories .category-info h4 {
            font-size: 15px;
            font-weight: 600;
        }

        .department-categories .category-info p {
            font-size: 13px;
        }

        .category-arrow {
            color: #1E6A2D;
            font-size: 14px;
            flex: 0 0 auto;
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
            font-weight: 800;
            text-align: left;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .home-departments .category-grid {
            gap: 18px 24px;
        }

        .category-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 18px 20px;
            background: rgba(255, 255, 255, 0.97);
            border: 1px solid rgba(222, 232, 224, 0.95);
            border-radius: 18px;
            text-decoration: none;
            color: #1F2937;
            cursor: pointer;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.05);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease, background 0.2s ease;
        }

        .home-departments .category-card {
            min-height: 92px;
            gap: 18px;
            padding: 18px 22px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.94);
            border-color: rgba(226, 232, 240, 0.92);
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        }

        .category-card:hover {
            border-color: rgba(27, 94, 32, 0.22);
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.09);
            transform: translateY(-2px);
        }

        .category-card.active {
            border-color: rgba(27, 94, 32, 0.26);
            background: rgba(255, 255, 255, 0.99);
        }

        .category-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
            color: #1E6A2D;
            flex: 0 0 auto;
            background: #EDF8EF;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.82);
        }

        .home-departments .category-icon {
            width: 58px;
            height: 58px;
            border-radius: 999px;
            font-size: 23px;
        }

        .tone-teal .category-icon,
        .tone-sand .category-icon,
        .tone-violet .category-icon,
        .tone-blue .category-icon,
        .tone-emerald .category-icon,
        .tone-sky .category-icon,
        .tone-mint .category-icon,
        .tone-slate .category-icon {
            background: #EDF8EF;
            color: #1E6A2D;
        }

        .category-info h4 {
            margin: 0 0 4px;
            color: #1F2937;
            font-size: 18px;
            font-weight: 600;
            line-height: 1.2;
        }

        .home-departments .category-info h4 {
            font-size: 18px;
            font-weight: 500;
        }

        .category-info p {
            margin: 0;
            color: #6B7280;
            font-size: 14px;
            font-weight: 500;
        }

        .home-departments .category-info p {
            font-size: 15px;
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
            justify-content: center;
            gap: 9px;
            margin-bottom: 16px;
            padding: 7px 19px;
            min-height: 40px;
            border-radius: 999px;
            border: 1px solid rgba(189, 223, 179, 0.62);
            background: #4a8f58;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.1);
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }

        .back-btn:hover {
            background: #5a9d67;
            border-color: rgba(213, 238, 204, 0.7);
            transform: translateY(-1px);
        }
        .back-btn i {
            color: #f6cf4a;
        }

        /* Grid Layout */
        .kb-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 30px;
        }

        /* Card Styles */
        .kb-card {
            text-decoration: none;
            color: inherit;
            background:
                linear-gradient(rgba(255, 255, 255, 0.86), rgba(255, 255, 255, 0.92)),
                url('../assets/img/kbkb.jpg') center / cover no-repeat;
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
            background-color: rgba(250, 250, 250, 0.72);
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
            <h1 class="kb-title"><?= $showCategoryView ? htmlspecialchars($categoryViewTitle) . ' Knowledge Base' : 'Knowledge Base' ?></h1>
            <?php if ($showCategoryView): ?>
                <div class="kb-breadcrumb" aria-label="Breadcrumb">
                    <a href="knowledge_base.php">Knowledge Base</a>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    <a href="knowledge_base.php">Departments</a>
                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                    <span class="active"><?= htmlspecialchars($categoryViewTitle) ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!($activeCategory === 'Others' && $selectedSubCategory === '')): ?>
                <form method="GET" class="search-filter-wrapper<?= $showCategoryView ? ' department-search' : '' ?>" id="kbSearchForm">
                    <?php if ($showCategoryView): ?>
                        <input type="hidden" name="category" value="<?= htmlspecialchars($activeCategory) ?>">
                    <?php endif; ?>
                    <div class="search-input-group">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" class="search-input" 
                               placeholder="<?= $showCategoryView ? 'Search ' . htmlspecialchars($categoryViewTitle) . ' articles, guides, or solutions...' : 'Search for articles, guides, or solutions...' ?>" 
                               value="<?= htmlspecialchars($search) ?>"
                               autocomplete="off">
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="results-section<?= $search !== '' ? ' is-active' : '' ?>" id="kbResultsSection"<?= $search !== '' ? '' : ' style="display:none;"' ?>>
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
                                <a href="view_article.php?id=<?= (int) $searchArticle['id'] ?>" class="kb-card">
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
                                        <span class="read-more-btn" aria-hidden="true">
                                            <i class="fas fa-arrow-right"></i>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php if (!empty($mostVisitedArticles)): ?>
            <div class="most-visited-section" id="kbMostVisitedSection"<?= $showHomeSections ? '' : ' style="display:none;"' ?>>
                <h2 class="most-visited-title">Most Recent Articles</h2>
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

        <?php if ($showCategoryView): ?>
            <div class="categories-section">
                <?php if ($activeCategory === 'Others' && $selectedSubCategory === ''): ?>
                    <a href="knowledge_base.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
                    <h2 class="categories-title">Other Categories</h2>
                    <?php if (empty($othersSubcategories)): ?>
                        <div class="no-results">
                            <div class="no-results-icon"><i class="fas fa-folder-open"></i></div>
                            <div class="no-results-text">No sub-categories available yet.</div>
                            <div class="no-results-sub">Articles saved under Others will appear here once a sub-category is added.</div>
                        </div>
                    <?php else: ?>
                        <div class="category-grid">
                            <?php foreach ($othersSubcategories as $subCategoryCard): ?>
                                <a
                                    href="knowledge_base.php?category=Others&sub=<?= urlencode($subCategoryCard['name']) ?>"
                                    class="category-card"
                                >
                                    <div class="category-icon">
                                        <i class="fas fa-folder"></i>
                                    </div>
                                    <div class="category-info">
                                        <h4><?= htmlspecialchars($subCategoryCard['name']) ?></h4>
                                        <p><?= number_format((int) $subCategoryCard['total_articles']) ?> Articles</p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="department-view">
                        <div class="department-back-row">
                            <a href="knowledge_base.php" class="department-back-btn">
                                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                                <span>Back</span>
                            </a>
                        </div>

                        <?php if (empty($departmentRecentArticles)): ?>
                            <div class="no-results">
                                <div class="no-results-icon"><i class="fas fa-book-open"></i></div>
                                <div class="no-results-text">No articles found.</div>
                                <div class="no-results-sub">There are no published articles in this section yet.</div>
                            </div>
                        <?php else: ?>
                            <div class="department-list">
                                <?php foreach ($departmentRecentArticles as $categoryArticle): ?>
                                    <?php
                                    $articleCategory = trim((string) ($categoryArticle['category'] ?? ''));
                                    $articleSubCategory = trim((string) ($categoryArticle['sub_category'] ?? ''));
                                    $badgeText = $activeCategory === 'Others'
                                        ? ($articleSubCategory !== '' ? $articleSubCategory : kb_others_subcategory_name($categoryArticle))
                                        : ($articleSubCategory !== '' ? $articleSubCategory : kb_category_label($articleCategory));
                                    ?>
                                    <a href="view_article.php?id=<?= (int) $categoryArticle['id'] ?>" class="department-article">
                                        <div class="department-article-main">
                                            <span class="department-article-icon" aria-hidden="true">
                                                <i class="fas fa-file-lines"></i>
                                            </span>
                                            <span>
                                                <h3 class="department-article-title"><?= htmlspecialchars((string) $categoryArticle['title']) ?></h3>
                                                <span class="kb-category-badge"><?= htmlspecialchars($badgeText !== '' ? $badgeText : $categoryViewTitle) ?></span>
                                            </span>
                                        </div>
                                        <span class="department-article-date">
                                            <?= !empty($categoryArticle['created_at']) ? date('M d, Y', strtotime((string) $categoryArticle['created_at'])) : '' ?>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="department-categories">
                            <h2 class="categories-title">Categories</h2>
                            <div class="category-grid">
                                <?php foreach ($departmentCategoryCards as $departmentCategoryCard): ?>
                                    <a href="knowledge_base.php?category=<?= urlencode($activeCategory) ?>" class="category-card">
                                        <span class="category-main">
                                            <span class="category-icon" aria-hidden="true">
                                                <i class="fas <?= htmlspecialchars($departmentCategoryCard['icon']) ?>"></i>
                                            </span>
                                            <span class="category-info">
                                                <h4><?= htmlspecialchars($departmentCategoryCard['label']) ?></h4>
                                                <p><?= number_format((int) $departmentCategoryCard['total_articles']) ?> Article<?= (int) $departmentCategoryCard['total_articles'] === 1 ? '' : 's' ?></p>
                                            </span>
                                        </span>
                                        <i class="fas fa-chevron-right category-arrow" aria-hidden="true"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="categories-section home-departments">
                <h2 class="categories-title">Departments</h2>
                <div class="category-grid">
                    <?php foreach ($categoryCards as $categoryCard): ?>
                        <a
                            href="knowledge_base.php?category=<?= urlencode($categoryCard['raw']) ?>"
                            class="category-card tone-<?= htmlspecialchars($categoryCard['tone']) ?>"
                        >
                            <div class="category-icon">
                                <i class="fas <?= htmlspecialchars($categoryCard['icon']) ?>"></i>
                            </div>
                            <div class="category-info">
                                <h4><?= htmlspecialchars($categoryCard['label']) ?></h4>
                                <p>Browse articles</p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

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
