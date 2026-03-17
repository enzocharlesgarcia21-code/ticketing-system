<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

ticket_ensure_assignment_columns($conn);
ticket_apply_sla_priority($conn);

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function normalize_domain(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === '') return '';
    if ($v[0] !== '@') $v = '@' . $v;
    return $v;
}

function parseLegacyRequester(string $desc): array
{
    $name = '';
    $email = '';
    if ($desc !== '') {
        if (preg_match('/REQUESTER NAME:\s*(.+)$/im', $desc, $m)) {
            $name = trim((string) ($m[1] ?? ''));
        }
        if (preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $desc, $m2)) {
            $email = trim((string) ($m2[1] ?? ''));
        }
    }
    return [$name, $email];
}

function time_ago_days(string $dateTime): string
{
    $dateTime = trim($dateTime);
    if ($dateTime === '') return '';
    try {
        $created = new DateTimeImmutable($dateTime);
    } catch (Throwable $e) {
        return '';
    }
    $now = new DateTimeImmutable('now');
    $diff = $now->diff($created);
    $days = (int) ($diff->days ?? 0);
    if ($diff->invert !== 1) $days = 0;
    if ($days <= 0) return 'Today';
    if ($days === 1) return '1 day ago';
    return $days . ' days ago';
}

function sla_badge_html(string $createdAt, string $status): string
{
    $statusKey = strtolower(trim($status));
    if ($statusKey === 'resolved' || $statusKey === 'closed') return '-';
    $createdAt = trim($createdAt);
    if ($createdAt === '') return '-';
    try {
        $created = new DateTimeImmutable($createdAt);
    } catch (Throwable $e) {
        return '-';
    }
    $now = new DateTimeImmutable('now');
    $diff = $now->diff($created);
    $days = (int) ($diff->days ?? 0);
    if ($diff->invert !== 1) $days = 0;

    if ($days >= 7) {
        return '<span class="badge badge-critical">Breached</span>';
    }
    if ($days >= 4) {
        return '<span class="badge badge-high">At Risk</span>';
    }
    return '<span class="badge badge-low">On Track</span>';
}

$search = trim((string) ($_GET['search'] ?? ''));
$department = trim((string) ($_GET['department'] ?? ''));
$priority = trim((string) ($_GET['priority'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$companyEmail = trim((string) ($_GET['company_email'] ?? ''));
$view = trim((string) ($_GET['view'] ?? ''));
$view = $view === 'trash' ? 'closed' : $view;
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 5);

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 50) $limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types = '';

$allowedViews = ['all', 'my_open', 'unresolved', 'resolved', 'closed'];
if (!in_array($view, $allowedViews, true)) $view = '';
if ($view === 'closed') {
    $where[] = "t.status IN ('Closed','Trash')";
} else {
    $where[] = "COALESCE(NULLIF(t.status,''),'') NOT IN ('Closed','Trash')";
}
if ($view !== '') {
    if ($view === 'my_open') {
        $where[] = "t.status IN ('Open','In Progress')";
    } elseif ($view === 'unresolved') {
        $where[] = "t.status IN ('Open','In Progress')";
    } elseif ($view === 'resolved') {
        $where[] = "t.status = 'Resolved'";
    } elseif ($view === 'closed') {
        // already applied
    }
}

if ($department !== '') {
    $deptKey = ticket_department_key_from_value($department);
    $aliases = ticket_department_aliases_for_key($deptKey);
    $aliases[] = $deptKey;
    $aliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $aliases)), static function ($v) { return is_string($v) && $v !== ''; })));
    if (count($aliases) > 0) {
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $where[] = "UPPER(COALESCE(NULLIF(t.assigned_group,''), NULLIF(t.assigned_department,''), NULLIF(t.department,''), NULLIF(u.department,''))) IN ($placeholders)";
        foreach ($aliases as $a) {
            $params[] = $a;
            $types .= 's';
        }
    }
}

if ($priority !== '') {
    $where[] = "t.priority = ?";
    $params[] = $priority;
    $types .= 's';
}

if ($status !== '' && $view !== 'closed') {
    if ($status === 'unread') {
        $where[] = "t.is_read = 0";
    } else {
        $where[] = "t.status = ?";
        $params[] = $status;
        $types .= 's';
    }
}

if ($companyEmail !== '') {
    $domain = normalize_domain($companyEmail);
    if ($domain !== '') {
        $where[] = "LOWER(COALESCE(NULLIF(t.requester_email,''), u.email)) LIKE ?";
        $params[] = '%' . $domain;
        $types .= 's';
    }
}

if ($search !== '') {
    $term = '%' . $search . '%';
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int) $searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    $chunk = "(u.name LIKE ? OR u.email LIKE ? OR t.subject LIKE ? OR CAST(t.id AS CHAR) LIKE ?";
    $params[] = $term; $types .= 's';
    $params[] = $term; $types .= 's';
    $params[] = $term; $types .= 's';
    $params[] = $term; $types .= 's';
    if ($searchById) {
        $chunk .= " OR t.id = ?";
        $params[] = $searchIdInt;
        $types .= 'i';
    }
    $chunk .= ")";
    $where[] = $chunk;
}

$baseFrom = " FROM employee_tickets t JOIN users u ON t.user_id = u.id";
$whereSql = count($where) ? (" WHERE " . implode(" AND ", $where)) : "";

