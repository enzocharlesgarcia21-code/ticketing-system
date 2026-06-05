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

if (stripos($analyticsHtml, '</body>') !== false) {
    $analyticsHtml = preg_replace('/<\/body>/i', $employeeMarketingChartDropdown . "\n</body>", $analyticsHtml, 1);
} else {
    $analyticsHtml .= "\n" . $employeeMarketingChartDropdown;
}

echo $analyticsHtml;
