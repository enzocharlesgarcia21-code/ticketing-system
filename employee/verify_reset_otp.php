<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

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
    csrf_validate();
    $otp = trim($_POST['otp']);
    $email = $_SESSION['reset_email'];

    $stmt = $conn->prepare("SELECT id, reset_otp, reset_otp_expiry FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($user = $res->fetch_assoc()) {
        if ($user['reset_otp'] === $otp) {
            if (strtotime($user['reset_otp_expiry']) > time()) {
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify OTP - Employee</title>
<link rel="stylesheet" href="../css/employee-login.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="login-container">
    <div class="login-card">
        <a href="forgot_password.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>

        <h2>Verify OTP</h2>
        <p class="auth-note">Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($_SESSION['reset_email']); ?></strong></p>

        <?php if (isset($error)) : ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label class="otp-label">OTP Code</label>
                <div class="otp-inputs" id="resetOtpInputs">
                    <input class="otp-digit" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="1" aria-label="OTP digit 1">
                    <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 2">
                    <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 3">
                    <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 4">
                    <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 5">
                    <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" aria-label="OTP digit 6">
                </div>
                <input type="hidden" name="otp" id="resetOtpFull" required>
                <div class="error otp-error" id="resetOtpClientError" style="display:none;">Please enter the 6-digit OTP.</div>
            </div>
        </form>

        <div class="auth-inline-link">
            Didn't receive code?
            <a href="forgot_password.php">Resend</a>
        </div>
    </div>
</div>

<script>
    (function () {
        var wrap = document.getElementById('resetOtpInputs');
        var full = document.getElementById('resetOtpFull');
        var err = document.getElementById('resetOtpClientError');
        if (!wrap || !full) return;
        var inputs = Array.prototype.slice.call(wrap.querySelectorAll('input.otp-digit'));
        if (inputs.length !== 6) return;

        function setError(show) {
            if (!err) return;
            err.style.display = show ? '' : 'none';
        }

        function readCode() {
            return inputs.map(function (i) { return (i.value || '').replace(/\D/g, '').slice(0, 1); }).join('');
        }

        function writeCode(code) {
            var digits = String(code || '').replace(/\D/g, '').slice(0, 6).split('');
            for (var i = 0; i < inputs.length; i++) {
                inputs[i].value = digits[i] || '';
            }
            full.value = digits.join('');
        }

        var submitting = false;

        function sync() {
            full.value = readCode();
            setError(false);
        }

        function resetInputs() {
            inputs.forEach(function (input) { input.value = ''; });
            full.value = '';
            submitting = false;
            if (inputs[0]) inputs[0].focus();
        }

        inputs.forEach(function (input, idx) {
            input.addEventListener('input', function () {
                var v = (input.value || '').replace(/\D/g, '');
                if (v.length > 1) {
                    writeCode(v);
                } else {
                    input.value = v.slice(0, 1);
                    sync();
                    if (input.value && idx < inputs.length - 1) {
                        inputs[idx + 1].focus();
                    }
                }
                sync();
                if (!submitting && /^\d{6}$/.test(full.value || '')) {
                    submitting = true;
                    form.submit();
                }
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !input.value && idx > 0) {
                    inputs[idx - 1].focus();
                }
                if (e.key === 'ArrowLeft' && idx > 0) {
                    e.preventDefault();
                    inputs[idx - 1].focus();
                }
                if (e.key === 'ArrowRight' && idx < inputs.length - 1) {
                    e.preventDefault();
                    inputs[idx + 1].focus();
                }
            });

            input.addEventListener('paste', function (e) {
                var text = (e.clipboardData || window.clipboardData).getData('text');
                if (!text) return;
                var digits = text.replace(/\D/g, '').slice(0, 6);
                if (!digits) return;
                e.preventDefault();
                writeCode(digits);
                var target = inputs[Math.min(inputs.length - 1, digits.length - 1)];
                if (target) target.focus();
            });

            input.addEventListener('focus', function () {
                input.select();
            });
        });

        var form = wrap.closest('form');
        if (form) {
            form.addEventListener('submit', function (e) {
                sync();
                if (!/^\d{6}$/.test(full.value || '')) {
                    e.preventDefault();
                    setError(true);
                    var firstEmpty = inputs.find(function (i) { return !(i.value || '').trim(); }) || inputs[0];
                    if (firstEmpty) firstEmpty.focus();
                    submitting = false;
                }
            });
        }

        <?php if (isset($error) && $error !== ''): ?>
        resetInputs();
        <?php else: ?>
        inputs[0].focus();
        <?php endif; ?>
    })();
</script>
</body>
</html>
