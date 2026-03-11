<?php
require_once '../config/database.php';

// Protect page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

// 1. Handle Search & Filter
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';

// 2. Base Query
$query = "SELECT * FROM knowledge_base WHERE 1=1";
$params = [];
$types = "";

// 3. Apply Filters
if (!empty($search)) {
    $query .= " AND (title LIKE ? OR content LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "ss";
}

if (!empty($category)) {
    $query .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

// 4. Sort
$query .= " ORDER BY created_at DESC";

// 5. Execute
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 6. Pre-defined Categories
$categories = ['Network Issue', 'Hardware Issue', 'Software Issue', 'Email Problem', 'Account Access', 'Technical Support', 'Other'];
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
            background-color: #F9FAFB;
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
            max-width: 800px;
            margin: 0 auto;
            flex-wrap: wrap;
        }

        .search-input-group {
            position: relative;
            flex: 1;
            min-width: 320px;
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
            padding: 14px 14px 14px 48px;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            font-size: 16px;
            background: white;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .search-input:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.1);
        }

        .category-select {
            padding: 14px 24px;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            background: white;
            color: #374151;
            font-size: 16px;
            cursor: pointer;
            min-width: 200px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236B7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
        }

        .category-select:focus {
            outline: none;
            border-color: #1B5E20;
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
            .category-select {
                width: 100%;
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
            <h1 class="kb-title"> Knowledge Base</h1>
            
            <form method="GET" class="search-filter-wrapper">
                <div class="search-input-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" name="search" class="search-input" 
                           placeholder="Search for articles, guides, or solutions..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <select name="category" class="category-select" onchange="this.form.submit()">
                    <option value=""disabled selected hidden>All Categories</option>
                    <?php foreach($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" 
                                <?= $category === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <!-- Articles Grid -->
        <div class="kb-grid">
            <?php if ($result->num_rows > 0): ?>
                <?php while($article = $result->fetch_assoc()): ?>
                    <div class="kb-card" onclick="window.location.href='view_article.php?id=<?= $article['id'] ?>'">
                        <?php if (!empty($article['image_path'])): ?>
                            <div style="height: 180px; overflow: hidden; border-bottom: 1px solid #E5E7EB;">
                                <img src="../<?= htmlspecialchars($article['image_path']) ?>" alt="<?= htmlspecialchars($article['title']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            </div>
                        <?php endif; ?>
                        <div class="kb-card-body">
                            <span class="kb-category-badge">
                                <?= htmlspecialchars($article['category']) ?>
                            </span>
                            <h3 class="kb-card-title">
                                <?= htmlspecialchars($article['title']) ?>
                            </h3>
                            <div class="kb-card-preview">
                                <?= htmlspecialchars(substr(strip_tags($article['content']), 0, 120)) ?>...
                            </div>
                        </div>
                        <div class="kb-card-footer">
                            <div class="kb-views">
                                <i class="far fa-eye"></i> <?= number_format($article['views'] ?? 0) ?> views
                            </div>
                            <a href="view_article.php?id=<?= $article['id'] ?>" class="read-more-btn">
                                Read Article <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-results">
                    <div class="no-results-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <p class="no-results-text">No articles found</p>
                    <p class="no-results-sub">Try adjusting your search or category filter</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script src="../js/employee-dashboard.js"></script>
</body>
</html>

