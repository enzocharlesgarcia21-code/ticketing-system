<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

$companies = [
    'FARMEX',
    'FARMASEE',
    'Golden Primestocks Chemical Inc - GPSCI',
    'Leads Animal Health - LAH',
    'Leads Environmental Health - LEH',
    'Leads Tech Corporation - LTC',
    'LINGAP LEADS FOUNDATION - Lingap',
    'Malveda Holdings Corporation - MHC',
    'Malveda Properties & Development Corporation - MPDC',
    'Primestocks Chemical Corporation - PCC',
];

$departments = ['Accounting', 'Admin', 'Bidding', 'E-Comm', 'HR', 'IT', 'Marketing', 'Sales'];

$stmt = $conn->prepare("SELECT id, name, email, company, department, role, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: logout.php");
    exit();
}

if (($user['company'] ?? '') !== '' && !in_array($user['company'], $companies, true)) {
    $companies[] = $user['company'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    $company = trim($_POST['company'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if ($company === '' || $department === '') {
        $error_msg = 'Company and Department are required.';
    } elseif (!in_array($company, $companies, true)) {
        $error_msg = 'Invalid company selected.';
    } elseif (!in_array($department, $departments, true)) {
        $error_msg = 'Invalid department selected.';
    } else {
        $update = $conn->prepare("UPDATE users SET company = ?, department = ? WHERE id = ?");
        $update->bind_param("ssi", $company, $department, $user_id);
        if ($update->execute()) {
            $success_msg = 'Profile updated successfully.';
            $_SESSION['department'] = $department;
            $user['company'] = $company;
            $user['department'] = $department;
        } else {
            $error_msg = 'Unable to update profile. Please try again.';
        }
        $update->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin: 0 auto 20px;
            max-width: 850px;
            border: 1px solid transparent;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-color: #bbf7d0;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-color: #fecaca;
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header" style="text-align: center; margin-bottom: 40px;">
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">View your account information and update your company/department.</p>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($success_msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <form method="POST" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <h3 class="form-section-title">Account Details</h3>

                    <div class="form-row">
                        <div class="form-group half">
                            <label>Full Name</label>
                            <input type="text" class="form-control readonly" value="<?= htmlspecialchars($user['name'] ?? '') ?>" readonly>
                        </div>
                        <div class="form-group half">
                            <label>Email</label>
                            <input type="email" class="form-control readonly" value="<?= htmlspecialchars($user['email'] ?? '') ?>" readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group half">
                            <label>Company / Subsidiary</label>
                            <div class="select-wrapper">
                                <select name="company" class="form-control" required>
                                    <option value=""disabled selected hidden>Select company</option>
                                    <?php foreach ($companies as $co): ?>
                                        <option value="<?= htmlspecialchars($co) ?>" <?= ($user['company'] ?? '') === $co ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($co) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                        <div class="form-group half">
                            <label>Department</label>
                            <div class="select-wrapper">
                                <select name="department" class="form-control" required>
                                    <option value=""disabled selected hidden>Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?= htmlspecialchars($dept) ?>" <?= ($user['department'] ?? '') === $dept ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group half">
                            <label>Role</label>
                            <input type="text" class="form-control readonly" value="<?= htmlspecialchars($user['role'] ?? '') ?>" readonly>
                        </div>
                        <div class="form-group half">
                            <label>Account Created Date</label>
                            <input type="text" class="form-control readonly" value="<?= htmlspecialchars(date('M d, Y', strtotime($user['created_at']))) ?>" readonly>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Save Changes</button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>
</body>
</html>

