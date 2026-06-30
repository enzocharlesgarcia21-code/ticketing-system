<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

ticket_ensure_assignment_columns($conn);
ticket_apply_sla_priority($conn);

function admin_sla_display_label(string $slaLevel): string
{
    return ticket_sla_display_label($slaLevel);
}

function admin_normalize_sla_filter(string $sla): string
{
    return ticket_normalize_sla_level($sla);
}

function time_ago_days(string $dateTime): string
{
    $dateTime = trim($dateTime);
    if ($dateTime === '') return '-';
    try {
        $created = new DateTimeImmutable($dateTime);
    } catch (Throwable $e) {
        return '-';
    }
    $now = new DateTimeImmutable('now');
    $createdDay = $created->setTime(0, 0, 0);
    $nowDay = $now->setTime(0, 0, 0);
    $diff = $nowDay->diff($createdDay);
    $days = (int) ($diff->days ?? 0);
    if ($diff->invert !== 1) $days = 0;
    if ($days <= 0) return 'Today';
    return $created->format('M d, Y');
}

function sla_badge_html(string $createdAt, string $status, string $priority = ''): string
{
    return ticket_sla_badge_html($createdAt, $status, $priority, '<span class="sla-empty">-</span>');
}

function sla_filter_condition_sql(string $tableAlias, string $sla): string
{
    return ticket_sla_filter_condition_sql($tableAlias, $sla);
}

function assigned_target_label(array $row): string
{
    $assignedCompany = ticket_normalize_company((string) (($row['assigned_company'] ?? '') !== '' ? $row['assigned_company'] : ($row['company'] ?? '')));
    $assignedGroup = trim((string) (($row['assigned_group'] ?? '') !== '' ? $row['assigned_group'] : ($row['assigned_department'] ?? '')));
    $assignedDept = trim((string) ($row['assigned_department'] ?? ''));

    if ($assignedGroup === '' && $assignedDept !== '') {
        $assignedGroup = $assignedDept;
    }

    $companyLabel = ticket_company_display_name($assignedCompany);
    $isLapc = ($assignedCompany === '@leadsagri.com' || strtoupper($assignedCompany) === 'LAPC');

    if ($isLapc && $assignedGroup !== '') {
        return $assignedGroup . ($companyLabel !== '' ? " ($companyLabel)" : '');
    }

    if ($companyLabel !== '') {
        return $companyLabel;
    }

    if ($assignedGroup !== '') {
        return $assignedGroup;
    }

    return '-';
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

/* ================= GET VALUES ================= */

$department = $_GET['department'] ?? '';
$company_email = $_GET['company_email'] ?? '';
$sla        = trim((string) ($_GET['sla'] ?? ($_GET['priority'] ?? '')));
$slaLevel = admin_normalize_sla_filter($sla);
if ($slaLevel !== '') {
    $sla = admin_sla_display_label($slaLevel);
}
$status     = $_GET['status']     ?? '';
$search     = $_GET['search']     ?? '';
$view       = (string) ($_GET['view'] ?? '');
$view = $view === 'trash' ? '' : $view;
$department_key = $department !== '' ? ticket_department_key_from_value((string) $department) : '';
$departmentFilterOptionsByCompany = [
    '@leadsagri.com' => ticket_company_allowed_groups('@leadsagri.com'),
    '@malvedaholdings.com' => ticket_company_allowed_groups('@malvedaholdings.com'),
];
$normalizedCompanyFilter = ticket_normalize_company((string) $company_email);
$initialDepartmentFilterOptions = $departmentFilterOptionsByCompany[$normalizedCompanyFilter] ?? [];
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$allowedViews = ['all', 'my_open', 'resolved'];
if (!in_array($view, $allowedViews, true)) $view = '';

$query = "
SELECT employee_tickets.*, users.name, users.email, users.department AS user_department
FROM employee_tickets
LEFT JOIN users ON employee_tickets.user_id = users.id
WHERE 1
";

/* ================= FILTERS ================= */

if ($view !== '') {
    if ($view === 'my_open') {
        $query .= " AND employee_tickets.status IN ('Open','In Progress')";
    } elseif ($view === 'resolved') {
        $query .= " AND employee_tickets.status = 'Resolved'";
    }
}
$query .= " AND COALESCE(NULLIF(employee_tickets.status,''),'') <> 'Trash'";

if (!empty($department)) {
    $deptKey = $department_key !== '' ? $department_key : ticket_department_key_from_value((string) $department);
    $deptAliases = ticket_department_aliases_for_key($deptKey);
    $deptAliases[] = $deptKey;
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $deptAliases)), static function ($v) { return is_string($v) && $v !== ''; })));

    if (count($deptAliases) > 0) {
        $deptConds = [];
        foreach ($deptAliases as $a) {
            $aEsc = $conn->real_escape_string($a);
            $deptConds[] = "UPPER(COALESCE(NULLIF(employee_tickets.assigned_group,''), NULLIF(employee_tickets.assigned_department,''), NULLIF(employee_tickets.department,''), NULLIF(users.department,''))) = '$aEsc'";
        }
        $query .= " AND (" . implode(" OR ", $deptConds) . ")";
    }
}

