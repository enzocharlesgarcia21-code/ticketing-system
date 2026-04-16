<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

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

function formatHandlingTimeDetailed($seconds) {
    $seconds = (int) $seconds;
    if ($seconds <= 0) return '0m';
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0 && $minutes > 0) return "{$hours}h {$minutes}m";
    if ($hours > 0) return "{$hours}h";
    return "{$minutes}m";
}

function initials_from_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    if (!$parts) return 'NA';
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $letters .= strtoupper(substr($part, 0, 1));
        if (strlen($letters) >= 2) break;
    }
    return $letters !== '' ? $letters : 'NA';
}

// Determine selected date range (default to current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$category_filter = trim((string) ($_GET['category'] ?? ''));
$company_filter = ticket_normalize_company(trim((string) ($_GET['company'] ?? '')));
$department_filter = trim((string) ($_GET['department'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));

$allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
if (!in_array($status_filter, $allowed_statuses, true)) $status_filter = '';

$company_options = [
    '@leadstech-corp.com',
    '@gpsci.net',
    '@leadsagri.com',
    '@leads-farmex.com',
    '@malvedaholdings.com',
    '@malvedaproperties.com',
    '@leadsanimalhealth.com',
    '@leads-eh.com',
    '@leadsav.com',
    '@lingapleads.org',
    '@farmasee.ph',
    '@primestocks.ph',
];
if ($company_filter !== '' && !in_array($company_filter, $company_options, true)) $company_filter = '';

$department_options = ticket_lapc_departments();
if ($company_filter !== '@leadsagri.com') {
    $department_filter = '';
} elseif ($department_filter !== '' && !in_array($department_filter, $department_options, true)) {
    $department_filter = '';
}

$categories = [
    'Documentation',
    'Email',
    'Hardware',
    'Internet Concerns',
    'Procurement',
    'Software',
    'Technical Support',
];
if ($category_filter !== '' && !in_array($category_filter, $categories, true)) $category_filter = '';

$ticket_where = ["DATE(t.created_at) BETWEEN ? AND ?"];
$ticket_params = [$start_date, $end_date];
$ticket_types = "ss";
if ($category_filter !== '') {
    $ticket_where[] = "t.category = ?";
    $ticket_params[] = $category_filter;
    $ticket_types .= "s";
}
if ($company_filter !== '') {
    $ticket_where[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $ticket_params[] = $company_filter;
    $ticket_types .= "s";
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

// 3. Daily average handling time for the last 7 days ending at the selected end date.
$trendEndDate = new DateTimeImmutable($end_date ?: date('Y-m-d'));
$trendStartDate = $trendEndDate->modify('-6 days');
$trendQueryStart = $trendStartDate->format('Y-m-d');
$trendQueryEnd = $trendEndDate->format('Y-m-d');
$trendDailyMap = [];
$trendAverageSeconds = 0;
$resolutionSecondsExpr = "TIMESTAMPDIFF(SECOND, t.started_at, t.resolved_at)";

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
    SELECT AVG($resolutionSecondsExpr) as avg_seconds
    FROM employee_tickets t
    WHERE t.status = 'Resolved'
    AND t.started_at IS NOT NULL
    AND t.resolved_at IS NOT NULL
    AND $resolutionSecondsExpr >= 0
    AND DATE(t.resolved_at) BETWEEN ? AND ?
");
if ($avgSecondsStmt) {
    $avgSecondsStmt->bind_param("ss", $start_date, $end_date);
    $avgSecondsStmt->execute();
    $avgRow = $avgSecondsStmt->get_result()->fetch_assoc();
    $summary['avg_seconds'] = (int) round((float) ($avgRow['avg_seconds'] ?? 0));
    $avgSecondsStmt->close();
}

$trend_where = [
    "t.status = 'Resolved'",
    "t.started_at IS NOT NULL",
    "t.resolved_at IS NOT NULL",
    "$resolutionSecondsExpr >= 0",
    "DATE(t.resolved_at) BETWEEN ? AND ?",
];
$trend_params = [$trendQueryStart, $trendQueryEnd];
$trend_types = "ss";
if ($category_filter !== '') {
    $trend_where[] = "t.category = ?";
    $trend_params[] = $category_filter;
    $trend_types .= "s";
}
if ($company_filter !== '') {
    $trend_where[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $trend_params[] = $company_filter;
    $trend_types .= "s";
}
if ($department_filter !== '') {
    $trend_where[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $trend_params[] = $department_filter;
    $trend_types .= "s";
}
$trendSql = "
    SELECT
        DATE(t.resolved_at) as resolved_day,
        AVG($resolutionSecondsExpr) as avg_seconds,
        COUNT(*) as ticket_count
    FROM employee_tickets t
    WHERE " . implode(" AND ", $trend_where) . "
    GROUP BY DATE(t.resolved_at)
    ORDER BY DATE(t.resolved_at) ASC
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
    $trendSecondsTotal = 0.0;
    $trendTicketCount = 0;
    while ($r = $tRes->fetch_assoc()) {
        $dayKey = (string) ($r['resolved_day'] ?? '');
        $avgSeconds = (float) ($r['avg_seconds'] ?? 0);
        $ticketCount = (int) ($r['ticket_count'] ?? 0);
        if ($dayKey !== '') {
            $trendDailyMap[$dayKey] = $avgSeconds;
            if ($avgSeconds >= 0 && $ticketCount > 0) {
                $trendSecondsTotal += ($avgSeconds * $ticketCount);
                $trendTicketCount += $ticketCount;
            }
        }
    }
    $trendAverageSeconds = $trendTicketCount > 0 ? (int) round($trendSecondsTotal / $trendTicketCount) : 0;
    $tStmt->close();
}

$trendWeeks = [];
$trendAvgHours = [];
for ($i = 0; $i < 7; $i++) {
    $currentDay = $trendStartDate->modify('+' . $i . ' days');
    $dayKey = $currentDay->format('Y-m-d');
    $trendWeeks[] = $currentDay->format('D');
    $trendAvgHours[] = array_key_exists($dayKey, $trendDailyMap)
        ? round(((float) $trendDailyMap[$dayKey]) / 3600, 1)
        : null;
}
$trendMaxHours = 6;
if (!empty(array_filter($trendAvgHours, static fn ($value) => $value !== null))) {
    $trendPeakHours = max(array_filter($trendAvgHours, static fn ($value) => $value !== null));
    if ($trendPeakHours > 0) {
        $trendMaxHours = max(6, (int) ceil($trendPeakHours / 2) * 2);
    }
}

$categoryLabels = [];
$categoryCounts = [];
$catWhere = ["DATE(t.created_at) BETWEEN ? AND ?"];
$catParams = [$start_date, $end_date];
$catTypes = "ss";
if ($company_filter !== '') {
    $catWhere[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $catParams[] = $company_filter;
    $catTypes .= "s";
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
if ($company_filter !== '') {
    $assigneeWhere[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $assigneeParams[] = $company_filter;
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
    LIMIT 5
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

$categoryPalette = ['#2f8cff', '#2fa36b', '#ff9f1c', '#ef4444', '#9b7bf4', '#21b7d8', '#88d05f', '#f97316', '#14b8a6', '#eab308'];
$categoryTotal = array_sum($categoryCounts);
$categoryLegendItems = [];
foreach ($categoryLabels as $idx => $label) {
    $count = (int) ($categoryCounts[$idx] ?? 0);
    $percent = $categoryTotal > 0 ? round(($count / $categoryTotal) * 100) : 0;
    $categoryLegendItems[] = [
        'label' => $label,
        'count' => $count,
        'percent' => $percent,
        'color' => $categoryPalette[$idx % count($categoryPalette)],
    ];
}

$assigneeAccentSets = [
    ['bg' => '#dbeafe', 'text' => '#2f80ed', 'bar' => 'linear-gradient(90deg, #2f80ed, #3b82f6)'],
    ['bg' => '#f3e8ff', 'text' => '#a855f7', 'bar' => 'linear-gradient(90deg, #a855f7, #c084fc)'],
    ['bg' => '#fee2e2', 'text' => '#fb7185', 'bar' => 'linear-gradient(90deg, #fb7185, #ff7a33)'],
];
$assigneeMax = !empty($assigneeCounts) ? max($assigneeCounts) : 0;
$assigneeCards = [];
foreach ($assigneeLabels as $idx => $name) {
    $count = (int) ($assigneeCounts[$idx] ?? 0);
    $accent = $assigneeAccentSets[$idx % count($assigneeAccentSets)];
    $assigneeCards[] = [
        'name' => $name,
        'initials' => initials_from_name((string) $name),
        'count' => $count,
        'percent' => $assigneeMax > 0 ? max(18, (int) round(($count / $assigneeMax) * 100)) : 0,
        'bg' => $accent['bg'],
        'text' => $accent['text'],
        'bar' => $accent['bar'],
    ];
}

$trendSubtitle = 'Daily average (last 7 days)';

$entries = (int) ($_GET['entries'] ?? 5);
$allowed_entries = [5, 10, 25, 50, 100];
if (!in_array($entries, $allowed_entries, true)) $entries = 5;
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
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
    <script src="../js/admin.js"></script>
    <style>
        body {
            background:
                radial-gradient(circle at 18% 18%, rgba(255, 255, 255, 0.96), rgba(246, 249, 253, 0.94) 34%, rgba(236, 242, 248, 0.92) 100%);
        }
        .admin-content {
            max-width: 1460px;
            padding-top: 10px;
        }
        .admin-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }
        .analytics-title {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-size: 2.05rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #111827;
        }
        .analytics-title i {
            color: #1B5E20;
            font-size: 1.7rem;
        }
        .analytics-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .analytics-toolbar,
        .analytics-card,
        .chart-card,
        .table-card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(225, 232, 243, 0.95);
            border-radius: 24px;
            box-shadow: 0 20px 48px rgba(148, 163, 184, 0.16);
            backdrop-filter: blur(14px);
        }
        .analytics-toolbar {
            padding: 20px 20px 18px;
            margin-bottom: 24px;
            position: relative;
            z-index: 50;
            overflow: visible;
        }
        .analytics-filterbar {
            display: block;
        }
        .analytics-filters {
            display: grid;
            grid-template-columns: 1.5fr 1.2fr 1.15fr 1.15fr 1fr;
            gap: 12px;
            align-items: end;
        }
        .analytics-filter {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 0;
            position: relative;
            z-index: 1;
        }
        .analytics-filter label {
            font-size: 12px;
            font-weight: 800;
            color: #374151;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .analytics-control,
        .analytics-status-row {
            width: 100%;
            min-height: 48px;
            padding: 0 14px;
            border: 1px solid #d9dee8;
            border-radius: 13px;
            font-size: 14px;
            outline: none;
            background: #ffffff;
            color: #111827;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .analytics-control:focus,
        .analytics-status-row:focus-within {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        .date-inputs {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            gap: 8px;
            align-items: center;
        }
        .date-inputs .analytics-control {
            padding-right: 10px;
        }
        .date-separator {
            color: #6b7280;
            font-size: 13px;
            font-weight: 700;
        }
        .analytics-status-row {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 0;
            padding: 0;
            border: 0;
            box-shadow: none;
            background: transparent;
        }
        .analytics-status-row .analytics-control {
            flex: 1 1 auto;
        }
        .assignee-dropdown {
            position: relative;
            z-index: 70;
        }
        .assignee-trigger {
            width: 100%;
            min-height: 52px;
            border-radius: 15px;
            border: 1px solid #d9dee8;
            background: #ffffff;
            color: #0f172a;
            font-size: 16px;
            font-weight: 500;
            padding: 0 44px 0 16px;
            display: flex;
            align-items: center;
            text-align: left;
            cursor: pointer;
            position: relative;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
        }
        .assignee-trigger::after {
            content: "\f078";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #1f2937;
            font-size: 13px;
            pointer-events: none;
            transition: transform 0.2s ease;
        }
        .assignee-dropdown.open .assignee-trigger::after {
            transform: translateY(-50%) rotate(180deg);
        }
        .assignee-trigger:focus-visible {
            outline: 2px solid rgba(22, 101, 52, 0.22);
            outline-offset: 2px;
        }
        .assignee-panel {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid #d9dee8;
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
            max-height: 268px;
            overflow-y: auto;
            z-index: 999;
            display: none;
            padding: 6px 0;
        }
        .assignee-dropdown.open .assignee-panel {
            display: block;
        }
        .assignee-option {
            width: 100%;
            border: 0;
            background: transparent;
            color: #374151;
            font-size: 15px;
            text-align: left;
            padding: 10px 14px;
            cursor: pointer;
            display: block;
        }
        .assignee-option:hover,
        .assignee-option:focus-visible,
        .assignee-option.is-selected {
            background: #767676;
            color: #ffffff;
            outline: none;
        }
        .analytics-status-row .analytics-inline-clear {
            min-height: 48px;
            padding: 0 16px;
            border: 1px solid #d9dee8;
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            flex: 1 1 auto;
        }
        .analytics-inline-clear {
            color: #111827;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 999px;
            transition: background 0.2s ease, color 0.2s ease;
            white-space: nowrap;
        }
        .analytics-inline-clear:hover {
            background: #f3f4f6;
            color: #1B5E20;
        }
        .btn-apply,
        .btn-export {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
            white-space: nowrap;
        }
        .btn-apply:hover,
        .btn-export:hover {
            transform: translateY(-1px);
        }
        .btn-export {
            background: #ffffff;
            border: 1px solid #c9d4c5;
            color: #1B5E20;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
        }
        .btn-export i {
            font-size: 1rem;
        }
        .btn-export-pdf,
        .btn-export-excel {
            min-width: 106px;
        }
        .btn-export-pdf:hover,
        .btn-export-excel:hover {
            background: #f2fbf1;
            border-color: #99c08d;
        }

        .analytics-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .analytics-card {
            padding: 20px 20px 18px;
            min-width: 0;
        }
        .analytics-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .analytics-label {
            font-size: 14px;
            font-weight: 500;
            color: #111827;
            letter-spacing: 0;
            text-transform: none;
            margin-bottom: 4px;
        }
        .analytics-value {
            font-size: 2.25rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
            letter-spacing: -0.04em;
        }
        .analytics-sub {
            margin-top: 10px;
            font-size: 0.98rem;
            color: #4b5563;
            font-weight: 500;
        }
        .analytics-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #eaf8ef 0%, #dff1e8 100%);
            border: 1px solid #c7e7d0;
            color: #26a14a;
            flex: 0 0 auto;
            font-size: 19px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
        }

        .analytics-charts {
            display: grid;
            grid-template-columns: 1fr 1.12fr 0.92fr;
            gap: 22px;
            margin-bottom: 24px;
            align-items: stretch;
        }
        .chart-card {
            padding: 26px 28px 24px;
            min-width: 0;
            min-height: 580px;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .chart-header {
            margin-bottom: 18px;
            min-height: 52px;
        }
        .chart-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #111827;
            margin: 0 0 4px;
        }
        .chart-subtitle {
            margin: 0;
            color: #8a94a6;
            font-size: 14px;
            font-weight: 600;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .chart-card.category-card .chart-container {
            height: 360px;
            flex: 0 0 360px;
        }
        .chart-card.trend-card .chart-container {
            height: 252px;
            margin-top: 2px;
            flex: 0 0 252px;
        }
        .insight-pill {
            margin-top: auto;
            min-height: 56px;
            border-radius: 14px;
            border: 1px solid #dce9f7;
            background: linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 0 18px;
            color: #1f2937;
            font-weight: 700;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.65);
        }
        .insight-pill i {
            color: #2f8cff;
            font-size: 18px;
        }
        .insight-pill-label {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #334155;
            font-size: 15px;
            font-weight: 700;
        }
        .insight-pill-value {
            color: #2f8cff;
            font-size: 17px;
            font-weight: 800;
        }
        .category-legend-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 18px;
            margin-top: auto;
            padding-top: 16px;
        }
        .category-legend-item {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .category-legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 4px;
            flex: 0 0 14px;
        }
        .category-legend-text {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
            min-width: 0;
            font-size: 14px;
            color: #1f2937;
        }
        .category-legend-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
        }
        .category-legend-percent {
            color: #111827;
            font-weight: 800;
            flex: 0 0 auto;
        }
        .assignee-list {
            display: flex;
            flex-direction: column;
            gap: 22px;
            margin-top: 4px;
            flex: 1 1 auto;
        }
        .assignee-item {
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr) auto;
            gap: 16px;
            align-items: center;
        }
        .assignee-avatar {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 800;
        }
        .assignee-main {
            min-width: 0;
        }
        .assignee-name {
            margin: 0 0 10px;
            color: #1f2937;
            font-size: 15px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .assignee-bar-track {
            width: 100%;
            height: 12px;
            border-radius: 999px;
            background: #eef2f7;
            overflow: hidden;
        }
        .assignee-bar-fill {
            height: 100%;
            border-radius: inherit;
        }
        .assignee-count {
            color: #334155;
            font-size: 15px;
            font-weight: 800;
            min-width: 28px;
            text-align: right;
        }
        .assignee-empty {
            min-height: 220px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #8a94a6;
            font-weight: 700;
        }
        .assignee-total-pill {
            margin-top: auto;
            min-height: 56px;
            border-radius: 16px;
            background: linear-gradient(180deg, #f0fff7 0%, #e9fbf2 100%);
            border: 1px solid #cfeedd;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 18px;
            color: #0f5132;
            font-size: 15px;
            font-weight: 800;
        }
        .assignee-total-pill i {
            color: #22c55e;
            font-size: 14px;
        }
        .assignee-total-pill strong {
            font-size: 16px;
        }

        .table-card {
            padding: 10px 14px 14px;
        }
        .tickets-table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
        }
        .tickets-table th,
        .tickets-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e8edf3;
            text-align: left;
            vertical-align: middle;
            font-size: 14px;
            color: #1f2937;
        }
        .tickets-table th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #1B5E20;
            background: transparent;
            font-weight: 800;
        }
        .tickets-table tbody tr:hover td {
            background: #f9fbfc;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 78px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .status-open {
            background: #fff2b3;
            border-color: #f8e58c;
            color: #5f5400;
        }
        .status-in-progress {
            background: #dcfce7;
            border-color: #bbf7d0;
            color: #166534;
        }
        .status-resolved {
            background: #dbeafe;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .status-closed {
            background: #ffedd5;
            border-color: #fed7aa;
            color: #9a3412;
        }
        .pagination-row {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
        }
        .entries-row {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #334155;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .entries-select {
            min-width: 120px;
            height: 50px;
            border-radius: 14px;
            border: 1px solid #d8e2ec;
            background: #ffffff;
            color: #334155;
            font-size: 14px;
            font-weight: 700;
            padding: 0 44px 0 18px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            outline: none;
        }
        .pagination-info {
            color: #6b7280;
            font-weight: 700;
            font-size: 13px;
            white-space: nowrap;
            text-align: center;
            justify-self: center;
        }
        .pagination-row > .pagination-info:first-of-type {
            display: none;
        }
        .pagination-controls {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: flex-end;
            justify-self: end;
            flex-wrap: wrap;
        }
        .pagination-pages {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .page-btn {
            min-width: 40px;
            height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid #d8e2ec;
            background: #ffffff;
            color: #334155;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            transition: all 0.2s ease;
        }
        .page-btn.prev,
        .page-btn.next {
            min-width: 78px;
            padding: 0 16px;
        }
        .page-btn:hover:not(.active):not(.disabled) {
            background: #f8fafc;
            border-color: #cfd9e3;
            transform: translateY(-1px);
        }
        .page-btn.active {
            background: #166534;
            color: #ffffff;
            border-color: #166534;
            box-shadow: 0 10px 24px rgba(22, 101, 52, 0.26);
        }
        .page-btn.disabled {
            opacity: 0.45;
            pointer-events: none;
            box-shadow: none;
            background: #ffffff;
            border-color: #d8e2ec;
        }
        .pagination-ellipsis {
            min-width: 24px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0.08em;
        }

        @media (max-width: 1280px) {
            .analytics-filters {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .analytics-charts {
                grid-template-columns: 1fr;
            }
            .analytics-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 900px) {
            .admin-page-header {
                align-items: flex-start;
                flex-direction: column;
            }
            .analytics-header-actions {
                justify-content: flex-start;
            }
            .analytics-filters {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 640px) {
            .admin-content {
                padding-top: 0;
            }
            .analytics-title {
                font-size: 1.7rem;
            }
            .analytics-filters {
                grid-template-columns: 1fr;
            }
            .date-inputs {
                grid-template-columns: 1fr;
            }
            .date-separator {
                display: none;
            }
            .analytics-header-actions {
                width: 100%;
                justify-content: flex-start;
            }
            .analytics-metrics {
                grid-template-columns: 1fr;
            }
            .chart-container,
            .chart-card.category-card .chart-container,
            .chart-card.trend-card .chart-container {
                height: 250px;
            }
            .chart-card {
                min-height: auto;
            }
            .chart-header {
                min-height: 0;
            }
            .category-legend-grid {
                grid-template-columns: 1fr;
            }
            .tickets-table th,
            .tickets-table td {
                font-size: 13px;
                padding: 13px 10px;
            }
            .pagination-row {
                grid-template-columns: 1fr;
                justify-items: center;
            }
            .entries-row {
                justify-content: center;
            }
            .entries-select {
                min-width: 110px;
                height: 44px;
            }
            .pagination-info {
                text-align: center;
            }
            .pagination-controls {
                justify-content: center;
                justify-self: center;
                gap: 8px;
            }
            .pagination-pages {
                gap: 8px;
                justify-content: center;
            }
            .page-btn {
                min-width: 38px;
                height: 38px;
                padding: 0 13px;
                font-size: 13px;
            }
            .page-btn.prev,
            .page-btn.next {
                min-width: 74px;
                padding: 0 14px;
            }
            .pagination-ellipsis {
                min-width: 18px;
                height: 38px;
                font-size: 16px;
            }
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
                <div class="analytics-header-actions">
                    <a href="export_analytics_pdf.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&category=<?= urlencode($category_filter) ?>&company=<?= urlencode($company_filter) ?>&department=<?= urlencode($department_filter) ?>&status=<?= urlencode($status_filter) ?>" class="btn-export btn-export-pdf" target="_blank">
                        <i class="fa-regular fa-file-pdf"></i> PDF
                    </a>
                    <a href="export_analytics_excel.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&category=<?= urlencode($category_filter) ?>&company=<?= urlencode($company_filter) ?>&department=<?= urlencode($department_filter) ?>&status=<?= urlencode($status_filter) ?>" class="btn-export btn-export-excel" target="_blank">
                        <i class="fa-regular fa-file-excel"></i> Excel
                    </a>
                </div>
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
                                <option value="" <?= $category_filter === '' ? 'selected' : '' ?> disabled hidden>Select </option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= $category_filter === $c ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="analytics-filter">
                            <label>Company</label>
                            <select class="analytics-control" name="company" id="analyticsCompanyFilter">
                                <option value="" <?= $company_filter === '' ? 'selected' : '' ?>>All Company</option>
                                <?php foreach ($company_options as $companyOption): ?>
                                    <option value="<?= htmlspecialchars($companyOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $company_filter === $companyOption ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ticket_company_display_name($companyOption) . ' (' . $companyOption . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="analytics-filter">
                            <label>Department</label>
                            <select class="analytics-control" name="department" id="analyticsDepartmentFilter" <?= $company_filter !== '@leadsagri.com' ? 'disabled' : '' ?>>
                                <option value="" <?= $department_filter === '' ? 'selected' : '' ?>>All Department</option>
                                <?php foreach ($department_options as $d): ?>
                                    <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $department_filter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="analytics-filter">
                            <label>Status</label>
                            <div class="analytics-status-row">
                                <select class="analytics-control" name="status">
                                    <option value="" <?= $status_filter === '' ? 'selected' : '' ?> disabled hidden>Select</option>
                                    <?php foreach ($allowed_statuses as $st): ?>
                                        <option value="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="analytics.php" class="analytics-inline-clear">Clear</a>
                            </div>
                        </div>
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
                </div>
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Resolved</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['resolved'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                </div>
                <div class="analytics-card">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Closed</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['closed'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-lock"></i></div>
                    </div>
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
            </div>

            <div class="analytics-charts">
                <div class="chart-card category-card">
                    <div class="chart-header">
                        <div class="chart-title">Tickets per Category</div>
                        <p class="chart-subtitle">Distribution by ticket type</p>
                    </div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                    <?php if (count($categoryLegendItems) > 0): ?>
                        <div class="category-legend-grid">
                            <?php foreach ($categoryLegendItems as $item): ?>
                                <div class="category-legend-item">
                                    <span class="category-legend-dot" style="background: <?= htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                    <div class="category-legend-text">
                                        <span class="category-legend-name"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="category-legend-percent"><?= (int) $item['percent'] ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="chart-card trend-card">
                    <div class="chart-header">
                        <div class="chart-title">Resolution Time Trend</div>
                        <p class="chart-subtitle"><?= htmlspecialchars($trendSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                    <div class="insight-pill">
                        <div class="insight-pill-label">
                            <i class="fa-regular fa-clock"></i>
                            <span>Average Resolution Time</span>
                        </div>
                        <span class="insight-pill-value"><?= htmlspecialchars(formatHandlingTimeDetailed((int) $trendAverageSeconds), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="chart-card assignee-card">
                    <div class="chart-header">
                        <div class="chart-title">Tickets per Assignee</div>
                        <p class="chart-subtitle">Top 5 assignees by selected tickets</p>
                    </div>
                    <?php if (count($assigneeCards) > 0): ?>
                        <div class="assignee-list">
                            <?php foreach ($assigneeCards as $person): ?>
                                <div class="assignee-item">
                                    <div class="assignee-avatar" style="background: <?= htmlspecialchars($person['bg'], ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($person['text'], ENT_QUOTES, 'UTF-8') ?>;">
                                        <?= htmlspecialchars($person['initials'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="assignee-main">
                                        <div class="assignee-name"><?= htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="assignee-bar-track">
                                            <div class="assignee-bar-fill" style="width: <?= (int) $person['percent'] ?>%; background: <?= htmlspecialchars($person['bar'], ENT_QUOTES, 'UTF-8') ?>;"></div>
                                        </div>
                                    </div>
                                    <div class="assignee-count"><?= number_format((int) $person['count']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="assignee-empty">No assignee data for the selected filters.</div>
                    <?php endif; ?>
                    <div class="assignee-total-pill">
                        <i class="fa-solid fa-circle"></i>
                        <span>Total Open Tickets: <strong><?= number_format((int) ($summary['open'] ?? 0)) ?></strong></span>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <?php
                    $startNum = $tickets_total > 0 ? ($offset + 1) : 0;
                    $endNum = min($tickets_total, $offset + $entries);
                ?>

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

                <div class="pagination-row">
                    <div class="pagination-info">
                        Showing <?= (int) $startNum ?> – <?= (int) $endNum ?> of <?= (int) $tickets_total ?> tickets
                    </div>
                    <form method="GET" action="analytics.php" class="entries-row">
                            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="category" value="<?= htmlspecialchars($category_filter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="company" value="<?= htmlspecialchars($company_filter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="department" value="<?= htmlspecialchars($department_filter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="page" value="1">
                            <span>Show</span>
                            <select name="entries" class="entries-select" onchange="this.form.submit()">
                                <?php foreach ([10, 25, 50, 100] as $entriesOption): ?>
                                    <option value="<?= (int) $entriesOption ?>" <?= (int) $entries === (int) $entriesOption ? 'selected' : '' ?>><?= (int) $entriesOption ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span>Entries</span>
                    </form>
                    <div class="pagination-info">
                        Showing <?= (int) $startNum ?> - <?= (int) $endNum ?> of <?= (int) $tickets_total ?> tickets
                    </div>
                    <?php if ($tickets_total_pages > 1): ?>
                        <div class="pagination-controls">
                            <?php
                                $qsBase = [
                                    'start_date' => $start_date,
                                    'end_date' => $end_date,
                                    'category' => $category_filter,
                                    'company' => $company_filter,
                                    'department' => $department_filter,
                                    'status' => $status_filter,
                                    'entries' => $entries,
                                ];
                                $prevPage = max(1, $page - 1);
                                $nextPage = min($tickets_total_pages, $page + 1);
                                $paginationPages = [];
                                if ($tickets_total_pages <= 5) {
                                    for ($i = 1; $i <= $tickets_total_pages; $i++) {
                                        $paginationPages[] = $i;
                                    }
                                } else {
                                    $paginationPages = [1];
                                    $windowStart = max(2, $page - 1);
                                    $windowEnd = min($tickets_total_pages - 1, $page + 1);

                                    if ($page <= 3) {
                                        $windowStart = 2;
                                        $windowEnd = 3;
                                    } elseif ($page >= $tickets_total_pages - 2) {
                                        $windowStart = max(2, $tickets_total_pages - 2);
                                        $windowEnd = $tickets_total_pages - 1;
                                    }

                                    if ($windowStart > 2) {
                                        $paginationPages[] = 'ellipsis';
                                    }

                                    for ($i = $windowStart; $i <= $windowEnd; $i++) {
                                        $paginationPages[] = $i;
                                    }

                                    if ($windowEnd < $tickets_total_pages - 1) {
                                        $paginationPages[] = 'ellipsis';
                                    }

                                    $paginationPages[] = $tickets_total_pages;
                                }
                            ?>
                            <a class="page-btn prev <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qsBase, ['page' => $prevPage])) ?>">&lsaquo; Previous</a>
                            <div class="pagination-pages">
                                <?php foreach ($paginationPages as $paginationItem): ?>
                                    <?php if ($paginationItem === 'ellipsis'): ?>
                                        <span class="pagination-ellipsis">&hellip;</span>
                                    <?php else: ?>
                                        <a class="page-btn <?= (int) $paginationItem === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($qsBase, ['page' => (int) $paginationItem])) ?>"><?= (int) $paginationItem ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <a class="page-btn next <?= $page >= $tickets_total_pages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($qsBase, ['page' => $nextPage])) ?>">Next &rsaquo;</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
        if (typeof Chart.register === 'function') {
            Chart.register(ChartDataLabels);
        } else if (Chart.plugins && typeof Chart.plugins.register === 'function') {
            Chart.plugins.register(ChartDataLabels);
        }
    }

    (function () {
        var filterForm = document.querySelector('.analytics-filterbar');
        if (!filterForm) return;

        var companyFilter = document.getElementById('analyticsCompanyFilter');
        var departmentFilter = document.getElementById('analyticsDepartmentFilter');

        function syncDepartmentAvailability() {
            if (!departmentFilter) return;
            var isLapc = companyFilter && companyFilter.value === '@leadsagri.com';
            departmentFilter.disabled = !isLapc;
            if (!isLapc) {
                departmentFilter.value = '';
            }
        }

        if (companyFilter) {
            companyFilter.addEventListener('change', function () {
                syncDepartmentAvailability();
                filterForm.submit();
            });
        }

        var controls = filterForm.querySelectorAll('select[name="category"], select[name="department"], select[name="status"], input[name="start_date"], input[name="end_date"]');
        controls.forEach(function (control) {
            control.addEventListener('change', function () {
                filterForm.submit();
            });
        });
        syncDepartmentAvailability();
    })();

    const textColor = '#7b8798';
    const gridColor = '#e7edf5';
    const categoryLabels = <?= json_encode($categoryLabels) ?>;
    const categoryCounts = <?= json_encode($categoryCounts) ?>;
    const categoryTotal = categoryCounts.reduce(function(sum, value) { return sum + (Number(value) || 0); }, 0);

    const catCtx = document.getElementById('categoryChart').getContext('2d');
    new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                data: categoryCounts,
                backgroundColor: <?= json_encode($categoryPalette) ?>,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var value = Number(context.raw || 0);
                            var pct = categoryTotal > 0 ? Math.round((value / categoryTotal) * 100) : 0;
                            return context.label + ': ' + value + ' (' + pct + '%)';
                        }
                    }
                },
                datalabels: {
                    color: '#ffffff',
                    font: { weight: '800', size: 12 },
                    formatter: function(value) {
                        if (!categoryTotal || !value) return '';
                        var pct = Math.round((Number(value) / categoryTotal) * 100);
                        return pct + '%';
                    }
                }
            },
            cutout: '63%'
        }
    });

    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendGradient = trendCtx.createLinearGradient(0, 0, 0, 286);
    trendGradient.addColorStop(0, 'rgba(47, 140, 255, 0.24)');
    trendGradient.addColorStop(1, 'rgba(47, 140, 255, 0.02)');
    const trendMaxHours = <?= json_encode($trendMaxHours) ?>;
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendWeeks) ?>,
            datasets: [{
                label: 'Avg Resolution Time (Hours)',
                data: <?= json_encode($trendAvgHours) ?>,
                borderColor: '#2f8cff',
                backgroundColor: trendGradient,
                borderWidth: 3.5,
                tension: 0.28,
                fill: true,
                spanGaps: true,
                clip: false,
                pointRadius: 5,
                pointHoverRadius: 6,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#2f8cff',
                pointBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 12,
                    right: 14,
                    left: 14,
                    bottom: 8
                }
            },
            plugins: { legend: { display: false }, datalabels: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    min: 0,
                    max: trendMaxHours,
                    grace: '6%',
                    border: { display: false },
                    grid: {
                        color: gridColor,
                        borderDash: [4, 6],
                        drawTicks: false
                    },
                    ticks: {
                        color: textColor,
                        stepSize: 2,
                        precision: 0,
                        padding: 10,
                        font: {
                            size: 13,
                            weight: 600
                        },
                        callback: function(value) {
                            var rounded = Math.round(Number(value) || 0);
                            return rounded === 0 ? '0' : rounded + 'h';
                        }
                    }
                },
                x: {
                    offset: true,
                    border: { display: false },
                    grid: { display: false },
                    ticks: {
                        color: textColor,
                        padding: 8,
                        font: {
                            size: 13,
                            weight: 600
                        }
                    }
                }
            }
        }
    });
</script>

</body>
</html>

