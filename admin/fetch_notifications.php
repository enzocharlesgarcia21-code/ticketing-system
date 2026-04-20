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

// Get unread count
$count_result = $conn->query("
    SELECT COUNT(*) as count
    FROM notifications n
    LEFT JOIN employee_tickets t ON n.ticket_id = t.id
    WHERE n.user_id = $user_id
      AND n.is_read = 0
      AND n.type <> 'chat_message'
      AND n.type <> 'hr_chat_pending'
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
            AND n.type <> 'hr_chat_pending'
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
