<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="css/auth-select.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            margin: 0;
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

        .auth-container,
        .auth-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 100vh;
            width: 100%;
            max-width: 1360px;
            margin: 0 auto;
            padding: 64px;
            gap: 40px;
            background: transparent;
            position: relative;
            z-index: 1;
        }

        .auth-split-left {
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            height: 100%;
            min-width: 0;
        }

        .auth-brand-wrap {
            display: none;
        }

        .auth-split-right {
            width: 560px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            padding: 0;
            flex: 0 0 560px;
        }

        .auth-card {
            width: 75%;
            max-width: 450px;
            min-height: 450px;
            padding: 52px 48px 50px;
            border-radius: 28px;
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
            border-radius: 28px;
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
            justify-content: center;
        }

        .auth-card .card-title {
            margin: 0 0 8px 0;
            font-size: 32px;
            letter-spacing: -0.02em;
            line-height: 1.15;
            color: #14532d;
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
            margin-bottom: 0;
        }

        .auth-btn {
            height: 60px;
            border-radius: 16px;
            padding: 0 18px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(226, 232, 240, 0.95);
            box-shadow: 0 10px 24px rgba(2, 6, 23, 0.06);
        }

        .auth-btn:hover {
            background: #166534;
            border-color: rgba(22, 101, 52, 0.35);
            box-shadow: 0 14px 30px rgba(2, 6, 23, 0.14);
        }

        #pageTransition {
            position: fixed;
            inset: 0;
            background:
                radial-gradient(700px 420px at 70% 45%, rgba(22, 163, 74, 0.18), rgba(255, 255, 255, 0) 60%),
                linear-gradient(135deg, rgba(6, 46, 46, 0.92), rgba(2, 20, 43, 0.95));
            opacity: 0;
            pointer-events: none;
            transition: opacity 260ms ease;
            z-index: 9999;
        }

        body.tm-transitioning #pageTransition {
            opacity: 1;
            pointer-events: auto;
        }

        body.tm-transitioning .auth-card {
            transform: translateY(4px) scale(0.99);
            opacity: 0.92;
            transition: transform 260ms ease, opacity 260ms ease;
        }

        @media (prefers-reduced-motion: reduce) {
            #pageTransition { transition: none; }
            body.tm-transitioning .auth-card { transition: none; }
        }

        @media (min-width: 1100px) {
            .auth-split-right { transform: translateX(-70px); }
        }

        @media (max-width: 900px) {
            .auth-container,
            .auth-wrapper {
                height: auto;
                min-height: 100vh;
                flex-direction: column;
                justify-content: center;
                max-width: none;
                margin: 0;
                padding: 24px 18px 44px;
                gap: 26px;
            }

            .auth-split-left {
                width: 100%;
                height: auto;
                justify-content: center;
            }

            .auth-brand-wrap {
                display: none;
            }

            .auth-split-right {
                width: min(560px, 100%);
                padding: 0;
                flex: 0 0 auto;
                transform: none;
            }

            .auth-card {
                min-height: 0;
                padding: 34px 24px;
            }
        }
    </style>
</head>
<body>

<div id="pageTransition" aria-hidden="true"></div>

<div class="auth-wrapper auth-container">
    <section class="auth-split-left" aria-label="Leads Agri branding">
    </section>

    <section class="auth-split-right" aria-label="Role selection">
        <div class="auth-card">
            <h1 class="card-title">Welcome to Leads Agri Helpdesk</h1>
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
            </div>

            <div class="auth-extra auth-extra-hidden">
                <span>Don't have an account? </span>
                <a href="employee/register.php">Register here</a>
            </div>
        </div>
    </section>
</div>

<script>
(function () {
    function shouldIgnoreClick(e) {
        if (!e) return true;
        if (e.defaultPrevented) return true;
        if (e.button !== 0) return true;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return true;
        return false;
    }

    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a.auth-btn') : null;
        if (!a) return;
        if (shouldIgnoreClick(e)) return;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#') return;

        e.preventDefault();
        document.body.classList.add('tm-transitioning');
        window.setTimeout(function () {
            window.location.href = href;
        }, 220);
    });
})();
</script>

</body>
</html>
