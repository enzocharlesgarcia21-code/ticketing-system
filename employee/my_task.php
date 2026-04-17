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
ticket_apply_sla_priority($conn);

function company_code(string $value): string
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

function company_aliases(string $value): array
{
    $v = trim($value);
    $code = company_code($v);
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
$flashErrorTitle = 'Update Failed';
if ($flashError !== '' && stripos($flashError, 'No assignee available') !== false) {
    $flashErrorTitle = 'No Assignee Available';
}

/* ================= GET VALUES ================= */

$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$company_email = $_GET['company_email'] ?? '';
$status = $_GET['status'] ?? '';
 $userCompanyNorm = ticket_normalize_company((string) $user_company);
 $userEmailNorm = strtolower(trim((string) $user_email));
 $show_department_filter = ($userCompanyNorm === '@leadsagri.com')
    || (company_code((string) $user_company) === 'LAPC')
    || ($userEmailNorm !== '' && str_ends_with($userEmailNorm, '@leadsagri.com'));

$allowed_departments = ticket_lapc_departments();
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
$allowed_statuses = ['Open','In Progress','Resolved','Closed'];

if (!$show_department_filter || $company_email !== '@leadsagri.com' || !in_array($department, $allowed_departments, true)) {
    $department = '';
}
if (!array_key_exists((string) $company_email, $company_filter_options)) {
    $company_email = '';
}
if (!in_array($status, $allowed_statuses, true)) {
    $status = '';
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
$groupCond = "$taskDeptExpr = ?";
$requiresGroupCond = "(($companyCol LIKE '@%' AND LOWER($companyCol) = '@leadsagri.com') OR ($companyCol NOT LIKE '@%' AND UPPER($companyCol) = 'LAPC'))";

$where[] = "((t.assigned_user_id = ? AND t.user_id <> ?) OR (t.user_id <> ? AND $companyCond AND ((NOT $requiresGroupCond) OR (? = '' OR $groupCond))))";
$params[] = (int) $user_id;
$types .= "i";
$params[] = (int) $user_id;
$types .= "i";
$params[] = (int) $user_id;
$types .= "i";
$params[] = strtolower((string) $user_email);
$types .= "s";
foreach ($companyAliases as $co) {
    $params[] = $co;
    $types .= "s";
}
$params[] = $user_department;
$types .= "s";
$params[] = $user_department;
$types .= "s";

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
    $where[] = "LOWER($sourceCompanyExpr) = ?";
    $params[] = strtolower((string) $company_email);
    $types .= "s";
}

if ($status !== '') {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
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

$sql .= " ORDER BY t.created_at DESC LIMIT ?, ?";

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
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        @media (max-width: 768px) {
            body.employee-my-task-page .filter-card {
                padding: 16px;
                border-radius: 14px;
            }

            body.employee-my-task-page .filter-form {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            body.employee-my-task-page .search-wrapper {
                width: 100%;
                min-width: 0;
            }

            body.employee-my-task-page .search-input {
                width: 100%;
                height: 46px;
                padding: 0 14px 0 40px;
                border-radius: 12px;
                font-size: 14px;
            }

            body.employee-my-task-page .filters-wrapper {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                width: 100%;
            }

            body.employee-my-task-page .select-wrapper.small {
                width: 100%;
                min-width: 0;
            }

            body.employee-my-task-page .filter-form .filter-select {
                width: 100%;
                height: 46px;
                padding: 0 36px 0 12px;
                border-radius: 12px;
                font-size: 14px;
            }

            body.employee-my-task-page .filter-form .clear-btn {
                width: 100%;
                min-height: 46px;
                padding: 10px 14px;
                border-radius: 12px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            body.employee-my-task-page .tm-global-chat-fab {
                right: 12px;
                bottom: 12px;
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

            body.employee-my-task-page .table-responsive table thead {
                display: none;
            }

            body.employee-my-task-page .table-responsive table tbody {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row {
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
            }

            body.employee-my-task-page .table-responsive table tbody tr.ticket-row td::before {
                display: none;
            }

            body.employee-my-task-page .task-ticket-id {
                grid-area: id;
                font-size: 10px;
                font-weight: 700;
                color: #0f172a;
            }

            body.employee-my-task-page .task-ticket-category {
                grid-area: category;
                font-size: 10px;
                color: #6b7280;
                font-weight: 600;
            }

            body.employee-my-task-page .task-ticket-requester {
                grid-area: title;
                font-size: 10px;
                color: #1f2937;
                line-height: 1.15;
            }

            body.employee-my-task-page .task-ticket-date {
                grid-area: date;
                font-size: 9px;
                color: #9ca3af;
                margin-top: 1px;
            }

            body.employee-my-task-page .task-ticket-arrow {
                display: block;
                grid-area: arrow;
                justify-self: end;
                align-self: end;
                font-size: 18px;
                font-weight: 700;
                color: #64748b;
                line-height: 1;
            }

            body.employee-my-task-page .task-ticket-status,
            body.employee-my-task-page .task-ticket-department {
                display: none;
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
        .task-success-icon {
            width: 74px;
            height: 74px;
            margin: 8px auto 18px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f6fff4;
            color: #15803d;
            border: 3px solid #d7ebc6;
            box-sizing: border-box;
            font-size: 34px;
            line-height: 1;
            font-weight: 700;
        }
        .task-success-title {
            margin: 0 0 10px;
            padding: 0 24px;
            font-size: 22px;
            line-height: 1.25;
            font-weight: 800;
            color: #20243a;
        }
        .task-success-message {
            margin: 0;
            padding: 0 24px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
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
            background: #1f6b24;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: none;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .task-success-btn:hover {
            background: #18591d;
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
    </style>
</head>
<body class="employee-my-task-page">

    <!-- TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title"> My Tasks </h1>
                <p class="page-subtitle">Tickets assigned to <strong><?= htmlspecialchars($user_department, ENT_QUOTES, 'UTF-8') ?></strong> department</p>
            </div>

            <!-- FILTERS CARD -->
            <div class="filter-card">
                <form method="GET" id="filterForm" class="filter-form">
                    
                    <div class="search-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="search-input"
                               placeholder="Search by ID, name, email or subject..."
                               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="filters-wrapper">
                        <div class="select-wrapper small">
                            <select name="company_email" class="filter-select" id="filterCompany">
                                <option value="" <?= $company_email === '' ? 'selected' : '' ?>>All Company</option>
                                <?php foreach ($company_filter_options as $companyValue => $companyLabel): ?>
                                    <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= $company_email === $companyValue ? 'selected' : '' ?>><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($show_department_filter): ?>
                        <div class="select-wrapper small">
                            <select name="department" class="filter-select" id="filterDepartment" <?= $company_email === '@leadsagri.com' ? '' : 'disabled' ?>>
                                <option value="" <?= $department === '' ? 'selected' : '' ?>>All Department</option>
                                <?php foreach ($allowed_departments as $d): ?>
                                    <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $department === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="select-wrapper small">
                            <select name="status" class="filter-select" id="filterStatus">
                                <option value=""disabled selected hidden <?= $status === '' ? 'selected' : '' ?>>All Status</option>
                                <?php foreach ($allowed_statuses as $s): ?>
                                    <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?= $status === $s ? 'selected' : '' ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <a href="my_task.php" class="clear-btn">Clear Filters</a>
                    </div>
                </form>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Requested By</th>
                                <th>From</th>
                                <th>Status</th>
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

                                    <td class="task-ticket-date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                    <td class="task-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                </tr>
                                <?php } ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; color: #94a3b8; padding: 40px;">
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
                    <a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search); ?>&company_email=<?= urlencode($company_email); ?>&department=<?= urlencode($department); ?>&status=<?= urlencode($status); ?>" 
                       data-page="<?= max(1, $page - 1) ?>"
                       class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        &lsaquo; Previous
                    </a>

                    <div class="page-numbers">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i; ?>&search=<?= urlencode($search); ?>&company_email=<?= urlencode($company_email); ?>&department=<?= urlencode($department); ?>&status=<?= urlencode($status); ?>" 
                               data-page="<?= $i ?>"
                               class="page-btn <?= ($i == $page) ? 'active' : ''; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search); ?>&company_email=<?= urlencode($company_email); ?>&department=<?= urlencode($department); ?>&status=<?= urlencode($status); ?>" 
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
        <div class="task-success-dialog">
            <div class="task-success-icon" aria-hidden="true">&#10003;</div>
            <h2 id="taskSuccessTitle" class="task-success-title">The ticket has been updated</h2>
            <p class="task-success-message"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="task-success-actions">
                <button type="button" class="task-success-btn" id="taskSuccessCloseBtn">Close</button>
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
    </script>
    <script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
    <script>
        var typingTimer = null;
        var doneTypingInterval = 300;
        var filterForm = document.getElementById("filterForm");
        var searchInput = document.getElementById("searchInput");
        var tbodyEl = document.getElementById("tasksTbody");
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

        function syncTaskDepartmentFilter() {
            var companyEl = document.getElementById('filterCompany');
            var departmentEl = document.getElementById('filterDepartment');
            if (!companyEl || !departmentEl) return;
            var selectedCompany = String(companyEl.value || '').toLowerCase();
            var isLapc = selectedCompany === '@leadsagri.com';
            if (!isLapc) {
                departmentEl.value = '';
            }
            departmentEl.disabled = !isLapc;
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

        syncTaskDepartmentFilter();

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
