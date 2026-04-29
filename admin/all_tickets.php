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
    $statusKey = strtolower(trim($status));
    if ($statusKey === 'resolved' || $statusKey === 'closed') return '-';
    $priorityKey = strtolower(trim($priority));
    if ($priorityKey === 'critical') {
        return '<span class="badge badge-critical">Breached</span>';
    }
    if ($priorityKey === 'high') {
        return '<span class="badge badge-high">At Risk</span>';
    }
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
        return '<span class="badge badge-critical">Breached</span>';
    }
    if ($days >= 4) {
        return '<span class="badge badge-high">At Risk</span>';
    }
    return '<span class="badge badge-low">On Track</span>';
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
$priority   = $_GET['priority']   ?? '';
$status     = $_GET['status']     ?? '';
$search     = $_GET['search']     ?? '';
$view       = (string) ($_GET['view'] ?? '');
$view = $view === 'trash' ? '' : $view;
$department_key = $department !== '' ? ticket_department_key_from_value((string) $department) : '';
$adminId = (int) ($_SESSION['user_id'] ?? 0);
$allowedViews = ['all', 'my_open', 'resolved'];
if (!in_array($view, $allowedViews, true)) $view = '';

$sidebarCounts = [
    'all' => 0,
    'my_open' => 0,
    'resolved' => 0,
];
$cntStmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN COALESCE(NULLIF(status,''),'') NOT IN ('Closed','Trash') THEN 1 ELSE 0 END) AS all_total,
        SUM(CASE WHEN COALESCE(NULLIF(status,''),'') NOT IN ('Closed','Trash') AND status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_total,
        SUM(CASE WHEN COALESCE(NULLIF(status,''),'') NOT IN ('Closed','Trash') AND status IN ('Open','In Progress') THEN 1 ELSE 0 END) AS my_open_total
    FROM employee_tickets
");
if ($cntStmt) {
    $cntStmt->execute();
    $cntRes = $cntStmt->get_result();
    $cntRow = $cntRes ? $cntRes->fetch_assoc() : null;
    $cntStmt->close();
    $sidebarCounts['all'] = (int) ($cntRow['all_total'] ?? 0);
    $sidebarCounts['resolved'] = (int) ($cntRow['resolved_total'] ?? 0);
    $sidebarCounts['my_open'] = (int) ($cntRow['my_open_total'] ?? 0);
}

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
$query .= " AND COALESCE(NULLIF(employee_tickets.status,''),'') NOT IN ('Closed','Trash')";

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

if (!empty($priority)) {
    $priority = $conn->real_escape_string($priority);
    $query .= " AND employee_tickets.priority = '$priority'";
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
    if ($domain !== '') {
        if ($domain[0] !== '@') $domain = '@' . $domain;
        $domainEsc = $conn->real_escape_string($domain);
        $query .= " AND LOWER(COALESCE(NULLIF(employee_tickets.assigned_company,''), NULLIF(employee_tickets.company,''))) = '$domainEsc'";
    }
}

if (!empty($search)) {
    $searchSQL = $conn->real_escape_string($search);
    
    // Parse ID from search (remove non-digits)
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int)$searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    $query .= " AND (
        users.name LIKE '%$searchSQL%' OR
        LOWER(COALESCE(NULLIF(employee_tickets.requester_email,''), users.email)) LIKE LOWER('%$searchSQL%') OR
        employee_tickets.subject LIKE '%$searchSQL%' OR
        employee_tickets.description LIKE '%$searchSQL%' OR
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
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tickets</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        .at-layout { width: 100%; max-width: 1560px; min-width: 0; display: flex; gap: 18px; align-items: flex-start; }
        .at-sidebar { width: 260px; flex: 0 0 260px; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 14px; box-shadow: 0 4px 10px rgba(2,6,23,0.04); position: static; top: auto; min-height: 580px; height: auto; display: flex; flex-direction: column; align-self: flex-start; }
        .at-sidebar-section + .at-sidebar-section { margin-top: 16px; }
        .at-sidebar-title { font-size: 12px; font-weight: 800; color: #475569; display: flex; align-items: center; justify-content: space-between; padding: 10px 10px 8px; text-transform: none; }
        .at-sidebar-add { width: 28px; height: 28px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: #f1f5f9; color: #1b5e20; border: 1px solid #e2e8f0; cursor: pointer; }
        .at-sidebar-add:active { transform: translateY(1px); }
        .at-sidebar-link { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 10px; border-radius: 12px; text-decoration: none; color: #0f172a; font-size: 13px; font-weight: 600; border: 1px solid transparent; }
        .at-sidebar-link:hover { background: #f8fafc; border-color: #e2e8f0; }
        .at-sidebar-link.active { background: rgba(27, 94, 32, 0.10); border-color: rgba(27, 94, 32, 0.25); }
        .at-sidebar-left { display: inline-flex; align-items: center; gap: 10px; min-width: 0; }
        .at-sidebar-icon { width: 28px; height: 28px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: #f1f5f9; color: #1b5e20; flex: 0 0 28px; }
        .at-sidebar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .at-sidebar-count { min-width: 28px; height: 22px; padding: 0 8px; border-radius: 999px; background: #f1f5f9; color: #334155; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; }
        .at-sidebar-link.active .at-sidebar-count { background: #1b5e20; color: #ffffff; }
        .at-sidebar-link.disabled { opacity: 0.45; pointer-events: none; }
        .at-main { flex: 1 1 auto; min-width: 0; max-width: 100%; }
        .at-main .admin-content { max-width: none; min-width: 0; }
        .at-main .admin-card { max-width: 100%; min-width: 0; }
        #filterForm .filter-row {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            width: 100%;
            min-width: 0;
        }
        #filterForm .filter-input {
            flex: 1 1 260px;
            min-width: 220px;
            max-width: 100%;
        }
        #filterForm .filter-select {
            min-width: 0;
            padding: 10px 26px 10px 10px;
            flex: 1 1 150px;
            max-width: 100%;
        }
        #filterForm #recipientFilterSelect { flex-basis: 220px; }
        #filterForm #departmentFilterSelect { width: 100%; }
        #filterForm select[name="priority"] { flex: 0 1 132px; }
        #filterForm select[name="status"] { flex: 0 1 132px; }
        #filterForm .clear-btn {
            flex: 0 0 auto;
            margin-left: 0;
        }
        #filterForm .lapc-department-filter {
            flex: 1 1 260px;
            min-width: 220px;
            max-width: 360px;
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
        @media (max-width: 1100px) {
            .at-sidebar { display: none; }
            .at-layout { max-width: 1200px; }
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
                flex: 1 1 100%;
                width: 100%;
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
        <div class="at-layout">
            <aside class="at-sidebar" aria-label="Views">
                <div class="at-sidebar-section">
                    <div class="at-sidebar-title">Views</div>
                    <a class="at-sidebar-link <?php echo $view === '' ? 'active' : ''; ?>" href="all_tickets.php">
                        <span class="at-sidebar-left">
                            <span class="at-sidebar-icon"><i class="fa-regular fa-rectangle-list"></i></span>
                            <span class="at-sidebar-label">All Tickets</span>
                        </span>
                        <span class="at-sidebar-count"><?php echo (int) ($sidebarCounts['all'] ?? 0); ?></span>
                    </a>
                    <a class="at-sidebar-link <?php echo $view === 'my_open' ? 'active' : ''; ?>" href="all_tickets.php?view=my_open">
                        <span class="at-sidebar-left">
                            <span class="at-sidebar-icon"><i class="fa-regular fa-circle-check"></i></span>
                            <span class="at-sidebar-label">Open &amp; Pending </span>
                        </span>
                        <span class="at-sidebar-count"><?php echo (int) ($sidebarCounts['my_open'] ?? 0); ?></span>
                    </a>
                </div>

                <div class="at-sidebar-section">
                    <a class="at-sidebar-link <?php echo $view === 'resolved' ? 'active' : ''; ?>" href="all_tickets.php?view=resolved">
                        <span class="at-sidebar-left">
                            <span class="at-sidebar-icon"><i class="fa-solid fa-check"></i></span>
                            <span class="at-sidebar-label">All Resolved Tickets</span>
                        </span>
                        <span class="at-sidebar-count"><?php echo (int) ($sidebarCounts['resolved'] ?? 0); ?></span>
                    </a>
                </div>
            </aside>

            <div class="at-main">
                <div class="admin-content">

            <?php if(isset($_SESSION['success'])): ?>
                <div class="admin-notice">
                    <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">All Tickets</h1>
                    <p class="admin-page-subtitle">Manage and track all support tickets.</p>
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

                        <select id="recipientFilterSelect" class="filter-select">
                            <option value="" <?= $company_email === '' ? 'selected' : '' ?>>All Company</option>
                            <option value="@leads-farmex.com">FARMEX</option>
                            <option value="@farmasee.ph">FARMASEE</option>
                            <option value="@gpsci.net">GPSCI</option>
                            <option value="@leadsanimalhealth.com">LAH</option>
                            <option value="@leadsagri.com">LAPC</option>
                            <option value="@leads-eh.com">LEH</option>
                            <option value="@leadsav.com">LAV</option>
                            <option value="@malvedaholdings.com">MHC</option>
                            <option value="@malvedaproperties.com">MPDC</option>
                            <option value="@leadstech-corp.com">LTC</option>
                            <option value="@lingapleads.org">LINGAP</option>
                            <option value="@primestocks.ph">PCC</option>
                        </select>

                        <div id="departmentFilterWrap" class="lapc-department-filter is-hidden is-disabled">
                            <select id="departmentFilterSelect" class="filter-select" disabled>
                                <option value="" disabled selected hidden>All Department</option>
                                <option value="Admin &amp; Legal">Admin &amp; Legal</option>
                                <option value="Banana Farm Operations">Banana Farm Operations</option>
                                <option value="Diagnostics / Lingap">Diagnostics / Lingap</option>
                                <option value="Digital Agri Solutions and Innovations">Digital Agri Solutions and Innovations</option>
                                <option value="E-Commerce">E-Commerce</option>
                                <option value="Executive">Executive</option>
                                <option value="Finance and Accounting">Finance and Accounting</option>
                                <option value="HR">HR</option>
                                <option value="IT">IT</option>
                                <option value="Institutional Sales (Bidding)">Institutional Sales (Bidding)</option>
                                <option value="Management">Management</option>
                                <option value="Marketing">Marketing</option>
                                <option value="New Business Segment">New Business Segment</option>
                                <option value="Seed Production">Seed Production</option>
                                <option value="Supply Chain">Supply Chain</option>
                                <option value="Supply Chain Innovation">Supply Chain Innovation</option>
                                <option value="Technical">Technical</option>
                            </select>
                        </div>

                        <select name="priority" class="filter-select" onchange="submitForm()">
                            <option value="" disabled selected hidden>All Priority</option>
                            <option value="Low" <?= $priority=='Low'?'selected':'' ?>>Low</option>
                            <option value="High" <?= $priority=='High'?'selected':'' ?>>High</option>
                            <option value="Critical" <?= $priority=='Critical'?'selected':'' ?>>Critical</option>
                        </select>

                        <select name="status" class="filter-select" onchange="submitForm()">
                            <option value="" disabled selected hidden>All Status</option>
                            <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                            <option value="In Progress" <?= $status=='In Progress'?'selected':'' ?>>In Progress</option>
                            <option value="Resolved" <?= $status=='Resolved'?'selected':'' ?>>Resolved</option>
                        </select>

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
                                <th>Priority</th>
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
                                <td data-label="Priority">
                                    <span class="badge badge-<?= strtolower($row['priority']); ?>">
                                        <?= htmlspecialchars($row['priority'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td data-label="Status">
                                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                        <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php if($row['is_read'] == 0): ?>
                                        <span class="new-badge">NEW</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Original Dept"><?php 
                                    $origDept = !empty($row['department']) ? $row['department'] : ($row['user_department'] ?? '');
                                    echo htmlspecialchars($origDept !== '' ? ticket_department_display_name((string) $origDept) : 'Sales');
                                ?></td>
                                <td data-label="Date"><?= htmlspecialchars(time_ago_days((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
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
                        <a href="?page=<?= $page - 1; ?>&limit=<?= $limit; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>&view=<?= urlencode($view); ?>" 
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
                                    <a href="?page=<?= $pagination_item; ?>&limit=<?= $limit; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>&view=<?= urlencode($view); ?>" 
                                       data-page="<?= $pagination_item ?>"
                                       class="page-btn <?= ($pagination_item == $page) ? 'active' : ''; ?>">
                                        <?= $pagination_item; ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <!-- Next Link -->
                        <a href="?page=<?= $page + 1; ?>&limit=<?= $limit; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>&view=<?= urlencode($view); ?>" 
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
const lapcDomainValue = '@leadsagri.com';

searchInput.addEventListener("keyup", function () {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(doneTyping, doneTypingInterval);
});

searchInput.addEventListener("keydown", function () {
    clearTimeout(typingTimer);
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

    if (currentCompany === lapcDomainValue) {
        departmentFilterWrap.classList.remove('is-hidden');
        departmentFilterWrap.classList.remove('is-disabled');
        departmentFilterSelect.disabled = false;
        if (departmentFilterSelect.options.length > 0) {
            departmentFilterSelect.options[0].textContent = 'All Department';
        }
        departmentFilterSelect.value = currentDepartment;
    } else {
        departmentFilterWrap.classList.add('is-hidden');
        departmentFilterWrap.classList.add('is-disabled');
        departmentFilterSelect.disabled = true;
        departmentFilterSelect.value = '';
    }
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
        submitForm(1);
        return;
    }

    if (selectedValue === lapcDomainValue) {
        departmentFilterValue.value = '';
        departmentFilterSelect.value = '';
        departmentFilterSelect.disabled = false;
        departmentFilterWrap.classList.remove('is-hidden');
        departmentFilterWrap.classList.remove('is-disabled');
        submitForm(1);
        return;
    }

    departmentFilterValue.value = '';
    departmentFilterSelect.value = '';
    departmentFilterSelect.disabled = true;
    departmentFilterWrap.classList.add('is-hidden');
    departmentFilterWrap.classList.add('is-disabled');
    submitForm(1);
}

function handleDepartmentFilterChange() {
    if (!departmentFilterSelect || !companyEmailFilterValue || !departmentFilterValue) return;
    departmentFilterValue.value = String(departmentFilterSelect.value || '');
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
        if (companyEmailFilterValue) companyEmailFilterValue.value = '';
        if (departmentFilterValue) departmentFilterValue.value = '';
        syncRecipientFilters();
        if (searchInput) searchInput.value = '';
        submitForm(1);
    });
}

if (filterForm) {
    filterForm.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(1);
    });
}

if (limitSelect) {
    limitSelect.addEventListener('change', function () {
        submitForm(1);
    });
}

if (recipientFilterSelect) {
    recipientFilterSelect.addEventListener('change', handleRecipientFilterChange);
}

if (departmentFilterSelect) {
    departmentFilterSelect.addEventListener('change', handleDepartmentFilterChange);
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
