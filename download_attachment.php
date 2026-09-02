<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/ticket_assignment.php';
require_once __DIR__ . '/includes/private_attachments.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(403);
    exit('Forbidden');
}

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$storedName = private_attachment_safe_name((string) ($_GET['file'] ?? ''));
if ($storedName === '') {
    http_response_code(400);
    exit('Invalid attachment');
}

if ($ticketId <= 0) {
    $lookup = $conn->prepare("SELECT ticket_id FROM ticket_attachments WHERE stored_name = ? ORDER BY id DESC LIMIT 1");
    if ($lookup) {
        $lookup->bind_param('s', $storedName);
        $lookup->execute();
        $ticketId = (int) (($lookup->get_result()->fetch_assoc()['ticket_id'] ?? 0));
        $lookup->close();
    }
    if ($ticketId <= 0) {
        $chatLookup = $conn->prepare('SELECT ticket_id FROM ticket_messages WHERE attachment_stored_name = ? ORDER BY id DESC LIMIT 1');
        if ($chatLookup) {
            $chatLookup->bind_param('s', $storedName);
            $chatLookup->execute();
            $ticketId = (int) (($chatLookup->get_result()->fetch_assoc()['ticket_id'] ?? 0));
            $chatLookup->close();
        }
    }
    if ($ticketId <= 0) {
        $legacy = $conn->prepare('SELECT id FROM employee_tickets WHERE attachment = ? LIMIT 1');
        if ($legacy) {
            $legacy->bind_param('s', $storedName);
            $legacy->execute();
            $ticketId = (int) (($legacy->get_result()->fetch_assoc()['id'] ?? 0));
            $legacy->close();
        }
    }
}

$ticketStmt = $conn->prepare("SELECT t.*, u.email AS creator_email, u.company AS user_company, u.department AS user_department
    FROM employee_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1");
$ticketStmt->bind_param('i', $ticketId);
$ticketStmt->execute();
$ticket = $ticketStmt->get_result()->fetch_assoc();
$ticketStmt->close();
if (!$ticket) {
    http_response_code(404);
    exit('Not Found');
}

$belongs = ((string) ($ticket['attachment'] ?? '') === $storedName);
if (!$belongs) {
    $belongsStmt = $conn->prepare('SELECT original_name FROM ticket_attachments WHERE ticket_id = ? AND stored_name = ? LIMIT 1');
    $belongsStmt->bind_param('is', $ticketId, $storedName);
    $belongsStmt->execute();
    $attachmentRow = $belongsStmt->get_result()->fetch_assoc();
    $belongsStmt->close();
    $belongs = (bool) $attachmentRow;
}
if (!$belongs) {
    $chatBelongs = $conn->prepare('SELECT attachment_original_name AS original_name FROM ticket_messages WHERE ticket_id = ? AND attachment_stored_name = ? LIMIT 1');
    if ($chatBelongs) {
        $chatBelongs->bind_param('is', $ticketId, $storedName);
        $chatBelongs->execute();
        $chatRow = $chatBelongs->get_result()->fetch_assoc();
        $chatBelongs->close();
        if ($chatRow) {
            $belongs = true;
            $attachmentRow = $chatRow;
        }
    }
}
if ((string) ($ticket['attachment'] ?? '') === $storedName && empty($attachmentRow)) {
    $attachmentRow = ['original_name' => $storedName];
}
if (!$belongs) {
    http_response_code(404);
    exit('Not Found');
}

$userId = (int) $_SESSION['user_id'];
$role = (string) $_SESSION['role'];
$authorized = $role === 'admin';
if (!$authorized && $role === 'employee') {
    $context = ticket_build_user_context($conn, $userId, $_SESSION);
    $assignedTo = (int) ($ticket['assigned_to'] ?? 0);
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    $specificUserLocked = $assignedUserId > 0
        && ($assignedTo > 0 || strcasecmp((string) ($ticket['status'] ?? ''), 'Open') !== 0);
    $handlerCandidate = ticket_user_is_handler_candidate($ticket, $userId, $context);
    if ($specificUserLocked && $assignedUserId !== $userId) {
        $handlerCandidate = false;
    }

    $feedbackAccess = false;
    $feedbackStmt = $conn->prepare("SELECT 1 FROM ticket_feedback tf
        WHERE tf.ticket_id = ? AND tf.assignee_id = ? LIMIT 1");
    if ($feedbackStmt) {
        $feedbackStmt->bind_param('ii', $ticketId, $userId);
        $feedbackStmt->execute();
        $feedbackAccess = (bool) $feedbackStmt->get_result()->fetch_row();
        $feedbackStmt->close();
    }

    $authorized = ticket_user_matches_requester($ticket, $userId, $context)
        || $handlerCandidate
        || ticket_user_can_manual_claim($ticket, $userId, $context)
        || ticket_user_is_sales_manager_for_ticket($ticket, $context)
        || ticket_user_has_task_read_access($conn, $ticket, $userId, $context)
        || $feedbackAccess
        || $assignedTo === $userId
        || $assignedUserId === $userId;
}
if (!$authorized) {
    http_response_code(403);
    exit('Forbidden');
}

$path = private_attachment_resolve_path($storedName);
if ($path === '') {
    http_response_code(404);
    exit('Not Found');
}
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (is_string($detected) && $detected !== '') $mime = $detected;
}
$originalName = basename(str_replace('\\', '/', (string) ($attachmentRow['original_name'] ?? $storedName)));
$originalName = preg_replace('/[^A-Za-z0-9 ._()\[\]-]+/', '_', $originalName) ?: $storedName;
$inlineAllowed = preg_match('#^(image/(?:jpeg|png|gif|webp)|application/pdf)$#', $mime) === 1;
$disposition = (!empty($_GET['download']) || !$inlineAllowed) ? 'attachment' : 'inline';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($originalName, '"\\') . '"');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
