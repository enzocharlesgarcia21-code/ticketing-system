<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/notification_service.php';
require_once '../includes/ticket_assignment.php';

notif_ensure_action_type_column($conn);
notif_ensure_title_column($conn);
notif_ensure_requester_identity_columns($conn);

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];
notif_backfill_priority_escalation_notifications($conn);
$user_email = strtolower(trim((string) ($_SESSION['email'] ?? '')));
if ($user_email === '') {
    $user_email = strtolower(trim((string) (notif_user_contact($conn, $user_id)['email'] ?? '')));
    if ($user_email !== '') {
        $_SESSION['email'] = $user_email;
    }
}
$requesterNotificationAccessSql = "(n.type <> 'note_added' OR t.user_id = n.user_id OR LOWER(TRIM(COALESCE(t.requester_email, ''))) = ?)";

function employee_notifications_manager_allowed_sql(string $alias = 'n'): string
{
    $a = preg_replace('/[^a-z0-9_]/i', '', $alias);
    if ($a === '') $a = 'n';
    return "(
        COALESCE($a.action_type, '') = 'claim'
        OR COALESCE($a.action_type, '') = 'reassign'
        OR $a.type IN ('ticket_claimed', 'claim_ticket', 'reassigned', 'status_update')
    )";
}

function employee_notifications_is_sales_manager(mysqli $conn, int $userId): array
{
    $company = trim((string) ($_SESSION['company'] ?? ''));
    $department = trim((string) ($_SESSION['department'] ?? ''));
    $region = trim((string) ($_SESSION['region'] ?? ''));
    $hasRegionColumn = false;
    $regionColumnRes = $conn->query("SHOW COLUMNS FROM users LIKE 'region'");
    if ($regionColumnRes && $regionColumnRes->num_rows > 0) {
        $hasRegionColumn = true;
    }

    if ($userId > 0 && ($company === '' || $department === '' || ($hasRegionColumn && $region === ''))) {
        $stmt = $conn->prepare("SELECT company, department" . ($hasRegionColumn ? ", region" : "") . " FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row) {
                $company = trim((string) ($row['company'] ?? $company));
                $department = trim((string) ($row['department'] ?? $department));
                if ($hasRegionColumn) {
                    $region = trim((string) ($row['region'] ?? $region));
                }
                $_SESSION['company'] = $company;
                $_SESSION['department'] = $department;
                $_SESSION['region'] = $region;
            }
        }
    }

    $eligible = (($_SESSION['employee_view_mode'] ?? '') === 'manager')
        && ticket_normalize_company($company) === '@leadsagri.com'
        && strcasecmp($department, 'Sales') === 0
        && $region !== '';

    return [$eligible, $region];
}

function employee_notifications_is_lapc_sales_user(): bool
{
    return function_exists('ticket_normalize_company')
        && ticket_normalize_company((string) ($_SESSION['company'] ?? '')) === '@leadsagri.com'
        && strcasecmp((string) ($_SESSION['department'] ?? ''), 'Sales') === 0
        && trim((string) ($_SESSION['region'] ?? '')) !== '';
}

