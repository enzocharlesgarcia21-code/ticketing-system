<?php
require_once '../config/database.php';
require_once '../includes/notification_service.php';
require_once '../includes/ticket_assignment.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

notif_ensure_action_type_column($conn);
notif_ensure_title_column($conn);
notif_ensure_requester_identity_columns($conn);
ticket_apply_sla_priority($conn);

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['unread_count' => 0, 'notifications' => []]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$user_email = strtolower(trim((string) ($_SESSION['email'] ?? '')));
if ($user_email === '') {
    $user_email = strtolower(trim((string) (notif_user_contact($conn, $user_id)['email'] ?? '')));
    if ($user_email !== '') {
        $_SESSION['email'] = $user_email;
    }
}
$user_email_sql = $conn->real_escape_string($user_email);
// Notifications are always inserted for the correct n.user_id, so the
// n.user_id = $user_id WHERE clause already enforces correct visibility.
// Avoid referencing t.requester_email here — the column may not exist on
// all server versions and would cause the entire query to fail.
$requesterNotificationAccessSql = "1";

function employee_manager_notification_allowed_sql(string $alias = 'n'): string
{
    $a = preg_replace('/[^a-z0-9_]/i', '', $alias);
    if ($a === '') $a = 'n';
    return "(
        COALESCE($a.action_type, '') = 'claim'
        OR COALESCE($a.action_type, '') = 'reassign'
        OR $a.type IN ('ticket_claimed', 'claim_ticket', 'reassigned', 'status_update')
    )";
}

function employee_manager_sales_ticket_sql(string $ticketAlias = 't', string $creatorAlias = 'creator'): string
{
    $t = preg_replace('/[^a-z0-9_]/i', '', $ticketAlias);
    $c = preg_replace('/[^a-z0-9_]/i', '', $creatorAlias);
    if ($t === '') $t = 't';
    if ($c === '') $c = 'creator';
    return "LOWER(TRIM(COALESCE($c.email, ''))) = 'sales_guest@leadsagri.com'
        AND COALESCE($t.description, '') LIKE ?";
}

function employee_is_lapc_sales_manager_view(mysqli $conn, int $userId, array $session): array
{
    $company = trim((string) ($session['company'] ?? ''));
    $department = trim((string) ($session['department'] ?? ''));
    $region = trim((string) ($session['region'] ?? ''));

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

function employee_is_lapc_sales_user(array $session): bool
{
    return function_exists('ticket_normalize_company')
        && ticket_normalize_company((string) ($session['company'] ?? '')) === '@leadsagri.com'
        && strcasecmp((string) ($session['department'] ?? ''), 'Sales') === 0
        && trim((string) ($session['region'] ?? '')) !== '';
}

function employee_sync_manager_sales_notifications(mysqli $conn, int $userId, string $region): void
{
    if ($userId <= 0 || $region === '') {
        return;
    }

    $regionNeedle = '%Region: ' . $region . '%';
    $allowedSql = employee_manager_notification_allowed_sql('n');
    $salesTicketSql = employee_manager_sales_ticket_sql('t', 'creator');
    $sql = "
        SELECT n.id, n.ticket_id, n.title, n.message, n.type, n.action_type, n.created_at
        FROM notifications n
        INNER JOIN employee_tickets t ON t.id = n.ticket_id
        LEFT JOIN users creator ON creator.id = t.user_id
        WHERE n.user_id <> ?
          AND n.type <> 'chat_message'
          AND $allowedSql
          AND $salesTicketSql
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 200
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('is', $userId, $regionNeedle);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    $existsStmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ?
          AND ticket_id = ?
          AND type = ?
          AND COALESCE(action_type, '') = COALESCE(?, '')
          AND COALESCE(title, '') = COALESCE(?, '')
          AND message = ?
          AND created_at = ?
        LIMIT 1
    ");
    $insertStmt = $conn->prepare("
        INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?)
    ");
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
        if ($ticketId <= 0 || $message === '' || $type === '' || $createdAt === '') {
            continue;
        }

        $existsStmt->bind_param('iisssss', $userId, $ticketId, $type, $actionType, $title, $message, $createdAt);
        $existsStmt->execute();
        $existsRes = $existsStmt->get_result();
        if ($existsRes && $existsRes->fetch_assoc()) {
            continue;
        }

        $insertStmt->bind_param('iisssss', $userId, $ticketId, $title, $message, $type, $actionType, $createdAt);
        $insertStmt->execute();
    }

    $existsStmt->close();
    $insertStmt->close();
}

