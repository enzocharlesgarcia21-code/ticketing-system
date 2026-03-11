<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

csrf_validate();

if (!isset($_POST['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Ticket ID']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];
$current_user_id = $_SESSION['user_id'];

// Check if user has access to this ticket (Admin or Assigned User)
// For Admin portal, we assume admins can see all tickets.
// If strict checking is needed:
if ($_SESSION['role'] !== 'admin') {
     // Verify if the ticket belongs to the user
     $check = $conn->prepare("SELECT id FROM employee_tickets WHERE id = ? AND user_id = ?");
     $check->bind_param("ii", $ticket_id, $current_user_id);
     $check->execute();
     if ($check->get_result()->num_rows === 0) {
         http_response_code(403);
         echo json_encode(['error' => 'Access Denied']);
         exit;
     }
}

// Fetch messages
$stmt = $conn->prepare("
    SELECT tm.id, tm.ticket_id, tm.sender_id, tm.message, tm.created_at, u.name as sender_name, u.role as sender_role
    FROM ticket_messages tm
    JOIN users u ON tm.sender_id = u.id
    WHERE tm.ticket_id = ?
    ORDER BY tm.created_at ASC
");

$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$result = $stmt->get_result();

$messages = [];
while ($row = $result->fetch_assoc()) {
    $messages[] = [
        'id' => $row['id'],
        'sender_id' => $row['sender_id'],
        'sender_name' => $row['sender_name'],
        'message' => $row['message'], // XSS protection should be handled on frontend or here. JSON is safe, but rendering needs care.
        'created_at' => date('H:i', strtotime($row['created_at'])),
        'is_me' => ($row['sender_id'] == $current_user_id)
    ];
}

echo json_encode($messages);
?>
