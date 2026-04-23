<?php

require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/pdf_thumbnail.php';

function ticket_company_group_map(): array
{
    $standard = ticket_standard_assigned_departments();
    $lapc = ticket_lapc_departments();
    return [
        'LAPC' => $lapc,
        'GPCI' => $standard,
        'PCC' => $standard,
        'MHC' => $standard,
        'Farmex Corp' => $standard,
        'LTC' => $standard,
        'MPDC' => $standard,
        'LINGAP' => $standard,
        '@gpsci.net' => $standard,
        '@farmasee.ph' => $standard,
        '@gmail.com' => $standard,
        '@leads-eh.com' => $standard,
        '@leads-farmex.com' => $standard,
        '@leadsagri.com' => $lapc,
        '@leadsanimalhealth.com' => $standard,
        '@leadsav.com' => $standard,
        '@malvedaholdings.com' => $standard,
        '@malvedaproperties.com' => $standard,
        '@leadstech-corp.com' => $standard,
        '@lingapleads.org' => $standard,
        '@primestocks.ph' => $standard,
    ];
}

function ticket_standard_assigned_departments(): array
{
    return [
        'ACCOUNTING',
        'ADMIN',
        'BIDDING',
        'E-COMM',
        'HR',
        'IT',
        'LINGAP',
        'MARKETING',
        'SUPPLY CHAIN',
        'TECHNICAL',
    ];
}

function ticket_lapc_departments(): array
{
    return [
        'Admin & Legal',
        'Banana Farm Operations',
        'Bidding',
        'Diagnostics / Lingap',
        'Digital Agri Solutions and Innovations',
        'E-Commerce',
        'Executive',
        'Finance and Accounting',
        'HR',
        'IT',
        'Institutional Sales',
        'Management',
        'Marketing',
        'New Business Segment',
        'Seed Production',
        'Supply Chain',
        'Supply Chain Innovation',
        'Technical',
    ];
}

function ticket_company_allowed_groups(string $company): array
{
    $company = ticket_normalize_company($company);
    if ($company === '@leadsagri.com' || strtoupper($company) === 'LAPC') {
        return ticket_lapc_departments();
    }
    return ticket_standard_assigned_departments();
}

function ticket_department_aliases_for_key(string $key): array
{
    $key = strtoupper(trim($key));
    $map = [
        'ACCOUNTING' => ['ACCOUNTING', 'FINANCE AND ACCOUNTING', 'FINANCE & ACCOUNTING'],
        'ADMIN' => ['ADMIN', 'ADMINISTRATION', 'ADMIN & LEGAL', 'FINANCE AND ADMIN', 'FINANCE & ADMIN'],
        'BIDDING' => ['BIDDING'],
        'E-COMM' => ['E-COMM', 'E-COMMERCE', 'E-COMMERCE', 'E COMMERCE', 'ECOMM'],
        'HR' => ['HR', 'HUMAN RESOURCE', 'HUMAN RESOURCES', 'HUMAN RESOURCE AND TRANSFORMATION', 'HUMAN RESOURCES AND TRANSFORMATION', 'HUMAN RESOURCE & TRANSFORMATION'],
        'IT' => ['IT'],
        'LINGAP' => ['LINGAP', 'DIAGNOSTICS / LINGAP', 'DIAGNOSTICS/LINGAP'],
        'MARKETING' => ['MARKETING', 'SALES AND MARKETING'],
        'SUPPLY CHAIN' => ['SUPPLY CHAIN', 'SUPPLY CHAIN INNOVATION', 'LOGISTICS', 'SERVICES & LOGISTICS (LUZON)'],
        'TECHNICAL' => ['TECHNICAL'],
    ];
    return $map[$key] ?? [$key];
}

function ticket_department_key_from_value(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === '') return '';
    $standard = ticket_standard_assigned_departments();
    if (in_array($value, $standard, true)) return $value;
    foreach ($standard as $key) {
        $aliases = ticket_department_aliases_for_key($key);
        foreach ($aliases as $a) {
            if ($value === strtoupper(trim((string) $a))) return $key;
        }
    }
    return $value;
}

function ticket_department_display_name(string $value): string
{
    $raw = trim($value);
    if ($raw === '') return '';

    $key = ticket_department_key_from_value($raw);
    if ($key === 'HR') {
        return 'HR';
    }

    return $raw;
}

function ticket_company_aliases(string $company): array
{
    $company = trim($company);
    if ($company === '') return [];
    if (strpos($company, '@') === 0) return [$company];

    $key = strtoupper(trim($company));
    if ($key === 'FARMEX') $company = 'Farmex Corp';
    if ($key === 'FARMEX CORP') $company = 'Farmex Corp';

    $aliases = [$company];
    $map = [
        'LAPC' => ['LAPC', 'Leads Agricultural products corporation - LAPC', 'Leads Animal Health - LAH', 'LEADS Animal Health - LAH'],
        'GPCI' => ['GPCI', 'GPSCI', 'Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI'],
        'PCC' => ['PCC', 'Primestocks Chemical Corporation - PCC', 'FARMASEE'],
        'MHC' => ['MHC', 'Malveda Holdings Corporation - MHC'],
        'FARMEX CORP' => ['Farmex Corp', 'FARMEX', 'FARMEX CORP'],
        'LTC' => ['LTC', 'Leads Tech Corporation - LTC'],
        'MPDC' => ['MPDC', 'Malveda Properties & Development Corporation - MPDC'],
        'LINGAP' => ['LINGAP', 'LINGAP LEADS FOUNDATION - Lingap'],
    ];
    if ($key === 'FARMEX') $key = 'FARMEX CORP';
    if (isset($map[$key])) {
        $aliases = array_merge($aliases, $map[$key]);
    }
    return array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($v) { return $v !== ''; })));
}

function ticket_is_valid_company(string $company): bool
{
    $company = ticket_normalize_company($company);
    $map = ticket_company_group_map();
    return array_key_exists($company, $map);
}

function ticket_is_valid_group_for_company(string $company, string $group): bool
{
    $company = ticket_normalize_company($company);
    $group = trim($group);
    $map = ticket_company_group_map();
    if (!array_key_exists($company, $map)) return false;
    $allowed = ticket_company_allowed_groups($company);
    if (count($allowed) === 0) return false;
    if (in_array($group, $allowed, true)) return true;
    $group = ticket_department_key_from_value($group);
    return in_array($group, $allowed, true);
}

function ticket_assignee_email_overrides(): array
{
    return [
        '@leadsagri.com' => [
            'IT' => 'matthew22@leadsagri.com',
        ],
    ];
}

function ticket_notification_department_email_map(): array
{
    return [
        'FARMASEE' => [
            '_default' => ['ecommercefarmasee@farmasee.ph'],
        ],
        'FARMEX' => [
            '_default' => ['inquiries@leads-farmex.com'],
        ],
        'LAPC' => [
            'ADMIN' => ['admin@leadsagri.com'],
            'HR' => ['hr@leadsagri.com'],
        ],
        'LINGAP' => [
            '_default' => ['partnership@lingapleads.org' , 'info@lingapleads.org'],
        ],
        'LAV' => [
            '_default' => ['all@leadsav.com'],
        ],
        'MPDC' => [
            '_default' => ['all@malvedaproperties.com'],
        ],

        



       
    ];
}

function ticket_notification_company_key(string $company): string
{
    $company = ticket_normalize_company($company);
    if ($company === '@leadsagri.com') {
        return 'LAPC';
    }
    if ($company === '@malvedaholdings.com') {
        return 'MHC';
    }
    if ($company === '@malvedaproperties.com') {
        return 'MPDC';
    }
    if ($company === '@gpsci.net') {
        return 'GPCI';
    }
    if ($company === '@leads-farmex.com') {
        return 'FARMEX';
    }
    if ($company === '@leadsav.com') {
        return 'LAV';
    }
    if ($company === '@farmasee.ph') {
        return 'FARMASEE';
    }
    if ($company === '@primestocks.ph') {
        return 'PCC';
    }
    if ($company === '@leadstech-corp.com') {
        return 'LTC';
    }
    if ($company === '@lingapleads.org') {
        return 'LINGAP';
    }
    return strtoupper(trim($company));
}

