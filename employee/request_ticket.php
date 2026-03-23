<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    ticket_ensure_assignment_columns($conn);

    $user_id    = $_SESSION['user_id'];
    $allowed_categories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support'];
    $category = trim((string) ($_POST['category'] ?? ''));
    if ($category === '' || !in_array($category, $allowed_categories, true)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please select a valid category.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please select a valid category.';
        header("Location: request_ticket.php");
        exit();
    }
    $subject = $category . ' Concern';
    $priority   = $_POST['priority'] ?? 'Low';
    if ($priority === '') {
        $priority = 'Low';
    }
    $company = $_SESSION['company'] ?? '';
    if (empty($company)) {
        $c_stmt = $conn->prepare("SELECT company FROM users WHERE id = ?");
        if ($c_stmt) {
            $c_stmt->bind_param("i", $user_id);
            $c_stmt->execute();
            $c_res = $c_stmt->get_result();
            if ($c_row = $c_res->fetch_assoc()) {
                $company = $c_row['company'] ?? $company;
                if (!empty($company)) {
                    $_SESSION['company'] = $company;
                }
            }
            $c_stmt->close();
        }
    }
    $department = $_SESSION['department'] ?? '';
    if (empty($department)) {
        $dept_stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
        if ($dept_stmt) {
            $dept_stmt->bind_param("i", $user_id);
            $dept_stmt->execute();
            $dept_res = $dept_stmt->get_result();
            if ($dept_row = $dept_res->fetch_assoc()) {
                $department = $dept_row['department'] ?? $department;
                if (!empty($department)) {
                    $_SESSION['department'] = $department;
                }
            }
            $dept_stmt->close();
        }
    }
    $assigned_company = isset($_POST['assigned_company']) ? trim((string) $_POST['assigned_company']) : '';
    $assigned_group = isset($_POST['assigned_group']) ? trim((string) $_POST['assigned_group']) : '';
    $assigned_company = ticket_normalize_company($assigned_company);
    $assigned_group = ticket_department_key_from_value($assigned_group);
    $assigned_department = $assigned_group;
    $description = trim((string) ($_POST['description'] ?? ''));

    if ($assigned_company === '' || !ticket_is_valid_company($assigned_company)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid ticket recipient selected.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Invalid ticket recipient selected.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($assigned_group === '' || !ticket_is_valid_group_for_company($assigned_company, $assigned_group)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid assigned department selected for the chosen recipient.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Invalid assigned department selected for the chosen recipient.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($description === '') {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Description is required.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Description is required.';
        header("Location: request_ticket.php");
        exit();
    }

    $assigned_user_ids = ticket_find_assignee_ids($conn, $assigned_company, $assigned_group);
    $assigned_user_id = count($assigned_user_ids) > 0 ? (int) $assigned_user_ids[0] : null;
    if (!$assigned_user_id) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'No assignee available for the selected recipient and department.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'No assignee available for the selected recipient and department.';
        header("Location: request_ticket.php");
        exit();
    }

    $companyAliasesMap = [
        'MHC' => ['MHC', 'Malveda Holdings Corporation - MHC'],
        'GPCI' => ['GPCI', 'GPSCI', 'Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI'],
        'LAPC' => ['LAPC', 'Leads Animal Health - LAH', 'LEADS Animal Health - LAH'],
        'PCC' => ['PCC', 'Primestocks Chemical Corporation - PCC', 'FARMASEE'],
        'MPDC' => ['MPDC', 'Malveda Properties & Development Corporation - MPDC'],
        'LINGAP' => ['LINGAP', 'LINGAP LEADS FOUNDATION - Lingap'],
        'LTC' => ['LTC', 'Leads Tech Corporation - LTC'],
        'FARMEX' => ['FARMEX', 'Farmex Corp'],
    ];
    $assigned_company_key = strtoupper(trim((string) $assigned_company));
    $companyAliases = [$assigned_company];
    if ($assigned_company_key === 'FARMEX CORP') $assigned_company_key = 'FARMEX';
    if ($assigned_company_key === 'FARMASEE') $assigned_company_key = 'PCC';
    if (isset($companyAliasesMap[$assigned_company_key])) {
        $companyAliases = array_merge($companyAliases, $companyAliasesMap[$assigned_company_key]);
    }
    $companyAliases = array_values(array_unique(array_filter(array_map('trim', $companyAliases), static function($v){ return $v !== ''; })));

    $attachmentName = NULL;
    $uploadedFiles = [];
    $unsupportedAttachmentMessage = 'Please insert supported files only.';

    /* ================= FILE UPLOAD ================= */

    if (isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $error_msg = '';
        $maxBytes = 5 * 1024 * 1024;
        $maxFiles = 5;
        $selectedFiles = 0;
        $totalBytes = 0;
        $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'docx'];
        $allowedMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ];
        $finfo = null;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
        }
        $movedPaths = [];
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $selectedFiles++;
        }
        if ($selectedFiles > $maxFiles) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Maximum 5 attachments allowed.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Maximum 5 attachments allowed.';
            header("Location: request_ticket.php");
            exit();
        }
        for ($i = 0; $i < $count; $i++) {
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $error_msg = 'Attachment too large. Max 5MB total.';
                break;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $error_msg = 'Attachment upload failed. Please try again.';
                break;
            }

            $fileName = (string)($_FILES['attachments']['name'][$i] ?? '');
            $fileTmp = (string)($_FILES['attachments']['tmp_name'][$i] ?? '');
            $fileSize = (int)($_FILES['attachments']['size'][$i] ?? 0);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedTypes, true)) {
                $error_msg = $unsupportedAttachmentMessage;
                break;
            }
            if ($fileSize <= 0 || $fileSize > $maxBytes) {
                $error_msg = 'Attachment too large. Max 5MB total.';
                break;
            }
            if (($totalBytes + $fileSize) > $maxBytes) {
                $error_msg = 'Attachment too large. Max 5MB total.';
                break;
            }
            if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
                $mime = (string) $finfo->file($fileTmp);
                $allowed = $allowedMimes[$fileExt] ?? [];
                if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                    $error_msg = $unsupportedAttachmentMessage;
                    break;
                }
            }

            if (!is_dir("../uploads")) {
                mkdir("../uploads", 0777, true);
            }

            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath  = "../uploads/" . $newFileName;

            if (move_uploaded_file($fileTmp, $uploadPath)) {
                $movedPaths[] = $uploadPath;
                $totalBytes += $fileSize;
                $uploadedFiles[] = [
                    'stored_name' => $newFileName,
                    'original_name' => $fileName,
                ];
                if ($attachmentName === NULL) {
                    $attachmentName = $newFileName;
                }
            } else {
                $error_msg = 'Failed to save attachment. Please try again.';
                break;
            }
        }
        if ($error_msg !== '') {
            foreach ($movedPaths as $p) {
                if (is_string($p) && $p !== '' && file_exists($p)) {
                    unlink($p);
                }
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $error_msg], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = $error_msg;
            header("Location: request_ticket.php");
            exit();
        }
    }

    /* ================= INSERT INTO DATABASE ================= */

    $stmt = $conn->prepare("
        INSERT INTO employee_tickets
        (user_id, subject, category, priority, company, department, assigned_department, assigned_company, assigned_group, assigned_user_id, description, attachment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if(!$stmt){
        die("Prepare Failed: " . $conn->error);
    }

    $stmt->bind_param(
        "issssssssiss",
        $user_id,
        $subject,
        $category,
        $priority,
        $company,
        $department,
        $assigned_department,
        $assigned_company,
        $assigned_group,
        $assigned_user_id,
        $description,
        $attachmentName
    );

    if(!$stmt->execute()){
        die("Execute Failed: " . $stmt->error);
    }
    
    $ticket_id = $stmt->insert_id;

    $stmt->close();

    if (count($uploadedFiles) > 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS ticket_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) DEFAULT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket_id (ticket_id),
            CONSTRAINT fk_ticket_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES employee_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $attStmt = $conn->prepare("INSERT INTO ticket_attachments (ticket_id, stored_name, original_name) VALUES (?, ?, ?)");
        if ($attStmt) {
            foreach ($uploadedFiles as $f) {
                $stored = (string)($f['stored_name'] ?? '');
                $orig = (string)($f['original_name'] ?? '');
                if ($stored === '') continue;
                $attStmt->bind_param("iss", $ticket_id, $stored, $orig);
                $attStmt->execute();
            }
            $attStmt->close();
        }
    }

    /* ================= NOTIFICATIONS & EMAILS ================= */
    // 1. Get User Details
    $user_stmt = $conn->prepare("SELECT name, company FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result();
    $user_data = $user_res->fetch_assoc();
    $user_name = $user_data['name'] ?? 'Unknown User';
    $user_company = $user_data['company'] ?? 'Unknown Company';
    $user_stmt->close();

    // 2. System Notifications
    $ticket_number = notif_ticket_number((int) $ticket_id);
    $ticketStatus = 'Open';
    $statusStmt = $conn->prepare("SELECT status FROM employee_tickets WHERE id = ? LIMIT 1");
    if ($statusStmt) {
        $statusStmt->bind_param("i", $ticket_id);
        $statusStmt->execute();
        $statusRes = $statusStmt->get_result();
        $statusRow = $statusRes ? $statusRes->fetch_assoc() : null;
        $statusStmt->close();
        if ($statusRow && isset($statusRow['status']) && trim((string) $statusRow['status']) !== '') {
            $ticketStatus = (string) $statusRow['status'];
        }
    }

    $notifDepartmentLabel = $assigned_department !== '' ? $assigned_department : 'the selected department';
    $notifCompanyLabel = ltrim((string) $assigned_company, '@');
    $notifTargetLabel = $notifCompanyLabel !== '' ? ($notifDepartmentLabel . ' at ' . $notifCompanyLabel) : $notifDepartmentLabel;
    $employeeTicketNotifMsg = "New ticket #$ticket_number from $user_name was assigned to your group.";
    $adminTicketNotifMsg = "New ticket #$ticket_number from $user_name was assigned to $notifTargetLabel.";
    foreach ($assigned_user_ids as $notifyUserId) {
        $notifyUserId = (int) $notifyUserId;
        if ($notifyUserId <= 0 || $notifyUserId === (int) $user_id) continue;
        notif_insert_system($conn, $notifyUserId, (int) $ticket_id, $employeeTicketNotifMsg, 'dept_assigned');
    }
    notif_insert_admins($conn, (int) $ticket_id, $adminTicketNotifMsg, 'new_ticket');

    $ticketDetails = null;
    $ticketStmt = $conn->prepare("
        SELECT t.subject, t.description, t.assigned_department, t.created_at, u.email, u.name
        FROM employee_tickets t
        JOIN users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    if ($ticketStmt) {
        $ticketStmt->bind_param("i", $ticket_id);
        $ticketStmt->execute();
        $ticketRes = $ticketStmt->get_result();
        $ticketDetails = $ticketRes ? $ticketRes->fetch_assoc() : null;
        $ticketStmt->close();
    }

    $adminEmails = [];
    $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
    if ($admins) {
        while ($admin = $admins->fetch_assoc()) {
            $adminEmails[] = $admin['email'];
        }
    }

    $ticketNumber = $ticket_number;
    $requesterName = (string) ($ticketDetails['name'] ?? ($user_name ?? ($_SESSION['name'] ?? 'Unknown')));
    $employeeEmail = (string) ($ticketDetails['email'] ?? '');
    $createdAt = (string) ($ticketDetails['created_at'] ?? '');
    $ticketSubject = (string) ($ticketDetails['subject'] ?? $subject);
    $ticketDescription = (string) ($ticketDetails['description'] ?? ($description ?? ''));
    $ticketAssignedDept = (string) ($ticketDetails['assigned_department'] ?? $assigned_department);

    $ticketNumberSafe = htmlspecialchars($ticketNumber);
    $requesterNameSafe = htmlspecialchars($requesterName);
    $ticketSubjectSafe = htmlspecialchars($ticketSubject);
    $ticketDescriptionSafe = nl2br(htmlspecialchars($ticketDescription));
    $ticketAssignedDeptSafe = htmlspecialchars($ticketAssignedDept);
    $createdAtSafe = htmlspecialchars($createdAt);
    $attachmentLabels = [];
    foreach ($uploadedFiles as $uploadedFile) {
        $originalName = trim((string) ($uploadedFile['original_name'] ?? ''));
        if ($originalName !== '') {
            $attachmentLabels[] = $originalName;
        }
    }
    $attachmentSummary = count($attachmentLabels) > 0
        ? 'Attachments: ' . implode(', ', $attachmentLabels)
        : '';

    $adminSubject = "New Ticket Submitted (#$ticketNumber)";
    $adminTpl = notif_email_simple('New Ticket Submitted', [
        "Ticket ID: #$ticketNumber",
        "Title: $ticketSubject",
        "Category: $category",
        "Priority: $priority",
        "Status: $ticketStatus",
        "Assigned Department: $ticketAssignedDept",
        "Requested by: $requesterName",
        "Requester Email: $employeeEmail"
    ], 'Open Ticket', notif_ticket_link_admin((int) $ticket_id));

    $attachments = [];
    if (!empty($attachmentName)) {
        $path = realpath(__DIR__ . '/../uploads/' . $attachmentName);
        if ($path) {
            $attachments[] = ['path' => $path];
        }
    }

    $adminOk = notif_email_send($adminEmails, $adminSubject, (string) $adminTpl['html'], (string) $adminTpl['text'], $attachments);
    if (!$adminOk) {
        error_log('Ticket email failed (admins) | ticketId=' . (string) $ticket_id);
    }

    $assigneeEmails = [];
    foreach ($assigned_user_ids as $notifyUserId) {
        $notifyUserId = (int) $notifyUserId;
        if ($notifyUserId <= 0 || $notifyUserId === (int) $user_id) continue;
        $assigneeContact = notif_user_contact($conn, $notifyUserId);
        $assigneeEmail = trim((string) ($assigneeContact['email'] ?? ''));
        if ($assigneeEmail !== '') {
            $assigneeEmails[] = $assigneeEmail;
        }
    }
    $assigneeEmails = array_values(array_unique($assigneeEmails));
    if (count($assigneeEmails) > 0) {
        $assigneeLines = [
            "Ticket ID: #$ticketNumber",
            "Category: $category",
            "Status: $ticketStatus",
            "Requested by: $requesterName",
            "Description:\n$ticketDescription"
        ];
        if ($attachmentSummary !== '') {
            $assigneeLines[] = $attachmentSummary;
        }
        $assigneeTpl = notif_email_simple('Ticket Assigned', $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks((int) $ticket_id));
        notif_email_send($assigneeEmails, "New Ticket Assigned (#$ticketNumber)", (string) $assigneeTpl['html'], (string) $assigneeTpl['text'], $attachments);
    }

    if ($employeeEmail !== '') {
        $employeeSubject = "Ticket Submitted (#$ticketNumber)";
        $employeeLines = [
            "Ticket ID: #$ticketNumber",
            "Category: $category",
            "Status: $ticketStatus",
            "Assigned Department: $ticketAssignedDept",
            "Description:\n$ticketDescription"
        ];
        if ($attachmentSummary !== '') {
            $employeeLines[] = $attachmentSummary;
        }
        $employeeTpl = notif_email_simple('Ticket Submitted', $employeeLines, 'View My Tickets', notif_ticket_link_employee_tickets((int) $ticket_id));

        $employeeOk = notif_email_send([$employeeEmail], $employeeSubject, (string) $employeeTpl['html'], (string) $employeeTpl['text'], $attachments);
        if (!$employeeOk) {
            error_log('Ticket email failed (employee) | ticketId=' . (string) $ticket_id);
        }
    } else {
        error_log('Ticket email skipped (employee email empty) | ticketId=' . (string) $ticket_id);
    }

    /* ================= SUCCESS MESSAGE ================= */

    $_SESSION['success'] = "Ticket successfully submitted!";

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Ticket successfully submitted!', 'ticket_id' => (int) $ticket_id, 'ticket_number' => (string) $ticketNumber], JSON_UNESCAPED_UNICODE);
        exit();
    }
    header("Location: my_tickets.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Ticket | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body.employee-request-ticket-page .select-wrapper {
            position: relative;
        }
        body.employee-request-ticket-page .select-wrapper .form-control {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            min-height: 48px;
            padding: 0 44px 0 16px;
            border: 1px solid #d9dee8;
            border-radius: 13px;
            background: #ffffff;
            color: #111827;
            box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .select-wrapper .form-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.employee-request-ticket-page .select-wrapper .select-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #374151;
            font-size: 14px;
            pointer-events: none;
        }
        body.employee-request-ticket-page .required-asterisk {
            color: #dc2626;
        }
        body.employee-request-ticket-page textarea.form-control {
            resize: none;
        }
        body.employee-request-ticket-page .ticket-loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
        }
        body.employee-request-ticket-page .ticket-loading-overlay.show {
            display: flex;
        }
        body.employee-request-ticket-page .ticket-loading-card {
            width: 392px;
            max-width: calc(100vw - 40px);
            background: #ffffff;
            border-radius: 24px;
            padding: 26px 24px 22px;
            text-align: center;
            border: 1px solid rgba(27, 94, 32, 0.18);
            box-shadow: 0 26px 80px rgba(2, 6, 23, 0.22);
            position: relative;
            overflow: hidden;
        }
        body.employee-request-ticket-page .ticket-loading-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 6px;
            background: linear-gradient(90deg, #1B5E20, #144a1e);
        }
        body.employee-request-ticket-page .ticket-loading-title {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }
        body.employee-request-ticket-page .ticket-loading-text {
            margin: 0 0 14px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.45;
        }
        body.employee-request-ticket-page .ticket-loading-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        body.employee-request-ticket-page .ticket-loading-progress {
            height: 8px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
            margin: 0 0 10px;
        }
        body.employee-request-ticket-page .ticket-loading-progress span {
            display: block;
            width: 22%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #1B5E20, #22c55e);
            transition: width 0.35s ease;
        }
        body.employee-request-ticket-page .ticket-loading-status {
            min-height: 18px;
            color: #166534;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        @media (max-width: 768px) {
            body.employee-request-ticket-page .dashboard-container {
                padding: 12px;
            }

            body.employee-request-ticket-page .page-header {
                margin-bottom: 18px !important;
            }

            body.employee-request-ticket-page .form-card {
                padding: 16px;
                border-radius: 14px;
                margin: 0;
            }

            body.employee-request-ticket-page .form-section-title {
                margin-top: 18px;
                margin-bottom: 14px;
                padding-bottom: 12px;
                font-size: 16px;
            }

            body.employee-request-ticket-page .form-group {
                margin-bottom: 14px;
            }

            body.employee-request-ticket-page .form-control,
            body.employee-request-ticket-page .form-group input,
            body.employee-request-ticket-page .form-group select,
            body.employee-request-ticket-page .form-group textarea {
                height: 42px;
                padding: 10px 12px;
                font-size: 14px;
                border-radius: 10px;
            }

            body.employee-request-ticket-page textarea.form-control {
                height: auto;
                min-height: 90px;
                padding: 10px 12px;
                resize: none;
            }

            body.employee-request-ticket-page .file-control {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
                padding: 10px !important;
                border-radius: 10px !important;
                border: 1px dashed #D1D5DB !important;
                background: #F9FAFB !important;
                flex-wrap: wrap;
            }

            body.employee-request-ticket-page .file-button {
                padding: 8px 12px !important;
                border-radius: 8px !important;
                font-size: 14px !important;
            }

            body.employee-request-ticket-page .file-name {
                font-size: 13px;
                flex: 1 1 140px;
                min-width: 0;
            }

            body.employee-request-ticket-page .form-text {
                margin-top: 6px;
                font-size: 11px;
            }

            body.employee-request-ticket-page .form-actions {
                margin-top: 18px;
            }

            body.employee-request-ticket-page .btn-submit {
                width: 100%;
                padding: 14px;
                font-size: 16px;
                border-radius: 12px;
                margin-top: 10px;
            }

            body.employee-request-ticket-page .tm-global-chat-fab {
                right: 12px;
                bottom: 80px;
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
                padding: 0 !important;
                border-radius: 999px;
                justify-content: center;
                gap: 0;
            }

            body.employee-request-ticket-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-request-ticket-page .tm-global-chat-fab i {
                font-size: 16px;
            }
        }
    </style>
</head>
<body class="employee-request-ticket-page">

    <!-- 2️⃣ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-error" id="pageError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700;">
                    <?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- 4️⃣ REQUEST TICKET PAGE – REDESIGN -->
            <div class="page-header" style="text-align: center; margin-bottom: 40px;">
                <h1 class="page-title">Create a Ticket</h1>
                <p class="page-subtitle">Please fill out the form below.</p>
            </div>

            <div class="form-card">
                <form id="ticketForm" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="alert alert-error" id="ajaxError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700; display:none;"></div>
                    
                    <!-- 🔹 Request Information -->
                    <h3 class="form-section-title">Request Information</h3>

                    <div class="form-group">
                        <label>Ticket Recipient <span class="required-asterisk">*</span></label>
                        <div class="select-wrapper">
                            <select name="assigned_company" id="assigned_company" class="form-control" required>
                                <option value="" disabled selected hidden>Choose recipient</option>
                                <option value="@gpsci.net">GPSCI (@gpsci.net)</option>
                                <option value="@farmasee.ph">FARMASEE (@farmasee.ph)</option>
                                <option value="@gmail.com">@gmail.com</option>
                                <option value="@leads-eh.com">LEH (@leads-eh.com)</option>
                                <option value="@leads-farmex.com">FARMEX (@leads-farmex.com)</option>
                                <option value="@leadsagri.com">LAPC (@leadsagri.com)</option>
                                <option value="@leadsanimalhealth.com">LAH (@leadsanimalhealth.com)</option>
                                <option value="@leadsav.com">LAV (@leadsav.com)</option>
                                <option value="@malvedaproperties.com">MHC (@malvedaproperties.com)</option>
                                <option value="@leadstech-corp.com">LTC (@leadstech-corp.com)</option>
                                <option value="@lingapleads.org">LINGAP (@lingapleads.org)</option>
                                <option value="@primestocks.ph">PCC (@primestocks.ph)</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Assigned Department <span class="required-asterisk">*</span></label>
                        <div class="select-wrapper">
                            <select name="assigned_group" id="assigned_group" class="form-control" required>
                                <option value="" disabled selected hidden>Choose department</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Category <span class="required-asterisk">*</span></label>
                        <div class="select-wrapper">
                            <select name="category" class="form-control" required>
                                <option value="" disabled selected hidden>Choose category</option>
                                <option value="Documentation">Documentation</option>
                                <option value="Email">Email</option>
                                <option value="Hardware">Hardware</option>
                                <option value="Internet Concerns">Internet Concerns</option>
                                <option value="Procurement">Procurement</option>
                                <option value="Software">Software</option>
                                <option value="Technical Support">Technical Support</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    

                    <div class="form-group">
                        <label>Description <span class="required-asterisk">*</span></label>
                        <textarea name="description" class="form-control" placeholder="Describe your issue in detail..." style="resize:none;" required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Attachment (Optional)</label>
                        <div class="file-control" style="display:flex;align-items:center;gap:12px;background:#f8faf9;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;">
                            <button type="button" id="choose-file-btn" class="file-button" style="display:inline-flex;align-items:center;gap:8px;background:#ecfdf5;color:#1B5E20;border:1px solid #bbf7d0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer;">
                                <i class="fas fa-paperclip"></i>
                                <span>Choose File</span>
                            </button>
                            <span id="file-name" class="file-name" style="color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">No file chosen</span>
                            <input type="file" name="attachments[]" id="attachments" class="file-hidden" multiple accept=".jpg,.jpeg,.png,.pdf,.docx" style="display:none;">
                        </div>
                        <small class="form-text">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)</small>
                        <div id="attachment-error" style="display:none;margin-top:10px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;font-weight:700;"></div>
                        <div id="attachment-toast" role="alert" aria-live="assertive" style="position:fixed;top:18px;right:18px;z-index:9999;display:none;max-width:min(420px, calc(100vw - 36px));background:#991b1b;color:#ffffff;padding:12px 14px;border-radius:12px;box-shadow:0 16px 40px rgba(2,6,23,0.22);font-weight:800;font-size:13px;"></div>
                        <div id="attachment-preview" style="margin-top: 10px;"></div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Submit Ticket</button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <div id="ticketLoadingOverlay" class="ticket-loading-overlay" aria-hidden="true">
        <div class="ticket-loading-card" role="status" aria-live="polite" aria-busy="true">
            <div class="ticket-loading-badge">Secure Submit</div>
            <h3 class="ticket-loading-title">Submitting Ticket</h3>
            <p class="ticket-loading-text">Preparing your request and attachments...</p>
            <div class="ticket-loading-progress"><span id="ticketLoadingProgressBar"></span></div>
            <div class="ticket-loading-status" id="ticketLoadingStatus">Validating ticket details</div>
        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const companyEl = document.getElementById('assigned_company');
        const groupEl = document.getElementById('assigned_group');
        const DEPARTMENTS = ["ACCOUNTING","ADMIN","BIDDING","E-COMM","HR","IT","LINGAP","MARKETING","SUPPLY CHAIN","TECHNICAL"];
        function populateGroups(arr) {
            groupEl.innerHTML = '';
            const ph = document.createElement('option');
            ph.value = '';
            ph.textContent = 'Select Department';
            ph.disabled = true;
            ph.selected = true;
            ph.defaultSelected = true;
            ph.hidden = true;
            groupEl.appendChild(ph);
            arr.forEach(function (g) {
                const opt = document.createElement('option');
                opt.value = g;
                opt.textContent = g;
                groupEl.appendChild(opt);
            });
            groupEl.value = '';
        }
        if (groupEl) populateGroups(DEPARTMENTS);
        var attachmentInput = document.getElementById('attachments');
        var chooseBtn = document.getElementById('choose-file-btn');
        var fileNameEl = document.getElementById('file-name');
        var preview = document.getElementById('attachment-preview');
        var errorEl = document.getElementById('attachment-error');
        var toastEl = document.getElementById('attachment-toast');
        var dt = new DataTransfer();
        var objectUrls = [];
        var MAX_BYTES = 5 * 1024 * 1024;
        var MAX_FILES = 5;
        var ALLOWED_EXT = ['jpg','jpeg','png','pdf','docx'];
        var UNSUPPORTED_FILE_MESSAGE = 'Please insert supported files only.';
        var toastTimer = null;

        if (chooseBtn) {
            chooseBtn.addEventListener('click', function () {
                if (attachmentInput) attachmentInput.click();
            });
        }

        function clearObjectUrls() {
            while (objectUrls.length) {
                try { URL.revokeObjectURL(objectUrls.pop()); } catch (e) {}
            }
        }

        function formatSize(bytes) {
            var b = Number(bytes || 0);
            if (!isFinite(b) || b < 0) b = 0;
            if (b < 1024) return b + ' B';
            var kb = b / 1024;
            if (kb < 1024) return (Math.round(kb * 10) / 10) + ' KB';
            var mb = kb / 1024;
            return (Math.round(mb * 10) / 10) + ' MB';
        }

        function syncFiles() {
            if (!attachmentInput) return;
            attachmentInput.files = dt.files;
            if (fileNameEl) {
                var n = dt.files.length;
                fileNameEl.textContent = n === 0 ? 'No file chosen' : (n === 1 ? dt.files[0].name : (n + ' files selected'));
            }
            if (!preview) return;
            clearObjectUrls();
            preview.innerHTML = '';
            Array.from(dt.files).forEach(function (file, idx) {
                var url = URL.createObjectURL(file);
                objectUrls.push(url);

                var row = document.createElement('div');
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.justifyContent = 'space-between';
                row.style.gap = '12px';
                row.style.padding = '10px 12px';
                row.style.border = '1px solid #e5e7eb';
                row.style.borderRadius = '10px';
                row.style.background = '#f8fafc';
                row.style.marginBottom = '10px';

                var left = document.createElement('a');
                left.href = url;
                left.target = '_blank';
                left.rel = 'noopener';
                left.style.display = 'flex';
                left.style.alignItems = 'center';
                left.style.gap = '10px';
                left.style.minWidth = '0';
                left.style.flex = '1 1 auto';
                left.style.textDecoration = 'none';
                left.style.cursor = 'pointer';

                var icon = document.createElement('div');
                icon.style.width = '36px';
                icon.style.height = '36px';
                icon.style.borderRadius = '10px';
                icon.style.display = 'flex';
                icon.style.alignItems = 'center';
                icon.style.justifyContent = 'center';
                icon.style.background = '#ecfdf5';
                icon.style.color = '#16a34a';
                icon.style.fontWeight = '900';

                if (file.type && file.type.startsWith('image/')) {
                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = '';
                    img.style.width = '28px';
                    img.style.height = '28px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    icon.style.background = '#ffffff';
                    icon.appendChild(img);
                } else {
                    icon.textContent = 'FILE';
                }

                var meta = document.createElement('div');
                meta.style.display = 'flex';
                meta.style.flexDirection = 'column';
                meta.style.minWidth = '0';

                var name = document.createElement('div');
                name.textContent = file.name;
                name.style.fontWeight = '700';
                name.style.color = '#0f172a';
                name.style.fontSize = '13px';
                name.style.overflow = 'hidden';
                name.style.textOverflow = 'ellipsis';
                name.style.whiteSpace = 'nowrap';

                meta.appendChild(name);

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = '×';
                removeBtn.style.border = '1px solid #e2e8f0';
                removeBtn.style.background = '#ffffff';
                removeBtn.style.color = '#ef4444';
                removeBtn.style.fontWeight = '900';
                removeBtn.style.width = '40px';
                removeBtn.style.height = '40px';
                removeBtn.style.padding = '0';
                removeBtn.style.borderRadius = '10px';
                removeBtn.style.cursor = 'pointer';
                removeBtn.style.fontSize = '18px';
                removeBtn.style.lineHeight = '1';
                removeBtn.addEventListener('click', function () {
                    try { URL.revokeObjectURL(url); } catch (e) {}
                    var ndt = new DataTransfer();
                    Array.from(dt.files).forEach(function (f, i) {
                        if (i !== idx) ndt.items.add(f);
                    });
                    dt = ndt;
                    syncFiles();
                });

                left.appendChild(icon);
                left.appendChild(meta);

                row.appendChild(left);
                row.appendChild(removeBtn);
                preview.appendChild(row);
            });
        }

        function showToast(msg) {
            if (!toastEl) return;
            if (!msg) {
                toastEl.style.display = 'none';
                toastEl.textContent = '';
                if (toastTimer) window.clearTimeout(toastTimer);
                toastTimer = null;
                return;
            }
            toastEl.textContent = msg;
            toastEl.style.display = 'block';
            if (toastTimer) window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(function () {
                if (!toastEl) return;
                toastEl.style.display = 'none';
                toastEl.textContent = '';
                toastTimer = null;
            }, 4000);
        }

        function showError(msg) {
            if (!errorEl) return;
            if (!msg) {
                errorEl.style.display = 'none';
                errorEl.textContent = '';
                showToast('');
                return;
            }
            errorEl.textContent = msg;
            errorEl.style.display = 'block';
            showToast(msg);
        }

        window.TMEmployeeResetAttachments = function () {
            dt = new DataTransfer();
            syncFiles();
            showError('');
        };

        function getExt(name) {
            var parts = String(name || '').toLowerCase().split('.');
            return parts.length > 1 ? parts.pop() : '';
        }

        if (attachmentInput) {
            attachmentInput.addEventListener('change', function (e) {
                var selectedFiles = Array.from(e.target.files || []);
                var blockedMax = false;
                var hasUnsupportedType = false;
                var validFiles = [];

                selectedFiles.forEach(function (file) {
                    var ext = getExt(file && file.name);
                    if (ALLOWED_EXT.indexOf(ext) === -1) {
                        hasUnsupportedType = true;
                        return;
                    }
                    validFiles.push(file);
                });

                if (hasUnsupportedType) {
                    attachmentInput.value = '';
                    showError(UNSUPPORTED_FILE_MESSAGE);
                    return;
                }

                validFiles.forEach(function (file) {
                    if (dt.files.length >= MAX_FILES) {
                        blockedMax = true;
                        return;
                    }
                    var nextTotal = (file && file.size || 0);
                    Array.from(dt.files).forEach(function (f) { nextTotal += (f && f.size) ? f.size : 0; });
                    if (nextTotal > MAX_BYTES) {
                        showError('Attachment too large. Max 5MB total.');
                        return;
                    }
                    var exists = Array.from(dt.files).some(function (f) {
                        return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
                    });
                    if (!exists) dt.items.add(file);
                });
                attachmentInput.value = '';
                if (blockedMax) {
                    showError('Maximum 5 attachments allowed. Extra files were not added.');
                } else {
                    showError('');
                }
                syncFiles();
            });
        }

        var formEl = attachmentInput ? attachmentInput.closest('form') : null;
        if (formEl) {
            formEl.addEventListener('submit', function (e) {
                var badType = Array.from(dt.files).find(function (file) {
                    var ext = getExt(file && file.name);
                    return ALLOWED_EXT.indexOf(ext) === -1;
                });
                var total = 0;
                Array.from(dt.files).forEach(function (f) { total += (f && f.size) ? f.size : 0; });
                if (dt.files.length > MAX_FILES || badType || total > MAX_BYTES) {
                    e.preventDefault();
                    showError(dt.files.length > MAX_FILES ? 'Maximum 5 attachments allowed.' : (badType ? UNSUPPORTED_FILE_MESSAGE : 'Attachment too large. Max 5MB total.'));
                    return;
                }
                showError('');
            });
        }
    });
    </script>

    <script>
    (function () {
        var form = document.getElementById('ticketForm');
        var ajaxError = document.getElementById('ajaxError');
        var loadingOverlay = document.getElementById('ticketLoadingOverlay');
        var loadingTitle = document.querySelector('.ticket-loading-title');
        var loadingText = document.querySelector('.ticket-loading-text');
        var loadingStatus = document.getElementById('ticketLoadingStatus');
        var loadingProgress = document.getElementById('ticketLoadingProgressBar');
        var loadingTimers = [];
        var successRedirectTimer = null;
        if (!form) return;

        function revealErrorBanner(message) {
            if (!ajaxError) return;
            ajaxError.textContent = message;
            ajaxError.style.display = 'block';
            ajaxError.setAttribute('tabindex', '-1');
            window.requestAnimationFrame(function () {
                ajaxError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                try { ajaxError.focus({ preventScroll: true }); } catch (e) {}
            });
        }

        function clearLoadingTimers() {
            while (loadingTimers.length) {
                window.clearTimeout(loadingTimers.pop());
            }
            if (successRedirectTimer) {
                window.clearTimeout(successRedirectTimer);
                successRedirectTimer = null;
            }
        }

        function setLoadingState(state, title, text, status, progress) {
            if (!loadingOverlay) return;
            loadingOverlay.setAttribute('data-state', state || '');
            if (loadingTitle && title) loadingTitle.textContent = title;
            if (loadingText && text) loadingText.textContent = text;
            if (loadingStatus) loadingStatus.textContent = status || '';
            if (loadingProgress && progress != null) loadingProgress.style.width = String(progress) + '%';
        }

        function startLoadingSequence() {
            clearLoadingTimers();
            setLoadingState('loading', 'Submitting Ticket', 'Preparing your request and attachments...', 'Validating ticket details', 24);
            loadingTimers.push(window.setTimeout(function () {
                setLoadingState('loading', 'Submitting Ticket', 'Preparing your request and attachments...', 'Creating your ticket record', 68);
            }, 180));
            loadingTimers.push(window.setTimeout(function () {
                setLoadingState('loading', 'Submitting Ticket', 'Almost there. We are finalizing your request...', 'Finalizing your request', 94);
            }, 420));
        }

        form.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;
            e.preventDefault();
            if (ajaxError) ajaxError.style.display = 'none';

            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            if (loadingOverlay) {
                loadingOverlay.classList.add('show');
                loadingOverlay.setAttribute('aria-hidden', 'false');
                startLoadingSequence();
            }

            var formData = new FormData(form);

            fetch("request_ticket.php", {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    clearLoadingTimers();
                    var msg = (data && data.error) ? data.error : 'Failed to submit ticket.';
                    if (loadingOverlay) {
                        loadingOverlay.classList.remove('show');
                        loadingOverlay.setAttribute('aria-hidden', 'true');
                        loadingOverlay.setAttribute('data-state', '');
                    }
                    revealErrorBanner(msg);
                    return;
                }
                form.reset();
                if (typeof window.TMEmployeeResetAttachments === 'function') window.TMEmployeeResetAttachments();
                clearLoadingTimers();
                setLoadingState('success', 'Ticket Submitted', 'Your ticket has been created successfully.', 'Redirecting to your tickets', 100);
                successRedirectTimer = window.setTimeout(function () {
                    window.location.href = 'my_tickets.php';
                }, 120);
            })
            .catch(function () {
                clearLoadingTimers();
                if (loadingOverlay) {
                    loadingOverlay.classList.remove('show');
                    loadingOverlay.setAttribute('aria-hidden', 'true');
                    loadingOverlay.setAttribute('data-state', '');
                }
                revealErrorBanner('Failed to submit ticket.');
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    })();

    (function () {
        var pageError = document.getElementById('pageError');
        if (!pageError) return;
        window.requestAnimationFrame(function () {
            pageError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    })();
    </script>
</body>
</html>
