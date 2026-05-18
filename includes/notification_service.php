<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

function notif_ensure_requester_identity_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'requester_name' => "VARCHAR(255) NULL",
        'requester_email' => "VARCHAR(255) NULL",
    ];

    foreach ($columns as $column => $ddl) {
        $hasColumn = false;
        $res = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE '$column'");
        if ($res && $res->fetch_assoc()) {
            $hasColumn = true;
        }
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        if (!$hasColumn) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $column $ddl");
        }
    }
}

function notif_ensure_action_type_column(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $hasColumn = false;
    $res = $conn->query("SHOW COLUMNS FROM notifications LIKE 'action_type'");
    if ($res && $res->fetch_assoc()) {
        $hasColumn = true;
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    if (!$hasColumn) {
        $conn->query("ALTER TABLE notifications ADD COLUMN action_type VARCHAR(20) NULL AFTER type");
    }
}

function notif_ensure_title_column(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $hasColumn = false;
    $res = $conn->query("SHOW COLUMNS FROM notifications LIKE 'title'");
    if ($res && $res->fetch_assoc()) {
        $hasColumn = true;
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    if (!$hasColumn) {
        $conn->query("ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NULL AFTER ticket_id");
    }
}

function notif_ensure_priority_escalation_notification_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [
        'auto_escalated_high_at' => "DATETIME NULL",
        'auto_escalated_critical_at' => "DATETIME NULL",
        'auto_escalated_high_notified_at' => "DATETIME NULL",
        'auto_escalated_critical_notified_at' => "DATETIME NULL",
    ];

    foreach ($columns as $column => $ddl) {
        $hasColumn = false;
        $res = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE '$column'");
        if ($res && $res->fetch_assoc()) {
            $hasColumn = true;
        }
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        if (!$hasColumn) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $column $ddl");
        }
    }
}

function notif_action_type_from_legacy_type(string $type): string
{
    $type = trim($type);
    switch ($type) {
        case 'dept_assigned':
        case 'new_ticket':
            return 'assign';
        case 'reassigned':
            return 'reassign';
        case 'ticket_closed':
            return 'close';
        case 'status_update':
        case 'note_added':
        case 'conference_booking':
            return 'update';
        default:
            return '';
    }
}

function notif_normalize_action_type(string $actionType, string $legacyType = ''): string
{
    $actionType = strtolower(trim($actionType));
    if (in_array($actionType, ['assign', 'reassign', 'update', 'close'], true)) {
        return $actionType;
    }
    return notif_action_type_from_legacy_type($legacyType);
}

function notif_ticket_number(int $ticketId): string
{
    return str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
}

function notif_company_display_map(): array
{
    return [
        '@farmasee.ph' => 'FARMASEE',
        'farmasee.ph' => 'FARMASEE',
        '@gmail.com' => 'Gmail',
        'gmail.com' => 'Gmail',
        '@gpsci.net' => 'GPSCI',
        'gpsci.net' => 'GPSCI',
        '@leads-eh.com' => 'LEH',
        'leads-eh.com' => 'LEH',
        '@leads-farmex.com' => 'FARMEX',
        'leads-farmex.com' => 'FARMEX',
        '@leadsagri.com' => 'LAPC',
        'leadsagri.com' => 'LAPC',
        '@leadsanimalhealth.com' => 'LAH',
        'leadsanimalhealth.com' => 'LAH',
        '@leadsav.com' => 'LAV',
        'leadsav.com' => 'LAV',
        '@leadstech-corp.com' => 'LTC',
        'leadstech-corp.com' => 'LTC',
        '@lingapleads.org' => 'LINGAP',
        'lingapleads.org' => 'LINGAP',
        '@malvedaholdings.com' => 'MHC',
        'malvedaholdings.com' => 'MHC',
        '@malvedaproperties.com' => 'MPDC',
        'malvedaproperties.com' => 'MPDC',
        '@primestocks.ph' => 'PCC',
        'primestocks.ph' => 'PCC',
    ];
}

function notif_replace_company_domains(string $message): string
{
    $message = trim($message);
    if ($message === '') return '';
    $map = notif_company_display_map();
    // Replace longer tokens first to avoid partial overlaps.
    uksort($map, static function ($a, $b) {
        return strlen((string) $b) <=> strlen((string) $a);
    });
    return str_ireplace(array_keys($map), array_values($map), $message);
}

function notif_company_requires_department(string $company): bool
{
    $company = strtolower(trim($company));
    return in_array($company, ['@leadsagri.com', 'leadsagri.com', 'lapc'], true);
}

function notif_assignment_target_label(string $company, string $department = '', string $fallback = 'the selected recipient'): string
{
    $company = trim($company);
    $department = trim($department);
    $companyLabel = trim(notif_replace_company_domains($company));
    if ($companyLabel !== '' && strpos($companyLabel, '@') === 0) {
        $companyLabel = ltrim($companyLabel, '@');
    }

    if (notif_company_requires_department($company)) {
        if ($department !== '' && $companyLabel !== '') {
            return $department . ' at ' . $companyLabel;
        }
        if ($department !== '') {
            return $department;
        }
    }

    if ($companyLabel !== '') {
        return $companyLabel;
    }

    if ($department !== '') {
        return $department;
    }

    return trim($fallback) !== '' ? trim($fallback) : 'the selected recipient';
}

function notif_assignment_email_label(string $company, string $department = '', string $fallback = 'Unassigned'): string
{
    $company = trim($company);
    $department = trim($department);
    $companyLabel = trim(notif_replace_company_domains($company));
    if ($companyLabel !== '' && strpos($companyLabel, '@') === 0) {
        $companyLabel = ltrim($companyLabel, '@');
    }

    if (notif_company_requires_department($company)) {
        if ($department !== '' && $companyLabel !== '') {
            return $department . ' Department - ' . $companyLabel;
        }
        if ($department !== '') {
            return $department . ' Department';
        }
    }

    if ($companyLabel !== '') {
        return $companyLabel;
    }

    if ($department !== '') {
        return $department;
    }

    return trim($fallback) !== '' ? trim($fallback) : 'Unassigned';
}