function ticket_assignee_notification_emails(mysqli $conn, array $assignedUserIds, string $company, string $group, int $excludeUserId = 0): array
{
    $company = ticket_normalize_company($company);
    $companyKey = ticket_notification_company_key($company);
    $groupKey = ticket_department_key_from_value($group);
    $excludeUserId = (int) $excludeUserId;

    $overrideMap = ticket_notification_department_email_map();
    $overrideEmails = $overrideMap[$companyKey][$groupKey] ?? ($overrideMap[$companyKey]['_default'] ?? []);
    if (!is_array($overrideEmails)) {
        $overrideEmails = [];
    }
    $overrideEmails = array_values(array_unique(array_filter(array_map(static function ($email) {
        return strtolower(trim((string) $email));
    }, $overrideEmails), static function ($email) {
        return $email !== '';
    })));
    if (count($overrideEmails) > 0) {
        return $overrideEmails;
    }

    $emails = [];
    foreach ($assignedUserIds as $notifyUserId) {
        $notifyUserId = (int) $notifyUserId;
        if ($notifyUserId <= 0) continue;
        if ($excludeUserId > 0 && $notifyUserId === $excludeUserId) continue;
        $assigneeContact = notif_user_contact($conn, $notifyUserId);
        $assigneeEmail = strtolower(trim((string) ($assigneeContact['email'] ?? '')));
        if ($assigneeEmail !== '') {
            $emails[] = $assigneeEmail;
        }
    }

    return array_values(array_unique($emails));
}

function ticket_find_department_admin_id(mysqli $conn, array $deptAliases): ?int
{
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $deptAliases)), static function ($v) {
        return is_string($v) && $v !== '';
    })));
    if (count($deptAliases) === 0) return null;

    $placeholders = implode(',', array_fill(0, count($deptAliases), '?'));
    $sql = "SELECT id FROM users WHERE role = 'admin' AND UPPER(department) IN ($placeholders) ORDER BY id ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;

    $types = str_repeat('s', count($deptAliases));
    $params = $deptAliases;
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $p) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || !isset($row['id'])) return null;
    return (int) $row['id'];
}

function ticket_find_assignee_ids(mysqli $conn, string $company, string $group): array
{
    $company = ticket_normalize_company($company);
    $group = trim($group);
    $isLapcCompany = ($company === '@leadsagri.com' || strtoupper($company) === 'LAPC');
    $allowedGroups = ticket_company_allowed_groups($company);
    $groupKey = in_array($group, $allowedGroups, true) ? $group : ticket_department_key_from_value($group);
    $group = $groupKey;
    if ($company === '') return [];
    if ($isLapcCompany && $group === '') return [];
    if ($isLapcCompany && in_array($group, ticket_lapc_departments(), true)) {
        $deptAliases = [$group];
        $standardKey = ticket_department_key_from_value($group);
        if ($standardKey !== '') {
            $deptAliases = array_merge($deptAliases, ticket_department_aliases_for_key($standardKey), [$standardKey]);
        }
    } elseif ($group !== '') {
        $deptAliases = ticket_department_aliases_for_key($group);
    } else {
        $deptAliases = [];
    }
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', $deptAliases), static function ($v) { return is_string($v) && $v !== ''; })));
    if ($isLapcCompany && count($deptAliases) === 0) return [];

    $overrides = ticket_assignee_email_overrides();
    $preferredEmail = '';
    if (isset($overrides[$company]) && isset($overrides[$company][$group])) {
        $preferredEmail = strtolower(trim((string) $overrides[$company][$group]));
    }

    $deptPlaceholders = count($deptAliases) > 0 ? implode(',', array_fill(0, count($deptAliases), '?')) : '';
    $types = '';
    $params = [];
    $ids = [];

    if (strpos($company, '@') === 0) {
        $domain = ltrim(strtolower($company), '@');
        if ($domain === '') {
            if (!$isLapcCompany) return [];
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $companyFilterSql = '';
        $deptFilterSql = count($deptAliases) > 0 ? " AND UPPER(department) IN ($deptPlaceholders)" : '';
        if ($company === '@leadsagri.com') {
            $companyFilterSql = " AND (
                    TRIM(COALESCE(company, '')) = ''
                    OR UPPER(TRIM(COALESCE(company, ''))) IN (
                        'LAPC',
                        'LAPC (@LEADSAGRI.COM)',
                        '@LEADSAGRI.COM',
                        'LEADSAGRI.COM',
                        'LEADS AGRICULTURAL PRODUCTS CORPORATION - LAPC'
                    )
                    OR LOWER(email) LIKE '%@leadsagri.com'
                )";
        }
        $sql = "SELECT id FROM users
                WHERE role IN ('employee', 'admin')
                  AND LOWER(email) LIKE ?
                  $deptFilterSql
                  $companyFilterSql
                ORDER BY " . ($preferredEmail !== '' ? "CASE WHEN LOWER(email) = ? THEN 0 ELSE 1 END, " : "") . "FIELD(role, 'employee', 'admin'), is_verified DESC, id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            if (!$isLapcCompany) return [];
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $types .= 's';
        $params[] = '%@' . $domain;
        if (count($deptAliases) > 0) {
            $types .= str_repeat('s', count($deptAliases));
            $params = array_merge($params, $deptAliases);
        }
        if ($preferredEmail !== '') {
            $types .= 's';
            $params[] = $preferredEmail;
        }
    } else {
        $aliases = ticket_company_aliases($company);
        if (count($aliases) === 0) {
            if (!$isLapcCompany) return [];
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $deptFilterSql = count($deptAliases) > 0 ? " AND UPPER(department) IN ($deptPlaceholders)" : '';
        $sql = "SELECT id FROM users
                WHERE role IN ('employee', 'admin')
                  AND company IN ($placeholders)
                  $deptFilterSql
                ORDER BY " . ($preferredEmail !== '' ? "CASE WHEN LOWER(email) = ? THEN 0 ELSE 1 END, " : "") . "FIELD(role, 'employee', 'admin'), is_verified DESC, id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            if (!$isLapcCompany) return [];
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $types .= str_repeat('s', count($aliases));
        $params = array_merge($params, $aliases);
        if (count($deptAliases) > 0) {
            $types .= str_repeat('s', count($deptAliases));
            $params = array_merge($params, $deptAliases);
        }
        if ($preferredEmail !== '') {
            $types .= 's';
            $params[] = $preferredEmail;
        }
    }

    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $p) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        if (!isset($row['id'])) continue;
        $id = (int) $row['id'];
        if ($id > 0) $ids[] = $id;
    }
    $stmt->close();

    $ids = array_values(array_filter(array_unique($ids), static function ($v) { return (int) $v > 0; }));
    if (count($ids) > 0) return $ids;

    if (!$isLapcCompany) return [];
    $adminId = ticket_find_department_admin_id($conn, $deptAliases);
    if ($adminId) return [$adminId];

    // LAPC fallback: route the ticket to the LAPC IT team when the
    // selected department has no registered assignee yet so submissions
    // are not blocked.
    if (strtoupper(trim($group)) !== 'IT') {
        $itIds = ticket_find_assignee_ids($conn, $company, 'IT');
        if (count($itIds) > 0) return $itIds;
    }

    // Final fallback: any LAPC employee/admin in the same domain.
    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE role IN ('employee', 'admin')
          AND LOWER(email) LIKE '%@leadsagri.com'
        ORDER BY FIELD(role, 'admin', 'employee'), is_verified DESC, id ASC
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row && (int) ($row['id'] ?? 0) > 0) {
            return [(int) $row['id']];
        }
    }

    return [];
}

function ticket_find_assignee_id(mysqli $conn, string $company, string $group): ?int
{
    $ids = ticket_find_assignee_ids($conn, $company, $group);
    if (count($ids) === 0) return null;
    return (int) $ids[0];
}

function ticket_ensure_assignment_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'assigned_group' => "VARCHAR(255) NULL",
        'assigned_user_id' => "INT NULL",
        'assigned_to' => "INT NULL",
    ];
    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM employee_tickets");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) $existing[(string) $row['Field']] = true;
        }
    }
    foreach ($cols as $col => $ddl) {
        if (!isset($existing[$col])) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
        }
    }
}

