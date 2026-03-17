<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

$id = (int)$_GET['id'];

ticket_ensure_assignment_columns($conn);

function company_code(string $value): string
{
    $s = strtoupper(trim($value));
    if ($s === '') return '';
    if ($s === 'FARMASEE') return 'PCC';
    if (strpos($s, 'MHC') !== false) return 'MHC';
    if (strpos($s, 'GPCI') !== false || strpos($s, 'GPSCI') !== false) return 'GPCI';
    if (strpos($s, 'LAPC') !== false || strpos($s, 'LAH') !== false) return 'LAPC';
    if (strpos($s, 'PCC') !== false) return 'PCC';
    if (strpos($s, 'MPDC') !== false) return 'MPDC';
    if (strpos($s, 'LINGAP') !== false) return 'LINGAP';
    if (strpos($s, 'LTC') !== false) return 'LTC';
    if (strpos($s, 'FARMEX') !== false) return 'FARMEX';
    if (strpos($s, 'FARMEX CORP') !== false) return 'FARMEX';
    return '';
}

function company_aliases(string $value): array
{
    $v = trim($value);
    $code = company_code($v);
    $map = [
        'MHC' => ['MHC', 'Malveda Holdings Corporation - MHC'],
        'GPCI' => ['GPCI', 'GPSCI', 'Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI'],
        'LAPC' => ['LAPC', 'Leads Animal Health - LAH', 'LEADS Animal Health - LAH'],
        'PCC' => ['PCC', 'Primestocks Chemical Corporation - PCC', 'FARMASEE'],
        'MPDC' => ['MPDC', 'Malveda Properties & Development Corporation - MPDC'],
        'LINGAP' => ['LINGAP', 'LINGAP LEADS FOUNDATION - Lingap'],
        'LTC' => ['LTC', 'Leads Tech Corporation - LTC', 'Leads Tech Corporation - LTC'],
        'FARMEX' => ['FARMEX', 'Farmex Corp'],
    ];
    $aliases = [];
    if ($v !== '') $aliases[] = $v;
    if ($code !== '' && isset($map[$code])) {
        $aliases = array_merge($aliases, $map[$code]);
    }
    $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($x) { return $x !== ''; })));
    return $aliases;
}

function parseLegacyRequesterInfo($text) {
    if (!is_string($text) || $text === '') {
        return [null, null, $text];
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $normalized = preg_replace('/^COMPANY:\s*@.*(\n)?/im', '', $normalized);
    $normalized = preg_replace('/^\s*\n/', '', $normalized);

    $name = null;
    $email = null;
    $desc = $normalized;

    if (preg_match('/REQUESTER NAME:\s*(.+)$/im', $normalized, $m)) {
        $name = trim($m[1]);
    }
    if (preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $normalized, $m)) {
        $email = trim($m[1]);
    }
    if (preg_match('/DESCRIPTION:\s*(.*)$/is', $normalized, $m)) {
        $desc = trim($m[1]);
    } else {
        $desc = preg_replace('/^REQUESTER NAME:.*(\n)?/im', '', $normalized);
        $desc = preg_replace('/^REQUESTER EMAIL:.*(\n)?/im', '', $desc);
        $desc = preg_replace('/^COMPANY:\s*@.*(\n)?/im', '', $desc);
        $desc = preg_replace('/^DESCRIPTION:\s*/im', '', $desc);
        $desc = trim($desc);
    }

    return [$name, $email, $desc];
}

/* Mark notifications as read for this ticket */
$user_id = $_SESSION['user_id'];
$conn->query("UPDATE notifications SET is_read = 1 WHERE ticket_id = $id AND user_id = $user_id");

// 🟢 START TIMER LOGIC (For Employees working on the ticket)
// Only if the ticket is assigned to their department
$dept = $_SESSION['department'];
// Fetch user company if not in session
if (!isset($_SESSION['company'])) {
    $c_stmt = $conn->prepare("SELECT company FROM users WHERE id = ?");
    $c_stmt->bind_param("i", $_SESSION['user_id']);
    $c_stmt->execute();
    $c_res = $c_stmt->get_result();
    if ($c_row = $c_res->fetch_assoc()) {
        $_SESSION['company'] = $c_row['company'];
    }
}
$company = $_SESSION['company'];

$checkStmt = $conn->prepare("SELECT started_at, assigned_department, assigned_group, assigned_company, assigned_user_id FROM employee_tickets WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    $ticketData = $checkResult->fetch_assoc();
    $assigneeOk = isset($ticketData['assigned_user_id']) && (int) $ticketData['assigned_user_id'] === (int) $_SESSION['user_id'];
    $ticketCompanyCode = company_code((string) ($ticketData['assigned_company'] ?? ''));
    $userCompanyCode = company_code((string) $company);
    $companyOk = ($ticketCompanyCode !== '' && $userCompanyCode !== '' && $ticketCompanyCode === $userCompanyCode) || ((string) ($ticketData['assigned_company'] ?? '') === (string) $company);
    $ticketGroup = (string) ($ticketData['assigned_group'] ?? ($ticketData['assigned_department'] ?? ''));
    $groupOk = $ticketGroup !== '' && $ticketGroup === $dept;
    if (($assigneeOk || ($groupOk && $companyOk)) && is_null($ticketData['started_at'])) {
        $updateStart = $conn->prepare("UPDATE employee_tickets SET started_at = NOW() WHERE id = ?");
        $updateStart->bind_param("i", $id);
        $updateStart->execute();
    }
}

