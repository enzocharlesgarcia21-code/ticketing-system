<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/user_permissions.php';

$analyticsExportViewMode = defined('TICKETING_ANALYTICS_EXPORT_VIEW_MODE') ? (string) TICKETING_ANALYTICS_EXPORT_VIEW_MODE : 'admin';
$analyticsExportIsSalesManagerView = $analyticsExportViewMode === 'sales_manager';
$analyticsExportIsEmployeeView = $analyticsExportViewMode === 'employee' || $analyticsExportIsSalesManagerView;
$analyticsExportSalesRegion = '';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    die('Access Denied');
}

if ($analyticsExportIsEmployeeView) {
    if ((string) ($_SESSION['role'] ?? '') !== 'employee') {
        die('Access Denied');
    }
    if ($analyticsExportIsSalesManagerView) {
        $exportUserId = (int) ($_SESSION['user_id'] ?? 0);
        $regionColumnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'region'");
        $hasRegionColumn = $regionColumnResult && $regionColumnResult->num_rows > 0;
        $exportUserStmt = $conn->prepare("SELECT company, department" . ($hasRegionColumn ? ", region" : "") . " FROM users WHERE id = ? LIMIT 1");
        $exportUserRow = null;
        if ($exportUserStmt) {
            $exportUserStmt->bind_param('i', $exportUserId);
            $exportUserStmt->execute();
            $exportUserRow = $exportUserStmt->get_result()->fetch_assoc();
            $exportUserStmt->close();
        }
        $exportCompany = trim((string) ($exportUserRow['company'] ?? ($_SESSION['company'] ?? '')));
        $exportDepartment = trim((string) ($exportUserRow['department'] ?? ($_SESSION['department'] ?? '')));
        $analyticsExportSalesRegion = $hasRegionColumn
            ? trim((string) ($exportUserRow['region'] ?? ($_SESSION['region'] ?? '')))
            : trim((string) ($_SESSION['region'] ?? ''));
        if (ticket_normalize_company($exportCompany) !== '@leadsagri.com'
            || strcasecmp($exportDepartment, 'Sales') !== 0
            || $analyticsExportSalesRegion === '') {
            die('Access Denied');
        }
    } else {
        user_permissions_ensure_table($conn);
        $employeePermissions = user_permissions_get_for_user($conn, (int) ($_SESSION['user_id'] ?? 0));
        if ((int) ($employeePermissions['analytics'] ?? 0) !== 1) {
            die('Access Denied');
        }
    }
} elseif (($_SESSION['role'] ?? '') !== 'admin') {
    die('Access Denied');
}

function analytics_excel_escape(string $value): string
{
    // XML 1.0 rejects most control characters, which can be present in text
    // pasted into a ticket description.
    $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function analytics_export_filters_excel(): array
{
    global $analyticsExportIsEmployeeView;
    $startDate = trim((string) ($_GET['start_date'] ?? ($analyticsExportIsEmployeeView ? date('Y-m-01') : '')));
    $endDate = trim((string) ($_GET['end_date'] ?? ($analyticsExportIsEmployeeView ? date('Y-m-d') : '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $startDate = '';
        $endDate = '';
    }
    $category = trim((string) ($_GET['category'] ?? ''));
    $assignee = (int) ($_GET['assignee'] ?? 0);
    $department = trim((string) ($_GET['department'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));

    global $analyticsExportIsSalesManagerView;
    $allowedStatuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = '';
    }

    $allowedDepartments = ['ACCOUNTING', 'ADMIN', 'BIDDING', 'E-COMM', 'HR', 'IT', 'LINGAP', 'MARKETING', 'SUPPLY CHAIN', 'TECHNICAL'];
    if (!$analyticsExportIsSalesManagerView && $department !== '' && !in_array($department, $allowedDepartments, true)) {
        $department = '';
    }

    $allowedCategories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support'];
    if (!$analyticsExportIsSalesManagerView && $category !== '' && !in_array($category, $allowedCategories, true)) {
        $category = '';
    }

    $filters = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'category' => $category,
        'assignee' => $assignee,
        'department' => $department,
        'status' => $status,
    ];

    if ($analyticsExportIsSalesManagerView) {
        $rawCompany = trim((string) ($_GET['company'] ?? ''));
        $filters['company'] = strtolower($rawCompany) === '__farmex_lav__'
            ? '__farmex_lav__'
            : ticket_normalize_company($rawCompany);
        $filters['assignee'] = 0;
    } elseif ($analyticsExportIsEmployeeView) {
        $filters['company'] = ticket_normalize_company(trim((string) ($_SESSION['company'] ?? '')));
        $filters['department'] = trim((string) ($_SESSION['department'] ?? ''));
        $filters['assignee'] = 0;
    } else {
        $filters['company'] = '';
    }

    return $filters;
}

function analytics_export_request_text_excel(string $description, string $subject): string
{
    $description = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description));
    if ($description === '') {
        return $subject !== '' ? $subject : '-';
    }

    $description = preg_replace('/^\s*(?:Position|Region):[^\r\n]*(?:\r\n|\r|\n)?/i', '', $description);
    $description = trim((string) preg_replace('/^\s*(?:Position|Region):[^\r\n]*(?:\r\n|\r|\n)?/i', '', (string) $description));

    $description = trim((string) preg_replace('/\s+/u', ' ', $description));
    if ($description === '') {
        return $subject !== '' ? $subject : '-';
    }

    $limit = 420;
    if (function_exists('mb_strlen') && mb_strlen($description, 'UTF-8') > $limit) {
        return rtrim(mb_substr($description, 0, $limit - 3, 'UTF-8')) . '...';
    }
    if (!function_exists('mb_strlen') && strlen($description) > $limit) {
        return rtrim(substr($description, 0, $limit - 3)) . '...';
    }

    return $description;
}

