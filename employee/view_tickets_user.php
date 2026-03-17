<?php
require_once '../config/database.php';

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

header("Location: dashboard.php");
exit();

/* ================= GET VALUES ================= */

$category   = $_GET['category']   ?? '';
$department = $_GET['department'] ?? '';
$priority   = $_GET['priority']   ?? '';
$status     = $_GET['status']     ?? '';
$search     = $_GET['search']     ?? '';

// --- PAGINATION LOGIC ---
$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// --- BUILD DYNAMIC QUERY ---
$where = [];
$params = [];
$types = "";

// 🎯 SECURITY: Only show tickets assigned to employee's department
$my_department = $_SESSION['department'];
$where[] = "t.assigned_department = ?";
$params[] = $my_department;
$types .= "s";

// 1. Search
if (!empty($search)) {
    $term = "%$search%";
    
    // Parse ID from search (remove non-digits)
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int)$searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    if ($searchById) {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR t.id = ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $searchIdInt;
        $types .= "sssssi";
    } else {
        $where[] = "(t.subject LIKE ? OR t.category LIKE ? OR t.id LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $types .= "sssss";
    }
}

// 2. Filters
if (!empty($category)) {
    $where[] = "t.category = ?";
    $params[] = $category;
    $types .= "s";
}
if (!empty($priority)) {
    $where[] = "t.priority = ?";
    $params[] = $priority;
    $types .= "s";
}
if (!empty($status)) {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Construct SQL
$sql = "SELECT t.*, u.name as user_name, u.email as user_email, u.department as user_department FROM employee_tickets t LEFT JOIN users u ON t.user_id = u.id";
$countSql = "SELECT COUNT(*) as total FROM employee_tickets t LEFT JOIN users u ON t.user_id = u.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
    $countSql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY t.created_at DESC LIMIT ?, ?";

// --- GET TOTAL COUNT ---
if (!empty($where)) {
    $stmt = $conn->prepare($countSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    // Should not happen due to mandatory filter, but kept as fallback
    $count_result = $conn->query($countSql);
    $total_row = $count_result->fetch_assoc();
}

$total_records = $total_row['total'];
$total_pages = ceil($total_records / $limit);

// --- EXECUTE MAIN QUERY ---
$stmt = $conn->prepare($sql);

// Add Limit/Offset to params
$params[] = $offset;
$params[] = $limit;
$types .= "ii";

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Tickets | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <!-- 2️⃣ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <!-- 5️⃣ VIEW TICKETS PAGE – REDESIGN -->
            <div class="page-header">
                <h1 class="page-title">All Tickets</h1>
            </div>

            <!-- FILTERS CARD -->
            <div class="filter-card">
                <form method="GET" id="filterForm" class="filter-form">
                    
                    <div class="search-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="search-input"
                               placeholder="Search tickets..."
                               value="<?= htmlspecialchars($search); ?>">
                    </div>

                    <div class="filters-wrapper">
                        <div class="select-wrapper small">
                            <select name="category" onchange="submitForm()" class="filter-select">
                                <option value="">All Categories</option>
                                <option <?= $category=='Network Issue'?'selected':'' ?>>Network Issue</option>
                                <option <?= $category=='Hardware Issue'?'selected':'' ?>>Hardware Issue</option>
                                <option <?= $category=='Software Issue'?'selected':'' ?>>Software Issue</option>
                                <option <?= $category=='Email Problem'?'selected':'' ?>>Email Problem</option>
                                <option <?= $category=='Account Access'?'selected':'' ?>>Account Access</option>
                                <option <?= $category=='Technical Support'?'selected':'' ?>>Technical Support</option>
                                <option <?= $category=='Other'?'selected':'' ?>>Other</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>

                        <div class="select-wrapper small">
                            <select name="priority" onchange="submitForm()" class="filter-select">
                                <option value="">All Priorities</option>
                                <option <?= $priority=='Low'?'selected':'' ?>>Low</option>
                                <option <?= $priority=='Medium'?'selected':'' ?>>Medium</option>
                                <option <?= $priority=='High'?'selected':'' ?>>High</option>
                                <option <?= $priority=='Critical'?'selected':'' ?>>Critical</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>

                        <div class="select-wrapper small">
                            <select name="status" onchange="submitForm()" class="filter-select">
                                <option value="">All Status</option>
                                <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                                <option value="In Progress" <?= $status=='In Progress'?'selected':'' ?>>In Progress</option>
                                <option value="Resolved" <?= $status=='Resolved'?'selected':'' ?>>Resolved</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>

                        <a href="view_tickets_user.php" class="clear-btn">Clear</a>
                    </div>
                </form>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Requested By</th>
                                <th>Reported Concern</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Attachment</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer;">
                                    <td>#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    <td class="subject-cell">
                                        <strong><?= htmlspecialchars($row['category']); ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                            $dispName = isset($row['requester_name']) && $row['requester_name'] !== '' ? $row['requester_name'] : $row['user_name'];
                                            $dispEmail = isset($row['requester_email']) && $row['requester_email'] !== '' ? $row['requester_email'] : $row['user_email'];
                                            if ((!isset($row['requester_name']) || $row['requester_name'] === '') || (!isset($row['requester_email']) || $row['requester_email'] === '')) {
                                                $descSrc = isset($row['description']) ? (string)$row['description'] : '';
                                                if ($descSrc !== '') {
                                                    if (preg_match('/REQUESTER NAME:\s*(.+)$/im', $descSrc, $m)) {
                                                        $dispName = trim($m[1]);
                                                    }
                                                    if (preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $descSrc, $m2)) {
                                                        $dispEmail = trim($m2[1]);
                                                    }
                                                }
                                            }
                                        ?>
                                        <div style="font-weight: 500; color: #334155;"><?= htmlspecialchars($dispName); ?></div>
                                        <div style="font-size: 0.85em; color: #64748b;"><?= htmlspecialchars($dispEmail); ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($row['subject']); ?></td>
                                    
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

                                    <td>
                                        <?php if(!empty($row['attachment'])) { ?>
                                            <a href="../uploads/<?= $row['attachment']; ?>" target="_blank" class="attachment-link">
                                                <i class="fas fa-paperclip"></i> View
                                            </a>
                                        <?php } else { ?>
                                            <span class="no-file">-</span>
                                        <?php } ?>
                                    </td>

                                    <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; color: #94a3b8; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-ticket-alt" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                                            <p>No tickets found matching your criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION UI -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination-glass">
                    <!-- Previous Link -->
                    <a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search); ?>&category=<?= urlencode($category); ?>&department=<?= urlencode($department); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>" 
                       class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        Previous
                    </a>

                    <div class="page-numbers">
                        <!-- Page Numbers -->
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i; ?>&search=<?= urlencode($search); ?>&category=<?= urlencode($category); ?>&department=<?= urlencode($department); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>" 
                               class="page-btn <?= ($i == $page) ? 'active' : ''; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <!-- Next Link -->
                    <a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search); ?>&category=<?= urlencode($category); ?>&department=<?= urlencode($department); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>" 
                       class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next
                    </a>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>
    <script>
        let typingTimer;
        const doneTypingInterval = 600; // 600ms delay

        const searchInput = document.getElementById("searchInput");

        searchInput.addEventListener("keyup", function () {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(doneTyping, doneTypingInterval);
        });

        searchInput.addEventListener("keydown", function () {
            clearTimeout(typingTimer);
        });

        function doneTyping() {
            document.getElementById("filterForm").submit();
        }

        /* Dropdown auto-submit */
        function submitForm(){
            document.getElementById("filterForm").submit();
        }
    </script>
