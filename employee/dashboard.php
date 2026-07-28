<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/csrf.php';

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

ticket_apply_sla_priority($conn);

$user_id = (int) $_SESSION['user_id'];
$feedbackFlash = isset($_SESSION['feedback_flash']) && is_array($_SESSION['feedback_flash']) ? $_SESSION['feedback_flash'] : null;
if ($feedbackFlash !== null) {
    unset($_SESSION['feedback_flash']);
}
$showFeedbackSuccessModal = $feedbackFlash && (($feedbackFlash['type'] ?? '') === 'success') && !empty($feedbackFlash['message']);

function dashboard_company_code(string $value): string
{
    $s = strtoupper(trim($value));
    if ($s === '') return '';
    if ($s === 'FARMASEE') return 'PCC';
    if (strpos($s, 'MHC') !== false) return 'MHC';
    if (strpos($s, 'GPCI') !== false || strpos($s, 'GPSCI') !== false) return 'GPCI';
    if (strpos($s, 'LAPC') !== false || strpos($s, 'LAH') !== false) return 'LAPC';
    if (strpos($s, 'PCC') !== false) return 'PCC';
    if (strpos($s, 'MPDC') !== false) return 'MPDC';
    if (strpos($s, 'LINGAP') !== false) return 'LINGAP';
    if (strpos($s, 'LTC') !== false) return 'LTC';
    if (strpos($s, 'FARMEX') !== false) return 'FARMEX';
    if (strpos($s, 'FARMEX CORP') !== false) return 'FARMEX';
    return '';
}

function dashboard_company_aliases(string $value): array
{
    $v = trim($value);
    $code = dashboard_company_code($v);
    $map = [
        'MHC' => ['MHC', 'Malveda Holdings Corporation - MHC'],
        'GPCI' => ['GPCI', 'GPSCI', 'Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI'],
        'LAPC' => ['LAPC', 'Leads Animal Health - LAH', 'LEADS Animal Health - LAH'],
        'PCC' => ['PCC', 'Primestocks Chemical Corporation - PCC', 'FARMASEE'],
        'MPDC' => ['MPDC', 'Malveda Properties & Development Corporation - MPDC'],
        'LINGAP' => ['LINGAP', 'LINGAP LEADS FOUNDATION - Lingap'],
        'LTC' => ['LTC', 'Leads Tech Corporation - LTC'],
        'FARMEX' => ['FARMEX', 'Farmex Corp'],
    ];
    $aliases = [];
    if ($v !== '') $aliases[] = $v;
    if ($code !== '' && isset($map[$code])) {
        $aliases = array_merge($aliases, $map[$code]);
    }
    return array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($x) { return $x !== ''; })));
}

function dashboard_assigned_query_parts(int $user_id, string $user_email, string $user_company, string $user_department): array
{
    $companyAliases = dashboard_company_aliases($user_company);
    if (count($companyAliases) === 0) {
        $companyAliases = [$user_company];
    }
    $companyAliases = array_values(array_filter(array_map('trim', $companyAliases), static function ($v) { return $v !== ''; }));

    $departmentKey = ticket_department_key_from_value($user_department);
    $departmentAliases = [];
    foreach (array_merge([$user_department, $departmentKey], ticket_department_aliases_for_key($departmentKey)) as $departmentAlias) {
        $departmentAlias = strtoupper(trim((string) $departmentAlias));
        if ($departmentAlias !== '') {
            $departmentAliases[$departmentAlias] = $departmentAlias;
        }
    }
    $departmentAliases = array_values($departmentAliases);

    $companyCol = "COALESCE(NULLIF(t.assigned_company, ''), t.company)";
    $companyAliasCond = count($companyAliases) > 0
        ? ("(" . implode(" OR ", array_fill(0, count($companyAliases), "$companyCol = ?")) . ")")
        : "(1=0)";
    $companyCond = "(($companyCol LIKE '@%' AND LOWER(?) LIKE CONCAT('%', LOWER($companyCol))) OR ($companyCol NOT LIKE '@%' AND $companyAliasCond))";
    $taskDeptExpr = "COALESCE(NULLIF(NULLIF(t.assigned_group, ''), NULLIF(t.assigned_department, 'Unassigned')), NULLIF(t.assigned_department, ''), NULLIF(t.department, ''), NULLIF(u.department, ''))";
    $sourceEmailExpr = "COALESCE(NULLIF(t.requester_email, ''), NULLIF(u.email, ''))";
    $groupCond = count($departmentAliases) > 0
        ? ("UPPER($taskDeptExpr) IN (" . implode(', ', array_fill(0, count($departmentAliases), '?')) . ")")
        : "0=1";
    $requiresGroupCond = "(($companyCol LIKE '@%' AND LOWER($companyCol) = '@leadsagri.com') OR ($companyCol NOT LIKE '@%' AND UPPER($companyCol) = 'LAPC'))";
    $requesterIsCurrentCond = "(t.user_id = ? OR LOWER($sourceEmailExpr) = ?)";
    $linkedItCond = "0=1";
    $normalizedUserCompany = ticket_normalize_company($user_company);
    if ($departmentKey === 'IT') {
        if ($normalizedUserCompany === '@malvedaholdings.com') {
            $linkedItCond = "(LOWER($companyCol) IN ('@leadsagri.com', 'lapc', 'leads agri', 'leads agricultural products corporation') AND UPPER($taskDeptExpr) = 'IT')";
        } elseif ($normalizedUserCompany === '@leadsagri.com') {
            $linkedItCond = "(LOWER($companyCol) IN ('@malvedaholdings.com', 'mhc', 'malveda holdings', 'malveda holdings corporation') AND UPPER($taskDeptExpr) = 'IT')";
        }
    }
    $condition = "(((t.assigned_user_id = ? OR t.assigned_to = ?) AND NOT $requesterIsCurrentCond) OR (NOT $requesterIsCurrentCond AND (($companyCond AND ((NOT $requiresGroupCond) OR $groupCond)) OR $linkedItCond)))";
    $params = [
        $user_id,
        $user_id,
        $user_id,
        strtolower($user_email),
        $user_id,
        strtolower($user_email),
        strtolower($user_email),
    ];
    $types = "iiisiss";

    foreach ($companyAliases as $companyAlias) {
        $params[] = $companyAlias;
        $types .= "s";
    }
    foreach ($departmentAliases as $departmentAlias) {
        $params[] = $departmentAlias;
        $types .= "s";
    }

    return [
        'condition' => $condition,
        'params' => $params,
        'types' => $types,
        'task_department_expr' => $taskDeptExpr,
    ];
}

/* Fetch profile context */
$company = (string) ($_SESSION['company'] ?? '');
$user_department = (string) ($_SESSION['department'] ?? '');
$user_email = (string) ($_SESSION['email'] ?? '');
$user_region = (string) ($_SESSION['region'] ?? '');
$hasUserRegionColumn = false;
$regionColumnRes = $conn->query("SHOW COLUMNS FROM users LIKE 'region'");
if ($regionColumnRes && $regionColumnRes->num_rows > 0) {
    $hasUserRegionColumn = true;
}
$userQuery = $conn->query("SELECT company, department, email" . ($hasUserRegionColumn ? ", region" : "") . " FROM users WHERE id = $user_id");
if ($userQuery && $row = $userQuery->fetch_assoc()) {
    $company = (string) ($row['company'] ?? '');
    if ($company !== '') {
        $_SESSION['company'] = $company;
    }
    if ($user_department === '') {
        $user_department = (string) ($row['department'] ?? '');
        if ($user_department !== '') $_SESSION['department'] = $user_department;
    }
    if ($user_email === '') {
        $user_email = (string) ($row['email'] ?? '');
        if ($user_email !== '') $_SESSION['email'] = $user_email;
    }
    if ($hasUserRegionColumn) {
        $user_region = (string) ($row['region'] ?? '');
        $_SESSION['region'] = $user_region;
    }
}

/* Ticket Counts (tickets created by this employee) */
$dept = (string) ($_SESSION['department'] ?? '');

$countStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND COALESCE(NULLIF(status,''),'') <> 'Trash'");
$countStmt->bind_param("i", $user_id);
$countStmt->execute();
$total = (int) (($countStmt->get_result()->fetch_assoc()['count'] ?? 0));
$countStmt->close();

$openStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Open'");
$openStmt->bind_param("i", $user_id);
$openStmt->execute();
$open = (int) (($openStmt->get_result()->fetch_assoc()['count'] ?? 0));
$openStmt->close();

$progressStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'In Progress'");
$progressStmt->bind_param("i", $user_id);
$progressStmt->execute();
$progress = (int) (($progressStmt->get_result()->fetch_assoc()['count'] ?? 0));
$progressStmt->close();

$resolvedStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Resolved'");
$resolvedStmt->bind_param("i", $user_id);
$resolvedStmt->execute();
$resolved = (int) (($resolvedStmt->get_result()->fetch_assoc()['count'] ?? 0));
$resolvedStmt->close();

$closedStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Closed'");
$closedStmt->bind_param("i", $user_id);
$closedStmt->execute();
$closed = (int) (($closedStmt->get_result()->fetch_assoc()['count'] ?? 0));
$closedStmt->close();

$submittedDashboardStats = [
    [
        'variant' => 'total',
        'label' => 'Total Tickets',
        'value' => $total,
        'subtitle' => 'All non-trash tickets in system',
        'icon' => 'fa-stopwatch',
        'href' => 'my_tickets.php',
    ],
    [
        'variant' => 'open',
        'label' => 'Open',
        'value' => $open,
        'subtitle' => 'Awaiting response',
        'icon' => 'fa-folder-open',
        'href' => 'my_tickets.php?status=Open',
    ],
    [
        'variant' => 'progress',
        'label' => 'In Progress',
        'value' => $progress,
        'subtitle' => 'Currently being worked',
        'icon' => 'fa-gear',
        'href' => 'my_tickets.php?status=In+Progress',
    ],
    [
        'variant' => 'resolved',
        'label' => 'Resolved',
        'value' => $resolved,
        'subtitle' => 'Completed tickets',
        'icon' => 'fa-check-circle',
        'href' => 'my_tickets.php?status=Resolved',
    ],
    [
        'variant' => 'closed',
        'label' => 'Closed',
        'value' => $closed,
        'subtitle' => 'Confirmed by requesters',
        'icon' => 'fa-lock',
        'href' => 'my_tickets.php?status=Closed',
    ],
];


/* Recent Tickets (created by this employee) */
$recentStmt = $conn->prepare("
    SELECT
        t.*,
        u.name AS user_name,
        u.email AS user_email,
        u.department AS user_department,
        u.company AS user_company
    FROM employee_tickets t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.user_id = ?
      AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
    ORDER BY t.created_at DESC
    LIMIT 5
");
$recentStmt->bind_param("i", $user_id);
$recentStmt->execute();
$recent = $recentStmt->get_result();
$raisedTickets = [];
while ($recent && ($row = $recent->fetch_assoc())) {
    $raisedTickets[] = $row;
}
$recentStmt->close();

$assignedStatusCounts = [
    'Open' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Closed' => 0,
];

$assignedQueryParts = dashboard_assigned_query_parts($user_id, $user_email, $company, $user_department);
$assignedCond = (string) $assignedQueryParts['condition'];
$assignedParams = $assignedQueryParts['params'];
$assignedTypes = (string) $assignedQueryParts['types'];
$taskDeptExpr = (string) $assignedQueryParts['task_department_expr'];

$assignedCountStmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) AS progress_count,
        SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
        SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count
    FROM employee_tickets t
    JOIN users u ON t.user_id = u.id
    WHERE $assignedCond
      AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
");
if ($assignedCountStmt) {
    $assignedCountStmt->bind_param($assignedTypes, ...$assignedParams);
    $assignedCountStmt->execute();
    $assignedCountRow = $assignedCountStmt->get_result()->fetch_assoc() ?: [];
    $assignedStatusCounts['Open'] = (int) ($assignedCountRow['open_count'] ?? 0);
    $assignedStatusCounts['In Progress'] = (int) ($assignedCountRow['progress_count'] ?? 0);
    $assignedStatusCounts['Resolved'] = (int) ($assignedCountRow['resolved_count'] ?? 0);
    $assignedStatusCounts['Closed'] = (int) ($assignedCountRow['closed_count'] ?? 0);
    $assignedCountStmt->close();
}

