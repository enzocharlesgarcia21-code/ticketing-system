<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

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
    $search_stmt = $conn->prepare($queryBase . " AND (name LIKE ? OR email LIKE ?) ORDER BY name ASC");
    $search_stmt->bind_param("ss", $term, $term);
    $search_stmt->execute();
    $result = $search_stmt->get_result();
    $search_stmt->close();
} else {
    $result = $conn->query($queryBase . " ORDER BY name ASC");
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

$email_domains = [
    'gpsci.net',
    'farmasee.ph',
    'gmail.com',
    'leads-eh.com',
    'leads-farmex.com',
    'leadsagri.com',
    'leadsanimalhealth.com',
    'leadsav.com',
    'leadstech-corp.com',
    'lingapleads.org',
    'primestocks.ph'
];

$department_options = [
    'ACCOUNTING',
    'ADMIN',
    'E-COMM',
    'HR',
    'IT',
    'LINGAP',
    'MARKETING',
    'SUPPLY CHAIN',
    'TECHNICAL'
];

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
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }

        .admin-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
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
            width: 64px;
            height: 64px;
            background-color: #e6f4ea;
            color: #1B5E20;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .admin-name {
            font-size: 16px;
            font-weight: 600;
            color: #1F2937;
            margin-bottom: 4px;
        }

        .admin-email {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 16px;
        }

        .admin-badge {
            background-color: #dcfce7;
            color: #166534;
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #bbf7d0;
            margin-bottom: 15px;
        }

        .remove-admin-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
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
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
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
        .mgmt-card-body { padding: 16px; }
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
        .username-row, .password-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .fullname-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .fullname-row > .form-control { flex: 1 1 auto; min-width: 0; }
        .fullname-row > .domain-select { flex: 0 0 280px; min-width: 0; }
        .password-field {
            position: relative;
            flex: 1 1 auto;
            min-width: 0;
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
        .username-row > .form-control { flex: 1 1 auto; min-width: 0; }
        .username-row > .domain-select { flex: 0 0 280px; min-width: 0; }
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
        }
        .users-company-inline {
            display: flex;
            gap: 10px;
            align-items: center;
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
            margin-top: 12px;
        }
        .pagination-info {
            color: #64748b;
            font-weight: 700;
            font-size: 12px;
        }
        .pagination-controls {
            display: flex;
            gap: 8px;
            margin-left: auto;
            align-items: center;
            justify-content: flex-end;
        }
        .page-btn {
            min-width: 34px;
            height: 34px;
            padding: 0 10px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            background: #ffffff;
            color: #0f172a;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            user-select: none;
        }
        .page-btn:hover { background: #f8fafc; }
        .page-btn.active { background: #1B5E20; color: #ffffff; border-color: #1B5E20; }
        .page-btn.disabled { opacity: 0.45; pointer-events: none; }
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
            align-items: start;
        }
        .admin-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
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
                            <select class="domain-select" id="usersDept">
                                <option value="all" selected>All Departments</option>
                                <?php foreach ($department_options as $d): ?>
                                    <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="users-company-inline">
                                <select class="domain-select" id="usersCompany">
                                    <option value="all" selected>All Companies</option>
                                    <?php foreach ($email_domains as $ed): ?>
                                        <?php $opt = '@' . $ed; ?>
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-auto" id="clearUsersFilters">Clear</button>
                            </div>
                        </div>
                    </div>

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
                    <div class="users-pagination" id="usersPagination" style="display:none;">
                        <div class="pagination-info" id="usersPaginationInfo"></div>
                        <div class="pagination-controls" id="usersPaginationControls"></div>
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
                    <form id="addUserForm" autocomplete="off">
                        <?php echo csrf_field(); ?>
                        <div class="form-grid">
                            <div class="form-label">Full Name *</div>
                            <div class="fullname-row">
                                <input type="text" class="form-control" name="full_name" id="fullName" placeholder="Juan Dela Cruz" required>
                                <select class="domain-select" name="department" id="newDept" aria-label="Department" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($department_options as $d): ?>
                                        <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-label">Email *</div>
                            <div class="username-row">
                                <input type="text" class="form-control" name="username" id="username" placeholder="juan.delacruz" required>
                                <select class="domain-select" name="domain" id="domain" required>
                                    <?php foreach ($email_domains as $ed): ?>
                                        <?php $opt = '@' . $ed; ?>
                                        <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?= $ed === 'leadsagri.com' ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
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
                    <div class="admin-card-grid">
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
                                        <button type="button" class="remove-admin-btn" style="width: 100%; justify-content: center; margin-top: 10px;" onclick="confirmRemoval(<?= $admin['id'] ?>)">
                                            <i class="fa-solid fa-trash"></i> Remove Admin
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="remove-admin-btn" style="width: 100%; justify-content: center; margin-top: 10px; opacity: 0.5; cursor: not-allowed;" disabled>
                                            <i class="fa-solid fa-lock"></i> Current Admin
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="color: #6B7280; font-weight: 700;">No IT Admins found.</div>
                        <?php endif; ?>
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
    var tmUsersState = { page: 1, limit: window.TM_USERS_PAGE_SIZE, total: 0, totalPages: 1 };

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
            var badge = isCurrent ? '<span class="users-badge-current">Current</span>' : '';
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
        btns.push('<a href=\"#\" class=\"page-btn' + (prevDisabled ? ' disabled' : '') + '\" data-page=\"' + (page - 1) + '\">\u2039</a>');

        var startPage = Math.max(1, page - 2);
        var endPage = Math.min(totalPages, startPage + 4);
        startPage = Math.max(1, endPage - 4);
        for (var p = startPage; p <= endPage; p++) {
            btns.push('<a href=\"#\" class=\"page-btn' + (p === page ? ' active' : '') + '\" data-page=\"' + p + '\">' + p + '</a>');
        }
        btns.push('<a href=\"#\" class=\"page-btn' + (nextDisabled ? ' disabled' : '') + '\" data-page=\"' + (page + 1) + '\">\u203a</a>');

        controls.innerHTML = btns.join('');
        wrap.style.display = 'flex';
    }

    function loadUsersList(page) {
        var qEl = document.getElementById('usersSearch');
        var deptEl = document.getElementById('usersDept');
        var companyEl = document.getElementById('usersCompany');
        var q = qEl ? qEl.value.trim() : '';
        var dept = deptEl ? deptEl.value : 'all';
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

    function loadItEmployees() {
        var input = document.getElementById('itSearchInput');
        var q = input ? input.value.trim() : '';
        fetch('ajax_it_employees.php?q=' + encodeURIComponent(q) + '&limit=60', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    renderItEmployees([]);
                    return;
                }
                renderItEmployees(data.employees || []);
            })
            .catch(function () { renderItEmployees([]); });
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
                closeModal();
            });
        }

        var addUserForm = document.getElementById('addUserForm');
        if (addUserForm) {
            addUserForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fullName = document.getElementById('fullName');
                var username = document.getElementById('username');
                var domain = document.getElementById('domain');
                var password = document.getElementById('newPassword');
                if (!fullName || !username || !domain || !password) return;

                var fd = new FormData(addUserForm);
                fd.set('full_name', fullName.value || '');
                fd.set('username', username.value || '');
                fd.set('domain', domain.value || '@leadsagri.com');
                fd.set('password', password.value || '');
                var deptEl = document.getElementById('newDept');
                if (deptEl) fd.set('department', deptEl.value || '');

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
                            Swal.fire({ icon: 'error', title: 'Error', text: msg, confirmButtonColor: '#1B5E20' });
                            return;
                        }
                        var emailAddress = (String(username.value || '').trim()) + (String(domain.value || ''));
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
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to create user.', confirmButtonColor: '#1B5E20' });
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
                    text: 'This will permanently delete ' + name + '.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6B7280',
                    confirmButtonText: 'Delete',
                    cancelButtonText: 'Cancel',
                    width: '420px'
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
                                Swal.fire({ icon: 'error', title: 'Error', text: msg, confirmButtonColor: '#1B5E20' });
                                return;
                            }
                            Swal.fire({ icon: 'success', title: 'Deleted', text: data.message || 'User deleted', confirmButtonColor: '#1B5E20' });
                            loadUsersList();
                        })
                        .catch(function () {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to delete user.', confirmButtonColor: '#1B5E20' });
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
        ['usersDept', 'usersCompany'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', function () { loadUsersList(1); });
        });
        var clearUsersBtn = document.getElementById('clearUsersFilters');
        if (clearUsersBtn) {
            clearUsersBtn.addEventListener('click', function () {
                if (usersSearch) usersSearch.value = '';
                var deptEl = document.getElementById('usersDept');
                var companyEl = document.getElementById('usersCompany');
                if (deptEl) deptEl.value = 'all';
                if (companyEl) companyEl.value = 'all';
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

        loadUsersList(1);

        var itForm = document.getElementById('itSearchForm');
        var itInput = document.getElementById('itSearchInput');
        var itDebounce = null;
        if (itForm) {
            itForm.addEventListener('submit', function (e) {
                e.preventDefault();
                loadItEmployees();
            });
        }
        if (itInput) {
            itInput.addEventListener('input', function () {
                if (itDebounce) clearTimeout(itDebounce);
                itDebounce = setTimeout(loadItEmployees, 250);
            });
        }
        var clearItBtn = document.getElementById('clearItSearch');
        if (clearItBtn) {
            clearItBtn.addEventListener('click', function () {
                if (itInput) itInput.value = '';
                loadItEmployees();
            });
        }
    });

    function confirmAddition(userId) {
        Swal.fire({
            title: 'Add this user as admin?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Add',
            cancelButtonText: 'Cancel',
            width: '400px',
            background: '#fff',
            customClass: {
                popup: 'swal-rounded',
                title: 'swal-title',
                confirmButton: 'swal-confirm',
                cancelButton: 'swal-cancel'
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
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Remove',
            cancelButtonText: 'Cancel',
            width: '400px',
            background: '#fff',
            customClass: {
                popup: 'swal-rounded',
                title: 'swal-title',
                confirmButton: 'swal-confirm',
                cancelButton: 'swal-cancel'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'remove_admin.php?id=' + adminId;
            }
        });
    }

    const Toast = Swal.mixin({
        toast: true,
        position: 'top',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: false,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    <?php if (isset($_SESSION['admin_added'])): ?>
        Toast.fire({
            icon: 'success',
            title: 'Admin added',
            background: '#dcfce7',
            color: '#166534',
            iconColor: '#166534'
        });
        <?php unset($_SESSION['admin_added']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['admin_removed'])): ?>
        Toast.fire({
            icon: 'success',
            title: 'Admin removed',
            background: '#dcfce7',
            color: '#166534',
            iconColor: '#166534'
        });
        <?php unset($_SESSION['admin_removed']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        Toast.fire({
            icon: 'error',
            title: '<?= addslashes($_SESSION['error_message']) ?>',
            background: '#fee2e2',
            color: '#991b1b',
            iconColor: '#991b1b'
        });
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
</script>

<style>
    .swal-rounded {
        border-radius: 12px !important;
        font-family: 'Inter', sans-serif !important;
    }
    .swal-title {
        font-size: 18px !important;
        font-weight: 600 !important;
        color: #1F2937 !important;
    }
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
