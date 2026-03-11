<?php
require_once '../config/database.php';

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

/* Mark all as read if requested */
if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    $_SESSION['success'] = "All notifications marked as read.";
    header("Location: notifications.php");
    exit();
}

/* Get notifications */
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$total_res = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_id = $user_id");
$total = $total_res->fetch_assoc()['c'];
$total_pages = ceil($total / $limit);

$stmt = $conn->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
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
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
            border-left: 4px solid #16a34a;
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
        }
        .notif-content {
            flex-grow: 1;
        }
        .notif-text {
            font-size: 0.95rem;
            color: #334155;
            margin-bottom: 4px;
        }
        .notif-date {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 20px;
        }
        .page-link {
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            text-decoration: none;
            color: #64748b;
        }
        .page-link.active {
            background-color: #16a34a;
            color: white;
            border-color: #16a34a;
        }
        .mark-read-btn {
            background: none;
            border: none;
            color: #16a34a;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .mark-read-btn:hover {
            text-decoration: underline;
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success" style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?= $_SESSION['success']; ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h1 class="page-title">Notifications</h1>
                <?php if($total > 0): ?>
                <form method="POST" style="display: inline;">
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
                            $iconClass = 'fa-info-circle';
                            $bgClass = '#e2e8f0';
                            $colorClass = '#64748b';
                            
                            switch($row['type']) {
                                case 'status_update':
                                    $iconClass = 'fa-sync-alt';
                                    $bgClass = '#dbeafe';
                                    $colorClass = '#2563eb';
                                    break;
                                case 'ticket_closed':
                                    $iconClass = 'fa-check-circle';
                                    $bgClass = '#dcfce7';
                                    $colorClass = '#16a34a';
                                    break;
                                case 'note_added':
                                    $iconClass = 'fa-sticky-note';
                                    $bgClass = '#fef9c3';
                                    $colorClass = '#ca8a04';
                                    break;
                                case 'reassigned':
                                    $iconClass = 'fa-exchange-alt';
                                    $bgClass = '#f3e8ff';
                                    $colorClass = '#9333ea';
                                    break;
                                case 'dept_assigned':
                                    $iconClass = 'fa-inbox';
                                    $bgClass = '#e0f2fe';
                                    $colorClass = '#0284c7';
                                    break;
                            }
                        ?>
                        <div class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?>" 
                             onclick="markAsRead(<?= $row['id'] ?>, <?= $row['ticket_id'] ?>, '<?= htmlspecialchars($row['type'], ENT_QUOTES) ?>')">
                            <div class="notif-icon" style="background-color: <?= $bgClass ?>; color: <?= $colorClass ?>;">
                                <i class="fas <?= $iconClass ?>"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-text"><?= htmlspecialchars($row['message']) ?></div>
                                <div class="notif-date" data-timestamp="<?= htmlspecialchars($row['created_at']) ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                            </div>
                            <?php if($row['is_read'] == 0): ?>
                                <div style="width: 8px; height: 8px; background: #16a34a; border-radius: 50%;"></div>
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
            <div class="pagination">
                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

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
</script>
<script src="../js/employee-dashboard.js"></script>
</body>
</html>

