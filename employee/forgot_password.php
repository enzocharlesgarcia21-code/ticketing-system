<?php
require_once '../config/database.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'employee') {
        header("Location: dashboard.php");
        exit();
    }
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();
    $email = trim($_POST['email']);

    if (!empty($email)) {
        $stmt = $conn->prepare("SELECT id, name, role FROM users WHERE email = ? AND role IN ('employee', 'admin')");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($user = $res->fetch_assoc()) {
            $otp = rand(100000, 999999);
            // Expire in 5 minutes
            $expiry = date("Y-m-d H:i:s", strtotime("+5 minutes"));

            $update = $conn->prepare("UPDATE users SET reset_otp = ?, reset_otp_expiry = ? WHERE id = ?");
            $update->bind_param("ssi", $otp, $expiry, $user['id']);

            if ($update->execute()) {
                $nameSafe = htmlspecialchars((string) $user['name']);
                $otpSafe = htmlspecialchars((string) $otp);

                $subjectLine = "Password Reset OTP - Leads Agri Helpdesk";
                $bodyHtml = "
                    <div style='font-family:Segoe UI, Arial, sans-serif; padding:20px; color:#111827; line-height:1.5'>
                        <p style='margin:0 0 12px 0'>Hello <strong>{$nameSafe}</strong>,</p>
                        <p style='margin:0 0 8px 0'>Your password reset OTP is:</p>
                        <div style='font-size:24px; letter-spacing:6px; font-weight:700; color:#1B5E20; margin:0 0 12px 0'>{$otpSafe}</div>
                        <p style='margin:0 0 12px 0'>This code will expire in 5 minutes.</p>
                        <p style='margin:0'>If you did not request this, please ignore this email.</p>
                    </div>
                ";
                $bodyText = "Password Reset OTP\n\n"
                    . "Hello " . (string) $user['name'] . ",\n"
                    . "Your password reset OTP is: $otp\n"
                    . "This code will expire in 5 minutes.\n\n"
                    . "If you did not request this, please ignore this email.\n";

                $_SESSION['reset_email'] = $email;
                $_SESSION['reset_role'] = (string) ($user['role'] ?? 'employee');
                $ok = sendSmtpEmail([$email], $subjectLine, $bodyHtml, $bodyText);
                if ($ok) {
                    header("Location: verify_reset_otp.php");
                    exit();
                }

                header("Location: verify_reset_otp.php?error=smtp_failed");
                exit();
            } else {
                $error = "Database error. Please try again.";
            }
        } else {
            $error = "Email not found.";
        }
    } else {
        $error = "Please enter your email.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - Employee</title>
    <link rel="stylesheet" href="../css/employee-login.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <a href="employee_login.php" class="back-btn">← Back</a>
        <h2>Forgot Password</h2>
        <p style="text-align:center; color:#666; font-size:0.9rem; margin-bottom:20px;">
            Enter your email to receive a password reset OTP.
        </p>

        <?php if(isset($error)) : ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" required placeholder="Enter your registered email">
            </div>

            <button type="submit">Send OTP</button>
        </form>

    </div>
</div>

</body>
</html>

