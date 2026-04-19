<?php
// Get current page for active link
$current_page = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/csrf.php';
$csrfToken = csrf_token();
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<header class="admin-navbar">
    <div class="admin-navbar-left">
        <img src="../assets/img/UPDATEDlogo.png" alt="Logo" class="admin-logo-img">
        <div>
            <div class="admin-logo-text-main">Leads Agri Helpdesk</div>
            <div class="admin-logo-text-sub">Admin</div>
        </div>
    </div>

    <button class="admin-navbar-toggle" id="adminNavbarToggle" aria-label="Toggle navigation" style="display:none;">
        <span></span><span></span><span></span>
    </button>

    <nav class="admin-navbar-center" id="adminNavbarCenter">
        <a href="dashboard.php" class="admin-nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="all_tickets.php" class="admin-nav-link <?= ($current_page == 'all_tickets.php' || $current_page == 'view_ticket.php') ? 'active' : '' ?>">All Tickets</a>
        <a href="analytics.php" class="admin-nav-link <?= $current_page == 'analytics.php' ? 'active' : '' ?>">Analytics</a>
        <a href="create_admin.php" class="admin-nav-link <?= ($current_page == 'create_admin.php' || $current_page == 'manage_users.php') ? 'active' : '' ?>">Admin Management</a>
        <a href="conference_bookings.php" class="admin-nav-link <?= ($current_page == 'conference_bookings.php' || $current_page == 'manage_rooms.php') ? 'active' : '' ?>">Conference Bookings</a>
        <a href="manage_kb.php" class="admin-nav-link <?= ($current_page == 'manage_kb.php' || $current_page == 'edit_kb.php' || $current_page == 'add_kb.php') ? 'active' : '' ?>">Knowledge Base</a>
    </nav>

    <div class="admin-navbar-right">
        <!-- Notification Bell -->
        <div class="notification-wrapper">
            <div class="notification-bell" id="notifBell" onclick="toggleNotifications()">
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
                    <div class="notif-empty">No new notifications</div>
                </div>
                <div class="notif-footer">
                    <a href="notifications.php">View All Notifications</a>
                </div>
            </div>
        </div>

        <div class="admin-user-dropdown">
            <button type="button" class="admin-user-pill" aria-label="<?= htmlspecialchars($_SESSION['email'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>" aria-expanded="false">
                <span class="admin-user-icon"><i class="fas fa-user"></i></span>
                <span class="admin-arrow"><i class="fas fa-chevron-down" style="font-size: 10px;"></i></span>
            </button>
            <div class="admin-dropdown-menu">
                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </div>
</header>

<div id="priorityEscalationToastHost" class="priority-escalation-toast-host" aria-live="polite" aria-atomic="true"></div>

<script>
window.TM_CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.TM_HIDE_ADMIN_CHAT = true;
</script>

<button type="button" id="globalChatFab" class="tm-global-chat-fab" onclick="window.TMGlobalChat && window.TMGlobalChat.open()" hidden aria-hidden="true">
    <i class="fas fa-comments"></i>
    <span class="tm-global-chat-label">Chat</span>
    <span id="globalChatBadge" class="chat-badge"></span>
</button>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('adminNavbarToggle');
    const navCenter = document.getElementById('adminNavbarCenter');

    if (toggleBtn && navCenter) {
        toggleBtn.addEventListener('click', function() {
            navCenter.classList.toggle('show');
        });
    }
});
</script>

