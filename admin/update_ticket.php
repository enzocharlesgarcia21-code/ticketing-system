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
ticket_ensure_chat_tables($conn);
    ticket_ensure_activity_table($conn);
    notif_ensure_action_type_column($conn);
    notif_ensure_requester_identity_columns($conn);

    if (!isset($_POST['id'])) {
        // Redirect if ID is missing
        header("Location: all_tickets.php");
        exit();
    }

    $id = (int) $_POST['id'];
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $new_department = isset($_POST['assigned_department']) ? trim($_POST['assigned_department']) : '';
    $new_company = isset($_POST['assigned_company']) ? trim($_POST['assigned_company']) : '';
    $requested_assigned_user_id = isset($_POST['assigned_user_id']) ? (int) $_POST['assigned_user_id'] : 0;
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : null;

    if (isset($_GET['debug_status'])) {
        var_dump($_POST['status']);
        exit();
    }

    // --- FETCH OLD DATA FOR COMPARISON & NOTIFICATIONS ---
    // Try with all optional columns first; fall back to minimal set if columns are missing.
    $old_stmt = $conn->prepare("SELECT user_id, requester_email, status, assigned_department, assigned_company, assigned_group, assigned_user_id, assigned_to, company, admin_note, hold_started_at FROM employee_tickets WHERE id = ?");
    if (!$old_stmt) {
        // Some optional columns may not exist yet — retry with minimal set
        $old_stmt = $conn->prepare("SELECT user_id, NULL AS requester_email, status, assigned_department, NULL AS assigned_company, NULL AS assigned_group, NULL AS assigned_user_id, NULL AS assigned_to, company, admin_note, NULL AS hold_started_at FROM employee_tickets WHERE id = ?");
        if (!$old_stmt) {
            error_log('admin/update_ticket.php: old_stmt prepare failed: ' . $conn->error);
            header("Location: all_tickets.php");
            exit();
        }
    }
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_res = $old_stmt->get_result();
    $old_data = $old_res->fetch_assoc();
    $old_stmt->close();
    if (!$old_data) {
        header("Location: all_tickets.php");
        exit();
    }

    if (trim((string) ($old_data['hold_started_at'] ?? '')) !== '') {
        $_SESSION['error'] = 'The ticket is on hold. Its assignee must resume it before it can be updated or reassigned.';
        header("Location: all_tickets.php");
        exit();
    }

    if (trim((string) ($admin_note ?? '')) === '') {
        $_SESSION['error'] = 'Action Taken/Comments is required before saving the ticket.';
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
    $effective_company_requires_department = ticket_company_requires_department($effective_company);

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
    $assigneeIds = [];
    $availableDepartmentUsers = [];
    if ($newCompanyNorm !== '' && (!$effective_company_requires_department || $newDeptNorm !== '')) {
        $availableDepartmentUsers = ticket_find_department_user_options($conn, $newCompanyNorm, $newDeptNorm);
    }
    $availableDepartmentUserIds = array_values(array_filter(array_map(static function ($userRow) {
        return (int) ($userRow['id'] ?? 0);
    }, $availableDepartmentUsers), static function ($userId) {
        return $userId > 0;
    }));
    $assignmentChanged = ($newCompanyNorm !== $oldCompany) || ($newDeptNorm !== $oldDept);
    $requestedAssigneeMatchesOld = ($requested_assigned_user_id <= 0 || $requested_assigned_user_id === $oldAssignedUserId);
    if ($assignmentChanged && $newCompanyNorm !== '') {
        if (!ticket_is_valid_company($newCompanyNorm) || ($effective_company_requires_department && !ticket_is_valid_group_for_company($newCompanyNorm, $newDeptNorm))) {
            $_SESSION['error'] = 'Invalid company/group selection.';
            header("Location: all_tickets.php");
            exit();
        }
        $assigneeIds = ticket_find_assignee_ids($conn, $newCompanyNorm, $newDeptNorm);
        if (count($assigneeIds) === 0) {
            $_SESSION['error'] = $effective_company_requires_department
                ? 'No assignee available for the selected company and group.'
                : 'No assignee available for the selected recipient.';
            header("Location: all_tickets.php");
            exit();
        }
        $assigned_user_id = null;
        if ($requested_assigned_user_id > 0 && in_array($requested_assigned_user_id, $availableDepartmentUserIds, true)) {
            $assigned_user_id = $requested_assigned_user_id;
            $assigneeIds = [$requested_assigned_user_id];
        }
    } elseif ($requested_assigned_user_id > 0) {
        if (!in_array($requested_assigned_user_id, $availableDepartmentUserIds, true)) {
            $_SESSION['error'] = 'Invalid department user selected.';
            header("Location: all_tickets.php");
            exit();
        }
        $assigned_user_id = $requested_assigned_user_id;
        $assigneeIds = [$requested_assigned_user_id];
    }
    $explicitUserAssignmentChanged = !$assignmentChanged
        && $requested_assigned_user_id > 0
        && $requested_assigned_user_id !== $oldAssignedUserId;
    $requesterAssignmentChanged = $assignmentChanged || $explicitUserAssignmentChanged;
    if ($assignmentChanged || $explicitUserAssignmentChanged) {
        $assigned_to = null;
    }
    if ($new_status === 'Open') {
        $assigned_to = null;
    }
    if ($new_status === $oldStatus && $newCompanyNorm === $oldCompany && $newDeptNorm === $oldDept && trim($newNoteNorm) === trim($oldNote) && $requestedAssigneeMatchesOld) {
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

    if (!$update) {
        error_log('admin/update_ticket.php: UPDATE prepare failed: ' . $conn->error);
        header("Location: all_tickets.php");
        exit();
    }

    $update->bind_param("ssssiissssi", $new_status, $newDeptNorm, $newCompanyNorm, $effective_group, $assigned_user_id, $assigned_to, $admin_note, $new_status, $new_status, $new_status, $id);
    
    if ($update->execute()) {
        if ($requesterAssignmentChanged) {
            ticket_chat_rotate_thread($conn, (int) $id);
        }
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
        if ($oldDept !== $newDeptNorm) {
            $activity_desc = "Reassigned from " . ($oldDept !== '' ? $oldDept : 'Unassigned') . " to " . ($newDeptNorm !== '' ? $newDeptNorm : 'Unassigned');
            if ((int) $assigned_user_id > 0) {
                $assigneeName = '';
                $assigneeStmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
                if ($assigneeStmt) {
                    $assigneeStmt->bind_param("i", $assigned_user_id);
                    $assigneeStmt->execute();
                    $assigneeRes = $assigneeStmt->get_result();
                    $assigneeRow = $assigneeRes ? $assigneeRes->fetch_assoc() : null;
                    $assigneeStmt->close();
                    $assigneeName = trim((string) ($assigneeRow['name'] ?? ''));
                }
                if ($assigneeName !== '') {
                    $activity_desc .= " | Handled by: " . $assigneeName;
                }
            }
            $actDept = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'department_change', ?, NOW())");
            if ($actDept) {
                $actDept->bind_param("is", $id, $activity_desc);
                $actDept->execute();
                $actDept->close();
            }
        }
        if ($oldCompany !== $newCompanyNorm) {
            $activity_desc = "Reassigned from company " . ($oldCompany !== '' ? $oldCompany : 'Unassigned') . " to " . ($newCompanyNorm !== '' ? $newCompanyNorm : 'Unassigned');
            $actCompany = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'company_change', ?, NOW())");
            if ($actCompany) {
                $actCompany->bind_param("is", $id, $activity_desc);
                $actCompany->execute();
                $actCompany->close();
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
            $notif_user_id = notif_requester_user_id($conn, $old_data);
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
            $updateSourceLabel = ticket_activity_actor_label($conn, (int) ($_SESSION['user_id'] ?? 0), $_SESSION);
            if ($updateSourceLabel === '') {
                $updateSourceLabel = 'Admin';
            }
            $notePreview = trim((string) ($admin_note ?? ''));

            $sharedUpdateLines = [];
            if ($ticketCategory !== '') {
                $sharedUpdateLines[] = 'Category: ' . $ticketCategory;
            }
            $sharedUpdateLines[] = "Description:\n" . ($ticketDescription !== '' ? ticket_email_description_for_notification($ticketDescription) : '-');
            $sharedUpdateLines[] = "Action Taken/Comments:\n" . $notePreview;
            if ($ticketPriority !== '') {
                $sharedUpdateLines[] = 'Priority: ' . $ticketPriority;
            }
            $sharedUpdateLines[] = 'Current status: ' . $new_status;
            if ($requesterAssignmentChanged) {
                $oldAssignedTargetLabel = notif_assignment_email_label((string) $oldCompany, (string) $oldDept, 'Unassigned');
                if ($oldAssignedTargetLabel !== '') {
                    $sharedUpdateLines[] = 'Reassigned From: ' . $oldAssignedTargetLabel;
                }
                $newAssignedTargetLabel = notif_assignment_email_label((string) $newCompanyNorm, (string) $newDeptNorm, '');
                if ($newAssignedTargetLabel === '') {
                    $newAssignedTargetLabel = notif_assignment_email_label((string) $currentAssignedCompany, (string) $currentAssignedGroup, '');
                }
                if ($newAssignedTargetLabel !== '') {
                    $sharedUpdateLines[] = 'Reassigned To: ' . $newAssignedTargetLabel;
                }
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
                    $requesterEmailTitle = 'Ticket Updated';
                    if ($requesterAssignmentChanged) {
                        $requesterEmailTitle = ($oldAssignedUserId > 0 || $oldCompany !== '' || $oldDept !== '') ? 'Ticket Reassigned' : 'Ticket Assigned';
                    }
                    $requesterLines = array_merge([
                        "Ticket has been updated.",
                        "Ticket ID: #$ticketNumber",
                        "Subject: $ticketSubject",
                    ], $sharedUpdateLines);
                    $requesterTpl = notif_email_simple($requesterEmailTitle, $requesterLines, 'View Ticket', notif_ticket_link_employee_tickets($id));
                    if (!notif_email_send([$requesterEmail], $requesterEmailTitle . " (#$ticketNumber)", (string) ($requesterTpl['html'] ?? ''), (string) ($requesterTpl['text'] ?? ''), $attachments)) {
                        error_log('Ticket update email failed (requester) | ticketId=' . (string) $id);
                    }
                }

                if (count($assigneeEmails) > 0) {
                    $assigneeEmailTitle = 'Ticket Updated';
                    if ($requesterAssignmentChanged) {
                        $assigneeEmailTitle = ($oldAssignedUserId > 0 || $oldCompany !== '' || $oldDept !== '') ? 'Ticket Reassigned' : 'Ticket Assigned';
                    }
                    if ($assigneeEmailTitle !== 'Ticket Assigned') {
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
                        $assigneeTpl = notif_email_simple($assigneeEmailTitle, $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks($id));
                        if (!notif_email_send($assigneeEmails, $assigneeEmailTitle . " (#$ticketNumber)", (string) ($assigneeTpl['html'] ?? ''), (string) ($assigneeTpl['text'] ?? ''), $attachments)) {
                            error_log('Ticket update email failed (assignee) | ticketId=' . (string) $id);
                        }
                    }
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
