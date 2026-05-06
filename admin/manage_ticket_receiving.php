<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/user_permissions.php';

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

if (!user_permissions_can_manage($conn)) {
    http_response_code(403);
    echo 'Only the super admin can access this page.';
    exit();
}

ticket_receiving_availability_ensure_table($conn);
$availabilityRows = ticket_receiving_management_rows($conn);
$csrfToken = csrf_token();

$activeRoutes = 0;
$inactiveRoutes = 0;
$expandedCompanyKey = '';

foreach ($availabilityRows as $companyRow) {
    $companyEnabled = (int) ($companyRow['receiving_enabled'] ?? 0) === 1;
    $departments = is_array($companyRow['departments'] ?? null) ? $companyRow['departments'] : [];

    if ($companyEnabled) {
        $activeRoutes++;
    } else {
        $inactiveRoutes++;
    }

    if (count($departments) > 0 && $expandedCompanyKey === '') {
        $expandedCompanyKey = (string) ($companyRow['company_key'] ?? '');
    }

    foreach ($departments as $departmentRow) {
        $departmentEnabled = (int) ($departmentRow['receiving_enabled'] ?? 0) === 1;
        if ($departmentEnabled) {
            $activeRoutes++;
        } else {
            $inactiveRoutes++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Routing Control</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --route-green: #0f6a2f;
            --route-green-soft: #e9f8ee;
            --route-red: #dc2626;
            --route-red-soft: #fff1f2;
            --route-amber: #d99a00;
            --route-amber-soft: #fff7df;
            --route-text: #1f2a44;
            --route-muted: #718096;
            --route-border: #e4e9f2;
            --route-shell: #f5f8fc;
            --route-shadow: 0 16px 36px rgba(18, 38, 63, 0.08);
            --route-radius: 22px;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(21, 101, 52, 0.05), transparent 25%),
                linear-gradient(180deg, #f7f9fc 0%, #eef3f8 100%);
        }

        .routing-page {
            max-width: 1560px;
            margin: 0 auto;
            padding: 28px 34px 40px;
        }

        .routing-top {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
            margin-bottom: 22px;
        }

        .routing-intro {
            background:
                radial-gradient(circle at 14% 18%, rgba(14, 108, 47, 0.1), transparent 32%),
                linear-gradient(180deg, #ffffff 0%, #f9fbfe 100%);
            border: 1px solid rgba(222, 230, 240, 0.95);
            border-radius: 28px;
            padding: 24px 28px;
            box-shadow: var(--route-shadow);
            min-height: 138px;
        }

        .routing-intro h1 {
            margin: 0 0 12px;
            color: #111827;
            font-size: 2.05rem;
            font-weight: 600;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }

        .routing-intro p {
            margin: 0;
            max-width: 780px;
            color: #4a5b74;
            font-size: 15px;
            line-height: 1.7;
        }

        .routing-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .routing-stat {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(223, 230, 239, 0.98);
            border-radius: 22px;
            padding: 18px 18px 16px;
            box-shadow: var(--route-shadow);
            display: flex;
            gap: 14px;
            align-items: center;
            min-height: 126px;
        }

        .routing-stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
            flex: 0 0 54px;
        }

        .routing-stat.active .routing-stat-icon {
            background: var(--route-green-soft);
            color: var(--route-green);
        }

        .routing-stat.inactive .routing-stat-icon {
            background: var(--route-red-soft);
            color: var(--route-red);
        }

        .routing-stat.scope .routing-stat-icon {
            background: var(--route-amber-soft);
            color: var(--route-amber);
        }

        .routing-stat-label {
            color: #51627b;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .routing-stat-value {
            color: #1b2a41;
            font-size: 34px;
            font-weight: 400;
            line-height: 1;
            margin-bottom: 6px;
        }

        .routing-stat-note {
            color: #8391a7;
            font-size: 13px;
            line-height: 1.35;
        }

        .routing-feedback {
            display: none;
            margin-bottom: 18px;
            padding: 14px 18px;
            border-radius: 14px;
            font-weight: 700;
            box-shadow: 0 12px 28px rgba(18, 38, 63, 0.06);
        }

        .routing-feedback.is-success {
            display: block;
            background: #eefaf1;
            color: #166534;
            border: 1px solid #cfead5;
        }

        .routing-feedback.is-error {
            display: block;
            background: #fff2f4;
            color: #b42318;
            border: 1px solid #ffd0d5;
        }

        .routing-board {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(223, 230, 239, 0.98);
            border-radius: 24px;
            box-shadow: var(--route-shadow);
            overflow: hidden;
        }

        .routing-save-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 16px;
            padding: 16px 18px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(223, 230, 239, 0.98);
            border-radius: 18px;
            box-shadow: var(--route-shadow);
        }

        .routing-save-summary {
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
        }

        .routing-save-btn {
            min-height: 42px;
            padding: 0 18px;
            border: 0;
            border-radius: 12px;
            background: #1B5E20;
            color: #ffffff;
            cursor: pointer;
            font-size: 14px;
            font-weight: 800;
            box-shadow: 0 12px 22px rgba(27, 94, 32, 0.22);
            transition: background 0.16s ease, opacity 0.16s ease, transform 0.16s ease, box-shadow 0.16s ease;
        }

        .routing-save-btn:hover:not(:disabled) {
            background: #144a1e;
            transform: translateY(-1px);
            box-shadow: 0 16px 28px rgba(27, 94, 32, 0.26);
        }

        .routing-save-btn:disabled {
            cursor: not-allowed;
            opacity: 0.45;
            box-shadow: none;
        }

        .routing-confirm-overlay {
            position: fixed;
            inset: 0;
            z-index: 7000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, 0.28);
            backdrop-filter: blur(2px);
        }

        .routing-confirm-overlay.is-visible {
            display: flex;
        }

        .routing-confirm-dialog {
            width: min(420px, 100%);
            padding: 28px 28px 22px;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.24);
            text-align: center;
        }

        .routing-confirm-icon {
            width: 58px;
            height: 58px;
            margin: 0 auto 16px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eaf7ec;
            color: #1B5E20;
            box-shadow: 0 0 0 8px rgba(27, 94, 32, 0.08);
            font-size: 24px;
        }

        .routing-confirm-title {
            margin: 0 0 8px;
            color: #172033;
            font-size: 20px;
            font-weight: 800;
        }

        .routing-confirm-text {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.5;
        }

        .routing-confirm-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #edf2f7;
        }

        .routing-confirm-actions.is-single {
            grid-template-columns: 1fr;
        }

        .routing-confirm-btn {
            min-height: 42px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 800;
            transition: background 0.16s ease, border-color 0.16s ease, transform 0.16s ease;
        }

        .routing-confirm-btn.is-cancel {
            border: 1px solid #dbe4ee;
            background: #ffffff;
            color: #334155;
        }

        .routing-confirm-btn.is-save {
            border: 1px solid #1B5E20;
            background: #1B5E20;
            color: #ffffff;
        }

        .routing-confirm-btn.is-ok {
            border: 1px solid #1B5E20;
            background: #1B5E20;
            color: #ffffff;
        }

        .routing-confirm-btn:hover {
            transform: translateY(-1px);
        }

        .routing-master-control {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 16px;
            padding: 18px 22px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(223, 230, 239, 0.98);
            border-radius: 22px;
            box-shadow: var(--route-shadow);
        }

        .routing-master-title {
            color: #1b2a41;
            font-size: 16px;
            font-weight: 400;
            margin-bottom: 4px;
        }

        .routing-master-note {
            color: #718096;
            font-size: 13px;
            font-weight: 600;
        }

        .routing-master-action {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex: 0 0 auto;
        }

        .routing-master-state {
            min-width: 86px;
            color: #46556d;
            font-size: 13px;
            font-weight: 800;
            text-align: right;
        }

        .routing-board-head,
        .route-row {
            display: grid;
            grid-template-columns: minmax(470px, 1fr) minmax(190px, 0.34fr) minmax(150px, 0.24fr);
            gap: 18px;
            align-items: center;
            padding: 0 22px;
        }

        .routing-board-head {
            min-height: 64px;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbfe 100%);
            border-bottom: 1px solid #edf2f7;
            color: #6c7a90;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .route-group {
            border-bottom: 1px solid #edf2f7;
        }

        .route-group:last-child {
            border-bottom: 0;
        }

        .route-row {
            min-height: 96px;
        }

        .route-company-row {
            background: #ffffff;
        }

        .route-company-row.is-expanded {
            background: linear-gradient(180deg, #ffffff 0%, #fbfdfd 100%);
        }

        .route-identity {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }

        .route-expand {
            width: 28px;
            height: 28px;
            border: 0;
            background: transparent;
            color: #617189;
            cursor: pointer;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: background 0.16s ease, color 0.16s ease, transform 0.16s ease;
        }

        .route-expand:hover {
            background: #eef3f9;
            color: #2d3e58;
        }

        .route-expand.is-expanded i {
            transform: rotate(90deg);
        }

        .route-expand i {
            transition: transform 0.16s ease;
        }

        .route-company-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: linear-gradient(180deg, #edf9f0 0%, #e0f3e6 100%);
            color: #1b7a3b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex: 0 0 44px;
        }

        .route-company-text {
            min-width: 0;
        }

        .route-company-name {
            color: #22324d;
            font-size: 17px;
            font-weight: 400;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .route-company-domain {
            color: #8090a7;
            font-size: 14px;
        }

        .route-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 999px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }

        .route-status-pill.is-enabled {
            background: #eaf7ec;
            color: #1b7a3b;
        }

        .route-status-pill.is-disabled {
            background: #fff0f1;
            color: #dc2626;
        }

        .route-switch {
            position: relative;
            width: 44px;
            height: 24px;
            border-radius: 999px;
            border: 0;
            background: #d8dee8;
            cursor: pointer;
            transition: background 0.16s ease, opacity 0.16s ease;
        }

        .route-switch::after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.18);
            transition: transform 0.16s ease;
        }

        .route-switch.is-enabled {
            background: #55c375;
        }

        .route-switch.is-pending {
            box-shadow: 0 0 0 4px rgba(244, 196, 48, 0.18);
        }

        .route-switch.is-enabled::after {
            transform: translateX(20px);
        }

        .route-switch:disabled {
            cursor: wait;
            opacity: 0.65;
        }

        .route-departments {
            display: none;
            position: relative;
            padding: 0 0 10px;
        }

        .route-departments.is-expanded {
            display: block;
        }

        .route-departments::before {
            content: '';
            position: absolute;
            left: 108px;
            top: 0;
            bottom: 16px;
            width: 2px;
            background: linear-gradient(180deg, rgba(82, 187, 112, 0.72), rgba(82, 187, 112, 0.28));
        }

        .route-department-row {
            min-height: 66px;
            padding-left: 0;
        }

        .route-department-row .route-identity {
            position: relative;
            padding-left: 132px;
        }

        .route-department-row .route-identity::before {
            content: '';
            position: absolute;
            left: 108px;
            top: 50%;
            width: 10px;
            height: 10px;
            background: #4bb569;
            border-radius: 999px;
            transform: translate(-50%, -50%);
            box-shadow: 0 0 0 4px #ffffff;
        }

        .route-department-label {
            color: #2a3954;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.4;
            white-space: normal;
            overflow-wrap: anywhere;
            display: block;
            padding-left: 4px;
        }

        .route-empty {
            display: none;
            padding: 48px 28px 54px;
            text-align: center;
            color: #708198;
        }

        .route-empty.is-visible {
            display: block;
        }

        .route-empty i {
            font-size: 34px;
            margin-bottom: 12px;
            color: #9dafc3;
        }

        @media (max-width: 1320px) {
            .routing-top {
                grid-template-columns: 1fr;
            }

            .routing-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 1180px) {
            .routing-board {
                overflow-x: auto;
            }

            .routing-board-head,
            .route-row {
                min-width: 840px;
            }
        }

        @media (max-width: 860px) {
            .routing-page {
                padding: 18px 14px 28px;
            }

            .routing-summary {
                grid-template-columns: 1fr;
            }

            .routing-master-control {
                align-items: flex-start;
                flex-direction: column;
            }

            .routing-master-action {
                justify-content: space-between;
                width: 100%;
            }

            .routing-save-bar {
                align-items: stretch;
                flex-direction: column;
            }

        }
    </style>
