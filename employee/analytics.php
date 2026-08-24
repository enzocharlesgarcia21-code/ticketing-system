<?php
define('TICKETING_ANALYTICS_VIEW_MODE', 'employee');

$isAnalyticsDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year);
};

if (!$isAnalyticsDate((string) ($_GET['start_date'] ?? ''))) {
    $_GET['start_date'] = date('Y-m-01');
}
if (!$isAnalyticsDate((string) ($_GET['end_date'] ?? ''))) {
    $_GET['end_date'] = date('Y-m-d');
}

ob_start();
require_once '../admin/analytics.php';
$analyticsHtml = ob_get_clean();

$employeeAnalyticsAdminParity = <<<'HTML'
<style id="employeeAnalyticsAdminParity">
body.employee-analytics-page .admin-content,
body.employee-analytics-page .admin-content *:not(i):not(.fa):not(.fa-solid):not(.fa-regular):not(.fa-brands) {
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
}

body.employee-analytics-page .admin-container {
    flex: 1;
    display: flex;
    justify-content: center;
    width: auto;
    max-width: none;
    margin: 0;
    padding: 30px;
}

body.employee-analytics-page .admin-content {
    width: 100%;
    max-width: 1460px;
    padding-top: 10px;
}

body.employee-analytics-page .admin-page-header {
    align-items: flex-start;
}

body.employee-analytics-page .analytics-heading {
    max-width: 860px;
}

body.employee-analytics-page .admin-page .admin-content .admin-page-header .analytics-heading .admin-page-title.analytics-title {
    color: #1B5E20 !important;
    font-family: 'Segoe UI', sans-serif !important;
    font-size: 28px !important;
    font-weight: 700 !important;
    line-height: 1.2 !important;
    letter-spacing: 0 !important;
}

body.employee-analytics-page .analytics-subtitle {
    color: #6B7280;
    font-size: 16px;
    font-weight: 400;
    line-height: 1.45;
}

body.employee-analytics-page .analytics-header-actions {
    margin-top: 24px;
}

body.employee-analytics-page .analytics-filters {
    grid-template-columns: minmax(330px, 1.35fr) minmax(220px, 1fr) minmax(220px, 1fr) !important;
}

body.employee-analytics-page .analytics-toolbar,
body.employee-analytics-page .chart-card,
body.employee-analytics-page .table-card {
    box-shadow: 0 20px 48px rgba(148, 163, 184, 0.16);
}

body.employee-analytics-page .analytics-card {
    box-shadow:
        0 14px 30px rgba(148, 163, 184, 0.14),
        inset 0 1px 0 rgba(255, 255, 255, 0.85);
}

body.employee-analytics-page:not(.sales-manager-analytics-page) .trend-overview-card {
    flex-direction: row;
    align-items: center;
}

body.employee-analytics-page:not(.sales-manager-analytics-page) .trend-delta-badge {
    width: auto;
    justify-content: normal;
}

body.employee-analytics-page .assignee-card .assignee-list {
    flex: 0 0 auto;
    justify-content: flex-start;
    gap: 14px;
    margin-bottom: 14px;
}

body.employee-analytics-page .assignee-card .assignee-total-pill {
    margin-top: 0;
}

body.employee-analytics-page .category-card .chart-header {
    gap: 10px;
}

body.employee-analytics-page .category-card .chart-heading {
    flex: 1 1 auto;
    min-width: 110px;
}

body.employee-analytics-page .category-card .company-chart-toggle {
    gap: 3px;
    padding: 3px;
    border-radius: 10px;
}

body.employee-analytics-page .category-card .company-chart-toggle-btn {
    min-height: 28px;
    padding: 0 8px;
    border-radius: 7px;
    font-size: 11px;
    line-height: 1.1;
    max-width: 102px;
    overflow: hidden;
    text-overflow: ellipsis;
}

body.employee-analytics-page .category-card .company-chart-toggle.is-dropdown-mode {
    padding: 0;
    border: 0;
    background: transparent;
    max-width: 178px;
    position: relative;
}

body.employee-analytics-page .category-card .company-chart-toggle.is-dropdown-mode .company-chart-toggle-btn {
    display: none;
}