function analytics_export_description_field_excel(string $description, string $label): string
{
    $labelPattern = preg_quote($label, '/');
    if (preg_match('/^\s*' . $labelPattern . '\s*:\s*(.+)$/mi', $description, $match)) {
        $value = trim(strip_tags((string) ($match[1] ?? '')));
        return $value !== '' ? $value : '-';
    }

    return '-';
}

function analytics_export_rows_excel(mysqli $conn, array $filters): array
{
    global $analyticsExportIsSalesManagerView, $analyticsExportSalesRegion;
    $where = [];
    $params = [];
    $types = '';
    if ($filters['start_date'] !== '' && $filters['end_date'] !== '') {
        $where[] = "DATE(t.created_at) BETWEEN ? AND ?";
        $params[] = $filters['start_date'];
        $params[] = $filters['end_date'];
        $types .= 'ss';
    }

    if ($filters['category'] !== '') {
        $where[] = "t.category = ?";
        $params[] = $filters['category'];
        $types .= 's';
    }
    if ((int) $filters['assignee'] > 0) {
        $where[] = "t.assigned_user_id = ?";
        $params[] = (int) $filters['assignee'];
        $types .= 'i';
    }
    if (($filters['company'] ?? '') !== '') {
        if ((string) $filters['company'] === '__farmex_lav__') {
            $where[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) IN (?, ?)";
            $params[] = '@leads-farmex.com';
            $params[] = '@leadsav.com';
            $types .= 'ss';
        } else {
            $where[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
            $params[] = (string) $filters['company'];
            $types .= 's';
        }
    }
    if ($filters['department'] !== '') {
        $where[] = "COALESCE(NULLIF(t.assigned_department,''), NULLIF(t.assigned_group,'')) = ?";
        $params[] = $filters['department'];
        $types .= 's';
    }
    if ($filters['status'] !== '') {
        $where[] = "t.status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    if ($analyticsExportIsSalesManagerView) {
        $where[] = "EXISTS (
            SELECT 1 FROM users analytics_sales_creator
            WHERE analytics_sales_creator.id = t.user_id
              AND LOWER(TRIM(COALESCE(analytics_sales_creator.email, ''))) = 'sales_guest@leadsagri.com'
        )";
        $where[] = "COALESCE(t.description, '') LIKE ?";
        $params[] = '%Region: ' . $analyticsExportSalesRegion . '%';
        $types .= 's';
    }
    $where[] = "COALESCE(NULLIF(t.status,''),'') <> 'Trash'";

    $sql = "
        SELECT
            t.id,
            t.subject,
            t.description,
            t.category,
            t.created_at,
            t.started_at,
            t.updated_at,
            t.resolved_at,
            t.closed_at,
            t.status,
            COALESCE(NULLIF(t.assigned_department, ''), NULLIF(t.assigned_group, ''), '-') AS attending_it,
            COALESCE(NULLIF(t.requester_name, ''), NULLIF(u.name, ''), '-') AS client_name,
            COALESCE(NULLIF(t.department, ''), NULLIF(u.department, ''), '-') AS requester_department
        FROM employee_tickets t
        LEFT JOIN users u ON t.user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.created_at ASC, t.id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die('Query preparation failed: ' . $conn->error);
    }

    if ($types !== '') {
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $createdAt = trim((string) ($row['created_at'] ?? ''));
        $startedAt = trim((string) ($row['started_at'] ?? ''));
        $resolvedAt = trim((string) ($row['resolved_at'] ?? ''));
        $closedAt = trim((string) ($row['closed_at'] ?? ''));
        $updatedAt = trim((string) ($row['updated_at'] ?? ''));
        $completionAt = $resolvedAt !== '' ? $resolvedAt : $closedAt;
        $endDateSource = $completionAt !== '' ? $completionAt : $updatedAt;

        $duration = '-';
        $resolutionStart = $startedAt !== '' ? $startedAt : $createdAt;
        if ($resolutionStart !== '' && $completionAt !== '') {
            $seconds = ticket_business_seconds_between($resolutionStart, $completionAt);
            $duration = ticket_format_business_duration_clock($seconds);
        }

        $description = (string) ($row['description'] ?? '');
        $subject = (string) ($row['subject'] ?? '-');
        $exportRow = [
            $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : '-',
            $endDateSource !== '' ? date('Y-m-d', strtotime($endDateSource)) : '-',
            (string) ($row['attending_it'] ?? '-'),
            (string) ($row['client_name'] ?? '-'),
            (string) ($row['requester_department'] ?? '-'),
        ];
        if ($analyticsExportIsSalesManagerView) {
            $exportRow[] = analytics_export_description_field_excel($description, 'Position');
            $exportRow[] = analytics_export_description_field_excel($description, 'Region');
        }
        $exportRow = array_merge($exportRow, [
            analytics_export_request_text_excel($description, $subject),
            (string) ($row['category'] ?? '-'),
            $createdAt !== '' ? date('h:i A', strtotime($createdAt)) : '-',
            $completionAt !== '' ? date('h:i A', strtotime($completionAt)) : '-',
            (string) ($row['status'] ?? '-'),
            $duration,
        ]);
        $rows[] = $exportRow;
    }
    $stmt->close();

    return $rows;
}