</head>
<body>
<?php include '../includes/admin_navbar.php'; ?>

<div class="routing-page">
    <section class="routing-top">
        <div class="routing-intro">
            <h1>Ticket Routing Control</h1>
            <p>Control which companies and departments can receive new tickets. Changes only affect new submissions. Existing tickets remain visible and manageable.</p>
        </div>

        <div class="routing-summary">
            <article class="routing-stat active">
                <span class="routing-stat-icon"><i class="fa-solid fa-route"></i></span>
                <div>
                    <div class="routing-stat-label">Active Routes</div>
                    <div class="routing-stat-value" id="activeRouteCount"><?= (int) $activeRoutes ?></div>
                    <div class="routing-stat-note">Accepting new tickets</div>
                </div>
            </article>
            <article class="routing-stat inactive">
                <span class="routing-stat-icon"><i class="fa-solid fa-ban"></i></span>
                <div>
                    <div class="routing-stat-label">Inactive Routes</div>
                    <div class="routing-stat-value" id="inactiveRouteCount"><?= (int) $inactiveRoutes ?></div>
                    <div class="routing-stat-note">Not accepting tickets</div>
                </div>
            </article>
        </div>
    </section>

    <div id="availabilityFeedback" class="routing-feedback" aria-live="polite"></div>

    <div class="routing-master-control">
        <div>
            <div class="routing-master-title">All Ticket Receiving</div>
            <div class="routing-master-note">Turn every company and department route on or off at once.</div>
        </div>
        <div class="routing-master-action">
            <span class="routing-master-state" id="allRoutesMasterState"></span>
            <button
                type="button"
                class="route-switch <?= ($inactiveRoutes === 0 && ($activeRoutes + $inactiveRoutes) > 0) ? 'is-enabled' : ''; ?>"
                id="allRoutesToggle"
                data-next-enabled="<?= ($inactiveRoutes === 0 && ($activeRoutes + $inactiveRoutes) > 0) ? '0' : '1'; ?>"
                aria-label="Toggle all ticket receiving routes"
            ></button>
        </div>
    </div>

    <section class="routing-board">
        <div class="routing-board-head">
            <div>Company</div>
            <div>Status</div>
            <div>Control</div>
        </div>

        <div id="routeBoardBody">
            <?php foreach ($availabilityRows as $companyRow): ?>
                <?php
                $companyEnabled = (int) ($companyRow['receiving_enabled'] ?? 0) === 1;
                $companyKey = (string) ($companyRow['company_key'] ?? '');
                $companyLabel = (string) ($companyRow['company_label'] ?? $companyKey);
                $departments = is_array($companyRow['departments'] ?? null) ? $companyRow['departments'] : [];
                $hasDepartments = count($departments) > 0;
                $isExpanded = $expandedCompanyKey !== '' && $expandedCompanyKey === $companyKey;
                ?>
                <div
                    class="route-group"
                    data-company-key="<?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?>"
                    data-company-label="<?= htmlspecialchars(strtolower($companyLabel), ENT_QUOTES, 'UTF-8'); ?>"
                    data-company-status="<?= $companyEnabled ? 'enabled' : 'disabled'; ?>"
                    data-has-departments="<?= $hasDepartments ? '1' : '0'; ?>"
                >
                    <div class="route-row route-company-row <?= $isExpanded ? 'is-expanded' : ''; ?>">
                        <div class="route-identity">
                            <?php if ($hasDepartments): ?>
                                <button
                                    type="button"
                                    class="route-expand <?= $isExpanded ? 'is-expanded' : ''; ?>"
                                    data-expand-company="<?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?>"
                                    aria-label="Toggle <?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?> departments"
                                >
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            <?php else: ?>
                                <span class="route-expand" aria-hidden="true"></span>
                            <?php endif; ?>

                            <span class="route-company-icon"><i class="fa-regular fa-building"></i></span>
                            <div class="route-company-text">
                                <div class="route-company-name"><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="route-company-domain"><?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>

                        <div>
                            <span class="route-status-pill <?= $companyEnabled ? 'is-enabled' : 'is-disabled'; ?>">
                                <?= $companyEnabled ? 'Accepting Tickets' : 'Not Accepting'; ?>
                            </span>
                        </div>

                        <div>
                            <button
                                type="button"
                                class="route-switch <?= $companyEnabled ? 'is-enabled' : ''; ?> availability-toggle"
                                data-item-type="company"
                                data-company="<?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?>"
                                data-department=""
                                data-next-enabled="<?= $companyEnabled ? '0' : '1'; ?>"
                                aria-label="<?= $companyEnabled ? 'Disable' : 'Enable'; ?> <?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?>"
                            ></button>
                        </div>

                    </div>

                    <?php if ($hasDepartments): ?>
                        <div class="route-departments <?= $isExpanded ? 'is-expanded' : ''; ?>" data-department-list="<?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php foreach ($departments as $departmentRow): ?>
                                <?php
                                $departmentEnabled = (int) ($departmentRow['receiving_enabled'] ?? 0) === 1;
                                $departmentName = (string) ($departmentRow['department_name'] ?? '');
                                ?>
                                <div
                                    class="route-row route-department-row"
                                    data-department-name="<?= htmlspecialchars(strtolower($departmentName), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-route-status="<?= $departmentEnabled ? 'enabled' : 'disabled'; ?>"
                                >
                                    <div class="route-identity">
                                        <div class="route-department-label"><?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8'); ?></div>
                                    </div>

                                    <div>
                                        <span class="route-status-pill <?= $departmentEnabled ? 'is-enabled' : 'is-disabled'; ?>">
                                            <?= $departmentEnabled ? 'Accepting Tickets' : 'Not Accepting'; ?>
                                        </span>
                                    </div>

                                    <div>
                                        <button
                                            type="button"
                                            class="route-switch <?= $departmentEnabled ? 'is-enabled' : ''; ?> availability-toggle"
                                            data-item-type="department"
                                            data-company="<?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-department="<?= htmlspecialchars($departmentName, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-next-enabled="<?= $departmentEnabled ? '0' : '1'; ?>"
                                            aria-label="<?= $departmentEnabled ? 'Disable' : 'Enable'; ?> <?= htmlspecialchars($companyLabel . ' - ' . $departmentName, ENT_QUOTES, 'UTF-8'); ?>"
                                        ></button>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="routeEmptyState" class="route-empty">
            <i class="fa-regular fa-folder-open"></i>
            <div>No routes match the current filters.</div>
        </div>
    </section>

    <div class="routing-save-bar">
        <div class="routing-save-summary" id="routeSaveSummary">No pending changes.</div>
        <button type="button" class="routing-save-btn" id="routeSaveButton" disabled>Save Changes</button>
    </div>
</div>

<div class="routing-confirm-overlay" id="routeSaveConfirm" role="dialog" aria-modal="true" aria-labelledby="routeSaveConfirmTitle">
    <div class="routing-confirm-dialog">
        <div class="routing-confirm-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></div>
        <h2 class="routing-confirm-title" id="routeSaveConfirmTitle">Save Changes?</h2>
        <p class="routing-confirm-text">This will update the ticket receiving availability for the selected company or department.</p>
        <div class="routing-confirm-actions">
            <button type="button" class="routing-confirm-btn is-cancel" id="routeSaveCancel">Cancel</button>
            <button type="button" class="routing-confirm-btn is-save" id="routeSaveConfirmBtn">Save Changes</button>
        </div>
    </div>
</div>

<div class="routing-confirm-overlay" id="routeSaveSuccess" role="dialog" aria-modal="true" aria-labelledby="routeSaveSuccessTitle">
    <div class="routing-confirm-dialog">
        <div class="routing-confirm-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></div>
        <h2 class="routing-confirm-title" id="routeSaveSuccessTitle">Changes Saved</h2>
        <p class="routing-confirm-text">Ticket receiving availability was successfully updated.</p>
        <div class="routing-confirm-actions is-single">
            <button type="button" class="routing-confirm-btn is-ok" id="routeSaveSuccessOk">OK</button>
        </div>
    </div>
</div>

<script>
const availabilityFeedback = document.getElementById('availabilityFeedback');
const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const routeEmptyState = document.getElementById('routeEmptyState');
const activeRouteCount = document.getElementById('activeRouteCount');
const inactiveRouteCount = document.getElementById('inactiveRouteCount');
const allRoutesToggle = document.getElementById('allRoutesToggle');
const allRoutesMasterState = document.getElementById('allRoutesMasterState');
const routeSaveButton = document.getElementById('routeSaveButton');
const routeSaveSummary = document.getElementById('routeSaveSummary');
const routeSaveConfirm = document.getElementById('routeSaveConfirm');
const routeSaveCancel = document.getElementById('routeSaveCancel');
const routeSaveConfirmBtn = document.getElementById('routeSaveConfirmBtn');
const routeSaveSuccess = document.getElementById('routeSaveSuccess');
const routeSaveSuccessOk = document.getElementById('routeSaveSuccessOk');
let isSavingRoutes = false;

function setAvailabilityFeedback(message, type) {
    if (!availabilityFeedback) return;
    availabilityFeedback.className = 'routing-feedback';
    if (!message) {
        availabilityFeedback.textContent = '';
        return;
    }
    availabilityFeedback.textContent = message;
    availabilityFeedback.classList.add(type === 'error' ? 'is-error' : 'is-success');
}

function toggleCompanyExpansion(companyKey, expand) {
    const trigger = document.querySelector('[data-expand-company="' + companyKey + '"]');
    const list = document.querySelector('[data-department-list="' + companyKey + '"]');
    const row = trigger ? trigger.closest('.route-company-row') : null;
    if (!trigger || !list) return;

    const shouldExpand = typeof expand === 'boolean' ? expand : !list.classList.contains('is-expanded');
    list.classList.toggle('is-expanded', shouldExpand);
    trigger.classList.toggle('is-expanded', shouldExpand);
    if (row) {
        row.classList.toggle('is-expanded', shouldExpand);
    }
}

function recalcRouteCounts() {
    let active = 0;
    let inactive = 0;
    document.querySelectorAll('.availability-toggle').forEach(function(toggle) {
        if (toggle.classList.contains('route-switch')) {
            if (toggle.classList.contains('is-enabled')) {
                active++;
            } else {
                inactive++;
            }
        }
    });
    if (activeRouteCount) activeRouteCount.textContent = String(active);
    if (inactiveRouteCount) inactiveRouteCount.textContent = String(inactive);
    updateMasterToggleState(active, inactive);
}

function updateMasterToggleState(active, inactive) {
    if (!allRoutesToggle) return;
    const total = active + inactive;
    const allEnabled = total > 0 && inactive === 0;
    allRoutesToggle.classList.toggle('is-enabled', allEnabled);
    allRoutesToggle.setAttribute('data-next-enabled', allEnabled ? '0' : '1');
    allRoutesToggle.disabled = total === 0 || isSavingRoutes;
    if (allRoutesMasterState) {
        allRoutesMasterState.textContent = total === 0 ? 'No routes' : (allEnabled ? 'All On' : (active === 0 ? 'All Off' : 'Some Off'));
    }
}

function routeButtonEnabled(button) {
    return button.classList.contains('is-enabled');
}

function pendingRouteButtons() {
    return Array.from(document.querySelectorAll('.availability-toggle')).filter(function(button) {
        return button.classList.contains('is-pending');
    });
}

function markRoutePending(button) {
    const originalEnabled = String(button.getAttribute('data-original-enabled') || '0') === '1';
    button.classList.toggle('is-pending', routeButtonEnabled(button) !== originalEnabled);
}

function updateSaveBar() {
    const pendingCount = pendingRouteButtons().length;
    if (routeSaveSummary) {
        routeSaveSummary.textContent = pendingCount === 0
            ? 'No pending changes.'
            : pendingCount + ' pending ' + (pendingCount === 1 ? 'change' : 'changes') + '. Click Save Changes to apply.';
    }
    if (routeSaveButton) {
        routeSaveButton.disabled = pendingCount === 0 || isSavingRoutes;
    }
}

function updateRowVisualState(button, enabled) {
    const row = button.closest('.route-row');
    if (!row) return;
    const pill = row.querySelector('.route-status-pill');
    if (pill) {
        pill.className = 'route-status-pill ' + (enabled ? 'is-enabled' : 'is-disabled');
        pill.textContent = enabled ? 'Accepting Tickets' : 'Not Accepting';
    }
    if (button.classList.contains('route-switch')) {
        button.classList.toggle('is-enabled', enabled);
        button.setAttribute('data-next-enabled', enabled ? '0' : '1');
        markRoutePending(button);
    }

    if (button.getAttribute('data-item-type') === 'company') {
        const group = button.closest('.route-group');
        if (group) {
            group.setAttribute('data-company-status', enabled ? 'enabled' : 'disabled');
        }
    } else if (button.getAttribute('data-item-type') === 'department') {
        row.setAttribute('data-route-status', enabled ? 'enabled' : 'disabled');
    }
}

function parentCompanyToggleFor(button) {
    const group = button.closest('.route-group');
    return group ? group.querySelector('.route-company-row .availability-toggle[data-item-type="company"]') : null;
}

function canEnableDepartment(button) {
    if (button.getAttribute('data-item-type') !== 'department') return true;
    const parentToggle = parentCompanyToggleFor(button);
    return !!(parentToggle && parentToggle.classList.contains('is-enabled'));
}

document.querySelectorAll('[data-expand-company]').forEach(function(trigger) {
    trigger.addEventListener('click', function() {
        const companyKey = String(trigger.getAttribute('data-expand-company') || '');
        if (companyKey !== '') {
            toggleCompanyExpansion(companyKey);
        }
    });
});

function saveAvailability(button, nextEnabled) {
    const company = String(button.getAttribute('data-company') || '').trim();
    const department = String(button.getAttribute('data-department') || '').trim();
    const itemType = String(button.getAttribute('data-item-type') || '').trim();

    return fetch('ajax_ticket_receiving_availability.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
            csrf_token: csrfToken,
            item_type: itemType,
            company_key: company,
            department_name: department,
            receiving_enabled: nextEnabled
        }).toString()
    }).then(function(response) {
        return response.json().then(function(payload) {
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Unable to update availability.');
            }
            return payload;
        });
    });
}