<style>
/* Notification Styles */
.admin-navbar {
    position: relative;
    z-index: 4000;
}
.notification-wrapper {
    position: relative;
    margin-right: 15px;
    z-index: 4005;
}
.notification-bell {
    position: relative;
    cursor: pointer;
    font-size: 1.2rem;
    color: white; /* Assuming admin navbar has dark background */
    padding: 8px;
    transition: transform 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
    z-index: 2606;
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
    display: none;
    position: absolute;
    top: 50px;
    right: 0;
    width: 380px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    z-index: 4010;
    overflow: hidden;
    color: #333;
    text-align: left;
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
.notif-item.variant-booking::before { --notif-accent: #0f766e; }
.notif-item.variant-reassign::before { --notif-accent: #9333ea; }
.notif-item.notif-chat-pending::before { --notif-accent: #1B5E20; }
.notif-item:hover {
    background-color: #f8fafc;
}
.notif-item.notif-chat-pending.unread::after {
    background: #1B5E20;
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
.notif-item.unread.variant-booking {
    background: #f0fdfa;
}
.notif-item.unread.variant-reassign {
    background: #faf5ff;
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
.notif-pill.variant-booking {
    color: #0f766e;
    background: #f0fdfa;
}
.notif-pill.variant-booking .notif-pill-icon {
    background: linear-gradient(135deg, #34d399, #0f766e);
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
.notif-title-text {
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1.3;
    color: #111827;
}
.notif-msg {
    font-size: 0.92rem;
    color: #334155;
    line-height: 1.4;
    margin-bottom: 6px;
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
body .tm-global-chat-fab,
#globalChatFab {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
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
    .tm-global-chat-fab { right: 16px; bottom: 16px; padding: 12px 14px; }
    .tm-global-chat-fab .tm-global-chat-label { display: none; }
}
</style>

<script>
(function () {
    window.TM_CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
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
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userButton = document.querySelector('.admin-user-pill');
    const userMenu = document.querySelector('.admin-dropdown-menu');

    function closeUserMenu() {
        if (!userButton || !userMenu) return;
        userMenu.classList.remove('show');
        userButton.setAttribute('aria-expanded', 'false');
    }

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
                    const u = parseInt(String((c && c.unread_count != null) ? c.unread_count : 0), 10) || 0;
                    total += Math.max(0, u);
                });
                setGlobalChatBadge(total);
            })
            .catch(() => {});
    }

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
    // Mobile navbar toggle
    const adminToggle = document.getElementById('adminNavbarToggle');
    const adminCenter = document.getElementById('adminNavbarCenter');
    if (adminToggle && adminCenter) {
        adminToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            adminCenter.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!adminCenter.contains(e.target) && !adminToggle.contains(e.target)) {
                adminCenter.classList.remove('show');
            }
        });
    }

    if (userButton && userMenu) {
        userButton.addEventListener('click', function(e) {
            e.stopPropagation();
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) {
                notifDropdown.classList.remove('show');
            }
            const isOpen = userMenu.classList.toggle('show');
            userButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    fetchAdminNotifications();
    setInterval(fetchAdminNotifications, 5000);
    setInterval(updateRelativeTimes, 60000);

    fetchChatUnreadTotal();
    setInterval(fetchChatUnreadTotal, 7000);

    const notifDropdown = document.getElementById('notifDropdown');
    const notifList = document.getElementById('notifList');
    if (notifDropdown) {
        notifDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
    if (notifList) {
        notifList.addEventListener('click', function(e) {
            const item = e.target.closest('.notif-item[data-notif-id]');
            if (!item) return;
            e.preventDefault();
            e.stopPropagation();
            handleNotificationClick(
                Number(item.getAttribute('data-notif-id') || 0),
                Number(item.getAttribute('data-ticket-id') || 0),
                item.getAttribute('data-notification-type') || ''
            );
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.querySelector('.notification-wrapper');
        const dropdown = document.getElementById('notifDropdown');
        if (wrapper && !wrapper.contains(e.target)) {
            dropdown.classList.remove('show');
        }
        if (userButton && userMenu && !userButton.contains(e.target) && !userMenu.contains(e.target)) {
            closeUserMenu();
        }
    });
});

function toggleNotifications() {
    const userMenu = document.querySelector('.admin-dropdown-menu');
    const userButton = document.querySelector('.admin-user-pill');
    if (userMenu) userMenu.classList.remove('show');
    if (userButton) userButton.setAttribute('aria-expanded', 'false');
    const dropdown = document.getElementById('notifDropdown');
    if (dropdown) dropdown.classList.toggle('show');
}

function fetchAdminNotifications() {
    fetch('fetch_notifications.php?_=' + Date.now(), { cache: 'no-store' })
        .then(response => {
            if (response.status === 403) {
                // Session expired
                window.location.reload();
                return null;
            }
            return response.json();
        })
        .then(data => {
            if (!data) return;

            const bell = document.getElementById('notifBell');
            const dot = document.getElementById('notifDot');
            const badge = document.getElementById('notifBadge');
            const list = document.getElementById('notifList');

            // Update Badge
            if (data.unread_count > 0) {
                dot.style.display = 'block';
                badge.style.display = 'block';
                badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
            } else {
                dot.style.display = 'none';
                badge.style.display = 'none';
                badge.textContent = '';
            }

            if (data.notifications.length === 0) {
                list.innerHTML = '<div class="notif-empty">No new notifications</div>';
            } else {
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
                    const notificationType = (n.type || '').toString();
                    const titleText = getNotificationTitle(actionType, notificationType, priorityKey, (n.title || '').toString());
                    const isFollowUp = notificationType === 'follow_up';
                    const isChatPending = notificationType === 'hr_chat_pending';
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
                        pillIcon = 'fa-right-left';
                    } else if (actionType === 'close') {
                        variantClass = 'variant-close';
                        pillText = 'Closed';
                        pillIcon = 'fa-check';
                    } else if (notificationType === 'conference_booking') {
                        variantClass = 'variant-booking';
                        pillText = 'Booking';
                        pillIcon = 'fa-calendar-check';
                    } else if (actionType === 'update' && n.type === 'note_added') {
                        variantClass = 'variant-note';
                        pillText = 'Private Note';
                        pillIcon = 'fa-plus';
                    }
                    const pillHtml = `<span class="notif-pill ${variantClass} ${isChatPending ? 'notif-chat-pill' : ''}"><span class="notif-pill-icon"><i class="fas ${pillIcon}"></i></span>${isChatPending ? '' : `<span class="notif-pill-text">${escapeHtml(pillText)}</span>`}</span>`;
                    const messageHtml = `<div class="notif-title">${pillHtml}<span class="notif-title-text">${escapeHtml(titleText)}</span></div><div class="notif-msg">${highlightNotificationMessage(n.message)}</div>`;
                    
                    return `
                    ${sectionHtml}
                    <div class="notif-item ${n.is_read == 0 ? 'unread' : ''} ${variantClass} ${isPriorityEscalation ? `priority-escalation ${variantClass}` : ''} ${isChatPending ? 'notif-chat-pending' : ''}" data-notif-id="${n.id}" data-ticket-id="${n.ticket_id}" data-notification-type="${escapeHtml(notificationType)}" role="button" tabindex="0" onclick="handleNotificationClick(${n.id}, ${n.ticket_id}, ${JSON.stringify(notificationType)})" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); handleNotificationClick(${n.id}, ${n.ticket_id}, ${JSON.stringify(notificationType)}); }">
                        <div class="notif-content">
                            ${messageHtml}
                            <time class="notif-time" data-timestamp="${n.created_at}">${n.time_ago || ''}</time>
                        </div>
                    </div>
                `}).join('');
                // Update relative times immediately after rendering
                document.querySelectorAll('.notif-time[data-timestamp]').forEach(el => {
                    const ts = el.getAttribute('data-timestamp');
                    const now = new Date();
                    const then = new Date(ts.replace(' ', 'T'));
                    const diff = Math.max(0, Math.floor((now - then) / 1000));
                    if (diff < 10) el.textContent = 'Just now';
                    else if (diff < 60) el.textContent = `${diff}s ago`;
                    else {
                        const m = Math.floor(diff / 60);
                        if (m < 60) el.textContent = `${m} minute${m === 1 ? '' : 's'} ago`;
                        else {
                            const h = Math.floor(diff / 3600);
                            if (h < 24) el.textContent = `${h} hour${h === 1 ? '' : 's'} ago`;
                            else {
                                const d = Math.floor(diff / 86400);
                                el.textContent = `${d} day${d === 1 ? '' : 's'} ago`;
                            }
                        }
                    }
                });
            }
        })
        .catch(err => console.error('Error fetching notifications:', err));
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

function handleNotificationClick(notifId, ticketId, type) {
    // Mark as read
    const formData = new FormData();
    formData.append('id', notifId);
    formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
    
    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).then(() => {
        if ((type || '').toString() === 'conference_booking') {
            window.location.href = 'conference_bookings.php';
            return;
        }
        window.location.href = `all_tickets.php?ticket_id=${ticketId}`;
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text
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

function getNotificationTitle(actionType, type, priorityKey, customTitle) {
    if ((customTitle || '').trim() !== '') return customTitle;
    if (type === 'priority_escalated') return getPriorityNotificationTitle(priorityKey);
    if (type === 'conference_booking') return 'Conference Booking';
    if (actionType === 'assign') return 'Ticket Assigned';
    if (actionType === 'reassign') return 'Ticket Reassigned';
    if (actionType === 'close') return 'Ticket Closed';
    if (type === 'follow_up') return 'Follow Up Request';
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
</script>