function analytics_excel_category_style_id(string $category): string
{
    $key = strtolower(trim($category));
    switch ($key) {
        case 'hardware':
            return 'CategoryHardware';
        case 'software':
            return 'CategorySoftware';
        case 'email':
            return 'CategoryEmail';
        case 'procurement':
            return 'CategoryProcurement';
        case 'internet concerns':
            return 'CategoryInternet';
        default:
            return 'CategoryOther';
    }
}

function analytics_excel_status_style_id(string $status): string
{
    $key = strtolower(trim($status));
    switch ($key) {
        case 'resolved':
            return 'StatusResolved';
        case 'open':
            return 'StatusOpen';
        case 'in progress':
            return 'StatusInProgress';
        case 'closed':
            return 'StatusClosed';
        default:
            return 'CenterCell';
    }
}

function analytics_xlsx_column_name(int $index): string
{
    $name = '';
    for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
        $name = chr(65 + (($index - 1) % 26)) . $name;
    }
    return $name;
}

function analytics_xlsx_cell(string $reference, string $value, int $style = 0): string
{
    $escaped = analytics_excel_escape($value);
    return '<c r="' . $reference . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
        . $escaped . '</t></is></c>';
}

function analytics_xlsx_category_style(string $category): int
{
    return match (analytics_excel_category_style_id($category)) {
        'CategoryHardware' => 6,
        'CategorySoftware' => 7,
        'CategoryEmail' => 8,
        'CategoryProcurement' => 9,
        'CategoryInternet' => 10,
        default => 11,
    };
}

function analytics_xlsx_status_style(string $status): int
{
    return match (analytics_excel_status_style_id($status)) {
        'StatusResolved' => 10,
        'StatusOpen' => 9,
        'StatusInProgress' => 7,
        'StatusClosed' => 11,
        default => 5,
    };
}

$filters = analytics_export_filters_excel();
$rows = analytics_export_rows_excel($conn, $filters);
$headers = [
    'Start Date',
    'End Date',
    'Attendee',
    'Client',
    'Department / Subs',
];
if ($analyticsExportIsSalesManagerView) {
    $headers[] = 'Position';
    $headers[] = 'Region';
}
$headers = array_merge($headers, [
    'Request / Reported Concern',
    'Category (HL Report)',
    'Time Reported',
    'Time Resolved',
    'Status',
    'Duration',
]);
$columnCount = count($headers);
$lastColumnIndex = $columnCount - 1;
$categoryColumnIndex = $analyticsExportIsSalesManagerView ? 8 : 6;
$statusColumnIndex = $analyticsExportIsSalesManagerView ? 11 : 9;
$centerColumnIndexes = $analyticsExportIsSalesManagerView
    ? [0, 1, 2, 8, 9, 10, 11, 12]
    : [0, 1, 2, 6, 7, 8, 9, 10];
$columnWidths = $analyticsExportIsSalesManagerView
    ? [72, 72, 78, 110, 110, 110, 140, 260, 110, 78, 78, 78, 78]
    : [72, 72, 78, 110, 110, 260, 110, 78, 78, 78, 78];

