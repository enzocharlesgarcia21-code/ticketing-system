<?php
$root = dirname(__DIR__, 2);
$failures = [];
$passes = 0;

function hold_assert(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) $passes++;
    else $failures[] = $message;
}

function hold_source(string $relative): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relative);
    return is_string($source) ? $source : '';
}

require_once $root . '/includes/sla_calendar.php';
require_once $root . '/includes/ticket_assignment.php';
require_once $root . '/includes/hold_approval.php';

ticket_ensure_assignment_columns($conn);
$holdColumns = ['hold_started_at', 'hold_reason', 'hold_by', 'sla_hold_seconds', 'sla_hold_seconds_at_high'];
$columnResult = $conn->query("SHOW COLUMNS FROM employee_tickets");
$existingHoldColumns = [];
while ($columnResult && ($column = $columnResult->fetch_assoc())) {
    $existingHoldColumns[(string) ($column['Field'] ?? '')] = true;
}
hold_assert(count(array_diff($holdColumns, array_keys($existingHoldColumns))) === 0, 'All ticket hold/SLA database columns must be installed.');

$held = [
    'created_at' => '2026-09-01 08:00:00',
    'hold_started_at' => '2026-09-01 10:00:00',
    'sla_hold_seconds' => 0,
];
hold_assert(ticket_sla_elapsed_seconds($held, 'created_at', '2026-09-01 15:00:00') === 7200, 'An active hold must freeze SLA elapsed time at hold_started_at.');

$resumed = [
    'created_at' => '2026-09-01 08:00:00',
    'hold_started_at' => null,
    'sla_hold_seconds' => 3600,
];
hold_assert(ticket_sla_elapsed_seconds($resumed, 'created_at', '2026-09-01 12:00:00') === 10800, 'Completed hold business seconds must be deducted after resume.');

$elapsedSql = ticket_sla_elapsed_seconds_sql('t', 't.created_at');
$elapsedResult = $conn->query("SELECT $elapsedSql AS elapsed_seconds FROM employee_tickets t LIMIT 1");
hold_assert($elapsedResult !== false, 'Pause-aware SLA SQL must execute against the installed ticket schema.');

hold_approval_ensure_table($conn);
$holdRequestTable = $conn->query("SHOW TABLES LIKE 'ticket_hold_requests'");
hold_assert($holdRequestTable && $holdRequestTable->num_rows === 1, 'The pending hold approval table must be installed.');
$permissionDefaults = user_permissions_defaults();
hold_assert(array_key_exists('hold_approver', $permissionDefaults) && (int) $permissionDefaults['hold_approver'] === 0, 'Hold Approver must be an explicit opt-in user permission.');
$scopeTicket = ['assigned_company' => '@leadsagri.com', 'assigned_group' => 'IT'];
hold_assert(hold_approval_scope_matches(['company' => 'LAPC', 'department' => 'IT'], $scopeTicket), 'An enabled approver in the same company and department must match the ticket scope.');
hold_assert(hold_approval_scope_matches(['company' => '', 'department' => 'IT'], $scopeTicket), 'A legacy employee without a stored company must still approve tickets from their own department.');
hold_assert(!hold_approval_scope_matches(['company' => 'LAPC', 'department' => 'HR'], $scopeTicket), 'An approver from another department must not match the ticket scope.');
hold_assert(!hold_approval_scope_matches(['company' => 'PCC', 'department' => 'IT'], $scopeTicket), 'An approver from another company must not match the ticket scope.');

$endpoint = hold_source('employee/hold_ticket.php');
hold_assert(strpos($endpoint, 'csrf_validate()') !== false && strpos($endpoint, "REQUEST_METHOD") !== false, 'Hold endpoint must be POST-only and CSRF protected.');
hold_assert(strpos($endpoint, '$assignedTo !== $userId && $assignedUserId !== $userId') !== false, 'Only the current assignee may change hold state.');
hold_assert(strpos($endpoint, 'A hold reason is required.') !== false && strpos($endpoint, 'mb_strlen($reason) > 1000') !== false, 'Hold reason must be required and length limited server-side.');
hold_assert(strpos($endpoint, 'notif_insert_system') !== false && strpos($endpoint, 'notif_email_send') !== false, 'Requester must receive system and email notifications.');
hold_assert(strpos($endpoint, "['ticket_id' => \$ticketId]") !== false, 'Hold email must use the existing ticket email thread.');
hold_assert(strpos($endpoint, 'hold_approval_approvers') !== false && strpos($endpoint, 'INSERT INTO ticket_hold_requests') !== false, 'A Hold action must create a pending request for another department approver.');
hold_assert(strpos($endpoint, "UPDATE employee_tickets SET hold_started_at = NOW()") === false, 'Requesting Hold must not pause the SLA before approval.');
hold_assert(strpos($endpoint, 'session_write_close()') !== false && strpos($endpoint, 'fastcgi_finish_request') !== false && strpos($endpoint, "header('Content-Length: '") !== false, 'Hold requests must return before slower SMTP delivery so the modal can refresh immediately.');

