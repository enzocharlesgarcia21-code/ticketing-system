<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

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

    if ($assignedCompany !== '') {
        $companyLabel = ticket_company_display_name($assignedCompany);
        if ($assignedGroup !== '') {
            return $assignedGroup . ($companyLabel !== '' ? " ($companyLabel)" : '');
        }
        if ($assignedDept !== '') {
            return $assignedDept . ($companyLabel !== '' ? " ($companyLabel)" : '');
        }
        if ($companyLabel !== '') return $companyLabel;
    }

    if ($assignedGroup !== '') return $assignedGroup;
    if ($assignedDept !== '') return $assignedDept;
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

/* Summary Counts */
$total = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets")
              ->fetch_assoc()['count'];

$open = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Open'")
             ->fetch_assoc()['count'];

$progress = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='In Progress'")
                 ->fetch_assoc()['count'];

$resolved = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Resolved'")
                 ->fetch_assoc()['count'];

$closed = $conn->query("SELECT COUNT(*) AS count FROM employee_tickets WHERE status='Closed'")
               ->fetch_assoc()['count'];

$weeklyOverview = $conn->query("
    SELECT
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS current_week_total,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS previous_week_total,
        SUM(status = 'Open' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS current_week_open,
        SUM(status = 'In Progress' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS current_week_progress,
        SUM(status = 'Resolved' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS current_week_resolved,
        SUM(status = 'Closed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS current_week_closed
    FROM employee_tickets
")->fetch_assoc();

$currentWeekTotal = (int) ($weeklyOverview['current_week_total'] ?? 0);
$previousWeekTotal = (int) ($weeklyOverview['previous_week_total'] ?? 0);
$currentWeekOpen = (int) ($weeklyOverview['current_week_open'] ?? 0);
$currentWeekProgress = (int) ($weeklyOverview['current_week_progress'] ?? 0);
$currentWeekResolved = (int) ($weeklyOverview['current_week_resolved'] ?? 0);
$currentWeekClosed = (int) ($weeklyOverview['current_week_closed'] ?? 0);

if ($previousWeekTotal > 0) {
    $totalDeltaPercent = (int) round((($currentWeekTotal - $previousWeekTotal) / $previousWeekTotal) * 100);
} elseif ($currentWeekTotal > 0) {
    $totalDeltaPercent = 100;
} else {
    $totalDeltaPercent = 0;
}

$dashboardStats = [
    [
        'variant' => 'total',
        'label' => 'Total Tickets',
        'value' => (int) $total,
        'subtitle' => 'All tickets in system',
        'icon' => 'fa-stopwatch',
        'trend_value' => abs($totalDeltaPercent) . '%',
        'trend_caption' => 'vs last week',
        'trend_direction' => $totalDeltaPercent >= 0 ? 'up' : 'down',
    ],
    [
        'variant' => 'open',
        'label' => 'Open',
        'value' => (int) $open,
        'subtitle' => 'Awaiting response',
        'icon' => 'fa-folder-open',
        'trend_value' => (string) $currentWeekOpen,
        'trend_caption' => 'this week',
        'trend_direction' => 'up',
    ],
    [
        'variant' => 'progress',
        'label' => 'In Progress',
        'value' => (int) $progress,
        'subtitle' => 'Currently being worked',
        'icon' => 'fa-gear',
        'trend_value' => (string) $currentWeekProgress,
        'trend_caption' => 'this week',
        'trend_direction' => 'down',
    ],
    [
        'variant' => 'resolved',
        'label' => 'Resolved',
        'value' => (int) $resolved,
        'subtitle' => 'Completed tickets',
        'icon' => 'fa-circle-check',
        'trend_value' => (string) $currentWeekResolved,
        'trend_caption' => 'this week',
        'trend_direction' => 'down',
    ],
    [
        'variant' => 'closed',
        'label' => 'Closed',
        'value' => (int) $closed,
        'subtitle' => 'Closed tickets',
        'icon' => 'fa-box-archive',
        'trend_value' => (string) $currentWeekClosed,
        'trend_caption' => 'this week',
        'trend_direction' => 'down',
    ],
];
/* ===== DEPARTMENT DATA ===== */

$deptQuery = $conn->query("
    SELECT
        COALESCE(
            NULLIF(TRIM(assigned_department), ''),
            NULLIF(TRIM(assigned_group), ''),
            NULLIF(TRIM(department), ''),
            'Unassigned'
        ) AS assigned_department,
        COUNT(*) as count
    FROM employee_tickets
    GROUP BY
        COALESCE(
            NULLIF(TRIM(assigned_department), ''),
            NULLIF(TRIM(assigned_group), ''),
            NULLIF(TRIM(department), ''),
            'Unassigned'
        )
    ORDER BY count DESC, assigned_department ASC
    LIMIT 5
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
        SUM(LOWER(priority) IN ('low','medium')) AS low_count,
        SUM(LOWER(priority) = 'high') AS high_count,
        SUM(LOWER(priority) = 'critical') AS critical_count
    FROM employee_tickets
")->fetch_assoc();

$priorities = ['Low', 'High', 'Critical'];
$priorityCounts = [
    (int) ($priorityAgg['low_count'] ?? 0),
    (int) ($priorityAgg['high_count'] ?? 0),
    (int) ($priorityAgg['critical_count'] ?? 0),
];
$priorityColors = ['#43A047', '#FB8C00', '#E53935'];
$priorityTotal = array_sum($priorityCounts);
$priorityLegendItems = [];
foreach ($priorities as $index => $priorityLabel) {
    $count = (int) ($priorityCounts[$index] ?? 0);
    $percent = $priorityTotal > 0 ? round(($count / $priorityTotal) * 100) : 0;
    $priorityLegendItems[] = [
        'label' => $priorityLabel,
        'count' => $count,
        'percent' => $percent,
        'color' => $priorityColors[$index] ?? '#94a3b8',
    ];
}
$priorityLeadIndex = 0;
if ($priorityTotal > 0) {
    $priorityLeadIndex = (int) array_search(max($priorityCounts), $priorityCounts, true);
    if ($priorityLeadIndex < 0) {
        $priorityLeadIndex = 0;
    }
}
$priorityCenterPercent = (int) ($priorityLegendItems[$priorityLeadIndex]['percent'] ?? 0);
$priorityCenterLabel = ($priorities[$priorityLeadIndex] ?? 'Low') . ' Priority';

$recentTickets = [];
$recentRes = $conn->query("
    SELECT
        t.id,
        u.name AS requester_name,
        u.email AS requester_email,
        t.department,
        u.department AS user_department,
        t.company,
        t.assigned_company,
        t.assigned_group,
        t.assigned_department,
        t.priority,
        t.status,
        t.created_at,
        t.is_read
    FROM employee_tickets t
    JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC
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
    <link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        .admin-content{
            max-width: 1460px;
        }

        .admin-stats-grid{
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 20px;
            margin-top: 10px;
        }

        .admin-stat-card{
            display:flex;
            flex-direction:column;
            justify-content:center;
            min-height:158px;
            padding:20px 22px 18px;
            border-radius:22px;
            border:1px solid #e7edf5;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.07);
            position:relative;
            overflow:hidden;
            background:
                linear-gradient(90deg, var(--stat-accent, #4ade80) 0 6px, #ffffff 6px 100%);
        }

        .admin-stat-card::before{
            content:none;
        }

        .admin-stat-card.total{
            --stat-accent:#4ade80;
            --stat-icon-bg:#ecfdf3;
            --stat-icon-color:#22c55e;
            --stat-chip-bg:#ecfdf3;
            --stat-chip-color:#22c55e;
        }

        .admin-stat-card.open{
            --stat-accent:#4fb7ff;
            --stat-icon-bg:#e8f4ff;
            --stat-icon-color:#36a3f6;
            --stat-chip-bg:#edf7ff;
            --stat-chip-color:#2d9bf0;
        }

        .admin-stat-card.progress{
            --stat-accent:#9b6bff;
            --stat-icon-bg:#f3ebff;
            --stat-icon-color:#8b5cf6;
            --stat-chip-bg:#f5edff;
            --stat-chip-color:#7c3aed;
        }

        .admin-stat-card.resolved{
            --stat-accent:#ffab2e;
            --stat-icon-bg:#fff4e5;
            --stat-icon-color:#f59e0b;
            --stat-chip-bg:#fff4e8;
            --stat-chip-color:#f97316;
        }

        .admin-stat-card.closed{
            --stat-accent:#94a3b8;
            --stat-icon-bg:#f1f5f9;
            --stat-icon-color:#475569;
            --stat-chip-bg:#f8fafc;
            --stat-chip-color:#475569;
        }

        .admin-stat-trend{
            position:absolute;
            top:16px;
            right:18px;
            display:inline-flex;
            align-items:flex-start;
            gap:8px;
            padding:8px 12px;
            border-radius:14px;
            background:var(--stat-chip-bg, #f8fafc);
            color:var(--stat-chip-color, #334155);
            font-weight:700;
            line-height:1;
            white-space:nowrap;
        }

        .admin-stat-trend-icon{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:16px;
            height:16px;
            flex:0 0 16px;
            margin-top:1px;
        }

        .admin-stat-trend i{
            font-size:14px;
        }

        .admin-stat-trend-text{
            display:flex;
            flex-direction:column;
            align-items:flex-start;
            gap:4px;
        }

        .admin-stat-trend-value{
            font-size:0.98rem;
            font-weight:700;
            line-height:1;
        }

        .admin-stat-trend-caption{
            font-size:0.78rem;
            font-weight:600;
            color:#64748b;
            line-height:1.05;
        }

        .admin-stat-main{
            display:flex;
            align-items:flex-start;
            gap:16px;
            padding-right:118px;
        }

        .admin-stat-copy{
            display:flex;
            flex-direction:column;
            align-items:flex-start;
            gap:0;
            min-width:0;
        }

        .admin-stat-icon{
            width:54px;
            height:54px;
            border-radius:14px;
            margin-bottom:0;
            background:var(--stat-icon-bg, #f3f4f6);
            color:var(--stat-icon-color, #0f172a);
            font-size:22px;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.16);
        }

        .admin-stat-label{
            font-size:13px;
            font-weight:600;
            color:#253047;
            margin-bottom:6px;
            line-height:1.2;
            padding-top:4px;
        }

        .admin-stat-value{
            font-size:2.55rem;
            line-height:1;
            font-weight:700;
            letter-spacing:-0.03em;
            color:#19233b;
            margin-bottom:8px;
        }

        .admin-stat-subtext{
            font-size:0.82rem;
            color:#64748b;
            font-weight:500;
            margin-top:2px;
            padding-right:118px;
        }

        .recent-tickets-title{
            margin: 0 0 12px;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
        }
        .recent-ticket-link{
            color:#1B5E20;
            font-weight:700;
            text-decoration:none;
        }
        .recent-ticket-link:hover{
            text-decoration:underline;
        }
        .recent-tickets-card .ticket-row{
            cursor: pointer;
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
        .recent-tickets-card.table-card .table-responsive{
            width:100%;
            overflow-x:auto;
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
        .recent-tickets-card .admin-table{
            min-width: 1100px;
        }
        @media (max-width: 600px){
            .recent-tickets-card .admin-table{
                min-width: 980px;
            }
        }

        .admin-analytics-section {
            align-items: stretch;
        }
        .admin-analytics-section .admin-card {
            display: flex;
            flex-direction: column;
        }
        .admin-analytics-section .chart-container {
            height: 360px;
        }
        .admin-analytics-section .admin-card canvas {
            height: 100% !important;
        }
        .priority-chart-card .chart-container{
            max-width: 420px;
            margin: 0 auto;
            position: relative;
            padding-bottom: 52px;
        }
        .priority-chart-card {
            position: relative;
            overflow: hidden;
            background:
                radial-gradient(circle at 22% 26%, rgba(255, 255, 255, 0.96), rgba(255, 255, 255, 0.92) 34%, rgba(244, 248, 251, 0.92) 100%);
        }
        .priority-chart-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 28% 76%, rgba(67, 160, 71, 0.08), transparent 22%),
                radial-gradient(circle at 76% 28%, rgba(251, 192, 45, 0.08), transparent 18%);
            pointer-events: none;
        }
        .priority-chart-card > * {
            position: relative;
            z-index: 1;
        }
        .priority-chart-header {
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            gap: 12px;
            margin-bottom: 8px;
        }
        .priority-chart-header h3 {
            margin: 0;
        }
        .priority-chart-legend {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: flex-end;
            position: absolute;
            right: 0;
            bottom: 0;
            max-width: 100%;
        }
        .priority-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #374151;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }
        .priority-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
            flex: 0 0 10px;
        }
        .priority-legend-percent {
            color: #111827;
            font-weight: 800;
        }
        .admin-analytics-section.admin-analytics-full{
            grid-template-columns: 1fr;
        }

        @media (max-width: 1400px){
            .admin-stats-grid{
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 992px){
            .admin-stats-grid{
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .admin-stat-card{
                min-height:148px;
            }

            .admin-stat-value{
                font-size:2.5rem;
            }
        }

        @media (max-width: 640px){
            .admin-stats-grid{
                grid-template-columns: 1fr;
            }

            .admin-stat-main,
            .admin-stat-subtext{
                padding-right:0;
            }

            .admin-stat-card{
                padding:18px 18px 16px;
                border-radius:18px;
            }

            .admin-stat-top{
                flex-direction:column;
                align-items:flex-start;
            }

            .admin-stat-icon{
                width:48px;
                height:48px;
                font-size:20px;
            }

            .admin-stat-trend{
                top:14px;
                right:14px;
            }
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
                <?php foreach ($dashboardStats as $stat): ?>
                    <div class="admin-stat-card <?= htmlspecialchars($stat['variant'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="admin-stat-trend">
                            <span class="admin-stat-trend-icon">
                                <i class="fas <?= $stat['trend_direction'] === 'down' ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i>
                            </span>
                            <span class="admin-stat-trend-text">
                                <span class="admin-stat-trend-value"><?= htmlspecialchars($stat['trend_value'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="admin-stat-trend-caption"><?= htmlspecialchars($stat['trend_caption'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </div>
                        <div class="admin-stat-main">
                            <div class="admin-stat-icon <?= htmlspecialchars($stat['variant'], ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fas <?= htmlspecialchars($stat['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                            </div>
                            <div class="admin-stat-copy">
                                <div class="admin-stat-label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="admin-stat-value"><?= (int) $stat['value'] ?></div>
                            </div>
                        </div>
                        <div class="admin-stat-subtext"><?= htmlspecialchars($stat['subtitle'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="admin-analytics-section" style="margin-top: 32px;">
                <div class="admin-card">
                    <h3>Tickets by Department</h3>
                    <div class="chart-container">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>

                <div class="admin-card priority-chart-card">
                    <div class="priority-chart-header">
                        <h3>Tickets by Priority</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="priorityChart"></canvas>
                        <div class="priority-chart-legend">
                            <?php foreach ($priorityLegendItems as $item): ?>
                                <span class="priority-legend-item">
                                    <span class="priority-legend-dot" style="background: <?= htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                    <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="priority-legend-percent"><?= (int) $item['percent'] ?>%</span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-analytics-section admin-analytics-full">
                <h3 class="recent-tickets-title">Recent Tickets</h3>
                <div class="admin-card table-card recent-tickets-card">
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
                                <tbody>
                                    <?php if (count($recentTickets) > 0): ?>
                                        <?php foreach ($recentTickets as $t): ?>
                                        <?php
                                            $priorityValue = (string) ($t['priority'] ?? '-');
                                            $prioritySlug = strtolower($priorityValue);
                                            if (!in_array($prioritySlug, ['critical', 'high', 'medium', 'low'], true)) {
                                                $prioritySlug = 'medium';
                                            }

                                            $statusValue = (string) ($t['status'] ?? '-');
                                            $statusSlug = strtolower(str_replace(' ', '-', $statusValue));
                                            if (!in_array($statusSlug, ['open', 'in-progress', 'resolved', 'closed'], true)) {
                                                $statusSlug = 'open';
                                            }
                                            $createdAt = (string) ($t['created_at'] ?? '');
                                            $dateLabel = $createdAt ? date('M j, Y', strtotime($createdAt)) : '-';
                                        ?>
                                            <tr class="ticket-row" data-id="<?= (int) $t['id'] ?>" tabindex="0" role="link" aria-label="Open ticket #<?= str_pad((string) $t['id'], 6, '0', STR_PAD_LEFT) ?>">
                                                <td data-label="ID">
                                                    <a class="recent-ticket-link" href="all_tickets.php?ticket_id=<?= (int) $t['id'] ?>">
                                                        #<?= str_pad((string) $t['id'], 6, '0', STR_PAD_LEFT) ?>
                                                    </a>
                                            </td>
                                            <td data-label="Requested By">
                                                    <div class="user-info">
                                                        <strong><?= htmlspecialchars((string) ($t['requester_name'] ?? '-')) ?></strong><br>
                                                        <small><?= htmlspecialchars((string) ($t['requester_email'] ?? '')) ?></small>
                                                    </div>
                                                </td>
                                                <td data-label="Priority">
                                                    <span class="badge badge-<?= htmlspecialchars($prioritySlug) ?>">
                                                        <?= htmlspecialchars($priorityValue) ?>
                                                    </span>
                                                </td>
                                                <td data-label="Status">
                                                    <span class="status-<?= htmlspecialchars($statusSlug) ?>">
                                                        <?= htmlspecialchars($statusValue) ?>
                                                    </span>
                                                    <?php if (!empty($t['is_read']) && (int) $t['is_read'] === 0): ?>
                                                        <span class="new-badge">NEW</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Department"><?php
                                                    $origDept = !empty($t['department']) ? $t['department'] : ($t['user_department'] ?? '');
                                                    echo htmlspecialchars($origDept !== '' ? ticket_department_display_name((string) $origDept) : 'Sales');
                                                ?></td>
                                                <td data-label="Created"><?= htmlspecialchars(time_ago_days((string) ($t['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td data-label="SLA"><?= sla_badge_html((string) ($t['created_at'] ?? ''), (string) ($t['status'] ?? ''), (string) ($t['priority'] ?? '')); ?></td>
                                                <td data-label="Assign To"><?= htmlspecialchars(assigned_target_label($t), ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" style="color:#64748b; padding:16px;">No recent tickets found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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

document.addEventListener('click', function (event) {
    var row = event.target && event.target.closest ? event.target.closest('.recent-tickets-card .ticket-row') : null;
    if (!row) return;
    var anchor = event.target && event.target.closest ? event.target.closest('a') : null;
    if (anchor) return;
    var ticketId = row.getAttribute('data-id');
    if (ticketId) {
        window.location.href = 'all_tickets.php?ticket_id=' + encodeURIComponent(ticketId);
    }
});

document.addEventListener('keydown', function (event) {
    var row = event.target && event.target.closest ? event.target.closest('.recent-tickets-card .ticket-row') : null;
    if (!row) return;
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    var ticketId = row.getAttribute('data-id');
    if (ticketId) {
        window.location.href = 'all_tickets.php?ticket_id=' + encodeURIComponent(ticketId);
    }
});

const priorityCenterTextPlugin = {
    id: 'priorityCenterText',
    afterDatasetsDraw(chart) {
        if (chart.canvas.id !== 'priorityChart') return;
        const meta = chart.getDatasetMeta(0);
        if (!meta || !meta.data || !meta.data.length) return;
        const x = meta.data[0].x;
        const y = meta.data[0].y;
        const ctx = chart.ctx;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#1f2937';
        ctx.font = '700 24px Inter, sans-serif';
        ctx.fillText('<?= $priorityCenterPercent ?>%', x, y - 8);
        ctx.fillStyle = '#6b7280';
        ctx.font = '500 13px Inter, sans-serif';
        ctx.fillText('<?= htmlspecialchars($priorityCenterLabel, ENT_QUOTES, 'UTF-8') ?>', x, y + 18);
        ctx.restore();
    }
};
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($departments); ?>,
        datasets: [{
            data: <?= json_encode($deptCounts); ?>,
            backgroundColor: '#1B5E20',
            borderColor: '#144a1e',
            borderWidth: 1,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        devicePixelRatio: 2,
        plugins: { 
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'end',
                color: '#ffffff',
                backgroundColor: function(ctx){ return 'rgba(0,0,0,0)'; },
                textStrokeColor: '#0b3d12',
                textStrokeWidth: 0,
                font: { weight: 'bold', size: 12 },
                offset: 4,
                formatter: function(value) {
                    return value > 0 ? value : '';
                }
            }
        },
        scales: { y: { beginAtZero: true } }
    }
});

new Chart(document.getElementById('priorityChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($priorities); ?>,
        datasets: [{
            data: <?= json_encode($priorityCounts); ?>,
            backgroundColor: <?= json_encode($priorityColors); ?>,
            borderWidth: 2,
            borderColor: '#ffffff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        aspectRatio: 1,
        cutout: '66%',
        devicePixelRatio: window.devicePixelRatio || 2,
        layout: {
            padding: {
                top: 6,
                bottom: 12,
                left: 8,
                right: 8
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
            legend: { display: false }
        }
    },
    plugins: [priorityCenterTextPlugin]
});
</script>
</body>
</html>
