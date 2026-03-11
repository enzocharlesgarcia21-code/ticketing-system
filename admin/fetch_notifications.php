<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get unread count
$count_result = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread_count = 0;
if (!$count_result) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL Error', 'details' => $conn->error]);
    exit;
}
$unread_count = $count_result->fetch_assoc()['count'];

// Get latest 10 notifications
$query = "SELECT n.*, t.priority, TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago 
          FROM notifications n 
          LEFT JOIN employee_tickets t ON n.ticket_id = t.id 
          WHERE n.user_id = $user_id 
          ORDER BY n.created_at DESC LIMIT 10";
$result = $conn->query($query);

$notifications = [];
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL Error', 'details' => $conn->error]);
    exit;
}

while ($row = $result->fetch_assoc()) {
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
    'unread_count' => $unread_count,
    'notifications' => $notifications
]);
?>
