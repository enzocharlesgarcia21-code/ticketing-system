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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    ticket_ensure_assignment_columns($conn);

    $user_id    = $_SESSION['user_id'];
    $subject    = $_POST['subject'] ?? '';
    $category   = 'Technical Support';
    $priority   = $_POST['priority'] ?? 'Medium';
    if ($priority === '') {
        $priority = 'Medium';
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
        $_SESSION['error'] = 'Invalid ticket recipient selected.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($assigned_group === '' || !ticket_is_valid_group_for_company($assigned_company, $assigned_group)) {
        $_SESSION['error'] = 'Invalid assigned department selected for the chosen recipient.';
        header("Location: request_ticket.php");
        exit();
    }

    $assigned_user_id = ticket_find_assignee_id($conn, $assigned_company, $assigned_group);
    if (!$assigned_user_id) {
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
                break;
            }

            $fileName = (string)($_FILES['attachments']['name'][$i] ?? '');
            $fileTmp = (string)($_FILES['attachments']['tmp_name'][$i] ?? '');
            $fileSize = (int)($_FILES['attachments']['size'][$i] ?? 0);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedTypes, true)) {
                continue;
            }
            if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
                continue;
            }

            if (!is_dir("../uploads")) {
                mkdir("../uploads", 0777, true);
            }

            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath  = "../uploads/" . $newFileName;

            if (move_uploaded_file($fileTmp, $uploadPath)) {
                $movedPaths[] = $uploadPath;
                if ($attachmentName === NULL) {
                    $attachmentName = $newFileName;
                }
            }
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
                Subject: <strong>$ticketSubjectSafe</strong><br>
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
        . "Subject: $ticketSubject\n"
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
                    Subject: <strong>$ticketSubjectSafe</strong><br>
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
            . "Subject: $ticketSubject\n"
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
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    
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
                        <label>Subject *</label>
                        <input type="text" name="subject" class="form-control" placeholder="Enter a brief title for the issue" required>
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
                        <small class="form-text">Supported formats: JPG, PNG, PDF, DOCX (Max 5MB)</small>
                        <div id="attachment-preview" style="margin-top: 10px;"></div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Submit Ticket</button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const companyEl = document.getElementById('assigned_company');
        const groupEl = document.getElementById('assigned_group');
        const DEPARTMENTS = ["ACCOUNTING","ADMIN","E-COMM","HR","IT","LINGAP","MARKETING","SUPPLY CHAIN","TECHNICAL"];
        function populateGroups(arr) {
            groupEl.innerHTML = '';
            const ph = document.createElement('option');
            ph.value = '';
            ph.textContent = 'Select Department';
            ph.disabled = true;
            ph.selected = true;
            groupEl.appendChild(ph);
            arr.forEach(function (g) {
                const opt = document.createElement('option');
                opt.value = g;
                opt.textContent = g;
                groupEl.appendChild(opt);
            });
        }
        if (groupEl) populateGroups(DEPARTMENTS);
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
    });
    </script>
</body>
</html>
