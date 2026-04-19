<?php
require_once '../config/database.php';
require_once '../includes/notification_service.php';
require_once '../includes/ticket_assignment.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

notif_ensure_action_type_column($conn);
ticket_apply_sla_priority($conn);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

function admin_send_due_hr_chat_reminders(mysqli $conn, int $userId): void
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
        GROUP BY t.id, t.subject
        HAVING last_unread_message_at IS NOT NULL
           AND TIMESTAMPDIFF(SECOND, last_unread_message_at, NOW()) >= ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("ii", $userId, $thresholdSeconds);
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

admin_send_due_hr_chat_reminders($conn, (int) $user_id);

// Get unread count
$count_result = $conn->query("
    SELECT COUNT(*) as count
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = $user_id
      AND n.is_read = 0
      AND n.type <> 'chat_message'
      AND (n.type <> 'note_added' OR t.user_id = n.user_id)
");
$unread_count = 0;
if (!$count_result) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL Error', 'details' => $conn->error]);
    exit;
}
$unread_count = (int) ($count_result->fetch_assoc()['count'] ?? 0);

// Get latest 10 notifications
$query = "SELECT n.*, t.priority, TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago 
          FROM notifications n
          LEFT JOIN employee_tickets t ON n.ticket_id = t.id
          WHERE n.user_id = $user_id
            AND n.type <> 'chat_message'
            AND (n.type <> 'note_added' OR t.user_id = n.user_id)
          ORDER BY n.created_at DESC LIMIT 10";
$result = $conn->query($query);

$notifications = [];
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL Error', 'details' => $conn->error]);
    exit;
}

while ($row = $result->fetch_assoc()) {
    $row['message'] = notif_display_message((string) ($row['type'] ?? ''), (string) ($row['message'] ?? ''), (int) ($row['ticket_id'] ?? 0));
    $row['title'] = (string) ($row['title'] ?? '');
    // Add time_ago for frontend convenience
    $time_ago = $row['seconds_ago'];
    if ($time_ago < 60) {
        $row['time_ago'] = 'Just now';
    } elseif ($time_ago < 3600) {
        $row['time_ago'] = floor($time_ago / 60) . 'm ago';
    } elseif ($time_ago < 86400) {
        $row['time_ago'] = floor($time_ago / 3600) . 'h ago';
    } else {
        $row['time_ago'] = floor($time_ago / 86400) . 'd ago';
    }
    
    // Include raw timestamp for client-side updates
    $row['created_at'] = $row['created_at'];
    $notifications[] = $row;
}

echo json_encode([
    'unread_count' => (int) $unread_count,
    'notifications' => $notifications
]);
?>
