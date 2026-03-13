<?php
// Get current page for active link
$current_page = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/csrf.php';
$csrfToken = csrf_token();
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<header class="admin-navbar">
    <div class="admin-navbar-left">
        <img src="../assets/img/logo.png" alt="Logo" class="admin-logo-img">
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
            <button class="admin-user-pill" aria-label="<?= htmlspecialchars($_SESSION['email'] ?? 'Admin', ENT_QUOTES, 'UTF-8'); ?>">
                <span class="admin-user-icon"><i class="fas fa-user"></i></span>
                <span class="admin-arrow"><i class="fas fa-chevron-down" style="font-size: 10px;"></i></span>
            </button>
            <div class="admin-dropdown-menu">
                <a href="logout.php" class="logout-link">Logout</a>
            </div>
        </div>
    </div>
</header>

<script>
window.TM_CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<button type="button" id="globalChatFab" class="tm-global-chat-fab" onclick="window.TMGlobalChat && window.TMGlobalChat.open()">
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
.notification-wrapper {
    position: relative;
    margin-right: 15px;
    z-index: 2605;
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
    z-index: 1000;
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
.notif-item {
    display: flex;
    align-items: flex-start;
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: all 0.2s ease;
    gap: 15px;
}
.notif-item:hover {
        background-color: #f8fafc;
    }
    .notif-item.unread {
        background-color: #f0f9f3;
    }

    .notif-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #ffffff;
}
.notif-icon.priority-critical { background: #E53935; }
.notif-icon.priority-high { background: #FB8C00; }
.notif-icon.priority-medium { background: #FBC02D; }
.notif-icon.priority-low { background: #43A047; }
.notif-icon.priority-neutral { background: #94a3b8; }

.priority-badge{
    padding:4px 10px;
    border-radius:6px;
    font-size:12px;
    font-weight:600;
    color:white;
    margin-right:6px;
    display: inline-block;
    vertical-align: middle;
}
.priority-badge.priority-critical { background:#E53935; }
.priority-badge.priority-high { background:#FB8C00; }
.priority-badge.priority-medium { background:#FBC02D; }
.priority-badge.priority-low { background:#43A047; }
.priority-badge.priority-neutral { background:#94a3b8; }
.notif-content {
    flex: 1;
    min-width: 0;
}
.notif-msg {
    font-size: 0.95rem;
    color: #334155;
    line-height: 1.4;
    margin-bottom: 6px;
}
.notif-time {
    font-size: 0.8rem;
    color: #94a3b8;
    display: block;
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
        s.src = '../js/ticket-modal.js';
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
        fetch('chat_fetch.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
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
    fetchAdminNotifications();
    setInterval(fetchAdminNotifications, 5000);
    setInterval(updateRelativeTimes, 60000);

    fetchChatUnreadTotal();
    setInterval(fetchChatUnreadTotal, 7000);

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        const wrapper = document.querySelector('.notification-wrapper');
        const dropdown = document.getElementById('notifDropdown');
        if (wrapper && !wrapper.contains(e.target)) {
            dropdown.classList.remove('show');
        }
    });
});

function toggleNotifications() {
    const userMenu = document.querySelector('.admin-dropdown-menu');
    if (userMenu) userMenu.style.display = 'none';
    document.getElementById('notifDropdown').classList.toggle('show');
}

function fetchAdminNotifications() {
    fetch('fetch_notifications.php')
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
            }

            // Update List
            if (data.notifications.length === 0) {
                list.innerHTML = '<div class="notif-empty">No new notifications</div>';
            } else {
                list.innerHTML = data.notifications.map(n => {
                    const rawPriority = (n.priority || '').toString().toLowerCase();
                    const allowed = ['critical', 'high', 'medium', 'low'];
                    const priorityKey = allowed.includes(rawPriority) ? rawPriority : '';
                    const priorityClass = priorityKey ? `priority-${priorityKey}` : 'priority-neutral';
                    const priorityLabel = priorityKey ? `<span class="priority-badge ${priorityClass}">${escapeHtml(priorityKey.charAt(0).toUpperCase() + priorityKey.slice(1))}</span>` : '';
                    
                    return `
                    <div class="notif-item ${n.is_read == 0 ? 'unread' : ''} ${priorityClass}" data-notif-id="${n.id}" data-ticket-id="${n.ticket_id}" onclick="handleNotificationClick(${n.id}, ${n.ticket_id})">
                        <div class="notif-icon ${priorityClass}"><i class="fas fa-ticket"></i></div>
                        <div class="notif-content">
                            <div class="notif-msg">${priorityLabel}${escapeHtml(n.message)}</div>
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

function handleNotificationClick(notifId, ticketId) {
    // Mark as read
    const formData = new FormData();
    formData.append('id', notifId);
    formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
    
    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).then(() => {
        // Redirect to all_tickets.php with ticket ID to auto-open
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
</script>
