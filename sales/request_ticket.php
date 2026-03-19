<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

$success_msg = "";
$error_msg = "";

$email = '';
$company_id = '';
$category = '';
$description = '';
$assigned_department_selected = '';

$companies = [
    "LTC (@leadstech-corp.com)",
    "GPSCI (@gpsci.net)",
    "LAPC (@leadsagri.com)",
    "FARMEX (@leads-farmex.com)",
    "@gmail.com",
    
];

function derive_name_from_email(string $email): string
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) return 'Sales User';
    $local = explode('@', $email, 2)[0];
    $local = preg_replace('/[^a-zA-Z0-9._-]+/', ' ', $local);
    $local = str_replace(['.', '_', '-'], ' ', (string) $local);
    $local = trim(preg_replace('/\s+/', ' ', $local));
    if ($local === '') return 'Sales User';
    return ucwords(strtolower($local));
}

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();
    ticket_ensure_assignment_columns($conn);

    $email      = trim((string)($_POST['email'] ?? ''));
    $company_id = trim((string)($_POST['company_id'] ?? ''));
    $assigned_department_selected = ticket_department_key_from_value(trim((string)($_POST['assigned_department'] ?? '')));
    $allowed_categories = ['Hardware', 'Software', 'Documentation', 'Email', 'Internet Concerns', 'Procurement', 'Technical Support'];
    $category   = trim((string)($_POST['category'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    $name = derive_name_from_email($email);
    $company = $company_id;
    $department = 'Sales';
    $priority = 'Low';
    $subject = $category !== '' ? ($category . ' Concern') : 'Sales Ticket';
    $assigned_department = $assigned_department_selected;
    $assigned_company = ticket_normalize_company($company_id);
    $assigned_group = $assigned_department;
    $assigned_user_ids = ticket_find_assignee_ids($conn, $assigned_company, $assigned_group);
    $assigned_user_id = count($assigned_user_ids) > 0 ? (int) $assigned_user_ids[0] : null;
    $allowedDepartments = ticket_standard_assigned_departments();
    if ($assigned_department === '') {
        $error_msg = "Please select a department.";
    } elseif (!in_array($assigned_department, $allowedDepartments, true)) {
        $error_msg = "Invalid department selected.";
    }
    if ($error_msg === '') {
        if ($assigned_company === '' || !ticket_is_valid_company($assigned_company)) {
            $error_msg = "Ticket Recipient (Company Email Domain) is required.";
        } elseif ($assigned_group === '' || !ticket_is_valid_group_for_company($assigned_company, $assigned_group)) {
            $error_msg = "Invalid department selected for the chosen recipient.";
        } elseif (!$assigned_user_id) {
            $error_msg = "No assignee available for the selected recipient and department.";
        }
    }

    $attachmentName = null;
    $uploadedFiles = [];

    /* ================= FILE UPLOAD ================= */

    if (isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $maxBytes = 5 * 1024 * 1024;
        $maxFiles = 5;
        $selectedFiles = 0;
        $totalBytes = 0;
        $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
        $allowedMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/vnd.ms-word', 'application/octet-stream'],
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
            $error_msg = "Maximum 5 attachments allowed.";
        }
        for ($i = 0; $i < $count; $i++) {
            if ($error_msg !== '') break;
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $error_msg = "Attachment too large. Max 5MB per file.";
                break;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $error_msg = "Attachment upload failed. Please try again.";
                break;
            }

            $origName = (string)($_FILES['attachments']['name'][$i] ?? '');
            $fileTmp = (string)($_FILES['attachments']['tmp_name'][$i] ?? '');
            $fileSize = (int)($_FILES['attachments']['size'][$i] ?? 0);
            $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedTypes, true)) {
                $error_msg = "Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.";
                break;
            }
            if ($fileSize <= 0 || $fileSize > $maxBytes) {
                $error_msg = "Attachment too large. Max 5MB total.";
                break;
            }
            if (($totalBytes + $fileSize) > $maxBytes) {
                $error_msg = "Attachment too large. Max 5MB total.";
                break;
            }
            if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
                $mime = (string) $finfo->file($fileTmp);
                $allowed = $allowedMimes[$fileExt] ?? [];
                if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                    $error_msg = "Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.";
                    break;
                }
            }

            if (!is_dir("../uploads")) {
                mkdir("../uploads", 0777, true);
            }
            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath = "../uploads/" . $newFileName;
            if (!move_uploaded_file($fileTmp, $uploadPath)) {
                $error_msg = "Failed to save attachment. Please try again.";
                break;
            }
            $movedPaths[] = $uploadPath;
            $totalBytes += $fileSize;
            $uploadedFiles[] = ['stored_name' => $newFileName, 'original_name' => $origName];
        }

        if ($error_msg !== '') {
            foreach ($movedPaths as $p) {
                if (is_string($p) && $p !== '' && file_exists($p)) {
                    unlink($p);
                }
            }
            $uploadedFiles = [];
        }
    }

    if (count($uploadedFiles) > 0) {
        $attachmentName = $uploadedFiles[0]['stored_name'];
    }

    /* ================= GET/CREATE SALES GUEST USER ================= */

    $sales_email = 'sales_guest@leadsagri.com';
    $user_id = null;
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $sales_email);
        $stmt->execute();
        $stmt->bind_result($found_user_id);
        if ($stmt->fetch()) {
            $user_id = (int) $found_user_id;
        }
        $stmt->close();
    } else {
        if ($error_msg === '') $error_msg = "System error. Please try again later.";
    }

    if ($error_msg === '' && empty($user_id)) {
        $guest_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $guest_name = 'Sales Department';
        $guest_company = 'Sales';
        $guest_department = 'SALES';
        $guest_role = 'employee';
        $guest_otp = '000000';
        $guest_verified = 1;

        $insert_stmt = $conn->prepare("
            INSERT INTO users (name, email, company, department, password, role, otp_code, is_verified)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($insert_stmt) {
            $insert_stmt->bind_param(
                "sssssssi",
                $guest_name,
                $sales_email,
                $guest_company,
                $guest_department,
                $guest_pass,
                $guest_role,
                $guest_otp,
                $guest_verified
            );
            if ($insert_stmt->execute()) {
                $user_id = (int) $insert_stmt->insert_id;
            } else {
                $error_msg = "System error. Please try again later.";
            }
            $insert_stmt->close();
        } else {
            $error_msg = "System error. Please try again later.";
        }
    }

    /* ================= BASIC VALIDATION ================= */
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "A valid email is required.";
    } elseif ($company_id === '' || !in_array($company_id, $companies, true)) {
        $error_msg = "Ticket Recipient (Company Email Domain) is required.";
    } elseif ($category === '' || !in_array($category, $allowed_categories, true)) {
        $error_msg = "Category is required.";
    } elseif ($description === '') {
        $error_msg = "Description is required.";
    }

    /* ================= PREPARE DESCRIPTION ================= */
    
    $raw_description = $description;
    $full_description = "REQUESTER NAME: $name\nREQUESTER EMAIL: $email\n\nDESCRIPTION:\n$description";

    /* ================= INSERT INTO DATABASE ================= */

    if (empty($error_msg)) {
        $has_requester_cols = true;
        $cols_to_ensure = [
            'requester_name' => "VARCHAR(255) NULL",
            'requester_email' => "VARCHAR(255) NULL"
        ];

        foreach ($cols_to_ensure as $col => $ddl) {
            $colRes = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE '$col'");
            if (!$colRes || $colRes->num_rows === 0) {
                $alterOk = $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
                if (!$alterOk) {
                    $has_requester_cols = false;
                    break;
                }
            }
        }

        if ($has_requester_cols) {
            $stmt = $conn->prepare("
                INSERT INTO employee_tickets
                (user_id, subject, category, priority, company, department, assigned_department, assigned_company, assigned_group, assigned_user_id, requester_name, requester_email, description, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO employee_tickets
                (user_id, subject, category, priority, company, department, assigned_department, assigned_company, assigned_group, assigned_user_id, description, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        }

        if(!$stmt){
            $error_msg = "System error. Please try again later.";
        } else {
            if ($has_requester_cols) {
                $stmt->bind_param(
                    "issssssssissss",
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
                    $name,
                    $email,
                    $raw_description,
                    $attachmentName
                );
            } else {
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
                    $full_description,
                    $attachmentName
                );
            }

            if($stmt->execute()){
                $ticket_id = (int) $stmt->insert_id;
                $success_msg = "Ticket successfully submitted! An admin will review it shortly.";

                $ticket_number = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);

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

                $newTicketNotifMsg = "New ticket #$ticket_number from $name was assigned to your group.";
                notif_insert_admins($conn, $ticket_id, $newTicketNotifMsg, 'new_ticket');

                foreach ($assigned_user_ids as $notifyUserId) {
                    $notifyUserId = (int) $notifyUserId;
                    if ($notifyUserId <= 0) continue;
                    notif_insert_system($conn, $notifyUserId, $ticket_id, $newTicketNotifMsg, 'dept_assigned');
                }

                $adminEmails = [];
                if (count($adminEmails) === 0) {
                    $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
                    if ($admins) {
                        while ($admin = $admins->fetch_assoc()) {
                            $adminEmails[] = $admin['email'];
                        }
                    }
                }

                $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
                $subjectLine = "New Sales Ticket (#$ticketNumber)";

                $attachments = [];
                $attachmentLabels = [];
                foreach ($uploadedFiles as $f) {
                    $stored = (string)($f['stored_name'] ?? '');
                    $original = trim((string)($f['original_name'] ?? ''));
                    if ($original !== '') {
                        $attachmentLabels[] = $original;
                    }
                    if ($stored === '') continue;
                    $path = realpath(__DIR__ . '/../uploads/' . $stored);
                    if ($path) {
                        $attachments[] = ['path' => $path];
                    }
                }
                $attachmentSummary = count($attachmentLabels) > 0
                    ? 'Attachments: ' . implode(', ', $attachmentLabels)
                    : '';

                $adminTpl = notif_email_simple('New Sales Ticket', [
                    "Ticket ID: #$ticketNumber",
                    "Title: $subject",
                    "Category: $category",
                    "Status: $ticketStatus",
                    "Requester: $email",
                    "Assigned Department: $assigned_department",
                    "Assigned Recipient: $assigned_company"
                ], 'Open Ticket', notif_ticket_link_admin($ticket_id));
                notif_email_send($adminEmails, $subjectLine, (string) $adminTpl['html'], (string) $adminTpl['text'], $attachments);

                $assigneeEmails = [];
                foreach ($assigned_user_ids as $notifyUserId) {
                    $notifyUserId = (int) $notifyUserId;
                    if ($notifyUserId <= 0) continue;
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
                        "Requester: $email",
                        "Assigned Department: $assigned_department",
                        "Assigned Recipient: $assigned_company",
                        "Description:\n$raw_description"
                    ];
                    if ($attachmentSummary !== '') {
                        $assigneeLines[] = $attachmentSummary;
                    }
                    $assigneeTpl = notif_email_simple('Ticket Assigned', $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks($ticket_id));
                    notif_email_send($assigneeEmails, "New Ticket Assigned (#$ticketNumber)", (string) $assigneeTpl['html'], (string) $assigneeTpl['text'], $attachments);
                }

                $requesterLines = [
                    "Ticket ID: #$ticketNumber",
                    "Category: $category",
                    "Status: $ticketStatus",
                    "Assigned Department: $assigned_department",
                    "Assigned Recipient: $assigned_company",
                    "Description:\n$raw_description"
                ];
                if ($attachmentSummary !== '') {
                    $requesterLines[] = $attachmentSummary;
                }
                $requesterTpl = notif_email_simple('Ticket Submitted', $requesterLines, 'Go To Helpdesk', notif_base_url() . '/ticketing/index.php');
                notif_email_send([$email], "Ticket Submitted (#$ticketNumber)", (string) $requesterTpl['html'], (string) $requesterTpl['text'], $attachments);

            } else {
                $error_msg = "Failed to submit ticket: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if ($isAjax && $_SERVER["REQUEST_METHOD"] == "POST") {
    header('Content-Type: application/json; charset=utf-8');
    if ($success_msg !== '') {
        echo json_encode(['ok' => true, 'message' => $success_msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error_msg !== '' ? $error_msg : 'Failed to submit ticket.'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Ticket Request | Leads Agri Helpdesk</title>
    <!-- Reuse existing CSS or inline minimal styles -->
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f3f4f6 url('../assets/img/leadss.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Inter', sans-serif;
            margin: 0;
        }
        .sales-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: linear-gradient(90deg, #1B5E20, #14532d);
            border-bottom: 3px solid #FBBF24;
            min-height: 96px;
        }
        .sales-topbar-inner {
            max-width: 100%;
            width: 100%;
            margin: 0 auto;
            padding: 22px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            position: relative;
            box-sizing: border-box;
        }
        .sales-logo {
            position: absolute;
            left: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .sales-logo img {
            height: 56px;
            width: 56px;
            object-fit: contain;
            background-color: #ffffff;
            padding: 6px;
            border-radius: 50%;
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
            display: block;
        }
        .sales-nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
            position: absolute;
            right: 24px;
        }
        .sales-nav-link {
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            transition: background 0.2s, color 0.2s, border-color 0.2s;
            white-space: nowrap;
        }
        .sales-nav-link:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #FDE68A;
            border-color: rgba(253, 230, 138, 0.65);
        }
        .sales-brand {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
            align-items: center;
            text-align: center;
        }
        .sales-brand-title {
            font-weight: 700;
            letter-spacing: 0.2px;
            color: #ffffff;
            font-size: 24px;
        }
        .sales-brand-subtitle {
            font-size: 18px;
            font-weight: 600;
            color: #FDE68A;
            margin-top: 3px;
        }
        @media (max-width: 768px) {
            .sales-topbar { min-height: 80px; }
            .sales-topbar-inner {
                padding: 14px 16px;
                display: grid;
                grid-template-columns: auto auto;
                grid-template-areas:
                    "logo title"
                    "subtitle subtitle"
                    "nav nav";
                justify-content: center;
                align-items: center;
                row-gap: 6px;
                column-gap: 10px;
            }
            .sales-logo {
                position: static;
                left: auto;
                grid-area: logo;
            }
            .sales-logo img { height: 44px; width: 44px; padding: 4px; }
            .sales-brand { display: contents; }
            .sales-brand-title {
                grid-area: title;
                font-size: 18px;
                font-weight: 600;
                text-align: left;
            }
            .sales-brand-subtitle {
                grid-area: subtitle;
                font-size: 14px;
                color: #FACC15;
                margin-top: 0;
                text-align: center;
            }
            .sales-nav-right {
                position: static;
                right: auto;
                grid-area: nav;
                width: 100%;
                justify-content: center;
                margin-top: 8px;
            }
            .sales-nav-link {
                width: 100%;
                max-width: 220px;
                justify-content: center;
                border-radius: 999px;
                padding: 8px 16px;
                font-size: 14px;
                background: #ffffff;
                color: #14532d;
                border-color: rgba(255, 255, 255, 0.6);
                box-shadow: 0 14px 30px rgba(2, 6, 23, 0.16);
                letter-spacing: 0.01em;
            }
            .sales-nav-link:hover {
                background: rgba(255, 255, 255, 0.92);
                color: #14532d;
                border-color: rgba(255, 255, 255, 0.75);
            }
            .sales-nav-link:active {
                transform: scale(0.98);
            }

            .sales-container {
                margin: 16px auto;
                padding: 22px 16px;
                border-radius: 16px;
                max-width: calc(100vw - 32px);
            }

            .header { margin-bottom: 20px; }
            .header h1 { font-size: 20px; margin-bottom: 6px; }
            .header p { font-size: 14px; }

            .form-row {
                display: flex;
                flex-direction: column;
                gap: 16px;
                margin-bottom: 20px;
            }

            .form-row.two-col {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-grid { gap: 20px; }

            input, select, textarea {
                font-size: 16px;
                border-radius: 14px;
            }

            input, select {
                height: 54px;
            }

            textarea {
                min-height: 140px;
                padding: 14px;
            }

            .file-control {
                width: 100%;
                border: 2px dashed #d1d5db;
                padding: 18px;
                border-radius: 14px;
                background: #f9fafb;
                text-align: center;
                flex-direction: column;
                align-items: center;
                gap: 12px;
            }
            .file-control:hover {
                border-color: rgba(27, 94, 32, 0.45);
                background: #ffffff;
            }
            .file-button {
                width: 100%;
                height: 54px;
                justify-content: center;
                border-radius: 14px;
                padding: 0 14px;
            }
            .file-name {
                width: 100%;
                white-space: normal;
                text-align: center;
            }

            .form-actions {
                display: flex;
                flex-direction: row;
                gap: 12px;
                margin-top: 25px;
                align-items: stretch;
                justify-content: stretch;
            }
            .form-actions button,
            .form-actions .btn-back {
                width: auto;
                flex: 1 1 0;
                height: 48px;
                border-radius: 14px;
                padding: 0 16px;
                font-size: 15px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                text-align: center;
            }
            .form-actions .btn-back {
                flex: 0 0 44%;
            }
            .form-actions .submit-btn {
                flex: 1 1 auto;
            }
        }
        @media (max-width: 640px) {
            .form-row.two-col { grid-template-columns: 1fr; }
        }
        .required-asterisk {
            color: #dc2626;
        }
        .sales-container {
            max-width: 1040px;
            margin: 24px auto;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #1B5E20;
            margin-bottom: 10px;
        }
        .header p {
            color: #6b7280;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            margin-bottom: 20px;
        }
        .form-row.two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .form-grid .form-group,
        .form-grid .form-row {
            width: 100%;
            margin-bottom: 0;
        }
        .form-grid input,
        .form-grid select,
        .form-grid textarea {
            width: 100%;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #1B5E20;
          
        }
        .category-select {
            height: 62px;
            padding: 0 52px 0 20px;
            border: 2px solid #73a66f;
            border-radius: 18px;
            background-color: #ffffff;
            color: #0f172a;
            font-size: 16px;
            line-height: 1.2;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, #1B5E20 50%),
                linear-gradient(135deg, #1B5E20 50%, transparent 50%);
            background-position:
                calc(100% - 24px) calc(50% - 3px),
                calc(100% - 18px) calc(50% - 3px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }
        .category-select:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        .category-select option {
            color: #0f172a;
            font-size: 16px;
        }
        button {
            width: 100%;
            padding: 14px;
            background: #1B5E20;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #144a1e;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
        }
        .form-actions button {
            width: auto;
            padding: 12px 18px;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 8px;
            border: 2px solid #1B5E20;
            background: #ffffff;
            color: #111827;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-back:hover {
            background: #f3f4f6;
            border-color: #14532d;
        }
        .file-control {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8faf9;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 10px 12px;
        }
        .file-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #ecfdf5;
            color: #1B5E20;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 8px 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .file-button:hover {
            background: #d1fae5;
            border-color: #86efac;
        }
        .file-name {
            color: #6b7280;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-hidden {
            display: none;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            text-decoration: none;
        }
        .back-link:hover {
            color: #1B5E20;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .ticket-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 9999;
            padding: 20px;
            box-sizing: border-box;
        }
        .ticket-modal.show { opacity: 1; pointer-events: all; }
        .ticket-modal-content {
            background: #ffffff;
            padding: 22px 22px 18px;
            border-radius: 18px;
            text-align: center;
            width: 360px;
            max-width: calc(100vw - 40px);
            animation: popIn 0.3s ease;
            border: 1px solid rgba(27, 94, 32, 0.18);
            box-shadow: 0 26px 80px rgba(2, 6, 23, 0.22);
            position: relative;
            overflow: hidden;
        }
        .ticket-modal-content::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #1B5E20, #144a1e);
        }
        .ticket-modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 999px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #1B5E20;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            font-weight: 900;
            margin: 6px auto 12px;
        }
        .ticket-modal-icon.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .ticket-modal-spinner {
            width: 56px;
            height: 56px;
            border-radius: 999px;
            border: 4px solid #e2e8f0;
            border-top-color: #1B5E20;
            margin: 8px auto 12px;
            animation: spin 0.9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .ticket-modal-content h3 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #0f172a;
        }
        .ticket-modal-content p {
            margin: 0 0 16px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.45;
        }
        .ticket-modal-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 6px;
        }
        .ticket-modal-content button {
            width: auto;
            border: 1px solid rgba(20, 74, 30, 0.28);
            background: #1B5E20;
            color: #ffffff;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 900;
            cursor: pointer;
        }
        .ticket-modal-content button:hover { background: #144a1e; }
        .ticket-modal[data-state="loading"] .ticket-modal-icon,
        .ticket-modal[data-state="loading"] .ticket-modal-actions { display: none; }
        .ticket-modal[data-state="success"] .ticket-modal-spinner,
        .ticket-modal[data-state="success"] .ticket-modal-icon.error { display: none; }
        .ticket-modal[data-state="error"] .ticket-modal-spinner,
        .ticket-modal[data-state="error"] .ticket-modal-icon.success { display: none; }
        @keyframes popIn {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @media (min-width: 900px) and (orientation: landscape) {
            .sales-container {
                max-width: 1040px;
                margin: 16px auto;
                padding: 24px;
            }

            .header {
                margin-bottom: 16px;
            }

            .header h1 {
                margin-bottom: 6px;
            }

            .form-group {
                margin-bottom: 0;
            }

            label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            input, select, textarea {
                padding: 10px 12px;
            }

            form {
                display: block;
            }

            textarea[name="description"] {
                height: 120px;
                resize: none;
            }

            button {
                width: auto;
                justify-self: end;
                padding: 12px 18px;
            }

            .back-link {
                margin-top: 14px;
            }
        }
    </style>
</head>
<body>

<header class="sales-topbar">
    <div class="sales-topbar-inner">
        <div class="sales-logo">
            <img src="../assets/img/UPDATEDlogo.png" alt="Leads Agri Logo">
        </div>
        <div class="sales-brand">
            <div class="sales-brand-title">Leads Agri Helpdesk</div>
            <div class="sales-brand-subtitle">Sales Ticket Request</div>
        </div>
        <div class="sales-nav-right">
            <a class="sales-nav-link" href="/ticketing/sales/knowledge_base.php">Knowledge Base</a>
        </div>
    </div>
</header>

<div class="sales-container">
    <div class="header">
        <h1>Create a Ticket </h1>
        <p>Please fill out the form below.</p>
    </div>

        <?php if($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <a href="../index.php" class="back-link">Back</a>
    <?php else: ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="alert alert-error" id="ajaxError" style="display:none;"></div>

        <form id="ticketForm" method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="form-grid">
            <div class="form-group">
                <label>Your Email <span class="required-asterisk">*</span></label>
                <input type="email" name="email" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-row two-col">
                <div class="form-group">
                    <label>Ticket Recipient <span class="required-asterisk">*</span></label>
                    <select name="company_id" required>
                        <option value="" disabled selected hidden>Select Recipient</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= (isset($company_id) && $company_id === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Department <span class="required-asterisk">*</span></label>
                    <select name="assigned_department" required>
                        <option value="" disabled <?= $assigned_department_selected === '' ? 'selected' : '' ?> hidden>Select Department</option>
                        <?php foreach (ticket_standard_assigned_departments() as $d): ?>
                            <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $assigned_department_selected === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Category <span class="required-asterisk">*</span></label>
                <select name="category" class="category-select" required>
                    <option value="" disabled hidden <?= ($category ?? '') === '' ? 'selected' : '' ?>>Choose category</option>
                    <option value="Documentation" <?= ($category ?? '') === 'Documentation' ? 'selected' : '' ?>>Documentation</option>
                    <option value="Email" <?= ($category ?? '') === 'Email' ? 'selected' : '' ?>>Email</option>
                    <option value="Hardware" <?= ($category ?? '') === 'Hardware' ? 'selected' : '' ?>>Hardware</option>
                    <option value="Internet Concerns" <?= ($category ?? '') === 'Internet Concerns' ? 'selected' : '' ?>>Internet Concerns</option>
                    <option value="Procurement" <?= ($category ?? '') === 'Procurement' ? 'selected' : '' ?>>Procurement</option>
                    <option value="Software" <?= ($category ?? '') === 'Software' ? 'selected' : '' ?>>Software</option>
                    <option value="Technical Support" <?= ($category ?? '') === 'Technical Support' ? 'selected' : '' ?>>Technical Support</option>
                </select>
            </div>

            <div class="form-group">
                <label>Description <span class="required-asterisk">*</span></label>
                <textarea name="description" rows="5" required placeholder="Describe your issue in detail..."><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="form-group">
                <label>Attachment (Optional)</label>
                <div class="file-control">
                    <button type="button" id="choose-file-btn" class="file-button" aria-label="Choose file">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M20 17.5A3.5 3.5 0 0 1 16.5 21H7a5 5 0 0 1-1-9.9V11a6 6 0 0 1 11.53-1.999.75.75 0 1 1-1.4.55A4.5 4.5 0 0 0 7.75 11v.77a.75.75 0 0 1-.63.74A3.5 3.5 0 0 0 7 19.5h9.5A2 2 0 0 0 18.5 15a.75.75 0 1 1 1.5 0zM12 7.5a.75.75 0 0 1 .75.75V12h1.94a.75.75 0 1 1 0 1.5H12.75v1.94a.75.75 0 0 1-1.5 0V13.5H9.31a.75.75 0 1 1 0-1.5h1.94V8.25A.75.75 0 0 1 12 7.5z"/>
                        </svg>
                        <span>Choose File</span>
                    </button>
                    <span id="file-name" class="file-name">No file chosen</span>
                    <input type="file" name="attachments[]" id="attachments" class="file-hidden" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                </div>
                <small style="display:block; margin-top:5px; color:#666;">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)</small>
                <div id="attachment-error" style="display:none;margin-top:10px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;font-weight:700;"></div>
                <div id="attachment-toast" role="alert" aria-live="assertive" style="position:fixed;top:18px;right:18px;z-index:9999;display:none;max-width:min(420px, calc(100vw - 36px));background:#991b1b;color:#ffffff;padding:12px 14px;border-radius:12px;box-shadow:0 16px 40px rgba(2,6,23,0.22);font-weight:800;font-size:13px;"></div>
                <div id="attachment-preview" style="margin-top: 10px;"></div>
            </div>
            </div>

            <div class="form-actions">
                <a href="../index.php" class="btn-back">Back</a>
                <button type="submit" class="submit-btn">Submit Ticket</button>
            </div>
        </form>

    <?php endif; ?>
</div>

<div id="successModal" class="ticket-modal" aria-hidden="true">
    <div class="ticket-modal-content" role="dialog" aria-modal="true" aria-labelledby="successModalTitle">
        <div class="ticket-modal-spinner" id="ticketModalSpinner"></div>
        <div class="ticket-modal-icon success" id="ticketModalSuccessIcon">✓</div>
        <div class="ticket-modal-icon error" id="ticketModalErrorIcon">!</div>
        <h3 id="successModalTitle">Submitting Ticket</h3>
        <p id="successModalDesc">Please wait while we submit your ticket.</p>
        <div class="ticket-modal-actions" id="ticketModalActions">
            <button type="button" id="ticketModalDoneBtn">Done</button>
        </div>
    </div>
</div>

<script>
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
var ALLOWED_EXT = ['jpg','jpeg','png','pdf','doc','docx'];
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

        var size = document.createElement('div');
        var kb = Math.round((file.size || 0) / 1024);
        size.textContent = kb + ' KB';
        size.style.color = '#64748b';
        size.style.fontSize = '12px';
        size.style.fontWeight = '600';

        meta.appendChild(name);
        meta.appendChild(size);

        var right = document.createElement('div');
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = '×';
        removeBtn.style.border = '1px solid #e2e8f0';
        removeBtn.style.background = '#ffffff';
        removeBtn.style.color = '#ef4444';
        removeBtn.style.fontWeight = '800';
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
        right.appendChild(removeBtn);

        left.appendChild(icon);
        left.appendChild(meta);

        row.appendChild(left);
        row.appendChild(right);
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

function getExt(name) {
    var parts = String(name || '').toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
}

if (attachmentInput) {
    attachmentInput.addEventListener('change', function (e) {
        var blockedMax = false;
        Array.from(e.target.files || []).forEach(function (file) {
            if (dt.files.length >= MAX_FILES) {
                blockedMax = true;
                return;
            }
            var ext = getExt(file && file.name);
            if (ALLOWED_EXT.indexOf(ext) === -1) {
                showError('Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.');
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
            showError(dt.files.length > MAX_FILES ? 'Maximum 5 attachments allowed.' : (badType ? 'Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.' : 'Attachment too large. Max 5MB total.'));
            return;
        }
        showError('');
    });
}
</script>

<script>
function closeModal(){
    var m = document.getElementById('successModal');
    if (!m) return;
    m.classList.remove('show');
    m.setAttribute('aria-hidden', 'true');
    m.setAttribute('data-state', '');
    var t = document.getElementById('successModalTitle');
    var d = document.getElementById('successModalDesc');
    if (t) t.textContent = 'Submitting Ticket';
    if (d) d.textContent = 'Please wait while we submit your ticket.';
}

(function () {
    var form = document.getElementById('ticketForm');
    var modal = document.getElementById('successModal');
    var ajaxError = document.getElementById('ajaxError');
    var doneBtn = document.getElementById('ticketModalDoneBtn');
    var descriptionField = form ? form.querySelector('textarea[name="description"]') : null;
    if (!form) return;

    function validateDescription() {
        if (!descriptionField) return true;
        var value = String(descriptionField.value || '').trim();
        descriptionField.setCustomValidity(value === '' ? 'Description is required.' : '');
        return value !== '';
    }

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }
    if (doneBtn) {
        doneBtn.addEventListener('click', function () {
            if (!modal) return;
            var state = modal.getAttribute('data-state') || '';
            if (state === 'success') {
                window.location.href = '../index.php';
                return;
            }
            closeModal();
        });
    }
    if (descriptionField) {
        descriptionField.addEventListener('input', validateDescription);
        descriptionField.addEventListener('blur', validateDescription);
    }

    form.addEventListener('submit', function(e) {
        if (e.defaultPrevented) return;
        if (!validateDescription()) {
            e.preventDefault();
            if (typeof descriptionField.reportValidity === 'function') {
                descriptionField.reportValidity();
            }
            return;
        }
        e.preventDefault();
        if (ajaxError) ajaxError.style.display = 'none';

        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        if (modal) {
            modal.setAttribute('data-state', 'loading');
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            var t = document.getElementById('successModalTitle');
            var d = document.getElementById('successModalDesc');
            if (t) t.textContent = 'Submitting Ticket';
            if (d) d.textContent = 'Please wait while we submit your ticket.';
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
                var msg = (data && data.error) ? data.error : 'Failed to submit ticket.';
                if (ajaxError) {
                    ajaxError.textContent = msg;
                    ajaxError.style.display = 'block';
                }
                if (modal) {
                    modal.setAttribute('data-state', 'error');
                    var t = document.getElementById('successModalTitle');
                    var d = document.getElementById('successModalDesc');
                    if (t) t.textContent = 'Submission Failed';
                    if (d) d.textContent = msg;
                }
            if (doneBtn) doneBtn.textContent = 'Close';
                return;
            }

            if (modal) {
                modal.setAttribute('data-state', 'success');
                var t = document.getElementById('successModalTitle');
                var d = document.getElementById('successModalDesc');
                if (t) t.textContent = 'Ticket Submitted';
                if (d) d.textContent = 'Your ticket has been successfully created.';
            }
        if (doneBtn) doneBtn.textContent = 'Done';
            form.reset();
            if (typeof dt !== 'undefined') {
                dt = new DataTransfer();
                if (typeof syncFiles === 'function') syncFiles();
                if (typeof showError === 'function') showError('');
            }
        })
        .catch(function () {
            if (ajaxError) {
                ajaxError.textContent = 'Failed to submit ticket.';
                ajaxError.style.display = 'block';
            }
            if (modal) {
                modal.setAttribute('data-state', 'error');
                var t = document.getElementById('successModalTitle');
                var d = document.getElementById('successModalDesc');
                if (t) t.textContent = 'Submission Failed';
                if (d) d.textContent = 'Failed to submit ticket.';
            }
            if (doneBtn) doneBtn.textContent = 'Close';
        })
        .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
        });
    });
})();
</script>

</body>
</html>
