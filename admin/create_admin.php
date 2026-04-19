<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit();
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
    $search_stmt = $conn->prepare($queryBase . " AND (name LIKE ? OR email LIKE ?) ORDER BY name ASC LIMIT 3");
    $search_stmt->bind_param("ss", $term, $term);
    $search_stmt->execute();
    $result = $search_stmt->get_result();
    $search_stmt->close();
} else {
    $result = $conn->query($queryBase . " ORDER BY name ASC LIMIT 3");
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
    '@leads-farmex.com' => 'FARMEX',
    '@farmasee.ph' => 'FARMASEE',
    '@gpsci.net' => 'GPCI',
    '@leadsanimalhealth.com' => 'LAH',
    '@leadsagri.com' => 'LAPC',
    '@leads-eh.com' => 'LEH',
    '@leadstech-corp.com' => 'LTC',
    '@leadsav.com' => 'LAV',
    '@lingapleads.org' => 'LINGAP',
    '@malvedaholdings.com' => 'MHC',
    '@malvedaproperties.com' => 'MPDC',
    '@primestocks.ph' => 'PCC'
];

$lapc_department_options = ticket_lapc_departments();

?>

<!DOCTYPE html>
<html>
<head>
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
            gap: 12px 14px;
            align-items: center;
        }
        .form-label {
            font-weight: 700;
            color: #334155;
            font-size: 13px;
        }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            outline: none;
            font-size: 14px;
            background: #ffffff;
        }
        .form-control:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.15);
        }
        .fullname-row,
        .username-row,
        .password-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            gap: 10px;
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
            width: auto;
            min-width: 148px;
            justify-self: start;
        }
        .password-field {
            position: relative;
        }
        .password-field .form-control {
            padding-right: 38px;
        }
        .password-eye {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }
        .password-eye i { font-size: 13px; }
        .password-eye:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        .form-options {
            margin-top: 2px;
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .checkbox-option {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #374151;
        }
        .checkbox-option-text {
            cursor: pointer;
            user-select: none;
        }
        .checkbox-option input {
            width: 16px;
            height: 16px;
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
        }
        .domain-select {
            min-width: 170px;
            width: 100%;
            padding: 10px 44px 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
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
            border-radius: 10px;
            padding: 10px 14px;
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
            background: #1B5E20;
            color: #ffffff;
        }
        .btn-primary:hover { background: #144a1e; }
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border-color: #e5e7eb;
        }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-auto {
            background: #f8fafc;
            color: #334155;
            border-color: #e2e8f0;
            white-space: nowrap;
        }
        .btn-auto:hover { background: #f1f5f9; }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
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
        .users-table th:nth-child(2), .users-table td:nth-child(2) { width: 34%; text-align: center; }
        .users-table th:nth-child(3), .users-table td:nth-child(3) { width: 28%; text-align: center; }
        .users-table th:nth-child(4), .users-table td:nth-child(4) { width: 10%; text-align: right; }
        .users-table td { vertical-align: middle; }
        .users-cell {
            display: inline-block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }
        .users-name-wrap {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            max-width: 100%;
        }
        .users-name-wrap .users-cell { min-width: 0; }
        .users-badge-current {
            flex: 0 0 auto;
            font-size: 11px;
            font-weight: 900;
            padding: 4px 10px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
            line-height: 1;
            white-space: nowrap;
        }
        .users-actions {
            display: inline-flex;
            justify-content: flex-end;
            width: 100%;
        }
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
            background: #1B5E20;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        .add-user-trigger:hover { background: #144a1e; }

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
            padding: 22px;
            z-index: 3000;
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
            z-index: 3001;
        }
        .modal-card .mgmt-card-body { padding: 18px; }
        @media (max-width: 980px) {
            .admin-mgmt-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
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
            .fullname-row { flex-direction: column; align-items: stretch; }
            .fullname-row > .domain-select { flex: 1 1 auto; width: 100%; }
        }

        .admin-dashboard {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }
        .admin-bottom-grid {
            display: grid;
            grid-template-columns: 1.45fr 0.95fr;
            gap: 18px;
            align-items: stretch;
        }
        .admin-bottom-grid > .mgmt-card {
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .admin-bottom-grid > .mgmt-card > .mgmt-card-body {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
        }
        .admin-bottom-grid > .mgmt-card .table-card { flex: 1 1 auto; }
        .admin-bottom-grid > .mgmt-card .admin-card-grid { flex: 1 1 auto; align-content: start; }
        .admin-bottom-grid > .mgmt-card .users-pagination {
            margin-top: auto;
            margin-left: 4px;
            margin-right: 4px;
            margin-bottom: 4px;
        }
        .admin-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .users-table th:nth-child(1), .users-table td:nth-child(1) { width: 34%; text-align: left; }
        .users-table th:nth-child(2), .users-table td:nth-child(2) { width: 36%; text-align: left; }
        .users-table th:nth-child(3), .users-table td:nth-child(3) { width: 22%; text-align: left; }
        .users-table th:nth-child(4), .users-table td:nth-child(4) { width: 8%; text-align: right; }
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
            min-width: 0;
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
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            border: 1px solid #e5e7eb;
            background: #f1f5f9;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .dept-it { background: #dbeafe; border-color: #bfdbfe; color: #1d4ed8; }
        .dept-hr { background: #fef9c3; border-color: #fde68a; color: #854d0e; }
        .dept-admin { background: #dcfce7; border-color: #bbf7d0; color: #166534; }
        .dept-marketing { background: #ede9fe; border-color: #ddd6fe; color: #6d28d9; }
        .dept-accounting { background: #e0f2fe; border-color: #bae6fd; color: #0369a1; }
        .dept-supply-chain { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
        .dept-technical { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
        .dept-e-comm { background: #e0e7ff; border-color: #c7d2fe; color: #3730a3; }
        .dept-lingap { background: #cffafe; border-color: #a5f3fc; color: #0e7490; }

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
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($label . ' (' . $opt . ')', ENT_QUOTES, 'UTF-8'); ?></option>
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
                                        <th style="text-align:right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="usersListBody">
                                    <tr><td class="users-empty" colspan="4">Loading...</td></tr>
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

        <div class="modal-overlay-lite" id="addUserModal" aria-hidden="true">
            <div class="modal-card">
                <div class="mgmt-card-header">
                    <div class="title">
                        <span class="icon"><i class="fas fa-user-plus"></i></span>
                        <span>Add New User</span>
                    </div>
                </div>
                <div class="mgmt-card-body">
                    <form id="addUserForm" autocomplete="off" novalidate>
                        <?php echo csrf_field(); ?>
                        <div class="form-grid">
                            <div class="form-label">Email *</div>
                            <div class="username-row">
                                <input type="text" class="form-control" name="username" id="username" placeholder="juan.delacruz" required>
                                <select class="domain-select" name="domain" id="domain" required>
                                    <?php foreach ($company_domain_options as $opt => $label): ?>
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?= $opt === '@leadsagri.com' ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label . ' (' . $opt . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-label">Full Name *</div>
                            <div class="fullname-row">
                                <input type="text" class="form-control" name="full_name" id="fullName" placeholder="Juan Dela Cruz" required inputmode="text" autocomplete="off">
                                <select class="domain-select" name="department" id="newDept" aria-label="Department" required disabled>
                                    <option value="">Select Company First</option>
                                </select>
                            </div>

                            <div class="form-label">New Password *</div>
                            <div class="password-row">
                                <div class="password-field">
                                    <input type="password" class="form-control" name="password" id="newPassword" required>
                                    <button type="button" class="password-eye" id="togglePassword" aria-label="View password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <button type="button" class="btn btn-auto" id="autoGenerateBtn">Auto Generate</button>
                            </div>

                            <div class="form-options">
                                <div class="checkbox-option">
                                    <input type="checkbox" name="send_credentials" id="sendCredentials" value="1">
                                    <label class="checkbox-option-text" for="sendCredentials">Send credentials via email</label>
                                    <span class="info-icon" title="Automatically email the user's login credentials after account creation.">?</span>
                                </div>

                                <div class="checkbox-option">
                                    <input type="checkbox" name="force_password_change" id="forcePasswordChange" value="1" checked>
                                    <label class="checkbox-option-text" for="forcePasswordChange">Force user to change password on first login</label>
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

                    <div class="table-card">
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
    window.TM_USERS_PAGE_SIZE = 5;
    window.TM_IT_PAGE_SIZE = 3;
    window.TM_IT_ADMINS_PAGE_SIZE = 4;
    var companyDepartments = {
        "@leadsagri.com": <?php echo json_encode(array_values($lapc_department_options), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        "@gpsci.net": ["Accounting", "Sales"],
        "@primestocks.ph": [
            "Admin",
            "Finance and Accounting",
            "Maintenance",
            "Management",
            "Production",
            "Quality Control",
            "Supply Chain",
            "Technical"
        ],
        "@malvedaholdings.com": [
            "Admin & Legal",
            "E-Commerce",
            "Executive",
            "Finance and Accounting",
            "IT",
            "Institutional Sales",
            "Management",
            "Marketing"
        ],
        "@leads-farmex.com": [
            "Business Development",
            "Finance and Admin",
            "Logistics",
            "Management",
            "Sales and Marketing",
            "Special Project",
            "Technical"
        ],
        "@leadstech-corp.com": [
            "Admin",
            "Finance and Accounting",
            "Logistics",
            "Marketing",
            "Sales",
            "Services & Logistics (Luzon)"
        ],
        "@farmasee.ph": [],
        "@malvedaproperties.com": [],
        "@lingapleads.org": [],
        "@leads-eh.com": [],
        "@leadsanimalhealth.com": [],
        "@leadsav.com": []
    };
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
            body.innerHTML = '<tr><td class="users-empty" colspan="4">No users found.</td></tr>';
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
            var id = u.id != null ? String(u.id) : '';
            var name = String(u.name || '');
            var isCurrent = (Number(u.id) === Number(window.TM_ADMIN_CURRENT_USER_ID));
            var isAdmin = (String(u.role || '') === 'admin');
            var isSuper = Number(u.is_super_admin || 0) === 1;
            var badges = [];
            if (isCurrent) badges.push('<span class="users-badge-current">Current</span>');
            if (isSuper) badges.push('<span class="users-badge-current">Super Admin</span>');
            var badge = badges.join('');
            var action = (!isCurrent && !isAdmin)
                ? '<span class="users-actions"><button type="button" class="btn-icon-danger users-del" data-id="' + escapeHtml(id) + '" data-name="' + escapeHtml(name) + '" aria-label="Delete user"><i class="fas fa-trash"></i></button></span>'
                : '<span class="users-actions"></span>';
            var initial = name ? name.trim().charAt(0).toUpperCase() : '?';
            var deptCls = deptClass(dept);
            var deptBadge = '<span class="dept-badge ' + deptCls + '" title="' + escapeHtml(dept) + '">' + escapeHtml(dept) + '</span>';
            return '' +
                '<tr>' +
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
        var limit = Number(tmItState.limit || window.TM_IT_PAGE_SIZE || 3);
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
        tmItState.limit = Number(window.TM_IT_PAGE_SIZE) || 3;
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
        if (openBtn) openBtn.addEventListener('click', openModal);
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
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
        var form = document.getElementById('addUserForm');
        if (cancelBtn && form) {
            cancelBtn.addEventListener('click', function () {
                form.reset();
                updateDepartmentDropdown();
                closeModal();
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
                    target: document.body,
                    icon: 'warning',
                    title: title,
                    html: escapeHtml(text),
                    width: '420px',
                    confirmButtonText: 'OK',
                    buttonsStyling: false,
                    allowOutsideClick: true,
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

        var usersBody = document.getElementById('usersListBody');
        if (usersBody) {
            usersBody.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.users-del') : null;
                if (!btn) return;
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
            });
        }

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
        z-index: 9999 !important;
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
