<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

function can_follow_up_ticket_status(string $status): bool
{
    $status = strtoupper(trim($status));
    return $status === 'OPEN' || $status === 'IN PROGRESS';
}

function submitted_ticket_target_label(array $row): string
{
    $assignedCompanyRaw = (string) (($row['assigned_company'] ?? '') !== '' ? $row['assigned_company'] : ($row['company'] ?? ''));
    $assignedCompany = ticket_normalize_company($assignedCompanyRaw);
    $assignedGroup = trim((string) (($row['assigned_group'] ?? '') !== '' ? $row['assigned_group'] : ($row['assigned_department'] ?? '')));

    if ($assignedCompany === '@leadsagri.com' && $assignedGroup !== '') {
        return ticket_department_display_name($assignedGroup) . ' (LAPC)';
    }

    $companyLabel = ticket_company_display_name($assignedCompanyRaw);
    if ($companyLabel !== '') {
        return $companyLabel;
    }

    if ($assignedGroup !== '') {
        return ticket_department_display_name($assignedGroup);
    }

    return '-';
}

function follow_up_company_user_ids(mysqli $conn, string $company, int $excludeUserId = 0): array
{
    $excludeUserId = (int) $excludeUserId;
    $company = trim($company);
    if ($company === '') {
        return [];
    }

    $ids = [];
    if (strpos($company, '@') === 0) {
        $domain = strtolower(ltrim($company, '@'));
        if ($domain === '') {
            return [];
        }

        $emailPattern = '%@' . $domain;
        if ($excludeUserId > 0) {
            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE LOWER(TRIM(COALESCE(email, ''))) LIKE ?
                  AND id <> ?
            ");
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param("si", $emailPattern, $excludeUserId);
        } else {
            $stmt = $conn->prepare("
                SELECT id
                FROM users
                WHERE LOWER(TRIM(COALESCE(email, ''))) LIKE ?
            ");
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param("s", $emailPattern);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
        $stmt->close();

        return notif_unique_user_ids($ids);
    }

    $aliases = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return strtoupper(trim((string) $value));
    }, ticket_company_aliases($company)), static function ($value): bool {
        return $value !== '';
    })));
    if (count($aliases) === 0) {
        return [];
    }

    $escapedAliases = array_map(static function (string $value) use ($conn): string {
        return "'" . $conn->real_escape_string($value) . "'";
    }, $aliases);
    $sql = "
        SELECT id
        FROM users
        WHERE UPPER(TRIM(COALESCE(company, ''))) IN (" . implode(', ', $escapedAliases) . ")
    ";
    if ($excludeUserId > 0) {
        $sql .= " AND id <> " . $excludeUserId;
    }

    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return notif_unique_user_ids($ids);
}

function follow_up_recipients(mysqli $conn, array $ticket, int $creatorUserId): array
{
    $creatorUserId = (int) $creatorUserId;
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    $assignedCompany = (string) (($ticket['assigned_company'] ?? '') !== '' ? $ticket['assigned_company'] : ($ticket['company'] ?? ''));
    $assignedDepartment = trim((string) (($ticket['assignee_department'] ?? '') !== '' ? $ticket['assignee_department'] : (($ticket['assigned_group'] ?? '') !== '' ? $ticket['assigned_group'] : ($ticket['assigned_department'] ?? ''))));

    $recipientIds = [];
    if ($assignedUserId > 0 && $assignedUserId !== $creatorUserId) {
        $recipientIds[] = $assignedUserId;
    }

    $recipientIds = array_merge($recipientIds, follow_up_company_user_ids($conn, $assignedCompany, $creatorUserId));
    if ($assignedDepartment !== '') {
        $recipientIds = array_merge($recipientIds, notif_department_user_ids($conn, $assignedDepartment));
    }

    $recipientIds = array_values(array_filter(notif_unique_user_ids($recipientIds), static function ($userId) use ($creatorUserId) {
        return (int) $userId > 0 && (int) $userId !== $creatorUserId;
    }));

    return [
        'user_ids' => $recipientIds,
        'emails' => follow_up_recipient_emails($conn, $recipientIds),
    ];
}

function follow_up_recipient_emails(mysqli $conn, array $userIds): array
{
    $userIds = notif_unique_user_ids($userIds);
    if (count($userIds) === 0) {
        return [];
    }

    $escapedIds = array_map('intval', $userIds);
    $sql = "
        SELECT DISTINCT LOWER(TRIM(COALESCE(email, ''))) AS email
        FROM users
        WHERE id IN (" . implode(', ', $escapedIds) . ")
          AND TRIM(COALESCE(email, '')) <> ''
    ";
    $res = $conn->query($sql);
    $emails = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return array_values(array_unique($emails));
}

