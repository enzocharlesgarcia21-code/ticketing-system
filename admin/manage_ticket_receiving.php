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
$companyOnlyCount = 0;
$expandedCompanyKey = '';

foreach ($availabilityRows as $companyRow) {
    $companyEnabled = (int) ($companyRow['receiving_enabled'] ?? 0) === 1;
    $departments = is_array($companyRow['departments'] ?? null) ? $companyRow['departments'] : [];

    if ($companyEnabled) {
        $activeRoutes++;
    } else {
        $inactiveRoutes++;
    }

    if (count($departments) === 0) {
        $companyOnlyCount++;
    } elseif ($expandedCompanyKey === '') {
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
            color: #155b2a;
            font-size: 24px;
            font-weight: 800;
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
            grid-template-columns: repeat(3, minmax(0, 1fr));
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
            font-weight: 800;
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

        .routing-toolbar {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(223, 230, 239, 0.98);
            border-radius: 22px;
            padding: 18px;
            box-shadow: var(--route-shadow);
            display: grid;
            grid-template-columns: minmax(280px, 1.4fr) minmax(220px, 1fr) minmax(220px, 1fr) auto;
            gap: 14px;
            align-items: center;
            margin-bottom: 20px;
        }

        .routing-search,
        .routing-select {
            position: relative;
        }

        .routing-search input,
        .routing-select select {
            width: 100%;
            min-height: 54px;
            border-radius: 16px;
            border: 1px solid var(--route-border);
            background: #ffffff;
            color: var(--route-text);
            font-size: 15px;
            padding: 0 18px 0 48px;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        .routing-select select {
            appearance: none;
            padding-right: 44px;
        }

        .routing-search input:focus,
        .routing-select select:focus {
            border-color: #5ca46f;
            box-shadow: 0 0 0 4px rgba(92, 164, 111, 0.12);
        }

        .routing-search i,
        .routing-select i {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: #728198;
            pointer-events: none;
        }

        .routing-search i {
            left: 18px;
        }

        .routing-select i:first-child {
            left: 18px;
        }

        .routing-select .caret {
            right: 16px;
        }

        .routing-toolbar-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .routing-btn {
            min-height: 54px;
            border-radius: 16px;
            border: 1px solid var(--route-border);
            background: #ffffff;
            color: #46556d;
            font-size: 14px;
            font-weight: 700;
            padding: 0 18px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
        }

        .routing-btn:hover {
            transform: translateY(-1px);
            border-color: #ccd7e5;
            box-shadow: 0 10px 22px rgba(18, 38, 63, 0.08);
        }

        .routing-board {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(223, 230, 239, 0.98);
            border-radius: 24px;
            box-shadow: var(--route-shadow);
            overflow: hidden;
        }

        .routing-board-head,
        .route-row {
            display: grid;
            grid-template-columns: minmax(470px, 1.35fr) minmax(280px, 0.85fr) minmax(190px, 0.55fr) minmax(150px, 0.3fr) 40px;
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
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .route-company-domain {
            color: #8090a7;
            font-size: 14px;
        }

        .route-scope-title {
            color: #31445f;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .route-scope-note {
            color: #8a96a9;
            font-size: 14px;
            font-style: italic;
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

        .route-switch.is-enabled::after {
            transform: translateX(20px);
        }

        .route-switch:disabled {
            cursor: wait;
            opacity: 0.65;
        }

        .route-menu-dot {
            width: 30px;
            height: 30px;
            border-radius: 999px;
            color: #74839a;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: transparent;
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
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 1180px) {
            .routing-toolbar {
                grid-template-columns: 1fr 1fr;
            }

            .routing-toolbar-actions {
                grid-column: 1 / -1;
                justify-content: stretch;
            }

            .routing-toolbar-actions .routing-btn {
                flex: 1 1 0;
                justify-content: center;
            }

            .routing-board {
                overflow-x: auto;
            }

            .routing-board-head,
            .route-row {
                min-width: 1140px;
            }
        }

        @media (max-width: 860px) {
            .routing-page {
                padding: 18px 14px 28px;
            }

            .routing-summary {
                grid-template-columns: 1fr;
            }

            .routing-toolbar {
                grid-template-columns: 1fr;
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
            <article class="routing-stat scope">
                <span class="routing-stat-icon"><i class="fa-regular fa-building"></i></span>
                <div>
                    <div class="routing-stat-label">Company-Level Only</div>
                    <div class="routing-stat-value"><?= (int) $companyOnlyCount ?></div>
                    <div class="routing-stat-note">No department routing</div>
                </div>
            </article>
        </div>
    </section>

    <div id="availabilityFeedback" class="routing-feedback" aria-live="polite"></div>

    <section class="routing-toolbar">
        <div class="routing-search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" id="routeSearchInput" placeholder="Search companies or departments...">
        </div>

        <div class="routing-select">
            <i class="fa-regular fa-building"></i>
            <select id="routeCompanyFilter">
                <option value="">All Companies</option>
                <?php foreach ($availabilityRows as $companyRow): ?>
                    <?php
                    $companyKey = (string) ($companyRow['company_key'] ?? '');
                    $companyLabel = (string) ($companyRow['company_label'] ?? $companyKey);
                    ?>
                    <option value="<?= htmlspecialchars($companyKey, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <i class="fa-solid fa-chevron-down caret"></i>
        </div>

        <div class="routing-select">
            <i class="fa-solid fa-filter"></i>
            <select id="routeStatusFilter">
                <option value="">All Statuses</option>
                <option value="enabled">Accepting Tickets</option>
                <option value="disabled">Not Accepting</option>
            </select>
            <i class="fa-solid fa-chevron-down caret"></i>
        </div>

        <div class="routing-toolbar-actions">
            <button type="button" class="routing-btn" id="expandAllBtn"><i class="fa-solid fa-chevron-down"></i> Expand All</button>
            <button type="button" class="routing-btn" id="collapseAllBtn"><i class="fa-solid fa-chevron-up"></i> Collapse All</button>
        </div>
    </section>

    <section class="routing-board">
        <div class="routing-board-head">
            <div>Company</div>
            <div>Department / Scope</div>
            <div>Status</div>
            <div>Control</div>
            <div></div>
        </div>

        <div id="routeBoardBody">
            <?php foreach ($availabilityRows as $companyRow): ?>
                <?php
                $companyEnabled = (int) ($companyRow['receiving_enabled'] ?? 0) === 1;
                $companyKey = (string) ($companyRow['company_key'] ?? '');
                $companyLabel = (string) ($companyRow['company_label'] ?? $companyKey);
                $departments = is_array($companyRow['departments'] ?? null) ? $companyRow['departments'] : [];
                $hasDepartments = count($departments) > 0;
                $scopeTitle = $hasDepartments ? 'Department-level routing' : 'Company-level routing only';
                $scopeNote = $hasDepartments ? 'Expand to manage individual departments' : 'Company-level routing only';
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
                            <div class="route-scope-title"><?= htmlspecialchars($scopeTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="route-scope-note"><?= htmlspecialchars($scopeNote, ENT_QUOTES, 'UTF-8'); ?></div>
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

                        <div class="route-menu-dot"><i class="fa-solid fa-ellipsis-vertical"></i></div>
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
                                        <div class="route-scope-note">Department</div>
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

                                    <div class="route-menu-dot"><i class="fa-solid fa-ellipsis-vertical"></i></div>
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
</div>

<script>
const availabilityFeedback = document.getElementById('availabilityFeedback');
const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const searchInput = document.getElementById('routeSearchInput');
const companyFilter = document.getElementById('routeCompanyFilter');
const statusFilter = document.getElementById('routeStatusFilter');
const expandAllBtn = document.getElementById('expandAllBtn');
const collapseAllBtn = document.getElementById('collapseAllBtn');
const routeEmptyState = document.getElementById('routeEmptyState');
const activeRouteCount = document.getElementById('activeRouteCount');
const inactiveRouteCount = document.getElementById('inactiveRouteCount');

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

function applyRouteFilters() {
    const term = String((searchInput && searchInput.value) || '').trim().toLowerCase();
    const selectedCompany = String((companyFilter && companyFilter.value) || '').trim();
    const selectedStatus = String((statusFilter && statusFilter.value) || '').trim();
    let visibleGroups = 0;

    document.querySelectorAll('.route-group').forEach(function(group) {
        const companyKey = String(group.getAttribute('data-company-key') || '');
        const companyLabel = String(group.getAttribute('data-company-label') || '');
        const companyStatus = String(group.getAttribute('data-company-status') || '');
        const departmentRows = Array.from(group.querySelectorAll('.route-department-row'));
        let hasVisibleDepartment = false;

        departmentRows.forEach(function(row) {
            const departmentName = String(row.getAttribute('data-department-name') || '');
            const routeStatus = String(row.getAttribute('data-route-status') || '');
            const matchesTerm = term === '' || companyLabel.indexOf(term) !== -1 || departmentName.indexOf(term) !== -1 || companyKey.toLowerCase().indexOf(term) !== -1;
            const matchesStatus = selectedStatus === '' || routeStatus === selectedStatus;
            const visible = matchesTerm && matchesStatus;
            row.style.display = visible ? '' : 'none';
            if (visible) {
                hasVisibleDepartment = true;
            }
        });

        const companyMatchesSearch = term === '' || companyLabel.indexOf(term) !== -1 || companyKey.toLowerCase().indexOf(term) !== -1;
        const companyMatchesFilter = selectedCompany === '' || companyKey === selectedCompany;
        const companyMatchesStatus = selectedStatus === '' || companyStatus === selectedStatus;
        const hasDepartments = String(group.getAttribute('data-has-departments') || '') === '1';
        const visible = companyMatchesFilter && ((hasDepartments && hasVisibleDepartment) || (!hasDepartments && companyMatchesSearch && companyMatchesStatus) || (hasDepartments && companyMatchesSearch && (selectedStatus === '' || companyMatchesStatus)));

        group.style.display = visible ? '' : 'none';

        if (visible) {
            visibleGroups++;
            if (hasDepartments && (term !== '' || selectedStatus !== '')) {
                toggleCompanyExpansion(companyKey, hasVisibleDepartment);
            }
        }
    });

    if (routeEmptyState) {
        routeEmptyState.classList.toggle('is-visible', visibleGroups === 0);
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

document.querySelectorAll('[data-expand-company]').forEach(function(trigger) {
    trigger.addEventListener('click', function() {
        const companyKey = String(trigger.getAttribute('data-expand-company') || '');
        if (companyKey !== '') {
            toggleCompanyExpansion(companyKey);
        }
    });
});

if (expandAllBtn) {
    expandAllBtn.addEventListener('click', function() {
        document.querySelectorAll('[data-expand-company]').forEach(function(trigger) {
            const companyKey = String(trigger.getAttribute('data-expand-company') || '');
            if (companyKey !== '') {
                toggleCompanyExpansion(companyKey, true);
            }
        });
    });
}

if (collapseAllBtn) {
    collapseAllBtn.addEventListener('click', function() {
        document.querySelectorAll('[data-expand-company]').forEach(function(trigger) {
            const companyKey = String(trigger.getAttribute('data-expand-company') || '');
            if (companyKey !== '') {
                toggleCompanyExpansion(companyKey, false);
            }
        });
    });
}

[searchInput, companyFilter, statusFilter].forEach(function(control) {
    if (!control) return;
    control.addEventListener('input', applyRouteFilters);
    control.addEventListener('change', applyRouteFilters);
});

document.querySelectorAll('.availability-toggle').forEach(function(button) {
    button.addEventListener('click', function() {
        const company = String(button.getAttribute('data-company') || '').trim();
        const department = String(button.getAttribute('data-department') || '').trim();
        const itemType = String(button.getAttribute('data-item-type') || '').trim();
        const nextEnabled = String(button.getAttribute('data-next-enabled') || '0') === '1' ? '1' : '0';

        button.disabled = true;
        setAvailabilityFeedback('', 'success');

        fetch('ajax_ticket_receiving_availability.php', {
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
        })
        .then(function(response) {
            return response.json().then(function(payload) {
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.error || 'Unable to update availability.');
                }
                return payload;
            });
        })
        .then(function(payload) {
            const enabled = nextEnabled === '1';
            updateRowVisualState(button, enabled);
            recalcRouteCounts();
            applyRouteFilters();
            setAvailabilityFeedback(payload.message || 'Availability updated successfully.', 'success');
            button.disabled = false;
        })
        .catch(function(error) {
            button.disabled = false;
            setAvailabilityFeedback(error.message || 'Unable to update availability.', 'error');
        });
    });
});

recalcRouteCounts();
applyRouteFilters();
</script>
</body>
</html>
