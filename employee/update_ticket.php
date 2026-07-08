<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

function flush_ticket_update_redirect(string $location): void
{
    static $flushed = false;
    if ($flushed || headers_sent()) {
        return;
    }
    $flushed = true;

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    ignore_user_abort(true);

    if (function_exists('session_write_close')) {
        @session_write_close();
    }

    header("Location: $location");
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Connection: close');
    header('Content-Length: 0');

    while (ob_get_level() > 0) {
        @ob_end_clean();
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
    ticket_ensure_chat_tables($conn);
    ticket_ensure_activity_table($conn);
    notif_ensure_action_type_column($conn);
    notif_ensure_requester_identity_columns($conn);

    if (!isset($_POST['id'])) {
        header("Location: my_task.php");
        exit();
    }

    $id = (int) $_POST['id'];
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $new_department = isset($_POST['assigned_department']) ? trim($_POST['assigned_department']) : '';
    $new_company = isset($_POST['assigned_company']) ? trim($_POST['assigned_company']) : '';
    $requested_assigned_user_id = isset($_POST['assigned_user_id']) ? (int) $_POST['assigned_user_id'] : 0;
    $admin_note = isset($_POST['admin_note']) ? trim($_POST['admin_note']) : null;

    // --- PERMISSION CHECK ---
    // Employee can only update tickets assigned to their department AND company
    // Try with optional columns first; fall back if they don't exist yet.
    $check_stmt = $conn->prepare("SELECT user_id, requester_email, status, assigned_department, assigned_group, assigned_company, assigned_user_id, assigned_to, admin_note, company FROM employee_tickets WHERE id = ?");
    if (!$check_stmt) {
        $check_stmt = $conn->prepare("SELECT user_id, NULL AS requester_email, status, assigned_department, NULL AS assigned_group, NULL AS assigned_company, NULL AS assigned_user_id, NULL AS assigned_to, admin_note, company FROM employee_tickets WHERE id = ?");
        if (!$check_stmt) {
            error_log('employee/update_ticket.php: check_stmt prepare failed: ' . $conn->error);
            header("Location: my_task.php?error=dbfail");
            exit();
        }
    }
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();
    $old_data = $check_res->fetch_assoc();
    $check_stmt->close();

    if (!$old_data) {
        header("Location: my_task.php?error=notfound");
        exit();
    }

    $lockedAssignedUserId = isset($old_data['assigned_user_id']) ? (int) $old_data['assigned_user_id'] : 0;
    $lockedHandlerId = isset($old_data['assigned_to']) ? (int) $old_data['assigned_to'] : 0;
    $currentSessionUserId = (int) $_SESSION['user_id'];
    $lockedStatusKey = strtolower(trim((string) ($old_data['status'] ?? '')));
    $specificUserLocked = $lockedAssignedUserId > 0 && ($lockedHandlerId > 0 || $lockedStatusKey !== 'open');
    $assigneeOk = ($specificUserLocked && $lockedAssignedUserId === $currentSessionUserId)
        || (!$specificUserLocked && $lockedHandlerId > 0 && $lockedHandlerId === $currentSessionUserId);
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
    $ticketGroupKey = ticket_department_key_from_value($ticketGroup);
    $userDepartmentKey = ticket_department_key_from_value((string) ($_SESSION['department'] ?? ''));
    $userDepartmentAliases = ticket_department_aliases_for_key($userDepartmentKey);
    if ($userDepartmentKey !== '') {
        $userDepartmentAliases[] = $userDepartmentKey;
    }
    $userDepartmentAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $userDepartmentAliases)), static function ($value) {
        return is_string($value) && $value !== '';
    })));
    $groupOk = $ticketGroup !== '' && (
        ($ticketGroupKey !== '' && $userDepartmentKey !== '' && $ticketGroupKey === $userDepartmentKey)
        || in_array(strtoupper(trim($ticketGroup)), $userDepartmentAliases, true)
    );
    $requiresGroupMatch = ((string) ($_SESSION['department'] ?? '')) !== '';
    if ($specificUserLocked && $lockedAssignedUserId !== $currentSessionUserId) {
        header("Location: my_task.php?error=unauthorized");
        exit();
    }
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
        if (ticket_company_requires_department($company)) {
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
    $new_company_requires_department = ticket_company_requires_department($new_company);
    if (empty($new_department)) {
        $new_department = $new_company_requires_department ? $oldDeptRaw : '';
    }
    $new_department = $normalizeGroupForCompany($new_department, $new_company);
    $new_group = $new_department;
    $currentUserDepartmentKey = ticket_department_key_from_value((string) ($_SESSION['department'] ?? ''));
    $newGroupKey = ticket_department_key_from_value((string) $new_group);
    if ($requested_assigned_user_id > 0 && ($currentUserDepartmentKey === '' || $newGroupKey === '' || $currentUserDepartmentKey !== $newGroupKey)) {
        $requested_assigned_user_id = 0;
    }

    $newNoteNorm = trim((string) ($admin_note ?? ''));
    $hasNewActionNote = $newNoteNorm !== '';
    $assignmentChanged = ($new_company !== $oldCompany) || ($new_department !== $oldDept);
    $requestedAssigneeMatchesOld = ($requested_assigned_user_id <= 0 || $requested_assigned_user_id === $oldAssignedUserId);
    if ($new_status === $oldStatus && $new_company === $oldCompany && $new_department === $oldDept && !$hasNewActionNote && $requestedAssigneeMatchesOld) {
        $_SESSION['task_success'] = "No changes were made.";
        header("Location: my_task.php");
        exit();
    }

    $assigned_user_ids = [];
    $assigned_user_id = $oldAssignedUserId > 0 ? $oldAssignedUserId : null;
    $assigned_to = isset($old_data['assigned_to']) ? (int) $old_data['assigned_to'] : null;
    $availableDepartmentUsers = [];
    if ($new_company !== '' && (!$new_company_requires_department || $new_group !== '')) {
        $availableDepartmentUsers = ticket_find_department_user_options($conn, $new_company, $new_group);
    }
    $availableDepartmentUserIds = array_values(array_filter(array_map(static function ($userRow) {
        return (int) ($userRow['id'] ?? 0);
    }, $availableDepartmentUsers), static function ($userId) {
        return $userId > 0;
    }));
    if ($assignmentChanged) {
        if ($new_company === '' || !ticket_is_valid_company($new_company) || ($new_company_requires_department && !ticket_is_valid_group_for_company($new_company, $new_group))) {
            $_SESSION['error'] = 'Invalid company/group selection.';
            header("Location: my_task.php");
            exit();
        }

        $assigned_user_ids = ticket_find_assignee_ids($conn, $new_company, $new_group);
        if (count($assigned_user_ids) === 0) {
            $_SESSION['error'] = $new_company_requires_department
                ? 'No assignee available for the selected company and group.'
                : 'No assignee available for the selected recipient.';
            header("Location: my_task.php");
            exit();
        }
        $assigned_user_id = null;
        if ($requested_assigned_user_id > 0 && in_array($requested_assigned_user_id, $availableDepartmentUserIds, true)) {
            $assigned_user_id = $requested_assigned_user_id;
            $assigned_user_ids = [$requested_assigned_user_id];
        }
        $assigned_to = null;
    } elseif ($requested_assigned_user_id > 0) {
        if (!in_array($requested_assigned_user_id, $availableDepartmentUserIds, true)) {
            $_SESSION['error'] = 'Invalid department user selected.';
            header("Location: my_task.php");
            exit();
        }
        $assigned_user_id = $requested_assigned_user_id;
        $assigned_user_ids = [$requested_assigned_user_id];
    }
    $explicitUserAssignmentChanged = !$assignmentChanged
        && $requested_assigned_user_id > 0
        && $requested_assigned_user_id !== $oldAssignedUserId;
    $requesterAssignmentChanged = $assignmentChanged || $explicitUserAssignmentChanged;
    if ($new_status === 'Open') {
        $assigned_to = null;
    } elseif ($assignmentChanged || $explicitUserAssignmentChanged) {
        $assigned_to = null;
    }
    $shouldSetStartedAt = 0;

    // Update ticket
    $update = $conn->prepare("
        UPDATE employee_tickets
        SET 
            status = ?,
            assigned_department = ?,
            assigned_company = ?,
            assigned_group = ?,
            assigned_user_id = ?,
            assigned_to = ?,
            is_read = 1,
            updated_at = NOW(),
            started_at = CASE
                WHEN ? = 1 AND started_at IS NULL THEN NOW()
                ELSE started_at
            END,
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

    $update->bind_param("ssssiiissi", $new_status, $new_department, $new_company, $new_group, $assigned_user_id, $assigned_to, $shouldSetStartedAt, $new_status, $new_status, $id);

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
        if ($requesterAssignmentChanged) {
            ticket_chat_rotate_thread($conn, (int) $id);
        }
        $_SESSION['task_success'] = "Ticket #$id successfully updated.";
        if ($oldStatus !== $new_status && in_array($new_status, ['Open', 'In Progress', 'Resolved'], true)) {
            $_SESSION['task_success_status'] = $new_status;
            $_SESSION['task_success_ticket_id'] = $id;
        } else {
            unset($_SESSION['task_success_status'], $_SESSION['task_success_ticket_id']);
        }

        $update->close();

        try {

        // --- TICKET ACTIVITY LOG ---
        // Status change
        if ($old_data['status'] !== $new_status) {
            $activity_desc = "Status changed to " . $new_status;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'status_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }
        
        $newAssigneeName = '';
        if ((int) $assigned_user_id > 0) {
            $assigneeStmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
            if ($assigneeStmt) {
                $assigneeStmt->bind_param("i", $assigned_user_id);
                $assigneeStmt->execute();
                $assigneeRes = $assigneeStmt->get_result();
                $assigneeRow = $assigneeRes ? $assigneeRes->fetch_assoc() : null;
                $assigneeStmt->close();
                $newAssigneeName = trim((string) ($assigneeRow['name'] ?? ''));
            }
        }
        $newAssigneeContextLabel = $newAssigneeName;
        if ($newAssigneeName !== '') {
            $newCompanyDisplay = ticket_company_display_name((string) $new_company);
            $newDepartmentDisplay = ticket_department_display_name((string) $new_department);
            $contextParts = array_values(array_filter([$newCompanyDisplay, $newDepartmentDisplay], static function ($value) {
                return trim((string) $value) !== '';
            }));
            if (count($contextParts) > 0) {
                $newAssigneeContextLabel = implode(' - ', $contextParts) . ' ' . $newAssigneeName;
            }
        }

        // Department reassignment
        if ($old_data['assigned_department'] !== $new_department) {
            $activity_desc = "Reassigned from " . $old_data['assigned_department'] . " to " . $new_department;
            if ($newAssigneeContextLabel !== '') {
                $activity_desc .= " | Handled by: " . $newAssigneeContextLabel;
            }
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'department_change', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        } elseif ($explicitUserAssignmentChanged && $newAssigneeContextLabel !== '') {
            $activity_desc = "Reassigned to " . $newAssigneeContextLabel;
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

        // Action history note
        if ($hasNewActionNote) {
            $activity_desc = $newNoteNorm;
            $act = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'action_history', ?, NOW())");
            if ($act) {
                $act->bind_param("is", $id, $activity_desc);
                $act->execute();
                $act->close();
            }
        }

        // --- INSERT NOTIFICATIONS ---
        $notif_user_id = notif_requester_user_id($conn, $old_data);
        $statusChanged = (string) ($old_data['status'] ?? '') !== (string) $new_status;
        $noteChanged = $hasNewActionNote;
        $suppressClaimFollowupEmail = false;
        if ($noteChanged && !$statusChanged && !$requesterAssignmentChanged) {
            $recentClaimStmt = $conn->prepare("
                SELECT 1
                FROM ticket_activity
                WHERE ticket_id = ?
                  AND activity_type = 'claim_ticket'
                  AND created_at >= (NOW() - INTERVAL 10 MINUTE)
                LIMIT 1
            ");
            if ($recentClaimStmt) {
                $recentClaimStmt->bind_param("i", $id);
                $recentClaimStmt->execute();
                $recentClaimRes = $recentClaimStmt->get_result();
                $suppressClaimFollowupEmail = $recentClaimRes && $recentClaimRes->num_rows > 0;
                $recentClaimStmt->close();
            }
        }

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
            $updateSourceLabel = ticket_activity_actor_label($conn, (int) ($_SESSION['user_id'] ?? 0), $_SESSION);
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
            if ($requesterAssignmentChanged) {
                $oldAssignedTargetLabel = notif_assignment_email_label((string) $oldCompany, (string) $oldDept, 'Unassigned');
                if ($oldAssignedTargetLabel !== '') {
                    $sharedUpdateLines[] = 'Reassigned From: ' . $oldAssignedTargetLabel;
                }
                $newAssignedTargetLabel = notif_assignment_email_label((string) $new_company, (string) $new_department, '');
                if ($newAssignedTargetLabel === '') {
                    $newAssignedTargetLabel = notif_assignment_email_label((string) $currentAssignedCompany, (string) $currentAssignedGroup, '');
                }
                if ($newAssignedTargetLabel !== '') {
                    $sharedUpdateLines[] = 'Reassigned To: ' . $newAssignedTargetLabel;
                }
            }
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
                        'skip_email' => true,
                    ]
                );

                flush_ticket_update_redirect("my_task.php");
                $responseFlushed = true;

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
                        'skip_system' => true,
                    ]
                );
            } else {
                flush_ticket_update_redirect("my_task.php");
                $responseFlushed = true;

                if (!$suppressClaimFollowupEmail && $requesterEmail !== '') {
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

                if (!$suppressClaimFollowupEmail && count($assigneeEmails) > 0) {
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

        } catch (Throwable $postUpdateEx) {
            error_log('update_ticket.php post-update side-effects failed: ' . $postUpdateEx->getMessage());
        }
    }

    if (!$responseFlushed) {
        header("Location: my_task.php");
    }
    exit();
}

header("Location: my_task.php");
exit();
?>
