<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';

$success_msg = "";
$error_msg = "";

$email = '';
$company_id = '';
$type_id = '';
$subject = '';
$description = '';

$companies = [
    "FARMEX",
    "Golden Primestocks Chemical Inc - GPCI",
    "Leads Agricultural products corporation - LAPC",
    "Leads Tech Corporation - LTC",
];

$types = ['Network Issue','Hardware Issue','Software Issue','Email Problem','Account Access','Technical Support','Other'];

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    $email      = trim((string)($_POST['email'] ?? ''));
    $company_id = trim((string)($_POST['company_id'] ?? ''));
    $type_id    = trim((string)($_POST['type_id'] ?? ''));
    $subject    = trim((string)($_POST['subject'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    $name = derive_name_from_email($email);
    $company = $company_id;
    $department = 'Sales';
    $category = $type_id;
    $priority = 'Low';
    $assigned_department = 'IT';
    $assigned_company = '';

    $attachmentName = null;
    $uploadedFiles = [];

    /* ================= FILE UPLOAD ================= */

    if (isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
        $movedPaths = [];
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
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
            if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
                $error_msg = "Attachment too large. Max 5MB per file.";
                break;
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
        $error_msg = "Company / Subsidiary is required.";
    } elseif ($type_id === '' || !in_array($type_id, $types, true)) {
        $error_msg = "Type is required.";
    } elseif ($subject === '') {
        $error_msg = "Subject is required.";
    } elseif ($description === '') {
        $error_msg = "Description is required.";
    }

    /* ================= PREPARE DESCRIPTION ================= */
    
    $raw_description = "COMPANY: $company\nTYPE: $category\n\n$description";
    $full_description = "REQUESTER NAME: $name\nREQUESTER EMAIL: $email\nCOMPANY: $company\nTYPE: $category\n\nDESCRIPTION:\n$description";

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
                (user_id, subject, category, priority, company, department, assigned_department, assigned_company, requester_name, requester_email, description, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO employee_tickets
                (user_id, subject, category, priority, company, department, assigned_department, assigned_company, description, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        }

        if(!$stmt){
            $error_msg = "System error. Please try again later.";
        } else {
            if ($has_requester_cols) {
                $stmt->bind_param(
                    "isssssssssss",
                    $user_id,
                    $subject,
                    $category,
                    $priority,
                    $company,
                    $department,
                    $assigned_department,
                    $assigned_company,
                    $name,
                    $email,
                    $raw_description,
                    $attachmentName
                );
            } else {
                $stmt->bind_param(
                    "isssssssss",
                    $user_id,
                    $subject,
                    $category,
                    $priority,
                    $company,
                    $department,
                    $assigned_department,
                    $assigned_company,
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
                            Subject: <strong>$ticketSubjectSafe</strong><br>
                            Requested by: <strong>$requesterEmailSafe</strong><br>
                            Company: <strong>$companySafe</strong><br>
                            Type: <strong>$typeSafe</strong>
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";

                $bodyText = "A new Sales ticket has been submitted.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: $subject\n"
                    . "Requested by: $email\n"
                    . "Company: $company\n"
                    . "Type: $category\n\n"
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
            max-width: 1100px;
            margin: 0 auto;
            padding: 22px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .sales-nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
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
        <h1>Submit a Ticket </h1>
        <p>Please fill out the form below.</p>
    </div>

        <?php if($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <a href="../index.php" class="back-link">Back</a>
    <?php else: ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>

            <div class="form-grid">
            <div class="form-group">
                <label>Your Email *</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="form-group">
                <label>Company / Subsidiary *</label>
                <select name="company_id" required>
                    <option value="" disabled selected hidden>Select Company</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>" <?= (isset($company_id) && $company_id === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Type *</label>
                <select name="type_id" required>
                    <option value="" <?= empty($type_id) ? 'selected' : '' ?>>Select Type</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" <?= (isset($type_id) && $type_id === $t) ? 'selected' : '' ?>><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Subject *</label>
                <input type="text" name="subject" required placeholder="Brief summary of the issue" value="<?= htmlspecialchars($subject ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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
                <small style="display:block; margin-top:5px; color:#666;">Supported formats: JPG, PNG, PDF, DOCX (Max 5MB)</small>
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

<script>
var attachmentInput = document.getElementById('attachments');
var chooseBtn = document.getElementById('choose-file-btn');
var fileNameEl = document.getElementById('file-name');
var preview = document.getElementById('attachment-preview');
var dt = new DataTransfer();
var objectUrls = [];

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

        var left = document.createElement('div');
        left.style.display = 'flex';
        left.style.alignItems = 'center';
        left.style.gap = '10px';
        left.style.minWidth = '0';

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
            var url = URL.createObjectURL(file);
            objectUrls.push(url);
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
        removeBtn.textContent = 'Remove';
        removeBtn.style.border = '1px solid #e2e8f0';
        removeBtn.style.background = '#ffffff';
        removeBtn.style.color = '#ef4444';
        removeBtn.style.fontWeight = '800';
        removeBtn.style.padding = '8px 10px';
        removeBtn.style.borderRadius = '10px';
        removeBtn.style.cursor = 'pointer';
        removeBtn.addEventListener('click', function () {
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

if (attachmentInput) {
    attachmentInput.addEventListener('change', function (e) {
        Array.from(e.target.files || []).forEach(function (file) {
            var exists = Array.from(dt.files).some(function (f) {
                return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
            });
            if (!exists) dt.items.add(file);
        });
        attachmentInput.value = '';
        syncFiles();
    });
}
</script>

</body>
</html>
