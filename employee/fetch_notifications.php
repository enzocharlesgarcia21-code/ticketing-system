<?php
require_once '../config/database.php';
require_once '../includes/notification_service.php';
require_once '../includes/ticket_assignment.php';
header('Content-Type: application/json');

notif_ensure_action_type_column($conn);
ticket_apply_sla_priority($conn);

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['unread_count' => 0, 'notifications' => []]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// Unread count
$count_result = $conn->query("
    SELECT COUNT(*) as count
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = $user_id
      AND n.is_read = 0
      AND n.type <> 'chat_message'
      AND (n.type <> 'note_added' OR t.user_id = n.user_id)
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
            AND (n.type <> 'note_added' OR t.user_id = n.user_id)
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
    'unread_count' => $unread_count,
    'notifications' => $notifications
]);
?>