if (!empty($sla)) {
    $slaCondition = sla_filter_condition_sql('employee_tickets', (string) $sla);
    if ($slaCondition !== '') {
        $query .= " AND ($slaCondition)";
    } else {
        $sla = '';
    }
}

if (!empty($status)) {
    if ($status === 'unread') {
        $query .= " AND employee_tickets.is_read = 0";
    } else {
        $status = $conn->real_escape_string($status);
        $query .= " AND employee_tickets.status = '$status'";
    }
}

if (!empty($company_email)) {
    $domain = strtolower(trim((string) $company_email));
    if ($domain === '__farmex_lav__') {
        $query .= " AND LOWER(COALESCE(NULLIF(employee_tickets.assigned_company,''), NULLIF(employee_tickets.company,''))) IN ('@leads-farmex.com', '@leadsav.com')";
    } elseif ($domain !== '') {
        if ($domain[0] !== '@') $domain = '@' . $domain;
        $domainEsc = $conn->real_escape_string($domain);
        $query .= " AND LOWER(COALESCE(NULLIF(employee_tickets.assigned_company,''), NULLIF(employee_tickets.company,''))) = '$domainEsc'";
    }
}

if (!empty($search)) {
    $searchSQL = $conn->real_escape_string($search);
    $searchPrefixSQL = $conn->real_escape_string($search . '%');
    
    // Parse ID from search (remove non-digits)
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int)$searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    $query .= " AND (
        users.name LIKE '$searchPrefixSQL' OR
        LOWER(COALESCE(NULLIF(employee_tickets.requester_email,''), users.email)) LIKE LOWER('$searchPrefixSQL') OR
        employee_tickets.subject LIKE '$searchPrefixSQL' OR
        employee_tickets.description LIKE '$searchPrefixSQL' OR
        employee_tickets.id LIKE '%$searchSQL%'";

    if ($searchById) {
        $query .= " OR employee_tickets.id = $searchIdInt";
    }
    
    $query .= " )";
}

// --- PAGINATION LOGIC ---
$allowed_limits = [10, 25, 50, 100, 500, 1000];
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
if (!in_array($limit, $allowed_limits, true)) {
    $limit = 10;
}
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Count total records (reuse the WHERE clause from $query)
$from_pos = strpos($query, "FROM employee_tickets");
if ($from_pos !== false) {
    $count_query = "SELECT COUNT(*) as total " . substr($query, $from_pos);
    $total_result = $conn->query($count_query);
    $total_row = $total_result->fetch_assoc();
    $total_records = $total_row['total'];
} else {
    $total_records = 0;
}

$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

