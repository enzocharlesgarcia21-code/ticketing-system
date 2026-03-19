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

$stmt = $conn->prepare("
    SELECT * FROM employee_tickets
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$successMessage = isset($_SESSION['success']) ? (string) $_SESSION['success'] : '';
unset($_SESSION['success']);
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
        .ticket-success-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }
        .ticket-success-overlay.show {
            display: flex;
        }
        .ticket-success-card {
            width: 360px;
            max-width: calc(100vw - 40px);
            background: #ffffff;
            border-radius: 18px;
            padding: 24px 22px 20px;
            text-align: center;
            border: 1px solid rgba(27, 94, 32, 0.18);
            box-shadow: 0 26px 80px rgba(2, 6, 23, 0.22);
            position: relative;
            overflow: hidden;
        }
        .ticket-success-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 6px;
            background: linear-gradient(90deg, #1B5E20, #144a1e);
        }
        .ticket-success-icon {
            width: 64px;
            height: 64px;
            margin: 8px auto 14px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #15803d;
            font-size: 28px;
            font-weight: 900;
        }
        .ticket-success-title {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }
        .ticket-success-text {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }
        .ticket-success-actions {
            margin-top: 18px;
            display: flex;
            justify-content: center;
        }
        .ticket-success-btn {
            border: 1px solid rgba(20, 74, 30, 0.28);
            background: #1B5E20;
            color: #ffffff;
            border-radius: 12px;
            padding: 10px 18px;
            font-weight: 800;
            cursor: pointer;
        }
        .ticket-success-btn:hover {
            background: #144a1e;
        }
    </style>
</head>
<body>

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
            <h2 id="ticketSuccessTitle" class="ticket-success-title">Your ticket has been submitted</h2>
            <p class="ticket-success-text"><?= htmlspecialchars($successMessage !== '' ? $successMessage : 'Your ticket has been submitted successfully.', ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="ticket-success-actions">
                <button type="button" id="ticketSuccessBtn" class="ticket-success-btn">OK</button>
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
    var ticketSuccessOverlay = document.getElementById('ticketSuccessOverlay');
    var ticketSuccessBtn = document.getElementById('ticketSuccessBtn');
    var hasSuccessMessage = <?php echo json_encode($successMessage !== ''); ?>;
    if (hasSuccessMessage && ticketSuccessOverlay) {
        ticketSuccessOverlay.classList.add('show');
        ticketSuccessOverlay.setAttribute('aria-hidden', 'false');
    }
    if (ticketSuccessBtn && ticketSuccessOverlay) {
        ticketSuccessBtn.addEventListener('click', function () {
            ticketSuccessOverlay.classList.remove('show');
            ticketSuccessOverlay.setAttribute('aria-hidden', 'true');
        });
    }
    if (ticketSuccessOverlay) {
        ticketSuccessOverlay.addEventListener('click', function (e) {
            if (e.target === ticketSuccessOverlay) {
                ticketSuccessOverlay.classList.remove('show');
                ticketSuccessOverlay.setAttribute('aria-hidden', 'true');
            }
        });
    }
    </script>

    
</body>
</html>
