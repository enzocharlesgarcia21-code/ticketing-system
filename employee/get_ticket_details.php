<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/pdf_thumbnail.php';

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
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

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

function unavailable_reassignment_target_label(array $ticketData): string
{
    $targetCompany = trim((string) (($ticketData['assigned_company'] ?? '') !== '' ? $ticketData['assigned_company'] : ($ticketData['company'] ?? '')));
    $targetGroup = trim((string) (($ticketData['assigned_group'] ?? '') !== '' ? $ticketData['assigned_group'] : ($ticketData['assigned_department'] ?? '')));
    $targetGroupDisplay = ticket_department_display_name($targetGroup);
    $targetCompanyDisplay = ticket_company_display_name($targetCompany);

    if ($targetGroupDisplay !== '' && notif_company_requires_department($targetCompany)) {
        return $targetGroupDisplay . ' Department';
    }

    if ($targetCompanyDisplay !== '') {
        return $targetCompanyDisplay;
    }

    if ($targetGroupDisplay !== '') {
        return $targetGroupDisplay . ' Department';
    }

    return 'another team';
}

function ticket_attachment_is_image(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function ticket_sort_attachments(array $attachments): array
{
    usort($attachments, static function ($a, $b) {
        $aStored = (string) ($a['stored_name'] ?? '');
        $bStored = (string) ($b['stored_name'] ?? '');
        $aName = strtolower(trim((string) ($a['original_name'] ?? $aStored)));
        $bName = strtolower(trim((string) ($b['original_name'] ?? $bStored)));
        $aImage = ticket_attachment_is_image($aStored) ? 0 : 1;
        $bImage = ticket_attachment_is_image($bStored) ? 0 : 1;
        if ($aImage !== $bImage) {
            return $aImage <=> $bImage;
        }
        return $aName <=> $bName;
    });
    return $attachments;
}

function ticket_enrich_attachment_preview(array $attachment): array
{
    $storedName = (string) ($attachment['stored_name'] ?? '');
    if ($storedName !== '' && function_exists('ticket_pdf_attachment_meta')) {
        $attachment = array_merge($attachment, ticket_pdf_attachment_meta($storedName, '../'));
    } else {
        $attachment['is_pdf'] = false;
        $attachment['thumbnail_available'] = false;
        $attachment['thumbnail_url'] = '';
    }
    return $attachment;
}

function ticket_request_meta_ensure_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS ticket_request_meta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        meta_key VARCHAR(100) NOT NULL,
        meta_value TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ticket_meta (ticket_id, meta_key),
        INDEX idx_ticket_request_meta_ticket (ticket_id),
        CONSTRAINT fk_ticket_request_meta_ticket FOREIGN KEY (ticket_id) REFERENCES employee_tickets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ticket_request_meta_load(mysqli $conn, int $ticketId): array
{
    ticket_request_meta_ensure_table($conn);
    $meta = [];
    $stmt = $conn->prepare("SELECT meta_key, meta_value FROM ticket_request_meta WHERE ticket_id = ?");
    if (!$stmt) {
        return $meta;
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $key = trim((string) ($row['meta_key'] ?? ''));
        if ($key === '') continue;
        $meta[$key] = (string) ($row['meta_value'] ?? '');
    }
    $stmt->close();
    return $meta;
}

function ticket_hr_attachment_groups(array $attachments, bool $isSssCategory): array
{
    $groups = [];
    $order = [];
    $preferredTitles = $isSssCategory
        ? [
            'Accomplished SSS Sickness Form',
            'Medical Procedures',
            'Laboratory Results',
            'Medical Certificates',
            'Discharge Summary/Proof',
        ]
        : [];
    $groupMeta = $isSssCategory
        ? [
            'Accomplished SSS Sickness Form' => ['helper_text' => 'Upload 1 supported file. Max 10 MB.'],
            'Medical Procedures' => ['helper_text' => 'Upload up to 5 supported files. Max 10 MB per file.'],
            'Laboratory Results' => ['helper_text' => 'Upload up to 5 supported files. Max 10 MB per file.'],
            'Medical Certificates' => ['helper_text' => 'Upload up to 5 supported files. Max 10 MB per file.'],
            'Discharge Summary/Proof' => ['helper_text' => 'Upload up to 5 supported files. Max 10 MB per file.'],
        ]
        : [];

    foreach ($attachments as $attachment) {
        $stored = (string) ($attachment['stored_name'] ?? '');
        if ($stored === '') continue;
        $display = (string) ($attachment['original_name'] ?? $stored);
        $groupTitle = 'Attachment';
        $itemName = $display;

        if ($isSssCategory && strpos($display, ' - ') !== false) {
            [$prefix, $rest] = explode(' - ', $display, 2);
            $prefix = trim((string) $prefix);
            $rest = trim((string) $rest);
            if ($prefix !== '') {
                $groupTitle = $prefix;
                if ($rest !== '') {
                    $itemName = $rest;
                }
            }
        }

        if (
            $isSssCategory
            && $groupTitle === 'Attachment'
            && !isset($groups['Accomplished SSS Sickness Form'])
        ) {
            $groupTitle = 'Accomplished SSS Sickness Form';
        }

        if (!isset($groups[$groupTitle])) {
            $groups[$groupTitle] = [];
            $order[] = $groupTitle;
        }

        $groupAttachment = $attachment;
        $groupAttachment['stored_name'] = $stored;
        $groupAttachment['original_name'] = $itemName;
        $groups[$groupTitle][] = $groupAttachment;
    }

    $result = [];
    $sortedOrder = $order;
    if (!empty($preferredTitles)) {
        $sortedOrder = [];
        foreach ($preferredTitles as $preferredTitle) {
            if (isset($groups[$preferredTitle])) {
                $sortedOrder[] = $preferredTitle;
            }
        }
        foreach ($order as $title) {
            if (!in_array($title, $sortedOrder, true)) {
                $sortedOrder[] = $title;
            }
        }
    }

    foreach ($sortedOrder as $title) {
        $result[] = [
            'title' => $title,
            'attachments' => ticket_sort_attachments($groups[$title]),
            'helper_text' => (string) ($groupMeta[$title]['helper_text'] ?? ''),
        ];
    }
    return $result;
}

function ticket_build_hr_display(array $row, array $attachments, array $meta): array
{
    $assignedCompany = strtolower(trim((string) ($row['assigned_company'] ?? '')));
    $assignedGroup = trim((string) ($row['assigned_group'] ?? ($row['assigned_department'] ?? '')));
    $category = trim((string) ($row['category'] ?? ''));
    $subject = trim((string) ($row['subject'] ?? ''));
    $priority = trim((string) ($row['priority'] ?? ''));
    $description = trim((string) ($row['description'] ?? ''));
    $concernType = trim((string) ($meta['hr_concern_type'] ?? ''));
    $defaultSubject = $category !== '' ? ($category . ' Concern') : '';
    $subjectTitle = ($subject !== '' && strcasecmp($subject, $defaultSubject) !== 0) ? $subject : '';
    $descriptionText = $description;
    if ($category === 'SSS Sickness and Benefit Concern' && strcasecmp($description, 'SSS Notification and Benefits Concern submission.') === 0) {
        $descriptionText = '';
    }

    $isLapcHr = ($assignedCompany === '@leadsagri.com' && $assignedGroup === 'HR');
    $isSpecialCategory = in_array($category, ['Attendance & Timekeeping', 'Leave Concern', 'SSS Sickness and Benefit Concern', 'Others'], true);
    $isHrSpecial = ($isLapcHr && $isSpecialCategory);
    $summarySubjectValue = $subjectTitle !== ''
        ? $subjectTitle
        : (($category === 'Leave Concern') ? $category : '');
    $summaryFields = [];

    if ($isHrSpecial && $concernType !== '') {
        $summaryFields[] = ['label' => 'Type of Concern', 'value' => $concernType];
    }
    if ($isHrSpecial && $summarySubjectValue !== '') {
        $summaryFields[] = ['label' => 'Subject/Title of Request', 'value' => $summarySubjectValue];
    }

    $sectionTitle = 'Request Details';
    if ($category === 'Attendance & Timekeeping') {
        $sectionTitle = 'Attendance and Timekeeping (KAMI)';
    } elseif ($category === 'SSS Sickness and Benefit Concern') {
        $sectionTitle = 'SSS Notification and Benefits Concern';
    }

    return [
        'is_hr_special' => $isHrSpecial,
        'request_section_title' => $sectionTitle,
        'category' => $category,
        'priority' => $priority,
        'concern_type' => $concernType,
        'subject_title' => $subjectTitle,
        'summary_fields' => $summaryFields,
        'detail_label' => in_array($category, ['Leave Concern', 'Others'], true)
            ? 'Detailed Description of Request or Concern'
            : 'Description',
        'detail_text' => $descriptionText,
        'attachment_groups' => $isHrSpecial
            ? ticket_hr_attachment_groups($attachments, $category === 'SSS Sickness and Benefit Concern')
            : [],
    ];
}

/* Mark notifications as read for this ticket */
$user_id = $currentUserId;
$conn->query("UPDATE notifications SET is_read = 1 WHERE ticket_id = $id AND user_id = $user_id");

// 🟢 START TIMER LOGIC (For Employees working on the ticket)
// Only if the ticket is assigned to their department
$dept = (string) ($_SESSION['department'] ?? '');
$company = (string) ($_SESSION['company'] ?? '');
$userEmail = (string) ($_SESSION['email'] ?? '');

if ($dept === '' || $company === '' || $userEmail === '') {
    $userInfoStmt = $conn->prepare("SELECT department, company, email FROM users WHERE id = ? LIMIT 1");
    if ($userInfoStmt) {
        $userInfoStmt->bind_param("i", $currentUserId);
        $userInfoStmt->execute();
        $userInfoRes = $userInfoStmt->get_result();
        $userInfoRow = $userInfoRes ? $userInfoRes->fetch_assoc() : null;
        $userInfoStmt->close();

        if ($userInfoRow) {
            if ($dept === '') {
                $dept = (string) ($userInfoRow['department'] ?? '');
                $_SESSION['department'] = $dept;
            }
            if ($company === '') {
                $company = (string) ($userInfoRow['company'] ?? '');
                $_SESSION['company'] = $company;
            }
            if ($userEmail === '') {
                $userEmail = (string) ($userInfoRow['email'] ?? '');
                $_SESSION['email'] = $userEmail;
            }
        }
    }
}

$deptKey = ticket_department_key_from_value($dept);
$deptAliases = ticket_department_aliases_for_key($deptKey);
if ($deptKey !== '') {
    $deptAliases[] = $deptKey;
}
$deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $deptAliases)), static function ($value) {
    return is_string($value) && $value !== '';
})));

