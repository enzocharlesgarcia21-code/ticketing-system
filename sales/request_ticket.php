<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    $name       = trim($_POST['name']);
    $email      = trim($_POST['email']);
    $subject    = $_POST['subject'] ?? '';
    $category   = $_POST['category'] ?? '';
    $priority   = $_POST['priority'] ?? '';
    $company    = $_POST['company'] ?? '';
    $department = "Sales"; // Fixed department
    $assigned_department = $_POST['assigned_department'] ?? '';
    $assigned_company = $_POST['assigned_company'] ?? '';
    $description = !empty($_POST['description']) ? $_POST['description'] : '';

    $attachmentName = NULL;

    /* ================= FILE UPLOAD ================= */

    if(isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
        $allowedTypes = ['jpg','jpeg','png','pdf','doc','docx'];
        $fileName = $_FILES['attachment']['name'];
        $fileTmp  = $_FILES['attachment']['tmp_name'];
        $fileSize = $_FILES['attachment']['size'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if(in_array($fileExt, $allowedTypes) && $fileSize <= 5 * 1024 * 1024) {
            if(!is_dir("../uploads")){
                mkdir("../uploads", 0777, true);
            }
            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath  = "../uploads/" . $newFileName;
            if(move_uploaded_file($fileTmp, $uploadPath)) {
                $attachmentName = $newFileName;
            }
        }
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
    $valid_departments = ['Accounting','Admin','Bidding','E-Comm','HR','IT','Marketing','Sales'];
    if (empty($assigned_department) || !in_array($assigned_department, $valid_departments, true)) {
        $error_msg = "Assigned Department is required.";
    }

    $valid_companies = [
        "FARMASEE",
        "FARMEX",
        "Golden Primestocks Chemical Inc - GPSCI",
        "Leads Animal Health - LAH",
        "Leads Environmental Health - LEH",
        "Leads Tech Corporation - LTC",
        "LINGAP LEADS FOUNDATION - Lingap",
        "Malveda Holdings Corporation - MHC",
        "Malveda Properties & Development Corporation - MPDC",
        "Primestocks Chemical Corporation - PCC"
    ];
    if (empty($assigned_company) || !in_array($assigned_company, $valid_companies, true)) {
        $error_msg = "Assigned Company is required.";
    }

    /* ================= PREPARE DESCRIPTION ================= */
    
    $raw_description = $description;
    $full_description = "REQUESTER NAME: $name\nREQUESTER EMAIL: $email\n\nDESCRIPTION:\n$raw_description";

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

                if (!empty($assigned_department) && !empty($assigned_company)) {
                    $dept_users_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'employee' AND department = ? AND company = ?");
                    if ($dept_users_stmt) {
                        $dept_users_stmt->bind_param("ss", $assigned_department, $assigned_company);
                        $dept_users_stmt->execute();
                        $dept_users_res = $dept_users_stmt->get_result();

                        $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
                        if ($dept_notif_stmt) {
                            $dept_type = 'dept_assigned';
                            $dept_msg = "New ticket #$ticket_number from $name was assigned to your department.";
                            while ($u = $dept_users_res->fetch_assoc()) {
                                $target_user_id = (int) $u['id'];
                                $dept_notif_stmt->bind_param("iiss", $target_user_id, $ticket_id, $dept_msg, $dept_type);
                                $dept_notif_stmt->execute();
                            }
                            $dept_notif_stmt->close();
                        }

                        $dept_users_stmt->close();
                    }
                }

                $admin_result = $conn->query("SELECT id FROM users WHERE role = 'admin'");
                if ($admin_result) {
                    $notif_msg = "New $priority priority ticket #$ticket_number from $name - Sales";
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
                if ($assigned_department !== '') {
                    $adminStmt = $conn->prepare("SELECT email FROM users WHERE role = 'admin' AND email <> '' AND (department = ? OR department IS NULL OR department = '')");
                    if ($adminStmt) {
                        $adminStmt->bind_param("s", $assigned_department);
                        $adminStmt->execute();
                        $adminRes = $adminStmt->get_result();
                        if ($adminRes) {
                            while ($admin = $adminRes->fetch_assoc()) {
                                $adminEmails[] = $admin['email'];
                            }
                        }
                        $adminStmt->close();
                    }
                }
                if (count($adminEmails) === 0) {
                    $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
                    if ($admins) {
                        while ($admin = $admins->fetch_assoc()) {
                            $adminEmails[] = $admin['email'];
                        }
                    }
                }

                $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
                $subjectLine = "New Ticket Assigned (#$ticketNumber)";
                $prioritySafe = htmlspecialchars($priority);
                $ticketSubjectSafe = htmlspecialchars($subject);
                $requesterNameSafe = htmlspecialchars($name);
                $assignedDeptSafe = htmlspecialchars($assigned_department);
                $assignedCompanySafe = htmlspecialchars($assigned_company);

                $bodyHtml = "
                    <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                        <h2 style='margin:0 0 12px 0'>A new support ticket has been assigned to your department.</h2>
                        <p style='margin:0 0 16px 0'>
                            Ticket ID: <strong>#$ticketNumber</strong><br>
                            Subject: <strong>$ticketSubjectSafe</strong><br>
                            Priority: <strong>$prioritySafe</strong><br>
                            Requested by: <strong>$requesterNameSafe</strong><br>
                            Assigned to: <strong>$assignedDeptSafe</strong>" . ($assigned_company !== '' ? " (<strong>$assignedCompanySafe</strong>)" : "") . "
                        </p>
                        <p style='margin:0'>Login to the system to view the ticket.</p>
                    </div>
                ";

                $bodyText = "A new support ticket has been assigned to your department.\n\n"
                    . "Ticket ID: #$ticketNumber\n"
                    . "Subject: $subject\n"
                    . "Priority: $priority\n"
                    . "Requested by: $name\n"
                    . "Assigned to: $assigned_department" . ($assigned_company !== '' ? " ($assigned_company)" : "") . "\n\n"
                    . "Login to the system to view the ticket.\n";

                $attachments = [];
                if (!empty($attachmentName)) {
                    $path = realpath(__DIR__ . '/../uploads/' . $attachmentName);
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
            background: #f3f4f6;
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
            justify-content: flex-start;
            gap: 16px;
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
            max-width: 800px;
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
                max-width: 1100px;
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
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px 20px;
            }

            form > .form-group:nth-of-type(9),
            form > .form-group:nth-of-type(10),
            form > .form-actions {
                grid-column: 1 / -1;
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
    </div>
</header>

<div class="sales-container">
    <div class="header">
        <h1>Submit a Ticket </h1>
        <p>Please fill out the form below.</p>
    </div>

    <?php if($success_msg): ?>
        <div class="alert alert-success"><?= $success_msg ?></div>
        <a href="../index.php" class="back-link">Back</a>
    <?php else: ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error"><?= $error_msg ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            
            <div class="form-group">
                <label> Name *</label>
                <input type="text" name="name" required placeholder=" ">
            </div>

            <div class="form-group">
                <label> Email *</label>
                <input type="email" name="email" required placeholder="">
            </div>

            <div class="form-group">
                <label>Subject *</label>
                <input type="text" name="subject" required placeholder="Brief summary of the issue">
            </div>

            <div class="form-group">
                <label>Category *</label>
                <select name="category" required>
                    <option value=""disabled selected hidden>Select Category</option>
                    <option value="Network Issue">Network Issue</option>
                    <option value="Hardware Issue">Hardware Issue</option>
                    <option value="Software Issue">Software Issue</option>
                    <option value="Email Problem">Email Problem</option>
                    <option value="Account Access">Account Access</option>
                    <option value="Technical Support">Technical Support</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="form-group">
                <label>Priority *</label>
                <select name="priority" required>
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                    <option value="Critical">Critical</option>
                </select>
            </div>

            <div class="form-group">
                <label>Company / Subsidiary *</label>
                <select name="company" required>
                    <option value=""disabled selected hidden>Select Company</option>
                    <option value="FARMASEE">FARMASEE</option>
                    <option value="FARMEX">FARMEX</option>
                    <option value="Golden Primestocks Chemical Inc - GPSCI">Golden Primestocks Chemical Inc - GPSCI</option>
                    <option value="Leads Animal Health - LAH">Leads Animal Health - LAH</option>
                    <option value="Leads Environmental Health - LEH">Leads Environmental Health - LEH</option>
                    <option value="Leads Tech Corporation - LTC">Leads Tech Corporation - LTC</option>
                    <option value="LINGAP LEADS FOUNDATION - Lingap">LINGAP LEADS FOUNDATION - Lingap</option>
                    <option value="Malveda Holdings Corporation - MHC">Malveda Holdings Corporation - MHC</option>
                    <option value="Malveda Properties & Development Corporation - MPDC">Malveda Properties & Development Corporation - MPDC</option>
                    <option value="Primestocks Chemical Corporation - PCC">Primestocks Chemical Corporation - PCC</option>
                </select>
            </div>

            <div class="form-group">
                <label>Assigned Department * </label>
                <select name="assigned_department" required>
                    <option value=""disabled selected hidden>Select Department</option>
                    <option value="Accounting">Accounting</option>
                    <option value="Admin">Admin</option>
                    <option value="Bidding">Bidding</option>
                    <option value="E-Comm">E-Comm</option>
                    <option value="HR">HR</option>
                    <option value="IT">IT</option>
                    <option value="Marketing">Marketing</option>
                    <option value="Sales">Sales</option>
                </select>
            </div>

            <div class="form-group">
                <label>Assigned Company / Subsidiary *</label>
                <select name="assigned_company" required>
                    <option value=""disabled selected hidden>Select Company</option>
                    <option value="FARMASEE">FARMASEE</option>
                    <option value="FARMEX">FARMEX</option>
                    <option value="Golden Primestocks Chemical Inc - GPSCI">Golden Primestocks Chemical Inc - GPSCI</option>
                    <option value="Leads Animal Health - LAH">Leads Animal Health - LAH</option>
                    <option value="Leads Environmental Health - LEH">Leads Environmental Health - LEH</option>
                    <option value="Leads Tech Corporation - LTC">Leads Tech Corporation - LTC</option>
                    <option value="LINGAP LEADS FOUNDATION - Lingap">LINGAP LEADS FOUNDATION - Lingap</option>
                    <option value="Malveda Holdings Corporation - MHC">Malveda Holdings Corporation - MHC</option>
                    <option value="Malveda Properties & Development Corporation - MPDC">Malveda Properties & Development Corporation - MPDC</option>
                    <option value="Primestocks Chemical Corporation - PCC">Primestocks Chemical Corporation - PCC</option>
                </select>
            </div>

            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" rows="5" required placeholder="Describe your issue in detail..."></textarea>
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
                    <input type="file" name="attachment" id="attachment" class="file-hidden" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                </div>
                <small style="display:block; margin-top:5px; color:#666;">Supported formats: JPG, PNG, PDF, DOCX (Max 5MB)</small>
                <div id="attachment-preview" style="margin-top: 10px;"></div>
            </div>

            <div class="form-actions">
                <a href="../index.php" class="btn-back">Back</a>
                <button type="submit" class="submit-btn">Submit Ticket</button>
            </div>
        </form>

    <?php endif; ?>
</div>

<script>
var attachmentInput = document.querySelector('input[name="attachment"]');
var currentObjectUrl = null;
var chooseBtn = document.getElementById('choose-file-btn');
var fileNameEl = document.getElementById('file-name');

if (chooseBtn) {
    chooseBtn.addEventListener('click', function () {
        if (attachmentInput) attachmentInput.click();
    });
}

attachmentInput.addEventListener('change', function(e) {
    var preview = document.getElementById('attachment-preview');
    preview.innerHTML = '';
    var file = e.target.files[0];

    if (fileNameEl) {
        fileNameEl.textContent = file ? file.name : 'No file chosen';
    }

    if (currentObjectUrl) {
        URL.revokeObjectURL(currentObjectUrl);
        currentObjectUrl = null;
    }

    if (!file) return;

    currentObjectUrl = URL.createObjectURL(file);

    if (file.type.startsWith('image/')) {
        var link = document.createElement('a');
        link.href = currentObjectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.style.display = 'inline-block';

        var img = document.createElement('img');
        img.src = currentObjectUrl;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '200px';
        img.style.borderRadius = '5px';
        img.style.border = '1px solid #ddd';
        img.style.cursor = 'pointer';

        link.appendChild(img);
        preview.appendChild(link);
    } else {
        var link = document.createElement('a');
        link.href = currentObjectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.style.display = 'inline-block';
        link.style.textDecoration = 'none';

        var div = document.createElement('div');
        div.innerHTML = '<i class="fas fa-file-alt"></i> ' + file.name;
        div.style.padding = '10px';
        div.style.background = '#f8f9fa';
        div.style.border = '1px solid #ddd';
        div.style.borderRadius = '5px';
        div.style.display = 'inline-block';
        div.style.cursor = 'pointer';

        link.appendChild(div);
        preview.appendChild(link);
    }
});
</script>

</body>
</html>
