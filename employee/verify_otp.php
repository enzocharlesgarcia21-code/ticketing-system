<?php
require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['verify_email'])) {
    header("Location: register.php");
    exit();
}

$email = $_SESSION['verify_email'];

// Dev Helper: If SMTP failed, show OTP
if (isset($_GET['error']) && $_GET['error'] == 'smtp_failed') {
    $otp_stmt = $conn->prepare("SELECT otp_code FROM users WHERE email = ?");
    $otp_stmt->bind_param("s", $email);
    $otp_stmt->execute();
    $otp_res = $otp_stmt->get_result();
    if ($otp_row = $otp_res->fetch_assoc()) {
        $error = "We couldn't send the verification email. Please use this OTP code: <strong>" . $otp_row['otp_code'] . "</strong>";
    }
    $otp_stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

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

<form method="POST">
    <label>Enter OTP</label>
    <input type="text" name="otp" required>
    <button type="submit">Verify</button>
</form>

</div>
</div>

</body>
</html>
