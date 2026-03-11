<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Ensure email is in session (fix for existing sessions)
if (!isset($_SESSION['email']) && isset($_SESSION['user_id'])) {
    $u_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['user_id']);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $_SESSION['email'] = $u_row['email'];
    }
}

// Helper for formatting time
function formatHandlingTime($seconds) {
    if (!$seconds) return '0h';
    $hours = floor($seconds / 3600);
    if ($hours >= 24) {
        $days = floor($hours / 24);
        $rem_hours = $hours % 24;
        return "{$days}d {$rem_hours}h";
    }
    return "{$hours}h";
}

// Determine selected date range (default to current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// 2. Metrics for Selected Range (Based on created_at)
// Received: Created in this range
// Resolved: Created in this range AND status is Resolved (Cohort analysis)
// Closed: Created in this range AND status is Closed (Cohort analysis)

$metricsQuery = $conn->prepare("
    SELECT 
        COUNT(*) as received,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) as closed
    FROM employee_tickets 
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$metricsQuery->bind_param("ss", $start_date, $end_date);
$metricsQuery->execute();
$metrics = $metricsQuery->get_result()->fetch_assoc();

// 3. Weekly Average Handling Time (Based on resolved_at)
// Filter using resolved_at date range.

$handlingQuery = $conn->prepare("
    SELECT 
        YEARWEEK(resolved_at) as week, 
        AVG(TIMESTAMPDIFF(SECOND, started_at, resolved_at)) as avg_seconds 
    FROM employee_tickets 
    WHERE status = 'Resolved' 
    AND started_at IS NOT NULL 
    AND resolved_at IS NOT NULL 
    AND DATE(resolved_at) BETWEEN ? AND ?
    GROUP BY week 
    ORDER BY week ASC
");
$handlingQuery->bind_param("ss", $start_date, $end_date);
$handlingQuery->execute();
$handlingResult = $handlingQuery->get_result();

$weeks = [];
$week_avg_hours = [];

while ($row = $handlingResult->fetch_assoc()) {
    // Format week
    $year = substr($row['week'], 0, 4);
    $weekNum = substr($row['week'], 4);
    $weeks[] = "Week $weekNum";
    $week_avg_hours[] = round($row['avg_seconds'] / 3600, 1);
}

// Optional: Daily Received vs Resolved (Inside selected month)
// Received: based on created_at
// Resolved: based on resolved_at (performance) or status? 
// Usually "Daily Activity" tracks when things happened.
// I'll stick to the "Weekly Avg Handling Time" as the main chart requested.

?>
<!DOCTYPE html>
<html>
<head>
    <title>Analytics - Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../js/admin.js"></script>
    <style>
        /* Analytics Toolbar */
        .analytics-toolbar {
            background: white;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            justify-content: flex-start; /* Changed from space-between to flex-start to group items */
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            z-index: 10;
        }

        .filter-section {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap; /* Allow wrapping on small screens */
        }

        .filter-label {
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            white-space: nowrap;
        }

        .date-inputs {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            transition: border-color 0.2s;
        }

        .date-inputs:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .date-inputs input[type="date"] {
            border: none;
            background: transparent;
            font-size: 14px;
            color: #334155;
            outline: none;
            padding: 4px 0;
            font-family: inherit;
            cursor: pointer;
        }

        .date-separator {
            color: #94a3b8;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-apply {
            padding: 10px 20px;
            background-color: #1B5E20;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(27, 94, 32, 0.1);
        }

        .btn-apply:hover {
            background-color: #144a1e;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(27, 94, 32, 0.2);
        }

        .export-section {
            display: flex;
            gap: 12px;
        }

        .btn-export {
            display: inline-flex;
            align-items: center;
            padding: 10px 16px;
            background-color: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #475569;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-export:hover {
            background-color: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .btn-export-pdf { color: #dc2626; border-color: #fca5a5; background-color: #fef2f2; }
        .btn-export-pdf:hover { background-color: #fee2e2; border-color: #f87171; }
        
        .btn-export-excel { color: #059669; border-color: #6ee7b7; background-color: #ecfdf5; }
        .btn-export-excel:hover { background-color: #d1fae5; border-color: #34d399; }

        /* Dark Mode Support */
        body.dark-mode .analytics-toolbar {
            background: #1e293b;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }
        
        body.dark-mode .filter-label { color: #cbd5e1; }
        
        body.dark-mode .date-inputs {
            background: #0f172a;
            border-color: #334155;
        }
        
        body.dark-mode .date-inputs input[type="date"] {
            color: #e2e8f0;
            color-scheme: dark;
        }
        
        body.dark-mode .btn-export {
            background-color: #1e293b;
            border-color: #334155;
            color: #cbd5e1;
        }
        
        body.dark-mode .btn-export:hover {
            background-color: #334155;
        }

        body.dark-mode .btn-export-pdf { background-color: #450a0a; border-color: #7f1d1d; color: #fca5a5; }
        body.dark-mode .btn-export-pdf:hover { background-color: #7f1d1d; }

        body.dark-mode .btn-export-excel { background-color: #064e3b; border-color: #065f46; color: #6ee7b7; }
        body.dark-mode .btn-export-excel:hover { background-color: #065f46; }

        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .stat-icon {
            font-size: 24px;
            margin-bottom: 12px;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-received .stat-icon { background: #fefce8; color: #ca8a04; }
        .stat-resolved .stat-icon { background: #dcfce7; color: #16a34a; }
        .stat-closed .stat-icon { background: #f3f4f6; color: #4b5563; }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
        }
        .chart-section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 32px;
        }
        .chart-header {
            margin-bottom: 20px;
        }
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
        }
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }


        body.dark-mode .stat-card,
        body.dark-mode .chart-section {
            background: #1e293b;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
        }
        body.dark-mode .stat-label,
        body.dark-mode .chart-title {
            color: #cbd5e1;
        }
        body.dark-mode .stat-value {
            color: #f1f5f9;
        }
        body.dark-mode .stat-received .stat-icon { background: #422006; color: #facc15; }
        body.dark-mode .stat-resolved .stat-icon { background: #064e3b; color: #34d399; }
        body.dark-mode .stat-closed .stat-icon { background: #374151; color: #9ca3af; }


    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-content">
            
            <div class="admin-page-header">
                <h1 class="admin-page-title">Analytics Dashboard</h1>
            </div>

            <div class="analytics-toolbar">
                <form method="GET" action="analytics.php" class="filter-section">
                    <span class="filter-label">Date Range:</span>
                    <div class="date-inputs">
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>
                        <span class="date-separator">to</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>
                    </div>
                    <button type="submit" class="btn-apply">Apply Filter</button>
                </form>

                <div class="export-section">
                    <a href="export_analytics_pdf.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn-export btn-export-pdf" target="_blank">
                        <span style="margin-right:8px">📄</span> PDF
                    </a>
                    <a href="export_analytics_excel.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn-export btn-export-excel" target="_blank">
                        <span style="margin-right:8px">📊</span> Excel
                    </a>
                </div>
            </div>

            <!-- 1. Summary Cards -->
            <div class="analytics-grid">
                <div class="stat-card stat-received">
                    <div class="stat-icon">📦</div>
                    <span class="stat-label">Received</span>
                    <span class="stat-value"><?= number_format($metrics['received']) ?></span>
                </div>
                <div class="stat-card stat-resolved">
                    <div class="stat-icon">✅</div>
                    <span class="stat-label">Resolved</span>
                    <span class="stat-value"><?= number_format($metrics['resolved']) ?></span>
                </div>
                <div class="stat-card stat-closed">
                    <div class="stat-icon">🔒</div>
                    <span class="stat-label">Closed</span>
                    <span class="stat-value"><?= number_format($metrics['closed']) ?></span>
                </div>
            </div>

            <!-- 2. Weekly Chart -->
            <div class="chart-section">
                <div class="chart-header">
                    <div class="chart-title">Weekly Average Handling Time (<?= $start_date . ' to ' . $end_date ?>)</div>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // Theme colors
    const isDarkMode = document.body.classList.contains('dark-mode');
    const textColor = isDarkMode ? '#cbd5e1' : '#64748b';
    const gridColor = isDarkMode ? '#334155' : '#e2e8f0';

    // Weekly Chart
    const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
    new Chart(weeklyCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($weeks) ?>,
            datasets: [{
                label: 'Avg Handling Time (Hours)',
                data: <?= json_encode($week_avg_hours) ?>,
                backgroundColor: '#1B5E20',
                borderRadius: 6,
                barThickness: 40
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' Hours';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Hours',
                        color: textColor
                    },
                    grid: { color: gridColor },
                    ticks: { color: textColor }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: textColor }
                }
            }
        }
    });
</script>

</body>
</html>

