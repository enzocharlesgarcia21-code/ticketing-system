<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

function can_follow_up_ticket_status(string $status): bool
{
    $status = strtoupper(trim($status));
    return $status === 'OPEN' || $status === 'IN PROGRESS';
}

function follow_up_ensure_cooldown_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM employee_tickets");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) {
                $existing[(string) $row['Field']] = true;
            }
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

function follow_up_notification_event_state(mysqli $conn, int $ticketId): array
{
    $stmt = $conn->prepare("
        SELECT created_at
        FROM notifications
        WHERE ticket_id = ?
          AND type = 'follow_up'
        ORDER BY created_at ASC
    ");
    if (!$stmt) {
        return [
            'last_sent_at' => null,
            'follow_up_send_count' => 0,
        ];
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();

    $eventCount = 0;
    $lastEventTimestamp = null;
    $lastSentAt = null;
    while ($res && ($row = $res->fetch_assoc())) {
        $createdAt = trim((string) ($row['created_at'] ?? ''));
        if ($createdAt === '') {
            continue;
        }
        $createdTimestamp = strtotime($createdAt);
        if ($createdTimestamp === false) {
            continue;
        }
        if ($lastEventTimestamp === null || ($createdTimestamp - $lastEventTimestamp) > 900) {
            $eventCount++;
        }
        $lastEventTimestamp = $createdTimestamp;
        $lastSentAt = $createdAt;
    }
    $stmt->close();

    return [
        'last_sent_at' => $lastSentAt,
        'follow_up_send_count' => $eventCount,
    ];
}

function follow_up_store_ticket_cooldown_state(mysqli $conn, int $ticketId, string $lastSentAt, int $stage): void
{
    follow_up_ensure_cooldown_columns($conn);
    $lastSentAt = trim($lastSentAt);
    $stage = max(0, min(3, (int) $stage));
    $stmt = $conn->prepare("
        UPDATE employee_tickets
        SET follow_up_last_sent_at = ?,
            follow_up_cooldown_stage = ?,
            follow_up_send_count = ?
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("siii", $lastSentAt, $stage, $stage, $ticketId);
    $stmt->execute();
    $stmt->close();
}

function follow_up_sync_user_ticket_cooldowns(mysqli $conn, int $userId): void
{
    follow_up_ensure_cooldown_columns($conn);

    $stmt = $conn->prepare("
        SELECT DISTINCT
            t.id,
            t.follow_up_last_sent_at,
            t.follow_up_cooldown_stage
        FROM employee_tickets t
        INNER JOIN notifications n
            ON n.ticket_id = t.id
           AND n.type = 'follow_up'
        WHERE t.user_id = ?
        ORDER BY t.id DESC
        LIMIT 200
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $ticketId = (int) ($row['id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        $storedLastSentAt = trim((string) ($row['follow_up_last_sent_at'] ?? ''));
        $storedStage = max(0, min(3, (int) ($row['follow_up_cooldown_stage'] ?? 0)));
        $derived = follow_up_notification_event_state($conn, $ticketId);
        $derivedLastSentAt = trim((string) ($derived['last_sent_at'] ?? ''));
        $derivedStage = max(0, min(3, (int) ($derived['follow_up_send_count'] ?? 0)));
        if ($storedLastSentAt !== $derivedLastSentAt || $storedStage !== $derivedStage) {
            follow_up_store_ticket_cooldown_state($conn, $ticketId, $derivedLastSentAt, $derivedStage);
        }
    }
    $stmt->close();
}

function follow_up_cooldown_label_from_remaining_seconds(int $remainingSeconds): string
{
    $remainingSeconds = max(0, $remainingSeconds);
    $hours = (int) floor($remainingSeconds / 3600);
    $minutes = (int) floor(($remainingSeconds % 3600) / 60);
    $seconds = (int) ($remainingSeconds % 60);
    return sprintf('Available in %02d:%02d:%02d', $hours, $minutes, $seconds);
}

function follow_up_button_html(array $row): string
{
    if (!can_follow_up_ticket_status((string) ($row['status'] ?? ''))) {
        return '';
    }

    $ticketId = (int) ($row['id'] ?? 0);
    $followUpInCooldown = !empty($row['follow_up_in_cooldown']);
    $followUpAvailableAt = trim((string) ($row['follow_up_available_at'] ?? ''));
    $class = 'follow-up-btn' . ($followUpInCooldown ? ' is-sent follow-up-cooldown' : '');
    $label = $followUpInCooldown ? 'Follow Up Sent' : 'Follow Up';
    $aria = $followUpInCooldown ? 'Follow up is on cooldown for ticket #' . $ticketId : 'Follow up ticket #' . $ticketId;
    $attrs = $followUpInCooldown ? ' aria-disabled="true" tabindex="-1"' : '';
    $availableTs = 0;
    $remainingSeconds = 0;
    if ($followUpInCooldown && $followUpAvailableAt !== '') {
        $timestamp = strtotime($followUpAvailableAt);
        if ($timestamp !== false) {
            $availableTs = (int) $timestamp;
            $remainingSeconds = max(0, $availableTs - time());
        }
    }
    if ($followUpInCooldown && $followUpAvailableAt !== '') {
        $attrs .= ' data-available-at="' . h($followUpAvailableAt) . '"';
    }
    if ($followUpInCooldown && $availableTs > 0) {
        $attrs .= ' data-available-at-ts="' . $availableTs . '"';
    }
    if ($followUpInCooldown) {
        $attrs .= ' data-remaining-seconds="' . $remainingSeconds . '"';
        $attrs .= ' data-cooldown-label="' . h(follow_up_cooldown_label_from_remaining_seconds($remainingSeconds)) . '"';
    }

    return '<button type="button" class="' . $class . '" data-ticket-id="' . $ticketId . '" aria-label="' . h($aria) . '"' . $attrs . '>' . $label . '</button>';
}

ticket_apply_sla_priority($conn);
follow_up_ensure_cooldown_columns($conn);

$user_id = (int) ($_SESSION['user_id'] ?? 0);
follow_up_sync_user_ticket_cooldowns($conn, $user_id);
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 10);

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM employee_tickets
    WHERE user_id = ?
");
if (!$countStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$countStmt->bind_param("i", $user_id);
$countStmt->execute();
$countResult = $countStmt->get_result();
$countRow = $countResult ? $countResult->fetch_assoc() : null;
$countStmt->close();

$total = (int) ($countRow['total'] ?? 0);
$totalPages = (int) ceil($total / $limit);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT
        t.*,
        t.follow_up_last_sent_at AS last_follow_up_sent_at,
        t.follow_up_cooldown_stage AS follow_up_stage,
        CASE
            WHEN t.follow_up_cooldown_stage <= 0 THEN DATE_ADD(t.created_at, INTERVAL 24 HOUR)
            WHEN t.follow_up_cooldown_stage = 1 THEN DATE_ADD(t.follow_up_last_sent_at, INTERVAL 12 HOUR)
            WHEN t.follow_up_cooldown_stage >= 2 THEN DATE_ADD(t.follow_up_last_sent_at, INTERVAL 6 HOUR)
            ELSE NULL
        END AS follow_up_available_at,
        CASE
            WHEN (
                CASE
                    WHEN t.follow_up_cooldown_stage <= 0 THEN DATE_ADD(t.created_at, INTERVAL 24 HOUR)
                    WHEN t.follow_up_cooldown_stage = 1 THEN DATE_ADD(t.follow_up_last_sent_at, INTERVAL 12 HOUR)
                    WHEN t.follow_up_cooldown_stage >= 2 THEN DATE_ADD(t.follow_up_last_sent_at, INTERVAL 6 HOUR)
                    ELSE NULL
                END
            ) > NOW()
            THEN 1
            ELSE 0
        END AS follow_up_in_cooldown
    FROM employee_tickets t
    WHERE t.user_id = ?
    ORDER BY t.created_at DESC
    LIMIT ?, ?
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$stmt->bind_param("iii", $user_id, $offset, $limit);
$stmt->execute();
$result = $stmt->get_result();

$rowsHtml = '';
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $rowsHtml .= '<tr class="ticket-row" data-id="' . (int) $row['id'] . '" style="cursor:pointer;">';
        $rowsHtml .= '<td data-label="ID">#' . (int) $row['id'] . '</td>';
        $rowsHtml .= '<td data-label="Category" class="subject-cell"><strong>' . h((string) ($row['category'] ?? '')) . '</strong></td>';
        $rowsHtml .= '<td data-label="Status"><span class="status-pill status-' . strtolower(str_replace(' ', '-', (string) ($row['status'] ?? ''))) . '">' . h((string) ($row['status'] ?? '')) . '</span></td>';
        $rowsHtml .= '<td data-label="Passed To">' . h(submitted_ticket_target_label($row)) . '</td>';
        $rowsHtml .= '<td data-label="Date">' . h(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now')))) . '</td>';
        $rowsHtml .= '<td data-label="Action" class="follow-up-cell">';
        $rowsHtml .= follow_up_button_html($row);
        $rowsHtml .= '</td>';
        $rowsHtml .= '</tr>';
    }
} else {
    $rowsHtml = '<tr><td colspan="6" style="text-align: center; color: #94a3b8; padding: 40px;"><div class="empty-state"><i class="fas fa-ticket-alt" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i><p>No tickets submitted yet.</p></div></td></tr>';
}
$stmt->close();

$showingFrom = $total > 0 ? ($offset + 1) : 0;
$showingTo = min($offset + $limit, $total);

$paginationHtml = '';
if ($total > 0) {
    $paginationHtml .= '<div class="pagination-glass">';
    $paginationHtml .= '<div class="pagination-summary">Showing ' . number_format($showingFrom) . ' - ' . number_format($showingTo) . ' of ' . number_format($total) . ' tickets</div>';
    if ($totalPages > 1) {
        $paginationHtml .= '<a href="#" data-page="' . max(1, $page - 1) . '" class="page-btn prev' . ($page <= 1 ? ' disabled' : '') . '">&lsaquo; Previous</a>';
        $paginationHtml .= '<div class="page-numbers">';

        $window = 2;
        $startPage = max(1, $page - $window);
        $endPage = min($totalPages, $page + $window);

        if ($startPage > 1) {
            $paginationHtml .= '<a href="#" data-page="1" class="page-btn' . ($page === 1 ? ' active' : '') . '">1</a>';
            if ($startPage > 2) {
                $paginationHtml .= '<span class="page-ellipsis">...</span>';
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $paginationHtml .= '<a href="#" data-page="' . $i . '" class="page-btn' . ($i === $page ? ' active' : '') . '">' . $i . '</a>';
        }

        if ($endPage < $totalPages) {
            if ($endPage < ($totalPages - 1)) {
                $paginationHtml .= '<span class="page-ellipsis">...</span>';
            }
            $paginationHtml .= '<a href="#" data-page="' . $totalPages . '" class="page-btn' . ($page === $totalPages ? ' active' : '') . '">' . $totalPages . '</a>';
        }

        $paginationHtml .= '</div>';
        $paginationHtml .= '<a href="#" data-page="' . min($totalPages, $page + 1) . '" class="page-btn next' . ($page >= $totalPages ? ' disabled' : '') . '">Next &rsaquo;</a>';
    }
    $paginationHtml .= '</div>';
}

echo json_encode([
    'ok' => true,
    'rows_html' => $rowsHtml,
    'pagination_html' => $paginationHtml,
    'page' => $page,
    'total_pages' => $totalPages,
]);