document.querySelectorAll('.availability-toggle').forEach(function(button) {
    button.setAttribute('data-original-enabled', button.classList.contains('is-enabled') ? '1' : '0');

    button.addEventListener('click', function() {
        if (isSavingRoutes) return;
        const nextEnabled = String(button.getAttribute('data-next-enabled') || '0') === '1' ? '1' : '0';
        if (nextEnabled === '1' && !canEnableDepartment(button)) {
            setAvailabilityFeedback('Turn on the subsidiary first before enabling its departments.', 'error');
            return;
        }

        updateRowVisualState(button, nextEnabled === '1');
        recalcRouteCounts();
        updateSaveBar();
        setAvailabilityFeedback('', 'success');
    });
});

if (allRoutesToggle) {
    allRoutesToggle.addEventListener('click', function() {
        const nextEnabled = String(allRoutesToggle.getAttribute('data-next-enabled') || '0') === '1' ? '1' : '0';
        const targetEnabled = nextEnabled === '1';
        const routeButtons = Array.from(document.querySelectorAll('.availability-toggle'));
        const buttonsToUpdate = routeButtons.filter(function(button) {
            return button.classList.contains('is-enabled') !== targetEnabled;
        });

        if (buttonsToUpdate.length === 0) {
            setAvailabilityFeedback(targetEnabled ? 'All routes are already enabled.' : 'All routes are already disabled.', 'success');
            return;
        }

        buttonsToUpdate.forEach(function(button) {
            updateRowVisualState(button, targetEnabled);
        });
        recalcRouteCounts();
        updateSaveBar();
        setAvailabilityFeedback(buttonsToUpdate.length + ' pending ' + (buttonsToUpdate.length === 1 ? 'change' : 'changes') + '. Click Save Changes to apply.', 'success');
    });
}

