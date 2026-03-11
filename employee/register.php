<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../vendor/phpmailer/src/Exception.php';
require_once '../vendor/phpmailer/src/PHPMailer.php';
require_once '../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}




/* If already logged in */
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name       = trim($_POST['name']);
    $email      = trim($_POST['email']);
    $company    = trim($_POST['company']);
    $department = trim($_POST['department']);
    $password   = trim($_POST['password']);

    /* === ADDED SAFE VALIDATION === */
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        $error = "Password is not strong enough.";
    }
    /* === END SAFE VALIDATION === */

    if (isset($error)) {
    } elseif (!empty($name) && !empty($email) && !empty($company) && !empty($department) && !empty($password)) {

        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Email already registered.";
        } else {

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $otp = rand(100000, 999999);

            $stmt = $conn->prepare("
                INSERT INTO users 
                (name, email, company, department, password, role, otp_code, is_verified)
                VALUES (?, ?, ?, ?, ?, 'employee', ?, 0)
            ");

            $stmt->bind_param("ssssss", $name, $email, $company, $department, $hashedPassword, $otp);

            if ($stmt->execute()) {

                /* ===== SEND OTP EMAIL ===== */

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

                    $mail->setFrom('matthewpascua052203@gmail.com', 'IT Helpdesk System');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = "Verify Your Email - OTP Code";

                    $mail->Body = "
                        <div style='font-family:Segoe UI;padding:20px'>
                            <h2 style='color:#1B5E20'>Email Verification</h2>
                            <p>Hello <strong>$name</strong>,</p>
                            <p>Your OTP Code is:</p>
                            <h1 style='color:#1B5E20'>$otp</h1>
                            <p>Please enter this code to activate your account.</p>
                        </div>
                    ";

                    $mail->send();

                    $_SESSION['verify_email'] = $email;

                    header("Location: verify_otp.php");
                    exit();

                } catch (Exception $e) {
                    // Fallback if email fails (e.g. localhost)
                    $_SESSION['verify_email'] = $email;
                    header("Location: verify_otp.php?error=smtp_failed");
                    exit();
                }

            } else {
                $error = "Registration failed.";
            }

            $stmt->close();
        }

        $check->close();
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Employee Account</title>
    <link rel="stylesheet" href="../css/register.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <h2>Register Account </h2>

        <?php if(isset($error)) : ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" required value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '' ?>">
            </div>

            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            </div>

            <div class="form-group">
                <label>Company / Subsidiary *</label>
                <select name="company" required>
                    <option value="" disabled selected hidden>Select company</option>
                    <?php 
                    $companies = [
                        "FARMEX", "FARMASEE", "Golden Primestocks Chemical Inc - GPSCI", 
                        "Leads Animal Health - LAH", "Leads Environmental Health - LEH", 
                        "Leads Tech Corporation - LTC", "LINGAP LEADS FOUNDATION - Lingap", 
                        "Malveda Holdings Corporation - MHC", "Malveda Properties & Development Corporation - MPDC", 
                        "Primestocks Chemical Corporation - PCC"
                    ];
                    foreach ($companies as $comp) {
                        $selected = (isset($_POST['company']) && $_POST['company'] === $comp) ? 'selected' : '';
                        echo "<option value=\"$comp\" $selected>$comp</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label>Department *</label>
                <select name="department" required>
                    <option value="" disabled selected hidden>Select Department</option>
                    <?php 
                    $depts = ["Accounting", "Admin", "Bidding", "E-Comm", "HR", "IT", "Marketing", "Sales"];
                    foreach ($depts as $dept) {
                        $selected = (isset($_POST['department']) && $_POST['department'] === $dept) ? 'selected' : '';
                        echo "<option value=\"$dept\" $selected>$dept</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" id="password" required>
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
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required>
                <div id="confirm-message" style="min-height:20px;font-size:13px;margin-top:6px;"></div>
            </div>

            <button type="submit" class="full-width-btn">Create Account</button>
        </form>

        <div class="signup-link">
            Already have an account?
            <a href="employee_login.php">Login here</a>
        </div>

    </div>
</div>

<script>
const formEl = document.querySelector("form");
const pwdEl = document.getElementById("password");
const validationBox = document.getElementById("password-validation");
const confirmEl = document.querySelector("input[name='confirm_password']");
const confirmMsg = document.getElementById("confirm-message");

const rules = {
    'rule-length': val => val.length >= 8,
    'rule-uppercase': val => /[A-Z]/.test(val),
    'rule-lowercase': val => /[a-z]/.test(val),
    'rule-number': val => /[0-9]/.test(val),
    'rule-special': val => /[^A-Za-z0-9]/.test(val)
};

function isPasswordStrong(val) {
    for (const id in rules) {
        if (!rules[id](val)) return false;
    }
    return true;
}

function validatePassword() {
    const val = pwdEl.value;

    if (val.length === 0) {
        for (const id in rules) {
            const el = document.getElementById(id);
            el.classList.remove('valid', 'invalid');
        }
        return;
    }

    for (const id in rules) {
        const el = document.getElementById(id);
        const ok = rules[id](val);
        if (ok) {
            el.classList.add('valid');
            el.classList.remove('invalid');
        } else {
            el.classList.add('invalid');
            el.classList.remove('valid');
        }
    }
}

pwdEl.addEventListener("focus", function() {
    validationBox.style.display = 'block';
    validatePassword();
});

pwdEl.addEventListener("input", function() {
    validatePassword();
    if (confirmEl.value) checkMatch();
});

function checkMatch() {
    const p = pwdEl.value;
    const c = confirmEl.value;
    
    if (!c) {
        confirmMsg.innerHTML = "";
        return;
    }

    if (p !== c) {
        confirmMsg.style.color = "#d93025";
        confirmMsg.innerHTML = "⚠ Passwords do not match";
    } else {
        confirmMsg.style.color = "#2e7d32";
        confirmMsg.innerHTML = "Passwords match ✓";
    }
}

confirmEl.addEventListener("input", checkMatch);

formEl.addEventListener("submit", function(e) {
    const v = pwdEl.value;
    const c = confirmEl.value;
    let hasError = false;

    if (!isPasswordStrong(v)) {
        pwdEl.focus();
        validationBox.style.display = 'block';
        validatePassword();
        hasError = true;
    }

    if (v !== c) {
        if (!hasError) confirmEl.focus();
        confirmEl.dispatchEvent(new Event('input'));
        hasError = true;
    }

    if (hasError) {
        e.preventDefault();
    }
});
</script>
</body>
</html>