function notif_base_url(): string
{
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function notif_ticket_link_admin(int $ticketId): string
{
    return notif_base_url() . '/ticketing/admin/all_tickets.php?ticket_id=' . urlencode((string) $ticketId);
}

function notif_ticket_link_employee_tasks(int $ticketId): string
{
    return notif_base_url() . '/ticketing/employee/my_task.php?ticket_id=' . urlencode((string) $ticketId);
}

function notif_ticket_link_employee_chat(int $ticketId): string
{
    return notif_base_url() . '/ticketing/employee/my_task.php?ticket_id=' . urlencode((string) $ticketId) . '&chat=1';
}

function notif_ticket_link_employee_tickets(int $ticketId): string
{
    return notif_base_url() . '/ticketing/employee/my_tickets.php?ticket_id=' . urlencode((string) $ticketId);
}

function notif_user_contact(mysqli $conn, int $userId): array
{
    $out = ['id' => $userId, 'name' => '', 'email' => '', 'role' => '', 'department' => '', 'company' => ''];
    if ($userId <= 0) return $out;
    $stmt = $conn->prepare("SELECT name, email, role, department, company FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return $out;
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return $out;
    $out['name'] = (string) ($row['name'] ?? '');
    $out['email'] = (string) ($row['email'] ?? '');
    $out['role'] = (string) ($row['role'] ?? '');
    $out['department'] = (string) ($row['department'] ?? '');
    $out['company'] = (string) ($row['company'] ?? '');
    return $out;
}

function notif_user_id_by_email(mysqli $conn, string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) ($row['id'] ?? 0) : 0;
}

function notif_requester_user_id(mysqli $conn, array $ticket): int
{
    // For shared-account (sales) tickets, requester_email identifies the actual requester
    $requesterEmail = trim((string) ($ticket['requester_email'] ?? ''));
    if ($requesterEmail !== '') {
        $found = notif_user_id_by_email($conn, $requesterEmail);
        if ($found > 0) {
            return $found;
        }
    }

    // For regular tickets the ticket's own user_id IS the requester — use it directly
    $userId = (int) ($ticket['user_id'] ?? 0);
    if ($userId > 0) {
        return $userId;
    }

    // Last resort: look up by creator_email (computed field from notif_ticket_data)
    $creatorEmail = trim((string) ($ticket['creator_email'] ?? ''));
    if ($creatorEmail !== '' && strcasecmp($creatorEmail, $requesterEmail) !== 0) {
        $found = notif_user_id_by_email($conn, $creatorEmail);
        if ($found > 0) {
            return $found;
        }
    }

    return 0;
}

/**
 * Returns ALL user IDs that should receive requester-side notifications for a ticket.
 * For shared/sales-account tickets this includes both the submitting account (user_id)
 * and the actual requester found via requester_email, so neither misses the notification.
 */
function notif_requester_user_ids(mysqli $conn, array $ticket): array
{
    $ids = [];

    // Always include the account that submitted the ticket
    $ownerId = (int) ($ticket['user_id'] ?? 0);
    if ($ownerId > 0) {
        $ids[] = $ownerId;
    }

    // For shared/sales-account tickets, also notify the actual requester by email
    $requesterEmail = trim((string) ($ticket['requester_email'] ?? ''));
    if ($requesterEmail !== '') {
        $found = notif_user_id_by_email($conn, $requesterEmail);
        if ($found > 0 && $found !== $ownerId) {
            $ids[] = $found;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function notif_admin_user_ids(mysqli $conn): array
{
    $ids = [];
    $res = $conn->query("SELECT id FROM users WHERE role = 'admin'");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $ids[] = (int) ($r['id'] ?? 0);
        }
    }
    return array_values(array_filter(array_unique($ids), static function ($v) { return (int) $v > 0; }));
}

function notif_insert_system(mysqli $conn, int $userId, int $ticketId, string $message, string $type = 'ticket', int $dedupeSeconds = 10, string $actionType = '', string $title = ''): bool
{
    $userId = (int) $userId;
    $ticketId = (int) $ticketId;
    if ($userId <= 0 || $ticketId <= 0 || trim($message) === '') return false;
    $type = trim($type) !== '' ? trim($type) : 'ticket';
    $actionType = notif_normalize_action_type($actionType, $type);
    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);
    $title = trim($title);

    $existsStmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ? AND ticket_id = ? AND type = ? AND message = ? AND COALESCE(action_type, '') = ?
          AND COALESCE(title, '') = ?
          AND created_at >= (NOW() - INTERVAL ? SECOND)
        LIMIT 1
    ");
    if ($existsStmt) {
        $existsStmt->bind_param("iissssi", $userId, $ticketId, $type, $message, $actionType, $title, $dedupeSeconds);
        $existsStmt->execute();
        $existsRes = $existsStmt->get_result();
        $exists = $existsRes && $existsRes->fetch_assoc();
        $existsStmt->close();
        if ($exists) return true;
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log('Notification insert prepare failed (full) | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $conn->error);
        // Fallback: title/action_type columns may not exist on this server yet — use basic INSERT
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            error_log('Notification insert prepare failed (basic fallback) | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $conn->error);
            return false;
        }
        $stmt->bind_param("iiss", $userId, $ticketId, $message, $type);
        $ok = $stmt->execute();
        if (!$ok) {
            error_log('Notification insert failed (basic fallback) | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $stmt->error);
        }
        $stmt->close();
        return (bool) $ok;
    }
    $stmt->bind_param("iissss", $userId, $ticketId, $title, $message, $type, $actionType);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Notification insert failed | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $stmt->error);
    }
    $stmt->close();
    return (bool) $ok;
}

function notif_insert_system_at(mysqli $conn, int $userId, int $ticketId, string $message, string $createdAt, string $type = 'ticket', string $actionType = '', string $title = ''): bool
{
    $userId = (int) $userId;
    $ticketId = (int) $ticketId;
    $createdAt = trim($createdAt);
    if ($userId <= 0 || $ticketId <= 0 || trim($message) === '' || $createdAt === '') {
        return false;
    }

    $type = trim($type) !== '' ? trim($type) : 'ticket';
    $actionType = notif_normalize_action_type($actionType, $type);
    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);
    $title = trim($title);

    $existsStmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ? AND ticket_id = ? AND type = ? AND message = ? AND COALESCE(action_type, '') = ?
          AND COALESCE(title, '') = ?
        LIMIT 1
    ");
    if ($existsStmt) {
        $existsStmt->bind_param("iissss", $userId, $ticketId, $type, $message, $actionType, $title);
        $existsStmt->execute();
        $existsRes = $existsStmt->get_result();
        $exists = $existsRes && $existsRes->fetch_assoc();
        $existsStmt->close();
        if ($exists) return true;
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log('Notification insert prepare failed | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $conn->error);
        return false;
    }
    $stmt->bind_param("iisssss", $userId, $ticketId, $title, $message, $type, $actionType, $createdAt);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Notification insert failed | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $stmt->error);
    }
    $stmt->close();
    return (bool) $ok;
}

