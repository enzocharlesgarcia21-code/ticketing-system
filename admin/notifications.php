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
      AND n.type <> 'conference_booking_created'
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
    SELECT n.*, t.priority, t.assigned_department, t.assigned_group, t.department AS ticket_department
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = ?
      AND n.type <> 'chat_message'
      AND n.type <> 'hr_chat_pending'
      AND n.type <> 'conference_booking_created'
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

$notificationBaseWhere = "
    n.user_id = $user_id
    AND n.type <> 'chat_message'
    AND n.type <> 'hr_chat_pending'
    AND n.type <> 'conference_booking_created'
    AND (n.type <> 'note_added' OR t.user_id = n.user_id)
";

function admin_notification_scalar(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_assoc();
    $res->free();
    return (int) ($row['c'] ?? 0);
}

$summaryUnread = admin_notification_scalar($conn, "
    SELECT COUNT(*) AS c
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE $notificationBaseWhere
      AND n.is_read = 0
");
$summaryHighPriority = admin_notification_scalar($conn, "
    SELECT COUNT(*) AS c
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE $notificationBaseWhere
      AND (
        LOWER(COALESCE(t.priority, '')) IN ('high', 'critical')
        OR n.type = 'priority_escalated'
        OR LOWER(COALESCE(n.message, '')) LIKE '%breach%'
      )
");
$summaryUnassigned = admin_notification_scalar($conn, "
    SELECT COUNT(*) AS c
    FROM employee_tickets
    WHERE LOWER(TRIM(COALESCE(status, ''))) NOT IN ('resolved', 'closed', 'trash')
      AND (assigned_to IS NULL OR assigned_to = 0)
      AND (assigned_user_id IS NULL OR assigned_user_id = 0)
");
$summaryOverdue = admin_notification_scalar($conn, "
    SELECT COUNT(*) AS c
    FROM employee_tickets
    WHERE created_at IS NOT NULL
      AND DATEDIFF(CURDATE(), DATE(created_at)) >= 6
      AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('resolved', 'closed', 'trash')
");

$departmentOptions = [];
$departmentRes = $conn->query("
    SELECT DISTINCT TRIM(COALESCE(NULLIF(assigned_group, ''), NULLIF(assigned_department, ''), NULLIF(department, ''))) AS dept
    FROM employee_tickets
    WHERE TRIM(COALESCE(NULLIF(assigned_group, ''), NULLIF(assigned_department, ''), NULLIF(department, ''))) <> ''
    ORDER BY dept ASC
    LIMIT 30
");
if ($departmentRes) {
    while ($deptRow = $departmentRes->fetch_assoc()) {
        $dept = trim((string) ($deptRow['dept'] ?? ''));
        if ($dept !== '') $departmentOptions[] = $dept;
    }
    $departmentRes->free();
}

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

function admin_conference_booking_summary(string $message): array
{
    $message = trim($message);
    $payload = json_decode($message, true);
    if (is_array($payload)) {
        $booker = trim((string) ($payload['user_email'] ?? $payload['booked_by_email'] ?? 'Someone'));
        $room = trim((string) ($payload['room_name'] ?? $payload['room'] ?? 'the room'));
        $dateValue = trim((string) ($payload['booking_date'] ?? ''));
        $date = $dateValue !== '' && strtotime($dateValue) !== false ? date('M d, Y', strtotime($dateValue)) : '';
        return [
            'booker' => $booker !== '' ? $booker : 'Someone',
            'room' => $room !== '' ? $room : 'the room',
            'date' => $date,
        ];
    }

    $booker = 'Someone';
    $room = 'the room';
    $date = '';
    if (preg_match('/^(.*?)\s+booked\s+(.*?)\s+for\s+(.*?)\s+from\s+/i', $message, $matches)) {
        $booker = trim((string) ($matches[1] ?? '')) ?: $booker;
        $room = trim((string) ($matches[2] ?? '')) ?: $room;
        $date = trim((string) ($matches[3] ?? ''));
    }

    return ['booker' => $booker, 'room' => $room, 'date' => $date];
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
    <link rel="stylesheet" href="../css/view-tickets.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --notif-green: #0f6b3a;
            --notif-soft-border: #e5edf4;
            --notif-text: #0f172a;
            --notif-muted: #64748b;
            --notif-page: #f6f8fb;
        }
        .admin-page {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        .admin-container {
            flex: 1;
            padding: 28px 32px 40px;
            background: var(--notif-page);
        }
        .admin-content {
            max-width: 1280px;
            margin: 0 auto;
        }
        .notif-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 22px;
            margin: 0 0 26px;
        }
        .notif-summary-card {
            display: flex;
            align-items: center;
            gap: 18px;
            min-height: 108px;
            padding: 22px 24px;
            background: #ffffff;
            border: 1px solid var(--notif-soft-border);
            border-radius: 12px;
            box-shadow: 0 14px 38px rgba(15, 23, 42, 0.06);
        }
        .notif-summary-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            flex: 0 0 auto;
        }
        .notif-summary-icon.icon-unread { color: #16a34a; background: #dcfce7; }
        .notif-summary-icon.icon-high { color: #ef4444; background: #fee2e2; }
        .notif-summary-icon.icon-unassigned { color: #eab308; background: #fef3c7; }
        .notif-summary-icon.icon-overdue { color: #f97316; background: #ffedd5; }
        .notif-summary-label {
            margin: 0 0 4px;
            color: #334155;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.2;
        }
        .notif-summary-value {
            color: var(--notif-green);
            font-size: 28px;
            font-weight: 900;
            line-height: 1;
        }
        .notif-summary-card.card-high .notif-summary-value { color: #ef4444; }
        .notif-summary-card.card-unassigned .notif-summary-value { color: #eab308; }
        .notif-summary-card.card-overdue .notif-summary-value { color: #f97316; }
        .notif-summary-subtitle {
            margin-top: 7px;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin: 0 0 22px;
        }
        .page-title {
            margin: 0;
            color: var(--notif-text);
            font-size: 32px;
            font-weight: 900;
            letter-spacing: 0;
        }
        .mark-read-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            border: none;
            color: #12833b;
            font-weight: 800;
            cursor: pointer;
            font-size: 13px;
            white-space: nowrap;
        }
        .mark-read-btn:hover {
            color: #0f6b3a;
        }
        .notif-toolbar {
            display: grid;
            grid-template-columns: minmax(260px, 1.05fr) minmax(360px, 1.35fr) 160px 170px 124px;
            align-items: center;
            gap: 14px;
            padding: 16px;
            margin: 0 0 16px;
            background: #ffffff;
            border: 1px solid #e8eef5;
            border-radius: 14px;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.055);
        }
        .notif-search-wrap {
            position: relative;
            min-width: 0;
        }
        .notif-search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 14px;
        }
        .notif-search-input {
            width: 100%;
            height: 42px;
            border: 1px solid #dfe8f1;
            border-radius: 9px;
            background: #ffffff;
            color: #334155;
            font-size: 13px;
            font-weight: 650;
            outline: none;
            box-sizing: border-box;
        }
        .notif-search-input {
            padding: 0 14px 0 38px;
        }
        .notif-select-shell {
            position: relative;
            min-width: 0;
        }
        .notif-filter-select {
            display: none;
        }
        .notif-select-trigger {
            width: 100%;
            height: 42px;
            border: 1px solid #cbdbea;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 14px;
            cursor: pointer;
            box-sizing: border-box;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .notif-select-trigger i {
            color: #0f6b3a;
            font-size: 12px;
            transition: transform 0.18s ease;
        }
        .notif-select-shell.open .notif-select-trigger {
            border-color: #8bb8d8;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.08);
        }
        .notif-select-shell.open .notif-select-trigger i {
            transform: rotate(180deg);
        }
        .notif-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 30;
            display: none;
            max-height: 248px;
            overflow-y: auto;
            padding: 6px;
            border: 1px solid #cbdbea;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
        }
        .notif-select-shell.open .notif-select-menu {
            display: block;
        }
        .notif-select-menu::-webkit-scrollbar {
            width: 9px;
        }
        .notif-select-menu::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 999px;
        }
        .notif-select-menu::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 999px;
        }
        .notif-select-option {
            width: 100%;
            min-height: 36px;
            border: 0;
            border-radius: 0;
            background: transparent;
            color: #0f172a;
            display: flex;
            align-items: center;
            padding: 0 12px;
            font-size: 13px;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
        }
        .notif-select-option:hover,
        .notif-select-option.is-selected {
            background: #eaf6ee;
        }
        .notif-clear-btn {
            height: 42px;
            border: 1px solid #dfe8f1;
            border-radius: 9px;
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }
        .notif-clear-btn:hover {
            background: #eef6f0;
            border-color: #bbf7d0;
            color: #0f6b3a;
        }
        .notif-search-input:focus,
        .notif-select-trigger:focus {
            border-color: #86efac;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.12);
            outline: none;
        }
        .notif-tabs {
            display: flex;
            align-items: center;
            gap: 22px;
            min-width: 0;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .notif-tabs::-webkit-scrollbar {
            display: none;
        }
        .notif-tab {
            appearance: none;
            border: none;
            background: transparent;
            color: #475569;
            cursor: pointer;
            padding: 10px 0;
            border-bottom: 2px solid transparent;
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }
        .notif-tab.active {
            color: #0f6b3a;
            border-bottom-color: #16a34a;
        }
        .notif-list-page {
            background: #ffffff;
            border: 1px solid #e8eef5;
            border-radius: 16px;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            padding: 14px;
        }
        .notif-section-label {
            font-size: 0.83rem;
            font-weight: 900;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 10px 4px 12px;
        }
        .notif-section-card {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 18px;
        }
        .notif-item-row {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto 12px;
            align-items: center;
            gap: 18px;
            min-height: 82px;
            padding: 18px 22px 18px 30px;
            border: 1px solid #e8eef5;
            border-radius: 14px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.045);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .notif-item-row > * {
            pointer-events: none;
        }
        .notif-item-row::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 6px;
            border-radius: 14px 0 0 14px;
            background: var(--notif-accent, #cbd5e1);
        }
        .notif-item-row:hover {
            transform: translateY(-1px);
            border-color: rgba(22, 163, 74, 0.28);
            box-shadow: 0 16px 30px rgba(15, 23, 42, 0.08);
        }
        .notif-item-row.unread::after {
            content: "";
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: var(--notif-dot, #16a34a);
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.96);
            grid-column: 3;
            justify-self: center;
        }
        .notif-item-row:not(.unread)::after {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #cbd5e1;
            grid-column: 3;
            justify-self: center;
        }
        .notif-item-row.notif-chat-pending::before {
            background: #1B5E20;
        }
        .notif-content {
            min-width: 0;
        }
        .notif-title {
            display: flex;
            align-items: center;
            gap: 18px;
            min-width: 0;
            margin-bottom: 7px;
        }
        .notif-title-text {
            color: #0f172a;
            font-size: 15px;
            font-weight: 900;
            line-height: 1.25;
        }
        .notif-text {
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.45;
            margin-bottom: 7px;
        }
        .notif-date {
            color: #64748b;
            font-size: 12px;
            font-weight: 650;
        }
        .notif-row-action {
            grid-column: 2;
            min-width: 112px;
            height: 36px;
            border: 1px solid #22c55e;
            border-radius: 8px;
            color: #12833b;
            background: #ffffff;
            font-size: 12px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }
        .notif-pill,
        .admin-booking-pill {
            display: inline-flex;
            align-items: stretch;
            height: 32px;
            border-radius: 9px;
            border: 2px solid currentColor;
            background: #ffffff;
            overflow: hidden;
            flex: 0 0 auto;
            color: #0f766e;
        }
        .notif-pill-icon,
        .admin-booking-icon {
            width: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            background: currentColor;
            font-size: 14px;
        }
        .notif-pill-icon i,
        .admin-booking-icon i {
            color: #ffffff;
        }
        .notif-pill-text,
        .admin-booking-label {
            display: inline-flex;
            align-items: center;
            padding: 0 14px 0 10px;
            font-size: 11px;
            font-weight: 900;
            line-height: 1;
        }
        .notif-pill.variant-low,
        .notif-pill.variant-close { color: #22c55e; }
        .notif-pill.variant-medium,
        .notif-pill.variant-follow-up { color: #eab308; }
        .notif-pill.variant-high,
        .notif-pill.variant-critical { color: #ef4444; }
        .notif-pill.variant-reassign { color: #9333ea; }
        .notif-pill.variant-assign,
        .notif-pill.variant-update { color: #0ea5e9; }
        .notif-pill.variant-note { color: #f97316; }
        .notif-pill.variant-booking,
        .admin-booking-pill { color: #0f766e; }
        .notif-item-row.notif-priority-escalation {
            background: #fffafa;
        }
        .notif-item-row.notif-follow-up {
            background: #fffdf4;
        }
        .notif-keyword {
            display: inline-flex;
            align-items: center;
            padding: 0.08rem 0.45rem;
            border-radius: 999px;
            font-size: 0.83em;
            font-weight: 800;
            line-height: 1.2;
            margin: 0 0.08rem;
            vertical-align: baseline;
        }
        .notif-keyword-success { background: #dcfce7; color: #166534; }
        .notif-keyword-info { background: #dbeafe; color: #1d4ed8; }
        .notif-keyword-assign { background: #e0f2fe; color: #0284c7; }
        .notif-keyword-reassign { background: #f3e8ff; color: #7e22ce; }
        .notif-keyword-generic { background: #e2e8f0; color: #475569; }
        .empty-notifications {
            padding: 56px 20px;
            text-align: center;
            color: #94a3b8;
        }
        .empty-notifications i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #cbd5e1;
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
            font-weight: 700;
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
        @media (max-width: 1180px) {
            .notif-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .notif-toolbar {
                grid-template-columns: minmax(260px, 1fr) minmax(0, 1fr) 150px 150px 124px;
            }
        }
        @media (max-width: 900px) {
            .admin-container {
                padding: 22px 16px 34px;
            }
            .notif-toolbar {
                grid-template-columns: 1fr;
            }
            .notif-tabs {
                order: 2;
            }
            .notif-item-row {
                grid-template-columns: minmax(0, 1fr);
                padding: 18px 18px 18px 26px;
            }
            .notif-row-action {
                grid-column: 1;
                justify-self: flex-start;
                margin-top: 4px;
            }
            .notif-item-row.unread::after,
            .notif-item-row:not(.unread)::after {
                position: absolute;
                right: 18px;
                top: 22px;
            }
        }
        @media (max-width: 640px) {
            .notif-summary-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }
            .page-title {
                font-size: 28px;
            }
            .notif-title {
                align-items: flex-start;
                flex-direction: column;
                gap: 8px;
            }
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

                <section class="notif-summary-grid" aria-label="Notification summary">
                    <article class="notif-summary-card">
                        <span class="notif-summary-icon icon-unread"><i class="far fa-bell"></i></span>
                        <div>
                            <p class="notif-summary-label">Unread Notifications</p>
                            <div class="notif-summary-value"><?= number_format($summaryUnread) ?></div>
                            <div class="notif-summary-subtitle">New items</div>
                        </div>
                    </article>
                    <article class="notif-summary-card card-high">
                        <span class="notif-summary-icon icon-high"><i class="fas fa-exclamation-triangle"></i></span>
                        <div>
                            <p class="notif-summary-label">High Priority</p>
                            <div class="notif-summary-value"><?= number_format($summaryHighPriority) ?></div>
                            <div class="notif-summary-subtitle">Requires attention</div>
                        </div>
                    </article>
                    <article class="notif-summary-card card-unassigned">
                        <span class="notif-summary-icon icon-unassigned"><i class="far fa-user"></i></span>
                        <div>
                            <p class="notif-summary-label">Unassigned Tickets</p>
                            <div class="notif-summary-value"><?= number_format($summaryUnassigned) ?></div>
                            <div class="notif-summary-subtitle">Need assignment</div>
                        </div>
                    </article>
                    <article class="notif-summary-card card-overdue">
                        <span class="notif-summary-icon icon-overdue"><i class="far fa-clock"></i></span>
                        <div>
                            <p class="notif-summary-label">Overdue / SLA Alerts</p>
                            <div class="notif-summary-value"><?= number_format($summaryOverdue) ?></div>
                            <div class="notif-summary-subtitle">Past due</div>
                        </div>
                    </article>
                </section>

                <div class="notif-toolbar" aria-label="Notification filters">
                    <label class="notif-search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="search" id="notifSearchInput" class="notif-search-input" placeholder="Search notifications..." autocomplete="off">
                    </label>
                    <div class="notif-tabs" role="tablist" aria-label="Notification category">
                        <button type="button" class="notif-tab active" data-filter-tab="all">All</button>
                        <button type="button" class="notif-tab" data-filter-tab="unread">Unread</button>
                        <button type="button" class="notif-tab" data-filter-tab="tickets">Tickets</button>
                        <button type="button" class="notif-tab" data-filter-tab="bookings">Bookings</button>
                        <button type="button" class="notif-tab" data-filter-tab="overdue">Overdue</button>
                    </div>
                    <div class="notif-select-shell" data-select-shell>
                        <select id="notifPriorityFilter" class="notif-filter-select" aria-label="Priority">
                            <option value="">Priority</option>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                        <button type="button" class="notif-select-trigger" data-select-trigger aria-haspopup="listbox" aria-expanded="false">
                            <span data-select-label>Priority</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="notif-select-menu" data-select-menu role="listbox">
                            <button type="button" class="notif-select-option is-selected" data-select-value="">Priority</button>
                            <button type="button" class="notif-select-option" data-select-value="low">Low</button>
                            <button type="button" class="notif-select-option" data-select-value="medium">Medium</button>
                            <button type="button" class="notif-select-option" data-select-value="high">High</button>
                            <button type="button" class="notif-select-option" data-select-value="critical">Critical</button>
                        </div>
                    </div>
                    <div class="notif-select-shell" data-select-shell>
                        <select id="notifDepartmentFilter" class="notif-filter-select" aria-label="Department">
                            <option value="">Department</option>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <option value="<?= htmlspecialchars(strtolower($departmentOption), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="notif-select-trigger" data-select-trigger aria-haspopup="listbox" aria-expanded="false">
                            <span data-select-label>Department</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="notif-select-menu" data-select-menu role="listbox">
                            <button type="button" class="notif-select-option is-selected" data-select-value="">Department</button>
                            <?php foreach ($departmentOptions as $departmentOption): ?>
                                <button type="button" class="notif-select-option" data-select-value="<?= htmlspecialchars(strtolower($departmentOption), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8') ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="button" class="notif-clear-btn" id="notifClearFilters">Clear Filters</button>
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
                                } elseif ($typeKey === 'conference_booking_deleted') {
                                    $iconClass = 'fa-calendar-xmark';
                                    $accentColor = '#0f766e';
                                    $dotColor = '#0f766e';
                                    $titleText = 'Conference Booking Deleted';
                                } elseif ($typeKey === 'conference_booking_cancelled') {
                                    $iconClass = 'fa-calendar-xmark';
                                    $accentColor = '#0f766e';
                                    $dotColor = '#0f766e';
                                    $titleText = 'Conference Booking Cancelled';
                                } elseif ($typeKey === 'conference_booking_created') {
                                    $iconClass = 'fa-calendar-check';
                                    $accentColor = '#0f766e';
                                    $dotColor = '#0f766e';
                                    $titleText = 'Conference Booking Created';
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
                                    if ($priorityKey === '') {
                                        $accentColor = '#0f766e';
                                        $dotColor = '#0f766e';
                                    }
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
                                    } elseif ($typeKey === 'conference_booking_deleted') {
                                        $titleText = 'Conference Booking Deleted';
                                    } elseif ($typeKey === 'conference_booking_cancelled') {
                                        $titleText = 'Conference Booking Cancelled';
                                    } elseif ($typeKey === 'conference_booking_created') {
                                        $titleText = 'Conference Booking Created';
                                    } elseif ($typeKey === 'conference_booking') {
                                        $titleText = 'Conference Booking';
                                    } elseif ($typeKey === 'ticket_claimed' || $actionType === 'claim') {
                                        $titleText = 'Ticket Claimed';
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
                                $isResolvedStatus = ($actionType === 'update' || $typeKey === 'status_update') && preg_match('/\bresolved\b/i', (string) ($row['message'] ?? ''));
                                if ($isResolvedStatus) {
                                    $titleText = 'Ticket Resolved';
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
                                } elseif ($actionType === 'claim' || $typeKey === 'ticket_claimed') {
                                    $pillVariantClass = 'variant-assign';
                                    $pillText = 'Claimed';
                                    $pillIconClass = 'fa-user-check';
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
                                if ($actionType === 'reassign') {
                                    $pillVariantClass = 'variant-reassign';
                                    $pillText = 'Reassigned';
                                    $pillIconClass = 'fa-retweet';
                                }
                                $displayMessage = notif_display_message($typeKey, (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
                                $notificationHref = $typeKey === 'conference_booking'
                                    ? 'conference_bookings.php'
                                    : ($ticketIdJs ? ('all_tickets.php?ticket_id=' . (int) $ticketIdJs) : 'notifications.php');
                                $filterCategory = 'system';
                                if (strpos($typeKey, 'conference_booking') === 0) {
                                    $filterCategory = 'bookings';
                                } elseif ($ticketIdJs !== null || strpos($typeKey, 'ticket') !== false || in_array($actionType, ['assign', 'reassign', 'update', 'close', 'claim'], true)) {
                                    $filterCategory = 'tickets';
                                } elseif (strpos($typeKey, 'user') !== false || strpos($typeKey, 'role') !== false) {
                                    $filterCategory = 'users';
                                }
                                $departmentFilterValue = strtolower(trim((string) (($row['assigned_group'] ?? '') !== '' ? $row['assigned_group'] : (($row['assigned_department'] ?? '') !== '' ? $row['assigned_department'] : ($row['ticket_department'] ?? '')))));
                                $searchBlob = strtolower(trim($titleText . ' ' . strip_tags($displayMessage) . ' ' . $pillText . ' ' . $departmentFilterValue));
                                $isOverdueFilter = ($typeKey === 'priority_escalated' || in_array($priorityKey, ['critical', 'high'], true) || preg_match('/\b(breach|overdue|past due)\b/i', (string) ($row['message'] ?? '')));
                            ?>
                            <?php if ($sectionLabel !== $currentSection): ?>
                                <?php if ($currentSection !== null): ?>
                                    </div>
                                <?php endif; ?>
                                <div class="notif-section-label"><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="notif-section-card">
                                <?php $currentSection = $sectionLabel; ?>
                            <?php endif; ?>
                            <?php if ($typeKey === 'conference_booking'): ?>
                                <?php
                                    $bookingSummary = admin_conference_booking_summary((string) ($row['message'] ?? ''));
                                    $bookingSubtitle = $bookingSummary['booker'] . ' booked ' . $bookingSummary['room'];
                                    if (trim((string) ($bookingSummary['date'] ?? '')) !== '') {
                                        $bookingSubtitle .= ' on ' . trim((string) $bookingSummary['date']);
                                    }
                                    $bookingSubtitle .= '.';
                                    $bookingSearchBlob = strtolower(trim('Conference Booking Created Booking ' . $bookingSubtitle . ' ' . $departmentFilterValue));
                                ?>
                                <a class="notif-item-row admin-booking-created <?= $row['is_read'] == 0 ? 'unread' : '' ?>"
                                   href="<?= htmlspecialchars($notificationHref, ENT_QUOTES, 'UTF-8') ?>"
                                   data-notification-id="<?= (int) $row['id'] ?>"
                                   data-ticket-id="<?= $ticketIdJs !== null ? (int) $ticketIdJs : '' ?>"
                                   data-notification-type="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>"
                                   data-filter-category="bookings"
                                   data-filter-priority="<?= htmlspecialchars(strtolower($priorityKey), ENT_QUOTES, 'UTF-8') ?>"
                                   data-filter-department="<?= htmlspecialchars($departmentFilterValue, ENT_QUOTES, 'UTF-8') ?>"
                                   data-filter-overdue="<?= $isOverdueFilter ? '1' : '0' ?>"
                                   data-filter-search="<?= htmlspecialchars($bookingSearchBlob, ENT_QUOTES, 'UTF-8') ?>"
                                   style="--notif-accent: #0f9f5a; --notif-dot: #008a22;"
                                   onclick="return handleNotificationRowClick(event, this);">
                                    <div class="notif-content">
                                        <div class="notif-title">
                                            <span class="notif-pill variant-booking">
                                                <span class="notif-pill-icon"><i class="fas fa-calendar-check"></i></span>
                                                <span class="notif-pill-text">Booking</span>
                                            </span>
                                            <span class="notif-title-text">Conference Booking Created</span>
                                        </div>
                                        <div class="notif-text"><?= htmlspecialchars($bookingSubtitle, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                                    </div>
                                </a>
                                <?php continue; ?>
                            <?php endif; ?>
                            <a class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?> <?= $typeKey === 'follow_up' ? 'notif-follow-up' : '' ?> <?= $typeKey === 'hr_chat_pending' ? 'notif-chat-pending' : '' ?> <?= $typeKey === 'priority_escalated' ? 'notif-priority-escalation' : '' ?>"
                               href="<?= htmlspecialchars($notificationHref, ENT_QUOTES, 'UTF-8') ?>"
                               data-notification-id="<?= (int) $row['id'] ?>"
                               data-ticket-id="<?= $ticketIdJs !== null ? (int) $ticketIdJs : '' ?>"
                               data-notification-type="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>"
                               data-filter-category="<?= htmlspecialchars($filterCategory, ENT_QUOTES, 'UTF-8') ?>"
                               data-filter-priority="<?= htmlspecialchars(strtolower($priorityKey), ENT_QUOTES, 'UTF-8') ?>"
                               data-filter-department="<?= htmlspecialchars($departmentFilterValue, ENT_QUOTES, 'UTF-8') ?>"
                               data-filter-overdue="<?= $isOverdueFilter ? '1' : '0' ?>"
                               data-filter-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>"
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
                        <div class="empty-notifications">
                            <i class="fas fa-bell-slash"></i>
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

    const searchInput = document.getElementById('notifSearchInput');
    const prioritySelect = document.getElementById('notifPriorityFilter');
    const departmentSelect = document.getElementById('notifDepartmentFilter');
    const clearBtn = document.getElementById('notifClearFilters');
    const tabs = Array.from(document.querySelectorAll('.notif-tab[data-filter-tab]'));
    const rows = Array.from(document.querySelectorAll('.notif-item-row[data-filter-search]'));
    let activeTab = 'all';

    function syncCustomSelect(select) {
        if (!select) return;
        const shell = select.closest('[data-select-shell]');
        if (!shell) return;
        const label = shell.querySelector('[data-select-label]');
        const options = Array.from(shell.querySelectorAll('[data-select-value]'));
        const selectedOption = options.find(option => (option.getAttribute('data-select-value') || '') === select.value) || options[0];
        if (label && selectedOption) label.textContent = selectedOption.textContent.trim();
        options.forEach(option => option.classList.toggle('is-selected', option === selectedOption));
    }

    function applyNotificationFilters() {
        const search = (searchInput ? searchInput.value : '').trim().toLowerCase();
        const priority = prioritySelect ? prioritySelect.value : '';
        const department = departmentSelect ? departmentSelect.value : '';

        rows.forEach(row => {
            const rowText = row.getAttribute('data-filter-search') || '';
            const rowPriority = row.getAttribute('data-filter-priority') || '';
            const rowDepartment = row.getAttribute('data-filter-department') || '';
            const rowCategory = row.getAttribute('data-filter-category') || 'system';
            const rowOverdue = row.getAttribute('data-filter-overdue') === '1';
            const matchesSearch = !search || rowText.includes(search);
            const matchesPriority = !priority || rowPriority === priority || (priority === 'high' && rowPriority === 'critical');
            const matchesDepartment = !department || rowDepartment === department;
            const matchesTab = activeTab === 'all'
                || (activeTab === 'unread' && row.classList.contains('unread'))
                || (activeTab === 'overdue' && rowOverdue)
                || rowCategory === activeTab;

            row.style.display = (matchesSearch && matchesPriority && matchesDepartment && matchesTab) ? '' : 'none';
        });

        document.querySelectorAll('.notif-section-card').forEach(section => {
            const hasVisibleRows = Array.from(section.querySelectorAll('.notif-item-row')).some(row => row.style.display !== 'none');
            section.style.display = hasVisibleRows ? '' : 'none';
            const label = section.previousElementSibling;
            if (label && label.classList.contains('notif-section-label')) {
                label.style.display = hasVisibleRows ? '' : 'none';
            }
        });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            activeTab = this.getAttribute('data-filter-tab') || 'all';
            tabs.forEach(item => item.classList.toggle('active', item === this));
            applyNotificationFilters();
        });
    });
    if (searchInput) searchInput.addEventListener('input', applyNotificationFilters);
    if (prioritySelect) prioritySelect.addEventListener('change', applyNotificationFilters);
    if (departmentSelect) departmentSelect.addEventListener('change', applyNotificationFilters);
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (prioritySelect) prioritySelect.value = '';
            if (departmentSelect) departmentSelect.value = '';
            syncCustomSelect(prioritySelect);
            syncCustomSelect(departmentSelect);
            activeTab = 'all';
            tabs.forEach(tab => tab.classList.toggle('active', tab.getAttribute('data-filter-tab') === 'all'));
            applyNotificationFilters();
        });
    }

    document.querySelectorAll('[data-select-shell]').forEach(shell => {
        const select = shell.querySelector('select');
        const trigger = shell.querySelector('[data-select-trigger]');
        const label = shell.querySelector('[data-select-label]');
        const options = Array.from(shell.querySelectorAll('[data-select-value]'));
        if (!select || !trigger || !label) return;

        trigger.addEventListener('click', function(event) {
            event.stopPropagation();
            const isOpen = shell.classList.toggle('open');
            trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            document.querySelectorAll('[data-select-shell].open').forEach(otherShell => {
                if (otherShell === shell) return;
                otherShell.classList.remove('open');
                const otherTrigger = otherShell.querySelector('[data-select-trigger]');
                if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
            });
        });

        options.forEach(option => {
            option.addEventListener('click', function(event) {
                event.stopPropagation();
                select.value = option.getAttribute('data-select-value') || '';
                label.textContent = option.textContent.trim();
                options.forEach(item => item.classList.toggle('is-selected', item === option));
                shell.classList.remove('open');
                trigger.setAttribute('aria-expanded', 'false');
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    document.addEventListener('click', function() {
        document.querySelectorAll('[data-select-shell].open').forEach(shell => {
            shell.classList.remove('open');
            const trigger = shell.querySelector('[data-select-trigger]');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    });
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

    element.classList.remove('unread');
    markAsRead(notificationId, ticketId, notificationType, element.getAttribute('href') || 'notifications.php');
    return false;
}

// Mark as Read & Redirect
function markAsRead(id, ticketId, type, fallbackHref) {
    // Send request to mark as read
    const formData = new FormData();
    formData.append('id', id);
    if (CSRF_TOKEN) formData.append('csrf_token', CSRF_TOKEN);

    const isConferenceBooking = (type || '').toString() === 'conference_booking';
    const shouldOpenTicketModal = !isConferenceBooking && !!ticketId;
    const dest = fallbackHref || ((type || '').toString() === 'conference_booking'
        ? 'conference_bookings.php'
        : (ticketId ? `all_tickets.php?ticket_id=${ticketId}` : 'notifications.php'));

    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).catch(() => {
        // Still open the target page even if the read update fails.
    }).finally(() => {
        if (shouldOpenTicketModal && typeof TMTicketModal !== 'undefined' && typeof TMTicketModal.open === 'function') {
            TMTicketModal.open(ticketId);
            return;
        }
        window.location.href = dest;
    });
}
</script>
<!-- Ticket Details Modal -->
<div id="ticketModal" class="modal-overlay">
    <div class="modal-content" id="modalContent">
        <!-- Content injected via JS -->
    </div>
</div>

<div id="imagePreviewModal" class="image-preview-modal" onclick="TMTicketModal.closeImagePreview(event)">
    <div class="preview-content">
        <button type="button" class="preview-close" onclick="TMTicketModal.closeImagePreview(event)" aria-label="Close preview">X</button>
        <button type="button" class="preview-nav preview-prev" onclick="TMTicketModal.stepImagePreview(-1)" aria-label="Previous attachment"><i class="fas fa-chevron-left"></i></button>
        <img id="previewImage" src="" alt="Preview" class="preview-image">
        <button type="button" class="preview-nav preview-next" onclick="TMTicketModal.stepImagePreview(1)" aria-label="Next attachment"><i class="fas fa-chevron-right"></i></button>
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
window.TM_HIDE_QUICK_TAGS = true;
</script>
<script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
<script src="../js/admin.js"></script>
</body>
</html>
