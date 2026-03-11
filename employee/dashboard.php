<?php
require_once '../config/database.php';

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* Fetch Company */
$company = '';
$userQuery = $conn->query("SELECT company FROM users WHERE id = $user_id");
if ($userQuery && $row = $userQuery->fetch_assoc()) {
    $company = $row['company'];
}

/* Ticket Counts (ONLY this employee AND their department) */
$dept = $conn->real_escape_string($_SESSION['department']);

$total = $conn->query("
    SELECT COUNT(*) AS count 
    FROM employee_tickets 
    WHERE user_id = $user_id AND assigned_department = '$dept'
")->fetch_assoc()['count'];

$open = $conn->query("
    SELECT COUNT(*) AS count 
    FROM employee_tickets 
    WHERE user_id = $user_id AND status='Open' AND assigned_department = '$dept'
")->fetch_assoc()['count'];

$progress = $conn->query("
    SELECT COUNT(*) AS count 
    FROM employee_tickets 
    WHERE user_id = $user_id AND status='In Progress' AND assigned_department = '$dept'
")->fetch_assoc()['count'];

$resolved = $conn->query("
    SELECT COUNT(*) AS count 
    FROM employee_tickets 
    WHERE user_id = $user_id AND status='Resolved' AND assigned_department = '$dept'
")->fetch_assoc()['count'];

/* Recent Tickets */
$recent = $conn->query("
    SELECT * 
    FROM employee_tickets 
    WHERE user_id = $user_id AND assigned_department = '$dept'
    ORDER BY created_at DESC 
    LIMIT 5
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <!-- Optional: Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <!-- 2️⃣ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <!-- 3️⃣ HERO SECTION -->
            <div class="hero-section">
                <h1 class="hero-title">Welcome back, <?= htmlspecialchars($_SESSION['name']); ?> 👋</h1>
                <div class="hero-dept">
                    <?= htmlspecialchars($_SESSION['department']); ?> Department
                    <?php if (!empty($company)): ?>
                        <span class="company-text">• <?= htmlspecialchars($company); ?></span>
                    <?php endif; ?>
                </div>
                <p class="hero-subtitle">Here's an overview of your helpdesk activity.</p>
            </div>

            <!-- 4️⃣ STATISTICS CARDS -->
            <div class="stats-grid">
                <!-- Total Tickets -->
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="stat-value"><?= $total ?></div>
                </div>

                <!-- Open -->
                <div class="stat-card open">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-label">Open</div>
                    <div class="stat-value"><?= $open ?></div>
                </div>

                <!-- In Progress -->
                <div class="stat-card progress">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?= $progress ?></div>
                </div>

                <!-- Resolved -->
                <div class="stat-card resolved">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Resolved</div>
                    <div class="stat-value"><?= $resolved ?></div>
                </div>
            </div>

            <!-- 5️⃣ RECENT TICKETS SECTION -->
            <div class="recent-section">
                <div class="section-header">
                    <h2 class="section-title">Recent Tickets</h2>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent->num_rows > 0): ?>
                                <?php while($row = $recent->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= (int) $row['id']; ?>" style="cursor:pointer;">
                                    <td>#<?= $row['id']; ?></td>
                                    <td><?= htmlspecialchars($row['subject']); ?></td>
                                    <td><?= htmlspecialchars($row['category']); ?></td>
                                    
                                    <td>
                                        <span class="priority-pill priority-<?= strtolower($row['priority']); ?>">
                                            <?= htmlspecialchars($row['priority']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>

                                    <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; color: #94a3b8; padding: 30px;">
                                        No recent tickets found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- JS Script -->
    <script src="../js/employee-dashboard.js"></script>
    <script>
    document.querySelectorAll('.recent-section .ticket-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (!id) return;
            window.location.href = 'my_tickets.php?ticket_id=' + encodeURIComponent(id);
        });
    });
    </script>

   

</body>
</html>