function follow_up_cooldown_window(mysqli $conn, int $ticketId): array
{
    $stmt = $conn->prepare("
        SELECT
            MAX(created_at) AS last_sent_at,
            DATE_ADD(MAX(created_at), INTERVAL 2 DAY) AS available_at,
            CASE
                WHEN MAX(created_at) IS NOT NULL
                 AND DATE_ADD(MAX(created_at), INTERVAL 2 DAY) > NOW()
                THEN 1
                ELSE 0
            END AS in_cooldown
        FROM notifications
        WHERE ticket_id = ?
          AND type = 'follow_up'
    ");
    if (!$stmt) {
        return [
            'last_sent_at' => null,
            'available_at' => null,
            'in_cooldown' => false,
        ];
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return [
        'last_sent_at' => $row['last_sent_at'] ?? null,
        'available_at' => $row['available_at'] ?? null,
        'in_cooldown' => !empty($row['in_cooldown']),
    ];
}

function follow_up_cooldown_message(array $window): string
{
    $availableAt = trim((string) ($window['available_at'] ?? ''));
    if ($availableAt === '') {
        return 'Follow up was already sent for this ticket.';
    }

    $timestamp = strtotime($availableAt);
    if ($timestamp === false) {
        return 'Follow up can be sent again after 2 days.';
    }

    return 'Follow up can be sent again on ' . date('M d, Y h:i A', $timestamp) . '.';
}

function follow_up_insert_notifications(mysqli $conn, array $recipientIds, int $ticketId, string $message, string $title): int
{
    $recipientIds = notif_unique_user_ids($recipientIds);
    if (count($recipientIds) === 0) {
        return 0;
    }

    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);

    $type = 'follow_up';
    $actionType = notif_normalize_action_type('update', $type);
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        error_log('Follow up notification insert prepare failed | ticketId=' . (string) $ticketId . ' err=' . (string) $conn->error);
        return 0;
    }

    $inserted = 0;
    foreach ($recipientIds as $recipientId) {
        $notifyUserId = (int) $recipientId;
        if ($notifyUserId <= 0) {
            continue;
        }

        $stmt->bind_param("iissss", $notifyUserId, $ticketId, $title, $message, $type, $actionType);
        if ($stmt->execute()) {
            $inserted++;
            continue;
        }

        error_log('Follow up notification insert failed | userId=' . (string) $notifyUserId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $stmt->error);
    }

    $stmt->close();

    return $inserted;
}

function finish_follow_up_response(array $payload): void
{
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    ignore_user_abort(true);

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        $body = '{"ok":false,"error":"Unable to send follow up right now."}';
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Connection: close');
        header('Content-Encoding: none');
        header('Content-Length: ' . strlen($body));
    }

    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    @flush();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'follow_up') {
    header('Content-Type: application/json');
    csrf_validate();

    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    if ($user_id <= 0 || $ticketId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid ticket selection.']);
        exit;
    }

    $ticketStmt = $conn->prepare("
        SELECT
            t.id,
            t.user_id,
            t.category,
            t.status,
            t.company,
            t.assigned_company,
            t.assigned_department,
            t.assigned_group,
            t.assigned_user_id,
            COALESCE(NULLIF(TRIM(t.requester_name), ''), creator.name) AS creator_name,
            COALESCE(NULLIF(TRIM(t.requester_email), ''), creator.email) AS creator_email,
            assignee.department AS assignee_department
        FROM employee_tickets t
        LEFT JOIN users creator ON creator.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
        WHERE t.id = ?
        LIMIT 1
    ");
    if (!$ticketStmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process follow up right now.']);
        exit;
    }
    $ticketStmt->bind_param("i", $ticketId);
    $ticketStmt->execute();
    $ticketRes = $ticketStmt->get_result();
    $ticket = $ticketRes ? $ticketRes->fetch_assoc() : null;
    $ticketStmt->close();

    if (!$ticket || (int) ($ticket['user_id'] ?? 0) !== $user_id) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You are not allowed to follow up this ticket.']);
        exit;
    }

    $statusLabel = trim((string) ($ticket['status'] ?? ''));
    if (!can_follow_up_ticket_status($statusLabel)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Follow up is only available for Open and In Progress tickets.']);
        exit;
    }

    $ticketNumber = notif_ticket_number($ticketId);
    $title = 'Ticket Follow Up';
    $requestorName = trim((string) ($ticket['creator_name'] ?? ''));
    if ($requestorName === '') {
        $requestorName = 'the requestor';
    }
    $message = 'Ticket #' . $ticketNumber . ' has a follow-up request from ' . $requestorName . '. Please check the ticket.';

    $cooldownWindow = follow_up_cooldown_window($conn, $ticketId);
    if (!empty($cooldownWindow['in_cooldown'])) {
        $availableAtValue = trim((string) ($cooldownWindow['available_at'] ?? ''));
        $availableAtTimestamp = $availableAtValue !== '' ? strtotime($availableAtValue) : false;
        http_response_code(429);
        echo json_encode([
            'ok' => false,
            'error' => follow_up_cooldown_message($cooldownWindow),
            'cooldown_active' => true,
            'available_at' => $availableAtValue !== '' ? $availableAtValue : null,
            'available_at_ts' => $availableAtTimestamp !== false ? (int) $availableAtTimestamp : null,
        ]);
        exit;
    }

    $recipients = follow_up_recipients($conn, $ticket, $user_id);
    if (count($recipients['user_ids']) === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No recipients available for this follow up request.']);
        exit;
    }

    $inserted = follow_up_insert_notifications($conn, $recipients['user_ids'], $ticketId, $message, $title);
    if ($inserted <= 0) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to process follow up right now.']);
        exit;
    }

    $newCooldownWindow = follow_up_cooldown_window($conn, $ticketId);
    $newAvailableAtValue = trim((string) ($newCooldownWindow['available_at'] ?? ''));
    $newAvailableAtTimestamp = $newAvailableAtValue !== '' ? strtotime($newAvailableAtValue) : false;
    $responsePayload = [
        'ok' => true,
        'message' => 'Follow up sent successfully.',
        'notifications_sent' => $inserted,
        'emails_sent' => 0,
        'cooldown_active' => !empty($newCooldownWindow['in_cooldown']),
        'available_at' => $newAvailableAtValue !== '' ? $newAvailableAtValue : null,
        'available_at_ts' => $newAvailableAtTimestamp !== false ? (int) $newAvailableAtTimestamp : null,
    ];
    finish_follow_up_response($responsePayload);

    if (count($recipients['emails']) > 0) {
        $ticketData = notif_ticket_data($conn, $ticketId);
        $attachments = notif_ticket_email_attachments($conn, $ticketId, (string) ($ticketData['attachment'] ?? ''));
        $attachmentSummary = notif_ticket_attachment_summary($attachments);
        $assignedTarget = trim((string) (($ticket['assignee_department'] ?? '') !== '' ? $ticket['assignee_department'] : (($ticket['assigned_group'] ?? '') !== '' ? $ticket['assigned_group'] : ($ticket['assigned_department'] ?? ''))));
        $lines = [
            'Ticket ID: #' . $ticketNumber,
            'Category: ' . trim((string) ($ticket['category'] ?? '-')),
            'Status: ' . ($statusLabel !== '' ? $statusLabel : '-'),
            'Assigned To: ' . ($assignedTarget !== '' ? $assignedTarget : '-'),
            'Requested by: ' . $requestorName,
            'Follow-up Message: ' . $message,
        ];
        if ($attachmentSummary !== '') {
            $lines[] = $attachmentSummary;
        }
        $lines = notif_compact_email_lines($lines);
        $mail = notif_email_simple($title, $lines, 'View Task', notif_ticket_link_employee_tasks($ticketId));
        if (!notif_email_send($recipients['emails'], $title . ' (#' . $ticketNumber . ')', (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''), $attachments)) {
            error_log('Follow up email send failed | ticketId=' . (string) $ticketId . ' recipients=' . implode(',', $recipients['emails']));
        }
    }

    exit;
}

