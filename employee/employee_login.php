<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

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
        $at_pos = strpos($email, '@');
        if ($at_pos !== false) {
            $maybe_domain = substr($email, $at_pos);
            $allowed = array_map(function ($d) { return '@' . $d; }, $email_domains);
            if (in_array($maybe_domain, $allowed, true)) {
                $email_domain = $maybe_domain;
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
                    $_SESSION['force_password_change'] = (int) ($user['force_password_change'] ?? 0);

                    if ($role === 'admin') {
                        header("Location: ../admin/dashboard.php");
                        exit();
                    }

                    if ((int) ($_SESSION['force_password_change'] ?? 0) === 1) {
                        header("Location: force_password_change.php");
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
<link rel="stylesheet" href="../css/employee-login.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="../css/password-toggle.css?v=<?php echo time(); ?>">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php
    $password_invalid = isset($error) && $error === "Incorrect password.";
?>

<div class="auth-wrapper auth-container login-container">
    <section class="auth-split-left" aria-hidden="true"></section>

    <section class="auth-split-right" aria-label="Employee login">
        <div class="login-card">
            <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>

            <h2>Login</h2>

            <?php if (isset($_GET['registered'])) : ?>
                <div class="success">Account created successfully! Please login.</div>
            <?php endif; ?>

            <?php if (isset($_GET['password_reset'])) : ?>
                <div class="success">Password reset successfully! Please login with your new password.</div>
            <?php endif; ?>

            <?php if (isset($error)) : ?>
                <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label>Email *</label>
                    <div class="input-shell">
                        <span class="input-icon"><i class="fas fa-envelope"></i></span>
                        <input
                            type="text"
                            name="email"
                            id="emailInput"
                            placeholder="Email"
                            value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>"
                            required
                            title="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                        <input type="hidden" name="email_domain" id="emailDomain" value="<?php echo htmlspecialchars($email_domain, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Password *</label>
                    <div class="input-shell password-wrapper<?php echo $password_invalid ? ' is-invalid' : ''; ?>">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input
                            type="password"
                            name="password"
                            class="password-input"
                            placeholder="Password"
                            required
                            aria-invalid="<?php echo $password_invalid ? 'true' : 'false'; ?>"
                        >
                        <button type="button" class="toggle-password" aria-label="Show or hide password">
                            <span class="eye-icon" aria-hidden="true"></span>
                        </button>
                    </div>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit">Login</button>
            </form>

            <div class="signup-link signup-link-hidden">
                Don&rsquo;t have an account?
                <a href="register.php">Sign up</a>
            </div>
        </div>
    </section>
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

        emailEl.addEventListener('blur', function () {
            normalizeEmail();
        });

        if (emailEl.form) {
            emailEl.form.addEventListener('submit', function () {
                normalizeEmail();
            });
        }
    })();
</script>
<script src="../js/password-toggle.js"></script>
</body>
</html>
