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
$status = trim((string) ($_GET['status'] ?? ''));
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 10);
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;
$offset = ($page - 1) * $limit;

$allowed_departments = ticket_standard_assigned_departments();
$allowed_statuses = ['Open', 'In Progress', 'Resolved'];
if (!in_array($department, $allowed_departments, true)) $department = '';
if (!in_array($status, $allowed_statuses, true)) $status = '';

$where = [];
$params = [];
$types = '';

$companyAliases = company_aliases_ajax((string) $user_company);
if (count($companyAliases) === 0) {
    $companyAliases = [(string) $user_company];
}
$companyCol = "COALESCE(NULLIF(t.assigned_company, ''), t.company)";
$companyAliases = array_values(array_filter(array_map('trim', $companyAliases), static function ($v) { return $v !== ''; }));
$companyAliasCond = count($companyAliases) > 0
    ? ("(" . implode(" OR ", array_fill(0, count($companyAliases), "$companyCol = ?")) . ")")
    : "(1=0)";
$companyCond = "(($companyCol LIKE '@%' AND LOWER(?) LIKE CONCAT('%', LOWER($companyCol))) OR ($companyCol NOT LIKE '@%' AND $companyAliasCond))";
$taskDeptExpr = "COALESCE(NULLIF(NULLIF(t.assigned_group, ''), NULLIF(t.assigned_department, 'Unassigned')), NULLIF(t.assigned_department, ''), NULLIF(t.department, ''), NULLIF(u.department, ''))";
$groupCond = "$taskDeptExpr = ?";

$where[] = "((t.assigned_user_id = ? AND t.user_id <> ?) OR (t.user_id <> ? AND $groupCond AND $companyCond))";
$params[] = $user_id;
$types .= "i";
$params[] = $user_id;
$types .= "i";
$params[] = $user_id;
$types .= "i";
$params[] = $user_department;
$types .= "s";
$params[] = strtolower((string) $user_email);
$types .= "s";
foreach ($companyAliases as $co) {
    $params[] = $co;
    $types .= "s";
}

$where[] = "t.status != 'Closed'";

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
    $where[] = "$taskDeptExpr = ?";
    $params[] = $department;
    $types .= "s";
}

if ($status !== '') {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
}

$sql = "SELECT t.*, u.name as user_name, u.email as user_email, u.department as user_department,
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
        $rowsHtml .= '<td class="task-ticket-department">' . h((string) (!empty($row['task_department']) ? $row['task_department'] : (!empty($row['department']) ? $row['department'] : ($row['user_department'] ?? 'Sales')))) . '</td>';
        $rowsHtml .= '<td class="task-ticket-status"><span class="status-' . strtolower(str_replace(' ', '-', (string) $row['status'])) . '">' . h((string) $row['status']) . '</span></td>';
        $rowsHtml .= '<td class="task-ticket-date">' . h(date("M d, Y", strtotime((string) $row['created_at']))) . '</td>';
        $rowsHtml .= '<td class="task-ticket-arrow" aria-hidden="true">&rsaquo;</td>';
        $rowsHtml .= '</tr>';
    }
} else {
    $rowsHtml = '<tr><td colspan="6" style="text-align:center; color: #94a3b8; padding: 40px;"><div class="empty-state"><i class="fas fa-tasks" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i><p>No tasks found for your department.</p></div></td></tr>';
}
$stmt->close();

$paginationHtml = '';
if ($totalPages > 1) {
    $paginationHtml .= '<div class="pagination-glass">';
    $paginationHtml .= '<a href="#" data-page="' . ($page - 1) . '" class="page-btn prev' . ($page <= 1 ? ' disabled' : '') . '">Previous</a>';
    $paginationHtml .= '<div class="page-numbers">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $paginationHtml .= '<a href="#" data-page="' . $i . '" class="page-btn' . ($i === $page ? ' active' : '') . '">' . $i . '</a>';
    }
    $paginationHtml .= '</div>';
    $paginationHtml .= '<a href="#" data-page="' . ($page + 1) . '" class="page-btn next' . ($page >= $totalPages ? ' disabled' : '') . '">Next</a>';
    $paginationHtml .= '</div>';
}

echo json_encode([
    'ok' => true,
    'rows_html' => $rowsHtml,
    'pagination_html' => $paginationHtml,
    'page' => $page,
    'total_pages' => $totalPages,
]);
