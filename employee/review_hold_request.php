<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/notification_service.php';
require_once '../includes/hold_approval.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}
csrf_validate();
ticket_ensure_assignment_columns($conn);
ticket_ensure_activity_table($conn);
hold_approval_ensure_table($conn);

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
$decisionNote = trim((string) ($_POST['decision_note'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($ticketId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid approval request.']);
    exit;
}
if (mb_strlen($decisionNote) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'The decision note must be 1,000 characters or fewer.']);
    exit;
}

$conn->begin_transaction();
try {
    $ticketStmt = $conn->prepare("SELECT t.*, creator.name AS creator_name,
            COALESCE(NULLIF(TRIM(t.requester_email), ''), creator.email) AS creator_email
        FROM employee_tickets t
        LEFT JOIN users creator ON creator.id = t.user_id
        WHERE t.id = ? FOR UPDATE");
    if (!$ticketStmt) throw new RuntimeException('Unable to load ticket.');
    $ticketStmt->bind_param('i', $ticketId);
    $ticketStmt->execute();
    $ticket = $ticketStmt->get_result()->fetch_assoc();
    $ticketStmt->close();
    if (!$ticket) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ticket not found.']);
        exit;
    }
    if (!hold_approval_user_can_review($conn, $userId, $ticket)) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You cannot review hold requests outside your department.']);
        exit;
    }

    $request = hold_approval_pending_request($conn, $ticketId, true);
    if (!$request) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'This hold request has already been reviewed or cancelled.']);
        exit;
    }
    if ((int) ($request['requested_by'] ?? 0) === $userId) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You cannot approve your own hold request.']);
        exit;
    }

    $holdStartedAt = trim((string) ($ticket['hold_started_at'] ?? ''));
    if ($holdStartedAt !== '' || strcasecmp((string) ($ticket['status'] ?? ''), 'In Progress') !== 0) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'The ticket state changed and this hold request can no longer be reviewed.']);
        exit;
    }

    $requestId = (int) ($request['id'] ?? 0);
    $requesterId = (int) ($request['requested_by'] ?? 0);
    $reason = trim((string) ($request['reason'] ?? ''));
    $requestStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $requestUpdate = $conn->prepare("UPDATE ticket_hold_requests
        SET status = ?, decided_by = ?, decision_note = ?, decided_at = NOW()
        WHERE id = ? AND status = 'pending'");
    if (!$requestUpdate) throw new RuntimeException('Unable to update hold request.');
    $requestUpdate->bind_param('sisi', $requestStatus, $userId, $decisionNote, $requestId);
    $requestUpdate->execute();
    $requestChanged = $requestUpdate->affected_rows > 0;
    $requestUpdate->close();
    if (!$requestChanged) throw new RuntimeException('The hold request changed. Please refresh and try again.');

    if ($decision === 'approve') {
        $ticketUpdate = $conn->prepare("UPDATE employee_tickets
            SET hold_started_at = NOW(), hold_reason = ?, hold_by = ?, updated_at = NOW()
            WHERE id = ? AND hold_started_at IS NULL AND status = 'In Progress'");
        if (!$ticketUpdate) throw new RuntimeException('Unable to place ticket on hold.');
        $ticketUpdate->bind_param('sii', $reason, $requesterId, $ticketId);
        $ticketUpdate->execute();
        $ticketChanged = $ticketUpdate->affected_rows > 0;
        $ticketUpdate->close();
        if (!$ticketChanged) throw new RuntimeException('The ticket state changed. Please refresh and try again.');
    }

    $reviewer = ticket_activity_actor_label($conn, $userId, $_SESSION);
    $requesterName = trim((string) ($request['requester_name'] ?? ''));
    if ($requesterName === '') $requesterName = 'the assignee';
    $activityType = $decision === 'approve' ? 'ticket_hold_approved' : 'ticket_hold_rejected';
    $activityDescription = $decision === 'approve'
        ? ('Ticket hold request from ' . $requesterName . ' approved by ' . $reviewer . '. Reason: ' . $reason)
        : ('Ticket hold request from ' . $requesterName . ' rejected by ' . $reviewer . '.' . ($decisionNote !== '' ? ' Note: ' . $decisionNote : ''));
    ticket_record_activity($conn, $ticketId, $activityType, $activityDescription);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Ticket hold review failed | ticket_id=' . $ticketId . ' | ' . $e->getMessage());
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Unable to review this hold request. Please refresh and try again.']);
    exit;
}

$conn->query("UPDATE notifications SET is_read = 1 WHERE ticket_id = " . $ticketId . " AND type = 'hold_approval_requested'");
$ticketNumber = notif_ticket_number($ticketId);
$approved = $decision === 'approve';
$title = $approved ? 'Hold Request Approved' : 'Hold Request Rejected';
$message = $approved
    ? ('Your request to place ticket #' . $ticketNumber . ' on hold was approved. The SLA timer is now paused.')
    : ('Your request to place ticket #' . $ticketNumber . ' on hold was rejected.' . ($decisionNote !== '' ? ' Note: ' . $decisionNote : ''));
notif_insert_system($conn, $requesterId, $ticketId, $message, $approved ? 'hold_approved' : 'hold_rejected', 10, 'update', $title);

$requesterContact = notif_user_contact($conn, $requesterId);
$assigneeEmail = strtolower(trim((string) ($requesterContact['email'] ?? '')));
$ticketRequesterEmail = '';

if ($approved) {
    $freshTicket = notif_ticket_data($conn, $ticketId) ?: $ticket;
    foreach (notif_requester_user_ids($conn, $freshTicket) as $ticketRequesterId) {
        if ((int) $ticketRequesterId === $requesterId) continue;
        notif_insert_system($conn, (int) $ticketRequesterId, $ticketId,
            'Ticket #' . $ticketNumber . ' has been placed on hold. Reason: ' . $reason,
            'ticket_on_hold', 10, 'update', 'Ticket On Hold');
    }
    $ticketRequesterEmail = strtolower(trim((string) ($freshTicket['creator_email'] ?? $freshTicket['requester_email'] ?? '')));
}

$assigneeEmailValid = $assigneeEmail !== '' && filter_var($assigneeEmail, FILTER_VALIDATE_EMAIL);
$ticketRequesterEmailValid = $approved
    && $ticketRequesterEmail !== ''
    && $ticketRequesterEmail !== $assigneeEmail
    && filter_var($ticketRequesterEmail, FILTER_VALIDATE_EMAIL);

// The approval decision, SLA change, and in-system notifications are complete.
// Release the session and respond before SMTP work so the modal refresh is not
// delayed by the mail server or by PHP's session lock.
$successJson = json_encode([
    'ok' => true,
    'message' => $approved ? 'Hold request approved. The ticket is now on hold.' : 'Hold request rejected.',
    'is_on_hold' => $approved,
    'email_scheduled' => $assigneeEmailValid || $ticketRequesterEmailValid,
]);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) {
    echo $successJson;
    fastcgi_finish_request();
} else {
    header('Content-Length: ' . strlen($successJson));
    header('Connection: close');
    echo $successJson;
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

$emailSent = false;
if ($assigneeEmailValid) {
    $lines = ['Ticket ID: #' . $ticketNumber, $message];
    if ($approved) $lines[] = 'Hold Reason: ' . $reason;
    $mail = notif_email_simple($title, $lines, 'View Ticket', notif_ticket_link_employee_tasks($ticketId));
    $emailSent = notif_email_send([$assigneeEmail], $title . ' (#' . $ticketNumber . ')',
        (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''), [], ['ticket_id' => $ticketId]);
}
if ($ticketRequesterEmailValid) {
    $requesterTitle = 'Ticket On Hold';
    $requesterLines = [
        'Ticket ID: #' . $ticketNumber,
        'Your ticket has been placed on hold.',
        'Hold Reason: ' . $reason,
        'The SLA timer is paused until work on this ticket resumes.',
    ];
    $requesterMail = notif_email_simple($requesterTitle, $requesterLines, 'View Ticket', notif_ticket_link_employee_tickets($ticketId));
    $requesterEmailSent = notif_email_send([$ticketRequesterEmail], $requesterTitle . ' (#' . $ticketNumber . ')',
        (string) ($requesterMail['html'] ?? ''), (string) ($requesterMail['text'] ?? ''), [], ['ticket_id' => $ticketId]);
    $emailSent = $emailSent || $requesterEmailSent;
}