body.employee-analytics-page .employee-marketing-chart-select {
    width: 178px;
    min-height: 36px;
    padding: 0 34px 0 12px;
    border: 1px solid #dbe3ef;
    border-radius: 10px;
    background: #ffffff;
    color: #1f2937;
    font-size: 12px;
    font-weight: 800;
    outline: none;
}

body.employee-analytics-page .employee-marketing-chart-select:focus {
    border-color: #1B5E20;
    box-shadow: 0 0 0 3px rgba(27, 94, 32, 0.12);
}

body.employee-analytics-page .employee-marketing-chart-dropdown {
    position: relative;
    width: 178px;
}

body.employee-analytics-page .employee-marketing-chart-trigger {
    width: 100%;
    min-height: 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 0 10px 0 12px;
    border: 1px solid #73a66f;
    border-radius: 9px;
    background: #ffffff;
    color: #243043;
    font-size: 12px;
    font-weight: 800;
    line-height: 1.15;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

body.employee-analytics-page .employee-marketing-chart-trigger:hover,
body.employee-analytics-page .employee-marketing-chart-trigger:focus-visible,
body.employee-analytics-page .employee-marketing-chart-dropdown.is-open .employee-marketing-chart-trigger {
    outline: none;
    border-color: #1B5E20;
    box-shadow: 0 0 0 3px rgba(27, 94, 32, 0.12);
}

body.employee-analytics-page .employee-marketing-chart-label {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

body.employee-analytics-page .employee-marketing-chart-caret {
    flex: 0 0 auto;
    color: #334155;
    font-size: 10px;
}

body.employee-analytics-page .employee-marketing-chart-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    z-index: 90;
    display: none;
    padding: 5px;
    border: 1px solid #d6e2d4;
    border-radius: 10px;
    background: #ffffff;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.14);
}

body.employee-analytics-page .employee-marketing-chart-dropdown.is-open .employee-marketing-chart-menu {
    display: block;
}

body.employee-analytics-page .employee-marketing-chart-option {
    width: 100%;
    min-height: 30px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: #243043;
    padding: 0 9px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

body.employee-analytics-page .employee-marketing-chart-option:hover,
body.employee-analytics-page .employee-marketing-chart-option:focus-visible {
    outline: none;
    background: #eef7ef;
}

body.employee-analytics-page .employee-marketing-chart-option.is-selected {
    background: #e8f5e9;
    color: #14532d;
}

body.employee-analytics-page .table-card {
    padding: 18px 24px 20px;
    overflow: hidden;
}

body.employee-analytics-page .table-responsive {
    margin: 0;
}

body.employee-analytics-page .analytics-task-table {
    width: 100%;
    margin: 0;
    border-collapse: collapse;
}

body.employee-analytics-page .analytics-task-table th,
body.employee-analytics-page .analytics-task-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #edf2f7;
    text-align: left;
    vertical-align: middle;
    color: #0f2342;
    font-size: 15px;
}

body.employee-analytics-page .analytics-task-table th {
    background: #f8fafc;
    color: #00531a;
    text-transform: uppercase;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 1px;
    border-bottom: 2px solid #0f6b2f;
}

body.employee-analytics-page .analytics-task-table th:first-child {
    border-top-left-radius: 8px;
}

body.employee-analytics-page .analytics-task-table th:last-child {
    border-top-right-radius: 8px;
}

body.employee-analytics-page .analytics-task-table tbody tr:hover td {
    background: #f8fbfd;
}

body.employee-analytics-page .subject-cell strong,
body.employee-analytics-page .user-info strong {
    color: #10213d;
    font-weight: 800;
}

body.employee-analytics-page .user-info small {
    color: #0f2342;
    font-size: 13px;
}

body.employee-analytics-page .priority-pill,
body.employee-analytics-page .status-pill,
body.employee-analytics-page .task-ticket-sla .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 48px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 14px;
    font-weight: 500;
    white-space: nowrap;
    border: 1px solid transparent;
}

body.employee-analytics-page .priority-low {
    background: #f0fdf4;
    color: #008236;
}

