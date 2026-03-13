<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

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

/* ================= GET VALUES ================= */

$department = $_GET['department'] ?? '';
$company_email = $_GET['company_email'] ?? '';
$priority   = $_GET['priority']   ?? '';
$status     = $_GET['status']     ?? '';
$search     = $_GET['search']     ?? '';
$department_key = $department !== '' ? ticket_department_key_from_value((string) $department) : '';

$query = "
SELECT employee_tickets.*, users.name, users.email, users.department AS user_department
FROM employee_tickets
JOIN users ON employee_tickets.user_id = users.id
WHERE 1
";

/* ================= FILTERS ================= */

if (!empty($department)) {
    $deptKey = $department_key !== '' ? $department_key : ticket_department_key_from_value((string) $department);
    $deptAliases = ticket_department_aliases_for_key($deptKey);
    $deptAliases[] = $deptKey;
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $deptAliases)), static function ($v) { return is_string($v) && $v !== ''; })));

    if (count($deptAliases) > 0) {
        $deptConds = [];
        foreach ($deptAliases as $a) {
            $aEsc = $conn->real_escape_string($a);
            $deptConds[] = "UPPER(COALESCE(NULLIF(employee_tickets.assigned_group,''), NULLIF(employee_tickets.assigned_department,''), NULLIF(employee_tickets.department,''), NULLIF(users.department,''))) = '$aEsc'";
        }
        $query .= " AND (" . implode(" OR ", $deptConds) . ")";
    }
}

if (!empty($priority)) {
    $priority = $conn->real_escape_string($priority);
    $query .= " AND employee_tickets.priority = '$priority'";
}

if (!empty($status)) {
    if ($status === 'unread') {
        $query .= " AND employee_tickets.is_read = 0";
    } else {
        $status = $conn->real_escape_string($status);
        $query .= " AND employee_tickets.status = '$status'";
    }
}

if (!empty($company_email)) {
    $domain = strtolower(trim((string) $company_email));
    if ($domain !== '') {
        if ($domain[0] !== '@') $domain = '@' . $domain;
        $domainEsc = $conn->real_escape_string($domain);
        $query .= " AND LOWER(COALESCE(NULLIF(employee_tickets.requester_email,''), users.email)) LIKE '%$domainEsc'";
    }
}

if (!empty($search)) {
    $searchSQL = $conn->real_escape_string($search);
    
    // Parse ID from search (remove non-digits)
    $searchId = preg_replace('/[^0-9]/', '', $search);
    $searchIdInt = (int)$searchId;
    $searchById = ($searchId !== '' && $searchIdInt > 0);

    $query .= " AND (
        users.name LIKE '%$searchSQL%' OR
        users.email LIKE '%$searchSQL%' OR
        employee_tickets.subject LIKE '%$searchSQL%' OR
        employee_tickets.id LIKE '%$searchSQL%'";

    if ($searchById) {
        $query .= " OR employee_tickets.id = $searchIdInt";
    }
    
    $query .= " )";
}

// --- PAGINATION LOGIC ---
$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Count total records (reuse the WHERE clause from $query)
$from_pos = strpos($query, "FROM employee_tickets");
if ($from_pos !== false) {
    $count_query = "SELECT COUNT(*) as total " . substr($query, $from_pos);
    $total_result = $conn->query($count_query);
    $total_row = $total_result->fetch_assoc();
    $total_records = $total_row['total'];
} else {
    $total_records = 0;
}

$total_pages = ceil($total_records / $limit);

