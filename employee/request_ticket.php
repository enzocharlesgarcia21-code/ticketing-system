<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

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
    $allowed_categories = ['Hardware', 'Software', 'Documentation', 'Email', 'Internet Concerns', 'Procurement'];
    $category = trim((string) ($_POST['category'] ?? ''));
    if ($category === '' || !in_array($category, $allowed_categories, true)) {
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
    $description = !empty($_POST['description']) ? $_POST['description'] : NULL;

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

    $assigned_user_id = ticket_find_assignee_id($conn, $assigned_company, $assigned_group);
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

    /* ================= FILE UPLOAD ================= */

    if (isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $error_msg = '';
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
                $error_msg = 'Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.';
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
                    $error_msg = 'Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.';
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

    /* ================= NOTIFICATIONS FOR ADMINS ================= */
    // 1. Get User Details
    $user_stmt = $conn->prepare("SELECT name, company FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result();
    $user_data = $user_res->fetch_assoc();
    $user_name = $user_data['name'] ?? 'Unknown User';
    $user_company = $user_data['company'] ?? 'Unknown Company';
    $user_stmt->close();

    // 2. Format Message
    $ticket_number = str_pad($ticket_id, 6, '0', STR_PAD_LEFT);
    $notif_msg = "New $priority priority ticket #$ticket_number from $user_name - $department";

    if ($assigned_user_id) {
        $dept_notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
        if ($dept_notif_stmt) {
            $dept_type = 'dept_assigned';
            $dept_msg = "New ticket #$ticket_number from $user_name was assigned to your group.";
            $dept_notif_stmt->bind_param("iiss", $assigned_user_id, $ticket_id, $dept_msg, $dept_type);
            $dept_notif_stmt->execute();
            $dept_notif_stmt->close();
        }
    }

    // 3. Insert Notification for ALL Admins
    $admin_query = "SELECT id FROM users WHERE role = 'admin'";
    $admin_result = $conn->query($admin_query);

    if ($admin_result) {
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, 'new_ticket')");
        while ($admin = $admin_result->fetch_assoc()) {
            $admin_id = $admin['id'];
            $notif_stmt->bind_param("iis", $admin_id, $ticket_id, $notif_msg);
            $notif_stmt->execute();
        }
        $notif_stmt->close();
    }

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

    $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
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

    $adminSubject = "New Ticket Submitted (#$ticketNumber)";
    $adminBodyHtml = "
        <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
            <h2 style='margin:0 0 12px 0'>New Ticket Submitted</h2>
            <p style='margin:0 0 16px 0'>
                Ticket ID: <strong>#$ticketNumberSafe</strong><br>
                Category: <strong>$ticketSubjectSafe</strong><br>
                Department: <strong>$ticketAssignedDeptSafe</strong><br>
                Date Created: <strong>$createdAtSafe</strong><br>
                Requested by: <strong>$requesterNameSafe</strong>
            </p>
            <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:0 0 16px 0'>
                $ticketDescriptionSafe
            </div>
            <p style='margin:0'>Login to the system to view the ticket.</p>
        </div>
    ";
    $adminBodyText = "New Ticket Submitted\n\n"
        . "Ticket ID: #$ticketNumber\n"
        . "Category: $ticketSubject\n"
        . "Department: $ticketAssignedDept\n"
        . "Date Created: $createdAt\n"
        . "Requested by: $requesterName\n\n"
        . $ticketDescription . "\n\n"
        . "Login to the system to view the ticket.\n";

    $attachments = [];
    if (!empty($attachmentName)) {
        $path = realpath(__DIR__ . '/../uploads/' . $attachmentName);
        if ($path) {
            $attachments[] = ['path' => $path];
        }
    }

    $adminOk = sendSmtpEmail($adminEmails, $adminSubject, $adminBodyHtml, $adminBodyText, $attachments);
    if (!$adminOk) {
        error_log('Ticket email failed (admins) | ticketId=' . (string) $ticket_id);
    }

    if ($employeeEmail !== '') {
        $employeeSubject = "Ticket Submitted (#$ticketNumber)";
        $employeeBodyHtml = "
            <div style='font-family:Arial, sans-serif; color:#333; line-height:1.5'>
                <h2 style='margin:0 0 12px 0'>Ticket Submitted</h2>
                <p style='margin:0 0 16px 0'>Hello <strong>$requesterNameSafe</strong>,</p>
                <p style='margin:0 0 16px 0'>We received your ticket. Here are the details:</p>
                <p style='margin:0 0 16px 0'>
                    Ticket ID: <strong>#$ticketNumberSafe</strong><br>
                    Category: <strong>$ticketSubjectSafe</strong><br>
                    Department: <strong>$ticketAssignedDeptSafe</strong><br>
                    Date Created: <strong>$createdAtSafe</strong>
                </p>
                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin:0 0 16px 0'>
                    $ticketDescriptionSafe
                </div>
                <p style='margin:0'>Login to the system to track updates.</p>
            </div>
        ";
        $employeeBodyText = "Ticket Submitted\n\n"
            . "Ticket ID: #$ticketNumber\n"
            . "Category: $ticketSubject\n"
            . "Department: $ticketAssignedDept\n"
            . "Date Created: $createdAt\n\n"
            . $ticketDescription . "\n\n"
            . "Login to the system to track updates.\n";

        $employeeOk = sendSmtpEmail([$employeeEmail], $employeeSubject, $employeeBodyHtml, $employeeBodyText);
        if (!$employeeOk) {
            error_log('Ticket email failed (employee) | ticketId=' . (string) $ticket_id);
        }
    } else {
        error_log('Ticket email skipped (employee email empty) | ticketId=' . (string) $ticket_id);
    }

    /* ================= SUCCESS MESSAGE ================= */

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => 'Ticket successfully submitted!'], JSON_UNESCAPED_UNICODE);
        exit();
    }
    $_SESSION['success'] = "Ticket successfully submitted!";
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
    </style>
