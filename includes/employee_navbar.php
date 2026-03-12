<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/csrf.php';

$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$user_email = 'Account';

if ($user_id > 0 && isset($conn)) {
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
    return '';
}
?>
<nav class="navbar">
    <div class="nav-left">
        <img src="../assets/img/image.png" alt="Leads Agri Logo" class="logo-icon">
        <div class="brand-name">Leads Agri Helpdesk</div>
        <button class="navbar-toggler" id="navbarToggler">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="navbar-collapse" id="navbarCollapse">
        <div class="nav-center">
            <a href="dashboard.php" class="nav-link <?= isActive('dashboard.php') ?>">Dashboard</a>
            <a href="request_ticket.php" class="nav-link <?= isActive('request_ticket.php') ?>">Create Ticket</a>
            <a href="my_task.php" class="nav-link <?= isActive('my_task.php') ?>">Task</a>
            <a href="my_tickets.php" class="nav-link <?= isActive('my_tickets.php') ?>">My Tickets</a>
            <a href="knowledge_base.php" class="nav-link <?= isActive('knowledge_base.php') ?>">Knowledge Base</a>
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
                <button class="user-btn" aria-label="<?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-user-circle"></i>
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

<button type="button" id="globalChatFab" class="tm-global-chat-fab" onclick="window.TMGlobalChat && window.TMGlobalChat.open()">
    <i class="fas fa-comments"></i>
    <span class="tm-global-chat-label">Chat</span>
    <span id="globalChatBadge" class="chat-badge"></span>
</button>

<style>
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

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.tm-global-chat-fab {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 2500;
    background: #2563eb;
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
.tm-global-chat-fab:hover { background: #1d4ed8; }
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
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge = document.getElementById('notifBadge');
    const dot = document.getElementById('notifDot');
    const list = document.getElementById('notifList');
    const userBtn = document.querySelector('.user-btn');
    const userDropdown = document.querySelector('.user-dropdown');
    
    // Toggle dropdown
    bell.addEventListener('click', function(e) {
        e.stopPropagation();
        if (userDropdown) userDropdown.classList.remove('show');
        dropdown.classList.toggle('show');
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.classList.remove('show');
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

    // Fetch Notifications
    function fetchNotifications() {
        fetch('fetch_notifications.php')
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
                    badge.style.display = 'none';
                    dot.style.display = 'none';
                }

                // Update List
                if (data.notifications && data.notifications.length > 0) {
                    list.innerHTML = data.notifications.map(n => {
                        const rawPriority = (n.priority || '').toString().toLowerCase();
                        const allowed = ['critical', 'high', 'medium', 'low'];
                        const priorityKey = allowed.includes(rawPriority) ? rawPriority : '';
                        const priorityClass = priorityKey ? `priority-${priorityKey}` : 'priority-neutral';
                        const priorityLabel = priorityKey ? `<span class="priority-badge ${priorityClass}">${escapeHtml(priorityKey.charAt(0).toUpperCase() + priorityKey.slice(1))}</span>` : '';
                        return `
                            <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" data-notif-id="${n.id}" data-ticket-id="${n.ticket_id}" onclick="markAsRead(${n.id}, ${n.ticket_id}, '${n.type || ''}')">
                                <div class="notif-icon ${priorityClass}"><i class="fas fa-ticket"></i></div>
                                <div class="notif-content">
                                    <div class="notif-msg">${priorityLabel}${escapeHtml(n.message)}</div>
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

    // Mark as Read & Redirect
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
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
            if (type === 'dept_assigned') {
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
            const getFromUrl = function() {
                try {
                    const p = new URLSearchParams(window.location.search);
                    return p.get('ticket_id') || p.get('id');
                } catch (e) {
                    return null;
                }
            };
            const last = (window.TMTicketModal && window.TMTicketModal.getCurrentTicketId) ? window.TMTicketModal.getCurrentTicketId() : null;
            const ticketId = last || getFromUrl();
            if (!ticketId) {
                alert('Open a ticket first to start chat.');
                return;
            }
            if (window.TMTicketModal && window.TMTicketModal.openChatModal) {
                window.TMTicketModal.openChatModal(ticketId);
                return;
            }
            if (typeof window.openChatModal === 'function') {
                window.openChatModal(ticketId);
            }
        }
    };
});
</script>
