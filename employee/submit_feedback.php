<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: my_tickets.php");
    exit();
}

csrf_validate();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
$rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
$comment = trim((string) ($_POST['comment'] ?? ''));
$redirectTarget = 'my_tickets.php';
$rawRedirectTarget = trim((string) ($_POST['redirect_to'] ?? ''));
if ($rawRedirectTarget !== '' && !preg_match('/^[a-z]+:/i', $rawRedirectTarget) && strpos($rawRedirectTarget, '//') !== 0) {
    $redirectPath = (string) parse_url($rawRedirectTarget, PHP_URL_PATH);
    if ($redirectPath === 'my_tickets.php' || $redirectPath === 'dashboard.php') {
        $redirectTarget = ltrim($rawRedirectTarget, '/');
    }
}

function feedback_redirect_url(string $target, int $ticketId, bool $keepModal, bool $keepTicketContext = true): string
{
    $target = trim($target) !== '' ? $target : 'my_tickets.php';
    $parts = parse_url($target);
    $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : 'my_tickets.php';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    if ($keepTicketContext && $ticketId > 0) {
        $query['ticket_id'] = $ticketId;
    }
    if ($keepModal) {
        $query['show_feedback'] = '1';
    } else {
        unset($query['show_feedback']);
    }
    $queryString = http_build_query($query);
    return $path . ($queryString !== '' ? '?' . $queryString : '');
}

$redirectWithMessage = static function (string $type, string $message, bool $keepModal = false, ?string $targetOverride = null, bool $keepTicketContext = true) use (&$ticketId, $redirectTarget): void {
    $_SESSION['feedback_flash'] = [
        'type' => $type,
        'message' => $message,
        'ticket_id' => $ticketId,
    ];
    $finalTarget = $targetOverride !== null && trim($targetOverride) !== '' ? $targetOverride : $redirectTarget;
    header("Location: " . feedback_redirect_url($finalTarget, $ticketId, $keepModal, $keepTicketContext));
    exit();
};

function feedback_assignee_department_label(array $ticket): string
{
    $department = trim((string) ($ticket['assigned_group'] ?? ''));
    if ($department === '') {
        $department = trim((string) ($ticket['assigned_department'] ?? ''));
    }
    if ($department === '') {
        $department = trim((string) ($ticket['assignee_department'] ?? ''));
    }
    if ($department === '') {
        return 'assigned team';
    }
    return ticket_department_display_name($department);
}

if ($ticketId <= 0) {
    $redirectWithMessage('error', 'Invalid ticket selected for feedback.', true);
}

if ($rating < 1 || $rating > 5) {
    $redirectWithMessage('error', 'Please choose a rating from 1 to 5 stars.', true);
}

$ticketStmt = $conn->prepare("
    SELECT
        employee_tickets.id,
        employee_tickets.subject,
        employee_tickets.user_id,
        employee_tickets.status,
        employee_tickets.feedback_status,
        employee_tickets.assigned_to,
        employee_tickets.assigned_user_id,
        employee_tickets.assigned_department,
        employee_tickets.assigned_group,
        assignee.department AS assignee_department
    FROM employee_tickets
    LEFT JOIN users assignee ON assignee.id = employee_tickets.assigned_user_id
    WHERE employee_tickets.id = ?
    LIMIT 1
");
if (!$ticketStmt) {
    $redirectWithMessage('error', 'Unable to process your feedback right now.', true);
}

$ticketStmt->bind_param("i", $ticketId);
$ticketStmt->execute();
$ticketRes = $ticketStmt->get_result();
$ticket = $ticketRes ? $ticketRes->fetch_assoc() : null;
$ticketStmt->close();

if (!$ticket || (int) ($ticket['user_id'] ?? 0) !== $userId) {
    $redirectWithMessage('error', 'You are not allowed to submit feedback for this ticket.', true);
}

if ((string) ($ticket['status'] ?? '') !== 'Resolved' || (string) ($ticket['feedback_status'] ?? '') !== 'pending') {
    $redirectWithMessage('error', 'This ticket is no longer waiting for feedback.');
}

$existingStmt = $conn->prepare("
    SELECT id
    FROM ticket_feedback
    WHERE ticket_id = ?
    LIMIT 1
");
if (!$existingStmt) {
    $redirectWithMessage('error', 'Unable to validate existing feedback right now.', true);
}

$existingStmt->bind_param("i", $ticketId);
$existingStmt->execute();
$existingRes = $existingStmt->get_result();
$existingFeedback = $existingRes ? $existingRes->fetch_assoc() : null;
$existingStmt->close();

if ($existingFeedback) {
    $syncStmt = $conn->prepare("
        UPDATE employee_tickets
        SET feedback_status = 'submitted'
        WHERE id = ?
        LIMIT 1
    ");
    if ($syncStmt) {
        $syncStmt->bind_param("i", $ticketId);
        $syncStmt->execute();
        $syncStmt->close();
    }
    $redirectWithMessage('success', 'Feedback was already submitted for this ticket.');
}

$assigneeId = isset($ticket['assigned_to']) ? (int) $ticket['assigned_to'] : 0;
if ($assigneeId <= 0) {
    $assigneeId = isset($ticket['assigned_user_id']) ? (int) $ticket['assigned_user_id'] : 0;
}

if ($assigneeId <= 0) {
    $redirectWithMessage('error', 'This ticket does not have a valid attending assignee yet.', true);
}

$insertStmt = $conn->prepare("
    INSERT INTO ticket_feedback (ticket_id, requestor_id, assignee_id, rating, comment)
    VALUES (?, ?, ?, ?, ?)
");
if (!$insertStmt) {
    $redirectWithMessage('error', 'Unable to save your feedback right now.', true);
}

$insertStmt->bind_param("iiiis", $ticketId, $userId, $assigneeId, $rating, $comment);
$insertOk = $insertStmt->execute();
$insertStmt->close();

if (!$insertOk) {
    $redirectWithMessage('error', 'Unable to save your feedback right now.', true);
}

$updateStmt = $conn->prepare("
    UPDATE employee_tickets
    SET feedback_status = 'submitted'
    WHERE id = ?
    LIMIT 1
");
if (!$updateStmt) {
    $redirectWithMessage('error', 'Feedback saved, but the ticket status could not be updated.');
}

$updateStmt->bind_param("i", $ticketId);
$updateStmt->execute();
$updateStmt->close();

$assigneeDepartmentLabel = feedback_assignee_department_label($ticket);
$redirectWithMessage('success', 'Your feedback has been submitted to the ' . $assigneeDepartmentLabel . ' department.', false, 'dashboard.php', false);