$lastColumnName = analytics_xlsx_column_name($lastColumnIndex);
$dateRangeLabel = 'Date Range: ' . ($filters['start_date'] !== '' && $filters['end_date'] !== ''
    ? $filters['start_date'] . ' to ' . $filters['end_date']
    : 'All time');

$sheetRows = [];
$sheetRows[] = '<row r="1" ht="24" customHeight="1">' . analytics_xlsx_cell('A1', 'Leads DeskMetamorph Ticket Analytics Report', 1) . '</row>';
$sheetRows[] = '<row r="2" ht="18" customHeight="1">' . analytics_xlsx_cell('A2', $dateRangeLabel, 2) . '</row>';
$sheetRows[] = '<row r="3"/>';
$headerCells = '';
foreach ($headers as $index => $header) {
    $headerCells .= analytics_xlsx_cell(analytics_xlsx_column_name($index) . '4', (string) $header, 3);
}
$sheetRows[] = '<row r="4" ht="30" customHeight="1">' . $headerCells . '</row>';

$sheetRowNumber = 5;
if (count($rows) === 0) {
    $sheetRows[] = '<row r="5" ht="24" customHeight="1">' . analytics_xlsx_cell('A5', 'No records found for the selected filters.', 5) . '</row>';
} else {
    foreach ($rows as $row) {
        $cells = '';
        foreach ($row as $index => $value) {
            $style = in_array($index, $centerColumnIndexes, true) ? 5 : 4;
            if ($index === $categoryColumnIndex) {
                $style = analytics_xlsx_category_style((string) $value);
            } elseif ($index === $statusColumnIndex) {
                $style = analytics_xlsx_status_style((string) $value);
            }
            $cells .= analytics_xlsx_cell(analytics_xlsx_column_name($index) . $sheetRowNumber, (string) $value, $style);
        }
        $sheetRows[] = '<row r="' . $sheetRowNumber . '" ht="72" customHeight="1">' . $cells . '</row>';
        $sheetRowNumber++;
    }
}
$lastSheetRow = max(5, $sheetRowNumber - 1);

$xlsxColumnWidths = $analyticsExportIsSalesManagerView
    ? [12, 12, 13, 18, 18, 18, 22, 42, 20, 14, 14, 13, 13]
    : [12, 12, 13, 18, 18, 42, 20, 14, 14, 13, 13];
$columnsXml = '';
foreach ($xlsxColumnWidths as $index => $width) {
    $number = $index + 1;
    $columnsXml .= '<col min="' . $number . '" max="' . $number . '" width="' . $width . '" customWidth="1"/>';
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<dimension ref="A1:' . $lastColumnName . $lastSheetRow . '"/>'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>'
    . '<cols>' . $columnsXml . '</cols><sheetData>' . implode('', $sheetRows) . '</sheetData>'
    . '<mergeCells count="' . (count($rows) === 0 ? 3 : 2) . '"><mergeCell ref="A1:' . $lastColumnName . '1"/><mergeCell ref="A2:' . $lastColumnName . '2"/>'
    . (count($rows) === 0 ? '<mergeCell ref="A5:' . $lastColumnName . '5"/>' : '') . '</mergeCells>'
    . '<autoFilter ref="A4:' . $lastColumnName . $lastSheetRow . '"/>'
    . '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
    . '</worksheet>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="9"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><color rgb="FF4A237B"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF166534"/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><color rgb="FF926200"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF991B1B"/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><color rgb="FF1E40AF"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF374151"/><sz val="11"/><name val="Calibri"/></font></fonts>'
    . '<fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EFE9"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFD6C1F7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFC6EFCE"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF4CCCC"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFCFE2F3"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
    . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="12">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="7" fillId="7" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="8" fillId="8" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

$xlsxFiles = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Analytics Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml' => $stylesXml,
    'xl/worksheets/sheet1.xml' => $sheetXml,
];

$temporaryFile = tempnam(sys_get_temp_dir(), 'analytics_xlsx_');
if ($temporaryFile === false) {
    http_response_code(500);
    die('Unable to create the Excel export.');
}
$zip = new ZipArchive();
if ($zip->open($temporaryFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($temporaryFile);
    http_response_code(500);
    die('Unable to create the Excel export.');
}
foreach ($xlsxFiles as $path => $contents) {
    $zip->addFromString($path, $contents);
}
$zip->close();

if (ob_get_level()) {
    ob_end_clean();
}
$excelFileRange = $filters['start_date'] !== '' && $filters['end_date'] !== ''
    ? $filters['start_date'] . '_to_' . $filters['end_date']
    : 'all_time';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="analytics_report_' . $excelFileRange . '.xlsx"');
header('Content-Length: ' . filesize($temporaryFile));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($temporaryFile);
@unlink($temporaryFile);
exit;
