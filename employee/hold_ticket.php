<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
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
$action = strtolower(trim((string) ($_POST['action'] ?? 'hold')));
$reason = trim((string) ($_POST['reason'] ?? ''));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$approvers = [];
$holdApprovalRequested = false;

if ($ticketId <= 0 || !in_array($action, ['hold', 'resume'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}
if (($action === 'hold' && $reason === '') || mb_strlen($reason) > 1000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $reason === '' ? 'A hold reason is required.' : 'The hold reason must be 1,000 characters or fewer.']);
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT t.*, creator.name AS creator_name,
            COALESCE(NULLIF(TRIM(t.requester_email), ''), creator.email) AS creator_email
        FROM employee_tickets t
        LEFT JOIN users creator ON creator.id = t.user_id
        WHERE t.id = ? FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Unable to load ticket.');
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ticket) {
        $conn->rollback();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Ticket not found.']);
        exit;
    }

    $assignedTo = (int) ($ticket['assigned_to'] ?? 0);
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    if ($assignedTo !== $userId && $assignedUserId !== $userId) {
        $conn->rollback();
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Only the ticket assignee can change its hold state.']);
        exit;
    }

    $holdStartedAt = trim((string) ($ticket['hold_started_at'] ?? ''));
    $status = trim((string) ($ticket['status'] ?? ''));
    if ($action === 'hold') {
        if ($status !== 'In Progress' || $holdStartedAt !== '') {
            $conn->rollback();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Only an active In Progress ticket can be placed on hold.']);
            exit;
        }
        $pendingRequest = hold_approval_pending_request($conn, $ticketId, true);
        if ($pendingRequest) {
            $conn->rollback();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'A hold request for this ticket is already awaiting approval.']);
            exit;
        }
        $approvers = hold_approval_approvers($conn, $ticket, $userId);
        if (!$approvers) {
            $conn->rollback();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'No Hold Approver is configured for this ticket department.']);
            exit;
        }
        $insertRequest = $conn->prepare("INSERT INTO ticket_hold_requests (ticket_id, requested_by, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
        if (!$insertRequest) throw new RuntimeException('Unable to create the hold approval request.');
        $insertRequest->bind_param('iis', $ticketId, $userId, $reason);
        $insertRequest->execute();
        $changed = $insertRequest->affected_rows > 0;
        $insertRequest->close();
        if (!$changed) throw new RuntimeException('Unable to create the hold approval request.');
        $holdApprovalRequested = true;
    } else {
        if ($holdStartedAt === '') {
            $conn->rollback();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'This ticket is not currently on hold.']);
            exit;
        }
        $pausedSeconds = ticket_sla_business_seconds_between($holdStartedAt);
        $update = $conn->prepare('UPDATE employee_tickets SET hold_started_at = NULL, hold_by = NULL, sla_hold_seconds = COALESCE(sla_hold_seconds, 0) + ?, updated_at = NOW() WHERE id = ? AND hold_started_at = ?');
        $update->bind_param('iis', $pausedSeconds, $ticketId, $holdStartedAt);
        $update->execute();
        $changed = $update->affected_rows > 0;
        $update->close();
        if (!$changed) throw new RuntimeException('The ticket hold state changed. Please refresh and try again.');
    }

    $actor = ticket_activity_actor_label($conn, $userId, $_SESSION);
    $activityType = $action === 'hold' ? 'ticket_hold_requested' : 'ticket_resume';
    $activityDescription = $action === 'hold'
        ? ('Ticket hold requested by ' . $actor . '. Reason: ' . $reason)
        : ('Ticket resumed by ' . $actor . '.' . ($reason !== '' ? ' Comments: ' . $reason : ''));
    ticket_record_activity($conn, $ticketId, $activityType, $activityDescription);
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Ticket hold update failed | ticket_id=' . $ticketId . ' | ' . $e->getMessage());
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Unable to update the ticket hold state. Please refresh and try again.']);
    exit;
}