function ticket_claim_first_handler(mysqli $conn, int $ticketId, int $userId): bool
{
    if ($ticketId <= 0 || $userId <= 0) return false;

    $stmt = $conn->prepare("UPDATE employee_tickets SET assigned_to = ? WHERE id = ? AND assigned_to IS NULL");
    if (!$stmt) return false;

    $stmt->bind_param("ii", $userId, $ticketId);
    $stmt->execute();
    $claimed = $stmt->affected_rows > 0;
    $stmt->close();

    return $claimed;
}

function ticket_string_ends_with(string $haystack, string $needle): bool
{
    if ($needle === '') return true;
    if (strlen($needle) > strlen($haystack)) return false;
    return substr($haystack, -strlen($needle)) === $needle;
}

function ticket_build_user_context(mysqli $conn, int $userId, array $session = []): array
{
    $ctx = [
        'id' => $userId,
        'role' => (string) ($session['role'] ?? ''),
        'department' => (string) ($session['department'] ?? ''),
        'company' => (string) ($session['company'] ?? ''),
        'email' => (string) ($session['email'] ?? ''),
    ];

    if ($userId <= 0) return $ctx;
    if ($ctx['department'] !== '' && $ctx['company'] !== '' && $ctx['email'] !== '') return $ctx;

    $stmt = $conn->prepare("SELECT role, department, company, email FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return $ctx;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) return $ctx;
    if ($ctx['role'] === '') $ctx['role'] = (string) ($row['role'] ?? '');
    if ($ctx['department'] === '') $ctx['department'] = (string) ($row['department'] ?? '');
    if ($ctx['company'] === '') $ctx['company'] = (string) ($row['company'] ?? '');
    if ($ctx['email'] === '') $ctx['email'] = (string) ($row['email'] ?? '');

    return $ctx;
}

function ticket_company_matches_user(string $ticketCompany, string $userCompany, string $userEmail): bool
{
    $ticketCompany = trim($ticketCompany);
    $userCompany = trim($userCompany);
    $userEmail = strtolower(trim($userEmail));

    if ($ticketCompany === '') return false;

    if (strpos($ticketCompany, '@') === 0) {
        $domain = strtolower(ltrim($ticketCompany, '@'));
        return $domain !== '' && $userEmail !== '' && ticket_string_ends_with($userEmail, '@' . $domain);
    }

    $ticketAliases = array_map('strtoupper', ticket_company_aliases($ticketCompany));
    $userAliases = array_map('strtoupper', ticket_company_aliases($userCompany));
    if (count($ticketAliases) === 0 || count($userAliases) === 0) return false;

    foreach ($userAliases as $alias) {
        if (in_array($alias, $ticketAliases, true)) {
            return true;
        }
    }

    return false;
}

function ticket_user_is_handler_candidate(array $ticket, int $userId, array $userContext): bool
{
    $requesterId = isset($ticket['user_id']) ? (int) $ticket['user_id'] : 0;
    $assignedUserId = isset($ticket['assigned_user_id']) ? (int) $ticket['assigned_user_id'] : 0;
    $ticketGroup = ticket_department_key_from_value((string) ($ticket['assigned_group'] ?? ($ticket['assigned_department'] ?? '')));
    $userGroup = ticket_department_key_from_value((string) ($userContext['department'] ?? ''));
    $ticketCompany = (string) ($ticket['assigned_company'] ?? ($ticket['company'] ?? ''));
    $userCompany = (string) ($userContext['company'] ?? '');
    $userEmail = (string) ($userContext['email'] ?? '');

    if ($userId <= 0 || $userId === $requesterId) return false;
    if ($assignedUserId > 0 && $assignedUserId === $userId) return true;
    if ($userGroup === '' && strpos($ticketCompany, '@') === 0) {
        return ticket_company_matches_user($ticketCompany, $userCompany, $userEmail);
    }
    if ($ticketGroup === '' || $userGroup === '' || $ticketGroup !== $userGroup) return false;

    return ticket_company_matches_user($ticketCompany, $userCompany, $userEmail);
}

function ticket_requester_email(array $ticket): string
{
    $candidates = [
        (string) ($ticket['requester_email'] ?? ''),
        (string) ($ticket['creator_email'] ?? ''),
        (string) ($ticket['created_by_email'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $email = strtolower(trim($candidate));
        if ($email !== '') {
            return $email;
        }
    }

    return '';
}

function ticket_user_matches_requester(array $ticket, int $userId, ?array $userContext = null): bool
{
    $requesterId = isset($ticket['user_id']) ? (int) $ticket['user_id'] : 0;

    if ($userId <= 0) {
        return false;
    }
    if ($userId === $requesterId) {
        return true;
    }
    if ($userContext === null) {
        return false;
    }

    $userEmail = strtolower(trim((string) ($userContext['email'] ?? '')));
    $requesterEmail = ticket_requester_email($ticket);

    return $userEmail !== '' && $requesterEmail !== '' && $userEmail === $requesterEmail;
}

function ticket_is_shared_lapc_hr_chat(array $ticket): bool
{
    $ticketCompany = ticket_normalize_company((string) ($ticket['assigned_company'] ?? ($ticket['company'] ?? '')));
    $ticketGroup = ticket_department_key_from_value((string) ($ticket['assigned_group'] ?? ($ticket['assigned_department'] ?? '')));

    return $ticketCompany === '@leadsagri.com' && $ticketGroup === 'HR';
}

function ticket_chat_effective_handler_id(array $ticket): int
{
    $status = trim((string) ($ticket['status'] ?? ''));
    if (strcasecmp($status, 'Open') === 0) {
        return 0;
    }

    $handlerId = isset($ticket['assigned_to']) ? (int) $ticket['assigned_to'] : 0;
    if ($handlerId > 0) {
        return $handlerId;
    }

    $assignedUserId = isset($ticket['assigned_user_id']) ? (int) $ticket['assigned_user_id'] : 0;

    // Once a ticket is already being worked on, the selected assignee becomes the only handler
    // even when legacy rows still have an empty assigned_to value.
    if ($assignedUserId > 0 && strcasecmp($status, 'Open') !== 0) {
        return $assignedUserId;
    }

    return 0;
}

function ticket_chat_apply_effective_handler(array $ticket): array
{
    $effectiveHandlerId = ticket_chat_effective_handler_id($ticket);
    if ($effectiveHandlerId <= 0) {
        $ticket['assigned_to'] = 0;
        $ticket['assigned_to_name'] = '';
        $ticket['assigned_to_email'] = '';
        $ticket['assigned_to_department'] = '';
        return $ticket;
    }

    $ticket['assigned_to'] = $effectiveHandlerId;

    if (trim((string) ($ticket['assigned_to_name'] ?? '')) === '') {
        $ticket['assigned_to_name'] = (string) ($ticket['assignee_name'] ?? '');
    }
    if (trim((string) ($ticket['assigned_to_email'] ?? '')) === '') {
        $ticket['assigned_to_email'] = (string) ($ticket['assignee_email'] ?? '');
    }
    if (trim((string) ($ticket['assigned_to_department'] ?? '')) === '') {
        $ticket['assigned_to_department'] = (string) ($ticket['assignee_department'] ?? '');
    }

    return $ticket;
}

function ticket_user_can_chat(array $ticket, int $userId, ?array $userContext = null): bool
{
    $handlerId = ticket_chat_effective_handler_id($ticket);

    if ($userId <= 0) return false;
    if (ticket_user_matches_requester($ticket, $userId, $userContext)) return true;
    if ($userContext === null) return false;
    if ($handlerId > 0) {
        return $userId === $handlerId;
    }

    return ticket_user_is_handler_candidate($ticket, $userId, $userContext);
}

function ticket_claim_first_handler_on_reply(mysqli $conn, int $ticketId, int $userId): bool
{
    if ($ticketId <= 0 || $userId <= 0) return false;

    $stmt = $conn->prepare("
        UPDATE employee_tickets
        SET assigned_to = ?,
            status = CASE WHEN status = 'Open' THEN 'In Progress' ELSE status END,
            started_at = CASE WHEN started_at IS NULL THEN NOW() ELSE started_at END,
            updated_at = NOW()
        WHERE id = ?
          AND assigned_to IS NULL
    ");
    if (!$stmt) return false;

    $stmt->bind_param("ii", $userId, $ticketId);
    $stmt->execute();
    $claimed = $stmt->affected_rows > 0;
    $stmt->close();

    return $claimed;
}

function ticket_promote_status_on_first_handler_reply(mysqli $conn, int $ticketId, int $senderId): void
{
    if ($ticketId <= 0 || $senderId <= 0) return;

    $stmt = $conn->prepare("
        UPDATE employee_tickets
        SET status = 'In Progress',
            started_at = CASE WHEN started_at IS NULL THEN NOW() ELSE started_at END,
            updated_at = NOW()
        WHERE id = ?
          AND assigned_to = ?
          AND status = 'Open'
    ");
    if (!$stmt) return;

    $stmt->bind_param("ii", $ticketId, $senderId);
    $stmt->execute();
    $stmt->close();
}

function ticket_ensure_chat_tables(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $tblRes = $conn->query("SHOW TABLES LIKE 'ticket_messages'");
    if (!$tblRes || $tblRes->num_rows === 0) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS ticket_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                sender_id INT NOT NULL,
                message TEXT NOT NULL,
                message_group_id VARCHAR(64) NULL,
                attachment_stored_name VARCHAR(255) NULL,
                attachment_original_name VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ticket_id (ticket_id),
                KEY idx_sender_id (sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $cols = [
        'ticket_id' => "INT NOT NULL",
        'sender_id' => "INT NOT NULL",
        'message' => "TEXT NOT NULL",
        'message_group_id' => "VARCHAR(64) NULL",
        'attachment_stored_name' => "VARCHAR(255) NULL",
        'attachment_original_name' => "VARCHAR(255) NULL",
        'is_read' => "TINYINT(1) NOT NULL DEFAULT 0",
        'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM ticket_messages");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) $existing[(string) $row['Field']] = true;
        }
        $res->free();
    }
    foreach ($cols as $col => $ddl) {
        if (!isset($existing[$col])) {
            $conn->query("ALTER TABLE ticket_messages ADD COLUMN $col $ddl");
        }
    }
}

function ticket_chat_attachment_is_image(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function ticket_chat_upload_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
}

function ticket_chat_store_attachment(array $file): array
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'has_file' => false];
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Unable to upload the attachment right now.'];
    }

    $originalName = function_exists('ticket_pdf_sanitize_original_name')
        ? ticket_pdf_sanitize_original_name((string) ($file['name'] ?? ''))
        : basename(str_replace('\\', '/', trim((string) ($file['name'] ?? ''))));
    $tmpPath = trim((string) ($file['tmp_name'] ?? ''));
    $size = (int) ($file['size'] ?? 0);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    $allowedMimes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    if ($originalName === '' || $tmpPath === '' || !in_array($ext, $allowedExtensions, true)) {
        return ['ok' => false, 'error' => 'Please upload only JPG, PNG, PDF, DOC, or DOCX files.'];
    }
    if ($size <= 0 || $size > (10 * 1024 * 1024)) {
        return ['ok' => false, 'error' => 'Chat attachments must be 10 MB or smaller.'];
    }

    if (class_exists('finfo') && is_file($tmpPath)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $allowed = $allowedMimes[$ext] ?? [];
        if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error' => 'Please upload only JPG, PNG, PDF, DOC, or DOCX files.'];
        }
    }

    $uploadDir = ticket_chat_upload_dir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Unable to prepare chat uploads right now.'];
    }
    if (function_exists('ticket_pdf_ensure_upload_guards')) {
        ticket_pdf_ensure_upload_guards();
    }

    $storedName = 'chat_' . time() . '_' . uniqid('', true) . '.' . $ext;
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['ok' => false, 'error' => 'Unable to save the attachment right now.'];
    }
    if ($ext === 'pdf' && function_exists('ticket_pdf_generate_thumbnail')) {
        ticket_pdf_generate_thumbnail($storedName);
    }

    return [
        'ok' => true,
        'has_file' => true,
        'stored_name' => $storedName,
        'original_name' => $originalName,
        'is_image' => ticket_chat_attachment_is_image($storedName),
    ];
}