$reviewEndpoint = hold_source('employee/review_hold_request.php');
hold_assert(strpos($reviewEndpoint, 'csrf_validate()') !== false && strpos($reviewEndpoint, "REQUEST_METHOD") !== false, 'Hold review must be POST-only and CSRF protected.');
hold_assert(strpos($reviewEndpoint, 'hold_approval_user_can_review') !== false && strpos($reviewEndpoint, 'You cannot approve your own hold request.') !== false, 'Hold review must enforce department permission and separation of duties.');
hold_assert(strpos($reviewEndpoint, "SET hold_started_at = NOW()") !== false && strpos($reviewEndpoint, "'approved'") !== false && strpos($reviewEndpoint, "'rejected'") !== false, 'Approval must pause the SLA while rejection leaves the ticket active.');
hold_assert(strpos($reviewEndpoint, 'session_write_close()') !== false && strpos($reviewEndpoint, 'fastcgi_finish_request') !== false && strpos($reviewEndpoint, "header('Content-Length: '") !== false, 'Hold approval decisions must return before slower SMTP delivery so the approver modal refreshes immediately.');

$details = hold_source('employee/get_ticket_details.php');
hold_assert(strpos($details, "\$row['can_hold_ticket']") !== false && strpos($details, "\$row['can_resume_ticket']") !== false, 'Ticket details must expose assignee-scoped Hold and Resume capabilities.');
hold_assert(strpos($details, "\$row['can_update_tab'] = !\$taskReadOnlyAccess && \$isCurrentAssignee") !== false, 'A held ticket must remain manageable in the assignee Update tab.');
hold_assert(strpos($details, "\$row['can_review_hold_request']") !== false && strpos($details, 'hold_approval_user_can_review') !== false, 'Ticket details must expose review controls only after department-scoped authorization.');
hold_assert(strpos($details, '$canSeePendingHold') !== false && strpos($details, "\$row['hold_approval_pending'] = (bool) \$canSeePendingHold") !== false, 'Pending hold reasons must be hidden from uninvolved ticket viewers.');

$ui = hold_source('js/ticket-modal.js');
$uiCss = hold_source('css/view-tickets.css');
hold_assert(strpos($ui, 'data-status-option="Hold"') !== false && strpos($ui, 'Reason for Placing Ticket on Hold') !== false, 'Hold must be available in the Update status control with a required reason field.');
hold_assert(strpos($ui, "currentStatus === 'Hold'") !== false && strpos($ui, "submitHoldChange((data && data.id) || '', 'hold'") !== false, 'Saving the Hold status must use the protected hold endpoint.');
hold_assert(strpos($ui, 'Place Ticket On Hold') === false && strpos($ui, 'onclick="TMTicketModal.holdTicket') === false, 'The old Hold popup and upper-right Hold control must be removed.');
hold_assert(strpos($ui, 'tm-note-footer') !== false && strpos($ui, 'data-update-fixed-footer') === false, 'Save Ticket must remain in the normal Ticket Update form footer.');
hold_assert(strpos($ui, 'holdInfoBannerHtml') !== false && strpos($ui, "'  <div id=\"tab-info\"") !== false, 'The Ticket On Hold banner must be rendered only within the Information tab.');
hold_assert(strpos($uiCss, '#ticketModal #tab-info > .tm-hold-banner') !== false && strpos($uiCss, 'grid-column: 1 / -1;') !== false && strpos($uiCss, 'order: -2;') !== false, 'The Information hold banner must span the upper row before the two-column ticket information layout.');
hold_assert(strpos($ui, 'isReassignedViewOnly && !isOnHold ? reassignedBannerHtml') !== false, 'The hold notice must replace the reassignment notice instead of stacking both banners.');
hold_assert(strpos($ui, "previousValue === 'Hold' && nextValue !== 'Hold'") !== false && strpos($ui, "noteEl.value = '';") !== false, 'Leaving Hold must clear the previous hold reason from the action comment field.');
hold_assert(strpos($ui, 'Hold Approval Requested') !== false && strpos($ui, 'reviewHoldRequest') !== false && strpos($ui, 'review_hold_request.php') !== false, 'The modal must provide Accept Hold and Reject controls to the approver.');
hold_assert(strpos($uiCss, '.tm-note-footer') !== false && strpos($uiCss, 'position: static;') !== false, 'The normal Ticket Update footer must stay in the card flow without a detached sticky action bar.');

$employeeNavbar = hold_source('includes/employee_navbar.php');
$employeeNotifications = hold_source('employee/notifications.php');
foreach ([$employeeNavbar, $employeeNotifications] as $notificationUi) {
    hold_assert(strpos($notificationUi, 'ticket_on_hold') !== false && strpos($notificationUi, 'hold_approved') !== false, 'Completed Hold notifications must be recognized in every employee notification surface.');
    hold_assert(strpos($notificationUi, 'variant-hold') !== false && strpos($notificationUi, 'fa-pause') !== false && strpos($notificationUi, "'HOLD'") !== false, 'Completed Hold notifications must use the orange pause/HOLD pill.');
    hold_assert(strpos($notificationUi, "'Status Update'") !== false && strpos($notificationUi, '#f59e0b') !== false, 'Completed Hold notifications must use the Status Update title and orange accent.');
    hold_assert(strpos($notificationUi, "'<br>Reason:'") !== false, 'Completed Hold notifications must place the hold reason on its own line.');
}

$assignment = hold_source('includes/ticket_assignment.php');
hold_assert(strpos($assignment, 'ticket_sla_elapsed_seconds_sql') !== false && strpos($assignment, 'hold_started_at IS NULL') !== false, 'Automatic SLA escalation must exclude active holds and use pause-aware elapsed time.');

if ($failures) {
    fwrite(STDERR, "Ticket hold regression failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Ticket hold regression tests passed: ' . $passes . PHP_EOL;
