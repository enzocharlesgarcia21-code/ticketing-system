<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

$stmt = $conn->prepare("SELECT force_password_change FROM users WHERE id = ? LIMIT 1");
$mustChange = 0;
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $mustChange = (int) ($row['force_password_change'] ?? 0);
    $stmt->close();
}

if ($mustChange !== 1) {
    unset($_SESSION['force_password_change']);
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validate();

    $pass = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($pass !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($pass) < 8 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[^A-Za-z0-9]/', $pass)) {
        $error = "Password must be at least 8 chars, include uppercase, lowercase, number, and special char.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
        if ($update) {
            $update->bind_param("si", $hash, $userId);
            if ($update->execute()) {
                $update->close();
                unset($_SESSION['force_password_change']);
                header("Location: dashboard.php");
                exit();
            }
            $update->close();
        }
        $error = "Error updating password.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password</title>
<link rel="stylesheet" href="../css/employee-login.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>

        <h2>Change Password</h2>
        <p class="auth-note">Please change your password before continuing to your account.</p>

        <?php if (isset($error)) : ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>New Password</label>
                <div class="input-shell">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password" id="password" required placeholder="Enter your new password">
                </div>

                <div id="password-validation" class="password-validation">
                    <div class="password-validation-title">Password requirements</div>
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
                <div class="input-shell">
                    <span class="input-icon"><i class="fas fa-shield-halved"></i></span>
                    <input type="password" name="confirm_password" required placeholder="Confirm your new password">
                </div>
            </div>

            <button type="submit">Update Password</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const validationBox = document.getElementById('password-validation');

    const rules = {
        'rule-length': val => val.length >= 8,
        'rule-uppercase': val => /[A-Z]/.test(val),
        'rule-lowercase': val => /[a-z]/.test(val),
        'rule-number': val => /[0-9]/.test(val),
        'rule-special': val => /[^A-Za-z0-9]/.test(val)
    };

    function validatePassword() {
        const val = passwordInput.value;
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
            el.classList.toggle('valid', isValid);
            el.classList.toggle('invalid', !isValid);
        }
    }

    passwordInput.addEventListener('focus', function() {
        validationBox.style.display = 'block';
        validatePassword();
    });

    passwordInput.addEventListener('input', validatePassword);
});
</script>

</body>
</html>
