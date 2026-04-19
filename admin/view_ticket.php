<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("Invalid Ticket ID");
}

$id = (int) $_GET['id'];

$conn->query("UPDATE employee_tickets SET is_read = 1 WHERE id = $id");

/* Get full ticket + employee info */
$stmt = $conn->prepare("
    SELECT employee_tickets.*, users.name, users.email
    FROM employee_tickets
    JOIN users ON employee_tickets.user_id = users.id
    WHERE employee_tickets.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$ticket = $result->fetch_assoc();

if (!$ticket) {
    die("Ticket not found.");
}

$conn->query("CREATE TABLE IF NOT EXISTS ticket_request_meta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ticket_meta (ticket_id, meta_key),
    INDEX idx_ticket_request_meta_ticket (ticket_id),
    CONSTRAINT fk_ticket_request_meta_ticket FOREIGN KEY (ticket_id) REFERENCES employee_tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ticketMeta = [];
$metaStmt = $conn->prepare("SELECT meta_key, meta_value FROM ticket_request_meta WHERE ticket_id = ?");
if ($metaStmt) {
    $metaStmt->bind_param("i", $id);
    $metaStmt->execute();
    $metaRes = $metaStmt->get_result();
    while ($metaRes && ($metaRow = $metaRes->fetch_assoc())) {
        $metaKey = trim((string) ($metaRow['meta_key'] ?? ''));
        if ($metaKey === '') continue;
        $ticketMeta[$metaKey] = (string) ($metaRow['meta_value'] ?? '');
    }
    $metaStmt->close();
}

$ticketAttachments = [];
if (!empty($ticket['attachment'])) {
    $ticketAttachments[] = [
        'stored_name' => (string) $ticket['attachment'],
        'original_name' => (string) $ticket['attachment'],
    ];
}
$attStmt = $conn->prepare("SELECT stored_name, original_name FROM ticket_attachments WHERE ticket_id = ? ORDER BY id ASC");
if ($attStmt) {
    $attStmt->bind_param("i", $id);
    $attStmt->execute();
    $attRes = $attStmt->get_result();
    $seenAttachments = [];
    foreach ($ticketAttachments as $attachmentSeed) {
        $seedName = (string) ($attachmentSeed['stored_name'] ?? '');
        if ($seedName !== '') {
            $seenAttachments[$seedName] = true;
        }
    }
    while ($attRes && ($attachmentRow = $attRes->fetch_assoc())) {
        $storedName = (string) ($attachmentRow['stored_name'] ?? '');
        if ($storedName === '' || isset($seenAttachments[$storedName])) {
            continue;
        }
        $seenAttachments[$storedName] = true;
        $ticketAttachments[] = [
            'stored_name' => $storedName,
            'original_name' => (string) ($attachmentRow['original_name'] ?? $storedName),
        ];
    }
    $attStmt->close();
}

$hrConcernType = trim((string) ($ticketMeta['hr_concern_type'] ?? ''));
$isLapcHrTicket = (strtolower(trim((string) ($ticket['assigned_company'] ?? ''))) === '@leadsagri.com'
    && trim((string) ($ticket['assigned_group'] ?? ($ticket['assigned_department'] ?? ''))) === 'HR');
$isSpecialHrCategory = in_array((string) ($ticket['category'] ?? ''), ['Attendance & Timekeeping', 'Leave Concern', 'SSS Sickness and Benefit Concern', 'Others'], true);
$isHrSpecialTicket = $isLapcHrTicket && $isSpecialHrCategory;

$groupedAttachments = [];
foreach ($ticketAttachments as $attachmentItem) {
    $storedName = (string) ($attachmentItem['stored_name'] ?? '');
    if ($storedName === '') continue;
    $displayName = (string) ($attachmentItem['original_name'] ?? $storedName);
    $groupTitle = 'Attachment';
    $itemName = $displayName;
    if ((string) ($ticket['category'] ?? '') === 'SSS Sickness and Benefit Concern' && strpos($displayName, ' - ') !== false) {
        [$prefixTitle, $restName] = explode(' - ', $displayName, 2);
        $prefixTitle = trim((string) $prefixTitle);
        $restName = trim((string) $restName);
        if ($prefixTitle !== '') {
            $groupTitle = $prefixTitle;
        }
        if ($restName !== '') {
            $itemName = $restName;
        }
    }
    if (!isset($groupedAttachments[$groupTitle])) {
        $groupedAttachments[$groupTitle] = [];
    }
    $groupedAttachments[$groupTitle][] = [
        'stored_name' => $storedName,
        'display_name' => $itemName,
    ];
}

/* Update status & department */
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    $new_status = $_POST['status'];
    $new_department = $_POST['assigned_department'];

    $update = $conn->prepare("
        UPDATE employee_tickets
        SET status = ?, assigned_department = ?
        WHERE id = ?
    ");
    $update->bind_param("ssi", $new_status, $new_department, $id);
    $update->execute();
    $update->close();

   /* ================= SEND EMAIL TO EMPLOYEE ================= */

    $toEmail = (string) ($ticket['email'] ?? '');
    $toName = (string) ($ticket['name'] ?? '');
    $ticketId = (string) ($ticket['id'] ?? $id);
    $subject = (string) ($ticket['subject'] ?? '');

    $subjectLine = "Ticket Update: #{$ticketId} - " . $subject;

    $nameSafe = htmlspecialchars($toName);
    $ticketIdSafe = htmlspecialchars($ticketId);
    $ticketSubjectSafe = htmlspecialchars($subject);
    $categorySafe = htmlspecialchars((string) ($ticket['category'] ?? ''));
    $prioritySafe = htmlspecialchars((string) ($ticket['priority'] ?? ''));
    $statusSafe = htmlspecialchars((string) $new_status);
    $deptSafe = htmlspecialchars((string) $new_department);

    $bodyHtml = "
        <div style='font-family:Segoe UI, Arial, sans-serif; padding:15px; color:#111827; line-height:1.5'>
            <h2 style='color:#1B5E20; margin:0 0 12px 0'>Ticket Update Notification</h2>
            <p style='margin:0 0 12px 0'>Hello <strong>{$nameSafe}</strong>,</p>
            <p style='margin:0 0 12px 0'>Your ticket has been updated by the Admin.</p>
            <hr>
            <p style='margin:0 0 6px 0'><strong>Ticket ID:</strong> #{$ticketIdSafe}</p>
            <p style='margin:0 0 6px 0'><strong>Subject:</strong> {$ticketSubjectSafe}</p>
            <p style='margin:0 0 6px 0'><strong>Category:</strong> {$categorySafe}</p>
            <p style='margin:0 0 6px 0'><strong>Priority:</strong> {$prioritySafe}</p>
            <p style='margin:0 0 6px 0'><strong>New Status:</strong> <span style='color:#1B5E20;font-weight:700'>{$statusSafe}</span></p>
            <p style='margin:0 0 6px 0'><strong>Assigned Department:</strong> {$deptSafe}</p>
            <hr>
            <p style='font-size:12px;color:#64748B;margin:0'>This is an automated message from Leads Agri Helpdesk.</p>
        </div>
    ";
    $bodyText = "Ticket Update Notification\n\n"
        . "Hello $toName,\n\n"
        . "Your ticket has been updated by the Admin.\n\n"
        . "Ticket ID: #$ticketId\n"
        . "Subject: $subject\n"
        . "Category: " . (string) ($ticket['category'] ?? '') . "\n"
        . "Priority: " . (string) ($ticket['priority'] ?? '') . "\n"
        . "New Status: $new_status\n"
        . "Assigned Department: $new_department\n";

    if ($toEmail !== '') {
        $ok = sendSmtpEmail([$toEmail], $subjectLine, $bodyHtml, $bodyText);
        if (!$ok) {
            error_log('Ticket update email failed (admin/view_ticket.php) | ticketId=' . (string) $ticketId);
        }
    } else {
        error_log('Ticket update email skipped (empty recipient) | ticketId=' . (string) $ticketId);
    }

    $_SESSION['success'] = "Ticket #$id successfully updated.";
    header("Location: all_tickets.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Ticket Details</title>
<link rel="stylesheet" href="../css/employee.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="sidebar">
    <h2>Admin Panel</h2>
    <a href="dashboard.php">Dashboard</a>
    <a href="all_tickets.php" class="active">All Tickets</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main-content">

<h1>Ticket #<?= $ticket['id']; ?></h1>

<div class="recent">

<!-- ===== EMPLOYEE INFO ===== -->
<h3>Employee Information</h3>
<p><strong>Name:</strong> <?= htmlspecialchars($ticket['name']); ?></p>
<p><strong>Email:</strong> <?= htmlspecialchars($ticket['email']); ?></p>
<p><strong>Original Department:</strong> <?= htmlspecialchars($ticket['department']); ?></p>

<hr style="margin:20px 0;">

<!-- ===== TICKET INFO ===== -->
<h3>Ticket Details</h3>
<p><strong>Subject:</strong> <?= htmlspecialchars($ticket['subject']); ?></p>
<p><strong>Category:</strong> <?= htmlspecialchars($ticket['category']); ?></p>
<p><strong>Priority:</strong> <?= htmlspecialchars($ticket['priority']); ?></p>
<p><strong>Status:</strong> <?= htmlspecialchars($ticket['status']); ?></p>
<p><strong>Assigned Department:</strong> <?= htmlspecialchars($ticket['assigned_department']); ?></p>
<p><strong>Date Created:</strong> <?= date("M d, Y h:i A", strtotime($ticket['created_at'])); ?></p>

<?php if ($isHrSpecialTicket && $hrConcernType !== '') { ?>
    <p><strong>Type of Concern:</strong> <?= htmlspecialchars($hrConcernType); ?></p>
<?php } ?>

<?php if (!empty($ticket['description'])) { ?>
    <p><strong><?= htmlspecialchars(in_array((string) ($ticket['category'] ?? ''), ['Leave Concern', 'Others'], true) ? 'Detailed Description of Request or Concern' : 'Description'); ?>:</strong><br>
    <?= nl2br(htmlspecialchars($ticket['description'])); ?></p>
<?php } ?>

<?php if (!empty($groupedAttachments)) { ?>
    <?php foreach ($groupedAttachments as $groupTitle => $groupItems) { ?>
        <p><strong><?= htmlspecialchars($groupTitle); ?>:</strong></p>
        <ul style="margin-top: 8px; margin-bottom: 14px;">
            <?php foreach ($groupItems as $groupItem) { ?>
                <li>
                    <a href="../uploads/<?= htmlspecialchars((string) $groupItem['stored_name']); ?>" target="_blank">
                        <?= htmlspecialchars((string) $groupItem['display_name']); ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>
<?php } ?>

<hr style="margin:25px 0;">

<!-- ===== UPDATE SECTION ===== -->
<h3>Update Ticket</h3>

<form method="POST">
    <?php echo csrf_field(); ?>

    <label>Status</label>
    <select name="status">
        <option <?= $ticket['status']=='Open'?'selected':'' ?>>Open</option>
        <option <?= $ticket['status']=='In Progress'?'selected':'' ?>>In Progress</option>
        <option <?= $ticket['status']=='Resolved'?'selected':'' ?>>Resolved</option>
    </select>

    <label>Assign To Department</label>
<select name="assigned_department">
    <option <?= strtoupper((string)$ticket['assigned_department'])=='ACCOUNTING'?'selected':'' ?>>ACCOUNTING</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='ADMIN'?'selected':'' ?>>ADMIN</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='BIDDING'?'selected':'' ?>>BIDDING</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='E-COMM'?'selected':'' ?>>E-COMM</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='HR'?'selected':'' ?>>HR</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='IT'?'selected':'' ?>>IT</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='LINGAP'?'selected':'' ?>>LINGAP</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='MARKETING'?'selected':'' ?>>MARKETING</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='SUPPLY CHAIN'?'selected':'' ?>>SUPPLY CHAIN</option>
    <option <?= strtoupper((string)$ticket['assigned_department'])=='TECHNICAL'?'selected':'' ?>>TECHNICAL</option>
</select>

    <br><br>
    <button type="submit">Update Ticket</button>

</form>

</div>
</div>

</body>
</html>
