<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

/* Protect page */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$user_department = $_SESSION['department'] ?? '';
$user_company = $_SESSION['company'] ?? '';
$user_email = $_SESSION['email'] ?? '';

ticket_ensure_assignment_columns($conn);
ticket_ensure_activity_table($conn);
ticket_apply_sla_priority($conn);

function company_code(string $value): string
{
    $s = strtoupper(trim($value));
    if ($s === '') return '';
    if ($s === 'FARMASEE') return 'PCC';
    if (strpos($s, 'MHC') !== false) return 'MHC';
    if (strpos($s, 'GPCI') !== false || strpos($s, 'GPCI') !== false) return 'GPCI';
    if (strpos($s, 'LAPC') !== false || strpos($s, 'LAH') !== false) return 'LAPC';
    if (strpos($s, 'PCC') !== false) return 'PCC';
    if (strpos($s, 'MPDC') !== false) return 'MPDC';
    if (strpos($s, 'LINGAP') !== false) return 'LINGAP';
    if (strpos($s, 'LTC') !== false) return 'LTC';
    if (strpos($s, 'FARMEX') !== false) return 'FARMEX';
    if (strpos($s, 'FARMEX CORP') !== false) return 'FARMEX';
    return '';
}

function company_aliases(string $value): array
{
    $v = trim($value);
    $code = company_code($v);
    $map = [
        'MHC' => ['MHC', 'Malveda Holdings Corporation - MHC'],
        'GPCI' => ['GPCI', 'GPCI', 'Golden Primestocks Chemical Inc - GPCI', 'Golden Primestocks Chemical Inc - GPCI'],
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

if ($user_department === '' || $user_company === '') {
    $user_dept_stmt = $conn->prepare("SELECT department, company FROM users WHERE id = ?");
    $user_dept_stmt->bind_param("i", $user_id);
    $user_dept_stmt->execute();
    $user_dept_result = $user_dept_stmt->get_result();
    if ($row = $user_dept_result->fetch_assoc()) {
        $user_department = $user_department !== '' ? $user_department : ($row['department'] ?? '');
        $user_company = $user_company !== '' ? $user_company : ($row['company'] ?? '');
    }
    $user_dept_stmt->close();

    if ($user_department !== '') $_SESSION['department'] = $user_department;
    if ($user_company !== '') $_SESSION['company'] = $user_company;
}
if ($user_email === '') {
    $ue = $conn->prepare("SELECT email FROM users WHERE id = ?");
    if ($ue) {
        $ue->bind_param("i", $user_id);
        $ue->execute();
        $ueRes = $ue->get_result();
        if ($ueRow = $ueRes->fetch_assoc()) {
            $user_email = (string) ($ueRow['email'] ?? '');
        }
        $ue->close();
    }
    if ($user_email !== '') $_SESSION['email'] = $user_email;
}

$flashError = isset($_SESSION['error']) ? trim((string) $_SESSION['error']) : '';
if ($flashError !== '') {
    unset($_SESSION['error']);
}
$flashSuccess = isset($_SESSION['task_success']) ? trim((string) $_SESSION['task_success']) : '';
if ($flashSuccess !== '') {
    unset($_SESSION['task_success']);
}
$flashSuccessStatus = isset($_SESSION['task_success_status']) ? trim((string) $_SESSION['task_success_status']) : '';
if ($flashSuccessStatus !== '') {
    unset($_SESSION['task_success_status']);
}
$flashSuccessTicketId = isset($_SESSION['task_success_ticket_id']) ? (int) $_SESSION['task_success_ticket_id'] : 0;
if ($flashSuccessTicketId > 0) {
    unset($_SESSION['task_success_ticket_id']);
}
$flashSuccessContext = isset($_SESSION['task_success_context']) ? trim((string) $_SESSION['task_success_context']) : '';
if ($flashSuccessContext !== '') {
    unset($_SESSION['task_success_context']);
}
$isStatusSuccess = $flashSuccessStatus === 'Open' || $flashSuccessStatus === 'In Progress' || $flashSuccessStatus === 'Resolved';
$isReassignedSuccess = $flashSuccessContext === 'reassigned' && $flashSuccessTicketId > 0;
$flashErrorTitle = 'Update Failed';
if ($flashError !== '' && stripos($flashError, 'No assignee available') !== false) {
    $flashErrorTitle = 'No Assignee Available';
}

/* ================= GET VALUES ================= */

$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$company_email = $_GET['company_email'] ?? '';
$status = $_GET['status'] ?? '';
$sla = $_GET['sla'] ?? '';
$reassignment = $_GET['reassignment'] ?? '';
$slaLevel = task_normalize_sla_filter((string) $sla);
if ($slaLevel !== '') {
    $sla = task_sla_display_label($slaLevel);
}
 $userCompanyNorm = ticket_normalize_company((string) $user_company);
 $userEmailNorm = strtolower(trim((string) $user_email));
 $show_department_filter = ($userCompanyNorm === '@leadsagri.com')
    || (company_code((string) $user_company) === 'LAPC')
    || ($userEmailNorm !== '' && str_ends_with($userEmailNorm, '@leadsagri.com'));

$lapc_departments = ticket_lapc_departments();
$mhc_departments = ['Marketing Creatives'];
$allowed_departments = $lapc_departments;
$allowed_departments_by_company = [
    '@leadsagri.com' => $lapc_departments,
    '@malvedaholdings.com' => $mhc_departments,
];
$company_filter_options = [
    '@farmex_lav' => 'FARMEX / LAV',
    '@farmasee.ph' => 'FARMASEE',
    '@gpsci.net' => 'GPCI',
    '@leadsagri.com' => 'LAPC',
    '@malvedaholdings.com' => 'MHC',
    '@malvedaproperties.com' => 'MPDC',
    '@leadstech-corp.com' => 'LTC',
    '@lingapleads.org' => 'LINGAP',
    '@primestocks.ph' => 'PCC',
];
$allowed_statuses = ['Open','In Progress','Resolved','Closed'];
$allowed_slas = ['On Track', 'At Risk', 'Breach'];
$allowed_reassignment_filters = ['reassigned', 'not_reassigned', 'handled_by_you'];

$selected_company_departments = $allowed_departments_by_company[$company_email] ?? [];
if (
    !$show_department_filter
    || !array_key_exists($company_email, $allowed_departments_by_company)
    || !in_array($department, $selected_company_departments, true)
) {
    $department = '';
}
if (!array_key_exists((string) $company_email, $company_filter_options)) {
    $company_email = '';
}
if (!in_array($status, $allowed_statuses, true)) {
    $status = '';
}
if ($slaLevel === '') {
    $sla = '';
}
if (!in_array($reassignment, $allowed_reassignment_filters, true)) {
    $reassignment = '';
}

function task_source_label(array $row): string
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

function task_sla_display_label(string $slaLevel): string
{
    return ticket_sla_display_label($slaLevel);
}

function task_normalize_sla_filter(string $sla): string
{
    return ticket_normalize_sla_level($sla);
}

function task_sla_badge_html(string $createdAt, string $status, string $priority = ''): string
{
    return ticket_sla_badge_html($createdAt, $status, $priority);
}

function task_urgency_badge_html(string $priority): string
{
    $priority = trim($priority);
    if ($priority === '') return '-';
    $priorityKey = strtolower($priority);
    $allowedKeys = ['low', 'medium', 'high', 'critical'];
    $priorityClass = in_array($priorityKey, $allowedKeys, true) ? $priorityKey : 'low';
    return '<span class="priority-pill priority-' . htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(ucfirst($priorityKey), ENT_QUOTES, 'UTF-8') . '</span>';
}

function task_sla_filter_condition(string $sla): string
{
    return ticket_sla_filter_condition_sql('t', $sla);
}

function task_company_filter_aliases(string $companyFilter): array
{
    $map = [
        '@farmex_lav' => ['@leads-farmex.com', '@leadsav.com', 'leads-farmex.com', 'leadsav.com', 'farmex', 'farmex corp', 'lav', 'farmex / lav'],
        '@farmasee.ph' => ['@farmasee.ph', 'farmasee'],
        '@gpsci.net' => ['@gpsci.net', 'gpsci', 'gpci', 'golden primestocks chemical inc - gpsci', 'golden primestocks chemical inc - gpci'],
        '@leadsagri.com' => ['@leadsagri.com', 'lapc', 'leads agri', 'leads agricultural products corporation'],
        '@malvedaholdings.com' => ['@malvedaholdings.com', 'mhc', 'malveda holdings', 'malveda holdings corporation', 'malveda holdings corporation - mhc'],
        '@malvedaproperties.com' => ['@malvedaproperties.com', 'mpdc', 'malveda properties', 'malveda properties & development corporation - mpdc'],
        '@leadstech-corp.com' => ['@leadstech-corp.com', 'ltc', 'leads tech corporation - ltc'],
        '@lingapleads.org' => ['@lingapleads.org', 'lingap', 'lingap leads foundation - lingap'],
        '@primestocks.ph' => ['@primestocks.ph', 'pcc', 'primestocks chemical corporation - pcc'],
    ];
    $aliases = $map[$companyFilter] ?? [$companyFilter];
    return array_values(array_unique(array_filter(array_map(static function ($value) {
        return strtolower(trim((string) $value));
    }, $aliases), static function ($value) {
        return $value !== '';
    })));
}

function task_company_filter_email_domains(string $companyFilter): array
{
    if ($companyFilter === '@farmex_lav') {
        return ['@leads-farmex.com', '@leadsav.com'];
    }
    return str_starts_with($companyFilter, '@') ? [$companyFilter] : [];
}

// --- PAGINATION LOGIC ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// --- BUILD DYNAMIC QUERY ---
$where = [];
$params = [];
$types = "";

// 🎯 MAIN FILTER: Assigned to employee OR assigned to user's group+company
$companyAliases = company_aliases((string) $user_company);
if (count($companyAliases) === 0) {
    $companyAliases = [(string) $user_company];
}
$reassignedHistoryAliases = $companyAliases;
$userCompanyDisplay = ticket_company_display_name((string) $user_company);
if ($userCompanyDisplay !== '') {
    $reassignedHistoryAliases[] = $userCompanyDisplay;
}
$userCompanyCode = company_code((string) $userCompanyDisplay);
if ($userCompanyCode !== '') {
    $reassignedHistoryAliases[] = $userCompanyCode;
}
$userDepartmentKey = ticket_department_key_from_value((string) $user_department);
$userDepartmentAliases = [];
foreach (array_merge([(string) $user_department, $userDepartmentKey], ticket_department_aliases_for_key($userDepartmentKey)) as $departmentAlias) {
    $departmentAlias = strtoupper(trim((string) $departmentAlias));
    if ($departmentAlias !== '') {
        $userDepartmentAliases[$departmentAlias] = $departmentAlias;
    }
}
$userDepartmentAliases = array_values($userDepartmentAliases);
$reassignedHistoryAliases = array_values(array_unique(array_filter(array_map(static function ($value) {
    return strtoupper(trim((string) $value));
}, array_merge($reassignedHistoryAliases, $userDepartmentAliases)), static function ($value) {
    return $value !== '';
})));
$companyCol = "COALESCE(NULLIF(t.assigned_company, ''), t.company)";
$companyAliases = array_values(array_filter(array_map('trim', $companyAliases), static function ($v) { return $v !== ''; }));
$companyAliasCond = count($companyAliases) > 0
    ? ("(" . implode(" OR ", array_fill(0, count($companyAliases), "$companyCol = ?")) . ")")
    : "(1=0)";
$companyCond = "(($companyCol LIKE '@%' AND LOWER(?) LIKE CONCAT('%', LOWER($companyCol))) OR ($companyCol NOT LIKE '@%' AND $companyAliasCond))";
$taskDeptExpr = "COALESCE(NULLIF(NULLIF(t.assigned_group, ''), NULLIF(t.assigned_department, 'Unassigned')), NULLIF(t.assigned_department, ''), NULLIF(t.department, ''), NULLIF(u.department, ''))";
$sourceDeptExpr = "COALESCE(NULLIF(t.department, ''), NULLIF(u.department, ''))";
$sourceEmailExpr = "COALESCE(NULLIF(t.requester_email, ''), NULLIF(u.email, ''))";
$sourceCompanyExpr = "COALESCE(NULLIF(t.company, ''), NULLIF(u.company, ''), CASE WHEN $sourceEmailExpr LIKE '%@%' THEN CONCAT('@', LOWER(SUBSTRING_INDEX($sourceEmailExpr, '@', -1))) ELSE '' END)";
$groupCond = count($userDepartmentAliases) > 0
    ? ("UPPER($taskDeptExpr) IN (" . implode(', ', array_fill(0, count($userDepartmentAliases), '?')) . ")")
    : "0=1";
$requiresGroupCond = "(($companyCol LIKE '@%' AND LOWER($companyCol) = '@leadsagri.com') OR ($companyCol NOT LIKE '@%' AND UPPER($companyCol) = 'LAPC'))";
$requesterIsCurrentCond = "(t.user_id = ? OR LOWER($sourceEmailExpr) = ?)";
$linkedItTaskCond = "0=1";
$normalizedUserCompany = ticket_normalize_company((string) $user_company);
$isLapcSalesEmployeeView = $normalizedUserCompany === '@leadsagri.com'
    && strcasecmp((string) $user_department, 'Sales') === 0
    && (($_SESSION['employee_view_mode'] ?? 'employee') !== 'manager');
if ($userDepartmentKey === 'IT') {
    if ($normalizedUserCompany === '@malvedaholdings.com') {
        $linkedItTaskCond = "(LOWER($companyCol) IN ('@leadsagri.com', 'lapc', 'leads agri', 'leads agricultural products corporation') AND UPPER($taskDeptExpr) = 'IT')";
    } elseif ($normalizedUserCompany === '@leadsagri.com') {
        $linkedItTaskCond = "(LOWER($companyCol) IN ('@malvedaholdings.com', 'mhc', 'malveda holdings', 'malveda holdings corporation') AND UPPER($taskDeptExpr) = 'IT')";
    }
}
$assignedTaskCond = $isLapcSalesEmployeeView
    ? "((t.assigned_user_id = ? OR t.assigned_to = ?) AND NOT $requesterIsCurrentCond)"
    : "(((t.assigned_user_id = ? OR t.assigned_to = ?) AND NOT $requesterIsCurrentCond) OR (NOT $requesterIsCurrentCond AND ($groupCond OR $linkedItTaskCond)))";
$reassignedActivityCond = count($reassignedHistoryAliases) > 0
    ? "EXISTS (SELECT 1 FROM ticket_activity ta WHERE ta.ticket_id = t.id AND ta.activity_type IN ('department_change', 'company_change') AND (" . implode(' OR ', array_fill(0, count($reassignedHistoryAliases), "UPPER(ta.description) LIKE ?")) . "))"
    : "0=1";
$reassignedNotificationCond = "EXISTS (
    SELECT 1
    FROM notifications n
    WHERE n.ticket_id = t.id
      AND n.user_id = ?
      AND n.type = 'dept_assigned'
      AND COALESCE(NULLIF(LOWER(TRIM(n.action_type)), ''), 'assign') IN ('assign', 'reassign')
)";
$reassignedTaskCond = $isLapcSalesEmployeeView
    ? "0=1"
    : "(NOT $requesterIsCurrentCond AND (($reassignedActivityCond) OR $reassignedNotificationCond))";
$reassignmentFilterNotificationCond = "EXISTS (
    SELECT 1
    FROM notifications n
    WHERE n.ticket_id = t.id
      AND n.user_id = ?
      AND n.type = 'dept_assigned'
      AND LOWER(TRIM(COALESCE(n.action_type, ''))) = 'reassign'
)";
$reassignmentFilterCond = $isLapcSalesEmployeeView
    ? "0=1"
    : "(($reassignedActivityCond) OR $reassignmentFilterNotificationCond)";
