<?php
require_once '../config/database.php';

define('FPDF_FONTPATH', dirname(__DIR__) . '/vendor/fpdf/font/');
require_once '../vendor/fpdf/fpdf.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    die('Access Denied');
}

function analytics_export_filters(): array
{
    $startDate = trim((string) ($_GET['start_date'] ?? date('Y-m-01')));
    $endDate = trim((string) ($_GET['end_date'] ?? date('Y-m-d')));
    $category = trim((string) ($_GET['category'] ?? ''));
    $assignee = (int) ($_GET['assignee'] ?? 0);
    $department = trim((string) ($_GET['department'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));

    $allowedStatuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
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

    return [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'category' => $category,
        'assignee' => $assignee,
        'department' => $department,
        'status' => $status,
    ];
}

function analytics_export_fetch_rows(mysqli $conn, array $filters): array
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
            'start_date' => $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : '-',
            'end_date' => $endDateSource !== '' ? date('Y-m-d', strtotime($endDateSource)) : '-',
            'attending_it' => (string) ($row['attending_it'] ?? '-'),
            'client' => (string) ($row['client_name'] ?? '-'),
            'department_subs' => (string) ($row['requester_department'] ?? '-'),
            'request_concern' => trim((string) ($row['description'] ?? '')) !== '' ? trim((string) $row['description']) : (string) ($row['subject'] ?? '-'),
            'category' => (string) ($row['category'] ?? '-'),
            'time_reported' => $createdAt !== '' ? date('h:i A', strtotime($createdAt)) : '-',
            'time_resolved' => $resolvedAt !== '' ? date('h:i A', strtotime($resolvedAt)) : '-',
            'status' => (string) ($row['status'] ?? '-'),
            'duration' => $duration,
        ];
    }
    $stmt->close();

    return $rows;
}

function analytics_category_pdf_colors(string $category): array
{
    $key = strtolower(trim($category));
    switch ($key) {
        case 'hardware':
            return ['fill' => [214, 193, 247], 'text' => [74, 35, 123]];
        case 'software':
            return ['fill' => [198, 239, 206], 'text' => [22, 101, 52]];
        case 'email':
            return ['fill' => [255, 242, 204], 'text' => [146, 98, 0]];
        case 'procurement':
            return ['fill' => [244, 204, 204], 'text' => [153, 27, 27]];
        case 'internet concerns':
            return ['fill' => [207, 226, 243], 'text' => [30, 64, 175]];
        default:
            return ['fill' => [229, 231, 235], 'text' => [55, 65, 81]];
    }
}

function analytics_status_pdf_colors(string $status): array
{
    $key = strtolower(trim($status));
    switch ($key) {
        case 'resolved':
            return ['fill' => [207, 226, 243], 'text' => [30, 64, 175]];
        case 'open':
            return ['fill' => [244, 204, 204], 'text' => [153, 27, 27]];
        case 'in progress':
            return ['fill' => [198, 239, 206], 'text' => [22, 101, 52]];
        case 'closed':
            return ['fill' => [229, 231, 235], 'text' => [55, 65, 81]];
        default:
            return ['fill' => [243, 244, 246], 'text' => [55, 65, 81]];
    }
}

class AnalyticsReportPdf extends FPDF
{
    public array $widths = [];
    public array $aligns = [];
    public int $categoryColumnIndex = 6;
    public int $statusColumnIndex = 9;

    public function Row(array $data): void
    {
        $nb = 0;
        for ($i = 0; $i < count($data); $i++) {
            $nb = max($nb, $this->NbLines($this->widths[$i], (string) $data[$i]));
        }
        $h = 5 * $nb;

        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }

        for ($i = 0; $i < count($data); $i++) {
            $w = $this->widths[$i];
            $a = $this->aligns[$i] ?? 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            if ($i === $this->categoryColumnIndex || $i === $this->statusColumnIndex) {
                $colors = $i === $this->categoryColumnIndex
                    ? analytics_category_pdf_colors((string) $data[$i])
                    : analytics_status_pdf_colors((string) $data[$i]);
                $fill = $colors['fill'];
                $text = $colors['text'];
                $this->SetFillColor($fill[0], $fill[1], $fill[2]);
                $this->Rect($x, $y, $w, $h, 'F');
                $this->Rect($x, $y, $w, $h);
                $this->SetTextColor($text[0], $text[1], $text[2]);
                $this->MultiCell($w, 5, (string) $data[$i], 0, $a);
                $this->SetTextColor(0, 0, 0);
            } else {
                $this->Rect($x, $y, $w, $h);
                $this->MultiCell($w, 5, (string) $data[$i], 0, $a);
            }
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }

    public function NbLines(float $w, string $txt): int
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

$filters = analytics_export_filters();
$rows = analytics_export_fetch_rows($conn, $filters);

if (ob_get_level()) {
    ob_end_clean();
}

$pdf = new AnalyticsReportPdf('L', 'mm', 'A4');
$pdf->SetMargins(6, 8, 6);
$pdf->SetAutoPageBreak(true, 8);
$pdf->AddPage();

$pdf->SetFont('Helvetica', 'B', 14);
$pdf->Cell(0, 8, 'Leads Agri Helpdesk Ticket Analytics Report', 0, 1, 'C');
$pdf->SetFont('Helvetica', '', 9);
$pdf->Cell(0, 6, 'Date Range: ' . $filters['start_date'] . ' to ' . $filters['end_date'], 0, 1, 'C');
$pdf->Ln(3);

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

$pdf->SetFont('Helvetica', 'B', 7);
$pdf->SetFillColor(232, 239, 233);
$widths = [18, 18, 20, 26, 24, 67, 22, 18, 18, 16, 16];
$aligns = ['C', 'C', 'C', 'L', 'L', 'L', 'C', 'C', 'C', 'C', 'C'];
$pdf->widths = $widths;
$pdf->aligns = $aligns;

foreach ($headers as $idx => $header) {
    $pdf->Cell($widths[$idx], 8, $header, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Helvetica', '', 7);
$fill = false;
if (count($rows) === 0) {
    $pdf->Cell(array_sum($widths), 8, 'No records found for the selected filters.', 1, 1, 'C');
} else {
    foreach ($rows as $row) {
        if ($fill) {
            $pdf->SetFillColor(248, 250, 252);
            $pdf->Rect($pdf->GetX(), $pdf->GetY(), array_sum($widths), 5, 'F');
        }
        $pdf->Row([
            $row['start_date'],
            $row['end_date'],
            $row['attending_it'],
            $row['client'],
            $row['department_subs'],
            $row['request_concern'],
            $row['category'],
            $row['time_reported'],
            $row['time_resolved'],
            $row['status'],
            $row['duration'],
        ]);
        $fill = !$fill;
    }
}

$pdf->Output('D', 'analytics_report_' . $filters['start_date'] . '_to_' . $filters['end_date'] . '.pdf');
