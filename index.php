<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads Agri</title>
    <link rel="stylesheet" href="css/auth-select.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        :where(img) {
            max-width: 100%;
            height: auto;
        }

        :root {
            --auth-card-max-width: 520px;
            --auth-card-min-height: 600px;
            --auth-card-radius: 28px;
            --auth-card-padding: 40px 36px 42px;
            --auth-card-mobile-max-width: 360px;
            --auth-card-mobile-radius: 18px;
            --auth-card-mobile-padding: 24px 26px 26px;
            --auth-shell-max-width: 1680px;
            --auth-shell-right-padding: 132px;
            --auth-shell-left-padding: 64px;
            --auth-panel-width: 580px;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #e7f0f7;
            background: url('assets/img/try.png') no-repeat 15% center fixed;
            background-size: cover;
            position: relative;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(980px 560px at 14% 56%, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0) 66%),
                linear-gradient(90deg, rgba(255, 255, 255, 0.00) 0%, rgba(231, 240, 247, 0.22) 62%, rgba(231, 240, 247, 0.36) 100%);
            pointer-events: none;
            z-index: 0;
        }

        body.portal-home-page .auth-container,
        body.portal-home-page .auth-wrapper {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            height: 100vh;
            width: 100%;
            max-width: var(--auth-shell-max-width);
            margin: 0 auto;
            padding: 64px var(--auth-shell-right-padding) 64px var(--auth-shell-left-padding);
            gap: 0;
            background: transparent;
            position: relative;
            z-index: 1;
        }

        body.portal-home-page .auth-split-left {
            display: none;
        }

        .auth-brand-wrap {
            display: none;
        }

        body.portal-home-page .auth-split-right {
            width: min(var(--auth-panel-width), 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            padding: 0;
            flex: 0 0 var(--auth-panel-width);
        }

        .auth-card {
            width: min(100%, var(--auth-card-max-width));
            max-width: var(--auth-card-max-width);
            min-height: var(--auth-card-min-height);
            height: auto;
            padding: var(--auth-card-padding);
            border-radius: var(--auth-card-radius);
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.72);
            box-shadow:
                0 18px 50px rgba(2, 6, 23, 0.18),
                0 2px 0 rgba(255, 255, 255, 0.45) inset;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            text-align: center;
            position: relative;
        }

        .auth-card::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, rgba(22, 163, 74, 0.25), rgba(255, 255, 255, 0.0) 45%, rgba(15, 23, 42, 0.08));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .auth-card {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }

        .auth-card .card-title {
            margin: 0 0 10px 0;
            line-height: 1.12;
            color: #14532d;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .auth-card .card-title-top {
            display: block;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .auth-card .welcome-logo {
            width: 92px;
            height: 92px;
            object-fit: contain;
            display: block;
            margin: -12px auto 18px;
        }

        .auth-card .card-title-main {
            display: block;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: 0.01em;
            text-transform: uppercase;
        }

        .auth-card .subtitle {
            margin: 0 0 28px 0;
            color: #64748b;
            font-size: 15px;
        }

        .portal-buttons {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-top: 22px;
            margin-bottom: 0;
        }

        .auth-btn {
            height: 72px;
            border-radius: 20px;
            padding: 0 24px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 10px 24px rgba(2, 6, 23, 0.06);
        }

        .auth-btn .btn-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
        }

        .auth-btn .btn-icon i,
        .auth-btn .btn-arrow i {
            font-size: 22px;
        }

        .auth-btn .btn-label {
            font-size: 18px;
        }

        .auth-btn:hover {
            background: #166534;
            border-color: rgba(22, 101, 52, 0.35);
            box-shadow: 0 14px 30px rgba(2, 6, 23, 0.14);
        }

        @media (max-width: 900px) {
            body.portal-home-page .auth-container,
            body.portal-home-page .auth-wrapper {
                height: auto;
                min-height: 100vh;
                flex-direction: column;
                justify-content: center;
                max-width: none;
                margin: 0;
                padding: 24px 18px 44px;
                gap: 26px;
            }

            body.portal-home-page .auth-split-left {
                width: 100%;
                height: auto;
                justify-content: center;
            }

            .auth-brand-wrap {
                display: none;
            }

            body.portal-home-page .auth-split-right {
                width: min(520px, 100%);
                padding: 0;
                flex: 0 0 auto;
            }

            .auth-card {
                min-height: 0;
                padding: 40px 24px 34px;
            }
        }

        @media (max-width: 768px) {
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 20px;
            }

            body::after {
                content: "";
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.15);
                pointer-events: none;
                z-index: 0;
            }

            body.portal-home-page .auth-container,
            body.portal-home-page .auth-wrapper {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                height: auto;
                min-height: 0;
                padding: 0;
                gap: 0;
            }

            body.portal-home-page .auth-split-left {
                display: none;
            }

            body.portal-home-page .auth-split-right {
                width: 100%;
                flex: 0 0 auto;
                height: auto;
            }

            .auth-card {
                width: 100%;
                max-width: var(--auth-card-mobile-max-width);
                min-height: 0;
                padding: var(--auth-card-mobile-padding);
                border-radius: var(--auth-card-mobile-radius);
                text-align: center;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
            }
            .auth-title,
            .auth-card .card-title {
                gap: 6px;
                line-height: 1.2;
            }

            .auth-card .card-title-top {
                font-size: 16px;
            }

            .auth-card .card-title-main {
                font-size: 24px;
            }

            .auth-card .welcome-logo {
                width: 76px;
                height: 76px;
                margin: 10px auto 14px;
            }

            .auth-subtitle,
            .auth-card .subtitle {
                font-size: 14px;
                margin-bottom: 22px;
            }

            .portal-buttons {
                margin-top: 24px;
            }

            .auth-logo {
                width: 140px;
                margin-bottom: 12px;
            }

            .auth-button,
            .auth-btn {
                width: 100%;
                height: 62px;
                font-size: 17px;
            }

            .auth-btn .btn-icon {
                width: 40px;
                height: 40px;
            }

            .auth-btn .btn-icon i,
            .auth-btn .btn-arrow i {
                font-size: 20px;
            }

            .auth-button:active,
            .auth-btn:active {
                transform: scale(0.98);
            }
        }
    </style>
</head>
<body class="portal-home-page">

<div class="auth-wrapper auth-container">
    <section class="auth-split-left" aria-label="Leads Agri branding">
    </section>

    <section class="auth-split-right" aria-label="Role selection">
        <div class="auth-card">
            <img src="assets/img/leads-logo.png" alt="Leads Agri Logo" class="welcome-logo">
            <h1 class="card-title">
                <span class="card-title-main">Welcome to LEADS AGRI HELPDESK</span>
            </h1>
            <p class="subtitle">Choose a portal to continue</p>

            <div class="auth-buttons portal-buttons">
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

                <a href="conference_booking.php" class="auth-btn">
                    <span class="btn-icon" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></span>
                    <span class="btn-label">Book Conference Room</span>
                    <span class="btn-arrow" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                </a>

            </div>

            <div class="auth-extra auth-extra-hidden">
                <span>Don't have an account? </span>
                <a href="employee/register.php">Register here</a>
            </div>
        </div>
    </section>
</div>

</body>
</html>