$receivedTickets = [];
$receivedStmt = $conn->prepare("
    SELECT
        t.*,
        u.name AS user_name,
        u.email AS user_email,
        u.department AS user_department,
        u.company AS user_company,
        $taskDeptExpr AS task_department
    FROM employee_tickets t
    JOIN users u ON u.id = t.user_id
    WHERE $assignedCond
      AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
    ORDER BY
        CASE LOWER(TRIM(COALESCE(t.status, '')))
            WHEN 'resolved' THEN 1
            WHEN 'closed' THEN 2
            ELSE 0
        END ASC,
        t.created_at DESC
    LIMIT 5
");
if ($receivedStmt) {
    $receivedStmt->bind_param($assignedTypes, ...$assignedParams);
    $receivedStmt->execute();
    $receivedResult = $receivedStmt->get_result();
    while ($receivedResult && ($ticketRow = $receivedResult->fetch_assoc())) {
        $receivedTickets[] = $ticketRow;
    }
    $receivedStmt->close();
}

$isSalesManagerView = ticket_normalize_company((string) $company) === '@leadsagri.com'
    && strcasecmp((string) $user_department, 'Sales') === 0
    && trim((string) $user_region) !== ''
    && (($_SESSION['employee_view_mode'] ?? 'employee') === 'manager');
$managerSalesTickets = [];
$managerSalesStatusCounts = [
    'Open' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Closed' => 0,
];
$managerSalesTotal = 0;
if ($isSalesManagerView) {
    $regionNeedle = '%Region: ' . trim((string) $user_region) . '%';
    $managerSalesCountStmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN t.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN t.status = 'In Progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN t.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN t.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count
        FROM employee_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE LOWER(TRIM(COALESCE(u.email, ''))) = 'sales_guest@leadsagri.com'
          AND COALESCE(t.description, '') LIKE ?
          AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
    ");
    if ($managerSalesCountStmt) {
        $managerSalesCountStmt->bind_param('s', $regionNeedle);
        $managerSalesCountStmt->execute();
        $managerSalesCountRow = $managerSalesCountStmt->get_result()->fetch_assoc() ?: [];
        $managerSalesTotal = (int) ($managerSalesCountRow['total_count'] ?? 0);
        $managerSalesStatusCounts['Open'] = (int) ($managerSalesCountRow['open_count'] ?? 0);
        $managerSalesStatusCounts['In Progress'] = (int) ($managerSalesCountRow['progress_count'] ?? 0);
        $managerSalesStatusCounts['Resolved'] = (int) ($managerSalesCountRow['resolved_count'] ?? 0);
        $managerSalesStatusCounts['Closed'] = (int) ($managerSalesCountRow['closed_count'] ?? 0);
        $managerSalesCountStmt->close();
    }

    $managerSalesStmt = $conn->prepare("
        SELECT
            t.*,
            u.name AS user_name,
            u.email AS user_email,
            u.department AS user_department,
            u.company AS user_company
        FROM employee_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        WHERE LOWER(TRIM(COALESCE(u.email, ''))) = 'sales_guest@leadsagri.com'
          AND COALESCE(t.description, '') LIKE ?
          AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT 5
    ");
    if ($managerSalesStmt) {
        $managerSalesStmt->bind_param('s', $regionNeedle);
        $managerSalesStmt->execute();
        $managerSalesResult = $managerSalesStmt->get_result();
        while ($managerSalesResult && ($ticketRow = $managerSalesResult->fetch_assoc())) {
            $managerSalesTickets[] = $ticketRow;
        }
        $managerSalesStmt->close();
    }
}
$assignedTotal = array_sum($assignedStatusCounts);
$assignedDashboardStats = [
    [
        'variant' => 'total',
        'label' => 'Total Tickets',
        'value' => $assignedTotal,
        'subtitle' => 'All assigned non-trash tickets',
        'icon' => 'fa-stopwatch',
        'href' => 'my_task.php',
    ],
    [
        'variant' => 'open',
        'label' => 'Open',
        'value' => $assignedStatusCounts['Open'],
        'subtitle' => 'Awaiting response',
        'icon' => 'fa-folder-open',
        'href' => 'my_task.php?status=Open',
    ],
    [
        'variant' => 'progress',
        'label' => 'In Progress',
        'value' => $assignedStatusCounts['In Progress'],
        'subtitle' => 'Currently being worked',
        'icon' => 'fa-gear',
        'href' => 'my_task.php?status=In+Progress',
    ],
    [
        'variant' => 'resolved',
        'label' => 'Resolved',
        'value' => $assignedStatusCounts['Resolved'],
        'subtitle' => 'Completed tickets',
        'icon' => 'fa-check-circle',
        'href' => 'my_task.php?status=Resolved',
    ],
    [
        'variant' => 'closed',
        'label' => 'Closed',
        'value' => $assignedStatusCounts['Closed'],
        'subtitle' => 'Confirmed by requesters',
        'icon' => 'fa-lock',
        'href' => 'my_task.php?status=Closed',
    ],
];
$managerSalesDashboardStats = [
    [
        'variant' => 'total',
        'label' => 'Total Tickets',
        'value' => $managerSalesTotal,
        'subtitle' => 'All regional sales tickets',
        'icon' => 'fa-stopwatch',
        'href' => 'sales_submitted_tickets.php',
    ],
    [
        'variant' => 'open',
        'label' => 'Open',
        'value' => $managerSalesStatusCounts['Open'],
        'subtitle' => 'Awaiting response',
        'icon' => 'fa-folder-open',
        'href' => 'sales_submitted_tickets.php?status=Open',
    ],
    [
        'variant' => 'progress',
        'label' => 'In Progress',
        'value' => $managerSalesStatusCounts['In Progress'],
        'subtitle' => 'Currently being worked',
        'icon' => 'fa-gear',
        'href' => 'sales_submitted_tickets.php?status=In+Progress',
    ],
    [
        'variant' => 'resolved',
        'label' => 'Resolved',
        'value' => $managerSalesStatusCounts['Resolved'],
        'subtitle' => 'Completed tickets',
        'icon' => 'fa-check-circle',
        'href' => 'sales_submitted_tickets.php?status=Resolved',
    ],
    [
        'variant' => 'closed',
        'label' => 'Closed',
        'value' => $managerSalesStatusCounts['Closed'],
        'subtitle' => 'Confirmed by requesters',
        'icon' => 'fa-lock',
        'href' => 'sales_submitted_tickets.php?status=Closed',
    ],
];
$dashboardStatSets = $isSalesManagerView
    ? ['manager' => $managerSalesDashboardStats]
    : [
        'submitted' => $submittedDashboardStats,
        'assigned' => $assignedDashboardStats,
    ];

function dashboard_status_class(string $status): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($status)));
}

function dashboard_ticket_category(array $row): string
{
    $category = trim((string) ($row['category'] ?? ''));
    if ($category !== '') return $category;
    $subject = trim((string) ($row['subject'] ?? ''));
    return $subject !== '' ? $subject : 'General Concern';
}

function dashboard_source_label(array $row): string
{
    $sourceEmail = trim((string) (($row['requester_email'] ?? '') !== '' ? $row['requester_email'] : ($row['user_email'] ?? '')));
    $sourceCompanyRaw = (string) (($row['company'] ?? '') !== '' ? $row['company'] : ($row['user_company'] ?? ''));
    if ($sourceCompanyRaw === '' && $sourceEmail !== '' && strpos($sourceEmail, '@') !== false) {
        $sourceCompanyRaw = '@' . strtolower(substr(strrchr($sourceEmail, '@'), 1));
    }
    $sourceCompany = ticket_normalize_company($sourceCompanyRaw);
    $sourceDept = trim((string) (($row['department'] ?? '') !== '' ? $row['department'] : ($row['user_department'] ?? '')));

    if ($sourceCompany === '@leadsagri.com' && $sourceDept !== '') {
        return ticket_department_display_name($sourceDept);
    }

    $companyLabel = ticket_company_display_name($sourceCompanyRaw);
    if ($companyLabel !== '') {
        return $companyLabel;
    }

    if ($sourceDept !== '') {
        return ticket_department_display_name($sourceDept);
    }

    return '-';
}

function dashboard_requester_info(array $row): array
{
    $name = trim((string) (($row['requester_name'] ?? '') !== '' ? $row['requester_name'] : ($row['user_name'] ?? '')));
    $email = trim((string) (($row['requester_email'] ?? '') !== '' ? $row['requester_email'] : ($row['user_email'] ?? '')));
    $description = (string) ($row['description'] ?? '');

    if ($description !== '') {
        if ($name === '' && preg_match('/REQUESTER NAME:\s*(.+)$/im', $description, $m)) {
            $name = trim($m[1]);
        }
        if ($email === '' && preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $description, $m)) {
            $email = trim($m[1]);
        }
    }

    return [
        'name' => $name !== '' ? $name : 'Unknown Requester',
        'email' => $email,
    ];
}

function dashboard_sales_position(array $row): string
{
    $description = preg_replace('/<br\s*\/?>/i', "\n", (string) ($row['description'] ?? '')) ?? '';
    if ($description !== '' && preg_match('/^\s*Position:\s*(.+)$/mi', $description, $m)) {
        $position = trim(strip_tags((string) $m[1]));
        return $position !== '' ? $position : '-';
    }
    return '-';
}

function dashboard_sla_rank(string $createdAt, string $status, string $priority = ''): int
{
    $slaLevel = ticket_effective_sla_level($createdAt, $status, $priority);
    if ($slaLevel === 'High') return 0;
    if ($slaLevel === 'Medium') return 1;
    if ($slaLevel === 'Low') return 2;
    return 3;
}

function dashboard_sla_badge_html(string $createdAt, string $status, string $priority = ''): string
{
    return ticket_sla_badge_html($createdAt, $status, $priority);
}

function dashboard_priority_badge_html(array $row): string
{
    $priority = trim((string) ($row['priority'] ?? ''));
    if ($priority === '') {
        $priority = 'Low';
    }
    $class = dashboard_status_class($priority);
    return '<span class="dashboard-priority-badge dashboard-priority-' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') . '</span>';
}

