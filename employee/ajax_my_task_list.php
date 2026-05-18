<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function company_code_ajax(string $value): string
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

function company_aliases_ajax(string $value): array
{
    $v = trim($value);
    $code = company_code_ajax($v);
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

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_department = (string) ($_SESSION['department'] ?? '');
$user_company = (string) ($_SESSION['company'] ?? '');
$user_email = (string) ($_SESSION['email'] ?? '');

ticket_ensure_assignment_columns($conn);

if ($user_department === '' || $user_company === '') {
    $user_dept_stmt = $conn->prepare("SELECT department, company FROM users WHERE id = ?");
    if ($user_dept_stmt) {
        $user_dept_stmt->bind_param("i", $user_id);
        $user_dept_stmt->execute();
        $user_dept_result = $user_dept_stmt->get_result();
        if ($row = $user_dept_result->fetch_assoc()) {
            $user_department = $user_department !== '' ? $user_department : (string) ($row['department'] ?? '');
            $user_company = $user_company !== '' ? $user_company : (string) ($row['company'] ?? '');
        }
        $user_dept_stmt->close();
    }
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
}

$search = trim((string) ($_GET['search'] ?? ''));
$department = trim((string) ($_GET['department'] ?? ''));
$company_email = trim((string) ($_GET['company_email'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$sla = trim((string) ($_GET['sla'] ?? ''));
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 10);
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;
$offset = ($page - 1) * $limit;

$lapc_departments = ticket_lapc_departments();
$mhc_departments = ['Marketing Creatives'];
$allowed_departments_by_company = [
    '@leadsagri.com' => $lapc_departments,
    '@malvedaholdings.com' => $mhc_departments,
];
$company_filter_options = [
    '@leads-farmex.com' => 'FARMEX (@leads-farmex.com)',
    '@farmasee.ph' => 'FARMASEE (@farmasee.ph)',
    '@gpsci.net' => 'GPSCI (@gpsci.net)',
    '@leadsanimalhealth.com' => 'LAH (@leadsanimalhealth.com)',
    '@leadsagri.com' => 'LAPC (@leadsagri.com)',
    '@leads-eh.com' => 'LEH (@leads-eh.com)',
    '@leadsav.com' => 'LAV (@leadsav.com)',
    '@malvedaholdings.com' => 'MHC (@malvedaholdings.com)',
    '@malvedaproperties.com' => 'MPDC (@malvedaproperties.com)',
    '@leadstech-corp.com' => 'LTC (@leadstech-corp.com)',
    '@lingapleads.org' => 'LINGAP (@lingapleads.org)',
    '@primestocks.ph' => 'PCC (@primestocks.ph)',
];
$allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
$allowed_slas = ['On Track', 'At Risk', 'Breach'];
$selected_company_departments = $allowed_departments_by_company[$company_email] ?? [];
if (!array_key_exists($company_email, $allowed_departments_by_company) || !in_array($department, $selected_company_departments, true)) $department = '';
if (!array_key_exists($company_email, $company_filter_options)) $company_email = '';
if (!in_array($status, $allowed_statuses, true)) $status = '';
if (!in_array($sla, $allowed_slas, true) && !in_array($sla, ['Low', 'Medium', 'High'], true)) $sla = '';

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
    $map = [
        'Low' => 'On Track',
        'Medium' => 'At Risk',
        'High' => 'Breach',
    ];
    return $map[$slaLevel] ?? $slaLevel;
}

function task_normalize_sla_filter(string $sla): string
{
    $sla = trim($sla);
    $map = [
        'On Track' => 'Low',
        'At Risk' => 'Medium',
        'Breach' => 'High',
        'Low' => 'Low',
        'Medium' => 'Medium',
        'High' => 'High',
    ];
    return $map[$sla] ?? '';
}

function task_sla_badge_html(string $createdAt, string $status, string $priority = ''): string
{
    $statusKey = strtolower(trim($status));
    if ($statusKey === 'resolved' || $statusKey === 'closed') return '-';
    $priorityKey = strtolower(trim($priority));
    if ($priorityKey === 'critical') {
        return '<span class="badge badge-high">' . h(task_sla_display_label('High')) . '</span>';
    }
    if ($priorityKey === 'high') {
        return '<span class="badge badge-medium">' . h(task_sla_display_label('Medium')) . '</span>';
    }
    $createdAt = trim($createdAt);
    if ($createdAt === '') return '-';
    try {
        $created = new DateTimeImmutable($createdAt);
    } catch (Throwable $e) {
        return '-';
    }
    $now = new DateTimeImmutable('now');
    $createdDay = $created->setTime(0, 0, 0);
    $nowDay = $now->setTime(0, 0, 0);
    $diff = $nowDay->diff($createdDay);
    $days = (int) ($diff->days ?? 0);
    if ($diff->invert !== 1) $days = 0;

    if ($days >= 7) {
        return '<span class="badge badge-high">' . h(task_sla_display_label('High')) . '</span>';
    }
    if ($days >= 4) {
        return '<span class="badge badge-medium">' . h(task_sla_display_label('Medium')) . '</span>';
    }
    return '<span class="badge badge-low">' . h(task_sla_display_label('Low')) . '</span>';
}

function task_sla_filter_condition(string $sla): string
{
    $sla = task_normalize_sla_filter($sla);
    $activeStatus = "LOWER(TRIM(COALESCE(t.status, ''))) NOT IN ('resolved', 'closed')";
    $priority = "LOWER(TRIM(COALESCE(t.priority, '')))";
    $ageHigh = "DATE(t.created_at) <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $ageMedium = "DATE(t.created_at) <= DATE_SUB(CURDATE(), INTERVAL 4 DAY)";

    if ($sla === 'High') {
        return "($activeStatus AND ($priority = 'critical' OR ($priority NOT IN ('critical', 'high') AND $ageHigh)))";
    }
    if ($sla === 'Medium') {
        return "($activeStatus AND ($priority = 'high' OR ($priority NOT IN ('critical', 'high') AND $ageMedium AND NOT ($ageHigh))))";
    }
    if ($sla === 'Low') {
        return "($activeStatus AND $priority NOT IN ('critical', 'high') AND NOT ($ageMedium))";
    }
    return '';
}

$where = [];
$params = [];
$types = '';

$companyAliases = company_aliases_ajax((string) $user_company);
if (count($companyAliases) === 0) {
    $companyAliases = [(string) $user_company];
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

$where[] = "(((t.assigned_user_id = ? OR t.assigned_to = ?) AND NOT $requesterIsCurrentCond) OR (NOT $requesterIsCurrentCond AND $companyCond AND (((t.assigned_to IS NULL OR t.assigned_to = 0) AND LOWER(TRIM(COALESCE(t.status, ''))) NOT IN ('resolved', 'closed')) OR ((NOT $requiresGroupCond) OR $groupCond))))";
$params[] = $user_id;
$types .= "i";
$params[] = $user_id;
$types .= "i";
$params[] = $user_id;
$types .= "i";
$params[] = strtolower((string) $user_email);
$types .= "s";
$params[] = $user_id;
$types .= "i";
$params[] = strtolower((string) $user_email);
$types .= "s";
$params[] = strtolower((string) $user_email);
$types .= "s";
foreach ($companyAliases as $co) {
    $params[] = $co;
    $types .= "s";
}
foreach ($userDepartmentAliases as $departmentAlias) {
    $params[] = $departmentAlias;
    $types .= "s";
}

if ($search !== '') {
    $term = "%$search%";
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int) $searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    if ($searchById) {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR t.id = ?)";
        array_push($params, $term, $term, $term, $term, $term, $searchIdInt);
        $types .= "sssssi";
    } else {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        array_push($params, $term, $term, $term, $term, $term);
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
    $where[] = "LOWER($sourceCompanyExpr) = ?";
    $params[] = strtolower((string) $company_email);
    $types .= "s";
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

$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}
if ($types !== '') {
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $p) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$countStmt, 'bind_param'], $bind);
}
$countStmt->execute();
$countRes = $countStmt->get_result();
$countRow = $countRes ? $countRes->fetch_assoc() : null;
$countStmt->close();

