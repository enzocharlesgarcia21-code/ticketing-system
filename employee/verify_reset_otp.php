<?php
require_once '../config/database.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

// Dev Helper: If SMTP failed, show OTP
if (isset($_GET['error']) && $_GET['error'] == 'smtp_failed') {
    $email = $_SESSION['reset_email'];
    $stmt = $conn->prepare("SELECT reset_otp FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($user = $res->fetch_assoc()) {
        $error = "We couldn't send the password reset email. Please use this code: <strong>" . $user['reset_otp'] . "</strong>";
    }
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $otp = trim($_POST['otp']);
    $email = $_SESSION['reset_email'];

    $stmt = $conn->prepare("SELECT id, reset_otp, reset_otp_expiry FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($user = $res->fetch_assoc()) {
        if ($user['reset_otp'] === $otp) {
            if (strtotime($user['reset_otp_expiry']) > time()) {
                // Valid OTP and not expired
                $_SESSION['otp_verified'] = true;
                header("Location: reset_password.php");
                exit();
            } else {
                $error = "OTP has expired.";
            }
        } else {
            $error = "Invalid OTP code.";
        }
    } else {
        $error = "User not found.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Verify OTP - Employee</title>
    <link rel="stylesheet" href="../css/employee-login.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <h2>Verify OTP</h2>
        <p style="text-align:center; color:#666; font-size:0.9rem; margin-bottom:20px;">
            Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong>
        </p>

        <?php if(isset($error)) : ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>OTP Code</label>
                <input type="text" name="otp" required placeholder="123456" maxlength="6" style="text-align:center; letter-spacing: 5px; font-size: 1.2rem;">
            </div>

            <button type="submit">Verify</button>
        </form>

        <div class="signup-link">
            Didn't receive code? 
            <a href="forgot_password.php">Resend</a>
        </div>

    </div>
</div>

</body>
</html>

