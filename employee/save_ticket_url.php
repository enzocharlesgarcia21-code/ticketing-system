<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

csrf_validate();
ticket_ensure_assignment_columns($conn);
ticket_ensure_activity_table($conn);

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$requesterUrl = trim((string) ($_POST['requester_url'] ?? ''));

if ($ticketId <= 0 || $currentUserId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid ticket.']);
    exit;
}

if ($requesterUrl !== '' && !preg_match('~^https?://~i', $requesterUrl)) {
    $requesterUrl = 'https://' . $requesterUrl;
}
if (strlen($requesterUrl) > 2048) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'The URL is too long.']);
    exit;
}
if ($requesterUrl !== '') {
    $scheme = strtolower((string) parse_url($requesterUrl, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || filter_var($requesterUrl, FILTER_VALIDATE_URL) === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Enter a valid HTTP or HTTPS URL.']);
        exit;
    }
}

$ticketStmt = $conn->prepare("
    SELECT user_id, assigned_user_id, assigned_to, status, requester_url
    FROM employee_tickets
    WHERE id = ?
    LIMIT 1
");
if (!$ticketStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to load the ticket.']);
    exit;
}
$ticketStmt->bind_param('i', $ticketId);
$ticketStmt->execute();
$ticketResult = $ticketStmt->get_result();
$ticket = $ticketResult ? $ticketResult->fetch_assoc() : null;
$ticketStmt->close();

if (!$ticket) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Ticket not found.']);
    exit;
}

$assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
$handlerId = (int) ($ticket['assigned_to'] ?? 0);
$statusKey = strtolower(trim((string) ($ticket['status'] ?? '')));
$isLockedAssignee = $assignedUserId === $currentUserId && ($handlerId > 0 || $statusKey !== 'open');
$canManageUrl = (int) ($ticket['user_id'] ?? 0) !== $currentUserId
    && ($handlerId === $currentUserId || $isLockedAssignee);
if (!$canManageUrl) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the current assignee can manage this URL.']);
    exit;
}

$oldUrl = trim((string) ($ticket['requester_url'] ?? ''));
if ($oldUrl === $requesterUrl) {
    echo json_encode(['ok' => true, 'url' => $requesterUrl, 'message' => 'No changes were made.']);
    exit;
}

$updateStmt = $conn->prepare("UPDATE employee_tickets SET requester_url = ?, updated_at = NOW() WHERE id = ?");
if (!$updateStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save the URL.']);
    exit;
}
$urlValue = $requesterUrl !== '' ? $requesterUrl : null;
$updateStmt->bind_param('si', $urlValue, $ticketId);
$saved = $updateStmt->execute();
$updateStmt->close();

if (!$saved) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save the URL.']);
    exit;
}

$activityDescription = $requesterUrl === '' ? 'Requester URL removed' : ($oldUrl === '' ? 'Requester URL added' : 'Requester URL updated');
$activityStmt = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'url_update', ?, NOW())");
if ($activityStmt) {
    $activityStmt->bind_param('is', $ticketId, $activityDescription);
    $activityStmt->execute();
    $activityStmt->close();
}

echo json_encode([
    'ok' => true,
    'url' => $requesterUrl,
    'message' => $requesterUrl === '' ? 'URL removed successfully.' : 'URL saved successfully.',
]);
