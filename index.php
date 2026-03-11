<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="css/auth-select.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-card">
        <h1>Welcome to Leads Agri Helpdesk</h1>
        <p>Choose your access role</p>

        <div class="auth-buttons">
            <a href="employee/employee_login.php" class="auth-btn">
                <span class="btn-icon" aria-hidden="true"><i class="fa-solid fa-right-to-bracket"></i></span>
                <span class="btn-label">Login</span>
                <span class="btn-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
            </a>

            <a href="sales/request_ticket.php" class="auth-btn">
                <span class="btn-icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
                <span class="btn-label">Sales Department</span>
                <span class="btn-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>

        <div class="auth-extra">
            <span>Don't have an account? </span>
            <a href="employee/register.php">Register here</a>
        </div>
    </div>
</div>

</body>
</html>
