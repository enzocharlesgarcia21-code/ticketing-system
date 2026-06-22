<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/user_permissions.php';
require_once '../includes/activity_logger.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

$adminMgmtActivityJsonRequest = isset($_GET['admin_mgmt_activity_logs']);
if ($adminMgmtActivityJsonRequest) {
    ini_set('display_errors', '0');
    ob_start();
}

// Ensure email is in session
if (!isset($_SESSION['email']) && isset($_SESSION['user_id'])) {
    $u_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['user_id']);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $_SESSION['email'] = $u_row['email'];
    }
}

$message = '';

// Handle Promotion Logic
    // Moved to add_admin.php

    // 2. Remove Admin Logic
    // Moved to remove_admin.php

// Query IT Employees (with optional search)
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$queryBase = "SELECT id, name, email, department FROM users WHERE department = 'IT' AND role = 'employee'";
if ($search !== '') {
    $term = '%' . $search . '%';
    $search_stmt = $conn->prepare($queryBase . " AND (name LIKE ? OR email LIKE ?) ORDER BY name ASC LIMIT 6");
    $search_stmt->bind_param("ss", $term, $term);
    $search_stmt->execute();
    $result = $search_stmt->get_result();
    $search_stmt->close();
} else {
    $result = $conn->query($queryBase . " ORDER BY name ASC LIMIT 6");
}

// Query Current IT Admins
$admins_query = "SELECT id, name, email FROM users WHERE department = 'IT' AND role = 'admin'";
$admins_result = $conn->query($admins_query);

$users_departments_res = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department <> '' ORDER BY department ASC");
$user_departments = [];
if ($users_departments_res) {
    while ($d = $users_departments_res->fetch_assoc()) {
        $val = (string) ($d['department'] ?? '');
        if ($val !== '') $user_departments[] = $val;
    }
}

$users_companies_res = $conn->query("SELECT DISTINCT company FROM users WHERE company IS NOT NULL AND company <> '' ORDER BY company ASC");
$user_companies = [];
if ($users_companies_res) {
    while ($c = $users_companies_res->fetch_assoc()) {
        $val = (string) ($c['company'] ?? '');
        if ($val !== '') $user_companies[] = $val;
    }
}

$company_domain_options = [
    '@farmasee.ph' => 'FARMASEE',
    '@leads-farmex.com' => 'FARMEX / LAV',
    '@gpsci.net' => 'GPSCI',
    '@leadsagri.com' => 'LAPC',
    '@leadstech-corp.com' => 'LTC',
    '@lingapleads.org' => 'LINGAP',
    '@malvedaholdings.com' => 'MHC',
    '@malvedaproperties.com' => 'MPDC',
    '@primestocks.ph' => 'PCC'
];

function company_domain_option_label(string $domain, string $label): string
{
    if ($domain === '@leads-farmex.com') {
        return $label . ' (@leads-farmex.com / @leadsav.com)';
    }
    return $label . ' (' . $domain . ')';
}

$lapc_department_options = ticket_company_allowed_groups('@leadsagri.com');
$mhc_department_options = ticket_company_allowed_groups('@malvedaholdings.com');
$edit_user_company_options = ticket_request_company_options();
$edit_user_department_options = ticket_company_group_map();
$canManageUserAccess = user_permissions_can_manage($conn);
user_permissions_ensure_table($conn);

function admin_mgmt_format_activity_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') return '-';
    $ts = strtotime($value);
    return $ts ? date('M d, Y g:i A', $ts) : '-';
}

function admin_mgmt_user_status(array $user): array
{
    $lastSeen = trim((string) ($user['last_seen_at'] ?? ''));
    if ($lastSeen === '') return ['label' => 'Never opened', 'state' => 'never'];
    $lastSeenTs = strtotime($lastSeen);
    if (!$lastSeenTs) return ['label' => 'Unknown', 'state' => 'never'];
    $lastLogout = trim((string) ($user['last_logout_at'] ?? ''));
    $lastLogoutTs = $lastLogout !== '' ? strtotime($lastLogout) : false;
    $loggedOut = $lastLogoutTs !== false && $lastLogoutTs >= $lastSeenTs;
    $seconds = max(0, time() - $lastSeenTs);
    if ((int) ($user['is_online'] ?? 0) === 1 && !$loggedOut && $seconds <= 120) {
        return ['label' => 'Online', 'state' => 'online'];
    }
    if ($seconds < 60) return ['label' => 'Just now', 'state' => 'recent'];
    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) return ['label' => $minutes . ' min' . ($minutes === 1 ? '' : 's') . ' ago', 'state' => 'recent'];
    $hours = (int) floor($minutes / 60);
    if ($hours < 24) return ['label' => $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago', 'state' => 'away'];
    $days = (int) floor($hours / 24);
    return ['label' => $days . ' day' . ($days === 1 ? '' : 's') . ' ago', 'state' => 'offline'];
}

function admin_mgmt_table_has_column(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $allowedTables = ['users' => true, 'employee_tickets' => true, 'activity_logs' => true];
    if (!isset($allowedTables[$table])) return false;

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $safeColumn = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$safeColumn'");
    $cache[$key] = $res && $res->num_rows > 0;
    return $cache[$key];
}

function admin_mgmt_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload);
    exit;
}

function admin_mgmt_ticket_number(int $ticketId): string
{
    if (function_exists('notif_ticket_number')) {
        return (string) notif_ticket_number($ticketId);
    }
    return str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
}