$teamTicketsCond = $isLapcSalesEmployeeView
    ? "((t.assigned_user_id = ? OR t.assigned_to = ?) AND NOT $requesterIsCurrentCond AND NOT ($reassignmentFilterCond))"
    : "(NOT $requesterIsCurrentCond AND ($groupCond OR $linkedItTaskCond) AND NOT ($reassignmentFilterCond))";
$handledByYouCond = "(t.assigned_to = ? AND LOWER(TRIM(COALESCE(t.status, ''))) = 'in progress')";

$addAssignedTaskParams = static function () use (&$params, &$types, $user_id, $user_email, $companyAliases, $userDepartmentAliases, $isLapcSalesEmployeeView): void {
    $params[] = (int) $user_id;
    $types .= "i";
    $params[] = (int) $user_id;
    $types .= "i";
    $params[] = (int) $user_id;
    $types .= "i";
    $params[] = strtolower((string) $user_email);
    $types .= "s";
    if ($isLapcSalesEmployeeView) {
        return;
    }
    $params[] = (int) $user_id;
    $types .= "i";
    $params[] = strtolower((string) $user_email);
    $types .= "s";
    foreach ($userDepartmentAliases as $departmentAlias) {
        $params[] = $departmentAlias;
        $types .= "s";
    }
};

$addReassignedTaskParams = static function () use (&$params, &$types, $user_id, $user_email, $reassignedHistoryAliases, $isLapcSalesEmployeeView): void {
    if ($isLapcSalesEmployeeView) {
        return;
    }
    $params[] = (int) $user_id;
    $types .= "i";
    $params[] = strtolower((string) $user_email);
    $types .= "s";
    foreach ($reassignedHistoryAliases as $historyAlias) {
        $params[] = '%' . strtoupper($historyAlias) . '%';
        $types .= "s";
    }
    $params[] = (int) $user_id;
    $types .= "i";
};
$addReassignmentFilterParams = static function () use (&$params, &$types, $user_id, $reassignedHistoryAliases, $isLapcSalesEmployeeView): void {
    if ($isLapcSalesEmployeeView) {
        return;
    }
    foreach ($reassignedHistoryAliases as $historyAlias) {
        $params[] = '%' . strtoupper($historyAlias) . '%';
        $types .= "s";
    }
    $params[] = (int) $user_id;
    $types .= "i";
};
$addHandledByYouFilterParams = static function () use (&$params, &$types, $user_id): void {
    $params[] = (int) $user_id;
    $types .= "i";
};
$addTeamTicketsFilterParams = static function () use (&$params, &$types, $user_id, $user_email, $companyAliases, $userDepartmentAliases, $reassignedHistoryAliases, $isLapcSalesEmployeeView): void {
    if ($isLapcSalesEmployeeView) {
        $params[] = (int) $user_id;
        $types .= "i";
        $params[] = (int) $user_id;
        $types .= "i";
        $params[] = (int) $user_id;
        $types .= "i";
        $params[] = strtolower((string) $user_email);
        $types .= "s";
        return;
    }
    $params[] = (int) $user_id;
    $types .= "i";
    $params[] = strtolower((string) $user_email);
    $types .= "s";
    foreach ($userDepartmentAliases as $departmentAlias) {
        $params[] = $departmentAlias;
        $types .= "s";
    }
    foreach ($reassignedHistoryAliases as $historyAlias) {
        $params[] = '%' . strtoupper($historyAlias) . '%';
        $types .= "s";
    }
    $params[] = (int) $user_id;
    $types .= "i";
};

$where[] = "($assignedTaskCond OR $reassignedTaskCond)";
$addAssignedTaskParams();
$addReassignedTaskParams();
$where[] = "COALESCE(NULLIF(t.status, ''), '') <> 'Trash'";

