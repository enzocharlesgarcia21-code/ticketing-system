<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['verify_email'])) {
    header("Location: register.php");
    exit();
}

$email = $_SESSION['verify_email'];

if (isset($_GET['error']) && $_GET['error'] === 'smtp_failed') {
    $error = "We couldn't send the verification email. Please check your email address or click Resend OTP.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    if (isset($_POST['resend']) && $_POST['resend'] === '1') {
        $otp = (string) rand(100000, 999999);
        $updateOtp = $conn->prepare("UPDATE users SET otp_code = ? WHERE email = ? AND is_verified = 0");
        if ($updateOtp) {
            $updateOtp->bind_param("ss", $otp, $email);
            $updateOtp->execute();
            $updateOtp->close();
        }

        $name = '';
        $nameStmt = $conn->prepare("SELECT name FROM users WHERE email = ? LIMIT 1");
        if ($nameStmt) {
            $nameStmt->bind_param("s", $email);
            $nameStmt->execute();
            $nameRes = $nameStmt->get_result();
            if ($row = $nameRes->fetch_assoc()) {
                $name = (string) ($row['name'] ?? '');
            }
            $nameStmt->close();
        }

        $nameSafe = htmlspecialchars($name !== '' ? $name : 'User', ENT_QUOTES, 'UTF-8');
        $otpSafe = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $subjectLine = "Verify Your Email - OTP Code";
        $bodyHtml = "
            <div style='font-family:Segoe UI, Arial, sans-serif; padding:20px; color:#111827; line-height:1.5'>
                <h2 style='color:#1B5E20; margin:0 0 12px 0'>Email Verification</h2>
                <p style='margin:0 0 12px 0'>Hello <strong>{$nameSafe}</strong>,</p>
                <p style='margin:0 0 8px 0'>Your OTP code is:</p>
                <div style='font-size:28px; letter-spacing:6px; font-weight:700; color:#1B5E20; margin:0 0 12px 0'>{$otpSafe}</div>
                <p style='margin:0'>Please enter this code to activate your account.</p>
            </div>
        ";
        $bodyText = "Email Verification\n\n"
            . "Hello " . ($name !== '' ? $name : 'User') . ",\n"
            . "Your OTP code is: $otp\n\n"
            . "Please enter this code to activate your account.\n";

        $ok = sendSmtpEmail([$email], $subjectLine, $bodyHtml, $bodyText);
        if ($ok) {
            $success = "OTP sent. Please check your inbox (and Spam folder).";
        } else {
            $error = "Failed to send OTP. Please try again later or contact the administrator.";
        }
    } else {
    $enteredOtp = trim($_POST['otp']);

    $stmt = $conn->prepare("
        SELECT otp_code FROM users 
        WHERE email = ? AND is_verified = 0
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && $enteredOtp == $user['otp_code']) {

        $update = $conn->prepare("
            UPDATE users 
            SET is_verified = 1, otp_code = NULL
            WHERE email = ?
        ");
        $update->bind_param("s", $email);
        $update->execute();

        unset($_SESSION['verify_email']);

        header("Location: employee_login.php?registered=1");
        exit();

    } else {
        $error = "Invalid OTP.";
    }

    $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Verify OTP</title>
<link rel="stylesheet" href="../css/employee-dashboard.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
<div class="login-card">

<h2>Email Verification</h2>

<?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
<?php if(isset($success)) echo "<div class='success' style='background:#dcfce7;color:#166534;padding:12px 14px;border-radius:10px;font-weight:700;margin-bottom:12px;'>$success</div>"; ?>

<form method="POST">
    <?php echo csrf_field(); ?>
    <label>Enter OTP</label>
    <input type="text" name="otp" required>
    <button type="submit">Verify</button>
</form>

<form method="POST" style="margin-top: 12px;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="resend" value="1">
    <button type="submit" style="width:100%; background:#1B5E20; color:#fff; border:none; padding:12px 14px; border-radius:10px; font-weight:800; cursor:pointer;">Resend OTP</button>
</form>

</div>
</div>

</body>
</html>
