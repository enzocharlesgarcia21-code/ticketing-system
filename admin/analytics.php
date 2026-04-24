<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/user_permissions.php';

$analyticsViewMode = defined('TICKETING_ANALYTICS_VIEW_MODE') ? (string) TICKETING_ANALYTICS_VIEW_MODE : 'admin';
$analyticsIsEmployeeView = $analyticsViewMode === 'employee';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: " . ($analyticsIsEmployeeView ? "../employee/employee_login.php" : "admin_login.php"));
    exit();
}

if ($analyticsIsEmployeeView) {
    if ((string) $_SESSION['role'] !== 'employee') {
        header("Location: ../employee/employee_login.php");
        exit();
    }
    user_permissions_ensure_table($conn);
    $employeePermissions = user_permissions_get_for_user($conn, (int) ($_SESSION['user_id'] ?? 0));
    if ((int) ($employeePermissions['analytics'] ?? 0) !== 1) {
        header("Location: dashboard.php");
        exit();
    }
} elseif ((string) $_SESSION['role'] !== 'admin') {
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

function analytics_is_weekday(DateTimeImmutable $date): bool
{
    return (int) $date->format('N') <= 5;
}

function analytics_last_n_weekdays(DateTimeImmutable $endDate, int $count): array
{
    $days = [];
    $cursor = $endDate;
    while (count($days) < $count) {
        if (analytics_is_weekday($cursor)) {
            $days[] = $cursor;
        }
        $cursor = $cursor->modify('-1 day');
    }
    return array_reverse($days);
}

// Determine selected date range (default to current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$category_filter = trim((string) ($_GET['category'] ?? ''));
$company_filter = ticket_normalize_company(trim((string) ($_GET['company'] ?? '')));
$department_filter = trim((string) ($_GET['department'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));

if ($analyticsIsEmployeeView) {
    $company_filter = ticket_normalize_company(trim((string) ($_SESSION['company'] ?? '')));
    $department_filter = trim((string) ($_SESSION['department'] ?? ''));
}

$allowed_statuses = ['Open', 'In Progress', 'Resolved'];
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
if (!$analyticsIsEmployeeView && $company_filter !== '@leadsagri.com') {
    $department_filter = '';
} elseif (!$analyticsIsEmployeeView && $department_filter !== '' && !in_array($department_filter, $department_options, true)) {
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
// Received: Created in this range excluding closed tickets
// Resolved: Created in this range AND status is Resolved (Cohort analysis)

$metricsQuery = $conn->prepare("
    SELECT 
        COUNT(*) as received,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved
    FROM employee_tickets 
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND COALESCE(NULLIF(status,''),'') NOT IN ('Closed','Trash')
");
$metricsQuery->bind_param("ss", $start_date, $end_date);
$metricsQuery->execute();
$metrics = $metricsQuery->get_result()->fetch_assoc();

// 3. Resolution-time analytics for the last 7 weekdays ending at the selected end date.
$trendAnchorDate = new DateTimeImmutable($end_date ?: date('Y-m-d'));
$currentTrendDates = analytics_last_n_weekdays($trendAnchorDate, 7);
$trendStartDate = $currentTrendDates[0];
$trendEndDate = $currentTrendDates[count($currentTrendDates) - 1];
$previousTrendDates = analytics_last_n_weekdays($trendStartDate->modify('-1 day'), 7);
$previousTrendStartDate = $previousTrendDates[0];
$previousTrendEndDate = $previousTrendDates[count($previousTrendDates) - 1];
$resolutionMinutesExpr = "TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at)";
$resolutionSecondsExpr = "TIMESTAMPDIFF(SECOND, t.started_at, t.resolved_at)";

$formatResolutionMinutes = static function ($minutes): string {
    $minutes = (float) $minutes;
    if ($minutes <= 0) return '0m';
    if ($minutes >= 60) {
        $hours = $minutes / 60;
        $formatted = number_format($hours, 1, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted . 'h';
    }
    return (string) round($minutes) . 'm';
};

$summary = [
    'received' => 0,
    'resolved' => 0,
    'open' => 0,
    'in_progress' => 0,
    'avg_seconds' => 0,
];
$metricsSql = "
    SELECT
        COUNT(*) as received,
        SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) as open_tickets,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_tickets
    FROM employee_tickets t
    WHERE " . implode(" AND ", $ticket_where) . "
      AND COALESCE(NULLIF(t.status,''),'') NOT IN ('Closed','Trash')
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
    $summary['open'] = (int) ($mRow['open_tickets'] ?? 0);
    $summary['in_progress'] = (int) ($mRow['in_progress_tickets'] ?? 0);
    $mStmt->close();
}

$currentStart = $trendStartDate->format('Y-m-d');
$currentEnd = $trendEndDate->format('Y-m-d');
$previousStart = $previousTrendStartDate->format('Y-m-d');
$previousEnd = $previousTrendEndDate->format('Y-m-d');

$resolutionRangeWhere = [
    "t.status = 'Resolved'",
    "t.resolved_at IS NOT NULL",
    "t.created_at IS NOT NULL",
    "$resolutionMinutesExpr >= 0",
    "WEEKDAY(t.resolved_at) < 5",
    "DATE(t.resolved_at) BETWEEN ? AND ?",
];
$resolutionRangeParams = [$previousStart, $currentEnd];
$resolutionRangeTypes = "ss";
if ($category_filter !== '') {
    $resolutionRangeWhere[] = "t.category = ?";
    $resolutionRangeParams[] = $category_filter;
    $resolutionRangeTypes .= "s";
}
if ($company_filter !== '') {
    $resolutionRangeWhere[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $resolutionRangeParams[] = $company_filter;
    $resolutionRangeTypes .= "s";
}
if ($department_filter !== '') {
    $resolutionRangeWhere[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $resolutionRangeParams[] = $department_filter;
    $resolutionRangeTypes .= "s";
}
if ($status_filter !== '') {
    $resolutionRangeWhere[] = "t.status = ?";
    $resolutionRangeParams[] = $status_filter;
    $resolutionRangeTypes .= "s";
}

$currentAvgMinutes = 0.0;
$previousAvgMinutes = 0.0;
$currentResolvedCount = 0;
$previousResolvedCount = 0;

$resolutionSummarySql = "
    SELECT
        AVG(CASE WHEN DATE(t.resolved_at) BETWEEN ? AND ? AND WEEKDAY(t.resolved_at) < 5 THEN $resolutionMinutesExpr END) AS current_avg_minutes,
        SUM(CASE WHEN DATE(t.resolved_at) BETWEEN ? AND ? AND WEEKDAY(t.resolved_at) < 5 THEN 1 ELSE 0 END) AS current_resolved_count,
        AVG(CASE WHEN DATE(t.resolved_at) BETWEEN ? AND ? AND WEEKDAY(t.resolved_at) < 5 THEN $resolutionMinutesExpr END) AS previous_avg_minutes,
        SUM(CASE WHEN DATE(t.resolved_at) BETWEEN ? AND ? AND WEEKDAY(t.resolved_at) < 5 THEN 1 ELSE 0 END) AS previous_resolved_count
    FROM employee_tickets t
    WHERE " . implode(" AND ", $resolutionRangeWhere) . "
";
$resolutionSummaryParams = array_merge(
    [$currentStart, $currentEnd, $currentStart, $currentEnd, $previousStart, $previousEnd, $previousStart, $previousEnd],
    $resolutionRangeParams
);
$resolutionSummaryTypes = "ssssssss" . $resolutionRangeTypes;
$resolutionSummaryStmt = $conn->prepare($resolutionSummarySql);
if ($resolutionSummaryStmt) {
    $bind = [];
    $bind[] = $resolutionSummaryTypes;
    foreach ($resolutionSummaryParams as $k => $p) {
        $bind[] = &$resolutionSummaryParams[$k];
    }
    call_user_func_array([$resolutionSummaryStmt, 'bind_param'], $bind);
    $resolutionSummaryStmt->execute();
    $resolutionSummaryRow = $resolutionSummaryStmt->get_result()->fetch_assoc();
    $currentAvgMinutes = (float) ($resolutionSummaryRow['current_avg_minutes'] ?? 0);
    $previousAvgMinutes = (float) ($resolutionSummaryRow['previous_avg_minutes'] ?? 0);
    $currentResolvedCount = (int) ($resolutionSummaryRow['current_resolved_count'] ?? 0);
    $previousResolvedCount = (int) ($resolutionSummaryRow['previous_resolved_count'] ?? 0);
    $resolutionSummaryStmt->close();
}

$dailyLabels = [];
$dailyValues = [];
$dailyMinutesMap = [];
$dailyTrendWhere = [
    "t.status = 'Resolved'",
    "t.resolved_at IS NOT NULL",
    "t.created_at IS NOT NULL",
    "$resolutionMinutesExpr >= 0",
    "WEEKDAY(t.resolved_at) < 5",
    "DATE(t.resolved_at) BETWEEN ? AND ?",
];
$dailyTrendParams = [$currentStart, $currentEnd];
$dailyTrendTypes = "ss";
if ($category_filter !== '') {
    $dailyTrendWhere[] = "t.category = ?";
    $dailyTrendParams[] = $category_filter;
    $dailyTrendTypes .= "s";
}
if ($company_filter !== '') {
    $dailyTrendWhere[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $dailyTrendParams[] = $company_filter;
    $dailyTrendTypes .= "s";
}
if ($department_filter !== '') {
    $dailyTrendWhere[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $dailyTrendParams[] = $department_filter;
    $dailyTrendTypes .= "s";
}
if ($status_filter !== '') {
    $dailyTrendWhere[] = "t.status = ?";
    $dailyTrendParams[] = $status_filter;
    $dailyTrendTypes .= "s";
}

$dailyTrendSql = "
    SELECT
        DATE(t.resolved_at) AS resolved_day,
        AVG($resolutionMinutesExpr) AS avg_minutes
    FROM employee_tickets t
    WHERE " . implode(" AND ", $dailyTrendWhere) . "
    GROUP BY DATE(t.resolved_at)
    ORDER BY DATE(t.resolved_at) ASC
";
$dailyTrendStmt = $conn->prepare($dailyTrendSql);
if ($dailyTrendStmt) {
    $bind = [];
    $bind[] = $dailyTrendTypes;
    foreach ($dailyTrendParams as $k => $p) {
        $bind[] = &$dailyTrendParams[$k];
    }
    call_user_func_array([$dailyTrendStmt, 'bind_param'], $bind);
    $dailyTrendStmt->execute();
    $dailyTrendRes = $dailyTrendStmt->get_result();
    while ($dailyRow = $dailyTrendRes->fetch_assoc()) {
        $dayKey = (string) ($dailyRow['resolved_day'] ?? '');
        if ($dayKey === '') continue;
        $dailyMinutesMap[$dayKey] = round((float) ($dailyRow['avg_minutes'] ?? 0), 1);
    }
    $dailyTrendStmt->close();
}

foreach ($currentTrendDates as $day) {
    $dayKey = $day->format('Y-m-d');
    $dailyLabels[] = $day->format('M d');
    $dailyValues[] = array_key_exists($dayKey, $dailyMinutesMap) ? (float) $dailyMinutesMap[$dayKey] : null;
}

$avgDisplay = $formatResolutionMinutes($currentAvgMinutes);
$trendPercent = $previousAvgMinutes > 0
    ? (int) round((($currentAvgMinutes - $previousAvgMinutes) / $previousAvgMinutes) * 100)
    : 0;

$summary['avg_seconds'] = (int) round($currentAvgMinutes * 60);
$trendAverageSeconds = (int) round($currentAvgMinutes * 60);
$previousTrendAverageSeconds = (int) round($previousAvgMinutes * 60);
$trendDeltaPercent = $trendPercent;
$trendDeltaDirection = 'flat';
if ($trendPercent < 0) {
    $trendDeltaDirection = 'down';
} elseif ($trendPercent > 0) {
    $trendDeltaDirection = 'up';
}

$trendWeeks = $dailyLabels;
$trendAvgHours = array_map(
    static function ($minutes) {
        return $minutes === null ? null : round(((float) $minutes) / 60, 1);
    },
    $dailyValues
);
$trendMaxHours = 6;
$trendHoursOnly = array_values(array_filter($trendAvgHours, static fn ($value) => $value !== null));
if (!empty($trendHoursOnly)) {
    $trendPeakHours = max($trendHoursOnly);
    if ($trendPeakHours > 0) {
        $trendMaxHours = max(6, (int) ceil($trendPeakHours / 2) * 2);
    }
}

$trendDayStats = [];
foreach ($trendAvgHours as $idx => $hours) {
    if ($hours === null) continue;
    $trendDayStats[] = [
        'index' => $idx,
        'label' => $trendWeeks[$idx] ?? '',
        'hours' => (float) $hours,
    ];
}

$trendFastestDay = null;
$trendPeakDay = null;
if (!empty($trendDayStats)) {
    $trendFastestDay = $trendDayStats[0];
    $trendPeakDay = $trendDayStats[0];
    foreach ($trendDayStats as $dayStat) {
        if ($dayStat['hours'] < $trendFastestDay['hours']) {
            $trendFastestDay = $dayStat;
        }
        if ($dayStat['hours'] > $trendPeakDay['hours']) {
            $trendPeakDay = $dayStat;
        }
    }
}

$resolutionBucketCounts = [
    'Under 1 hour' => 0,
    '1-2 hours' => 0,
    '2-4 hours' => 0,
    'Over 4 hours' => 0,
];
$resolutionBucketWhere = [
    "t.status = 'Resolved'",
    "t.started_at IS NOT NULL",
    "t.resolved_at IS NOT NULL",
    "$resolutionSecondsExpr >= 0",
    "DATE(t.resolved_at) BETWEEN ? AND ?",
];
$resolutionBucketParams = [$start_date, $end_date];
$resolutionBucketTypes = "ss";
if ($category_filter !== '') {
    $resolutionBucketWhere[] = "t.category = ?";
    $resolutionBucketParams[] = $category_filter;
    $resolutionBucketTypes .= "s";
}
if ($company_filter !== '') {
    $resolutionBucketWhere[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $resolutionBucketParams[] = $company_filter;
    $resolutionBucketTypes .= "s";
}
if ($department_filter !== '') {
    $resolutionBucketWhere[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
    $resolutionBucketParams[] = $department_filter;
    $resolutionBucketTypes .= "s";
}
$resolutionBucketStmt = $conn->prepare("
    SELECT $resolutionSecondsExpr as duration_seconds
    FROM employee_tickets t
    WHERE " . implode(" AND ", $resolutionBucketWhere) . "
");
if ($resolutionBucketStmt) {
    $bind = [];
    $bind[] = $resolutionBucketTypes;
    foreach ($resolutionBucketParams as $k => $p) {
        $bind[] = &$resolutionBucketParams[$k];
    }
    call_user_func_array([$resolutionBucketStmt, 'bind_param'], $bind);
    $resolutionBucketStmt->execute();
    $resolutionBucketRes = $resolutionBucketStmt->get_result();
    while ($bucketRow = $resolutionBucketRes->fetch_assoc()) {
        $durationSeconds = (int) ($bucketRow['duration_seconds'] ?? 0);
        if ($durationSeconds < 3600) {
            $resolutionBucketCounts['Under 1 hour']++;
        } elseif ($durationSeconds < 7200) {
            $resolutionBucketCounts['1-2 hours']++;
        } elseif ($durationSeconds < 14400) {
            $resolutionBucketCounts['2-4 hours']++;
        } else {
            $resolutionBucketCounts['Over 4 hours']++;
        }
    }
    $resolutionBucketStmt->close();
}
$resolutionBucketTotal = array_sum($resolutionBucketCounts);
$resolutionTopBucketLabel = 'No resolved tickets in the selected range';
$resolutionTopBucketPercent = 0;
if ($resolutionBucketTotal > 0) {
    arsort($resolutionBucketCounts);
    $resolutionTopBucketLabel = (string) array_key_first($resolutionBucketCounts);
    $resolutionTopBucketPercent = (int) round((current($resolutionBucketCounts) / $resolutionBucketTotal) * 100);
}

$trendSummaryBadgeClass = $trendDeltaDirection === 'down'
    ? 'down'
    : ($trendDeltaDirection === 'up' ? 'up' : 'flat');
$trendSummaryBadgeIcon = $trendDeltaDirection === 'down'
    ? 'fa-arrow-down'
    : ($trendDeltaDirection === 'up' ? 'fa-arrow-up' : 'fa-minus');
$trendSummaryBadgeText = !empty($trendDayStats) || $trendAverageSeconds > 0
    ? ($trendDeltaPercent !== 0 ? abs($trendDeltaPercent) . '%' : '0%')
    : 'No data';

$companyExpr = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,''), '')";
$departmentExpr = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,''), '')";
$lapcCompanySql = "LOWER(TRIM($companyExpr)) IN ('@leadsagri.com', 'leadsagri.com', 'lapc', 'lapc (@leadsagri.com)', 'leads agricultural products corporation - lapc')";

$companyChartWhere = ["DATE(t.created_at) BETWEEN ? AND ?"];
$companyChartParams = [$start_date, $end_date];
$companyChartTypes = "ss";
if ($category_filter !== '') {
    $companyChartWhere[] = "t.category = ?";
    $companyChartParams[] = $category_filter;
    $companyChartTypes .= "s";
}
if ($status_filter !== '') {
    $companyChartWhere[] = "t.status = ?";
    $companyChartParams[] = $status_filter;
    $companyChartTypes .= "s";
}
if ($company_filter !== '') {
    $companyChartWhere[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
    $companyChartParams[] = $company_filter;
    $companyChartTypes .= "s";
}

$lapcDepartmentLabels = [];
$lapcDepartmentCounts = [];
$lapcWhere = $companyChartWhere;
$lapcWhere[] = $lapcCompanySql;
if ($department_filter !== '') {
    $lapcWhere[] = "$departmentExpr = ?";
    $companyChartParamsForLapc = $companyChartParams;
    $companyChartParamsForLapc[] = $department_filter;
    $lapcTypes = $companyChartTypes . "s";
} else {
    $companyChartParamsForLapc = $companyChartParams;
    $lapcTypes = $companyChartTypes;
}
$lapcDepartmentSql = "
    SELECT COALESCE(NULLIF($departmentExpr, ''), 'Unspecified') AS department_value, COUNT(*) as total
    FROM employee_tickets t
    WHERE " . implode(" AND ", $lapcWhere) . "
    GROUP BY department_value
    ORDER BY total DESC
";
$lapcDepartmentStmt = $conn->prepare($lapcDepartmentSql);
if ($lapcDepartmentStmt) {
    $bind = [];
    $bind[] = $lapcTypes;
    foreach ($companyChartParamsForLapc as $k => $p) {
        $bind[] = &$companyChartParamsForLapc[$k];
    }
    call_user_func_array([$lapcDepartmentStmt, 'bind_param'], $bind);
    $lapcDepartmentStmt->execute();
    $lapcDepartmentRes = $lapcDepartmentStmt->get_result();
    while ($r = $lapcDepartmentRes->fetch_assoc()) {
        $departmentValue = trim((string) ($r['department_value'] ?? ''));
        $lapcDepartmentLabels[] = $departmentValue !== '' ? $departmentValue : 'Unspecified';
        $lapcDepartmentCounts[] = (int) ($r['total'] ?? 0);
    }
    $lapcDepartmentStmt->close();
}

$otherCompanyLabels = [];
$otherCompanyCounts = [];
$otherCompanyWhere = $companyChartWhere;
$otherCompanyWhere[] = "NOT ($lapcCompanySql)";
$otherCompanySql = "
    SELECT $companyExpr AS company_value, COUNT(*) as total
    FROM employee_tickets t
    WHERE " . implode(" AND ", $otherCompanyWhere) . "
    GROUP BY company_value
    ORDER BY total DESC
";
$otherCompanyStmt = $conn->prepare($otherCompanySql);
if ($otherCompanyStmt) {
    $bind = [];
    $bind[] = $companyChartTypes;
    foreach ($companyChartParams as $k => $p) {
        $bind[] = &$companyChartParams[$k];
    }
    call_user_func_array([$otherCompanyStmt, 'bind_param'], $bind);
    $otherCompanyStmt->execute();
    $otherCompanyRes = $otherCompanyStmt->get_result();
    while ($r = $otherCompanyRes->fetch_assoc()) {
        $companyValue = (string) ($r['company_value'] ?? '');
        $label = ticket_company_display_name($companyValue);
        if ($label === '') $label = 'Unknown Company';
        $otherCompanyLabels[] = $label;
        $otherCompanyCounts[] = (int) ($r['total'] ?? 0);
    }
    $otherCompanyStmt->close();
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

$companyPalette = ['#2f8cff', '#2fa36b', '#ff9f1c', '#ef4444', '#9b7bf4', '#21b7d8', '#88d05f', '#f97316', '#14b8a6', '#eab308'];
$buildCompanyChartItems = static function (array $labels, array $counts) use ($companyPalette): array {
    $items = [];
    foreach ($labels as $idx => $label) {
        $items[] = [
            'label' => (string) $label,
            'count' => (int) ($counts[$idx] ?? 0),
            'color' => $companyPalette[$idx % count($companyPalette)],
        ];
    }
    return $items;
};
$lapcDepartmentItems = $buildCompanyChartItems($lapcDepartmentLabels, $lapcDepartmentCounts);
$otherCompanyItems = $buildCompanyChartItems($otherCompanyLabels, $otherCompanyCounts);

$buildCompanyChartDataset = static function (array $items, string $title, string $subtitle): array {
    return [
        'title' => $title,
        'subtitle' => $subtitle,
        'labels' => array_values(array_map(static fn ($item) => (string) $item['label'], $items)),
        'counts' => array_values(array_map(static fn ($item) => (int) $item['count'], $items)),
        'colors' => array_values(array_map(static fn ($item) => (string) $item['color'], $items)),
    ];
};
$employeeCompanyLabel = $company_filter !== '' ? ticket_company_display_name($company_filter) : 'Your Company';
$employeeDepartmentLabel = $department_filter !== '' ? $department_filter : 'Your Department';
$companyChartDatasets = [
    'lapc' => $buildCompanyChartDataset(
        $lapcDepartmentItems,
        $analyticsIsEmployeeView ? 'Department Ticket Distribution' : 'Tickets per Company',
        $analyticsIsEmployeeView ? ($employeeCompanyLabel . ' • ' . $employeeDepartmentLabel) : 'LAPC tickets by department'
    ),
    'other' => $buildCompanyChartDataset(
        $otherCompanyItems,
        $analyticsIsEmployeeView ? 'Department Ticket Distribution' : 'Tickets per Company',
        $analyticsIsEmployeeView ? 'Scoped to your assigned department' : 'Non-LAPC company distribution'
    ),
];
$companyLegendItems = $lapcDepartmentItems;
$companyChartTotal = array_sum(array_column($companyLegendItems, 'count'));
foreach ($companyLegendItems as $idx => $item) {
    $companyLegendItems[$idx]['percent'] = $companyChartTotal > 0 ? round(((int) $item['count'] / $companyChartTotal) * 100) : 0;
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

$trendSubtitle = 'Daily average (last 7 weekdays)';

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
    <?php if ($analyticsIsEmployeeView): ?>
    <link rel="stylesheet" href="../css/employee-dashboard.css?v=<?php echo time(); ?>">
    <?php endif; ?>
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
        body.employee-analytics-page {
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 18%, rgba(255, 255, 255, 0.96), rgba(246, 249, 253, 0.94) 34%, rgba(236, 242, 248, 0.92) 100%);
        }
        body.employee-analytics-page .navbar {
            position: sticky;
            top: 0;
            z-index: 4000;
        }
        body.employee-analytics-page .navbar-collapse,
        body.employee-analytics-page .nav-right,
        body.employee-analytics-page .notification-wrapper,
        body.employee-analytics-page .user-menu,
        body.employee-analytics-page .user-btn,
        body.employee-analytics-page .user-dropdown {
            position: relative;
            z-index: 4001;
        }
        body.employee-analytics-page .admin-page,
        body.employee-analytics-page .admin-container {
            background: transparent;
        }
        body.employee-analytics-page .admin-container {
            width: 100%;
            max-width: 1440px;
            margin: 0 auto;
            padding: 28px 24px 42px;
        }
        .admin-content {
            max-width: 1460px;
            padding-top: 10px;
        }
        body.employee-analytics-page .admin-content {
            max-width: 1320px;
            padding-top: 0;
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
        .analytics-filter-note {
            font-size: 12px;
            color: #64748b;
            font-weight: 700;
            line-height: 1.35;
        }
        body.employee-analytics-page .analytics-toolbar,
        body.employee-analytics-page .analytics-card,
        body.employee-analytics-page .chart-card,
        body.employee-analytics-page .table-card {
            box-shadow: 0 18px 44px rgba(148, 163, 184, 0.14);
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
            gap: 16px;
            margin-bottom: 22px;
        }
        .analytics-card {
            position: relative;
            padding: 16px 16px 14px;
            min-width: 0;
            overflow: hidden;
            border-radius: 20px;
            background:
                radial-gradient(circle at 18% 88%, rgba(102, 241, 168, 0.12), transparent 26%),
                radial-gradient(circle at 82% 22%, rgba(255, 255, 255, 0.94), rgba(255, 255, 255, 0.9) 44%, rgba(246, 248, 252, 0.96) 100%);
            box-shadow:
                0 14px 30px rgba(148, 163, 184, 0.14),
                inset 0 1px 0 rgba(255, 255, 255, 0.85);
            border: 1px solid rgba(225, 232, 243, 0.95);
        }
        .analytics-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 8px;
            border-radius: 0 10px 10px 0;
            background: var(--metric-accent, #4ade80);
            box-shadow: 1px 0 0 rgba(255, 255, 255, 0.48);
        }
        .analytics-card.received {
            --metric-accent: #4ecb72;
            --metric-label: #2eaf62;
            --metric-icon-bg: linear-gradient(180deg, #effbf2 0%, #e3f5ea 100%);
            --metric-icon-border: #cdecd7;
            --metric-icon-color: #28a85b;
        }
        .analytics-card.resolved {
            --metric-accent: #ffb427;
            --metric-label: #dc8c00;
            --metric-icon-bg: linear-gradient(180deg, #fff6e8 0%, #ffefd7 100%);
            --metric-icon-border: #f6d8a1;
            --metric-icon-color: #d99a00;
        }
        .analytics-card.closed {
            --metric-accent: #8a95ab;
            --metric-label: #7b839b;
            --metric-icon-bg: linear-gradient(180deg, #f0f3fa 0%, #e6ebf4 100%);
            --metric-icon-border: #d5ddeb;
            --metric-icon-color: #6d7692;
        }
        .analytics-card.avg-time {
            --metric-accent: #2196f3;
            --metric-label: #2385db;
            --metric-icon-bg: linear-gradient(180deg, #ebf6ff 0%, #dff1ff 100%);
            --metric-icon-border: #bcdfff;
            --metric-icon-color: #2388e7;
        }
        .analytics-card.in-progress {
            --metric-accent: #7c3aed;
            --metric-label: #6d28d9;
            --metric-icon-bg: linear-gradient(180deg, #f5eeff 0%, #ede2ff 100%);
            --metric-icon-border: #d8c1ff;
            --metric-icon-color: #6d28d9;
        }
        .analytics-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .analytics-label {
            font-size: 0.88rem;
            font-weight: 500;
            color: var(--metric-label, #111827);
            letter-spacing: 0;
            text-transform: none;
            margin-bottom: 16px;
            line-height: 1.15;
        }
        .analytics-value {
            font-size: 2.55rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
            letter-spacing: -0.04em;
        }
        .analytics-sub {
            margin-top: 16px;
            font-size: 0.88rem;
            color: #4b5563;
            font-weight: 500;
        }
        .analytics-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--metric-icon-bg, linear-gradient(180deg, #eaf8ef 0%, #dff1e8 100%));
            border: 1px solid var(--metric-icon-border, #c7e7d0);
            color: var(--metric-icon-color, #26a14a);
            flex: 0 0 auto;
            font-size: 16px;
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.78),
                0 8px 18px rgba(226, 232, 240, 0.28);
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
        .category-card .chart-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }
        .chart-heading {
            min-width: 0;
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
        .company-chart-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            padding: 4px;
            border: 1px solid #dbe3ef;
            border-radius: 13px;
            background: #f8fafc;
            flex: 0 0 auto;
        }
        .company-chart-toggle-btn {
            min-height: 34px;
            border: 0;
            border-radius: 9px;
            padding: 0 12px;
            background: transparent;
            color: #556171;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        }
        .company-chart-toggle-btn:hover,
        .company-chart-toggle-btn:focus-visible {
            color: #1B5E20;
            outline: none;
        }
        .company-chart-toggle-btn.active {
            background: #1B5E20;
            color: #ffffff;
            box-shadow: 0 6px 14px rgba(27, 94, 32, 0.2);
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
        .trend-card {
            gap: 18px;
        }
        .trend-card .chart-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 0;
            min-height: 0;
        }
        .trend-period-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 14px;
            border: 1px solid #dce8f6;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            color: #475569;
            font-size: 13px;
            font-weight: 700;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.72);
            white-space: nowrap;
        }
        .trend-period-pill i {
            color: #2f8cff;
        }
        .trend-overview-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 18px 20px;
            border-radius: 22px;
            border: 1px solid #dfe8f5;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 14px 32px rgba(148, 163, 184, 0.12);
        }
        .trend-overview-main {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }
        .trend-overview-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #eaf3ff 0%, #dce9ff 100%);
            color: #2f8cff;
            border: 1px solid #cfe0fb;
            font-size: 26px;
            flex: 0 0 64px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.75);
        }
        .trend-overview-copy {
            min-width: 0;
        }
        .trend-overview-value {
            font-size: 3rem;
            line-height: 1;
            letter-spacing: -0.05em;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .trend-overview-label {
            font-size: 0.98rem;
            font-weight: 700;
            color: #334155;
        }
        .trend-delta-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 52px;
            padding: 0 16px;
            border-radius: 18px;
            font-weight: 800;
            flex: 0 0 auto;
        }
        .trend-delta-badge.up {
            background: linear-gradient(180deg, #fff3eb 0%, #ffeddc 100%);
            color: #f97316;
        }
        .trend-delta-badge.down {
            background: linear-gradient(180deg, #edfdf2 0%, #dcfce7 100%);
            color: #16a34a;
        }
        .trend-delta-badge.flat {
            background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
            color: #64748b;
        }
        .trend-delta-copy {
            display: flex;
            flex-direction: column;
            gap: 4px;
            line-height: 1;
        }
        .trend-delta-value {
            font-size: 1.02rem;
            font-weight: 800;
        }
        .trend-delta-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: #64748b;
        }
        body.employee-analytics-page .trend-overview-card {
            flex-direction: column;
            align-items: flex-start;
        }
        body.employee-analytics-page .trend-delta-badge {
            width: 100%;
            justify-content: flex-start;
        }
        .trend-mini-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .trend-mini-card {
            min-width: 0;
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid #e3eaf4;
            background: linear-gradient(180deg, #ffffff 0%, #fafcff 100%);
            box-shadow: 0 12px 28px rgba(148, 163, 184, 0.1);
        }
        .trend-mini-top {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .trend-mini-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex: 0 0 38px;
        }
        .trend-mini-card.fastest .trend-mini-icon {
            background: linear-gradient(180deg, #ecfdf3 0%, #dcfce7 100%);
            color: #16a34a;
        }
        .trend-mini-card.peak .trend-mini-icon {
            background: linear-gradient(180deg, #fff3eb 0%, #ffe7d1 100%);
            color: #f97316;
        }
        .trend-mini-card.trend .trend-mini-icon {
            background: linear-gradient(180deg, #f3ebff 0%, #ede9fe 100%);
            color: #7c3aed;
        }
        .trend-mini-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #1f2937;
        }
        .trend-mini-main {
            font-size: 1rem;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }
        .trend-mini-sub {
            font-size: 0.92rem;
            color: #64748b;
            font-weight: 700;
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
        .trend-card .insight-pill {
            margin-top: 0;
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
            .trend-mini-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 900px) {
            body.employee-analytics-page .admin-container {
                padding: 18px 14px 30px;
            }
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
            .trend-card .chart-header,
            .trend-overview-card {
                flex-direction: column;
                align-items: flex-start;
            }
            .trend-delta-badge {
                width: 100%;
                justify-content: flex-start;
            }
            .trend-mini-grid {
                grid-template-columns: 1fr;
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
            .category-card .chart-header {
                flex-direction: column;
                align-items: stretch;
            }
            .company-chart-toggle {
                width: 100%;
                justify-content: stretch;
            }
            .company-chart-toggle-btn {
                flex: 1 1 0;
                padding: 0 8px;
            }
            .trend-overview-card {
                padding: 16px;
            }
            .trend-overview-icon {
                width: 56px;
                height: 56px;
                flex-basis: 56px;
                font-size: 22px;
            }
            .trend-overview-value {
                font-size: 2.4rem;
            }
            .trend-period-pill,
            .trend-delta-badge {
                width: 100%;
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
<body class="<?= $analyticsIsEmployeeView ? 'employee-analytics-page' : '' ?>">

<div class="admin-page">

    <?php include __DIR__ . '/../includes/' . ($analyticsIsEmployeeView ? 'employee_navbar.php' : 'admin_navbar.php'); ?>

    <div class="admin-container">
        <div class="admin-content">
            
            <div class="admin-page-header">
                <h1 class="admin-page-title analytics-title"><i class="fa-solid fa-chart-line"></i> Analytics</h1>
                <div class="analytics-header-actions">
                    <?php
                        $analyticsPdfHref = ($analyticsIsEmployeeView ? 'export_analytics_pdf.php' : 'export_analytics_pdf.php')
                            . '?start_date=' . urlencode($start_date)
                            . '&end_date=' . urlencode($end_date)
                            . '&category=' . urlencode($category_filter)
                            . '&company=' . urlencode($company_filter)
                            . '&department=' . urlencode($department_filter)
                            . '&status=' . urlencode($status_filter);
                        $analyticsExcelHref = ($analyticsIsEmployeeView ? 'export_analytics_excel.php' : 'export_analytics_excel.php')
                            . '?start_date=' . urlencode($start_date)
                            . '&end_date=' . urlencode($end_date)
                            . '&category=' . urlencode($category_filter)
                            . '&company=' . urlencode($company_filter)
                            . '&department=' . urlencode($department_filter)
                            . '&status=' . urlencode($status_filter);
                    ?>
                    <a href="<?= htmlspecialchars($analyticsPdfHref, ENT_QUOTES, 'UTF-8') ?>" class="btn-export btn-export-pdf" target="_blank">
                        <i class="fa-regular fa-file-pdf"></i> PDF
                    </a>
                    <a href="<?= htmlspecialchars($analyticsExcelHref, ENT_QUOTES, 'UTF-8') ?>" class="btn-export btn-export-excel" target="_blank">
                        <i class="fa-regular fa-file-excel"></i> Excel
                    </a>
                </div>
            </div>

            <div class="analytics-toolbar">
                <form method="GET" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8') ?>" class="analytics-filterbar">
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
                        <?php if ($analyticsIsEmployeeView): ?>
                            <input type="hidden" name="company" value="<?= htmlspecialchars($company_filter, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php else: ?>
                            <div class="analytics-filter">
                                <label>Company</label>
                                <select class="analytics-control" name="company" id="analyticsCompanyFilter">
                                    <option value="" <?= $company_filter === '' ? 'selected' : '' ?>>All Company</option>
                                    <?php foreach ($company_options as $companyOption): ?>
                                        <option value="<?= htmlspecialchars($companyOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $company_filter === $companyOption ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(ticket_company_display_name($companyOption), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="analytics-filter">
                            <label>Department</label>
                            <?php if ($analyticsIsEmployeeView): ?>
                                <input type="hidden" name="department" value="<?= htmlspecialchars($department_filter, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="analytics-control" style="display:flex; align-items:center;">
                                    <?= htmlspecialchars($department_filter !== '' ? $department_filter : 'Unassigned', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php else: ?>
                                <select class="analytics-control" name="department" id="analyticsDepartmentFilter" <?= $company_filter !== '@leadsagri.com' ? 'disabled' : '' ?>>
                                    <option value="" <?= $department_filter === '' ? 'selected' : '' ?>>All Department</option>
                                    <?php foreach ($department_options as $d): ?>
                                        <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $department_filter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
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
                                <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8') ?>" class="analytics-inline-clear">Clear</a>
                            </div>
                        </div>
                    </div>

                </form>
            </div>

            <div class="analytics-metrics">
                <div class="analytics-card received">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Total Tickets</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['received'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-inbox"></i></div>
                    </div>
                    <div class="analytics-sub">All tickets in the selected range</div>
                </div>
                <div class="analytics-card resolved">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Resolved</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['resolved'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="analytics-sub">Resolved tickets</div>
                </div>
                <div class="analytics-card avg-time">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">Open Tickets</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['open'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-folder-open"></i></div>
                    </div>
                    <div class="analytics-sub">Tickets waiting to be handled</div>
                </div>
                <div class="analytics-card in-progress">
                    <div class="analytics-card-top">
                        <div>
                            <div class="analytics-label">In Progress</div>
                            <div class="analytics-value"><?= number_format((int) ($summary['in_progress'] ?? 0)) ?></div>
                        </div>
                        <div class="analytics-icon"><i class="fa-solid fa-spinner"></i></div>
                    </div>
                    <div class="analytics-sub">Tickets currently being handled</div>
                </div>
            </div>

            <div class="analytics-charts">
                <div class="chart-card category-card">
                    <div class="chart-header">
                        <div class="chart-heading">
                            <div class="chart-title" id="companyChartTitle"><?= $analyticsIsEmployeeView ? 'Department Ticket Distribution' : 'Tickets per Company' ?></div>
                            <p class="chart-subtitle" id="companyChartSubtitle"><?= htmlspecialchars($analyticsIsEmployeeView ? ($employeeCompanyLabel . ' • ' . $employeeDepartmentLabel) : 'LAPC tickets by department', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <?php if (!$analyticsIsEmployeeView): ?>
                        <div class="company-chart-toggle" aria-label="Tickets per company view">
                            <button type="button" class="company-chart-toggle-btn active" data-company-view="lapc" aria-pressed="true">LAPC</button>
                            <button type="button" class="company-chart-toggle-btn" data-company-view="other" aria-pressed="false">Other Companies</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                    <?php if (count($companyLegendItems) > 0): ?>
                        <div class="category-legend-grid" id="companyChartLegend">
                            <?php foreach ($companyLegendItems as $item): ?>
                                <div class="category-legend-item">
                                    <span class="category-legend-dot" style="background: <?= htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                    <div class="category-legend-text">
                                        <span class="category-legend-name"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="category-legend-percent"><?= number_format((int) $item['count']) ?> (<?= (int) $item['percent'] ?>%)</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="category-legend-grid" id="companyChartLegend"></div>
                    <?php endif; ?>
                </div>
                <div class="chart-card trend-card">
                    <div class="chart-header">
                        <div class="chart-heading">
                            <div class="chart-title">Resolution Time Trend</div>
                            <p class="chart-subtitle"><?= htmlspecialchars($trendSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="trend-period-pill">
                            <i class="fa-regular fa-calendar"></i>
                            <span>Last 7 weekdays</span>
                        </div>
                    </div>
                    <div class="trend-overview-card">
                        <div class="trend-overview-main">
                            <div class="trend-overview-icon"><i class="fa-regular fa-clock"></i></div>
                            <div class="trend-overview-copy">
                                <div class="trend-overview-value"><?= htmlspecialchars(formatHandlingTimeDetailed((int) $trendAverageSeconds), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="trend-overview-label">Avg Resolution Time</div>
                            </div>
                        </div>
                        <div class="trend-delta-badge <?= htmlspecialchars($trendSummaryBadgeClass, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fa-solid <?= htmlspecialchars($trendSummaryBadgeIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                            <div class="trend-delta-copy">
                                <span class="trend-delta-value"><?= htmlspecialchars($trendSummaryBadgeText, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="trend-delta-label">vs previous 7 weekdays</span>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                    <div class="trend-mini-grid">
                        <div class="trend-mini-card fastest">
                            <div class="trend-mini-top">
                                <span class="trend-mini-icon"><i class="fa-regular fa-circle-check"></i></span>
                                <span class="trend-mini-title">Fastest</span>
                            </div>
                            <div class="trend-mini-main"><?= htmlspecialchars($trendFastestDay['label'] ?? 'No data', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="trend-mini-sub"><?= $trendFastestDay ? htmlspecialchars(number_format((float) $trendFastestDay['hours'], 1) . 'h', ENT_QUOTES, 'UTF-8') : 'No resolved tickets' ?></div>
                        </div>
                        <div class="trend-mini-card peak">
                            <div class="trend-mini-top">
                                <span class="trend-mini-icon"><i class="fa-solid fa-fire-flame-curved"></i></span>
                                <span class="trend-mini-title">Peak</span>
                            </div>
                            <div class="trend-mini-main"><?= htmlspecialchars($trendPeakDay['label'] ?? 'No data', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="trend-mini-sub"><?= $trendPeakDay ? htmlspecialchars(number_format((float) $trendPeakDay['hours'], 1) . 'h', ENT_QUOTES, 'UTF-8') : 'No resolved tickets' ?></div>
                        </div>
                        <div class="trend-mini-card trend">
                            <div class="trend-mini-top">
                                <span class="trend-mini-icon"><i class="fa-solid fa-arrow-trend-up"></i></span>
                                <span class="trend-mini-title">Trend</span>
                            </div>
                            <div class="trend-mini-main">
                                <?= !empty($trendDayStats) ? htmlspecialchars(($trendDeltaPercent !== 0 ? ($trendDeltaPercent > 0 ? '+' : '') . $trendDeltaPercent . '%' : '0%'), ENT_QUOTES, 'UTF-8') : 'No data' ?>
                            </div>
                            <div class="trend-mini-sub">vs previous 7 weekdays</div>
                        </div>
                    </div>
                    <div class="insight-pill">
                        <div class="insight-pill-label">
                            <i class="fa-regular fa-lightbulb"></i>
                            <span>
                                <?php if ($resolutionBucketTotal > 0): ?>
                                    Most resolved tickets took <?= htmlspecialchars($resolutionTopBucketLabel, ENT_QUOTES, 'UTF-8') ?> (<?= (int) $resolutionTopBucketPercent ?>%)
                                <?php else: ?>
                                    No resolved tickets in the selected range
                                <?php endif; ?>
                            </span>
                        </div>
                        <span class="insight-pill-value"><i class="fa-solid fa-chevron-right"></i></span>
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
    const companyChartDatasets = <?= json_encode($companyChartDatasets) ?>;
    let activeCompanyChartData = companyChartDatasets.lapc || { title: 'Tickets per Company', subtitle: 'LAPC tickets by department', labels: [], counts: [], colors: [] };

    function companyChartTotal(data) {
        return (data.counts || []).reduce(function(sum, value) { return sum + (Number(value) || 0); }, 0);
    }

    function renderCompanyLegend(data) {
        var legend = document.getElementById('companyChartLegend');
        if (!legend) return;

        var total = companyChartTotal(data);
        var labels = data.labels || [];
        var counts = data.counts || [];
        var colors = data.colors || [];
        legend.innerHTML = '';

        labels.forEach(function(label, idx) {
            var count = Number(counts[idx] || 0);
            var percent = total > 0 ? Math.round((count / total) * 100) : 0;
            var item = document.createElement('div');
            item.className = 'category-legend-item';

            var dot = document.createElement('span');
            dot.className = 'category-legend-dot';
            dot.style.background = colors[idx] || '#2f8cff';

            var text = document.createElement('div');
            text.className = 'category-legend-text';

            var name = document.createElement('span');
            name.className = 'category-legend-name';
            name.textContent = label;

            var pct = document.createElement('span');
            pct.className = 'category-legend-percent';
            pct.textContent = count.toLocaleString() + ' (' + percent + '%)';

            text.appendChild(name);
            text.appendChild(pct);
            item.appendChild(dot);
            item.appendChild(text);
            legend.appendChild(item);
        });
    }

    const catCtx = document.getElementById('categoryChart').getContext('2d');
    const companyChart = new Chart(catCtx, {
        type: 'doughnut',
        data: {
            labels: activeCompanyChartData.labels,
            datasets: [{
                data: activeCompanyChartData.counts,
                backgroundColor: activeCompanyChartData.colors,
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
                            var total = companyChartTotal(activeCompanyChartData);
                            var pct = total > 0 ? Math.round((value / total) * 100) : 0;
                            return context.label + ': ' + value + ' (' + pct + '%)';
                        }
                    }
                },
                datalabels: {
                    color: '#ffffff',
                    font: { weight: '800', size: 12 },
                    formatter: function(value) {
                        var total = companyChartTotal(activeCompanyChartData);
                        if (!total || !value) return '';
                        var pct = Math.round((Number(value) / total) * 100);
                        if (pct < 2) return '';
                        return pct + '%';
                    }
                }
            },
            cutout: '63%'
        }
    });

    (function () {
        var title = document.getElementById('companyChartTitle');
        var subtitle = document.getElementById('companyChartSubtitle');
        var buttons = document.querySelectorAll('.company-chart-toggle-btn[data-company-view]');

        function setCompanyChartView(view) {
            var nextData = companyChartDatasets[view] || companyChartDatasets.lapc || activeCompanyChartData;
            activeCompanyChartData = nextData;
            companyChart.data.labels = nextData.labels || [];
            companyChart.data.datasets[0].data = nextData.counts || [];
            companyChart.data.datasets[0].backgroundColor = nextData.colors || [];
            companyChart.update();

            if (title) title.textContent = nextData.title || 'Tickets per Company';
            if (subtitle) subtitle.textContent = nextData.subtitle || '';
            renderCompanyLegend(nextData);

            buttons.forEach(function(button) {
                var isActive = button.getAttribute('data-company-view') === view;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        buttons.forEach(function(button) {
            button.addEventListener('click', function() {
                setCompanyChartView(button.getAttribute('data-company-view'));
            });
        });
        setCompanyChartView('lapc');
    })();

    const trendCtx = document.getElementById('trendChart').getContext('2d');
    const trendGradient = trendCtx.createLinearGradient(0, 0, 0, 286);
    trendGradient.addColorStop(0, 'rgba(47, 140, 255, 0.24)');
    trendGradient.addColorStop(1, 'rgba(47, 140, 255, 0.02)');
    const trendMaxHours = <?= json_encode($trendMaxHours) ?>;
    const trendDataPoints = <?= json_encode($trendAvgHours) ?>;
    const trendPeakIndex = <?= json_encode($trendPeakDay['index'] ?? null) ?>;
    const trendFastestIndex = <?= json_encode($trendFastestDay['index'] ?? null) ?>;
    const isEmployeeAnalyticsView = <?= $analyticsIsEmployeeView ? 'true' : 'false' ?>;
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendWeeks) ?>,
            datasets: [{
                label: 'Avg Resolution Time (Hours)',
                data: trendDataPoints,
                borderColor: '#2f8cff',
                backgroundColor: trendGradient,
                borderWidth: 3.5,
                tension: 0.28,
                fill: true,
                spanGaps: true,
                clip: false,
                pointRadius: function(context) {
                    var value = context.raw;
                    if (value === null || typeof value === 'undefined') return 0;
                    if (context.dataIndex === trendPeakIndex || context.dataIndex === trendFastestIndex) return 7;
                    return 5;
                },
                pointHoverRadius: function(context) {
                    var value = context.raw;
                    if (value === null || typeof value === 'undefined') return 0;
                    return context.dataIndex === trendPeakIndex || context.dataIndex === trendFastestIndex ? 8 : 6;
                },
                pointBackgroundColor: function(context) {
                    if (context.dataIndex === trendFastestIndex) return '#22c55e';
                    return '#2f8cff';
                },
                pointBorderColor: '#ffffff',
                pointBorderWidth: function(context) {
                    var value = context.raw;
                    return value === null || typeof value === 'undefined' ? 0 : 3;
                }
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: isEmployeeAnalyticsView ? 34 : 18,
                    right: 14,
                    left: 14,
                    bottom: 8
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.raw === null || typeof context.raw === 'undefined') return 'No resolved tickets';
                            return 'Avg time: ' + Number(context.raw).toFixed(1) + 'h';
                        }
                    }
                },
                datalabels: {
                    align: 'top',
                    anchor: 'end',
                    offset: 6,
                    clamp: true,
                    clip: false,
                    color: '#0f172a',
                    font: {
                        weight: '800',
                        size: 11
                    },
                    formatter: function(value) {
                        if (value === null || typeof value === 'undefined') return '';
                        return Number(value).toFixed(1) + 'h';
                    }
                }
            },
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
                        color: function(context) {
                            return context.index === trendPeakIndex ? '#2f8cff' : textColor;
                        },
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

