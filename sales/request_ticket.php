<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

$success_msg = "";
$error_msg = "";

$email = '';
$company_id = '';
$category = '';
$description = '';
$assigned_department_selected = '';

$companies = [
    "@leadstech-corp.com",
    "@gpsci.net",
    "@leadsagri.com",
    "@leads-farmex.com",
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
    $allowed_categories = ['Hardware', 'Software', 'Documentation', 'Email', 'Internet Concerns', 'Procurement'];
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
    $assigned_user_id = ticket_find_assignee_id($conn, $assigned_company, $assigned_group);
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
        $error_msg = "System error. Please try again later.";
    }

    if (empty($error_msg) && empty($user_id)) {
        $guest_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $guest_name = 'Sales Department';
        $guest_company = 'Sales';
        $guest_department = 'Sales';
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

                $admin_result = $conn->query("SELECT id FROM users WHERE role = 'admin'");
                if ($admin_result) {
                    $notif_msg = "New ticket #$ticket_number from $email (Sales)";
                    $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, 'new_ticket')");
                    if ($notif_stmt) {
                        while ($admin = $admin_result->fetch_assoc()) {
                            $admin_id = (int) $admin['id'];
                            $notif_stmt->bind_param("iis", $admin_id, $ticket_id, $notif_msg);
                            $notif_stmt->execute();
                        }
                        $notif_stmt->close();
                    }
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
                $ticketSubjectSafe = htmlspecialchars($subject);
                $requesterEmailSafe = htmlspecialchars($email);
                $companySafe = htmlspecialchars($company);
                $typeSafe = htmlspecialchars($category);

                $bodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>A new Sales ticket has been submitted.</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Category: <strong>$typeSafe</strong><br>
                            Requested by: <strong>$requesterEmailSafe</strong><br>
                            Company: <strong>$companySafe</strong><br>
                            Department: <strong>Sales</strong>
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";

                $bodyText = "A new Sales ticket has been submitted.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Category: $category\n"
                    . "Requested by: $email\n"
                    . "Company: $company\n"
                    . "Department: Sales\n\n"
                    . "Login to the system to view the ticket.\n";

                $attachments = [];
                foreach ($uploadedFiles as $f) {
                    $stored = (string)($f['stored_name'] ?? '');
                    if ($stored === '') continue;
                    $path = realpath(__DIR__ . '/../uploads/' . $stored);
                    if ($path) {
                        $attachments[] = ['path' => $path];
                    }
                }

                sendSmtpEmail($adminEmails, $subjectLine, $bodyHtml, $bodyText, $attachments);

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
        @media (max-width: 640px) {
            .sales-topbar { min-height: 80px; }
            .sales-topbar-inner { padding: 18px 16px; }
            .sales-logo { left: 20px; }
            .sales-logo img { height: 44px; width: 44px; padding: 4px; }
            .sales-nav-right { right: 16px; }
            .sales-brand-title { font-size: 20px; }
            .sales-brand-subtitle { font-size: 14px; }
        }
        .sales-container {
            max-width: 780px;
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
            background: rgba(2, 6, 23, 0.45);
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
            background: white;
            padding: 26px 22px;
            border-radius: 14px;
            text-align: center;
            width: 320px;
            max-width: calc(100vw - 40px);
            animation: popIn 0.3s ease;
            border: 1px solid #e5e7eb;
            box-shadow: 0 22px 60px rgba(2, 6, 23, 0.18);
        }
        .check-icon {
            width: 52px;
            height: 52px;
            border-radius: 999px;
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #1B5E20;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 900;
            margin: 0 auto 12px;
        }
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
        .ticket-modal-content button {
            width: auto;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #0f172a;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 800;
            cursor: pointer;
        }
        .ticket-modal-content button:hover { background: #f8fafc; }
        @keyframes popIn {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        @media (min-width: 900px) and (orientation: landscape) {
            .sales-container {
                max-width: 780px;
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
            <img src="../assets/img/logo.png" alt="Leads Agri Logo">
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
                <label>Your Email *</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label>Ticket Recipient *</label>
                <select name="company_id" required>
                    <option value="" disabled selected hidden>Select Recipient</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= (isset($company_id) && $company_id === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Department *</label>
                <select name="assigned_department" required>
                    <option value="" disabled <?= $assigned_department_selected === '' ? 'selected' : '' ?> hidden>Select Department</option>
                    <?php foreach (ticket_standard_assigned_departments() as $d): ?>
                        <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $assigned_department_selected === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Category *</label>
                <select name="category" required>
                    <option value="" disabled <?= ($category ?? '') === '' ? 'selected' : '' ?> hidden>Select Category</option>
                    <option value="Hardware" <?= ($category ?? '') === 'Hardware' ? 'selected' : '' ?>>Hardware</option>
                    <option value="Software" <?= ($category ?? '') === 'Software' ? 'selected' : '' ?>>Software</option>
                    <option value="Documentation" <?= ($category ?? '') === 'Documentation' ? 'selected' : '' ?>>Documentation</option>
                    <option value="Email" <?= ($category ?? '') === 'Email' ? 'selected' : '' ?>>Email</option>
                    <option value="Internet Concerns" <?= ($category ?? '') === 'Internet Concerns' ? 'selected' : '' ?>>Internet Concerns</option>
                    <option value="Procurement" <?= ($category ?? '') === 'Procurement' ? 'selected' : '' ?>>Procurement</option>
                </select>
            </div>

            <div class="form-group">
                <label>Description *</label>
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
                <small style="display:block; margin-top:5px; color:#666;">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files, 5MB total)</small>
                <div id="attachment-error" style="display:none;margin-top:10px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;font-weight:700;"></div>
                <div id="attachment-total" style="margin-top:10px;color:#475569;font-weight:800;font-size:12px;white-space:nowrap;"></div>
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
        <div class="check-icon">✓</div>
        <h3 id="successModalTitle">Ticket Submitted</h3>
        <p>Your ticket has been successfully created.</p>
        <button type="button" onclick="closeModal()">Close</button>
    </div>
</div>

<script>
var attachmentInput = document.getElementById('attachments');
var chooseBtn = document.getElementById('choose-file-btn');
var fileNameEl = document.getElementById('file-name');
var preview = document.getElementById('attachment-preview');
var errorEl = document.getElementById('attachment-error');
var totalEl = document.getElementById('attachment-total');
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

function setTotal() {
    if (!totalEl) return;
    var total = 0;
    Array.from(dt.files).forEach(function (f) { total += (f && f.size) ? f.size : 0; });
    totalEl.textContent = 'Total: ' + formatSize(total) + ' / 5 MB';
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
    setTotal();
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
    if (m) m.classList.remove('show');
}

(function () {
    var form = document.getElementById('ticketForm');
    var modal = document.getElementById('successModal');
    var ajaxError = document.getElementById('ajaxError');
    if (!form) return;

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (ajaxError) ajaxError.style.display = 'none';

        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

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
                return;
            }

            if (modal) modal.classList.add("show");
            form.reset();
            if (typeof dt !== 'undefined') {
                dt = new DataTransfer();
                if (typeof syncFiles === 'function') syncFiles();
                if (typeof setTotal === 'function') setTotal();
                if (typeof showError === 'function') showError('');
            }
        })
        .catch(function () {
            if (ajaxError) {
                ajaxError.textContent = 'Failed to submit ticket.';
                ajaxError.style.display = 'block';
            }
        })
        .finally(function () {
            if (submitBtn) submitBtn.disabled = false;
        });
    });
})();
</script>

</body>
</html>