function orderedPendingRouteButtons() {
    return pendingRouteButtons().sort(function(a, b) {
        function weight(button) {
            const itemType = button.getAttribute('data-item-type');
            const enabled = routeButtonEnabled(button);
            if (enabled && itemType === 'company') return 0;
            if (enabled && itemType === 'department') return 1;
            if (!enabled && itemType === 'department') return 2;
            return 3;
        }
        return weight(a) - weight(b);
    });
}

function setRouteControlsDisabled(disabled) {
    document.querySelectorAll('.availability-toggle').forEach(function(button) {
        button.disabled = disabled;
    });
    if (allRoutesToggle) {
        allRoutesToggle.disabled = disabled;
    }
}

if (routeSaveButton) {
    routeSaveButton.addEventListener('click', function() {
        if (routeSaveButton.disabled || isSavingRoutes) return;
        if (routeSaveConfirm) {
            routeSaveConfirm.classList.add('is-visible');
            if (routeSaveConfirmBtn) routeSaveConfirmBtn.focus();
        }
    });
}

function closeRouteSaveConfirm() {
    if (routeSaveConfirm) {
        routeSaveConfirm.classList.remove('is-visible');
    }
}

function showRouteSaveSuccess() {
    if (routeSaveSuccess) {
        routeSaveSuccess.classList.add('is-visible');
        if (routeSaveSuccessOk) routeSaveSuccessOk.focus();
    }
}

