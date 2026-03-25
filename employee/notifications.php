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
    SELECT n.*
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
            background: white;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        .notif-item-row {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }
        .notif-item-row:hover {
            background-color: #f8fafc;
        }
        .notif-item-row.unread {
            background-color: #f0fdf4;
            border-left: 4px solid #16a34a;
        }
        .notif-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .notif-content {
            flex-grow: 1;
        }
        .notif-text {
            font-size: 0.95rem;
            color: #334155;
            margin-bottom: 4px;
        }
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
            font-size: 0.8rem;
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
        <a href="my_task.php">Task</a>
        <a href="my_tickets.php">My Tickets</a>
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
                    <?php while($row = $result->fetch_assoc()): ?>
                        <?php
                            $iconClass = 'fa-info-circle';
                            $bgClass = '#e2e8f0';
                            $colorClass = '#64748b';
                            $ticketIdJs = isset($row['ticket_id']) && $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
                            $typeJs = (string) ($row['type'] ?? '');
                            $actionType = notif_normalize_action_type((string) ($row['action_type'] ?? ''), $typeJs);
                            
                            switch($actionType) {
                                case 'update':
                                    if ($typeJs === 'note_added') {
                                        $iconClass = 'fa-sticky-note';
                                        $bgClass = '#fef9c3';
                                        $colorClass = '#ca8a04';
                                    } else {
                                        $iconClass = 'fa-sync-alt';
                                        $bgClass = '#dbeafe';
                                        $colorClass = '#2563eb';
                                    }
                                    break;
                                case 'close':
                                    $iconClass = 'fa-check-circle';
                                    $bgClass = '#dcfce7';
                                    $colorClass = '#16a34a';
                                    break;
                                case 'reassign':
                                    $iconClass = 'fa-exchange-alt';
                                    $bgClass = '#f3e8ff';
                                    $colorClass = '#9333ea';
                                    break;
                                case 'assign':
                                    $iconClass = 'fa-inbox';
                                    $bgClass = '#e0f2fe';
                                    $colorClass = '#0284c7';
                                    break;
                                default:
                                    $iconClass = 'fa-sticky-note';
                                    $bgClass = '#e2e8f0';
                                    $colorClass = '#64748b';
                                    break;
                            }
                            $displayMessage = notif_display_message($typeJs, (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
                        ?>
                        <div class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?>" 
                             onclick="markAsRead(<?= (int) $row['id'] ?>, <?= json_encode($ticketIdJs) ?>, <?= json_encode($typeJs) ?>)">
                            <div class="notif-icon" style="background-color: <?= $bgClass ?>; color: <?= $colorClass ?>;">
                                <i class="fas <?= $iconClass ?>"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-text"><?= notif_message_highlight_html($displayMessage) ?></div>
                                <div class="notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                            </div>
                            <?php if($row['is_read'] == 0): ?>
                                <div style="width: 8px; height: 8px; background: #16a34a; border-radius: 50%;"></div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
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
</script>
<script src="../js/employee-dashboard.js"></script>
</body>
</html>