$total = (int) ($countRow['total'] ?? 0);
$totalPages = (int) ceil($total / $limit);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$sql .= " ORDER BY t.created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$params2 = $params;
$types2 = $types . "ii";
$params2[] = $offset;
$params2[] = $limit;
$bind2 = [];
$bind2[] = $types2;
foreach ($params2 as $k => $p) {
    $bind2[] = &$params2[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bind2);
$stmt->execute();
$result = $stmt->get_result();

$rowsHtml = '';
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $dispName = isset($row['requester_name']) && $row['requester_name'] !== '' ? $row['requester_name'] : $row['user_name'];
        $dispEmail = isset($row['requester_email']) && $row['requester_email'] !== '' ? $row['requester_email'] : $row['user_email'];
        if ((!isset($row['requester_name']) || $row['requester_name'] === '') || (!isset($row['requester_email']) || $row['requester_email'] === '')) {
            $descSrc = isset($row['description']) ? (string) $row['description'] : '';
            if ($descSrc !== '') {
                if (preg_match('/REQUESTER NAME:\s*(.+)$/im', $descSrc, $m)) {
                    $dispName = trim((string) $m[1]);
                }
                if (preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $descSrc, $m2)) {
                    $dispEmail = trim((string) $m2[1]);
                }
            }
        }

        $rowsHtml .= '<tr class="ticket-row" data-id="' . (int) $row['id'] . '" style="cursor:pointer;">';
        $rowsHtml .= '<td class="task-ticket-id">#' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT) . '</td>';
        $rowsHtml .= '<td class="subject-cell task-ticket-category"><strong>' . h((string) $row['category']) . '</strong></td>';
        $rowsHtml .= '<td class="task-ticket-requester"><div class="user-info"><strong>' . h((string) $dispName) . '</strong><br><small>' . h((string) $dispEmail) . '</small></div></td>';
        $rowsHtml .= '<td class="task-ticket-department">' . h(task_source_label($row)) . '</td>';
        $rowsHtml .= '<td class="task-ticket-status"><span class="status-pill status-' . strtolower(str_replace(' ', '-', (string) $row['status'])) . '">' . h((string) $row['status']) . '</span></td>';
        $rowsHtml .= '<td class="task-ticket-sla">' . task_sla_badge_html((string) ($row['created_at'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['priority'] ?? '')) . '</td>';
        $rowsHtml .= '<td class="task-ticket-date">' . h(date("M d, Y", strtotime((string) $row['created_at']))) . '</td>';
        $rowsHtml .= '<td class="task-ticket-arrow" aria-hidden="true">&rsaquo;</td>';
        $rowsHtml .= '</tr>';
    }
} else {
    $rowsHtml = '<tr><td colspan="8" style="text-align:center; color: #94a3b8; padding: 40px;"><div class="empty-state"><i class="fas fa-tasks" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i><p>No tickets available for the selected filters.</p></div></td></tr>';
  }
