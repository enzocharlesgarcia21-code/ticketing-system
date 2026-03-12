<?php
require_once '../config/database.php';

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

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Management</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <style>
        .create-admin-container {
            padding: 28px 20px 40px;
            max-width: 980px;
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
