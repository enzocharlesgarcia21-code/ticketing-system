<?php
$isPrivacyPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'privacy.php';
$pageTitle = $isPrivacyPage ? 'Privacy Policy' : 'Terms and Conditions';
$acknowledgmentDocument = $isPrivacyPage ? 'this Privacy Policy' : 'these Terms and Conditions';
$policyItems = $isPrivacyPage
    ? [
        ['Information We Collect', 'We collect account details, ticket information, attachments, comments, and system activity when you use the Leads DeskMetamorph Ticketing System.'],
        ['How We Use Information', 'We use collected data to process tickets, communicate updates, improve support services, and maintain system security.'],
        ['Information Sharing', 'We share information only with authorized Leads Agri personnel and relevant departments as required to resolve your concerns.'],
        ['Data Security', 'We implement reasonable administrative and technical safeguards to protect your information from unauthorized access, loss, or misuse.'],
        ['Data Retention', 'Ticket records are retained according to company policies and legitimate business or legal requirements.'],
        ['User Rights', 'You may request access, correction, or clarification regarding your personal information through the system administrator.'],
    ]
    : [
        ['Acceptance of Terms', 'By accessing and using the Leads DeskMetamorph Ticketing System, you accept and agree to be bound by these Terms and Conditions. If you do not agree with any part of these terms, you may not use this system.'],
        ['Authorized Use', 'This system is provided for the exclusive use of authorized employees, agents, and partners of Leads Agri. You agree to use the system only for legitimate business purposes in accordance with company policies and applicable laws.'],
        ['User Responsibilities', 'You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. You agree to notify the system administrator immediately of any unauthorized use or security breach.'],
        ['Ticket Information', 'All information submitted through the ticketing system must be accurate, complete, and truthful. Leads Agri reserves the right to update, close, or escalate tickets at its sole discretion based on business needs.'],
        ['System Availability', 'We strive to ensure the availability and reliability of the system; however, we do not guarantee uninterrupted access. The system may be temporarily unavailable due to maintenance, updates, or unforeseen technical issues.'],
        ['Limitation of Liability', 'To the fullest extent permitted by law, Leads Agri shall not be liable for any indirect, incidental, or consequential damages arising from the use or inability to use this system.'],
    ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | Leads DeskMetamorph</title>
    <link rel="icon" type="image/png" href="assets/img/leads-favicon.png?v=3">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            color: #0f172a;
            background: #f3f4f6 url('assets/img/kbkb.jpg') no-repeat -360px center fixed;
            background-size: cover;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background: rgba(255, 255, 255, 0.14);
        }

        .legal-page {
            position: relative;
            width: min(100%, 910px);
            padding: 24px 34px 18px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 20px 52px rgba(15, 23, 42, 0.16);
        }

        .legal-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .legal-brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #14532d;
            font-size: 16px;
            font-weight: 800;
        }

        .legal-brand img {
            width: 54px;
            height: 54px;
            object-fit: contain;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-width: 132px;
            height: 34px;
            padding: 0 14px;
            border: 1px solid #15803d;
            border-radius: 6px;
            color: #166534;
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .back-button:hover { background: #f0fdf4; }

        h1 {
            margin: 20px 0 4px;
            color: #14532d;
            font-size: 25px;
            line-height: 1.2;
        }

        .legal-intro {
            margin: 0 0 17px;
            color: #334155;
            font-size: 11px;
            line-height: 1.45;
        }

        .legal-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
        }

        .legal-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 164px;
            height: 35px;
            padding: 0 18px;
            border: 1px solid #e2e8f0;
            border-bottom: 0;
            border-radius: 6px 6px 0 0;
            color: #334155;
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .legal-tab + .legal-tab { margin-left: 4px; }
        .legal-tab.active { color: #fff; background: #08742c; border-color: #08742c; }

        .policy-panel {
            height: clamp(276px, 43vh, 350px);
            margin-top: 10px;
            padding: 14px 18px;
            overflow-y: auto;
            border: 1px solid #e1e7e4;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.8);
            scrollbar-width: thin;
            scrollbar-color: #8b929a transparent;
        }

        .policy-panel::-webkit-scrollbar { width: 8px; }
        .policy-panel::-webkit-scrollbar-thumb { border-radius: 10px; background: #8b929a; }

        .policy-list {
            margin: 0;
            padding-left: 22px;
        }

        .policy-list li { padding-left: 2px; }
        .policy-list li + li { margin-top: 10px; }
        .policy-list li::marker { color: #15803d; font-weight: 800; }

        .policy-list strong {
            display: block;
            margin-bottom: 2px;
            color: #15803d;
            font-size: 12px;
            line-height: 1.25;
        }

        .policy-list p {
            margin: 0;
            color: #1e293b;
            font-size: 10px;
            line-height: 1.42;
        }

        .legal-acknowledgment {
            margin-top: 12px;
            padding: 14px 0 2px;
            border-top: 1px solid #e2e8f0;
        }

        .legal-checkbox {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #1e293b;
            font-size: 12px;
            line-height: 1.35;
            cursor: pointer;
        }

        .legal-checkbox input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .legal-checkbox-mark {
            display: inline-flex;
            flex: 0 0 30px;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border: 1px solid #cbd5e1;
            border-radius: 50%;
            color: transparent;
            background: #fff;
            transition: 0.15s ease;
        }

        .legal-checkbox input:checked + .legal-checkbox-mark {
            border-color: #08742c;
            color: #fff;
            background: #08742c;
        }

        .legal-checkbox input:focus-visible + .legal-checkbox-mark {
            outline: 3px solid rgba(8, 116, 44, 0.2);
            outline-offset: 2px;
        }

        .legal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-top: 14px;
        }

        .updated-at { color: #334155; font-size: 10px; }
        .updated-at span { color: #15803d; }

        .acknowledge-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 110px;
            height: 35px;
            padding: 0 16px;
            border: 0;
            border-radius: 7px;
            color: #fff;
            background: #08742c;
            box-shadow: 0 4px 10px rgba(8, 116, 44, 0.2);
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .acknowledge-button:hover { background: #056522; }

        .acknowledge-button:disabled {
            cursor: not-allowed;
            background: #94a3b8;
            box-shadow: none;
        }

        @media (max-width: 600px) {
            body { align-items: flex-start; padding: 12px; }
            .legal-page { padding: 18px 16px; border-radius: 16px; }
            .legal-brand { font-size: 14px; gap: 8px; }
            .legal-brand img { width: 45px; height: 45px; }
            .back-button { min-width: auto; padding: 0 10px; }
            h1 { margin-top: 16px; font-size: 22px; }
            .legal-tab { min-width: 0; flex: 1; padding: 0 8px; }
            .policy-panel { height: 43vh; padding: 13px 14px; }
        }
    </style>
</head>
<body>
    <main class="legal-page">
        <header class="legal-topbar">
            <div class="legal-brand">
                <img src="assets/img/leads-logo.png" alt="Leads Agri logo">
                <span>Leads DeskMetamorph</span>
            </div>
            <a class="back-button" href="index.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to Portal</a>
        </header>

        <h1>Terms and Privacy</h1>
        <p class="legal-intro">Please review the policies governing your use of the Leads DeskMetamorph Ticketing System.</p>

        <nav class="legal-tabs" aria-label="Legal documents">
            <a class="legal-tab<?= $isPrivacyPage ? '' : ' active'; ?>" href="terms.php">Terms and Conditions</a>
            <a class="legal-tab<?= $isPrivacyPage ? ' active' : ''; ?>" href="privacy.php">Privacy Policy</a>
        </nav>

        <section class="policy-panel" aria-label="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
            <ol class="policy-list">
                <?php foreach ($policyItems as [$heading, $copy]): ?>
                    <li><strong><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></strong><p><?= htmlspecialchars($copy, ENT_QUOTES, 'UTF-8'); ?></p></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <div class="legal-acknowledgment">
            <label class="legal-checkbox">
                <input id="legalAcknowledgment" type="checkbox" data-policy="<?= $isPrivacyPage ? 'privacy' : 'terms'; ?>">
                <span class="legal-checkbox-mark"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                <span>I have read and understood <?= $acknowledgmentDocument; ?>.</span>
            </label>
        </div>

        <footer class="legal-footer">
            <div class="updated-at">Last updated: <span>September 2026</span></div>
            <button id="legalAcknowledgeButton" class="acknowledge-button" type="button" disabled>I Understand</button>
        </footer>
    </main>
    <script>
        (function () {
            var checkbox = document.getElementById('legalAcknowledgment');
            var button = document.getElementById('legalAcknowledgeButton');
            if (!checkbox || !button) return;

            var keys = {
                privacy: 'leadsLegalPrivacyAcknowledged',
                terms: 'leadsLegalTermsAcknowledged'
            };
            var policy = checkbox.dataset.policy;

            function readAcknowledgment(key) {
                try {
                    return window.localStorage.getItem(key) === '1';
                } catch (error) {
                    return false;
                }
            }

            function updateButton() {
                var bothAcknowledged = readAcknowledgment(keys.privacy) && readAcknowledgment(keys.terms);
                button.disabled = !bothAcknowledged;
            }

            checkbox.checked = readAcknowledgment(keys[policy]);
            updateButton();

            checkbox.addEventListener('change', function () {
                try {
                    window.localStorage.setItem(keys[policy], checkbox.checked ? '1' : '0');
                } catch (error) {}
                updateButton();
            });

            button.addEventListener('click', function () {
                if (!button.disabled) window.location.href = 'index.php';
            });
        }());
    </script>
</body>
</html>
