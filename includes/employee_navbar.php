<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/user_permissions.php';
$csrfToken = csrf_token();

$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$user_email = 'Account';
$tmUserPermissions = user_permissions_defaults();

if ($user_id > 0 && isset($conn)) {
    user_permissions_ensure_table($conn);
    $tmUserPermissions = user_permissions_get_for_user($conn, $user_id);
    $user_query = $conn->query("SELECT email FROM users WHERE id = $user_id");
    if ($user_query && $user_query->num_rows > 0) {
        $user_email = $user_query->fetch_assoc()['email'];
    }
}

// Helper to check active link
function isActive($page) {
    $current = basename($_SERVER['PHP_SELF']);
    // Handle main pages
    if ($current == $page) {
        return 'active';
    }
    // Handle sub-pages
    if ($page == 'my_tickets.php' && ($current == 'view_ticket.php' || $current == 'view_tickets_user.php')) {
        return 'active';
    }
    if ($page == 'knowledge_base.php' && $current == 'view_article.php') {
        return 'active';
    }
    if ($page == 'feedback.php' && $current == 'feedback.php') {
        return 'active';
    }
    if ($page == 'book_conference.php' && $current == 'book_conference.php') {
        return 'active';
    }
    if ($page == 'analytics.php' && $current == 'analytics.php') {
        return 'active';
    }
    return '';
}