function render_my_tickets_pagination(int $page, int $totalPages, int $showingFrom, int $showingTo, int $totalRecords): string
{
    if ($totalRecords <= 0) {
        return '';
    }

    $html = '<div class="pagination-glass">';
    $html .= '<div class="pagination-summary">Showing ' . number_format($showingFrom) . ' - ' . number_format($showingTo) . ' of ' . number_format($totalRecords) . ' tickets</div>';

    if ($totalPages > 1) {
        $html .= '<a href="?page=' . max(1, $page - 1) . '" data-page="' . max(1, $page - 1) . '" class="page-btn prev' . ($page <= 1 ? ' disabled' : '') . '">&lsaquo; Previous</a>';
        $html .= '<div class="page-numbers">';

        $window = 2;
        $startPage = max(1, $page - $window);
        $endPage = min($totalPages, $page + $window);

        if ($startPage > 1) {
            $html .= '<a href="?page=1" data-page="1" class="page-btn' . ($page === 1 ? ' active' : '') . '">1</a>';
            if ($startPage > 2) {
                $html .= '<span class="page-ellipsis">...</span>';
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $html .= '<a href="?page=' . $i . '" data-page="' . $i . '" class="page-btn' . ($i === $page ? ' active' : '') . '">' . $i . '</a>';
        }

        if ($endPage < $totalPages) {
            if ($endPage < ($totalPages - 1)) {
                $html .= '<span class="page-ellipsis">...</span>';
            }
            $html .= '<a href="?page=' . $totalPages . '" data-page="' . $totalPages . '" class="page-btn' . ($page === $totalPages ? ' active' : '') . '">' . $totalPages . '</a>';
        }

        $html .= '</div>';
        $html .= '<a href="?page=' . min($totalPages, $page + 1) . '" data-page="' . min($totalPages, $page + 1) . '" class="page-btn next' . ($page >= $totalPages ? ' disabled' : '') . '">Next &rsaquo;</a>';
    }

    $html .= '</div>';

    return $html;
}

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
    SELECT
        t.*,
        fu.last_follow_up_sent_at,
        fu.follow_up_available_at,
        CASE
            WHEN fu.last_follow_up_sent_at IS NOT NULL
             AND fu.follow_up_available_at > NOW()
            THEN 1
            ELSE 0
        END AS follow_up_in_cooldown
    FROM employee_tickets t
    LEFT JOIN (
        SELECT
            ticket_id,
            MAX(created_at) AS last_follow_up_sent_at,
            DATE_ADD(MAX(created_at), INTERVAL 2 DAY) AS follow_up_available_at
        FROM notifications
        WHERE type = 'follow_up'
        GROUP BY ticket_id
    ) fu ON fu.ticket_id = t.id
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
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
        body.employee-my-tickets-page .table-card.my-tickets-card {
            min-height: 760px;
            display: flex;
            flex-direction: column;
        }
        body.employee-my-tickets-page .my-tickets-table-shell {
            flex: 1 1 auto;
            min-height: 620px;
            display: flex;
            flex-direction: column;
        }
        body.employee-my-tickets-page .table-responsive.my-tickets-table-responsive {
            flex: 1 1 auto;
            overflow-x: auto;
            overflow-y: visible;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive table {
            table-layout: fixed;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive th:nth-child(1),
        body.employee-my-tickets-page .my-tickets-table-responsive td:nth-child(1) {
            width: 90px;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive th:nth-child(2),
        body.employee-my-tickets-page .my-tickets-table-responsive td:nth-child(2) {
            width: 26%;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive th:nth-child(3),
        body.employee-my-tickets-page .my-tickets-table-responsive td:nth-child(3) {
            width: 140px;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive th:nth-child(4),
        body.employee-my-tickets-page .my-tickets-table-responsive td:nth-child(4) {
            width: 28%;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive th:nth-child(5),
        body.employee-my-tickets-page .my-tickets-table-responsive td:nth-child(5) {
            width: 150px;
        }
        body.employee-my-tickets-page .my-tickets-table-responsive th:nth-child(6),
        body.employee-my-tickets-page .my-tickets-table-responsive td:nth-child(6) {
            width: 150px;
        }
        body.employee-my-tickets-page #myTicketsTbody tr {
            height: 58px;
        }
        body.employee-my-tickets-page .follow-up-cell {
            text-align: center;
            overflow: visible;
        }
        body.employee-my-tickets-page .follow-up-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 112px;
            min-height: 38px;
            padding: 0 18px;
            position: relative;
            border: 0;
            border-radius: 999px;
            background: linear-gradient(180deg, #1f7a36 0%, #16602a 100%);
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.01em;
            box-shadow: 0 10px 22px rgba(22, 96, 42, 0.2);
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        body.employee-my-tickets-page .follow-up-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 24px rgba(22, 96, 42, 0.24);
        }
        body.employee-my-tickets-page .follow-up-btn:disabled {
            opacity: 0.7;
            cursor: wait;
            transform: none;
        }
        body.employee-my-tickets-page .follow-up-btn.is-sent,
        body.employee-my-tickets-page .follow-up-btn.is-sent:hover,
        body.employee-my-tickets-page .follow-up-btn.is-sent:disabled {
            background: linear-gradient(180deg, #dbe5dc 0%, #cfdad1 100%);
            color: #46604d;
            box-shadow: none;
            cursor: default;
            opacity: 1;
            transform: none;
        }
        body.employee-my-tickets-page .follow-up-btn.follow-up-cooldown {
            cursor: not-allowed;
            transition: all 0.2s ease;
            overflow: visible;
            z-index: 0;
            isolation: isolate;
        }
        body.employee-my-tickets-page .follow-up-btn.follow-up-cooldown::before {
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
        body.employee-my-tickets-page .follow-up-btn.follow-up-cooldown::after {
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
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:hover,
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:focus-visible {
            cursor: not-allowed;
            z-index: 1;
            box-shadow: 0 0 0 2px rgba(133, 182, 138, 0.85), 0 0 0 6px rgba(133, 182, 138, 0.2), 0 12px 28px rgba(113, 160, 118, 0.18);
        }
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:hover::before,
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::before,
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:hover::after,
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::after {
            opacity: 1;
            visibility: visible;
        }
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:hover::before,
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::before {
            transform: translateX(-50%) translateY(0);
        }
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:hover::after,
        body.employee-my-tickets-page .follow-up-btn.is-sent.follow-up-cooldown:focus-visible::after {
            transform: translateX(-50%) rotate(45deg) translateY(0);
            box-shadow: 0 0 0 0 rgba(15, 122, 42, 0), 0 8px 16px rgba(58, 91, 65, 0.07);
        }
        body.employee-my-tickets-page #myTicketsPagination {
            margin-top: auto;
            padding-top: 18px;
        }
        body.employee-my-tickets-page #myTicketsPagination .pagination-glass {
            margin-top: 0;
            margin-bottom: 0;
            min-height: 46px;
            justify-content: space-between;
        }
        body.employee-my-tickets-page #myTicketsPagination .pagination-summary {
            flex: 1 1 auto;
        }
        body.employee-my-tickets-page #myTicketsPagination .page-numbers {
            min-width: 224px;
            justify-content: center;
        }
        #ticketSuccessOverlay {
            display: none !important;
        }
        #ticketModal .modal-content {
            width: min(96vw, 1220px);
            max-width: 1220px;
            height: min(92vh, 760px);
            max-height: 92vh;
        }
                body.employee-my-tickets-page .tm-tab-content.active#tab-info > .tm-info-col,
        body.employee-my-tickets-page .tm-tab-content.active#tab-info > .tm-desc-col {
            display: flex;
        }
        body.employee-my-tickets-page .tm-tab-content.active#tab-info > .tm-info-col {
            order: -1;
        }
        body.employee-my-tickets-page .tm-tab-content.active#tab-info > .tm-desc-col {
            order: 1;
        }
        .page-ellipsis {
            color: #94a3b8;
            font-weight: 700;
            padding: 0 4px;
            user-select: none;
        }
        body.employee-my-tickets-page .follow-up-feedback-overlay {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background:
                radial-gradient(circle at top, rgba(61, 122, 77, 0.16), transparent 34%),
                rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(10px);
            z-index: 10060;
        }
        body.employee-my-tickets-page .follow-up-feedback-overlay.is-visible {
            display: flex;
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog {
            width: min(100%, 460px);
            background:
                radial-gradient(circle at top center, rgba(141, 231, 160, 0.18), transparent 32%),
                linear-gradient(180deg, #ffffff 0%, #fbfffc 100%);
            border-radius: 28px;
            border: 1px solid rgba(214, 232, 221, 0.92);
            box-shadow: 0 32px 90px rgba(15, 23, 42, 0.22);
            position: relative;
            overflow: hidden;
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 7px;
            background: linear-gradient(90deg, #1f6d34 0%, #3a8350 55%, #1f6d34 100%);
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-error::before {
            background: linear-gradient(90deg, #b91c1c 0%, #ef4444 55%, #b91c1c 100%);
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-pending::before {
            background: linear-gradient(90deg, #166534 0%, #22c55e 55%, #166534 100%);
        }
        body.employee-my-tickets-page .follow-up-feedback-body {
            padding: 34px 34px 30px;
            text-align: center;
        }
        body.employee-my-tickets-page .follow-up-feedback-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 999px;
            background: rgba(226, 232, 240, 0.64);
            color: #475569;
            font-size: 22px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease;
        }
        body.employee-my-tickets-page .follow-up-feedback-close:hover {
            background: rgba(203, 213, 225, 0.9);
            color: #1e293b;
            transform: scale(1.03);
        }
        body.employee-my-tickets-page .follow-up-feedback-close:focus-visible,
        body.employee-my-tickets-page .follow-up-feedback-btn:focus-visible {
            outline: 3px solid rgba(59, 130, 246, 0.28);
            outline-offset: 2px;
        }
        body.employee-my-tickets-page .follow-up-feedback-label {
            margin: 0 0 18px;
            color: #2f7b3d;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-error .follow-up-feedback-label {
            color: #dc2626;
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-pending .follow-up-feedback-label {
            color: #166534;
        }
        body.employee-my-tickets-page .follow-up-feedback-icon {
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
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-error .follow-up-feedback-icon {
            background: linear-gradient(180deg, #fff7f7 0%, #fff1f2 100%);
            color: #b91c1c;
            border-color: #fecdd3;
            box-shadow:
                0 0 0 10px rgba(239, 68, 68, 0.08),
                0 14px 34px rgba(185, 28, 28, 0.14);
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-pending .follow-up-feedback-icon {
            background: linear-gradient(180deg, #f7fff8 0%, #dcfce7 100%);
            color: #166534;
            border-color: #86efac;
            box-shadow:
                0 0 0 10px rgba(34, 197, 94, 0.1),
                0 14px 34px rgba(22, 101, 52, 0.16);
        }
        body.employee-my-tickets-page .follow-up-feedback-icon-spinner {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 4px solid rgba(34, 197, 94, 0.18);
            border-top-color: #166534;
            animation: follow-up-feedback-spin 0.8s linear infinite;
        }
        body.employee-my-tickets-page .follow-up-feedback-title {
            margin: 0 0 10px;
            color: #0f172a;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }
        body.employee-my-tickets-page .follow-up-feedback-text {
            margin: 0;
            color: #5b6b80;
            font-size: 16px;
            line-height: 1.65;
        }
        body.employee-my-tickets-page .follow-up-feedback-actions {
            padding-top: 24px;
            display: flex;
            justify-content: center;
        }
        body.employee-my-tickets-page .follow-up-feedback-btn {
            min-width: 132px;
            min-height: 46px;
            border: 0;
            border-radius: 999px;
            background: linear-gradient(180deg, #1f7a36 0%, #16602a 100%);
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
            box-shadow: 0 16px 32px rgba(22, 96, 42, 0.2);
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        body.employee-my-tickets-page .follow-up-feedback-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 34px rgba(22, 96, 42, 0.22);
        }
        body.employee-my-tickets-page .follow-up-feedback-dialog.is-error .follow-up-feedback-btn {
            background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 12px 24px rgba(185, 28, 28, 0.2);
        }
        body.employee-my-tickets-page .follow-up-feedback-close[hidden],
        body.employee-my-tickets-page .follow-up-feedback-btn[hidden] {
            display: none !important;
        }
        @keyframes follow-up-feedback-spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
        @media (max-width: 640px) {
            body.employee-my-tickets-page .follow-up-feedback-overlay {
                padding: 18px;
            }
            body.employee-my-tickets-page .follow-up-feedback-dialog {
                border-radius: 24px;
            }
            body.employee-my-tickets-page .follow-up-feedback-body {
                padding: 30px 22px 24px;
            }
            body.employee-my-tickets-page .follow-up-feedback-close {
                top: 12px;
                right: 12px;
            }
            body.employee-my-tickets-page .follow-up-feedback-title {
                font-size: 24px;
            }
            body.employee-my-tickets-page .follow-up-feedback-text {
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <script>document.body.classList.add('tm-hide-requestor-admin-chat');</script>
    <script>document.body.classList.add('employee-my-tickets-page');</script>

    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

            <div class="page-header">
                <h1 class="page-title">My Submitted Tickets</h1>
            </div>

            <div class="table-card my-tickets-card">
                <div class="my-tickets-table-shell">
                <div class="table-responsive my-tickets-table-responsive">
                    <table>
                        <colgroup>
                            <col style="width: 90px;">
                            <col style="width: 26%;">
                            <col style="width: 140px;">
                            <col style="width: 28%;">
                            <col style="width: 150px;">
                            <col style="width: 150px;">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Passed To</th>
                                <th>Date Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="myTicketsTbody">
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
                                    <td data-label="Passed To"><?= htmlspecialchars(submitted_ticket_target_label($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="Date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                    <td data-label="Action" class="follow-up-cell">
                                        <?php if (can_follow_up_ticket_status((string) ($row['status'] ?? ''))): ?>
                                            <?php
                                                $followUpInCooldown = !empty($row['follow_up_in_cooldown']);
                                                $followUpAvailableAt = trim((string) ($row['follow_up_available_at'] ?? ''));
                                                $followUpAvailableTs = 0;
                                                if ($followUpInCooldown && $followUpAvailableAt !== '') {
                                                    $followUpTimestamp = strtotime($followUpAvailableAt);
                                                    if ($followUpTimestamp !== false) {
                                                        $followUpAvailableTs = (int) $followUpTimestamp;
                                                    }
                                                }
                                            ?>
                                            <button
                                                type="button"
                                                class="follow-up-btn<?= $followUpInCooldown ? ' is-sent follow-up-cooldown' : ''; ?>"
                                                data-ticket-id="<?= (int) $row['id']; ?>"
                                                aria-label="<?= $followUpInCooldown ? 'Follow up is on cooldown for ticket #' : 'Follow up ticket #'; ?><?= (int) $row['id']; ?>"
                                                <?= $followUpInCooldown ? 'aria-disabled="true" tabindex="-1"' : ''; ?>
                                                <?= $followUpInCooldown && $followUpAvailableAt !== '' ? 'data-available-at="' . htmlspecialchars($followUpAvailableAt, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
                                                <?= $followUpInCooldown && $followUpAvailableTs > 0 ? 'data-available-at-ts="' . $followUpAvailableTs . '"' : ''; ?>
                                                <?= $followUpInCooldown ? 'data-cooldown-label="Available in 48 hours"' : ''; ?>
                                            ><?= $followUpInCooldown ? 'Follow Up Sent' : 'Follow Up'; ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #94a3b8; padding: 40px;">
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
                <div id="myTicketsPagination">
                <?= render_my_tickets_pagination($page, $total_pages, $showing_from, $showing_to, $total_records); ?>
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
            <button class="preview-close" onclick="TMTicketModal.closeImagePreview(event)">Ã—</button>
            <img id="previewImage" src="" alt="Preview" class="preview-image">
        </div>
    </div>
    <div id="ticketSuccessOverlay" class="ticket-success-overlay" aria-hidden="true">
        <div class="ticket-success-card" role="dialog" aria-modal="true" aria-labelledby="ticketSuccessTitle">
            <div class="ticket-success-icon">âœ“</div>
            <h2 id="ticketSuccessTitle" class="ticket-success-title">Ticket Submitted Successfully</h2>
            <p class="ticket-success-text"><?= htmlspecialchars($successMessage !== '' ? $successMessage : 'Your request has been sent. Our team will get back to you soon.', ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="ticket-success-actions">
                <button type="button" id="ticketSuccessBtn" class="ticket-success-btn">Done</button>
            </div>
        </div>
    </div>
    <div id="followUpFeedbackOverlay" class="follow-up-feedback-overlay" aria-hidden="true">
        <div id="followUpFeedbackDialog" class="follow-up-feedback-dialog" role="dialog" aria-modal="true" aria-labelledby="followUpFeedbackTitle">
            <div class="follow-up-feedback-body">
                <p id="followUpFeedbackLabel" class="follow-up-feedback-label">Ticket Update</p>
                <div id="followUpFeedbackIcon" class="follow-up-feedback-icon" aria-hidden="true">&#10003;</div>
                <h2 id="followUpFeedbackTitle" class="follow-up-feedback-title">Follow Up Sent</h2>
                <p id="followUpFeedbackText" class="follow-up-feedback-text">Follow up sent successfully.</p>
                <div class="follow-up-feedback-actions">
                    <button type="button" id="followUpFeedbackBtn" class="follow-up-feedback-btn">OK</button>
                </div>
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
    var myTicketsBodyEl = document.getElementById('myTicketsTbody');
    var myTicketsPaginationEl = document.getElementById('myTicketsPagination');
    var myTicketsCurrentPage = <?= (int) $page ?>;
    var myTicketsAutoRefreshMs = 10000;
    var followUpInFlight = {};
    var followUpFeedbackOverlay = document.getElementById('followUpFeedbackOverlay');
    var followUpFeedbackDialog = document.getElementById('followUpFeedbackDialog');
    var followUpFeedbackLabel = document.getElementById('followUpFeedbackLabel');
    var followUpFeedbackTitle = document.getElementById('followUpFeedbackTitle');
    var followUpFeedbackText = document.getElementById('followUpFeedbackText');
    var followUpFeedbackIcon = document.getElementById('followUpFeedbackIcon');
    var followUpFeedbackBtn = document.getElementById('followUpFeedbackBtn');
    var followUpFeedbackClose = document.getElementById('followUpFeedbackClose');
    var followUpFeedbackState = '';
    var followUpCooldownTimers = {};

    function getMyTicketsCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? String(meta.getAttribute('content') || '') : '';
        if (!token && window.TM_CSRF_TOKEN) {
            token = String(window.TM_CSRF_TOKEN || '');
        }
        return token;
    }

    function myTicketsModalOpen() {
        var overlay = document.getElementById('ticketModal');
        return !!(overlay && overlay.style.display === 'flex');
    }

    function refreshMyTickets(page, updateHistory) {
        if (!myTicketsBodyEl || !myTicketsPaginationEl) return;
        var nextPage = parseInt(page || myTicketsCurrentPage || 1, 10);
        if (!nextPage || nextPage < 1) nextPage = 1;
        var params = new URLSearchParams();
        params.set('page', String(nextPage));
        params.set('limit', '10');
        fetch('ajax_my_tickets_list.php?' + params.toString(), { method: 'GET', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                myTicketsBodyEl.innerHTML = data.rows_html || '';
                myTicketsPaginationEl.innerHTML = data.pagination_html || '';
                myTicketsCurrentPage = parseInt(data.page || nextPage, 10) || 1;
                initializeFollowUpCooldownButtons(myTicketsBodyEl);
                if (updateHistory === false) return;
                var url = new URL(window.location.href);
                url.searchParams.set('page', String(myTicketsCurrentPage));
                history.replaceState({}, '', url.toString());
            })
            .catch(function () {});
    }

    function parseFollowUpTimestamp(value) {
        var timestamp = parseInt(value || '', 10);
        return timestamp > 0 ? timestamp * 1000 : 0;
    }

    function parseFollowUpAvailableAt(value) {
        var normalized = String(value || '').trim();
        if (!normalized) return 0;
        var match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!match) return 0;
        return new Date(
            parseInt(match[1], 10),
            parseInt(match[2], 10) - 1,
            parseInt(match[3], 10),
            parseInt(match[4], 10),
            parseInt(match[5], 10),
            parseInt(match[6] || '0', 10)
        ).getTime();
    }

    function formatFollowUpCooldownLabel(availableTimeMs) {
        if (!availableTimeMs || availableTimeMs <= 0) return 'Available in 48 hours';
        var remainingMs = Math.max(availableTimeMs - Date.now(), 0);
        var totalMinutes = Math.ceil(remainingMs / 60000);
        if (totalMinutes <= 1) return 'Available in 1 minute';
        if (totalMinutes < 60) return 'Available in ' + totalMinutes + ' minutes';
        var totalHours = Math.ceil(totalMinutes / 60);
        if (totalHours <= 48) return 'Available in ' + totalHours + ' hour' + (totalHours === 1 ? '' : 's');
        var totalDays = Math.ceil(totalHours / 24);
        return 'Available in ' + totalDays + ' day' + (totalDays === 1 ? '' : 's');
    }

    function clearFollowUpCooldownTimer(buttonEl) {
        if (!buttonEl) return;
        var ticketId = parseInt(buttonEl.getAttribute('data-ticket-id') || '', 10);
        if (ticketId > 0 && followUpCooldownTimers[ticketId]) {
            window.clearTimeout(followUpCooldownTimers[ticketId]);
            delete followUpCooldownTimers[ticketId];
        }
    }

    function restoreFollowUpButtonActive(buttonEl) {
        if (!buttonEl) return;
        clearFollowUpCooldownTimer(buttonEl);
        buttonEl.disabled = false;
        buttonEl.removeAttribute('disabled');
        buttonEl.classList.remove('is-sent', 'follow-up-cooldown');
        buttonEl.removeAttribute('aria-disabled');
        buttonEl.removeAttribute('tabindex');
        buttonEl.removeAttribute('data-available-at');
        buttonEl.removeAttribute('data-available-at-ts');
        buttonEl.removeAttribute('data-cooldown-label');
        buttonEl.textContent = buttonEl.getAttribute('data-default-text') || 'Follow Up';
        var ticketId = parseInt(buttonEl.getAttribute('data-ticket-id') || '', 10);
        if (ticketId > 0) {
            buttonEl.setAttribute('aria-label', 'Follow up ticket #' + ticketId);
        }
    }

    function scheduleFollowUpCooldown(buttonEl) {
        if (!buttonEl || !buttonEl.classList.contains('follow-up-cooldown')) return;
        clearFollowUpCooldownTimer(buttonEl);
        var availableTimeMs = parseFollowUpTimestamp(buttonEl.getAttribute('data-available-at-ts'));
        if (!availableTimeMs) {
            availableTimeMs = parseFollowUpAvailableAt(buttonEl.getAttribute('data-available-at'));
        }
        if (!availableTimeMs) return;
        var delay = availableTimeMs - Date.now();
        if (delay <= 0) {
            restoreFollowUpButtonActive(buttonEl);
            return;
        }
        var ticketId = parseInt(buttonEl.getAttribute('data-ticket-id') || '', 10);
        if (ticketId <= 0) return;
        followUpCooldownTimers[ticketId] = window.setTimeout(function () {
            delete followUpCooldownTimers[ticketId];
            if (!document.body.contains(buttonEl)) return;
            restoreFollowUpButtonActive(buttonEl);
        }, delay + 200);
    }

    function initializeFollowUpCooldownButtons(rootEl) {
        var scope = rootEl && rootEl.querySelectorAll ? rootEl : document;
        var cooldownButtons = scope.querySelectorAll('.follow-up-btn.follow-up-cooldown');
        cooldownButtons.forEach(function (buttonEl) {
            if (!buttonEl.getAttribute('data-default-text')) {
                buttonEl.setAttribute('data-default-text', 'Follow Up');
            }
            var availableTimeMs = parseFollowUpTimestamp(buttonEl.getAttribute('data-available-at-ts'))
                || parseFollowUpAvailableAt(buttonEl.getAttribute('data-available-at'));
            buttonEl.setAttribute('data-cooldown-label', formatFollowUpCooldownLabel(availableTimeMs));
            scheduleFollowUpCooldown(buttonEl);
        });
    }

    function scheduleMyTicketsRefresh() {
        if (document.hidden || myTicketsModalOpen()) return;
        refreshMyTickets(myTicketsCurrentPage, false);
    }

    function closeFollowUpFeedback() {
        if (followUpFeedbackState === 'pending') return;
        if (!followUpFeedbackOverlay) return;
        followUpFeedbackState = '';
        followUpFeedbackOverlay.classList.remove('is-visible');
        followUpFeedbackOverlay.setAttribute('aria-hidden', 'true');
    }

    function showFollowUpFeedback(kind, title, message) {
        if (!followUpFeedbackOverlay || !followUpFeedbackDialog || !followUpFeedbackTitle || !followUpFeedbackText || !followUpFeedbackIcon) {
            return;
        }
        var isError = kind === 'error';
        var isPending = kind === 'pending';
        followUpFeedbackState = kind;
        followUpFeedbackDialog.classList.toggle('is-error', isError);
        followUpFeedbackDialog.classList.toggle('is-pending', isPending);
        if (followUpFeedbackLabel) {
            followUpFeedbackLabel.textContent = isPending ? 'Sending Follow Up' : (isError ? 'Follow Up Error' : 'Ticket Update');
        }
        followUpFeedbackTitle.textContent = title || (isPending ? 'Sending Follow Up' : (isError ? 'Follow Up Failed' : 'Follow Up Sent'));
        followUpFeedbackText.textContent = message || (isPending ? 'Please wait while we notify the assigned team.' : (isError ? 'Unable to send follow up right now.' : 'Follow up sent successfully.'));
        followUpFeedbackIcon.innerHTML = isPending ? '<span class="follow-up-feedback-icon-spinner"></span>' : (isError ? '!' : '&#10003;');
        if (followUpFeedbackClose) {
            followUpFeedbackClose.hidden = isPending;
        }
        if (followUpFeedbackBtn) {
            followUpFeedbackBtn.hidden = isPending;
            followUpFeedbackBtn.disabled = isPending;
            followUpFeedbackBtn.textContent = isPending ? 'Sending...' : 'OK';
        }
        followUpFeedbackOverlay.classList.add('is-visible');
        followUpFeedbackOverlay.setAttribute('aria-hidden', 'false');
        if (followUpFeedbackBtn && !isPending) {
            window.setTimeout(function () {
                try { followUpFeedbackBtn.focus(); } catch (e) {}
            }, 0);
        }
    }

    function setFollowUpButtonSending(buttonEl) {
        if (!buttonEl) return;
        clearFollowUpCooldownTimer(buttonEl);
        if (!buttonEl.getAttribute('data-default-text')) {
            buttonEl.setAttribute('data-default-text', buttonEl.textContent || 'Follow Up');
        }
        buttonEl.disabled = true;
        buttonEl.textContent = 'Sending...';
    }

    function resetFollowUpButton(buttonEl) {
        if (!buttonEl) return;
        buttonEl.disabled = false;
        buttonEl.removeAttribute('aria-disabled');
        buttonEl.removeAttribute('tabindex');
        buttonEl.textContent = buttonEl.getAttribute('data-default-text') || 'Follow Up';
    }

    function setFollowUpButtonCooldown(buttonEl, availableAt, availableAtTs) {
        if (!buttonEl) return;
        buttonEl.disabled = false;
        buttonEl.removeAttribute('disabled');
        buttonEl.classList.add('is-sent', 'follow-up-cooldown');
        buttonEl.setAttribute('aria-disabled', 'true');
        buttonEl.setAttribute('tabindex', '-1');
        buttonEl.textContent = 'Follow Up Sent';
        if (availableAt) {
            buttonEl.setAttribute('data-available-at', availableAt);
        }
        if (availableAtTs) {
            buttonEl.setAttribute('data-available-at-ts', String(availableAtTs));
        }
        buttonEl.setAttribute('data-cooldown-label', formatFollowUpCooldownLabel(parseFollowUpTimestamp(availableAtTs) || parseFollowUpAvailableAt(availableAt)));
        var ticketId = parseInt(buttonEl.getAttribute('data-ticket-id') || '', 10);
        if (ticketId > 0) {
            buttonEl.setAttribute('aria-label', 'Follow up is on cooldown for ticket #' + ticketId);
        }
        scheduleFollowUpCooldown(buttonEl);
    }

    function sendFollowUp(ticketId, buttonEl) {
        if (!ticketId || followUpInFlight[ticketId]) return;
        followUpInFlight[ticketId] = true;
        setFollowUpButtonSending(buttonEl);
        showFollowUpFeedback('pending', 'Sending Follow Up', 'Please wait while we notify the assigned team.');

        var formData = new FormData();
        formData.append('action', 'follow_up');
        formData.append('ticket_id', String(ticketId));
        var csrfToken = getMyTicketsCsrfToken();
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        fetch('my_tickets.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: formData
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        return { ok: false, error: 'Invalid server response.' };
                    }
                });
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    if (data && data.cooldown_active && buttonEl) {
                        setFollowUpButtonCooldown(buttonEl, data.available_at || '', data.available_at_ts || '');
                        showFollowUpFeedback('error', 'Follow Up Cooldown', data.error || 'Follow up can be sent again after 2 days.');
                        return;
                    }
                    showFollowUpFeedback('error', 'Follow Up Failed', (data && data.error) ? data.error : 'Unable to send follow up right now.');
                    return;
                }
                setFollowUpButtonCooldown(buttonEl, data.available_at || '', data.available_at_ts || '');
                showFollowUpFeedback('success', 'Follow Up Sent', data.message || 'Follow up sent successfully.');
                refreshMyTickets(myTicketsCurrentPage, false);
            })
            .catch(function () {
                showFollowUpFeedback('error', 'Follow Up Failed', 'Unable to send follow up right now.');
            })
            .finally(function () {
                delete followUpInFlight[ticketId];
                if (buttonEl && !buttonEl.classList.contains('is-sent')) {
                    resetFollowUpButton(buttonEl);
                }
            });
    }

    document.addEventListener('click', function (e) {
        var followUpBtn = e.target && e.target.closest ? e.target.closest('.follow-up-btn') : null;
        if (followUpBtn) {
            e.preventDefault();
            e.stopPropagation();
            if (followUpBtn.disabled || followUpBtn.classList.contains('follow-up-cooldown') || followUpBtn.getAttribute('aria-disabled') === 'true') {
                return;
            }
            var followUpTicketId = parseInt(followUpBtn.getAttribute('data-ticket-id') || '', 10);
            if (followUpTicketId > 0) {
                sendFollowUp(followUpTicketId, followUpBtn);
            }
            return;
        }

        var row = e.target && e.target.closest ? e.target.closest('.ticket-row') : null;
        if (row && row.getAttribute) {
            var id = row.getAttribute('data-id');
            if (id) TMTicketModal.open(id);
        }
        var pageBtn = e.target && e.target.closest ? e.target.closest('#myTicketsPagination a.page-btn') : null;
        if (pageBtn) {
            e.preventDefault();
            if (pageBtn.classList.contains('disabled')) return;
            var nextPage = parseInt(pageBtn.getAttribute('data-page') || '', 10);
            if (!nextPage || nextPage < 1) return;
            refreshMyTickets(nextPage, true);
        }
    });

    var modal = document.getElementById('ticketModal');
    modal.addEventListener('click', function(e){ if(e.target === modal) TMTicketModal.close(); });
    if (followUpFeedbackBtn) {
        followUpFeedbackBtn.addEventListener('click', closeFollowUpFeedback);
    }
    if (followUpFeedbackClose) {
        followUpFeedbackClose.addEventListener('click', closeFollowUpFeedback);
    }
    if (followUpFeedbackOverlay) {
        followUpFeedbackOverlay.addEventListener('click', function (e) {
            if (e.target === followUpFeedbackOverlay) {
                closeFollowUpFeedback();
            }
        });
    }
    initializeFollowUpCooldownButtons(document);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && followUpFeedbackOverlay && followUpFeedbackOverlay.classList.contains('is-visible')) {
            closeFollowUpFeedback();
        }
    });
    var p = new URLSearchParams(window.location.search);
    var tid = p.get('ticket_id') || p.get('id');
    var openChat = p.get('chat') === '1';
    if (tid) {
        if (openChat && window.TMTicketModal && typeof window.TMTicketModal.openConversation === 'function') {
            TMTicketModal.openConversation(tid);
        } else {
            TMTicketModal.open(tid);
        }
    }
    setInterval(scheduleMyTicketsRefresh, myTicketsAutoRefreshMs);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            scheduleMyTicketsRefresh();
        }
    });
    </script>

    
</body>
</html>


