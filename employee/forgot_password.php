<?php
require_once '../config/database.php';
require '../vendor/phpmailer/src/PHPMailer.php';
require '../vendor/phpmailer/src/SMTP.php';
require '../vendor/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_SESSION['user_id']) && $_SESSION['role'] == 'employee') {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);

    if (!empty($email)) {
        // Check if email exists and is employee
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND role = 'employee'");
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
                // Send Email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'matthewpascua052203@gmail.com';
                    $mail->Password   = 'tmwtjqjvadsmgzje';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $mail->setFrom('matthewpascua052203@gmail.com', 'Leads Agri Helpdesk');
                    $mail->addAddress($email, $user['name']);

                    $mail->isHTML(true);
                    $mail->Subject = "Password Reset OTP - Leads Agri Helpdesk";
                    $mail->Body = "
                        <div style='font-family:Segoe UI;padding:20px'>
                            <p>Hello <strong>{$user['name']}</strong>,</p>
                            <p>Your password reset OTP is:</p>
                            <h2 style='color:#1B5E20'>$otp</h2>
                            <p>This code will expire in 5 minutes.</p>
                            <p>If you did not request this, please ignore this email.</p>
                        </div>
                    ";

                    $mail->send();

                    $_SESSION['reset_email'] = $email;
                    header("Location: verify_reset_otp.php");
                    exit();

                } catch (Exception $e) {
                    $_SESSION['reset_email'] = $email;
                    header("Location: verify_reset_otp.php?error=smtp_failed");
                    exit();
                }
            } else {
                $error = "Database error. Please try again.";
            }
        } else {
            $error = "Email not found or not an employee account.";
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