if ($holdApprovalRequested) {
    $ticketNumber = notif_ticket_number($ticketId);
    $title = 'Hold Approval Required';
    $message = $actor . ' requested to place ticket #' . $ticketNumber . ' on hold. Reason: ' . $reason;
    $approverEmails = [];
    foreach ($approvers as $approver) {
        $approverId = (int) ($approver['id'] ?? 0);
        if ($approverId > 0) {
            notif_insert_system($conn, $approverId, $ticketId, $message, 'hold_approval_requested', 10, 'update', $title);
        }
        $approverEmail = strtolower(trim((string) ($approver['email'] ?? '')));
        if ($approverEmail !== '' && filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
            $approverEmails[] = $approverEmail;
        }
    }

    // The approval request and in-system notifications are complete. Release
    // the session and return before SMTP work so the modal can refresh without
    // waiting for the mail server or being blocked by PHP's session lock.
    $successJson = json_encode([
        'ok' => true,
        'message' => 'Hold request sent for department approval.',
        'is_on_hold' => false,
        'approval_pending' => true,
        'email_scheduled' => !empty($approverEmails),
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
    if ($approverEmails) {
        $mail = notif_email_simple($title, [
            'Ticket ID: #' . $ticketNumber,
            'Requested by: ' . $actor,
            'Reason: ' . $reason,
            'The ticket remains In Progress and its SLA timer continues until this request is approved.',
        ], 'Review Hold Request', notif_ticket_link_employee_tasks($ticketId));
        $emailSent = notif_email_send(
            array_values(array_unique($approverEmails)),
            $title . ' (#' . $ticketNumber . ')',
            (string) ($mail['html'] ?? ''),
            (string) ($mail['text'] ?? ''),
            [],
            ['ticket_id' => $ticketId]
        );
    }
    exit;
}

$freshTicket = notif_ticket_data($conn, $ticketId) ?: $ticket;
$ticketNumber = notif_ticket_number($ticketId);
$title = $action === 'hold' ? 'Ticket On Hold' : 'Ticket Resumed';
$message = $action === 'hold'
    ? ('Ticket #' . $ticketNumber . ' has been placed on hold. Reason: ' . $reason)
    : ('Ticket #' . $ticketNumber . ' has resumed and its SLA timer is running again.' . ($reason !== '' ? ' Comments: ' . $reason : ''));
$type = $action === 'hold' ? 'ticket_on_hold' : 'ticket_resumed';

foreach (notif_requester_user_ids($conn, $freshTicket) as $requesterId) {
    notif_insert_system($conn, (int) $requesterId, $ticketId, $message, $type, 10, 'update', $title);
}

$requesterEmail = strtolower(trim((string) ($freshTicket['creator_email'] ?? $freshTicket['requester_email'] ?? '')));
$emailSent = false;
if ($requesterEmail !== '' && filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
    $lines = [
        'Ticket ID: #' . $ticketNumber,
        'Current Status: ' . ($action === 'hold' ? 'On Hold' : 'In Progress'),
    ];
    if ($action === 'hold') $lines[] = 'Hold Reason: ' . $reason;
    if ($action === 'resume' && $reason !== '') $lines[] = 'Comments: ' . $reason;
    $lines[] = $action === 'hold'
        ? 'The SLA timer has been paused until work on this ticket resumes.'
        : 'The SLA timer has resumed.';
    $mail = notif_email_simple($title, $lines, 'View Ticket', notif_ticket_link_employee_tickets($ticketId));
    $emailSent = notif_email_send(
        [$requesterEmail],
        $title . ' (#' . $ticketNumber . ')',
        (string) ($mail['html'] ?? ''),
        (string) ($mail['text'] ?? ''),
        [],
        ['ticket_id' => $ticketId]
    );
}

echo json_encode([
    'ok' => true,
    'message' => $action === 'hold' ? 'Ticket placed on hold.' : 'Ticket resumed.',
    'is_on_hold' => $action === 'hold',
    'email_sent' => $emailSent,
]);
