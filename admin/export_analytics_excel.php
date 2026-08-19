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
    $description = trim($description);
    if ($description === '') {
        return $subject !== '' ? $subject : '-';
    }

    $description = preg_replace('/^\s*(?:Position|Region):[^\r\n]*(?:\r\n|\r|\n)?/i', '', $description);
    $description = trim((string) preg_replace('/^\s*(?:Position|Region):[^\r\n]*(?:\r\n|\r|\n)?/i', '', (string) $description));

    return $description !== '' ? $description : ($subject !== '' ? $subject : '-');
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

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.ms-excel');
$excelFileRange = $filters['start_date'] !== '' && $filters['end_date'] !== ''
    ? $filters['start_date'] . '_to_' . $filters['end_date']
    : 'all_time';
header('Content-Disposition: attachment; filename="analytics_report_' . $excelFileRange . '.xls"');
header('Cache-Control: max-age=0');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<?mso-application progid="Excel.Sheet"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <Styles>
  <Style ss:ID="Title">
   <Font ss:Bold="1" ss:Size="14"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  </Style>
  <Style ss:ID="Header">
   <Font ss:Bold="1"/>
   <Interior ss:Color="#E8EFE9" ss:Pattern="Solid"/>
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="Cell">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Alignment ss:Vertical="Top" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CenterCell">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CategoryHardware">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#D6C1F7" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#4A237B"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CategorySoftware">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#C6EFCE" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#166534"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CategoryEmail">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#FFF2CC" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#926200"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CategoryProcurement">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#F4CCCC" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#991B1B"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CategoryInternet">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#CFE2F3" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#1E40AF"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="CategoryOther">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#374151"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="StatusResolved">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#CFE2F3" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#1E40AF"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="StatusOpen">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#F4CCCC" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#991B1B"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="StatusInProgress">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#C6EFCE" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#166534"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
  <Style ss:ID="StatusClosed">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
   <Interior ss:Color="#E5E7EB" ss:Pattern="Solid"/>
   <Font ss:Bold="1" ss:Color="#374151"/>
   <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Analytics Report">
  <Table>
<?php foreach ($columnWidths as $columnWidth): ?>
   <Column ss:AutoFitWidth="0" ss:Width="<?= (int) $columnWidth ?>"/>
<?php endforeach; ?>
   <Row>
    <Cell ss:MergeAcross="<?= (int) $lastColumnIndex ?>" ss:StyleID="Title"><Data ss:Type="String">Leads DeskMetamorph Ticket Analytics Report</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="<?= (int) $lastColumnIndex ?>"><Data ss:Type="String"><?= analytics_excel_escape('Date Range: ' . ($filters['start_date'] !== '' && $filters['end_date'] !== '' ? $filters['start_date'] . ' to ' . $filters['end_date'] : 'All time')) ?></Data></Cell>
   </Row>
   <Row></Row>
   <Row>
<?php foreach ($headers as $header): ?>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?= analytics_excel_escape($header) ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php if (count($rows) === 0): ?>
   <Row>
    <Cell ss:MergeAcross="<?= (int) $lastColumnIndex ?>" ss:StyleID="CenterCell"><Data ss:Type="String">No records found for the selected filters.</Data></Cell>
   </Row>
<?php else: ?>
<?php foreach ($rows as $row): ?>
<?php $categoryStyleId = analytics_excel_category_style_id((string) $row[$categoryColumnIndex]); ?>
<?php $statusStyleId = analytics_excel_status_style_id((string) $row[$statusColumnIndex]); ?>
   <Row>
<?php foreach ($row as $idx => $value): ?>
<?php
    $styleId = in_array($idx, $centerColumnIndexes, true) ? 'CenterCell' : 'Cell';
    if ($idx === $categoryColumnIndex) {
        $styleId = $categoryStyleId;
    } elseif ($idx === $statusColumnIndex) {
        $styleId = $statusStyleId;
    }
?>
    <Cell ss:StyleID="<?= analytics_excel_escape($styleId) ?>"><Data ss:Type="String"><?= analytics_excel_escape((string) $value) ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php endforeach; ?>
<?php endif; ?>
  </Table>
 </Worksheet>
</Workbook>
