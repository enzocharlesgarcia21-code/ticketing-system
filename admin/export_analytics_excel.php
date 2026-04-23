<?php
require_once '../config/database.php';
require_once '../includes/user_permissions.php';

$analyticsExportViewMode = defined('TICKETING_ANALYTICS_EXPORT_VIEW_MODE') ? (string) TICKETING_ANALYTICS_EXPORT_VIEW_MODE : 'admin';
$analyticsExportIsEmployeeView = $analyticsExportViewMode === 'employee';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    die('Access Denied');
}

if ($analyticsExportIsEmployeeView) {
    if ((string) ($_SESSION['role'] ?? '') !== 'employee') {
        die('Access Denied');
    }
    user_permissions_ensure_table($conn);
    $employeePermissions = user_permissions_get_for_user($conn, (int) ($_SESSION['user_id'] ?? 0));
    if ((int) ($employeePermissions['analytics'] ?? 0) !== 1) {
        die('Access Denied');
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
    $startDate = trim((string) ($_GET['start_date'] ?? date('Y-m-01')));
    $endDate = trim((string) ($_GET['end_date'] ?? date('Y-m-d')));
    $category = trim((string) ($_GET['category'] ?? ''));
    $assignee = (int) ($_GET['assignee'] ?? 0);
    $department = trim((string) ($_GET['department'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));

    $allowedStatuses = ['Open', 'In Progress', 'Resolved'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = '';
    }

    $allowedDepartments = ['ACCOUNTING', 'ADMIN', 'BIDDING', 'E-COMM', 'HR', 'IT', 'LINGAP', 'MARKETING', 'SUPPLY CHAIN', 'TECHNICAL'];
    if ($department !== '' && !in_array($department, $allowedDepartments, true)) {
        $department = '';
    }

    $allowedCategories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support'];
    if ($category !== '' && !in_array($category, $allowedCategories, true)) {
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

    global $analyticsExportIsEmployeeView;
    if ($analyticsExportIsEmployeeView) {
        $filters['company'] = ticket_normalize_company(trim((string) ($_SESSION['company'] ?? '')));
        $filters['department'] = trim((string) ($_SESSION['department'] ?? ''));
        $filters['assignee'] = 0;
    } else {
        $filters['company'] = '';
    }

    return $filters;
}

function analytics_export_rows_excel(mysqli $conn, array $filters): array
{
    $where = ["DATE(t.created_at) BETWEEN ? AND ?"];
    $params = [$filters['start_date'], $filters['end_date']];
    $types = 'ss';

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
        $where[] = "COALESCE(NULLIF(t.assigned_company,''), NULLIF(t.company,'')) = ?";
        $params[] = (string) $filters['company'];
        $types .= 's';
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
    $where[] = "COALESCE(NULLIF(t.status,''),'') NOT IN ('Closed','Trash')";

    $sql = "
        SELECT
            t.id,
            t.subject,
            t.description,
            t.category,
            t.created_at,
            t.updated_at,
            t.resolved_at,
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

    $bind = [$types];
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $createdAt = trim((string) ($row['created_at'] ?? ''));
        $resolvedAt = trim((string) ($row['resolved_at'] ?? ''));
        $updatedAt = trim((string) ($row['updated_at'] ?? ''));
        $endDateSource = $resolvedAt !== '' ? $resolvedAt : $updatedAt;

        $duration = '-';
        if ($createdAt !== '' && $resolvedAt !== '') {
            try {
                $start = new DateTimeImmutable($createdAt);
                $end = new DateTimeImmutable($resolvedAt);
                $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
                $duration = gmdate('H:i:s', $seconds);
            } catch (Throwable $e) {
                $duration = '-';
            }
        }

        $rows[] = [
            $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : '-',
            $endDateSource !== '' ? date('Y-m-d', strtotime($endDateSource)) : '-',
            (string) ($row['attending_it'] ?? '-'),
            (string) ($row['client_name'] ?? '-'),
            (string) ($row['requester_department'] ?? '-'),
            trim((string) ($row['description'] ?? '')) !== '' ? trim((string) $row['description']) : (string) ($row['subject'] ?? '-'),
            (string) ($row['category'] ?? '-'),
            $createdAt !== '' ? date('h:i A', strtotime($createdAt)) : '-',
            $resolvedAt !== '' ? date('h:i A', strtotime($resolvedAt)) : '-',
            (string) ($row['status'] ?? '-'),
            $duration,
        ];
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
    'Attending IT',
    'Client',
    'Department / Subs',
    'Request / Reported Concern',
    'Category (HL Report)',
    'Time Reported',
    'Time Resolved',
    'Status',
    'Duration',
];

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="analytics_report_' . $filters['start_date'] . '_to_' . $filters['end_date'] . '.xls"');
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
   <Column ss:AutoFitWidth="0" ss:Width="72"/>
   <Column ss:AutoFitWidth="0" ss:Width="72"/>
   <Column ss:AutoFitWidth="0" ss:Width="78"/>
   <Column ss:AutoFitWidth="0" ss:Width="110"/>
   <Column ss:AutoFitWidth="0" ss:Width="110"/>
   <Column ss:AutoFitWidth="0" ss:Width="260"/>
   <Column ss:AutoFitWidth="0" ss:Width="110"/>
   <Column ss:AutoFitWidth="0" ss:Width="78"/>
   <Column ss:AutoFitWidth="0" ss:Width="78"/>
   <Column ss:AutoFitWidth="0" ss:Width="78"/>
   <Column ss:AutoFitWidth="0" ss:Width="78"/>
   <Row>
    <Cell ss:MergeAcross="10" ss:StyleID="Title"><Data ss:Type="String">Leads Agri Helpdesk Ticket Analytics Report</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="10"><Data ss:Type="String"><?= analytics_excel_escape('Date Range: ' . $filters['start_date'] . ' to ' . $filters['end_date']) ?></Data></Cell>
   </Row>
   <Row></Row>
   <Row>
<?php foreach ($headers as $header): ?>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?= analytics_excel_escape($header) ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php if (count($rows) === 0): ?>
   <Row>
    <Cell ss:MergeAcross="10" ss:StyleID="CenterCell"><Data ss:Type="String">No records found for the selected filters.</Data></Cell>
   </Row>
<?php else: ?>
<?php foreach ($rows as $row): ?>
<?php $categoryStyleId = analytics_excel_category_style_id((string) $row[6]); ?>
<?php $statusStyleId = analytics_excel_status_style_id((string) $row[9]); ?>
   <Row>
    <Cell ss:StyleID="CenterCell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[0]) ?></Data></Cell>
    <Cell ss:StyleID="CenterCell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[1]) ?></Data></Cell>
    <Cell ss:StyleID="CenterCell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[2]) ?></Data></Cell>
    <Cell ss:StyleID="Cell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[3]) ?></Data></Cell>
    <Cell ss:StyleID="Cell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[4]) ?></Data></Cell>
    <Cell ss:StyleID="Cell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[5]) ?></Data></Cell>
    <Cell ss:StyleID="<?= analytics_excel_escape($categoryStyleId) ?>"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[6]) ?></Data></Cell>
    <Cell ss:StyleID="CenterCell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[7]) ?></Data></Cell>
    <Cell ss:StyleID="CenterCell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[8]) ?></Data></Cell>
    <Cell ss:StyleID="<?= analytics_excel_escape($statusStyleId) ?>"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[9]) ?></Data></Cell>
    <Cell ss:StyleID="CenterCell"><Data ss:Type="String"><?= analytics_excel_escape((string) $row[10]) ?></Data></Cell>
   </Row>
<?php endforeach; ?>
<?php endif; ?>
  </Table>
 </Worksheet>
</Workbook>