function employee_send_due_hr_chat_reminders(mysqli $conn, int $userId): void
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    $thresholdSeconds = 60 * 60;
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.subject,
            MAX(CASE
                WHEN tm.sender_id <> ? AND tm.is_read = 0 THEN tm.created_at
                ELSE NULL
            END) AS last_unread_message_at
        FROM employee_tickets t
        LEFT JOIN ticket_messages tm ON tm.ticket_id = t.id AND tm.chat_thread_id = COALESCE(t.current_chat_thread_id, 1)
        WHERE t.status IN ('Open', 'In Progress')
          AND (t.user_id = ? OR t.assigned_user_id = ? OR t.assigned_to = ?)
        GROUP BY t.id, t.subject
        HAVING last_unread_message_at IS NOT NULL
            AND TIMESTAMPDIFF(SECOND, last_unread_message_at, NOW()) >= ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("iiiii", $userId, $userId, $userId, $userId, $thresholdSeconds);
    $stmt->execute();
    $res = $stmt->get_result();

    $dueTicketIds = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $ticketId = (int) ($row['id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        $dueTicketIds[$ticketId] = true;

        $existsStmt = $conn->prepare("
            SELECT COUNT(*) AS sent_count, MAX(created_at) AS last_sent_at
            FROM notifications
            WHERE user_id = ?
              AND ticket_id = ?
              AND type = 'hr_chat_pending'
              AND created_at >= CURDATE()
        ");
        $sentToday = 0;
        $lastSentAt = '';
        if ($existsStmt) {
            $existsStmt->bind_param("ii", $userId, $ticketId);
            $existsStmt->execute();
            $existsRes = $existsStmt->get_result();
            $existsRow = $existsRes ? $existsRes->fetch_assoc() : null;
            $sentToday = (int) ($existsRow['sent_count'] ?? 0);
            $lastSentAt = trim((string) ($existsRow['last_sent_at'] ?? ''));
            $existsStmt->close();
        }
        $lastSentTs = $lastSentAt !== '' ? strtotime($lastSentAt) : false;
        $canSendReminder = $sentToday === 0
            || ($sentToday === 1 && $lastSentTs !== false && $lastSentTs <= time() - (30 * 60))
            || ($sentToday === 2 && $lastSentTs !== false && $lastSentTs <= time() - (15 * 60));
        if ($sentToday >= 3 || !$canSendReminder) {
            continue;
        }

        $ticketNumber = notif_ticket_number($ticketId);
        $subject = trim((string) ($row['subject'] ?? ''));
        $message = 'You have a pending chat reply on ticket #' . $ticketNumber . '. Please check the conversation.';
        if ($subject !== '') {
            $message = 'You have a pending chat reply on ticket #' . $ticketNumber . ' (' . $subject . ').';
        }

        if (notif_insert_system($conn, $userId, $ticketId, $message, 'hr_chat_pending', 300, 'update', 'Pending Chat')) {
            notif_send_pending_chat_email($conn, $userId, $ticketId, $subject);
        }
    }
    $stmt->close();

    $reminderRes = $conn->query("
        SELECT id, ticket_id
        FROM notifications
        WHERE user_id = " . $userId . "
          AND type = 'hr_chat_pending'
          AND is_read = 0
    ");
    while ($reminderRes && ($reminderRow = $reminderRes->fetch_assoc())) {
        $ticketId = (int) ($reminderRow['ticket_id'] ?? 0);
        if ($ticketId > 0 && isset($dueTicketIds[$ticketId])) {
            continue;
        }
        $notifId = (int) ($reminderRow['id'] ?? 0);
        if ($notifId <= 0) {
            continue;
        }
        $conn->query("UPDATE notifications SET is_read = 1 WHERE id = " . $notifId);
    }
}

[$isSalesManagerNotificationView, $salesManagerNotificationRegion] = employee_is_lapc_sales_manager_view($conn, $user_id, $_SESSION);
if ($isSalesManagerNotificationView) {
    employee_sync_manager_sales_notifications($conn, $user_id, $salesManagerNotificationRegion);
} else {
    employee_send_due_hr_chat_reminders($conn, $user_id);
}

$managerJoinSql = '';
$managerWhereSql = '';
$managerRegionNeedle = '%Region: ' . $salesManagerNotificationRegion . '%';
$hideManagerSalesNotifications = !$isSalesManagerNotificationView && employee_is_lapc_sales_user($_SESSION);
if ($isSalesManagerNotificationView) {
    $managerJoinSql = "
    INNER JOIN employee_tickets t ON t.id = n.ticket_id
    LEFT JOIN users creator ON creator.id = t.user_id";
    $managerWhereSql = "
      AND " . employee_manager_notification_allowed_sql('n') . "
      AND " . employee_manager_sales_ticket_sql('t', 'creator');
} elseif ($hideManagerSalesNotifications) {
    $managerJoinSql = "
    LEFT JOIN employee_tickets t ON t.id = n.ticket_id
    LEFT JOIN users creator ON creator.id = t.user_id";
    $managerWhereSql = "
      AND NOT (
        " . employee_manager_notification_allowed_sql('n') . "
        AND " . employee_manager_sales_ticket_sql('t', 'creator') . "
      )";
}

// Unread count — simple query without optional columns
$countStmt = $conn->prepare("
    SELECT COUNT(*) as count
    FROM notifications n
    $managerJoinSql
    WHERE n.user_id = ?
      AND n.is_read = 0
      AND n.type <> 'chat_message'
      $managerWhereSql
");
$count_result = null;
if ($countStmt) {
    if ($isSalesManagerNotificationView || $hideManagerSalesNotifications) {
        $countStmt->bind_param('is', $user_id, $managerRegionNeedle);
    } else {
        $countStmt->bind_param('i', $user_id);
    }
    $countStmt->execute();
    $count_result = $countStmt->get_result();
}
if (!$count_result) {
    http_response_code(500);
    echo json_encode(['unread_count' => 0, 'notifications' => [], 'error' => 'SQL Error']);
    exit;
}
$unread_count = (int) ($count_result->fetch_assoc()['count'] ?? 0);
if ($countStmt) {
    $countStmt->close();
}

// Recent notifications stay in time order so a clicked item does not jump away
// after it is marked read.
$query = "SELECT n.id, n.ticket_id, n.title, n.message, n.type, n.is_read, n.created_at,
                 n.action_type,
                 t.priority,
                 TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
          FROM notifications n
          LEFT JOIN employee_tickets t ON n.ticket_id = t.id
          " . (($isSalesManagerNotificationView || $hideManagerSalesNotifications) ? "LEFT JOIN users creator ON creator.id = t.user_id" : "") . "
          WHERE n.user_id = ?
            AND n.type <> 'chat_message'
            $managerWhereSql
          ORDER BY n.created_at DESC
          LIMIT 25";
$queryStmt = $conn->prepare($query);
$result = null;
if ($queryStmt) {
    if ($isSalesManagerNotificationView || $hideManagerSalesNotifications) {
        $queryStmt->bind_param('is', $user_id, $managerRegionNeedle);
    } else {
        $queryStmt->bind_param('i', $user_id);
    }
    $queryStmt->execute();
    $result = $queryStmt->get_result();
}
if (!$result) {
    // Fallback: title/action_type columns may not exist yet on this server
    $query = "SELECT n.id, n.ticket_id, '' AS title, n.message, n.type, n.is_read, n.created_at,
                     '' AS action_type,
                     t.priority,
                     TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
              FROM notifications n
              LEFT JOIN employee_tickets t ON n.ticket_id = t.id
              " . (($isSalesManagerNotificationView || $hideManagerSalesNotifications) ? "LEFT JOIN users creator ON creator.id = t.user_id" : "") . "
              WHERE n.user_id = ?
                AND n.type <> 'chat_message'
                $managerWhereSql
              ORDER BY n.created_at DESC
              LIMIT 25";
    $fallbackStmt = $conn->prepare($query);
    $result = null;
    if ($fallbackStmt) {
        if ($isSalesManagerNotificationView || $hideManagerSalesNotifications) {
            $fallbackStmt->bind_param('is', $user_id, $managerRegionNeedle);
        } else {
            $fallbackStmt->bind_param('i', $user_id);
        }
        $fallbackStmt->execute();
        $result = $fallbackStmt->get_result();
    }
}
if (!$result) {
    http_response_code(500);
    echo json_encode(['unread_count' => $unread_count, 'notifications' => [], 'error' => 'SQL Error']);
    exit;
}

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $seconds = (int) ($row['seconds_ago'] ?? 0);
    if ($seconds < 60) {
        $time_ago = 'Just now';
    } elseif ($seconds < 3600) {
        $time_ago = floor($seconds / 60) . 'm ago';
    } elseif ($seconds < 86400) {
        $time_ago = floor($seconds / 3600) . 'h ago';
    } else {
        $time_ago = floor($seconds / 86400) . 'd ago';
    }

    $notifications[] = [
        'id' => (int) $row['id'],
        'ticket_id' => (int) $row['ticket_id'],
        'title' => (string) ($row['title'] ?? ''),
        'message' => notif_display_message((string) ($row['type'] ?? ''), (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0)),
        'type' => (string) $row['type'],
        'action_type' => (string) ($row['action_type'] ?? ''),
        'priority' => $row['priority'] ?? null,
        'is_read' => (int) $row['is_read'],
        'created_at' => (string) $row['created_at'],
        'time_ago' => $time_ago
    ];
}

echo json_encode([
    'unread_count' => (int) $unread_count,
    'notifications' => $notifications
]);
?>
