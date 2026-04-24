<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    ticket_ensure_assignment_columns($conn);
    notif_ensure_action_type_column($conn);

    if (!isset($_POST['id'])) {
        // Redirect if ID is missing
        header("Location: all_tickets.php");
        exit();
    }

    $id = (int) $_POST['id'];
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $new_department = isset($_POST['assigned_department']) ? trim($_POST['assigned_department']) : '';
    $new_company = isset($_POST['assigned_company']) ? trim($_POST['assigned_company']) : '';
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : null;

    if (isset($_GET['debug_status'])) {
        var_dump($_POST['status']);
        exit();
    }

    // --- FETCH OLD DATA FOR COMPARISON & NOTIFICATIONS ---
    $old_stmt = $conn->prepare("SELECT user_id, status, assigned_department, assigned_company, assigned_group, assigned_user_id, assigned_to, company, admin_note FROM employee_tickets WHERE id = ?");
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_res = $old_stmt->get_result();
    $old_data = $old_res->fetch_assoc();
    $old_stmt->close();
    if (!$old_data) {
        header("Location: all_tickets.php");
        exit();
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

    $effective_company = $new_company;
    if ($effective_company === '') {
        $effective_company = (string) (($old_data['assigned_company'] ?? '') !== '' ? $old_data['assigned_company'] : ($old_data['company'] ?? ''));
    }
    $effective_company = ticket_normalize_company($effective_company);
    $effective_company_requires_department = ($effective_company === '@leadsagri.com' || strtoupper($effective_company) === 'LAPC');

    $effective_group = $new_department !== '' ? $new_department : ($effective_company_requires_department ? $oldDeptRaw : '');
    $effective_group = $normalizeGroupForCompany($effective_group, $effective_company);

    // Normalize and validate status, prevent blank status
    $allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $old_data && isset($old_data['status']) ? $old_data['status'] : 'Open';
    }
    if ($new_department === '') {
        $new_department = $effective_group;
    }
    $new_department = $normalizeGroupForCompany($new_department, $effective_company);

    $newCompanyNorm = ticket_normalize_company((string) $effective_company);
    $newDeptNorm = $normalizeGroupForCompany((string) $new_department, $newCompanyNorm);
    $newNoteNorm = (string) ($admin_note ?? '');
    $assigned_user_id = $oldAssignedUserId > 0 ? $oldAssignedUserId : null;
    $assigned_to = isset($old_data['assigned_to']) ? (int) $old_data['assigned_to'] : null;
    $assignmentChanged = ($newCompanyNorm !== $oldCompany) || ($newDeptNorm !== $oldDept);
    if ($assignmentChanged && $newCompanyNorm !== '') {
        if (!ticket_is_valid_company($newCompanyNorm) || ($effective_company_requires_department && !ticket_is_valid_group_for_company($newCompanyNorm, $newDeptNorm))) {
            $_SESSION['error'] = 'Invalid company/group selection.';
            header("Location: all_tickets.php");
            exit();
        }
        $assigneeIds = ticket_find_assignee_ids($conn, $newCompanyNorm, $newDeptNorm);
        $assignee = count($assigneeIds) > 0 ? (int) $assigneeIds[0] : null;
        if (!$assignee) {
            $_SESSION['error'] = $effective_company_requires_department
                ? 'No assignee available for the selected company and group.'
                : 'No assignee available for the selected recipient.';
            header("Location: all_tickets.php");
            exit();
        }
        $assigned_user_id = $assignee;
    }
    if ($assignmentChanged) {
        $assigned_user_id = $assigned_user_id ?: $oldAssignedUserId;
    }
    $assignmentChanged = $assignmentChanged || ((int) $assigned_user_id !== $oldAssignedUserId);
    $requesterAssignmentChanged = $assignmentChanged;
    if ($assignmentChanged) {
        $assigned_to = null;
    }
    if ($new_status === 'Open') {
        $assigned_to = null;
    } elseif ($new_status === 'In Progress' && (int) $assigned_to <= 0) {
        if ((int) $assigned_user_id <= 0) {
            $assigned_user_id = (int) ($_SESSION['user_id'] ?? 0);
        }
        if ((int) $assigned_user_id > 0) {
            $assigned_to = (int) $assigned_user_id;
        }
    }
    if ($new_status === $oldStatus && $newCompanyNorm === $oldCompany && $newDeptNorm === $oldDept && trim($newNoteNorm) === trim($oldNote)) {
        $_SESSION['success'] = "No changes were made.";
        header("Location: all_tickets.php");
        exit();
    }

    // Update status, department, admin_note and mark as read
    // Also update resolved_at if status is Resolved or Closed AND it hasn't been set yet
    $update = $conn->prepare("
        UPDATE employee_tickets
        SET 
            status = ?, 
            assigned_department = ?, 
            assigned_company = ?,
            assigned_group = ?,
            assigned_user_id = ?,
            assigned_to = ?,
            admin_note = ?,
            is_read = 1, 
            updated_at = NOW(),
            resolved_at = CASE 
                WHEN (? = 'Resolved' OR ? = 'Closed') AND resolved_at IS NULL THEN NOW() 
                WHEN ? = 'Open' THEN NULL
                ELSE resolved_at 
            END
        WHERE id = ?
    ");
    
    $update->bind_param("ssssiissssi", $new_status, $newDeptNorm, $newCompanyNorm, $effective_group, $assigned_user_id, $assigned_to, $admin_note, $new_status, $new_status, $new_status, $id);
    
    if ($update->execute()) {
        $_SESSION['success'] = "Ticket #$id successfully updated.";

        // --- TICKET ACTIVITY LOG: Status change ---
        if ($old_data && isset($old_data['status']) && $old_data['status'] !== $new_status) {
            $activity_desc = "Status changed to " . $new_status;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }
        // Optional explicit close activity
        if ($new_status === 'Closed') {
            $act2 = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', 'Ticket closed', NOW())");
            if ($act2) {
                $act2->bind_param("i", $id);
                $act2->execute();
                $act2->close();
            }
        }

        // --- INSERT NOTIFICATIONS ---
        if ($old_data) {
            $notif_user_id = (int) ($old_data['user_id'] ?? 0);
            $statusChanged = (string) ($old_data['status'] ?? '') !== (string) $new_status;
            $noteChanged = !empty($admin_note) && (string) $admin_note !== (string) ($old_data['admin_note'] ?? '');

            $requesterNotification = null;

            if ($requesterAssignmentChanged) {
                $hadPreviousAssignment = $oldAssignedUserId > 0 || $oldCompany !== '' || $oldDept !== '';
                $assignmentActionType = $hadPreviousAssignment ? 'reassign' : 'assign';
                $notifyTargetLabel = notif_assignment_target_label((string) $newCompanyNorm, (string) $newDeptNorm, 'the selected recipient');
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

                foreach (($assigneeIds ?? []) as $notifyUserId) {
                    $notifyUserId = (int) $notifyUserId;
                    if ($notifyUserId <= 0) continue;
                    $assigneeMessage = $assignmentActionType === 'assign'
                        ? "New ticket #$id was assigned to your group."
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
            $currentAssignedCompany = ticket_normalize_company((string) ($ticket['assigned_company'] ?? $effective_company));
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
            $updateSourceLabel = trim((string) ($_SESSION['department'] ?? 'Admin'));
            if ($updateSourceLabel === '') {
                $updateSourceLabel = 'Admin';
            }
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

                $adminBodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>Ticket Note Updated</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Subject: <strong>{$ticketSubjectSafe}</strong><br>
                            Priority: <strong>{$prioritySafe}</strong><br>
                            Requested by: <strong>{$requesterNameSafe}</strong><br>
                            Assigned to: <strong>" . htmlspecialchars($assignedTargetLabel) . "</strong>
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
                    . "Assigned to: $assignedTargetLabel\n\n"
                    . $notePreview . "\n\n"
                    . "Login to the system to view and reply.\n";

                $adminOk = sendSmtpEmail($adminEmails, $adminSubject, $adminBodyHtml, $adminBodyText);
                if (!$adminOk) {
                    error_log('Ticket update email failed (admins note change) | ticketId=' . (string) $id);
                }
            }
        }
    }
    
    $update->close();

    header("Location: all_tickets.php");
    exit();
}

// If accessed directly via GET, redirect back to all tickets
header("Location: all_tickets.php");
exit();
?>
