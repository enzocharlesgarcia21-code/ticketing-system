<?php

require_once __DIR__ . '/notification_service.php';

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
    $allowedGroups = ticket_company_allowed_groups($company);
    $groupKey = in_array($group, $allowedGroups, true) ? $group : ticket_department_key_from_value($group);
    $group = $groupKey;
    if ($company === '' || $group === '') return [];
    if (($company === '@leadsagri.com' || strtoupper($company) === 'LAPC') && in_array($group, ticket_lapc_departments(), true)) {
        $deptAliases = [$group];
        $standardKey = ticket_department_key_from_value($group);
        if ($standardKey !== '') {
            $deptAliases = array_merge($deptAliases, ticket_department_aliases_for_key($standardKey), [$standardKey]);
        }
    } else {
        $deptAliases = ticket_department_aliases_for_key($group);
    }
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', $deptAliases), static function ($v) { return is_string($v) && $v !== ''; })));
    if (count($deptAliases) === 0) return [];

    $overrides = ticket_assignee_email_overrides();
    $preferredEmail = '';
    if (isset($overrides[$company]) && isset($overrides[$company][$group])) {
        $preferredEmail = strtolower(trim((string) $overrides[$company][$group]));
    }

    $deptPlaceholders = implode(',', array_fill(0, count($deptAliases), '?'));
    $types = str_repeat('s', count($deptAliases));
    $params = $deptAliases;
    $ids = [];

    if (strpos($company, '@') === 0) {
        $domain = ltrim(strtolower($company), '@');
        if ($domain === '') {
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $companyFilterSql = '';
        if ($company === '@leadsagri.com') {
            $companyFilterSql = " AND (TRIM(COALESCE(company, '')) = '' OR UPPER(TRIM(COALESCE(company, ''))) IN ('LAPC', '@LEADSAGRI.COM', 'LEADS AGRICULTURAL PRODUCTS CORPORATION - LAPC'))";
        }
        $sql = "SELECT id FROM users
                WHERE role = 'employee'
                  AND UPPER(department) IN ($deptPlaceholders)
                  AND LOWER(email) LIKE ?
                  $companyFilterSql
                ORDER BY " . ($preferredEmail !== '' ? "CASE WHEN LOWER(email) = ? THEN 0 ELSE 1 END, " : "") . "is_verified DESC, id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $types .= 's';
        $params[] = '%@' . $domain;
        if ($preferredEmail !== '') {
            $types .= 's';
            $params[] = $preferredEmail;
        }
    } else {
        $aliases = ticket_company_aliases($company);
        if (count($aliases) === 0) {
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $sql = "SELECT id FROM users
                WHERE role = 'employee'
                  AND UPPER(department) IN ($deptPlaceholders)
                  AND company IN ($placeholders)
                ORDER BY " . ($preferredEmail !== '' ? "CASE WHEN LOWER(email) = ? THEN 0 ELSE 1 END, " : "") . "is_verified DESC, id ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $adminId = ticket_find_department_admin_id($conn, $deptAliases);
            return $adminId ? [$adminId] : [];
        }
        $types .= str_repeat('s', count($aliases));
        $params = array_merge($params, $aliases);
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

    $adminId = ticket_find_department_admin_id($conn, $deptAliases);
    return $adminId ? [$adminId] : [];
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

function ticket_user_can_chat(array $ticket, int $userId, ?array $userContext = null): bool
{
    $requesterId = isset($ticket['user_id']) ? (int) $ticket['user_id'] : 0;
    $handlerId = isset($ticket['assigned_to']) ? (int) $ticket['assigned_to'] : 0;

    if ($userId <= 0) return false;
    if ($userId === $requesterId) return true;
    if ($handlerId > 0) return $userId === $handlerId;
    if ($userContext === null) return false;

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

function ticket_normalize_company(string $company): string
{
    $company = trim($company);
    if ($company === '') return '';
    if (strpos($company, '@') === 0) return strtolower($company);
    $u = strtoupper($company);
    if ($u === 'FARMEX') return 'FARMEX';
    if ($u === 'FARMEX CORP') return 'FARMEX';
    if ($u === 'FARMASEE') return 'FARMASEE';
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

function ticket_target_priority_for_row(array $ticket): string
{
    $status = trim((string) ($ticket['status'] ?? ''));
    if ($status === 'Resolved' || $status === 'Closed') return trim((string) ($ticket['priority'] ?? 'Low'));
    $currentPriority = trim((string) ($ticket['priority'] ?? 'Low'));

    $reference = (string) ($ticket['reference_time'] ?? ($ticket['updated_at'] ?? ($ticket['started_at'] ?? ($ticket['created_at'] ?? ''))));
    if ($reference === '') return $currentPriority;

    $elapsed = strtotime('now') - strtotime($reference);
    $elapsedDays = (int) floor($elapsed / 86400);
    if ($currentPriority === 'High') {
        return $elapsedDays >= 6 ? 'Critical' : 'High';
    }
    if ($currentPriority === 'Critical') {
        return 'Critical';
    }
    if ($elapsedDays >= 3) {
        return 'High';
    }
    return 'Low';
}

function escalateTicketPriority(mysqli $conn, int $ticketId): ?array
{
    ticket_ensure_priority_escalation_columns($conn);
    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);

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
            t.auto_escalated_critical_at,
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

    if (!$ticket) return null;

    $currentPriority = trim((string) ($ticket['priority'] ?? ''));
    $status = trim((string) ($ticket['status'] ?? ''));
    if ($status === 'Resolved' || $status === 'Closed') return null;

    $targetPriority = ticket_target_priority_for_row($ticket);
    if (!in_array($targetPriority, ['High', 'Critical'], true)) return null;
    if (!in_array($currentPriority, ['Low', 'High'], true)) return null;
    if ($currentPriority === $targetPriority) return null;
    if ($currentPriority === 'Low' && $targetPriority !== 'High') return null;
    if ($currentPriority === 'High' && $targetPriority !== 'Critical') return null;

    $alreadyEscalated = $targetPriority === 'High'
        ? !empty($ticket['auto_escalated_high_at'])
        : !empty($ticket['auto_escalated_critical_at']);
    if ($alreadyEscalated) return null;

    $updateSql = $targetPriority === 'High'
        ? "UPDATE employee_tickets SET priority = 'High', auto_escalated_high_at = NOW(), updated_at = NOW() WHERE id = ? AND status NOT IN ('Resolved', 'Closed') AND (priority = 'Low' OR priority = '' OR priority IS NULL) AND auto_escalated_high_at IS NULL"
        : "UPDATE employee_tickets SET priority = 'Critical', auto_escalated_critical_at = NOW(), updated_at = NOW() WHERE id = ? AND status NOT IN ('Resolved', 'Closed') AND priority = 'High' AND auto_escalated_critical_at IS NULL";
    $update = $conn->prepare($updateSql);
    if (!$update) return null;
    $update->bind_param("i", $ticketId);
    $update->execute();
    $changed = $update->affected_rows > 0;
    $update->close();
    if (!$changed) return null;

    $freshTicket = notif_ticket_data($conn, $ticketId);
    if (!$freshTicket) return null;
    $users = getUsersToNotify($conn, $freshTicket);
    $result = sendPriorityEscalationNotification($conn, $freshTicket, $users, $targetPriority, $currentPriority);

    $activity = $conn->prepare("INSERT INTO ticket_activity (ticket_id, activity_type, description, created_at) VALUES (?, 'priority_escalated', ?, NOW())");
    if ($activity) {
        $desc = 'Priority automatically escalated from ' . $currentPriority . ' to ' . $targetPriority . '.';
        $activity->bind_param("is", $ticketId, $desc);
        $activity->execute();
        $activity->close();
    }

    return [
        'ticket_id' => $ticketId,
        'old_priority' => $currentPriority,
        'new_priority' => $targetPriority,
        'notified_users' => count($users),
        'notifications_inserted' => (int) ($result['inserted'] ?? 0),
        'emails_sent' => (int) ($result['emailed'] ?? 0),
    ];
}

function ticket_apply_sla_priority(mysqli $conn): void
{
    ticket_ensure_priority_escalation_columns($conn);

    $sql = "
        SELECT id
        FROM employee_tickets
        WHERE status NOT IN ('Resolved', 'Closed')
          AND COALESCE(priority, '') IN ('', 'Low', 'High')
          AND " . ticket_escalation_reference_sql() . " IS NOT NULL
          AND (
                (TIMESTAMPDIFF(DAY, " . ticket_escalation_reference_sql() . ", NOW()) >= 3 AND auto_escalated_high_at IS NULL)
             OR (TIMESTAMPDIFF(DAY, " . ticket_escalation_reference_sql() . ", NOW()) >= 6 AND auto_escalated_critical_at IS NULL)
          )
        ORDER BY id ASC
    ";
    $res = $conn->query($sql);
    while ($res && ($row = $res->fetch_assoc())) {
        $ticketId = (int) ($row['id'] ?? 0);
        if ($ticketId > 0) {
            escalateTicketPriority($conn, $ticketId);
        }
    }
}
