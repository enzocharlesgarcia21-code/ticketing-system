<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

csrf_validate();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
$rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
$comment = trim((string) ($_POST['comment'] ?? ''));

$redirectWithMessage = static function (string $type, string $message): void {
    $_SESSION['feedback_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
    header("Location: dashboard.php");
    exit();
};

if ($ticketId <= 0) {
    $redirectWithMessage('error', 'Invalid ticket selected for feedback.');
}

if ($rating < 1 || $rating > 5) {
    $redirectWithMessage('error', 'Please choose a rating from 1 to 5 stars.');
}

$ticketStmt = $conn->prepare("
    SELECT id, subject, user_id, status, feedback_status, assigned_to, assigned_user_id
    FROM employee_tickets
    WHERE id = ?
    LIMIT 1
");
if (!$ticketStmt) {
    $redirectWithMessage('error', 'Unable to process your feedback right now.');
}

$ticketStmt->bind_param("i", $ticketId);
$ticketStmt->execute();
$ticketRes = $ticketStmt->get_result();
$ticket = $ticketRes ? $ticketRes->fetch_assoc() : null;
$ticketStmt->close();

if (!$ticket || (int) ($ticket['user_id'] ?? 0) !== $userId) {
    $redirectWithMessage('error', 'You are not allowed to submit feedback for this ticket.');
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
    $redirectWithMessage('error', 'Unable to validate existing feedback right now.');
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
    $redirectWithMessage('error', 'This ticket does not have a valid attending assignee yet.');
}

$insertStmt = $conn->prepare("
    INSERT INTO ticket_feedback (ticket_id, requestor_id, assignee_id, rating, comment)
    VALUES (?, ?, ?, ?, ?)
");
if (!$insertStmt) {
    $redirectWithMessage('error', 'Unable to save your feedback right now.');
}

$insertStmt->bind_param("iiiis", $ticketId, $userId, $assigneeId, $rating, $comment);
$insertOk = $insertStmt->execute();
$insertStmt->close();

if (!$insertOk) {
    $redirectWithMessage('error', 'Unable to save your feedback right now.');
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

$redirectWithMessage('success', 'Thanks for rating your resolved ticket.');
