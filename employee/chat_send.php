<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

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

ticket_ensure_chat_tables($conn);

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

function normalize_domain(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === '') return '';
    if ($v[0] !== '@') $v = '@' . $v;
    return $v;
}

$ticket = null;
$ticketStmt = $conn->prepare("SELECT id, user_id, assigned_user_id, subject, priority FROM employee_tickets WHERE id = ? LIMIT 1");
if ($ticketStmt) {
    $ticketStmt->bind_param("i", $ticket_id);
    $ticketStmt->execute();
    $ticketRes = $ticketStmt->get_result();
    $ticket = $ticketRes ? $ticketRes->fetch_assoc() : null;
    $ticketStmt->close();
}
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
    exit;
}

$requesterId = (int) ($ticket['user_id'] ?? 0);
$assigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
if ($sender_id !== $requesterId && ($assigneeId <= 0 || $sender_id !== $assigneeId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

// Insert Message
$stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_id, message, is_read) VALUES (?, ?, ?, 0)");
$stmt->bind_param("iis", $ticket_id, $sender_id, $message);

if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => true]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }

    $recipientId = 0;
    if ($sender_id === $requesterId) {
        $recipientId = $assigneeId;
    } else {
        $recipientId = $requesterId;
    }

    if ($recipientId > 0) {
        $metaStmt = $conn->prepare("
            SELECT t.subject, t.priority,
                   u1.name AS requester_name, u1.email AS requester_email,
                   u2.name AS recipient_name, u2.email AS recipient_email
            FROM employee_tickets t
            JOIN users u1 ON t.user_id = u1.id
            JOIN users u2 ON u2.id = ?
            WHERE t.id = ?
            LIMIT 1
        ");
        if ($metaStmt) {
            $metaStmt->bind_param("ii", $recipientId, $ticket_id);
            $metaStmt->execute();
            $metaRes = $metaStmt->get_result();
            $meta = $metaRes ? $metaRes->fetch_assoc() : null;
            $metaStmt->close();

            if ($meta && !empty($meta['recipient_email'])) {
                $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
                $subjectLine = "New Ticket Message (#$ticketNumber)";
                $ticketSubjectSafe = htmlspecialchars((string) ($meta['subject'] ?? ''));
                $prioritySafe = htmlspecialchars((string) ($meta['priority'] ?? ''));
                $senderNameSafe = htmlspecialchars((string) ($_SESSION['name'] ?? ($_SESSION['email'] ?? 'User')));
                $requesterSafe = htmlspecialchars((string) ($meta['requester_name'] ?? ''));
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
                    . "Subject: " . (string) ($meta['subject'] ?? '') . "\n"
                    . "Priority: " . (string) ($meta['priority'] ?? '') . "\n"
                    . "Requested by: " . (string) ($meta['requester_name'] ?? '') . "\n"
                    . "From: " . (string) ($_SESSION['name'] ?? ($_SESSION['email'] ?? 'User')) . "\n\n"
                    . $messagePreview . "\n\n"
                    . "Login to the system to view and reply.\n";

                $ok = sendSmtpEmail([(string) $meta['recipient_email']], $subjectLine, $bodyHtml, $bodyText);
                if (!$ok) {
                    error_log('Chat email failed | ticketId=' . (string) $ticket_id);
                }
            }
        }
    }
    exit;
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database Error']);
}
?>