$employeeNavItems = [
    ['key' => 'dashboard', 'page' => 'dashboard.php', 'label' => 'Dashboard'],
    ['key' => 'create_ticket', 'page' => 'request_ticket.php', 'label' => 'Create Ticket'],
    ['key' => 'all_ticket', 'page' => 'my_task.php', 'label' => 'Assigned Tickets'],
    ['key' => 'my_tickets', 'page' => 'my_tickets.php', 'label' => 'My Submitted Tickets'],
    ['key' => 'feedback', 'page' => 'feedback.php', 'label' => 'Feedback'],
    ['key' => 'knowledge_base', 'page' => 'knowledge_base.php', 'label' => 'Knowledge Base'],
    ['key' => 'conference_booking', 'page' => 'book_conference.php', 'label' => 'Conference Booking'],
    ['key' => 'analytics', 'page' => 'analytics.php', 'label' => 'Analytics'],
];
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<nav class="navbar">
    <div class="nav-left">
        <img src="../assets/img/UPDATEDlogo.png" alt="Leads Agri Logo" class="logo-icon">
        <div class="brand-name">Leads Agri Helpdesk</div>
        <button class="navbar-toggler" id="navbarToggler">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="navbar-collapse" id="navbarCollapse">
        <div class="nav-center">
            <?php foreach ($employeeNavItems as $navItem): ?>
                <?php
                    $permissionKey = (string) ($navItem['key'] ?? '');
                    $isVisible = !array_key_exists($permissionKey, $tmUserPermissions) || (int) $tmUserPermissions[$permissionKey] === 1;
                    if (!$isVisible) {
                        continue;
                    }
                ?>
                <a href="<?= htmlspecialchars((string) $navItem['page'], ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= isActive((string) $navItem['page']) ?>">
                    <?= htmlspecialchars((string) $navItem['label'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="nav-right">
            <!-- Notification Bell -->
            <div class="notification-wrapper">
                <div class="notification-bell" id="notifBell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-dot" id="notifDot" style="display: none;"></span>
                    <span class="notification-badge" id="notifBadge" style="display: none;">0</span>
                </div>
                <div class="notification-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <i class="fas fa-bell" style="color: #16a34a;"></i>
                        <span>Notifications</span>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty">No notifications</div>
                    </div>
                    <div class="notif-footer">
                        <a href="notifications.php">View All Notifications</a>
                    </div>
                </div>
            </div>

            <div class="user-menu">
                <button type="button" class="user-btn" aria-label="<?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8'); ?>" onclick="window.toggleEmployeeUserMenu && window.toggleEmployeeUserMenu(event)">
                    <i class="fas fa-user"></i>
                    <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="user-dropdown">
                    <a href="my_profile.php" class="dropdown-item">My Profile</a>
                    <a href="logout.php" class="dropdown-item">Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<div id="priorityEscalationToastHost" class="priority-escalation-toast-host" aria-live="polite" aria-atomic="true"></div>

<script>
window.TM_CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.TM_MESSENGER_STYLE = 'employee';
</script>

<button type="button" id="globalChatFab" class="tm-global-chat-fab" onclick="window.TMGlobalChat && window.TMGlobalChat.open()">
    <i class="fas fa-comments"></i>
    <span class="tm-global-chat-label">Chat</span>
    <span id="globalChatBadge" class="chat-badge"></span>
</button>

<style>
.navbar {
    border-bottom: 4px solid #F4C430;
}

/* Notification Styles */
.notification-wrapper {
    position: relative;
    margin-right: 15px;
}

.notification-bell {
    position: relative;
    cursor: pointer;
    font-size: 1.2rem;
    color: white;
    padding: 8px;
    transition: transform 0.2s;
}

.notification-bell:hover {
    transform: scale(1.1);
}

.notification-dot {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 8px;
    height: 8px;
    background-color: #ff4444;
    border-radius: 50%;
    border: 2px solid #1B5E20; /* Match navbar bg */
}

.notification-badge {
    position: absolute;
    top: -2px;
    right: -10px;
    background-color: #ff4444;
    color: white;
    font-size: 0.7rem;
    padding: 2px 5px;
    border-radius: 10px;
    font-weight: bold;
    border: 2px solid #1B5E20;
}

.notification-dropdown {
    position: absolute;
    top: 50px;
    right: -10px;
    width: 380px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    display: none;
    z-index: 1000;
    overflow: hidden;
    animation: slideDown 0.2s ease-out;
    border: none;
}

.notification-dropdown.show {
    display: block;
}

.notif-header {
    background: #fff;
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f0;
    font-weight: 700;
    font-size: 1.1rem;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.notif-list {
    max-height: 400px;
    overflow-y: auto;
    background: #fff;
}
.notif-section-label {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 10px 16px 8px;
    background: #ffffff;
    border-bottom: 1px solid #eef2f7;
    font-size: 0.9rem;
    font-weight: 500;
    color: #475569;
}

.notif-item {
    --notif-accent: transparent;
    position: relative;
    display: flex;
    align-items: flex-start;
    padding: 16px 40px 16px 26px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: all 0.2s ease;
    gap: 0;
}
.notif-item::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: var(--notif-accent);
}
.notif-item.variant-assign::before,
.notif-item.variant-close::before,
.notif-item.variant-low::before { --notif-accent: #43A047; }
.notif-item.variant-note::before,
.notif-item.variant-high::before { --notif-accent: #f59e0b; }
.notif-item.variant-critical::before { --notif-accent: #E53935; }
.notif-item.variant-update::before { --notif-accent: #2563eb; }
.notif-item.variant-reassign::before { --notif-accent: #9333ea; }

.notif-item:hover {
    background-color: #f8fafc;
}
.notif-item.notif-chat-pending {
    display: flex;
    align-items: flex-start;
    gap: 0;
    margin: 0;
    padding: 16px 40px 16px 26px;
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    border-radius: 0;
    box-shadow: none;
    background: #ffffff;
    overflow: hidden;
}
.notif-item.notif-chat-pending:hover {
    background-color: #f8fbff;
}
.notif-item.notif-chat-pending::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: var(--notif-accent, #1B5E20);
    border-radius: 0;
}
.notif-item.notif-chat-pending.unread::after {
    content: "";
    position: absolute;
    right: 18px;
    top: 50%;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #1B5E20;
    transform: translateY(-50%);
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
}

.notif-item.unread {
    background-color: #ffffff;
}
.notif-item.unread::after {
    content: "";
    position: absolute;
    right: 18px;
    top: 50%;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #5aa364;
    transform: translateY(-50%);
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
}
.notif-item.unread.variant-assign,
.notif-item.unread.variant-close,
.notif-item.unread.variant-low {
    background: #f1fbf3;
}
.notif-item.unread.variant-note,
.notif-item.unread.variant-high {
    background: #fff8ef;
}
.notif-item.unread.variant-critical {
    background: #fff4f5;
}
.notif-item.unread.variant-update {
    background: #f3f8ff;
}
.notif-item.unread.variant-reassign {
    background: #faf5ff;
}
.notif-item.unread.variant-reassign::after {
    background: #9333ea;
}
.notif-item.priority-escalation {
    position: relative;
    gap: 0;
    margin: 0;
    padding: 16px 40px 16px 26px;
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    border-radius: 0;
    background: #ffffff;
    box-shadow: none;
    overflow: hidden;
}
.notif-item.priority-escalation::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    border-radius: 0;
    background: var(--notif-accent, #94a3b8);
}
.notif-item.priority-escalation.priority-low::before { --notif-accent: #43A047; }
.notif-item.priority-escalation.priority-high::before { --notif-accent: #f59e0b; }
.notif-item.priority-escalation.priority-critical::before { --notif-accent: #E53935; }

.notif-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 11px;
    border: 2px solid currentColor;
    background: #ffffff;
    color: #64748b;
    overflow: hidden;
    min-height: 26px;
}
.notif-pill-icon {
    width: 28px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #ffffff;
    font-weight: 800;
}
.notif-pill-text {
    padding: 0 16px 0 12px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.01em;
    line-height: 1;
}
.notif-pill.variant-assign,
.notif-pill.variant-close,
.notif-pill.variant-low {
    color: #43A047;
    background: #f9fff9;
}
.notif-pill.variant-assign .notif-pill-icon,
.notif-pill.variant-close .notif-pill-icon,
.notif-pill.variant-low .notif-pill-icon {
    background: linear-gradient(135deg, #7cd992, #43A047);
}
.notif-pill.variant-note,
.notif-pill.variant-high {
    color: #f59e0b;
    background: #fff8ef;
}
.notif-pill.variant-note .notif-pill-icon,
.notif-pill.variant-high .notif-pill-icon {
    background: linear-gradient(135deg, #fcd34d, #f59e0b);
}
.notif-pill.variant-critical {
    color: #E53935;
    background: #fff4f5;
}
.notif-pill.variant-critical .notif-pill-icon {
    background: linear-gradient(135deg, #ff7d7d, #E53935);
}
.notif-pill.variant-update {
    color: #2563eb;
    background: #f4f8ff;
}
.notif-pill.variant-update .notif-pill-icon {
    background: linear-gradient(135deg, #7db2ff, #2563eb);
}
.notif-pill.variant-reassign {
    color: #9333ea;
    background: #faf5ff;
}
.notif-pill.variant-reassign .notif-pill-icon {
    background: linear-gradient(135deg, #c084fc, #9333ea);
}
.notif-pill.variant-follow-up {
    color: #7c4a03;
    background: #fff6d8;
}
.notif-pill.variant-follow-up .notif-pill-icon {
    background: linear-gradient(135deg, #fde68a, #f59e0b);
}
.notif-pill.notif-chat-pill {
    min-height: 36px;
    min-width: 36px;
    padding: 0;
    gap: 0;
    border: 0;
    border-radius: 999px;
    color: #ffffff;
    background: #1B5E20;
    box-shadow: 0 6px 14px rgba(27, 94, 32, 0.2);
}
.notif-pill.notif-chat-pill .notif-pill-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
    background: transparent;
}
.notif-pill.notif-chat-pill .notif-pill-text {
    display: none;
}

.notif-content {
    flex: 1;
    min-width: 0;
}
.notif-item.priority-escalation .notif-content {
    padding-left: 0;
}
.notif-title {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 5px;
    flex-wrap: wrap;
}
.notif-item.notif-chat-pending .notif-title {
    gap: 10px;
    margin-bottom: 6px;
}
.notif-title-text {
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1.3;
    color: #111827;
}
.notif-item.notif-chat-pending .notif-title-text {
    font-size: 0.92rem;
    font-weight: 700;
    color: #111827;
    line-height: 1.3;
}
.notif-item.variant-follow-up {
    background: linear-gradient(180deg, #fffdf4 0%, #fff9e7 100%);
}
.notif-item.variant-follow-up::before {
    background: #f4c542;
}
.notif-item.variant-follow-up.unread::after {
    background: #f4c542;
}
.notif-item.variant-follow-up .notif-title-text {
    color: #111827;
}

.notif-msg {
    font-size: 0.92rem;
    color: #334155;
    line-height: 1.4;
    margin-bottom: 6px;
}
.notif-item.notif-chat-pending .notif-msg {
    font-size: 0.9rem;
    color: #23324f;
    line-height: 1.45;
    margin-bottom: 6px;
}
.notif-item.notif-chat-pending .notif-msg strong {
    color: #1d4f9b;
    font-weight: 700;
}
.notif-item.priority-escalation .notif-msg {
    font-size: 0.95rem;
    line-height: 1.4;
    color: #334155;
    margin-bottom: 6px;
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

.notif-time {
    font-size: 0.8rem;
    color: #94a3b8;
    display: block;
}
.notif-item.notif-chat-pending .notif-time {
    font-size: 0.82rem;
    color: #94a3b8;
    letter-spacing: 0.01em;
}
.notif-item.priority-escalation .notif-time {
    font-size: 0.8rem;
    color: #94a3b8;
}

.priority-escalation-toast-host {
    position: fixed;
    top: 82px;
    right: 18px;
    z-index: 5000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}

.priority-escalation-toast {
    min-width: 340px;
    max-width: min(460px, calc(100vw - 36px));
    background: linear-gradient(180deg, #fff7f8 0%, #ffffff 100%);
    color: #3f1d24;
    border-radius: 22px;
    box-shadow: 0 22px 48px rgba(127, 29, 29, 0.18);
    padding: 18px 18px 18px 16px;
    border-left: 6px solid #fb7185;
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 14px;
}

.priority-escalation-toast.priority-critical {
    border-left-color: #dc2626;
}

.priority-escalation-toast-icon {
    width: 64px;
    height: 64px;
    border-radius: 18px;
    background: linear-gradient(180deg, #d9465f 0%, #be123c 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.24);
}

.priority-escalation-toast-body {
    flex: 1;
    min-width: 0;
}

.priority-escalation-toast-title {
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 6px;
    color: #881337;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.priority-escalation-toast-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 58px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.01em;
    background: #fb7185;
}

.priority-escalation-toast-pill.priority-critical {
    background: #dc2626;
}

.priority-escalation-toast-pill.priority-high {
    background: #f97316;
}

.priority-escalation-toast-message {
    font-size: 15px;
    line-height: 1.5;
    color: #4c1d2a;
}

.priority-escalation-toast-dot {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: #fb7185;
    box-shadow: 0 0 0 4px rgba(251, 113, 133, 0.14);
    flex-shrink: 0;
}

.priority-escalation-toast.priority-critical .priority-escalation-toast-dot {
    background: #dc2626;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
}

.priority-escalation-toast.employee-chat-toast {
    background: linear-gradient(180deg, #f2f7ff 0%, #ffffff 100%);
    color: #173a6b;
    border-left-color: #2563eb;
    box-shadow: 0 22px 48px rgba(37, 99, 235, 0.18);
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-icon {
    background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%);
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-title {
    color: #1d4f9b;
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-pill {
    background: #2563eb;
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-message {
    color: #1f3a63;
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-dot {
    background: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.14);
}

@media (max-width: 640px) {
    .priority-escalation-toast {
        min-width: 0;
        width: calc(100vw - 24px);
        padding: 16px;
        gap: 12px;
    }

    .priority-escalation-toast-icon {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        font-size: 24px;
    }

    .priority-escalation-toast-message {
        font-size: 14px;
    }
}

.notif-footer {
    padding: 12px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
    text-align: center;
}

.notif-footer a {
    color: #16a34a;
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: color 0.2s;
}

.notif-footer a:hover {
    color: #15803d;
    text-decoration: underline;
}

.notif-empty {
    padding: 30px;
    text-align: center;
    color: #94a3b8;
    font-style: italic;
}

/* Scrollbar styling */
.notif-list::-webkit-scrollbar {
    width: 6px;
}
.notif-list::-webkit-scrollbar-track {
    background: #f1f5f9;
}
.notif-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.tm-global-chat-fab {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 2500;
    background: #1B5E20;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 12px 16px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    box-shadow: 0 12px 28px rgba(2, 6, 23, 0.25);
    user-select: none;
}
.tm-global-chat-fab:hover { background: #144a1e; }
.tm-global-chat-fab:active { transform: translateY(1px); }
.tm-global-chat-fab .tm-global-chat-label { font-size: 14px; }
.tm-global-chat-fab .chat-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #ffffff;
    font-size: 11px;
    font-weight: 900;
    display: none;
    align-items: center;
    justify-content: center;
    line-height: 1;
}
.tm-global-chat-fab .chat-badge.is-visible { display: inline-flex; }
@media (max-width: 768px) {
    .tm-global-chat-fab {
        right: 12px;
        bottom: 12px;
        width: 42px;
        height: 42px;
        min-width: 42px;
        min-height: 42px;
        padding: 0;
        border-radius: 999px;
        justify-content: center;
        gap: 0;
    }
    .tm-global-chat-fab .tm-global-chat-label { display: none; }
    .tm-global-chat-fab i { font-size: 16px; }
    .tm-global-chat-fab .chat-badge {
        top: -4px;
        right: -4px;
    }
}

/* Employee user pill (match admin style) */
.user-menu {
    position: relative;
    display: inline-block;
    z-index: 20000;
}
.user-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: #ffffff;
    padding: 8px 16px;
    border-radius: 30px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
    z-index: 20001;
    pointer-events: auto;
}
.user-btn:hover { background: rgba(255,255,255,0.25); }
.user-dropdown {
    position: absolute;
    right: 0;
    top: 50px;
    background: #ffffff;
    min-width: 200px;
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    display: none;
    flex-direction: column;
    overflow: hidden;
    z-index: 20002;
    border: 1px solid #e5e7eb;
    pointer-events: auto;
}
.user-dropdown.show { display: flex; }
body.employee-analytics-page .user-btn {
    position: relative !important;
    z-index: 20001 !important;
    pointer-events: auto !important;
}
body.employee-analytics-page .user-dropdown {
    z-index: 20002 !important;
}
.user-dropdown .dropdown-item {
    padding: 12px 16px;
    text-decoration: none;
    color: #1f2937;
    font-size: 14px;
    transition: background 0.2s;
    display: block;
    font-weight: 500;
}
.user-dropdown .dropdown-item:hover {
    background: #f9fafb;
    color: #1B5E20;
}
</style>

<script>
window.toggleEmployeeUserMenu = function(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    var userBtn = document.querySelector('.user-btn');
    var userDropdown = document.querySelector('.user-dropdown');
    var notifDropdown = document.getElementById('notifDropdown');
    if (notifDropdown) {
        notifDropdown.classList.remove('show');
    }
    if (userDropdown) {
        var willShow = !userDropdown.classList.contains('show');
        if (document.body && document.body.classList.contains('employee-analytics-page') && userBtn && willShow) {
            var rect = userBtn.getBoundingClientRect();
            userDropdown.style.position = 'fixed';
            userDropdown.style.top = Math.round(rect.bottom + 10) + 'px';
            userDropdown.style.right = Math.max(12, Math.round(window.innerWidth - rect.right)) + 'px';
            userDropdown.style.zIndex = '20002';
        } else if (!willShow) {
            userDropdown.style.position = '';
            userDropdown.style.top = '';
            userDropdown.style.right = '';
            userDropdown.style.zIndex = '';
        }
        userDropdown.classList.toggle('show');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge = document.getElementById('notifBadge');
    const dot = document.getElementById('notifDot');
    const list = document.getElementById('notifList');
    const userBtn = document.querySelector('.user-btn');
    const userDropdown = document.querySelector('.user-dropdown');
    const chatReminderToastKey = 'tm_employee_seen_hr_chat_pending_notifications';
    let chatReminderToastIds = new Set();

    try {
        const stored = sessionStorage.getItem(chatReminderToastKey);
        if (stored) {
            JSON.parse(stored).forEach((id) => {
                chatReminderToastIds.add(String(id));
            });
        }
    } catch (err) {
        chatReminderToastIds = new Set();
    }

    function persistChatReminderToastIds() {
        try {
            sessionStorage.setItem(chatReminderToastKey, JSON.stringify(Array.from(chatReminderToastIds)));
        } catch (err) {
            // Ignore storage failures; the toast will still render once per poll cycle.
        }
    }

    function showChatReminderToast(notification) {
        const host = document.getElementById('priorityEscalationToastHost');
        if (!host) return;

        const toast = document.createElement('div');
        toast.className = 'priority-escalation-toast employee-chat-toast';
        toast.innerHTML = `
            <div class="priority-escalation-toast-icon" aria-hidden="true">
                <i class="fas fa-comments"></i>
            </div>
            <div class="priority-escalation-toast-body">
                <div class="priority-escalation-toast-title">
                    <span class="priority-escalation-toast-pill">Chat</span>
                    <span>Pending Chat</span>
                </div>
                <div class="priority-escalation-toast-message">${escapeHtml(notification.message || 'You have a pending chat reply.')}</div>
            </div>
            <span class="priority-escalation-toast-dot" aria-hidden="true"></span>
        `;
        host.appendChild(toast);

        window.setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
            toast.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        }, 5600);

        window.setTimeout(() => {
            if (toast && toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 6200);
    }
    
    // Toggle dropdown
    document.addEventListener('click', function(e) {
        const btn = e.target && e.target.closest ? e.target.closest('.user-btn') : null;
        if (!btn) return;
        if (document.body && document.body.classList.contains('employee-analytics-page')) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            window.toggleEmployeeUserMenu(e);
        }
    }, true);

    if (bell && dropdown) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            if (userDropdown) userDropdown.classList.remove('show');
            dropdown.classList.toggle('show');
        });
    }

    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropdown.classList.remove('show');
            userDropdown.classList.toggle('show');
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (dropdown && bell && !dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.classList.remove('show');
        }
        if (userDropdown && userBtn && !userDropdown.contains(e.target) && !userBtn.contains(e.target)) {
            userDropdown.classList.remove('show');
        }
    });

    // Relative time helpers
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
    function updateRelativeTimes() {
        document.querySelectorAll('.notif-time[data-timestamp]').forEach(el => {
            const ts = el.getAttribute('data-timestamp');
            el.textContent = toRelative(ts);
        });
    }
    function getNotifSectionLabel(ts) {
        const value = new Date(String(ts).replace(' ', 'T'));
        if (Number.isNaN(value.getTime())) return 'Older';
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const itemDay = new Date(value.getFullYear(), value.getMonth(), value.getDate());
        const diffDays = Math.round((today - itemDay) / 86400000);
        if (diffDays <= 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        return 'Older';
    }

    // Fetch Notifications
    function fetchNotifications() {
        fetch('fetch_notifications.php?_=' + Date.now(), { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                const badge = document.getElementById('notifBadge');
                const dot = document.getElementById('notifDot');
                const list = document.getElementById('notifList');

                // Update Badge
                if (data.unread_count > 0) {
                    badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                    badge.style.display = 'block';
                    dot.style.display = 'block';
                } else {
                    badge.textContent = '';
                    badge.style.display = 'none';
                    dot.style.display = 'none';
                }

                // Update List
                if (data.notifications && data.notifications.length > 0) {
                    let currentSection = '';
                    list.innerHTML = data.notifications.map(n => {
                        const sectionLabel = getNotifSectionLabel(n.created_at);
                        const sectionHtml = sectionLabel !== currentSection
                            ? `<div class="notif-section-label">${escapeHtml(sectionLabel)}</div>`
                            : '';
                        currentSection = sectionLabel;
                        const actionType = (n.action_type || '').toString().toLowerCase() || (function (legacyType) {
                            if (legacyType === 'dept_assigned' || legacyType === 'new_ticket') return 'assign';
                            if (legacyType === 'reassigned') return 'reassign';
                            if (legacyType === 'ticket_closed') return 'close';
                            if (legacyType === 'status_update' || legacyType === 'note_added') return 'update';
                            return '';
                        })((n.type || '').toString());
                        const isPriorityEscalation = (n.type || '').toString() === 'priority_escalated';
                        const rawPriority = (n.priority || '').toString().toLowerCase();
                        const allowed = ['critical', 'high', 'medium', 'low'];
                        const priorityKey = isPriorityEscalation
                            ? escalationPriorityFromMessage(n.message)
                            : (allowed.includes(rawPriority) ? rawPriority : '');
                        const typeKey = (n.type || '').toString();
                        const titleText = getNotificationTitle(actionType, typeKey, priorityKey);
                        const isFollowUp = typeKey === 'follow_up';
                        const isChatPending = typeKey === 'hr_chat_pending';
                        let variantClass = 'variant-update';
                        let pillText = 'Updated';
                        let pillIcon = 'fa-rotate';
                        if (isChatPending) {
                            variantClass = 'variant-update';
                            pillText = 'Chat';
                            pillIcon = 'fa-comments';
                        } else if (isFollowUp) {
                            variantClass = 'variant-follow-up';
                            pillText = 'Follow Up';
                            pillIcon = 'fa-rotate';
                            accentColor = '#d4a017';
                            dotColor = '#d4a017';
                        } else if (priorityKey === 'critical') {
                            variantClass = 'variant-critical';
                            pillText = 'Critical';
                            pillIcon = 'fa-exclamation';
                        } else if (priorityKey === 'high') {
                            variantClass = 'variant-high';
                            pillText = 'High';
                            pillIcon = 'fa-plus';
                        } else if (priorityKey === 'low') {
                            variantClass = 'variant-low';
                            pillText = 'Low';
                            pillIcon = 'fa-check';
                        } else if (actionType === 'assign') {
                            variantClass = 'variant-assign';
                            pillText = 'Assigned';
                            pillIcon = 'fa-check';
                        } else if (actionType === 'reassign') {
                            variantClass = 'variant-reassign';
                            pillText = 'Reassigned';
                            pillIcon = 'fa-retweet';
                        } else if (actionType === 'close') {
                            variantClass = 'variant-close';
                            pillText = 'Closed';
                            pillIcon = 'fa-check';
                        } else if (actionType === 'update' && n.type === 'note_added') {
                            variantClass = 'variant-note';
                            pillText = 'Private Note';
                            pillIcon = 'fa-plus';
                        }
                        if (actionType === 'reassign') {
                            variantClass = 'variant-reassign';
                            pillText = 'Reassigned';
                            pillIcon = 'fa-retweet';
                        }
                        if (isChatPending && Number(n.is_read) === 0 && !chatReminderToastIds.has(String(n.id))) {
                            chatReminderToastIds.add(String(n.id));
                            persistChatReminderToastIds();
                            showChatReminderToast(n);
                        }
                        const pillHtml = `<span class="notif-pill ${variantClass} ${isChatPending ? 'notif-chat-pill' : ''}"><span class="notif-pill-icon"><i class="fas ${pillIcon}"></i></span>${isChatPending ? '' : `<span class="notif-pill-text">${escapeHtml(pillText)}</span>`}</span>`;
                        const messageHtml = `<div class="notif-title">${pillHtml}<span class="notif-title-text">${escapeHtml(titleText)}</span></div><div class="notif-msg">${highlightNotificationMessage(n.message)}</div>`;
                        return `
                            ${sectionHtml}
                            <div class="notif-item ${n.is_read == 0 ? 'unread' : ''} ${variantClass} ${isPriorityEscalation ? `priority-escalation ${variantClass}` : ''} ${isChatPending ? 'notif-chat-pending' : ''}" data-notif-id="${n.id}" data-ticket-id="${n.ticket_id}" onclick="markAsRead(${n.id}, ${n.ticket_id}, '${n.type || ''}')">
                                <div class="notif-content">
                                    ${messageHtml}
                                    <time class="notif-time" data-timestamp="${n.created_at}">${n.time_ago || ''}</time>
                                </div>
                            </div>
                        `;
                    }).join('');
                    updateRelativeTimes();
                } else {
                    list.innerHTML = '<div class="notif-empty">No notifications</div>';
                }
            })
            .catch(err => console.error('Error fetching notifications:', err));
    }
    
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return text.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function getPriorityNotificationTitle(priorityKey) {
        if (priorityKey === 'critical') return 'Priority Escalation';
        if (priorityKey === 'high') return 'Ticket Warning';
        if (priorityKey === 'low') return 'Ticket Assigned';
        return 'Ticket Update';
    }

    function getNotificationTitle(actionType, type, priorityKey) {
        if (type === 'priority_escalated') return getPriorityNotificationTitle(priorityKey);
        if (type === 'conference_booking_deleted') return 'Conference Booking Deleted';
        if (actionType === 'assign') return 'Ticket Assigned';
        if (actionType === 'reassign') return 'Ticket Reassigned';
        if (actionType === 'close') return 'Ticket Closed';
        if (type === 'follow_up') return 'Follow Up Request';
        if (type === 'hr_chat_pending') return 'Pending Chat';
        if (actionType === 'update' && type === 'note_added') return 'Ticket Note';
        if (actionType === 'update') return 'Status Update';
        return 'Ticket Update';
    }

    function escalationPriorityFromMessage(message) {
        const match = String(message || '').match(/escalated to\s+(critical|high|medium|low)\b/i);
        return match ? String(match[1] || '').toLowerCase() : '';
    }

    function highlightNotificationMessage(text) {
        const safe = escapeHtml(text);
        return safe.replace(/\b(in progress|reassigned|assigned|resolved|closed|open)\b/gi, (match) => {
            const token = match.toLowerCase().replace(/\s+/g, ' ').trim();
            let className = 'notif-keyword-generic';
            if (token === 'resolved' || token === 'closed') {
                className = 'notif-keyword-success';
            } else if (token === 'in progress' || token === 'open') {
                className = 'notif-keyword-info';
            } else if (token === 'assigned') {
                className = 'notif-keyword-assign';
            } else if (token === 'reassigned') {
                className = 'notif-keyword-reassign';
            }
            return `<span class="notif-keyword ${className}">${match}</span>`;
        });
    }

    // Mark as Read & Redirect
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    window.TM_CSRF_TOKEN = CSRF_TOKEN;
    window.markAsRead = function(id, ticketId, type) {
        // Send request to mark as read
        const body = 'id=' + encodeURIComponent(String(id)) + (CSRF_TOKEN ? ('&csrf_token=' + encodeURIComponent(String(CSRF_TOKEN))) : '');
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(() => {
            if (!ticketId) {
                window.location.href = 'notifications.php';
                return;
            }
            const notifType = String(type || '');
            if (notifType === 'hr_chat_pending') {
                window.location.href = `my_task.php?ticket_id=${ticketId}&chat=1`;
                return;
            }
            const taskTypes = new Set(['dept_assigned', 'reassigned', 'priority_escalated', 'new_ticket', 'follow_up', 'hr_chat_pending']);
            if (taskTypes.has(notifType)) {
                window.location.href = `my_task.php?ticket_id=${ticketId}`;
            } else {
                window.location.href = `my_tickets.php?ticket_id=${ticketId}`;
            }
        });
    };

    // Initial fetch and poll every 5 seconds
    fetchNotifications();
    setInterval(fetchNotifications, 5000);
    // Also refresh relative timestamps every 60s
    setInterval(updateRelativeTimes, 60000);

    function setGlobalChatBadge(n) {
        const badge = document.getElementById('globalChatBadge');
        if (!badge) return;
        const count = Math.max(0, parseInt(String(n || 0), 10) || 0);
        if (count <= 0) {
            badge.classList.remove('is-visible');
            badge.textContent = '';
            return;
        }
        badge.classList.add('is-visible');
        badge.textContent = count > 99 ? '99+' : String(count);
    }

    function fetchChatUnreadTotal() {
        const formData = new FormData();
        formData.append('action', 'conversations');
        if (window.TM_CSRF_TOKEN) formData.append('csrf_token', String(window.TM_CSRF_TOKEN));
        const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
        if (window.TM_CSRF_TOKEN) headers['X-CSRF-Token'] = String(window.TM_CSRF_TOKEN);
        fetch('chat_fetch.php', { method: 'POST', body: formData, headers: headers })
            .then(r => r.text())
            .then(txt => {
                let data = null;
                try { data = JSON.parse(txt); } catch (e) { return; }
                if (data && data.error) return;
                const items = Array.isArray(data) ? data : [];
                let total = 0;
                items.forEach(c => {
                    const unreadValue = c && c.unread_count_raw != null ? c.unread_count_raw : (c && c.unread_count != null ? c.unread_count : 0);
                    const u = parseInt(String(unreadValue), 10) || 0;
                    total += Math.max(0, u);
                });
                setGlobalChatBadge(total);
            })
            .catch(() => {});
    }
    window.TMRefreshGlobalChatBadge = fetchChatUnreadTotal;

    function ensureTicketModalScript() {
        if (window.TMTicketModal) return;
        if (document.getElementById('tmTicketModalScript')) return;
        const s = document.createElement('script');
        s.id = 'tmTicketModalScript';
        s.src = '../js/ticket-modal.js?v=' + Date.now();
        document.body.appendChild(s);
    }

    window.TMGlobalChat = {
        open: function() {
            ensureTicketModalScript();
            const tryOpen = function(attempt) {
                if (window.TMTicketModal && typeof window.TMTicketModal.openMessengerChat === 'function') {
                    window.TMTicketModal.openMessengerChat();
                    return;
                }
                if (attempt >= 20) return;
                setTimeout(function() { tryOpen(attempt + 1); }, 50);
            };
            tryOpen(0);
        }
    };

    fetchChatUnreadTotal();
    setInterval(fetchChatUnreadTotal, 3000);
});
</script>
