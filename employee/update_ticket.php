<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    ticket_ensure_assignment_columns($conn);

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
    $check_stmt = $conn->prepare("SELECT user_id, status, assigned_department, assigned_group, assigned_company, assigned_user_id, admin_note FROM employee_tickets WHERE id = ?");
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
    $ticketCompanyCode = company_code((string) ($old_data['assigned_company'] ?? ''));
    $userCompanyCode = company_code((string) ($_SESSION['company'] ?? ''));
    $companyOk = ($ticketCompanyCode !== '' && $userCompanyCode !== '' && $ticketCompanyCode === $userCompanyCode) || ((string) ($old_data['assigned_company'] ?? '') === (string) ($_SESSION['company'] ?? ''));
    $ticketGroup = (string) ($old_data['assigned_group'] ?? ($old_data['assigned_department'] ?? ''));
    $groupOk = $ticketGroup !== '' && $ticketGroup === (string) ($_SESSION['department'] ?? '');
    if (!$assigneeOk && (!$groupOk || !$companyOk)) {
        header("Location: my_task.php?error=unauthorized");
        exit();
    }

    // Normalize and validate status
    $allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $old_data['status'];
    }
    
    // If company is not set (shouldn't happen with dropdown), keep old company
    if (empty($new_company)) {
        $new_company = $old_data['assigned_company'];
    }
    $new_company = ticket_normalize_company((string) $new_company);
    if (empty($new_department)) {
        $new_department = (string) ($old_data['assigned_group'] ?? ($old_data['assigned_department'] ?? ''));
    }
    $new_group = $new_department;

    if ($new_company === '' || !ticket_is_valid_company($new_company) || !ticket_is_valid_group_for_company($new_company, $new_group)) {
        $_SESSION['error'] = 'Invalid company/group selection.';
        header("Location: my_task.php");
        exit();
    }

    $assigned_user_id = ticket_find_assignee_id($conn, $new_company, $new_group);
    if (!$assigned_user_id) {
        $_SESSION['error'] = 'No assignee available for the selected company and group.';
        header("Location: my_task.php");
        exit();
    }

    // Update ticket
    $update = $conn->prepare("
        UPDATE employee_tickets
        SET 
            status = ?, 
            assigned_department = ?, 
            assigned_company = ?,
            assigned_group = ?,
            assigned_user_id = ?,
            admin_note = ?,
            is_read = 1, 
            updated_at = NOW(),
            resolved_at = CASE 
                WHEN (? = 'Resolved' OR ? = 'Closed') AND resolved_at IS NULL THEN NOW() 
                ELSE resolved_at 
            END
        WHERE id = ?
    ");
    
    $update->bind_param("ssssisssi", $new_status, $new_department, $new_company, $new_group, $assigned_user_id, $admin_note, $new_status, $new_status, $id);
    
    if ($update->execute()) {
        $_SESSION['success'] = "Ticket #$id successfully updated.";

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
        $notif_user_id = $old_data['user_id'];
        $notifications = [];

        // 1. Status Change
        if ($old_data['status'] !== $new_status) {
            if ($new_status === 'Resolved' || $new_status === 'Closed') {
                    $notifications[] = [
                    'msg' => "Your ticket #$id has been closed by " . $_SESSION['department'] . ".",
                    'type' => 'ticket_closed'
                ];
            } else {
                $notifications[] = [
                    'msg' => "Your ticket #$id status was updated to $new_status by " . $_SESSION['department'] . ".",
                    'type' => 'status_update'
                ];
            }
        }

        // 2. Department/Company Change
        if ($old_data['assigned_department'] !== $new_department || $old_data['assigned_company'] !== $new_company) {
            $notifications[] = [
                'msg' => "Your ticket #$id was reassigned to $new_department at $new_company.",
                'type' => 'reassigned'
            ];

            if (!empty($assigned_user_id)) {
                $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
                if ($dept_notif_stmt) {
                    $dept_type = 'dept_assigned';
                    $dept_msg = "New ticket #$id was assigned to your group by " . $_SESSION['department'] . " (" . $_SESSION['company'] . ").";
                    $assigneeIdInt = (int) $assigned_user_id;
                    $dept_notif_stmt->bind_param("iiss", $assigneeIdInt, $id, $dept_msg, $dept_type);
                    $dept_notif_stmt->execute();
                    $dept_notif_stmt->close();
                }
            }
        }

        // 3. Admin Note Added
        if (!empty($admin_note) && $admin_note !== $old_data['admin_note']) {
                $preview = strlen($admin_note) > 50 ? substr($admin_note, 0, 50) . '...' : $admin_note;
                $notifications[] = [
                'msg' => $_SESSION['department'] . " added a note to ticket #$id: '$preview'",
                'type' => 'note_added'
            ];
        }

        if (!empty($notifications)) {
            $ins_notif = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
            foreach ($notifications as $n) {
                $ins_notif->bind_param("iiss", $notif_user_id, $id, $n['msg'], $n['type']);
                $ins_notif->execute();
            }
            $ins_notif->close();
        }

        $stmt = $conn->prepare("
            SELECT t.subject, t.priority, t.category, t.assigned_department, t.assigned_company, u.name, u.email
            FROM employee_tickets t
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $ticket = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        } else {
            $ticket = null;
        }

        if ($ticket) {
            $ticketNumber = str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $ticketSubjectSafe = htmlspecialchars((string) $ticket['subject']);
            $prioritySafe = htmlspecialchars((string) $ticket['priority']);
            $requesterNameSafe = htmlspecialchars((string) $ticket['name']);

            $noteFrom = htmlspecialchars((string) ($_SESSION['department'] ?? ''));
            $adminNoteHtml = '';
            if (!empty($admin_note)) {
                $adminNoteHtml = "<div style='background-color:#f0fdf4;border-left:4px solid #16a34a;padding:15px;margin:15px 0;color:#14532d'><strong style='color:#166534'>Note from {$noteFrom}:</strong><br>" . nl2br(htmlspecialchars($admin_note)) . "</div>";
            }

            $employeeSubject = "Ticket Update (#$ticketNumber)";
            $employeeBodyHtml = "
                <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                    <h2 style='margin:0 0 12px 0'>Ticket Updated</h2>
                    <p style='margin:0 0 16px 0'>Hello <strong>{$requesterNameSafe}</strong>,</p>
                    <p style='margin:0 0 16px 0'>Your ticket <strong>#$ticketNumber</strong> has been updated.</p>
                    <p style='margin:0 0 16px 0'>
                        Subject: <strong>{$ticketSubjectSafe}</strong><br>
                        Priority: <strong>{$prioritySafe}</strong><br>
                        Status: <strong>" . htmlspecialchars($new_status) . "</strong><br>
                        Assigned To: <strong>" . htmlspecialchars($new_department) . "</strong>" . ($new_company !== '' ? " (<strong>" . htmlspecialchars($new_company) . "</strong>)" : "") . "
                    </p>
                    $adminNoteHtml
                    <p style='margin:0'>Login to the system to view the ticket.</p>
                </div>
            ";
            $employeeBodyText = "Ticket Updated\n\n"
                . "Ticket ID: #$ticketNumber\n"
                . "Subject: " . (string) $ticket['subject'] . "\n"
                . "Priority: " . (string) $ticket['priority'] . "\n"
                . "Status: $new_status\n"
                . "Assigned To: $new_department" . ($new_company !== '' ? " ($new_company)" : "") . "\n\n"
                . "Login to the system to view the ticket.\n";

            $employeeOk = sendSmtpEmail([(string) $ticket['email']], $employeeSubject, $employeeBodyHtml, $employeeBodyText);
            if (!$employeeOk) {
                error_log('Ticket update email failed (employee) | ticketId=' . (string) $id);
            }

            $adminEmails = [];
            $targetDept = $new_department !== '' ? $new_department : (string) ($ticket['assigned_department'] ?? '');
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
            $deptChanged = $old_data && isset($old_data['assigned_department']) && ($old_data['assigned_department'] !== $new_department || $old_data['assigned_company'] !== $new_company);
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
                            Assigned to: <strong>" . htmlspecialchars($new_department) . "</strong>" . ($new_company !== '' ? " (<strong>" . htmlspecialchars($new_company) . "</strong>)" : "") . "
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";
                $adminBodyText = "A support ticket has been assigned to your department.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: " . (string) $ticket['subject'] . "\n"
                    . "Priority: " . (string) $ticket['priority'] . "\n"
                    . "Requested by: " . (string) $ticket['name'] . "\n"
                    . "Assigned to: $new_department" . ($new_company !== '' ? " ($new_company)" : "") . "\n\n"
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
                            Status: <strong>" . htmlspecialchars($old_data['status']) . "</strong> → <strong>" . htmlspecialchars($new_status) . "</strong>
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";
                $adminBodyText = "A ticket status has changed.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: " . (string) $ticket['subject'] . "\n"
                    . "Priority: " . (string) $ticket['priority'] . "\n"
                    . "Requested by: " . (string) $ticket['name'] . "\n"
                    . "Status: " . (string) $old_data['status'] . " -> $new_status\n\n"
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
                            Assigned to: <strong>" . htmlspecialchars($targetDept) . "</strong>" . ($new_company !== '' ? " (<strong>" . htmlspecialchars($new_company) . "</strong>)" : "") . "<br>
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
                    . "Subject: " . (string) $ticket['subject'] . "\n"
                    . "Priority: " . (string) $ticket['priority'] . "\n"
                    . "Requested by: " . (string) $ticket['name'] . "\n"
                    . "Assigned to: $targetDept" . ($new_company !== '' ? " ($new_company)" : "") . "\n"
                    . "Note from: " . (string) ($_SESSION['department'] ?? '') . "\n\n"
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

    header("Location: my_task.php");
    exit();
}

header("Location: my_task.php");
exit();
?>
