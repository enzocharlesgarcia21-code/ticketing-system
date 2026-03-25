<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

ticket_apply_sla_priority($conn);

$user_id = (int) $_SESSION['user_id'];
$limit = 10;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) $page = 1;

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM employee_tickets
    WHERE user_id = ?
");
$countStmt->bind_param("i", $user_id);
$countStmt->execute();
$countResult = $countStmt->get_result();
$countRow = $countResult ? $countResult->fetch_assoc() : null;
$countStmt->close();

$total_records = (int) ($countRow['total'] ?? 0);
$total_pages = (int) ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;
$showing_from = $total_records > 0 ? ($offset + 1) : 0;
$showing_to = min($offset + $limit, $total_records);

$stmt = $conn->prepare("
    SELECT *
    FROM employee_tickets
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ?, ?
");
$stmt->bind_param("iii", $user_id, $offset, $limit);
$stmt->execute();
$result = $stmt->get_result();
unset($_SESSION['success']);
$successMessage = '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Tickets | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body.tm-hide-requestor-admin-chat .tm-inline-chat-btn {
            display: none !important;
        }
        #ticketSuccessOverlay {
            display: none !important;
        }
    </style>
</head>
<body>
    <script>document.body.classList.add('tm-hide-requestor-admin-chat');</script>

    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">My Submitted Tickets</h1>
            </div>

            <div class="table-card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Attachment</th>
                                <th>Date Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="ticket-row" data-id="<?= $row['id']; ?>" style="cursor:pointer;">
                                    <td data-label="ID">#<?= $row['id']; ?></td>
                                    <td data-label="Category" class="subject-cell">
                                        <strong><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </td>
                                    <td data-label="Status">
                                        <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td data-label="Attachment">
                                        <?php if(!empty($row['attachment'])) { ?>
                                            <a href="../uploads/<?= rawurlencode($row['attachment']); ?>" target="_blank" class="attachment-link">
                                                <i class="fas fa-paperclip"></i> View
                                            </a>
                                        <?php } else { ?>
                                            <span class="no-file">-</span>
                                        <?php } ?>
                                    </td>
                                    <td data-label="Date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #94a3b8; padding: 40px;">
                                        <div class="empty-state">
                                            <i class="fas fa-ticket-alt" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                                            <p>No tickets submitted yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_records > 0): ?>
                <div class="pagination-glass">
                    <div class="pagination-summary">Showing <?= number_format($showing_from) ?> - <?= number_format($showing_to) ?> of <?= number_format($total_records) ?> tickets</div>
                    <?php if ($total_pages > 1): ?>
                    <a href="?page=<?= max(1, $page - 1) ?>" class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">
                        &lsaquo; Previous
                    </a>
                    <div class="page-numbers">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?= $i ?>" class="page-btn <?= ($i === $page) ? 'active' : ''; ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                    <a href="?page=<?= min($total_pages, $page + 1) ?>" class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        Next &rsaquo;
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>

    <!-- Ticket Details Modal -->
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
    <div id="ticketSuccessOverlay" class="ticket-success-overlay" aria-hidden="true">
        <div class="ticket-success-card" role="dialog" aria-modal="true" aria-labelledby="ticketSuccessTitle">
            <div class="ticket-success-icon">✓</div>
            <h2 id="ticketSuccessTitle" class="ticket-success-title">Ticket Submitted Successfully</h2>
            <p class="ticket-success-text"><?= htmlspecialchars($successMessage !== '' ? $successMessage : 'Your request has been sent. Our team will get back to you soon.', ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="ticket-success-actions">
                <button type="button" id="ticketSuccessBtn" class="ticket-success-btn">Done</button>
            </div>
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
    window.TM_HIDE_UPDATE_TAB = true;
    window.TM_HIDE_REQUESTOR_ADMIN_CHAT_BUTTON = true;
    </script>
    <script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
    <script>
    document.querySelectorAll('.ticket-row').forEach(function(row){
        row.addEventListener('click', function(){
            var id = this.getAttribute('data-id');
            TMTicketModal.open(id);
        });
    });
    var modal = document.getElementById('ticketModal');
    modal.addEventListener('click', function(e){ if(e.target === modal) TMTicketModal.close(); });
    var p = new URLSearchParams(window.location.search);
    var tid = p.get('ticket_id') || p.get('id');
    if (tid) {
        TMTicketModal.open(tid);
    }
    </script>

    
</body>
</html>