$stmt->close();
$showingFrom = $total > 0 ? ($offset + 1) : 0;
$showingTo = min($offset + $limit, $total);

$paginationHtml = '';
if ($total > 0) {
    $paginationHtml .= '<div class="pagination-glass">';
    $paginationHtml .= '<div class="pagination-summary">Showing ' . number_format($showingFrom) . ' - ' . number_format($showingTo) . ' of ' . number_format($total) . ' tickets</div>';
    if ($totalPages > 1) {
        $paginationHtml .= '<a href="#" data-page="' . max(1, $page - 1) . '" class="page-btn prev' . ($page <= 1 ? ' disabled' : '') . '">&lsaquo; Previous</a>';
        $paginationHtml .= '<div class="page-numbers">';

        $paginationItems = [];
        if ($totalPages <= 5) {
            for ($i = 1; $i <= $totalPages; $i++) {
                $paginationItems[] = $i;
            }
        } else {
            $paginationItems = [1];
            $windowStart = max(2, $page - 1);
            $windowEnd = min($totalPages - 1, $page + 1);

            if ($page <= 3) {
                $windowStart = 2;
                $windowEnd = 3;
            } elseif ($page >= $totalPages - 2) {
                $windowStart = $totalPages - 2;
                $windowEnd = $totalPages - 1;
            }

            if ($windowStart > 2) {
                $paginationItems[] = 'ellipsis';
            }
            for ($i = $windowStart; $i <= $windowEnd; $i++) {
                $paginationItems[] = $i;
            }
            if ($windowEnd < $totalPages - 1) {
                $paginationItems[] = 'ellipsis';
            }
            $paginationItems[] = $totalPages;
        }

        foreach ($paginationItems as $paginationItem) {
            if ($paginationItem === 'ellipsis') {
                $paginationHtml .= '<span class="pagination-ellipsis">...</span>';
                continue;
            }

            $item = (int) $paginationItem;
            $paginationHtml .= '<a href="#" data-page="' . $item . '" class="page-btn' . ($item === $page ? ' active' : '') . '">' . $item . '</a>';
        }
        $paginationHtml .= '</div>';
        $paginationHtml .= '<a href="#" data-page="' . min($totalPages, $page + 1) . '" class="page-btn next' . ($page >= $totalPages ? ' disabled' : '') . '">Next &rsaquo;</a>';
    }
    $paginationHtml .= '</div>';
}

echo json_encode([
    'ok' => true,
    'rows_html' => $rowsHtml,
    'pagination_html' => $paginationHtml,
    'page' => $page,
    'total_pages' => $totalPages,
]);