$companyAliases = company_aliases((string) $company);
$companyCond = implode(' OR ', array_fill(0, max(1, count($companyAliases)), "COALESCE(NULLIF(t.assigned_company, ''), t.company) = ?"));
if (count($companyAliases) === 0) {
    $companyAliases = [''];
}
$sql = "
    SELECT 
        t.*, 
        u.name as created_by_name, 
        u.email as created_by_email, 
        u.company as user_company,
        u.department as user_department
    FROM employee_tickets t 
    JOIN users u ON t.user_id = u.id 
    WHERE t.id = ? AND (
        t.user_id = ?
        OR t.assigned_user_id = ?
        OR (COALESCE(NULLIF(t.assigned_group, ''), t.assigned_department) = ? AND ($companyCond))
    )
";
$stmt = $conn->prepare($sql);
$types = 'iiis' . str_repeat('s', count($companyAliases));
$params = array_merge([$id, (int) $_SESSION['user_id'], (int) $_SESSION['user_id'], $dept], $companyAliases);
$bind = [];
$bind[] = $types;
foreach ($params as $k => $p) {
    $bind[] = &$params[$k];
}
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Use ticket company if set, otherwise user company
    $row['company'] = !empty($row['company']) ? $row['company'] : $row['user_company'];
    $row['department'] = !empty($row['department']) ? $row['department'] : ($row['user_department'] ?? '');
    if (empty($row['department'])) {
        $row['department'] = 'Unknown';
    }

    $requester_name = trim((string)($row['requester_name'] ?? ''));
    $requester_email = trim((string)($row['requester_email'] ?? ''));

    $clean_desc = $row['description'] ?? '';
    if ($requester_name === '' && $requester_email === '') {
        [$parsed_name, $parsed_email, $parsed_desc] = parseLegacyRequesterInfo($clean_desc);
        if (!empty($parsed_name)) $requester_name = $parsed_name;
        if (!empty($parsed_email)) $requester_email = $parsed_email;
        $clean_desc = $parsed_desc;
    }
    if (is_string($clean_desc) && $clean_desc !== '') {
        $clean_desc = preg_replace('/^\s*COMPANY:\s*.*(\r?\n)+/i', '', $clean_desc);
        $clean_desc = ltrim((string) $clean_desc, "\r\n");
    }

    if ($requester_name !== '') $row['created_by_name'] = $requester_name;
    if ($requester_email !== '') $row['created_by_email'] = $requester_email;
    $row['description'] = $clean_desc;

    $attachments = [];
    if (!empty($row['attachment'])) {
        $attachments[] = ['stored_name' => (string) $row['attachment'], 'original_name' => (string) $row['attachment']];
    }
    $attStmt = $conn->prepare("SELECT stored_name, original_name FROM ticket_attachments WHERE ticket_id = ? ORDER BY id ASC");
    if ($attStmt) {
        $attStmt->bind_param("i", $id);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        $seen = [];
        foreach ($attachments as $a0) {
            $sn0 = (string) ($a0['stored_name'] ?? '');
            if ($sn0 !== '') $seen[$sn0] = true;
        }
        while ($attRes && ($a = $attRes->fetch_assoc())) {
            $sn = (string) ($a['stored_name'] ?? '');
            if ($sn === '') continue;
            if (isset($seen[$sn])) continue;
            $seen[$sn] = true;
            $attachments[] = [
                'stored_name' => $sn,
                'original_name' => (string) ($a['original_name'] ?? $sn)
            ];
        }
        $attStmt->close();
    }
    $row['attachments'] = $attachments;

    // Calculate Duration
    $duration = "Not Started";
    if (!is_null($row['started_at'])) {
        if (is_null($row['resolved_at'])) {
            $duration = "In Progress";
        } else {
            $start = new DateTime($row['started_at']);
            $end = new DateTime($row['resolved_at']);
            $diff = $start->diff($end);
            
            $parts = [];
            if ($diff->d > 0) $parts[] = $diff->d . ($diff->d === 1 ? " day" : " days");
            if ($diff->h > 0) $parts[] = $diff->h . ($diff->h === 1 ? " hr" : " hrs");
            if ($diff->i > 0) $parts[] = $diff->i . ($diff->i === 1 ? " min" : " mins");
            
            $duration = empty($parts) ? "0 min" : implode(" ", $parts);
        }
    }
    $row['duration'] = $duration;

    echo json_encode($row);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Ticket not found']);
}
?>
