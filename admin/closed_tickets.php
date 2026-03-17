<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

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

$query = "
SELECT employee_tickets.*, users.name, users.email
FROM employee_tickets
JOIN users ON employee_tickets.user_id = users.id
WHERE employee_tickets.status = 'Closed'
";

// --- PAGINATION LOGIC ---
$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$count_query = "
SELECT COUNT(*) as total
FROM employee_tickets
WHERE status = 'Closed'
";
$total_result = $conn->query($count_query);
$total_row = $total_result ? $total_result->fetch_assoc() : ['total' => 0];
$total_records = (int)($total_row['total'] ?? 0);

$total_pages = (int)ceil($total_records / $limit);

$query .= " ORDER BY employee_tickets.updated_at DESC, employee_tickets.created_at DESC LIMIT ?, ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $offset, $limit);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <title>Closed Tickets</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="admin-page">
    
    <!-- Admin Navbar -->
    <?php include '../includes/admin_navbar.php'; ?>

    <div class="admin-container">
        <div class="admin-content">

            <?php if(isset($_SESSION['success'])): ?>
                <div class="admin-notice">
                    <?= $_SESSION['success']; ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">Closed Tickets</h1>
                    <p class="admin-page-subtitle">Tickets with status set to Closed.</p>
                </div>
            </div>

            <!-- TABLE -->
            <div class="admin-card table-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Requested By</th>
                                <th>Original Dept</th>
                                <th>Assigned Dept</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $result->fetch_assoc()) { ?>
                            <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer; <?= $row['is_read'] == 0 ? 'background:rgba(27, 94, 32, 0.08);' : ''; ?>">
                                <td>#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td>
                                    <div class="user-info">
                                        <strong><?= htmlspecialchars($row['email']); ?></strong><br>
                                        <small><?= htmlspecialchars($row['name']); ?></small>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($row['department']); ?></td>
                                <td><?= htmlspecialchars($row['assigned_department']); ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($row['priority']); ?>">
                                        <?= htmlspecialchars($row['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                        <?= htmlspecialchars($row['status']); ?>
                                    </span>
                                    <?php if($row['is_read'] == 0): ?>
                                        <span class="new-badge">NEW</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION UI -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-glass">
                    <a href="?page=<?= $page - 1; ?>" class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        Previous
                    </a>

                    <div class="page-numbers">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i; ?>" class="page-btn <?= ($i == $page) ? 'active' : ''; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <a href="?page=<?= $page + 1; ?>" class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next
                    </a>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Ticket Details Modal -->
<div id="ticketModal" class="modal-overlay">
    <div class="modal-content" id="modalContent">
        <!-- Content injected via JS -->
    </div>
</div>

<div id="chatModal" class="modal-overlay">
    <div class="modal-content" style="width: 560px; max-width: 95%;">
        <div class="tm-header">
            <div class="tm-header-left">
                <div class="tm-title">Ticket Chat</div>
                <div class="tm-chat-peer">
                    <div class="tm-peer-avatar" id="chatPeerAvatar">--</div>
                    <div class="tm-peer-meta">
                        <div class="tm-peer-name" id="chatPeerName">-</div>
                        <div class="tm-peer-email" id="chatPeerEmail">-</div>
                    </div>
                </div>
            </div>
            <button class="tm-close-btn" type="button" onclick="closeChatModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div style="padding: 20px 24px 24px;">
            <div class="chat-wrapper" style="margin-top: 0;">
                <div id="chatMessages" class="chat-messages">
                    <div style="text-align:center; color:#999; margin-top:20px;">Loading chat...</div>
                </div>
                <div class="chat-input-area">
                    <input type="hidden" id="chatTicketId" value="">
                    <input type="text" id="chatInput" placeholder="Type a message..." autocomplete="off">
                    <button id="chatSendBtn" type="button">➤</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Chat Functions
const CURRENT_USER_ID = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; ?>;
const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
let chatInterval;
let chatUiBound = false;

function startChat(ticketId) {
    stopChat(); // Clear any existing interval
    loadMessages(ticketId, true); // Initial load with scroll
    chatInterval = setInterval(() => {
        loadMessages(ticketId, false); // Auto-refresh
    }, 3000);
}

function stopChat() {
    if (chatInterval) {
        clearInterval(chatInterval);
        chatInterval = null;
    }
}

function loadMessages(ticketId, scrollBottom = false) {
    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    if (CSRF_TOKEN) formData.append('csrf_token', CSRF_TOKEN);

    fetch('chat_fetch.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error('Chat Error:', data.error);
            return;
        }
        renderMessages(data, scrollBottom);
    })
    .catch(err => console.error('Chat Fetch Error:', err));
}

function renderMessages(messages, scrollBottom) {
    const container = document.getElementById('chatMessages');
    if (!container) return;

    // Preserve scroll position if refreshing
    const isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
    
    // Clear and rebuild
    container.innerHTML = '';

    if (messages.length === 0) {
        container.innerHTML = '<div style="text-align:center; color:#ccc; margin-top:20px;">No messages yet.</div>';
        return;
    }

    messages.forEach(msg => {
        const bubble = document.createElement("div");
        bubble.classList.add("chat-bubble");
        
        if (msg.is_me) {
            bubble.classList.add("me");
        } else {
            bubble.classList.add("other");
        }
        
        // Message Content
        const contentDiv = document.createElement("div");
        contentDiv.textContent = msg.message;
        
        // Time
        const timeDiv = document.createElement("div");
        timeDiv.classList.add("chat-time");
        timeDiv.textContent = msg.created_at; // Already formatted H:i from PHP
        
        bubble.appendChild(contentDiv);
        bubble.appendChild(timeDiv);
        
        container.appendChild(bubble);
    });

    if (scrollBottom || isNearBottom) {
        container.scrollTop = container.scrollHeight;
    }
}

function sendMessage() {
    const input = document.getElementById('chatInput');
    const ticketId = document.getElementById('chatTicketId').value;
    const message = input.value.trim();
    const btn = document.getElementById('chatSendBtn');

    if (!message) return;

    if(btn.disabled) return;
    
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = '...';

    const formData = new FormData();
    formData.append('ticket_id', ticketId);
    formData.append('message', message);
    if (CSRF_TOKEN) formData.append('csrf_token', CSRF_TOKEN);

    fetch('chat_send.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = originalText;
        
        if (data.success) {
            input.value = '';
            loadMessages(ticketId, true); 
        } else {
            alert('Error: ' + (data.error || 'Failed to send'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = originalText;
        console.error(err);
        alert('Network error');
    });
}

// Modal Logic
const modal = document.getElementById('ticketModal');
const modalContent = document.getElementById('modalContent');
const chatModal = document.getElementById('chatModal');

document.querySelectorAll('.ticket-row').forEach(row => {
    row.addEventListener('click', function() {
        const ticketId = this.getAttribute('data-id');
        openModal(ticketId);
    });
});

function openModal(id) {
    modal.style.display = 'flex';
    modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;">Loading details...</div>';

    fetch(`get_ticket_details.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                modalContent.innerHTML = `<div style="padding:40px; text-align:center; color:#ef4444;">${data.error}</div>`;
                return;
            }

            // Status & Priority Logic
            const statusSlug = data.status ? data.status.toLowerCase().replace(/\s+/g, '') : 'default';
            const prioritySlug = data.priority ? data.priority.toLowerCase() : 'default';

            // Helper for Info Rows
            const renderInfo = (label, value) => `
                <div class="tm-info-group">
                    <span class="tm-label">${label}</span>
                    <span class="tm-value">${value ? escapeHtml(String(value)) : '-'}</span>
                </div>
            `;

            // Helper for Attachment
            const renderAttachment = (filename) => {
                const ext = filename.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
                
                let viewBtn = '';
                if (isImage) {
                    // We use onclick with the full path
                    viewBtn = `
                        <button class="tm-view-btn" data-src="../uploads/${escapeHtml(filename)}" onclick="viewImage(this.dataset.src)">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            View
                        </button>
                    `;
                }

                return `
                <div class="tm-attachment">
                    <div class="tm-file-info">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                        <span>${escapeHtml(filename)}</span>
                    </div>
                    <div class="tm-actions">
                        ${viewBtn}
                        <a href="../uploads/${filename}" class="tm-download-btn" download>Download</a>
                    </div>
                </div>
            `;
            };

            const renderAttachments = (data) => {
                const list = Array.isArray(data.attachments) && data.attachments.length
                    ? data.attachments
                    : (data.attachment ? [{ stored_name: data.attachment, original_name: data.attachment }] : []);

                if (!list.length) return '';

                const images = [];
                const others = [];
                list.forEach(att => {
                    const filename = String(att && att.stored_name ? att.stored_name : '').trim();
                    if (!filename) return;
                    const ext = filename.split('.').pop().toLowerCase();
                    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
                    if (isImage) images.push({ filename });
                    else others.push(filename);
                });

                let html = '';
                if (images.length) {
                    html += '<div class="tm-attachment-gallery">';
                    html += images.map(i => `<button type="button" class="tm-attachment-thumb" data-src="../uploads/${escapeHtml(i.filename)}" onclick="viewImage(this.dataset.src)"><img class="tm-attachment-img" src="../uploads/${escapeHtml(i.filename)}" alt=""></button>`).join('');
                    html += '</div>';
                }
                if (others.length) {
                    html += others.map(f => renderAttachment(f)).join('');
                }
                return html;
            };

            const formatTimelineTime = (dateLike) => {
                if (!dateLike) return '-';
                const d = dateLike instanceof Date ? dateLike : new Date(dateLike);
                if (Number.isNaN(d.getTime())) return '-';
                return d.toLocaleString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
            };

            const renderTimeline = (ticket) => {
                const createdAt = ticket.created_at ? new Date(ticket.created_at) : null;
                const updatedAt = ticket.updated_at ? new Date(ticket.updated_at) : null;
                const fallbackWhen = updatedAt || createdAt;

                const events = [
                    { title: 'Ticket created', when: createdAt }
                ];

                if (ticket.assigned_department) {
                    events.push({ title: `Assigned to ${ticket.assigned_department}`, when: fallbackWhen });
                }

                if (ticket.admin_note && String(ticket.admin_note).trim() !== '') {
                    events.push({ title: 'Admin added a note', when: fallbackWhen });
                }

                if (ticket.status && ticket.status !== 'Open') {
                    events.push({ title: `Status changed to ${ticket.status}`, when: fallbackWhen });
                }

                return `
                    <div class="tm-timeline">
                        ${events.map(e => `
                            <div class="tm-timeline-item">
                                <div class="tm-timeline-content">
                                    <div class="tm-timeline-title">${escapeHtml(e.title)}</div>
                                    <div class="tm-timeline-time">${formatTimelineTime(e.when)}</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
            };

            // Build HTML
            let html = `
                <div class="tm-header">
                    <div class="tm-header-left">
                        <div class="tm-title">${escapeHtml(data.subject)}</div>
                        <div class="tm-chips">
                            <span class="tm-chip tm-chip-${statusSlug}">${escapeHtml(data.status)}</span>
                            <span class="tm-chip tm-chip-${prioritySlug}">${escapeHtml(data.priority)}</span>
                            <span class="tm-id">#${data.id.toString().padStart(6, '0')}</span>
                        </div>
                    </div>
                    <button class="tm-close-btn" onclick="closeModal()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <div class="tm-body">
                    <div class="tm-info-col">
                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Ticket Information</span>
                            </div>
                            <div class="tm-card-body">
                                <div class="tm-info-grid">
                                    <div class="tm-info-label">CREATED BY</div>
                                    <div class="tm-info-value">${data.created_by_name ? escapeHtml(String(data.created_by_name)) : '-'}</div>

                                    <div class="tm-info-label">EMAIL</div>
                                    <div class="tm-info-value">${data.created_by_email ? escapeHtml(String(data.created_by_email)) : '-'}</div>

                                    <div class="tm-info-label">DEPARTMENT</div>
                                    <div class="tm-info-value">${data.department ? escapeHtml(String(data.department)) : '-'}</div>

                                    <div class="tm-info-label">CREATED AT</div>
                                    <div class="tm-info-value">${data.created_at ? formatTimelineTime(data.created_at) : '-'}</div>

                                    <div class="tm-info-label">LAST UPDATED</div>
                                    <div class="tm-info-value">${data.updated_at ? formatTimelineTime(data.updated_at) : '-'}</div>

                                    <div class="tm-info-label">DURATION</div>
                                    <div class="tm-info-value">${data.duration ? `<span class="tm-duration-badge">${escapeHtml(String(data.duration))}</span>` : '-'}</div>

                                    <div class="tm-info-label">ASSIGNED TO</div>
                                    <div class="tm-info-value">${data.assigned_department ? escapeHtml(String(data.assigned_department)) : '-'}</div>
                                </div>
                            </div>
                        </div>

                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Ticket Activity</span>
                            </div>
                            <div class="tm-card-body">
                                ${renderTimeline(data)}
                            </div>
                        </div>
                    </div>

                    <div class="tm-desc-col">
                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Description</span>
                            </div>
                            <div class="tm-card-body">
                                <div class="tm-desc-text">${escapeHtml(data.description)}</div>
                                ${renderAttachments(data)}
                            </div>
                        </div>

                        ${(data.impact && data.impact !== '-') ? `
                            <div class="tm-card">
                                <div class="tm-card-header">
                                    <span class="tm-card-title">Impact</span>
                                </div>
                                <div class="tm-card-body">
                                    <div class="tm-info-value">${escapeHtml(String(data.impact))}</div>
                                </div>
                            </div>
                        ` : ''}

                        ${(data.urgency && data.urgency !== '-') ? `
                            <div class="tm-card">
                                <div class="tm-card-header">
                                    <span class="tm-card-title">Urgency</span>
                                </div>
                                <div class="tm-card-body">
                                    <div class="tm-info-value">${escapeHtml(String(data.urgency))}</div>
                                </div>
                            </div>
                        ` : ''}

                        <div class="tm-card">
                            <div class="tm-card-header">
                                <span class="tm-card-title">Admin Note (Visible to Requestor)</span>
                            </div>
                            <div class="tm-card-body">
                                <textarea 
                                    name="admin_note" 
                                    form="ticketUpdateForm"
                                    class="tm-admin-note"
                                    placeholder="Enter a note for the employee..."
                                >${data.admin_note ? escapeHtml(data.admin_note) : ''}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tm-footer">
                    <div class="tm-action-bar">
                        <span class="tm-action-title">Ticket Actions</span>
                        <form id="ticketUpdateForm" method="POST" action="update_ticket.php" class="tm-action-form">
                            <input type="hidden" name="id" value="${data.id}">
                            <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                            
                            <div class="tm-action-controls">
                                <div class="tm-action-left">
                                    <!-- Status Dropdown -->
                                    <div class="tm-control-group">
                                        <label class="tm-control-label">Status:</label>
                                        <div class="tm-select-wrapper">
                                            <select class="tm-select tm-status-select" name="status" onchange="updateStatusColor(this)">
                                                <option value="Open" ${data.status === 'Open' ? 'selected' : ''}>Open</option>
                                                <option value="In Progress" ${data.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                                                <option value="Resolved" ${data.status === 'Resolved' ? 'selected' : ''}>Resolved</option>
                                                <option value="Closed" ${data.status === 'Closed' ? 'selected' : ''}>Closed</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Department Dropdown -->
                                    <div class="tm-control-group">
                                        <label class="tm-control-label">Assign:</label>
                                        <div class="tm-dept-wrapper">
                                            <span class="tm-dept-icon">🏢</span>
                                            <select class="tm-select tm-dept-select" name="assigned_department">
                                                <option value="" selected>Assign Department</option>
                                                <option value="IT" ${data.assigned_department === 'IT' ? 'selected' : ''}>IT</option>
                                                <option value="HR" ${data.assigned_department === 'HR' ? 'selected' : ''}>HR</option>
                                                <option value="Marketing" ${data.assigned_department === 'Marketing' ? 'selected' : ''}>Marketing</option>
                                                <option value="Admin" ${data.assigned_department === 'Admin' ? 'selected' : ''}>Admin</option>
                                                <option value="Technical" ${data.assigned_department === 'Technical' ? 'selected' : ''}>Technical</option>
                                                <option value="Accounting" ${data.assigned_department === 'Accounting' ? 'selected' : ''}>Accounting</option>
                                                <option value="Supply Chain" ${data.assigned_department === 'Supply Chain' ? 'selected' : ''}>Supply Chain</option>
                                                <option value="MPDC" ${data.assigned_department === 'MPDC' ? 'selected' : ''}>MPDC</option>
                                                <option value="E-Comm" ${data.assigned_department === 'E-Comm' ? 'selected' : ''}>E-Comm</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="tm-btn-group">
                                    <button type="button" class="tm-btn tm-btn-secondary" onclick="closeModal()">Close</button>
                                    <button type="submit" class="tm-btn tm-btn-primary">Save Ticket</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            `;

            modalContent.innerHTML = html;
            
            // Initialize status color and store peer info
            setTimeout(() => {
                const statusSelect = modalContent.querySelector('.tm-status-select');
                if(statusSelect) updateStatusColor(statusSelect);
                try { localStorage.setItem('tm_current_ticket_id', String(data.id)); } catch (e) {}
                window.tmChatPeer = {
                    name: data.created_by_name || '',
                    email: data.created_by_email || ''
                };
            }, 0);
        })
        .catch(err => {
            console.error(err);
            modalContent.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Failed to load details.</div>';
        });
}

function closeModal() {
    modal.style.display = 'none';
    closeChatModal();
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

function bindChatUiOnce() {
    if (chatUiBound) return;
    chatUiBound = true;

    const chatInput = document.getElementById('chatInput');
    const chatSendBtn = document.getElementById('chatSendBtn');

    if (chatInput) {
        chatInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });
    }

    if (chatSendBtn) {
        chatSendBtn.addEventListener('click', function() {
            sendMessage();
        });
    }
}

function openChatModal(ticketId) {
    if (!chatModal) return;
    bindChatUiOnce();
    document.getElementById('chatTicketId').value = ticketId;
    document.getElementById('chatMessages').innerHTML = '<div style="text-align:center; color:#999; margin-top:20px;">Loading chat...</div>';
    
    // Update chat header with peer info
    const name = (window.tmChatPeer && window.tmChatPeer.name) ? String(window.tmChatPeer.name) : '';
    const email = (window.tmChatPeer && window.tmChatPeer.email) ? String(window.tmChatPeer.email) : '';
    const nameEl = document.getElementById('chatPeerName');
    const emailEl = document.getElementById('chatPeerEmail');
    const avatarEl = document.getElementById('chatPeerAvatar');
    if (nameEl) nameEl.textContent = name || 'Requestor';
    if (emailEl) emailEl.textContent = email || '';
    if (avatarEl) {
        const initials = (name || email || '--').trim().split(' ').map(p => p[0]).join('').slice(0,2).toUpperCase();
        avatarEl.textContent = initials || '--';
    }
    chatModal.style.display = 'flex';
    startChat(ticketId);
    setTimeout(() => {
        const input = document.getElementById('chatInput');
        if (input) input.focus();
    }, 0);
}

function closeChatModal() {
    if (!chatModal) return;
    chatModal.style.display = 'none';
    stopChat();
    const input = document.getElementById('chatInput');
    if (input) input.value = '';
}

modal.addEventListener('click', function(event) {
    if (event.target === modal) {
        closeModal();
    }
});

chatModal.addEventListener('click', function(event) {
    if (event.target === chatModal) {
        closeChatModal();
    }
});
</script>
    <script src="../js/admin.js"></script>
<!-- Image Preview Modal -->
<div id="imagePreviewModal" class="image-preview-modal" onclick="closeImagePreview(event)">
    <div class="preview-content">
        <button class="preview-close" onclick="closeImagePreview(event)">×</button>
        <img id="previewImage" src="" alt="Preview" class="preview-image">
    </div>
</div>

<script>
// Image Preview Logic
function viewImage(src) {
    const modal = document.getElementById('imagePreviewModal');
    const img = document.getElementById('previewImage');
    img.src = src;
    modal.classList.add('show');
}

function closeImagePreview(e) {
    // Close if clicked on overlay (ID match) or Close Button
    if (e.target.id === 'imagePreviewModal' || e.target.classList.contains('preview-close')) {
        const modal = document.getElementById('imagePreviewModal');
        modal.classList.remove('show');
        setTimeout(() => {
            document.getElementById('previewImage').src = '';
        }, 300); // Wait for transition
    }
}

function updateStatusColor(select) {
    if (!select) return;
    const status = select.value;
    
    // Remove all status classes first
    select.classList.remove('status-open', 'status-progress', 'status-resolved', 'status-closed');
    
    // Add specific class
    if (status === 'Open') select.classList.add('status-open');
    else if (status === 'In Progress') select.classList.add('status-progress');
    else if (status === 'Resolved') select.classList.add('status-resolved');
    else if (status === 'Closed') select.classList.add('status-closed');
}

// Auto-open modal if ID is in URL
const urlParams = new URLSearchParams(window.location.search);
const ticketIdParam = urlParams.get('id');
if (ticketIdParam) {
    openModal(ticketIdParam);
}
</script>

</body>
</html>