function employee_notifications_sync_sales_manager(mysqli $conn, int $userId, string $region): void
{
    if ($userId <= 0 || $region === '') return;
    $regionNeedle = '%Region: ' . $region . '%';
    $allowedSql = employee_notifications_manager_allowed_sql('n');
    $stmt = $conn->prepare("
        SELECT n.ticket_id, n.title, n.message, n.type, n.action_type, n.created_at
        FROM notifications n
        INNER JOIN employee_tickets t ON t.id = n.ticket_id
        LEFT JOIN users creator ON creator.id = t.user_id
        WHERE n.user_id <> ?
          AND n.type <> 'chat_message'
          AND $allowedSql
          AND LOWER(TRIM(COALESCE(creator.email, ''))) = 'sales_guest@leadsagri.com'
          AND COALESCE(t.description, '') LIKE ?
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 200
    ");
    if (!$stmt) return;
    $stmt->bind_param('is', $userId, $regionNeedle);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    $existsStmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND ticket_id = ? AND type = ? AND COALESCE(action_type, '') = COALESCE(?, '') AND COALESCE(title, '') = COALESCE(?, '') AND message = ? AND created_at = ? LIMIT 1");
    $insertStmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
    if (!$existsStmt || !$insertStmt) {
        if ($existsStmt) $existsStmt->close();
        if ($insertStmt) $insertStmt->close();
        return;
    }
    foreach ($rows as $row) {
        $ticketId = (int) ($row['ticket_id'] ?? 0);
        $title = (string) ($row['title'] ?? '');
        $message = (string) ($row['message'] ?? '');
        $type = (string) ($row['type'] ?? '');
        $actionType = (string) ($row['action_type'] ?? '');
        $createdAt = (string) ($row['created_at'] ?? '');
        if ($ticketId <= 0 || $message === '' || $type === '' || $createdAt === '') continue;
        $existsStmt->bind_param('iisssss', $userId, $ticketId, $type, $actionType, $title, $message, $createdAt);
        $existsStmt->execute();
        $existsRes = $existsStmt->get_result();
        if ($existsRes && $existsRes->fetch_assoc()) continue;
        $insertStmt->bind_param('iisssss', $userId, $ticketId, $title, $message, $type, $actionType, $createdAt);
        $insertStmt->execute();
    }
    $existsStmt->close();
    $insertStmt->close();
}

[$isSalesManagerNotificationView, $salesManagerNotificationRegion] = employee_notifications_is_sales_manager($conn, $user_id);
if ($isSalesManagerNotificationView) {
    employee_notifications_sync_sales_manager($conn, $user_id, $salesManagerNotificationRegion);
}

/* Mark all as read if requested */
if (isset($_POST['mark_all_read'])) {
    csrf_validate();
    if ($isSalesManagerNotificationView) {
        $regionNeedle = '%Region: ' . $salesManagerNotificationRegion . '%';
        $allowedSql = employee_notifications_manager_allowed_sql('n');
        $markStmt = $conn->prepare("
            UPDATE notifications n
            INNER JOIN employee_tickets t ON t.id = n.ticket_id
            LEFT JOIN users creator ON creator.id = t.user_id
            SET n.is_read = 1
            WHERE n.user_id = ?
              AND n.type <> 'chat_message'
              AND $allowedSql
              AND LOWER(TRIM(COALESCE(creator.email, ''))) = 'sales_guest@leadsagri.com'
              AND COALESCE(t.description, '') LIKE ?
        ");
        if ($markStmt) {
            $markStmt->bind_param('is', $user_id, $regionNeedle);
            $markStmt->execute();
            $markStmt->close();
        }
    } else {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    }
    $_SESSION['success'] = "All notifications marked as read.";
    header("Location: notifications.php");
    exit();
}

/* Get notifications */
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$managerRegionNeedle = '%Region: ' . $salesManagerNotificationRegion . '%';
$managerAllowedSql = employee_notifications_manager_allowed_sql('n');
$hideManagerSalesNotifications = !$isSalesManagerNotificationView && employee_notifications_is_lapc_sales_user();
$managerJoinSql = $isSalesManagerNotificationView
    ? "LEFT JOIN users creator ON creator.id = t.user_id"
    : ($hideManagerSalesNotifications ? "LEFT JOIN users creator ON creator.id = t.user_id" : "");
if ($isSalesManagerNotificationView) {
    $managerWhereSql = "AND $managerAllowedSql
       AND LOWER(TRIM(COALESCE(creator.email, ''))) = 'sales_guest@leadsagri.com'
       AND COALESCE(t.description, '') LIKE ?";
} elseif ($hideManagerSalesNotifications) {
    $managerWhereSql = "AND $requesterNotificationAccessSql
       AND NOT (
           $managerAllowedSql
           AND LOWER(TRIM(COALESCE(creator.email, ''))) = 'sales_guest@leadsagri.com'
           AND COALESCE(t.description, '') LIKE ?
       )";
} else {
    $managerWhereSql = "AND $requesterNotificationAccessSql";
}

$totalStmt = $conn->prepare("
    SELECT COUNT(*) as c
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    $managerJoinSql
    WHERE n.user_id = ?
      AND n.type <> 'chat_message'
      $managerWhereSql
");
if ($isSalesManagerNotificationView) {
    $totalStmt->bind_param("is", $user_id, $managerRegionNeedle);
} elseif ($hideManagerSalesNotifications) {
    $totalStmt->bind_param("iss", $user_id, $user_email, $managerRegionNeedle);
} else {
    $totalStmt->bind_param("is", $user_id, $user_email);
}
$totalStmt->execute();
$total_res = $totalStmt->get_result();
$total = $total_res->fetch_assoc()['c'];
$totalStmt->close();
$total_pages = ceil($total / $limit);

$stmt = $conn->prepare("
    SELECT n.*, t.priority
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    $managerJoinSql
    WHERE n.user_id = ?
      AND n.type <> 'chat_message'
      $managerWhereSql
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
if ($isSalesManagerNotificationView) {
    $stmt->bind_param("isii", $user_id, $managerRegionNeedle, $limit, $offset);
} elseif ($hideManagerSalesNotifications) {
    $stmt->bind_param("issii", $user_id, $user_email, $managerRegionNeedle, $limit, $offset);
} else {
    $stmt->bind_param("isii", $user_id, $user_email, $limit, $offset);
}
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
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body.employee-notifications-page .mobile-sidebar,
        body.employee-notifications-page .mobile-sidebar-overlay {
            display: none;
        }

        body.employee-notifications-page .content-wrapper {
            max-width: 860px;
            margin: 0 auto;
        }

        .notif-list-page {
            background: transparent;
            border-radius: 0;
            box-shadow: none;
            overflow: visible;
            border: 0;
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
            border: 1px solid #eef2f7;
            border-bottom-color: #e5edf6;
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
        .notif-item-row:last-child {
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
            background: #ffffff;
        }
        .notif-item-row.notif-chat-pending:hover {
            background-color: #f8fbff;
        }
        .notif-item-row.notif-chat-pending.unread {
            background: #f7fbff;
        }
        .notif-item-row.notif-chat-pending.unread::after {
            content: "";
            position: absolute;
            right: 16px;
            top: 22px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #1B5E20;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
        }
        .notif-item-row.notif-follow-up {
            background: linear-gradient(180deg, #fffdf4 0%, #fff9e7 100%);
            border: 1px solid rgba(245, 195, 66, 0.24);
        }
        .notif-item-row.notif-follow-up::before {
            background: #f4c542;
        }
        .notif-item-row.notif-follow-up:hover {
            background: linear-gradient(180deg, #fff9e7 0%, #fff4ce 100%);
        }
        .notif-item-row.notif-follow-up.unread::after {
            background: #f4c542;
        }
        .notif-item-row.notif-hold {
            background: #ffffff;
        }
        .notif-item-row.notif-hold::before,
        .notif-item-row.notif-hold.unread::after {
            background: #f59e0b;
        }
        .notif-item-row.notif-hold.unread {
            background: #fffaf0;
        }
        .notif-follow-up .notif-title {
            color: #111827;
        }
        .notif-follow-up .notif-title-text {
            color: #111827;
        }
        .notif-follow-up .notif-date {
            color: #7c8aa3;
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
        .notif-content {
            flex-grow: 1;
            min-width: 0;
        }
        .notif-text {
            font-size: 0.92rem;
            color: #1f2937;
            line-height: 1.4;
            margin-bottom: 8px;
        }
        .notif-chat-pending .notif-text {
            font-size: 0.92rem;
            line-height: 1.4;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .notif-chat-pending .notif-text strong {
            color: #1d4f9b;
            font-weight: 700;
        }
        .notif-title {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 5px;
        }
        .notif-title-text {
            font-size: 0.92rem;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
        }
        .notif-follow-up .notif-msg strong {
            color: #8a5b00;
            font-weight: 700;
        }
        .notif-pill {
            display: inline-flex;
            align-items: center;
            border: 2px solid currentColor;
            border-radius: 11px;
            background: #ffffff;
            color: #64748b;
            overflow: hidden;
            min-height: 26px;
            flex: 0 0 auto;
        }
        .notif-pill-icon {
            width: 28px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 13px;
            font-weight: 800;
        }
        .notif-pill-text {
            padding: 0 16px 0 12px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.01em;
            line-height: 1;
        }
        .notif-pill.variant-assign,
        .notif-pill.variant-close,
        .notif-pill.variant-low {
            color: #16a34a;
            background: #ecfdf5;
        }
        .notif-pill.variant-assign .notif-pill-icon,
        .notif-pill.variant-close .notif-pill-icon,
        .notif-pill.variant-low .notif-pill-icon {
            background: linear-gradient(135deg, #4ade80, #22c55e);
        }
        .notif-pill.variant-medium {
            color: #eab308;
            background: #fffbeb;
        }
        .notif-pill.variant-medium .notif-pill-icon {
            background: linear-gradient(135deg, #facc15, #eab308);
        }
        .notif-pill.variant-high {
            color: #ef4444;
            background: #fef2f2;
        }
        .notif-pill.variant-high .notif-pill-icon {
            background: linear-gradient(135deg, #fb7185, #ef4444);
        }
        .notif-pill.variant-critical {
            color: #E53935;
            background: #fff4f5;
        }
        .notif-pill.variant-critical .notif-pill-icon {
            background: linear-gradient(135deg, #ff7d7d, #E53935);
        }
        .notif-pill.variant-update {
            color: #0f766e;
            background: #f0fdfa;
        }
        .notif-pill.variant-update .notif-pill-icon {
            background: linear-gradient(135deg, #34d399, #0f766e);
        }
        .notif-pill.variant-note {
            color: #f59e0b;
            background: #fff8ef;
        }
        .notif-pill.variant-note .notif-pill-icon {
            background: linear-gradient(135deg, #fcd34d, #f59e0b);
        }
        .notif-pill.variant-hold {
            color: #e99000;
            background: #fff8e6;
            border-color: #f59e0b;
        }
        .notif-pill.variant-hold .notif-pill-icon {
            background: linear-gradient(135deg, #ffb52e, #f59e0b);
        }
        .notif-pill.variant-reassign {
            color: #9333ea;
            background: #faf5ff;
        }
        .notif-pill.variant-reassign .notif-pill-icon {
            background: linear-gradient(135deg, #c084fc, #9333ea);
        }
        .notif-pill.variant-follow-up {
            color: #7c4a03;
            background: #fff6d8;
        }
        .notif-pill.variant-follow-up .notif-pill-icon {
            background: linear-gradient(135deg, #fde68a, #f59e0b);
        }
        .notif-pill.notif-chat-pill {
            min-height: 36px;
            min-width: 36px;
            padding: 0;
            gap: 0;
            border: 0;
            border-radius: 999px;
            color: #ffffff;
            background: #1B5E20;
            box-shadow: 0 6px 14px rgba(27, 94, 32, 0.2);
        }
        .notif-pill.notif-chat-pill .notif-pill-icon {
            width: 36px;
            height: 36px;
            font-size: 16px;
            background: transparent;
        }
        .notif-pill.notif-chat-pill .notif-pill-text {
            display: none;
        }
        .notif-pill.notif-priority-breach-pill {
            min-height: 24px;
            border: 1px solid #ff3b45;
            border-radius: 7px;
            color: #ff2634;
            background: #fff7f7;
        }
        .notif-pill.notif-priority-breach-pill .notif-pill-icon {
            width: 28px;
            height: 22px;
            color: #ffffff;
            background: #ff3b45;
            border-right: 1px solid #ff3b45;
            font-size: 13px;
        }
        .notif-pill.notif-priority-breach-pill .notif-pill-text {
            padding: 0 8px;
            font-size: 10px;
            font-weight: 900;
            white-space: nowrap;
        }
        .notif-item-row.notif-priority-escalation {
            background: linear-gradient(180deg, #fffafa 0%, #fff5f5 100%);
            border-color: #fecaca;
        }
        .notif-item-row.notif-priority-escalation:hover {
            background: linear-gradient(180deg, #fff7f7 0%, #feecec 100%);
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
        .notif-item-row.booking-created {
            padding: 16px 24px 16px 22px;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 1px 0 rgba(0,0,0,0.06);
            display: block;
        }
        .notif-item-row.booking-created::before {
            background: #0f766e;
        }
        .notif-item-row.booking-created .booking-header {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .notif-item-row.booking-created .left-pill {
            min-width: 0;
            height: 26px;
            display: flex;
            align-items: center;
            gap: 0;
            align-self: flex-start;
            background: #f0fdfa;
            border: 2px solid #0f766e;
            border-radius: 11px;
            padding: 0;
            overflow: hidden;
            flex: 0 0 auto;
        }
        .notif-item-row.booking-created .booking-icon {
            width: 28px;
            height: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #34d399, #0f766e);
            color: #ffffff;
            font-size: 13px;
        }
        .notif-item-row.booking-created .pill-label {
            color: #0f766e;
            font-weight: 800;
            font-size: 11px;
            line-height: 1;
            padding: 0 4px;
        }
        .notif-item-row.booking-created .notification-body {
            min-width: 0;
            display: block;
        }
        .notif-item-row.booking-created .booking-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            color: #111827;
        }
        .notif-item-row.booking-created .notif-subtitle {
            margin-top: 6px;
            color: #333333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .notif-item-row.booking-created .notif-details {
            margin-top: 2px;
            color: #666666;
            font-size: 13px;
            line-height: 1.35;
        }
        .notif-item-row.booking-created .notif-meta {
            margin-top: 0;
            color: #1e88e5;
            font-weight: 600;
            font-size: 0.8rem;
        }
        .notif-chat-pending .notif-date {
            font-size: 0.8rem;
            color: #94a3b8;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            padding: 30px 12px 16px;
            flex-wrap: wrap;
        }
        .page-link {
            min-width: 44px;
            height: 44px;
            padding: 0 16px;
            border: 1px solid #dbe4ee;
            border-radius: 999px;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .page-link:hover:not(.active):not(.disabled) {
            background: #f8fafc;
            transform: translateY(-1px);
            border-color: #cbd5e1;
        }
        .page-link.active {
            background: #166534;
            color: white;
            border-color: #166534;
            box-shadow: 0 18px 28px rgba(22, 101, 52, 0.22);
        }
        .page-link.prev,
        .page-link.next {
            min-width: 118px;
            color: #475569;
            font-weight: 700;
        }
        .page-link.disabled {
            opacity: 0.55;
            pointer-events: none;
            box-shadow: none;
            color: #94a3b8;
        }
        .page-ellipsis {
            min-width: 32px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 18px;
            font-weight: 800;
            user-select: none;
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

        @media (max-width: 768px) {
            body.employee-notifications-page #navbarCollapse,
            body.employee-notifications-page.sidebar-open #navbarCollapse {
                display: none !important;
            }

            body.employee-notifications-page.sidebar-open .tm-global-chat-fab {
                opacity: 0;
                pointer-events: none;
                transform: translateY(8px);
            }

            body.employee-notifications-page .mobile-sidebar {
                position: fixed;
                top: 0;
                right: -260px;
                width: 260px;
                height: 100vh;
                background: #1B5E20;
                padding: 20px;
                transition: right 0.3s ease;
                z-index: 2000;
                display: flex;
                flex-direction: column;
                gap: 18px;
                box-shadow: 12px 0 28px rgba(15, 23, 42, 0.25);
            }

            body.employee-notifications-page .mobile-sidebar.active {
                right: 0;
            }

            body.employee-notifications-page .mobile-sidebar-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            body.employee-notifications-page .mobile-sidebar-header img {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #ffffff;
                padding: 4px;
                object-fit: contain;
                flex: 0 0 36px;
            }

            body.employee-notifications-page .mobile-sidebar-header span {
                color: #ffffff;
                font-size: 15px;
                font-weight: 700;
                line-height: 1.2;
            }

            body.employee-notifications-page .mobile-sidebar a {
                color: white;
                text-decoration: none;
                font-size: 16px;
                font-weight: 500;
                min-height: 44px;
                display: flex;
                align-items: center;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-notifications-page .mobile-sidebar a.active,
            body.employee-notifications-page .mobile-sidebar a:hover {
                background: rgba(255, 255, 255, 0.12);
            }

            body.employee-notifications-page .mobile-sidebar-footer {
                margin-top: auto;
                padding-top: 14px;
                border-top: 1px solid rgba(255, 255, 255, 0.18);
                display: flex;
                align-items: center;
                gap: 12px;
            }

            body.employee-notifications-page .mobile-sidebar-icon-link,
            body.employee-notifications-page .mobile-sidebar-user-btn {
                min-height: 44px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.28);
                color: #ffffff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
            }

            body.employee-notifications-page .mobile-sidebar-icon-link {
                width: 44px;
                min-width: 44px;
                position: relative;
            }

            body.employee-notifications-page .mobile-sidebar-icon-link i,
            body.employee-notifications-page .mobile-sidebar-user-btn i {
                font-size: 16px;
            }

            body.employee-notifications-page .mobile-sidebar-badge {
                position: absolute;
                top: -6px;
                right: -4px;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                border-radius: 999px;
                background: #ff4d4f;
                color: #ffffff;
                font-size: 11px;
                font-weight: 800;
                display: none;
                align-items: center;
                justify-content: center;
                line-height: 1;
                border: 2px solid #1B5E20;
            }

            body.employee-notifications-page .mobile-sidebar-user {
                position: relative;
            }

            body.employee-notifications-page .mobile-sidebar-user-btn {
                gap: 10px;
                padding: 0 16px;
                cursor: pointer;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu {
                position: absolute;
                right: 0;
                bottom: calc(100% + 10px);
                min-width: 170px;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 16px 30px rgba(15, 23, 42, 0.18);
                padding: 8px;
                display: none;
                flex-direction: column;
                gap: 4px;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu.show {
                display: flex;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu a {
                min-height: 40px;
                color: #0f172a;
                font-size: 14px;
                font-weight: 600;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-notifications-page .mobile-sidebar-user-menu a:hover {
                background: #f1f5f9;
            }

            body.employee-notifications-page .mobile-sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.4);
                opacity: 0;
                visibility: hidden;
                transition: 0.3s;
                z-index: 1500;
                display: block;
            }

            body.employee-notifications-page .mobile-sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            body.employee-notifications-page .nav-left,
            body.employee-notifications-page .navbar-toggler {
                position: relative;
                z-index: 2105;
            }

            body.employee-notifications-page .tm-global-chat-fab {
                right: 12px;
                bottom: 12px;
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
                padding: 0 !important;
                border-radius: 999px;
                gap: 0;
                flex: 0 0 42px;
                justify-content: center;
            }

            body.employee-notifications-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-notifications-page .tm-global-chat-fab i {
                font-size: 16px;
            }

            body.employee-notifications-page .content-wrapper {
                max-width: none;
            }

            .page-header {
                gap: 12px;
                align-items: flex-start !important;
                flex-direction: column;
            }

            .notif-item-row {
                padding: 14px 16px;
                gap: 0;
            }
        }
    </style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body class="employee-notifications-page">

    <?php include '../includes/employee_navbar.php'; ?>

    <div id="mobileSidebar" class="mobile-sidebar" aria-hidden="true">
        <div class="mobile-sidebar-header">
            <img src="../assets/img/UPDATEDlogo.png" alt="Logo">
            <span>Leads Agri</span>
        </div>
        <a href="dashboard.php">Dashboard</a>
        <a href="request_ticket.php">Create Ticket</a>
        <a href="my_task.php">Assigned Tickets</a>
        <a href="my_tickets.php">My Submitted Tickets</a>
        <a href="feedback.php">Feedback</a>
        <a href="knowledge_base.php">Knowledge Base</a>
        <div class="mobile-sidebar-footer">
            <a href="notifications.php" class="mobile-sidebar-icon-link" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span id="mobileSidebarNotifBadge" class="mobile-sidebar-badge"></span>
            </a>
            <div class="mobile-sidebar-user">
                <button type="button" id="mobileSidebarUserBtn" class="mobile-sidebar-user-btn" aria-label="Account menu">
                    <i class="fas fa-user"></i>
                    <i class="fas fa-chevron-down" style="font-size: 11px;"></i>
                </button>
                <div id="mobileSidebarUserMenu" class="mobile-sidebar-user-menu">
                    <a href="my_profile.php">My Profile</a>
                    <form method="post" action="logout.php" style="display:contents"><?= csrf_field(); ?><button type="submit" style="border:0;width:100%;text-align:left">Logout</button></form>
                </div>
            </div>
        </div>
    </div>

    <div id="mobileSidebarOverlay" class="mobile-sidebar-overlay" aria-hidden="true"></div>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <?php if(isset($_SESSION['success'])): ?>
                <div class="alert alert-success" style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
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
                            $typeJs = (string) ($row['type'] ?? '');
                            $priorityKey = $typeJs === 'priority_escalated'
                                ? notif_priority_from_message((string) ($row['message'] ?? ''))
                                : '';
                            if ($typeJs !== 'priority_escalated' && $priorityKey === '' && !empty($row['priority'])) {
                                $p = strtolower((string) $row['priority']);
                                if (in_array($p, ['critical', 'high', 'medium', 'low'], true)) {
                                    $priorityKey = $p;
                                }
                            }
                            $iconClass = 'fa-sticky-note';
                            $bgClass = '#e2e8f0';
                            $colorClass = '#64748b';
                            $accentColor = '#cbd5e1';
                            $dotColor = '#94a3b8';
                            $ticketIdJs = isset($row['ticket_id']) && $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null;
                            $actionType = notif_normalize_action_type((string) ($row['action_type'] ?? ''), $typeJs);
                            $isHoldStatus = in_array($typeJs, ['ticket_on_hold', 'hold_approved'], true);
                            if ($isHoldStatus) {
                                $iconClass = 'fa-pause';
                                $bgClass = 'linear-gradient(135deg, #ffb52e, #f59e0b)';
                                $colorClass = '#ffffff';
                                $accentColor = '#f59e0b';
                                $dotColor = '#f59e0b';
                            } elseif ($typeJs === 'priority_escalated' && in_array($priorityKey, ['high', 'critical'], true)) {
                                $iconClass = 'fa-exclamation';
                                $bgClass = 'linear-gradient(135deg, #ef4444, #dc2626)';
                                $colorClass = '#ffffff';
                                $accentColor = '#ef4444';
                                $dotColor = '#ef4444';
                            } elseif ($typeJs === 'priority_escalated' && $priorityKey === 'medium') {
                                $iconClass = 'fa-exclamation';
                                $bgClass = 'linear-gradient(135deg, #fcd34d, #f59e0b)';
                                $colorClass = '#ffffff';
                                $accentColor = '#d4a017';
                                $dotColor = '#d4a017';
                            } elseif ($priorityKey === 'critical') {
                                $iconClass = 'fa-exclamation';
                                $bgClass = 'linear-gradient(135deg, #ef4444, #dc2626)';
                                $colorClass = '#ffffff';
                                $accentColor = '#E53935';
                                $dotColor = '#E53935';
                            } elseif ($priorityKey === 'high') {
                                $iconClass = 'fa-exclamation';
                                $bgClass = 'linear-gradient(135deg, #ef4444, #dc2626)';
                                $colorClass = '#ffffff';
                                $accentColor = '#ef4444';
                                $dotColor = '#ef4444';
                            } elseif ($priorityKey === 'medium') {
                                $iconClass = 'fa-triangle-exclamation';
                                $bgClass = 'linear-gradient(135deg, #facc15, #eab308)';
                                $colorClass = '#ffffff';
                                $accentColor = '#eab308';
                                $dotColor = '#eab308';
                            } elseif ($priorityKey === 'low') {
                                $iconClass = 'fa-arrow-down';
                                $bgClass = 'linear-gradient(135deg, #4ade80, #22c55e)';
                                $colorClass = '#ffffff';
                                $accentColor = '#22c55e';
                                $dotColor = '#22c55e';
                            } else {
                                switch($actionType) {
                                    case 'update':
                                        if ($typeJs === 'note_added') {
                                            $iconClass = 'fa-sticky-note';
                                            $bgClass = 'linear-gradient(135deg, #fcd34d, #f59e0b)';
                                            $colorClass = '#ffffff';
                                            $accentColor = '#ca8a04';
                                            $dotColor = '#ca8a04';
                                        } else {
                                            $iconClass = 'fa-rotate';
                                            $bgClass = 'linear-gradient(135deg, #34d399, #0f766e)';
                                            $colorClass = '#ffffff';
                                            $accentColor = '#0f766e';
                                            $dotColor = '#0f766e';
                                        }
                                        break;
                                    case 'close':
                                        $iconClass = 'fa-check';
                                        $bgClass = 'linear-gradient(135deg, #58b368, #43A047)';
                                        $colorClass = '#ffffff';
                                        $accentColor = '#43A047';
                                        $dotColor = '#43A047';
                                        break;
                                    case 'reassign':
                                        $iconClass = 'fa-retweet';
                                        $bgClass = 'linear-gradient(135deg, #b77cf5, #9333ea)';
                                        $colorClass = '#ffffff';
                                        $accentColor = '#9333ea';
                                        $dotColor = '#9333ea';
                                        break;
                                    case 'assign':
                                        $iconClass = 'fa-inbox';
                                        $bgClass = 'linear-gradient(135deg, #60a5fa, #2563eb)';
                                        $colorClass = '#ffffff';
                                        $accentColor = '#2563eb';
                                        $dotColor = '#2563eb';
                                        break;
                                }
                                if ($typeJs === 'follow_up') {
                                    $iconClass = 'fa-rotate';
                                    $bgClass = 'linear-gradient(135deg, #f8e08c, #f4c542)';
                                    $colorClass = '#7c4a03';
                                    $accentColor = '#d4a017';
                                    $dotColor = '#d4a017';
                                } elseif ($typeJs === 'hr_chat_pending') {
                                    $iconClass = 'fa-comments';
                                    $bgClass = 'linear-gradient(135deg, #2f8f44, #1B5E20)';
                                    $colorClass = '#ffffff';
                                    $accentColor = '#1B5E20';
                                    $dotColor = '#1B5E20';
                                }
                            }
                            if ($actionType === 'reassign') {
                                $iconClass = 'fa-retweet';
                                $bgClass = 'linear-gradient(135deg, #b77cf5, #9333ea)';
                                $colorClass = '#ffffff';
                                $accentColor = '#9333ea';
                                $dotColor = '#9333ea';
                            } elseif ($actionType === 'update' && $typeJs !== 'note_added' && $priorityKey === '' && !$isHoldStatus) {
                                $iconClass = 'fa-rotate';
                                $bgClass = 'linear-gradient(135deg, #34d399, #0f766e)';
                                $colorClass = '#ffffff';
                                $accentColor = '#0f766e';
                                $dotColor = '#0f766e';
                            }
                            $displayMessage = notif_display_message($typeJs, (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
                            $isFollowUp = $typeJs === 'follow_up';
                            $isChatPending = $typeJs === 'hr_chat_pending';
                            $isPriorityEscalation = $typeJs === 'priority_escalated';
                            $isResolvedStatus = ($actionType === 'update' || $typeJs === 'status_update') && preg_match('/\bresolved\b/i', $displayMessage);

                            $titleText = 'Ticket Update';
                            if ($isHoldStatus) $titleText = 'Status Update';
                            elseif ($isPriorityEscalation && in_array($priorityKey, ['medium', 'high', 'critical'], true)) $titleText = 'Priority Escalation';
                            elseif ($typeJs === 'conference_booking_created') $titleText = 'Conference Booking Created';
                            elseif ($typeJs === 'conference_booking_cancelled') $titleText = 'Conference Booking Cancelled';
                            elseif ($typeJs === 'conference_booking_deleted') $titleText = 'Conference Booking Deleted';
                            elseif ($isResolvedStatus) $titleText = 'Ticket Resolved';
                            elseif ($typeJs === 'ticket_claimed' || $actionType === 'claim') $titleText = 'Ticket Claimed';
                            elseif ($actionType === 'assign') $titleText = 'Ticket Assigned';
                            elseif ($actionType === 'reassign') $titleText = 'Ticket Reassigned';
                            elseif ($actionType === 'close') $titleText = 'Ticket Closed';
                            elseif ($isChatPending) $titleText = 'Pending Chat';
                            elseif ($actionType === 'update' && $typeJs === 'note_added') $titleText = 'Ticket Note';
                            elseif ($actionType === 'update') $titleText = 'Status Update';

                            $pillVariantClass = 'variant-update';
                            $pillExtraClass = '';
                            $pillText = 'Updated';
                            $pillIconClass = 'fa-rotate';
                            if ($isHoldStatus) {
                                $pillVariantClass = 'variant-hold';
                                $pillText = 'HOLD';
                                $pillIconClass = 'fa-pause';
                            } elseif ($isChatPending) {
                                $pillVariantClass = 'variant-update';
                                $pillExtraClass = 'notif-chat-pill';
                                $pillText = 'Chat';
                                $pillIconClass = 'fa-comments';
                            } elseif ($isFollowUp) {
                                $pillVariantClass = 'variant-follow-up';
                                $pillText = 'Follow Up';
                                $pillIconClass = 'fa-rotate';
                                $accentColor = '#d4a017';
                                $dotColor = '#d4a017';
                                $titleText = 'Follow Up Request';
                            } elseif ($priorityKey === 'critical') {
                                $pillVariantClass = 'variant-critical';
                                $pillText = 'Critical';
                                $pillIconClass = 'fa-exclamation';
                            } elseif ($priorityKey === 'high') {
                                $pillVariantClass = 'variant-high';
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
                            } elseif ($actionType === 'claim' || $typeJs === 'ticket_claimed') {
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
                            } elseif ($actionType === 'update' && $typeJs === 'note_added') {
                                $pillVariantClass = 'variant-note';
                                $pillText = 'Private Note';
                                $pillIconClass = 'fa-plus';
                            }
                            if ($isPriorityEscalation && $priorityKey === 'high') {
                                $transition = notif_priority_transition_from_message((string) ($row['message'] ?? ''));
                                $fromPriority = trim((string) ($transition['from'] ?? ''));
                                $toPriority = trim((string) ($transition['to'] ?? ''));
                                $pillText = $fromPriority !== '' || $toPriority !== ''
                                    ? (($fromPriority !== '' ? $fromPriority . ' -> ' : '') . ($toPriority !== '' ? $toPriority : 'Breach'))
                                    : 'At Risk -> Breach';
                                $pillIconClass = 'fa-stopwatch';
                                $pillExtraClass = trim($pillExtraClass . ' notif-priority-breach-pill');
                            }
                            if ($actionType === 'reassign') {
                                $pillVariantClass = 'variant-reassign';
                                $pillExtraClass = '';
                                $pillText = 'Reassigned';
                                $pillIconClass = 'fa-retweet';
                            }
                            $displayMessageHtml = notif_message_highlight_html($displayMessage);
                            if ($isHoldStatus) {
                                $displayMessageHtml = (string) preg_replace('/\s+Reason:/i', '<br>Reason:', $displayMessageHtml, 1);
                            }
                        ?>
                        <?php if ($sectionLabel !== $currentSection): ?>
                            <?php if ($currentSection !== null): ?>
                                </div>
                            <?php endif; ?>
                            <div class="notif-section-label"><?= htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="notif-section-card">
                            <?php $currentSection = $sectionLabel; ?>
                        <?php endif; ?>
                        <?php if ($typeJs === 'conference_booking_created'): ?>
                            <?php
                                $payload = json_decode((string) ($row['message'] ?? ''), true);
                                $payload = is_array($payload) ? $payload : [];
                                $formatBookingTime = static function (string $timeValue): string {
                                    $timeValue = trim($timeValue);
                                    if ($timeValue === '') {
                                        return '';
                                    }
                                    if (function_exists('conference_booking_format_time_12h')) {
                                        return conference_booking_format_time_12h($timeValue);
                                    }
                                    $ts = strtotime($timeValue);
                                    return $ts !== false ? date('g:i A', $ts) : $timeValue;
                                };
                                $email = trim((string) ($payload['user_email'] ?? ''));
                                $room = trim((string) ($payload['room_name'] ?? ($payload['room'] ?? '')));
                                $dateValue = trim((string) ($payload['booking_date'] ?? ''));
                                $date = $dateValue !== '' && strtotime($dateValue) !== false ? date('M d, Y', strtotime($dateValue)) : '';
                                $start = $formatBookingTime((string) ($payload['start_time'] ?? ''));
                                $end = $formatBookingTime((string) ($payload['end_time'] ?? ''));
                                $location = trim((string) ($payload['location'] ?? ($payload['room_location'] ?? $room)));
                                $purpose = trim((string) ($payload['purpose'] ?? ''));
                                $fallbackMessage = trim((string) ($row['message'] ?? ''));
                                if ($email === '') $email = 'Someone';
                                if ($room === '') $room = 'the room';
                                if ($location === '') $location = $room;
                                $subtitle = $email . ' booked ' . $room . ($date !== '' ? ' on ' . $date : '') . '.';
                                $details = trim('from ' . $start . ' to ' . $end . ' (' . $location . ').');
                                if ($start === '' && $end === '') {
                                    $details = '(' . $location . ').';
                                }
                                if ($purpose !== '') {
                                    $details .= ' Purpose: ' . $purpose . '.';
                                } elseif (!$payload && $fallbackMessage !== '') {
                                    $details = $fallbackMessage;
                                }
                            ?>
                            <div class="notif-item-row booking-created <?= $row['is_read'] == 0 ? 'unread' : '' ?>"
                                 style="--notif-accent: #0f7a3a; --notif-dot: #0f7a3a;"
                                 role="button"
                                 tabindex="0"
                                 data-notification-id="<?= (int) $row['id'] ?>"
                                 data-ticket-id="<?= $ticketIdJs === null ? '' : (int) $ticketIdJs ?>"
                                 data-notification-type="<?= htmlspecialchars($typeJs, ENT_QUOTES, 'UTF-8') ?>"
                                 onclick="openEmployeeNotification(this)"
                                 onkeydown="handleEmployeeNotificationKey(event, this)">
                                <div class="booking-header">
                                    <div class="left-pill">
                                        <span class="booking-icon"><i class="fas fa-calendar-check"></i></span>
                                        <span class="pill-label">Booking</span>
                                    </div>
                                    <h4 class="booking-title">Conference Booking Created</h4>
                                </div>
                                <div class="notification-body">
                                    <div class="notif-subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="notif-details"><?= htmlspecialchars($details, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="notif-meta notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                                </div>
                            </div>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="notif-item-row <?= $row['is_read'] == 0 ? 'unread' : '' ?> <?= $typeJs === 'hr_chat_pending' ? 'notif-chat-pending' : '' ?> <?= $typeJs === 'follow_up' ? 'notif-follow-up' : '' ?> <?= $isHoldStatus ? 'notif-hold' : '' ?> <?= $typeJs === 'priority_escalated' ? 'notif-priority-escalation' : '' ?>"
                             style="--notif-accent: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>; --notif-dot: <?= htmlspecialchars($dotColor, ENT_QUOTES, 'UTF-8') ?>;"
                             role="button"
                             tabindex="0"
                             data-notification-id="<?= (int) $row['id'] ?>"
                             data-ticket-id="<?= $ticketIdJs === null ? '' : (int) $ticketIdJs ?>"
                             data-notification-type="<?= htmlspecialchars($typeJs, ENT_QUOTES, 'UTF-8') ?>"
                             onclick="openEmployeeNotification(this)"
                            onkeydown="handleEmployeeNotificationKey(event, this)">
                            <div class="notif-content">
                                <div class="notif-title">
                                    <span class="notif-pill <?= htmlspecialchars(trim($pillVariantClass . ' ' . $pillExtraClass), ENT_QUOTES, 'UTF-8') ?>">
                                        <span class="notif-pill-icon"><i class="fas <?= htmlspecialchars($pillIconClass, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                                        <?php if (!$isChatPending): ?>
                                            <span class="notif-pill-text"><?= htmlspecialchars($pillText, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="notif-title-text"><?= htmlspecialchars($titleText, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="notif-text"><?= $displayMessageHtml ?></div>
                                <div class="notif-date" data-timestamp="<?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= time_elapsed_string($row['created_at']) ?></div>
                            </div>
                        </div>
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
            <div class="pagination">
                <a href="?page=<?= max(1, $page - 1) ?>" class="page-link prev <?= ($page <= 1) ? 'disabled' : '' ?>">&lsaquo; Previous</a>
                <?php
                    $paginationPages = [];
                    $addPage = static function (int $pageNumber) use (&$paginationPages, $total_pages): void {
                        if ($pageNumber >= 1 && $pageNumber <= $total_pages) {
                            $paginationPages[$pageNumber] = true;
                        }
                    };

                    $addPage(1);
                    $addPage(2);
                    $addPage(3);
                    $addPage((int) $page - 1);
                    $addPage((int) $page);
                    $addPage((int) $page + 1);
                    $addPage((int) $total_pages);
                    $paginationNumbers = array_keys($paginationPages);
                    sort($paginationNumbers, SORT_NUMERIC);
                    $previousPageNumber = 0;
                ?>
                <?php foreach($paginationNumbers as $pageNumber): ?>
                    <?php if($previousPageNumber > 0 && $pageNumber > $previousPageNumber + 1): ?>
                        <span class="page-ellipsis">...</span>
                    <?php endif; ?>
                    <a href="?page=<?= (int) $pageNumber ?>" class="page-link <?= ((int) $pageNumber === (int) $page) ? 'active' : '' ?>"><?= (int) $pageNumber ?></a>
                    <?php $previousPageNumber = (int) $pageNumber; ?>
                <?php endforeach; ?>
                <a href="?page=<?= min($total_pages, $page + 1) ?>" class="page-link next <?= ($page >= $total_pages) ? 'disabled' : '' ?>">Next &rsaquo;</a>
            </div>
            <?php endif; ?>

        </div>
    </div>

<script>
const IS_LAPC_SALES_MANAGER_NOTIFICATION_VIEW = <?php echo json_encode($isSalesManagerNotificationView); ?>;
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

    const menuBtn = document.getElementById('navbarToggler');
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    const mobileUserBtn = document.getElementById('mobileSidebarUserBtn');
    const mobileUserMenu = document.getElementById('mobileSidebarUserMenu');
    const desktopNotifBadge = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileSidebarNotifBadge');

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
        if (mobileUserMenu) mobileUserMenu.classList.remove('show');
        sidebar.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function syncMobileNotifBadge() {
        if (!desktopNotifBadge || !mobileNotifBadge) return;
        const desktopText = (desktopNotifBadge.textContent || '').trim();
        const desktopVisible = desktopNotifBadge.style.display !== 'none' && desktopText !== '';
        mobileNotifBadge.textContent = desktopText;
        mobileNotifBadge.style.display = desktopVisible ? 'inline-flex' : 'none';
    }

    if (menuBtn && sidebar && overlay) {
        menuBtn.addEventListener('click', function (event) {
            if (window.innerWidth > 768) return;
            event.preventDefault();
            event.stopPropagation();
            const shouldOpen = !sidebar.classList.contains('active');
            sidebar.classList.toggle('active', shouldOpen);
            overlay.classList.toggle('active', shouldOpen);
            document.body.classList.toggle('sidebar-open', shouldOpen);
            sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
            overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
        });

        overlay.addEventListener('click', function () {
            if (window.innerWidth > 768) return;
            closeSidebar();
        });

        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth > 768) return;
                closeSidebar();
            });
        });

        if (mobileUserBtn && mobileUserMenu) {
            mobileUserBtn.addEventListener('click', function (event) {
                if (window.innerWidth > 768) return;
                event.stopPropagation();
                mobileUserMenu.classList.toggle('show');
            });

            document.addEventListener('click', function (event) {
                if (window.innerWidth > 768) return;
                if (!mobileUserMenu.contains(event.target) && !mobileUserBtn.contains(event.target)) {
                    mobileUserMenu.classList.remove('show');
                }
            });
        }

        syncMobileNotifBadge();
        if (desktopNotifBadge && typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(syncMobileNotifBadge);
            observer.observe(desktopNotifBadge, { attributes: true, childList: true, subtree: true });
        }
    }
});

function employeeNotificationTargetUrl(ticketId, type) {
    const notifType = String(type || '');
    if (!ticketId) {
        return 'notifications.php';
    }
    if (IS_LAPC_SALES_MANAGER_NOTIFICATION_VIEW) {
        return `sales_submitted_tickets.php?ticket_id=${ticketId}`;
    }
    if (notifType === 'hr_chat_pending') {
        return `my_task.php?ticket_id=${ticketId}&chat=1`;
    }
    const taskTypes = new Set(['dept_assigned', 'reassigned', 'priority_escalated', 'new_ticket', 'follow_up', 'hr_chat_pending', 'hold_approval_requested', 'hold_approved', 'hold_rejected']);
    return taskTypes.has(notifType)
        ? `my_task.php?ticket_id=${ticketId}`
        : `my_tickets.php?ticket_id=${ticketId}`;
}

function openEmployeeNotification(element) {
    if (!element) return;

    const id = parseInt(element.getAttribute('data-notification-id') || '0', 10) || 0;
    const ticketId = parseInt(element.getAttribute('data-ticket-id') || '0', 10) || 0;
    const type = element.getAttribute('data-notification-type') || '';

    if (typeof window.markAsRead === 'function') {
        window.markAsRead(id, ticketId || null, type);
        return;
    }

    const targetUrl = employeeNotificationTargetUrl(ticketId, type);
    const csrfToken = (window.TM_CSRF_TOKEN || '').toString();
    const body = 'id=' + encodeURIComponent(String(id)) + (csrfToken ? ('&csrf_token=' + encodeURIComponent(csrfToken)) : '');

    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    }).finally(() => {
        window.location.href = targetUrl;
    });
}

function handleEmployeeNotificationKey(event, element) {
    if (!event) return;
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openEmployeeNotification(element);
    }
}
</script>
<script src="../js/employee-dashboard.js"></script>
</body>
</html>