function dashboard_urgency_badge_html(string $priority): string
{
    $priority = trim($priority);
    if ($priority === '') return '-';
    $priorityKey = strtolower($priority);
    $allowedKeys = ['low', 'medium', 'high', 'critical'];
    $priorityClass = in_array($priorityKey, $allowedKeys, true) ? $priorityKey : 'low';
    return '<span class="priority-pill priority-' . htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(ucfirst($priorityKey), ENT_QUOTES, 'UTF-8') . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>Employee Dashboard | Leads DeskMetamorph</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <!-- Optional: Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-green: #19692a;
            --brand-green-600: #145322;
            --accent-orange: #ffb26b;
            --accent-yellow: #f7e2a2;
            --badge-green: #dff3e5;
            --space-xs: 6px;
            --space-sm: 12px;
            --space-md: 18px;
            --space-lg: 28px;
            --radius-sm: 8px;
            --radius-md: 12px;
            --text-base: 16px;
        }

        body.employee-dashboard-page .feedback-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 3200;
        }

        body.employee-dashboard-page .feedback-modal-overlay.is-visible {
            display: flex;
        }

        body.employee-dashboard-page .feedback-modal-dialog {
            position: relative;
            width: min(100%, 560px);
            background: #ffffff;
            border-radius: 22px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.24);
            overflow: hidden;
            border: 1px solid rgba(203, 213, 225, 0.8);
            text-align: center;
        }

        body.employee-dashboard-page .feedback-modal-header {
            padding: 36px 36px 34px;
            background: #ffffff;
            color: #111827;
            position: relative;
        }

        body.employee-dashboard-page .feedback-modal-success-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 4px solid #bbf7b5;
            background: #ecfdf5;
            color: #0f5f24;
            font-size: 44px;
            font-weight: 500;
            line-height: 1;
        }

        body.employee-dashboard-page .feedback-modal-title {
            margin: 0 0 16px;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 800;
        }

        body.employee-dashboard-page .feedback-modal-subtitle {
            margin: 0;
            font-size: 18px;
            line-height: 1.45;
            color: #4b5563;
        }

        body.employee-dashboard-page .feedback-modal-body {
            padding: 22px 36px 28px;
            border-top: 1px solid #e5e7eb;
        }

        body.employee-dashboard-page .feedback-modal-body .feedback-actions {
            display: flex;
            justify-content: center;
            gap: 0;
        }

        body.employee-dashboard-page .feedback-modal-body .feedback-submit-btn {
            min-width: 170px;
            min-height: 52px;
            border-radius: 16px;
            background: #11651f;
            font-size: 18px;
            box-shadow: 0 14px 28px rgba(17, 101, 31, 0.22);
        }

        body.employee-dashboard-page .feedback-ticket-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            max-width: 100%;
            padding: 12px 16px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid #dbe4ee;
            color: #0f172a;
            margin-bottom: 18px;
        }

        body.employee-dashboard-page .feedback-ticket-chip strong {
            font-size: 15px;
            font-weight: 800;
            color: #14532d;
        }

        body.employee-dashboard-page .feedback-ticket-chip span {
            font-size: 14px;
            color: #475569;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        body.employee-dashboard-page .feedback-flash {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
        }

        body.employee-dashboard-page .feedback-flash.is-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        body.employee-dashboard-page .feedback-flash.is-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        body.employee-dashboard-page .feedback-form {
            display: grid;
            gap: 18px;
        }

        body.employee-dashboard-page .feedback-label {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: #334155;
            text-transform: uppercase;
        }

        body.employee-dashboard-page .feedback-stars {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        body.employee-dashboard-page .feedback-star-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        body.employee-dashboard-page .feedback-star {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            border: 1px solid #dbe4ee;
            background: #ffffff;
            color: #cbd5e1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.18s ease, border-color 0.18s ease, color 0.18s ease, background 0.18s ease;
        }

        body.employee-dashboard-page .feedback-star:hover,
        body.employee-dashboard-page .feedback-star:focus-visible {
            transform: translateY(-1px);
            border-color: #f4c430;
            color: #f59e0b;
            background: #fffbea;
        }

        body.employee-dashboard-page .feedback-star.is-active {
            border-color: #f4c430;
            color: #f59e0b;
            background: #fff7d6;
            box-shadow: 0 10px 22px rgba(245, 158, 11, 0.18);
        }

        body.employee-dashboard-page .feedback-textarea {
            width: 100%;
            min-height: 130px;
            resize: vertical;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid #dbe4ee;
            background: #ffffff;
            color: #0f172a;
            font-size: 15px;
            line-height: 1.55;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        body.employee-dashboard-page .feedback-textarea:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.12);
        }

        body.employee-dashboard-page .feedback-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        body.employee-dashboard-page .feedback-cancel-btn {
            min-width: 132px;
            min-height: 48px;
            border: 1px solid #dbe4ee;
            border-radius: 14px;
            background: #ffffff;
            color: #334155;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
        }

        body.employee-dashboard-page .feedback-submit-btn {
            min-width: 168px;
            min-height: 48px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #166534 0%, #15803d 100%);
            color: #ffffff;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 16px 30px rgba(22, 101, 52, 0.24);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        body.employee-dashboard-page .feedback-submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 34px rgba(22, 101, 52, 0.28);
        }

        body.employee-dashboard-page .feedback-submit-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }


        body.employee-dashboard-page {
            background: #f8fafc;
        }

        body.employee-dashboard-page .dashboard-container {
            width: min(calc(100% - 72px), 1480px);
            max-width: none;
            padding: 34px 0 54px;
        }

        body.employee-dashboard-page .content-wrapper {
            display: grid;
            gap: 24px;
        }

        body.employee-dashboard-page .hero-section {
            margin: 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
        }

        body.employee-dashboard-page .hero-copy {
            min-width: 0;
            flex: 1 1 auto;
        }

        body.employee-dashboard-page .hero-action {
            flex: 0 0 auto;
            align-self: flex-start;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 20px;
            border-radius: 14px;
            background: #1B5E20;
            color: #ffffff;
            border: 1px solid rgba(20, 74, 30, 0.28);
            box-shadow: 0 14px 28px rgba(27, 94, 32, 0.18);
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        body.employee-dashboard-page .hero-action:hover {
            background: #166534;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 18px 32px rgba(27, 94, 32, 0.22);
        }

        body.employee-dashboard-page .hero-title {
            margin: 0 0 10px;
            color: #0f5f24;
            font-size: 30px;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: 0;
        }

        body.employee-dashboard-page .hero-dept {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            padding: 5px 12px;
            border-radius: 8px;
            background: #eef2f7;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        body.employee-dashboard-page .company-text {
            color: #64748b;
        }

        body.employee-dashboard-page .hero-subtitle {
            margin: 0;
            color: #64748b;
            font-size: 16px;
        }

        body.employee-dashboard-page .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
            margin: 10px 0 0;
        }

        body.employee-dashboard-page .cards-panel {
            padding: 18px 18px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
        }

        body.employee-dashboard-page .card-filter-row {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin: 0;
            flex-wrap: nowrap;
        }

        body.employee-dashboard-page .card-filter-label {
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        body.employee-dashboard-page .card-filter-dropdown {
            position: relative;
            flex: 0 0 auto;
        }

        body.employee-dashboard-page .card-filter-trigger {
            min-width: 190px;
            height: 40px;
            padding: 0 38px 0 12px;
            border: 1px solid #dbe4ee;
            border-radius: 10px;
            background: #ffffff;
            color: #0f172a;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
        }

        body.employee-dashboard-page .card-filter-trigger-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .card-filter-trigger-icon {
            width: 18px;
            text-align: center;
            color: #166534;
            flex: 0 0 18px;
        }

        body.employee-dashboard-page .card-filter-trigger-caret {
            color: #475569;
            font-size: 12px;
            flex: 0 0 auto;
        }

        body.employee-dashboard-page .card-filter-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 190px;
            padding: 6px;
            border: 1px solid #dbe4ee;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
            z-index: 20;
        }

        body.employee-dashboard-page .card-filter-menu[hidden] {
            display: none;
        }

        body.employee-dashboard-page .card-filter-option {
            width: 100%;
            min-height: 38px;
            padding: 0 10px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
        }

        body.employee-dashboard-page .card-filter-option:hover {
            background: #f8fafc;
        }

        body.employee-dashboard-page .card-filter-option-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .card-filter-option-icon {
            width: 18px;
            text-align: center;
            color: #166534;
            flex: 0 0 18px;
        }

        body.employee-dashboard-page .card-filter-option-check {
            color: #16a34a;
            font-size: 12px;
            opacity: 0;
        }

        body.employee-dashboard-page .card-filter-option.is-active .card-filter-option-check {
            opacity: 1;
        }

        body.employee-dashboard-page .stats-grid[hidden] {
            display: none;
        }

        body.employee-dashboard-page .stat-card {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 168px;
            padding: 18px 20px 16px;
            border: 1px solid #e0e8f2;
            border-radius: 18px;
            background:
                linear-gradient(90deg, var(--stat-accent, #4ade80) 0 7px, #ffffff 7px 100%);
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
        }

        body.employee-dashboard-page .stat-card::after {
            content: none;
        }

        body.employee-dashboard-page .stat-card:hover,
        body.employee-dashboard-page .stat-card:focus-visible {
            border-color: var(--stat-accent, #f4c430);
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.12);
            transform: translateY(-2px);
            outline: none;
        }

        body.employee-dashboard-page .stat-card.total {
            --stat-accent: #22c55e;
            --stat-icon-bg: #e4f5ea;
            --stat-icon-color: #087029;
            --stat-chip-bg: #ecfdf3;
            --stat-chip-color: #11651f;
        }

        body.employee-dashboard-page .stat-card.open {
            --stat-accent: #4fb7ff;
            --stat-icon-bg: #fffde9;
            --stat-icon-color: #f2b500;
            --stat-chip-bg: #edf7ff;
            --stat-chip-color: #2d9bf0;
        }

        body.employee-dashboard-page .stat-card.progress {
            --stat-accent: #9b6bff;
            --stat-icon-bg: #e4f5ea;
            --stat-icon-color: #087029;
            --stat-chip-bg: #f5edff;
            --stat-chip-color: #7c3aed;
        }

        body.employee-dashboard-page .stat-card.resolved {
            --stat-accent: #ffab2e;
            --stat-icon-bg: #e4f5ea;
            --stat-icon-color: #0b6b35;
            --stat-chip-bg: #fff4e8;
            --stat-chip-color: #f97316;
        }

        body.employee-dashboard-page .stat-card.closed {
            --stat-accent: #94a3b8;
            --stat-icon-bg: #eef3f9;
            --stat-icon-color: #475569;
            --stat-chip-bg: #f8fafc;
            --stat-chip-color: #334155;
        }

        body.employee-dashboard-page .stat-main {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            min-width: 0;
        }

        body.employee-dashboard-page .stat-copy {
            min-width: 0;
        }

        body.employee-dashboard-page .stat-icon {
            flex: 0 0 auto;
            width: 52px;
            height: 52px;
            margin-bottom: 0;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--stat-icon-bg, #e4f5ea);
            color: var(--stat-icon-color, #087029);
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.42);
            font-size: 22px;
        }

        body.employee-dashboard-page .stat-label {
            margin: 2px 0 6px;
            color: #081635;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
        }

        body.employee-dashboard-page .stat-value {
            color: #17213d;
            font-size: 40px;
            line-height: 1;
            font-weight: 500;
            letter-spacing: 0;
        }

        body.employee-dashboard-page .stat-subtext {
            margin-top: 12px;
            color: #58708f;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.35;
        }

        body.employee-dashboard-page .stat-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: max-content;
            margin-top: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--stat-chip-bg, #ecfdf3);
            color: var(--stat-chip-color, #11651f);
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            transition: transform 0.16s ease;
        }

        body.employee-dashboard-page .stat-card:hover .stat-action,
        body.employee-dashboard-page .stat-card:focus-visible .stat-action {
            transform: translateX(2px);
        }

        @media (max-width: 1400px) {
            body.employee-dashboard-page .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 992px) {
            body.employee-dashboard-page .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            body.employee-dashboard-page .stat-card {
                min-height: 204px;
            }
        }

        body.employee-dashboard-page .dashboard-ticket-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 22px;
        }

        body.employee-dashboard-page .dashboard-ticket-panel {
            min-width: 0;
            padding: 24px 24px 22px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
        }

        body.employee-dashboard-page .dashboard-ticket-title {
            margin: 0 0 18px;
            color: #111827;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }

        body.employee-dashboard-page .dashboard-ticket-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        body.employee-dashboard-page .dashboard-ticket-table th {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #1B5E20;
            color: #1B5E20;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-align: left;
        }

        body.employee-dashboard-page .dashboard-ticket-table th:first-child {
            border-bottom-left-radius: 8px;
            border-top-left-radius: 8px;
        }

        body.employee-dashboard-page .dashboard-ticket-table th:last-child {
            border-bottom-right-radius: 8px;
            border-top-right-radius: 8px;
        }

        body.employee-dashboard-page .dashboard-ticket-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #edf2f7;
            color: #334155;
            font-size: 13px;
            vertical-align: middle;
        }

        body.employee-dashboard-page .dashboard-ticket-table tr:last-child td {
            border-bottom: 0;
        }

        body.employee-dashboard-page .dashboard-ticket-table tr.ticket-row {
            cursor: pointer;
        }

        body.employee-dashboard-page .dashboard-ticket-table tr.ticket-row:hover td {
            background: #f8fafc;
        }

        body.employee-dashboard-page .sales-manager-table-card {
            padding: 18px 24px 20px;
            overflow: hidden;
            min-height: 0;
            display: flex;
            flex-direction: column;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        body.employee-dashboard-page .sales-manager-table-card .table-responsive {
            margin: 0;
            flex: 1 1 auto;
            min-height: 0;
            overflow-x: auto;
        }

        body.employee-dashboard-page .sales-manager-admin-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }

        body.employee-dashboard-page .sales-manager-admin-table th {
            padding: 16px;
            background: #f8fafc;
            color: #1B5E20;
            border-bottom: 1px solid #1B5E20;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            text-align: left;
        }

        body.employee-dashboard-page .sales-manager-admin-table th:first-child {
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }

        body.employee-dashboard-page .sales-manager-admin-table th:last-child {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        body.employee-dashboard-page .sales-manager-admin-table td {
            padding: 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 14px;
            vertical-align: middle;
        }

        body.employee-dashboard-page .sales-manager-admin-table tr:last-child td {
            border-bottom: none;
        }

        body.employee-dashboard-page .sales-manager-admin-table tr.ticket-row:hover td {
            background-color: #f8fafc;
        }

        body.employee-dashboard-page .sales-manager-admin-table .subject-cell {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        body.employee-dashboard-page .sales-manager-admin-table .user-info strong {
            color: #0f172a;
            font-weight: 700;
        }

        body.employee-dashboard-page .sales-manager-admin-table .user-info small {
            color: #475569;
            font-size: 12px;
        }

        body.employee-dashboard-page .sales-manager-admin-table .task-ticket-sla .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 26px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 400;
            line-height: 1;
            white-space: nowrap;
            box-sizing: border-box;
        }

        body.employee-dashboard-page .sales-manager-admin-table .task-ticket-arrow {
            color: #64748b;
            font-size: 22px;
            line-height: 1;
            text-align: right;
        }

        body.employee-dashboard-page .dashboard-ticket-id {
            width: 116px;
            color: #334155;
            font-weight: 500;
        }

        body.employee-dashboard-page .dashboard-ticket-category {
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
        }

        body.employee-dashboard-page .dashboard-ticket-requester {
            min-width: 230px;
        }

        body.employee-dashboard-page .dashboard-ticket-requester strong {
            display: block;
            color: #172b4d;
            font-size: 14px;
            line-height: 1.25;
        }

        body.employee-dashboard-page .dashboard-ticket-requester small {
            display: block;
            margin-top: 3px;
            color: #0f2f57;
            font-size: 12px;
            line-height: 1.25;
        }

        body.employee-dashboard-page .dashboard-ticket-department {
            min-width: 110px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .dashboard-ticket-table .status-pill {
            font-weight: 400;
        }

        body.employee-dashboard-page .dashboard-ticket-sla {
            width: 120px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .dashboard-ticket-priority,
        body.employee-dashboard-page .dashboard-ticket-priority-header {
            display: none;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 26px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 400;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            box-sizing: border-box;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-low {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-high {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-medium {
            background: #ffedd5;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-critical {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        body.employee-dashboard-page .dashboard-ticket-date {
            width: 150px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .dashboard-ticket-arrow {
            width: 24px;
            color: #64748b;
            font-size: 18px;
            font-weight: 900;
            text-align: right;
        }

        body.employee-dashboard-page .dashboard-ticket-empty {
            padding: 34px 12px;
            color: #94a3b8;
            text-align: center;
            font-weight: 700;
        }


        body.employee-dashboard-page .feedback-modal-dialog.feedback-modal-dialog-success {
            width: min(100%, 430px);
            height: 400px;
            max-height: calc(100vh - 44px);
            border-radius: 12px;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.28);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-header {
            padding: 0 30px;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-success-icon {
            width: 76px;
            height: 76px;
            margin-bottom: 18px;
            border-width: 2px;
            background: #f0fbf3;
            font-size: 36px;
            font-weight: 400;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-title {
            margin-bottom: 10px;
            font-size: 26px;
            line-height: 1.2;
            color: #0f172a;
            font-weight: 600;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-subtitle {
            max-width: 340px;
            margin: 0 auto;
            font-size: 16px;
            line-height: 1.45;
            color: #5b6473;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-title,
        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-subtitle {
            position: relative;
            top: 0;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-body {
            width: calc(100% - 60px);
            margin: 32px 30px 0;
            padding: 18px 0 0;
            display: grid;
            gap: 0;
            overflow: visible;
            max-height: none;
            border-top: 1px solid #d9dee6;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-flash.is-success {
            margin: 0;
            padding: 14px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-align: left;
            font-size: 15px;
            font-weight: 500;
            line-height: 1.45;
        }

        body.employee-dashboard-page .feedback-success-message-icon {
            width: 46px;
            height: 46px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            background: #dcfce7;
            color: var(--brand-green);
            font-size: 24px;
        }

        body.employee-dashboard-page .feedback-success-message-copy strong {
            display: inline;
            color: var(--brand-green);
            font-weight: 800;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-actions {
            justify-content: center;
            width: 100%;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-submit-btn {
            width: 300px;
            min-width: 300px;
            min-height: 48px;
            position: relative;
            top: 12px;
            border-radius: 999px;
            background: #1B5E20;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 14px 28px rgba(27, 94, 32, 0.18);
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-submit-btn:hover {
            background: #144a1a;
        }

        @media (max-width: 768px) {
            body.employee-dashboard-page .feedback-modal-overlay {
                padding: 12px;
            }

            body.employee-dashboard-page .feedback-modal-dialog.feedback-modal-dialog-success {
                width: min(100%, 430px);
                height: min(400px, calc(100vh - 24px));
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-header {
                padding-left: 24px;
                padding-right: 24px;
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-body {
                width: calc(100% - 48px);
                margin-left: 24px;
                margin-right: 24px;
                padding-left: 0;
                padding-right: 0;
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-flash.is-success {
                align-items: flex-start;
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-submit-btn {
                width: 100%;
                min-width: 0;
            }
        }

        @media (max-width: 640px) {
            body.employee-dashboard-page .feedback-modal-dialog.feedback-modal-dialog-success {
                width: min(100%, 390px);
                height: min(390px, calc(100vh - 24px));
            }
        }

        body.employee-dashboard-page .mobile-sidebar,
        body.employee-dashboard-page .mobile-sidebar-overlay {
            display: none;
        }

        @media (max-width: 768px) {
            body.employee-dashboard-page .hero-section {
                flex-direction: column;
                align-items: stretch;
            }

            body.employee-dashboard-page .hero-action {
                width: 100%;
            }

            body.employee-dashboard-page #navbarCollapse,
            body.employee-dashboard-page.sidebar-open #navbarCollapse {
                display: none !important;
            }

            body.employee-dashboard-page.sidebar-open .tm-global-chat-fab {
                opacity: 0;
                pointer-events: none;
                transform: translateY(8px);
            }

            body.employee-dashboard-page .mobile-sidebar {
                position: fixed;
                top: 0;
                right: -260px;
                width: 260px;
                height: 100vh;
                background: #1B5E20;
                padding: 20px;
                transition: right 0.3s ease;
                z-index: 2000;
                display: flex;
                flex-direction: column;
                gap: 18px;
                box-shadow: 12px 0 28px rgba(15, 23, 42, 0.25);
            }

            body.employee-dashboard-page .mobile-sidebar.active {
                right: 0;
            }

            body.employee-dashboard-page .mobile-sidebar-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            body.employee-dashboard-page .mobile-sidebar-header img {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #ffffff;
                padding: 4px;
                object-fit: contain;
                flex: 0 0 36px;
            }

            body.employee-dashboard-page .mobile-sidebar-header span {
                color: #ffffff;
                font-size: 15px;
                font-weight: 700;
                line-height: 1.2;
            }

            body.employee-dashboard-page .mobile-sidebar a {
                color: white;
                text-decoration: none;
                font-size: 16px;
                font-weight: 500;
                min-height: 44px;
                display: flex;
                align-items: center;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-dashboard-page .mobile-sidebar a.active,
            body.employee-dashboard-page .mobile-sidebar a:hover {
                background: rgba(255, 255, 255, 0.12);
            }

            body.employee-dashboard-page .mobile-sidebar-footer {
                margin-top: auto;
                padding-top: 14px;
                border-top: 1px solid rgba(255, 255, 255, 0.18);
                display: flex;
                align-items: center;
                gap: 12px;
            }

            body.employee-dashboard-page .mobile-sidebar-icon-link,
            body.employee-dashboard-page .mobile-sidebar-user-btn {
                min-height: 44px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.28);
                color: #ffffff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
            }

            body.employee-dashboard-page .mobile-sidebar-icon-link {
                width: 44px;
                min-width: 44px;
                position: relative;
            }

            body.employee-dashboard-page .mobile-sidebar-icon-link i,
            body.employee-dashboard-page .mobile-sidebar-user-btn i {
                font-size: 16px;
            }

            body.employee-dashboard-page .mobile-sidebar-badge {
                position: absolute;
                top: -6px;
                right: -4px;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                border-radius: 999px;
                background: #ff4d4f;
                color: #ffffff;
                font-size: 11px;
                font-weight: 800;
                display: none;
                align-items: center;
                justify-content: center;
                line-height: 1;
                border: 2px solid #1B5E20;
            }

            body.employee-dashboard-page .mobile-sidebar-user {
                position: relative;
            }

            body.employee-dashboard-page .mobile-sidebar-user-btn {
                gap: 10px;
                padding: 0 16px;
                cursor: pointer;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu {
                position: absolute;
                right: 0;
                bottom: calc(100% + 10px);
                min-width: 170px;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 16px 30px rgba(15, 23, 42, 0.18);
                padding: 8px;
                display: none;
                flex-direction: column;
                gap: 4px;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu.show {
                display: flex;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu a {
                min-height: 40px;
                color: #0f172a;
                font-size: 14px;
                font-weight: 600;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu a:hover {
                background: #f1f5f9;
            }

            body.employee-dashboard-page .mobile-sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.4);
                opacity: 0;
                visibility: hidden;
                transition: 0.3s;
                z-index: 1500;
                display: block;
            }

            body.employee-dashboard-page .mobile-sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            body.employee-dashboard-page .nav-left,
            body.employee-dashboard-page .navbar-toggler {
                position: relative;
                z-index: 2105;
            }

            body.employee-dashboard-page .tm-global-chat-fab {
                right: 12px;
                bottom: 12px;
                width: 42px !important;
                max-width: 42px !important;
                height: 42px;
                min-height: 42px;
                min-width: 42px;
                padding: 0 !important;
                border-radius: 999px;
                gap: 0;
                flex: 0 0 42px;
                justify-content: center;
                transition: opacity 0.2s ease, transform 0.2s ease, background 0.2s ease;
            }

            body.employee-dashboard-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-dashboard-page .tm-global-chat-fab i {
                font-size: 16px;
            }

            body.employee-dashboard-page .tm-global-chat-fab .chat-badge {
                top: -4px;
                right: -4px;
            }

            body.employee-dashboard-page .dashboard-container {
                width: auto;
                padding: 22px 14px 36px;
            }

            body.employee-dashboard-page .hero-title {
                font-size: 24px;
            }

            body.employee-dashboard-page .stats-grid,
            body.employee-dashboard-page .dashboard-ticket-grid {
                grid-template-columns: 1fr;
            }

            body.employee-dashboard-page .cards-panel {
                padding: 14px;
                border-radius: 16px;
            }

            body.employee-dashboard-page .card-filter-row {
                justify-content: flex-start;
                margin-bottom: 8px;
            }

            body.employee-dashboard-page .stat-card {
                min-height: 148px;
                padding: 14px 16px 12px;
                border-radius: 16px;
            }

            body.employee-dashboard-page .stat-icon {
                width: 46px;
                height: 46px;
                font-size: 20px;
            }

            body.employee-dashboard-page .stat-value {
                font-size: 36px;
            }

            body.employee-dashboard-page .stat-subtext {
                margin-top: 10px;
                font-size: 13px;
            }

            body.employee-dashboard-page .dashboard-ticket-panel {
                padding: 18px;
                overflow-x: auto;
            }

            body.employee-dashboard-page .dashboard-ticket-table {
                min-width: 1040px;
            }

            body.employee-dashboard-page .recent-section .table-responsive table thead {
                display: none;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row {
                display: grid;
                grid-template-columns: 1fr;
                grid-template-areas:
                    "id"
                    "category"
                    "title"
                    "date"
                    "arrow";
                gap: 1px;
                padding: 8px;
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.04);
                border: 1px solid #dbe4ee;
                cursor: pointer;
                transition: all 0.2s ease;
                min-height: 104px;
                align-content: start;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row:hover {
                border-color: #1B5E20;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row:active {
                transform: scale(0.98);
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row td {
                display: block;
                padding: 0;
                border: none;
                text-align: left;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row td::before {
                display: none;
            }

            body.employee-dashboard-page .recent-ticket-id {
                grid-area: id;
                font-size: 10px;
                font-weight: 700;
                color: #0f172a;
            }

            body.employee-dashboard-page .recent-ticket-status {
                display: none;
            }

            body.employee-dashboard-page .recent-ticket-category {
                grid-area: category;
                font-size: 10px;
                color: #6b7280;
                font-weight: 600;
            }

            body.employee-dashboard-page .recent-ticket-title {
                grid-area: title;
                font-size: 10px;
                color: #1f2937;
                line-height: 1.15;
            }

            body.employee-dashboard-page .recent-ticket-date {
                grid-area: date;
                font-size: 9px;
                color: #9ca3af;
                margin-top: 1px;
            }

            body.employee-dashboard-page .recent-ticket-arrow {
                display: block;
                grid-area: arrow;
                justify-self: end;
                align-self: end;
                font-size: 18px;
                font-weight: 700;
                color: #64748b;
                line-height: 1;
            }

            body.employee-dashboard-page .recent-ticket-status .status-pill {
                padding: 1px 6px;
                border-radius: 999px;
                font-size: 9px;
                font-weight: 700;
                white-space: nowrap;
            }

            body.employee-dashboard-page .recent-mobile-pagination {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                margin-top: 8px;
            }

            body.employee-dashboard-page .recent-mobile-page-btn {
                min-width: 32px;
                height: 32px;
                padding: 0 8px;
                border: 1px solid #dbe4ee;
                border-radius: 999px;
                background: #ffffff;
                color: #475569;
                font-size: 11px;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }

            body.employee-dashboard-page .recent-mobile-page-btn.is-active {
                background: #1B5E20;
                border-color: #1B5E20;
                color: #ffffff;
            }

            body.employee-dashboard-page .recent-mobile-page-btn:disabled {
                opacity: 0.45;
                cursor: default;
            }
        }

        @media (max-width: 768px) {
            body.employee-dashboard-page {
                background: #f5f8fb;
            }

            body.employee-dashboard-page .dashboard-container {
                padding: 18px 12px 84px;
            }

            body.employee-dashboard-page .content-wrapper {
                gap: 16px;
            }

            body.employee-dashboard-page .hero-section {
                gap: 14px;
                padding: 2px 0 0;
            }

            body.employee-dashboard-page .hero-title {
                margin-bottom: 8px;
                font-size: 22px;
                line-height: 1.18;
            }

            body.employee-dashboard-page .hero-dept {
                margin-bottom: 10px;
                padding: 5px 10px;
                border-radius: 8px;
                font-size: 11px;
                letter-spacing: 0.08em;
                max-width: 100%;
                flex-wrap: wrap;
            }

            body.employee-dashboard-page .hero-subtitle {
                font-size: 14px;
                line-height: 1.45;
            }

            body.employee-dashboard-page .hero-action {
                min-height: 46px;
                border-radius: 14px;
                font-size: 15px;
            }

            body.employee-dashboard-page .cards-panel {
                padding: 12px;
                border-radius: 14px;
                box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
            }

            body.employee-dashboard-page .card-filter-row {
                display: grid;
                grid-template-columns: 1fr;
                gap: 8px;
                margin-bottom: 10px;
            }

            body.employee-dashboard-page .card-filter-label {
                font-size: 13px;
            }

            body.employee-dashboard-page .card-filter-trigger {
                width: 100%;
                min-height: 42px;
                justify-content: space-between;
                padding: 0 12px;
                border-radius: 12px;
                font-size: 13px;
            }

            body.employee-dashboard-page .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-top: 0;
            }

            body.employee-dashboard-page .stat-card {
                min-height: 136px;
                padding: 12px 12px 11px 15px;
                border-radius: 14px;
                background:
                    linear-gradient(90deg, var(--stat-accent, #4ade80) 0 5px, #ffffff 5px 100%);
            }

            body.employee-dashboard-page .stat-main {
                align-items: center;
                gap: 10px;
            }

            body.employee-dashboard-page .stat-icon {
                width: 38px;
                height: 38px;
                border-radius: 12px;
                font-size: 17px;
                flex: 0 0 38px;
            }

            body.employee-dashboard-page .stat-label {
                margin: 0 0 3px;
                font-size: 12px;
                line-height: 1.15;
            }

            body.employee-dashboard-page .stat-value {
                font-size: 30px;
            }

            body.employee-dashboard-page .stat-subtext {
                margin-top: 8px;
                font-size: 11px;
                line-height: 1.25;
            }

            body.employee-dashboard-page .stat-action {
                margin-top: 8px;
                padding: 6px 9px;
                font-size: 11px;
                gap: 6px;
            }

            body.employee-dashboard-page .dashboard-ticket-grid {
                gap: 14px;
            }

            body.employee-dashboard-page .dashboard-ticket-panel {
                padding: 18px 14px 20px;
                border: 0;
                border-radius: 18px;
                overflow: visible;
                background: #ffffff;
                box-shadow: 0 12px 26px rgba(15, 23, 42, 0.06);
            }

            body.employee-dashboard-page .dashboard-ticket-title {
                margin-bottom: 14px;
                color: #0f172a;
                font-size: 20px;
                font-weight: 900;
            }

            body.employee-dashboard-page .dashboard-ticket-table,
            body.employee-dashboard-page .dashboard-ticket-table tbody,
            body.employee-dashboard-page .dashboard-ticket-table tr,
            body.employee-dashboard-page .dashboard-ticket-table td {
                display: block;
                width: 100%;
                min-width: 0;
            }

            body.employee-dashboard-page .dashboard-ticket-table {
                min-width: 0;
                border-collapse: separate;
                border-spacing: 0;
            }

            body.employee-dashboard-page .dashboard-ticket-table thead {
                display: none;
            }

            body.employee-dashboard-page .dashboard-ticket-table tbody {
                display: grid;
                gap: 16px;
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-row {
                position: relative;
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr)) 28px;
                grid-template-areas:
                    "id id id arrow"
                    "category category category arrow"
                    "requester requester requester arrow"
                    "date date date arrow"
                    "department department department arrow"
                    "priority status sla arrow";
                gap: 8px 10px;
                min-height: 204px;
                padding: 22px 18px 18px;
                border: 1px solid #d9e2ec;
                border-radius: 22px;
                background: #ffffff;
                box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
                overflow: hidden;
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-row:hover td {
                background: transparent;
            }

            body.employee-dashboard-page .dashboard-ticket-table td {
                padding: 0;
                border: 0;
                min-width: 0;
                font-size: 14px;
                line-height: 1.25;
            }

            body.employee-dashboard-page .dashboard-ticket-id {
                grid-area: id;
                width: auto;
                color: #0f172a;
                font-size: 17px;
                font-weight: 950;
                letter-spacing: 0;
            }

            body.employee-dashboard-page .dashboard-ticket-category {
                grid-area: category;
                max-width: none;
                margin-top: 4px;
                color: #687386;
                font-size: 17px;
                font-weight: 900;
                line-height: 1.18;
                white-space: normal;
                overflow: visible;
                text-overflow: clip;
            }

            body.employee-dashboard-page .dashboard-ticket-requester {
                grid-area: requester;
                min-width: 0;
                margin-top: 2px;
            }

            body.employee-dashboard-page .dashboard-ticket-requester strong {
                color: #111827;
                font-size: 18px;
                font-weight: 950;
                line-height: 1.2;
            }

            body.employee-dashboard-page .dashboard-ticket-requester small {
                display: block;
                margin-top: 22px;
                max-width: 100%;
                color: #566276;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.3;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-ticket-department {
                grid-area: department;
                min-width: 0;
                margin-top: 2px;
                color: #334155;
                font-size: 15px;
                font-weight: 900;
                line-height: 1.25;
                white-space: normal;
            }

            body.employee-dashboard-page .dashboard-ticket-priority {
                grid-area: priority;
                display: block;
                align-self: end;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-priority-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                min-height: 46px;
                padding: 10px 14px;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 900;
                line-height: 1.1;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-priority-low {
                background: #ecfdf3;
                color: #166534;
                border: 1px solid #bbf7d0;
            }

            body.employee-dashboard-page .dashboard-priority-medium {
                background: #fff7d1;
                color: #854d0e;
                border: 1px solid #fde68a;
            }

            body.employee-dashboard-page .dashboard-priority-high,
            body.employee-dashboard-page .dashboard-priority-critical {
                background: #fff1f2;
                color: #b91c1c;
                border: 1px solid #ffe4e6;
            }

            body.employee-dashboard-page .dashboard-ticket-table td:nth-child(6) {
                grid-area: status;
                justify-self: stretch;
                align-self: end;
            }

            body.employee-dashboard-page .dashboard-ticket-table .status-pill {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                min-height: 46px;
                padding: 10px 14px;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 900;
                line-height: 1.1;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-ticket-sla {
                grid-area: sla;
                width: auto;
                align-self: end;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-ticket-sla .badge {
                width: 100%;
                min-height: 46px;
                padding: 10px 14px;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 900;
                line-height: 1.1;
            }

            body.employee-dashboard-page .dashboard-ticket-date {
                grid-area: date;
                width: auto;
                justify-self: start;
                color: #9aa3b2;
                font-size: 14px;
                font-weight: 900;
                line-height: 1.25;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-ticket-arrow {
                grid-area: arrow;
                width: 28px;
                justify-self: center;
                align-self: start;
                padding-top: 58px;
                color: #64748b;
                font-size: 38px;
                font-weight: 950;
                line-height: 1;
            }

            body.employee-dashboard-page .dashboard-ticket-empty {
                padding: 18px 12px;
                border: 1px dashed #cbd5e1;
                border-radius: 12px;
                font-size: 13px;
                text-align: center;
            }
        }

        body.employee-dashboard-page {
            font-size: var(--text-base);
            -webkit-font-smoothing: antialiased;
        }

        body.employee-dashboard-page .dashboard-container {
            box-sizing: border-box;
            max-width: 720px;
            margin: 0 auto;
        }

        body.employee-dashboard-page .ticket-card {
            box-sizing: border-box;
        }

        body.employee-dashboard-page .ticket-card__meta,
        body.employee-dashboard-page .ticket-card__body,
        body.employee-dashboard-page .ticket-card__badges {
            min-width: 0;
        }

        @media (max-width: 768px) {
            body.employee-dashboard-page {
                overflow-x: hidden;
            }

            body.employee-dashboard-page .navbar {
                position: sticky;
                top: 0;
                z-index: 1200;
                min-height: 60px;
                padding: 8px 14px !important;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            }

            body.employee-dashboard-page .navbar .nav-left {
                grid-template-columns: 40px minmax(0, 1fr) 44px !important;
                gap: var(--space-sm) !important;
            }

            body.employee-dashboard-page .navbar .logo-icon {
                width: 40px !important;
                height: 40px !important;
                min-width: 40px !important;
            }

            body.employee-dashboard-page .navbar .brand-name {
                font-size: 15px !important;
                line-height: 1.15 !important;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            body.employee-dashboard-page .navbar .navbar-toggler {
                width: 44px !important;
                height: 44px !important;
                min-width: 44px !important;
                min-height: 44px !important;
                border-radius: var(--radius-md) !important;
            }

            body.employee-dashboard-page .dashboard-container {
                width: 100%;
                max-width: 720px;
                padding: var(--space-md) 16px calc(92px + env(safe-area-inset-bottom, 0px));
            }

            body.employee-dashboard-page .content-wrapper {
                gap: var(--space-md);
            }

            body.employee-dashboard-page .hero-section {
                gap: var(--space-sm);
                padding-top: 0;
            }

            body.employee-dashboard-page .hero-title {
                margin-bottom: var(--space-xs);
                font-size: 21px;
                line-height: 1.2;
            }

            body.employee-dashboard-page .hero-subtitle {
                font-size: 15px;
            }

            body.employee-dashboard-page .create-ticket-btn {
                display: inline-flex;
                width: 100%;
                min-height: 52px;
                height: 52px;
                padding: 0 16px;
                border-radius: var(--radius-md);
                background: var(--brand-green);
                color: #ffffff;
                font-size: 16px;
                font-weight: 700;
                text-align: center;
                box-shadow: 0 10px 22px rgba(25, 105, 42, 0.22);
            }

            body.employee-dashboard-page .cards-panel,
            body.employee-dashboard-page .dashboard-ticket-panel {
                padding: var(--space-md);
                border-radius: var(--radius-md);
                box-shadow: 0 6px 18px rgba(10, 10, 20, 0.06);
            }

            body.employee-dashboard-page .stats-grid,
            body.employee-dashboard-page .dashboard-ticket-grid {
                grid-template-columns: 1fr;
                align-items: stretch;
                gap: var(--space-md);
            }

            body.employee-dashboard-page .stat-card {
                min-height: 148px;
                height: 100%;
                padding: var(--space-md);
                border-radius: var(--radius-md);
                justify-content: space-between;
            }

            body.employee-dashboard-page .dashboard-ticket-table tbody {
                display: grid;
                grid-template-columns: 1fr;
                align-items: stretch;
                gap: var(--space-md);
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-card {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr)) 32px;
                grid-template-areas:
                    "id id id arrow"
                    "title title title arrow"
                    "requester requester requester arrow"
                    "department department department arrow"
                    "date date date arrow"
                    "priority status sla arrow";
                align-content: stretch;
                gap: var(--space-xs) var(--space-sm);
                width: 100%;
                min-height: 224px;
                height: 100%;
                margin: 0;
                padding: var(--space-md);
                border: 1px solid #dce6ef;
                border-radius: var(--radius-md);
                background: #ffffff;
                box-shadow: 0 6px 18px rgba(10, 10, 20, 0.06);
                overflow: hidden;
            }

            body.employee-dashboard-page .dashboard-ticket-id {
                grid-area: id;
                color: #0f172a;
                font-size: 14px;
                font-weight: 800;
            }

            body.employee-dashboard-page .dashboard-ticket-category {
                grid-area: title;
                margin: 0;
                color: #102033;
                font-size: 18px;
                font-weight: 800;
                line-height: 1.22;
                white-space: normal;
            }

            body.employee-dashboard-page .dashboard-ticket-requester {
                grid-area: requester;
                margin: var(--space-xs) 0 0;
            }

            body.employee-dashboard-page .dashboard-ticket-requester strong {
                font-size: 16px;
                line-height: 1.25;
            }

            body.employee-dashboard-page .dashboard-ticket-requester small {
                margin-top: var(--space-xs);
                color: #4b5563;
                font-size: 14px;
                line-height: 1.3;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page .dashboard-ticket-department {
                grid-area: department;
                margin: 0;
                color: #334155;
                font-size: 14px;
                font-weight: 700;
                line-height: 1.35;
            }

            body.employee-dashboard-page .dashboard-ticket-date {
                grid-area: date;
                margin-top: var(--space-xs);
                color: #64748b;
                font-size: 14px;
                font-weight: 700;
            }

            body.employee-dashboard-page .dashboard-ticket-priority,
            body.employee-dashboard-page .dashboard-ticket-status,
            body.employee-dashboard-page .dashboard-ticket-sla {
                display: flex;
                align-self: end;
                width: 100%;
                margin-top: var(--space-sm);
            }

            body.employee-dashboard-page .dashboard-ticket-priority {
                grid-area: priority;
            }

            body.employee-dashboard-page .dashboard-ticket-status {
                grid-area: status;
            }

            body.employee-dashboard-page .dashboard-ticket-sla {
                grid-area: sla;
            }

            body.employee-dashboard-page .dashboard-priority-badge,
            body.employee-dashboard-page .dashboard-ticket-table .status-pill,
            body.employee-dashboard-page .dashboard-ticket-sla .badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                height: 36px;
                min-height: 36px;
                padding: 0 12px;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 700;
                line-height: 1;
                text-align: center;
                white-space: nowrap;
            }

            body.employee-dashboard-page .dashboard-priority-low,
            body.employee-dashboard-page .status-in-progress {
                background: #e6f8ee;
                color: #19692a;
                border-color: #b7e6c7;
            }

            body.employee-dashboard-page .status-open,
            body.employee-dashboard-page .dashboard-ticket-sla .badge-low {
                background: #fff6dd;
                color: #765000;
                border-color: #f2d278;
            }

            body.employee-dashboard-page .dashboard-priority-medium,
            body.employee-dashboard-page .dashboard-ticket-sla .badge-medium {
                background: #fff4d6;
                color: #855400;
                border-color: #efc66d;
            }

            body.employee-dashboard-page .dashboard-priority-high,
            body.employee-dashboard-page .dashboard-priority-critical,
            body.employee-dashboard-page .dashboard-ticket-sla .badge-high,
            body.employee-dashboard-page .dashboard-ticket-sla .badge-critical {
                background: #ffe7d9;
                color: #9a3412;
                border-color: #fdba74;
            }

            body.employee-dashboard-page .status-resolved {
                background: #e0efff;
                color: #174ea6;
                border-color: #b7d4ff;
            }

            body.employee-dashboard-page .status-closed {
                background: #eef2f7;
                color: #334155;
                border-color: #d7dee8;
            }

            body.employee-dashboard-page .dashboard-ticket-arrow {
                grid-area: arrow;
                justify-self: end;
                align-self: center;
                width: 32px;
                padding: 0;
                color: #64748b;
                font-size: 34px;
                line-height: 1;
            }

            body.employee-dashboard-page .tm-global-chat-fab {
                right: 12px;
                bottom: calc(12px + env(safe-area-inset-bottom, 0px));
                width: 56px !important;
                max-width: 56px !important;
                height: 56px !important;
                min-width: 56px !important;
                min-height: 56px !important;
                border-radius: 50%;
                background: var(--brand-green) !important;
                color: #ffffff;
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
                z-index: 9999;
            }

            body.employee-dashboard-page .tm-global-chat-fab i {
                font-size: 19px;
            }
        }

        @media (min-width: 480px) and (max-width: 768px) {
            body.employee-dashboard-page .dashboard-container {
                padding-inline: 18px;
            }

            body.employee-dashboard-page .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 20px;
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-card {
                min-height: 212px;
            }

            body.employee-dashboard-page .dashboard-ticket-category {
                font-size: 18px;
            }
        }

        /* Match the mobile ticket-card UI from employee/my_task.php. */
        @media (max-width: 767px) {
            body.employee-dashboard-page .dashboard-ticket-grid,
            body.employee-dashboard-page .dashboard-ticket-panel,
            body.employee-dashboard-page .sales-manager-table-card,
            body.employee-dashboard-page .dashboard-ticket-panel .table-responsive,
            body.employee-dashboard-page .sales-manager-table-card .table-responsive {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            body.employee-dashboard-page .dashboard-ticket-panel,
            body.employee-dashboard-page .sales-manager-table-card {
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                overflow: visible;
            }

            body.employee-dashboard-page .dashboard-ticket-panel .table-responsive,
            body.employee-dashboard-page .sales-manager-table-card .table-responsive {
                margin: 0;
                overflow: visible;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table),
            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody {
                display: block;
                width: 100%;
                max-width: 100%;
                min-width: 0;
                margin: 0;
                border-collapse: separate;
                border-spacing: 0;
                box-sizing: border-box;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) thead {
                display: none;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr.ticket-row {
                position: relative;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 7px 8px;
                width: 100%;
                max-width: 100%;
                min-width: 0;
                min-height: 0;
                height: auto;
                margin: 0;
                padding: clamp(13px, 4vw, 17px);
                padding-right: clamp(42px, 12vw, 52px);
                border: 1px solid #dde5ed;
                border-radius: 14px;
                background: #ffffff;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
                overflow: hidden;
                box-sizing: border-box;
                cursor: pointer;
                transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr.ticket-row:hover {
                border-color: #1B5E20;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr.ticket-row:hover td {
                background: transparent;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr.ticket-row:active {
                transform: scale(0.98);
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr.ticket-row td {
                display: block;
                width: auto;
                min-width: 0;
                padding: 0;
                border: 0;
                text-align: left;
                box-sizing: border-box;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-id, .task-ticket-id) {
                order: 1;
                flex: 0 0 100%;
                width: 100%;
                color: #0f172a;
                font-size: clamp(12px, 3.4vw, 14px);
                font-weight: 800;
                line-height: 1.2;
                font-variant-numeric: tabular-nums;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-category, .task-ticket-category) {
                order: 2;
                flex: 0 0 100%;
                width: 100%;
                max-width: 100%;
                margin: 0;
                color: #1f2937;
                font-size: clamp(13px, 3.6vw, 15px);
                font-weight: 700;
                line-height: 1.25;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) {
                order: 3;
                flex: 0 0 100%;
                width: 100%;
                min-width: 0;
                margin: 2px 0 0;
                color: #1f2937;
                line-height: 1.3;
                overflow: hidden;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) .user-info,
            body.employee-dashboard-page .dashboard-ticket-requester {
                min-width: 0;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) .user-info {
                display: grid;
                gap: 2px;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) br {
                display: none;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) strong,
            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) small {
                display: block;
                max-width: 100%;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) strong {
                color: #111827;
                font-size: clamp(13px, 3.7vw, 15px);
                font-weight: 700;
                line-height: 1.25;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-requester, .task-ticket-requester) small {
                margin-top: 0;
                color: #475569;
                font-size: clamp(11px, 3.1vw, 12px);
                font-weight: 500;
                line-height: 1.3;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-date, .task-ticket-date) {
                order: 4;
                flex: 1 1 calc(50% - 4px);
                width: calc(50% - 4px);
                margin: 2px 0 0;
                color: #64748b;
                font-size: clamp(10px, 2.9vw, 12px);
                font-weight: 600;
                line-height: 1.2;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-department, .task-ticket-department) {
                order: 5;
                flex: 1 1 calc(50% - 4px);
                width: calc(50% - 4px);
                min-width: 0;
                margin: 2px 0 0;
                color: #475569;
                font-size: clamp(10px, 2.9vw, 12px);
                font-weight: 600;
                line-height: 1.25;
                text-align: right;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page :is(
                .dashboard-ticket-priority,
                .dashboard-ticket-status,
                .dashboard-ticket-sla,
                .task-ticket-urgency,
                .task-ticket-status,
                .task-ticket-sla
            ) {
                order: 6;
                flex: 0 1 auto;
                display: inline-flex;
                align-items: center;
                align-self: center;
                width: max-content;
                max-width: 100%;
                margin: 3px 0 0;
                box-sizing: border-box;
            }

            body.employee-dashboard-page :is(
                .dashboard-priority-badge,
                .priority-pill,
                .dashboard-ticket-table .status-pill,
                .sales-manager-admin-table .status-pill,
                .dashboard-ticket-sla .badge,
                .task-ticket-sla .badge
            ) {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: auto;
                min-width: 0;
                min-height: 27px;
                height: auto;
                max-width: 100%;
                padding: 5px clamp(9px, 2.8vw, 12px);
                border-radius: 999px;
                font-size: clamp(10px, 2.9vw, 12px);
                font-weight: 700;
                line-height: 1;
                text-align: center;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                box-sizing: border-box;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-sla, .task-ticket-sla) .badge-low {
                background: #f1f5f9;
                color: #334155;
                border-color: #e2e8f0;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-arrow, .task-ticket-arrow) {
                position: absolute;
                top: 50%;
                right: clamp(14px, 4vw, 18px);
                transform: translateY(-50%);
                display: block;
                width: auto;
                padding: 0;
                color: #64748b;
                font-size: 25px;
                font-weight: 700;
                line-height: 1;
                text-align: center;
            }

            body.employee-dashboard-page .dashboard-ticket-empty,
            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr:not(.ticket-row) td {
                display: block;
                width: 100%;
                max-width: 100%;
                padding: 20px 12px;
                box-sizing: border-box;
                text-align: center;
            }

        }

        @media (max-width: 359px) {
            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody {
                gap: 10px;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-table, .sales-manager-admin-table) tbody tr.ticket-row {
                gap: 6px 7px;
                padding: 12px 38px 12px 12px;
                border-radius: 12px;
            }

            body.employee-dashboard-page :is(
                .dashboard-priority-badge,
                .priority-pill,
                .dashboard-ticket-table .status-pill,
                .sales-manager-admin-table .status-pill,
                .dashboard-ticket-sla .badge,
                .task-ticket-sla .badge
            ) {
                min-height: 25px;
                padding: 4px 8px;
            }

            body.employee-dashboard-page :is(.dashboard-ticket-arrow, .task-ticket-arrow) {
                right: 12px;
                font-size: 22px;
            }
        }

        @media (min-width: 769px) {
            body.employee-dashboard-page .dashboard-container {
                width: min(calc(100% - 72px), 1480px);
                max-width: none;
            }

            body.employee-dashboard-page .stats-grid,
            body.employee-dashboard-page .dashboard-ticket-grid {
                align-items: stretch;
            }
        }
    </style>
    <link rel="stylesheet" href="../css/dashboard-carousel.css?v=<?= (int) filemtime(__DIR__ . '/../css/dashboard-carousel.css') ?>">
    <style id="dashboardMobileTicketCardParity">
        /* Keep this after dashboard-carousel.css so dashboard tickets match my_task.php on mobile. */
        @media (max-width: 767px) {
            body.employee-dashboard-page .dashboard-ticket-grid {
                gap: 14px;
                width: auto;
                margin-inline: clamp(10px, 4vw, 22px);
            }

            body.employee-dashboard-page .dashboard-ticket-panel {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                overflow: visible;
            }

            body.employee-dashboard-page .dashboard-ticket-table,
            body.employee-dashboard-page .dashboard-ticket-table tbody {
                display: block;
                width: 100%;
                max-width: 100%;
                min-width: 0;
                margin: 0;
            }

            body.employee-dashboard-page .dashboard-ticket-table tbody {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-card {
                position: relative;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 5px 7px;
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0;
                min-height: 0;
                height: auto;
                margin: 0;
                padding: clamp(11px, 3.2vw, 14px);
                padding-right: clamp(38px, 10vw, 46px);
                border: 1px solid #dde5ed;
                border-radius: 14px;
                background: #ffffff;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
                overflow: hidden;
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-card td {
                display: block;
                width: auto;
                min-width: 0;
                padding: 0;
                border: 0;
                text-align: left;
            }

            body.employee-dashboard-page .dashboard-ticket-id {
                order: 1;
                flex: 0 0 100%;
                width: 100%;
                color: #0f172a;
                font-size: 12px;
                font-weight: 800;
                line-height: 1.2;
            }

            body.employee-dashboard-page .dashboard-ticket-category {
                order: 2;
                flex: 0 0 100%;
                width: 100%;
                max-width: 100%;
                margin: 0;
                color: #1f2937;
                font-size: 13px;
                font-weight: 700;
                line-height: 1.25;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page .dashboard-ticket-requester {
                order: 3;
                flex: 0 0 100%;
                width: 100%;
                margin: 2px 0 0;
                color: #1f2937;
                line-height: 1.3;
                overflow: hidden;
            }

            body.employee-dashboard-page .dashboard-ticket-requester strong,
            body.employee-dashboard-page .dashboard-ticket-requester small {
                display: block;
                max-width: 100%;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page .dashboard-ticket-requester strong {
                color: #111827;
                font-size: 13px;
                font-weight: 700;
                line-height: 1.25;
            }

            body.employee-dashboard-page .dashboard-ticket-requester small {
                margin-top: 2px;
                color: #475569;
                font-size: 11px;
                font-weight: 500;
                line-height: 1.3;
            }

            body.employee-dashboard-page .dashboard-ticket-date {
                order: 4;
                flex: 1 1 calc(50% - 4px);
                width: calc(50% - 4px);
                margin: 2px 0 0;
                color: #64748b;
                font-size: 10px;
                font-weight: 600;
                line-height: 1.2;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page .dashboard-ticket-department {
                order: 5;
                flex: 1 1 calc(50% - 4px);
                width: calc(50% - 4px);
                margin: 2px 0 0;
                color: #475569;
                font-size: 10px;
                font-weight: 600;
                line-height: 1.25;
                text-align: right;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page .dashboard-ticket-priority,
            body.employee-dashboard-page .dashboard-ticket-status,
            body.employee-dashboard-page .dashboard-ticket-sla {
                order: 6;
                flex: 0 1 auto;
                display: inline-flex;
                align-items: center;
                align-self: center;
                justify-self: auto;
                width: max-content;
                max-width: 100%;
                margin: 3px 0 0;
            }

            body.employee-dashboard-page .dashboard-priority-badge,
            body.employee-dashboard-page .dashboard-ticket-table .status-pill,
            body.employee-dashboard-page .dashboard-ticket-sla .badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: auto;
                min-width: 0;
                min-height: 24px;
                height: auto;
                max-width: 100%;
                padding: 4px 9px;
                border-radius: 999px;
                font-size: 10px;
                line-height: 1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-dashboard-page .dashboard-priority-badge,
            body.employee-dashboard-page .dashboard-ticket-table .status-pill {
                font-weight: 600;
            }

            body.employee-dashboard-page .dashboard-ticket-sla .badge {
                font-weight: 400;
            }

            body.employee-dashboard-page .dashboard-priority-low {
                color: #166534;
                background: #f0fdf4;
                border-color: transparent;
            }

            body.employee-dashboard-page .dashboard-priority-medium {
                color: #ca8a04;
                background: #fefce8;
                border-color: transparent;
            }

            body.employee-dashboard-page .dashboard-priority-high {
                color: #dc2626;
                background: #fef2f2;
                border-color: transparent;
            }

            body.employee-dashboard-page .dashboard-priority-critical {
                color: #ffffff;
                background: #991b1b;
                border-color: transparent;
            }

            body.employee-dashboard-page .dashboard-ticket-table .status-open {
                color: #5f5400;
                background: #fff2b3;
                border-color: #f8e58c;
            }

            body.employee-dashboard-page .dashboard-ticket-table .status-in-progress {
                color: #166534;
                background: #dcfce7;
                border-color: #bbf7d0;
            }

            body.employee-dashboard-page .dashboard-ticket-table .status-resolved {
                color: #1d4ed8;
                background: #dbeafe;
                border-color: #bfdbfe;
            }

            body.employee-dashboard-page .dashboard-ticket-table .status-closed {
                color: #4b5563;
                background: #f3f4f6;
                border-color: #e5e7eb;
            }

            body.employee-dashboard-page .dashboard-ticket-arrow {
                position: absolute;
                top: 50%;
                right: clamp(12px, 3.5vw, 16px);
                transform: translateY(-50%);
                display: block;
                align-self: center;
                width: auto;
                margin: 0;
                padding: 0;
                color: #64748b;
                font-size: 22px;
                font-weight: 700;
                line-height: 1;
            }

            body.employee-dashboard-page .mobile-divider {
                width: auto;
                margin-inline: clamp(10px, 4vw, 22px);
                padding-inline: 0;
            }

            body.employee-dashboard-page .dashboard-ticket-grid > .mobile-divider {
                margin-inline: 0;
            }

            body.employee-dashboard-page .mobile-divider__inner {
                gap: 8px;
                padding: 8px 12px;
            }

            body.employee-dashboard-page .mobile-divider__icon {
                flex-basis: 28px;
                width: 28px;
                height: 28px;
                font-size: 13px;
            }

            body.employee-dashboard-page .mobile-divider__title {
                font-size: 14px;
            }

            body.employee-dashboard-page .tm-global-chat-fab {
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px !important;
                height: 42px !important;
                min-height: 42px !important;
                padding: 0 !important;
            }

            body.employee-dashboard-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-dashboard-page .tm-global-chat-fab i {
                font-size: 16px;
            }
        }

        @media (max-width: 359px) {
            body.employee-dashboard-page .dashboard-ticket-table tbody {
                gap: 10px;
            }

            body.employee-dashboard-page .dashboard-ticket-table tr.ticket-card {
                gap: 5px 6px;
                padding: 10px 34px 10px 10px;
                border-radius: 12px;
            }

            body.employee-dashboard-page .dashboard-ticket-arrow {
                right: 12px;
                font-size: 22px;
            }
        }
    </style>
</head>
<body class="employee-dashboard-page">

    <!-- 2ï¸âƒ£ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div id="mobileSidebar" class="mobile-sidebar" aria-hidden="true">
        <div class="mobile-sidebar-header">
            <img src="../assets/img/UPDATEDlogo.png" alt="Logo">
            <span>Leads Agri</span>
        </div>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="request_ticket.php">Create Ticket</a>
        <a href="my_task.php">Assigned Tickets</a>
        <a href="my_tickets.php">My Submitted Tickets</a>
        <a href="feedback.php">Feedback</a>
        <a href="knowledge_base.php">Knowledge Base</a>
        <div class="mobile-sidebar-footer">
            <a href="notifications.php" class="mobile-sidebar-icon-link" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span id="mobileSidebarNotifBadge" class="mobile-sidebar-badge"></span>
            </a>
            <div class="mobile-sidebar-user">
                <button type="button" id="mobileSidebarUserBtn" class="mobile-sidebar-user-btn" aria-label="Account menu">
                    <i class="fas fa-user"></i>
                    <i class="fas fa-chevron-down" style="font-size: 11px;"></i>
                </button>
                <div id="mobileSidebarUserMenu" class="mobile-sidebar-user-menu">
                    <a href="my_profile.php">My Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div id="mobileSidebarOverlay" class="mobile-sidebar-overlay" aria-hidden="true"></div>
    <?php if ($showFeedbackSuccessModal): ?>
    <div
        id="feedbackModalOverlay"
        class="feedback-modal-overlay is-visible"
        aria-hidden="false"
    >
        <div class="feedback-modal-dialog feedback-modal-dialog-success" role="dialog" aria-modal="true" aria-labelledby="feedbackModalTitle">
            <div class="feedback-modal-header">
                <div class="feedback-modal-success-icon" aria-hidden="true">&#10003;</div>
                <h2 id="feedbackModalTitle" class="feedback-modal-title">Feedback Submitted</h2>
                <p class="feedback-modal-subtitle">Your feedback has been submitted.<br>Thank you for sharing your support experience.</p>
            </div>
            <div class="feedback-modal-body">
                <div class="feedback-actions">
                    <button type="button" class="feedback-submit-btn" id="feedbackModalDismissBtn">Done</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

                        <!-- 3ï¸âƒ£ HERO SECTION -->
            <div class="hero-section">
                <div class="hero-copy">
                    <h1 class="hero-title">Welcome back, <?= $isSalesManagerView ? 'Manager ' : ''; ?><?= htmlspecialchars($_SESSION['name']); ?></h1>
                    <div class="hero-dept">
                        <?php
                            $heroDepartment = trim((string) ($_SESSION['department'] ?? ''));
                            $heroCompanyLabel = ticket_company_display_name((string) $company);
                            $heroRegion = trim((string) ($user_region ?? ($_SESSION['region'] ?? '')));
                            $heroIsLapcSales = ticket_normalize_company((string) $company) === '@leadsagri.com'
                                && strcasecmp($heroDepartment, 'Sales') === 0
                                && $heroRegion !== '';
                        ?>
                        <?php if ($heroDepartment !== ''): ?>
                            <?= htmlspecialchars($heroIsLapcSales ? $heroRegion : ($heroDepartment . ' Department')); ?>
                            <?php if ($heroCompanyLabel !== ''): ?>
                                <span class="company-text">&bull; <?= htmlspecialchars($heroCompanyLabel); ?></span>
                            <?php endif; ?>
                        <?php elseif ($heroCompanyLabel !== ''): ?>
                            <?= htmlspecialchars($heroCompanyLabel); ?>
                        <?php endif; ?>
                    </div>
                    <p class="hero-subtitle"><?= $isSalesManagerView ? 'View all ticket submissions from your assigned sales region.' : "Here's an overview of your helpdesk activity."; ?></p>
                </div>
                <?php if (!$isSalesManagerView): ?>
                    <a href="request_ticket.php" class="hero-action create-ticket-btn">
                        <i class="fas fa-plus-circle" aria-hidden="true"></i>
                        <span>Create Ticket</span>
                    </a>
                <?php endif; ?>
            </div>

            <!-- 4ï¸âƒ£ STATISTICS CARDS -->
            <div class="cards-panel">
                <?php if (!$isSalesManagerView): ?>
                <div class="card-filter-row">
                    <label class="card-filter-label" for="dashboardCardFilter">Show cards:</label>
                    <div class="card-filter-dropdown">
                        <button type="button" class="card-filter-trigger" id="dashboardCardFilter" aria-haspopup="true" aria-expanded="false">
                            <span class="card-filter-trigger-label">
                                <i class="fas fa-file-lines card-filter-trigger-icon" aria-hidden="true"></i>
                                <span id="dashboardCardFilterText">Assigned Tickets</span>
                            </span>
                            <i class="fas fa-chevron-down card-filter-trigger-caret" aria-hidden="true"></i>
                        </button>
                        <div class="card-filter-menu" id="dashboardCardFilterMenu" hidden>
                            <button type="button" class="card-filter-option is-active" data-card-filter-value="assigned" data-card-filter-label="Assigned Tickets" data-card-filter-icon="fa-user-check">
                                <span class="card-filter-option-label">
                                    <i class="fas fa-user-check card-filter-option-icon" aria-hidden="true"></i>
                                    <span>Assigned Tickets</span>
                                </span>
                                <i class="fas fa-check card-filter-option-check" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="card-filter-option" data-card-filter-value="submitted" data-card-filter-label="My Submitted Tickets" data-card-filter-icon="fa-file-lines">
                                <span class="card-filter-option-label">
                                    <i class="fas fa-file-lines card-filter-option-icon" aria-hidden="true"></i>
                                    <span>My Submitted Tickets</span>
                                </span>
                                <i class="fas fa-check card-filter-option-check" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach ($dashboardStatSets as $setKey => $stats): ?>
                    <?php $summarySetLabel = $setKey === 'manager' ? 'Regional sales tickets' : ($setKey === 'assigned' ? 'Assigned tickets' : 'My submitted tickets'); ?>
                    <section class="summary-carousel" data-card-set="<?= htmlspecialchars($setKey, ENT_QUOTES, 'UTF-8') ?>" role="region" aria-label="<?= htmlspecialchars($summarySetLabel, ENT_QUOTES, 'UTF-8') ?> summary carousel" tabindex="0" <?= ($setKey === 'assigned' || $setKey === 'manager') ? '' : 'hidden' ?>>
                        <button class="carousel__nav carousel__nav--prev" aria-label="Previous summary" type="button">
                            <span aria-hidden="true">&lsaquo;</span>
                        </button>
                        <div class="carousel__viewport">
                            <div class="carousel__track" role="list">
                                <?php foreach ($stats as $stat): ?>
                                    <div class="carousel__slide" role="listitem" data-slide="<?= htmlspecialchars($stat['variant'], ENT_QUOTES, 'UTF-8') ?>">
                                        <a class="stat-card summary-card <?= htmlspecialchars($stat['variant'], ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($stat['href'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View <?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?> tickets">
                                            <div class="stat-main">
                                                <div class="stat-icon">
                                                    <i class="fas <?= htmlspecialchars($stat['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                </div>
                                                <div class="stat-copy">
                                                    <div class="stat-label summary-card__label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="stat-value summary-card__value"><?= (int) $stat['value'] ?></div>
                                                </div>
                                            </div>
                                            <div class="stat-subtext summary-card__desc"><?= htmlspecialchars($stat['subtitle'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="stat-action">View tickets <i class="fas fa-arrow-right" aria-hidden="true"></i></div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button class="carousel__nav carousel__nav--next" aria-label="Next summary" type="button">
                            <span aria-hidden="true">&rsaquo;</span>
                        </button>
                        <div class="carousel__dots" role="tablist" aria-label="Summary pagination"></div>
                        <div class="visually-hidden carousel__announce" aria-live="polite"></div>
                    </section>
                <?php endforeach; ?>
            </div>

            <?php if ($isSalesManagerView): ?>
            <!-- Mobile divider: visually separates carousel from ticket lists -->
            <div class="mobile-divider" aria-hidden="false">
                <div class="mobile-divider__inner">
                    <span class="mobile-divider__icon" aria-hidden="true">&#10003;</span>
                    <span class="mobile-divider__title">Recent Sales Submitted Tickets</span>
                </div>
            </div>

            <!-- Recent Sales Submitted Tickets Section -->
            <div class="table-card sales-manager-table-card">
                <h2 id="managerSalesTicketsTitle" class="dashboard-ticket-title">Recent Submitted Tickets</h2>
                <div class="table-responsive">
                    <table class="admin-table sales-manager-admin-table" aria-labelledby="managerSalesTicketsTitle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Urgency</th>
                                <th>Requested By</th>
                                <th>Position</th>
                                <th>Status</th>
                                <th>SLA</th>
                                <th>Date Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($managerSalesTickets) > 0): ?>
                                <?php foreach ($managerSalesTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <?php $requester = dashboard_requester_info($row); ?>
                                    <tr class="ticket-row sales-manager-ticket-row" data-id="<?= (int) $row['id']; ?>" style="cursor:pointer;">
                                        <td class="task-ticket-id">#<?= str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td class="subject-cell task-ticket-category"><strong><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td class="task-ticket-urgency"><?= dashboard_urgency_badge_html((string) ($row['priority'] ?? '')); ?></td>
                                        <td class="task-ticket-requester">
                                            <div class="user-info">
                                                <strong><?= htmlspecialchars($requester['name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                                <small><?= htmlspecialchars($requester['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            </div>
                                        </td>
                                        <td class="task-ticket-department"><?= htmlspecialchars(dashboard_sales_position($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="task-ticket-status">
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="task-ticket-sla"><?= dashboard_sla_badge_html((string) ($row['created_at'] ?? ''), $status, (string) ($row['priority'] ?? '')); ?></td>
                                        <td class="task-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="task-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; color: #94a3b8; padding: 40px;">No submitted tickets found for this region.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <!-- Mobile divider: visually separates carousel from ticket lists -->
            <div class="mobile-divider" aria-hidden="false">
                <div class="mobile-divider__inner">
                    <span class="mobile-divider__icon" aria-hidden="true">&#10003;</span>
                    <span class="mobile-divider__title">Assigned Tickets</span>
                </div>
            </div>

            <!-- 5ï¸âƒ£ RECENT TICKETS SECTION -->
            <div class="dashboard-ticket-grid">
                <section class="dashboard-ticket-panel" aria-labelledby="receivedTicketsTitle">
                    <h2 id="receivedTicketsTitle" class="dashboard-ticket-title mobile-heading-sr-only">Assigned Tickets</h2>
                    <table class="dashboard-ticket-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Requested By</th>
                                <th>From</th>
                                <th class="dashboard-ticket-priority-header">Priority</th>
                                <th>Status</th>
                                <th>SLA</th>
                                <th>Date Created</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($receivedTickets) > 0): ?>
                                <?php foreach ($receivedTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <?php $requester = dashboard_requester_info($row); ?>
                                    <tr class="ticket-row ticket-card received-ticket-row" data-id="<?= (int) $row['id']; ?>">
                                        <td class="dashboard-ticket-id ticket-card__meta">#<?= str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td class="dashboard-ticket-category ticket-card__title"><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-requester ticket-card__body">
                                            <strong><?= htmlspecialchars($requester['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if ($requester['email'] !== ''): ?>
                                                <small><?= htmlspecialchars($requester['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="dashboard-ticket-department ticket-card__body"><?= htmlspecialchars(dashboard_source_label($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-priority ticket-card__badges"><?= dashboard_priority_badge_html($row); ?></td>
                                        <td class="dashboard-ticket-status ticket-card__badges">
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-ticket-sla ticket-card__badges"><?= dashboard_sla_badge_html((string) ($row['created_at'] ?? ''), $status, (string) ($row['priority'] ?? '')); ?></td>
                                        <td class="dashboard-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="dashboard-ticket-empty">No received tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <!-- Mobile divider: visually separates submitted ticket lists -->
                <div class="mobile-divider mobile-divider--submitted" aria-hidden="false">
                    <div class="mobile-divider__inner">
                        <span class="mobile-divider__icon" aria-hidden="true">&#10003;</span>
                        <span class="mobile-divider__title">My Submitted Tickets</span>
                    </div>
                </div>

                <section class="dashboard-ticket-panel" aria-labelledby="raisedTicketsTitle">
                    <h2 id="raisedTicketsTitle" class="dashboard-ticket-title mobile-heading-sr-only">My Submitted Tickets</h2>
                    <table class="dashboard-ticket-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Requested By</th>
                                <th>From</th>
                                <th class="dashboard-ticket-priority-header">Priority</th>
                                <th>Status</th>
                                <th>SLA</th>
                                <th>Date Created</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($raisedTickets) > 0): ?>
                                <?php foreach ($raisedTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <?php $requester = dashboard_requester_info($row); ?>
                                    <tr class="ticket-row ticket-card raised-ticket-row" data-id="<?= (int) $row['id']; ?>">
                                        <td class="dashboard-ticket-id ticket-card__meta">#<?= str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td class="dashboard-ticket-category ticket-card__title"><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-requester ticket-card__body">
                                            <strong><?= htmlspecialchars($requester['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if ($requester['email'] !== ''): ?>
                                                <small><?= htmlspecialchars($requester['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="dashboard-ticket-department ticket-card__body"><?= htmlspecialchars(dashboard_source_label($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-priority ticket-card__badges"><?= dashboard_priority_badge_html($row); ?></td>
                                        <td class="dashboard-ticket-status ticket-card__badges">
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-ticket-sla ticket-card__badges"><?= dashboard_sla_badge_html((string) ($row['created_at'] ?? ''), $status, (string) ($row['priority'] ?? '')); ?></td>
                                        <td class="dashboard-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="dashboard-ticket-empty">No raised tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
            <?php endif; ?>

            <div class="recent-section" style="display:none;">
                <div class="section-header">
                    <h2 class="section-title">Recent Tickets</h2>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Reported Concern</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent->num_rows > 0): ?>
                                <?php while($row = $recent->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= (int) $row['id']; ?>" style="cursor:pointer;">
                                    <td class="recent-ticket-id" data-label="ID">#<?= $row['id']; ?></td>
                                    <td class="recent-ticket-category" data-label="Category"><?= htmlspecialchars($row['category']); ?></td>
                                    <td class="recent-ticket-title" data-label="Reported Concern"><?= htmlspecialchars($row['subject']); ?></td>
                                    <td class="recent-ticket-status" data-label="Status">
                                        <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>

                                    <td class="recent-ticket-date" data-label="Date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                    <td class="recent-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; color: #94a3b8; padding: 30px;">
                                        No recent tickets found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="recentMobilePagination" class="recent-mobile-pagination" aria-label="Recent tickets pagination"></div>
            </div>

        </div>
    </div>

    <!-- JS Script -->
    <script src="../js/employee-dashboard.js"></script>
    <script src="../js/dashboard-carousel.js?v=<?= (int) filemtime(__DIR__ . '/../js/dashboard-carousel.js') ?>"></script>
    <script>
    (function () {
        var feedbackModal = document.getElementById('feedbackModalOverlay');
        var closeBtn = document.getElementById('feedbackModalCloseBtn');
        var dismissBtn = document.getElementById('feedbackModalDismissBtn');
        if (feedbackModal) {
            function closeFeedbackModal() {
                feedbackModal.classList.remove('is-visible');
                feedbackModal.setAttribute('aria-hidden', 'true');
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', closeFeedbackModal);
            }
            if (dismissBtn) {
                dismissBtn.addEventListener('click', closeFeedbackModal);
            }
            feedbackModal.addEventListener('click', function (event) {
                if (event.target === feedbackModal) {
                    closeFeedbackModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && feedbackModal.classList.contains('is-visible')) {
                    closeFeedbackModal();
                }
            });
        }
    })();

    (function () {
        const menuBtn = document.getElementById('navbarToggler');
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('mobileSidebarOverlay');
        const mobileUserBtn = document.getElementById('mobileSidebarUserBtn');
        const mobileUserMenu = document.getElementById('mobileSidebarUserMenu');
        const desktopNotifBadge = document.getElementById('notifBadge');
        const mobileNotifBadge = document.getElementById('mobileSidebarNotifBadge');

        function closeSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            if (mobileUserMenu) mobileUserMenu.classList.remove('show');
            sidebar.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function syncMobileNotifBadge() {
            if (!desktopNotifBadge || !mobileNotifBadge) return;
            const desktopText = (desktopNotifBadge.textContent || '').trim();
            const desktopVisible = desktopNotifBadge.style.display !== 'none' && desktopText !== '';
            mobileNotifBadge.textContent = desktopText;
            mobileNotifBadge.style.display = desktopVisible ? 'inline-flex' : 'none';
        }

        if (menuBtn && sidebar && overlay) {
            menuBtn.addEventListener('click', function (event) {
                if (window.innerWidth > 768) return;
                event.preventDefault();
                event.stopPropagation();
                const shouldOpen = !sidebar.classList.contains('active');
                sidebar.classList.toggle('active', shouldOpen);
                overlay.classList.toggle('active', shouldOpen);
                document.body.classList.toggle('sidebar-open', shouldOpen);
                sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
                overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
            });

            overlay.addEventListener('click', function () {
                if (window.innerWidth > 768) return;
                closeSidebar();
            });

            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth > 768) return;
                    closeSidebar();
                });
            });

            if (mobileUserBtn && mobileUserMenu) {
                mobileUserBtn.addEventListener('click', function (event) {
                    if (window.innerWidth > 768) return;
                    event.stopPropagation();
                    mobileUserMenu.classList.toggle('show');
                });

                document.addEventListener('click', function (event) {
                    if (window.innerWidth > 768) return;
                    if (!mobileUserMenu.contains(event.target) && !mobileUserBtn.contains(event.target)) {
                        mobileUserMenu.classList.remove('show');
                    }
                });
            }

            syncMobileNotifBadge();
            if (desktopNotifBadge && typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver(syncMobileNotifBadge);
                observer.observe(desktopNotifBadge, { attributes: true, childList: true, subtree: true });
            }
        }
    })();

    (function () {
        const tbody = document.querySelector('.recent-section table tbody');
        const pagination = document.getElementById('recentMobilePagination');
        if (!tbody || !pagination) return;

        const rows = Array.from(tbody.querySelectorAll('tr.ticket-row'));
        if (!rows.length) {
            pagination.style.display = 'none';
            return;
        }

        const perPageMobile = 4;
        let currentPage = 1;

        function renderPagination(totalPages) {
            if (window.innerWidth > 768 || totalPages <= 1) {
                pagination.innerHTML = '';
                pagination.style.display = 'none';
                rows.forEach(function (row) { row.style.display = ''; });
                return;
            }

            pagination.style.display = 'flex';
            pagination.innerHTML = '';

            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'recent-mobile-page-btn';
            prevBtn.textContent = '<';
            prevBtn.disabled = currentPage === 1;
            prevBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage--;
                    updateRecentCards();
                }
            });
            pagination.appendChild(prevBtn);

            for (let page = 1; page <= totalPages; page++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'recent-mobile-page-btn' + (page === currentPage ? ' is-active' : '');
                btn.textContent = String(page);
                btn.addEventListener('click', function () {
                    currentPage = page;
                    updateRecentCards();
                });
                pagination.appendChild(btn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'recent-mobile-page-btn';
            nextBtn.textContent = '>';
            nextBtn.disabled = currentPage === totalPages;
            nextBtn.addEventListener('click', function () {
                if (currentPage < totalPages) {
                    currentPage++;
                    updateRecentCards();
                }
            });
            pagination.appendChild(nextBtn);
        }

        function updateRecentCards() {
            const isMobile = window.innerWidth <= 768;
            const totalPages = Math.max(1, Math.ceil(rows.length / perPageMobile));
            if (currentPage > totalPages) currentPage = totalPages;

            rows.forEach(function (row, index) {
                if (!isMobile) {
                    row.style.display = '';
                    return;
                }
                const start = (currentPage - 1) * perPageMobile;
                const end = start + perPageMobile;
                row.style.display = index >= start && index < end ? '' : 'none';
            });

            renderPagination(totalPages);
        }

        window.addEventListener('resize', updateRecentCards);
        updateRecentCards();
    })();

    document.querySelectorAll('.raised-ticket-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (!id) return;
            window.location.href = 'my_tickets.php?ticket_id=' + encodeURIComponent(id);
        });
    });

    document.querySelectorAll('.sales-manager-ticket-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (!id) return;
            window.location.href = 'sales_submitted_tickets.php?ticket_id=' + encodeURIComponent(id);
        });
    });

    document.querySelectorAll('.received-ticket-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (!id) return;
            window.location.href = 'my_task.php?ticket_id=' + encodeURIComponent(id);
        });
    });

    (function () {
        var filter = document.getElementById('dashboardCardFilter');
        var filterText = document.getElementById('dashboardCardFilterText');
        var filterMenu = document.getElementById('dashboardCardFilterMenu');
        var filterIcon = filter ? filter.querySelector('.card-filter-trigger-icon') : null;
        var options = document.querySelectorAll('[data-card-filter-value]');
        var grids = document.querySelectorAll('[data-card-set]');
        if (!filter || !filterMenu || !filterText || !filterIcon || !grids.length || !options.length) return;

        function setActiveCardSet(value, label, iconClass) {
            grids.forEach(function (grid) {
                grid.hidden = grid.getAttribute('data-card-set') !== value;
            });
            options.forEach(function (option) {
                option.classList.toggle('is-active', option.getAttribute('data-card-filter-value') === value);
            });
            filterText.textContent = label;
            filterIcon.className = 'fas ' + iconClass + ' card-filter-trigger-icon';
            filter.setAttribute('data-card-filter-value', value);
        }

        function closeMenu() {
            filterMenu.hidden = true;
            filter.setAttribute('aria-expanded', 'false');
        }

        filter.addEventListener('click', function () {
            var shouldOpen = filterMenu.hidden;
            filterMenu.hidden = !shouldOpen;
            filter.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                setActiveCardSet(
                    option.getAttribute('data-card-filter-value') || 'submitted',
                    option.getAttribute('data-card-filter-label') || 'My Submitted Tickets',
                    option.getAttribute('data-card-filter-icon') || 'fa-file-lines'
                );
                closeMenu();
            });
        });

        document.addEventListener('click', function (event) {
            if (!filterMenu.hidden && !event.target.closest('.card-filter-dropdown')) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !filterMenu.hidden) {
                closeMenu();
            }
        });
    })();

    </script>

   

</body>
</html>