$query .= " ORDER BY employee_tickets.created_at DESC LIMIT ?, ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $offset, $limit);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Tickets</title>
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
                    <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="admin-page-header">
                <div>
                    <h1 class="admin-page-title">All Tickets</h1>
                    <p class="admin-page-subtitle">Manage and track all support tickets.</p>
                </div>
            </div>

            <!-- FILTERS -->
            <div class="admin-card filter-card">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="filter-input"
                               placeholder="Search name, email or subject..."
                               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">

                        <select name="department" class="filter-select" onchange="submitForm()">
                            <option value="" disabled selected hidden>All Department</option>
                            <option value="ACCOUNTING" <?= $department_key==='ACCOUNTING'?'selected':'' ?>>ACCOUNTING</option>
                            <option value="ADMIN" <?= $department_key==='ADMIN'?'selected':'' ?>>ADMIN</option>
                            <option value="E-COMM" <?= $department_key==='E-COMM'?'selected':'' ?>>E-COMM</option>
                            <option value="HR" <?= $department_key==='HR'?'selected':'' ?>>HR</option>
                            <option value="IT" <?= $department_key==='IT'?'selected':'' ?>>IT</option>
                            <option value="LINGAP" <?= $department_key==='LINGAP'?'selected':'' ?>>LINGAP</option>
                            <option value="MARKETING" <?= $department_key==='MARKETING'?'selected':'' ?>>MARKETING</option>
                            <option value="SUPPLY CHAIN" <?= $department_key==='SUPPLY CHAIN'?'selected':'' ?>>SUPPLY CHAIN</option>
                            <option value="TECHNICAL" <?= $department_key==='TECHNICAL'?'selected':'' ?>>TECHNICAL</option>
                        </select>

                        <select name="company_email" class="filter-select" onchange="submitForm()">
                            <option value="" disabled selected hidden>All Company Email</option>
                            <?php
                                $domains = ['@gpsci.net','@farmasee.ph','@gmail.com','@leads-eh.com','@leads-farmex.com','@leadsagri.com','@leadsanimalhealth.com','@leadsav.com','@leadstech-corp.com','@lingapleads.org','@primestocks.ph'];
                                $selDomain = strtolower(trim((string) $company_email));
                                if ($selDomain !== '' && $selDomain[0] !== '@') $selDomain = '@' . $selDomain;
                                foreach ($domains as $d) {
                                    $isSel = $selDomain !== '' && strtolower($d) === $selDomain;
                                    echo '<option value="' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '" ' . ($isSel ? 'selected' : '') . '>' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '</option>';
                                }
                            ?>
                        </select>

                        <select name="priority" class="filter-select" onchange="submitForm()">
                            <option value="" disabled selected hidden>All Priority</option>
                            <option value="Low" <?= $priority=='Low'?'selected':'' ?>>Low</option>
                            <option value="Medium" <?= $priority=='Medium'?'selected':'' ?>>Medium</option>
                            <option value="High" <?= $priority=='High'?'selected':'' ?>>High</option>
                            <option value="Critical" <?= $priority=='Critical'?'selected':'' ?>>Critical</option>
                        </select>

                        <select name="status" class="filter-select" onchange="submitForm()">
                            <option value="" disabled selected hidden>All Status</option>
                            <option value="Open" <?= $status=='Open'?'selected':'' ?>>Open</option>
                            <option value="In Progress" <?= $status=='In Progress'?'selected':'' ?>>In Progress</option>
                            <option value="Resolved" <?= $status=='Resolved'?'selected':'' ?>>Resolved</option>
                            <option value="Closed" <?= $status=='Closed'?'selected':'' ?>>Closed</option>
                            <option value="unread" <?= $status=='unread'?'selected':'' ?>>Unread</option>
                        </select>

                        <a href="all_tickets.php" class="clear-btn" id="clearFiltersBtn">Clear Filters</a>
                    </div>
                </form>
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
                                <th>Date Created</th>
                            </tr>
                        </thead>
                        <tbody id="ticketsTbody">
                            <?php while($row = $result->fetch_assoc()) { ?>
                            <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer; <?= $row['is_read'] == 0 ? 'background:rgba(27, 94, 32, 0.08);' : ''; ?>">
                                <td data-label="ID">#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                <td data-label="Requested By">
                                    <div class="user-info">
                                        <?php
                                            $dispName = isset($row['requester_name']) && $row['requester_name'] !== '' ? $row['requester_name'] : $row['name'];
                                            $dispEmail = isset($row['requester_email']) && $row['requester_email'] !== '' ? $row['requester_email'] : $row['email'];
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
                                        <strong><?= htmlspecialchars($dispName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                        <small><?= htmlspecialchars($dispEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                </td>
                                <td data-label="Original Dept"><?php 
                                    $origDept = !empty($row['department']) ? $row['department'] : ($row['user_department'] ?? '');
                                    echo htmlspecialchars($origDept !== '' ? $origDept : 'Sales');
                                ?></td>
                                <td data-label="Assigned Dept"><?= htmlspecialchars($row['assigned_department'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="Priority">
                                    <span class="badge badge-<?= strtolower($row['priority']); ?>">
                                        <?= htmlspecialchars($row['priority'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td data-label="Status">
                                    <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                        <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php if($row['is_read'] == 0): ?>
                                        <span class="new-badge">NEW</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- PAGINATION UI -->
                <div id="ticketsPagination">
                <?php if ($total_pages > 1): ?>
                <div class="pagination-glass">
                    <!-- Previous Link -->
                    <a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>" 
                       class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        Previous
                    </a>

                    <!-- Page Numbers -->
                    <div class="page-numbers">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>" 
                               class="page-btn <?= ($i == $page) ? 'active' : ''; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <!-- Next Link -->
                    <a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search); ?>&department=<?= urlencode($department); ?>&company_email=<?= urlencode($company_email); ?>&priority=<?= urlencode($priority); ?>&status=<?= urlencode($status); ?>" 
                       class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next
                    </a>
                </div>
                <?php endif; ?>
                </div>

            </div>

        </div>
    </div>
</div>

<script>
let typingTimer;
const doneTypingInterval = 350;

const searchInput = document.getElementById("searchInput");
const filterForm = document.getElementById("filterForm");
const tbodyEl = document.getElementById("ticketsTbody");
const paginationEl = document.getElementById("ticketsPagination");

searchInput.addEventListener("keyup", function () {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(doneTyping, doneTypingInterval);
});

searchInput.addEventListener("keydown", function () {
    clearTimeout(typingTimer);
});

function doneTyping() {
    submitForm(1);
}

function serializeForm(page) {
    var fd = new FormData(filterForm);
    var params = new URLSearchParams();
    fd.forEach(function (v, k) {
        if (v === null || v === undefined) return;
        var s = String(v);
        if (s.trim() === '') return;
        params.set(k, s);
    });
    params.set('page', String(page || 1));
    params.set('limit', '5');
    return params;
}

function refreshTickets(page) {
    if (!filterForm || !tbodyEl || !paginationEl) return;
    var params = serializeForm(page || 1);
    fetch('ajax_all_tickets_list.php?' + params.toString(), { method: 'GET' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.ok) return;
            tbodyEl.innerHTML = data.rows_html || '';
            paginationEl.innerHTML = data.pagination_html || '';
            var url = new URL(window.location.href);
            url.search = '';
            params.forEach(function (v, k) { url.searchParams.set(k, v); });
            history.replaceState({}, '', url.toString());
        })
        .catch(function () {});
}

function submitForm(page){
    refreshTickets(page || 1);
}
</script>
<!-- Ticket Details Modal -->
<div id="ticketModal" class="modal-overlay">
    <div class="modal-content" id="modalContent">
        <!-- Content injected via JS -->
    </div>
</div>

<!-- Chat Modal Removed (Integrated into Ticket Modal) -->

<div id="imagePreviewModal" class="image-preview-modal" onclick="TMTicketModal.closeImagePreview(event)">
    <div class="preview-content">
        <button class="preview-close" onclick="TMTicketModal.closeImagePreview(event)">×</button>
        <img id="previewImage" src="" alt="Preview" class="preview-image">
    </div>
</div>
<script>
window.TM_CURRENT_USER = <?php echo json_encode([
    'id' => $_SESSION['user_id'] ?? null,
    'name' => $_SESSION['name'] ?? null,
    'email' => $_SESSION['email'] ?? null,
    'department' => $_SESSION['department'] ?? null,
    'company' => $_SESSION['company'] ?? null,
    'role' => $_SESSION['role'] ?? null
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
<script>
document.addEventListener('click', function (e) {
    var target = e.target;
    var row = target && target.closest ? target.closest('.ticket-row') : null;
    if (row && row.getAttribute) {
        var ticketId = row.getAttribute('data-id');
        if (ticketId && typeof TMTicketModal !== 'undefined' && typeof TMTicketModal.open === 'function') {
            TMTicketModal.open(ticketId);
        }
    }
    var pageBtn = target && target.closest ? target.closest('#ticketsPagination a.page-btn') : null;
    if (pageBtn) {
        if (pageBtn.classList.contains('disabled')) {
            e.preventDefault();
            return;
        }
        var p = pageBtn.getAttribute('data-page');
        if (p) {
            e.preventDefault();
            submitForm(parseInt(p, 10) || 1);
        }
    }
});

var clearBtn = document.getElementById('clearFiltersBtn');
if (clearBtn) {
    clearBtn.addEventListener('click', function (e) {
        e.preventDefault();
        if (!filterForm) return;
        filterForm.reset();
        if (searchInput) searchInput.value = '';
        submitForm(1);
    });
}

if (filterForm) {
    filterForm.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(1);
    });
}
</script>
    <script src="../js/admin.js"></script>
<script>
const urlParams = new URLSearchParams(window.location.search);
const ticketIdParam = urlParams.get('ticket_id') || urlParams.get('id');
if (ticketIdParam) {
    if (typeof TMTicketModal !== 'undefined' && typeof TMTicketModal.open === 'function') {
        TMTicketModal.open(ticketIdParam);
    }
}
</script>



</body>
</html>
