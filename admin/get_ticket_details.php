<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

$id = (int)$_GET['id'];

function parseLegacyRequesterInfo($text) {
    if (!is_string($text) || $text === '') {
        return [null, null, $text];
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $text);

    $name = null;
    $email = null;
    $desc = $normalized;

    if (preg_match('/REQUESTER NAME:\s*(.+)$/im', $normalized, $m)) {
        $name = trim($m[1]);
    }
    if (preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $normalized, $m)) {
        $email = trim($m[1]);
    }
    if (preg_match('/DESCRIPTION:\s*(.*)$/is', $normalized, $m)) {
        $desc = trim($m[1]);
    } else {
        $desc = preg_replace('/^REQUESTER NAME:.*(\n)?/im', '', $normalized);
        $desc = preg_replace('/^REQUESTER EMAIL:.*(\n)?/im', '', $desc);
        $desc = preg_replace('/^DESCRIPTION:\s*/im', '', $desc);
        $desc = trim($desc);
    }

    return [$name, $email, $desc];
}

// 🟢 START TIMER LOGIC (Only for Admin)
// If admin views the ticket and started_at is NULL, set it to NOW()
$checkStmt = $conn->prepare("SELECT started_at FROM employee_tickets WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    $ticketData = $checkResult->fetch_assoc();
    if (is_null($ticketData['started_at'])) {
        $updateStart = $conn->prepare("UPDATE employee_tickets SET started_at = NOW() WHERE id = ?");
        $updateStart->bind_param("i", $id);
        $updateStart->execute();
    }
}

$stmt = $conn->prepare("
    SELECT 
        t.*, 
        u.name as created_by_name, 
        u.email as created_by_email, 
        u.company as user_company,
        u.department as user_department
    FROM employee_tickets t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Fallbacks for display
    $row['company'] = !empty($row['company']) ? $row['company'] : $row['user_company'];
    $row['department'] = !empty($row['department']) ? $row['department'] : ($row['user_department'] ?? '');
    if (empty($row['department'])) {
        $row['department'] = 'Unknown';
    }

    $requester_name = trim((string)($row['requester_name'] ?? ''));
    $requester_email = trim((string)($row['requester_email'] ?? ''));

    $clean_desc = $row['description'] ?? '';
    if ($requester_name === '' && $requester_email === '') {
        [$parsed_name, $parsed_email, $parsed_desc] = parseLegacyRequesterInfo($clean_desc);
        if (!empty($parsed_name)) $requester_name = $parsed_name;
        if (!empty($parsed_email)) $requester_email = $parsed_email;
        $clean_desc = $parsed_desc;
    }

    if ($requester_name !== '') $row['created_by_name'] = $requester_name;
    if ($requester_email !== '') $row['created_by_email'] = $requester_email;
    $row['description'] = $clean_desc;

    $attachments = [];
    if (!empty($row['attachment'])) {
        $attachments[] = ['stored_name' => (string) $row['attachment'], 'original_name' => (string) $row['attachment']];
    }
    $attStmt = $conn->prepare("SELECT stored_name, original_name FROM ticket_attachments WHERE ticket_id = ? ORDER BY id ASC");
    if ($attStmt) {
        $attStmt->bind_param("i", $id);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        $attachments = [];
        while ($attRes && ($a = $attRes->fetch_assoc())) {
            if (!empty($a['stored_name'])) {
                $attachments[] = [
                    'stored_name' => (string) $a['stored_name'],
                    'original_name' => (string) ($a['original_name'] ?? $a['stored_name'])
                ];
            }
        }
        $attStmt->close();
    }
    $row['attachments'] = $attachments;
    
    // Calculate Duration
    $duration = "Not Started";
    if (!is_null($row['started_at'])) {
        if (is_null($row['resolved_at'])) {
            $duration = "In Progress";
        } else {
            $start = new DateTime($row['started_at']);
            $end = new DateTime($row['resolved_at']);
            $diff = $start->diff($end);
            
            $parts = [];
            if ($diff->d > 0) $parts[] = $diff->d . ($diff->d === 1 ? " day" : " days");
            if ($diff->h > 0) $parts[] = $diff->h . ($diff->h === 1 ? " hr" : " hrs");
            if ($diff->i > 0) $parts[] = $diff->i . ($diff->i === 1 ? " min" : " mins");
            
            $duration = empty($parts) ? "0 min" : implode(" ", $parts);
        }
    }
    $row['duration'] = $duration;

    echo json_encode($row);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
}
?>