$countSql = "SELECT COUNT(*) as total" . $baseFrom . $whereSql;
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
$totalRow = $countRes ? $countRes->fetch_assoc() : null;
$countStmt->close();
$total = (int) ($totalRow['total'] ?? 0);
$totalPages = (int) ceil($total / $limit);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$sql = "SELECT t.*, u.name, u.email, u.department AS user_department" . $baseFrom . $whereSql . " ORDER BY t.created_at DESC LIMIT ?, ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$params2 = $params;
$types2 = $types;
$params2[] = $offset;
$types2 .= 'i';
$params2[] = $limit;
$types2 .= 'i';

$bind2 = [];
$bind2[] = $types2;
foreach ($params2 as $k => $p) {
    $bind2[] = &$params2[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bind2);
$stmt->execute();
$res = $stmt->get_result();

$rowsHtml = '';
while ($res && ($row = $res->fetch_assoc())) {
    $dispName = (string) (($row['requester_name'] ?? '') !== '' ? $row['requester_name'] : ($row['name'] ?? ''));
    $dispEmail = (string) (($row['requester_email'] ?? '') !== '' ? $row['requester_email'] : ($row['email'] ?? ''));
    if (($row['requester_name'] ?? '') === '' || ($row['requester_email'] ?? '') === '') {
        $descSrc = (string) ($row['description'] ?? '');
        [$pn, $pe] = parseLegacyRequester($descSrc);
        if (($row['requester_name'] ?? '') === '' && $pn !== '') $dispName = $pn;
        if (($row['requester_email'] ?? '') === '' && $pe !== '') $dispEmail = $pe;
    }

    $origDept = (string) (($row['department'] ?? '') !== '' ? $row['department'] : (($row['user_department'] ?? '') !== '' ? $row['user_department'] : 'Sales'));
    $assignedDept = ticket_department_key_from_value((string) ($row['assigned_department'] ?? ''));
    $isUnread = (int) ($row['is_read'] ?? 1) === 0;
    $id = (int) ($row['id'] ?? 0);
    $priorityVal = (string) ($row['priority'] ?? '');
    $statusVal = (string) ($row['status'] ?? '');
    $createdAt = (string) ($row['created_at'] ?? '');
    $dateStr = $createdAt !== '' ? time_ago_days($createdAt) : '';
    $slaHtml = sla_badge_html($createdAt, $statusVal);
    $rowsHtml .= '<tr class="ticket-row" data-id="' . (string) $id . '" style="cursor:pointer;' . ($isUnread ? 'background:rgba(27, 94, 32, 0.08);' : '') . '">';
    $rowsHtml .= '<td data-label="ID">#' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) . '</td>';
    $rowsHtml .= '<td data-label="Requested By"><div class="user-info"><strong>' . h($dispName) . '</strong><br><small>' . h($dispEmail) . '</small></div></td>';
    $rowsHtml .= '<td data-label="Priority"><span class="badge badge-' . h(strtolower($priorityVal)) . '">' . h($priorityVal) . '</span></td>';
    $rowsHtml .= '<td data-label="Status"><span class="status-' . h(strtolower(str_replace(' ', '-', $statusVal))) . '">' . h($statusVal) . '</span>' . ($isUnread ? '<span class="new-badge">NEW</span>' : '') . '</td>';
    $rowsHtml .= '<td data-label="Original Dept">' . h($origDept) . '</td>';
    $rowsHtml .= '<td data-label="Date">' . h($dateStr) . '</td>';
    $rowsHtml .= '<td data-label="SLA">' . $slaHtml . '</td>';
    $rowsHtml .= '<td data-label="Assign To">' . h((string) $assignedDept) . '</td>';
    $rowsHtml .= '</tr>';
}
$stmt->close();

$queryBase = [
    'search' => $search,
    'department' => $department,
    'company_email' => $companyEmail,
    'priority' => $priority,
    'status' => $status,
    'view' => $view,
];
$paginationHtml = '';
if ($totalPages > 1) {
    $prevParams = $queryBase;
    $prevParams['page'] = max(1, $page - 1);
    $nextParams = $queryBase;
    $nextParams['page'] = min($totalPages, $page + 1);

    $paginationHtml .= '<div class="pagination-glass">';
    $paginationHtml .= '<a href="?' . h(http_build_query($prevParams)) . '" data-page="' . (string) max(1, $page - 1) . '" class="page-btn prev ' . ($page <= 1 ? 'disabled' : '') . '">Previous</a>';
    $paginationHtml .= '<div class="page-numbers">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $p = $queryBase;
        $p['page'] = $i;
        $paginationHtml .= '<a href="?' . h(http_build_query($p)) . '" data-page="' . (string) $i . '" class="page-btn ' . ($i === $page ? 'active' : '') . '">' . (string) $i . '</a>';
    }
    $paginationHtml .= '</div>';
    $paginationHtml .= '<a href="?' . h(http_build_query($nextParams)) . '" data-page="' . (string) min($totalPages, $page + 1) . '" class="page-btn next ' . ($page >= $totalPages ? 'disabled' : '') . '">Next</a>';
    $paginationHtml .= '</div>';
}

echo json_encode([
    'ok' => true,
    'rows_html' => $rowsHtml,
    'pagination_html' => $paginationHtml,
    'page' => $page,
    'total_pages' => $totalPages,
    'total' => $total,
]);
