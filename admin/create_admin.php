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

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Management</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <style>
        .create-admin-container {
            padding: 28px 20px 40px;
            max-width: 1240px;
            margin: 0 auto;
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
        }

        .remove-admin-btn:hover {
            background-color: #c82333;
            transform: translateY(-1px);
        }

        .promote-header {
            display: flex;
            align-items: flex-start;
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
        .search-row {
            margin: 14px 0 14px;
        }
        .search-wrapper {
            position: relative;
            width: 100%;
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
            grid-template-columns: 2fr 1fr;
            gap: 18px;
            margin-bottom: 22px;
        }
        .mgmt-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 10px 22px rgba(2, 6, 23, 0.08);
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
        .domain-select {
            min-width: 170px;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            font-weight: 700;
            color: #0f172a;
            cursor: pointer;
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
        .checks {
            grid-column: 1 / -1;
            display: flex;
            gap: 14px;
            align-items: center;
            margin-top: 6px;
        }
        .checks label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            color: #334155;
            font-size: 13px;
        }
        .checks input { accent-color: #1B5E20; }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 14px;
        }
        .users-list-controls {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 12px;
        }
        .users-filters {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .users-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 1px solid #eef2f7;
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
        @media (max-width: 980px) {
            .admin-mgmt-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
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

        <div class="admin-mgmt-grid">
            <div class="mgmt-card" id="addUserCard">
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
                            <div class="form-label">Full Name:</div>
                            <input type="text" class="form-control" name="full_name" id="fullName" placeholder="Juan Dela Cruz" required>

                            <div class="form-label">Username:</div>
                            <div class="username-row">
                                <input type="text" class="form-control" name="username" id="username" placeholder="juan.delacruz" required>
                                <select class="domain-select" name="domain" id="domain">
                                    <option value="@leadsagri.com" selected>@leadsagri.com</option>
                                </select>
                            </div>

                            <div class="form-label">New Password:</div>
                            <div class="password-row">
                                <input type="password" class="form-control" name="password" id="newPassword" required>
                                <button type="button" class="btn btn-auto" id="autoGenerateBtn">Auto Generate</button>
                            </div>

                            <div class="checks">
                                <label><input type="checkbox" id="sendInitiation" checked> Send Account Initiation</label>
                                <label><input type="checkbox" id="sendInvitation" checked> Send Email Invitation</label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" id="cancelAddUser">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="createUserBtn">Create User</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mgmt-card" id="usersListCard">
                <div class="mgmt-card-header">
                    <div class="title">
                        <span class="icon"><i class="fas fa-users"></i></span>
                        <span>Users List</span>
                    </div>
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
                                <?php foreach ($user_departments as $d): ?>
                                    <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="domain-select" id="usersRole">
                                <option value="all" selected>All Roles</option>
                                <option value="employee">Employee</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>

                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Department</th>
                            </tr>
                        </thead>
                        <tbody id="usersListBody">
                            <tr><td class="users-empty" colspan="2">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="promote-header">
            <div class="promote-header-icon">
                <i class="fas fa-user-shield"></i>
            </div>
            <div>
                <h2 class="promote-header-title">Promote IT Employees to Admin</h2>
                <div class="promote-header-subtitle">Manage which IT staff can be granted <strong>administrator</strong> access.</div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="GET" class="search-row">
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" name="search" class="search-input" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search IT employee...">
            </div>
        </form>
        <script>
        (function () {
            var input = document.querySelector('input[name="search"]');
            if (!input) return;
            var t = null;
            input.addEventListener('input', function () {
                if (t) clearTimeout(t);
                t = setTimeout(function () {
                    if (input.form) input.form.submit();
                }, 350);
            });
        })();
        </script>

        <div class="table-card">
            <table class="user-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
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
                                <td><span class="dept-pill">IT</span></td>
                                <td>
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

        <!-- New Section: Current IT Admins -->
        <h3 class="section-title"><span class="status-dot"></span>Current IT Admins (<?= (int) $admins_result->num_rows ?>)</h3>

        <div class="admin-grid">
            <?php if ($admins_result->num_rows > 0): ?>
                <?php while($admin = $admins_result->fetch_assoc()): ?>
                    <div class="admin-card">
                        <div class="admin-avatar">
                            <?= strtoupper(substr($admin['name'], 0, 1)) ?>
                        </div>
                        <div class="admin-name"><?= htmlspecialchars($admin['name']) ?></div>
                        <div class="admin-email"><?= htmlspecialchars($admin['email']) ?></div>
                        <span class="admin-badge">Admin</span>

                        <?php if ($admin['id'] != $_SESSION['user_id']): ?>
                            <button type="button" class="remove-admin-btn" style="width: 100%; justify-content: center; margin-top: 10px;" onclick="confirmRemoval(<?= $admin['id'] ?>)">
                                <i class="fa-solid fa-trash"></i> Remove Admin
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color: #6B7280;">No IT Admins found.</p>
            <?php endif; ?>
        </div>

    </div>

</div>

<script src="../js/admin.js"></script>

<script>
    function randomPassword(len) {
        var length = typeof len === 'number' && len > 0 ? len : 14;
        var lower = 'abcdefghijklmnopqrstuvwxyz';
        var upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        var nums = '0123456789';
        var syms = '!@#$%^&*()-_=+[]{};:,.?';
        var all = lower + upper + nums + syms;
        function pick(set) { return set[Math.floor(Math.random() * set.length)]; }
        var out = [pick(lower), pick(upper), pick(nums), pick(syms)];
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
            body.innerHTML = '<tr><td class="users-empty" colspan="2">No users found.</td></tr>';
            return;
        }
        body.innerHTML = users.map(function (u) {
            var dept = u.department ? String(u.department) : '-';
            return '<tr><td>' + escapeHtml(String(u.name || '')) + '</td><td>' + escapeHtml(dept) + '</td></tr>';
        }).join('');
    }

    function loadUsersList() {
        var qEl = document.getElementById('usersSearch');
        var deptEl = document.getElementById('usersDept');
        var roleEl = document.getElementById('usersRole');
        var q = qEl ? qEl.value.trim() : '';
        var dept = deptEl ? deptEl.value : 'all';
        var role = roleEl ? roleEl.value : 'all';
        var url = 'ajax_users_list.php?q=' + encodeURIComponent(q) + '&department=' + encodeURIComponent(dept) + '&role=' + encodeURIComponent(role) + '&limit=80';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    renderUsers([]);
                    return;
                }
                renderUsers(data.users || []);
            })
            .catch(function () { renderUsers([]); });
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
        var autoBtn = document.getElementById('autoGenerateBtn');
        var passEl = document.getElementById('newPassword');
        if (autoBtn && passEl) {
            autoBtn.addEventListener('click', function () {
                passEl.value = randomPassword(14);
                passEl.focus();
            });
        }

        var cancelBtn = document.getElementById('cancelAddUser');
        var form = document.getElementById('addUserForm');
        if (cancelBtn && form) {
            cancelBtn.addEventListener('click', function () {
                form.reset();
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
                fd.set('send_initiation', (document.getElementById('sendInitiation') && document.getElementById('sendInitiation').checked) ? '1' : '0');
                fd.set('send_invitation', (document.getElementById('sendInvitation') && document.getElementById('sendInvitation').checked) ? '1' : '0');

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
                        Swal.fire({ icon: 'success', title: 'Success', text: data.message || 'User created successfully', confirmButtonColor: '#1B5E20' });
                        addUserForm.reset();
                        loadUsersList();
                    })
                    .catch(function () {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to create user.', confirmButtonColor: '#1B5E20' });
                    })
                    .finally(function () {
                        if (btn) btn.disabled = false;
                    });
            });
        }

        var debounceT = null;
        var usersSearch = document.getElementById('usersSearch');
        if (usersSearch) {
            usersSearch.addEventListener('input', function () {
                if (debounceT) clearTimeout(debounceT);
                debounceT = setTimeout(loadUsersList, 250);
            });
        }
        ['usersDept', 'usersRole'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', loadUsersList);
        });

        loadUsersList();
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
</style>

</body>
</html>