body.employee-analytics-page .priority-medium {
    background: #fffbe6;
    color: #d28a00;
}

body.employee-analytics-page .priority-high,
body.employee-analytics-page .priority-critical {
    background: #fff1f2;
    color: #e11d48;
}

body.employee-analytics-page .status-open {
    background: #fff2b3;
    border-color: #f8e58c;
    color: #5f5400;
}

body.employee-analytics-page .status-in-progress {
    background: #dcfce7;
    border-color: #bbf7d0;
    color: #166534;
}

body.employee-analytics-page .status-resolved {
    background: #dbeafe;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

body.employee-analytics-page .status-closed {
    background: #e5e7eb;
    border-color: #d1d5db;
    color: #374151;
}

body.employee-analytics-page .task-ticket-sla .badge-on-track {
    background: #f1f5f9;
    color: #0f2342;
    border: 1px solid #dbe4ee;
}

body.employee-analytics-page .task-ticket-sla .badge-at-risk {
    background: #fff7ed;
    color: #ea580c;
    border: 1px solid #fed7aa;
}

body.employee-analytics-page .task-ticket-sla .badge-breach,
body.employee-analytics-page .task-ticket-sla .badge-critical {
    background: #fef2f2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

body.employee-analytics-page .task-ticket-arrow {
    width: 34px;
    text-align: center;
    color: #0f2342;
    font-weight: 800;
    font-size: 18px;
}

@media (max-width: 900px) {
    body.employee-analytics-page .admin-container {
        padding: 30px;
    }
}

@media (max-width: 768px) {
    body.employee-analytics-page .admin-container {
        padding: 20px;
    }

    body.employee-analytics-page .category-card .company-chart-toggle {
        max-width: none;
        width: 100%;
    }

    body.employee-analytics-page .employee-marketing-chart-select,
    body.employee-analytics-page .employee-marketing-chart-dropdown {
        width: 100%;
    }

    body.employee-analytics-page .category-card .company-chart-toggle-btn {
        max-width: none;
        font-size: 11px;
    }

    body.employee-analytics-page .table-card {
        padding: 14px;
    }

    body.employee-analytics-page .analytics-task-table th,
    body.employee-analytics-page .analytics-task-table td {
        padding: 13px 12px;
        font-size: 13px;
    }
}

/* Employee page background-only consistency override. */
body.employee-analytics-page {
    background-image:
        radial-gradient(circle at 6% 24%, rgba(225, 244, 229, 0.82) 0 78px, transparent 79px),
        radial-gradient(circle at -4% 83%, rgba(215, 239, 221, 0.78) 0 190px, transparent 191px),
        radial-gradient(circle at 98% 26%, rgba(219, 241, 224, 0.76) 0 205px, transparent 206px),
        radial-gradient(circle at 94% 76%, rgba(233, 247, 236, 0.82) 0 90px, transparent 91px),
        radial-gradient(ellipse at 58% 108%, rgba(223, 243, 228, 0.74) 0 260px, transparent 261px),
        radial-gradient(circle at 4% 77%, transparent 0 165px, rgba(205, 230, 213, 0.24) 166px 168px, transparent 169px),
        radial-gradient(circle at 4% 78%, transparent 0 210px, rgba(205, 230, 213, 0.16) 211px 213px, transparent 214px),
        radial-gradient(circle at 92% 19%, transparent 0 145px, rgba(202, 229, 211, 0.23) 146px 148px, transparent 149px),
        radial-gradient(circle at 94% 20%, transparent 0 198px, rgba(202, 229, 211, 0.16) 199px 201px, transparent 202px),
        linear-gradient(145deg, rgba(239, 249, 242, 0.78) 0%, rgba(255, 255, 255, 0.98) 20%, rgba(255, 255, 255, 0.99) 60%, rgba(239, 249, 242, 0.84) 100%) !important;
    background-repeat: no-repeat !important;
    background-attachment: fixed !important;
}

body.employee-analytics-page::before,
body.employee-analytics-page::after {
    content: "" !important;
    position: fixed !important;
    pointer-events: none !important;
    z-index: 0 !important;
    background-image: radial-gradient(circle, rgba(105, 163, 123, 0.2) 1.2px, transparent 1.55px);
}

body.employee-analytics-page::before {
    left: 2%;
    top: 132px;
    width: 104px;
    height: 88px;
    background-size: 16px 16px;
}

body.employee-analytics-page::after {
    right: 6%;
    top: 88%;
    width: 116px;
    height: 96px;
    background-size: 15px 15px;
}

body.employee-analytics-page .admin-container,
body.employee-analytics-page .admin-content {
    position: relative !important;
    z-index: 1 !important;
    background: transparent !important;
}

/* Mobile employee Analytics presentation. Navbar/chat remain shared. */
@media (max-width: 768px) {
    body.employee-analytics-page .admin-page {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        background: transparent !important;
    }

    body.employee-analytics-page .admin-container {
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 26px 12px 40px !important;
        box-sizing: border-box !important;
        background:
            linear-gradient(180deg, rgba(255,255,255,.34) 0, rgba(255,255,255,.73) 250px, rgba(247,249,248,.96) 430px),
            url('../assets/img/dashboard_bg.jpg') center top / 100% 430px no-repeat !important;
    }

    body.employee-analytics-page .admin-content {
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    body.employee-analytics-page .admin-page-header {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: end !important;
        gap: 10px 12px !important;
        margin: 0 8px 18px !important;
    }

    body.employee-analytics-page .analytics-heading {
        grid-column: 1 / -1 !important;
        gap: 7px !important;
        max-width: 430px !important;
    }

    body.employee-analytics-page .admin-page .admin-content .admin-page-header .analytics-heading .admin-page-title.analytics-title {
        margin: 0 !important;
        color: #075d27 !important;
        font-size: 27px !important;
        font-weight: 800 !important;
        line-height: 1.1 !important;
    }

    body.employee-analytics-page .analytics-subtitle {
        max-width: 410px !important;
        color: #334155 !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        line-height: 1.55 !important;
    }

    body.employee-analytics-page .analytics-header-actions {
        grid-column: 2 !important;
        justify-self: end !important;
        width: auto !important;
        margin: -2px 0 0 !important;
        gap: 8px !important;
        flex-wrap: nowrap !important;
    }

    body.employee-analytics-page .btn-export {
        min-width: 78px !important;
        min-height: 38px !important;
        height: 38px !important;
        padding: 0 12px !important;
        border: 1px solid #159447 !important;
        border-radius: 9px !important;
        background: rgba(255,255,255,.95) !important;
        color: #147a37 !important;
        font-size: 12px !important;
        font-weight: 750 !important;
        box-shadow: 0 5px 14px rgba(15,92,39,.08) !important;
    }

    body.employee-analytics-page .analytics-toolbar {
        margin: 0 0 16px !important;
        padding: 16px !important;
        border: 1px solid rgba(218,229,221,.95) !important;
        border-radius: 16px !important;
        background: rgba(255,255,255,.96) !important;
        box-shadow: 0 10px 24px rgba(15,23,42,.07) !important;
    }

    body.employee-analytics-page:not(.sales-manager-analytics-page) .analytics-filters {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 12px 14px !important;
        align-items: end !important;
    }

    body.employee-analytics-page .analytics-filter:first-child {
        grid-column: 1 / -1 !important;
    }

    body.employee-analytics-page .analytics-filter label {
        margin-bottom: 7px !important;
        color: #172033 !important;
        font-size: 11px !important;
        font-weight: 750 !important;
    }

    body.employee-analytics-page .date-inputs {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr) !important;
        align-items: center !important;
        gap: 9px !important;
    }

    body.employee-analytics-page .date-separator {
        display: inline-flex !important;
        color: #334155 !important;
        font-size: 12px !important;
        font-weight: 750 !important;
    }

    body.employee-analytics-page .analytics-control,
    body.employee-analytics-page .analytics-select-trigger {
        width: 100% !important;
        min-width: 0 !important;
        min-height: 42px !important;
        height: 42px !important;
        padding: 0 12px !important;
        border: 1px solid #d7dee7 !important;
        border-radius: 9px !important;
        background-color: #fff !important;
        color: #172033 !important;
        font-size: 12px !important;
        box-shadow: none !important;
        box-sizing: border-box !important;
    }

    body.employee-analytics-page .analytics-status-row {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        gap: 8px !important;
        align-items: center !important;
    }

    body.employee-analytics-page .analytics-inline-clear {
        min-width: 72px !important;
        min-height: 42px !important;
        padding: 0 10px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border: 1px solid #64bd7e !important;
        border-radius: 9px !important;
        background: #fff !important;
        color: #147a37 !important;
        font-size: 11px !important;
        font-weight: 750 !important;
        text-decoration: none !important;
        white-space: nowrap !important;
        box-sizing: border-box !important;
    }

    body.employee-analytics-page .analytics-metrics {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)) !important;
        gap: 10px !important;
        margin: 0 0 16px !important;
    }

    body.employee-analytics-page .analytics-card {
        min-height: 126px !important;
        padding: 13px 12px 12px !important;
        border-radius: 13px !important;
        box-shadow: 0 8px 19px rgba(15,23,42,.065) !important;
    }

    body.employee-analytics-page .analytics-card::before {
        width: 3px !important;
    }

    body.employee-analytics-page .analytics-label {
        margin: 0 0 12px !important;
        font-size: 10px !important;
        font-weight: 750 !important;
    }

    body.employee-analytics-page .analytics-value {
        font-size: 27px !important;
        font-weight: 800 !important;
    }

    body.employee-analytics-page .analytics-sub {
        margin-top: 11px !important;
        font-size: 9.5px !important;
        font-weight: 550 !important;
        line-height: 1.35 !important;
    }

    body.employee-analytics-page .analytics-icon {
        width: 34px !important;
        height: 34px !important;
        border-radius: 10px !important;
        font-size: 13px !important;
    }

    body.employee-analytics-page .analytics-charts {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(235px, 1fr)) !important;
        gap: 9px !important;
        margin: 0 0 16px !important;
        align-items: stretch !important;
    }

    body.employee-analytics-page .chart-card {
        min-width: 0 !important;
        min-height: 390px !important;
        height: 100% !important;
        padding: 12px 10px !important;
        border-radius: 13px !important;
        box-shadow: 0 8px 19px rgba(15,23,42,.065) !important;
        overflow: hidden !important;
    }

    body.employee-analytics-page .chart-header,
    body.employee-analytics-page .category-card .chart-header,
    body.employee-analytics-page .trend-card .chart-header {
        display: block !important;
        min-height: 54px !important;
        margin-bottom: 8px !important;
    }

    body.employee-analytics-page .chart-title {
        margin-bottom: 4px !important;
        font-size: 10px !important;
        font-weight: 800 !important;
        line-height: 1.25 !important;
    }

    body.employee-analytics-page .chart-subtitle {
        font-size: 8.5px !important;
        font-weight: 550 !important;
        line-height: 1.35 !important;
    }

    body.employee-analytics-page .chart-card.category-card .chart-container,
    body.employee-analytics-page .chart-card.trend-card .chart-container {
        width: 100% !important;
        height: 185px !important;
        min-height: 185px !important;
    }

    body.employee-analytics-page .category-legend-grid {
        grid-template-columns: 1fr !important;
        gap: 5px !important;
        margin-top: 8px !important;
    }

    body.employee-analytics-page .category-legend-item,
    body.employee-analytics-page .category-legend-text {
        min-width: 0 !important;
        font-size: 8px !important;
    }

    body.employee-analytics-page .category-legend-name {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    body.employee-analytics-page .trend-period-actions {
        display: flex !important;
        gap: 3px !important;
        margin-top: 6px !important;
    }

    body.employee-analytics-page .trend-period-pill {
        width: auto !important;
        min-height: 24px !important;
        padding: 0 7px !important;
        border-radius: 12px !important;
        font-size: 7.5px !important;
    }

    body.employee-analytics-page:not(.sales-manager-analytics-page) .trend-overview-card {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 6px !important;
        width: 100% !important;
        padding: 7px !important;
        border-radius: 9px !important;
    }

    body.employee-analytics-page .trend-overview-icon {
        width: 28px !important;
        height: 28px !important;
        flex-basis: 28px !important;
        font-size: 11px !important;
    }

    body.employee-analytics-page .trend-overview-value {
        font-size: 14px !important;
    }

    body.employee-analytics-page .trend-overview-label,
    body.employee-analytics-page .trend-delta-value,
    body.employee-analytics-page .trend-delta-label {
        font-size: 7.5px !important;
        line-height: 1.25 !important;
    }

    body.employee-analytics-page .trend-delta-badge {
        width: 100% !important;
        min-height: 32px !important;
        padding: 5px 7px !important;
        box-sizing: border-box !important;
    }

    body.employee-analytics-page .insight-pill {
        display: none !important;
    }

    body.employee-analytics-page .assignee-card .chart-header {
        min-height: 54px !important;
    }

    body.employee-analytics-page .assignee-card .assignee-list {
        gap: 10px !important;
        margin: 4px 0 10px !important;
    }

    body.employee-analytics-page .assignee-item {
        gap: 6px !important;
    }

    body.employee-analytics-page .assignee-avatar {
        width: 29px !important;
        height: 29px !important;
        flex-basis: 29px !important;
        font-size: 8px !important;
    }

    body.employee-analytics-page .assignee-name,
    body.employee-analytics-page .assignee-count {
        font-size: 8px !important;
    }

    body.employee-analytics-page .assignee-total-pill {
        min-height: 35px !important;
        padding: 7px 9px !important;
        border-radius: 18px !important;
        font-size: 8px !important;
    }

    body.employee-analytics-page .table-card {
        position: relative !important;
        margin: 0 !important;
        padding: 42px 12px 14px !important;
        border-radius: 14px !important;
        box-shadow: 0 8px 19px rgba(15,23,42,.065) !important;
        overflow: hidden !important;
    }

    body.employee-analytics-page .table-card::before {
        content: 'Ticket Overview';
        position: absolute;
        top: 15px;
        left: 14px;
        color: #172033;
        font-size: 12px;
        font-weight: 800;
    }

    body.employee-analytics-page .table-responsive {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        -webkit-overflow-scrolling: touch !important;
    }

    body.employee-analytics-page .analytics-task-table {
        display: table !important;
        width: 760px !important;
        min-width: 760px !important;
        max-width: none !important;
        table-layout: auto !important;
        border-collapse: collapse !important;
    }

    body.employee-analytics-page .analytics-task-table thead {
        display: table-header-group !important;
    }

    body.employee-analytics-page .analytics-task-table tbody {
        display: table-row-group !important;
    }

    body.employee-analytics-page .analytics-task-table thead tr,
    body.employee-analytics-page .analytics-task-table tbody tr {
        display: table-row !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }

    body.employee-analytics-page .analytics-task-table th,
    body.employee-analytics-page .analytics-task-table td {
        display: table-cell !important;
        padding: 10px 9px !important;
        font-size: 9px !important;
        text-align: left !important;
        vertical-align: middle !important;
        white-space: nowrap !important;
    }

    body.employee-analytics-page .analytics-task-table td::before {
        display: none !important;
        content: none !important;
    }

    body.employee-analytics-page .analytics-task-table th {
        font-size: 8px !important;
        letter-spacing: .45px !important;
    }

    body.employee-analytics-page .pagination-row {
        display: grid !important;
        grid-template-columns: auto 1fr auto !important;
        gap: 8px !important;
        align-items: center !important;
        justify-items: stretch !important;
        margin-top: 12px !important;
    }

    body.employee-analytics-page .pagination-row > .pagination-info:first-child {
        display: none !important;
    }

    body.employee-analytics-page .entries-row {
        justify-self: start !important;
        gap: 5px !important;
        font-size: 8.5px !important;
    }

    body.employee-analytics-page .entries-select {
        width: 58px !important;
        min-width: 58px !important;
        height: 34px !important;
        padding: 0 8px !important;
        font-size: 9px !important;
    }

    body.employee-analytics-page .pagination-info {
        justify-self: end !important;
        font-size: 8.5px !important;
        text-align: right !important;
    }

    body.employee-analytics-page .pagination-controls {
        grid-column: 1 / -1 !important;
        justify-self: end !important;
    }
}

