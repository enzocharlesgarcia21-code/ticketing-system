<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/notification_service.php';

notif_ensure_action_type_column($conn);

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* Mark all as read if requested */
if (isset($_POST['mark_all_read'])) {
    csrf_validate();
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    $_SESSION['success'] = "All notifications marked as read.";
    header("Location: notifications.php");
    exit();
}

/* Get notifications */
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$total_res = $conn->query("
    SELECT COUNT(*) as c
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = $user_id
      AND n.type <> 'chat_message'
      AND (n.type <> 'note_added' OR t.user_id = n.user_id)
");
$total = $total_res->fetch_assoc()['c'];
$total_pages = ceil($total / $limit);

$stmt = $conn->prepare("
    SELECT n.*, t.priority
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = ?
      AND n.type <> 'chat_message'
      AND (n.type <> 'note_added' OR t.user_id = n.user_id)
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function notif_section_label($datetime) {
    $itemDate = new DateTime($datetime);
    $today = new DateTime('today');
    $yesterday = new DateTime('yesterday');
    if ($itemDate >= $today) return 'Today';
    if ($itemDate >= $yesterday) return 'Yesterday';
    return $itemDate->format('F j, Y');
}

function notif_priority_from_message(string $message): string
{
    if (preg_match('/escalated to\s+(critical|high|medium|low)\b/i', $message, $matches)) {
        return strtolower((string) ($matches[1] ?? ''));
    }
    return '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body.employee-notifications-page .mobile-sidebar,
        body.employee-notifications-page .mobile-sidebar-overlay {
            display: none;
        }

        body.employee-notifications-page .content-wrapper {
            max-width: 860px;
            margin: 0 auto;
        }

        .notif-list-page {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
            border: 0;
        }
        .notif-section-label {
            font-size: 1.08rem;
            font-weight: 700;
            color: #374151;
            margin: 0 0 16px;
            padding-left: 4px;
        }
        .notif-section-card {
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(226, 232, 240, 0.8);
            overflow: hidden;
            margin-bottom: 28px;
        }
        .notif-item-row {
            position: relative;
            padding: 22px 54px 22px 28px;
            border-bottom: 1px solid #edf2f7;
            display: flex;
            align-items: flex-start;
            gap: 18px;
            transition: background 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            background: #ffffff;
        }
        .notif-item-row:last-child {
            border-bottom: 0;
        }
        .notif-item-row::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 7px;
            background: var(--notif-accent, #cbd5e1);
        }
        .notif-item-row:hover {
            background-color: #fbfdff;
        }
        .notif-item-row.notif-chat-pending {
            padding: 22px 54px 22px 28px;
            display: flex;
            align-items: flex-start;
            gap: 18px;
            background: #ffffff;
            border-left: 7px solid #1B5E20;
        }
        .notif-item-row.notif-chat-pending:hover {
            background-color: #f8fbff;
        }
        .notif-item-row.notif-chat-pending::before {
            display: none;
        }
        .notif-item-row.notif-chat-pending.unread {
            background: #f7fbff;
        }
        .notif-item-row.notif-chat-pending.unread::after {
            content: "";
            position: absolute;
            right: 24px;
            top: 32px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #1B5E20;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
        }
        .notif-item-row.notif-follow-up {
            background: linear-gradient(180deg, #fffdf4 0%, #fff9e7 100%);
            border: 1px solid rgba(245, 195, 66, 0.24);
            padding-left: 28px;
        }
        .notif-item-row.notif-follow-up::before {
            background: #f4c542;
        }
        .notif-item-row.notif-follow-up:hover {
            background: linear-gradient(180deg, #fff9e7 0%, #fff4ce 100%);
        }
        .notif-item-row.notif-follow-up.unread::after {
            background: #f4c542;
        }
        .notif-follow-up .notif-chat-pill {
            background: linear-gradient(135deg, #fff3bd 0%, #f9d24d 100%);
            border-color: #d4a017;
            color: #7c4a03;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.45);
        }
        .notif-follow-up .notif-chat-pill i {
            color: #7c4a03;
        }
        .notif-follow-up .notif-title {
            color: #111827;
        }
        .notif-follow-up .notif-title-text {
            color: #111827;
        }
        .notif-follow-up .notif-date {
            color: #7c8aa3;
        }
        .notif-item-row.unread {
            background-color: #ffffff;
        }
        .notif-item-row.unread::after {
            content: "";
            position: absolute;
            right: 24px;
            top: 32px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: var(--notif-dot, #5aa364);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
        }
        .notif-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.55rem;
            flex-shrink: 0;
            color: #ffffff;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.22);
        }
        .notif-content {
            flex-grow: 1;
            min-width: 0;
        }
        .notif-text {
            font-size: 0.98rem;
            color: #1f2937;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        .notif-chat-pending .notif-text {
            font-size: 0.98rem;
            line-height: 1.5;
            color: #1f2937;
            margin-bottom: 10px;
        }
        .notif-chat-pending .notif-text strong {
            color: #1d4f9b;
            font-weight: 700;
        }
        .notif-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .notif-chat-pending .notif-title-row {
            gap: 12px;
            margin-bottom: 8px;
        }
        .notif-title {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
        }
        .notif-chat-pending .notif-title {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }
        .notif-follow-up .notif-msg strong {
            color: #8a5b00;
            font-weight: 700;
        }
        .notif-chat-pill {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-height: 34px;
            padding: 0 14px;
            border-radius: 999px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            box-shadow: 0 6px 14px rgba(37, 99, 235, 0.16);
            font-size: 0.88rem;
            font-weight: 800;
            line-height: 1;
        }
        .notif-chat-pill i {
            font-size: 0.95rem;
        }
        .priority-badge{
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 18px;
            border-radius: 14px;
            border: 2px solid currentColor;
            font-size: 12px;
            font-weight: 800;
            background: #ffffff;
            letter-spacing: 0.01em;
            line-height: 1;
        }
        .priority-badge.priority-critical { color:#E53935; background:#fff3f4; }
        .priority-badge.priority-high { color:#FB8C00; background:#fff7ed; }
        .priority-badge.priority-medium { color:#d4a017; background:#fff9db; }
        .priority-badge.priority-low { color:#43A047; background:#f2fbf3; }
        .priority-badge.priority-neutral { color:#64748b; background:#f8fafc; border-color:#cbd5e1; }
        .notif-keyword {
            display: inline-flex;
            align-items: center;
            padding: 0.08rem 0.45rem;
            border-radius: 999px;
            font-size: 0.83em;
            font-weight: 700;
            line-height: 1.2;
            margin: 0 0.08rem;
            vertical-align: baseline;
        }
        .notif-keyword-success {
            background: #dcfce7;
            color: #166534;
        }
        .notif-keyword-info {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .notif-keyword-assign {
            background: #e0f2fe;
            color: #0284c7;
        }
        .notif-keyword-reassign {
            background: #f3e8ff;
            color: #7e22ce;
        }
        .notif-keyword-generic {
            background: #e2e8f0;
            color: #475569;
        }
        .notif-date {
            font-size: 0.86rem;
            color: #94a3b8;
        }
        .notif-chat-pending .notif-date {
            font-size: 0.86rem;
            color: #94a3b8;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px;
            flex-wrap: wrap;
        }
        .page-link {
            min-width: 40px;
            height: 40px;
            padding: 0 15px;
            border: 1px solid #d7e2ea;
            border-radius: 999px;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            transition: all 0.2s ease;
        }
        .page-link:hover {
            background: #f8fafc;
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }
        .page-link.active {
            background-color: #166534;
            color: white;
            border-color: #166534;
            box-shadow: 0 10px 18px rgba(22, 101, 52, 0.22);
        }
        .page-link.prev,
        .page-link.next {
            min-width: 110px;
            padding: 0 18px;
        }
        .page-link.disabled {
            opacity: 0.45;
            pointer-events: none;
            box-shadow: none;
        }
        .mark-read-btn {
            background: none;
            border: none;
            color: #16a34a;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .mark-read-btn:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            body.employee-notifications-page #navbarCollapse,
            body.employee-notifications-page.sidebar-open #navbarCollapse {
                display: none !important;
            }

            body.employee-notifications-page.sidebar-open .tm-global-chat-fab {
                opacity: 0;
                pointer-events: none;
                transform: translateY(8px);
            }

            body.employee-notifications-page .mobile-sidebar {
                position: fixed;
                top: 0;
                right: -260px;
                width: 260px;
                height: 100vh;
                background: #1B5E20;
                padding: 20px;
                transition: right 0.3s ease;
                z-index: 2000;
                display: flex;
                flex-direction: column;
                gap: 18px;
                box-shadow: 12px 0 28px rgba(15, 23, 42, 0.25);
            }

            body.employee-notifications-page .mobile-sidebar.active {
                right: 0;
            }

            body.employee-notifications-page .mobile-sidebar-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            body.employee-notifications-page .mobile-sidebar-header img {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #ffffff;
                padding: 4px;
                object-fit: contain;
                flex: 0 0 36px;
            }

            body.employee-notifications-page .mobile-sidebar-header span {
                color: #ffffff;
                font-size: 15px;
                font-weight: 700;
                line-height: 1.2;
            }

            body.employee-notifications-page .mobile-sidebar a {
                color: white;
                text-decoration: none;
                font-size: 16px;
                font-weight: 500;
                min-height: 44px;
                display: flex;
                align-items: center;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-notifications-page .mobile-sidebar a.active,
            body.employee-notifications-page .mobile-sidebar a:hover {
                background: rgba(255, 255, 255, 0.12);
            }

            body.employee-notifications-page .mobile-sidebar-footer {
                margin-top: auto;
                padding-top: 14px;
                border-top: 1px solid rgba(255, 255, 255, 0.18);
                display: flex;
                align-items: center;
                gap: 12px;
            }

            body.employee-notifications-page .mobile-sidebar-icon-link,
            body.employee-notifications-page .mobile-sidebar-user-btn {
                min-height: 44px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.28);
                color: #ffffff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
            }

            body.employee-notifications-page .mobile-sidebar-icon-link {
                width: 44px;
                min-width: 44px;
                position: relative;
            }

            body.employee-notifications-page .mobile-sidebar-icon-link i,
            body.employee-notifications-page .mobile-sidebar-user-btn i {
                font-size: 16px;
            }

            body.employee-notifications-page .mobile-sidebar-badge {
                position: absolute;
                top: -6px;
                right: -4px;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                border-radius: 999px;
                background: #ff4d4f;
                color: #ffffff;
                font-size: 11px;
                font-weight: 800;
                display: none;
                align-items: center;
                justify-content: center;
                line-height: 1;
                border: 2px solid #1B5E20;
            }

            body.employee-notifications-page .mobile-sidebar-user {
                position: relative;
            }

            body.employee-notifications-page .mobile-sidebar-user-btn {
                gap: 10px;
                padding: 0 16px;
                cursor: pointer;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu {
                position: absolute;
                right: 0;
                bottom: calc(100% + 10px);
                min-width: 170px;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 16px 30px rgba(15, 23, 42, 0.18);
                padding: 8px;
                display: none;
                flex-direction: column;
                gap: 4px;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu.show {
                display: flex;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu a {
                min-height: 40px;
                color: #0f172a;
                font-size: 14px;
                font-weight: 600;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu a:hover {
                background: #f1f5f9;
            }

            body.employee-notifications-page .mobile-sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.4);
                opacity: 0;
                visibility: hidden;
                transition: 0.3s;
                z-index: 1500;
                display: block;
            }

            body.employee-notifications-page .mobile-sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            body.employee-notifications-page .nav-left,
            body.employee-notifications-page .navbar-toggler {
                position: relative;
                z-index: 2105;
            }

            body.employee-notifications-page .tm-global-chat-fab {
                right: 12px;
                bottom: 12px;
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
                padding: 0 !important;
                border-radius: 999px;
                gap: 0;
                flex: 0 0 42px;
                justify-content: center;
            }

            body.employee-notifications-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-notifications-page .tm-global-chat-fab i {
                font-size: 16px;
            }

            body.employee-notifications-page .content-wrapper {
                max-width: none;
            }

            .page-header {
                gap: 12px;
                align-items: flex-start !important;
                flex-direction: column;
            }

            .notif-item-row {
                padding: 14px 16px;
                gap: 12px;
            }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body class="employee-notifications-page">

    <?php include '../includes/employee_navbar.php'; ?>

    <div id="mobileSidebar" class="mobile-sidebar" aria-hidden="true">
        <div class="mobile-sidebar-header">
            <img src="../assets/img/UPDATEDlogo.png" alt="Logo">
            <span>Leads Agri</span>
        </div>
        <a href="dashboard.php">Dashboard</a>
        <a href="request_ticket.php">Create Ticket</a>
        <a href="my_task.php">Tickets</a>
        <a href="my_tickets.php">My Tickets</a>
        <a href="feedback.php">Feedback</a>
        <a href="knowledge_base.php">Knowledge Base</a>
        <div class="mobile-sidebar-footer">
            <a href="notifications.php" class="mobile-sidebar-icon-link" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span id="mobileSidebarNotifBadge" class="mobile-sidebar-badge"></span>
            </a>
            <div class="mobile-sidebar-user">
                <button type="button" id="mobileSidebarUserBtn" class="mobile-sidebar-user-btn" aria-label="Account menu">
                    <i class="fas fa-user"></i>
                    <i class="fas fa-chevron-down" style="font-size: 11px;"></i>
                </button>
                <div id="mobileSidebarUserMenu" class="mobile-sidebar-user-menu">
                    <a href="my_profile.php">My Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div id="mobileSidebarOverlay" class="mobile-sidebar-overlay" aria-hidden="true"></div>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success" style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h1 class="page-title">Notifications</h1>
                <?php if($total > 0): ?>
                <form method="POST" style="display: inline;">
                    <?php echo csrf_field(); ?>
                    <button type="submit" name="mark_all_read" class="mark-read-btn">
                        <i class="fas fa-check-double"></i> Mark all as read
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <div class="notif-list-page">
                <?php if($result->num_rows > 0): ?>
                        <?php $currentSection = null; ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <?php
                            $sectionLabel = notif_section_label((string) ($row['created_at'] ?? 'now'));
                            $typeJs = (string) ($row['type'] ?? '');
                            $priorityKey = $typeJs === 'priority_escalated'
                                ? notif_priority_from_message((string) ($row['message'] ?? ''))
                                : '';
                            if ($typeJs !== 'priority_escalated' && $priorityKey === '' && !empty($row['priority'])) {
                                $p = strtolower((string) $row['priority']);
                                if (in_array($p, ['critical', 'high', 'medium', 'low'], true)) {
                                    $priorityKey = $p;
                                }
                            }
                            $priorityClass = $priorityKey !== '' ? 'priority-' . $priorityKey : 'priority-neutral';
                            $priorityLabel = $priorityKey !== ''
                                ? '<span class="priority-badge ' . $priorityClass . '">' . htmlspecialchars(ucfirst($priorityKey), ENT_QUOTES, 'UTF-8') . '</span>'
                                : '';
                            $iconClass = 'fa-sticky-note';
                            $bgClass = '#e2e8f0';
                            $colorClass = '#64748b';
                            $accentColor = '#cbd5e1';
                            $dotColor = '#94a3b8';
                            $ticketIdJs = isset($row['ticket_id']) && $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
                            $actionType = notif_normalize_action_type((string) ($row['action_type'] ?? ''), $typeJs);
                            if ($priorityKey === 'critical') {
                                $iconClass = 'fa-exclamation';
                                $bgClass = 'linear-gradient(135deg, #ef4444, #dc2626)';
                                $colorClass = '#ffffff';
                                $accentColor = '#E53935';
                                $dotColor = '#E53935';
                            } elseif ($priorityKey === 'high') {
                                $iconClass = 'fa-plus';
                                $bgClass = 'linear-gradient(135deg, #fbbf24, #f59e0b)';
                                $colorClass = '#ffffff';
                                $accentColor = '#FB8C00';
                                $dotColor = '#FB8C00';
                            } elseif ($priorityKey === 'low') {
                                $iconClass = 'fa-check';
                                $bgClass = 'linear-gradient(135deg, #58b368, #43A047)';
                                $colorClass = '#ffffff';
                                $accentColor = '#43A047';
                                $dotColor = '#43A047';
                            } else {
                                switch($actionType) {
                                    case 'update':
                                        if ($typeJs === 'note_added') {
                                            $iconClass = 'fa-sticky-note';
                                            $bgClass = 'linear-gradient(135deg, #fcd34d, #f59e0b)';
                                            $colorClass = '#ffffff';
                                            $accentColor = '#ca8a04';
                                            $dotColor = '#ca8a04';
                                        } else {
                                            $iconClass = 'fa-rotate';
                                            $bgClass = 'linear-gradient(135deg, #60a5fa, #2563eb)';
                                            $colorClass = '#ffffff';
                                            $accentColor = '#2563eb';
                                            $dotColor = '#2563eb';
                                        }
                                        break;
                                    case 'close':
                                        $iconClass = 'fa-check';
                                        $bgClass = 'linear-gradient(135deg, #58b368, #43A047)';
                                        $colorClass = '#ffffff';
                                        $accentColor = '#43A047';
                                        $dotColor = '#43A047';
                                        break;
                                    case 'reassign':
                                        $iconClass = 'fa-right-left';
                                        $bgClass = 'linear-gradient(135deg, #b77cf5, #9333ea)';
                                        $colorClass = '#ffffff';
                                        $accentColor = '#9333ea';
                                        $dotColor = '#9333ea';
                                        break;
                                    case 'assign':
                                        $iconClass = 'fa-inbox';
                                        $bgClass = 'linear-gradient(135deg, #60a5fa, #2563eb)';
                                        $colorClass = '#ffffff';
                                        $accentColor = '#2563eb';
                                        $dotColor = '#2563eb';
                                        break;
                                }
                                if ($typeJs === 'follow_up') {
                                    $iconClass = 'fa-rotate';
                                    $bgClass = 'linear-gradient(135deg, #f8e08c, #f4c542)';
                                    $colorClass = '#7c4a03';
                                    $accentColor = '#d4a017';
                                    $dotColor = '#d4a017';
                                } elseif ($typeJs === 'hr_chat_pending') {
                                    $iconClass = 'fa-comments';
                                    $bgClass = 'linear-gradient(135deg, #2f8f44, #1B5E20)';
                                    $colorClass = '#ffffff';
                                    $accentColor = '#1B5E20';
                                    $dotColor = '#1B5E20';
                                }
                            }
                            $displayMessage = notif_display_message($typeJs, (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
                                $isFollowUp = $typeJs === 'follow_up';
                                $titleText = 'Ticket Update';
                                if ($priorityKey === 'critical') $titleText = 'Priority Escalation';
                                elseif ($priorityKey === 'high') $titleText = 'Ticket Warning';
                                elseif ($typeJs === 'conference_booking_deleted') $titleText = 'Conference Booking Deleted';
                            elseif ($actionType === 'assign') $titleText = 'Ticket Assigned';
                            elseif ($actionType === 'reassign') $titleText = 'Ticket Reassigned';
                            elseif ($actionType === 'close') $titleText = 'Ticket Closed';
                                elseif ($typeJs === 'hr_chat_pending') $titleText = 'Pending Chat';
                                elseif ($actionType === 'update' && $typeJs === 'note_added') $titleText = 'Ticket Note';
                                elseif ($actionType === 'update') $titleText = 'Status Update';
                                if ($isFollowUp) {
                                    $iconClass = 'fa-rotate';
                                    $bgClass = 'linear-gradient(135deg, #f8e08c, #f4c542)';
                                    $colorClass = '#7c4a03';
                                    $accentColor = '#d4a017';
                                    $dotColor = '#d4a017';
                                    $priorityLabel = '';
                                    $titleText = 'Follow Up Request';
                                }
                        ?>
                        <?php if ($sectionLabel !== $currentSection): ?>
                            <?php if ($currentSection !== null): ?>
                                </div>
                            <?php endif; ?>
                            <div class="notif-section-label"><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="notif-section-card">
                            <?php $currentSection = $sectionLabel; ?>
                        <?php endif; ?>
                        <div class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?> <?= $typeJs === 'hr_chat_pending' ? 'notif-chat-pending' : '' ?> <?= $typeJs === 'follow_up' ? 'notif-follow-up' : '' ?>"
                             style="--notif-accent: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>; --notif-dot: <?= htmlspecialchars($dotColor, ENT_QUOTES, 'UTF-8') ?>;"
                             role="button"
                             tabindex="0"
                             data-notification-id="<?= (int) $row['id'] ?>"
                             data-ticket-id="<?= $ticketIdJs === null ? '' : (int) $ticketIdJs ?>"
                             data-notification-type="<?= htmlspecialchars($typeJs, ENT_QUOTES, 'UTF-8') ?>"
                             onclick="openEmployeeNotification(this)"
                             onkeydown="handleEmployeeNotificationKey(event, this)">
                            <div class="notif-content">
                                <div class="notif-title-row">
                                    <?php if ($typeJs === 'hr_chat_pending'): ?>
                                        <span class="notif-chat-pill"><i class="fas fa-comments"></i></span>
                                    <?php elseif ($typeJs === 'follow_up'): ?>
                                        <span class="notif-chat-pill notif-follow-pill"><i class="fas fa-rotate"></i><span>Follow Up</span></span>
                                    <?php else: ?>
                                        <div class="notif-icon" style="background: <?= htmlspecialchars($bgClass, ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8') ?>;">
                                            <i class="fas <?= $iconClass ?>"></i>
                                        </div>
                                        <?= $priorityLabel ?>
                                    <?php endif; ?>
                                    <span class="notif-title"><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="notif-text"><?= notif_message_highlight_html($displayMessage) ?></div>
                                <div class="notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    <?php if ($currentSection !== null): ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="padding: 40px; text-align: center; color: #94a3b8;">
                        <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                        <p>No notifications found.</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if($total_pages > 1): ?>
            <div class="pagination">
                <a href="?page=<?= max(1, $page - 1) ?>" class="page-link prev <?= ($page <= 1) ? 'disabled' : '' ?>">&lsaquo; Previous</a>
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages, $page + 1) ?>" class="page-link next <?= ($page >= $total_pages) ? 'disabled' : '' ?>">Next &rsaquo;</a>
            </div>
            <?php endif; ?>

        </div>
    </div>

<script>
// Auto-update relative times every 60 seconds
function toRelative(ts) {
    const now = new Date();
    const then = new Date(ts.replace(' ', 'T'));
    const diff = Math.max(0, Math.floor((now - then) / 1000)); // seconds
    if (diff < 10) return 'Just now';
    if (diff < 60) return `${diff}s ago`;
    const m = Math.floor(diff / 60);
    if (m < 60) return `${m} minute${m === 1 ? '' : 's'} ago`;
    const h = Math.floor(diff / 3600);
    if (h < 24) return `${h} hour${h === 1 ? '' : 's'} ago`;
    const d = Math.floor(diff / 86400);
    return `${d} day${d === 1 ? '' : 's'} ago`;
}
function updateRelativeTimesList() {
    document.querySelectorAll('.notif-date[data-timestamp]').forEach(el => {
        const ts = el.getAttribute('data-timestamp');
        el.textContent = toRelative(ts);
    });
}
document.addEventListener('DOMContentLoaded', function() {
    updateRelativeTimesList();
    setInterval(updateRelativeTimesList, 60000);

    const menuBtn = document.getElementById('navbarToggler');
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    const mobileUserBtn = document.getElementById('mobileSidebarUserBtn');
    const mobileUserMenu = document.getElementById('mobileSidebarUserMenu');
    const desktopNotifBadge = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileSidebarNotifBadge');

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
        if (mobileUserMenu) mobileUserMenu.classList.remove('show');
        sidebar.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function syncMobileNotifBadge() {
        if (!desktopNotifBadge || !mobileNotifBadge) return;
        const desktopText = (desktopNotifBadge.textContent || '').trim();
        const desktopVisible = desktopNotifBadge.style.display !== 'none' && desktopText !== '';
        mobileNotifBadge.textContent = desktopText;
        mobileNotifBadge.style.display = desktopVisible ? 'inline-flex' : 'none';
    }

    if (menuBtn && sidebar && overlay) {
        menuBtn.addEventListener('click', function (event) {
            if (window.innerWidth > 768) return;
            event.preventDefault();
            event.stopPropagation();
            const shouldOpen = !sidebar.classList.contains('active');
            sidebar.classList.toggle('active', shouldOpen);
            overlay.classList.toggle('active', shouldOpen);
            document.body.classList.toggle('sidebar-open', shouldOpen);
            sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
            overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
        });

        overlay.addEventListener('click', function () {
            if (window.innerWidth > 768) return;
            closeSidebar();
        });

        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth > 768) return;
                closeSidebar();
            });
        });

        if (mobileUserBtn && mobileUserMenu) {
            mobileUserBtn.addEventListener('click', function (event) {
                if (window.innerWidth > 768) return;
                event.stopPropagation();
                mobileUserMenu.classList.toggle('show');
            });

            document.addEventListener('click', function (event) {
                if (window.innerWidth > 768) return;
                if (!mobileUserMenu.contains(event.target) && !mobileUserBtn.contains(event.target)) {
                    mobileUserMenu.classList.remove('show');
                }
            });
        }

        syncMobileNotifBadge();
        if (desktopNotifBadge && typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(syncMobileNotifBadge);
            observer.observe(desktopNotifBadge, { attributes: true, childList: true, subtree: true });
        }
    }
});

function employeeNotificationTargetUrl(ticketId, type) {
    const notifType = String(type || '');
    if (!ticketId) {
        return 'notifications.php';
    }
    if (notifType === 'hr_chat_pending') {
        return `my_task.php?ticket_id=${ticketId}&chat=1`;
    }
    const taskTypes = new Set(['dept_assigned', 'reassigned', 'priority_escalated', 'new_ticket', 'follow_up', 'hr_chat_pending']);
    return taskTypes.has(notifType)
        ? `my_task.php?ticket_id=${ticketId}`
        : `my_tickets.php?ticket_id=${ticketId}`;
}

function openEmployeeNotification(element) {
    if (!element) return;

    const id = parseInt(element.getAttribute('data-notification-id') || '0', 10) || 0;
    const ticketId = parseInt(element.getAttribute('data-ticket-id') || '0', 10) || 0;
    const type = element.getAttribute('data-notification-type') || '';

    if (typeof window.markAsRead === 'function') {
        window.markAsRead(id, ticketId || null, type);
        return;
    }

    const targetUrl = employeeNotificationTargetUrl(ticketId, type);
    const csrfToken = (window.TM_CSRF_TOKEN || '').toString();
    const body = 'id=' + encodeURIComponent(String(id)) + (csrfToken ? ('&csrf_token=' + encodeURIComponent(csrfToken)) : '');

    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).finally(() => {
        window.location.href = targetUrl;
    });
}

function handleEmployeeNotificationKey(event, element) {
    if (!event) return;
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openEmployeeNotification(element);
    }
}
</script>
<script src="../js/employee-dashboard.js"></script>
</body>
</html>
