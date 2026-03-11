<?php
require_once '../config/database.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
     header("Location: verify_reset_otp.php");
     exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    // Validation
    if ($pass !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($pass) < 8 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
        $error = "Password must be at least 8 chars, include uppercase, lowercase, number, and special char.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $email = $_SESSION['reset_email'];

        $update = $conn->prepare("UPDATE users SET password = ?, reset_otp = NULL, reset_otp_expiry = NULL WHERE email = ?");
        $update->bind_param("ss", $hash, $email);

        if ($update->execute()) {
            unset($_SESSION['reset_email']);
            unset($_SESSION['otp_verified']);
            // Redirect to login with success message
            header("Location: employee_login.php?password_reset=1");
            exit();
        } else {
            $error = "Error updating password.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - Employee</title>
    <link rel="stylesheet" href="../css/employee-login.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <h2>Reset Password</h2>
        <p style="text-align:center; color:#666; font-size:0.9rem; margin-bottom:20px;">
            Create a new strong password.
        </p>

        <?php if(isset($error)) : ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="password" id="password" required placeholder="New Password">
                
                <div id="password-validation" class="password-validation">
                    <ul>
                        <li id="rule-length">Minimum 8 characters</li>
                        <li id="rule-uppercase">At least 1 uppercase letter</li>
                        <li id="rule-lowercase">At least 1 lowercase letter</li>
                        <li id="rule-number">At least 1 number</li>
                        <li id="rule-special">At least 1 special character</li>
                    </ul>
                </div>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" required placeholder="Confirm Password">
            </div>

            <button type="submit">Reset Password</button>
        </form>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const validationBox = document.getElementById('password-validation');
    
    // Map rule IDs to regex patterns
    const rules = {
        'rule-length': val => val.length >= 8,
        'rule-uppercase': val => /[A-Z]/.test(val),
        'rule-lowercase': val => /[a-z]/.test(val),
        'rule-number': val => /[0-9]/.test(val),
        'rule-special': val => /[^A-Za-z0-9]/.test(val)
    };

    function validatePassword() {
        const val = passwordInput.value;
        let allValid = true;

        // If empty, reset to neutral
        if (val.length === 0) {
            for (const id in rules) {
                const el = document.getElementById(id);
                el.classList.remove('valid', 'invalid');
            }
            return;
        }

        for (const id in rules) {
            const el = document.getElementById(id);
            const isValid = rules[id](val);
            
            if (isValid) {
                el.classList.add('valid');
                el.classList.remove('invalid');
            } else {
                el.classList.add('invalid');
                el.classList.remove('valid');
                allValid = false;
            }
        }
    }

    // Show validation on focus
    passwordInput.addEventListener('focus', () => {
        validationBox.style.display = 'block';
        validatePassword(); // Run initial check in case value exists
    });

    // Update on input
    passwordInput.addEventListener('input', validatePassword);
});
</script>

</body>
</html>