function admin_mgmt_emit_user_activity(mysqli $conn): void
{
    header('Content-Type: application/json');

    activity_logs_ensure_table($conn);

    $userId = (int) ($_GET['user_id'] ?? 0);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = (int) ($_GET['limit'] ?? 10);
    if (!in_array($limit, [10, 25, 50], true)) $limit = 10;
    $offset = ($page - 1) * $limit;
    $type = trim((string) ($_GET['type'] ?? 'all'));
    $module = trim((string) ($_GET['module'] ?? 'all'));
    $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? ''));

    if ($userId <= 0) {
        admin_mgmt_json_response(['ok' => false, 'error' => 'Invalid user id'], 400);
    }

    $hasFullNameCol = admin_mgmt_table_has_column($conn, 'users', 'full_name');
    $hasSuperAdminCol = admin_mgmt_table_has_column($conn, 'users', 'is_super_admin');
    $displayNameExpr = $hasFullNameCol
        ? "COALESCE(NULLIF(full_name,''), NULLIF(name,''), '')"
        : "COALESCE(NULLIF(name,''), '')";
    $userSql = "SELECT id, {$displayNameExpr} AS display_name, name AS raw_name, email, department, role, created_at, last_seen_at, last_logout_at, COALESCE(is_online, 0) AS is_online";
    $userSql .= $hasSuperAdminCol ? ", COALESCE(is_super_admin, 0) AS is_super_admin" : ", 0 AS is_super_admin";
    $userSql .= " FROM users WHERE id = ? LIMIT 1";

    $userStmt = $conn->prepare($userSql);
    if (!$userStmt) {
        admin_mgmt_json_response(['ok' => false, 'error' => 'System error'], 500);
    }
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userRes = $userStmt->get_result();
    $user = $userRes ? $userRes->fetch_assoc() : null;
    $userStmt->close();

    if (!$user) {
        admin_mgmt_json_response(['ok' => false, 'error' => 'User not found'], 404);
    }

    $items = [];
    $activityWhere = ['user_id = ?'];
    $activityParams = [$userId];
    $activityTypes = 'i';
    if ($type !== '' && $type !== 'all') {
        $activityWhere[] = 'activity_type = ?';
        $activityParams[] = strtoupper($type);
        $activityTypes .= 's';
    }
    if ($module !== '' && $module !== 'all') {
        $activityWhere[] = 'module_name = ?';
        $activityParams[] = $module;
        $activityTypes .= 's';
    }
    if ($dateFrom !== '') {
        $activityWhere[] = 'created_at >= ?';
        $activityParams[] = $dateFrom . ' 00:00:00';
        $activityTypes .= 's';
    }
    if ($dateTo !== '') {
        $activityWhere[] = 'created_at <= ?';
        $activityParams[] = $dateTo . ' 23:59:59';
        $activityTypes .= 's';
    }
    if ($search !== '') {
        $activityWhere[] = 'activity_description LIKE ?';
        $activityParams[] = '%' . $search . '%';
        $activityTypes .= 's';
    }

    $activitySql = 'SELECT id, activity_type, activity_description, module_name, reference_id, ip_address, created_at FROM activity_logs WHERE ' . implode(' AND ', $activityWhere);
    if ($activityStmt = $conn->prepare($activitySql)) {
        $bind = [$activityTypes];
        foreach ($activityParams as $k => $p) $bind[] = &$activityParams[$k];
        call_user_func_array([$activityStmt, 'bind_param'], $bind);
        $activityStmt->execute();
        $activityRes = $activityStmt->get_result();
        while ($row = $activityRes ? $activityRes->fetch_assoc() : null) {
            if (!$row) break;
            $items[] = [
                'sort_at' => (string) $row['created_at'],
                'sort_id' => (int) $row['id'],
                'id' => (int) $row['id'],
                'activity_type' => (string) $row['activity_type'],
                'activity_description' => (string) $row['activity_description'],
                'module_name' => (string) $row['module_name'],
                'reference_id' => (string) ($row['reference_id'] ?? ''),
                'ip_address' => (string) ($row['ip_address'] ?? ''),
                'created_at' => (string) $row['created_at'],
                'created_at_display' => admin_mgmt_format_activity_datetime((string) $row['created_at']),
            ];
        }
        $activityStmt->close();
    }

    $allowTicketRows = ($type === '' || $type === 'all' || strtoupper($type) === 'TICKET_CREATED')
        && ($module === '' || $module === 'all' || $module === 'Tickets');
    if ($allowTicketRows) {
        $userEmail = strtolower(trim((string) ($user['email'] ?? '')));
        $hasRequesterEmail = admin_mgmt_table_has_column($conn, 'employee_tickets', 'requester_email');
        $hasTicketCategory = admin_mgmt_table_has_column($conn, 'employee_tickets', 'category');
        $hasTicketAssignedDepartment = admin_mgmt_table_has_column($conn, 'employee_tickets', 'assigned_department');
        $hasTicketAssignedGroup = admin_mgmt_table_has_column($conn, 'employee_tickets', 'assigned_group');
        $ticketSelect = [
            'id',
            'subject',
            ($hasTicketCategory ? 'category' : "'' AS category"),
            ($hasTicketAssignedDepartment ? 'assigned_department' : "'' AS assigned_department"),
            ($hasTicketAssignedGroup ? 'assigned_group' : "'' AS assigned_group"),
            'created_at',
        ];
        $ticketWhere = ['(user_id = ?' . ($hasRequesterEmail ? " OR LOWER(TRIM(COALESCE(requester_email, ''))) = ?" : '') . ')'];
        $ticketParams = [$userId];
        $ticketTypes = 'i';
        if ($hasRequesterEmail) {
            $ticketParams[] = $userEmail;
            $ticketTypes .= 's';
        }
        if ($dateFrom !== '') {
            $ticketWhere[] = 'created_at >= ?';
            $ticketParams[] = $dateFrom . ' 00:00:00';
            $ticketTypes .= 's';
        }
        if ($dateTo !== '') {
            $ticketWhere[] = 'created_at <= ?';
            $ticketParams[] = $dateTo . ' 23:59:59';
            $ticketTypes .= 's';
        }
        if ($search !== '') {
            $ticketSearchParts = ['subject LIKE ?', 'CAST(id AS CHAR) LIKE ?'];
            if ($hasTicketCategory) $ticketSearchParts[] = 'category LIKE ?';
            if ($hasTicketAssignedDepartment) $ticketSearchParts[] = 'assigned_department LIKE ?';
            if ($hasTicketAssignedGroup) $ticketSearchParts[] = 'assigned_group LIKE ?';
            $ticketWhere[] = '(' . implode(' OR ', $ticketSearchParts) . ')';
            for ($i = 0, $count = count($ticketSearchParts); $i < $count; $i++) {
                $ticketParams[] = '%' . $search . '%';
                $ticketTypes .= 's';
            }
        }

        $ticketSql = 'SELECT ' . implode(', ', $ticketSelect) . ' FROM employee_tickets WHERE ' . implode(' AND ', $ticketWhere);
        if ($ticketStmt = $conn->prepare($ticketSql)) {
            $bind = [$ticketTypes];
            foreach ($ticketParams as $k => $p) $bind[] = &$ticketParams[$k];
            call_user_func_array([$ticketStmt, 'bind_param'], $bind);
            $ticketStmt->execute();
            $ticketRes = $ticketStmt->get_result();
            while ($ticket = $ticketRes ? $ticketRes->fetch_assoc() : null) {
                if (!$ticket) break;
                $ticketId = (int) ($ticket['id'] ?? 0);
                $subject = trim((string) ($ticket['subject'] ?? 'Untitled ticket'));
                $category = trim((string) ($ticket['category'] ?? ''));
                $target = trim((string) (($ticket['assigned_group'] ?? '') !== '' ? $ticket['assigned_group'] : ($ticket['assigned_department'] ?? '')));
                $description = 'Created ticket #' . admin_mgmt_ticket_number($ticketId) . ': ' . $subject;
                if ($category !== '') $description .= ' (' . $category . ')';
                if ($target !== '') $description .= ' for ' . $target;
                $createdAt = (string) ($ticket['created_at'] ?? '');
                $items[] = [
                    'sort_at' => $createdAt,
                    'sort_id' => $ticketId,
                    'id' => 0,
                    'activity_type' => 'TICKET_CREATED',
                    'activity_description' => $description,
                    'module_name' => 'Tickets',
                    'reference_id' => (string) $ticketId,
                    'ip_address' => '',
                    'created_at' => $createdAt,
                    'created_at_display' => admin_mgmt_format_activity_datetime($createdAt),
                ];
            }
            $ticketStmt->close();
        }
    }

    $allowTicketWorkRows = ($type === '' || $type === 'all' || in_array(strtoupper($type), ['CLAIM_TICKET', 'STATUS_CHANGE', 'DEPARTMENT_CHANGE', 'COMPANY_CHANGE', 'NOTE_ADDED'], true))
        && ($module === '' || $module === 'all' || $module === 'Tickets');
    if ($allowTicketWorkRows) {
        if (function_exists('ticket_ensure_activity_table')) {
            ticket_ensure_activity_table($conn);
        }

        $hasTicketAssignedUserId = admin_mgmt_table_has_column($conn, 'employee_tickets', 'assigned_user_id');
        $hasTicketAssignedTo = admin_mgmt_table_has_column($conn, 'employee_tickets', 'assigned_to');
        $hasTicketCategory = admin_mgmt_table_has_column($conn, 'employee_tickets', 'category');
        $hasTicketAssignedDepartment = admin_mgmt_table_has_column($conn, 'employee_tickets', 'assigned_department');
        $hasTicketAssignedGroup = admin_mgmt_table_has_column($conn, 'employee_tickets', 'assigned_group');
        $ticketWorkSelect = [
            'ta.id AS activity_id',
            'ta.ticket_id',
            'ta.activity_type',
            'ta.description',
            'ta.created_at',
            't.subject',
            ($hasTicketCategory ? 't.category' : "'' AS category"),
            ($hasTicketAssignedDepartment ? 't.assigned_department' : "'' AS assigned_department"),
            ($hasTicketAssignedGroup ? 't.assigned_group' : "'' AS assigned_group"),
        ];
        $assigneeParts = [];
        $ticketWorkParams = [];
        $ticketWorkTypes = '';
        if ($hasTicketAssignedUserId) {
            $assigneeParts[] = 'COALESCE(t.assigned_user_id, 0) = ?';
            $ticketWorkParams[] = $userId;
            $ticketWorkTypes .= 'i';
        }
        if ($hasTicketAssignedTo) {
            $assigneeParts[] = 'COALESCE(t.assigned_to, 0) = ?';
            $ticketWorkParams[] = $userId;
            $ticketWorkTypes .= 'i';
        }

        $claimNames = array_values(array_unique(array_filter([
            trim((string) ($user['display_name'] ?? '')),
            trim((string) ($user['raw_name'] ?? '')),
        ], static function ($value) {
            return $value !== '';
        })));
        $actorParts = [];
        if (count($assigneeParts) > 0) {
            $actorParts[] = '((' . implode(' OR ', $assigneeParts) . ") AND ta.activity_type IN ('claim_ticket', 'status_change', 'department_change', 'company_change', 'note_added'))";
        }
        if (count($claimNames) > 0) {
            $claimParts = [];
            foreach ($claimNames as $claimName) {
                $claimParts[] = 'ta.description = ?';
                $ticketWorkParams[] = 'Claimed by ' . $claimName;
                $ticketWorkTypes .= 's';
            }
            $actorParts[] = "(ta.activity_type = 'claim_ticket' AND (" . implode(' OR ', $claimParts) . '))';
        }

        if (count($actorParts) > 0) {
            $ticketWorkWhere = ['(' . implode(' OR ', $actorParts) . ')'];
            if ($type !== '' && $type !== 'all') {
                $ticketWorkWhere[] = 'ta.activity_type = ?';
                $ticketWorkParams[] = strtolower($type);
                $ticketWorkTypes .= 's';
            }
            if ($dateFrom !== '') {
                $ticketWorkWhere[] = 'ta.created_at >= ?';
                $ticketWorkParams[] = $dateFrom . ' 00:00:00';
                $ticketWorkTypes .= 's';
            }
            if ($dateTo !== '') {
                $ticketWorkWhere[] = 'ta.created_at <= ?';
                $ticketWorkParams[] = $dateTo . ' 23:59:59';
                $ticketWorkTypes .= 's';
            }
            if ($search !== '') {
                $ticketWorkSearchParts = ['ta.description LIKE ?', 't.subject LIKE ?', 'CAST(ta.ticket_id AS CHAR) LIKE ?'];
                if ($hasTicketCategory) $ticketWorkSearchParts[] = 't.category LIKE ?';
                if ($hasTicketAssignedDepartment) $ticketWorkSearchParts[] = 't.assigned_department LIKE ?';
                if ($hasTicketAssignedGroup) $ticketWorkSearchParts[] = 't.assigned_group LIKE ?';
                $ticketWorkWhere[] = '(' . implode(' OR ', $ticketWorkSearchParts) . ')';
                for ($i = 0, $count = count($ticketWorkSearchParts); $i < $count; $i++) {
                    $ticketWorkParams[] = '%' . $search . '%';
                    $ticketWorkTypes .= 's';
                }
            }

            $ticketWorkSql = 'SELECT ' . implode(', ', $ticketWorkSelect) . ' FROM ticket_activity ta JOIN employee_tickets t ON t.id = ta.ticket_id WHERE ' . implode(' AND ', $ticketWorkWhere);
            if ($ticketWorkStmt = $conn->prepare($ticketWorkSql)) {
                if ($ticketWorkTypes !== '') {
                    $bind = [$ticketWorkTypes];
                    foreach ($ticketWorkParams as $k => $p) $bind[] = &$ticketWorkParams[$k];
                    call_user_func_array([$ticketWorkStmt, 'bind_param'], $bind);
                }
                $ticketWorkStmt->execute();
                $ticketWorkRes = $ticketWorkStmt->get_result();
                while ($work = $ticketWorkRes ? $ticketWorkRes->fetch_assoc() : null) {
                    if (!$work) break;
                    $ticketId = (int) ($work['ticket_id'] ?? 0);
                    $subject = trim((string) ($work['subject'] ?? 'Untitled ticket'));
                    $category = trim((string) ($work['category'] ?? ''));
                    $target = trim((string) (($work['assigned_group'] ?? '') !== '' ? $work['assigned_group'] : ($work['assigned_department'] ?? '')));
                    $rawDescription = trim((string) ($work['description'] ?? ''));
                    $description = $rawDescription !== '' ? $rawDescription : 'Updated ticket';
                    $description .= ' on ticket #' . admin_mgmt_ticket_number($ticketId) . ': ' . $subject;
                    if ($category !== '') $description .= ' (' . $category . ')';
                    if ($target !== '') $description .= ' for ' . $target;
                    $createdAt = (string) ($work['created_at'] ?? '');
                    $items[] = [
                        'sort_at' => $createdAt,
                        'sort_id' => (int) ($work['activity_id'] ?? 0),
                        'id' => 0,
                        'activity_type' => strtoupper((string) ($work['activity_type'] ?? 'TICKET_ACTIVITY')),
                        'activity_description' => $description,
                        'module_name' => 'Tickets',
                        'reference_id' => (string) $ticketId,
                        'ip_address' => '',
                        'created_at' => $createdAt,
                        'created_at_display' => admin_mgmt_format_activity_datetime($createdAt),
                    ];
                }
                $ticketWorkStmt->close();
            }
        }
    }

    usort($items, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['sort_at'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['sort_at'] ?? '')) ?: 0;
        if ($aTs === $bTs) return ((int) ($b['sort_id'] ?? 0)) <=> ((int) ($a['sort_id'] ?? 0));
        return $bTs <=> $aTs;
    });

    $total = count($items);
    $items = array_slice($items, $offset, $limit);
    foreach ($items as &$item) {
        unset($item['sort_at'], $item['sort_id']);
    }
    unset($item);

    $firstLogin = '';
    $lastLogin = '';
    if ($loginStmt = $conn->prepare("SELECT MIN(created_at) AS first_login, MAX(created_at) AS last_login FROM activity_logs WHERE user_id = ? AND activity_type = 'LOGIN'")) {
        $loginStmt->bind_param('i', $userId);
        $loginStmt->execute();
        $loginRes = $loginStmt->get_result();
        $loginRow = $loginRes ? $loginRes->fetch_assoc() : [];
        $firstLogin = (string) ($loginRow['first_login'] ?? '');
        $lastLogin = (string) ($loginRow['last_login'] ?? '');
        $loginStmt->close();
    }

    $status = admin_mgmt_user_status($user);
    $role = (string) ($user['role'] ?? '');
    $roleLabel = ((int) ($user['is_super_admin'] ?? 0) === 1) ? 'Super Admin' : ($role === 'admin' ? 'Admin' : 'Employee');

    admin_mgmt_json_response([
        'ok' => true,
        'user' => [
            'id' => (int) $user['id'],
            'name' => (string) ($user['display_name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'department' => (string) ($user['department'] ?? ''),
            'role' => $roleLabel,
            'status_label' => $status['label'],
            'status_state' => $status['state'],
        ],
        'summary' => [
            'account_created' => admin_mgmt_format_activity_datetime((string) ($user['created_at'] ?? '')),
            'first_login' => admin_mgmt_format_activity_datetime($firstLogin),
            'last_login' => admin_mgmt_format_activity_datetime($lastLogin),
            'last_active' => admin_mgmt_format_activity_datetime((string) ($user['last_seen_at'] ?? '')),
        ],
        'logs' => $items,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
    ]);
}

if ($adminMgmtActivityJsonRequest) {
    ini_set('display_errors', '0');
    if (ob_get_level() === 0) ob_start();
    try {
        admin_mgmt_emit_user_activity($conn);
    } catch (Throwable $e) {
        error_log('Admin management activity logs failed: ' . $e->getMessage());
        admin_mgmt_json_response(['ok' => false, 'error' => 'Unable to load activity logs.'], 500);
    }
}

activity_log($conn, (int) ($_SESSION['user_id'] ?? 0), 'OPEN_ADMIN_MANAGEMENT', 'Opened Admin Management', 'Admin Management');

?>

<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <title>Admin Management</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <style>
        .create-admin-container {
            padding: 20px 30px;
            max-width: 1380px;
            width: 95%;
            margin: 0 auto 40px;
        }
        .user-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 22px rgba(2, 6, 23, 0.08);
            margin-top: 0;
            border: 1px solid #e5e7eb;
        }
        .user-table th, .user-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .user-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .promote-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 10px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            min-width: 120px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .promote-btn:hover {
            background-color: #218838;
        }
        .alert-success {
            padding: 10px;
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        /* --- New Admin Grid Styles --- */
        .section-title {
            margin-top: 50px;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 600;
            color: #1B5E20; /* Primary Green */
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title::before {
            display: none;
        }

        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 18px;
        }

        .admin-card {
            background: white;
            border-radius: 14px;
            padding: 10px 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            overflow: hidden;
            pointer-events: none;
            min-height: 165px;
        }

        .admin-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px rgba(0,0,0,0.08);
            border-color: #1B5E20;
        }

        .admin-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1B5E20, #144a1e);
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            background-color: #e6f4ea;
            color: #1B5E20;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .admin-name {
            font-size: 11px;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 2px;
            line-height: 1.3;
        }

        .admin-email {
            font-size: 9px;
            color: #6B7280;
            margin-bottom: 6px;
            line-height: 1.35;
            word-break: break-word;
        }

        .admin-badge {
            background-color: #dcfce7;
            color: #166534;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #bbf7d0;
            margin-bottom: 6px;
        }

        .remove-admin-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 5px 7px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 9px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
            pointer-events: auto;
        }

        .remove-admin-btn:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }

        .promote-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }
        .promote-header-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #16a34a;
            flex: 0 0 auto;
        }
        .promote-header-title {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .promote-header-subtitle {
            margin-top: 6px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }
        .promote-header-subtitle:empty { display: none; }
        .search-row {
            margin: 14px 0 14px;
            display: flex;
            justify-content: flex-start;
        }
        .search-wrapper {
            position: relative;
            width: 100%;
            max-width: 520px;
        }
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .search-input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            background: #ffffff;
        }
        .search-input:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.15);
        }
        .table-card {
            background: transparent;
            min-width: 0;
            overflow-x: auto;
        }
        .employee-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .employee-avatar {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            background: #e2e8f0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #0f172a;
            flex: 0 0 auto;
        }
        .dept-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 8px;
            background: #e2e8f0;
            color: #334155;
            font-weight: 800;
            font-size: 12px;
        }
        .section-title .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #16a34a;
            display: inline-block;
        }

        .admin-mgmt-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .admin-mgmt-header h1 {
            margin: 0;
            font-size: 2.05rem;
            font-weight: 600;
            color: #111827;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }
        .admin-mgmt-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
            margin-bottom: 22px;
        }
        #usersListCard { width: 100%; }
        .mgmt-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .mgmt-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
            font-weight: 800;
            color: #0f172a;
        }
        .mgmt-card-header .title {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .mgmt-card-header .title .icon {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #16a34a;
            flex: 0 0 auto;
        }
        .mgmt-card-body { padding: 12px; }
        .form-grid {
            display: grid;
            grid-template-columns: 160px 1fr;
            gap: 18px 18px;
            align-items: start;
        }
        .form-label {
            font-weight: 800;
            color: #0f172a;
            font-size: 13px;
            padding-top: 14px;
            line-height: 1.35;
        }
        .form-required {
            color: #dc2626;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            outline: none;
            font-size: 14px;
            background: #ffffff;
            min-height: 48px;
            color: #0f172a;
        }
        .form-control:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.15);
        }
        .form-field-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }
        .field-hint {
            font-size: 12px;
            line-height: 1.45;
            color: #64748b;
            font-weight: 600;
            padding-left: 2px;
        }
        .fullname-row,
        .username-row,
        .password-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            gap: 12px;
            align-items: center;
        }
        .fullname-row > .form-control,
        .fullname-row > .domain-select,
        .username-row > .form-control,
        .username-row > .domain-select,
        .password-row > .password-field,
        .password-row > .btn.btn-auto {
            width: 100%;
            min-width: 0;
        }
        .password-row > .btn.btn-auto {
            width: 100%;
            min-width: 148px;
            justify-self: stretch;
        }
        .password-field {
            position: relative;
        }
        .password-field .form-control {
            padding-right: 44px;
        }
        .password-field .form-control::-ms-reveal,
        .password-field .form-control::-ms-clear {
            display: none;
        }
        .password-eye {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }
        .password-eye i { font-size: 13px; }
        .password-eye:hover {
            background: #eef2f7;
            color: #0f172a;
        }
        .form-options {
            margin-top: 6px;
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .checkbox-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14px;
            color: #374151;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 14px 16px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.85);
        }
        .checkbox-option-control {
            padding-top: 2px;
            flex: 0 0 auto;
        }
        .checkbox-option-copy {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
            flex: 1 1 auto;
        }
        .checkbox-option-text {
            cursor: pointer;
            user-select: none;
            font-weight: 800;
            color: #0f172a;
        }
        .checkbox-option-help {
            font-size: 12px;
            line-height: 1.45;
            color: #64748b;
            font-weight: 600;
        }
        .checkbox-option input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #16a34a;
        }
        .info-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #E5E7EB;
            font-size: 12px;
            cursor: help;
            color: #475569;
            font-weight: 800;
            flex: 0 0 auto;
            margin-top: 1px;
        }
        .domain-select {
            min-width: 170px;
            width: 100%;
            padding: 12px 44px 12px 14px;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background-color: #ffffff;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 12px 12px;
            font-weight: 700;
            color: #0f172a;
            cursor: pointer;
            box-sizing: border-box;
            font-size: 13px;
            min-height: 48px;
        }
        .domain-select:disabled {
            background-color: #f8fafc;
            border-color: #dbe4ee;
            color: #94a3b8;
            box-shadow: none;
            cursor: not-allowed;
        }
        .btn {
            border: 1px solid transparent;
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.08s ease, background 0.2s ease, border-color 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            user-select: none;
        }
        .btn:active { transform: translateY(1px); }
        .btn-primary {
            background: linear-gradient(180deg, #1f7a32 0%, #1B5E20 100%);
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(27, 94, 32, 0.18);
        }
        .btn-primary:hover { background: #144a1e; }
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border-color: #e5e7eb;
        }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-auto {
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            color: #1f2937;
            border-color: #dbe3ee;
            white-space: nowrap;
        }
        .btn-auto:hover { background: #f1f5f9; }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #eef2f7;
        }
        .users-list-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .users-list-controls .search-wrapper { flex: 1 1 480px; }
        .users-filters {
            display: flex;
            gap: 10px;
            flex: 0 0 auto;
            align-items: center;
        }
        .users-company-inline {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .users-dept-filter.is-hidden {
            display: none;
        }
        .users-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 1px solid #eef2f7;
        }
        .users-table-wrap {
            max-height: 420px;
            overflow: auto;
            border: 1px solid #eef2f7;
            border-radius: 12px;
        }
        #usersListCard .users-table-container {
            min-height: 320px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        #usersListCard .users-table-wrap {
            min-height: 320px;
            flex: 1 1 auto;
        }
        #usersListCard .users-table-footer {
            margin-top: auto;
        }
        .users-table { border-top: none; }
        .users-table { table-layout: fixed; }
        .users-table th:nth-child(1), .users-table td:nth-child(1) { width: 28%; text-align: center; }
        .users-table th:nth-child(2), .users-table td:nth-child(2) { width: 25%; text-align: center; }
        .users-table th:nth-child(3), .users-table td:nth-child(3) { width: 23%; text-align: center; }
        .users-table th:nth-child(4), .users-table td:nth-child(4) { width: 14%; text-align: center; }
        .users-table th:nth-child(5), .users-table td:nth-child(5) { width: 10%; text-align: right; }
        .users-table td {
            vertical-align: middle;
            overflow: hidden;
        }
        .users-table td:nth-child(5) {
            overflow: visible;
        }
        .users-cell {
            display: inline-block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }
        .users-name-wrap {
            display: flex;
            align-items: center;
            gap: 9px;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        .users-name-wrap .users-cell { min-width: 0; }
        .users-name-wrap .users-badge-current {
            max-width: 100%;
        }
        .users-badge-current {
            flex: 0 0 auto;
            font-size: 10px;
            font-weight: 900;
            padding: 4px 8px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            line-height: 1;
            white-space: nowrap;
        }
        .users-actions {
            position: relative;
            display: inline-flex;
            justify-content: flex-end;
            align-items: center;
            width: 100%;
        }
        .btn-icon-menu,
        .btn-icon-activity {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-icon-menu:hover,
        .btn-icon-activity:hover {
            background: #dcfce7;
            border-color: #86efac;
        }
        .users-action-menu {
            position: fixed;
            z-index: 10050;
            display: none;
            min-width: 178px;
            max-width: calc(100vw - 16px);
            padding: 6px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.16);
        }
        .users-actions.is-open .users-action-menu {
            display: grid;
            gap: 4px;
        }
        .users-action-item {
            width: 100%;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 800;
            text-align: left;
        }
        .users-action-item:hover {
            background: #f0fdf4;
            color: #166534;
        }
        .users-action-item.is-danger {
            color: #ef4444;
        }
        .users-action-item.is-danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }
        .users-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            max-width: 100%;
            color: #334155;
            font-weight: 800;
            white-space: nowrap;
        }
        .users-status-dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            flex: 0 0 auto;
            background: #111827;
            box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.08);
        }
        .users-status.is-online { color: #166534; }
        .users-status.is-online .users-status-dot {
            background: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.16);
        }
        .users-status.is-recent,
        .users-status.is-away { color: #92400e; }
        .users-status.is-recent .users-status-dot,
        .users-status.is-away .users-status-dot {
            background: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.16);
        }
        .users-status.is-never { color: #64748b; }
        .btn-icon-danger {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            color: #ef4444;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-icon-danger:hover {
            background: #fef2f2;
            border-color: #fecaca;
        }
        .users-table th, .users-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            color: #0f172a;
        }
        .users-table th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #1B5E20;
            background: #ffffff;
        }
        .users-empty {
            padding: 16px 12px;
            color: #64748b;
            text-align: center;
            font-weight: 700;
        }
        .users-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            padding: 10px 8px 2px;
        }
        .pagination-info {
            color: #64748b;
            font-weight: 700;
            font-size: 12px;
        }
        .pagination-controls {
            display: flex;
            gap: 12px;
            margin-left: auto;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .pagination-pages {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .page-btn {
            min-width: 42px;
            height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid #d8e2ec;
            cursor: pointer;
            background: #ffffff;
            color: #334155;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            user-select: none;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            transition: all 0.2s ease;
        }
        .page-btn:hover:not(.active):not(.disabled) { background: #f8fafc; transform: translateY(-1px); border-color: #cfd9e3; }
        .page-btn.active { background: #166534; color: #ffffff; border-color: #166534; box-shadow: 0 10px 24px rgba(22, 101, 52, 0.26); }
        .page-btn.disabled { opacity: 0.45; pointer-events: none; box-shadow: none; background: #ffffff; border-color: #d8e2ec; }
        .page-btn.prev,
        .page-btn.next {
            min-width: 84px;
            padding: 0 18px;
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
        .add-user-trigger {
            background: #166534;
            color: #ffffff;
            border: none;
            border-radius: 14px;
            padding: 15px 28px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s, filter 0.2s;
            box-shadow: 0 10px 24px rgba(13, 93, 34, 0.28);
        }
        .add-user-trigger:hover {
            transform: translateY(-2px);
            filter: brightness(0.98);
            box-shadow: 0 14px 28px rgba(13, 93, 34, 0.32);
        }

        .user-table {
            box-shadow: none;
            border-radius: 12px;
            border: 1px solid #eef2f7;
        }
        .user-table thead th {
            background: #ffffff;
            color: #1B5E20;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 12px;
        }
        .user-table tbody tr:hover td { background: #f8fafc; }

        .modal-overlay-lite {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px 22px;
            z-index: 12000;
            overflow-y: auto;
        }
        .modal-overlay-lite.show { display: flex; }
        .modal-card {
            width: 100%;
            max-width: 860px;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 22px 60px rgba(2, 6, 23, 0.25);
            overflow: hidden;
            position: relative;
            z-index: 12001;
            margin: 0 auto;
            max-height: calc(100vh - 44px);
            overflow-y: auto;
        }
        .modal-card .mgmt-card-body { padding: 18px; }
        .add-user-modal-card {
            max-width: 900px;
            border-radius: 22px;
        }
        .add-user-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 22px 24px 18px;
            border-bottom: 1px solid #ecf1f6;
            background:
                radial-gradient(circle at top right, rgba(34, 197, 94, 0.10), transparent 34%),
                linear-gradient(180deg, #f8fffa 0%, #f8fafc 100%);
        }
        .add-user-modal-title {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            min-width: 0;
        }
        .add-user-modal-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(180deg, #ecfdf5 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #16a34a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex: 0 0 auto;
            box-shadow: 0 10px 20px rgba(22, 163, 74, 0.10);
        }
        .add-user-modal-title h2 {
            margin: 0;
            font-size: 24px;
            line-height: 1.1;
            color: #0f172a;
            font-weight: 900;
        }
        .add-user-modal-title p {
            margin: 6px 0 0;
            font-size: 13px;
            line-height: 1.5;
            color: #64748b;
            font-weight: 600;
            max-width: 560px;
        }
        .add-user-modal-close {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            background: rgba(255,255,255,0.92);
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .add-user-modal-close:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .add-user-modal-card .mgmt-card-body {
            padding: 22px 24px 24px;
        }
        .access-modal-card .mgmt-card-body { padding: 14px; }
        .users-access-row {
            transition: background-color 0.18s ease, transform 0.18s ease;
        }
        .users-access-row.is-clickable {
            cursor: pointer;
        }
        .users-access-row.is-clickable:hover td {
            background: #f0fdf4;
        }
        .users-access-row.is-clickable:focus-within td,
        .users-access-row.is-clickable.is-active td {
            background: #ecfdf5;
        }
        .activity-drawer-overlay {
            position: fixed;
            inset: 0;
            z-index: 12000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px 22px;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
            overflow-y: auto;
        }
        .activity-drawer-overlay.show { display: flex; }
        .activity-drawer {
            width: min(920px, 100%);
            max-height: calc(100vh - 44px);
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 22px;
            box-shadow: 0 22px 60px rgba(2, 6, 23, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            z-index: 12001;
            margin: 0 auto;
        }
        .activity-drawer-head {
            padding: 22px 24px 18px;
            border-bottom: 1px solid #ecf1f6;
            background:
                radial-gradient(circle at top right, rgba(34, 197, 94, 0.10), transparent 34%),
                linear-gradient(180deg, #f8fffa 0%, #f8fafc 100%);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }
        .activity-drawer-title {
            margin: 0;
            color: #0f172a;
            font-size: 24px;
            line-height: 1.1;
            font-weight: 900;
        }
        .activity-drawer-close {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            background: rgba(255,255,255,0.92);
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }
        .activity-drawer-close:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .activity-drawer-body {
            padding: 22px 24px 24px;
            overflow: auto;
            flex: 1 1 auto;
            background: #f8fafc;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            align-items: stretch;
        }
        .activity-user-card,
        .activity-timeline-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
        }
        .activity-user-card {
            padding: 16px;
        }
        .activity-user-profile {
            display: flex;
            gap: 13px;
            align-items: flex-start;
        }
        .activity-avatar {
            width: 44px;
            height: 44px;
            border-radius: 999px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex: 0 0 auto;
        }
        .activity-user-main { min-width: 0; flex: 1 1 auto; }
        .activity-user-name {
            font-size: 15px;
            font-weight: 900;
            color: #0f172a;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .activity-user-email {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .activity-user-meta {
            margin-top: 12px;
            display: grid;
            gap: 7px;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }
        .activity-status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 999px;
            margin-right: 6px;
            background: #0f172a;
        }
        .activity-status-dot.is-online { background: #22c55e; }
        .activity-status-dot.is-recent,
        .activity-status-dot.is-away { background: #f59e0b; }
        .activity-summary-card {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e2e8f0;
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .activity-summary-label {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }
        .activity-summary-value {
            display: block;
            margin-top: 4px;
            color: #0f172a;
            font-size: 12px;
            font-weight: 800;
            line-height: 1.35;
        }
        .activity-timeline-card {
            padding: 16px;
            max-height: min(520px, calc(100vh - 210px));
            overflow: auto;
        }
        .activity-recent-title {
            margin: 0 0 12px;
            color: #0f172a;
            font-size: 15px;
            font-weight: 900;
        }
        .activity-timeline { display: grid; gap: 8px; }
        .activity-item {
            position: relative;
            padding-left: 18px;
            color: #334155;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.4;
        }
        .activity-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: #15803d;
        }
        .activity-item.is-warning::before { background: #f59e0b; }
        .activity-item.is-danger::before { background: #dc2626; }
        .activity-empty { padding: 22px 12px; text-align: center; color: #64748b; font-weight: 800; }
        .access-modal-card {
            max-width: 520px;
            margin-top: 0;
            margin-bottom: 18px;
        }
        .access-modal-shell-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(20, 83, 45, 0.28);
            background: linear-gradient(135deg, #14532d 0%, #166534 58%, #15803d 100%);
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .access-modal-shell-title {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 0;
        }
        .access-modal-shell-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.28);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            font-size: 16px;
        }
        .access-modal-shell-heading {
            margin: 0;
            color: #ffffff;
            font-size: 18px;
            font-weight: 900;
            line-height: 1.15;
        }
        .access-modal-shell-copy {
            margin-top: 4px;
            color: rgba(255, 255, 255, 0.86);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.4;
        }
        .access-modal-close {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex: 0 0 auto;
            transition: all 0.18s ease;
        }
        .access-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            color: #ffffff;
        }
        .access-modal-header-copy {
            display: grid;
            grid-template-columns: auto 1fr;
            column-gap: 12px;
            row-gap: 4px;
            align-items: center;
        }
        .access-modal-header-copy .icon {
            grid-row: 1 / span 2;
        }
        .access-modal-subtitle {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.5;
            grid-column: 2;
        }
        .access-user-chip {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 8px 10px;
            border-radius: 14px;
            background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 100%);
            border: 1px solid #dcfce7;
            margin-bottom: 10px;
        }
        .access-user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            background: #166534;
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 900;
            flex: 0 0 auto;
        }
        .access-user-name {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }
        .access-user-email,
        .access-user-role {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
        }
        .access-sections {
            display: grid;
            gap: 8px;
        }
        .access-section {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
        }
        .access-section-head {
            padding: 8px 10px;
            border-bottom: 1px solid #ecf0f4;
            background: #f8fafc;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #166534;
        }
        .access-toggle-list {
            display: grid;
            gap: 0;
        }
        .access-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 10px;
            border-top: 1px solid #f1f5f9;
        }
        .access-toggle-row:first-child {
            border-top: none;
        }
        .access-toggle-copy {
            min-width: 0;
        }
        .access-toggle-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
        }
        .access-toggle-meta {
            margin-top: 2px;
            color: #64748b;
            font-size: 10px;
            font-weight: 600;
            line-height: 1.2;
            word-break: break-word;
        }
        .switch {
            position: relative;
            display: inline-flex;
            width: 54px;
            height: 30px;
            flex: 0 0 auto;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .switch-slider {
            position: absolute;
            inset: 0;
            cursor: pointer;
            background: #cbd5e1;
            border-radius: 999px;
            transition: background 0.2s ease;
            box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.08);
        }
        .switch-slider::before {
            content: '';
            position: absolute;
            width: 24px;
            height: 24px;
            left: 3px;
            top: 3px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.14);
            transition: transform 0.2s ease;
        }
        .switch input:checked + .switch-slider {
            background: #16a34a;
        }
        .switch input:checked + .switch-slider::before {
            transform: translateX(24px);
        }
        .switch input:focus-visible + .switch-slider {
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.18);
        }
        .access-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 10px;
        }
        .access-modal-empty {
            padding: 18px 16px;
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            color: #64748b;
            text-align: center;
            font-weight: 700;
            background: #f8fafc;
        }
        .access-manage-note {
            margin-top: 10px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }
        .access-modal-card {
            max-width: 880px;
            border-radius: 20px;
            border: 1px solid rgba(220, 252, 231, 0.72);
            box-shadow: 0 28px 70px rgba(15, 23, 42, 0.30);
            overflow: hidden;
        }
        .access-modal-card .mgmt-card-body {
            padding: 20px 24px 0;
            background:
                radial-gradient(circle at 80% 18%, rgba(34, 197, 94, 0.08), transparent 32%),
                #ffffff;
        }
        .access-modal-shell-header {
            padding: 24px 30px;
            min-height: 108px;
            align-items: center;
            background: #16521f;
        }
        .access-modal-shell-title {
            align-items: center;
            gap: 18px;
        }
        .access-modal-shell-icon {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            font-size: 24px;
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.16);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.14);
        }
        .access-modal-shell-heading {
            font-size: 22px;
            letter-spacing: -0.02em;
        }
        .access-modal-shell-copy {
            max-width: 520px;
            font-size: 13px;
            line-height: 1.35;
        }
        .access-modal-close {
            width: 38px;
            height: 38px;
            border-radius: 14px;
        }
        .access-user-chip {
            padding: 16px 18px;
            border-radius: 18px;
            border-color: #d6f4df;
            margin-bottom: 20px;
            background:
                radial-gradient(circle at 100% 0%, rgba(34, 197, 94, 0.10), transparent 32%),
                linear-gradient(135deg, #f8fffb 0%, #ffffff 100%);
        }
        .access-user-avatar {
            width: 52px;
            height: 52px;
            font-size: 19px;
            background: linear-gradient(135deg, #15803d, #166534);
            box-shadow: 0 12px 24px rgba(22, 101, 52, 0.18);
        }
        .access-user-name {
            font-size: 16px;
        }
        .access-user-email,
        .access-user-role {
            font-size: 12px;
            line-height: 1.45;
        }
        .access-sections {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            align-items: start;
        }
        .access-column {
            display: grid;
            gap: 16px;
            align-content: start;
            min-width: 0;
        }
        .access-section {
            border-color: #dbe5ee;
            border-radius: 14px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.05);
        }
        .access-section-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            min-height: 58px;
            background: linear-gradient(180deg, #fbfefd 0%, #f8fafc 100%);
        }
        .access-section-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: #dcfce7;
            color: #15803d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex: 0 0 auto;
        }
        .access-section-title {
            display: block;
            color: #166534;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            line-height: 1.15;
        }
        .access-section-subtitle {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
            line-height: 1.25;
        }
        .access-toggle-row {
            min-height: 58px;
            padding: 12px 16px;
        }
        .access-toggle-title {
            font-size: 14px;
        }
        .access-toggle-meta {
            font-size: 11px;
        }
        .access-modal-actions {
            margin: 22px -24px 0;
            padding: 20px 24px;
            border-top: 1px solid #e5e7eb;
            background: #ffffff;
        }
        #saveUserAccess {
            background: #123f1b;
            box-shadow: 0 12px 24px rgba(18, 63, 27, 0.18);
        }
        #saveUserAccess:hover {
            background: #0f3517;
        }
        @media (max-width: 980px) {
            .admin-mgmt-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .activity-drawer {
                width: min(760px, 100%);
            }
            .activity-drawer-body {
                grid-template-columns: 1fr;
            }
            .activity-timeline-card {
                grid-column: auto;
                grid-row: auto;
                max-height: none;
            }
            .access-modal-card {
                max-width: min(880px, calc(100vw - 28px));
            }
            .access-sections {
                grid-template-columns: 1fr;
            }
            .access-toggle-row {
                align-items: flex-start;
            }
        }
        @media (max-width: 1200px) {
            .create-admin-container { width: 95%; }
        }
        @media (max-width: 900px) {
            .users-list-controls { flex-direction: column; }
            .users-list-controls .search-wrapper { flex: 1 1 auto; }
            .users-filters { width: 100%; }
        }
        @media (max-width: 720px) {
            .modal-overlay-lite {
                align-items: center;
                padding: 16px 12px;
            }
            .activity-drawer-overlay {
                padding: 16px 12px;
            }
            .activity-drawer {
                max-height: calc(100vh - 24px);
            }
            .activity-drawer-head {
                padding: 18px 18px 14px;
            }
            .activity-drawer-title {
                font-size: 20px;
            }
            .activity-drawer-body {
                padding: 18px;
            }
            .modal-card {
                max-height: calc(100vh - 24px);
            }
            .add-user-modal-header {
                padding: 18px 18px 14px;
                gap: 12px;
            }
            .add-user-modal-title h2 {
                font-size: 20px;
            }
            .add-user-modal-title p {
                font-size: 12px;
            }
            .add-user-modal-card .mgmt-card-body {
                padding: 18px;
            }
            .fullname-row,
            .username-row,
            .password-row {
                grid-template-columns: 1fr;
            }
            .access-user-chip {
                align-items: flex-start;
            }
            .access-modal-shell-header {
                padding: 14px;
            }
            .access-modal-shell-heading {
                font-size: 18px;
            }
            .access-modal-actions {
                flex-direction: column-reverse;
            }
            .access-modal-actions .btn {
                width: 100%;
            }
            .form-label {
                padding-top: 0;
            }
            .checkbox-option {
                padding: 12px 14px;
            }
            .form-actions {
                flex-direction: column-reverse;
            }
            .form-actions .btn {
                width: 100%;
            }
        }

        .admin-dashboard {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }
        .admin-bottom-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(480px, 0.95fr);
            gap: 18px;
            align-items: stretch;
            min-height: 560px;
        }
        .admin-bottom-grid > .mgmt-card {
            height: 100%;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .admin-bottom-grid > .mgmt-card > .mgmt-card-body {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .admin-bottom-grid > .mgmt-card .table-card { flex: 1 1 auto; }
        .admin-bottom-grid > .mgmt-card .admin-card-grid { flex: 1 1 auto; align-content: stretch; }
        .admin-bottom-grid > .mgmt-card .users-pagination {
            margin-top: auto;
            margin-left: 4px;
            margin-right: 4px;
            margin-bottom: 4px;
        }
        .admin-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            grid-auto-rows: minmax(0, 1fr);
            gap: 12px;
            min-height: 0;
        }
        #itAdminsGrid .admin-card {
            min-height: 0;
            height: 100%;
            justify-content: center;
        }
        .users-table th:nth-child(1), .users-table td:nth-child(1) { width: 28%; text-align: left; }
        .users-table th:nth-child(2), .users-table td:nth-child(2) { width: 25%; text-align: left; }
        .users-table th:nth-child(3), .users-table td:nth-child(3) { width: 23%; text-align: left; }
        .users-table th:nth-child(4), .users-table td:nth-child(4) { width: 14%; text-align: left; }
        .users-table th:nth-child(5), .users-table td:nth-child(5) { width: 10%; text-align: right; }
        .users-table tbody tr:hover td { background: #f8fafc; }
        .users-avatar {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex: 0 0 auto;
        }
        .users-name-block {
            display: inline-flex;
            flex-direction: column;
            flex: 1 1 90px;
            min-width: 0;
            max-width: 100%;
        }
        .users-name {
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
        }
        .users-subtle {
            color: #64748b;
            font-weight: 600;
            font-size: 12px;
            line-height: 1.1;
        }
        .dept-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
            border: 1px solid #e5e7eb;
            background: #f1f5f9;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dept-badge.dept-long,
        .dept-badge.dept-very-long,
        .dept-badge.dept-ultra-long {
            width: 100%;
        }
        .dept-badge.dept-long { font-size: 8px; padding-left: 5px; padding-right: 5px; }
        .dept-badge.dept-very-long { font-size: 7px; padding-left: 4px; padding-right: 4px; }
        .dept-badge.dept-ultra-long { font-size: 6px; padding-left: 3px; padding-right: 3px; }
        .dept-it { background: #dbeafe; border-color: #bfdbfe; color: #1d4ed8; }
        .dept-hr { background: #fef9c3; border-color: #fde68a; color: #854d0e; }
        .dept-admin { background: #dcfce7; border-color: #bbf7d0; color: #166534; }
        .dept-marketing { background: #ede9fe; border-color: #ddd6fe; color: #6d28d9; }
        .dept-accounting { background: #e0f2fe; border-color: #bae6fd; color: #0369a1; }
        .dept-supply-chain { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
        .dept-technical { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
        .dept-e-comm { background: #e0e7ff; border-color: #c7d2fe; color: #3730a3; }
        .dept-lingap { background: #cffafe; border-color: #a5f3fc; color: #0e7490; }

        .promote-table-card {
            width: 100%;
            overflow-x: hidden;
            overflow-y: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }
        .promote-table-card .user-table {
            table-layout: fixed;
            width: 100%;
            min-width: 0;
            margin: 0;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .promote-table-card .user-table th,
        .promote-table-card .user-table td {
            padding: 10px 10px;
            font-size: 12px;
            line-height: 1.25;
        }
        .promote-table-card .user-table th {
            font-size: 10.5px;
            letter-spacing: 0.03em;
        }
        .promote-table-card .user-table th:nth-child(1),
        .promote-table-card .user-table td:nth-child(1) { width: 25%; }
        .promote-table-card .user-table th:nth-child(2),
        .promote-table-card .user-table td:nth-child(2) { width: 40%; }
        .promote-table-card .user-table th:nth-child(3),
        .promote-table-card .user-table td:nth-child(3) { width: 15%; }
        .promote-table-card .user-table th:nth-child(4),
        .promote-table-card .user-table td:nth-child(4) {
            width: 20%;
            white-space: nowrap;
        }
        .promote-table-card .user-table td:nth-child(2) {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .promote-table-card .employee-cell {
            min-width: 0;
            gap: 8px;
        }
        .promote-table-card .employee-cell > span:last-child {
            min-width: 0;
            overflow-wrap: anywhere;
            font-size: 13px;
            line-height: 1.2;
        }
        .promote-table-card .employee-avatar {
            width: 30px;
            height: 30px;
            font-size: 13px;
        }
        .promote-table-card .dept-badge {
            padding: 5px 8px;
            font-size: 11px;
        }
        .promote-table-card .promote-btn {
            min-width: 0;
            max-width: 100%;
            padding: 8px 10px;
            font-size: 12px;
            border-radius: 8px;
            gap: 6px;
        }

        @media (max-width: 1100px) {
            .admin-bottom-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .admin-card-grid { grid-template-columns: 1fr; }
            .users-pagination {
                justify-content: center;
            }
            .pagination-info {
                width: 100%;
                text-align: center;
            }
            .pagination-controls {
                justify-content: center;
                margin-left: 0;
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

        .swal-delete-popup {
            border-radius: 22px;
            padding: 16px 0 0;
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.18);
            overflow: hidden;
        }

        .swal-delete-icon {
            width: 58px !important;
            height: 58px !important;
            margin: 0 auto 10px !important;
            border-width: 3px !important;
            color: #f6b26b !important;
            border-color: #f6b26b !important;
        }

        .swal-delete-title {
            font-size: 20px !important;
            font-weight: 700 !important;
            color: #20243a !important;
            line-height: 1.15 !important;
            padding: 0 22px !important;
            margin: 0 0 7px !important;
        }

        .swal-delete-html {
            font-size: 13px !important;
            line-height: 1.45 !important;
            color: #5b6275 !important;
            padding: 0 22px !important;
            margin: 0 0 14px !important;
        }

        .swal-delete-actions {
            width: 100%;
            gap: 12px;
            margin: 0 !important;
            padding: 14px 18px 18px !important;
            border-top: 1px solid #e6e8ef;
            justify-content: center;
        }

        .swal-delete-confirm,
        .swal-delete-cancel {
            min-width: 0;
            width: 154px;
            height: 42px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            margin: 0 !important;
            box-shadow: none !important;
        }

        .swal-delete-confirm {
            background: linear-gradient(180deg, #e54559 0%, #d6374d 100%) !important;
            color: #ffffff !important;
        }

        .swal-delete-cancel {
            background: linear-gradient(180deg, #eceef3 0%, #dfe3eb 100%) !important;
            color: #2e3345 !important;
        }

        .swal-delete-success-popup {
            border-radius: 22px;
            padding: 16px 0 0;
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.18);
            overflow: hidden;
        }

        .swal-delete-success-icon {
            width: 58px !important;
            height: 58px !important;
            margin: 0 auto 18px !important;
            border-width: 3px !important;
            color: #9bd67a !important;
            border-color: #d9f0cd !important;
        }

        .swal-delete-success-title {
            font-size: 20px !important;
            font-weight: 700 !important;
            color: #20243a !important;
            line-height: 1.2 !important;
            padding: 0 22px !important;
            margin: 0 0 10px !important;
        }

        .swal-delete-success-html {
            font-size: 13px !important;
            line-height: 1.45 !important;
            color: #5b6275 !important;
            padding: 0 22px !important;
            margin: 0 0 16px !important;
        }

        .swal-delete-success-actions {
            width: 100%;
            gap: 12px;
            margin: 0 !important;
            padding: 14px 18px 18px !important;
            border-top: 1px solid #e6e8ef;
            justify-content: center;
        }

        .swal-delete-success-confirm {
            min-width: 0;
            width: 154px;
            height: 42px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            margin: 0 !important;
            box-shadow: none !important;
            background: linear-gradient(180deg, #1f7a32 0%, #1b5e20 100%) !important;
            color: #ffffff !important;
        }

        .swal-admin-alert-popup {
            border-radius: 22px;
            padding: 16px 0 0;
            box-shadow: 0 18px 48px rgba(15, 23, 42, 0.18);
            overflow: hidden;
        }

        .swal-admin-alert-icon {
            width: 58px !important;
            height: 58px !important;
            margin: 0 auto 18px !important;
            border-width: 3px !important;
        }

        .swal-admin-alert-icon.swal2-warning,
        .swal-admin-alert-icon.swal2-question {
            color: #f6b26b !important;
            border-color: #f6b26b !important;
        }

        .swal-admin-alert-icon.swal2-success {
            color: #9bd67a !important;
            border-color: #d9f0cd !important;
        }

        .swal-admin-alert-icon.swal2-success .swal2-success-ring,
        .swal-admin-alert-icon.swal2-success [class^="swal2-success-line"],
        .swal-admin-alert-icon.swal2-success [class*=" swal2-success-line"] {
            display: none !important;
        }

        .swal-admin-alert-icon.swal2-success .swal2-icon-content {
            color: #8dcf6f !important;
            font-size: 34px !important;
            line-height: 1 !important;
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        .swal-admin-alert-icon.swal2-error {
            color: #e54559 !important;
            border-color: #f2b3bc !important;
        }

        .swal-admin-alert-title {
            font-size: 20px !important;
            font-weight: 700 !important;
            color: #20243a !important;
            line-height: 1.2 !important;
            padding: 0 22px !important;
            margin: 0 0 10px !important;
        }

        .swal-admin-alert-html {
            font-size: 13px !important;
            line-height: 1.45 !important;
            color: #5b6275 !important;
            padding: 0 22px !important;
            margin: 0 0 16px !important;
        }

        .swal-admin-alert-actions {
            width: 100%;
            gap: 12px;
            margin: 0 !important;
            padding: 14px 18px 18px !important;
            border-top: 1px solid #e6e8ef;
            justify-content: center;
        }

        .swal-admin-alert-confirm,
        .swal-admin-alert-cancel {
            min-width: 0;
            width: 154px;
            height: 42px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            margin: 0 !important;
            box-shadow: none !important;
        }

        .swal-admin-alert-confirm {
            background: linear-gradient(180deg, #1f7a32 0%, #1b5e20 100%) !important;
            color: #ffffff !important;
            border: 1px solid rgba(20, 74, 30, 0.28) !important;
        }

        .swal-admin-alert-cancel {
            background: linear-gradient(180deg, #eceef3 0%, #dfe3eb 100%) !important;
            color: #2e3345 !important;
            border: 1px solid rgba(100, 116, 139, 0.18) !important;
        }
    </style>
    <!-- Add FontAwesome for trash icon -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="create-admin-container">
        <div class="admin-mgmt-header">
            <h1>Admin Management</h1>
        </div>

        <div class="admin-dashboard">
        <div class="admin-mgmt-grid">
            <div class="mgmt-card" id="usersListCard">
                <div class="mgmt-card-header">
                    <div class="title">
                        <span class="icon"><i class="fas fa-users"></i></span>
                        <span>Users Management</span>
                    </div>
                    <button type="button" class="add-user-trigger" id="openAddUser">
                        <i class="fas fa-plus"></i>
                        Add User
                    </button>
                </div>
                <div class="mgmt-card-body">
                    <div class="users-list-controls">
                        <div class="search-wrapper" style="margin:0;">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" class="search-input" id="usersSearch" placeholder="Search user...">
                        </div>
                        <div class="users-filters">
                            <div class="users-company-inline">
                                <select class="domain-select" id="usersCompany">
                                    <option value="all" selected>All Companies</option>
                                    <?php foreach ($company_domain_options as $opt => $label): ?>
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select class="domain-select users-dept-filter" id="usersDept">
                                    <option value="all" selected>All Departments</option>
                                    <?php foreach ($lapc_department_options as $d): ?>
                                        <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-auto" id="clearUsersFilters">Clear</button>
                            </div>
                        </div>
                    </div>

                    <div class="users-table-container">
                        <div class="users-table-wrap">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="usersListBody">
                                    <tr><td class="users-empty" colspan="5">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="users-pagination users-table-footer" id="usersPagination" style="display:none;">
                            <div class="pagination-info" id="usersPaginationInfo"></div>
                            <div class="pagination-controls" id="usersPaginationControls"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="activity-drawer-overlay" id="activityDrawerOverlay" aria-hidden="true">
            <aside class="activity-drawer" role="dialog" aria-modal="true" aria-labelledby="activityDrawerTitle">
                <div class="activity-drawer-head">
                    <h2 class="activity-drawer-title" id="activityDrawerTitle">User Activity Timeline</h2>
                    <button type="button" class="activity-drawer-close" id="closeActivityDrawer" aria-label="Close activity logs">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="activity-drawer-body">
                    <section class="activity-user-card">
                        <div class="activity-user-profile">
                            <div class="activity-avatar" id="activityUserAvatar">?</div>
                            <div class="activity-user-main">
                                <div class="activity-user-name" id="activityUserName">Select a user</div>
                                <div class="activity-user-email" id="activityUserEmail">No user selected</div>
                                <div class="activity-user-meta">
                                    <div>Department: <span id="activityUserDepartment">-</span></div>
                                    <div>Role: <span id="activityUserRole">-</span></div>
                                    <div>Status: <span class="activity-status-dot" id="activityStatusDot"></span><span id="activityUserStatus">-</span></div>
                                </div>
                            </div>
                        </div>

                        <div class="activity-summary-card">
                            <div><span class="activity-summary-label">Account Created</span><span class="activity-summary-value" id="activityAccountCreated">-</span></div>
                            <div><span class="activity-summary-label">First Login</span><span class="activity-summary-value" id="activityFirstLogin">-</span></div>
                            <div><span class="activity-summary-label">Last Login</span><span class="activity-summary-value" id="activityLastLogin">-</span></div>
                        </div>
                    </section>

                    <section class="activity-timeline-card">
                        <h3 class="activity-recent-title">Recent Activities</h3>
                        <div class="activity-timeline" id="activityTimeline">
                            <div class="activity-empty">Select a user to load recent activities.</div>
                        </div>
                    </section>
                </div>
            </aside>
        </div>

        <div class="modal-overlay-lite" id="addUserModal" aria-hidden="true">
            <div class="modal-card add-user-modal-card">
                <div class="add-user-modal-header">
                    <div class="add-user-modal-title">
                        <span class="add-user-modal-icon"><i class="fas fa-user-plus"></i></span>
                        <div>
                            <h2>Add New User</h2>
                            <p>Create an employee account, assign its company domain, and set the initial login controls.</p>
                        </div>
                    </div>
                    <button type="button" class="add-user-modal-close" id="closeAddUserModal" aria-label="Close add user modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mgmt-card-body">
                    <form id="addUserForm" autocomplete="off" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="form-grid">
                            <div class="form-label">Account Email <span class="form-required">*</span></div>
                            <div class="form-field-stack">
                                <div class="username-row">
                                    <input type="text" class="form-control" name="username" id="username" placeholder="juan.delacruz" required>
                                    <select class="domain-select" name="domain" id="domain" required>
                                        <?php foreach ($company_domain_options as $opt => $label): ?>
                                            <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?= $opt === '@leadsagri.com' ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(company_domain_option_label($opt, $label), ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-label">Name <span class="form-required">*</span></div>
                            <div class="form-field-stack">
                                <div class="fullname-row">
                                    <input type="text" class="form-control" name="full_name" id="fullName" placeholder="Juan Dela Cruz" required inputmode="text" autocomplete="off">
                                    <select class="domain-select" name="department" id="newDept" aria-label="Department" required disabled>
                                        <option value="">Select Company First</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-label">Password <span class="form-required">*</span></div>
                            <div class="form-field-stack">
                                <div class="password-row">
                                    <div class="password-field">
                                        <input type="password" class="form-control" name="password" id="newPassword" placeholder="Create a temporary password" required>
                                        <button type="button" class="password-eye" id="togglePassword" aria-label="View password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <button type="button" class="btn btn-auto" id="autoGenerateBtn">Auto Generate</button>
                                </div>
                            </div>

                            <div class="form-options">
                                <div class="checkbox-option">
                                    <div class="checkbox-option-control">
                                        <input type="checkbox" name="send_credentials" id="sendCredentials" value="1">
                                    </div>
                                    <div class="checkbox-option-copy">
                                        <label class="checkbox-option-text" for="sendCredentials">Send credentials via email</label>
                                        <div class="checkbox-option-help">Automatically send the created login details to the new user's email address.</div>
                                    </div>
                                    <span class="info-icon" title="Automatically email the user's login credentials after account creation.">?</span>
                                </div>

                                <div class="checkbox-option">
                                    <div class="checkbox-option-control">
                                        <input type="checkbox" name="force_password_change" id="forcePasswordChange" value="1" checked>
                                    </div>
                                    <div class="checkbox-option-copy">
                                        <label class="checkbox-option-text" for="forcePasswordChange">Force user to change password on first login</label>
                                        <div class="checkbox-option-help">Keep this enabled to require a fresh password after the first successful sign-in.</div>
                                    </div>
                                    <span class="info-icon" title="User will be required to change their password the first time they log in.">?</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" id="cancelAddUser">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="createUserBtn">Create User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal-overlay-lite" id="editUserModal" aria-hidden="true">
            <div class="modal-card add-user-modal-card">
                <div class="add-user-modal-header">
                    <div class="add-user-modal-title">
                        <span class="add-user-modal-icon"><i class="fas fa-user-pen"></i></span>
                        <div>
                            <h2>Edit User</h2>
                            <p>Update the selected user's name, email address, and department.</p>
                        </div>
                    </div>
                    <button type="button" class="add-user-modal-close" id="closeEditUserModal" aria-label="Close edit user modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mgmt-card-body">
                    <form id="editUserForm" autocomplete="off" novalidate>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" id="editUserId" value="">
                        <div class="form-grid">
                            <div class="form-label">Name <span class="form-required">*</span></div>
                            <div class="form-field-stack">
                                <input type="text" class="form-control" name="name" id="editUserName" placeholder="Juan Dela Cruz" required>
                            </div>

                            <div class="form-label">Email <span class="form-required">*</span></div>
                            <div class="form-field-stack">
                                <input type="email" class="form-control" name="email" id="editUserEmail" placeholder="user@leadsagri.com" required>
                            </div>

                            <div class="form-label">Subsidiary <span class="form-required">*</span></div>
                            <div class="form-field-stack">
                                <select class="domain-select" name="company" id="editUserCompany" aria-label="Subsidiary" required>
                                    <?php foreach ($edit_user_company_options as $companyKey => $companyLabel): ?>
                                        <option value="<?= htmlspecialchars((string) $companyKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) $companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-label">Department</div>
                            <div class="form-field-stack">
                                <select class="domain-select" name="department" id="editUserDepartment" aria-label="Department">
                                    <option value="">No Department</option>
                                </select>
                            </div>
                        </div>
                        <div class="access-modal-actions">
                            <button type="button" class="btn btn-secondary" id="cancelEditUser">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="saveEditUser">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal-overlay-lite" id="userAccessModal" aria-hidden="true">
            <div class="modal-card access-modal-card">
                <div class="access-modal-shell-header">
                    <div class="access-modal-shell-title">
                        <span class="access-modal-shell-icon"><i class="fas fa-sliders-h"></i></span>
                        <div>
                            <h2 class="access-modal-shell-heading">User Access Control</h2>
                            <div class="access-modal-shell-copy">Manage which employee-side modules and navbar items are available for the selected user.</div>
                        </div>
                    </div>
                    <button type="button" class="access-modal-close" id="closeUserAccessModal" aria-label="Close access control modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mgmt-card-body">
                    <div class="access-user-chip" id="accessUserChip">
                        <span class="access-user-avatar" id="accessUserAvatar">?</span>
                        <div>
                            <div class="access-user-name" id="accessUserName">Select a user</div>
                            <div class="access-user-email" id="accessUserEmail">Choose a registered user to update module access.</div>
                            <div class="access-user-role" id="accessUserRole"></div>
                        </div>
                    </div>
                    <form id="userAccessForm" autocomplete="off" novalidate>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="user_id" id="accessUserId" value="">
                        <div class="access-sections" id="accessSections">
                            <div class="access-modal-empty">Select a registered user to load module access.</div>
                        </div>
                        <div class="access-manage-note" <?php echo $canManageUserAccess ? 'style="display:none;"' : ''; ?>>
                            Only the super admin can update per-user module access.
                        </div>
                        <div class="access-modal-actions">
                            <button type="button" class="btn btn-secondary" id="cancelUserAccess">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="saveUserAccess" <?php echo $canManageUserAccess ? '' : 'disabled'; ?>>Save Access</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="admin-bottom-grid">
            <div class="mgmt-card">
                <div class="mgmt-card-header">
                    <div class="title">
                        <span class="icon"><i class="fas fa-user-shield"></i></span>
                        <span>Promote IT Employees</span>
                    </div>
                </div>
                <div class="mgmt-card-body">
                    <?php if ($message): ?>
                        <div class="alert-success"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <form method="GET" class="search-row" id="itSearchForm" style="margin-top:0;">
                        <div class="search-wrapper" style="max-width: 100%;">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" name="search" class="search-input" id="itSearchInput" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search IT employee...">
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <button type="button" class="btn btn-auto" id="clearItSearch">Clear</button>
                        </div>
                    </form>

                    <div class="table-card promote-table-card">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th style="text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody id="itEmployeesBody">
                                <?php if ($result->num_rows > 0): ?>
                                    <?php while($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="employee-cell">
                                                    <span class="employee-avatar"><?= strtoupper(substr((string)$row['name'], 0, 1)) ?></span>
                                                    <span><?= htmlspecialchars($row['name']) ?></span>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($row['email']) ?></td>
                                            <td><span class="dept-badge dept-it">IT</span></td>
                                            <td style="text-align:right;">
                                                <button type="button" class="promote-btn" onclick="confirmAddition(<?= $row['id'] ?>)"><i class="fas fa-plus"></i> Promote</button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color:#6B7280; padding: 22px 12px;">No eligible IT employees found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="users-pagination" id="itPagination" style="display:none;">
                        <div class="pagination-info" id="itPaginationInfo"></div>
                        <div class="pagination-controls" id="itPaginationControls"></div>
                    </div>
                </div>
            </div>

            <div class="mgmt-card">
                <div class="mgmt-card-header">
                    <div class="title">
                        <span class="icon"><i class="fas fa-shield-halved"></i></span>
                        <span>Current IT Administrators</span>
                    </div>
                </div>
                <div class="mgmt-card-body">
                    <div class="admin-card-grid" id="itAdminsGrid">
                        <?php if ($admins_result->num_rows > 0): ?>
                            <?php while($admin = $admins_result->fetch_assoc()): ?>
                                <div class="admin-card">
                                    <div class="admin-avatar">
                                        <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                                    </div>
                                    <div class="admin-name"><?= htmlspecialchars($admin['name']) ?></div>
                                    <div class="admin-email"><?= htmlspecialchars($admin['email']) ?></div>
                                    <span class="admin-badge">ADMIN</span>

                                    <?php if ($admin['id'] != $_SESSION['user_id']): ?>
                                        <button type="button" class="remove-admin-btn" style="width: 100%; justify-content: center; margin-top: 6px;" onclick="confirmRemoval(<?= $admin['id'] ?>)">
                                            <i class="fa-solid fa-trash"></i> Remove Admin
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="remove-admin-btn" style="width: 100%; justify-content: center; margin-top: 6px; opacity: 0.5; cursor: not-allowed;" disabled>
                                            <i class="fa-solid fa-lock"></i> Current Admin
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="color: #6B7280; font-weight: 700;">No IT Admins found.</div>
                        <?php endif; ?>
                    </div>
                    <div class="users-pagination" id="itAdminsPagination" style="display:none;">
                        <div class="pagination-info" id="itAdminsPaginationInfo"></div>
                        <div class="pagination-controls" id="itAdminsPaginationControls"></div>
                    </div>
                </div>
            </div>
        </div>
        </div>

    </div>

</div>

<script src="../js/admin.js"></script>

<script>
    window.TM_ADMIN_CURRENT_USER_ID = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
    window.TM_CAN_MANAGE_USER_ACCESS = <?php echo $canManageUserAccess ? 'true' : 'false'; ?>;
    window.TM_USERS_PAGE_SIZE = 5;
    window.TM_IT_PAGE_SIZE = 6;
    window.TM_IT_ADMINS_PAGE_SIZE = 4;
    var companyDepartments = {
        "@leadsagri.com": <?php echo json_encode(array_values($lapc_department_options), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        "@gpsci.net": [],
        "@primestocks.ph": [],
        "@malvedaholdings.com": <?php echo json_encode(array_values($mhc_department_options), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        "@leads-farmex.com": [],
        "@leadstech-corp.com": [],
        "@farmasee.ph": [],
        "@malvedaproperties.com": [],
        "@lingapleads.org": [],
        "@leadsav.com": []
    };
    var editCompanyDepartments = <?php echo json_encode($edit_user_department_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var tmUsersState = { page: 1, limit: window.TM_USERS_PAGE_SIZE, total: 0, totalPages: 1 };
    var tmItState = { page: 1, limit: window.TM_IT_PAGE_SIZE, total: 0, totalPages: 1 };
    var tmItAdminsState = { page: 1, limit: window.TM_IT_ADMINS_PAGE_SIZE, total: 0, totalPages: 1 };

    function randomPassword(len) {
        var length = typeof len === 'number' && len > 0 ? len : 12;
        var lower = 'abcdefghjkmnpqrstuvwxyz';
        var upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        var nums = '23456789';
        var all = lower + upper + nums;
        function pick(set) { return set[Math.floor(Math.random() * set.length)]; }
        var out = [pick(lower), pick(upper), pick(nums)];
        for (var i = out.length; i < length; i++) out.push(pick(all));
        for (var j = out.length - 1; j > 0; j--) {
            var k = Math.floor(Math.random() * (j + 1));
            var tmp = out[j]; out[j] = out[k]; out[k] = tmp;
        }
        return out.join('');
    }

    function buildPaginationModel(page, totalPages) {
        var currentPage = Math.max(1, Number(page || 1));
        var pageCount = Math.max(1, Number(totalPages || 1));
        var items = [];

        if (pageCount <= 7) {
            for (var i = 1; i <= pageCount; i++) items.push(i);
            return items;
        }

        items.push(1);

        var windowStart = Math.max(2, currentPage - 1);
        var windowEnd = Math.min(pageCount - 1, currentPage + 1);

        if (currentPage <= 4) {
            windowStart = 2;
            windowEnd = 5;
        } else if (currentPage >= pageCount - 3) {
            windowStart = Math.max(2, pageCount - 4);
            windowEnd = pageCount - 1;
        }

        if (windowStart > 2) items.push('ellipsis');

        for (var p = windowStart; p <= windowEnd; p++) {
            items.push(p);
        }

        if (windowEnd < pageCount - 1) items.push('ellipsis');

        items.push(pageCount);
        return items;
    }

    function updateDepartmentDropdown() {
        var companyEl = document.getElementById('domain');
        var deptEl = document.getElementById('newDept');
        if (!companyEl || !deptEl) return;

        var selectedCompany = String(companyEl.value || '').trim();
        var departments = companyDepartments[selectedCompany] || [];
        var html = '';

        if (!selectedCompany) {
            html = '<option value="">Select Company First</option>';
            deptEl.disabled = true;
            deptEl.required = false;
        } else if (!departments.length) {
            html = '<option value="">No departments available</option>';
            deptEl.disabled = true;
            deptEl.required = false;
        } else {
            html = departments.map(function (department) {
                return '<option value="' + escapeHtml(String(department)) + '">' + escapeHtml(String(department)) + '</option>';
            }).join('');
            deptEl.disabled = false;
            deptEl.required = true;
        }

        deptEl.innerHTML = html;
        if (deptEl.disabled) {
            deptEl.value = '';
        } else if (departments.length) {
            deptEl.selectedIndex = 0;
        }
    }

    function renderUsers(users) {
        var body = document.getElementById('usersListBody');
        if (!body) return;
        if (!users || users.length === 0) {
            body.innerHTML = '<tr><td class="users-empty" colspan="5">No users found.</td></tr>';
            return;
        }
        function deptClass(dept) {
            var d = String(dept || '').trim().toLowerCase();
            if (!d) return '';
            if (d === 'it') return 'dept-it';
            if (d === 'hr') return 'dept-hr';
            if (d === 'admin') return 'dept-admin';
            if (d === 'marketing') return 'dept-marketing';
            if (d === 'accounting') return 'dept-accounting';
            if (d === 'supply chain') return 'dept-supply-chain';
            if (d === 'technical') return 'dept-technical';
            if (d === 'e-comm' || d === 'e-comm ') return 'dept-e-comm';
            if (d === 'lingap') return 'dept-lingap';
            return '';
        }
        body.innerHTML = users.map(function (u) {
            var dept = u.department ? String(u.department) : '-';
            var email = u.email ? String(u.email) : '-';
            var company = u.company ? String(u.company) : '';
            var id = u.id != null ? String(u.id) : '';
            var name = String(u.name || '');
            var role = String(u.role || '');
            var isCurrent = (Number(u.id) === Number(window.TM_ADMIN_CURRENT_USER_ID));
            var isAdmin = (role === 'admin');
            var isSuper = Number(u.is_super_admin || 0) === 1;
            var canManageAccess = !!window.TM_CAN_MANAGE_USER_ACCESS;
            var badges = [];
            if (isCurrent) badges.push('<span class="users-badge-current">Current</span>');
            if (isSuper) badges.push('<span class="users-badge-current">Super Admin</span>');
            var badge = badges.join('');
            var menuItems = [];
            menuItems.push('<button type="button" class="users-action-item users-activity" data-id="' + escapeHtml(id) + '" data-name="' + escapeHtml(name) + '"><i class="fas fa-chart-line"></i><span>Activity Logs</span></button>');
            if (canManageAccess) {
                menuItems.push('<button type="button" class="users-action-item users-edit" data-id="' + escapeHtml(id) + '" data-name="' + escapeHtml(name) + '" data-email="' + escapeHtml(email) + '" data-company="' + escapeHtml(company) + '" data-department="' + escapeHtml(dept === '-' ? '' : dept) + '"><i class="fas fa-user-pen"></i><span>Edit</span></button>');
            }
            if (!isCurrent && !isAdmin) {
                menuItems.push('<button type="button" class="users-action-item users-del is-danger" data-id="' + escapeHtml(id) + '" data-name="' + escapeHtml(name) + '"><i class="fas fa-trash"></i><span>Delete</span></button>');
            }
            var action = menuItems.length
                ? '<span class="users-actions"><button type="button" class="btn-icon-menu users-action-toggle" aria-label="Open user actions" aria-expanded="false"><i class="fas fa-ellipsis-h"></i></button><span class="users-action-menu">' + menuItems.join('') + '</span></span>'
                : '';
            var initial = name ? name.trim().charAt(0).toUpperCase() : '?';
            var deptCls = deptClass(dept);
            var deptLen = dept.length;
            var deptSizeCls = deptLen >= 36 ? 'dept-ultra-long' : (deptLen >= 28 ? 'dept-very-long' : (deptLen >= 20 ? 'dept-long' : ''));
            var deptBadge = '<span class="dept-badge ' + deptCls + ' ' + deptSizeCls + '" title="' + escapeHtml(dept) + '">' + escapeHtml(dept) + '</span>';
            var statusLabel = String(u.status_label || 'Never opened');
            var statusState = String(u.status_state || 'never').toLowerCase();
            var statusTitle = u.last_seen_at ? ('Last seen: ' + String(u.last_seen_at)) : statusLabel;
            var status = '<span class="users-status is-' + escapeHtml(statusState) + '" title="' + escapeHtml(statusTitle) + '"><span class="users-status-dot"></span><span class="users-cell">' + escapeHtml(statusLabel) + '</span></span>';
            var rowClasses = 'users-access-row' + (canManageAccess ? ' is-clickable' : '');
            return '' +
                '<tr class="' + rowClasses + '" data-user-id="' + escapeHtml(id) + '" data-user-name="' + escapeHtml(name) + '" data-user-email="' + escapeHtml(email) + '" data-user-company="' + escapeHtml(company) + '" data-user-role="' + escapeHtml(role) + '" data-user-department="' + escapeHtml(dept) + '">' +
                '  <td>' +
                '    <span class="users-name-wrap">' +
                '      <span class="users-avatar">' + escapeHtml(initial) + '</span>' +
                '      <span class="users-name-block">' +
                '        <span class="users-name users-cell" title="' + escapeHtml(name) + '">' + escapeHtml(name) + '</span>' +
                '      </span>' +
                '      ' + badge +
                '    </span>' +
                '  </td>' +
                '  <td><span class="users-cell" title="' + escapeHtml(email) + '">' + escapeHtml(email) + '</span></td>' +
                '  <td>' + deptBadge + '</td>' +
                '  <td>' + status + '</td>' +
                '  <td>' + action + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderUsersPagination() {
        var wrap = document.getElementById('usersPagination');
        var info = document.getElementById('usersPaginationInfo');
        var controls = document.getElementById('usersPaginationControls');
        if (!wrap || !info || !controls) return;

        var total = Number(tmUsersState.total || 0);
        var page = Number(tmUsersState.page || 1);
        var limit = Number(tmUsersState.limit || window.TM_USERS_PAGE_SIZE);
        var totalPages = Number(tmUsersState.totalPages || 1);
        if (total <= 0) {
            wrap.style.display = 'none';
            info.textContent = '';
            controls.innerHTML = '';
            return;
        }

        var start = (page - 1) * limit + 1;
        var end = Math.min(total, page * limit);
        info.textContent = 'Showing ' + start + ' \u2013 ' + end + ' of ' + total + ' users';

        var btns = [];
        var prevDisabled = page <= 1;
        var nextDisabled = page >= totalPages;
        btns.push('<a href=\"#\" class=\"page-btn prev' + (prevDisabled ? ' disabled' : '') + '\" data-page=\"' + (page - 1) + '\">&lsaquo; Previous</a>');
        var paginationItems = buildPaginationModel(page, totalPages);
        btns.push('<div class=\"pagination-pages\">');
        for (var i = 0; i < paginationItems.length; i++) {
            var item = paginationItems[i];
            if (item === 'ellipsis') {
                btns.push('<span class=\"pagination-ellipsis\">&hellip;</span>');
            } else {
                btns.push('<a href=\"#\" class=\"page-btn' + (item === page ? ' active' : '') + '\" data-page=\"' + item + '\">' + item + '</a>');
            }
        }
        btns.push('</div>');
        btns.push('<a href=\"#\" class=\"page-btn next' + (nextDisabled ? ' disabled' : '') + '\" data-page=\"' + (page + 1) + '\">Next &rsaquo;</a>');

        controls.innerHTML = btns.join('');
        wrap.style.display = 'flex';
    }

    function loadUsersList(page) {
        var qEl = document.getElementById('usersSearch');
        var deptEl = document.getElementById('usersDept');
        var companyEl = document.getElementById('usersCompany');
        var q = qEl ? qEl.value.trim() : '';
        var dept = (deptEl && !deptEl.disabled) ? deptEl.value : 'all';
        var company = companyEl ? companyEl.value : 'all';
        var p = typeof page === 'number' && page > 0 ? page : (tmUsersState.page || 1);
        tmUsersState.page = p;
        tmUsersState.limit = Number(window.TM_USERS_PAGE_SIZE) || 5;
        var url = 'ajax_users_list.php?q=' + encodeURIComponent(q) + '&department=' + encodeURIComponent(dept) + '&company=' + encodeURIComponent(company) + '&limit=' + encodeURIComponent(String(tmUsersState.limit)) + '&page=' + encodeURIComponent(String(tmUsersState.page));
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    renderUsers([]);
                    tmUsersState.total = 0;
                    tmUsersState.totalPages = 1;
                    renderUsersPagination();
                    return;
                }
                renderUsers(data.users || []);
                tmUsersState.total = Number(data.total_users || 0);
                tmUsersState.page = Number(data.page || tmUsersState.page || 1);
                tmUsersState.limit = Number(data.limit || tmUsersState.limit || window.TM_USERS_PAGE_SIZE);
                tmUsersState.totalPages = Number(data.total_pages || Math.max(1, Math.ceil((tmUsersState.total || 0) / (tmUsersState.limit || 1))));
                renderUsersPagination();
            })
            .catch(function () {
                renderUsers([]);
                tmUsersState.total = 0;
                tmUsersState.totalPages = 1;
                renderUsersPagination();
            });
    }

    function syncUsersDepartmentFilter() {
        var deptEl = document.getElementById('usersDept');
        var companyEl = document.getElementById('usersCompany');
        if (!deptEl || !companyEl) return;
        var isLapc = companyEl.value === '@leadsagri.com';
        deptEl.classList.toggle('is-hidden', !isLapc);
        deptEl.disabled = !isLapc;
        if (!isLapc) {
            deptEl.value = 'all';
        }
    }

    function renderItEmployees(list) {
        var body = document.getElementById('itEmployeesBody');
        if (!body) return;
        if (!list || list.length === 0) {
            body.innerHTML = '<tr><td colspan="4" style="text-align: center; color:#6B7280; padding: 22px 12px;">No eligible IT employees found.</td></tr>';
            return;
        }
        body.innerHTML = list.map(function (e) {
            var id = e.id != null ? String(e.id) : '';
            var name = String(e.name || '');
            var email = String(e.email || '');
            var initial = name ? name.trim().charAt(0).toUpperCase() : '?';
            return '' +
                '<tr>' +
                '  <td>' +
                '    <div class="employee-cell">' +
                '      <span class="employee-avatar">' + escapeHtml(initial) + '</span>' +
                '      <span>' + escapeHtml(name) + '</span>' +
                '    </div>' +
                '  </td>' +
                '  <td>' + escapeHtml(email) + '</td>' +
                '  <td><span class="dept-badge dept-it">IT</span></td>' +
                '  <td style="text-align:right;"><button type="button" class="promote-btn" onclick="confirmAddition(' + escapeHtml(id) + ')"><i class="fas fa-plus"></i> Promote</button></td>' +
                '</tr>';
        }).join('');
    }

    function renderItPagination() {
        var wrap = document.getElementById('itPagination');
        var info = document.getElementById('itPaginationInfo');
        var controls = document.getElementById('itPaginationControls');
        if (!wrap || !info || !controls) return;
        var total = Number(tmItState.total || 0);
        var page = Number(tmItState.page || 1);
        var limit = Number(tmItState.limit || window.TM_IT_PAGE_SIZE || 6);
        var totalPages = Number(tmItState.totalPages || Math.max(1, Math.ceil(total / Math.max(1, limit))));

        if (!total || totalPages <= 1) {
            wrap.style.display = 'none';
            return;
        }

        var start = (page - 1) * limit + 1;
        var end = Math.min(total, page * limit);
        info.textContent = 'Showing ' + start + ' \u2013 ' + end + ' of ' + total + ' users';

        var btns = [];
        var prevDisabled = page <= 1;
        var nextDisabled = page >= totalPages;
        btns.push('<a href=\"#\" class=\"page-btn prev' + (prevDisabled ? ' disabled' : '') + '\" data-page=\"' + (page - 1) + '\">&lsaquo; Previous</a>');
        var paginationItems = buildPaginationModel(page, totalPages);
        btns.push('<div class=\"pagination-pages\">');
        for (var i = 0; i < paginationItems.length; i++) {
            var item = paginationItems[i];
            if (item === 'ellipsis') {
                btns.push('<span class=\"pagination-ellipsis\">&hellip;</span>');
            } else {
                btns.push('<a href=\"#\" class=\"page-btn' + (item === page ? ' active' : '') + '\" data-page=\"' + item + '\">' + item + '</a>');
            }
        }
        btns.push('</div>');
        btns.push('<a href=\"#\" class=\"page-btn next' + (nextDisabled ? ' disabled' : '') + '\" data-page=\"' + (page + 1) + '\">Next &rsaquo;</a>');

        controls.innerHTML = btns.join('');
        wrap.style.display = 'flex';
    }

    function loadItEmployees(page) {
        var input = document.getElementById('itSearchInput');
        var q = input ? input.value.trim() : '';
        var p = typeof page === 'number' && page > 0 ? page : (tmItState.page || 1);
        tmItState.page = p;
        tmItState.limit = Number(window.TM_IT_PAGE_SIZE) || 6;
        var url = 'ajax_it_employees.php?q=' + encodeURIComponent(q) + '&limit=' + encodeURIComponent(String(tmItState.limit)) + '&page=' + encodeURIComponent(String(tmItState.page));
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    renderItEmployees([]);
                    tmItState.total = 0;
                    tmItState.totalPages = 1;
                    renderItPagination();
                    return;
                }
                renderItEmployees(data.employees || []);
                tmItState.total = Number(data.total_employees || 0);
                tmItState.page = Number(data.page || tmItState.page || 1);
                tmItState.limit = Number(data.limit || tmItState.limit || window.TM_IT_PAGE_SIZE);
                tmItState.totalPages = Number(data.total_pages || Math.max(1, Math.ceil((tmItState.total || 0) / (tmItState.limit || 1))));
                renderItPagination();
            })
            .catch(function () {
                renderItEmployees([]);
                tmItState.total = 0;
                tmItState.totalPages = 1;
                renderItPagination();
            });
    }

    function renderItAdminsPagination() {
        var wrap = document.getElementById('itAdminsPagination');
        var info = document.getElementById('itAdminsPaginationInfo');
        var controls = document.getElementById('itAdminsPaginationControls');
        if (!wrap || !info || !controls) return;
        var total = Number(tmItAdminsState.total || 0);
        var page = Number(tmItAdminsState.page || 1);
        var limit = Number(tmItAdminsState.limit || window.TM_IT_ADMINS_PAGE_SIZE || 2);
        var totalPages = Number(tmItAdminsState.totalPages || Math.max(1, Math.ceil(total / Math.max(1, limit))));

        if (!total || totalPages <= 1) {
            wrap.style.display = 'none';
            return;
        }

        var start = (page - 1) * limit + 1;
        var end = Math.min(total, page * limit);
        info.textContent = 'Showing ' + start + ' \u2013 ' + end + ' of ' + total + ' admins';

        var btns = [];
        var prevDisabled = page <= 1;
        var nextDisabled = page >= totalPages;
        btns.push('<a href=\"#\" class=\"page-btn prev' + (prevDisabled ? ' disabled' : '') + '\" data-page=\"' + (page - 1) + '\">&lsaquo; Previous</a>');
        var paginationItems = buildPaginationModel(page, totalPages);
        btns.push('<div class=\"pagination-pages\">');
        for (var i = 0; i < paginationItems.length; i++) {
            var item = paginationItems[i];
            if (item === 'ellipsis') {
                btns.push('<span class=\"pagination-ellipsis\">&hellip;</span>');
            } else {
                btns.push('<a href=\"#\" class=\"page-btn' + (item === page ? ' active' : '') + '\" data-page=\"' + item + '\">' + item + '</a>');
            }
        }
        btns.push('</div>');
        btns.push('<a href=\"#\" class=\"page-btn next' + (nextDisabled ? ' disabled' : '') + '\" data-page=\"' + (page + 1) + '\">Next &rsaquo;</a>');

        controls.innerHTML = btns.join('');
        wrap.style.display = 'flex';
    }

    function showItAdminsPage(page) {
        var grid = document.getElementById('itAdminsGrid');
        if (!grid) return;
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.admin-card'));
        var limit = Number(tmItAdminsState.limit || window.TM_IT_ADMINS_PAGE_SIZE || 2);
        tmItAdminsState.total = cards.length;
        tmItAdminsState.totalPages = Math.max(1, Math.ceil((tmItAdminsState.total || 0) / Math.max(1, limit)));
        tmItAdminsState.page = Math.min(Math.max(1, Number(page || 1)), tmItAdminsState.totalPages);

        if (cards.length <= limit) {
            cards.forEach(function (c) { c.style.display = ''; });
            var wrap = document.getElementById('itAdminsPagination');
            if (wrap) wrap.style.display = 'none';
            return;
        }

        var startIdx = (tmItAdminsState.page - 1) * limit;
        var endIdx = startIdx + limit;
        cards.forEach(function (c, idx) {
            c.style.display = (idx >= startIdx && idx < endIdx) ? '' : 'none';
        });
        renderItAdminsPagination();
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('addUserModal');
        var openBtn = document.getElementById('openAddUser');
        var editUserModal = document.getElementById('editUserModal');
        var editUserForm = document.getElementById('editUserForm');
        var closeEditUserModalBtn = document.getElementById('closeEditUserModal');
        var cancelEditUserBtn = document.getElementById('cancelEditUser');
        var editUserId = document.getElementById('editUserId');
        var editUserName = document.getElementById('editUserName');
        var editUserEmail = document.getElementById('editUserEmail');
        var editUserCompany = document.getElementById('editUserCompany');
        var editUserDepartment = document.getElementById('editUserDepartment');
        var saveEditUserBtn = document.getElementById('saveEditUser');
        var accessModal = document.getElementById('userAccessModal');
        var accessForm = document.getElementById('userAccessForm');
        var accessSections = document.getElementById('accessSections');
        var accessUserId = document.getElementById('accessUserId');
        var accessUserName = document.getElementById('accessUserName');
        var accessUserEmail = document.getElementById('accessUserEmail');
        var accessUserRole = document.getElementById('accessUserRole');
        var accessUserAvatar = document.getElementById('accessUserAvatar');
        var saveUserAccessBtn = document.getElementById('saveUserAccess');
        var selectedAccessRow = null;
        var accessPermissionsBaseline = '';
        var activityDrawer = document.getElementById('activityDrawerOverlay');
        var closeActivityDrawerBtn = document.getElementById('closeActivityDrawer');
        var activityTimeline = document.getElementById('activityTimeline');
        var activityState = { userId: '' };
        function openModal() {
            if (!modal) return;
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            updateDepartmentDropdown();
            var fullName = document.getElementById('fullName');
            if (fullName) fullName.focus();
        }
        function closeModal() {
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        }
        function normalizeEditCompany(value) {
            var raw = String(value || '').trim();
            if (Object.prototype.hasOwnProperty.call(editCompanyDepartments, raw)) return raw;
            var lower = raw.toLowerCase();
            var aliases = {
                'lapc': '@leadsagri.com',
                'lapc (@leadsagri.com)': '@leadsagri.com',
                'leadsagri.com': '@leadsagri.com',
                'leads agricultural products corporation - lapc': '@leadsagri.com',
                '@leadsagri.com': '@leadsagri.com',
                'mhc': '@malvedaholdings.com',
                'mhc (@malvedaholdings.com)': '@malvedaholdings.com',
                'malveda holdings corporation - mhc': '@malvedaholdings.com',
                '@malvedaholdings.com': '@malvedaholdings.com',
                'mpdc': '@malvedaproperties.com',
                'mpdc (@malvedaproperties.com)': '@malvedaproperties.com',
                'malveda properties & development corporation - mpdc': '@malvedaproperties.com',
                '@malvedaproperties.com': '@malvedaproperties.com',
                'gpsci': '@gpsci.net',
                'gpci': '@gpsci.net',
                'gpsci (@gpsci.net)': '@gpsci.net',
                'golden primestocks chemical inc - gpsci': '@gpsci.net',
                'golden primestocks chemical inc - gpci': '@gpsci.net',
                '@gpsci.net': '@gpsci.net',
                'pcc': '@primestocks.ph',
                'pcc (@primestocks.ph)': '@primestocks.ph',
                'primestocks chemical corporation - pcc': '@primestocks.ph',
                '@primestocks.ph': '@primestocks.ph',
                'farmasee': '@farmasee.ph',
                'farmasee (@farmasee.ph)': '@farmasee.ph',
                '@farmasee.ph': '@farmasee.ph',
                'farmex / lav': '@leads-farmex.com',
                'farmex': '@leads-farmex.com',
                'lav': '@leads-farmex.com',
                'lav (@leadsav.com)': '@leads-farmex.com',
                'farmex corp': '@leads-farmex.com',
                '@leads-farmex.com': '@leads-farmex.com',
                'ltc': '@leadstech-corp.com',
                'ltc (@leadstech-corp.com)': '@leadstech-corp.com',
                'leads tech corporation - ltc': '@leadstech-corp.com',
                '@leadstech-corp.com': '@leadstech-corp.com',
                'lingap': '@lingapleads.org',
                'lingap (@lingapleads.org)': '@lingapleads.org',
                'lingap leads foundation - lingap': '@lingapleads.org',
                '@lingapleads.org': '@lingapleads.org'
            };
            return aliases[lower] || raw;
        }
        function editCompanyFromEmail(email) {
            var value = String(email || '').trim().toLowerCase();
            var at = value.lastIndexOf('@');
            if (at < 0) return '';
            var domain = value.slice(at);
            var domainMap = {
                '@leadsagri.com': '@leadsagri.com',
                '@malvedaholdings.com': '@malvedaholdings.com',
                '@malvedaproperties.com': '@malvedaproperties.com',
                '@gpsci.net': '@gpsci.net',
                '@primestocks.ph': '@primestocks.ph',
                '@farmasee.ph': '@farmasee.ph',
                '@leads-farmex.com': '@leads-farmex.com',
                '@leadsav.com': '@leads-farmex.com',
                '@leadstech-corp.com': '@leadstech-corp.com',
                '@lingapleads.org': '@lingapleads.org'
            };
            return domainMap[domain] || '';
        }
        function editCompanyOptionExists(value) {
            if (!editUserCompany) return false;
            return Array.prototype.slice.call(editUserCompany.options).some(function (option) {
                return String(option.value || '') === String(value || '');
            });
        }
        function syncEditDepartmentOptions(selectedDepartment) {
            if (!editUserDepartment || !editUserCompany) return;
            var company = normalizeEditCompany(editUserCompany.value);
            var departments = editCompanyDepartments[company] || [];
            if (!departments.length) {
                editUserDepartment.innerHTML = '<option value="">No Department</option>';
                editUserDepartment.value = '';
                editUserDepartment.disabled = false;
                return;
            }
            editUserDepartment.disabled = false;
            editUserDepartment.innerHTML = departments.map(function (department) {
                return '<option value="' + escapeHtml(String(department)) + '">' + escapeHtml(String(department)) + '</option>';
            }).join('');
            if (selectedDepartment && departments.indexOf(selectedDepartment) !== -1) {
                editUserDepartment.value = selectedDepartment;
            } else {
                editUserDepartment.selectedIndex = 0;
            }
        }
        function openEditUserModal(data) {
            if (!editUserModal || !window.TM_CAN_MANAGE_USER_ACCESS) return;
            data = data || {};
            if (editUserId) editUserId.value = String(data.id || '');
            if (editUserName) editUserName.value = String(data.name || '');
            if (editUserEmail) editUserEmail.value = String(data.email || '');
            if (editUserCompany) {
                var normalizedCompany = normalizeEditCompany(data.company || '');
                if (!editCompanyOptionExists(normalizedCompany)) {
                    normalizedCompany = editCompanyFromEmail(data.email || '');
                }
                if (!editCompanyOptionExists(normalizedCompany)) {
                    normalizedCompany = editUserCompany.options.length ? String(editUserCompany.options[0].value || '') : '';
                }
                editUserCompany.value = normalizedCompany;
            }
            syncEditDepartmentOptions(String(data.department || ''));
            editUserModal.classList.add('show');
            editUserModal.setAttribute('aria-hidden', 'false');
            if (editUserName) editUserName.focus();
        }
        function closeEditUserModal() {
            if (!editUserModal) return;
            editUserModal.classList.remove('show');
            editUserModal.setAttribute('aria-hidden', 'true');
            if (editUserForm) editUserForm.reset();
        }
        function setAccessLoadingState(isLoading) {
            if (saveUserAccessBtn) {
                saveUserAccessBtn.disabled = isLoading || !window.TM_CAN_MANAGE_USER_ACCESS;
                saveUserAccessBtn.textContent = isLoading ? 'Saving...' : 'Save Access';
            }
        }
        function resetAccessModalCard() {
            accessPermissionsBaseline = '';
            if (accessUserId) accessUserId.value = '';
            if (accessUserName) accessUserName.textContent = 'Select a user';
            if (accessUserEmail) accessUserEmail.textContent = 'Choose a registered user to update module access.';
            if (accessUserRole) accessUserRole.textContent = '';
            if (accessUserAvatar) accessUserAvatar.textContent = '?';
            if (accessSections) {
                accessSections.innerHTML = '<div class="access-modal-empty">Select a registered user to load module access.</div>';
            }
        }
        function openAccessModal() {
            if (!accessModal) return;
            accessModal.classList.add('show');
            accessModal.setAttribute('aria-hidden', 'false');
        }
        function closeAccessModal() {
            if (!accessModal) return;
            accessModal.classList.remove('show');
            accessModal.setAttribute('aria-hidden', 'true');
            if (selectedAccessRow) {
                selectedAccessRow.classList.remove('is-active');
                selectedAccessRow = null;
            }
            resetAccessModalCard();
            setAccessLoadingState(false);
        }
        function activityIconMeta(type) {
            var t = String(type || '').toUpperCase();
            if (t.indexOf('FAILED') > -1 || t.indexOf('DELETED') > -1) return { icon: 'fa-exclamation', tone: 'is-danger' };
            if (t.indexOf('PASSWORD') > -1 || t.indexOf('UPDATED') > -1 || t.indexOf('CHANGED') > -1) return { icon: 'fa-pen', tone: 'is-warning' };
            if (t.indexOf('LOGIN') > -1) return { icon: 'fa-right-to-bracket', tone: '' };
            if (t.indexOf('LOGOUT') > -1) return { icon: 'fa-right-from-bracket', tone: '' };
            if (t.indexOf('TICKET') > -1) return { icon: 'fa-ticket-alt', tone: '' };
            return { icon: 'fa-circle', tone: '' };
        }
        function openActivityDrawer(userId) {
            if (!activityDrawer || !userId) return;
            activityState.userId = String(userId);
            activityState.page = 1;
            activityDrawer.classList.add('show');
            activityDrawer.setAttribute('aria-hidden', 'false');
            loadActivityLogs();
        }
        function closeActivityDrawer() {
            if (!activityDrawer) return;
            activityDrawer.classList.remove('show');
            activityDrawer.setAttribute('aria-hidden', 'true');
            activityState.userId = '';
        }
        function setActivityText(id, value) {
            var el = document.getElementById(id);
            if (el) el.textContent = value || '-';
        }
        function renderActivityHeader(data) {
            var user = data.user || {};
            var summary = data.summary || {};
            var name = String(user.name || 'Selected user');
            setActivityText('activityUserName', name);
            setActivityText('activityUserEmail', String(user.email || ''));
            setActivityText('activityUserDepartment', String(user.department || '-'));
            setActivityText('activityUserRole', String(user.role || '-'));
            setActivityText('activityUserStatus', String(user.status_label || '-'));
            setActivityText('activityAccountCreated', String(summary.account_created || '-'));
            setActivityText('activityFirstLogin', String(summary.first_login || '-'));
            setActivityText('activityLastLogin', String(summary.last_login || '-'));
            var avatar = document.getElementById('activityUserAvatar');
            if (avatar) avatar.textContent = name ? name.trim().charAt(0).toUpperCase() : '?';
            var dot = document.getElementById('activityStatusDot');
            if (dot) {
                dot.className = 'activity-status-dot is-' + escapeHtml(String(user.status_state || 'never'));
            }
        }
        function renderActivityTimeline(logs) {
            if (!activityTimeline) return;
            if (!logs || !logs.length) {
                activityTimeline.innerHTML = '<div class="activity-empty">No recent activities found.</div>';
                return;
            }
            activityTimeline.innerHTML = logs.map(function (log) {
                var meta = activityIconMeta(log.activity_type);
                return '' +
                    '<div class="activity-item ' + escapeHtml(meta.tone) + '">' +
                    escapeHtml(log.activity_description || '') +
                    '</div>';
            }).join('');
        }
        function loadActivityLogs() {
            if (!activityState.userId || !activityTimeline) return;
            activityTimeline.innerHTML = '<div class="activity-empty">Loading recent activities...</div>';
            var params = new URLSearchParams({
                user_id: activityState.userId,
                page: '1',
                limit: '10'
            });
            params.set('admin_mgmt_activity_logs', '1');
            fetch('create_admin.php?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) throw new Error((data && data.error) ? data.error : 'Unable to load activity logs.');
                    renderActivityHeader(data);
                    renderActivityTimeline(data.logs || []);
                })
                .catch(function (error) {
                    activityTimeline.innerHTML = '<div class="activity-empty">' + escapeHtml(error && error.message ? error.message : 'Unable to load activity logs.') + '</div>';
                });
        }
        function showAccessAlert(icon, title, text) {
            Swal.fire({
                target: document.body,
                icon: icon,
                title: title,
                html: escapeHtml(text),
                width: '420px',
                confirmButtonText: 'OK',
                buttonsStyling: false,
                customClass: {
                    popup: 'swal-admin-alert-popup',
                    icon: 'swal-admin-alert-icon',
                    title: 'swal-admin-alert-title',
                    htmlContainer: 'swal-admin-alert-html',
                    actions: 'swal-admin-alert-actions',
                    confirmButton: 'swal-admin-alert-confirm'
                }
            });
        }
        function getAccessSectionUi(section) {
            var normalized = String(section || '').toLowerCase();
            if (normalized.indexOf('ticket') > -1) {
                return { icon: 'fa-ticket-alt', subtitle: 'All ticket related modules.' };
            }
            if (normalized.indexOf('resource') > -1) {
                return { icon: 'fa-book', subtitle: 'Knowledge and resource center.' };
            }
            if (normalized.indexOf('navbar') > -1 || normalized.indexOf('other') > -1) {
                return { icon: 'fa-location-arrow', subtitle: 'Additional navigation and quick access items.' };
            }
            return { icon: 'fa-border-all', subtitle: 'Core modules for general navigation.' };
        }
        function serializeAccessPermissions() {
            if (!accessSections) return '';
            var values = {};
            Array.prototype.slice.call(accessSections.querySelectorAll('input[type="checkbox"][name^="permissions["]')).forEach(function (input) {
                var match = String(input.name || '').match(/^permissions\[(.+)\]$/);
                if (!match) return;
                values[match[1]] = input.checked ? 1 : 0;
            });
            return JSON.stringify(Object.keys(values).sort().reduce(function (acc, key) {
                acc[key] = values[key];
                return acc;
            }, {}));
        }
        function buildAccessSections(definitions, permissions) {
            if (!accessSections) return;
            if (!definitions || !definitions.length) {
                accessSections.innerHTML = '<div class="access-modal-empty">No module permissions are configured yet.</div>';
                accessPermissionsBaseline = '';
                return;
            }
            var grouped = {};
            definitions.forEach(function (definition) {
                var section = String(definition.section || 'Modules');
                if (!grouped[section]) grouped[section] = [];
                grouped[section].push(definition);
            });

            function renderAccessSection(section) {
                var sectionUi = getAccessSectionUi(section);
                var rows = grouped[section].map(function (definition) {
                    var key = String(definition.key || '');
                    var checked = Number((permissions && permissions[key]) || 0) === 1;
                    return '' +
                        '<div class="access-toggle-row">' +
                        '  <div class="access-toggle-copy">' +
                        '    <div class="access-toggle-title">' + escapeHtml(String(definition.label || key)) + '</div>' +
                        '  </div>' +
                        '  <label class="switch">' +
                        '    <input type="checkbox" name="permissions[' + escapeHtml(key) + ']" value="1" ' + (checked ? 'checked' : '') + '>' +
                        '    <span class="switch-slider"></span>' +
                        '  </label>' +
                        '</div>';
                }).join('');
                return '' +
                    '<section class="access-section">' +
                    '  <div class="access-section-head">' +
                    '    <span class="access-section-icon"><i class="fas ' + escapeHtml(sectionUi.icon) + '"></i></span>' +
                    '    <span>' +
                    '      <span class="access-section-title">' + escapeHtml(section) + '</span>' +
                    '      <span class="access-section-subtitle">' + escapeHtml(sectionUi.subtitle) + '</span>' +
                    '    </span>' +
                    '  </div>' +
                    '  <div class="access-toggle-list">' + rows + '</div>' +
                    '</section>';
            }

            var leftOrder = ['General', 'Tickets'];
            var rightOrder = ['Resources'];
            var knownSections = {};
            leftOrder.concat(rightOrder).forEach(function (section) {
                knownSections[section] = true;
            });
            var extraSections = Object.keys(grouped).filter(function (section) {
                return !knownSections[section];
            }).sort(function (a, b) {
                return a.localeCompare(b);
            });
            var leftHtml = leftOrder.filter(function (section) {
                return grouped[section];
            }).map(renderAccessSection).join('');
            var rightHtml = rightOrder.concat(extraSections).filter(function (section) {
                return grouped[section];
            }).map(renderAccessSection).join('');
            var html = '<div class="access-column">' + leftHtml + '</div><div class="access-column">' + rightHtml + '</div>';

            accessSections.innerHTML = html;
            accessPermissionsBaseline = serializeAccessPermissions();
        }
        function loadUserAccess(row) {
            if (!row || !window.TM_CAN_MANAGE_USER_ACCESS) return;
            var userId = row.getAttribute('data-user-id') || '';
            if (!userId) return;
            if (selectedAccessRow) selectedAccessRow.classList.remove('is-active');
            selectedAccessRow = row;
            selectedAccessRow.classList.add('is-active');
            openAccessModal();
            setAccessLoadingState(false);
            if (accessSections) {
                accessSections.innerHTML = '<div class="access-modal-empty">Loading module access...</div>';
            }

            fetch('ajax_user_permissions.php?user_id=' + encodeURIComponent(userId), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw new Error((data && data.error) ? data.error : 'Unable to load module access.');
                    }
                    var user = data.user || {};
                    var displayName = String(user.name || row.getAttribute('data-user-name') || 'Selected user');
                    var displayEmail = String(user.email || row.getAttribute('data-user-email') || '');
                    var displayRole = String(user.role || row.getAttribute('data-user-role') || '');
                    var displayDepartment = String(user.department || row.getAttribute('data-user-department') || '');
                    if (accessUserId) accessUserId.value = String(user.id || userId);
                    if (accessUserName) accessUserName.textContent = displayName;
                    if (accessUserEmail) accessUserEmail.textContent = displayEmail || 'No email available';
                    if (accessUserRole) {
                        accessUserRole.textContent = [displayRole ? ('Role: ' + displayRole) : '', displayDepartment ? ('Department: ' + displayDepartment) : '']
                            .filter(Boolean)
                            .join(' • ');
                    }
                    if (accessUserAvatar) {
                        accessUserAvatar.textContent = displayName ? displayName.trim().charAt(0).toUpperCase() : '?';
                    }
                    buildAccessSections(data.definitions || [], data.permissions || {});
                })
                .catch(function (error) {
                    if (accessSections) {
                        accessSections.innerHTML = '<div class="access-modal-empty">Unable to load module access.</div>';
                    }
                    showAccessAlert('error', 'Access load failed', error && error.message ? error.message : 'Unable to load module access.');
                });
        }
        if (openBtn) openBtn.addEventListener('click', openModal);
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
        }
        if (closeEditUserModalBtn) closeEditUserModalBtn.addEventListener('click', closeEditUserModal);
        if (cancelEditUserBtn) cancelEditUserBtn.addEventListener('click', closeEditUserModal);
        if (editUserModal) {
            editUserModal.addEventListener('click', function (e) {
                if (e.target === editUserModal) closeEditUserModal();
            });
        }
        if (editUserCompany) {
            editUserCompany.addEventListener('change', function () {
                syncEditDepartmentOptions('');
            });
        }
        if (editUserForm) {
            editUserForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!window.TM_CAN_MANAGE_USER_ACCESS) {
                    showAccessAlert('warning', 'Access denied', 'Only the super admin can edit users.');
                    return;
                }
                if (saveEditUserBtn) saveEditUserBtn.disabled = true;
                fetch('ajax_update_user.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(editUserForm)
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            throw new Error((data && data.error) ? data.error : 'Failed to update user.');
                        }
                        closeEditUserModal();
                        loadUsersList(tmUsersState.page || 1);
                        Swal.fire({
                            icon: 'success',
                            iconHtml: '<i class="fa-solid fa-check"></i>',
                            title: 'User updated',
                            html: escapeHtml(data.message || 'User updated successfully.'),
                            width: '420px',
                            confirmButtonText: 'OK',
                            buttonsStyling: false,
                            customClass: {
                                popup: 'swal-admin-alert-popup',
                                icon: 'swal-admin-alert-icon',
                                title: 'swal-admin-alert-title',
                                htmlContainer: 'swal-admin-alert-html',
                                actions: 'swal-admin-alert-actions',
                                confirmButton: 'swal-admin-alert-confirm'
                            }
                        });
                    })
                    .catch(function (error) {
                        showAccessAlert('error', 'Update failed', error && error.message ? error.message : 'Failed to update user.');
                    })
                    .finally(function () {
                        if (saveEditUserBtn) saveEditUserBtn.disabled = false;
                    });
            });
        }
        if (accessModal) {
            accessModal.addEventListener('click', function (e) {
                if (e.target === accessModal) closeAccessModal();
            });
        }
        if (closeActivityDrawerBtn) closeActivityDrawerBtn.addEventListener('click', closeActivityDrawer);
        if (activityDrawer) {
            activityDrawer.addEventListener('click', function (e) {
                if (e.target === activityDrawer) closeActivityDrawer();
            });
        }
        var domainSelect = document.getElementById('domain');
        if (domainSelect) {
            domainSelect.addEventListener('change', updateDepartmentDropdown);
        }
        updateDepartmentDropdown();

        var autoBtn = document.getElementById('autoGenerateBtn');
        var passEl = document.getElementById('newPassword');
        if (autoBtn && passEl) {
            autoBtn.addEventListener('click', function () {
                passEl.value = randomPassword(12);
                passEl.focus();
            });
        }

        var toggleBtn = document.getElementById('togglePassword');
        if (toggleBtn && passEl) {
            toggleBtn.addEventListener('click', function () {
                var isHidden = passEl.getAttribute('type') === 'password';
                passEl.setAttribute('type', isHidden ? 'text' : 'password');
                toggleBtn.innerHTML = isHidden ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
            });
        }

        var cancelBtn = document.getElementById('cancelAddUser');
        var closeAddUserBtn = document.getElementById('closeAddUserModal');
        var cancelUserAccessBtn = document.getElementById('cancelUserAccess');
        var closeUserAccessBtn = document.getElementById('closeUserAccessModal');
        var form = document.getElementById('addUserForm');
        if (cancelBtn && form) {
            cancelBtn.addEventListener('click', function () {
                form.reset();
                updateDepartmentDropdown();
                closeModal();
            });
        }
        if (closeAddUserBtn && form) {
            closeAddUserBtn.addEventListener('click', function () {
                form.reset();
                updateDepartmentDropdown();
                closeModal();
            });
        }
        if (cancelUserAccessBtn) {
            cancelUserAccessBtn.addEventListener('click', closeAccessModal);
        }
        if (closeUserAccessBtn) {
            closeUserAccessBtn.addEventListener('click', closeAccessModal);
        }
        if (accessForm) {
            accessForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!window.TM_CAN_MANAGE_USER_ACCESS) {
                    showAccessAlert('warning', 'Access denied', 'Only the super admin can update user access.');
                    return;
                }
                var userIdValue = accessUserId ? String(accessUserId.value || '').trim() : '';
                if (!userIdValue) {
                    showAccessAlert('warning', 'No user selected', 'Choose a user first before saving access.');
                    return;
                }
                if (accessPermissionsBaseline !== '' && serializeAccessPermissions() === accessPermissionsBaseline) {
                    showAccessAlert('info', 'No changes were made', 'No changes were made.');
                    return;
                }
                setAccessLoadingState(true);
                fetch('ajax_user_permissions.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(accessForm)
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            throw new Error((data && data.error) ? data.error : 'Unable to save module access.');
                        }
                        Swal.fire({
                            title: '',
                            html:
                                '<div class="cred-wrap">' +
                                '  <div class="cred-check"><i class="fa-solid fa-check"></i></div>' +
                                '  <div class="cred-title">Access updated</div>' +
                                '  <div class="cred-subtitle">' + escapeHtml(data.message || 'User module access has been saved.') + '</div>' +
                                '</div>',
                            width: '420px',
                            confirmButtonText: 'Done',
                            buttonsStyling: false,
                            customClass: {
                                popup: 'swal-delete-success-popup',
                                htmlContainer: 'swal-delete-success-html',
                                actions: 'swal-delete-success-actions',
                                confirmButton: 'swal-delete-success-confirm'
                            }
                        });
                        closeAccessModal();
                    })
                    .catch(function (error) {
                        showAccessAlert('error', 'Save failed', error && error.message ? error.message : 'Unable to save module access.');
                    })
                    .finally(function () {
                        setAccessLoadingState(false);
                    });
            });
        }

        var addUserForm = document.getElementById('addUserForm');
        if (addUserForm) {
            var usernameEl = document.getElementById('username');
            var domainEl = document.getElementById('domain');
            var fullNameEl = document.getElementById('fullName');
            function normalizeEmailInputs() {
                if (!usernameEl || !domainEl) return;
                var raw = String(usernameEl.value || '').trim();
                if (!raw) return;
                var atIdx = raw.indexOf('@');
                if (atIdx > -1) {
                    var local = raw.slice(0, atIdx);
                    var dom = raw.slice(atIdx + 1);
                    dom = dom ? ('@' + dom) : '';
                    dom = dom.toLowerCase();
                    if (dom) {
                        var matched = false;
                        Array.prototype.slice.call(domainEl.options || []).forEach(function (opt) {
                            if (!opt || matched) return;
                            if (String(opt.value || '').toLowerCase() === dom) {
                                domainEl.value = opt.value;
                                matched = true;
                            }
                        });
                    }
                    usernameEl.value = local;
                }
                usernameEl.value = String(usernameEl.value || '')
                    .trim()
                    .toLowerCase();
            }
            function normalizeFullName() {
                if (!fullNameEl) return '';
                fullNameEl.value = String(fullNameEl.value || '')
                    .replace(/\d+/g, '')
                    .replace(/\s+/g, ' ')
                    .trim();
                return fullNameEl.value;
            }
            function validFullName(value) {
                return /^(?=.{2,100}$)[A-Za-z][A-Za-z .,'-]*[A-Za-z.]$/.test(String(value || '')) && !/\d/.test(String(value || ''));
            }
            function validEmailLocalPart(value) {
                var local = String(value || '').trim().toLowerCase();
                if (!local || /\s/.test(local)) return false;
                if (local.indexOf('..') > -1) return false;
                return /^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/.test(local);
            }
            function showCreateUserError(title, text) {
                Swal.fire({
                    target: modal || document.body,
                    icon: 'warning',
                    title: title,
                    html: escapeHtml(text),
                    width: '420px',
                    confirmButtonText: 'OK',
                    buttonsStyling: false,
                    allowOutsideClick: true,
                    customClass: {
                        container: 'swal-create-user-container',
                        popup: 'swal-admin-alert-popup',
                        icon: 'swal-admin-alert-icon',
                        title: 'swal-admin-alert-title',
                        htmlContainer: 'swal-admin-alert-html',
                        actions: 'swal-admin-alert-actions',
                        confirmButton: 'swal-admin-alert-confirm'
                    }
                });
            }
            if (fullNameEl) {
                fullNameEl.addEventListener('input', function () {
                    var current = String(fullNameEl.value || '');
                    var cleaned = current.replace(/\d+/g, '');
                    if (cleaned !== current) {
                        var cursorPos = fullNameEl.selectionStart || cleaned.length;
                        fullNameEl.value = cleaned;
                        try {
                            fullNameEl.setSelectionRange(cursorPos - (current.length - cleaned.length), cursorPos - (current.length - cleaned.length));
                        } catch (e) {}
                    }
                });
                fullNameEl.addEventListener('paste', function () {
                    setTimeout(normalizeFullName, 0);
                });
                fullNameEl.addEventListener('blur', normalizeFullName);
            }
            if (usernameEl) {
                usernameEl.addEventListener('blur', normalizeEmailInputs);
                usernameEl.addEventListener('input', function () {
                    if (String(usernameEl.value || '').indexOf('@') > -1) normalizeEmailInputs();
                });
                usernameEl.addEventListener('paste', function () {
                    setTimeout(normalizeEmailInputs, 0);
                });
            }
            addUserForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fullName = document.getElementById('fullName');
                var username = document.getElementById('username');
                var domain = document.getElementById('domain');
                var password = document.getElementById('newPassword');
                var deptEl = document.getElementById('newDept');
                if (!fullName || !username || !domain || !password) return;
                var normalizedName = normalizeFullName();
                var rawUsernameValue = String(username.value || '');

                if (!normalizedName) {
                    showCreateUserError('Full name required', 'Please enter the user\'s full name.');
                    fullName.focus();
                    return;
                }
                if (/\d/.test(normalizedName)) {
                    showCreateUserError('Invalid full name', 'Numbers are not allowed in the full name.');
                    fullName.focus();
                    return;
                }
                if (!validFullName(normalizedName)) {
                    showCreateUserError('Invalid full name', 'Please use a valid name with letters only.');
                    fullName.focus();
                    return;
                }
                if (!String(rawUsernameValue || '').trim()) {
                    showCreateUserError('Email required', 'Please enter the email username.');
                    username.focus();
                    return;
                }
                if (/\s/.test(rawUsernameValue)) {
                    showCreateUserError('Invalid email', 'Email must not contain spaces.');
                    username.focus();
                    return;
                }
                normalizeEmailInputs();
                var normalizedUsername = String(username.value || '').trim().toLowerCase();
                var emailAddress = normalizedUsername + String(domain.value || '');
                if (!validEmailLocalPart(normalizedUsername) || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailAddress)) {
                    showCreateUserError('Invalid email', 'Please enter a valid email address.');
                    username.focus();
                    return;
                }
                if (deptEl && !deptEl.disabled && !String(deptEl.value || '').trim()) {
                    showCreateUserError('Department required', 'Please select a department.');
                    deptEl.focus();
                    return;
                }
                if (!String(password.value || '').trim()) {
                    showCreateUserError('Password required', 'Please enter a password for the new user.');
                    password.focus();
                    return;
                }

                var fd = new FormData(addUserForm);
                fd.set('full_name', normalizedName);
                fd.set('username', normalizedUsername);
                fd.set('domain', domain.value || '@leadsagri.com');
                fd.set('password', password.value || '');
                if (deptEl) fd.set('department', deptEl.disabled ? '' : (deptEl.value || ''));

                var btn = document.getElementById('createUserBtn');
                if (btn) btn.disabled = true;

                fetch('ajax_create_user.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            var msg = (data && data.error) ? data.error : 'Failed to create user.';
                            var title = 'Unable to create user';
                            if (data && data.error_code === 'email_exists') title = 'Email already registered';
                            if (data && (data.error_code === 'email_invalid' || data.error_code === 'email_has_spaces')) title = 'Invalid email';
                            if (data && data.error_code === 'name_exists') title = 'Name already registered';
                            if (data && (data.error_code === 'name_invalid' || data.error_code === 'name_has_number')) title = 'Invalid full name';
                            showCreateUserError(title, msg);
                            return;
                        }
                        var emailAddress = normalizedUsername + (String(domain.value || ''));
                        var plainPassword = String(password.value || '');
                        Swal.fire({
                            title: '',
                            html:
                                '<div class="cred-wrap">' +
                                '  <div class="cred-check"><i class="fa-solid fa-check"></i></div>' +
                                '  <div class="cred-title">User created successfully</div>' +
                                '  <div class="cred-subtitle">New Credentials</div>' +
                                '  <div class="cred-box">' +
                                '    <div class="cred-row">' +
                                '      <div class="cred-label">Email Address</div>' +
                                '      <div class="cred-value">' +
                                '        <span class="cred-text" id="credEmail">' + escapeHtml(emailAddress) + '</span>' +
                                '        <button type="button" class="cred-icon-btn" data-action="copy-email" aria-label="Copy email"><i class="fa-regular fa-copy"></i></button>' +
                                '      </div>' +
                                '    </div>' +
                                '    <div class="cred-row">' +
                                '      <div class="cred-label">Password</div>' +
                                '      <div class="cred-value">' +
                                '        <span class="cred-text" id="credPass" data-plain="' + escapeHtml(plainPassword) + '">••••••••••</span>' +
                                '        <button type="button" class="cred-icon-btn" data-action="toggle-pass" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>' +
                                '        <button type="button" class="cred-icon-btn" data-action="copy-pass" aria-label="Copy password"><i class="fa-regular fa-copy"></i></button>' +
                                '      </div>' +
                                '    </div>' +
                                '  </div>' +
                                '</div>',
                            showConfirmButton: true,
                            confirmButtonText: 'Done',
                            buttonsStyling: false,
                            customClass: {
                                popup: 'swal-cred-popup',
                                confirmButton: 'swal-cred-btn'
                            },
                            didOpen: function (el) {
                                var popup = el;
                                function copyText(text) {
                                    var t = String(text || '');
                                    if (!t) return;
                                    if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                                        navigator.clipboard.writeText(t).catch(function () {});
                                        return;
                                    }
                                    var ta = document.createElement('textarea');
                                    ta.value = t;
                                    ta.setAttribute('readonly', 'readonly');
                                    ta.style.position = 'fixed';
                                    ta.style.left = '-9999px';
                                    document.body.appendChild(ta);
                                    ta.select();
                                    try { document.execCommand('copy'); } catch (e) {}
                                    document.body.removeChild(ta);
                                }
                                popup.addEventListener('click', function (e) {
                                    var btn = e.target && e.target.closest ? e.target.closest('button[data-action]') : null;
                                    if (!btn) return;
                                    var act = btn.getAttribute('data-action') || '';
                                    var emailEl = document.getElementById('credEmail');
                                    var passEl = document.getElementById('credPass');
                                    if (act === 'copy-email' && emailEl) {
                                        copyText(emailEl.textContent || '');
                                    }
                                    if (act === 'copy-pass' && passEl) {
                                        copyText(passEl.getAttribute('data-plain') || '');
                                    }
                                    if (act === 'toggle-pass' && passEl) {
                                        var shown = passEl.getAttribute('data-shown') === '1';
                                        var nextShown = !shown;
                                        passEl.setAttribute('data-shown', nextShown ? '1' : '0');
                                        passEl.textContent = nextShown ? (passEl.getAttribute('data-plain') || '') : '••••••••••';
                                        btn.innerHTML = nextShown ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
                                    }
                                });
                            }
                        });
                        addUserForm.reset();
                        loadUsersList();
                        closeModal();
                    })
                    .catch(function () {
                        showCreateUserError('Unable to create user', 'Failed to create user.');
                    })
                    .finally(function () {
                        if (btn) btn.disabled = false;
                    });
            });
        }

        function closeUserActionMenus(exceptWrap) {
            Array.prototype.slice.call(document.querySelectorAll('.users-actions.is-open')).forEach(function (wrap) {
                if (wrap === exceptWrap) return;
                wrap.classList.remove('is-open');
                var toggle = wrap.querySelector('.users-action-toggle');
                var menu = wrap.querySelector('.users-action-menu');
                if (menu) {
                    menu.style.top = '';
                    menu.style.left = '';
                    menu.style.maxHeight = '';
                    menu.style.overflowY = '';
                }
                if (toggle) toggle.setAttribute('aria-expanded', 'false');
            });
        }

        function positionUserActionMenu(toggle, menu) {
            var viewportGap = 8;
            var menuGap = 6;
            var toggleRect = toggle.getBoundingClientRect();
            var menuRect = menu.getBoundingClientRect();
            var roomBelow = window.innerHeight - toggleRect.bottom - viewportGap;
            var roomAbove = toggleRect.top - viewportGap;
            var openAbove = roomBelow < menuRect.height + menuGap && roomAbove > roomBelow;
            var availableHeight = Math.max(80, (openAbove ? roomAbove : roomBelow) - menuGap);
            var top = openAbove
                ? toggleRect.top - Math.min(menuRect.height, availableHeight) - menuGap
                : toggleRect.bottom + menuGap;
            var left = toggleRect.right - menuRect.width;

            left = Math.max(viewportGap, Math.min(left, window.innerWidth - menuRect.width - viewportGap));
            top = Math.max(viewportGap, top);
            menu.style.left = Math.round(left) + 'px';
            menu.style.top = Math.round(top) + 'px';
            menu.style.maxHeight = Math.floor(availableHeight) + 'px';
            menu.style.overflowY = menuRect.height > availableHeight ? 'auto' : '';
        }

        var usersBody = document.getElementById('usersListBody');
        if (usersBody) {
            usersBody.addEventListener('click', function (e) {
                var menuToggle = e.target && e.target.closest ? e.target.closest('.users-action-toggle') : null;
                if (menuToggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    var actionWrap = menuToggle.closest('.users-actions');
                    closeUserActionMenus(actionWrap);
                    if (actionWrap) {
                        actionWrap.classList.toggle('is-open');
                        var isOpen = actionWrap.classList.contains('is-open');
                        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

                        if (isOpen) {
                            var menu = actionWrap.querySelector('.users-action-menu');
                            if (menu) positionUserActionMenu(menuToggle, menu);
                        }
                    }
                    return;
                }

                var editBtn = e.target && e.target.closest ? e.target.closest('.users-edit') : null;
                if (editBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeUserActionMenus();
                    openEditUserModal({
                        id: editBtn.getAttribute('data-id') || '',
                        name: editBtn.getAttribute('data-name') || '',
                        email: editBtn.getAttribute('data-email') || '',
                        company: editBtn.getAttribute('data-company') || '',
                        department: editBtn.getAttribute('data-department') || ''
                    });
                    return;
                }

                var activityBtn = e.target && e.target.closest ? e.target.closest('.users-activity') : null;
                if (activityBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeUserActionMenus();
                    var activityUserId = activityBtn.getAttribute('data-id') || '';
                    openActivityDrawer(activityUserId);
                    return;
                }

                var btn = e.target && e.target.closest ? e.target.closest('.users-del') : null;
                if (btn) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeUserActionMenus();
                    var id = btn.getAttribute('data-id');
                    var name = btn.getAttribute('data-name') || 'this user';
                    if (!id) return;
                    Swal.fire({
                        title: 'Delete user?',
                        html: 'This will permanently delete ' + escapeHtml(name) + '.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Delete',
                        cancelButtonText: 'Cancel',
                        width: '420px',
                        buttonsStyling: false,
                        customClass: {
                            popup: 'swal-delete-popup',
                            icon: 'swal-delete-icon',
                            title: 'swal-delete-title',
                            htmlContainer: 'swal-delete-html',
                            actions: 'swal-delete-actions',
                            confirmButton: 'swal-delete-confirm',
                            cancelButton: 'swal-delete-cancel'
                        }
                    }).then(function (result) {
                        if (!result.isConfirmed) return;
                        var csrfEl = document.querySelector('#addUserForm input[name="csrf_token"]') || document.querySelector('input[name="csrf_token"]');
                        var csrf = csrfEl ? csrfEl.value : '';
                        fetch('ajax_delete_user.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams({ id: id, csrf_token: csrf })
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (!data || !data.ok) {
                                    var msg = (data && data.error) ? data.error : 'Failed to delete user.';
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        html: escapeHtml(msg),
                                        width: '420px',
                                        confirmButtonText: 'OK',
                                        buttonsStyling: false,
                                        customClass: {
                                            popup: 'swal-admin-alert-popup',
                                            icon: 'swal-admin-alert-icon',
                                            title: 'swal-admin-alert-title',
                                            htmlContainer: 'swal-admin-alert-html',
                                            actions: 'swal-admin-alert-actions',
                                            confirmButton: 'swal-admin-alert-confirm'
                                        }
                                    });
                                    return;
                                }
                                Swal.fire({
                                    title: '',
                                    html:
                                        '<div class="cred-wrap">' +
                                        '  <div class="cred-check"><i class="fa-solid fa-check"></i></div>' +
                                        '  <div class="cred-title">Deleted</div>' +
                                        '  <div class="cred-subtitle">' + escapeHtml(data.message || 'User deleted') + '</div>' +
                                        '</div>',
                                    width: '420px',
                                    confirmButtonText: 'OK',
                                    buttonsStyling: false,
                                    customClass: {
                                        popup: 'swal-delete-success-popup',
                                        htmlContainer: 'swal-delete-success-html',
                                        actions: 'swal-delete-success-actions',
                                        confirmButton: 'swal-delete-success-confirm'
                                    }
                                });
                                loadUsersList();
                            })
                            .catch(function () {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    html: 'Failed to delete user.',
                                    width: '420px',
                                    confirmButtonText: 'OK',
                                    buttonsStyling: false,
                                    customClass: {
                                        popup: 'swal-admin-alert-popup',
                                        icon: 'swal-admin-alert-icon',
                                        title: 'swal-admin-alert-title',
                                        htmlContainer: 'swal-admin-alert-html',
                                        actions: 'swal-admin-alert-actions',
                                        confirmButton: 'swal-admin-alert-confirm'
                                    }
                                });
                            });
                    });
                    return;
                }

                var row = e.target && e.target.closest ? e.target.closest('.users-access-row[data-user-id]') : null;
                if (e.target && e.target.closest && e.target.closest('.users-actions')) return;
                if (!row || !window.TM_CAN_MANAGE_USER_ACCESS) return;
                loadUserAccess(row);
            });
        }
        document.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('.users-actions')) return;
            closeUserActionMenus();
        });

        var usersTableWrap = document.querySelector('#usersListCard .users-table-wrap');
        if (usersTableWrap) {
            usersTableWrap.addEventListener('scroll', function () {
                closeUserActionMenus();
            }, { passive: true });
        }
        window.addEventListener('resize', function () {
            closeUserActionMenus();
        }, { passive: true });

        var debounceT = null;
        var usersSearch = document.getElementById('usersSearch');
        if (usersSearch) {
            usersSearch.addEventListener('input', function () {
                if (debounceT) clearTimeout(debounceT);
                debounceT = setTimeout(function () { loadUsersList(1); }, 250);
            });
        }
        var usersDeptEl = document.getElementById('usersDept');
        if (usersDeptEl) {
            usersDeptEl.addEventListener('change', function () { loadUsersList(1); });
        }
        var usersCompanyEl = document.getElementById('usersCompany');
        if (usersCompanyEl) {
            usersCompanyEl.addEventListener('change', function () {
                syncUsersDepartmentFilter();
                loadUsersList(1);
            });
        }
        var clearUsersBtn = document.getElementById('clearUsersFilters');
        if (clearUsersBtn) {
            clearUsersBtn.addEventListener('click', function () {
                if (usersSearch) usersSearch.value = '';
                var deptEl = document.getElementById('usersDept');
                var companyEl = document.getElementById('usersCompany');
                if (deptEl) deptEl.value = 'all';
                if (companyEl) companyEl.value = 'all';
                syncUsersDepartmentFilter();
                loadUsersList(1);
            });
        }

        var usersPagination = document.getElementById('usersPaginationControls');
        if (usersPagination) {
            usersPagination.addEventListener('click', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.page-btn') : null;
                if (!target) return;
                e.preventDefault();
                if (target.classList.contains('disabled') || target.classList.contains('active')) return;
                var nextPage = parseInt(target.getAttribute('data-page') || '', 10);
                if (!nextPage || nextPage < 1) return;
                loadUsersList(nextPage);
            });
        }

        syncUsersDepartmentFilter();
        loadUsersList(1);

        var itForm = document.getElementById('itSearchForm');
        var itInput = document.getElementById('itSearchInput');
        var itDebounce = null;
        if (itForm) {
            itForm.addEventListener('submit', function (e) {
                e.preventDefault();
                loadItEmployees(1);
            });
        }
        if (itInput) {
            itInput.addEventListener('input', function () {
                if (itDebounce) clearTimeout(itDebounce);
                itDebounce = setTimeout(function () { loadItEmployees(1); }, 250);
            });
        }
        var clearItBtn = document.getElementById('clearItSearch');
        if (clearItBtn) {
            clearItBtn.addEventListener('click', function () {
                if (itInput) itInput.value = '';
                loadItEmployees(1);
            });
        }

        var itPagination = document.getElementById('itPaginationControls');
        if (itPagination) {
            itPagination.addEventListener('click', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.page-btn') : null;
                if (!target) return;
                e.preventDefault();
                if (target.classList.contains('disabled') || target.classList.contains('active')) return;
                var nextPage = parseInt(target.getAttribute('data-page') || '', 10);
                if (!nextPage || nextPage < 1) return;
                loadItEmployees(nextPage);
            });
        }

        loadItEmployees(1);

        var itAdminsPagination = document.getElementById('itAdminsPaginationControls');
        if (itAdminsPagination) {
            itAdminsPagination.addEventListener('click', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.page-btn') : null;
                if (!target) return;
                e.preventDefault();
                if (target.classList.contains('disabled') || target.classList.contains('active')) return;
                var nextPage = parseInt(target.getAttribute('data-page') || '', 10);
                if (!nextPage || nextPage < 1) return;
                showItAdminsPage(nextPage);
            });
        }
        showItAdminsPage(1);
    });

    function confirmAddition(userId) {
        Swal.fire({
            title: 'Add this user as admin?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Add',
            cancelButtonText: 'Cancel',
            width: '420px',
            buttonsStyling: false,
            customClass: {
                popup: 'swal-admin-alert-popup',
                icon: 'swal-admin-alert-icon',
                title: 'swal-admin-alert-title',
                actions: 'swal-admin-alert-actions',
                confirmButton: 'swal-admin-alert-confirm',
                cancelButton: 'swal-admin-alert-cancel'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'add_admin.php?id=' + userId;
            }
        });
    }

    function confirmRemoval(adminId) {
        Swal.fire({
            title: 'Do you want to remove this admin?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Remove',
            cancelButtonText: 'Cancel',
            width: '420px',
            buttonsStyling: false,
            customClass: {
                popup: 'swal-admin-alert-popup',
                icon: 'swal-admin-alert-icon',
                title: 'swal-admin-alert-title',
                actions: 'swal-admin-alert-actions',
                confirmButton: 'swal-admin-alert-confirm',
                cancelButton: 'swal-admin-alert-cancel'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'remove_admin.php?id=' + adminId;
            }
        });
    }

    <?php if (isset($_SESSION['admin_added'])): ?>
        Swal.fire({
            icon: 'success',
            iconHtml: '<i class="fa-solid fa-check"></i>',
            title: 'Admin added',
            html: 'The selected user is now an admin.',
            width: '420px',
            confirmButtonText: 'OK',
            buttonsStyling: false,
            customClass: {
                popup: 'swal-admin-alert-popup',
                icon: 'swal-admin-alert-icon',
                title: 'swal-admin-alert-title',
                htmlContainer: 'swal-admin-alert-html',
                actions: 'swal-admin-alert-actions',
                confirmButton: 'swal-admin-alert-confirm'
            }
        });
        <?php unset($_SESSION['admin_added']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['admin_removed'])): ?>
        Swal.fire({
            icon: 'success',
            iconHtml: '<i class="fa-solid fa-check"></i>',
            title: 'Admin removed',
            html: 'The admin has been removed successfully.',
            width: '420px',
            confirmButtonText: 'OK',
            buttonsStyling: false,
            customClass: {
                popup: 'swal-admin-alert-popup',
                icon: 'swal-admin-alert-icon',
                title: 'swal-admin-alert-title',
                htmlContainer: 'swal-admin-alert-html',
                actions: 'swal-admin-alert-actions',
                confirmButton: 'swal-admin-alert-confirm'
            }
        });
        <?php unset($_SESSION['admin_removed']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            html: '<?= addslashes($_SESSION['error_message']) ?>',
            width: '420px',
            confirmButtonText: 'OK',
            buttonsStyling: false,
            customClass: {
                popup: 'swal-admin-alert-popup',
                icon: 'swal-admin-alert-icon',
                title: 'swal-admin-alert-title',
                htmlContainer: 'swal-admin-alert-html',
                actions: 'swal-admin-alert-actions',
                confirmButton: 'swal-admin-alert-confirm'
            }
        });
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
</script>

