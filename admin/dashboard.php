<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
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

/* Summary Counts */
$total = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets")
              ->fetch_assoc()['count'];

$open = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Open'")
             ->fetch_assoc()['count'];

$progress = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='In Progress'")
                 ->fetch_assoc()['count'];

$resolved = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Resolved'")
                 ->fetch_assoc()['count'];
/* ===== DEPARTMENT DATA ===== */

$deptQuery = $conn->query("
    SELECT assigned_department, COUNT(*) as count
    FROM employee_tickets
    GROUP BY assigned_department
");

$departments = [];
$deptCounts = [];

while($row = $deptQuery->fetch_assoc()) {
    $departments[] = $row['assigned_department'];
    $deptCounts[] = $row['count'];
}

/* ===== PRIORITY DATA ===== */

$priorityAgg = $conn->query("
    SELECT 
        SUM(LOWER(priority) = 'low') AS low_count,
        SUM(LOWER(priority) = 'medium') AS medium_count,
        SUM(LOWER(priority) = 'high') AS high_count,
        SUM(LOWER(priority) = 'critical') AS critical_count
    FROM employee_tickets
")->fetch_assoc();

$priorities = ['Low', 'Medium', 'High', 'Critical'];
$priorityCounts = [
    (int) ($priorityAgg['low_count'] ?? 0),
    (int) ($priorityAgg['medium_count'] ?? 0),
    (int) ($priorityAgg['high_count'] ?? 0),
    (int) ($priorityAgg['critical_count'] ?? 0),
];

$recentTickets = [];
$recentRes = $conn->query("
    SELECT id, assigned_department AS department, priority, status
    FROM employee_tickets
    ORDER BY created_at DESC
    LIMIT 5
");
if ($recentRes) {
    while ($row = $recentRes->fetch_assoc()) {
        $recentTickets[] = $row;
    }
}
?>


<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        .recent-tickets-card .card-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
        }
        .recent-tickets-card .card-header h3{
            margin:0;
            font-size: 1.05rem;
            color: #0f172a;
        }
        .recent-tickets-card .card-menu{
            color:#64748b;
            cursor:pointer;
            padding:6px 8px;
            border-radius:8px;
            transition: background-color .15s ease;
        }
        .recent-tickets-card .card-menu:hover{
            background:#f1f5f9;
        }
        .card-body{
            padding-top: 12px;
        }
        .recent-ticket-table, .recent-tickets-table{
            width:100%;
            border-collapse:collapse;
        }
        .recent-ticket-table th, .recent-tickets-table th{
            text-align:left;
            font-size:12px;
            letter-spacing:.02em;
            color:#64748b;
            padding:8px 10px;
            border-bottom:1px solid #e2e8f0;
            white-space:nowrap;
        }
        .recent-ticket-table td, .recent-tickets-table td{
            padding:10px;
            border-bottom:1px solid #f1f5f9;
            color:#0f172a;
            vertical-align:middle;
        }
        .recent-ticket-table tbody tr, .recent-tickets-table tbody tr{
            transition:background-color .15s ease;
        }
        .recent-ticket-table tbody tr:hover, .recent-tickets-table tbody tr:hover{
            background:#f8fafc;
        }
        .recent-ticket-link{
            color:#1B5E20;
            font-weight:700;
            text-decoration:none;
        }
        .recent-ticket-link:hover{
            text-decoration:underline;
        }
        .create-ticket-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:10px 14px;
            border-radius:10px;
            background:#1B5E20;
            color:#fff;
            font-weight:700;
            font-size:14px;
            text-decoration:none;
            border:1px solid rgba(27, 94, 32, .25);
            transition: transform .12s ease, background-color .12s ease;
            white-space:nowrap;
        }
        .create-ticket-btn:hover{
            background:#166534;
            transform: translateY(-1px);
        }
        .rt-priority, .rt-status{
            display:inline-block;
            padding:4px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            line-height:1;
            white-space:nowrap;
        }
        .rt-priority-critical{ background:#E53935; color:#fff; }
        .rt-priority-high{ background:#FB8C00; color:#fff; }
        .rt-priority-medium{ background:#FBC02D; color:#111827; }
        .rt-priority-low{ background:#43A047; color:#fff; }
        .rt-priority-default{ background:#e2e8f0; color:#0f172a; }
        .rt-status-open{ background:#dcfce7; color:#166534; }
        .rt-status-in-progress{ background:#dbeafe; color:#1d4ed8; }
        .rt-status-resolved{ background:#e2e8f0; color:#334155; }
        .rt-status-closed{ background:#e2e8f0; color:#334155; }
        .recent-tickets-table-wrap{
            width:100%;
            overflow-x:auto;
        }
        .recent-ticket-table, .recent-tickets-table{
            min-width:520px;
        }
        @media (max-width: 600px){
            .recent-ticket-table, .recent-tickets-table{
                min-width:480px;
            }
        }

        .priority-chart-card .chart-container{
            position: relative;
            width: 100%;
            max-width: 420px;
            height: auto;
            min-height: 350px;
            margin: auto;
            padding-bottom: 20px;
        }
        .priority-chart-card canvas{
            max-width: 100%;
            height: auto !important;
            max-height: 400px;
        }
    </style>
</head>
<body>

<div class="admin-page">

    <?php include '../includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-content">

            <div class="admin-page-header">
                <div>
                    <div class="admin-page-title">Admin Dashboard</div>
                    <div class="admin-page-subtitle">
                        Overview of ticket activity and system performance.
                    </div>
                </div>
            </div>

            <section class="admin-stats-grid">
                <div class="admin-stat-card">
                    <div class="admin-stat-icon total">⏱</div>
                    <div class="admin-stat-label">Total Tickets</div>
                    <div class="admin-stat-value"><?= $total ?></div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon open">📂</div>
                    <div class="admin-stat-label">Open</div>
                    <div class="admin-stat-value"><?= $open ?></div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon progress">⚙️</div>
                    <div class="admin-stat-label">In Progress</div>
                    <div class="admin-stat-value"><?= $progress ?></div>
                </div>

                <div class="admin-stat-card">
                    <div class="admin-stat-icon resolved">✅</div>
                    <div class="admin-stat-label">Resolved</div>
                    <div class="admin-stat-value"><?= $resolved ?></div>
                </div>
            </section>

            <section class="admin-analytics-section" style="margin-top: 18px;">
                <div class="admin-card">
                    <h3>Tickets by Department</h3>
                    <div class="chart-container">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>

                <div class="admin-card priority-chart-card">
                    <h3>Tickets by Priority</h3>
                    <div class="chart-container">
                        <canvas id="priorityChart" width="400" height="400"></canvas>
                    </div>
                </div>
            </section>

            <section class="admin-analytics-section">
                <div class="admin-card recent-tickets-card">
                    <div class="card-header">
                        <h3>Recent Tickets</h3>
                        <div class="card-menu" title="Options"><i class="fas fa-ellipsis-h"></i></div>
                    </div>
                    <div class="card-body">
                        <div class="recent-tickets-table-wrap">
                            <table class="recent-ticket-table">
                                <thead>
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Department</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($recentTickets) > 0): ?>
                                        <?php foreach ($recentTickets as $t): ?>
                                            <?php
                                                $prioritySlug = strtolower((string) ($t['priority'] ?? ''));
                                                if (!in_array($prioritySlug, ['critical', 'high', 'medium', 'low'], true)) {
                                                    $prioritySlug = 'default';
                                                }

                                                $statusSlug = strtolower((string) ($t['status'] ?? ''));
                                                $statusSlug = str_replace(' ', '-', $statusSlug);
                                                if (!in_array($statusSlug, ['open', 'in-progress', 'resolved', 'closed'], true)) {
                                                    $statusSlug = 'resolved';
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <a class="recent-ticket-link" href="all_tickets.php?ticket_id=<?= (int) $t['id'] ?>">
                                                        #<?= str_pad((string) $t['id'], 6, '0', STR_PAD_LEFT) ?>
                                                    </a>
                                                </td>
                                                <td><?= htmlspecialchars((string) ($t['department'] ?? '-')) ?></td>
                                                <td>
                                                    <span class="rt-priority rt-priority-<?= htmlspecialchars($prioritySlug) ?>">
                                                        <?= htmlspecialchars((string) ($t['priority'] ?? '-')) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="rt-status rt-status-<?= htmlspecialchars($statusSlug) ?>">
                                                        <?= htmlspecialchars((string) ($t['status'] ?? '-')) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" style="color:#64748b; padding:16px;">No recent tickets found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                            <a href="all_tickets.php" class="create-ticket-btn">+ Create Ticket</a>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<script src="../js/admin.js"></script>

<script>
if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
    if (typeof Chart.register === 'function') {
        Chart.register(ChartDataLabels);
    } else if (Chart.plugins && typeof Chart.plugins.register === 'function') {
        Chart.plugins.register(ChartDataLabels);
    }
}
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($departments); ?>,
        datasets: [{
            data: <?= json_encode($deptCounts); ?>,
            backgroundColor: '#1B5E20',
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        devicePixelRatio: 2,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

new Chart(document.getElementById('priorityChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($priorities); ?>,
        datasets: [{
            data: <?= json_encode($priorityCounts); ?>,
            backgroundColor: [
                '#43A047',
                '#FBC02D',
                '#FB8C00',
                '#E53935'
            ],
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        aspectRatio: 1,
        cutout: '60%',
        devicePixelRatio: window.devicePixelRatio || 2,
        layout: {
            padding: {
                top: 10,
                bottom: 20,
                left: 10,
                right: 10
            }
        },
        plugins: { 
            datalabels: {
                color: '#fff',
                font: {
                    weight: 'bold',
                    size: 13
                },
                formatter: (value, context) => {
                    const data = (context.chart.data.datasets[0] && context.chart.data.datasets[0].data) ? context.chart.data.datasets[0].data : [];
                    const total = data.reduce((a, b) => a + (Number(b) || 0), 0);
                    if (!total || value === 0) return '';
                    const pct = Math.round(((Number(value) || 0) / total) * 100);
                    return pct > 0 ? pct + '%' : '';
                },
                display: function(context) {
                    return context.dataset.data[context.dataIndex] > 0;
                }
            },
            legend: { 
                position: 'top',
                labels: { 
                    usePointStyle: true,
                    boxWidth: 8,
                    padding: 20,
                    generateLabels: function(chart) {
                        const labels = Array.isArray(chart.data.labels) ? chart.data.labels : [];
                        const colors = (chart.data.datasets[0] && Array.isArray(chart.data.datasets[0].backgroundColor)) ? chart.data.datasets[0].backgroundColor : [];
                        const data = (chart.data.datasets[0] && Array.isArray(chart.data.datasets[0].data)) ? chart.data.datasets[0].data : [];
                        const total = data.reduce((sum, v) => sum + (Number(v) || 0), 0);
                        return labels.map((label, i) => {
                            const val = Number(data[i]) || 0;
                            const pct = total ? Math.round((val / total) * 100) : 0;
                            return {
                                text: `${label} ${pct}%`,
                                fillStyle: colors[i],
                                strokeStyle: colors[i],
                                lineWidth: 0,
                                pointStyle: 'circle',
                                hidden: chart.getDataVisibility ? !chart.getDataVisibility(i) : false,
                                index: i
                            };
                        });
                    },
                    font: { 
                        size: 13, 
                        weight: '500',
                        family: "'Inter', sans-serif"
                    } 
                } 
            } 
        }
    }
});
</script>
</body>
</html>
