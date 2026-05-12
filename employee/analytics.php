<?php
define('TICKETING_ANALYTICS_VIEW_MODE', 'employee');

ob_start();
require_once '../admin/analytics.php';
$analyticsHtml = ob_get_clean();

$employeeAnalyticsTypography = <<<'HTML'
<style id="employeeAnalyticsTypographySync">
/* Typography synced with admin analytics.php */
body.employee-analytics-page,
body.employee-analytics-page .admin-content {
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    color: #1F2937;
}

body.employee-analytics-page .analytics-title {
    font-size: 2.05rem;
    font-weight: 800 !important;
    line-height: normal;
    letter-spacing: -0.03em;
    color: #111827;
}

body.employee-analytics-page .analytics-title i {
    font-size: 1.7rem;
    color: #1B5E20;
}

body.employee-analytics-page .analytics-filter label {
    font-size: 12px;
    font-weight: 800 !important;
    line-height: normal;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #374151;
}

body.employee-analytics-page .analytics-filter-note {
    font-size: 12px;
    font-weight: 700 !important;
    line-height: 1.35;
    color: #64748b;
}

body.employee-analytics-page .analytics-control {
    font-size: 14px;
    font-weight: 400 !important;
    line-height: normal;
    color: #111827;
}

body.employee-analytics-page .date-separator {
    font-size: 13px;
    font-weight: 700 !important;
    line-height: normal;
    color: #6b7280;
}

body.employee-analytics-page .assignee-trigger {
    font-size: 16px;
    font-weight: 500 !important;
    line-height: normal;
    color: #0f172a;
}

body.employee-analytics-page .assignee-option {
    font-size: 15px;
    font-weight: 400 !important;
    line-height: normal;
    color: #374151;
}

body.employee-analytics-page .analytics-inline-clear {
    font-size: 14px;
    font-weight: 500 !important;
    line-height: normal;
    color: #111827;
}

body.employee-analytics-page .btn-apply,
body.employee-analytics-page .btn-export {
    font-size: 14px;
    font-weight: 800 !important;
    line-height: normal;
}

body.employee-analytics-page .btn-export {
    color: #1B5E20;
}

