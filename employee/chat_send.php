<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
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

if (!isset($_POST['ticket_id']) || !isset($_POST['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Parameters']);
    exit;
}

$ticket_id = (int)$_POST['ticket_id'];
$message = trim($_POST['message']);
$sender_id = $_SESSION['user_id'];

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

// Access Control
if ($_SESSION['role'] !== 'admin') {
     // Verify if the ticket belongs to the user's department
     $department = $_SESSION['department'];
     $check = $conn->prepare("SELECT id FROM employee_tickets WHERE id = ? AND assigned_department = ?");
     $check->bind_param("is", $ticket_id, $department);
     $check->execute();
     if ($check->get_result()->num_rows === 0) {
         http_response_code(403);
         echo json_encode(['error' => 'Access Denied']);
         exit;
     }
}

// Insert Message
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $ticket_id, $sender_id, $message);

if ($stmt->execute()) {
    $ticket = null;
    $ticketStmt = $conn->prepare("
        SELECT t.user_id, t.subject, t.priority, t.assigned_department, u.name, u.email
        FROM employee_tickets t
        JOIN users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    if ($ticketStmt) {
        $ticketStmt->bind_param("i", $ticket_id);
        $ticketStmt->execute();
        $ticketRes = $ticketStmt->get_result();
        $ticket = $ticketRes ? $ticketRes->fetch_assoc() : null;
        $ticketStmt->close();
    }

    if ($ticket) {
        $adminEmails = [];
        $dept = (string) ($ticket['assigned_department'] ?? '');
        if ($dept !== '') {
            $adminStmt = $conn->prepare("SELECT email FROM users WHERE role = 'admin' AND email <> '' AND (department = ? OR department IS NULL OR department = '')");
            if ($adminStmt) {
                $adminStmt->bind_param("s", $dept);
                $adminStmt->execute();
                $adminRes = $adminStmt->get_result();
                if ($adminRes) {
                    while ($a = $adminRes->fetch_assoc()) {
                        $adminEmails[] = $a['email'];
                    }
                }
                $adminStmt->close();
            }
        }
        if (count($adminEmails) === 0) {
            $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
            if ($admins) {
                while ($a = $admins->fetch_assoc()) {
                    $adminEmails[] = $a['email'];
                }
            }
        }

        $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
        $subjectLine = "New Ticket Message (#$ticketNumber)";
        $ticketSubjectSafe = htmlspecialchars((string) $ticket['subject']);
        $prioritySafe = htmlspecialchars((string) $ticket['priority']);
        $senderNameSafe = htmlspecialchars((string) ($_SESSION['name'] ?? 'Employee'));
        $requesterSafe = htmlspecialchars((string) $ticket['name']);
        $requesterEmail = (string) ($ticket['email'] ?? '');
        $ticketOwnerId = (int) ($ticket['user_id'] ?? 0);
        $messagePreview = strlen($message) > 200 ? (substr($message, 0, 200) . '...') : $message;
        $messagePreviewSafe = htmlspecialchars($messagePreview);

        $bodyHtml = "
            <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                <h2 style='margin:0 0 12px 0'>New message received</h2>
                <p style='margin:0 0 16px 0'>
                    Ticket ID: <strong>#$ticketNumber</strong><br>
                    Subject: <strong>$ticketSubjectSafe</strong><br>
                    Priority: <strong>$prioritySafe</strong><br>
                    Requested by: <strong>$requesterSafe</strong><br>
                    From: <strong>$senderNameSafe</strong>
                </p>
                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:0 0 16px 0'>
                    $messagePreviewSafe
                </div>
                <p style='margin:0'>Login to the system to view and reply.</p>
            </div>
        ";
        $bodyText = "New message received\n\n"
            . "Ticket ID: #$ticketNumber\n"
            . "Subject: " . (string) $ticket['subject'] . "\n"
            . "Priority: " . (string) $ticket['priority'] . "\n"
            . "Requested by: " . (string) $ticket['name'] . "\n"
            . "From: " . (string) ($_SESSION['name'] ?? 'Employee') . "\n\n"
            . $messagePreview . "\n\n"
            . "Login to the system to view and reply.\n";

        $to = [];
        if ($sender_id === $ticketOwnerId) {
            $to = $adminEmails;
        } else {
            $to = array_merge([$requesterEmail], $adminEmails);
        }

        $ok = sendSmtpEmail($to, $subjectLine, $bodyHtml, $bodyText);
        if (!$ok) {
            error_log('Chat email failed | ticketId=' . (string) $ticket_id);
        }
    }

    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>
