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
notif_backfill_priority_escalation_notifications($conn);

$clearAdminPendingChat = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE user_id = ?
      AND type = 'hr_chat_pending'
      AND is_read = 0
");
if ($clearAdminPendingChat) {
    $clearAdminPendingChat->bind_param("i", $user_id);
    $clearAdminPendingChat->execute();
    $clearAdminPendingChat->close();
}

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
      AND n.type <> 'hr_chat_pending'
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
      AND n.type <> 'hr_chat_pending'
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

function notif_section_label($datetime) {
    $itemDate = new DateTime($datetime);
    $today = new DateTime('today');
    $yesterday = new DateTime('yesterday');
    if ($itemDate >= $today) return 'Today';
    if ($itemDate >= $yesterday) return 'Yesterday';
    return $itemDate->format('F j, Y');
}

function notif_priority_from_message(string $message): string
{
    $transition = notif_priority_transition_from_message($message);
    $to = strtolower((string) ($transition['to'] ?? ''));
    if ($to === 'breach') return 'high';
    if ($to === 'at risk') return 'medium';
    if ($to === 'on track') return 'low';
    return $to;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Leads DeskMetamorph</title>
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
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
            max-width: 860px;
        }
        .notif-section-label {
            font-size: 1.08rem;
            font-weight: 700;
            color: #374151;
            margin: 0 0 16px;
            padding-left: 4px;
        }
        .notif-section-card {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            border: 0;
            overflow: visible;
            margin-bottom: 18px;
        }
        .notif-item-row {
            position: relative;
            padding: 16px 24px 16px 22px;
            border-bottom: 1px solid #eef2f7;
            border-radius: 16px;
            display: flex;
            align-items: flex-start;
            gap: 0;
            transition: background 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            background: #ffffff;
            margin-bottom: 10px;
        }
        .notif-item-row > * {
            pointer-events: none;
        }
        .notif-item-row:last-child {
            border-bottom: 1px solid #eef2f7;
            margin-bottom: 0;
        }
        .notif-item-row::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 7px;
            background: var(--notif-accent, #cbd5e1);
        }
        .notif-item-row:hover {
            background-color: #fbfdff;
        }
        .notif-item-row.notif-chat-pending {
            border-left: 7px solid #1B5E20;
        }
        .notif-item-row.notif-chat-pending::before {
            display: none;
        }
        .notif-item-row.unread {
            background-color: #ffffff;
        }
        .notif-item-row.unread::after {
            content: "";
            position: absolute;
            right: 16px;
            top: 22px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: var(--notif-dot, #5aa364);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
        }

        .notif-list-page .notif-content {
            flex-grow: 1;
            min-width: 0;
        }
        .notif-list-page .notif-text {
            font-size: 0.92rem;
            color: #1f2937;
            margin-bottom: 8px;
            line-height: 1.4;
        }
        .notif-list-page .notif-title {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 5px;
        }
        .notif-list-page .notif-title-text {
            font-size: 0.92rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
        }
        .notif-list-page .notif-pill {
            min-height: 26px;
            border-radius: 11px;
            border: 2px solid currentColor;
            background: #ffffff;
        }
        .notif-list-page .notif-pill-icon {
            width: 28px;
            height: 26px;
            font-size: 13px;
        }
        .notif-list-page .notif-pill-text {
            padding: 0 16px 0 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.01em;
            line-height: 1;
        }
        .notif-list-page .notif-pill.notif-priority-breach-pill {
            min-height: 26px;
            border-radius: 11px;
            border: 2px solid currentColor;
            background: #ffffff;
        }
        .notif-list-page .notif-pill.notif-priority-breach-pill .notif-pill-icon {
            width: 28px;
            height: 26px;
            font-size: 13px;
        }
        .notif-list-page .notif-pill.notif-priority-breach-pill .notif-pill-text {
            padding: 0 16px 0 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.01em;
        }
        .notif-item-row.notif-priority-escalation {
            background: linear-gradient(180deg, #fffafa 0%, #fff5f5 100%);
            border-color: #fecaca;
        }
        .notif-item-row.notif-priority-escalation:hover {
            background: linear-gradient(180deg, #fff7f7 0%, #feecec 100%);
        }
        .notif-item-row.notif-follow-up {
            background: linear-gradient(180deg, #fffdf4 0%, #fff9e7 100%);
        }
        .notif-item-row.notif-follow-up::before {
            background: #f4c542;
        }
        .notif-item-row.notif-follow-up.unread::after {
            background: #f4c542;
        }
        .notif-item-row.notif-follow-up .notif-title-text {
            color: #111827;
        }
        .notif-item-row.notif-follow-up .notif-text strong {
            color: #8a5b00;
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
        .notif-list-page .notif-date {
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
            max-width: 860px;
            width: 100%;
            gap: 16px;
            margin-bottom: 20px;
        }
        .page-title {
            margin: 0;
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
                        <?php $currentSection = null; ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <?php
                                $sectionLabel = notif_section_label((string) ($row['created_at'] ?? 'now'));
                                $typeKey = (string) ($row['type'] ?? '');
                                $priorityKey = $typeKey === 'priority_escalated'
                                    ? notif_priority_from_message((string) ($row['message'] ?? ''))
                                    : '';
                                if ($typeKey !== 'priority_escalated' && $priorityKey === '' && !empty($row['priority'])) {
                                    $p = strtolower((string) $row['priority']);
                                    if (in_array($p, ['critical', 'high', 'medium', 'low'], true)) {
                                        $priorityKey = $p;
                                    }
                                }
                                $ticketIdJs = isset($row['ticket_id']) && $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
                                $actionType = notif_normalize_action_type((string) ($row['action_type'] ?? ''), $typeKey);
                                $iconClass = 'fa-ticket';
                                $accentColor = '#94a3b8';
                                $dotColor = '#94a3b8';
                                $customTitle = trim((string) ($row['title'] ?? ''));
                                $titleText = $customTitle !== '' ? $customTitle : 'Ticket Update';
                                if ($typeKey === 'priority_escalated' && in_array($priorityKey, ['high', 'critical'], true)) {
                                    $iconClass = 'fa-exclamation';
                                    $accentColor = '#ef4444';
                                    $dotColor = '#ef4444';
                                    $titleText = 'Priority Escalation';
                                } elseif ($typeKey === 'priority_escalated' && $priorityKey === 'medium') {
                                    $iconClass = 'fa-exclamation';
                                    $accentColor = '#d4a017';
                                    $dotColor = '#d4a017';
                                    $titleText = 'Priority Escalation';
                                } elseif ($priorityKey === 'critical') {
                                    $iconClass = 'fa-exclamation';
                                    $accentColor = '#E53935';
                                    $dotColor = '#E53935';
                                    $titleText = 'Priority Escalation';
                                } elseif ($priorityKey === 'high') {
                                    $iconClass = 'fa-exclamation';
                                    $accentColor = '#ef4444';
                                    $dotColor = '#ef4444';
                                    $titleText = 'Ticket Warning';
                                } elseif ($priorityKey === 'medium') {
                                    $iconClass = 'fa-triangle-exclamation';
                                    $accentColor = '#eab308';
                                    $dotColor = '#eab308';
                                } elseif ($priorityKey === 'low') {
                                    $iconClass = 'fa-arrow-down';
                                    $accentColor = '#22c55e';
                                    $dotColor = '#22c55e';
                                }
                                $isFollowUp = $typeKey === 'follow_up';
                                if ($isFollowUp) {
                                    $iconClass = 'fa-rotate';
                                    $accentColor = '#d4a017';
                                    $dotColor = '#d4a017';
                                    $titleText = 'Follow Up Request';
                                } elseif ($typeKey === 'conference_booking') {
                                    $iconClass = 'fa-calendar-check';
                                    $accentColor = '#0f766e';
                                    $dotColor = '#0f766e';
                                    if ($customTitle === '') {
                                        $titleText = 'Conference Booking';
                                    }
                                } elseif ($actionType === 'update' && $typeKey === 'note_added') {
                                    $iconClass = 'fa-sticky-note';
                                    if ($priorityKey === '') {
                                        $accentColor = '#ca8a04';
                                        $dotColor = '#ca8a04';
                                        $titleText = 'Ticket Note';
                                    }
                                } elseif ($actionType === 'update') {
                                    $iconClass = 'fa-rotate';
                                    $accentColor = '#0f766e';
                                    $dotColor = '#0f766e';
                                    $titleText = 'Status Update';
                                } elseif ($actionType === 'close') {
                                    if ($priorityKey === '') {
                                        $iconClass = 'fa-check';
                                        $accentColor = '#43A047';
                                        $dotColor = '#43A047';
                                        $titleText = 'Ticket Closed';
                                    }
                                } elseif ($actionType === 'reassign') {
                                    $iconClass = 'fa-retweet';
                                    $accentColor = '#9333ea';
                                    $dotColor = '#9333ea';
                                    $titleText = 'Ticket Reassigned';
                                } elseif ($actionType === 'assign') {
                                    if ($priorityKey === '') {
                                        $iconClass = 'fa-inbox';
                                        $accentColor = '#2563eb';
                                        $dotColor = '#2563eb';
                                        $titleText = 'Ticket Assigned';
                                    } elseif ($priorityKey === 'low') {
                                        $titleText = 'Ticket Assigned';
                                    }
                                }
                                if ($typeKey === 'hr_chat_pending') {
                                    $iconClass = 'fa-comments';
                                    $accentColor = '#1B5E20';
                                    $dotColor = '#1B5E20';
                                    $titleText = 'Pending Chat';
                                }
                                if ($customTitle === '') {
                                    if ($typeKey === 'priority_escalated') {
                                        if (in_array($priorityKey, ['critical', 'high'], true)) {
                                            $titleText = 'Priority Escalation';
                                        } elseif ($priorityKey === 'low') {
                                            $titleText = 'Ticket Assigned';
                                        } else {
                                            $titleText = 'Ticket Update';
                                        }
                                    } elseif ($typeKey === 'conference_booking') {
                                        $titleText = 'Conference Booking';
                                    } elseif ($actionType === 'assign') {
                                        $titleText = 'Ticket Assigned';
                                    } elseif ($actionType === 'reassign') {
                                        $titleText = 'Ticket Reassigned';
                                    } elseif ($actionType === 'close') {
                                        $titleText = 'Ticket Closed';
                                    } elseif ($typeKey === 'follow_up') {
                                        $titleText = 'Follow Up Request';
                                    } elseif ($actionType === 'update' && $typeKey === 'note_added') {
                                        $titleText = 'Ticket Note';
                                    } elseif ($actionType === 'update') {
                                        $titleText = 'Status Update';
                                    } else {
                                        $titleText = 'Ticket Update';
                                    }
                                }
                                $pillVariantClass = 'variant-update';
                                $pillText = 'Update';
                                $pillIconClass = 'fa-rotate';
                                if ($isFollowUp) {
                                    $pillVariantClass = 'variant-follow-up';
                                    $pillText = 'Follow Up';
                                    $pillIconClass = 'fa-rotate';
                                } elseif ($typeKey === 'conference_booking') {
                                    $pillVariantClass = 'variant-booking';
                                    $pillText = 'Booking';
                                    $pillIconClass = 'fa-calendar-check';
                                } elseif ($typeKey === 'hr_chat_pending') {
                                    $pillVariantClass = 'variant-update';
                                    $pillText = 'Chat';
                                    $pillIconClass = 'fa-comments';
                                } elseif ($priorityKey === 'critical') {
                                    $pillVariantClass = 'variant-critical';
                                    $pillText = 'Critical';
                                    $pillIconClass = 'fa-exclamation';
                                } elseif ($priorityKey === 'high') {
                                    $pillVariantClass = $typeKey === 'priority_escalated' ? 'variant-high notif-priority-breach-pill' : 'variant-high';
                                    $pillText = 'High';
                                    $pillIconClass = 'fa-exclamation';
                                } elseif ($priorityKey === 'medium') {
                                    $pillVariantClass = 'variant-medium';
                                    $pillText = 'Medium';
                                    $pillIconClass = 'fa-triangle-exclamation';
                                } elseif ($priorityKey === 'low') {
                                    $pillVariantClass = 'variant-low';
                                    $pillText = 'Low';
                                    $pillIconClass = 'fa-arrow-down';
                                } elseif ($actionType === 'assign') {
                                    $pillVariantClass = 'variant-assign';
                                    $pillText = 'Assigned';
                                    $pillIconClass = 'fa-check';
                                } elseif ($actionType === 'reassign') {
                                    $pillVariantClass = 'variant-reassign';
                                    $pillText = 'Reassigned';
                                    $pillIconClass = 'fa-retweet';
                                } elseif ($actionType === 'close') {
                                    $pillVariantClass = 'variant-close';
                                    $pillText = 'Closed';
                                    $pillIconClass = 'fa-check';
                                } elseif ($typeKey === 'note_added') {
                                    $pillVariantClass = 'variant-note';
                                    $pillText = 'Note';
                                    $pillIconClass = 'fa-plus';
                                }
                                $displayMessage = notif_display_message($typeKey, (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
                                $notificationHref = $typeKey === 'conference_booking'
                                    ? 'conference_bookings.php'
                                    : ($ticketIdJs ? ('all_tickets.php?ticket_id=' . (int) $ticketIdJs) : 'notifications.php');
                            ?>
                            <?php if ($sectionLabel !== $currentSection): ?>
                                <?php if ($currentSection !== null): ?>
                                    </div>
                                <?php endif; ?>
                                <div class="notif-section-label"><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="notif-section-card">
                                <?php $currentSection = $sectionLabel; ?>
                            <?php endif; ?>
                            <a class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?> <?= $typeKey === 'follow_up' ? 'notif-follow-up' : '' ?> <?= $typeKey === 'hr_chat_pending' ? 'notif-chat-pending' : '' ?> <?= $typeKey === 'priority_escalated' ? 'notif-priority-escalation' : '' ?>"
                               href="<?= htmlspecialchars($notificationHref, ENT_QUOTES, 'UTF-8') ?>"
                               data-notification-id="<?= (int) $row['id'] ?>"
                               data-ticket-id="<?= $ticketIdJs !== null ? (int) $ticketIdJs : '' ?>"
                               data-notification-type="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>"
                               style="--notif-accent: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>; --notif-dot: <?= htmlspecialchars($dotColor, ENT_QUOTES, 'UTF-8') ?>;"
                               onclick="return handleNotificationRowClick(event, this);">
                                <div class="notif-content">
                                    <div class="notif-title">
                                        <span class="notif-pill <?= htmlspecialchars($pillVariantClass, ENT_QUOTES, 'UTF-8') ?>">
                                            <span class="notif-pill-icon"><i class="fas <?= htmlspecialchars($pillIconClass, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                                            <span class="notif-pill-text"><?= htmlspecialchars($pillText, ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                        <span class="notif-title-text"><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="notif-text"><?= notif_message_highlight_html($displayMessage) ?></div>
                                    <div class="notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                        <?php if ($currentSection !== null): ?>
                            </div>
                        <?php endif; ?>
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

function handleNotificationRowClick(event, element) {
    if (!element) {
        return true;
    }

    if (event) {
        event.preventDefault();
    }

    const notificationId = Number(element.getAttribute('data-notification-id') || 0);
    const ticketIdValue = element.getAttribute('data-ticket-id') || '';
    const ticketId = ticketIdValue === '' ? null : Number(ticketIdValue);
    const notificationType = element.getAttribute('data-notification-type') || '';

    markAsRead(notificationId, ticketId, notificationType, element.getAttribute('href') || 'notifications.php');
    return false;
}

// Mark as Read & Redirect
function markAsRead(id, ticketId, type, fallbackHref) {
    // Send request to mark as read
    const formData = new FormData();
    formData.append('id', id);
    if (CSRF_TOKEN) formData.append('csrf_token', CSRF_TOKEN);

    const dest = fallbackHref || ((type || '').toString() === 'conference_booking'
        ? 'conference_bookings.php'
        : (ticketId ? `all_tickets.php?ticket_id=${ticketId}` : 'notifications.php'));

    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).catch(() => {
        // Still open the target page even if the read update fails.
    }).finally(() => {
        window.location.href = dest;
    });
}
</script>
<script src="../js/admin.js"></script>
</body>
</html>
