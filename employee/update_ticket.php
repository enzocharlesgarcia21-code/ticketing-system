<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

function finish_ticket_update_response(string $location): void
{
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    ignore_user_abort(true);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header("Location: $location");
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Length: 0');
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    @flush();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

function company_code(string $value): string
{
    $s = strtoupper(trim($value));
    if ($s === '') return '';
    if ($s === 'FARMASEE') return 'PCC';
    if (strpos($s, 'MHC') !== false) return 'MHC';
    if (strpos($s, 'GPCI') !== false || strpos($s, 'GPSCI') !== false) return 'GPCI';
    if (strpos($s, 'LAPC') !== false || strpos($s, 'LAH') !== false) return 'LAPC';
    if (strpos($s, 'PCC') !== false) return 'PCC';
    if (strpos($s, 'MPDC') !== false) return 'MPDC';
    if (strpos($s, 'LINGAP') !== false) return 'LINGAP';
    if (strpos($s, 'LTC') !== false) return 'LTC';
    if (strpos($s, 'FARMEX') !== false) return 'FARMEX';
    if (strpos($s, 'FARMEX CORP') !== false) return 'FARMEX';
    return '';
}

// Ensure department and company are in session
if (!isset($_SESSION['department']) || !isset($_SESSION['company'])) {
    $u_stmt = $conn->prepare("SELECT department, company FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $_SESSION['user_id']);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    if ($u_row = $u_res->fetch_assoc()) {
        $_SESSION['department'] = $u_row['department'];
        $_SESSION['company'] = $u_row['company'];
    }
}

if (!isset($_SESSION['email']) || trim((string) $_SESSION['email']) === '') {
    $e_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    if ($e_stmt) {
        $e_stmt->bind_param("i", $_SESSION['user_id']);
        $e_stmt->execute();
        $e_res = $e_stmt->get_result();
        if ($e_row = $e_res->fetch_assoc()) {
            $_SESSION['email'] = (string) ($e_row['email'] ?? '');
        }
        $e_stmt->close();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();
    $responseFlushed = false;

    ticket_ensure_assignment_columns($conn);
    notif_ensure_action_type_column($conn);

    if (!isset($_POST['id'])) {
        header("Location: my_task.php");
        exit();
    }

    $id = (int) $_POST['id'];
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $new_department = isset($_POST['assigned_department']) ? trim($_POST['assigned_department']) : '';
    $new_company = isset($_POST['assigned_company']) ? trim($_POST['assigned_company']) : '';
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : null;

    // --- PERMISSION CHECK ---
    // Employee can only update tickets assigned to their department AND company
    $check_stmt = $conn->prepare("SELECT user_id, status, assigned_department, assigned_group, assigned_company, assigned_user_id, assigned_to, admin_note, company FROM employee_tickets WHERE id = ?");
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();
    $old_data = $check_res->fetch_assoc();
    $check_stmt->close();

    if (!$old_data) {
        header("Location: my_task.php?error=notfound");
        exit();
    }

    $assigneeOk = isset($old_data['assigned_user_id']) && (int) $old_data['assigned_user_id'] === (int) $_SESSION['user_id'];
    $ticketAssignedCompany = (string) (!empty($old_data['assigned_company']) ? $old_data['assigned_company'] : ($old_data['company'] ?? ''));
    $ticketCompanyCode = company_code($ticketAssignedCompany);
    $userCompanyCode = company_code((string) ($_SESSION['company'] ?? ''));
    $userEmail = strtolower(trim((string) ($_SESSION['email'] ?? '')));
    if (strpos($ticketAssignedCompany, '@') === 0) {
        $ticketDomain = strtolower(ltrim($ticketAssignedCompany, '@'));
        $companyOk = ($ticketDomain !== '' && $userEmail !== '' && str_ends_with($userEmail, '@' . $ticketDomain));
    } else {
        $companyOk = ($ticketCompanyCode !== '' && $userCompanyCode !== '' && $ticketCompanyCode === $userCompanyCode)
            || ($ticketAssignedCompany === (string) ($_SESSION['company'] ?? ''));
    }
    $ticketGroup = (string) ($old_data['assigned_group'] ?? ($old_data['assigned_department'] ?? ''));
    $groupOk = $ticketGroup !== '' && $ticketGroup === (string) ($_SESSION['department'] ?? '');
    $requiresGroupMatch = ((string) ($_SESSION['department'] ?? '')) !== '';
    if (!$assigneeOk && (!$companyOk || ($requiresGroupMatch && !$groupOk))) {
        header("Location: my_task.php?error=unauthorized");
        exit();
    }

    // Normalize and validate status
    // Temporarily deactivate "Closed" status updates.
    $allowed_statuses = ['Open', 'In Progress', 'Resolved'];
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $old_data['status'];
    }
    
    $oldStatus = (string) ($old_data['status'] ?? '');
    $oldCompany = ticket_normalize_company((string) (($old_data['assigned_company'] ?? '') !== '' ? $old_data['assigned_company'] : ($old_data['company'] ?? '')));
    $normalizeGroupForCompany = static function (string $group, string $company): string {
        $group = trim($group);
        $company = ticket_normalize_company($company);
        if ($company === '@leadsagri.com' || strtoupper($company) === 'LAPC') {
            return $group;
        }
        return '';
    };
    $oldDeptRaw = (string) ($old_data['assigned_group'] ?? ($old_data['assigned_department'] ?? ''));
    $oldDept = $normalizeGroupForCompany($oldDeptRaw, $oldCompany);
    $oldNote = (string) ($old_data['admin_note'] ?? '');
    $oldAssignedUserId = isset($old_data['assigned_user_id']) ? (int) $old_data['assigned_user_id'] : 0;

    if (empty($new_company)) {
        $new_company = (string) (($old_data['assigned_company'] ?? '') !== '' ? $old_data['assigned_company'] : ($old_data['company'] ?? ''));
    }
    $new_company = ticket_normalize_company((string) $new_company);
    $new_company_requires_department = ($new_company === '@leadsagri.com' || strtoupper($new_company) === 'LAPC');
    if (empty($new_department)) {
        $new_department = $new_company_requires_department ? $oldDeptRaw : '';
    }
    $new_department = $normalizeGroupForCompany($new_department, $new_company);
    $new_group = $new_department;

    $newNoteNorm = (string) ($admin_note ?? '');
    $assignmentChanged = ($new_company !== $oldCompany) || ($new_department !== $oldDept);
    $requesterAssignmentChanged = $assignmentChanged;
    $currentHandlerUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($new_status === $oldStatus && $new_company === $oldCompany && $new_department === $oldDept && trim($newNoteNorm) === trim($oldNote)) {
        $_SESSION['success'] = "No changes were made.";
        header("Location: my_task.php");
        exit();
    }

    $assigned_user_ids = [];
    $assigned_user_id = $oldAssignedUserId > 0 ? $oldAssignedUserId : null;
    $assigned_to = isset($old_data['assigned_to']) ? (int) $old_data['assigned_to'] : null;
    if ($assignmentChanged) {
        if ($new_company === '' || !ticket_is_valid_company($new_company) || ($new_company_requires_department && !ticket_is_valid_group_for_company($new_company, $new_group))) {
            $_SESSION['error'] = 'Invalid company/group selection.';
            header("Location: my_task.php");
            exit();
        }

        $assigned_user_ids = ticket_find_assignee_ids($conn, $new_company, $new_group);
        $assigned_user_id = count($assigned_user_ids) > 0 ? (int) $assigned_user_ids[0] : null;
        if (!$assigned_user_id) {
            $_SESSION['error'] = $new_company_requires_department
                ? 'No assignee available for the selected company and group.'
                : 'No assignee available for the selected recipient.';
            header("Location: my_task.php");
            exit();
        }
        $assigned_to = null;
    }
    if ($new_status === 'Open') {
        $assigned_to = null;
    } else {
        if ($currentHandlerUserId > 0) {
            $assigned_to = $currentHandlerUserId;
        } elseif ((int) $assigned_to <= 0 && (int) $assigned_user_id > 0) {
            $assigned_to = (int) $assigned_user_id;
        }
    }

    // Update ticket
    $update = $conn->prepare("
        UPDATE employee_tickets
        SET 
            status = ?,
            feedback_status = CASE
                WHEN ? = 'Resolved' THEN 'pending'
                ELSE NULL
            END,
            assigned_department = ?,
            assigned_company = ?,
            assigned_group = ?,
            assigned_user_id = ?,
            assigned_to = ?,
            admin_note = ?,
            is_read = 1,
            updated_at = NOW(),
            resolved_at = CASE
                WHEN ? = 'Resolved' AND resolved_at IS NULL THEN NOW()
                WHEN ? = 'Open' THEN NULL
                ELSE resolved_at
            END
        WHERE id = ?
    ");

    if (!$update) {
        error_log('update_ticket.php prepare failed: ' . $conn->error);
        $_SESSION['error'] = 'Unable to update the ticket right now. (' . $conn->error . ')';
        header("Location: my_task.php");
        exit();
    }

    $update->bind_param("sssssiisssi", $new_status, $new_status, $new_department, $new_company, $new_group, $assigned_user_id, $assigned_to, $admin_note, $new_status, $new_status, $id);

    $updateOk = false;
    $updateError = '';
    try {
        $updateOk = $update->execute();
        if (!$updateOk) {
            $updateError = (string) $update->error;
        }
    } catch (Throwable $execEx) {
        $updateError = $execEx->getMessage();
    }

    if (!$updateOk) {
        error_log('update_ticket.php execute failed: ' . $updateError);
        $update->close();
        $_SESSION['error'] = 'Unable to update the ticket right now. (' . $updateError . ')';
        header("Location: my_task.php");
        exit();
    }

    if ($updateOk) {
        $_SESSION['task_success'] = "Ticket #$id successfully updated.";

        // Flush the redirect to the browser as early as possible so any
        // downstream notification/email failure cannot close the connection
        // before the response is delivered (NS_ERROR_NET_ERROR_RESPONSE).
        $update->close();
        finish_ticket_update_response("my_task.php");
        $responseFlushed = true;

        try {

        // --- TICKET ACTIVITY LOG ---
        // Status change
        if ($old_data['status'] !== $new_status) {
            $activity_desc = "Status changed to " . $new_status . " by " . $_SESSION['department'];
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }
        
        // Department reassignment
        if ($old_data['assigned_department'] !== $new_department) {
            $activity_desc = "Reassigned from " . $old_data['assigned_department'] . " to " . $new_department;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'department_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // Company reassignment
        if ($old_data['assigned_company'] !== $new_company) {
            $activity_desc = "Reassigned from company " . $old_data['assigned_company'] . " to " . $new_company;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'company_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // Note added
        if (!empty($admin_note) && $admin_note !== $old_data['admin_note']) {
            $activity_desc = $_SESSION['department'] . " added a note";
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'note_added', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // --- INSERT NOTIFICATIONS ---
        $notif_user_id = (int) ($old_data['user_id'] ?? 0);
        $statusChanged = (string) ($old_data['status'] ?? '') !== (string) $new_status;
        $noteChanged = !empty($admin_note) && (string) $admin_note !== (string) ($old_data['admin_note'] ?? '');

        $requesterNotification = null;

        if ($requesterAssignmentChanged) {
            $hadPreviousAssignment = $oldAssignedUserId > 0 || $oldCompany !== '' || $oldDept !== '';
            $assignmentActionType = $hadPreviousAssignment ? 'reassign' : 'assign';
            $notifyTargetLabel = notif_assignment_target_label((string) $new_company, (string) $new_department, 'the selected recipient');
            $adminAssignmentMessage = $assignmentActionType === 'assign'
                ? "Ticket #$id was assigned to $notifyTargetLabel."
                : "Ticket #$id was reassigned to $notifyTargetLabel.";
            $requesterNotification = [
                'msg' => $assignmentActionType === 'assign'
                    ? "Your ticket #$id was assigned to $notifyTargetLabel."
                    : "Your ticket #$id was reassigned to $notifyTargetLabel.",
                'type' => 'reassigned',
                'action_type' => $assignmentActionType
            ];
            notif_insert_admins(
                $conn,
                (int) $id,
                $adminAssignmentMessage,
                'reassigned',
                $assignmentActionType,
                $assignmentActionType === 'assign' ? 'Ticket Assigned' : 'Ticket Reassigned'
            );

            foreach ($assigned_user_ids as $notifyUserId) {
                $notifyUserId = (int) $notifyUserId;
                if ($notifyUserId <= 0) continue;
                $assigneeMessage = $assignmentActionType === 'assign'
                    ? "New ticket #$id was assigned to your group by " . $_SESSION['department'] . "."
                    : "The ticket #$id was reassigned to $notifyTargetLabel.";
                notif_insert_system($conn, $notifyUserId, (int) $id, $assigneeMessage, 'dept_assigned', 10, $assignmentActionType);
            }
        } elseif ($noteChanged) {
            $requesterNotification = [
                'msg' => "A private note was added to ticket #$id.",
                'type' => 'note_added',
                'action_type' => 'update'
            ];
        }

        if ($notif_user_id > 0 && is_array($requesterNotification)) {
            notif_insert_system(
                $conn,
                $notif_user_id,
                (int) $id,
                (string) $requesterNotification['msg'],
                (string) $requesterNotification['type'],
                15,
                (string) $requesterNotification['action_type']
            );
        }

        $ticket = notif_ticket_data($conn, $id);

        if ($ticket) {
            $ticketNumber = str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $ticketSubject = (string) ($ticket['subject'] ?? '');
            $ticketCategory = (string) ($ticket['category'] ?? '');
            $ticketDescription = trim((string) ($ticket['description'] ?? ''));
            $ticketPriority = (string) ($ticket['priority'] ?? '');
            $requesterName = (string) ($ticket['creator_name'] ?? 'Requester');
            $requesterEmail = trim((string) ($ticket['creator_email'] ?? ''));
            $currentAssignedCompany = ticket_normalize_company((string) ($ticket['assigned_company'] ?? $new_company));
            $currentAssignedGroup = trim((string) ($ticket['assigned_group'] ?? ($ticket['assigned_department'] ?? $new_department)));
            $assignedCompanyDisplay = ticket_company_display_name($currentAssignedCompany);
            $isLapcAssignment = ($currentAssignedCompany === '@leadsagri.com' || strtoupper($currentAssignedCompany) === 'LAPC');
            $assignedTargetLabel = $assignedCompanyDisplay;
            if ($isLapcAssignment && $currentAssignedGroup !== '') {
                $assignedTargetLabel = $currentAssignedGroup . ($assignedCompanyDisplay !== '' ? " ($assignedCompanyDisplay)" : '');
            } elseif ($assignedTargetLabel === '' && $currentAssignedGroup !== '') {
                $assignedTargetLabel = $currentAssignedGroup;
            }

            $ticketSubjectSafe = htmlspecialchars($ticketSubject);
            $prioritySafe = htmlspecialchars($ticketPriority);
            $requesterNameSafe = htmlspecialchars($requesterName);
            $attachments = notif_ticket_email_attachments($conn, $id, (string) ($ticket['attachment'] ?? ''));
            $currentAssignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
            $assigneeIdsForEmail = $currentAssignedUserId > 0 ? [$currentAssignedUserId] : [];
            $assigneeEmails = ticket_assignee_notification_emails($conn, $assigneeIdsForEmail, $currentAssignedCompany, $currentAssignedGroup, (int) ($ticket['user_id'] ?? 0));
            $updateSourceLabel = trim((string) ($_SESSION['department'] ?? 'Employee'));
            $notePreview = trim((string) ($admin_note ?? ''));
            if (strlen($notePreview) > 400) {
                $notePreview = substr($notePreview, 0, 400) . '...';
            }

            $sharedUpdateLines = [];
            if ($ticketCategory !== '') {
                $sharedUpdateLines[] = 'Category: ' . $ticketCategory;
            }
            if ($ticketDescription !== '') {
                $sharedUpdateLines[] = "Description:\n" . $ticketDescription;
            }
            if ($ticketPriority !== '') {
                $sharedUpdateLines[] = 'Priority: ' . $ticketPriority;
            }
            $sharedUpdateLines[] = 'Current status: ' . $new_status;
            if ($noteChanged && $notePreview !== '') {
                $sharedUpdateLines[] = "Note from $updateSourceLabel:\n$notePreview";
            }

            if ($statusChanged) {
                notif_send_ticket_status_update(
                    $conn,
                    (int) $id,
                    (string) ($old_data['status'] ?? ''),
                    (string) $new_status,
                    $updateSourceLabel,
                    [
                        'attachments' => $attachments,
                        'assignee_emails' => $assigneeEmails,
                        'extra_lines' => $sharedUpdateLines,
                    ]
                );
            } else {
                if ($requesterEmail !== '') {
                    $requesterLines = array_merge([
                        "Ticket has been updated.",
                        "Ticket ID: #$ticketNumber",
                        "Subject: $ticketSubject",
                    ], $sharedUpdateLines);
                    $requesterTpl = notif_email_simple('Ticket Updated', $requesterLines, 'View Ticket', notif_ticket_link_employee_tickets($id));
                    if (!notif_email_send([$requesterEmail], "Ticket Update (#$ticketNumber)", (string) ($requesterTpl['html'] ?? ''), (string) ($requesterTpl['text'] ?? ''), $attachments)) {
                        error_log('Ticket update email failed (requester) | ticketId=' . (string) $id);
                    }
                }

                if (count($assigneeEmails) > 0) {
                    $assigneeLines = [
                        "Ticket has been updated.",
                        "Ticket ID: #$ticketNumber",
                        "Subject: $ticketSubject",
                        "Requester: $requesterName",
                    ];
                    if ($requesterEmail !== '') {
                        $assigneeLines[] = 'Requester Email: ' . $requesterEmail;
                    }
                    $assigneeLines = array_merge($assigneeLines, $sharedUpdateLines);
                    $assigneeTpl = notif_email_simple('Ticket Updated', $assigneeLines, 'View Task', notif_ticket_link_employee_tasks($id));
                    if (!notif_email_send($assigneeEmails, "Ticket Update (#$ticketNumber)", (string) ($assigneeTpl['html'] ?? ''), (string) ($assigneeTpl['text'] ?? ''), $attachments)) {
                        error_log('Ticket update email failed (assignee) | ticketId=' . (string) $id);
                    }
                }
            }

            $adminEmails = [];
            $targetDept = $currentAssignedGroup !== '' ? $currentAssignedGroup : (string) ($ticket['assigned_department'] ?? '');
            if ($targetDept !== '') {
                $adminStmt = $conn->prepare("SELECT email FROM users WHERE role = 'admin' AND email <> '' AND (department = ? OR department IS NULL OR department = '')");
                if ($adminStmt) {
                    $adminStmt->bind_param("s", $targetDept);
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

            $statusChanged = $old_data && isset($old_data['status']) && $old_data['status'] !== $new_status;
            $deptChanged = $requesterAssignmentChanged;
            $noteChanged = $old_data && array_key_exists('admin_note', $old_data) && ((string) ($old_data['admin_note'] ?? '') !== (string) ($admin_note ?? ''));

            if ($deptChanged) {
                $adminSubject = "New Ticket Assigned (#$ticketNumber)";
                $adminBodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>A support ticket has been assigned to your department.</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Subject: <strong>{$ticketSubjectSafe}</strong><br>
                            Priority: <strong>{$prioritySafe}</strong><br>
                            Requested by: <strong>{$requesterNameSafe}</strong><br>
                            Assigned to: <strong>" . htmlspecialchars($assignedTargetLabel) . "</strong>
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";
                $adminBodyText = "A support ticket has been assigned to your department.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: $ticketSubject\n"
                    . "Priority: $ticketPriority\n"
                    . "Requested by: $requesterName\n"
                    . "Assigned to: $assignedTargetLabel\n\n"
                    . "Login to the system to view the ticket.\n";

                $adminOk = sendSmtpEmail($adminEmails, $adminSubject, $adminBodyHtml, $adminBodyText);
                if (!$adminOk) {
                    error_log('Ticket update email failed (admins dept reassignment) | ticketId=' . (string) $id);
                }
            }

            if ($statusChanged) {
                $adminSubject = "Ticket Status Updated (#$ticketNumber)";
                $adminBodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>A ticket status has changed.</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Subject: <strong>{$ticketSubjectSafe}</strong><br>
                            Priority: <strong>{$prioritySafe}</strong><br>
                            Requested by: <strong>{$requesterNameSafe}</strong><br>
                            Status: <strong>" . htmlspecialchars((string) ($old_data['status'] ?? '')) . "</strong> to <strong>" . htmlspecialchars($new_status) . "</strong>
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";
                $adminBodyText = "A ticket status has changed.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: $ticketSubject\n"
                    . "Priority: $ticketPriority\n"
                    . "Requested by: $requesterName\n"
                    . "Status: " . (string) ($old_data['status'] ?? '') . " -> $new_status\n\n"
                    . "Login to the system to view the ticket.\n";

                $adminOk = sendSmtpEmail($adminEmails, $adminSubject, $adminBodyHtml, $adminBodyText);
                if (!$adminOk) {
                    error_log('Ticket update email failed (admins status change) | ticketId=' . (string) $id);
                }
            }

            if ($noteChanged) {
                $adminSubject = "Ticket Note Updated (#$ticketNumber)";
                $notePreview = (string) ($admin_note ?? '');
                $notePreview = strlen($notePreview) > 400 ? (substr($notePreview, 0, 400) . '...') : $notePreview;
                $notePreviewSafe = nl2br(htmlspecialchars($notePreview));
                $noteFromSafe = htmlspecialchars((string) ($_SESSION['department'] ?? ''));

                $adminBodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>Ticket Note Updated</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Subject: <strong>{$ticketSubjectSafe}</strong><br>
                            Priority: <strong>{$prioritySafe}</strong><br>
                            Requested by: <strong>{$requesterNameSafe}</strong><br>
                            Assigned to: <strong>" . htmlspecialchars($assignedTargetLabel) . "</strong><br>
                            Note from: <strong>$noteFromSafe</strong>
                        </p>
                        <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:0 0 16px 0'>
                            $notePreviewSafe
                        </div>
                        <p style='margin:0'>Login to the system to view and reply.</p>
                    </div>
                ";
                $adminBodyText = "Ticket Note Updated\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: $ticketSubject\n"
                    . "Priority: $ticketPriority\n"
                    . "Requested by: $requesterName\n"
                    . "Assigned to: $assignedTargetLabel\n"
                    . "Note from: " . (string) ($_SESSION['department'] ?? '') . "\n\n"
                    . $notePreview . "\n\n"
                    . "Login to the system to view and reply.\n";

                $adminOk = sendSmtpEmail($adminEmails, $adminSubject, $adminBodyHtml, $adminBodyText);
                if (!$adminOk) {
                    error_log('Ticket update email failed (admins note change) | ticketId=' . (string) $id);
                }
            }
        }

        } catch (Throwable $postUpdateEx) {
            error_log('update_ticket.php post-update side-effects failed: ' . $postUpdateEx->getMessage());
        }
    }

    if ($responseFlushed) {
        exit();
    }
    header("Location: my_task.php");
    exit();
}

header("Location: my_task.php");
exit();
?>