// 1. Search
if (!empty($search)) {
    $term = "%$search%";
    
    // Parse ID from search (remove non-digits)
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int)$searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    if ($searchById) {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR t.id = ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $searchIdInt;
        $types .= "sssssi";
    } else {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types .= "sssss";
    }
}

if ($department !== '') {
    $deptKey = ticket_department_key_from_value((string) $department);
    $deptAliases = ticket_department_aliases_for_key($deptKey);
    $deptAliases[] = $deptKey;
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $deptAliases)), static function ($v) {
        return is_string($v) && $v !== '';
    })));
    if (count($deptAliases) > 0) {
        $deptConds = [];
        foreach ($deptAliases as $a) {
            $deptConds[] = "UPPER($sourceDeptExpr) = ?";
            $params[] = $a;
            $types .= "s";
        }
        $where[] = "(" . implode(" OR ", $deptConds) . ")";
    }
}

if ($company_email !== '') {
    $companyFilterAliases = task_company_filter_aliases((string) $company_email);
    $companyFilterDomains = task_company_filter_email_domains((string) $company_email);
    $companyFilterParts = [];
    if (count($companyFilterAliases) > 0) {
        $companyFilterParts[] = "LOWER($sourceCompanyExpr) IN (" . implode(', ', array_fill(0, count($companyFilterAliases), '?')) . ")";
        foreach ($companyFilterAliases as $companyFilterAlias) {
            $params[] = $companyFilterAlias;
            $types .= "s";
        }
    }
    foreach ($companyFilterDomains as $companyFilterDomain) {
        $companyFilterParts[] = "LOWER($sourceEmailExpr) LIKE ?";
        $params[] = '%' . strtolower($companyFilterDomain);
        $types .= "s";
    }
    if (count($companyFilterParts) > 0) {
        $where[] = "(" . implode(" OR ", $companyFilterParts) . ")";
    }
}