function notif_has_system_record(mysqli $conn, int $userId, int $ticketId, string $message, string $type = 'ticket', string $actionType = '', string $title = ''): bool
{
    $userId = (int) $userId;
    $ticketId = (int) $ticketId;
    if ($userId <= 0 || $ticketId <= 0 || trim($message) === '') {
        return false;
    }

    $type = trim($type) !== '' ? trim($type) : 'ticket';
    $actionType = notif_normalize_action_type($actionType, $type);
    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);
    $title = trim($title);

    $stmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ? AND ticket_id = ? AND type = ? AND message = ? AND COALESCE(action_type, '') = ?
          AND COALESCE(title, '') = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iissss", $userId, $ticketId, $type, $message, $actionType, $title);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();
    return (bool) $exists;
}

function notif_has_priority_escalation_record(mysqli $conn, int $userId, int $ticketId, string $targetPriority): bool
{
    $userId = (int) $userId;
    $ticketId = (int) $ticketId;
    $targetPriority = trim($targetPriority);
    if ($userId <= 0 || $ticketId <= 0 || $targetPriority === '') {
        return false;
    }

    notif_ensure_action_type_column($conn);
    $type = 'priority_escalated';
    $actionType = 'update';
    $ticketNeedle = '%Ticket #' . notif_ticket_number($ticketId) . '%';
    $priorityNeedle = '%to ' . $targetPriority . '%';
    $stmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ?
          AND ticket_id = ?
          AND type = ?
          AND message LIKE ?
          AND message LIKE ?
          AND COALESCE(action_type, '') = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iissss", $userId, $ticketId, $type, $ticketNeedle, $priorityNeedle, $actionType);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();
    return (bool) $exists;
}

function notif_insert_admins(mysqli $conn, int $ticketId, string $message, string $type = 'ticket', string $actionType = '', string $title = ''): void
{
    $ids = notif_admin_user_ids($conn);
    foreach ($ids as $id) {
        notif_insert_system($conn, (int) $id, $ticketId, $message, $type, 10, $actionType, $title);
    }
}

function notif_unique_user_ids(array $ids): array
{
    return array_values(array_filter(array_unique(array_map('intval', $ids)), static function ($id) {
        return $id > 0;
    }));
}

function notif_department_user_ids(mysqli $conn, string $department): array
{
    $department = trim($department);
    if ($department === '') return [];

    $ids = [];
    $stmt = $conn->prepare("SELECT id FROM users WHERE UPPER(TRIM(COALESCE(department, ''))) = UPPER(TRIM(?))");
    if (!$stmt) return [];
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    $stmt->close();
    return notif_unique_user_ids($ids);
}