$checkStmt = $conn->prepare("SELECT user_id, started_at, assigned_department, assigned_group, assigned_company, company, assigned_user_id, assigned_to FROM employee_tickets WHERE id = ?");
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    $ticketData = $checkResult->fetch_assoc();
    $isRequester = isset($ticketData['user_id']) && (int) $ticketData['user_id'] === $currentUserId;
    $assigneeOk = isset($ticketData['assigned_user_id']) && (int) $ticketData['assigned_user_id'] === (int) $_SESSION['user_id'];
    $ticketAssignedCompany = (string) ($ticketData['assigned_company'] ?? '');
    $ticketCompanyCode = company_code($ticketAssignedCompany);
    $userCompanyCode = company_code((string) $company);
    if (strpos($ticketAssignedCompany, '@') === 0) {
        $ticketDomain = strtolower(ltrim($ticketAssignedCompany, '@'));
        $companyOk = ($ticketDomain !== '' && $userEmail !== '' && str_ends_with(strtolower($userEmail), '@' . $ticketDomain));
    } else {
        $companyOk = ($ticketCompanyCode !== '' && $userCompanyCode !== '' && $ticketCompanyCode === $userCompanyCode) || ($ticketAssignedCompany === (string) $company);
    }
    $ticketGroup = (string) ($ticketData['assigned_group'] ?? ($ticketData['assigned_department'] ?? ''));
    $ticketGroupKey = ticket_department_key_from_value($ticketGroup);
    $groupOk = $ticketGroup !== '' && (
        ($deptKey !== '' && $ticketGroupKey !== '' && $ticketGroupKey === $deptKey)
        || in_array(strtoupper(trim($ticketGroup)), $deptAliases, true)
    );
}