</head>
<body>

    <!-- 2️⃣ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-error" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700;">
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
                        <label>Ticket Recipient (Company Email Domain) *</label>
                        <div class="select-wrapper">
                            <select name="assigned_company" id="assigned_company" class="form-control" required>
                                <option value=""disabled selected hidden>Select Recipient</option>
                                <option value="@gpsci.net">@gpsci.net</option>
                                <option value="@farmasee.ph">@farmasee.ph</option>
                                <option value="@gmail.com">@gmail.com</option>
                                <option value="@leads-eh.com">@leads-eh.com</option>
                                <option value="@leads-farmex.com">@leads-farmex.com</option>
                                <option value="@leadsagri.com">@leadsagri.com</option>
                                <option value="@leadsanimalhealth.com">@leadsanimalhealth.com</option>
                                <option value="@leadsav.com">@leadsav.com</option>
                                <option value="@leadstech-corp.com">@leadstech-corp.com</option>
                                <option value="@lingapleads.org">@lingapleads.org</option>
                                <option value="@primestocks.ph">@primestocks.ph</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Assigned Department *</label>
                        <div class="select-wrapper">
                            <select name="assigned_group" id="assigned_group" class="form-control" required>
                                <option value="" disabled selected hidden>Select Department</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Category *</label>
                        <div class="select-wrapper">
                            <select name="category" class="form-control" required>
                                <option value="" disabled selected hidden>Select Category</option>
                                <option value="Hardware">Hardware</option>
                                <option value="Software">Software</option>
                                <option value="Documentation">Documentation</option>
                                <option value="Email">Email</option>
                                <option value="Internet Concerns">Internet Concerns</option>
                                <option value="Procurement">Procurement</option>
                            </select>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>

                    

                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" class="form-control" placeholder="Describe your issue in detail..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Attachment (Optional)</label>
                        <div class="file-control" style="display:flex;align-items:center;gap:12px;background:#f8faf9;border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;">
                            <button type="button" id="choose-file-btn" class="file-button" style="display:inline-flex;align-items:center;gap:8px;background:#ecfdf5;color:#1B5E20;border:1px solid #bbf7d0;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer;">
                                <i class="fas fa-paperclip"></i>
                                <span>Choose File</span>
                            </button>
                            <span id="file-name" class="file-name" style="color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">No file chosen</span>
                            <input type="file" name="attachments[]" id="attachments" class="file-hidden" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" style="display:none;">
                        </div>
                        <small class="form-text">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files, 5MB total)</small>
                        <div id="attachment-error" style="display:none;margin-top:10px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;font-weight:700;"></div>
                        <div id="attachment-total" style="margin-top:10px;color:#475569;font-weight:800;font-size:12px;white-space:nowrap;"></div>
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

    <div id="successModal" class="ticket-modal" aria-hidden="true">
        <div class="ticket-modal-content" role="dialog" aria-modal="true" aria-labelledby="successModalTitle">
            <div class="check-icon">✓</div>
            <h3 id="successModalTitle">Ticket Submitted</h3>
            <p>Your ticket has been successfully created.</p>
            <button type="button" onclick="closeModal()">Close</button>
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
    });
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
            if (e.defaultPrevented) return;
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
                if (typeof window.TMEmployeeResetAttachments === 'function') window.TMEmployeeResetAttachments();
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