body.employee-analytics-page .analytics-label {
    font-size: 0.88rem;
    font-weight: 500 !important;
    line-height: 1.15;
    letter-spacing: 0;
    text-transform: none;
    color: var(--metric-label, #111827);
}

body.employee-analytics-page .analytics-value {
    font-size: 2.55rem;
    font-weight: 700 !important;
    line-height: 1;
    letter-spacing: -0.04em;
    color: #111827;
}

body.employee-analytics-page .analytics-sub {
    font-size: 0.88rem;
    font-weight: 500 !important;
    line-height: normal;
    color: #4b5563;
}

body.employee-analytics-page .chart-title {
    font-size: 1.05rem;
    font-weight: 800 !important;
    line-height: normal;
    color: #111827;
}

body.employee-analytics-page .chart-subtitle {
    font-size: 14px;
    font-weight: 600 !important;
    line-height: normal;
    color: #8a94a6;
}

body.employee-analytics-page .company-chart-toggle-btn {
    font-size: 13px;
    font-weight: 800 !important;
    line-height: normal;
    color: #556171;
}

body.employee-analytics-page .company-chart-toggle-btn.active {
    color: #ffffff;
}

body.employee-analytics-page .trend-period-pill {
    font-size: 13px;
    font-weight: 700 !important;
    line-height: normal;
    color: #475569;
}

body.employee-analytics-page .trend-period-pill i {
    color: #2f8cff;
}

body.employee-analytics-page .trend-overview-value {
    font-size: 2.55rem;
    font-weight: 800 !important;
    line-height: 1;
    letter-spacing: -0.05em;
    color: #0f172a;
}

body.employee-analytics-page .trend-overview-label {
    font-size: 0.98rem;
    font-weight: 700 !important;
    line-height: normal;
    color: #334155;
}

body.employee-analytics-page .trend-delta-badge {
    font-weight: 800 !important;
}

body.employee-analytics-page .trend-delta-badge.up {
    color: #f97316;
}

body.employee-analytics-page .trend-delta-badge.down {
    color: #16a34a;
}

body.employee-analytics-page .trend-delta-badge.flat {
    color: #64748b;
}

body.employee-analytics-page .trend-delta-copy {
    line-height: 1.15;
}

body.employee-analytics-page .trend-delta-value {
    font-size: 1.02rem;
    font-weight: 800 !important;
    line-height: normal;
}

body.employee-analytics-page .trend-delta-label {
    font-size: 0.82rem;
    font-weight: 700 !important;
    line-height: normal;
    color: #64748b;
}

body.employee-analytics-page .insight-pill {
    font-weight: 700 !important;
    line-height: normal;
    color: #1f2937;
}

body.employee-analytics-page .insight-pill i {
    font-size: 18px;
    color: #2f8cff;
}

body.employee-analytics-page .insight-pill-label {
    font-size: 15px;
    font-weight: 700 !important;
    line-height: normal;
    color: #334155;
}

body.employee-analytics-page .insight-pill-value {
    font-size: 17px;
    font-weight: 800 !important;
    line-height: normal;
    color: #2f8cff;
}

body.employee-analytics-page .category-legend-text {
    font-size: 14px;
    font-weight: 400 !important;
    line-height: normal;
    color: #1f2937;
}

body.employee-analytics-page .category-legend-name {
    font-weight: 700 !important;
    color: #1f2937;
}

body.employee-analytics-page .category-legend-percent {
    font-weight: 800 !important;
    color: #111827;
}

body.employee-analytics-page .assignee-avatar {
    font-size: 16px;
    font-weight: 800 !important;
    line-height: normal;
}

body.employee-analytics-page .assignee-name {
    font-size: 15px;
    font-weight: 800 !important;
    line-height: normal;
    color: #1f2937;
}

body.employee-analytics-page .assignee-count {
    font-size: 15px;
    font-weight: 800 !important;
    line-height: normal;
    color: #334155;
}

body.employee-analytics-page .assignee-empty {
    font-weight: 700 !important;
    line-height: normal;
    color: #8a94a6;
}

body.employee-analytics-page .assignee-total-pill {
    font-size: 15px;
    font-weight: 800 !important;
    line-height: normal;
    color: #0f5132;
}

body.employee-analytics-page .assignee-total-pill i {
    font-size: 14px;
    color: #22c55e;
}

body.employee-analytics-page .assignee-total-pill strong {
    font-size: 16px;
    font-weight: 800 !important;
    line-height: normal;
}

body.employee-analytics-page .tickets-table {
    font-size: 14px;
    font-weight: 400 !important;
    line-height: normal;
    color: #1f2937;
}

body.employee-analytics-page .tickets-table th {
    font-size: 12px;
    font-weight: 800 !important;
    line-height: normal;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #334155;
}

body.employee-analytics-page .tickets-table td {
    font-size: 14px;
    font-weight: 400 !important;
    line-height: normal;
    color: #1f2937;
}

body.employee-analytics-page .tickets-table strong,
body.employee-analytics-page .ticket-id {
    font-weight: 700 !important;
}

body.employee-analytics-page .badge,
body.employee-analytics-page .status-badge,
body.employee-analytics-page .priority-badge {
    font-size: 14px;
    font-weight: 500 !important;
    line-height: normal;
}

body.employee-analytics-page .pagination-ellipsis {
    font-size: 18px;
    font-weight: 800 !important;
    line-height: normal;
    letter-spacing: 0.08em;
    color: #64748b;
}

body.employee-analytics-page .page-btn {
    font-size: 14px;
    font-weight: 700 !important;
    line-height: normal;
    color: #334155;
}

body.employee-analytics-page .page-btn.active {
    color: #ffffff;
}

@media (max-width: 640px) {
    body.employee-analytics-page .analytics-title {
        font-size: 1.7rem;
    }

    body.employee-analytics-page .trend-overview-value {
        font-size: 2.4rem;
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

if (stripos($analyticsHtml, '</head>') !== false) {
    $analyticsHtml = preg_replace('/<\/head>/i', $employeeAnalyticsTypography . "\n</head>", $analyticsHtml, 1);
} else {
    $analyticsHtml = $employeeAnalyticsTypography . "\n" . $analyticsHtml;
}

echo $analyticsHtml;
