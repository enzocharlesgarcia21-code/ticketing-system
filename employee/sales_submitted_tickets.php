<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    header('Location: employee_login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$company = trim((string) ($_SESSION['company'] ?? ''));
$department = trim((string) ($_SESSION['department'] ?? ''));
$region = trim((string) ($_SESSION['region'] ?? ''));
$hasRegionColumn = false;
$regionColumnRes = $conn->query("SHOW COLUMNS FROM users LIKE 'region'");
if ($regionColumnRes && $regionColumnRes->num_rows > 0) {
    $hasRegionColumn = true;
}

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
        $region = $hasRegionColumn ? trim((string) ($row['region'] ?? $region)) : $region;
        $_SESSION['company'] = $company;
        $_SESSION['department'] = $department;
        $_SESSION['region'] = $region;
    }
}

$eligible = ticket_normalize_company($company) === '@leadsagri.com'
    && strcasecmp($department, 'Sales') === 0
    && $region !== '';
if (!$eligible) {
    $_SESSION['employee_view_mode'] = 'employee';
    header('Location: dashboard.php');
    exit;
}
$_SESSION['employee_view_mode'] = 'manager';

function sales_manager_can_follow_up_ticket_status(string $status): bool
{
    $status = strtoupper(trim($status));
    return $status !== '' && $status !== 'RESOLVED' && $status !== 'CLOSED';
}

function sales_manager_can_close_ticket_status(string $status): bool
{
    return trim($status) === 'Resolved';
}

function sales_manager_ensure_follow_up_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM employee_tickets");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) $existing[(string) $row['Field']] = true;
        }
        $res->free();
    }

    if (!isset($existing['follow_up_last_sent_at'])) {
        $conn->query("ALTER TABLE employee_tickets ADD COLUMN follow_up_last_sent_at DATETIME NULL");
    }
    if (!isset($existing['follow_up_cooldown_stage'])) {
        $conn->query("ALTER TABLE employee_tickets ADD COLUMN follow_up_cooldown_stage TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($existing['follow_up_send_count'])) {
        $conn->query("ALTER TABLE employee_tickets ADD COLUMN follow_up_send_count INT NOT NULL DEFAULT 0");
    }
}

function sales_manager_follow_up_cooldown_hours(string $priority, int $sendCount): int
{
    $priority = strtolower(trim($priority));
    $sendCount = max(0, $sendCount);
    if ($priority === 'high' || $priority === 'critical') return 4;
    if ($priority === 'medium') return $sendCount <= 0 ? 24 : ($sendCount === 1 ? 12 : 6);
    if ($priority === 'low') return $sendCount <= 0 ? 48 : ($sendCount === 1 ? 24 : 12);
    return 24;
}

function sales_manager_follow_up_window(array $ticket): array
{
    $createdAt = trim((string) ($ticket['created_at'] ?? ''));
    $lastSentAt = trim((string) ($ticket['follow_up_last_sent_at'] ?? ''));
    $sendCount = max(0, (int) ($ticket['follow_up_send_count'] ?? 0));
    $priority = trim((string) ($ticket['priority'] ?? ''));
    $baseTimestamp = $sendCount > 0 && $lastSentAt !== '' ? $lastSentAt : $createdAt;
    $baseTs = $baseTimestamp !== '' ? strtotime($baseTimestamp) : false;
    $availableAt = $baseTs !== false ? date('Y-m-d H:i:s', strtotime('+' . sales_manager_follow_up_cooldown_hours($priority, $sendCount) . ' hours', $baseTs)) : '';
    $availableTs = $availableAt !== '' ? strtotime($availableAt) : false;
    $remainingSeconds = $availableTs !== false ? max(0, $availableTs - time()) : 0;

    return [
        'available_at' => $availableAt !== '' ? $availableAt : null,
        'available_at_ts' => $availableTs !== false ? (int) $availableTs : null,
        'remaining_seconds' => $remainingSeconds,
        'in_cooldown' => $availableTs !== false && $availableTs > time(),
    ];
}

function sales_manager_follow_up_cooldown_label(int $remainingSeconds): string
{
    $remainingSeconds = max(0, $remainingSeconds);
    $hours = (int) floor($remainingSeconds / 3600);
    $minutes = (int) floor(($remainingSeconds % 3600) / 60);
    $seconds = (int) ($remainingSeconds % 60);
    if ($hours > 0) return 'Next follow-up available in ' . $hours . 'h ' . $minutes . 'm';
    if ($minutes > 0) return 'Next follow-up available in ' . $minutes . 'm';
    return 'Next follow-up available in ' . $seconds . 's';
}

function sales_manager_fetch_region_ticket(mysqli $conn, int $ticketId, string $region): ?array
{
    if ($ticketId <= 0 || $region === '') return null;
    $regionNeedle = '%Region: ' . $region . '%';
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.user_id,
            t.category,
            t.status,
            t.priority,
            t.company,
            t.assigned_company,
            t.assigned_department,
            t.assigned_group,
            t.assigned_user_id,
            t.created_at,
            t.description,
            t.follow_up_last_sent_at,
            t.follow_up_send_count,
            COALESCE(NULLIF(TRIM(t.requester_name), ''), creator.name) AS creator_name,
            COALESCE(NULLIF(TRIM(t.requester_email), ''), creator.email) AS creator_email,
            assignee.department AS assignee_department
        FROM employee_tickets t
        LEFT JOIN users creator ON creator.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
        WHERE t.id = ?
          AND LOWER(TRIM(COALESCE(creator.email, ''))) = 'sales_guest@leadsagri.com'
          AND COALESCE(t.description, '') LIKE ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('is', $ticketId, $regionNeedle);
    $stmt->execute();
    $res = $stmt->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $ticket ?: null;
}

function sales_manager_follow_up_recipients(mysqli $conn, array $ticket, int $managerUserId): array
{
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    $assignedCompany = (string) (($ticket['assigned_company'] ?? '') !== '' ? $ticket['assigned_company'] : ($ticket['company'] ?? ''));
    $assignedDepartment = trim((string) (($ticket['assignee_department'] ?? '') !== '' ? $ticket['assignee_department'] : (($ticket['assigned_group'] ?? '') !== '' ? $ticket['assigned_group'] : ($ticket['assigned_department'] ?? ''))));
    $recipientIds = [];
    if ($assignedUserId > 0 && $assignedUserId !== $managerUserId) {
        $recipientIds[] = $assignedUserId;
    }
    $recipientIds = array_merge($recipientIds, ticket_find_assignee_ids($conn, $assignedCompany, $assignedDepartment), notif_admin_user_ids($conn));
    return array_values(array_filter(notif_unique_user_ids($recipientIds), static function ($id) use ($managerUserId) {
        return (int) $id > 0 && (int) $id !== $managerUserId;
    }));
}

