<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

/* If already logged in, redirect */
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit();
    }
    if ($_SESSION['role'] === 'employee') {
        header("Location: dashboard.php");
        exit();
    }
}

$email_domains = [
    'gpsci.net',
    'farmasee.ph',
    'gmail.com',
    'leads-eh.com',
    'leads-farmex.com',
    'leadsagri.com',
    'leadsanimalhealth.com',
    'leadsav.com',
    'leadstech-corp.com',
    'lingapleads.org',
    'primestocks.ph'
];
$default_email_domain = '@leadsagri.com';
$email_domain = $default_email_domain;
$email_value = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();

    $posted_email = trim($_POST['email'] ?? '');
    $posted_domain = (string) ($_POST['email_domain'] ?? $email_domain);
    $email_domain = $posted_domain !== '' ? $posted_domain : $email_domain;
    $email = $posted_email;
    if ($email !== '' && strpos($email, '@') === false) {
        $email = $email . $email_domain;
    }
    $password = trim($_POST['password']);

    if ($email !== '') {
        $atPos = strpos($email, '@');
        if ($atPos !== false) {
            $maybeDomain = substr($email, $atPos);
            $allowed = array_map(function ($d) { return '@' . $d; }, $email_domains);
            if (in_array($maybeDomain, $allowed, true)) {
                $email_domain = $maybeDomain;
            }
        }
        $email_value = $email;
    }

    if (!empty($email) && !empty($password)) {

        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        if (!$stmt) {
            $error = "System error. Please try again.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($user = $result->fetch_assoc()) {
                $role = (string) ($user['role'] ?? '');

                if ($role !== 'employee' && $role !== 'admin') {
                    $error = "No account found with that email.";
                } elseif ($role === 'employee' && (int) ($user['is_verified'] ?? 0) !== 1) {
                    $error = "Please verify your email first.";
                } elseif (password_verify($password, (string) $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['company'] = $user['company'];
                    $_SESSION['department'] = $user['department'];
                    $_SESSION['role'] = $role;

                    if ($role === 'admin') {
                        header("Location: ../admin/dashboard.php");
                        exit();
                    }

                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = "Incorrect password.";
                }
            } else {
                $error = "No account found with that email.";
            }

            $stmt->close();
        }

    } else {
        $error = "Please fill in all fields.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Login</title>
    <link rel="stylesheet" href="../css/employee-login.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <a href="../index.php" class="back-btn">← Back</a>
        <h2>Login</h2>

        <?php if(isset($_GET['registered'])) : ?>
            <div class="success">Account created successfully! Please login.</div>
        <?php endif; ?>

        <?php if(isset($_GET['password_reset'])) : ?>
            <div class="success">Password reset successfully! Please login with your new password.</div>
        <?php endif; ?>

        <?php if(isset($error)) : ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Email *</label>
                <div class="email-row">
                    <input type="text" name="email" id="emailInput" value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>" required title="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>">
                    <select class="email-domain" name="email_domain" id="emailDomain" title="<?php echo htmlspecialchars($email_domain, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php foreach ($email_domains as $ed): ?>
                            <?php $opt = '@' . $ed; ?>
                            <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($email_domain === $opt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required>
                <a href="forgot_password.php" class="forgot-link" style="display: block; margin-top: 5px; font-size: 0.85rem; color: #1B5E20; text-decoration: none;">Forgot Password?</a>
            </div>

            <button type="submit">Login</button>

        </form>

        <div class="signup-link signup-link-hidden">
            Don’t have an account?
            <a href="register.php">Sign up</a>
        </div>

    </div>
</div>

<script>
    (function () {
        var emailEl = document.getElementById('emailInput');
        var domainEl = document.getElementById('emailDomain');
        if (!emailEl || !domainEl) return;

        function normalizeEmail() {
            var raw = (emailEl.value || '').trim();
            if (!raw) {
                return;
            }
            if (raw.indexOf('@') === -1) {
                emailEl.value = raw + domainEl.value;
            }
            emailEl.title = emailEl.value || '';
        }

        function syncDomainFromEmail() {
            var raw = (emailEl.value || '').trim();
            var at = raw.indexOf('@');
            if (at < 0) return;
            var dom = raw.slice(at);
            var options = Array.prototype.slice.call(domainEl.options).map(function (o) { return o.value; });
            if (options.indexOf(dom) >= 0) {
                domainEl.value = dom;
                domainEl.title = dom;
            }
        }

        function applyDomainToEmail() {
            var raw = (emailEl.value || '').trim();
            if (!raw) return;
            var local = raw.split('@')[0];
            if (!local) return;
            emailEl.value = local + domainEl.value;
            emailEl.title = emailEl.value || '';
            domainEl.title = domainEl.value || '';
        }

        emailEl.addEventListener('input', function () {
            syncDomainFromEmail();
        });
        domainEl.addEventListener('change', function () {
            applyDomainToEmail();
        });
        emailEl.addEventListener('blur', function () {
            normalizeEmail();
        });

        if (emailEl.form) {
            emailEl.form.addEventListener('submit', function () {
                normalizeEmail();
            });
        }

        syncDomainFromEmail();
    })();
</script>
</body>
</html>