$query .= " ORDER BY employee_tickets.created_at DESC LIMIT ?, ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $offset, $limit);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tickets</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        .at-layout { width: 100%; max-width: 1560px; min-width: 0; display: block; }
        .at-main { flex: 1 1 auto; min-width: 0; max-width: 100%; }
        .at-main .admin-content { max-width: none; min-width: 0; }
        .at-main .admin-card { max-width: 100%; min-width: 0; }
        #filterForm .filter-row {
            display: grid;
            grid-template-columns: minmax(260px, 1.25fr) minmax(180px, 220px) minmax(110px, 130px) minmax(104px, 120px) minmax(112px, 128px);
            gap: 8px;
            align-items: center;
            width: 100%;
            min-width: 0;
        }
        #filterForm .filter-row:has(#departmentFilterWrap:not(.is-hidden)) {
            grid-template-columns: minmax(220px, 1fr) minmax(160px, 190px) minmax(240px, 1.1fr) minmax(96px, 112px) minmax(104px, 118px) minmax(108px, 112px);
        }
        #filterForm .filter-row:has(#departmentFilterWrap:not(.is-hidden)) .filter-input {
            min-width: 220px;
        }
        #filterForm .filter-input {
            width: 100%;
            min-width: 260px;
            max-width: 100%;
            min-height: 44px;
            padding: 10px 14px;
            border: 2px solid #d8e2ec;
            border-radius: 13px;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            box-shadow: none;
            outline: none;
            transition: border-color 0.16s ease, box-shadow 0.16s ease;
        }
        #filterForm .filter-input:focus {
            border-color: #cbd5e1;
            box-shadow: none;
        }
        #filterForm .filter-input::placeholder {
            color: #0f172a;
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            opacity: 1;
        }
        #filterForm .filter-select {
            min-width: 0;
            padding: 10px 26px 10px 10px;
            flex: 1 1 150px;
            max-width: 100%;
        }
        #filterForm #recipientFilterSelect { flex-basis: 220px; }
        #filterForm #departmentFilterSelect { width: 100%; }
        #filterForm select[name="sla"] { flex: 0 1 132px; }
        #filterForm select[name="status"] { flex: 0 1 132px; }
        #filterForm .at-select-wrap {
            position: relative;
            min-width: 0;
            max-width: 100%;
            width: 100%;
        }
        #filterForm .at-select-wrap.company-filter-wrap {
            width: 100%;
        }
        #filterForm .at-select-wrap.sla-filter-wrap,
        #filterForm .at-select-wrap.status-filter-wrap {
            width: 100%;
        }
        #filterForm .at-select-wrap .filter-select {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            pointer-events: none;
        }
        #filterForm .at-select-trigger {
            width: 100%;
            min-height: 44px;
            padding: 10px 38px 10px 14px;
            border: 2px solid #d8e2ec;
            border-radius: 13px;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            text-align: left;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            box-shadow: none;
            min-width: 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        #filterForm .at-select-trigger::after {
            content: "\f078";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #0f172a;
            font-size: 11px;
            transition: transform 0.16s ease, color 0.16s ease;
        }
        #filterForm .at-select-wrap.is-open .at-select-trigger::after {
            transform: translateY(-50%) rotate(180deg);
            color: #166534;
        }
        #filterForm .at-select-menu {
            position: absolute;
            z-index: 80;
            top: calc(100% + 7px);
            left: 0;
            right: 0;
            display: none;
            max-height: 250px;
            overflow-y: auto;
            padding: 7px 0;
            background: #ffffff;
            border: 2px solid #d8e2ec;
            border-radius: 13px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.12);
            scrollbar-width: thin;
            scrollbar-color: #9ca3af #f3f4f6;
        }
        #filterForm .at-select-menu::-webkit-scrollbar { width: 10px; }
        #filterForm .at-select-menu::-webkit-scrollbar-track {
            background: #f3f4f6;
            border-radius: 999px;
        }
        #filterForm .at-select-menu::-webkit-scrollbar-thumb {
            background: #9ca3af;
            border-radius: 999px;
            border: 2px solid #f3f4f6;
        }
        #filterForm .at-select-menu::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
        #filterForm .at-select-wrap.is-open .at-select-menu {
            display: block;
        }
        #filterForm .at-select-option {
            min-height: 34px;
            padding: 8px 14px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 400;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        #filterForm .at-select-option:hover {
            background: #edf7ef;
        }
        #filterForm .at-select-option.is-selected {
            background: #166534;
            color: #ffffff;
        }
        #filterForm .clear-btn {
            margin-left: 0;
            min-height: 44px;
            padding: 10px 12px;
            border: 2px solid #d8e2ec;
            border-radius: 13px;
            background: #ffffff;
            color: #0f172a;
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            text-decoration: none;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            transition: border-color 0.16s ease, color 0.16s ease, box-shadow 0.16s ease;
        }
        #filterForm .clear-btn:hover,
        #filterForm .clear-btn:focus {
            border-color: #cbd5e1;
            color: #0f172a;
            box-shadow: none;
            outline: none;
        }
        #filterForm .lapc-department-filter {
            min-width: 220px;
            max-width: none;
            width: 100%;
        }
        #filterForm .lapc-department-filter.is-hidden {
            display: none;
        }
        #filterForm .lapc-department-filter.is-disabled {
            opacity: 0.7;
        }
        .table-footer-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 0 0;
            flex-wrap: wrap;
        }
        .table-footer-bar .entries-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 0 0 auto;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .table-footer-bar .tickets-summary {
            flex: 1 1 260px;
            min-width: 220px;
            text-align: center;
            color: #64748b;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }
        .table-footer-bar .entries-row .filter-select {
            width: 96px;
            min-width: 96px;
        }
        .table-footer-bar #ticketsPagination {
            flex: 0 0 auto;
            margin-left: auto;
            max-width: 100%;
            min-width: 420px;
        }
        .table-footer-bar #ticketsPagination .pagination-glass {
            display: inline-flex;
            justify-content: center;
            width: 100%;
            gap: 8px;
            margin-top: 0;
            flex-wrap: nowrap;
        }
        .table-footer-bar #ticketsPagination .page-numbers {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 224px;
            gap: 6px;
        }
        .table-footer-bar #ticketsPagination .page-btn {
            min-width: 38px;
            height: 38px;
            padding: 0 13px;
            border: 1px solid #d8e2ec;
            background: #ffffff;
            color: #334155;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            font-size: 13px;
        }
        .table-footer-bar #ticketsPagination .page-btn.prev,
        .table-footer-bar #ticketsPagination .page-btn.next {
            min-width: 72px;
            padding: 0 14px;
            font-weight: 700;
        }
        .table-footer-bar #ticketsPagination .page-btn:hover:not(.active):not(.disabled) {
            background: #f8fafc;
            border-color: #cfd9e3;
        }
        .table-footer-bar #ticketsPagination .page-btn.active {
            background: #166534;
            border-color: #166534;
            color: #ffffff;
            box-shadow: 0 8px 22px rgba(22, 101, 52, 0.18);
        }
        .table-footer-bar #ticketsPagination .page-btn.disabled {
            opacity: 0.45;
            background: #ffffff;
            border-color: #d8e2ec;
        }
        .table-footer-bar #ticketsPagination .pagination-ellipsis {
            min-width: 18px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.08em;
        }
        .status-resolved {
            background: #dbeafe !important;
            border-color: #bfdbfe !important;
            color: #1d4ed8 !important;
        }
        .status-closed {
            background: #f3f4f6 !important;
            border-color: #e5e7eb !important;
            color: #4b5563 !important;
        }
        .table-card {
            overflow: hidden;
        }
        .table-responsive {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }
        .admin-table {
            width: 100%;
            min-width: 1040px;
        }
        .sla-empty {
            display: inline-block;
            margin-left: 10px;
        }
        @media (max-width: 1100px) {
            .at-layout { max-width: 1200px; }
            #filterForm .filter-row {
                grid-template-columns: minmax(240px, 1.2fr) minmax(160px, 200px) minmax(100px, 120px) minmax(104px, 118px) minmax(108px, 112px);
            }
            .table-footer-bar {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        @media (max-width: 768px) {
            .admin-container {
                padding-left: 16px;
                padding-right: 16px;
            }
            #filterForm .filter-input,
            #filterForm .filter-select,
            #filterForm .lapc-department-filter,
            #filterForm .clear-btn {
                width: 100%;
            }
            #filterForm .filter-row {
                grid-template-columns: 1fr;
            }
            .admin-table {
                min-width: 0;
            }
            .table-footer-bar {
                flex-direction: column;
                align-items: center;
            }
            .table-footer-bar #ticketsPagination {
                width: 100%;
                margin-left: 0;
                min-width: 0;
            }
            .table-footer-bar #ticketsPagination .pagination-glass {
                justify-content: center;
                gap: 8px;
            }
            .table-footer-bar #ticketsPagination .page-numbers {
                min-width: 0;
                gap: 8px;
            }
            .table-footer-bar #ticketsPagination .page-btn {
                min-width: 38px;
                height: 38px;
                padding: 0 13px;
                font-size: 13px;
            }
            .table-footer-bar #ticketsPagination .page-btn.prev,
            .table-footer-bar #ticketsPagination .page-btn.next {
                min-width: 74px;
                padding: 0 14px;
            }
            .table-footer-bar #ticketsPagination .pagination-ellipsis {
                min-width: 18px;
                height: 38px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body>