function sales_manager_insert_follow_up_notifications(mysqli $conn, array $recipientIds, int $ticketId, string $message): int
{
    $recipientIds = notif_unique_user_ids($recipientIds);
    if (count($recipientIds) === 0) return 0;
    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);
    $type = 'follow_up';
    $title = 'Ticket Follow-up';
    $actionType = notif_normalize_action_type('update', $type);
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) return 0;
    $inserted = 0;
    foreach ($recipientIds as $recipientId) {
        $notifyUserId = (int) $recipientId;
        if ($notifyUserId <= 0) continue;
        $stmt->bind_param('iissss', $notifyUserId, $ticketId, $title, $message, $type, $actionType);
        if ($stmt->execute()) $inserted++;
    }
    $stmt->close();
    return $inserted;
}

function sales_manager_record_follow_up(mysqli $conn, int $ticketId, int $nextCount): void
{
    $stage = min(3, max(0, $nextCount));
    $sentAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE employee_tickets SET follow_up_last_sent_at = ?, follow_up_cooldown_stage = ?, follow_up_send_count = ? WHERE id = ? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('siii', $sentAt, $stage, $nextCount, $ticketId);
    $stmt->execute();
    $stmt->close();
}

function sales_manager_action_feedback(string $type, string $message): void
{
    $_SESSION['sales_manager_flash'] = ['type' => $type, 'message' => $message];
}

sales_manager_ensure_follow_up_columns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'follow_up') {
        header('Content-Type: application/json');
        csrf_validate();
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $ticket = sales_manager_fetch_region_ticket($conn, $ticketId, $region);
        if (!$ticket || !sales_manager_can_follow_up_ticket_status((string) ($ticket['status'] ?? ''))) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Follow up is not available for this ticket.']);
            exit;
        }
        $cooldownWindow = sales_manager_follow_up_window($ticket);
        if (!empty($cooldownWindow['in_cooldown'])) {
            http_response_code(429);
            echo json_encode([
                'ok' => false,
                'error' => sales_manager_follow_up_cooldown_label((int) ($cooldownWindow['remaining_seconds'] ?? 0)) . '.',
                'cooldown_active' => true,
                'available_at' => $cooldownWindow['available_at'],
                'available_at_ts' => $cooldownWindow['available_at_ts'],
                'remaining_seconds' => (int) ($cooldownWindow['remaining_seconds'] ?? 0),
            ]);
            exit;
        }
        $requestorName = trim((string) ($ticket['creator_name'] ?? ''));
        if ($requestorName === '') $requestorName = 'the requestor';
        $message = 'Ticket #' . notif_ticket_number($ticketId) . ' has a follow-up request from ' . $requestorName . '. Please check the ticket.';
        $recipientIds = sales_manager_follow_up_recipients($conn, $ticket, $userId);
        $inserted = sales_manager_insert_follow_up_notifications($conn, $recipientIds, $ticketId, $message);
        if ($inserted <= 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'No recipients available for this follow up request.']);
            exit;
        }
        $nextCount = max(0, (int) ($ticket['follow_up_send_count'] ?? 0)) + 1;
        sales_manager_record_follow_up($conn, $ticketId, $nextCount);
        $updatedTicket = sales_manager_fetch_region_ticket($conn, $ticketId, $region) ?: $ticket;
        $newWindow = sales_manager_follow_up_window($updatedTicket);
        echo json_encode([
            'ok' => true,
            'message' => 'Follow up sent successfully.',
            'available_at' => $newWindow['available_at'],
            'available_at_ts' => $newWindow['available_at_ts'],
            'remaining_seconds' => (int) ($newWindow['remaining_seconds'] ?? 0),
        ]);
        exit;
    }

    if ($action === 'close_ticket') {
        csrf_validate();
        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $ticket = sales_manager_fetch_region_ticket($conn, $ticketId, $region);
        if ($ticket && sales_manager_can_close_ticket_status((string) ($ticket['status'] ?? ''))) {
            $updateStmt = $conn->prepare("
                UPDATE employee_tickets
                SET status = 'Closed',
                    feedback_status = 'pending',
                    updated_at = NOW(),
                    resolved_at = IFNULL(resolved_at, NOW())
                WHERE id = ?
                  AND status = 'Resolved'
                LIMIT 1
            ");
            if ($updateStmt) {
                $updateStmt->bind_param('i', $ticketId);
                $updateStmt->execute();
                if ($updateStmt->affected_rows > 0) {
                    ticket_record_activity($conn, $ticketId, 'status_change', 'Closed by Sales Manager');
                    sales_manager_action_feedback('success', 'Ticket closed successfully.');
                } else {
                    sales_manager_action_feedback('error', 'Only resolved tickets can be closed.');
                }
                $updateStmt->close();
            }
        } else {
            sales_manager_action_feedback('error', 'Only resolved tickets can be closed.');
        }
        header('Location: sales_submitted_tickets.php');
        exit;
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$positionFilter = trim((string) ($_GET['position'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$sla = trim((string) ($_GET['sla'] ?? ''));
$allowedStatuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
$allowedSlas = ['On Track', 'At Risk', 'Breach'];
$positionFilterOptions = [
    'Area Supervisor',
    'Sr. Area Manager',
    'Technical and Promo Specialist',
    'Store Sales Technician',
    'Store Clerk',
    'Jr. Agronomist',
    'Seasonal Crop Technician',
    'Over-The-Counter (OTC) Promo Clerk',
    'Sales Coordinator',
    'Seasonal Crop Tech',
    'Store Supervisor',
];
if (!in_array($positionFilter, $positionFilterOptions, true)) {
    $positionFilter = '';
}
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}
$slaLevel = ticket_normalize_sla_level($sla);
if ($slaLevel !== '') {
    $sla = ticket_sla_display_label($slaLevel);
}
if ($slaLevel === '') {
    $sla = '';
}

$tickets = [];
$regionNeedle = '%Region: ' . $region . '%';
$where = [
    "LOWER(TRIM(COALESCE(creator.email, ''))) = 'sales_guest@leadsagri.com'",
    "COALESCE(t.description, '') LIKE ?",
    "COALESCE(NULLIF(t.status, ''), '') <> 'Trash'",
];
$params = [$regionNeedle];
$types = 's';

if ($search !== '') {
    $searchNeedle = '%' . $search . '%';
    $where[] = "(CAST(t.id AS CHAR) LIKE ? OR COALESCE(t.category, '') LIKE ? OR COALESCE(t.subject, '') LIKE ? OR COALESCE(t.requester_name, '') LIKE ? OR COALESCE(t.requester_email, '') LIKE ? OR COALESCE(t.description, '') LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchNeedle;
        $types .= 's';
    }
}
if ($positionFilter !== '') {
    $where[] = "COALESCE(t.description, '') LIKE ?";
    $params[] = '%Position: ' . $positionFilter . '%';
    $types .= 's';
}
if ($status !== '') {
    $where[] = "t.status = ?";
    $params[] = $status;
    $types .= 's';
}
if ($sla !== '') {
    $slaCondition = ticket_sla_filter_condition_sql('t', $sla);
    if ($slaCondition !== '') {
        $where[] = $slaCondition;
    }
}

$sql = "
    SELECT
        t.id,
        t.subject,
        t.category,
        t.status,
        t.priority,
        t.requester_name,
        t.requester_email,
        t.created_at,
        t.description,
        t.follow_up_last_sent_at,
        t.follow_up_send_count
    FROM employee_tickets t
    LEFT JOIN users creator ON creator.id = t.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 300
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $tickets[] = $row;
    }
    $stmt->close();
}

function sales_manager_ticket_number($id): string
{
    return '#' . str_pad((string) ((int) $id), 6, '0', STR_PAD_LEFT);
}

function sales_manager_date($value): string
{
    $ts = strtotime((string) $value);
    return $ts ? date('M d, Y', $ts) : '-';
}

function sales_manager_status_class(string $status): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($status)));
}