@media (max-width: 520px) {
    body.employee-analytics-page .admin-container {
        padding-left: 9px !important;
        padding-right: 9px !important;
    }

    body.employee-analytics-page .admin-page-header {
        margin-left: 4px !important;
        margin-right: 4px !important;
    }

    body.employee-analytics-page .analytics-metrics {
        grid-template-columns: repeat(auto-fit, minmax(125px, 1fr)) !important;
    }

    body.employee-analytics-page .analytics-charts {
        grid-template-columns: minmax(0, 1fr) !important;
    }

    body.employee-analytics-page .chart-card {
        min-height: 360px !important;
        padding: 14px 13px !important;
    }

    body.employee-analytics-page .chart-title {
        font-size: 12px !important;
    }

    body.employee-analytics-page .chart-subtitle {
        font-size: 10px !important;
    }

    body.employee-analytics-page .chart-card.category-card .chart-container,
    body.employee-analytics-page .chart-card.trend-card .chart-container {
        height: 220px !important;
        min-height: 220px !important;
    }
}

@media (max-width: 400px) {
    body.employee-analytics-page:not(.sales-manager-analytics-page) .analytics-filters {
        grid-template-columns: minmax(0, 1fr) !important;
    }

    body.employee-analytics-page .analytics-filter:first-child {
        grid-column: 1 !important;
    }

    body.employee-analytics-page .analytics-header-actions {
        grid-column: 1 / -1 !important;
    }

    body.employee-analytics-page .analytics-card {
        min-height: 116px !important;
    }
}