<style>
    .swal-cred-popup {
        border-radius: 18px !important;
        background: #ffffff !important;
        color: #0f172a !important;
        font-family: 'Inter', sans-serif !important;
        padding: 26px 22px 18px !important;
        width: min(520px, calc(100vw - 32px)) !important;
        box-shadow: 0 26px 80px rgba(2, 6, 23, 0.22) !important;
        border: 1px solid rgba(27, 94, 32, 0.18) !important;
    }
    .swal-cred-btn {
        margin-top: 18px !important;
        background: #1B5E20 !important;
        color: #ffffff !important;
        border: 1px solid rgba(20, 74, 30, 0.35) !important;
        border-radius: 12px !important;
        padding: 10px 18px !important;
        font-weight: 900 !important;
        cursor: pointer !important;
    }
    .swal-cred-btn:hover { background: #144a1e !important; }
    .swal2-container {
        z-index: 13050 !important;
    }
    .swal-create-user-container {
        z-index: 13060 !important;
    }
    .swal-create-user-popup {
        border-radius: 18px !important;
        background: #ffffff !important;
        color: #0f172a !important;
        border: 1px solid rgba(251, 191, 36, 0.28) !important;
        box-shadow: 0 26px 80px rgba(2, 6, 23, 0.22) !important;
        font-family: 'Inter', sans-serif !important;
        width: min(520px, calc(100vw - 32px)) !important;
    }
    .cred-wrap { text-align: center; }
    .cred-check {
        width: 72px;
        height: 72px;
        border-radius: 999px;
        background: #ecfdf5;
        border: 1px solid #bbf7d0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 14px;
        color: #1B5E20;
        font-size: 34px;
    }
    .cred-title { font-size: 22px; font-weight: 900; color: #0f172a; margin-bottom: 6px; }
    .cred-subtitle { font-size: 13px; font-weight: 800; color: #64748b; margin-bottom: 14px; letter-spacing: 0.02em; }
    .cred-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 14px;
        text-align: left;
    }
    .cred-row + .cred-row { margin-top: 12px; }
    .cred-label { font-size: 12px; font-weight: 900; color: #334155; margin-bottom: 6px; }
    .cred-value { display: flex; align-items: center; gap: 10px; }
    .cred-text {
        flex: 1 1 auto;
        min-width: 0;
        font-weight: 900;
        color: #0f172a;
        font-size: 14px;
        word-break: break-all;
    }
    .cred-icon-btn {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #1B5E20;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        flex: 0 0 auto;
    }
    .cred-icon-btn:hover { background: #ecfdf5; border-color: #bbf7d0; }
</style>

</body>
</html>
