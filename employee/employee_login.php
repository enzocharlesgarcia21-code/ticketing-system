<?php
require_once '../config/database.php';

/* If already logged in, redirect */
if (isset($_SESSION['user_id']) && $_SESSION['role'] == 'employee') {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {

       $stmt = $conn->prepare("
    SELECT * FROM users 
    WHERE email = ? AND role = 'employee' AND is_verified = 1
");

$error = "Please verify your email first.";

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {

            if (password_verify($password, $user['password'])) {

                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['company'] = $user['company'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['role'] = $user['role'];

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Incorrect password.";
            }

        } else {
            $error = "No account found with that email.";
        }

        $stmt->close();

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

        <a href="../auth_select.php" class="back-btn">← Back</a>
        <h2>Employee Login</h2>

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
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required>
            </div>

            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required>
                <a href="forgot_password.php" class="forgot-link" style="display: block; margin-top: 5px; font-size: 0.85rem; color: #1B5E20; text-decoration: none;">Forgot Password?</a>
            </div>

            <button type="submit">Login</button>

        </form>

        <div class="signup-link">
            Don’t have an account?
            <a href="register.php">Sign up</a>
        </div>

    </div>
</div>

</body>
</html>

