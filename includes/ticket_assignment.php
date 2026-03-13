<?php

function ticket_company_group_map(): array
{
    return [
        'LAPC' => [
            'Banana Farm Operations',
            'Seed Production',
            'Supply Chain',
            'Supply Chain Innovation',
            'Admin & Legal',
            'Diagnostics / Lingap',
            'E-Commerce',
            'Finance and Accounting',
            'Human Resource and Transformation',
            'Institutional Sales',
            'Digital Agri Solutions and Innovations',
            'Marketing',
            'New Business Segment',
            'Technical',
            'Executive',
            'Management',
        ],
        'GPCI' => [
            'Accounting',
            'Sales',
        ],
        'PCC' => [
            'Management',
            'Admin',
            'Finance and Accounting',
            'Maintenance',
            'Production',
            'Quality Control',
            'Supply Chain',
            'Technical',
        ],
        'MHC' => [
            'Management',
            'Admin & Legal',
            'E-Commerce',
            'Executive',
            'Finance and Accounting',
            'Institutional Sales',
            'IT',
            'Marketing',
        ],
        'Farmex Corp' => [
            'Management',
            'Finance and Admin',
            'Logistics',
            'Sales and Marketing',
            'Special Project',
            'Technical',
            'Business Development',
        ],
        'LTC' => [
            'Admin',
            'Finance and Accounting',
            'Logistics',
            'Marketing',
            'Sales',
            'Services & Logistics (Luzon)',
        ],
        'MPDC' => [],
        'LINGAP' => [],
    ];
}

function ticket_company_aliases(string $company): array
{
    $company = trim($company);
    $key = strtoupper($company);
    if ($key === 'FARMEX CORP') $key = 'FARMEX CORP';
    if ($key === 'FARMASEE') $key = 'PCC';

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
    if (isset($map[$key])) {
        $aliases = array_merge($aliases, $map[$key]);
    }
    $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($v) { return $v !== ''; })));
    return $aliases;
}

function ticket_is_valid_company(string $company): bool
{
    $map = ticket_company_group_map();
    return array_key_exists(trim($company), $map);
}

function ticket_is_valid_group_for_company(string $company, string $group): bool
{
    $company = trim($company);
    $group = trim($group);
    $map = ticket_company_group_map();
    if (!array_key_exists($company, $map)) return false;
    if (!is_array($map[$company]) || count($map[$company]) === 0) return false;
    return in_array($group, $map[$company], true);
}

function ticket_find_assignee_id(mysqli $conn, string $company, string $group): ?int
{
    $company = trim($company);
    $group = trim($group);
    if ($company === '' || $group === '') return null;
    $aliases = ticket_company_aliases($company);
    if (count($aliases) === 0) return null;

    $placeholders = implode(',', array_fill(0, count($aliases), '?'));
    $sql = "SELECT id FROM users WHERE role = 'employee' AND department = ? AND company IN ($placeholders) ORDER BY id ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;

    $types = 's' . str_repeat('s', count($aliases));
    $params = array_merge([$group], $aliases);
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
    $cols = [
        'assigned_group' => "VARCHAR(255) NULL",
        'assigned_user_id' => "INT NULL",
    ];
    foreach ($cols as $col => $ddl) {
        $colRes = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE '$col'");
        if (!$colRes || $colRes->num_rows === 0) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
        }
    }
}

function ticket_normalize_company(string $company): string
{
    $company = trim($company);
    $map = ticket_company_group_map();
    if (array_key_exists($company, $map)) return $company;
    $u = strtoupper($company);
    if ($u === 'FARMEX') return 'Farmex Corp';
    if ($u === 'FARMEX CORP') return 'Farmex Corp';
    return $company;
}

