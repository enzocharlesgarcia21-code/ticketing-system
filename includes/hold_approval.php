<?php

require_once __DIR__ . '/user_permissions.php';
require_once __DIR__ . '/ticket_assignment.php';

function hold_approval_ensure_table(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) return;

    $conn->query("CREATE TABLE IF NOT EXISTS ticket_hold_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ticket_id INT NOT NULL,
        requested_by INT NOT NULL,
        reason TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        decided_by INT NULL,
        decision_note VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_hold_request_ticket_status (ticket_id, status),
        KEY idx_hold_request_requested_by (requested_by),
        KEY idx_hold_request_decided_by (decided_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ensured = true;
}

function hold_approval_user_scope(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0) return null;
    $stmt = $conn->prepare("SELECT id, name, email, role, company, department FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function hold_approval_ticket_company(array $ticket): string
{
    return ticket_normalize_company((string) (($ticket['assigned_company'] ?? '') !== ''
        ? $ticket['assigned_company']
        : ($ticket['company'] ?? '')));
}

function hold_approval_ticket_department(array $ticket): string
{
    return ticket_department_key_from_value((string) (($ticket['assigned_group'] ?? '') !== ''
        ? $ticket['assigned_group']
        : ($ticket['assigned_department'] ?? '')));
}

function hold_approval_scope_matches(array $user, array $ticket): bool
{
    $userCompany = ticket_normalize_company((string) ($user['company'] ?? ''));
    $userDepartment = ticket_department_key_from_value((string) ($user['department'] ?? ''));
    $ticketCompany = hold_approval_ticket_company($ticket);
    $ticketDepartment = hold_approval_ticket_department($ticket);

    if ($userDepartment === '' || $ticketDepartment === '' || !hash_equals($ticketDepartment, $userDepartment)) {
        return false;
    }

    // Department is the required approval boundary. Some established employee
    // records do not have a company value; enforce company only when both sides
    // provide one instead of incorrectly excluding a valid department approver.
    return $userCompany === '' || $ticketCompany === '' || hash_equals($ticketCompany, $userCompany);
}

function hold_approval_user_can_review(mysqli $conn, int $userId, array $ticket): bool
{
    $user = hold_approval_user_scope($conn, $userId);
    if (!$user || (string) ($user['role'] ?? '') !== 'employee') return false;
    $permissions = user_permissions_get_for_user($conn, $userId);
    return !empty($permissions['hold_approver']) && hold_approval_scope_matches($user, $ticket);
}

function hold_approval_approvers(mysqli $conn, array $ticket, int $excludeUserId = 0): array
{
    user_permissions_ensure_table($conn);
    $stmt = $conn->prepare("SELECT u.id, u.name, u.email, u.role, u.company, u.department
        FROM users u
        INNER JOIN user_permissions p ON p.user_id = u.id
        WHERE p.permission_key = 'hold_approver' AND p.is_enabled = 1 AND u.role = 'employee'");
    if (!$stmt) return [];
    $stmt->execute();
    $result = $stmt->get_result();
    $approvers = [];
    while ($result && ($user = $result->fetch_assoc())) {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0 || $id === $excludeUserId || !hold_approval_scope_matches($user, $ticket)) continue;
        $approvers[] = $user;
    }
    $stmt->close();
    return $approvers;
}

function hold_approval_pending_request(mysqli $conn, int $ticketId, bool $forUpdate = false): ?array
{
    hold_approval_ensure_table($conn);
    $sql = "SELECT r.*, requester.name AS requester_name
        FROM ticket_hold_requests r
        LEFT JOIN users requester ON requester.id = r.requested_by
        WHERE r.ticket_id = ? AND r.status = 'pending'
        ORDER BY r.id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $ticketId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