function notif_ticket_data(mysqli $conn, int $ticketId): ?array
{
    notif_ensure_requester_identity_columns($conn);

    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.user_id,
            t.subject,
            t.category,
            t.description,
            t.attachment,
            t.priority,
            t.status,
            t.created_at,
            t.updated_at,
            t.started_at,
            t.assigned_user_id,
            t.assigned_department,
            t.assigned_group,
            t.assigned_company,
            t.requester_name,
            t.requester_email,
            COALESCE(NULLIF(TRIM(t.requester_name), ''), creator.name) AS creator_name,
            COALESCE(NULLIF(TRIM(t.requester_email), ''), creator.email) AS creator_email,
            assignee.name AS assignee_name,
            assignee.email AS assignee_email,
            assignee.department AS assignee_department
        FROM employee_tickets t
        LEFT JOIN users creator ON creator.id = t.user_id
        LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
        WHERE t.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        // Fallback 1: requester_name / requester_email / assigned_group / assigned_company
        // may not exist yet. Try without optional columns.
        error_log('notif_ticket_data: primary prepare failed (ticketId=' . $ticketId . ') err=' . $conn->error . ' — retrying without requester/group columns');
        $stmt = $conn->prepare("
            SELECT
                t.id,
                t.user_id,
                t.subject,
                t.category,
                t.description,
                t.attachment,
                t.priority,
                t.status,
                t.created_at,
                t.updated_at,
                t.assigned_user_id,
                t.assigned_department,
                NULL AS assigned_group,
                NULL AS assigned_company,
                NULL AS requester_name,
                NULL AS requester_email,
                creator.name AS creator_name,
                creator.email AS creator_email,
                assignee.name AS assignee_name,
                assignee.email AS assignee_email,
                assignee.department AS assignee_department
            FROM employee_tickets t
            LEFT JOIN users creator ON creator.id = t.user_id
            LEFT JOIN users assignee ON assignee.id = t.assigned_user_id
            WHERE t.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            // Fallback 2: ultra-minimal — only columns present in every schema version.
            error_log('notif_ticket_data: fallback1 prepare also failed (ticketId=' . $ticketId . ') err=' . $conn->error . ' — trying ultra-minimal');
            $stmt = $conn->prepare("
                SELECT
                    t.id,
                    t.user_id,
                    t.subject,
                    t.priority,
                    t.status,
                    t.created_at,
                    NULL AS category,
                    NULL AS description,
                    NULL AS attachment,
                    NULL AS updated_at,
                    NULL AS assigned_user_id,
                    t.department AS assigned_department,
                    NULL AS assigned_group,
                    NULL AS assigned_company,
                    NULL AS requester_name,
                    NULL AS requester_email,
                    creator.name AS creator_name,
                    creator.email AS creator_email,
                    NULL AS assignee_name,
                    NULL AS assignee_email,
                    NULL AS assignee_department
                FROM employee_tickets t
                LEFT JOIN users creator ON creator.id = t.user_id
                WHERE t.id = ?
                LIMIT 1
            ");
            if (!$stmt) {
                error_log('notif_ticket_data: all fallbacks failed (ticketId=' . $ticketId . ') err=' . $conn->error);
                return null;
            }
        }
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function notif_priority_escalation_old_priority(mysqli $conn, int $ticketId, string $targetPriority): string
{
    $ticketId = (int) $ticketId;
    $targetPriority = trim($targetPriority);
    if ($ticketId <= 0 || $targetPriority === '') {
        return '';
    }

    $tableExists = false;
    $tblRes = $conn->query("SHOW TABLES LIKE 'ticket_activity'");
    if ($tblRes && $tblRes->fetch_assoc()) {
        $tableExists = true;
    }
    if ($tblRes instanceof mysqli_result) {
        $tblRes->free();
    }

    if ($tableExists) {
        $stmt = $conn->prepare("
            SELECT description
            FROM ticket_activity
            WHERE ticket_id = ?
              AND activity_type = 'priority_escalated'
              AND description LIKE ?
            ORDER BY created_at ASC
            LIMIT 1
        ");
        if ($stmt) {
            $needle = '% to ' . $targetPriority . '%';
            $stmt->bind_param("is", $ticketId, $needle);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            $description = (string) ($row['description'] ?? '');
            if (preg_match('/from\s+(critical|high|medium|low)\s+to\s+' . preg_quote($targetPriority, '/') . '\b/i', $description, $matches)) {
                return ucfirst(strtolower((string) ($matches[1] ?? '')));
            }
        }
    }

    return strcasecmp($targetPriority, 'Critical') === 0 ? 'High' : 'Medium';
}

function notif_priority_escalation_recipient_ids(mysqli $conn, array $ticket): array
{
    $ids = [];
    $ids = array_merge($ids, notif_requester_user_ids($conn, $ticket));

    $assignedUserId = (int) ($ticket['assigned_user_id'] ?? 0);
    if ($assignedUserId > 0) {
        $ids[] = $assignedUserId;
    }

    $department = trim((string) ($ticket['assigned_group'] ?? ''));
    if ($department === '') {
        $department = trim((string) ($ticket['assigned_department'] ?? ''));
    }
    if ($department !== '') {
        $ids = array_merge($ids, notif_department_user_ids($conn, $department));
    }

    $ids = array_merge($ids, notif_admin_user_ids($conn));
    return notif_unique_user_ids($ids);
}

function notif_backfill_priority_escalation_notifications(mysqli $conn): int
{
    notif_ensure_priority_escalation_notification_columns($conn);
    notif_ensure_requester_identity_columns($conn);

    $sql = "
        SELECT
            t.id,
            t.user_id,
            t.priority,
            t.status,
            t.created_at,
            t.assigned_user_id,
            t.assigned_department,
            t.assigned_group,
            t.requester_name,
            t.requester_email,
            t.auto_escalated_high_at,
            t.auto_escalated_critical_at
        FROM employee_tickets t
        WHERE t.auto_escalated_high_at IS NOT NULL
           OR t.auto_escalated_critical_at IS NOT NULL
           OR (LOWER(TRIM(COALESCE(t.priority, ''))) IN ('high', 'critical') AND DATE_ADD(t.created_at, INTERVAL 3 DAY) <= NOW())
           OR (LOWER(TRIM(COALESCE(t.priority, ''))) = 'critical' AND DATE_ADD(t.created_at, INTERVAL 6 DAY) <= NOW())
        ORDER BY COALESCE(t.auto_escalated_critical_at, t.auto_escalated_high_at) DESC
        LIMIT 300
    ";
    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }

    $inserted = 0;
    while ($ticket = $res->fetch_assoc()) {
        $ticketId = (int) ($ticket['id'] ?? 0);
        if ($ticketId <= 0) {
            continue;
        }
        $createdAt = trim((string) ($ticket['created_at'] ?? ''));
        $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
        $priorityKey = strtolower(trim((string) ($ticket['priority'] ?? '')));
        $highAt = trim((string) ($ticket['auto_escalated_high_at'] ?? ''));
        $criticalAt = trim((string) ($ticket['auto_escalated_critical_at'] ?? ''));
        if ($highAt === '' && in_array($priorityKey, ['high', 'critical'], true) && $createdTs !== false) {
            $dueTs = strtotime('+3 days', $createdTs);
            if ($dueTs !== false && $dueTs <= time()) {
                $highAt = date('Y-m-d H:i:s', $dueTs);
            }
        }
        if ($criticalAt === '' && $priorityKey === 'critical' && $createdTs !== false) {
            $dueTs = strtotime('+6 days', $createdTs);
            if ($dueTs !== false && $dueTs <= time()) {
                $criticalAt = date('Y-m-d H:i:s', $dueTs);
            }
        }
        $stages = [
            ['target' => 'High', 'at' => $highAt],
            ['target' => 'Critical', 'at' => $criticalAt],
        ];
        $recipientIds = notif_priority_escalation_recipient_ids($conn, $ticket);
        foreach ($stages as $stage) {
            $targetPriority = (string) $stage['target'];
            $createdAt = (string) $stage['at'];
            if ($createdAt === '') {
                continue;
            }
            $oldPriority = notif_priority_escalation_old_priority($conn, $ticketId, $targetPriority);
            $message = 'Ticket #' . notif_ticket_number($ticketId) . ' was escalated from ' . $oldPriority . ' to ' . $targetPriority . ' due to SLA delay. Please review and take action.';
            foreach ($recipientIds as $userId) {
                if (notif_has_priority_escalation_record($conn, (int) $userId, $ticketId, $targetPriority)) {
                    continue;
                }
                if (notif_insert_system_at($conn, (int) $userId, $ticketId, $message, $createdAt, 'priority_escalated', 'update', 'Priority Escalation')) {
                    $inserted++;
                }
            }
        }
    }
    $res->free();
    return $inserted;
}

function notif_ticket_email_attachments(mysqli $conn, int $ticketId, string $legacyAttachment = ''): array
{
    $ticketId = (int) $ticketId;
    if ($ticketId <= 0) {
        return [];
    }

    $attachments = [];
    $seen = [];

    $attStmt = $conn->prepare("SELECT stored_name, original_name FROM ticket_attachments WHERE ticket_id = ? ORDER BY id ASC");
    if ($attStmt) {
        $attStmt->bind_param("i", $ticketId);
        $attStmt->execute();
        $attRes = $attStmt->get_result();
        while ($attRes && ($row = $attRes->fetch_assoc())) {
            $storedName = trim((string) ($row['stored_name'] ?? ''));
            if ($storedName === '') {
                continue;
            }
            $path = realpath(__DIR__ . '/../uploads/' . $storedName);
            if ($path === false || !is_file($path)) {
                continue;
            }
            $name = trim((string) ($row['original_name'] ?? ''));
            $pathKey = strtolower($path);
            $nameKey = strtolower($name);
            if (isset($seen[$pathKey])) {
                continue;
            }
            $key = $pathKey . '|' . $nameKey;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$pathKey] = true;
            $seen[$key] = true;
            $attachments[] = [
                'path' => $path,
                'name' => $name !== '' ? $name : basename($path),
            ];
        }
        $attStmt->close();
    }

    $legacyAttachment = trim($legacyAttachment);
    if ($legacyAttachment !== '') {
        $legacyPath = realpath(__DIR__ . '/../uploads/' . $legacyAttachment);
        if ($legacyPath !== false && is_file($legacyPath)) {
            $pathKey = strtolower($legacyPath);
            $key = $pathKey . '|' . strtolower(basename($legacyPath));
            if (!isset($seen[$pathKey]) && !isset($seen[$key])) {
                $attachments[] = [
                    'path' => $legacyPath,
                    'name' => basename($legacyPath),
                ];
                $seen[$pathKey] = true;
                $seen[$key] = true;
            }
        }
    }

    return $attachments;
}

function notif_ticket_attachment_summary(array $attachments): string
{
    $labels = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $name = trim((string) ($attachment['name'] ?? ''));
        if ($name === '') {
            $path = trim((string) ($attachment['path'] ?? ''));
            if ($path !== '') {
                $name = basename($path);
            }
        }
        if ($name !== '') {
            $labels[] = $name;
        }
    }

    $labels = array_values(array_unique($labels));
    return count($labels) > 0
        ? ('Attachments: ' . implode(', ', $labels))
        : '';
}