if ($status !== '') {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($sla !== '') {
    $slaCondition = task_sla_filter_condition($sla);
    if ($slaCondition !== '') {
        $where[] = $slaCondition;
    }
}

if ($reassignment === 'reassigned') {
    $where[] = $reassignmentFilterCond;
    $addReassignmentFilterParams();
} elseif ($reassignment === 'not_reassigned') {
    $where[] = $teamTicketsCond;
    $addTeamTicketsFilterParams();
} elseif ($reassignment === 'handled_by_you') {
    $where[] = $handledByYouCond;
    $addHandledByYouFilterParams();
}

// Construct SQL
$sql = "SELECT t.*, u.name as user_name, u.email as user_email, u.department as user_department, u.company as user_company,
               $taskDeptExpr AS task_department
        FROM employee_tickets t 
        JOIN users u ON t.user_id = u.id";
$countSql = "SELECT COUNT(*) as total 
             FROM employee_tickets t 
             JOIN users u ON t.user_id = u.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
    $countSql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY CASE LOWER(TRIM(COALESCE(t.status, ''))) WHEN 'resolved' THEN 1 WHEN 'closed' THEN 2 ELSE 0 END ASC, t.created_at DESC LIMIT ?, ?";

// --- GET TOTAL COUNT ---
if (!empty($where)) {
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $count_result = $conn->query($countSql);
    $total_row = $count_result->fetch_assoc();
}

$total_records = $total_row['total'] ?? 0;
$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// --- EXECUTE MAIN QUERY ---
$stmt = $conn->prepare($sql);

// Add Limit/Offset to params
$params[] = $offset;
$params[] = $limit;
$types .= "ii";

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$showing_from = $total_records > 0 ? ($offset + 1) : 0;
$showing_to = min($offset + $limit, (int) $total_records);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assigned Tickets | Leads DeskMetamorph</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body.employee-my-task-page .table-card {
            padding: 18px 24px 20px;
            overflow: hidden;
            min-height: 592px;
            display: flex;
            flex-direction: column;
        }

        body.employee-my-task-page .table-responsive {
            margin: 0;
            flex: 1 1 auto;
            min-height: 0;
        }

        body.employee-my-task-page .table-responsive .admin-table {
            margin: 0;
        }

        /* Keep the assigned-ticket columns fixed while filter results change. */
        @media (min-width: 768px) {
            body.employee-my-task-page #tasksTable {
                table-layout: fixed;
            }

            body.employee-my-task-page #tasksTable :is(th, td):nth-child(1) { width: 8%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(2) { width: 20%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(3) { width: 9%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(4) { width: 20%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(5) { width: 7%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(6) { width: 12%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(7) { width: 8%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(8) { width: 13%; }
            body.employee-my-task-page #tasksTable :is(th, td):nth-child(9) { width: 3%; }
        }

        body.employee-my-task-page .table-responsive .admin-table.is-empty th:last-child {
            display: none;
        }

        body.employee-my-task-page .task-ticket-sla .badge {
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

        body.employee-my-task-page .task-ticket-sla .badge-low {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
        }

        body.employee-my-task-page .task-ticket-sla .badge-high {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        body.employee-my-task-page .task-ticket-sla .badge-medium {
            background: #ffedd5;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        body.employee-my-task-page .task-ticket-sla .badge-critical {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        body.employee-my-task-page .table-responsive .admin-table th,
        body.employee-my-task-page .table-responsive .admin-table td {
            padding-top: 16px;
            padding-bottom: 16px;
        }

        body.employee-my-task-page #tasksPagination .pagination-glass {
            margin: 14px 0 0;
            justify-content: space-between;
            gap: 14px;
            flex: 0 0 auto;
        }

        body.employee-my-task-page #tasksPagination .pagination-summary {
            margin-right: auto;
            color: #64748b;
            font-weight: 700;
        }

        body.employee-my-task-page .my-tickets-filter-card {
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.06);
            padding: 24px 18px;
            margin-bottom: 22px;
        }

        body.employee-my-task-page .my-tickets-filter-form {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) 160px 160px 140px 178px 118px;
            gap: 16px;
            align-items: center;
            width: 100%;
        }

        body.employee-my-task-page .my-tickets-filter-form.has-department-filter {
            grid-template-columns: minmax(200px, 1fr) 150px 150px 150px 130px 178px 118px;
        }

        body.employee-my-task-page .my-tickets-search-row,
        body.employee-my-task-page .my-tickets-filter-controls {
            display: contents;
        }

        body.employee-my-task-page .my-tickets-mobile-filter-btn,
        body.employee-my-task-page .my-tickets-mobile-filter-summary {
            display: none;
        }

        body.employee-my-task-page .my-tickets-search-wrapper {
            position: relative;
            min-width: 0;
        }

        body.employee-my-task-page .my-tickets-search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #8fa1bd;
            font-size: 18px;
            pointer-events: none;
        }

        body.employee-my-task-page .my-tickets-search-input,
        body.employee-my-task-page .my-tickets-filter-select {
            width: 100%;
            height: 48px;
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
        }

        body.employee-my-task-page .my-tickets-search-input {
            padding: 0 16px 0 50px;
        }

        body.employee-my-task-page .my-tickets-filter-select {
            padding: 0 40px 0 16px;
            appearance: none;
            cursor: pointer;
        }

        body.employee-my-task-page .my-tickets-filter-select.is-customized {
            position: absolute;
            inset: 0;
            opacity: 0;
            pointer-events: none;
        }

        body.employee-my-task-page .my-tickets-filter-select option {
            background: #ffffff;
            color: #0f172a;
        }

        body.employee-my-task-page .my-tickets-filter-select option:hover,
        body.employee-my-task-page .my-tickets-filter-select option:focus,
        body.employee-my-task-page .my-tickets-filter-select option:checked {
            background: #1B5E20;
            color: #ffffff;
        }

        body.employee-my-task-page .my-tickets-filter-select-wrap {
            position: relative;
            width: 100%;
            min-width: 0;
        }

        body.employee-my-task-page .my-tickets-filter-select-wrap.has-custom-dropdown::after {
            display: none;
        }

        body.employee-my-task-page .my-task-filter-trigger {
            width: 100%;
            height: 48px;
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            letter-spacing: 0;
            padding: 0 14px 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            cursor: pointer;
            box-sizing: border-box;
            text-align: left;
        }

        body.employee-my-task-page .my-task-filter-trigger:focus-visible,
        body.employee-my-task-page .my-tickets-filter-select-wrap.is-open .my-task-filter-trigger {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 3px rgba(27, 94, 32, 0.14);
        }

        body.employee-my-task-page .my-task-filter-trigger-icon {
            color: #8fa1bd;
            font-size: 12px;
            transition: transform 0.16s ease;
        }

        body.employee-my-task-page .my-tickets-filter-select-wrap.is-open .my-task-filter-trigger-icon {
            transform: rotate(180deg);
        }

        body.employee-my-task-page .my-task-filter-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 80;
            width: 100%;
            margin: 0;
            padding: 6px 0;
            list-style: none;
            background: #ffffff;
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
            display: none;
            max-height: 260px;
            overflow-y: auto;
            box-sizing: border-box;
        }

        body.employee-my-task-page .my-tickets-filter-select-wrap.is-open .my-task-filter-menu {
            display: block;
        }

        body.employee-my-task-page .my-task-filter-option {
            width: 100%;
            min-height: 36px;
            padding: 0 14px;
            border: 0;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            letter-spacing: 0;
            text-align: left;
            display: flex;
            align-items: center;
            cursor: pointer;
            box-sizing: border-box;
        }

        body.employee-my-task-page .my-task-filter-option:hover,
        body.employee-my-task-page .my-task-filter-option:focus {
            background: rgba(27, 94, 32, 0.08);
            color: #1b5e20;
            outline: none;
        }

        body.employee-my-task-page .my-task-filter-option.is-selected {
            background: #1B5E20;
            color: #ffffff;
            font-weight: 400;
            border-radius: 12px;
            outline: none;
        }

        body.employee-my-task-page .my-tickets-reassignment-filter {
            width: 100%;
        }

        body.employee-my-task-page .my-tickets-filter-select-wrap.is-hidden {
            display: none;
        }

        body.employee-my-task-page .my-tickets-filter-select-wrap::after {
            content: "\f078";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #8fa1bd;
            font-size: 12px;
            pointer-events: none;
        }

        body.employee-my-task-page .my-tickets-search-input:focus,
        body.employee-my-task-page .my-tickets-filter-select:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 3px rgba(27, 94, 32, 0.14);
        }

        body.employee-my-task-page .my-tickets-clear-btn {
            height: 48px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            background: #f1f5f9;
            color: #475569;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }

        body.employee-my-task-page .my-tickets-clear-btn:hover {
            background: #e2e8f0;
        }

        @media (max-width: 767px) {
            body.employee-my-task-page {
                overflow-x: hidden;
            }

            body.employee-my-task-page .dashboard-container,
            body.employee-my-task-page .content-wrapper {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            body.employee-my-task-page .my-tickets-filter-card *,
            body.employee-my-task-page .table-card * {
                box-sizing: border-box;
            }

            body.employee-my-task-page .content-wrapper {
                padding-left: clamp(10px, 4vw, 22px);
                padding-right: clamp(10px, 4vw, 22px);
                padding-bottom: calc(76px + env(safe-area-inset-bottom, 0px));
            }

            body.employee-my-task-page .my-tickets-filter-card {
                width: 100%;
                max-width: 100%;
                margin: 0 0 14px;
                padding: 0;
                border: 0;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                box-sizing: border-box;
            }

            body.employee-my-task-page .table-card {
                width: 100%;
                max-width: 100%;
                padding: 0;
                min-height: 0;
                display: block;
                border: 0;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                overflow: visible;
                box-sizing: border-box;
            }

            body.employee-my-task-page .table-responsive {
                min-height: 0;
                flex: none;
                overflow: visible;
            }

            body.employee-my-task-page .my-tickets-filter-form,
            body.employee-my-task-page .my-tickets-filter-form.has-department-filter {
                display: block;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }

            body.employee-my-task-page .my-tickets-search-row {
                display: flex;
                align-items: stretch;
                gap: clamp(8px, 2.5vw, 12px);
                width: 100%;
                min-width: 0;
            }

            body.employee-my-task-page .my-tickets-search-wrapper {
                flex: 1 1 auto;
                width: auto;
                min-width: 0;
            }

            body.employee-my-task-page .my-tickets-search-input {
                height: clamp(42px, 11vw, 48px);
                padding-left: 42px;
                font-size: clamp(12px, 3.4vw, 14px);
            }

            body.employee-my-task-page .my-tickets-search-icon {
                left: 15px;
                font-size: 15px;
            }

            body.employee-my-task-page .my-tickets-mobile-filter-btn {
                flex: 0 0 clamp(42px, 11vw, 48px);
                width: clamp(42px, 11vw, 48px);
                min-width: 0;
                min-height: clamp(42px, 11vw, 48px);
                padding: 0;
                border: 1px solid #d7e0ea;
                border-radius: 11px;
                background: #ffffff;
                color: #166534;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                cursor: pointer;
                box-sizing: border-box;
            }

            body.employee-my-task-page .my-tickets-filter-controls {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: clamp(8px, 2.5vw, 12px);
                width: 100%;
                min-width: 0;
                margin-top: 10px;
            }

            body.employee-my-task-page .my-tickets-filter-select-wrap,
            body.employee-my-task-page .my-tickets-filter-select {
                width: 100%;
                max-width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            body.employee-my-task-page .my-tickets-filter-select {
                height: clamp(40px, 10.5vw, 46px);
                padding-left: clamp(11px, 3vw, 15px);
                padding-right: 34px;
                font-size: clamp(12px, 3.2vw, 14px);
            }

            body.employee-my-task-page .my-tickets-filter-select-wrap::after {
                right: 13px;
            }

            body.employee-my-task-page .my-tickets-desktop-clear {
                display: none;
            }

            body.employee-my-task-page .my-tickets-reassignment-filter {
                margin-top: 10px;
            }

            body.employee-my-task-page .my-tickets-mobile-filter-summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                width: 100%;
                margin-top: 10px;
                padding: 0 2px 11px;
                border-bottom: 1px solid #dbe3ec;
                color: #64748b;
                font-size: clamp(11px, 3vw, 13px);
                font-weight: 600;
                box-sizing: border-box;
            }

            body.employee-my-task-page .my-tickets-mobile-filter-summary a {
                color: #166534;
                font-weight: 700;
                text-decoration: none;
                white-space: nowrap;
            }

            body.employee-my-task-page .tm-global-chat-fab {
                right: 12px;
                bottom: calc(12px + env(safe-area-inset-bottom, 0px));
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
                padding: 0 !important;
                border-radius: 999px;
                justify-content: center;
                gap: 0;
            }

            body.employee-my-task-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-my-task-page .tm-global-chat-fab i {
                font-size: 16px;
            }

            body.employee-my-task-page .table-responsive table {
                display: block;
                width: 100%;
                min-width: 0;
            }

            body.employee-my-task-page .table-responsive table thead {
                display: none;
            }

            body.employee-my-task-page .table-responsive table tbody {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
                width: 100%;
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row {
                --task-card-padding-x: clamp(13px, 4vw, 17px);
                --task-card-arrow-space: clamp(42px, 12vw, 52px);
                --task-card-header-space: 45px;
                position: relative;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 7px 8px;
                width: 100%;
                max-width: 100%;
                padding: var(--task-card-header-space) var(--task-card-padding-x) var(--task-card-padding-x);
                padding-right: var(--task-card-arrow-space);
                border-radius: 14px;
                background: #ffffff;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
                border: 1px solid #dde5ed;
                cursor: pointer;
                transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
                min-height: 0;
                box-sizing: border-box;
                overflow: hidden;
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row:hover {
                border-color: #1B5E20;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row:active {
                transform: scale(0.98);
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row td {
                display: block;
                padding: 0;
                border: none;
                text-align: left;
                min-width: 0;
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row td::before {
                display: none;
            }

            body.employee-my-task-page .task-ticket-id {
                position: absolute;
                top: 0;
                right: 0;
                left: 0;
                order: 1;
                display: flex !important;
                align-items: center;
                flex: none;
                width: auto;
                min-height: 36px;
                margin: 0;
                padding: 10px var(--task-card-padding-x) !important;
                border-radius: 13px 13px 0 0;
                background: #1B5E20 !important;
                font-size: clamp(12px, 3.4vw, 14px);
                line-height: 1.2;
                font-weight: 800;
                color: #ffffff !important;
                font-variant-numeric: tabular-nums;
                letter-spacing: 0.02em;
                box-sizing: border-box;
                z-index: 1;
            }

            body.employee-my-task-page .task-ticket-category {
                order: 2;
                flex: 0 0 100%;
                width: 100%;
                font-size: clamp(13px, 3.6vw, 15px);
                line-height: 1.25;
                color: #1f2937;
                font-weight: 700;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            body.employee-my-task-page .task-ticket-requester {
                order: 3;
                flex: 0 0 100%;
                width: 100%;
                margin-top: 2px;
                font-size: clamp(12px, 3.3vw, 13px);
                color: #1f2937;
                line-height: 1.3;
                overflow: hidden;
            }

            body.employee-my-task-page .task-ticket-requester .user-info {
                display: grid;
                gap: 2px;
            }

            body.employee-my-task-page .task-ticket-requester br {
                display: none;
            }

            body.employee-my-task-page .task-ticket-requester strong {
                font-size: clamp(13px, 3.7vw, 15px);
                line-height: 1.25;
                color: #111827;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            body.employee-my-task-page .task-ticket-requester small {
                display: block;
                max-width: 100%;
                font-size: clamp(11px, 3.1vw, 12px);
                line-height: 1.3;
                color: #475569;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            body.employee-my-task-page .task-ticket-date {
                order: 4;
                flex: 1 1 calc(50% - 4px);
                width: calc(50% - 4px);
                font-size: clamp(10px, 2.9vw, 12px);
                line-height: 1.2;
                color: #64748b;
                margin-top: 2px;
                font-weight: 600;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-my-task-page .task-ticket-arrow {
                display: block;
                position: absolute;
                top: 50%;
                right: clamp(14px, 4vw, 18px);
                transform: translateY(-50%);
                font-size: 25px;
                font-weight: 700;
                color: #64748b;
                line-height: 1;
            }

            body.employee-my-task-page .task-ticket-department {
                order: 5;
                flex: 1 1 calc(50% - 4px);
                width: calc(50% - 4px);
                display: block;
                color: #475569;
                font-size: clamp(10px, 2.9vw, 12px);
                line-height: 1.25;
                font-weight: 600;
                text-align: right;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-my-task-page .task-ticket-urgency,
            body.employee-my-task-page .task-ticket-status,
            body.employee-my-task-page .task-ticket-sla {
                order: 6;
                flex: 0 1 auto;
                display: inline-flex;
                align-items: center;
                width: max-content;
                max-width: 100%;
                margin: 3px 0 0;
                align-self: center;
                box-sizing: border-box;
            }

            body.employee-my-task-page .task-ticket-urgency .priority-pill,
            body.employee-my-task-page .task-ticket-status .status-pill,
            body.employee-my-task-page .task-ticket-sla .badge {
                min-width: 0;
                min-height: 27px;
                max-width: 100%;
                padding: 5px clamp(9px, 2.8vw, 12px);
                border-radius: 999px;
                font-size: clamp(10px, 2.9vw, 12px);
                line-height: 1;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            body.employee-my-task-page .table-responsive table tbody tr:not(.ticket-row) {
                display: block;
                width: 100%;
            }
        }

        @media (max-width: 359px) {
            body.employee-my-task-page .content-wrapper {
                padding-left: 8px;
                padding-right: 8px;
            }

            body.employee-my-task-page .my-tickets-filter-controls {
                grid-template-columns: minmax(0, 1fr);
                gap: 8px;
            }

            body.employee-my-task-page .table-responsive table tbody {
                gap: 10px;
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row {
                --task-card-padding-x: 12px;
                --task-card-arrow-space: 38px;
                --task-card-header-space: 43px;
                gap: 6px 7px;
                padding: var(--task-card-header-space) var(--task-card-arrow-space) 12px var(--task-card-padding-x);
                border-radius: 12px;
            }

            body.employee-my-task-page .task-ticket-id {
                border-radius: 11px 11px 0 0;
            }

            body.employee-my-task-page .task-ticket-arrow {
                right: 12px;
                font-size: 22px;
            }

            body.employee-my-task-page .task-ticket-urgency .priority-pill,
            body.employee-my-task-page .task-ticket-status .status-pill,
            body.employee-my-task-page .task-ticket-sla .badge {
                min-height: 25px;
                padding: 4px 8px;
            }
        }

        .task-flash-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            padding: 20px;
        }

        .task-flash-dialog {
            width: min(100%, 460px);
            background: #ffffff;
            border: 1px solid #fecaca;
            border-radius: 24px;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
            overflow: hidden;
        }

        .task-flash-topbar {
            height: 6px;
            background: linear-gradient(90deg, #dc2626, #f97316);
        }

        .task-flash-body {
            padding: 30px 30px 26px;
            text-align: center;
        }

        .task-flash-icon {
            width: 78px;
            height: 78px;
            margin: 0 auto 18px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff7ed;
            color: #ea580c;
            border: 2px solid #fdba74;
            font-size: 36px;
            font-weight: 800;
        }

        .task-flash-title {
            margin: 0 0 10px;
            font-size: 30px;
            line-height: 1.12;
            font-weight: 800;
            color: #1f2937;
        }

        .task-flash-message {
            margin: 0;
            font-size: 18px;
            line-height: 1.55;
            color: #475569;
        }

        .task-flash-actions {
            margin-top: 24px;
            display: flex;
            justify-content: center;
        }

        .task-flash-btn {
            min-width: 112px;
            height: 48px;
            border: none;
            border-radius: 14px;
            background: #166534;
            color: #ffffff;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 12px 28px rgba(22, 101, 52, 0.24);
        }

        .task-flash-btn:hover {
            background: #14532d;
        }
        .task-success-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3100;
            padding: 20px;
        }
        .task-success-dialog {
            width: min(100%, 430px);
            background: #ffffff;
            border-radius: 22px;
            padding: 28px 0 0;
            text-align: center;
            border: 1px solid rgba(27, 94, 32, 0.18);
            box-shadow: 0 26px 80px rgba(2, 6, 23, 0.22);
            position: relative;
            overflow: hidden;
        }
        .task-success-dialog.is-reassigned,
        .task-success-dialog.is-status-update {
            width: min(500px, calc(100vw - 48px));
            max-width: calc(100vw - 40px);
            height: auto;
            min-height: 284px;
            background: linear-gradient(180deg, #ffffff 0%, #fcfefd 100%);
            border-radius: 28px;
            padding: 30px 40px 28px;
            border: none;
            box-shadow: 0 28px 64px rgba(15, 23, 42, 0.16);
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .task-success-dialog.is-reassigned::before,
        .task-success-dialog.is-status-update::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 50% 10%, rgba(190, 242, 100, 0.24), transparent 22%),
                radial-gradient(circle at 50% 18%, rgba(34, 197, 94, 0.1), transparent 18%);
            pointer-events: none;
        }
        .task-success-dialog.is-reassigned .task-success-icon,
        .task-success-dialog.is-reassigned .task-success-title,
        .task-success-dialog.is-reassigned .task-success-message,
        .task-success-dialog.is-reassigned .task-success-actions,
        .task-success-dialog.is-status-update .task-success-icon,
        .task-success-dialog.is-status-update .task-success-title,
        .task-success-dialog.is-status-update .task-success-message,
        .task-success-dialog.is-status-update .task-success-actions {
            position: relative;
            z-index: 1;
        }
        .task-success-dialog.is-reassigned .task-success-icon,
        .task-success-dialog.is-status-update .task-success-icon {
            width: 66px;
            height: 66px;
            margin: 0 auto 16px;
            font-size: 22px;
        }
        .task-success-dialog.is-reassigned .task-success-title,
        .task-success-dialog.is-status-update .task-success-title {
            margin: 0 0 12px;
            padding: 0;
            font-size: 24px;
        }
        .task-success-dialog.is-reassigned .task-success-message,
        .task-success-dialog.is-status-update .task-success-message {
            margin: 0 auto 8px;
            padding: 0;
            max-width: 420px;
            font-size: 15px;
            line-height: 1.45;
        }
        .task-success-dialog.is-reassigned .task-success-ticket-line,
        .task-success-dialog.is-status-update .task-success-ticket-line {
            margin-top: 8px;
            font-size: 15px;
        }
        .task-success-dialog.is-reassigned .task-success-actions,
        .task-success-dialog.is-status-update .task-success-actions {
            width: 100%;
            min-height: 44px;
            margin-top: auto;
            padding: 18px 0 0;
            border-top: 1px solid #e6e8ef;
            background: transparent;
            box-sizing: border-box;
        }
        .task-success-dialog.is-reassigned .task-success-btn,
        .task-success-dialog.is-status-update .task-success-btn {
            width: 136px;
            min-width: 0;
            height: 40px;
            border: none;
            outline: none;
            border-radius: 12px;
            padding: 0 18px;
            font-size: 10px;
        }
        .task-success-dialog.is-reassigned .task-success-btn:focus,
        .task-success-dialog.is-reassigned .task-success-btn:focus-visible,
        .task-success-dialog.is-status-update .task-success-btn:focus,
        .task-success-dialog.is-status-update .task-success-btn:focus-visible {
            outline: none;
            box-shadow: none;
        }
        .task-success-dialog.is-status-update .task-success-meta {
            font-weight: 800;
        }
        .task-success-icon {
            width: 74px;
            height: 74px;
            margin: 8px auto 18px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            color: #1B5E20;
            border: 3px solid #d9f0cd;
            box-sizing: border-box;
            font-size: 34px;
            line-height: 1;
            font-weight: 600;
            box-shadow: 0 0 0 12px rgba(187, 247, 208, 0.22), 0 0 34px rgba(74, 222, 128, 0.28);
        }
        .task-success-title {
            margin: 0 0 10px;
            padding: 0 24px;
            font-size: 22px;
            line-height: 1.25;
            font-weight: 600;
            color: #303957;
        }
        .task-success-message {
            margin: 0;
            padding: 0 24px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }
        .task-success-meta {
            color: #3f4861;
            font-weight: 800;
            letter-spacing: -0.01em;
        }
        .task-success-ticket-id {
            display: inline;
            padding: 0;
            margin: 0 4px;
            color: #166534;
            font-weight: 800;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.01em;
        }
        .task-success-dialog.is-reassigned .task-success-meta,
        .task-success-dialog.is-reassigned .task-success-ticket-id,
        .task-success-dialog.is-status-update .task-success-meta,
        .task-success-dialog.is-status-update .task-success-ticket-id {
            font-weight: 800;
        }
        .task-success-message-line {
            display: block;
        }
        .task-success-ticket-line {
            display: block;
            margin-top: 12px;
            font-size: 18px;
            line-height: 1.2;
        }
        .task-success-status {
            display: inline;
            padding: 0;
            border-radius: 0;
            border: none;
            font-weight: 700;
            line-height: 1;
            vertical-align: baseline;
        }
        .task-success-status.is-in-progress {
            color: #166534;
        }
        .task-success-status.is-open {
            color: #eab308;
        }
        .task-success-status.is-resolved {
            color: #1d4ed8;
        }
        .task-success-actions {
            margin-top: 20px;
            padding: 18px 24px 22px;
            display: flex;
            justify-content: center;
            border-top: 1px solid #e2e8f0;
            background: #fbfdff;
        }
        .task-success-btn {
            min-width: 172px;
            height: 44px;
            border: none;
            border-radius: 10px;
            background: #1B5E20;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: none;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .task-success-btn:hover {
            background: #144a1e;
        }
        .task-success-btn:active {
            transform: translateY(1px);
        }

        body.employee-my-task-page #ticketModal .tm-control-label-department {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
            letter-spacing: 0;
            text-transform: none;
        }

        body.employee-my-task-page #ticketModal .tm-control-label-department .tm-required-star {
            color: #dc2626;
        }

        @media (min-width: 769px) {
            body.employee-my-task-page #ticketModal .tm-body {
                padding: 34px 46px 38px;
                gap: 24px;
            }
        }

        body.employee-my-task-page #ticketModal .modal-content,
        body.employee-my-task-page #ticketModal .modal-content *:not(i):not(svg):not(path):not(.tm-id) {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body.employee-my-task-page #tasksPagination .pagination-glass {
            flex-wrap: nowrap;
        }

        body.employee-my-task-page #tasksPagination .page-numbers {
            flex-wrap: nowrap;
        }

        body.employee-my-task-page #tasksPagination .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-weight: 700;
            padding: 0 4px;
            user-select: none;
        }

        @media (max-width: 767px) {
            body.employee-my-task-page #tasksPagination {
                margin-top: 16px;
                padding-top: 0;
            }

            body.employee-my-task-page #tasksPagination .pagination-glass {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                align-items: center;
                gap: 10px;
                min-height: 0;
                margin: 0;
                padding: 14px 0 4px;
            }

            body.employee-my-task-page #tasksPagination .pagination-summary {
                grid-column: 1 / -1;
                margin: 0;
                width: 100%;
                font-size: 14px;
                line-height: 1.35;
                text-align: left;
            }

            body.employee-my-task-page #tasksPagination .page-numbers {
                min-width: 0;
                overflow-x: auto;
                flex-wrap: nowrap;
                justify-content: flex-start;
                padding: 2px 2px 6px;
                scrollbar-width: none;
            }

            body.employee-my-task-page #tasksPagination .page-numbers::-webkit-scrollbar {
                display: none;
            }

            body.employee-my-task-page #tasksPagination .page-btn {
                flex: 0 0 auto;
                min-width: 44px;
                height: 44px;
                padding: 0 14px;
                border-radius: 999px;
                font-size: 14px;
            }

            body.employee-my-task-page #tasksPagination .page-btn.prev,
            body.employee-my-task-page #tasksPagination .page-btn.next {
                min-width: 44px;
                width: 44px;
                padding: 0;
                overflow: hidden;
                white-space: nowrap;
                color: transparent;
                position: relative;
            }

            body.employee-my-task-page #tasksPagination .page-btn.prev::before,
            body.employee-my-task-page #tasksPagination .page-btn.next::before {
                position: absolute;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #334155;
                font-size: 20px;
                font-weight: 900;
            }

            body.employee-my-task-page #tasksPagination .page-btn.prev::before {
                content: "\2039";
            }

            body.employee-my-task-page #tasksPagination .page-btn.next::before {
                content: "\203A";
            }
        }

        /* Assigned Tickets background-only override. */
        body.employee-my-task-page {
            background-image:
                radial-gradient(circle at -5% 16%, rgba(211, 237, 218, 0.78) 0 235px, transparent 236px),
                radial-gradient(circle at 101% 17%, rgba(232, 246, 235, 0.82) 0 150px, transparent 151px),
                radial-gradient(circle at 103% 54%, rgba(216, 240, 222, 0.8) 0 96px, transparent 97px),
                radial-gradient(circle at 99% 86%, rgba(219, 241, 224, 0.82) 0 150px, transparent 151px),
                radial-gradient(ellipse at -4% 103%, rgba(220, 241, 224, 0.76) 0 250px, transparent 251px),
                radial-gradient(circle at 3% 95%, transparent 0 74px, rgba(205, 230, 213, 0.28) 75px 77px, transparent 78px),
                radial-gradient(circle at 3% 96%, transparent 0 105px, rgba(205, 230, 213, 0.2) 106px 108px, transparent 109px),
                radial-gradient(circle at 94% 21%, transparent 0 180px, rgba(202, 229, 211, 0.25) 181px 183px, transparent 184px),
                radial-gradient(circle at 96% 24%, transparent 0 230px, rgba(202, 229, 211, 0.18) 231px 233px, transparent 234px),
                linear-gradient(145deg, rgba(239, 249, 242, 0.78) 0%, rgba(255, 255, 255, 0.98) 20%, rgba(255, 255, 255, 0.99) 60%, rgba(239, 249, 242, 0.84) 100%) !important;
            background-repeat: no-repeat !important;
            background-attachment: fixed !important;
        }

        body.employee-my-task-page::before,
        body.employee-my-task-page::after {
            content: "" !important;
            position: fixed !important;
            pointer-events: none !important;
            z-index: 0 !important;
            background-image: radial-gradient(circle, rgba(105, 163, 123, 0.2) 1.2px, transparent 1.55px);
        }

        body.employee-my-task-page::before {
            left: 3%;
            top: 112px;
            width: 118px;
            height: 128px;
            background-size: 15px 15px;
        }

        body.employee-my-task-page::after {
            right: 4%;
            top: 67%;
            width: 128px;
            height: 132px;
            background-size: 17px 17px;
        }

        body.employee-my-task-page .dashboard-container,
        body.employee-my-task-page .content-wrapper {
            position: relative !important;
            z-index: 1 !important;
            background: transparent !important;
        }

    </style>
