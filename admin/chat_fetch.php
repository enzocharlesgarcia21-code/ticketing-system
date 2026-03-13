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

$current_user_id = $_SESSION['user_id'];

$col = $conn->query("SHOW COLUMNS FROM ticket_messages LIKE 'is_read'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE ticket_messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
}

if (isset($_POST['action']) && $_POST['action'] === 'conversations') {
    $sql = "
        SELECT
            t.id,
            t.subject,
            MAX(tm.created_at) AS last_message_time,
            SUM(CASE WHEN tm.is_read = 0 AND tm.sender_id <> ? THEN 1 ELSE 0 END) AS unread_count,
            SUBSTRING_INDEX(GROUP_CONCAT(tm.message ORDER BY tm.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_message,
            SUBSTRING_INDEX(GROUP_CONCAT(u.name ORDER BY tm.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_sender_name
        FROM employee_tickets t
        JOIN ticket_messages tm ON t.id = tm.ticket_id
        JOIN users u ON tm.sender_id = u.id
    ";
    $params = [$current_user_id];
    $types = 'i';

    if (($_SESSION['role'] ?? '') !== 'admin') {
        $sql .= " WHERE t.user_id = ? ";
        $params[] = $current_user_id;
        $types .= 'i';
    }

    $sql .= "
        GROUP BY t.id, t.subject
        ORDER BY last_message_time DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to prepare query']);
        exit;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $r['id'],
            'subject' => (string) $r['subject'],
            'last_message_time' => (string) $r['last_message_time'],
            'unread_count' => (int) $r['unread_count'],
            'last_message' => (string) $r['last_message'],
            'last_sender_name' => (string) $r['last_sender_name']
        ];
    }
    echo json_encode($rows);
    exit;
}

if (!isset($_POST['ticket_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Ticket ID']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];

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

$mark = $conn->prepare("UPDATE ticket_messages SET is_read = 1 WHERE ticket_id = ? AND sender_id <> ? AND is_read = 0");
if ($mark) {
    $mark->bind_param("ii", $ticket_id, $current_user_id);
    $mark->execute();
    $mark->close();
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