function notif_compact_email_lines(array $lines): array
{
    $out = [];
    $seenCategory = false;
    $seenStatus = false;

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (stripos($line, 'Subject:') === 0) {
            continue;
        }

        if (stripos($line, 'Previous status:') === 0) {
            continue;
        }

        if (stripos($line, 'New status:') === 0) {
            continue;
        }

        if (stripos($line, 'Category:') === 0) {
            if ($seenCategory) {
                continue;
            }
            $seenCategory = true;
        }

        if (stripos($line, 'Priority:') === 0) {
            continue;
        }

        if (stripos($line, 'Current status:') === 0) {
            if ($seenStatus) {
                continue;
            }
            $line = 'Ticket Status:' . substr($line, strlen('Current status:'));
            $seenStatus = true;
        }

        $out[] = $line;
    }

    return $out;
}

function getUsersToNotify(mysqli $conn, array $ticket): array
{
    $ids = [];
    $creatorId = notif_requester_user_id($conn, $ticket);
    $assigneeId = (int) ($ticket['assigned_user_id'] ?? 0);
    $assigneeDepartment = trim((string) ($ticket['assignee_department'] ?? ''));

    if ($creatorId > 0) $ids[] = $creatorId;
    if ($assigneeId > 0) $ids[] = $assigneeId;
    if ($assigneeDepartment !== '') {
        $ids = array_merge($ids, notif_department_user_ids($conn, $assigneeDepartment));
    }
    $ids = array_merge($ids, notif_admin_user_ids($conn));

    return notif_unique_user_ids($ids);
}