function closeRouteSaveSuccess() {
    if (routeSaveSuccess) {
        routeSaveSuccess.classList.remove('is-visible');
    }
}

if (routeSaveCancel) {
    routeSaveCancel.addEventListener('click', closeRouteSaveConfirm);
}

if (routeSaveConfirm) {
    routeSaveConfirm.addEventListener('click', function(event) {
        if (event.target === routeSaveConfirm) {
            closeRouteSaveConfirm();
        }
    });
}

if (routeSaveSuccessOk) {
    routeSaveSuccessOk.addEventListener('click', closeRouteSaveSuccess);
}

if (routeSaveSuccess) {
    routeSaveSuccess.addEventListener('click', function(event) {
        if (event.target === routeSaveSuccess) {
            closeRouteSaveSuccess();
        }
    });
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && routeSaveConfirm && routeSaveConfirm.classList.contains('is-visible')) {
        closeRouteSaveConfirm();
    }
    if (event.key === 'Escape' && routeSaveSuccess && routeSaveSuccess.classList.contains('is-visible')) {
        closeRouteSaveSuccess();
    }
});

if (routeSaveConfirmBtn) {
    routeSaveConfirmBtn.addEventListener('click', function() {
        closeRouteSaveConfirm();
        const buttonsToSave = orderedPendingRouteButtons();
        if (buttonsToSave.length === 0 || isSavingRoutes) return;

        isSavingRoutes = true;
        setRouteControlsDisabled(true);
        updateSaveBar();
        setAvailabilityFeedback('Saving route changes...', 'success');

        const results = [];
        let chain = Promise.resolve();
        buttonsToSave.forEach(function(button) {
            chain = chain.then(function() {
                const nextEnabled = routeButtonEnabled(button) ? '1' : '0';
                return saveAvailability(button, nextEnabled)
                    .then(function(payload) {
                        button.setAttribute('data-original-enabled', nextEnabled);
                        markRoutePending(button);
                        results.push({ ok: true, payload: payload });
                    })
                    .catch(function(error) {
                        results.push({ ok: false, error: error });
                    });
            });
        });

        chain.then(function() {
            const failed = results.filter(function(result) { return !result.ok; });
            isSavingRoutes = false;
            setRouteControlsDisabled(false);
            recalcRouteCounts();
            updateSaveBar();

            if (failed.length > 0) {
                setAvailabilityFeedback((buttonsToSave.length - failed.length) + ' changes saved. ' + failed.length + ' failed to save.', 'error');
                return;
            }

            setAvailabilityFeedback('Ticket receiving changes saved successfully.', 'success');
            showRouteSaveSuccess();
        });
    });
}

recalcRouteCounts();
updateSaveBar();
if (routeEmptyState) {
    routeEmptyState.classList.toggle('is-visible', document.querySelectorAll('.route-group').length === 0);
}
</script>
</body>
</html>
