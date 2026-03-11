<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

/* Protect page */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$user_department = $_SESSION['department'] ?? '';
$user_company = $_SESSION['company'] ?? '';

if ($user_department === '' || $user_company === '') {
    $user_dept_stmt = $conn->prepare("SELECT department, company FROM users WHERE id = ?");
    $user_dept_stmt->bind_param("i", $user_id);
    $user_dept_stmt->execute();
    $user_dept_result = $user_dept_stmt->get_result();
    if ($row = $user_dept_result->fetch_assoc()) {
        $user_department = $user_department !== '' ? $user_department : ($row['department'] ?? '');
        $user_company = $user_company !== '' ? $user_company : ($row['company'] ?? '');
    }
    $user_dept_stmt->close();

    if ($user_department !== '') $_SESSION['department'] = $user_department;
    if ($user_company !== '') $_SESSION['company'] = $user_company;
}

/* ================= GET VALUES ================= */

$search = $_GET['search'] ?? '';

// --- PAGINATION LOGIC ---
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// --- BUILD DYNAMIC QUERY ---
$where = [];
$params = [];
$types = "";

// 🎯 MAIN FILTER: Assigned to user's department AND Company AND Not Closed
$where[] = "COALESCE(NULLIF(NULLIF(t.assigned_department, 'Unassigned'), ''), t.department) = ?";
$params[] = $user_department;
$types .= "s";

$where[] = "COALESCE(NULLIF(t.assigned_company, ''), t.company) = ?";
$params[] = $user_company;
$types .= "s";

$where[] = "t.status != 'Closed'";

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

// Construct SQL
$sql = "SELECT t.*, u.name as user_name, u.email as user_email, u.department as user_department
        FROM employee_tickets t 
        JOIN users u ON t.user_id = u.id";
$countSql = "SELECT COUNT(*) as total 
             FROM employee_tickets t 
             JOIN users u ON t.user_id = u.id";

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
    $count_result = $conn->query($countSql);
    $total_row = $count_result->fetch_assoc();
}

$total_records = $total_row['total'] ?? 0;
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
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tasks | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <!-- TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">My Tasks</h1>
                <p class="page-subtitle">Tickets assigned to <strong><?= htmlspecialchars($user_department, ENT_QUOTES, 'UTF-8') ?></strong> department</p>
            </div>

            <!-- FILTERS CARD -->
            <div class="filter-card">
                <form method="GET" id="filterForm" class="filter-form">
                    
                    <div class="search-wrapper" style="width: 100%; max-width: 400px;">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="search-input"
                               placeholder="Search tasks..."
                               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="filters-wrapper">
                        <a href="my_task.php" class="clear-btn">Clear Search</a>
                    </div>
                </form>
            </div>

            <!-- TABLE CARD -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Requested By</th>
                                <th>Original Dept</th>
                                <th>Assigned Dept</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer;">
                                    <td>#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                    <td class="subject-cell">
                                        <strong><?= htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </td>
                                    <td>
                                        <div class="user-info">
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
                                            <strong><?= htmlspecialchars($dispName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                            <small><?= htmlspecialchars($dispEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars(!empty($row['department']) ? $row['department'] : ($row['user_department'] ?? 'Sales'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?= htmlspecialchars($row['assigned_department'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    
                                    <td>
                                        <span class="badge badge-<?= strtolower($row['priority']); ?>">
                                            <?= htmlspecialchars($row['priority'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>

                                    <td><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; color: #94a3b8; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-tasks" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                                            <p>No tasks found for your department.</p>
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
                    <a href="?page=<?= $page - 1; ?>&search=<?= urlencode($search); ?>" 
                       class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        Previous
                    </a>

                    <div class="page-numbers">
                        <!-- Page Numbers -->
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i; ?>&search=<?= urlencode($search); ?>" 
                               class="page-btn <?= ($i == $page) ? 'active' : ''; ?>">
                                <?= $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>

                    <!-- Next Link -->
                    <a href="?page=<?= $page + 1; ?>&search=<?= urlencode($search); ?>" 
                       class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next
                    </a>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

    <!-- Ticket Details Modal (Admin Style) -->
    <div id="ticketModal" class="modal-overlay">
        <div class="modal-content" id="modalContent">
            <!-- Content injected via JS -->
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div id="imagePreviewModal" class="image-preview-modal" onclick="TMTicketModal.closeImagePreview(event)">
        <div class="preview-content">
            <button class="preview-close" onclick="TMTicketModal.closeImagePreview(event)">×</button>
            <img id="previewImage" src="" alt="Preview" class="preview-image">
        </div>
    </div>
    
    <script src="../js/employee-dashboard.js"></script>
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
        let typingTimer;
        const doneTypingInterval = 600;

        const searchInput = document.getElementById("searchInput");

        if(searchInput){
            searchInput.addEventListener("keyup", function () {
                clearTimeout(typingTimer);
                typingTimer = setTimeout(doneTyping, doneTypingInterval);
            });

            searchInput.addEventListener("keydown", function () {
                clearTimeout(typingTimer);
            });
        }

        function doneTyping() {
            document.getElementById("filterForm").submit();
        }

        document.querySelectorAll('.ticket-row').forEach(function(row){
            row.addEventListener('click', function(){
                var ticketId = this.dataset.id;
                TMTicketModal.open(ticketId);
            });
        });
        
        var params = new URLSearchParams(window.location.search);
        var tid = params.get('ticket_id') || params.get('id');
        if (tid) {
            TMTicketModal.open(tid);
        }
    </script>
</body>
</html>