$sql = "
    SELECT 
        t.*, 
        u.name as created_by_name, 
        u.email as created_by_email, 
        u.company as user_company,
        u.department as user_department,
        assignee.name AS assignee_name,
        assignee.email AS assignee_email,
        assignee.department AS assignee_department,
        handler.name AS assigned_to_name,
        handler.email AS assigned_to_email,
        handler.department AS assigned_to_department
    FROM employee_tickets t 
    LEFT JOIN users u ON t.user_id = u.id 
    LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
    LEFT JOIN users handler ON handler.id = t.assigned_to
    WHERE t.id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
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
    $row = ticket_chat_apply_effective_handler($row);
    $userContext = ticket_build_user_context($conn, $currentUserId, $_SESSION);
    $hasAccess = ticket_user_matches_requester($row, $currentUserId, $userContext)
        || ticket_user_is_handler_candidate($row, $currentUserId, $userContext);
    if (!$hasAccess) {
        $ticketData = $row;
        http_response_code(403);
        $targetLabel = unavailable_reassignment_target_label($ticketData);
        echo json_encode([
            'error' => 'Ticket access removed',
            'error_code' => 'ticket_reassigned',
            'unavailable_title' => 'This ticket has been reassigned to ' . $targetLabel . '.',
            'unavailable_message' => 'You can no longer view or respond to this ticket.'
        ]);
        exit;
    }
    $row['can_chat'] = ticket_user_can_chat($row, $currentUserId, $userContext);
    $row['assigned_to'] = isset($row['assigned_to']) ? (int) $row['assigned_to'] : null;
    $row['assigned_to_name'] = isset($row['assigned_to_name']) ? (string) $row['assigned_to_name'] : '';
    $row['assigned_to_email'] = isset($row['assigned_to_email']) ? (string) $row['assigned_to_email'] : '';
    $row['assigned_to_department'] = isset($row['assigned_to_department']) ? (string) $row['assigned_to_department'] : '';
    if ($row['can_chat']) {
        $row['chat_locked_message'] = '';
    } elseif ($row['assigned_to_name'] !== '') {
        $row['chat_locked_message'] = 'This ticket is already assigned to ' . $row['assigned_to_name'] . '.';
    } else {
        $row['chat_locked_message'] = 'This ticket is handled by another IT staff.';
    }

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
    $row['attachments'] = array_map('ticket_enrich_attachment_preview', ticket_sort_attachments($attachments));
    $row['request_meta'] = ticket_request_meta_load($conn, $id);
    $row['hr_display'] = ticket_build_hr_display($row, $row['attachments'], $row['request_meta']);
    $row['subject_display'] = (
        !empty($row['hr_display']['is_hr_special'])
        && in_array((string) ($row['category'] ?? ''), ['Leave Concern', 'Others'], true)
    )
        ? (string) ($row['category'] ?? $row['subject'])
        : (string) ($row['subject'] ?? '');

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
    if (!empty($ticketData)) {
        $targetLabel = unavailable_reassignment_target_label($ticketData);
        http_response_code(403);
        echo json_encode([
            'error' => 'Ticket access removed',
            'error_code' => 'ticket_reassigned',
            'unavailable_title' => 'This ticket has been reassigned to ' . $targetLabel . '.',
            'unavailable_message' => 'You can no longer view or respond to this ticket.'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Ticket not found']);
    }
}
?>
