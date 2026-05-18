<?php
define('TICKETING_ANALYTICS_VIEW_MODE', 'employee');

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

@media (max-width: 900px) {
    body.employee-analytics-page .admin-container {
        padding: 30px;
    }
}

@media (max-width: 768px) {
    body.employee-analytics-page .admin-container {
        padding: 20px;
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
