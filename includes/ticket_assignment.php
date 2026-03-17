<?php

function ticket_company_group_map(): array
{
    $standard = ticket_standard_assigned_departments();
    return [
        'LAPC' => $standard,
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
        '@leadsagri.com' => $standard,
        '@leadsanimalhealth.com' => $standard,
        '@leadsav.com' => $standard,
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

function ticket_department_aliases_for_key(string $key): array
{
    $key = strtoupper(trim($key));
    $map = [
        'ACCOUNTING' => ['ACCOUNTING', 'FINANCE AND ACCOUNTING', 'FINANCE & ACCOUNTING'],
        'ADMIN' => ['ADMIN', 'ADMINISTRATION', 'ADMIN & LEGAL', 'FINANCE AND ADMIN', 'FINANCE & ADMIN'],
        'BIDDING' => ['BIDDING'],
        'E-COMM' => ['E-COMM', 'E-COMMERCE', 'E-COMMERCE', 'E COMMERCE', 'ECOMM'],
        'HR' => ['HR', 'HUMAN RESOURCE', 'HUMAN RESOURCES', 'HUMAN RESOURCE AND TRANSFORMATION'],
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
    $group = ticket_department_key_from_value($group);
    $map = ticket_company_group_map();
    if (!array_key_exists($company, $map)) return false;
    if (!is_array($map[$company]) || count($map[$company]) === 0) return false;
    return in_array($group, $map[$company], true);
}

function ticket_find_assignee_id(mysqli $conn, string $company, string $group): ?int
{
    $company = ticket_normalize_company($company);
    $groupKey = ticket_department_key_from_value($group);
    $group = $groupKey;
    if ($company === '' || $group === '') return null;
    $deptAliases = ticket_department_aliases_for_key($group);
    $deptAliases = array_values(array_unique(array_filter(array_map('strtoupper', $deptAliases), static function ($v) { return is_string($v) && $v !== ''; })));
    if (count($deptAliases) === 0) return null;
    $deptPlaceholders = implode(',', array_fill(0, count($deptAliases), '?'));
    $types = str_repeat('s', count($deptAliases));
    $params = $deptAliases;

    if (strpos($company, '@') === 0) {
        $sql = "SELECT id FROM users WHERE role = 'employee' AND UPPER(department) IN ($deptPlaceholders) AND LOWER(email) LIKE ? ORDER BY id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $types .= 's';
        $params[] = '%' . strtolower($company);
    } else {
        $aliases = ticket_company_aliases($company);
        if (count($aliases) === 0) return null;
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $sql = "SELECT id FROM users WHERE role = 'employee' AND UPPER(department) IN ($deptPlaceholders) AND company IN ($placeholders) ORDER BY id ASC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $types .= str_repeat('s', count($aliases));
        $params = array_merge($params, $aliases);
    }

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

function ticket_ensure_assignment_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'assigned_group' => "VARCHAR(255) NULL",
        'assigned_user_id' => "INT NULL",
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
    if ($u === 'FARMEX') return 'Farmex Corp';
    if ($u === 'FARMEX CORP') return 'Farmex Corp';
    return $company;
}

function ticket_apply_sla_priority(mysqli $conn): void
{
    $sql = "
        UPDATE employee_tickets
        SET priority = CASE
            WHEN TIMESTAMPDIFF(DAY, created_at, NOW()) >= 7 THEN 'Critical'
            WHEN TIMESTAMPDIFF(DAY, created_at, NOW()) >= 4 THEN 'High'
            ELSE 'Low'
        END
        WHERE created_at IS NOT NULL
          AND status NOT IN ('Resolved', 'Closed')
          AND (
            priority IS NULL OR priority = '' OR priority <> CASE
                WHEN TIMESTAMPDIFF(DAY, created_at, NOW()) >= 7 THEN 'Critical'
                WHEN TIMESTAMPDIFF(DAY, created_at, NOW()) >= 4 THEN 'High'
                ELSE 'Low'
            END
          )
    ";
    $conn->query($sql);
}