function sales_manager_urgency_badge_html(string $priority): string
{
    $priority = trim($priority);
    if ($priority === '') return '-';
    $priorityKey = strtolower($priority);
    $allowedKeys = ['low', 'medium', 'high', 'critical'];
    $priorityClass = in_array($priorityKey, $allowedKeys, true) ? $priorityKey : 'low';
    return '<span class="priority-pill priority-' . htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(ucfirst($priorityKey), ENT_QUOTES, 'UTF-8') . '</span>';
}

function sales_manager_position(array $ticket): string
{
    $description = preg_replace('/<br\s*\/?>/i', "\n", (string) ($ticket['description'] ?? '')) ?? '';
    if ($description !== '' && preg_match('/^\s*Position:\s*(.+)$/mi', $description, $m)) {
        $position = trim(strip_tags((string) $m[1]));
        return $position !== '' ? $position : '-';
    }
    return '-';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Submitted Tickets | Leads DeskMetamorph</title>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css?v=<?= (int) filemtime(__DIR__ . '/../css/view-tickets.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .sales-manager-container {
            width: min(calc(100% - 72px), 1560px);
            max-width: none;
            margin: 0 auto;
            padding: 34px 38px 60px;
            box-sizing: border-box;
        }
        .sales-manager-title {
            margin: 0 0 8px;
            color: #1B5E20;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0;
        }
        .sales-manager-subtitle {
            margin: 0 0 24px;
            color: #64748b;
            font-size: 16px;
            font-weight: 400;
        }
        .sales-manager-region {
            color: #1B5E20;
            font-weight: 700;
        }
        .sales-manager-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 18px 44px rgba(15, 23, 42, 0.06);
            padding: 18px 24px 20px;
            overflow: hidden;
        }
        .sales-manager-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .sales-manager-table th {
            padding: 16px 14px;
            color: #1B5E20;
            background: #f8fafc;
            border-bottom: 1px solid #1B5E20;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-align: left;
            text-transform: uppercase;
        }
        .sales-manager-table th:first-child {
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }
        .sales-manager-table th:last-child {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        .sales-manager-table td {
            padding: 16px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 14px;
            vertical-align: middle;
        }
        .sales-manager-table tr:last-child td {
            border-bottom: none;
        }
        .sales-manager-table tr.ticket-row:hover td {
            background-color: #f8fafc;
        }
        .sales-manager-table .subject-cell {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sales-manager-table .task-ticket-requester,
        .sales-manager-table .task-ticket-department {
            overflow-wrap: anywhere;
        }
        .sales-manager-table .task-ticket-date {
            white-space: nowrap;
        }
        .sales-manager-ticket-id {
            color: #0f172a;
        }
        .sales-manager-table .user-info strong {
            color: #0f172a;
            font-weight: 700;
        }
        .sales-manager-table .user-info small {
            color: #475569;
            font-size: 12px;
        }
        .sales-manager-table .task-ticket-sla .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 28px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 400;
            line-height: 1;
            white-space: nowrap;
            box-sizing: border-box;
        }
        .sales-manager-table .sales-manager-action-cell {
            width: 168px;
            padding-left: 16px;
            padding-right: 16px;
            text-align: center;
            overflow: visible;
        }
        .sales-manager-table th.sales-manager-action-heading {
            text-align: center;
        }
        .sales-manager-table .ticket-action-buttons {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .sales-manager-table .follow-up-btn,
        .sales-manager-table .close-ticket-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 124px;
            min-width: 124px;
            height: 38px;
            min-height: 38px;
            padding: 0 18px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: 0.01em;
            cursor: pointer;
            white-space: nowrap;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        .sales-manager-table .follow-up-btn {
            position: relative;
            border: 0;
            background: linear-gradient(180deg, #1f7a36 0%, #16602a 100%);
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 10px 22px rgba(22, 96, 42, 0.2);
        }
        .sales-manager-table .follow-up-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 24px rgba(22, 96, 42, 0.24);
        }
        .sales-manager-table .follow-up-btn:disabled {
            opacity: 0.7;
            cursor: wait;
            transform: none;
        }
        .sales-manager-table .follow-up-btn.is-sent,
        .sales-manager-table .follow-up-btn.is-sent:hover,
        .sales-manager-table .follow-up-btn.is-sent:disabled {
            background: linear-gradient(180deg, #dbe5dc 0%, #cfdad1 100%);
            color: #46604d;
            box-shadow: none;
            cursor: default;
            opacity: 1;
            transform: none;
        }
        .sales-manager-table .follow-up-btn.follow-up-cooldown {
            cursor: not-allowed;
            transition: all 0.2s ease;
            overflow: visible;
            z-index: 0;
            isolation: isolate;
        }
        .sales-manager-table .follow-up-btn.follow-up-cooldown::before {
            content: attr(data-cooldown-label);
            position: absolute;
            left: 50%;
            bottom: calc(100% + 12px);
            transform: translateX(-50%) translateY(5px);
            padding: 7px 12px;
            border-radius: 10px;
            background: linear-gradient(180deg, rgba(247, 245, 236, 0.98) 0%, rgba(240, 237, 225, 0.98) 100%);
            border: 1px solid rgba(208, 204, 183, 0.9);
            box-shadow: 0 8px 18px rgba(58, 91, 65, 0.1);
            color: #5a5f58;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.15;
            letter-spacing: 0.01em;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: all 0.2s ease;
        }
        .sales-manager-table .follow-up-btn.follow-up-cooldown::after {
            content: "";
            position: absolute;
            left: 50%;
            bottom: calc(100% + 5px);
            width: 11px;
            height: 11px;
            transform: translateX(-50%) rotate(45deg) translateY(5px);
            background: linear-gradient(180deg, rgba(247, 245, 236, 0.98) 0%, rgba(240, 237, 225, 0.98) 100%);
            border-right: 1px solid rgba(208, 204, 183, 0.9);
            border-bottom: 1px solid rgba(208, 204, 183, 0.9);
            box-shadow: 0 0 0 0 rgba(15, 122, 42, 0), 0 8px 16px rgba(58, 91, 65, 0);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: all 0.2s ease;
        }
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:hover,
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:focus-visible {
            cursor: not-allowed;
            z-index: 1;
            box-shadow: 0 0 0 2px rgba(133, 182, 138, 0.85), 0 0 0 6px rgba(133, 182, 138, 0.2), 0 12px 28px rgba(113, 160, 118, 0.18);
        }
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:hover::before,
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::before,
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:hover::after,
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::after {
            opacity: 1;
            visibility: visible;
        }
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:hover::before,
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::before {
            transform: translateX(-50%) translateY(0);
        }
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:hover::after,
        .sales-manager-table .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::after {
            transform: translateX(-50%) rotate(45deg) translateY(0);
            box-shadow: 0 0 0 0 rgba(15, 122, 42, 0), 0 8px 16px rgba(58, 91, 65, 0.07);
        }
        .sales-manager-table .close-ticket-form {
            margin: 0;
            display: inline-flex;
        }
        .sales-manager-table .close-ticket-btn {
            gap: 7px;
            border: 1px solid #bfdbfe;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 800;
            box-shadow: none;
        }
        .sales-manager-table .close-ticket-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(29, 78, 216, 0.12);
        }
        .sales-manager-table .close-ticket-btn:disabled {
            opacity: 0.72;
            cursor: wait;
            transform: none;
        }
        .sales-manager-table .follow-up-cooldown-note {
            display: none;
        }
        .sales-manager-flash {
            margin: 0 0 18px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
        }
        .sales-manager-flash.is-success {
            background: #dcfce7;
            color: #166534;
        }
        .sales-manager-flash.is-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .sales-action-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, 0.52);
            backdrop-filter: blur(8px);
            z-index: 10070;
        }
        .sales-action-overlay.is-visible {
            display: flex;
        }
        .sales-action-dialog {
            width: min(100%, 440px);
            background: #ffffff;
            border: 1px solid rgba(203, 213, 225, 0.9);
            border-radius: 22px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.24);
            overflow: hidden;
        }
        #salesFollowUpConfirmOverlay .sales-action-dialog,
        #salesCloseConfirmOverlay .sales-action-dialog {
            width: min(500px, calc(100vw - 48px));
            max-width: calc(100vw - 40px);
            min-height: 284px;
            padding: 30px 30px 26px;
            box-sizing: border-box;
            text-align: center;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog {
            width: min(500px, calc(100vw - 48px));
            max-width: calc(100vw - 40px);
            min-height: 284px;
            padding: 30px 40px 28px;
            box-sizing: border-box;
            text-align: center;
            background:
                radial-gradient(circle at top center, rgba(141, 231, 160, 0.18), transparent 32%),
                linear-gradient(180deg, #ffffff 0%, #fbfffc 100%);
            border-radius: 28px;
            border: 1px solid rgba(214, 232, 221, 0.92);
            box-shadow: 0 32px 90px rgba(15, 23, 42, 0.22);
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-error {
            background:
                radial-gradient(circle at top center, rgba(254, 202, 202, 0.18), transparent 32%),
                linear-gradient(180deg, #ffffff 0%, #fffafa 100%);
            border-color: rgba(254, 205, 211, 0.92);
        }
        .sales-follow-up-feedback-icon {
            width: 78px;
            height: 78px;
            margin: 0 auto 18px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #f7fff8 0%, #ebf9ef 100%);
            color: #166534;
            border: 2px solid #d6eedc;
            box-shadow:
                0 0 0 10px rgba(88, 198, 117, 0.08),
                0 14px 34px rgba(40, 137, 69, 0.16);
            font-size: 34px;
            font-weight: 800;
            line-height: 1;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-error .sales-follow-up-feedback-icon {
            background: linear-gradient(180deg, #fff7f7 0%, #fff1f2 100%);
            color: #b91c1c;
            border-color: #fecdd3;
            box-shadow:
                0 0 0 10px rgba(239, 68, 68, 0.08),
                0 14px 34px rgba(185, 28, 28, 0.14);
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-pending .sales-follow-up-feedback-icon {
            background: transparent;
            color: #166534;
            border-color: transparent;
            box-shadow:
                0 0 0 14px rgba(34, 197, 94, 0.08),
                0 0 38px rgba(74, 222, 128, 0.28),
                0 18px 42px rgba(22, 101, 52, 0.12);
        }
        .sales-follow-up-feedback-icon-spinner {
            width: 64px;
            height: 64px;
            border-radius: 999px;
            background: transparent;
            border: 7px solid rgba(34, 197, 94, 0.18);
            border-top-color: #22c55e;
            border-right-color: #16a34a;
            border-bottom-color: rgba(34, 197, 94, 0.26);
            border-left-color: rgba(34, 197, 94, 0.08);
            position: relative;
            animation: follow-up-feedback-spin 1s linear infinite;
            isolation: isolate;
        }
        .sales-follow-up-feedback-icon-spinner::before {
            content: "";
            position: absolute;
            inset: 9px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow:
                0 0 0 10px rgba(255, 255, 255, 0.98),
                inset 0 0 0 1px rgba(15, 23, 42, 0.03);
        }
        .sales-follow-up-feedback-icon-spinner::after {
            content: "";
            position: absolute;
            inset: -7px;
            border-radius: 999px;
            background: conic-gradient(from 0deg, rgba(255, 255, 255, 0) 0deg 220deg, rgba(255, 255, 255, 0.95) 255deg 290deg, rgba(134, 239, 172, 0.35) 315deg 345deg, rgba(255, 255, 255, 0) 360deg);
            -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 13px), #000 calc(100% - 12px));
            mask: radial-gradient(farthest-side, transparent calc(100% - 13px), #000 calc(100% - 12px));
            animation: follow-up-feedback-spin 0.9s linear infinite;
            pointer-events: none;
            z-index: 0;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog h2 {
            margin: 0 0 10px;
            font-size: 28px;
            letter-spacing: -0.03em;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog p {
            font-size: 16px;
            line-height: 1.65;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog-actions {
            padding-top: 24px;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-pending {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 40px 28px;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-pending h2 {
            margin: 0 0 14px;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.035em;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-pending p {
            color: #6b7280;
            line-height: 1.55;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-pending .sales-action-dialog-actions {
            width: 100%;
            min-height: 1px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        #salesFollowUpFeedbackOverlay .sales-action-dialog.is-pending #salesFollowUpFeedbackDoneBtn {
            display: none;
        }
        @keyframes follow-up-feedback-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .sales-action-confirm-icon {
            width: 78px;
            height: 78px;
            margin: 0 auto 18px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(180deg, #f7fff8 0%, #ecfdf3 100%);
            color: #1B5E20;
            border: 2px solid #c9eec9;
            box-shadow:
                0 0 0 10px rgba(88, 198, 117, 0.08),
                0 14px 34px rgba(40, 137, 69, 0.16);
            font-size: 40px;
            font-weight: 700;
            line-height: 1;
        }
        .sales-action-dialog h2 {
            margin: 0 0 10px;
            color: #0f172a;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: -0.02em;
        }
        .sales-action-dialog p {
            margin: 0;
            color: #5b6b80;
            font-size: 15px;
            line-height: 1.6;
        }
        .sales-action-dialog-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding-top: 24px;
            margin-top: 0;
            border-top: 1px solid #e6e8ef;
            flex-wrap: wrap;
        }
        .sales-action-dialog button {
            min-width: 130px;
            min-height: 44px;
            border-radius: 999px;
            font-family: 'Segoe UI', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .sales-action-cancel {
            border: 1px solid #dbe4ee;
            background: #ffffff;
            color: #334155;
        }
        .sales-action-submit {
            border: 1px solid #1B5E20;
            background: #1B5E20;
            color: #ffffff;
            box-shadow: 0 10px 22px rgba(27, 94, 32, 0.18);
        }
        .sales-action-cancel:hover,
        .sales-action-submit:hover {
            transform: translateY(-1px);
        }
        .sales-action-submit:hover {
            background: #144a1a;
            border-color: #144a1a;
            box-shadow: 0 12px 24px rgba(27, 94, 32, 0.24);
        }
        .sales-manager-empty {
            padding: 36px 18px;
            color: #64748b;
            text-align: center;
            font-weight: 700;
        }
        body.employee-sales-manager-page .my-tickets-filter-card {
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.06);
            padding: 24px 18px;
            margin-bottom: 22px;
        }
        body.employee-sales-manager-page .my-tickets-filter-form {
            display: grid;
            grid-template-columns: minmax(430px, 1fr) minmax(360px, 430px) 170px 150px 140px;
            gap: 16px;
            align-items: center;
            width: 100%;
        }
        body.employee-sales-manager-page .my-tickets-search-wrapper,
        body.employee-sales-manager-page .my-tickets-filter-select-wrap {
            position: relative;
            min-width: 0;
            width: 100%;
        }
        body.employee-sales-manager-page .my-tickets-search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #8fa1bd;
            font-size: 18px;
            pointer-events: none;
        }
        body.employee-sales-manager-page .my-tickets-search-input,
        body.employee-sales-manager-page .my-tickets-filter-select {
            width: 100%;
            height: 48px;
            border: 1px solid #dbe3ef;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
        }
        body.employee-sales-manager-page .my-tickets-search-input {
            padding: 0 16px 0 50px;
        }
        body.employee-sales-manager-page .my-tickets-filter-select {
            padding: 0 40px 0 16px;
            appearance: none;
            cursor: pointer;
        }
        body.employee-sales-manager-page .my-tickets-filter-select-wrap::after {
            content: "\f078";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #8fa1bd;
            font-size: 12px;
            pointer-events: none;
        }
        body.employee-sales-manager-page .my-tickets-search-input:focus,
        body.employee-sales-manager-page .my-tickets-filter-select:focus {
            border-color: #94a3b8;
            box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.16);
        }
        body.employee-sales-manager-page .my-tickets-clear-btn {
            width: 140px;
            height: 48px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            background: #f1f5f9;
            color: #475569;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            justify-self: end;
        }
        body.employee-sales-manager-page .my-tickets-clear-btn:hover {
            background: #e2e8f0;
        }
        @media (max-width: 768px) {
            .sales-manager-container {
                width: 100%;
                padding: 24px 16px 48px;
            }
            body.employee-sales-manager-page .my-tickets-filter-card {
                padding: 16px;
                border-radius: 14px;
            }
            body.employee-sales-manager-page .my-tickets-filter-form {
                grid-template-columns: 1fr;
            }
            body.employee-sales-manager-page .my-tickets-clear-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body class="employee-sales-manager-page">
    <?php include '../includes/employee_navbar.php'; ?>
    <main class="sales-manager-container">
        <h1 class="sales-manager-title">Sales Submitted Tickets</h1>
        <p class="sales-manager-subtitle">Showing sales request tickets from <span class="sales-manager-region"><?= htmlspecialchars($region, ENT_QUOTES, 'UTF-8'); ?></span>.</p>
        <?php if (!empty($_SESSION['sales_manager_flash'])): ?>
            <?php
                $salesFlash = $_SESSION['sales_manager_flash'];
                unset($_SESSION['sales_manager_flash']);
                $salesFlashType = (($salesFlash['type'] ?? '') === 'success') ? 'success' : 'error';
            ?>
            <div class="sales-manager-flash is-<?= htmlspecialchars($salesFlashType, ENT_QUOTES, 'UTF-8'); ?>">
                <?= htmlspecialchars((string) ($salesFlash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="my-tickets-filter-card">
            <form method="GET" action="sales_submitted_tickets.php" class="my-tickets-filter-form">
                <div class="my-tickets-search-wrapper">
                    <i class="fas fa-search my-tickets-search-icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        name="search"
                        class="my-tickets-search-input"
                        placeholder="Search by ID, name, email or category..."
                        autocomplete="off"
                        value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="my-tickets-filter-select-wrap">
                    <select name="position" class="my-tickets-filter-select">
                        <option value="" <?= $positionFilter === '' ? 'selected' : ''; ?> hidden>All Position</option>
                        <?php foreach ($positionFilterOptions as $positionOption): ?>
                            <option value="<?= htmlspecialchars($positionOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $positionFilter === $positionOption ? 'selected' : ''; ?>><?= htmlspecialchars($positionOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="my-tickets-filter-select-wrap">
                    <select name="status" class="my-tickets-filter-select">
                        <option value="" <?= $status === '' ? 'selected' : ''; ?> hidden>All Status</option>
                        <?php foreach ($allowedStatuses as $statusOption): ?>
                            <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $status === $statusOption ? 'selected' : ''; ?>><?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="my-tickets-filter-select-wrap">
                    <select name="sla" class="my-tickets-filter-select">
                        <option value="" <?= $sla === '' ? 'selected' : ''; ?> hidden>All SLA</option>
                        <?php foreach ($allowedSlas as $slaOption): ?>
                            <option value="<?= htmlspecialchars($slaOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $sla === $slaOption ? 'selected' : ''; ?>><?= htmlspecialchars($slaOption, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <a href="sales_submitted_tickets.php" class="my-tickets-clear-btn">Clear Filters</a>
            </form>
        </div>

        <section class="sales-manager-card table-card">
            <div class="table-responsive">
                <table class="sales-manager-table admin-table">
                    <colgroup>
                        <col style="width: 7%;">
                        <col style="width: 11%;">
                        <col style="width: 8%;">
                        <col style="width: 17%;">
                        <col style="width: 14%;">
                        <col style="width: 9%;">
                        <col style="width: 7%;">
                        <col style="width: 11%;">
                        <col style="width: 16%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Category</th>
                            <th>Urgency</th>
                            <th>Requested By</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th>SLA</th>
                            <th>Date Created</th>
                            <th class="sales-manager-action-heading">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($tickets) === 0): ?>
                            <tr><td colspan="9" class="sales-manager-empty">No submitted tickets found for this region.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php $status = (string) (($ticket['status'] ?? '') !== '' ? $ticket['status'] : 'Open'); ?>
                                <?php
                                    $ticketId = (int) ($ticket['id'] ?? 0);
                                    $ticketCategory = (string) (($ticket['category'] ?? '') !== '' ? $ticket['category'] : ($ticket['subject'] ?? '-'));
                                    $ticketName = (string) (($ticket['requester_name'] ?? '') !== '' ? $ticket['requester_name'] : '-');
                                    $ticketEmail = (string) (($ticket['requester_email'] ?? '') !== '' ? $ticket['requester_email'] : '-');
                                    $ticketPosition = sales_manager_position($ticket);
                                    $searchIndex = implode(' ', [
                                        (string) $ticketId,
                                        sales_manager_ticket_number($ticketId),
                                        $ticketCategory,
                                        $ticketName,
                                        $ticketEmail,
                                        $ticketPosition,
                                    ]);
                                ?>
                                <tr class="ticket-row" data-id="<?= $ticketId; ?>" data-search="<?= htmlspecialchars($searchIndex, ENT_QUOTES, 'UTF-8'); ?>" style="cursor:pointer;">
                                    <td class="task-ticket-id sales-manager-ticket-id"><?= htmlspecialchars(sales_manager_ticket_number($ticket['id'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="subject-cell task-ticket-category"><strong><?= htmlspecialchars($ticketCategory, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td class="task-ticket-urgency"><?= sales_manager_urgency_badge_html((string) ($ticket['priority'] ?? '')); ?></td>
                                    <td class="task-ticket-requester">
                                        <div class="user-info">
                                            <strong><?= htmlspecialchars($ticketName, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                            <small><?= htmlspecialchars($ticketEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                    </td>
                                    <td class="task-ticket-department"><?= htmlspecialchars($ticketPosition, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="task-ticket-status">
                                        <span class="status-pill status-<?= htmlspecialchars(sales_manager_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td class="task-ticket-sla"><?= ticket_sla_badge_html((string) ($ticket['created_at'] ?? ''), $status, (string) ($ticket['priority'] ?? '')); ?></td>
                                    <td class="task-ticket-date"><?= htmlspecialchars(sales_manager_date((string) ($ticket['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="sales-manager-action-cell">
                                        <div class="ticket-action-buttons">
                                            <?php if (sales_manager_can_follow_up_ticket_status($status)): ?>
                                                <?php
                                                    $followUpSendCount = (int) ($ticket['follow_up_send_count'] ?? 0);
                                                    $followUpWindow = sales_manager_follow_up_window($ticket);
                                                    $followUpInCooldown = !empty($followUpWindow['in_cooldown']);
                                                    $followUpRemainingSeconds = (int) ($followUpWindow['remaining_seconds'] ?? 0);
                                                    $followUpCooldownLabel = $followUpInCooldown ? sales_manager_follow_up_cooldown_label($followUpRemainingSeconds) : '';
                                                ?>
                                                <button
                                                    type="button"
                                                    class="follow-up-btn<?= $followUpInCooldown ? ' is-sent follow-up-cooldown' : ''; ?>"
                                                    data-ticket-id="<?= $ticketId; ?>"
                                                    <?= $followUpInCooldown ? 'aria-disabled="true" tabindex="-1" disabled' : ''; ?>
                                                    <?= $followUpInCooldown && !empty($followUpWindow['available_at']) ? 'data-available-at="' . htmlspecialchars((string) $followUpWindow['available_at'], ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                                                    <?= $followUpInCooldown && !empty($followUpWindow['available_at_ts']) ? 'data-available-at-ts="' . (int) $followUpWindow['available_at_ts'] . '"' : ''; ?>
                                                    <?= $followUpInCooldown ? 'data-remaining-seconds="' . $followUpRemainingSeconds . '"' : ''; ?>
                                                    <?= $followUpInCooldown && $followUpCooldownLabel !== '' ? 'data-cooldown-label="' . htmlspecialchars($followUpCooldownLabel, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                                                ><?= $followUpInCooldown && $followUpSendCount > 0 ? 'Follow Up Sent' : 'Follow Up'; ?></button>
                                                <?php if ($followUpInCooldown && $followUpCooldownLabel !== ''): ?>
                                                    <span class="follow-up-cooldown-note"><?= htmlspecialchars($followUpCooldownLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (sales_manager_can_close_ticket_status($status)): ?>
                                                <form method="POST" action="sales_submitted_tickets.php" class="close-ticket-form">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="close_ticket">
                                                    <input type="hidden" name="ticket_id" value="<?= $ticketId; ?>">
                                                    <button type="submit" class="close-ticket-btn">Close Ticket</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="sales-manager-live-empty" style="display:none;"><td colspan="9" class="sales-manager-empty">No submitted tickets match your search.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <div id="salesFollowUpFeedbackOverlay" class="sales-action-overlay" aria-hidden="true">
        <div class="sales-action-dialog" role="dialog" aria-modal="true" aria-labelledby="salesFollowUpFeedbackTitle">
            <div id="salesFollowUpFeedbackIcon" class="sales-follow-up-feedback-icon" aria-hidden="true">&#10003;</div>
            <h2 id="salesFollowUpFeedbackTitle">Follow Up Sent</h2>
            <p id="salesFollowUpFeedbackText">Follow up sent successfully.</p>
            <div class="sales-action-dialog-actions">
                <button type="button" class="sales-action-submit" id="salesFollowUpFeedbackDoneBtn">Done</button>
            </div>
        </div>
    </div>
    <div id="salesFollowUpConfirmOverlay" class="sales-action-overlay" aria-hidden="true">
        <div class="sales-action-dialog" role="dialog" aria-modal="true" aria-labelledby="salesFollowUpConfirmTitle">
            <div class="sales-action-confirm-icon" aria-hidden="true">?</div>
            <h2 id="salesFollowUpConfirmTitle">Follow Up Ticket?</h2>
            <p>Do you want to send a follow up for this ticket?</p>
            <div class="sales-action-dialog-actions">
                <button type="button" class="sales-action-cancel" id="salesFollowUpCancelBtn">No</button>
                <button type="button" class="sales-action-submit" id="salesFollowUpConfirmBtn">Yes</button>
            </div>
        </div>
    </div>
    <div id="salesCloseConfirmOverlay" class="sales-action-overlay" aria-hidden="true">
        <div class="sales-action-dialog" role="dialog" aria-modal="true" aria-labelledby="salesCloseConfirmTitle">
            <div class="sales-action-confirm-icon" aria-hidden="true">?</div>
            <h2 id="salesCloseConfirmTitle">Close Ticket?</h2>
            <p>Are you sure you want to close this ticket?</p>
            <div class="sales-action-dialog-actions">
                <button type="button" class="sales-action-cancel" id="salesCloseCancelBtn">Cancel</button>
                <button type="button" class="sales-action-submit" id="salesCloseConfirmBtn">Close Ticket</button>
            </div>
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
    window.TM_HIDE_QUICK_TAGS = true;
    window.TM_DEPARTMENT_LABEL_TEXT = 'Assigned Department';
    window.TM_DEPARTMENT_REQUIRED = true;
    window.TM_SHOW_DEPARTMENT_USER_SELECT = true;
    window.TM_DEPARTMENT_USERS_ENDPOINT = 'ajax_department_users.php';
    window.TM_COMPANY_DEPARTMENT_OPTIONS = <?php echo json_encode(
        ticket_company_department_option_map(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
    </script>
    <script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
    <script>
    var salesSearchInput = document.querySelector('.employee-sales-manager-page .my-tickets-search-input');
    var salesTicketRows = Array.prototype.slice.call(document.querySelectorAll('.employee-sales-manager-page .ticket-row[data-id]'));
    var salesEmptyRow = document.querySelector('.employee-sales-manager-page .sales-manager-live-empty');
    var salesCsrfToken = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var pendingSalesFollowUp = null;
    var pendingSalesCloseForm = null;
    var salesFollowUpConfirmOverlay = document.getElementById('salesFollowUpConfirmOverlay');
    var salesFollowUpConfirmBtn = document.getElementById('salesFollowUpConfirmBtn');
    var salesFollowUpCancelBtn = document.getElementById('salesFollowUpCancelBtn');
    var salesFollowUpFeedbackOverlay = document.getElementById('salesFollowUpFeedbackOverlay');
    var salesFollowUpFeedbackDialog = salesFollowUpFeedbackOverlay ? salesFollowUpFeedbackOverlay.querySelector('.sales-action-dialog') : null;
    var salesFollowUpFeedbackIcon = document.getElementById('salesFollowUpFeedbackIcon');
    var salesFollowUpFeedbackTitle = document.getElementById('salesFollowUpFeedbackTitle');
    var salesFollowUpFeedbackText = document.getElementById('salesFollowUpFeedbackText');
    var salesFollowUpFeedbackDoneBtn = document.getElementById('salesFollowUpFeedbackDoneBtn');
    var salesFollowUpFeedbackState = '';
    var salesCloseConfirmOverlay = document.getElementById('salesCloseConfirmOverlay');
    var salesCloseConfirmBtn = document.getElementById('salesCloseConfirmBtn');
    var salesCloseCancelBtn = document.getElementById('salesCloseCancelBtn');

    function normalizeSalesSearch(value) {
        return String(value || '').toLowerCase().replace(/^#/, '').trim();
    }

    function applySalesLiveSearch() {
        var query = normalizeSalesSearch(salesSearchInput ? salesSearchInput.value : '');
        var visibleCount = 0;
        salesTicketRows.forEach(function (row) {
            var haystack = normalizeSalesSearch(row.getAttribute('data-search') || row.textContent || '');
            var shouldShow = query === '' || haystack.indexOf(query) !== -1;
            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) visibleCount++;
        });
        if (salesEmptyRow) {
            salesEmptyRow.style.display = visibleCount === 0 && salesTicketRows.length > 0 ? '' : 'none';
        }
    }

    if (salesSearchInput) {
        salesSearchInput.addEventListener('input', applySalesLiveSearch);
        salesSearchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        applySalesLiveSearch();
    }

    document.querySelectorAll('.employee-sales-manager-page .my-tickets-filter-select').forEach(function (select) {
        select.addEventListener('change', function () {
            if (this.form) this.form.submit();
        });
    });
    function showSalesOverlay(overlay) {
        if (!overlay) return;
        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
    }
    function hideSalesOverlay(overlay) {
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
    }
    function formatSalesCooldown(seconds) {
        seconds = Math.max(parseInt(seconds || 0, 10), 0);
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        if (hours > 0) return 'Next follow-up available in ' + hours + 'h ' + minutes + 'm';
        if (minutes > 0) return 'Next follow-up available in ' + minutes + 'm';
        return 'Next follow-up available in ' + (seconds % 60) + 's';
    }
    function setSalesFollowUpCooldown(button, data) {
        if (!button) return;
        button.disabled = true;
        button.setAttribute('disabled', 'disabled');
        button.classList.add('is-sent', 'follow-up-cooldown');
        button.setAttribute('aria-disabled', 'true');
        button.textContent = 'Follow Up Sent';
        var remaining = parseInt((data && data.remaining_seconds) || 0, 10);
        var cooldownLabel = formatSalesCooldown(remaining);
        button.setAttribute('data-cooldown-label', cooldownLabel);
        var note = button.parentElement ? button.parentElement.querySelector('.follow-up-cooldown-note') : null;
        if (!note && button.parentElement) {
            note = document.createElement('span');
            note.className = 'follow-up-cooldown-note';
            button.parentElement.insertBefore(note, button.nextSibling);
        }
        if (note) note.textContent = cooldownLabel;
    }
    function showSalesFollowUpFeedback(kind, title, message) {
        var isError = kind === 'error';
        var isPending = kind === 'pending';
        salesFollowUpFeedbackState = kind || '';
        if (salesFollowUpFeedbackDialog) salesFollowUpFeedbackDialog.classList.toggle('is-error', isError);
        if (salesFollowUpFeedbackDialog) salesFollowUpFeedbackDialog.classList.toggle('is-pending', isPending);
        if (salesFollowUpFeedbackIcon) salesFollowUpFeedbackIcon.innerHTML = isPending ? '<span class="sales-follow-up-feedback-icon-spinner"></span>' : (isError ? '!' : '&#10003;');
        if (salesFollowUpFeedbackTitle) salesFollowUpFeedbackTitle.textContent = title || (isPending ? 'Sending Follow Up' : (isError ? 'Follow Up Failed' : 'Follow Up Sent'));
        if (salesFollowUpFeedbackText) salesFollowUpFeedbackText.textContent = message || (isPending ? 'Please wait while we notify the assigned team.' : (isError ? 'Unable to send follow up right now.' : 'Follow up sent successfully.'));
        if (salesFollowUpFeedbackDoneBtn) {
            salesFollowUpFeedbackDoneBtn.hidden = isPending;
            salesFollowUpFeedbackDoneBtn.disabled = isPending;
            salesFollowUpFeedbackDoneBtn.textContent = isPending ? 'Sending...' : 'Done';
        }
        showSalesOverlay(salesFollowUpFeedbackOverlay);
    }
    function sendSalesFollowUp(ticketId, button) {
        if (!ticketId || !button) return;
        button.disabled = true;
        button.textContent = 'Sending...';
        showSalesFollowUpFeedback('pending', 'Sending Follow Up', 'Please wait while we notify the assigned team.');
        var formData = new FormData();
        formData.append('action', 'follow_up');
        formData.append('ticket_id', String(ticketId));
        formData.append('csrf_token', salesCsrfToken);
        fetch('sales_submitted_tickets.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': salesCsrfToken
            },
            body: formData
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    try { return JSON.parse(text); } catch (e) { return { ok: false, error: 'Invalid server response.' }; }
                });
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    button.disabled = false;
                    button.textContent = 'Follow Up';
                    showSalesFollowUpFeedback('error', 'Follow Up Failed', data && data.error ? data.error : 'Unable to send follow up right now.');
                    return;
                }
                setSalesFollowUpCooldown(button, data);
                showSalesFollowUpFeedback('success', 'Follow Up Sent', data.message || 'Follow up sent successfully.');
            })
            .catch(function () {
                button.disabled = false;
                button.textContent = 'Follow Up';
                showSalesFollowUpFeedback('error', 'Follow Up Failed', 'Unable to send follow up right now.');
            });
    }
    document.addEventListener('click', function (event) {
        var followUpBtn = event.target && event.target.closest ? event.target.closest('.employee-sales-manager-page .follow-up-btn') : null;
        if (followUpBtn) {
            event.preventDefault();
            event.stopPropagation();
            if (followUpBtn.disabled || followUpBtn.getAttribute('aria-disabled') === 'true') return;
            pendingSalesFollowUp = {
                ticketId: parseInt(followUpBtn.getAttribute('data-ticket-id') || '0', 10),
                button: followUpBtn
            };
            showSalesOverlay(salesFollowUpConfirmOverlay);
            return;
        }
    });
    document.addEventListener('submit', function (event) {
        var closeForm = event.target && event.target.closest ? event.target.closest('.employee-sales-manager-page .close-ticket-form') : null;
        if (!closeForm) return;
        event.stopPropagation();
        if (closeForm.getAttribute('data-confirmed-close') === '1') return;
        event.preventDefault();
        pendingSalesCloseForm = closeForm;
        showSalesOverlay(salesCloseConfirmOverlay);
    });
    if (salesFollowUpConfirmBtn) {
        salesFollowUpConfirmBtn.addEventListener('click', function () {
            var action = pendingSalesFollowUp;
            pendingSalesFollowUp = null;
            hideSalesOverlay(salesFollowUpConfirmOverlay);
            if (action) sendSalesFollowUp(action.ticketId, action.button);
        });
    }
    if (salesFollowUpCancelBtn) {
        salesFollowUpCancelBtn.addEventListener('click', function () {
            pendingSalesFollowUp = null;
            hideSalesOverlay(salesFollowUpConfirmOverlay);
        });
    }
    if (salesFollowUpFeedbackDoneBtn) {
        salesFollowUpFeedbackDoneBtn.addEventListener('click', function () {
            salesFollowUpFeedbackState = '';
            hideSalesOverlay(salesFollowUpFeedbackOverlay);
        });
    }
    if (salesCloseConfirmBtn) {
        salesCloseConfirmBtn.addEventListener('click', function () {
            if (!pendingSalesCloseForm) return;
            var form = pendingSalesCloseForm;
            pendingSalesCloseForm = null;
            form.setAttribute('data-confirmed-close', '1');
            var button = form.querySelector('.close-ticket-btn');
            if (button) {
                button.disabled = true;
                button.textContent = 'Closing...';
            }
            hideSalesOverlay(salesCloseConfirmOverlay);
            form.submit();
        });
    }
    if (salesCloseCancelBtn) {
        salesCloseCancelBtn.addEventListener('click', function () {
            pendingSalesCloseForm = null;
            hideSalesOverlay(salesCloseConfirmOverlay);
        });
    }
    [salesFollowUpConfirmOverlay, salesFollowUpFeedbackOverlay, salesCloseConfirmOverlay].forEach(function (overlay) {
        if (!overlay) return;
        overlay.addEventListener('click', function (event) {
            if (overlay === salesFollowUpFeedbackOverlay && salesFollowUpFeedbackState === 'pending') return;
            if (event.target === overlay) hideSalesOverlay(overlay);
        });
    });
    document.querySelectorAll('.employee-sales-manager-page .ticket-row[data-id]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target && event.target.closest && event.target.closest('.ticket-action-buttons')) return;
            var id = this.getAttribute('data-id');
            if (!id) return;
            if (window.TMTicketModal && typeof window.TMTicketModal.open === 'function') {
                window.TMTicketModal.open(id);
                return;
            }
        });
    });

    (function () {
        var params = new URLSearchParams(window.location.search);
        var ticketId = params.get('ticket_id');
        if (!ticketId || !(window.TMTicketModal && typeof window.TMTicketModal.open === 'function')) return;
        window.TMTicketModal.open(ticketId);
        params.delete('ticket_id');
        var nextQuery = params.toString();
        var nextUrl = window.location.pathname + (nextQuery ? '?' + nextQuery : '') + window.location.hash;
        window.history.replaceState(null, '', nextUrl);
    })();
    </script>
</body>
</html>