</head>
<body class="employee-my-task-page">

    <!-- TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title"> Assigned Tickets </h1>
                <p class="page-subtitle">Tickets assigned to <strong><?= htmlspecialchars($user_department, ENT_QUOTES, 'UTF-8') ?></strong> department</p>
            </div>

            <!-- FILTERS CARD -->
            <div class="my-tickets-filter-card">
                <?php $has_visible_department_filter = $show_department_filter && array_key_exists($company_email, $allowed_departments_by_company); ?>
                <form method="GET" action="my_task.php" id="filterForm" class="my-tickets-filter-form <?= $has_visible_department_filter ? 'has-department-filter' : ''; ?>">
                    <div class="my-tickets-search-row">
                        <div class="my-tickets-search-wrapper">
                            <i class="fas fa-search my-tickets-search-icon" aria-hidden="true"></i>
                            <input
                                type="search"
                                name="search"
                                id="searchInput"
                                class="my-tickets-search-input"
                                placeholder="Search by ID, name, email or category..."
                                autocomplete="off"
                                value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                            >
                        </div>
                        <button type="button" class="my-tickets-mobile-filter-btn" aria-label="Ticket filters" aria-controls="mobileFilterControls">
                            <i class="fas fa-sliders-h" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="my-tickets-filter-controls" id="mobileFilterControls">
                    <div class="my-tickets-filter-select-wrap">
                        <select name="company_email" class="my-tickets-filter-select" id="filterCompany">
                            <option value="" <?= $company_email === '' ? 'selected' : '' ?> hidden>All Company</option>
                            <?php foreach ($company_filter_options as $companyValue => $companyLabel): ?>
                                <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= $company_email === $companyValue ? 'selected' : '' ?>><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($show_department_filter): ?>
                    <?php $department_filter_options = $allowed_departments_by_company[$company_email] ?? []; ?>
                    <div class="my-tickets-filter-select-wrap <?= $has_visible_department_filter ? '' : 'is-hidden' ?>" id="filterDepartmentWrap">
                        <select name="department" class="my-tickets-filter-select" id="filterDepartment" <?= $has_visible_department_filter ? '' : 'disabled' ?>>
                            <option value="" <?= $department === '' ? 'selected' : '' ?>>All Department</option>
                            <?php foreach ($department_filter_options as $d): ?>
                                <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $department === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="my-tickets-filter-select-wrap">
                        <select name="status" class="my-tickets-filter-select" id="filterStatus">
                            <option value="" <?= $status === '' ? 'selected' : '' ?> hidden>All Status</option>
                            <?php foreach ($allowed_statuses as $s): ?>
                                <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?= $status === $s ? 'selected' : '' ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="my-tickets-filter-select-wrap">
                        <select name="sla" class="my-tickets-filter-select" id="filterSla">
                            <option value="" <?= $sla === '' ? 'selected' : '' ?> hidden>All SLA</option>
                            <?php foreach ($allowed_slas as $slaOption): ?>
                                <option value="<?= htmlspecialchars($slaOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $sla === $slaOption ? 'selected' : '' ?>><?= htmlspecialchars($slaOption, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    </div>

                    <div class="my-tickets-mobile-filter-summary">
                        <span>3 Active Filters</span>
                        <a href="my_task.php">Clear all</a>
                    </div>

                    <div class="my-tickets-filter-select-wrap my-tickets-reassignment-filter">
                        <select name="reassignment" class="my-tickets-filter-select" id="filterReassignment">
                            <option value="" <?= $reassignment === '' ? 'selected' : '' ?> hidden>All Tickets</option>
                            <option value="handled_by_you" <?= $reassignment === 'handled_by_you' ? 'selected' : '' ?>>Handled by you</option>
                            <option value="not_reassigned" <?= $reassignment === 'not_reassigned' ? 'selected' : '' ?>>Team Tickets</option>
                            <option value="reassigned" <?= $reassignment === 'reassigned' ? 'selected' : '' ?>>Reassigned</option>
                        </select>
                    </div>

                    <a href="my_task.php" class="my-tickets-clear-btn my-tickets-desktop-clear">Clear Filters</a>

                </form>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="admin-table <?= (int) $total_records === 0 ? 'is-empty' : ''; ?>" id="tasksTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Urgency</th>
                                <th>Requested By</th>
                                <th>From</th>
                                <th>Status</th>
                                <th>SLA</th>
                                <th>Date Created</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tasksTbody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer;">
                                    <td class="task-ticket-id">#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    <td class="subject-cell task-ticket-category">
                                        <strong><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </td>
                                    <td class="task-ticket-urgency"><?= task_urgency_badge_html((string) ($row['priority'] ?? '')); ?></td>
                                    <td class="task-ticket-requester">
                                        <div class="user-info">
                                            <?php
                                                $dispName = isset($row['requester_name']) && $row['requester_name'] !== '' ? $row['requester_name'] : $row['user_name'];
                                                $dispEmail = isset($row['requester_email']) && $row['requester_email'] !== '' ? $row['requester_email'] : $row['user_email'];
                                                if ((!isset($row['requester_name']) || $row['requester_name'] === '') || (!isset($row['requester_email']) || $row['requester_email'] === '')) {
                                                    $descSrc = isset($row['description']) ? (string)$row['description'] : '';
                                                    if ($descSrc !== '') {
                                                        if (preg_match('/REQUESTER NAME:\s*(.+)$/im', $descSrc, $m)) {
                                                            $dispName = trim($m[1]);
                                                        }
                                                        if (preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $descSrc, $m2)) {
                                                            $dispEmail = trim($m2[1]);
                                                        }
                                                    }
                                                }
                                            ?>
                                            <strong><?= htmlspecialchars($dispName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                            <small><?= htmlspecialchars($dispEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                    </td>
                                    <td class="task-ticket-department"><?= htmlspecialchars(task_source_label($row), ENT_QUOTES, 'UTF-8'); ?></td>

                                    <td class="task-ticket-status">
                                        <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>

                                    <td class="task-ticket-sla"><?= task_sla_badge_html((string) ($row['created_at'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['priority'] ?? '')); ?></td>
                                    <td class="task-ticket-date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                    <td class="task-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                </tr>
                                <?php } ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; color: #94a3b8; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-tasks" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                                            <p>No tickets available for the selected filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION UI -->
                <div id="tasksPagination">
                <?php if ($total_records > 0): ?>
                <div class="pagination-glass">
                    <div class="pagination-summary">Showing <?= number_format($showing_from) ?> - <?= number_format($showing_to) ?> of <?= number_format((int) $total_records) ?> tickets</div>
                    <?php if ($total_pages > 1): ?>
                    <a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search); ?>&company_email=<?= urlencode($company_email); ?>&department=<?= urlencode($department); ?>&status=<?= urlencode($status); ?>&sla=<?= urlencode($sla); ?>&reassignment=<?= urlencode($reassignment); ?>" 
                       data-page="<?= max(1, $page - 1) ?>"
                       class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        &lsaquo; Previous
                    </a>

                    <div class="page-numbers">
                        <?php
                            $pagination_pages = [];
                            if ($total_pages <= 5) {
                                for ($i = 1; $i <= $total_pages; $i++) {
                                    $pagination_pages[] = $i;
                                }
                            } else {
                                $pagination_pages = [1];
                                $window_start = max(2, $page - 1);
                                $window_end = min($total_pages - 1, $page + 1);

                                if ($page <= 3) {
                                    $window_start = 2;
                                    $window_end = 3;
                                } elseif ($page >= $total_pages - 2) {
                                    $window_start = $total_pages - 2;
                                    $window_end = $total_pages - 1;
                                }

                                if ($window_start > 2) {
                                    $pagination_pages[] = 'ellipsis';
                                }
                                for ($i = $window_start; $i <= $window_end; $i++) {
                                    $pagination_pages[] = $i;
                                }
                                if ($window_end < $total_pages - 1) {
                                    $pagination_pages[] = 'ellipsis';
                                }
                                $pagination_pages[] = $total_pages;
                            }
                        ?>
                        <?php foreach ($pagination_pages as $pagination_item): ?>
                            <?php if ($pagination_item === 'ellipsis'): ?>
                                <span class="pagination-ellipsis">...</span>
                            <?php else: ?>
                                <a href="?page=<?= $pagination_item; ?>&search=<?= urlencode($search); ?>&company_email=<?= urlencode($company_email); ?>&department=<?= urlencode($department); ?>&status=<?= urlencode($status); ?>&sla=<?= urlencode($sla); ?>&reassignment=<?= urlencode($reassignment); ?>"
                                   data-page="<?= $pagination_item ?>"
                                   class="page-btn <?= ($pagination_item == $page) ? 'active' : ''; ?>">
                                    <?= $pagination_item; ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search); ?>&company_email=<?= urlencode($company_email); ?>&department=<?= urlencode($department); ?>&status=<?= urlencode($status); ?>&sla=<?= urlencode($sla); ?>&reassignment=<?= urlencode($reassignment); ?>" 
                       data-page="<?= min($total_pages, $page + 1) ?>"
                       class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next &rsaquo;
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                </div>

            </div>

        </div>
    </div>

    <!-- Ticket Details Modal (Admin Style) -->
    <div id="ticketModal" class="modal-overlay">
        <div class="modal-content" id="modalContent">
            <!-- Content injected via JS -->
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div id="imagePreviewModal" class="image-preview-modal" onclick="TMTicketModal.closeImagePreview(event)">
        <div class="preview-content">
            <button type="button" class="preview-close" onclick="TMTicketModal.closeImagePreview(event)" aria-label="Close preview">X</button>
            <button type="button" class="preview-nav preview-prev" onclick="TMTicketModal.stepImagePreview(-1)" aria-label="Previous attachment"><i class="fas fa-chevron-left"></i></button>
            <img id="previewImage" src="" alt="Preview" class="preview-image">
            <button type="button" class="preview-nav preview-next" onclick="TMTicketModal.stepImagePreview(1)" aria-label="Next attachment"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
    
    <?php if ($flashError !== ''): ?>
    <div id="taskFlashOverlay" class="task-flash-overlay" role="dialog" aria-modal="true" aria-labelledby="taskFlashTitle">
        <div class="task-flash-dialog">
            <div class="task-flash-topbar"></div>
            <div class="task-flash-body">
                <div class="task-flash-icon">!</div>
                <h2 id="taskFlashTitle" class="task-flash-title"><?= htmlspecialchars($flashErrorTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="task-flash-message"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="task-flash-actions">
                    <button type="button" class="task-flash-btn" id="taskFlashCloseBtn">OK</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
    <div id="taskSuccessOverlay" class="task-success-overlay" role="dialog" aria-modal="true" aria-labelledby="taskSuccessTitle">
        <div class="task-success-dialog<?= $isReassignedSuccess ? ' is-reassigned' : ($isStatusSuccess ? ' is-status-update' : ''); ?>">
            <div class="task-success-icon" aria-hidden="true">&#10003;</div>
            <h2 id="taskSuccessTitle" class="task-success-title">
                <?php if ($isReassignedSuccess): ?>
                    Ticket Reassigned Successfully
                <?php elseif ($isStatusSuccess): ?>
                    Ticket Updated Successfully
                <?php else: ?>
                    <?= strcasecmp($flashSuccess, 'No changes were made.') === 0 ? 'No changes were made' : 'The ticket has been updated'; ?>
                <?php endif; ?>
            </h2>
            <p class="task-success-message">
                <?php if ($isReassignedSuccess): ?>
                    <span class="task-success-message-line">The ticket has been assigned to a new recipient.</span>
                    <span class="task-success-message-line">They can now review and continue handling the request.</span>
                    <span class="task-success-ticket-line">
                        <span class="task-success-meta">Ticket ID:</span>
                        <span class="task-success-ticket-id">#<?= htmlspecialchars(str_pad((string) $flashSuccessTicketId, 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                <?php elseif ($isStatusSuccess && $flashSuccessTicketId > 0): ?>
                    <span class="task-success-message-line">The ticket has been updated.</span>
                    <span class="task-success-ticket-line">
                        <span class="task-success-meta">Ticket ID:</span>
                        <span class="task-success-ticket-id">#<?= htmlspecialchars(str_pad((string) $flashSuccessTicketId, 6, '0', STR_PAD_LEFT), ENT_QUOTES, 'UTF-8'); ?></span>
                        <span>is now</span>
                        <span class="task-success-status <?= $flashSuccessStatus === 'Open' ? 'is-open' : ($flashSuccessStatus === 'In Progress' ? 'is-in-progress' : 'is-resolved'); ?>">
                            <?= htmlspecialchars($flashSuccessStatus, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </span>
                <?php else: ?>
                    <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </p>
            <div class="task-success-actions">
                <button type="button" class="task-success-btn" id="taskSuccessCloseBtn"><?= ($isReassignedSuccess || $isStatusSuccess) ? 'Done' : 'Close'; ?></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="../js/employee-dashboard.js"></script>
    <script>
    window.TM_CURRENT_USER = <?php echo json_encode([
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['name'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'department' => $_SESSION['department'] ?? null,
        'company' => $_SESSION['company'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script>
        window.TM_HIDE_QUICK_TAGS = true;
        window.TM_DEPARTMENT_LABEL_TEXT = 'Assigned Department';
        window.TM_DEPARTMENT_REQUIRED = true;
        window.TM_SHOW_DEPARTMENT_USER_SELECT = true;
        window.TM_DEPARTMENT_USERS_ENDPOINT = 'ajax_department_users.php';
        window.TM_COMPANY_DEPARTMENT_OPTIONS = <?php echo json_encode(
            ticket_company_department_option_map(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
    </script>
    <script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
    <script>
        var typingTimer = null;
        var doneTypingInterval = 300;
        var filterForm = document.getElementById("filterForm");
        var searchInput = document.getElementById("searchInput");
        var tbodyEl = document.getElementById("tasksTbody");
        var tasksTableEl = document.getElementById("tasksTable");
        var paginationEl = document.getElementById("tasksPagination");
        var currentTasksPage = <?= (int) $page ?>;
        var tasksAutoRefreshMs = 10000;

        function taskModalOpen() {
            var overlay = document.getElementById('ticketModal');
            return !!(overlay && overlay.style.display === 'flex');
        }

        function refreshTasks(page, updateHistory) {
            if (!filterForm || !tbodyEl || !paginationEl) return;
            var params = new URLSearchParams(new FormData(filterForm));
            var nextPage = parseInt(page || currentTasksPage || 1, 10);
            if (!nextPage || nextPage < 1) nextPage = 1;
            params.set('page', String(nextPage));
            params.set('limit', '10');
            fetch('ajax_my_task_list.php?' + params.toString(), { method: 'GET', credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) return;
                    tbodyEl.innerHTML = data.rows_html || '';
                    paginationEl.innerHTML = data.pagination_html || '';
                    if (tasksTableEl) {
                        tasksTableEl.classList.toggle('is-empty', parseInt(data.total || 0, 10) <= 0);
                    }
                    currentTasksPage = parseInt(data.page || nextPage, 10) || 1;
                    if (updateHistory === false) return;
                    var url = new URL(window.location.href);
                    url.search = '';
                    params.forEach(function (v, k) { url.searchParams.set(k, v); });
                    url.searchParams.set('page', String(currentTasksPage));
                    history.replaceState({}, '', url.toString());
                })
                .catch(function () {});
        }

        function scheduleTasksRefresh() {
            if (document.hidden || taskModalOpen()) return;
            refreshTasks(currentTasksPage, false);
        }

        var taskDepartmentOptionsByCompany = {
            '@leadsagri.com': <?= json_encode(array_values($lapc_departments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
            '@malvedaholdings.com': <?= json_encode(array_values($mhc_departments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
        };

        function closeTaskFilterDropdowns(exceptWrap) {
            if (!filterForm) return;
            Array.prototype.slice.call(filterForm.querySelectorAll('.my-tickets-filter-select-wrap.is-open')).forEach(function (wrap) {
                if (exceptWrap && wrap === exceptWrap) return;
                wrap.classList.remove('is-open');
                var trigger = wrap.querySelector('.my-task-filter-trigger');
                if (trigger) trigger.setAttribute('aria-expanded', 'false');
            });
        }

        function syncTaskCustomSelect(selectEl) {
            if (!selectEl) return;
            var wrap = selectEl.closest('.my-tickets-filter-select-wrap');
            if (!wrap) return;
            var triggerText = wrap.querySelector('.my-task-filter-trigger-text');
            var menu = wrap.querySelector('.my-task-filter-menu');
            var selectedOption = selectEl.options[selectEl.selectedIndex] || null;
            var selectedValue = String(selectEl.value || '');
            if (triggerText) {
                triggerText.textContent = selectedOption ? selectedOption.textContent.trim() : '';
            }
            if (!menu) return;
            menu.innerHTML = '';
            Array.prototype.slice.call(selectEl.options).forEach(function (option) {
                if (option.hidden || option.disabled) return;
                var value = String(option.value || '');
                var item = document.createElement('li');
                var btn = document.createElement('button');
                var isSelected = value === selectedValue;
                btn.type = 'button';
                btn.className = 'my-task-filter-option' + (isSelected ? ' is-selected' : '');
                btn.setAttribute('role', 'option');
                btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                btn.setAttribute('data-value', value);
                btn.textContent = option.textContent.trim();
                btn.addEventListener('click', function (event) {
                    event.stopPropagation();
                    selectEl.value = value;
                    syncTaskCustomSelect(selectEl);
                    closeTaskFilterDropdowns();
                    selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                });
                btn.addEventListener('keydown', function (event) {
                    var options = Array.prototype.slice.call(menu.querySelectorAll('.my-task-filter-option'));
                    var index = options.indexOf(btn);
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeTaskFilterDropdowns();
                        var trigger = wrap.querySelector('.my-task-filter-trigger');
                        if (trigger) trigger.focus();
                    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        var nextIndex = event.key === 'ArrowDown'
                            ? (index + 1) % options.length
                            : (index - 1 + options.length) % options.length;
                        if (options[nextIndex]) options[nextIndex].focus();
                    }
                });
                item.appendChild(btn);
                menu.appendChild(item);
            });
        }

        function enhanceTaskFilterSelect(selectEl) {
            if (!selectEl || selectEl.dataset.customDropdown === '1') return;
            var wrap = selectEl.closest('.my-tickets-filter-select-wrap');
            if (!wrap) return;
            selectEl.dataset.customDropdown = '1';
            selectEl.classList.add('is-customized');
            wrap.classList.add('has-custom-dropdown');

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'my-task-filter-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.innerHTML = '<span class="my-task-filter-trigger-text"></span><i class="fas fa-chevron-down my-task-filter-trigger-icon" aria-hidden="true"></i>';

            var menu = document.createElement('ul');
            menu.className = 'my-task-filter-menu';
            menu.setAttribute('role', 'listbox');

            selectEl.insertAdjacentElement('afterend', trigger);
            trigger.insertAdjacentElement('afterend', menu);

            trigger.addEventListener('click', function (event) {
                event.stopPropagation();
                var willOpen = !wrap.classList.contains('is-open');
                closeTaskFilterDropdowns(willOpen ? wrap : null);
                wrap.classList.toggle('is-open', willOpen);
                trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
            trigger.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowDown' && event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                closeTaskFilterDropdowns(wrap);
                wrap.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
                var selected = menu.querySelector('.my-task-filter-option.is-selected');
                var first = menu.querySelector('.my-task-filter-option');
                if (selected) selected.focus();
                else if (first) first.focus();
            });
            selectEl.addEventListener('change', function () {
                syncTaskCustomSelect(selectEl);
            });
            syncTaskCustomSelect(selectEl);
        }

        function syncTaskDepartmentFilter() {
            var formEl = document.getElementById('filterForm');
            var companyEl = document.getElementById('filterCompany');
            var departmentEl = document.getElementById('filterDepartment');
            var departmentWrapEl = document.getElementById('filterDepartmentWrap');
            if (!companyEl || !departmentEl || !departmentWrapEl) return;
            var selectedCompany = String(companyEl.value || '').toLowerCase();
            var options = taskDepartmentOptionsByCompany[selectedCompany] || [];
            var hasDepartmentFilter = options.length > 0;
            var previousValue = String(departmentEl.value || '');
            if (!hasDepartmentFilter) {
                departmentEl.value = '';
            }
            departmentEl.innerHTML = '<option value="">All Department</option>';
            options.forEach(function (label) {
                var option = document.createElement('option');
                option.value = label;
                option.textContent = label;
                if (label === previousValue) option.selected = true;
                departmentEl.appendChild(option);
            });
            if (hasDepartmentFilter && previousValue && options.indexOf(previousValue) === -1) {
                departmentEl.value = '';
            }
            departmentWrapEl.classList.toggle('is-hidden', !hasDepartmentFilter);
            if (formEl) {
                formEl.classList.toggle('has-department-filter', hasDepartmentFilter);
            }
            departmentEl.disabled = !hasDepartmentFilter;
            syncTaskCustomSelect(departmentEl);
        }

        if (searchInput) {
            searchInput.addEventListener("input", function () {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(function () {
                    refreshTasks(1);
                }, doneTypingInterval);
            });
        }

        var filterCompanyEl = document.getElementById('filterCompany');
        var filterDepartmentEl = document.getElementById('filterDepartment');
        var filterStatusEl = document.getElementById('filterStatus');
        var filterSlaEl = document.getElementById('filterSla');
        var filterReassignmentEl = document.getElementById('filterReassignment');

        if (filterForm) {
            Array.prototype.slice.call(filterForm.querySelectorAll('.my-tickets-filter-select')).forEach(function (selectEl) {
                enhanceTaskFilterSelect(selectEl);
            });
        }

        if (filterCompanyEl) {
            filterCompanyEl.addEventListener('change', function() {
                syncTaskDepartmentFilter();
                refreshTasks(1);
            });
        }

        if (filterDepartmentEl) {
            filterDepartmentEl.addEventListener('change', function() {
                refreshTasks(1);
            });
        }

        if (filterStatusEl) {
            filterStatusEl.addEventListener('change', function() {
                refreshTasks(1);
            });
        }

        if (filterSlaEl) {
            filterSlaEl.addEventListener('change', function() {
                refreshTasks(1);
            });
        }

        if (filterReassignmentEl) {
            filterReassignmentEl.addEventListener('change', function() {
                refreshTasks(1);
            });
        }

        syncTaskDepartmentFilter();

        document.addEventListener('click', function (event) {
            if (!filterForm || filterForm.contains(event.target)) return;
            closeTaskFilterDropdowns();
        });

        document.addEventListener('click', function (e) {
            var row = e.target && e.target.closest ? e.target.closest('.ticket-row') : null;
            if (row && row.dataset && row.dataset.id) {
                var ticketId = row.dataset.id;
                TMTicketModal.open(ticketId);
            }
            var pageBtn = e.target && e.target.closest ? e.target.closest('#tasksPagination a.page-btn') : null;
            if (pageBtn) {
                e.preventDefault();
                if (pageBtn.classList.contains('disabled')) return;
                var nextPage = parseInt(pageBtn.getAttribute('data-page') || '', 10);
                if (!nextPage || nextPage < 1) return;
                refreshTasks(nextPage);
            }
        });

        function syncClosedTicketTabs() {
            var modal = document.getElementById('ticketModal');
            if (!modal || modal.style.display !== 'flex') return;
            var statusChip = modal.querySelector('.tm-header .tm-chip');
            if (!statusChip) return;
            var isClosed = String(statusChip.textContent || '').trim().toLowerCase() === 'closed';
            if (!isClosed) return;

            var updateTab = modal.querySelector('.tm-tab[data-tab="actions"]');
            var updateContent = modal.querySelector('#tab-actions');

            if (updateTab) updateTab.style.display = 'none';
            if (updateContent) updateContent.style.display = 'none';

            var activeTab = modal.querySelector('.tm-tab.active');
            if (activeTab && activeTab.getAttribute('data-tab') === 'actions') {
                TMTicketModal.switchTab('info');
            }
        }

        var ticketModalObserver = new MutationObserver(function () {
            syncClosedTicketTabs();
        });
        var ticketModalEl = document.getElementById('ticketModal');
        if (ticketModalEl) {
            ticketModalObserver.observe(ticketModalEl, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['style']
            });
        }
        
        var params = new URLSearchParams(window.location.search);
        var tid = params.get('ticket_id') || params.get('id');
        var openChat = params.get('chat') === '1';
        if (tid) {
            if (openChat && window.TMTicketModal && typeof window.TMTicketModal.openConversation === 'function') {
                TMTicketModal.openConversation(tid);
            } else {
                TMTicketModal.open(tid);
            }
        }
        setInterval(scheduleTasksRefresh, tasksAutoRefreshMs);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                scheduleTasksRefresh();
            }
        });

        (function () {
            var overlay = document.getElementById('taskFlashOverlay');
            if (!overlay) return;
            var closeBtn = document.getElementById('taskFlashCloseBtn');
            function closeFlash() {
                overlay.style.display = 'none';
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', closeFlash);
                closeBtn.focus();
            }
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeFlash();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display !== 'none') {
                    closeFlash();
                }
            });
        })();

        (function () {
            var overlay = document.getElementById('taskSuccessOverlay');
            if (!overlay) return;
            var closeBtn = document.getElementById('taskSuccessCloseBtn');
            function closeSuccess() {
                overlay.style.display = 'none';
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', closeSuccess);
                closeBtn.focus();
            }
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeSuccess();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display !== 'none') {
                    closeSuccess();
                }
            });
        })();
    </script>
</body>
</html>
