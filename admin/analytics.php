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

$category_filter = trim((string) ($_GET['category'] ?? ''));
$assignee_filter = (int) ($_GET['assignee'] ?? 0);
$department_filter = trim((string) ($_GET['department'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));

$allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
if (!in_array($status_filter, $allowed_statuses, true)) $status_filter = '';

$department_options = [
    'ACCOUNTING',
    'ADMIN',
    'BIDDING',
    'E-COMM',
    'HR',
    'IT',
    'LINGAP',
    'MARKETING',
    'SUPPLY CHAIN',
    'TECHNICAL',
];
if ($department_filter !== '' && !in_array($department_filter, $department_options, true)) $department_filter = '';

$categories = [];
$catRes = $conn->query("SELECT DISTINCT category FROM employee_tickets WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
if ($catRes) {
    while ($r = $catRes->fetch_assoc()) {
        $v = (string) ($r['category'] ?? '');
        if ($v !== '') $categories[] = $v;
    }
}
if ($category_filter !== '' && !in_array($category_filter, $categories, true)) $category_filter = '';

$assignees = [];
$assigneeRes = $conn->query("SELECT id, name, email FROM users WHERE role = 'employee' ORDER BY name ASC");
if ($assigneeRes) {
    while ($r = $assigneeRes->fetch_assoc()) {
        $assignees[] = [
            'id' => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['name'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
        ];
    }
}

$ticket_where = ["DATE(t.created_at) BETWEEN ? AND ?"];
$ticket_params = [$start_date, $end_date];
$ticket_types = "ss";
if ($category_filter !== '') {
    $ticket_where[] = "t.category = ?";
    $ticket_params[] = $category_filter;
    $ticket_types .= "s";
}
if ($assignee_filter > 0) {
    $ticket_where[] = "t.assigned_user_id = ?";
    $ticket_params[] = $assignee_filter;
    $ticket_types .= "i";
}
if ($department_filter !== '') {
    $ticket_where[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $ticket_params[] = $department_filter;
    $ticket_types .= "s";
}
if ($status_filter !== '') {
    $ticket_where[] = "t.status = ?";
    $ticket_params[] = $status_filter;
    $ticket_types .= "s";
}

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

$summary = [
    'received' => 0,
    'resolved' => 0,
    'closed' => 0,
    'open' => 0,
    'avg_seconds' => 0,
];
$metricsSql = "
    SELECT
        COUNT(*) as received,
        SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) as closed,
        SUM(CASE WHEN t.status IN ('Open','In Progress') THEN 1 ELSE 0 END) as open_tickets
    FROM employee_tickets t
    WHERE " . implode(" AND ", $ticket_where) . "
";
$mStmt = $conn->prepare($metricsSql);
if ($mStmt) {
    $bind = [];
    $bind[] = $ticket_types;
    foreach ($ticket_params as $k => $p) {
        $bind[] = &$ticket_params[$k];
    }
    call_user_func_array([$mStmt, 'bind_param'], $bind);
    $mStmt->execute();
    $mRow = $mStmt->get_result()->fetch_assoc();
    $summary['received'] = (int) ($mRow['received'] ?? 0);
    $summary['resolved'] = (int) ($mRow['resolved'] ?? 0);
    $summary['closed'] = (int) ($mRow['closed'] ?? 0);
    $summary['open'] = (int) ($mRow['open_tickets'] ?? 0);
    $mStmt->close();
}

$avgSecondsStmt = $conn->prepare("
    SELECT AVG(TIMESTAMPDIFF(SECOND, t.started_at, t.resolved_at)) as avg_seconds
    FROM employee_tickets t
    WHERE t.status = 'Resolved'
    AND t.started_at IS NOT NULL
    AND t.resolved_at IS NOT NULL
    AND DATE(t.resolved_at) BETWEEN ? AND ?
");
if ($avgSecondsStmt) {
    $avgSecondsStmt->bind_param("ss", $start_date, $end_date);
    $avgSecondsStmt->execute();
    $avgRow = $avgSecondsStmt->get_result()->fetch_assoc();
    $summary['avg_seconds'] = (int) round((float) ($avgRow['avg_seconds'] ?? 0));
    $avgSecondsStmt->close();
}

$trendWeeks = $weeks;
$trendAvgHours = $week_avg_hours;
if ($category_filter !== '' || $assignee_filter > 0 || $department_filter !== '') {
    $trend_where = [
        "t.status = 'Resolved'",
        "t.started_at IS NOT NULL",
        "t.resolved_at IS NOT NULL",
        "DATE(t.resolved_at) BETWEEN ? AND ?",
    ];
    $trend_params = [$start_date, $end_date];
    $trend_types = "ss";
    if ($category_filter !== '') {
        $trend_where[] = "t.category = ?";
        $trend_params[] = $category_filter;
        $trend_types .= "s";
    }
    if ($assignee_filter > 0) {
        $trend_where[] = "t.assigned_user_id = ?";
        $trend_params[] = $assignee_filter;
        $trend_types .= "i";
    }
    if ($department_filter !== '') {
        $trend_where[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
        $trend_params[] = $department_filter;
        $trend_types .= "s";
    }
    $trendSql = "
        SELECT YEARWEEK(t.resolved_at) as week, AVG(TIMESTAMPDIFF(SECOND, t.started_at, t.resolved_at)) as avg_seconds
        FROM employee_tickets t
        WHERE " . implode(" AND ", $trend_where) . "
        GROUP BY week
        ORDER BY week ASC
    ";
    $tStmt = $conn->prepare($trendSql);
    if ($tStmt) {
        $bind = [];
        $bind[] = $trend_types;
        foreach ($trend_params as $k => $p) {
            $bind[] = &$trend_params[$k];
        }
        call_user_func_array([$tStmt, 'bind_param'], $bind);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        $trendWeeks = [];
        $trendAvgHours = [];
        while ($r = $tRes->fetch_assoc()) {
            $weekNum = substr((string) ($r['week'] ?? ''), 4);
            if ($weekNum !== '') {
                $trendWeeks[] = "Week $weekNum";
                $trendAvgHours[] = round(((float) ($r['avg_seconds'] ?? 0)) / 3600, 1);
            }
        }
        $tStmt->close();
    }
}

$categoryLabels = [];
$categoryCounts = [];
$catWhere = ["DATE(t.created_at) BETWEEN ? AND ?"];
$catParams = [$start_date, $end_date];
$catTypes = "ss";
if ($assignee_filter > 0) {
    $catWhere[] = "t.assigned_user_id = ?";
    $catParams[] = $assignee_filter;
    $catTypes .= "i";
}
if ($department_filter !== '') {
    $catWhere[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $catParams[] = $department_filter;
    $catTypes .= "s";
}
if ($status_filter !== '') {
    $catWhere[] = "t.status = ?";
    $catParams[] = $status_filter;
    $catTypes .= "s";
}
$catSql2 = "SELECT t.category, COUNT(*) as total FROM employee_tickets t WHERE " . implode(" AND ", $catWhere) . " GROUP BY t.category ORDER BY total DESC";
$catStmt2 = $conn->prepare($catSql2);
if ($catStmt2) {
    $bind = [];
    $bind[] = $catTypes;
    foreach ($catParams as $k => $p) {
        $bind[] = &$catParams[$k];
    }
    call_user_func_array([$catStmt2, 'bind_param'], $bind);
    $catStmt2->execute();
    $catRes2 = $catStmt2->get_result();
    while ($r = $catRes2->fetch_assoc()) {
        $label = (string) ($r['category'] ?? '');
        if ($label === '') $label = 'Uncategorized';
        $categoryLabels[] = $label;
        $categoryCounts[] = (int) ($r['total'] ?? 0);
    }
    $catStmt2->close();
}

$assigneeLabels = [];
$assigneeCounts = [];
$assigneeWhere = ["DATE(t.created_at) BETWEEN ? AND ?"];
$assigneeParams = [$start_date, $end_date];
$assigneeTypes = "ss";
$assigneeWhere[] = "t.assigned_user_id IS NOT NULL";
if ($category_filter !== '') {
    $assigneeWhere[] = "t.category = ?";
    $assigneeParams[] = $category_filter;
    $assigneeTypes .= "s";
}
if ($department_filter !== '') {
    $assigneeWhere[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $assigneeParams[] = $department_filter;
    $assigneeTypes .= "s";
}
if ($status_filter !== '') {
    $assigneeWhere[] = "t.status = ?";
    $assigneeParams[] = $status_filter;
    $assigneeTypes .= "s";
}
$assigneeSql = "
    SELECT TRIM(a.name) as assignee_name, COUNT(*) as total
    FROM employee_tickets t
    JOIN users a ON t.assigned_user_id = a.id
    WHERE " . implode(" AND ", $assigneeWhere) . "
    GROUP BY TRIM(a.name)
    ORDER BY total DESC
    LIMIT 3
";
$asStmt = $conn->prepare($assigneeSql);
if ($asStmt) {
    $bind = [];
    $bind[] = $assigneeTypes;
    foreach ($assigneeParams as $k => $p) {
        $bind[] = &$assigneeParams[$k];
    }
    call_user_func_array([$asStmt, 'bind_param'], $bind);
    $asStmt->execute();
    $asRes = $asStmt->get_result();
    while ($r = $asRes->fetch_assoc()) {
        $assigneeLabels[] = (string) ($r['assignee_name'] ?? '');
        $assigneeCounts[] = (int) ($r['total'] ?? 0);
    }
    $asStmt->close();
}

$entries = (int) ($_GET['entries'] ?? 10);
$allowed_entries = [5, 10, 25, 50, 100];
if (!in_array($entries, $allowed_entries, true)) $entries = 10;
$page = (int) ($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $entries;

$tickets_total = 0;
$countSql = "SELECT COUNT(*) as total FROM employee_tickets t JOIN users u ON t.user_id = u.id WHERE " . implode(" AND ", $ticket_where);
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $bind = [];
    $bind[] = $ticket_types;
    foreach ($ticket_params as $k => $p) {
        $bind[] = &$ticket_params[$k];
    }
    call_user_func_array([$countStmt, 'bind_param'], $bind);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $tickets_total = (int) ($countRow['total'] ?? 0);
    $countStmt->close();
}
$tickets_total_pages = $entries > 0 ? (int) ceil($tickets_total / $entries) : 1;
if ($tickets_total_pages < 1) $tickets_total_pages = 1;
if ($page > $tickets_total_pages) $page = $tickets_total_pages;
$offset = ($page - 1) * $entries;

$tickets = [];
$ticketsSql = "
    SELECT
        t.id,
        u.name as client_name,
        t.subject,
        t.category,
        COALESCE(a.name, 'Unassigned') as assignee_name,
        t.started_at,
        t.resolved_at,
        t.status,
        TIMESTAMPDIFF(SECOND, t.started_at, t.resolved_at) as duration_seconds
    FROM employee_tickets t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN users a ON t.assigned_user_id = a.id
    WHERE " . implode(" AND ", $ticket_where) . "
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
";
$ticketsStmt = $conn->prepare($ticketsSql);
if ($ticketsStmt) {
    $params2 = $ticket_params;
    $types2 = $ticket_types . "ii";
    $params2[] = $entries;
    $params2[] = $offset;
    $bind = [];
    $bind[] = $types2;
    foreach ($params2 as $k => $p) {
        $bind[] = &$params2[$k];
    }
    call_user_func_array([$ticketsStmt, 'bind_param'], $bind);
    $ticketsStmt->execute();
    $tRes = $ticketsStmt->get_result();
    while ($r = $tRes->fetch_assoc()) {
        $tickets[] = $r;
    }
    $ticketsStmt->close();
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../js/admin.js"></script>
    <style>
        .admin-content { max-width: 1600px; }
        .analytics-title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .analytics-title i { color: #1B5E20; }

        .analytics-toolbar {
            background: white;
            padding: 16px;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            border: 1px solid #eef2f7;
            margin-bottom: 24px;
        }
        .analytics-filterbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .analytics-filters {
            display: grid;
            grid-template-columns: 1.25fr 1fr 1fr 1fr 1fr;
            gap: 12px;
            align-items: center;
            flex: 1 1 auto;
            min-width: 520px;
        }
        .analytics-filter {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        .analytics-filter label {
            font-size: 12px;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .analytics-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            background: #ffffff;
        }
        .analytics-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        .date-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 8px;
            align-items: center;
        }
        .date-separator { color: #94a3b8; font-weight: 700; }
        .analytics-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
            flex: 0 0 auto;
        }
        .btn-apply {
            padding: 10px 16px;
            background-color: #1B5E20;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 8px 18px rgba(27, 94, 32, 0.12);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .btn-apply:hover { background-color: #144a1e; transform: translateY(-1px); }
        .btn-export {
            display: inline-flex;
            align-items: center;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
            gap: 8px;
        }
        .btn-export:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(2,6,23,0.08); }
        .btn-export-pdf { color: #991b1b; border-color: #fecaca; background: #fef2f2; }
        .btn-export-pdf:hover { background: #fee2e2; }
        .btn-export-excel { color: #065f46; border-color: #a7f3d0; background: #ecfdf5; }
        .btn-export-excel:hover { background: #d1fae5; }
        .btn-clear { color: #334155; border-color: #e2e8f0; background: #f8fafc; }
        .btn-clear:hover { background: #eef2f7; }

        .analytics-metrics {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .analytics-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            border: 1px solid #eef2f7;
            padding: 18px 18px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }
        .analytics-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .analytics-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            flex: 0 0 auto;
            font-size: 18px;
        }
        .analytics-label {
            font-size: 12px;
            font-weight: 900;
            color: #64748b;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .analytics-value {
            font-size: 28px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1.05;
        }
        .analytics-sub {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
        }

        .analytics-charts {
            display: grid;
            grid-template-columns: 2fr 2fr 1.6fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .chart-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            border: 1px solid #eef2f7;
            padding: 16px;
            overflow: hidden;
            min-width: 0;
        }
        .chart-title {
            font-size: 14px;
            font-weight: 900;
            color: #0f172a;
            margin: 0 0 12px;
        }
        .chart-container { position: relative; height: 320px; width: 100%; }
        .assignee-chart-container { height: 260px; }

        .table-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            border: 1px solid #eef2f7;
            padding: 16px;
        }
        .table-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .entries-control {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: #64748b;
            font-weight: 800;
            font-size: 13px;
        }
        .entries-control select {
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            font-weight: 800;
        }
        .tickets-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
        }
        .tickets-table th, .tickets-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: middle;
            font-size: 13px;
        }
        .tickets-table th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #1B5E20;
            background: #ffffff;
            font-weight: 900;
        }
        .tickets-table tbody tr:hover td { background: #f8fafc; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .status-open { background: #fef9c3; border-color: #fde68a; color: #854d0e; }
        .status-in-progress { background: #dbeafe; border-color: #bfdbfe; color: #1d4ed8; }
        .status-resolved { background: #dcfce7; border-color: #bbf7d0; color: #166534; }
        .status-closed { background: #ffedd5; border-color: #fed7aa; color: #9a3412; }

        .pagination-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-top: 14px;
            flex-wrap: wrap;
        }
        .pagination-info {
            color: #64748b;
            font-weight: 800;
            font-size: 12px;
        }
        .pagination-controls { display: flex; gap: 8px; align-items: center; justify-content: flex-end; }
        .page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #0f172a;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }
        .page-btn:hover { background: #f8fafc; }
        .page-btn.active { background: #1B5E20; color: #ffffff; border-color: #1B5E20; }
        .page-btn.disabled { opacity: 0.45; pointer-events: none; }

        @media (max-width: 1200px) {
            .analytics-filters { grid-template-columns: 1.2fr 1fr 1fr; }
            .analytics-charts { grid-template-columns: 1fr; }
            .analytics-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .analytics-filters { grid-template-columns: 1fr; min-width: 0; }
            .analytics-actions { width: 100%; justify-content: flex-start; }
            .analytics-metrics { grid-template-columns: 1fr; }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-content">
            
            <div class="admin-page-header">
                <h1 class="admin-page-title analytics-title"><i class="fa-solid fa-chart-line"></i> Analytics</h1>
            </div>

            <div class="analytics-toolbar">
                <form method="GET" action="analytics.php" class="analytics-filterbar">
                    <input type="hidden" name="entries" value="<?= (int) $entries ?>">
                    <input type="hidden" name="page" value="1">
                    <div class="analytics-filters">
                        <div class="analytics-filter">
                            <label>Date Range</label>
                            <div class="date-inputs">
                                <input class="analytics-control" type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required>
                                <span class="date-separator">to</span>
                                <input class="analytics-control" type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required>
                            </div>
                        </div>
                        <div class="analytics-filter">
                            <label>Category</label>
                            <select class="analytics-control" name="category">
                                <option value="">All</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= $category_filter === $c ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="analytics-filter">
                            <label>Assignee</label>
                            <select class="analytics-control" name="assignee">
                                <option value="0">All</option>
                                <?php foreach ($assignees as $a): ?>
                                    <option value="<?= (int) ($a['id'] ?? 0); ?>" <?= (int) $assignee_filter === (int) ($a['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) ($a['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="analytics-filter">
                            <label>Department</label>
                            <select class="analytics-control" name="department">
                                <option value="">All</option>
                                <?php foreach ($department_options as $d): ?>
                                    <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $department_filter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="analytics-filter">
                            <label>Status</label>
                            <select class="analytics-control" name="status">
                                <option value="">All</option>
                                <?php foreach ($allowed_statuses as $st): ?>
                                    <option value="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="analytics-actions">
                        <button type="submit" class="btn-apply"><i class="fa-solid fa-filter"></i> Apply Filter</button>
                        <a href="analytics.php" class="btn-export btn-clear"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                        <a href="export_analytics_pdf.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn-export btn-export-pdf" target="_blank">
                            <i class="fa-regular fa-file-pdf"></i> PDF
                        </a>
                        <a href="export_analytics_excel.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn-export btn-export-excel" target="_blank">
                            <i class="fa-regular fa-file-excel"></i> Excel
                        </a>
                    </div>
                </form>
            </div>

            <div class="analytics-metrics">
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Received</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['received'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-inbox"></i></div>
                    </div>
                    <div class="analytics-sub"><?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Resolved</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['resolved'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="analytics-sub"><?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Closed</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['closed'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-lock"></i></div>
                    </div>
                    <div class="analytics-sub"><?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Avg. Resolution Time</div>
                            <div class="analytics-value"><?= htmlspecialchars(formatHandlingTime((int) ($summary['avg_seconds'] ?? 0))) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-stopwatch"></i></div>
                    </div>
                    <div class="analytics-sub">Resolved tickets only</div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Open Tickets</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['open'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-ticket"></i></div>
                    </div>
                    <div class="analytics-sub">Open &amp; In Progress</div>
                </div>
            </div>

            <div class="analytics-charts">
                <div class="chart-card">
                    <div class="chart-title">Tickets per Category</div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">Resolution Time Trend</div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">Tickets per Assignee</div>
                    <div class="chart-container assignee-chart-container">
                        <canvas id="assigneeChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-topbar">
                    <div class="entries-control">
                        Entries
                        <form method="GET" action="analytics.php" style="display:inline-flex; align-items:center; gap:10px;">
                            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                            <input type="hidden" name="category" value="<?= htmlspecialchars($category_filter) ?>">
                            <input type="hidden" name="assignee" value="<?= (int) $assignee_filter ?>">
                            <input type="hidden" name="department" value="<?= htmlspecialchars($department_filter) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                            <input type="hidden" name="page" value="1">
                            <select name="entries" onchange="this.form.submit()">
                                <?php foreach ($allowed_entries as $e): ?>
                                    <option value="<?= (int) $e ?>" <?= (int) $entries === (int) $e ? 'selected' : '' ?>><?= (int) $e ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="pagination-info">
                        <?php
                            $startNum = $tickets_total > 0 ? ($offset + 1) : 0;
                            $endNum = min($tickets_total, $offset + $entries);
                        ?>
                        Showing <?= (int) $startNum ?> – <?= (int) $endNum ?> of <?= (int) $tickets_total ?> tickets
                    </div>
                </div>

                <div style="width:100%; overflow:auto;">
                    <table class="tickets-table">
                        <thead>
                            <tr>
                                <th>Ticket ID</th>
                                <th>Client</th>
                                <th>Reported Concern</th>
                                <th>Category</th>
                                <th>Assignee</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($tickets) > 0): ?>
                                <?php foreach ($tickets as $t): ?>
                                    <?php
                                        $status = (string) ($t['status'] ?? '');
                                        $statusSlug = strtolower(str_replace(' ', '-', $status));
                                        if (!in_array($statusSlug, ['open','in-progress','resolved','closed'], true)) $statusSlug = 'open';
                                        $startedAt = (string) ($t['started_at'] ?? '');
                                        $resolvedAt = (string) ($t['resolved_at'] ?? '');
                                        $durationSec = (int) ($t['duration_seconds'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>#<?= str_pad((string) ($t['id'] ?? ''), 6, '0', STR_PAD_LEFT) ?></td>
                                        <td><?= htmlspecialchars((string) ($t['client_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($t['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($t['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($t['assignee_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= $startedAt !== '' ? htmlspecialchars(date('M d, Y g:i A', strtotime($startedAt)), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                        <td><?= $resolvedAt !== '' ? htmlspecialchars(date('M d, Y g:i A', strtotime($resolvedAt)), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                        <td><?= ($startedAt !== '' && $resolvedAt !== '' && $durationSec > 0) ? htmlspecialchars(formatHandlingTime($durationSec), ENT_QUOTES, 'UTF-8') : '-' ?></td>
                                        <td><span class="status-badge status-<?= htmlspecialchars($statusSlug, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status !== '' ? $status : '-', ENT_QUOTES, 'UTF-8') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="color:#64748b; font-weight:800; text-align:center; padding:16px;">No tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($tickets_total_pages > 1): ?>
                    <div class="pagination-row">
                        <div class="pagination-info">Page <?= (int) $page ?> of <?= (int) $tickets_total_pages ?></div>
                        <div class="pagination-controls">
                            <?php
                                $qsBase = [
                                    'start_date' => $start_date,
                                    'end_date' => $end_date,
                                    'category' => $category_filter,
                                    'assignee' => $assignee_filter,
                                    'department' => $department_filter,
                                    'status' => $status_filter,
                                    'entries' => $entries,
                                ];
                                $prevPage = max(1, $page - 1);
                                $nextPage = min($tickets_total_pages, $page + 1);
                            ?>
                            <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qsBase, ['page' => $prevPage])) ?>">‹</a>
                            <?php
                                $startP = max(1, $page - 2);
                                $endP = min($tickets_total_pages, $startP + 4);
                                $startP = max(1, $endP - 4);
                                for ($p = $startP; $p <= $endP; $p++):
                            ?>
                                <a class="page-btn <?= $p === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($qsBase, ['page' => $p])) ?>"><?= (int) $p ?></a>
                            <?php endfor; ?>
                            <a class="page-btn <?= $page >= $tickets_total_pages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qsBase, ['page' => $nextPage])) ?>">›</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
    // Theme colors
    const isDarkMode = document.body.classList.contains('dark-mode');
    const textColor = isDarkMode ? '#cbd5e1' : '#64748b';
    const gridColor = isDarkMode ? '#334155' : '#e2e8f0';

    const catCtx = document.getElementById('categoryChart').getContext('2d');
    new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($categoryLabels) ?>,
            datasets: [{
                data: <?= json_encode($categoryCounts) ?>,
                backgroundColor: ['#16a34a','#0ea5e9','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f97316','#22c55e','#3b82f6','#eab308'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { color: textColor, boxWidth: 10, boxHeight: 10 } }
            },
            cutout: '65%'
        }
    });

    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendWeeks) ?>,
            datasets: [{
                label: 'Avg Resolution Time (Hours)',
                data: <?= json_encode($trendAvgHours) ?>,
                borderColor: '#1B5E20',
                backgroundColor: 'rgba(27, 94, 32, 0.12)',
                tension: 0.35,
                fill: true,
                pointRadius: 3,
                pointBackgroundColor: '#1B5E20'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } },
                x: { grid: { display: false }, ticks: { color: textColor } }
            }
        }
    });

    const asCtx = document.getElementById('assigneeChart').getContext('2d');
    const assigneePalette = ['#2e7d32', '#fbc02d', '#1e88e5'];
    const assigneeColors = (<?= json_encode($assigneeLabels) ?> || []).map(function (_, i) {
        return assigneePalette[i % assigneePalette.length];
    });
    const assigneeTickLabels = (<?= json_encode($assigneeLabels) ?> || []).map(function (name) {
        var n = String(name || '').trim();
        if (!n) return '';
        var parts = n.split(/\s+/);
        if (parts.length <= 1) return n;
        var first = parts[0];
        var rest = parts.slice(1).join(' ');
        if (!rest) return first;
        return [first, rest];
    });
    new Chart(asCtx, {
        type: 'bar',
        data: {
            labels: assigneeTickLabels,
            datasets: [{
                label: 'Tickets',
                data: <?= json_encode($assigneeCounts) ?>,
                backgroundColor: assigneeColors,
                borderRadius: 8,
                maxBarThickness: 46
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: textColor, maxRotation: 0, minRotation: 0, autoSkip: false, font: { size: 10 }, padding: 6 }
                },
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor } }
            }
        }
    });
</script>

</body>
</html>