<!-- Ticket Details Modal -->
<div id="ticketModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Ticket Details</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <!-- Content loaded via AJAX -->
            <div style="text-align:center; padding: 20px;">Loading...</div>
        </div>
    </div>
</div>

<div id="chatModal" class="modal-overlay">
    <div class="modal-content" style="width: 560px; max-width: 95%;">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" style="margin-bottom: 6px;">Ticket Chat</h2>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div id="empChatAvatar" style="width:32px; height:32px; border-radius:50%; background:#E5E7EB; color:#374151; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px;">--</div>
                    <div>
                        <div id="empChatPeerName" style="font-weight:700; color:#111827; font-size:14px;">Support</div>
                        <div id="empChatPeerEmail" style="font-weight:500; color:#6B7280; font-size:12px;"></div>
                    </div>
                </div>
            </div>
            <button class="modal-close" onclick="closeChatModal()">&times;</button>
        </div>
        <div class="modal-body">
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
// Chat Global Variables
const CURRENT_USER_ID = <?php echo isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0; ?>;
let chatInterval;
let chatUiBound = false;

// Chat Functions
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
const modalBody = document.getElementById('modalBody');
const chatModal = document.getElementById('chatModal');

document.querySelectorAll('.ticket-row').forEach(row => {
    row.addEventListener('click', function() {
        const ticketId = this.getAttribute('data-id');
        openModal(ticketId);
    });
});