/* Keep mobile analytics body copy at one readable size. Page headings and KPI
   numbers retain their visual hierarchy; all labels, controls, charts and table
   content use the shared employee-page 14px baseline. This block intentionally comes last so
   narrower breakpoints cannot shrink individual elements again. */
@media (max-width: 768px) {
    body.employee-analytics-page .analytics-subtitle,
    body.employee-analytics-page .btn-export,
    body.employee-analytics-page .analytics-toolbar *,
    body.employee-analytics-page .analytics-label,
    body.employee-analytics-page .analytics-sub,
    body.employee-analytics-page .chart-card *,
    body.employee-analytics-page .table-card * {
        font-size: 14px !important;
    }

    body.employee-analytics-page .table-card::before {
        font-size: 14px !important;
    }
}
</style>
HTML;

$employeeMarketingChartDropdown = <<<'HTML'
<script id="employeeMarketingChartDropdown">
document.addEventListener('DOMContentLoaded', function () {
    var marketingButton = document.querySelector('.company-chart-toggle-btn[data-company-view="marketing_operations"]');
    var channelButton = document.querySelector('.company-chart-toggle-btn[data-company-view="channel_campaigns"]');
    if (!marketingButton || !channelButton) return;

    var toggle = marketingButton.closest('.company-chart-toggle');
    if (!toggle || toggle.querySelector('.employee-marketing-chart-dropdown')) return;

    toggle.classList.add('is-dropdown-mode');

    var options = [
        { value: 'marketing_operations', label: 'Marketing Operations' },
        { value: 'channel_campaigns', label: 'Channel & Campaigns' }
    });

    var dropdown = document.createElement('div');
    dropdown.className = 'employee-marketing-chart-dropdown';

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'employee-marketing-chart-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');

    var label = document.createElement('span');
    label.className = 'employee-marketing-chart-label';

    var caret = document.createElement('span');
    caret.className = 'employee-marketing-chart-caret';
    caret.setAttribute('aria-hidden', 'true');
    caret.textContent = '▾';

    trigger.appendChild(label);
    trigger.appendChild(caret);

    var menu = document.createElement('div');
    menu.className = 'employee-marketing-chart-menu';
    menu.setAttribute('role', 'listbox');

    function getActiveValue() {
        return channelButton.classList.contains('active') ? 'channel_campaigns' : 'marketing_operations';
    }

    function setOpen(isOpen) {
        dropdown.classList.toggle('is-open', isOpen);
        trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    function syncSelected() {
        var activeValue = getActiveValue();
        var activeOption = options.find(function(optionConfig) {
            return optionConfig.value === activeValue;
        }) || options[0];
        label.textContent = activeOption.label;
        Array.from(menu.querySelectorAll('.employee-marketing-chart-option')).forEach(function(optionButton) {
            var isSelected = optionButton.getAttribute('data-value') === activeValue;
            optionButton.classList.toggle('is-selected', isSelected);
            optionButton.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }

    options.forEach(function(optionConfig) {
        var optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = 'employee-marketing-chart-option';
        optionButton.setAttribute('data-value', optionConfig.value);
        optionButton.setAttribute('role', 'option');
        optionButton.textContent = optionConfig.label;
        optionButton.addEventListener('click', function() {
            var target = optionConfig.value === 'channel_campaigns' ? channelButton : marketingButton;
            target.click();
            setOpen(false);
            syncSelected();
        });
        menu.appendChild(optionButton);
    });

    trigger.addEventListener('click', function () {
        setOpen(!dropdown.classList.contains('is-open'));
    });

    document.addEventListener('click', function(event) {
        if (dropdown.contains(event.target)) return;
        setOpen(false);
    });

    dropdown.appendChild(trigger);
    dropdown.appendChild(menu);
    toggle.appendChild(dropdown);
    syncSelected();

    [marketingButton, channelButton].forEach(function(button) {
        button.addEventListener('click', function() {
            window.setTimeout(syncSelected, 0);
        });
    });
});
</script>
HTML;

$analyticsHtml = str_replace(
    "const analyticsChartTextWeight = isEmployeeAnalyticsView ? '400' : '800';",
    "const analyticsChartTextWeight = '800';",
    $analyticsHtml
);
$analyticsHtml = str_replace(
    "const analyticsChartAxisWeight = isEmployeeAnalyticsView ? '400' : '600';",
    "const analyticsChartAxisWeight = '600';",
    $analyticsHtml
);
$analyticsHtml = str_replace(
    [
        "size: pct <= 2 ? 7 : (pct <= 4 ? 8 : 10)",
        "size: isMonthTrendView() ? 10 : 11",
        "size: isMonthTrendView() ? 12 : 13",
        "size: 13,\n                            weight: analyticsChartAxisWeight",
    ],
    [
        "size: 14",
        "size: 14",
        "size: 14",
        "size: 14,\n                            weight: analyticsChartAxisWeight",
    ],
    $analyticsHtml
);
$analyticsHtml = str_replace(
    "top: isEmployeeAnalyticsView ? 34 : 18,",
    "top: 18,",
    $analyticsHtml
);
$analyticsHtml = preg_replace(
    '/\s*body\.employee-analytics-page(?::not\(\.sales-manager-analytics-page\))? \.admin-content,\s*body\.employee-analytics-page(?::not\(\.sales-manager-analytics-page\))? \.admin-content \*:not\(i\):not\(\.fa\):not\(\.fa-solid\):not\(\.fa-regular\):not\(\.fa-brands\)\s*\{\s*font-weight:\s*400\s*!important;\s*\}/',
    '',
    $analyticsHtml,
    1
);

if (stripos($analyticsHtml, '</head>') !== false) {
    $analyticsHtml = preg_replace('/<\/head>/i', $employeeAnalyticsAdminParity . "\n</head>", $analyticsHtml, 1);
} else {
    $analyticsHtml = $employeeAnalyticsAdminParity . "\n" . $analyticsHtml;
}

$employeeAnalyticsBodyExtras = $employeeMarketingChartDropdown;

if (stripos($analyticsHtml, '</body>') !== false) {
    $analyticsHtml = preg_replace('/<\/body>/i', $employeeAnalyticsBodyExtras . "\n</body>", $analyticsHtml, 1);
} else {
    $analyticsHtml .= "\n" . $employeeAnalyticsBodyExtras;
}

echo $analyticsHtml;