<div class="admin-page">
    
    <!-- Admin Navbar -->
    <?php include '../includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-content">
            <div class="at-layout">
                <div class="at-main">

            <?php if(isset($_SESSION['success'])): ?>
                <div class="admin-notice">
                    <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">All Tickets</h1>
                    <p class="admin-page-subtitle">Manage, monitor, and track support tickets with their status, assigned department, SLA progress, and requestor details.</p>
                </div>
            </div>

            <!-- FILTERS -->
            <div class="admin-card filter-card">
                <form method="GET" id="filterForm">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="department" id="departmentFilterValue" value="<?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="company_email" id="companyEmailFilterValue" value="<?= htmlspecialchars($company_email, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="filter-row">
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="filter-input"
                               placeholder="Search by ID, name, email or subject..."
                               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="at-select-wrap company-filter-wrap" data-at-select="company">
                            <select id="recipientFilterSelect" class="filter-select" tabindex="-1">
                                <option value="" <?= $company_email === '' ? 'selected' : '' ?>>All Company</option>
                                <option value="@farmasee.ph">FARMASEE</option>
                                <option value="__farmex_lav__" <?= $company_email === '__farmex_lav__' ? 'selected' : '' ?>>FARMEX / LAV</option>
                                <option value="@gpsci.net" <?= $company_email === '@gpsci.net' ? 'selected' : '' ?>>GPSCI</option>
                                <option value="@leadsagri.com">LAPC</option>
                                <option value="@leadstech-corp.com">LTC</option>
                                <option value="@lingapleads.org">LINGAP</option>
                                <option value="@malvedaholdings.com">MHC</option>
                                <option value="@malvedaproperties.com">MPDC</option>
                                <option value="@primestocks.ph">PCC</option>
                            </select>
                            <button type="button" class="at-select-trigger" aria-haspopup="listbox" aria-expanded="false">All Company</button>
                            <div class="at-select-menu" role="listbox"></div>
                        </div>

                        <div id="departmentFilterWrap" class="at-select-wrap lapc-department-filter is-hidden is-disabled" data-at-select="department">
                            <select id="departmentFilterSelect" class="filter-select" tabindex="-1" disabled>
                                <option value="" disabled <?= $department === '' ? 'selected' : ''; ?> hidden>All Department</option>
                                <?php foreach ($initialDepartmentFilterOptions as $departmentOption): ?>
                                    <option value="<?= htmlspecialchars((string) $departmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $department === (string) $departmentOption ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars((string) $departmentOption, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="at-select-trigger" aria-haspopup="listbox" aria-expanded="false">All Department</button>
                            <div class="at-select-menu" role="listbox"></div>
                        </div>

                        <div class="at-select-wrap sla-filter-wrap" data-at-select="sla">
                            <select name="sla" class="filter-select" tabindex="-1">
                                <option value="" <?= $sla === '' ? 'selected' : '' ?>>All SLA</option>
                                <option value="On Track" <?= $sla=='On Track'?'selected':'' ?>>On Track</option>
                                <option value="At Risk" <?= $sla=='At Risk'?'selected':'' ?>>At Risk</option>
                                <option value="Breach" <?= $sla=='Breach'?'selected':'' ?>>Breach</option>
                            </select>
                            <button type="button" class="at-select-trigger" aria-haspopup="listbox" aria-expanded="false">All SLA</button>
                            <div class="at-select-menu" role="listbox"></div>
                        </div>

                        <div class="at-select-wrap status-filter-wrap" data-at-select="status">
                            <select name="status" class="filter-select" tabindex="-1">
                                <option value="" <?= $status === '' ? 'selected' : '' ?>>All Status</option>
                                <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                                <option value="In Progress" <?= $status=='In Progress'?'selected':'' ?>>In Progress</option>
                                <option value="Resolved" <?= $status=='Resolved'?'selected':'' ?>>Resolved</option>
                                <option value="Closed" <?= $status=='Closed'?'selected':'' ?>>Closed</option>
                            </select>
                            <button type="button" class="at-select-trigger" aria-haspopup="listbox" aria-expanded="false">All Status</button>
                            <div class="at-select-menu" role="listbox"></div>
                        </div>

                        <a href="all_tickets.php" class="clear-btn" id="clearFiltersBtn">Clear Filters</a>
                    </div>
                </form>
            </div>

            <!-- TABLE -->
            <div class="admin-card table-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Requested By</th>
                                <th>Status</th>
                                <th>Department</th>
                                <th>Created</th>
                                <th>SLA</th>
                                <th>Assign To</th>
                            </tr>
                        </thead>
                        <tbody id="ticketsTbody">
                            <?php while($row = $result->fetch_assoc()) { ?>
                            <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer; <?= $row['is_read'] == 0 ? 'background:rgba(27, 94, 32, 0.08);' : ''; ?>">
                                <td data-label="ID">#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td data-label="Requested By">
                                    <div class="user-info">
                                        <?php
                                            $dispName = isset($row['requester_name']) && $row['requester_name'] !== '' ? $row['requester_name'] : $row['name'];
                                            $dispEmail = isset($row['requester_email']) && $row['requester_email'] !== '' ? $row['requester_email'] : $row['email'];
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
                                <td data-label="Status">
                                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                        <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php if($row['is_read'] == 0): ?>
                                        <span class="new-badge">NEW</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Department"><?php 
                                    $origDept = !empty($row['department']) ? $row['department'] : ($row['user_department'] ?? '');
                                    echo htmlspecialchars($origDept !== '' ? ticket_department_display_name((string) $origDept) : 'Sales');
                                ?></td>
                                <td data-label="Created"><?= htmlspecialchars(time_ago_days((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="SLA"><?= sla_badge_html((string) ($row['created_at'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['priority'] ?? '')); ?></td>
                                <td data-label="Assign To"><?= htmlspecialchars(assigned_target_label($row), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-footer-bar">
                    <div class="entries-row">
                        <span>Show</span>
                        <select id="limitSelect" name="limit" class="filter-select" onchange="submitForm(1)">
                            <?php foreach ($allowed_limits as $allowed_limit): ?>
                                <option value="<?= $allowed_limit; ?>" <?= $limit === $allowed_limit ? 'selected' : ''; ?>><?= number_format($allowed_limit); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span>Entries</span>
                    </div>

                    <?php
                        $summaryStart = $total_records > 0 ? ($offset + 1) : 0;
                        $summaryEnd = $total_records > 0 ? min($total_records, $offset + $limit) : 0;
                    ?>
                    <div class="tickets-summary" id="ticketsSummary">
                        Showing <?= number_format($summaryStart); ?>-<?= number_format($summaryEnd); ?> of <?= number_format((int) $total_records); ?> tickets
                    </div>

                    <!-- PAGINATION UI -->
                    <div id="ticketsPagination">
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-glass">
                        <!-- Previous Link -->
                        <a href="?page=<?= $page - 1; ?>&limit=<?= $limit; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&sla=<?= urlencode($sla); ?>&status=<?= urlencode($status); ?>&view=<?= urlencode($view); ?>" 
                           data-page="<?= max(1, $page - 1) ?>"
                           class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                            &lsaquo; Previous
                        </a>

                        <!-- Page Numbers -->
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
                                    <a href="?page=<?= $pagination_item; ?>&limit=<?= $limit; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&sla=<?= urlencode($sla); ?>&status=<?= urlencode($status); ?>&view=<?= urlencode($view); ?>" 
                                       data-page="<?= $pagination_item ?>"
                                       class="page-btn <?= ($pagination_item == $page) ? 'active' : ''; ?>">
                                        <?= $pagination_item; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Next Link -->
                        <a href="?page=<?= $page + 1; ?>&limit=<?= $limit; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&sla=<?= urlencode($sla); ?>&status=<?= urlencode($status); ?>&view=<?= urlencode($view); ?>" 
                           data-page="<?= min($total_pages, $page + 1) ?>"
                           class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            Next &rsaquo;
                        </a>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>

            </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
let typingTimer;
const doneTypingInterval = 350;

const searchInput = document.getElementById("searchInput");
const filterForm = document.getElementById("filterForm");
const tbodyEl = document.getElementById("ticketsTbody");
const paginationEl = document.getElementById("ticketsPagination");
const ticketsSummaryEl = document.getElementById("ticketsSummary");
var currentAdminTicketsPage = <?php echo (int) $page; ?>;
var adminTicketsAutoRefreshMs = 10000;

function adminTicketModalOpen() {
    var overlay = document.getElementById('ticketModal');
    return !!(overlay && overlay.style.display === 'flex');
}
const limitSelect = document.getElementById("limitSelect");
const recipientFilterSelect = document.getElementById("recipientFilterSelect");
const departmentFilterSelect = document.getElementById("departmentFilterSelect");
const departmentFilterWrap = document.getElementById("departmentFilterWrap");
const companyEmailFilterValue = document.getElementById("companyEmailFilterValue");
const departmentFilterValue = document.getElementById("departmentFilterValue");
const departmentOptionsByCompany = <?php echo json_encode($departmentFilterOptionsByCompany, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function atEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
}

function closeAtSelects(exceptWrap) {
    document.querySelectorAll('#filterForm .at-select-wrap.is-open').forEach(function (wrap) {
        if (exceptWrap && wrap === exceptWrap) return;
        wrap.classList.remove('is-open');
        var trigger = wrap.querySelector('.at-select-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
}

function refreshAtSelect(selectEl) {
    if (!selectEl) return;
    var wrap = selectEl.closest('.at-select-wrap');
    if (!wrap) return;
    var trigger = wrap.querySelector('.at-select-trigger');
    var menu = wrap.querySelector('.at-select-menu');
    if (!trigger || !menu) return;
    var selectedOption = selectEl.options[selectEl.selectedIndex] || selectEl.options[0];
    trigger.textContent = selectedOption ? selectedOption.textContent.trim() : '';
    trigger.disabled = !!selectEl.disabled;
    menu.innerHTML = Array.prototype.slice.call(selectEl.options).map(function (option, index) {
        if (String(option.value || '') === '') return '';
        var selected = option.selected ? ' is-selected' : '';
        return '<div class="at-select-option' + selected + '" role="option" aria-selected="' + (option.selected ? 'true' : 'false') + '" data-index="' + index + '">' + atEscapeHtml(option.textContent.trim()) + '</div>';
    }).join('');
}

function initAtSelect(selectEl) {
    if (!selectEl) return;
    var wrap = selectEl.closest('.at-select-wrap');
    if (!wrap) return;
    var trigger = wrap.querySelector('.at-select-trigger');
    var menu = wrap.querySelector('.at-select-menu');
    if (!trigger || !menu) return;
    refreshAtSelect(selectEl);
    trigger.addEventListener('click', function () {
        if (selectEl.disabled) return;
        var isOpen = wrap.classList.contains('is-open');
        closeAtSelects(wrap);
        wrap.classList.toggle('is-open', !isOpen);
        trigger.setAttribute('aria-expanded', !isOpen ? 'true' : 'false');
    });
    menu.addEventListener('click', function (event) {
        var optionEl = event.target && event.target.closest ? event.target.closest('.at-select-option') : null;
        if (!optionEl) return;
        var index = Number(optionEl.getAttribute('data-index'));
        if (!Number.isFinite(index) || !selectEl.options[index]) return;
        selectEl.selectedIndex = index;
        refreshAtSelect(selectEl);
        closeAtSelects();
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

function getCompanyDepartmentOptions(companyValue) {
    var key = String(companyValue || '').toLowerCase();
    return Array.isArray(departmentOptionsByCompany[key]) ? departmentOptionsByCompany[key] : [];
}

function rebuildDepartmentFilterOptions(companyValue, selectedDepartment) {
    if (!departmentFilterSelect) return [];
    var departments = getCompanyDepartmentOptions(companyValue);
    var selectedValue = String(selectedDepartment || '');
    departmentFilterSelect.innerHTML = '';

    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.disabled = true;
    placeholder.hidden = true;
    placeholder.textContent = 'All Department';
    placeholder.selected = true;
    departmentFilterSelect.appendChild(placeholder);

    departments.forEach(function (departmentName) {
        var value = String(departmentName || '');
        if (!value) return;
        var option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        if (selectedValue === value) {
            option.selected = true;
            placeholder.selected = false;
        }
        departmentFilterSelect.appendChild(option);
    });

    if (departments.indexOf(selectedValue) === -1) {
        departmentFilterSelect.value = '';
    }

    return departments;
}

searchInput.addEventListener("input", function () {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(doneTyping, doneTypingInterval);
});

function doneTyping() {
    submitForm(1);
}

function syncRecipientFilters() {
    if (!recipientFilterSelect || !departmentFilterSelect || !departmentFilterWrap || !companyEmailFilterValue || !departmentFilterValue) return;

    var currentCompany = String(companyEmailFilterValue.value || '').toLowerCase();
    var currentDepartment = String(departmentFilterValue.value || '');
    recipientFilterSelect.value = currentCompany;
    if (recipientFilterSelect.value !== currentCompany) {
        recipientFilterSelect.value = '';
    }

    var companyDepartments = rebuildDepartmentFilterOptions(currentCompany, currentDepartment);
    if (companyDepartments.length > 0) {
        departmentFilterWrap.classList.remove('is-hidden');
        departmentFilterWrap.classList.remove('is-disabled');
        departmentFilterSelect.disabled = false;
    } else {
        departmentFilterWrap.classList.add('is-hidden');
        departmentFilterWrap.classList.add('is-disabled');
        departmentFilterSelect.disabled = true;
        departmentFilterSelect.value = '';
    }
    refreshAtSelect(recipientFilterSelect);
    refreshAtSelect(departmentFilterSelect);
}

function handleRecipientFilterChange() {
    if (!recipientFilterSelect || !departmentFilterSelect || !departmentFilterWrap || !companyEmailFilterValue || !departmentFilterValue) return;

    var selectedValue = String(recipientFilterSelect.value || '').toLowerCase();
    companyEmailFilterValue.value = selectedValue;

    if (!selectedValue) {
        departmentFilterValue.value = '';
        departmentFilterSelect.value = '';
        departmentFilterSelect.disabled = true;
        departmentFilterWrap.classList.add('is-hidden');
        departmentFilterWrap.classList.add('is-disabled');
        refreshAtSelect(recipientFilterSelect);
        refreshAtSelect(departmentFilterSelect);
        submitForm(1);
        return;
    }

    var selectedDepartments = rebuildDepartmentFilterOptions(selectedValue, '');
    if (selectedDepartments.length > 0) {
        departmentFilterValue.value = '';
        departmentFilterSelect.value = '';
        departmentFilterSelect.disabled = false;
        departmentFilterWrap.classList.remove('is-hidden');
        departmentFilterWrap.classList.remove('is-disabled');
        refreshAtSelect(recipientFilterSelect);
        refreshAtSelect(departmentFilterSelect);
        submitForm(1);
        return;
    }

    departmentFilterValue.value = '';
    departmentFilterSelect.value = '';
    departmentFilterSelect.disabled = true;
    departmentFilterWrap.classList.add('is-hidden');
    departmentFilterWrap.classList.add('is-disabled');
    refreshAtSelect(recipientFilterSelect);
    refreshAtSelect(departmentFilterSelect);
    submitForm(1);
}

function handleDepartmentFilterChange() {
    if (!departmentFilterSelect || !companyEmailFilterValue || !departmentFilterValue) return;
    departmentFilterValue.value = String(departmentFilterSelect.value || '');
    refreshAtSelect(departmentFilterSelect);
    submitForm(1);
}

syncRecipientFilters();

function serializeForm(page) {
    var fd = new FormData(filterForm);
    var params = new URLSearchParams();
    fd.forEach(function (v, k) {
        if (v === null || v === undefined) return;
        var s = String(v);
        if (s.trim() === '') return;
        params.set(k, s);
    });
    params.set('page', String(page || 1));
    if (limitSelect && limitSelect.value) {
        params.set('limit', String(limitSelect.value));
    }
    return params;
}

function refreshTickets(page, updateHistory) {
    if (!filterForm || !tbodyEl || !paginationEl) return;
    var nextPage = parseInt(page || currentAdminTicketsPage || 1, 10);
    if (!nextPage || nextPage < 1) nextPage = 1;
    var params = serializeForm(nextPage);
    fetch('ajax_all_tickets_list.php?' + params.toString(), { method: 'GET' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            tbodyEl.innerHTML = data.rows_html || '';
            paginationEl.innerHTML = data.pagination_html || '';
            if (ticketsSummaryEl) {
                ticketsSummaryEl.textContent = data.summary_text || 'Showing 0-0 of 0 tickets';
            }
            currentAdminTicketsPage = parseInt(data.page || nextPage, 10) || 1;
            if (updateHistory === false) return;
            var url = new URL(window.location.href);
            url.search = '';
            params.forEach(function (v, k) { url.searchParams.set(k, v); });
            url.searchParams.set('page', String(currentAdminTicketsPage));
            history.replaceState({}, '', url.toString());
        })
        .catch(function () {});
}

function scheduleAdminTicketsRefresh() {
    if (document.hidden || adminTicketModalOpen()) return;
    refreshTickets(currentAdminTicketsPage, false);
}

function submitForm(page){
    refreshTickets(page || 1);
}
</script>
<!-- Ticket Details Modal -->
<div id="ticketModal" class="modal-overlay">
    <div class="modal-content" id="modalContent">
        <!-- Content injected via JS -->
    </div>
</div>

<!-- Chat Modal Removed (Integrated into Ticket Modal) -->

<div id="imagePreviewModal" class="image-preview-modal" onclick="TMTicketModal.closeImagePreview(event)">
    <div class="preview-content">
        <button type="button" class="preview-close" onclick="TMTicketModal.closeImagePreview(event)" aria-label="Close preview">X</button>
        <button type="button" class="preview-nav preview-prev" onclick="TMTicketModal.stepImagePreview(-1)" aria-label="Previous attachment"><i class="fas fa-chevron-left"></i></button>
        <img id="previewImage" src="" alt="Preview" class="preview-image">
        <button type="button" class="preview-nav preview-next" onclick="TMTicketModal.stepImagePreview(1)" aria-label="Next attachment"><i class="fas fa-chevron-right"></i></button>
    </div>
</div>
<script>
window.TM_CURRENT_USER = <?php echo json_encode([
    'id' => $_SESSION['user_id'] ?? null,
    'name' => $_SESSION['name'] ?? null,
    'email' => $_SESSION['email'] ?? null,
    'department' => $_SESSION['department'] ?? null,
    'company' => $_SESSION['company'] ?? null,
    'role' => $_SESSION['role'] ?? null
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.TM_HIDE_QUICK_TAGS = true;
</script>
<script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('click', function (e) {
    var target = e.target;
    var row = target && target.closest ? target.closest('.ticket-row') : null;
    if (row && row.getAttribute) {
        var ticketId = row.getAttribute('data-id');
        if (ticketId && typeof TMTicketModal !== 'undefined' && typeof TMTicketModal.open === 'function') {
            TMTicketModal.open(ticketId);
        }
    }
    var pageBtn = target && target.closest ? target.closest('#ticketsPagination a.page-btn') : null;
    if (pageBtn) {
        if (pageBtn.classList.contains('disabled')) {
            e.preventDefault();
            return;
        }
        var p = pageBtn.getAttribute('data-page');
        if (p) {
            e.preventDefault();
            submitForm(parseInt(p, 10) || 1);
        }
    }
});

var clearBtn = document.getElementById('clearFiltersBtn');
if (clearBtn) {
    clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!filterForm) return;
        filterForm.reset();
        if (recipientFilterSelect) recipientFilterSelect.value = '';
        var slaFilterSelect = document.querySelector('#filterForm select[name="sla"]');
        if (slaFilterSelect) slaFilterSelect.value = '';
        var statusFilterSelect = document.querySelector('#filterForm select[name="status"]');
        if (statusFilterSelect) statusFilterSelect.value = '';
        if (companyEmailFilterValue) companyEmailFilterValue.value = '';
        if (departmentFilterValue) departmentFilterValue.value = '';
        if (departmentFilterSelect) {
            departmentFilterSelect.value = '';
            departmentFilterSelect.disabled = true;
        }
        if (departmentFilterWrap) {
            departmentFilterWrap.classList.add('is-hidden');
            departmentFilterWrap.classList.add('is-disabled');
        }
        syncRecipientFilters();
        if (searchInput) searchInput.value = '';
        refreshAtSelect(recipientFilterSelect);
        refreshAtSelect(departmentFilterSelect);
        refreshAtSelect(slaFilterSelect);
        refreshAtSelect(statusFilterSelect);
        submitForm(1);
    });
}

if (filterForm) {
    filterForm.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(1);
    });
}

document.querySelectorAll('#filterForm .at-select-wrap select').forEach(function (selectEl) {
    initAtSelect(selectEl);
});
document.addEventListener('click', function (event) {
    if (event.target && event.target.closest && event.target.closest('#filterForm .at-select-wrap')) return;
    closeAtSelects();
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeAtSelects();
});

if (limitSelect) {
    limitSelect.addEventListener('change', function () {
        submitForm(1);
    });
}

if (recipientFilterSelect) {
    recipientFilterSelect.addEventListener('change', function () {
        refreshAtSelect(recipientFilterSelect);
        handleRecipientFilterChange();
    });
}

if (departmentFilterSelect) {
    departmentFilterSelect.addEventListener('change', handleDepartmentFilterChange);
}
var slaFilterSelect = document.querySelector('#filterForm select[name="sla"]');
if (slaFilterSelect) {
    slaFilterSelect.addEventListener('change', function () {
        refreshAtSelect(slaFilterSelect);
        submitForm(1);
    });
}
var statusFilterSelect = document.querySelector('#filterForm select[name="status"]');
if (statusFilterSelect) {
    statusFilterSelect.addEventListener('change', function () {
        refreshAtSelect(statusFilterSelect);
        submitForm(1);
    });
}
setInterval(scheduleAdminTicketsRefresh, adminTicketsAutoRefreshMs);
document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
        scheduleAdminTicketsRefresh();
    }
});
</script>
    <script src="../js/admin.js"></script>
<script>
const urlParams = new URLSearchParams(window.location.search);
const ticketIdParam = urlParams.get('ticket_id') || urlParams.get('id');
if (ticketIdParam) {
    if (typeof TMTicketModal !== 'undefined' && typeof TMTicketModal.open === 'function') {
        TMTicketModal.open(ticketIdParam);
    }
}
</script>



</body>
</html>
