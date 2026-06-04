<?php
define('TICKETING_ANALYTICS_VIEW_MODE', 'employee');
define('TICKETING_ANALYTICS_NO_DEFAULT_DATE', true);

ob_start();
require_once '../admin/analytics.php';
$analyticsHtml = ob_get_clean();

$employeeAnalyticsAdminParity = <<<'HTML'
<style id="employeeAnalyticsAdminParity">
body.employee-analytics-page,
body.employee-analytics-page *:not(i):not(.fa):not(.fa-solid):not(.fa-regular):not(.fa-brands) {
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

body.employee-analytics-page .trend-overview-card {
    flex-direction: row;
    align-items: center;
}

body.employee-analytics-page .trend-delta-badge {
    width: auto;
    justify-content: normal;
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

    body.employee-analytics-page .table-card {
        padding: 14px;
    }

    body.employee-analytics-page .analytics-task-table th,
    body.employee-analytics-page .analytics-task-table td {
        padding: 13px 12px;
        font-size: 13px;
    }
}
</style>
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
    "top: isEmployeeAnalyticsView ? 34 : 18,",
    "top: 18,",
    $analyticsHtml
);
$analyticsHtml = preg_replace(
    '/\s*body\.employee-analytics-page \.admin-content,\s*body\.employee-analytics-page \.admin-content \*:not\(i\):not\(\.fa\):not\(\.fa-solid\):not\(\.fa-regular\):not\(\.fa-brands\)\s*\{\s*font-weight:\s*400\s*!important;\s*\}/',
    '',
    $analyticsHtml,
    1
);

if (stripos($analyticsHtml, '</head>') !== false) {
    $analyticsHtml = preg_replace('/<\/head>/i', $employeeAnalyticsAdminParity . "\n</head>", $analyticsHtml, 1);
} else {
    $analyticsHtml = $employeeAnalyticsAdminParity . "\n" . $analyticsHtml;
}

echo $analyticsHtml;
