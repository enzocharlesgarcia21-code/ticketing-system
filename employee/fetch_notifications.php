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
$requesterNotificationAccessSql = "(n.type <> 'note_added' OR t.user_id = n.user_id OR LOWER(TRIM(COALESCE(t.requester_email, ''))) = '$user_email_sql')";

function employee_send_due_hr_chat_reminders(mysqli $conn, int $userId): void
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    $thresholdSeconds = 8 * 3600;
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.subject,
            MAX(CASE
                WHEN tm.sender_id <> ? AND tm.is_read = 0 THEN tm.created_at
                ELSE NULL
            END) AS last_unread_message_at
        FROM employee_tickets t
        LEFT JOIN ticket_messages tm ON tm.ticket_id = t.id
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
            SELECT id
            FROM notifications
            WHERE user_id = ?
              AND ticket_id = ?
              AND type = 'hr_chat_pending'
              AND is_read = 0
            LIMIT 1
        ");
        $hasUnreadReminder = false;
        if ($existsStmt) {
            $existsStmt->bind_param("ii", $userId, $ticketId);
            $existsStmt->execute();
            $existsRes = $existsStmt->get_result();
            $hasUnreadReminder = (bool) ($existsRes && $existsRes->fetch_assoc());
            $existsStmt->close();
        }
        if ($hasUnreadReminder) {
            continue;
        }

        $ticketNumber = notif_ticket_number($ticketId);
        $subject = trim((string) ($row['subject'] ?? ''));
        $message = 'You have a pending chat reply on ticket #' . $ticketNumber . '. Please check the conversation.';
        if ($subject !== '') {
            $message = 'You have a pending chat reply on ticket #' . $ticketNumber . ' (' . $subject . ').';
        }

        notif_insert_system($conn, $userId, $ticketId, $message, 'hr_chat_pending', 300, 'update', 'Pending Chat');
        $verifyStmt = $conn->prepare("
            SELECT id
            FROM notifications
            WHERE user_id = ?
              AND ticket_id = ?
              AND type = 'hr_chat_pending'
              AND is_read = 0
            LIMIT 1
        ");
        if ($verifyStmt) {
            $verifyStmt->bind_param("ii", $userId, $ticketId);
            $verifyStmt->execute();
            $verifyRes = $verifyStmt->get_result();
            $hasReminderNow = (bool) ($verifyRes && $verifyRes->fetch_assoc());
            $verifyStmt->close();
            if ($hasReminderNow) {
                notif_send_pending_chat_email($conn, $userId, $ticketId, $subject);
            }
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

employee_send_due_hr_chat_reminders($conn, $user_id);

// Unread count
$count_result = $conn->query("
    SELECT COUNT(*) as count
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = $user_id
      AND n.is_read = 0
      AND n.type <> 'chat_message'
      AND $requesterNotificationAccessSql
");
if (!$count_result) {
    http_response_code(500);
    echo json_encode(['unread_count' => 0, 'notifications' => [], 'error' => 'SQL Error']);
    exit;
}
$unread_count = (int) ($count_result->fetch_assoc()['count'] ?? 0);

// Latest 10 notifications with seconds_ago for lightweight time-ago formatting
$query = "SELECT n.id, n.ticket_id, n.title, n.message, n.type, n.is_read, n.created_at,
                 n.action_type,
                 t.priority,
                 TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
          FROM notifications n
          LEFT JOIN employee_tickets t ON n.ticket_id = t.id
          WHERE n.user_id = $user_id
            AND n.type <> 'chat_message'
            AND $requesterNotificationAccessSql
          ORDER BY n.created_at DESC
          LIMIT 10";
$result = $conn->query($query);
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
