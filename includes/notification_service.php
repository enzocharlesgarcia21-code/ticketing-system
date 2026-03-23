<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

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
    if (!$hasColumn) {
        $conn->query("ALTER TABLE notifications ADD COLUMN action_type VARCHAR(20) NULL AFTER type");
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

function notif_insert_system(mysqli $conn, int $userId, int $ticketId, string $message, string $type = 'ticket', int $dedupeSeconds = 10, string $actionType = ''): bool
{
    $userId = (int) $userId;
    $ticketId = (int) $ticketId;
    if ($userId <= 0 || $ticketId <= 0 || trim($message) === '') return false;
    $type = trim($type) !== '' ? trim($type) : 'ticket';
    $actionType = notif_normalize_action_type($actionType, $type);
    notif_ensure_action_type_column($conn);

    $existsStmt = $conn->prepare("
        SELECT id
        FROM notifications
        WHERE user_id = ? AND ticket_id = ? AND type = ? AND message = ? AND COALESCE(action_type, '') = ?
          AND created_at >= (NOW() - INTERVAL ? SECOND)
        LIMIT 1
    ");
    if ($existsStmt) {
        $existsStmt->bind_param("iisssi", $userId, $ticketId, $type, $message, $actionType, $dedupeSeconds);
        $existsStmt->execute();
        $existsRes = $existsStmt->get_result();
        $exists = $existsRes && $existsRes->fetch_assoc();
        $existsStmt->close();
        if ($exists) return true;
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, ticket_id, message, type, action_type) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        error_log('Notification insert prepare failed | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $conn->error);
        return false;
    }
    $stmt->bind_param("iisss", $userId, $ticketId, $message, $type, $actionType);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Notification insert failed | userId=' . (string) $userId . ' ticketId=' . (string) $ticketId . ' err=' . (string) $stmt->error);
    }
    $stmt->close();
    return (bool) $ok;
}

function notif_insert_admins(mysqli $conn, int $ticketId, string $message, string $type = 'ticket', string $actionType = ''): void
{
    $ids = notif_admin_user_ids($conn);
    foreach ($ids as $id) {
        notif_insert_system($conn, (int) $id, $ticketId, $message, $type, 10, $actionType);
    }
}

function notif_email_send(array $toEmails, string $subjectLine, string $bodyHtml, string $bodyText, array $attachments = []): bool
{
    $to = array_values(array_filter(array_map('trim', $toEmails), static function ($v) { return is_string($v) && $v !== ''; }));
    if (count($to) === 0) return false;
    $ok = sendSmtpEmail($to, $subjectLine, $bodyHtml, $bodyText, $attachments);
    if (!$ok) {
        error_log('Email send failed | subject=' . (string) $subjectLine . ' to=' . implode(',', $to));
    }
    return (bool) $ok;
}

function notif_email_simple(string $title, array $lines, string $ctaLabel, string $ctaUrl): array
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $lineHtml = '';
    $lineText = '';
    foreach ($lines as $l) {
        $line = (string) $l;
        $lineText .= $line . "\n";
        $lineHtml .= '<div style="margin:0 0 6px 0">' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</div>';
    }
    $ctaLabelSafe = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
    $ctaUrlSafe = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
    $bodyHtml = "
        <div style='font-family:Arial, sans-serif; color:#0f172a; line-height:1.5'>
            <div style='max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden'>
                <div style='background:linear-gradient(90deg,#1B5E20,#144a1e);padding:18px 20px;color:#ffffff'>
                    <div style='font-size:16px;font-weight:800'>Leads Agri Helpdesk</div>
                    <div style='font-size:13px;font-weight:700;color:#FDE68A;margin-top:2px'>$safeTitle</div>
                </div>
                <div style='padding:18px 20px'>
                    $lineHtml
                    <div style='margin-top:14px'>
                        <a href='$ctaUrlSafe' style='display:inline-block;background:#1B5E20;color:#ffffff;text-decoration:none;font-weight:800;border-radius:12px;padding:10px 14px'>$ctaLabelSafe</a>
                    </div>
                </div>
            </div>
        </div>
    ";
    $bodyText = "Leads Agri Helpdesk\n$title\n\n" . $lineText . "\n$ctaLabel: $ctaUrl\n";
    return ['html' => $bodyHtml, 'text' => $bodyText];
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
