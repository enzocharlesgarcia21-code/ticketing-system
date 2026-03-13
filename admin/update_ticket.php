<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    ticket_ensure_assignment_columns($conn);

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
    $old_stmt = $conn->prepare("SELECT user_id, status, assigned_department, assigned_company, assigned_group, assigned_user_id, company, admin_note FROM employee_tickets WHERE id = ?");
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_res = $old_stmt->get_result();
    $old_data = $old_res->fetch_assoc();
    $old_stmt->close();
    if (!$old_data) {
        header("Location: all_tickets.php");
        exit();
    }

    $effective_company = $new_company;
    if ($effective_company === '') {
        $effective_company = (string) ($old_data['assigned_company'] ?? '');
        if ($effective_company === '') {
            $effective_company = (string) ($old_data['company'] ?? '');
        }
    }
    $effective_company = ticket_normalize_company($effective_company);

    $effective_group = $new_department !== '' ? $new_department : (string) ($old_data['assigned_group'] ?? ($old_data['assigned_department'] ?? ''));
    $assigned_user_id = $old_data['assigned_user_id'] ?? null;
    if ($effective_company !== '' && $effective_group !== '') {
        if (!ticket_is_valid_company($effective_company) || !ticket_is_valid_group_for_company($effective_company, $effective_group)) {
            $_SESSION['error'] = 'Invalid company/group selection.';
            header("Location: all_tickets.php");
            exit();
        }
        $assignee = ticket_find_assignee_id($conn, $effective_company, $effective_group);
        if (!$assignee) {
            $_SESSION['error'] = 'No assignee available for the selected company and group.';
            header("Location: all_tickets.php");
            exit();
        }
        $assigned_user_id = $assignee;
    }

    // Normalize and validate status, prevent blank status
    $allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if ($new_status === '' || !in_array($new_status, $allowed_statuses, true)) {
        $new_status = $old_data && isset($old_data['status']) ? $old_data['status'] : 'Open';
    }
    if ($new_department === '') {
        $new_department = $effective_group;
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
            admin_note = ?,
            is_read = 1, 
            updated_at = NOW(),
            resolved_at = CASE 
                WHEN (? = 'Resolved' OR ? = 'Closed') AND resolved_at IS NULL THEN NOW() 
                ELSE resolved_at 
            END
        WHERE id = ?
    ");
    
    $update->bind_param("ssssisssi", $new_status, $new_department, $effective_company, $effective_group, $assigned_user_id, $admin_note, $new_status, $new_status, $id);
    
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
            $notif_user_id = $old_data['user_id'];
            $notifications = [];

            // 1. Status Change
            if ($old_data['status'] !== $new_status) {
                if ($new_status === 'Resolved' || $new_status === 'Closed') {
                     $notifications[] = [
                        'msg' => "Your ticket #$id has been closed.",
                        'type' => 'ticket_closed'
                    ];
                } else {
                    $notifications[] = [
                        'msg' => "Your ticket #$id status was updated to $new_status.",
                        'type' => 'status_update'
                    ];
                }
            }

            // 2. Department/Company Change
            $deptChanged = (string) ($old_data['assigned_department'] ?? '') !== (string) $new_department;
            $companyChanged = (string) ($old_data['assigned_company'] ?? '') !== (string) $effective_company;
            if ($deptChanged || $companyChanged) {
                $notifications[] = [
                    'msg' => "Your ticket #$id was reassigned to $new_department" . ($effective_company !== '' ? (" at $effective_company") : "") . ".",
                    'type' => 'reassigned'
                ];

                if (!empty($assigned_user_id)) {
                    $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
                    if ($dept_notif_stmt) {
                        $dept_type = 'dept_assigned';
                        $dept_msg = "New ticket #$id was assigned to your group" . ($effective_company !== '' ? (" ($effective_company)") : "") . ".";
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
                    'msg' => "Admin added a note to ticket #$id: '$preview'",
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
        }

        $stmt = $conn->prepare("
            SELECT t.subject, t.priority, t.category, t.assigned_department, u.name, u.email
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

            $adminNoteHtml = '';
            if (!empty($admin_note)) {
                $adminNoteHtml = "<div style='background-color:#f0fdf4;border-left:4px solid #16a34a;padding:15px;margin:15px 0;color:#14532d'><strong style='color:#166534'>Admin Note:</strong><br>" . nl2br(htmlspecialchars($admin_note)) . "</div>";
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
                        Assigned Department: <strong>" . htmlspecialchars($new_department) . "</strong><br>
                        Assigned Company: <strong>" . htmlspecialchars($effective_company) . "</strong>
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
                . "Assigned Department: $new_department\n"
                . "Assigned Company: $effective_company\n\n"
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
            $deptChanged = $old_data && isset($old_data['assigned_department']) && (string) $old_data['assigned_department'] !== (string) $new_department;
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
                            Assigned to: <strong>" . htmlspecialchars($new_department) . "</strong>
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";
                $adminBodyText = "A support ticket has been assigned to your department.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: " . (string) $ticket['subject'] . "\n"
                    . "Priority: " . (string) $ticket['priority'] . "\n"
                    . "Requested by: " . (string) $ticket['name'] . "\n"
                    . "Assigned to: $new_department\n\n"
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

                $adminBodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>Ticket Note Updated</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Subject: <strong>{$ticketSubjectSafe}</strong><br>
                            Priority: <strong>{$prioritySafe}</strong><br>
                            Requested by: <strong>{$requesterNameSafe}</strong><br>
                            Assigned to: <strong>" . htmlspecialchars($targetDept) . "</strong>
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
                    . "Assigned to: $targetDept\n\n"
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
