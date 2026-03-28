<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/notification_service.php';

notif_ensure_action_type_column($conn);

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* Mark all as read if requested */
if (isset($_POST['mark_all_read'])) {
    csrf_validate();
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    $_SESSION['success'] = "All notifications marked as read.";
    header("Location: notifications.php");
    exit();
}

/* Get notifications */
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;

$total_res = $conn->query("
    SELECT COUNT(*) as c
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = $user_id
      AND n.type <> 'chat_message'
      AND (n.type <> 'note_added' OR t.user_id = n.user_id)
");
if (!$total_res) {
    die("SQL Error: " . $conn->error);
}
$total = $total_res->fetch_assoc()['c'];
$total_pages = max(1, (int) ceil($total / $limit));
$page = max(1, min($page, $total_pages));
$offset = ($page - 1) * $limit;

$sql = "
    SELECT n.*, t.priority
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = ?
      AND n.type <> 'chat_message'
      AND (n.type <> 'note_added' OR t.user_id = n.user_id)
    ORDER BY n.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
}
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .admin-page {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        .admin-container {
            flex: 1;
            padding: 20px;
            background-color: #f8fafc;
        }
        .admin-content {
            max-width: 1000px;
            margin: 0 auto;
        }
        .notif-list-page {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .notif-item-row {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }
        .notif-item-row:hover {
            background-color: #f8fafc;
        }
        .notif-item-row.unread {
            background-color: #f0fdf4;
        }

        .notif-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            color: #ffffff;
        }
.notif-icon.type-assigned { background: #2563eb; }
.notif-icon.type-updated { background: #2563eb; }
.notif-icon.type-reassigned { background: #9333ea; }
.notif-icon.type-closed { background: #16a34a; }
.notif-icon.type-note { background: #ca8a04; }
.notif-icon.type-neutral { background: #94a3b8; }

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
            flex-grow: 1;
        }
        .notif-text {
            font-size: 0.95rem;
            color: #334155;
            margin-bottom: 4px;
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
        .notif-date {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 20px;
            flex-wrap: wrap;
        }
        .page-link {
            min-width: 40px;
            height: 40px;
            padding: 0 15px;
            border: 1px solid #d7e2ea;
            border-radius: 999px;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            transition: all 0.2s ease;
        }
        .page-link:hover { background: #f8fafc; transform: translateY(-1px); border-color: #cbd5e1; }
        .page-link.active {
            background-color: #166534;
            color: white;
            border-color: #166534;
            box-shadow: 0 10px 18px rgba(22, 101, 52, 0.22);
        }
        .pagination-glass {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 24px;
            padding: 0 6px 10px;
        }
        .page-numbers {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .page-btn {
            min-width: 40px;
            height: 40px;
            padding: 0 15px;
            border: 1px solid #d7e2ea;
            border-radius: 999px;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .page-btn:hover:not(.active):not(.disabled) {
            background: #f8fafc;
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }
        .page-btn.active {
            background: #166534;
            color: white;
            border-color: #166534;
            box-shadow: 0 10px 18px rgba(22, 101, 52, 0.22);
        }
        .page-btn.disabled {
            opacity: 0.45;
            pointer-events: none;
            box-shadow: none;
        }
        .page-btn.prev,
        .page-btn.next {
            padding: 0 18px;
        }
        .page-ellipsis {
            color: #94a3b8;
            font-weight: 700;
            padding: 0 4px;
            user-select: none;
        }
        .mark-read-btn {
            background: none;
            border: none;
            color: #1f6f3f;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .mark-read-btn:hover {
            text-decoration: underline;
        }
        .page-header {
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 20px;
        }
        .page-title {
            font-size: 1.5rem;
            color: #1f2937;
            font-weight: 600;
        }
        @media (max-width: 768px) {
            .pagination-glass {
                justify-content: flex-start;
            }
            .page-btn.prev,
            .page-btn.next {
                padding: 0 14px;
            }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <div class="admin-page">
        <?php include '../includes/admin_navbar.php'; ?>

        <div class="admin-container">
            <div class="admin-content">

                <?php if(isset($_SESSION['success'])): ?>
                    <div class="alert alert-success" style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <div class="page-header">
                    <h1 class="page-title">Notifications</h1>
                    <?php if($total > 0): ?>
                    <form method="POST" style="display: inline;">
                        <?php echo csrf_field(); ?>
                        <button type="submit" name="mark_all_read" class="mark-read-btn">
                            <i class="fas fa-check-double"></i> Mark all as read
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="notif-list-page">
                    <?php if($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <?php
                                $priorityKey = '';
                                if (!empty($row['priority'])) {
                                    $p = strtolower((string) $row['priority']);
                                    if (in_array($p, ['critical', 'high', 'medium', 'low'], true)) {
                                        $priorityKey = $p;
                                    }
                                }
                                $priorityClass = $priorityKey !== '' ? 'priority-' . $priorityKey : 'priority-neutral';
                                $ticketIdJs = isset($row['ticket_id']) && $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
                                $typeKey = (string) ($row['type'] ?? '');
                                $actionType = notif_normalize_action_type((string) ($row['action_type'] ?? ''), $typeKey);
                                $priorityLabel = ($typeKey !== 'note_added' && $priorityKey !== '')
                                    ? '<span class="priority-badge ' . $priorityClass . '">' . htmlspecialchars(ucfirst($priorityKey), ENT_QUOTES, 'UTF-8') . '</span>'
                                    : '';
                                $iconClass = 'fa-ticket';
                                $iconTypeClass = 'type-neutral';
                                if ($actionType === 'update' && $typeKey === 'note_added') {
                                    $iconClass = 'fa-sticky-note';
                                    $iconTypeClass = 'type-note';
                                } elseif ($actionType === 'update') {
                                    $iconClass = 'fa-sync-alt';
                                    $iconTypeClass = 'type-updated';
                                } elseif ($actionType === 'close') {
                                    $iconClass = 'fa-check-circle';
                                    $iconTypeClass = 'type-closed';
                                } elseif ($actionType === 'reassign') {
                                    $iconClass = 'fa-exchange-alt';
                                    $iconTypeClass = 'type-reassigned';
                                } elseif ($actionType === 'assign') {
                                    $iconClass = 'fa-inbox';
                                    $iconTypeClass = 'type-assigned';
                                }
                                $displayMessage = notif_display_message($typeKey, (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
                            ?>
                            <div class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?>" 
                                 onclick="markAsRead(<?= (int) $row['id'] ?>, <?= json_encode($ticketIdJs) ?>)">
                                <div class="notif-icon <?= htmlspecialchars($iconTypeClass, ENT_QUOTES, 'UTF-8') ?>"><i class="fas <?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?>"></i></div>
                                <div class="notif-content">
                                    <div class="notif-text"><?= $priorityLabel ?><?= notif_message_highlight_html($displayMessage) ?></div>
                                    <div class="notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                                </div>
                                <?php if($row['is_read'] == 0): ?>
                                    <div style="width: 8px; height: 8px; background: #1f6f3f; border-radius: 50%;"></div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding: 40px; text-align: center; color: #94a3b8;">
                            <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i>
                            <p>No notifications found.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if($total_pages > 1): ?>
                <div class="pagination-glass">
                    <a href="?page=<?= max(1, $page - 1); ?>" class="page-btn prev <?= ($page <= 1) ? 'disabled' : ''; ?>">&lsaquo; Previous</a>
                    <div class="page-numbers">
                        <?php
                            $window = 2;
                            $start_page = max(1, $page - $window);
                            $end_page = min($total_pages, $page + $window);
                            if ($start_page > 1):
                        ?>
                            <a href="?page=1" class="page-btn <?= ($page == 1) ? 'active' : ''; ?>">1</a>
                            <?php if ($start_page > 2): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <a href="?page=<?= $i; ?>" class="page-btn <?= ($i == $page) ? 'active' : ''; ?>"><?= $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < ($total_pages - 1)): ?>
                                <span class="page-ellipsis">...</span>
                            <?php endif; ?>
                            <a href="?page=<?= $total_pages; ?>" class="page-btn <?= ($page == $total_pages) ? 'active' : ''; ?>"><?= $total_pages; ?></a>
                        <?php endif; ?>
                    </div>
                    <a href="?page=<?= min($total_pages, $page + 1); ?>" class="page-btn next <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">Next &rsaquo;</a>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

<script>
// Auto-update relative times every 60 seconds
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
function updateRelativeTimesList() {
    document.querySelectorAll('.notif-date[data-timestamp]').forEach(el => {
        const ts = el.getAttribute('data-timestamp');
        el.textContent = toRelative(ts);
    });
}
document.addEventListener('DOMContentLoaded', function() {
    updateRelativeTimesList();
    setInterval(updateRelativeTimesList, 60000);
});

const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;

// Mark as Read & Redirect
function markAsRead(id, ticketId) {
    // Send request to mark as read
    const formData = new FormData();
    formData.append('id', id);
    if (CSRF_TOKEN) formData.append('csrf_token', CSRF_TOKEN);

    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).then(() => {
        const dest = ticketId ? `all_tickets.php?ticket_id=${ticketId}` : 'notifications.php';
        window.location.href = dest;
    });
}
</script>
<script src="../js/admin.js"></script>
</body>
</html>