function openModal(id, mode = 'full') {
    modal.style.display = 'flex';
    modalBody.innerHTML = '<div style="text-align:center; padding: 20px;">Loading...</div>';

    fetch(`get_ticket_details.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                modalBody.innerHTML = `<p style="color:red; text-align:center;">${data.error}</p>`;
                return;
            }

            const statusClass = 'badge-' + (data.status ? data.status.toLowerCase().replace(' ', '-') : 'default');
            const priorityClass = 'badge-' + (data.priority ? data.priority.toLowerCase() : 'default');

            // --- SECTION 1: HEADER AREA ---
            let html = `
                <div class="modal-section-header">
                    <div class="modal-title-large">${escapeHtml(data.subject)}</div>
                    <div class="modal-meta-row">
                        <span class="modal-badge ${statusClass}">${data.status}</span>
                        <span class="modal-badge ${priorityClass}">${data.priority}</span>
                        <span class="modal-id-badge">#${data.id.toString().padStart(6, '0')}</span>
                    </div>
                </div>
            `;

            const showInfo = (mode === 'info' || mode === 'full');
            const showActions = (mode === 'actions' || mode === 'full');

            // --- SECTION 2: DETAILS GRID (Info) ---
            if (showInfo) {
                html += `<div class="modal-grid">`;

            // Row 1: Created By & Company
            html += `
                <div class="modal-info-group">
                    <span class="modal-info-label">Created By</span>
                    <span class="modal-info-value">${escapeHtml(data.created_by_name)}</span>
                </div>
            `;

            // Row 2: Department & Assigned To
            html += `
                <div class="modal-info-group">
                    <span class="modal-info-label">Department</span>
                    <span class="modal-info-value">${escapeHtml(data.department)}</span>
                </div>
            `;

            // Assigned To (Show "-" if empty)
             html += `
                <div class="modal-info-group">
                    <span class="modal-info-label">Assigned To</span>
                    <span class="modal-info-value">${data.assigned_department ? escapeHtml(data.assigned_department) : '-'}</span>
                </div>
            `;
            
            // Row 3: Created At & Last Updated
            html += `
                <div class="modal-info-group">
                    <span class="modal-info-label">Created At</span>
                    <span class="modal-info-value">${new Date(data.created_at).toLocaleString()}</span>
                </div>
            `;

             html += `
                <div class="modal-info-group">
                    <span class="modal-info-label">Last Updated</span>
                    <span class="modal-info-value">${data.updated_at ? new Date(data.updated_at).toLocaleString() : '-'}</span>
                </div>
            `;

            // Extra Fields: Impact, Urgency, Attachment (Only render if NOT empty/null/-)
            if (data.impact && data.impact !== '-') {
                html += `
                    <div class="modal-info-group">
                        <span class="modal-info-label">Impact</span>
                        <span class="modal-info-value">${escapeHtml(data.impact)}</span>
                    </div>
                `;
            }

            if (data.urgency && data.urgency !== '-') {
                 html += `
                    <div class="modal-info-group">
                        <span class="modal-info-label">Urgency</span>
                        <span class="modal-info-value">${escapeHtml(data.urgency)}</span>
                    </div>
                `;
            }

            var attList = Array.isArray(data.attachments) && data.attachments.length ? data.attachments : (data.attachment ? [{ stored_name: data.attachment, original_name: data.attachment }] : []);
            if (attList.length) {
                html += `
                    <div class="modal-info-group" style="grid-column: span 2;">
                        <span class="modal-info-label">Attachments</span>
                        <span class="modal-info-value" style="display:flex; flex-wrap:wrap; gap:10px;">
                            ${attList.map(function (a) {
                                var filename = a && a.stored_name ? String(a.stored_name) : '';
                                if (!filename) return '';
                                return `
                                    <a href="../uploads/${filename}" target="_blank" style="color:#1B5E20; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                        <i class="fas fa-paperclip"></i> ${escapeHtml((a.original_name || filename))}
                                    </a>
                                `;
                            }).join('')}
                        </span>
                    </div>
                `;
            }

                html += `</div>`; // End Grid
            }

            // --- SECTION 3: DESCRIPTION (Actions) ---
            if (showActions) {
                html += `
                <div class="modal-description-section">
                    <div class="modal-description-card">
                         <span class="modal-info-label" style="display:block; margin-bottom:12px;">Description</span>
                        <div class="modal-description-text">${escapeHtml(data.description)}</div>
                    </div>
                </div>
            `;
            }

            // --- SECTION 4: ADMIN NOTE (If exists, Actions) ---
            if (showActions && data.admin_note) {
                html += `
                    <div class="modal-description-section" style="margin-top: 16px;">
                        <div class="modal-description-card" style="background-color: #f0fdf4; border-left: 4px solid #16a34a;">
                            <span class="modal-info-label" style="display:block; margin-bottom:12px; color: #166534;">
                                <i class="fas fa-user-shield" style="margin-right: 6px;"></i> Admin Note
                            </span>
                            <div class="modal-description-text" style="color: #14532d;">${escapeHtml(data.admin_note).replace(/\n/g, '<br>')}</div>
                        </div>
                    </div>
                `;
            }

            // --- SECTION 5: CHAT (only in full) ---
            if (mode === 'full') {
                html += `
                    <div class="modal-description-section" style="margin-top: 24px;">
                        <span class="tm-section-title">Ticket Chat</span>
                        <div style="margin-top: 12px; display:flex; justify-content:flex-end;">
                            <button type="button" onclick="openChatModal(${data.id})" style="background:#1B5E20; color:#fff; border:none; padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:600;">Open Chat</button>
                        </div>
                    </div>
                `;
            }

                modalBody.innerHTML = html;

                // Store peer info for chat header (Employee talks to department support)
                window.empChatPeer = {
                    name: (data.assigned_department ? data.assigned_department + ' Support' : 'Admin Support'),
                    email: ''
                };
        })
        .catch(err => {
            console.error(err);
            modalBody.innerHTML = '<p style="color:red; text-align:center;">Failed to load details.</p>';
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
    const peer = window.empChatPeer || { name: 'Support', email: '' };
    const nameEl = document.getElementById('empChatPeerName');
    const emailEl = document.getElementById('empChatPeerEmail');
    const avatarEl = document.getElementById('empChatAvatar');
    if (nameEl) nameEl.textContent = peer.name || 'Support';
    if (emailEl) emailEl.textContent = peer.email || '';
    if (avatarEl) {
        const initials = (peer.name || 'S').trim().split(' ').map(p => p[0]).join('').slice(0,2).toUpperCase();
        avatarEl.textContent = initials;
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

// Auto-open modal if ID is in URL
const urlParams = new URLSearchParams(window.location.search);
const ticketIdParam = urlParams.get('id');
if (ticketIdParam) {
    openModal(ticketIdParam, 'info');
}
</script>


</body>
</html>
