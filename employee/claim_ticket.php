<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/notification_service.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

csrf_validate();
ticket_ensure_assignment_columns($conn);
ticket_ensure_activity_table($conn);

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($ticketId <= 0 || $currentUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid ticket.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        t.id,
        t.user_id,
        t.status,
        t.started_at,
        t.assigned_user_id,
        t.assigned_to,
        t.assigned_department,
        t.assigned_group,
        t.assigned_company,
        t.company
    FROM employee_tickets t
    WHERE t.id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load ticket.']);
    exit;
}
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$res = $stmt->get_result();
$ticket = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Ticket not found.']);
    exit;
}

$userContext = ticket_build_user_context($conn, $currentUserId, $_SESSION);
if (!ticket_user_can_manual_claim($ticket, $currentUserId, $userContext)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You cannot claim this ticket.']);
    exit;
}

$claimStmt = $conn->prepare("
    UPDATE employee_tickets
    SET
        assigned_to = ?,
        assigned_user_id = ?,
        status = CASE WHEN status = 'Open' THEN 'In Progress' ELSE status END,
        started_at = CASE WHEN started_at IS NULL THEN NOW() ELSE started_at END,
        updated_at = NOW()
    WHERE id = ?
      AND (assigned_to IS NULL OR assigned_to = 0)
");
if (!$claimStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to claim ticket.']);
    exit;
}
$claimStmt->bind_param("iii", $currentUserId, $currentUserId, $ticketId);
$claimStmt->execute();
$claimed = $claimStmt->affected_rows > 0;
$claimStmt->close();

if (!$claimed) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'This ticket was already claimed by someone else.']);
    exit;
}

$userName = trim((string) ($_SESSION['name'] ?? ''));
if ($userName === '') {
    $nameStmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    if ($nameStmt) {
        $nameStmt->bind_param("i", $currentUserId);
        $nameStmt->execute();
        $nameRes = $nameStmt->get_result();
        $nameRow = $nameRes ? $nameRes->fetch_assoc() : null;
        $nameStmt->close();
        $userName = trim((string) ($nameRow['name'] ?? ''));
    }
}

$activityDescription = $userName !== ''
    ? ('Claimed by ' . $userName)
    : ('Claimed by user #' . $currentUserId);
$activityStmt = $conn->prepare("
    INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at)
    VALUES (?, 'claim_ticket', ?, NOW())
");
if ($activityStmt) {
    $activityStmt->bind_param("is", $ticketId, $activityDescription);
    $activityStmt->execute();
    $activityStmt->close();
}

if ((string) ($ticket['status'] ?? '') === 'Open') {
    $statusActivityStmt = $conn->prepare("
        INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at)
        VALUES (?, 'status_change', 'Status changed to In Progress', NOW())
    ");
    if ($statusActivityStmt) {
        $statusActivityStmt->bind_param("i", $ticketId);
        $statusActivityStmt->execute();
        $statusActivityStmt->close();
    }

    $ticketForNotification = notif_ticket_data($conn, $ticketId);
    $sharedRequesterEmail = trim((string) ($ticketForNotification['requester_email'] ?? ''));
    if ($ticketForNotification && $sharedRequesterEmail !== '') {
        $currentAssignedCompany = ticket_normalize_company((string) ($ticketForNotification['assigned_company'] ?? ($ticket['assigned_company'] ?? '')));
        $currentAssignedGroup = trim((string) ($ticketForNotification['assigned_group'] ?? ($ticketForNotification['assigned_department'] ?? ($ticket['assigned_group'] ?? $ticket['assigned_department'] ?? ''))));
        $attachments = notif_ticket_email_attachments($conn, $ticketId, (string) ($ticketForNotification['attachment'] ?? ''));
        $updateSourceLabel = ticket_activity_actor_label($conn, $currentUserId, $_SESSION);
        $extraLines = [];

        $ticketCategory = trim((string) ($ticketForNotification['category'] ?? ''));
        if ($ticketCategory !== '') {
            $extraLines[] = 'Category: ' . $ticketCategory;
        }

        $ticketDescription = trim((string) ($ticketForNotification['description'] ?? ''));
        if ($ticketDescription !== '') {
            $extraLines[] = "Description:\n" . $ticketDescription;
        }

        $ticketPriority = trim((string) ($ticketForNotification['priority'] ?? ''));
        if ($ticketPriority !== '') {
            $extraLines[] = 'Priority: ' . $ticketPriority;
        }

        $extraLines[] = 'Current status: In Progress';

        notif_send_ticket_status_update(
            $conn,
            $ticketId,
            'Open',
            'In Progress',
            $updateSourceLabel,
            [
                'attachments' => $attachments,
                'assignee_emails' => ticket_assignee_notification_emails($conn, [$currentUserId], $currentAssignedCompany, $currentAssignedGroup, (int) ($ticketForNotification['user_id'] ?? 0)),
                'extra_lines' => $extraLines,
                'skip_system' => true,
            ]
        );
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Ticket claimed successfully.',
    'claimed_by' => $userName,
]);