function sendPriorityEscalationNotification(mysqli $conn, array $ticket, array $userIds, string $newPriority, string $oldPriority = '', array $options = []): array
{
    $ticketId = (int) ($ticket['id'] ?? 0);
    $newPriority = trim($newPriority);
    if ($ticketId <= 0 || $newPriority === '') {
        return ['inserted' => 0, 'notified' => 0, 'emailed' => 0];
    }

    $title = 'Priority Escalation';
    $oldPriorityLabel = trim($oldPriority) !== '' ? trim($oldPriority) : 'Low';
    $message = 'Ticket #' . notif_ticket_number($ticketId) . ' was escalated from ' . $oldPriorityLabel . ' to ' . $newPriority . ' due to SLA delay. Please review and take action.';
    $type = 'priority_escalated';
    $actionType = 'update';
    $inserted = 0;
    $notified = 0;
    $notificationCreatedAt = trim((string) ($options['notification_created_at'] ?? ''));

    foreach (notif_unique_user_ids($userIds) as $userId) {
        $alreadyExists = notif_has_system_record($conn, (int) $userId, $ticketId, $message, $type, $actionType, $title);
        $ok = $notificationCreatedAt !== ''
            ? notif_insert_system_at($conn, (int) $userId, $ticketId, $message, $notificationCreatedAt, $type, $actionType, $title)
            : notif_insert_system($conn, (int) $userId, $ticketId, $message, $type, 86400, $actionType, $title);
        if ($ok) {
            $notified++;
            if (!$alreadyExists) {
                $inserted += 1;
            }
        }
    }

    $emails = isset($options['email_recipients']) && is_array($options['email_recipients']) ? $options['email_recipients'] : [];
    if (count($emails) === 0) {
        foreach (notif_unique_user_ids($userIds) as $userId) {
            $contact = notif_user_contact($conn, (int) $userId);
            $email = trim((string) ($contact['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
    }
    $emails = array_values(array_unique(array_filter(array_map(static function ($email) {
        return strtolower(trim((string) $email));
    }, $emails), static function ($email) {
        return $email !== '';
    })));

    $emailed = 0;
    if (count($emails) > 0) {
        $lines = [
            'Ticket #' . notif_ticket_number($ticketId) . ' priority has been escalated to ' . $newPriority . '.',
            'Immediate attention is required.',
        ];
        if ($oldPriority !== '') {
            $lines[] = 'Previous priority: ' . $oldPriority;
        }
        if (!empty($ticket['subject'])) {
            $lines[] = 'Subject: ' . (string) $ticket['subject'];
        }
        $ctaUrl = trim((string) ($options['email_cta_url'] ?? ''));
        if ($ctaUrl === '') {
            $ctaUrl = notif_ticket_link_admin($ticketId);
        }
        $mail = notif_email_simple($title, $lines, 'View Ticket', $ctaUrl);
        if (notif_email_send($emails, $title, (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''))) {
            $emailed = count($emails);
        }
    }

    return ['inserted' => $inserted, 'notified' => $notified, 'emailed' => $emailed];
}

function notif_send_ticket_status_update(mysqli $conn, int $ticketId, string $oldStatus, string $newStatus, string $updatedBy = '', array $options = []): array
{
    $ticketId = (int) $ticketId;
    $oldStatus = trim($oldStatus);
    $newStatus = trim($newStatus);
    $updatedBy = trim($updatedBy);
    if ($ticketId <= 0 || $newStatus === '' || strcasecmp($oldStatus, $newStatus) === 0) {
        error_log('notif_send_ticket_status_update: skipped (ticketId=' . $ticketId . ' old=' . $oldStatus . ' new=' . $newStatus . ')');
        return ['inserted' => 0, 'emailed' => 0];
    }

    $ticket = notif_ticket_data($conn, $ticketId);
    if (!$ticket) {
        error_log('notif_send_ticket_status_update: ticket not found (ticketId=' . $ticketId . ')');
        return ['inserted' => 0, 'emailed' => 0];
    }

    $creatorId = notif_requester_user_id($conn, $ticket);
    $creatorIds = notif_requester_user_ids($conn, $ticket);
    error_log('notif_send_ticket_status_update: ticketId=' . $ticketId . ' old=' . $oldStatus . ' new=' . $newStatus . ' creatorIds=' . implode(',', $creatorIds));
    $creatorEmail = trim((string) ($ticket['creator_email'] ?? ''));
    $ticketNumber = notif_ticket_number($ticketId);
    $title = 'Ticket Status Updated';
    if (strcasecmp($oldStatus, 'Open') === 0 && strcasecmp($newStatus, 'In Progress') === 0) {
        $title = 'Ticket Claimed';
    } elseif (strcasecmp($newStatus, 'Resolved') === 0) {
        $title = 'Ticket Resolved';
    } elseif (strcasecmp($newStatus, 'Closed') === 0) {
        $title = 'Ticket Closed';
    }
    $attachments = isset($options['attachments']) && is_array($options['attachments']) ? $options['attachments'] : [];
    $assigneeEmails = isset($options['assignee_emails']) && is_array($options['assignee_emails']) ? $options['assignee_emails'] : [];
    $extraLines = isset($options['extra_lines']) && is_array($options['extra_lines']) ? $options['extra_lines'] : [];
    $skipSystem = !empty($options['skip_system']);
    $skipEmail = !empty($options['skip_email']);

    $assigneeEmails = array_values(array_unique(array_filter(array_map(static function ($email) {
        return strtolower(trim((string) $email));
    }, $assigneeEmails), static function ($email) {
        return $email !== '';
    })));
    if ($creatorEmail !== '') {
        $assigneeEmails = array_values(array_filter($assigneeEmails, static function ($email) use ($creatorEmail) {
            return strcasecmp($email, $creatorEmail) !== 0;
        }));
    }

    $bySuffix = $updatedBy !== '' ? (' by ' . $updatedBy) : '';
    $message = strcasecmp($newStatus, 'Closed') === 0
        ? ('Your ticket #' . $ticketId . ' has been closed' . $bySuffix . '.')
        : ('Your ticket #' . $ticketId . ' status was updated to ' . $newStatus . $bySuffix . '.');

    $inserted = 0;
    if (!$skipSystem) {
        foreach ($creatorIds as $cId) {
            if ($cId > 0 && notif_insert_system($conn, $cId, $ticketId, $message, strcasecmp($newStatus, 'Closed') === 0 ? 'ticket_closed' : 'status_update', 15, strcasecmp($newStatus, 'Closed') === 0 ? 'close' : 'update', $title)) {
                $inserted++;
            }
        }
    }

    if ($skipEmail) {
        return ['inserted' => $inserted, 'emailed' => 0];
    }

    $emailed = 0;
    if ($creatorEmail !== '') {
        $lines = [
            'Ticket has been updated.',
            'Ticket ID: #' . $ticketNumber,
        ];
        if ($updatedBy !== '') {
            if ($title === 'Ticket Claimed') {
                $lines[] = 'Handled By: ' . $updatedBy;
            } elseif ($title === 'Ticket Resolved') {
                $lines[] = 'Resolved By: ' . $updatedBy;
            } else {
                $lines[] = 'Updated By: ' . $updatedBy;
            }
        }
        foreach ($extraLines as $line) {
            $line = trim((string) $line);
            if ($line !== '' && !preg_match('/^(Assigned To|Attachments):/i', $line)) {
                $lines[] = $line;
            }
        }
        $lines = notif_compact_email_lines($lines);
        $mail = notif_email_simple($title, $lines, 'View Ticket', notif_ticket_link_employee_tickets($ticketId));
        if (notif_email_send([$creatorEmail], $title . ' (#' . $ticketNumber . ')', (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''), $attachments)) {
            $emailed = 1;
        }
    }

    if (count($assigneeEmails) > 0) {
        $lines = [
            'Ticket has been updated.',
            'Ticket ID: #' . $ticketNumber,
        ];
        if ($updatedBy !== '') {
            if ($title === 'Ticket Claimed') {
                $lines[] = 'Handled By: ' . $updatedBy;
            } elseif ($title === 'Ticket Resolved') {
                $lines[] = 'Resolved By: ' . $updatedBy;
            } else {
                $lines[] = 'Updated By: ' . $updatedBy;
            }
        }
        foreach ($extraLines as $line) {
            $line = trim((string) $line);
            if ($line !== '' && !preg_match('/^(Assigned To|Attachments):/i', $line)) {
                $lines[] = $line;
            }
        }
        $lines = notif_compact_email_lines($lines);
        $mail = notif_email_simple($title, $lines, 'View Task', notif_ticket_link_employee_tasks($ticketId));
        if (notif_email_send($assigneeEmails, $title . ' (#' . $ticketNumber . ')', (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''), $attachments)) {
            $emailed += count($assigneeEmails);
        }
    }

    return ['inserted' => $inserted, 'emailed' => $emailed];
}

function notif_email_send(array $toEmails, string $subjectLine, string $bodyHtml, string $bodyText, array $attachments = []): bool
{
    $to = array_values(array_filter(array_map('trim', $toEmails), static function ($v) { return is_string($v) && $v !== ''; }));
    if (count($to) === 0) return false;
    $options = [];
    if (preg_match('/\(#0*(\d+)\)/', $subjectLine, $m)) {
        $options['ticket_id'] = (int) $m[1];
    } elseif (preg_match('/Ticket(?:\s+ID)?\s*:\s*#?0*(\d+)|Ticket\s+#0*(\d+)/i', strip_tags($bodyText . "\n" . $bodyHtml), $m)) {
        $options['ticket_id'] = (int) ((string) ($m[1] ?? '') !== '' ? $m[1] : $m[2]);
    }
    $ok = sendSmtpEmail($to, $subjectLine, $bodyHtml, $bodyText, $attachments, $options);
    if (!$ok) {
        error_log('Email send failed | subject=' . (string) $subjectLine . ' to=' . implode(',', $to));
    }
    return (bool) $ok;
}

function notif_send_pending_chat_email(mysqli $conn, int $userId, int $ticketId, string $ticketSubject = ''): bool
{
    $userId = (int) $userId;
    $ticketId = (int) $ticketId;
    if ($userId <= 0 || $ticketId <= 0) {
        return false;
    }

    $contact = notif_user_contact($conn, $userId);
    $email = strtolower(trim((string) ($contact['email'] ?? '')));
    if ($email === '') {
        return false;
    }

    $ticketNumber = notif_ticket_number($ticketId);
    $ticketSubject = trim($ticketSubject);
    $lines = [
        'Ticket ID: #' . $ticketNumber,
        'You have a pending chat reply that needs your attention.',
    ];
    if ($ticketSubject !== '') {
        $lines[] = 'Subject: ' . $ticketSubject;
    }

    $mail = notif_email_simple('Pending Chat', $lines, 'Open Chat', notif_ticket_link_employee_chat($ticketId));
    return notif_email_send([$email], 'Pending Chat (#' . $ticketNumber . ')', (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''));
}

function notif_email_simple(string $title, array $lines, string $ctaLabel, string $ctaUrl): array
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $normalizedTitle = strtolower(trim($title));
    $introText = '';
    if ($normalizedTitle === 'ticket submitted' || $normalizedTitle === 'new ticket submitted' || $normalizedTitle === 'new sales ticket') {
        $introText = 'Your ticket has been successfully submitted and is now awaiting review.';
    } elseif ($normalizedTitle === 'ticket claimed') {
        $introText = 'Your ticket has been claimed by a support staff member and is now in progress.';
    } elseif ($normalizedTitle === 'ticket reassigned') {
        $introText = 'Your ticket has been reassigned to another department for further handling.';
    } elseif ($normalizedTitle === 'ticket resolved') {
        $introText = 'Your ticket has been marked as resolved. Please review the completed update.';
    } elseif ($normalizedTitle === 'ticket closed') {
        $introText = 'Your ticket has been closed. Please review the final update.';
    } elseif ($normalizedTitle === 'ticket assigned') {
        $introText = 'A support ticket has been assigned and is ready for handling.';
    }
    $lineHtml = '';
    $lineText = '';
    foreach ($lines as $l) {
        $line = (string) $l;
        if (strcasecmp(trim($line), 'Ticket has been updated.') === 0) {
            continue;
        }
        $line = preg_replace('/^Status:\s*/i', 'Current Status: ', $line);
        $line = preg_replace('/^Current status:\s*/i', 'Current Status: ', $line);
        $lineText .= $line . "\n";
        $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^([A-Za-z][A-Za-z\s&]+:)(\s*.*)$/s', $safeLine, $matches)) {
            $safeLine = '<strong>' . $matches[1] . '</strong>' . $matches[2];
        }
        $lineHtml .= '<div style="margin:0 0 20px 0; font-size:26px; line-height:1.55; color:#0f172a;">' . nl2br($safeLine) . '</div>';
    }
    $ctaLabelSafe = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
    $ctaUrlSafe = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
    $ctaBlock = '';
    if ($ctaLabelSafe !== '' && $ctaUrlSafe !== '') {
        $ctaBlock = '
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:28px 0 0 0">
                        <tr>
                            <td align="left" style="padding:0;">
                                <a href="' . $ctaUrlSafe . '" target="_blank" rel="noopener" style="display:block; width:100%; box-sizing:border-box; text-align:center; background:#05651f; border:1px solid #05651f; border-radius:16px; padding:18px 24px; color:#ffffff; text-decoration:none; font-weight:900; font-size:26px; line-height:1.2;">
                                    ' . $ctaLabelSafe . '
                                </a>
                            </td>
                        </tr>
                    </table>';
        $ctaBlock .= '
                    <div style="margin-top:16px; font-size:18px; line-height:1.45; color:#475569;">
                        If the button does not appear, use this link.
                        <a href="' . $ctaUrlSafe . '" target="_blank" rel="noopener" style="color:#05651f; text-decoration:underline; font-weight:800;">' . $ctaLabelSafe . '</a>
                    </div>';
    }
    $introHtml = $introText !== ''
        ? '<div style="margin:0 0 28px 0; font-size:24px; line-height:1.45; color:#0f172a;">' . htmlspecialchars($introText, ENT_QUOTES, 'UTF-8') . '</div>'
        : '';
    $bodyHtml = "
        <div style='font-family:Arial, sans-serif; color:#0f172a; line-height:1.5; padding:18px 0;'>
            <div style='max-width:850px;margin:0 auto;background:#ffffff;border:2px solid #d7e3f1;border-radius:26px;overflow:hidden;box-shadow:0 12px 40px rgba(15,23,42,0.06)'>
                <div style='background:linear-gradient(90deg,#055f1f,#03551b);padding:42px 40px 30px;color:#ffffff'>
                    <div style='font-size:28px;font-weight:900;line-height:1.15'>Leads Agri Helpdesk</div>
                    <div style='font-size:20px;font-weight:900;color:#ffe44d;margin-top:12px'>$safeTitle</div>
                </div>
                <div style='padding:40px 44px 38px'>
                    $introHtml
                    $lineHtml
                    $ctaBlock
                </div>
            </div>
        </div>
    ";
    $bodyText = "Leads Agri Helpdesk\n$title\n\n" . ($introText !== '' ? ($introText . "\n\n") : '') . $lineText . "\n$ctaLabel: $ctaUrl\n";
    return ['html' => $bodyHtml, 'text' => $bodyText];
}

function notif_display_message(string $type, string $message, int $ticketId = 0): string
{
    $type = strtolower(trim($type));
    if ($type === 'note_added') {
        return $ticketId > 0
            ? ("A private note was added to ticket #" . $ticketId . ".")
            : "A private note was added to a ticket.";
    }
    return notif_replace_company_domains($message);
}

function notif_priority_transition_from_message(string $message): array
{
    $message = trim($message);
    if ($message === '') {
        return ['from' => '', 'to' => ''];
    }

    if (preg_match('/\bescalated\s+from\s+(critical|high|medium|low)\s+to\s+(critical|high|medium|low)\b/i', $message, $matches)) {
        return [
            'from' => ucfirst(strtolower((string) ($matches[1] ?? ''))),
            'to' => ucfirst(strtolower((string) ($matches[2] ?? ''))),
        ];
    }

    if (preg_match('/\bescalated\s+to\s+(critical|high|medium|low)\b/i', $message, $matches)) {
        return [
            'from' => '',
            'to' => ucfirst(strtolower((string) ($matches[1] ?? ''))),
        ];
    }

    return ['from' => '', 'to' => ''];
}

function notif_message_highlight_html(string $message): string
{
    $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    if ($escaped === '') {
        return '';
    }

    return (string) preg_replace_callback(
        '/\b(in progress|reassigned|assigned|resolved|closed|open)\b/i',
        static function (array $matches): string {
            $token = strtolower(preg_replace('/\s+/', ' ', trim((string) ($matches[1] ?? ''))));
            $class = 'notif-keyword-generic';

            switch ($token) {
                case 'resolved':
                case 'closed':
                    $class = 'notif-keyword-success';
                    break;
                case 'in progress':
                case 'open':
                    $class = 'notif-keyword-info';
                    break;
                case 'assigned':
                    $class = 'notif-keyword-assign';
                    break;
                case 'reassigned':
                    $class = 'notif-keyword-reassign';
                    break;
            }

            return '<span class="notif-keyword ' . $class . '">' . $matches[0] . '</span>';
        },
        $escaped
    );
}
