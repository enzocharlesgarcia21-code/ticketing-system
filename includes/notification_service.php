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
        // Fallback: requester_name / requester_email columns may not exist yet on this server.
        // Use a simpler query so notifications still work.
        error_log('notif_ticket_data: primary prepare failed (ticketId=' . $ticketId . ') err=' . $conn->error . ' — retrying without requester columns');
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
            error_log('notif_ticket_data: fallback prepare also failed (ticketId=' . $ticketId . ') err=' . $conn->error);
            return null;
        }
    }
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
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

    $title = 'Ticket Priority Escalated';
    $message = 'Ticket #' . notif_ticket_number($ticketId) . ' priority has been escalated to ' . $newPriority . '. Immediate attention is required.';
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
    $title = strcasecmp($newStatus, 'Closed') === 0 ? 'Ticket Closed' : 'Ticket Status Updated';
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
            $lines[] = 'Updated by: ' . $updatedBy;
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
            $lines[] = 'Updated by: ' . $updatedBy;
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
    $lineHtml = '';
    $lineText = '';
    foreach ($lines as $l) {
        $line = (string) $l;
        $lineText .= $line . "\n";
        $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^([A-Za-z][A-Za-z\s&]+:)(\s*.*)$/s', $safeLine, $matches)) {
            $safeLine = '<strong>' . $matches[1] . '</strong>' . $matches[2];
        }
        $lineHtml .= '<div style="margin:0 0 6px 0">' . nl2br($safeLine) . '</div>';
    }
    $ctaLabelSafe = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
    $ctaUrlSafe = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
    $ctaBlock = '';
    if ($ctaLabelSafe !== '' && $ctaUrlSafe !== '') {
        $ctaBlock = '
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 0 0">
                        <tr>
                            <td align="left" style="padding:0;">
                                <a href="' . $ctaUrlSafe . '" target="_blank" rel="noopener" style="display:inline-block; background:#1B5E20; border:1px solid #1B5E20; border-radius:12px; padding:12px 18px; color:#ffffff; text-decoration:none; font-weight:800; font-size:15px; line-height:1.2;">
                                    ' . $ctaLabelSafe . '
                                </a>
                            </td>
                        </tr>
                    </table>';
        $ctaBlock .= '
                    <div style="margin-top:10px;font-size:13px;line-height:1.4;color:#475569">
                        If the button does not appear, use this link:
                        <a href="' . $ctaUrlSafe . '" target="_blank" rel="noopener" style="color:#1B5E20;text-decoration:underline;font-weight:700">' . $ctaLabelSafe . '</a>
                    </div>';
    }
    $bodyHtml = "
        <div style='font-family:Arial, sans-serif; color:#0f172a; line-height:1.5'>
            <div style='max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden'>
                <div style='background:linear-gradient(90deg,#1B5E20,#144a1e);padding:18px 20px;color:#ffffff'>
                    <div style='font-size:16px;font-weight:800'>Leads Agri Helpdesk</div>
                    <div style='font-size:13px;font-weight:700;color:#FDE68A;margin-top:2px'>$safeTitle</div>
                </div>
                <div style='padding:18px 20px'>
                    $lineHtml
                    $ctaBlock
                </div>
            </div>
        </div>
    ";
    $bodyText = "Leads Agri Helpdesk\n$title\n\n" . $lineText . "\n$ctaLabel: $ctaUrl\n";
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
