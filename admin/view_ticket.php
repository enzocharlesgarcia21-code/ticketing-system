<?php
require_once '../config/database.php';

 require '../vendor/phpmailer/src/PHPMailer.php';
    require '../vendor/phpmailer/src/SMTP.php';
    require '../vendor/phpmailer/src/Exception.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

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

/* Update status & department */
if ($_SERVER["REQUEST_METHOD"] == "POST") {

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

    

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'matthewpascua052203@gmail.com'; // your gmail
        $mail->Password = 'tmwtjqjvadsmgzje';               // your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->setFrom('matthewpascua052203@gmail.com', 'Leads Agri Helpdesk');
        $mail->addAddress($ticket['email'], $ticket['name']);

        $mail->isHTML(true);
        $mail->Subject = "Ticket Update: #{$ticket['id']} - " . $ticket['subject'];

        $mail->Body = "
        <div style='font-family:Segoe UI;padding:15px'>
            <h2 style='color:#1B5E20;'>Ticket Update Notification</h2>
            <p>Hello <strong>{$ticket['name']}</strong>,</p>

            <p>Your ticket has been updated by the Admin.</p>

            <hr>

            <p><strong>Ticket ID:</strong> #{$ticket['id']}</p>
            <p><strong>Subject:</strong> {$ticket['subject']}</p>
            <p><strong>Category:</strong> {$ticket['category']}</p>
            <p><strong>Priority:</strong> {$ticket['priority']}</p>
            <p><strong>New Status:</strong> 
                <span style='color:#1B5E20;font-weight:bold'>
                    {$new_status}
                </span>
            </p>
            <p><strong>Assigned Department:</strong> {$new_department}</p>

            <hr>
            <p style='font-size:12px;color:#64748B'>
                This is an automated message from Leads Agri Helpdesk.
            </p>
        </div>
        ";

        $mail->send();

    } catch (Exception $e) {
        // Optional: ignore email error
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

<?php if (!empty($ticket['description'])) { ?>
    <p><strong>Description:</strong><br>
    <?= nl2br(htmlspecialchars($ticket['description'])); ?></p>
<?php } ?>

<?php if (!empty($ticket['attachment'])) { ?>
    <p><strong>Attachment:</strong>
        <a href="../uploads/<?= $ticket['attachment']; ?>" target="_blank">
            View Attachment
        </a>
    </p>
<?php } ?>

<hr style="margin:25px 0;">

<!-- ===== UPDATE SECTION ===== -->
<h3>Update Ticket</h3>

<form method="POST">

    <label>Status</label>
    <select name="status">
        <option <?= $ticket['status']=='Open'?'selected':'' ?>>Open</option>
        <option <?= $ticket['status']=='In Progress'?'selected':'' ?>>In Progress</option>
        <option <?= $ticket['status']=='Resolved'?'selected':'' ?>>Resolved</option>
    </select>

    <label>Assign To Department</label>
<select name="assigned_department">
    <option <?= $ticket['assigned_department']=='IT'?'selected':'' ?>>IT</option>
    <option <?= $ticket['assigned_department']=='HR'?'selected':'' ?>>HR</option>
    <option <?= $ticket['assigned_department']=='Marketing'?'selected':'' ?>>Marketing</option>
    <option <?= $ticket['assigned_department']=='Admin'?'selected':'' ?>>Admin</option>
    <option <?= $ticket['assigned_department']=='Technical'?'selected':'' ?>>Technical</option>
    <option <?= $ticket['assigned_department']=='Accounting'?'selected':'' ?>>Accounting</option>
    <option <?= $ticket['assigned_department']=='Supply Chain'?'selected':'' ?>>Supply Chain</option>
    <option <?= $ticket['assigned_department']=='MPDC'?'selected':'' ?>>MPDC</option>
    <option <?= $ticket['assigned_department']=='E-Comm'?'selected':'' ?>>E-Comm</option>
</select>

    <br><br>
    <button type="submit">Update Ticket</button>

</form>

</div>
</div>

</body>
</html>