function ticket_ensure_chat_activity_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'last_chat_at' => "DATETIME NULL",
        'closed_at' => "DATETIME NULL",
        'closed_reason' => "VARCHAR(255) NULL",
    ];
    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM employee_tickets");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) {
                $existing[(string) $row['Field']] = true;
            }
        }
        $res->free();
    }
    foreach ($cols as $col => $ddl) {
        if (!isset($existing[$col])) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
        }
    }
}

function ticket_record_chat_activity(mysqli $conn, int $ticketId): void
{
    if ($ticketId <= 0) return;

    ticket_ensure_chat_tables($conn);
    ticket_ensure_chat_activity_columns($conn);

    $stmt = $conn->prepare("
        UPDATE employee_tickets
        SET last_chat_at = NOW()
        WHERE id = ?
    ");
    if (!$stmt) return;

    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $stmt->close();
}

function ticket_backfill_last_chat_activity(mysqli $conn): int
{
    ticket_ensure_chat_tables($conn);
    ticket_ensure_chat_activity_columns($conn);

    $sql = "
        UPDATE employee_tickets t
        INNER JOIN (
            SELECT ticket_id, MAX(created_at) AS last_message_at
            FROM ticket_messages
            GROUP BY ticket_id
        ) tm ON tm.ticket_id = t.id
        SET t.last_chat_at = tm.last_message_at
        WHERE t.last_chat_at IS NULL
    ";
    $ok = $conn->query($sql);
    if ($ok === false) {
        return 0;
    }

    return (int) $conn->affected_rows;
}

function getLastChatActivity(mysqli $conn, int $ticketId): ?string
{
    if ($ticketId <= 0) {
        return null;
    }

    ticket_ensure_chat_tables($conn);
    ticket_ensure_chat_activity_columns($conn);

    $stmt = $conn->prepare("
        SELECT last_chat_at
        FROM employee_tickets
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $lastChatAt = trim((string) ($row['last_chat_at'] ?? ''));
    if ($lastChatAt !== '') {
        return $lastChatAt;
    }

    $msgStmt = $conn->prepare("
        SELECT MAX(created_at) AS last_chat_at
        FROM ticket_messages
        WHERE ticket_id = ?
        LIMIT 1
    ");
    if (!$msgStmt) {
        return null;
    }
    $msgStmt->bind_param("i", $ticketId);
    $msgStmt->execute();
    $msgRes = $msgStmt->get_result();
    $msgRow = $msgRes ? $msgRes->fetch_assoc() : null;
    $msgStmt->close();

    $lastChatAt = trim((string) ($msgRow['last_chat_at'] ?? ''));
    if ($lastChatAt === '') {
        return null;
    }

    $syncStmt = $conn->prepare("
        UPDATE employee_tickets
        SET last_chat_at = ?
        WHERE id = ?
          AND last_chat_at IS NULL
    ");
    if ($syncStmt) {
        $syncStmt->bind_param("si", $lastChatAt, $ticketId);
        $syncStmt->execute();
        $syncStmt->close();
    }

    return $lastChatAt;
}

function ticket_fetch_auto_close_context(mysqli $conn, int $ticketId): ?array
{
    if ($ticketId <= 0) {
        return null;
    }

    ticket_ensure_chat_activity_columns($conn);
    notif_ensure_requester_identity_columns($conn);

    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.user_id,
            t.subject,
            t.category,
            t.status,
            t.last_chat_at,
            t.assigned_user_id,
            t.assigned_to,
            t.assigned_department,
            t.assigned_group,
            t.assigned_company,
            t.closed_at,
            t.closed_reason,
            COALESCE(NULLIF(TRIM(t.requester_name), ''), requester.name) AS creator_name,
            COALESCE(NULLIF(TRIM(t.requester_email), ''), requester.email) AS creator_email,
            assignee.name AS assignee_name,
            assignee.email AS assignee_email,
            handler.name AS assigned_to_name,
            handler.email AS assigned_to_email
        FROM employee_tickets t
        LEFT JOIN users requester ON requester.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
        LEFT JOIN users handler ON handler.id = t.assigned_to
        WHERE t.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $ticket ?: null;
}

function shouldAutoCloseTicket(mysqli $conn, int $ticketId, int $inactivitySeconds = 7200): bool
{
    if ($ticketId <= 0) {
        return false;
    }

    $lastChatAt = getLastChatActivity($conn, $ticketId);
    if ($lastChatAt === null || $lastChatAt === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM employee_tickets
        WHERE id = ?
          AND status IN ('Open', 'In Progress')
          AND last_chat_at IS NOT NULL
          AND TIMESTAMPDIFF(SECOND, last_chat_at, NOW()) >= ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $ticketId, $inactivitySeconds);
    $stmt->execute();
    $res = $stmt->get_result();
    $shouldClose = $res && $res->fetch_assoc();
    $stmt->close();

    return (bool) $shouldClose;
}

function ticket_auto_close_user_ids(mysqli $conn, array $ticket): array
{
    $ids = [];

    $creatorId = (int) ($ticket['user_id'] ?? 0);
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    $handlerId = (int) ($ticket['assigned_to'] ?? 0);

    if ($creatorId > 0) $ids[] = $creatorId;
    if ($assignedUserId > 0) $ids[] = $assignedUserId;
    if ($handlerId > 0) $ids[] = $handlerId;

    $ids = array_merge($ids, notif_admin_user_ids($conn));

    return notif_unique_user_ids($ids);
}

function ticket_user_email_addresses(mysqli $conn, array $userIds): array
{
    $userIds = notif_unique_user_ids($userIds);
    if (count($userIds) === 0) {
        return [];
    }

    $sql = "
        SELECT DISTINCT LOWER(TRIM(COALESCE(email, ''))) AS email
        FROM users
        WHERE id IN (" . implode(', ', array_map('intval', $userIds)) . ")
          AND TRIM(COALESCE(email, '')) <> ''
    ";
    $res = $conn->query($sql);
    $emails = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return array_values(array_unique($emails));
}

function notifyTicketClosed(mysqli $conn, array $ticket, int $inactivitySeconds = 7200): array
{
    $ticketId = (int) ($ticket['id'] ?? 0);
    if ($ticketId <= 0) {
        return ['inserted' => 0, 'emailed' => 0];
    }

    $hours = max(1, (int) round($inactivitySeconds / 3600));
    $hoursLabel = $hours . ' hour' . ($hours === 1 ? '' : 's');
    $ticketNumber = notif_ticket_number($ticketId);
    $title = 'Ticket Auto-Closed';
    $message = 'Ticket #' . $ticketNumber . ' was automatically closed after ' . $hoursLabel . ' of chat inactivity.';
    $reason = trim((string) ($ticket['closed_reason'] ?? ''));
    if ($reason === '') {
        $reason = 'Automatically closed after ' . $hoursLabel . ' of chat inactivity.';
    }

    $userIds = ticket_auto_close_user_ids($conn, $ticket);
    $inserted = 0;
    foreach ($userIds as $userId) {
        if (notif_insert_system($conn, (int) $userId, $ticketId, $message, 'ticket_closed', 86400, 'close', $title)) {
            $inserted++;
        }
    }

    $lastChatAt = trim((string) ($ticket['last_chat_at'] ?? ''));
    $commonLines = [
        'Ticket ID: #' . $ticketNumber,
        'Current status: Closed',
        'Closure reason: ' . $reason,
    ];
    if ($lastChatAt !== '') {
        $ts = strtotime($lastChatAt);
        if ($ts !== false) {
            $commonLines[] = 'Last chat activity: ' . date('M d, Y h:i A', $ts);
        }
    }
    if (!empty($ticket['subject'])) {
        $commonLines[] = 'Subject: ' . (string) $ticket['subject'];
    }
    if (!empty($ticket['category'])) {
        $commonLines[] = 'Category: ' . (string) $ticket['category'];
    }
    $commonLines = notif_compact_email_lines($commonLines);

    $emailed = 0;
    $usedEmails = [];

    $requesterEmail = strtolower(trim((string) ($ticket['creator_email'] ?? '')));
    if ($requesterEmail !== '') {
        $requesterMail = notif_email_simple($title, $commonLines, 'View Ticket', notif_ticket_link_employee_tickets($ticketId));
        if (notif_email_send([$requesterEmail], $title . ' (#' . $ticketNumber . ')', (string) ($requesterMail['html'] ?? ''), (string) ($requesterMail['text'] ?? ''))) {
            $emailed++;
            $usedEmails[$requesterEmail] = true;
        }
    }

    $assigneeUserIds = [];
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    $handlerId = (int) ($ticket['assigned_to'] ?? 0);
    if ($assignedUserId > 0) $assigneeUserIds[] = $assignedUserId;
    if ($handlerId > 0) $assigneeUserIds[] = $handlerId;

    $assigneeEmails = ticket_user_email_addresses($conn, $assigneeUserIds);
    foreach ([(string) ($ticket['assignee_email'] ?? ''), (string) ($ticket['assigned_to_email'] ?? '')] as $email) {
        $email = strtolower(trim($email));
        if ($email !== '') {
            $assigneeEmails[] = $email;
        }
    }
    $assigneeEmails = array_values(array_filter(array_unique($assigneeEmails), static function ($email) use ($usedEmails) {
        return !isset($usedEmails[$email]);
    }));
    if (count($assigneeEmails) > 0) {
        $assigneeMail = notif_email_simple($title, $commonLines, 'View Task', notif_ticket_link_employee_tasks($ticketId));
        if (notif_email_send($assigneeEmails, $title . ' (#' . $ticketNumber . ')', (string) ($assigneeMail['html'] ?? ''), (string) ($assigneeMail['text'] ?? ''))) {
            $emailed += count($assigneeEmails);
            foreach ($assigneeEmails as $email) {
                $usedEmails[$email] = true;
            }
        }
    }

    $adminEmails = ticket_user_email_addresses($conn, notif_admin_user_ids($conn));
    $adminEmails = array_values(array_filter(array_unique($adminEmails), static function ($email) use ($usedEmails) {
        return !isset($usedEmails[$email]);
    }));
    if (count($adminEmails) > 0) {
        $adminMail = notif_email_simple($title, $commonLines, 'View Ticket', notif_ticket_link_admin($ticketId));
        if (notif_email_send($adminEmails, $title . ' (#' . $ticketNumber . ')', (string) ($adminMail['html'] ?? ''), (string) ($adminMail['text'] ?? ''))) {
            $emailed += count($adminEmails);
        }
    }

    return ['inserted' => $inserted, 'emailed' => $emailed];
}

function autoCloseTicket(mysqli $conn, int $ticketId, int $inactivitySeconds = 7200): ?array
{
    if ($ticketId <= 0) {
        return null;
    }

    ticket_ensure_chat_tables($conn);
    ticket_ensure_chat_activity_columns($conn);

    $ticket = ticket_fetch_auto_close_context($conn, $ticketId);
    if (!$ticket || !shouldAutoCloseTicket($conn, $ticketId, $inactivitySeconds)) {
        return null;
    }

    $oldStatus = trim((string) ($ticket['status'] ?? ''));
    $hours = max(1, (int) round($inactivitySeconds / 3600));
    $reason = 'Automatically closed after ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . ' of chat inactivity.';

    $stmt = $conn->prepare("
        UPDATE employee_tickets
        SET status = 'Closed',
            updated_at = NOW(),
            resolved_at = CASE WHEN resolved_at IS NULL THEN NOW() ELSE resolved_at END,
            closed_at = NOW(),
            closed_reason = ?
        WHERE id = ?
          AND status IN ('Open', 'In Progress')
          AND last_chat_at IS NOT NULL
          AND TIMESTAMPDIFF(SECOND, last_chat_at, NOW()) >= ?
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("sii", $reason, $ticketId, $inactivitySeconds);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$changed) {
        return null;
    }

    $ticket['status'] = 'Closed';
    $ticket['closed_at'] = date('Y-m-d H:i:s');
    $ticket['closed_reason'] = $reason;

    $activity = $conn->prepare("
        INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at)
        VALUES (?, 'status_change', ?, NOW())
    ");
    if ($activity) {
        $description = $reason;
        $activity->bind_param("is", $ticketId, $description);
        $activity->execute();
        $activity->close();
    }

    $notify = notifyTicketClosed($conn, $ticket, $inactivitySeconds);

    return [
        'ticket_id' => $ticketId,
        'old_status' => $oldStatus,
        'new_status' => 'Closed',
        'last_chat_at' => (string) ($ticket['last_chat_at'] ?? ''),
        'notifications_inserted' => (int) ($notify['inserted'] ?? 0),
        'emails_sent' => (int) ($notify['emailed'] ?? 0),
    ];
}

function processAutoCloseInactiveTickets(mysqli $conn, int $inactivitySeconds = 7200): array
{
    ticket_ensure_chat_tables($conn);
    ticket_ensure_chat_activity_columns($conn);
    ticket_backfill_last_chat_activity($conn);

    $stmt = $conn->prepare("
        SELECT id
        FROM employee_tickets
        WHERE status IN ('Open', 'In Progress')
          AND last_chat_at IS NOT NULL
          AND TIMESTAMPDIFF(SECOND, last_chat_at, NOW()) >= ?
        ORDER BY last_chat_at ASC, id ASC
    ");
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("i", $inactivitySeconds);
    $stmt->execute();
    $res = $stmt->get_result();

    $results = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $ticketId = (int) ($row['id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        $result = autoCloseTicket($conn, $ticketId, $inactivitySeconds);
        if ($result !== null) {
            $results[] = $result;
        }
    }
    $stmt->close();

    return $results;
}

function ticket_normalize_company(string $company): string
{
    $company = trim($company);
    if ($company === '') return '';
    if (strpos($company, '@') === 0) return strtolower($company);

    $lookup = strtolower($company);
    $aliases = [
        'lapc' => '@leadsagri.com',
        'lapc (@leadsagri.com)' => '@leadsagri.com',
        'leadsagri.com' => '@leadsagri.com',
        'leads agricultural products corporation - lapc' => '@leadsagri.com',

        'lah' => '@leadsanimalhealth.com',
        'lah (@leadsanimalhealth.com)' => '@leadsanimalhealth.com',
        'leadsanimalhealth.com' => '@leadsanimalhealth.com',
        'leads animal health - lah' => '@leadsanimalhealth.com',

        'leh' => '@leads-eh.com',
        'leh (@leads-eh.com)' => '@leads-eh.com',
        'leads-eh.com' => '@leads-eh.com',

        'gpsci' => '@gpsci.net',
        'gpsci (@gpsci.net)' => '@gpsci.net',
        'gpci' => '@gpsci.net',
        'gpsci.net' => '@gpsci.net',

        'farmasee' => '@farmasee.ph',
        'farmasee (@farmasee.ph)' => '@farmasee.ph',
        'farmasee.ph' => '@farmasee.ph',

        'farmex' => '@leads-farmex.com',
        'farmex (@leads-farmex.com)' => '@leads-farmex.com',
        'farmex corp' => '@leads-farmex.com',
        'leads-farmex.com' => '@leads-farmex.com',

        'mhc' => '@malvedaholdings.com',
        'mhc (@malvedaholdings.com)' => '@malvedaholdings.com',
        'malvedaholdings.com' => '@malvedaholdings.com',
        'malveda holdings corporation - mhc' => '@malvedaholdings.com',

        'mpdc' => '@malvedaproperties.com',
        'mpdc (@malvedaproperties.com)' => '@malvedaproperties.com',
        'malvedaproperties.com' => '@malvedaproperties.com',
        'malveda properties & development corporation - mpdc' => '@malvedaproperties.com',

        'ltc' => '@leadstech-corp.com',
        'ltc (@leadstech-corp.com)' => '@leadstech-corp.com',
        'leadstech-corp.com' => '@leadstech-corp.com',
        'leads tech corporation - ltc' => '@leadstech-corp.com',

        'lingap' => '@lingapleads.org',
        'lingap (@lingapleads.org)' => '@lingapleads.org',
        'lingapleads.org' => '@lingapleads.org',
        'lingap leads foundation - lingap' => '@lingapleads.org',

        'lav' => '@leadsav.com',
        'lav (@leadsav.com)' => '@leadsav.com',
        'leadsav.com' => '@leadsav.com',

        'pcc' => '@primestocks.ph',
        'pcc (@primestocks.ph)' => '@primestocks.ph',
        'primestocks.ph' => '@primestocks.ph',
    ];

    if (isset($aliases[$lookup])) {
        return $aliases[$lookup];
    }

    return $company;
}

function ticket_company_display_map(): array
{
    return [
        '@farmasee.ph' => 'FARMASEE',
        'farmasee.ph' => 'FARMASEE',
        'farmasee' => 'FARMASEE',
        '@gmail.com' => 'Gmail',
        'gmail.com' => 'Gmail',
        '@gpsci.net' => 'GPSCI',
        'gpsci.net' => 'GPSCI',
        'gpsci' => 'GPSCI',
        'gpci' => 'GPSCI',
        '@leads-eh.com' => 'LEH',
        'leads-eh.com' => 'LEH',
        'leh' => 'LEH',
        '@leads-farmex.com' => 'FARMEX',
        'leads-farmex.com' => 'FARMEX',
        'farmex' => 'FARMEX',
        'farmex corp' => 'FARMEX',
        '@leadsagri.com' => 'LAPC',
        'leadsagri.com' => 'LAPC',
        'lapc' => 'LAPC',
        '@leadsanimalhealth.com' => 'LAH',
        'leadsanimalhealth.com' => 'LAH',
        'lah' => 'LAH',
        '@leadsav.com' => 'LAV',
        'leadsav.com' => 'LAV',
        'lav' => 'LAV',
        '@leadstech-corp.com' => 'LTC',
        'leadstech-corp.com' => 'LTC',
        'ltc' => 'LTC',
        '@lingapleads.org' => 'LINGAP',
        'lingapleads.org' => 'LINGAP',
        'lingap' => 'LINGAP',
        '@malvedaholdings.com' => 'MHC',
        'malvedaholdings.com' => 'MHC',
        'mhc' => 'MHC',
        '@malvedaproperties.com' => 'MPDC',
        'malvedaproperties.com' => 'MPDC',
        'mpdc' => 'MPDC',
        '@primestocks.ph' => 'PCC',
        'primestocks.ph' => 'PCC',
        'pcc' => 'PCC',
    ];
}

function ticket_company_display_name(string $company): string
{
    $company = trim($company);
    if ($company === '') return '';

    $map = ticket_company_display_map();
    $key = strtolower($company);
    if (isset($map[$key])) {
        return $map[$key];
    }

    $normalized = strtolower(ticket_normalize_company($company));
    if (isset($map[$normalized])) {
        return $map[$normalized];
    }

    return $company;
}

function ticket_ensure_priority_escalation_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'updated_at' => "DATETIME NULL DEFAULT CURRENT_TIMESTAMP",
        'auto_escalated_high_at' => "DATETIME NULL",
        'auto_escalated_critical_at' => "DATETIME NULL",
        'auto_escalated_high_notified_at' => "DATETIME NULL",
        'auto_escalated_high_emailed_at' => "DATETIME NULL",
        'auto_escalated_critical_notified_at' => "DATETIME NULL",
        'auto_escalated_critical_emailed_at' => "DATETIME NULL",
    ];
    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM employee_tickets");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) $existing[(string) $row['Field']] = true;
        }
        $res->free();
    }
    foreach ($cols as $col => $ddl) {
        if (!isset($existing[$col])) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
        }
    }
}

function ticket_escalation_reference_sql(string $tableAlias = ''): string
{
    $prefix = trim($tableAlias);
    if ($prefix !== '') {
        $prefix .= '.';
    }
    return $prefix . "created_at";
}

function ticket_priority_escalation_log(string $event, array $context = []): void
{
    $parts = [];
    foreach ($context as $key => $value) {
        if (is_array($value)) {
            $value = implode(',', array_map(static function ($item) {
                return trim((string) $item);
            }, $value));
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $parts[] = $key . '=' . $value;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $event;
    if (count($parts) > 0) {
        $line .= ' | ' . implode(' | ', $parts);
    }

    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/priority_escalation.log';
    if (@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log($line);
    }
}

function ticket_priority_escalation_stage_config(string $targetPriority): ?array
{
    $targetPriority = trim($targetPriority);
    if ($targetPriority === 'High') {
        return [
            'target_priority' => 'High',
            'from_priorities' => ['', 'Low'],
            'days' => 3,
            'escalated_at_column' => 'auto_escalated_high_at',
            'notified_at_column' => 'auto_escalated_high_notified_at',
            'emailed_at_column' => 'auto_escalated_high_emailed_at',
        ];
    }
    if ($targetPriority === 'Critical') {
        return [
            'target_priority' => 'Critical',
            'from_priorities' => ['High'],
            'days' => 3,
            'escalated_at_column' => 'auto_escalated_critical_at',
            'notified_at_column' => 'auto_escalated_critical_notified_at',
            'emailed_at_column' => 'auto_escalated_critical_emailed_at',
        ];
    }
    return null;
}

function ticket_priority_escalation_due_at(array $ticket, string $targetPriority): ?string
{
    $config = ticket_priority_escalation_stage_config($targetPriority);
    if ($config === null) {
        return null;
    }

    $reference = '';
    if ($targetPriority === 'Critical') {
        $reference = trim((string) ($ticket['auto_escalated_high_at'] ?? ''));
        if ($reference === '') {
            $reference = ticket_priority_escalation_due_at($ticket, 'High') ?? '';
        }
    } else {
        $reference = trim((string) ($ticket['reference_time'] ?? ''));
    }
    if ($reference === '') {
        return null;
    }

    $referenceTs = strtotime($reference);
    if ($referenceTs === false) {
        return null;
    }

    $dueTs = strtotime('+' . (int) $config['days'] . ' days', $referenceTs);
    if ($dueTs === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $dueTs);
}

function ticket_priority_escalation_notification_time(mysqli $conn, int $ticketId, string $targetPriority): ?string
{
    $message = 'Ticket #' . notif_ticket_number($ticketId) . ' priority has been escalated to ' . $targetPriority . '. Immediate attention is required.';
    $title = 'Ticket Priority Escalated';
    $actionType = 'update';
    $type = 'priority_escalated';

    $stmt = $conn->prepare("
        SELECT MIN(created_at) AS created_at
        FROM notifications
        WHERE ticket_id = ?
          AND type = ?
          AND message = ?
          AND COALESCE(action_type, '') = ?
          AND COALESCE(title, '') = ?
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("issss", $ticketId, $type, $message, $actionType, $title);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $createdAt = trim((string) ($row['created_at'] ?? ''));
    return $createdAt !== '' ? $createdAt : null;
}

function ticket_load_priority_escalation_ticket(mysqli $conn, int $ticketId): ?array
{
    ticket_ensure_priority_escalation_columns($conn);

    $sql = "
        SELECT
            t.id,
            t.user_id,
            t.subject,
            t.priority,
            t.status,
            t.created_at,
            t.updated_at,
            t.started_at,
            t.assigned_user_id,
            t.assigned_department,
            t.assigned_group,
            t.assigned_company,
            t.auto_escalated_high_at,
            t.auto_escalated_high_notified_at,
            t.auto_escalated_high_emailed_at,
            t.auto_escalated_critical_at,
            t.auto_escalated_critical_notified_at,
            t.auto_escalated_critical_emailed_at,
            assignee.department AS assignee_department,
            " . ticket_escalation_reference_sql('t') . " AS reference_time
        FROM employee_tickets t
        LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
        WHERE t.id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ticket = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $ticket ?: null;
}

function ticket_priority_escalation_recipient_data(mysqli $conn, array $ticket): array
{
    $ticketId = (int) ($ticket['id'] ?? 0);
    $requesterId = (int) ($ticket['user_id'] ?? 0);
    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    $group = trim((string) ($ticket['assigned_group'] ?? ''));
    if ($group === '') {
        $group = trim((string) ($ticket['assigned_department'] ?? ''));
    }
    $company = trim((string) ($ticket['assigned_company'] ?? ''));

    $departmentUserIds = ticket_find_assignee_ids($conn, $company, $group);
    if ($assignedUserId > 0) {
        $departmentUserIds[] = $assignedUserId;
    }
    $departmentUserIds = notif_unique_user_ids($departmentUserIds);
    $departmentEmails = ticket_user_email_addresses($conn, $departmentUserIds);

    $deptAliases = [];
    $groupKey = ticket_department_key_from_value($group);
    if ($groupKey !== '') {
        $deptAliases = array_merge($deptAliases, [$groupKey], ticket_department_aliases_for_key($groupKey));
    } elseif ($group !== '') {
        $deptAliases[] = strtoupper($group);
    }
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', array_map('trim', $deptAliases)))));
    $departmentAdminId = count($deptAliases) > 0 ? ticket_find_department_admin_id($conn, $deptAliases) : null;
    $departmentAdminEmails = $departmentAdminId ? ticket_user_email_addresses($conn, [(int) $departmentAdminId]) : [];

    $notifyUserIds = [];
    if ($requesterId > 0) {
        $notifyUserIds[] = $requesterId;
    }
    $notifyUserIds = array_merge($notifyUserIds, $departmentUserIds);
    if ($departmentAdminId) {
        $notifyUserIds[] = (int) $departmentAdminId;
    }
    $notifyUserIds = array_merge($notifyUserIds, notif_admin_user_ids($conn));
    $notifyUserIds = notif_unique_user_ids($notifyUserIds);

    $emailRecipients = array_values(array_unique(array_merge($departmentEmails, $departmentAdminEmails)));

    ticket_priority_escalation_log('recipient_resolution', [
        'ticket_id' => $ticketId,
        'department_user_ids' => $departmentUserIds,
        'department_admin_id' => $departmentAdminId ? (string) $departmentAdminId : '',
        'email_recipients' => $emailRecipients,
    ]);

    return [
        'user_ids' => $notifyUserIds,
        'email_recipients' => $emailRecipients,
    ];
}

function ticket_mark_priority_escalation_delivery(mysqli $conn, int $ticketId, string $targetPriority, bool $notificationsSent, bool $emailsSent, ?string $notificationTime = null): void
{
    $config = ticket_priority_escalation_stage_config($targetPriority);
    if ($config === null || $ticketId <= 0 || (!$notificationsSent && !$emailsSent)) {
        return;
    }

    $sets = [];
    if ($notificationsSent) {
        $quotedNotificationTime = $notificationTime !== null ? ("'" . $conn->real_escape_string($notificationTime) . "'") : 'NOW()';
        $sets[] = $config['notified_at_column'] . " = COALESCE(" . $config['notified_at_column'] . ", " . $quotedNotificationTime . ")";
    }
    if ($emailsSent) {
        $sets[] = $config['emailed_at_column'] . " = COALESCE(" . $config['emailed_at_column'] . ", NOW())";
    }
    if (count($sets) === 0) {
        return;
    }

    $sql = "UPDATE employee_tickets SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $stmt->close();
}

function ticket_process_priority_escalation_stage(mysqli $conn, int $ticketId, string $targetPriority, string $runId = ''): ?array
{
    $ticket = ticket_load_priority_escalation_ticket($conn, $ticketId);
    if (!$ticket) return null;

    $config = ticket_priority_escalation_stage_config($targetPriority);
    if ($config === null) return null;

    $status = trim((string) ($ticket['status'] ?? ''));
    if ($status === 'Resolved' || $status === 'Closed') return null;

    $dueAt = ticket_priority_escalation_due_at($ticket, $targetPriority);
    if ($dueAt === null) return null;

    $dueTs = strtotime($dueAt);
    if ($dueTs === false || $dueTs > time()) return null;

    $currentPriority = trim((string) ($ticket['priority'] ?? ''));
    $escalatedAtColumn = $config['escalated_at_column'];
    $notifiedAtColumn = $config['notified_at_column'];
    $emailedAtColumn = $config['emailed_at_column'];
    $displayOldPriority = $currentPriority === '' ? 'Low' : $currentPriority;

    $escalated = false;
    if (empty($ticket[$escalatedAtColumn]) && in_array($currentPriority, $config['from_priorities'], true)) {
        $priorityCondition = $targetPriority === 'High'
            ? "(priority = 'Low' OR priority = '' OR priority IS NULL)"
            : "priority = 'High'";
        $sql = "UPDATE employee_tickets
                SET priority = ?, $escalatedAtColumn = ?
                WHERE id = ?
                  AND status NOT IN ('Resolved', 'Closed')
                  AND $priorityCondition
                  AND $escalatedAtColumn IS NULL";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssi", $targetPriority, $dueAt, $ticketId);
            $stmt->execute();
            $escalated = $stmt->affected_rows > 0;
            $stmt->close();
        }

        if ($escalated) {
            $activity = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'priority_escalated', ?, ?)");
            if ($activity) {
                $desc = 'Priority automatically escalated from ' . $displayOldPriority . ' to ' . $targetPriority . '.';
                $activity->bind_param("iss", $ticketId, $desc, $dueAt);
                $activity->execute();
                $activity->close();
            }

            ticket_priority_escalation_log('ticket_escalated', [
                'run_id' => $runId,
                'ticket_id' => $ticketId,
                'from_priority' => $displayOldPriority,
                'to_priority' => $targetPriority,
                'reference_time' => (string) ($ticket['reference_time'] ?? ''),
                'effective_due_at' => $dueAt,
            ]);
        }
    }

    $stageTicket = ticket_load_priority_escalation_ticket($conn, $ticketId);
    if (!$stageTicket || empty($stageTicket[$escalatedAtColumn])) {
        return $escalated ? [
            'ticket_id' => $ticketId,
            'old_priority' => $displayOldPriority,
            'new_priority' => $targetPriority,
            'escalated' => true,
            'notifications_inserted' => 0,
            'emails_sent' => 0,
            'due_at' => $dueAt,
        ] : null;
    }

    $existingNotificationTime = ticket_priority_escalation_notification_time($conn, $ticketId, $targetPriority);
    if ($existingNotificationTime !== null && empty($stageTicket[$notifiedAtColumn])) {
        ticket_mark_priority_escalation_delivery($conn, $ticketId, $targetPriority, true, false, $existingNotificationTime);
        $stageTicket[$notifiedAtColumn] = $existingNotificationTime;
    }

    $shouldSendNotifications = empty($stageTicket[$notifiedAtColumn]);
    $shouldSendEmails = empty($stageTicket[$emailedAtColumn]);
    if (!$shouldSendNotifications && !$shouldSendEmails && !$escalated) {
        return null;
    }

    $freshTicket = notif_ticket_data($conn, $ticketId);
    if (!$freshTicket) return null;

    $recipients = ticket_priority_escalation_recipient_data($conn, $freshTicket);
    $dispatch = sendPriorityEscalationNotification(
        $conn,
        $freshTicket,
        $shouldSendNotifications ? ($recipients['user_ids'] ?? []) : [],
        $targetPriority,
        $displayOldPriority,
        [
            'email_recipients' => $shouldSendEmails ? ($recipients['email_recipients'] ?? []) : [],
            'email_cta_url' => notif_ticket_link_employee_tasks($ticketId),
            'notification_created_at' => $dueAt,
        ]
    );

    $notificationsSent = ((int) ($dispatch['notified'] ?? 0)) > 0 || ($shouldSendNotifications && count($recipients['user_ids'] ?? []) === 0);
    $emailsSent = ((int) ($dispatch['emailed'] ?? 0)) > 0 || ($shouldSendEmails && count($recipients['email_recipients'] ?? []) === 0);
    ticket_mark_priority_escalation_delivery($conn, $ticketId, $targetPriority, $notificationsSent, $emailsSent, $dueAt);

    ticket_priority_escalation_log('notification_dispatch', [
        'run_id' => $runId,
        'ticket_id' => $ticketId,
        'to_priority' => $targetPriority,
        'notifications_inserted' => (string) ((int) ($dispatch['inserted'] ?? 0)),
        'emails_sent' => (string) ((int) ($dispatch['emailed'] ?? 0)),
        'email_recipients' => $recipients['email_recipients'] ?? [],
        'effective_due_at' => $dueAt,
    ]);

    return [
        'ticket_id' => $ticketId,
        'old_priority' => $displayOldPriority,
        'new_priority' => $targetPriority,
        'escalated' => $escalated,
        'notifications_inserted' => (int) ($dispatch['inserted'] ?? 0),
        'emails_sent' => (int) ($dispatch['emailed'] ?? 0),
        'due_at' => $dueAt,
    ];
}

function ticket_target_priority_for_row(array $ticket): string
{
    $status = trim((string) ($ticket['status'] ?? ''));
    if ($status === 'Resolved' || $status === 'Closed') return trim((string) ($ticket['priority'] ?? 'Low'));
    $currentPriority = trim((string) ($ticket['priority'] ?? 'Low'));

    $highDueAt = ticket_priority_escalation_due_at($ticket, 'High');
    $criticalDueAt = ticket_priority_escalation_due_at($ticket, 'Critical');
    $now = time();

    if ($currentPriority === 'Critical') {
        return 'Critical';
    }
    if ($currentPriority === 'High') {
        return ($criticalDueAt !== null && strtotime($criticalDueAt) !== false && strtotime($criticalDueAt) <= $now) ? 'Critical' : 'High';
    }
    if ($highDueAt !== null && strtotime($highDueAt) !== false && strtotime($highDueAt) <= $now) {
        return 'High';
    }
    return 'Low';
}

function escalateTicketPriority(mysqli $conn, int $ticketId): ?array
{
    $runId = 'manual-' . $ticketId . '-' . date('YmdHis');
    $highResult = ticket_process_priority_escalation_stage($conn, $ticketId, 'High', $runId);
    $criticalResult = ticket_process_priority_escalation_stage($conn, $ticketId, 'Critical', $runId);
    return $criticalResult ?? $highResult;
}

function ticket_apply_sla_priority(mysqli $conn, bool $force = false): array
{
    if (!$force && PHP_SAPI !== 'cli') {
        return [
            'ok' => true,
            'skipped' => true,
            'reason' => 'web_request',
            'processed' => 0,
            'escalated' => 0,
            'notifications_inserted' => 0,
            'emails_sent' => 0,
            'tickets' => [],
        ];
    }

    ticket_ensure_priority_escalation_columns($conn);
    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);

    $runId = 'run-' . date('YmdHis') . '-' . substr(md5((string) microtime(true)), 0, 8);
    $report = [
        'ok' => true,
        'skipped' => false,
        'run_id' => $runId,
        'processed' => 0,
        'escalated' => 0,
        'notifications_inserted' => 0,
        'emails_sent' => 0,
        'tickets' => [],
    ];

    $sql = "
        SELECT id
        FROM employee_tickets
        WHERE status NOT IN ('Resolved', 'Closed')
          AND (
                (
                    COALESCE(priority, '') IN ('', 'Low', 'High')
                    AND " . ticket_escalation_reference_sql() . " IS NOT NULL
                    AND (
                        (DATE_ADD(" . ticket_escalation_reference_sql() . ", INTERVAL 3 DAY) <= NOW() AND auto_escalated_high_at IS NULL)
                        OR (DATE_ADD(COALESCE(auto_escalated_high_at, DATE_ADD(" . ticket_escalation_reference_sql() . ", INTERVAL 3 DAY)), INTERVAL 3 DAY) <= NOW() AND (priority = 'High' OR auto_escalated_high_at IS NOT NULL) AND auto_escalated_critical_at IS NULL)
                    )
                )
                OR (auto_escalated_high_at IS NOT NULL AND (auto_escalated_high_notified_at IS NULL OR auto_escalated_high_emailed_at IS NULL))
                OR (auto_escalated_critical_at IS NOT NULL AND (auto_escalated_critical_notified_at IS NULL OR auto_escalated_critical_emailed_at IS NULL))
          )
        ORDER BY " . ticket_escalation_reference_sql() . " ASC, id ASC
    ";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $ticketId = (int) ($row['id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }

        $report['processed']++;
        foreach (['High', 'Critical'] as $targetPriority) {
            $result = ticket_process_priority_escalation_stage($conn, $ticketId, $targetPriority, $runId);
            if ($result === null) {
                continue;
            }

            if (!empty($result['escalated'])) {
                $report['escalated']++;
            }
            $report['notifications_inserted'] += (int) ($result['notifications_inserted'] ?? 0);
            $report['emails_sent'] += (int) ($result['emails_sent'] ?? 0);
            $report['tickets'][] = $result;
        }
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    ticket_priority_escalation_log('run_complete', [
        'run_id' => $runId,
        'processed' => (string) $report['processed'],
        'escalated' => (string) $report['escalated'],
        'notifications_inserted' => (string) $report['notifications_inserted'],
        'emails_sent' => (string) $report['emails_sent'],
    ]);

    return $report;
}
