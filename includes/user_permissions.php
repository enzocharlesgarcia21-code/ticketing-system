<?php

if (!function_exists('user_permissions_definitions')) {
    function user_permissions_definitions(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'nav_label' => 'Dashboard',
                'path' => 'dashboard.php',
                'section' => 'General',
                'default_enabled' => 1,
            ],
            'create_ticket' => [
                'label' => 'Create Ticket',
                'nav_label' => 'Create Ticket',
                'path' => 'request_ticket.php',
                'section' => 'General',
                'default_enabled' => 1,
            ],
            'all_ticket' => [
                'label' => 'All Ticket',
                'nav_label' => 'Tickets',
                'path' => 'my_task.php',
                'section' => 'Tickets',
                'default_enabled' => 1,
            ],
            'my_tickets' => [
                'label' => 'My Tickets',
                'nav_label' => 'My Tickets',
                'path' => 'my_tickets.php',
                'section' => 'Tickets',
                'default_enabled' => 1,
            ],
            'feedback' => [
                'label' => 'Feedback',
                'nav_label' => 'Feedback',
                'path' => 'feedback.php',
                'section' => 'Resources',
                'default_enabled' => 1,
            ],
            'knowledge_base' => [
                'label' => 'Knowledge Base',
                'nav_label' => 'Knowledge Base',
                'path' => 'knowledge_base.php',
                'section' => 'Resources',
                'default_enabled' => 1,
            ],
            'conference_booking' => [
                'label' => 'Conference Booking',
                'nav_label' => 'Conference Booking',
                'path' => 'book_conference.php',
                'section' => 'Resources',
                'default_enabled' => 0,
            ],
            'analytics' => [
                'label' => 'Analytics',
                'nav_label' => 'Analytics',
                'path' => 'analytics.php',
                'section' => 'Resources',
                'default_enabled' => 0,
            ],
        ];
    }
}

if (!function_exists('user_permissions_defaults')) {
    function user_permissions_defaults(): array
    {
        $defaults = [];
        foreach (user_permissions_definitions() as $key => $definition) {
            $defaults[$key] = !empty($definition['default_enabled']) ? 1 : 0;
        }

        return $defaults;
    }
}

if (!function_exists('user_permissions_ensure_table')) {
    function user_permissions_ensure_table(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS user_permissions (
                user_id INT NOT NULL,
                permission_key VARCHAR(100) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, permission_key),
                KEY idx_user_permissions_key (permission_key),
                CONSTRAINT fk_user_permissions_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        $conn->query($sql);
        $ensured = true;
    }
}

if (!function_exists('user_permissions_has_super_admin_column')) {
    function user_permissions_has_super_admin_column(mysqli $conn): bool
    {
        static $hasColumn = null;
        if ($hasColumn !== null) {
            return $hasColumn;
        }

        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'is_super_admin'");
        $hasColumn = $res && $res->num_rows > 0;

        return $hasColumn;
    }
}

if (!function_exists('user_permissions_is_super_admin')) {
    function user_permissions_is_super_admin(mysqli $conn, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!user_permissions_has_super_admin_column($conn)) {
            return (string) ($_SESSION['role'] ?? '') === 'admin';
        }

        $stmt = $conn->prepare("SELECT COALESCE(is_super_admin, 0) AS is_super_admin FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return (int) ($row['is_super_admin'] ?? 0) === 1;
    }
}

if (!function_exists('user_permissions_can_manage')) {
    function user_permissions_can_manage(mysqli $conn): bool
    {
        return isset($_SESSION['user_id'], $_SESSION['role'])
            && (string) $_SESSION['role'] === 'admin'
            && user_permissions_is_super_admin($conn, (int) $_SESSION['user_id']);
    }
}

if (!function_exists('user_permissions_get_for_user')) {
    function user_permissions_get_for_user(mysqli $conn, int $userId): array
    {
        $permissions = user_permissions_defaults();
        if ($userId <= 0) {
            return $permissions;
        }

        user_permissions_ensure_table($conn);

        $stmt = $conn->prepare("
            SELECT permission_key, is_enabled
            FROM user_permissions
            WHERE user_id = ?
        ");
        if (!$stmt) {
            return $permissions;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && ($row = $result->fetch_assoc())) {
            $key = (string) ($row['permission_key'] ?? '');
            if (array_key_exists($key, $permissions)) {
                $permissions[$key] = (int) ($row['is_enabled'] ?? 0) === 1 ? 1 : 0;
            }
        }

        $stmt->close();

        return $permissions;
    }
}

if (!function_exists('user_permissions_save_for_user')) {
    function user_permissions_save_for_user(mysqli $conn, int $userId, array $values): bool
    {
        if ($userId <= 0) {
            return false;
        }

        user_permissions_ensure_table($conn);

        $stmt = $conn->prepare("
            INSERT INTO user_permissions (user_id, permission_key, is_enabled, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                is_enabled = VALUES(is_enabled),
                updated_at = VALUES(updated_at)
        ");
        if (!$stmt) {
            return false;
        }

        foreach (user_permissions_definitions() as $key => $definition) {
            $enabled = !empty($values[$key]) ? 1 : 0;
            $stmt->bind_param('isi', $userId, $key, $enabled);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }
        }

        $stmt->close();

        return true;
    }
}

if (!function_exists('user_permissions_grouped_definitions')) {
    function user_permissions_grouped_definitions(): array
    {
        $grouped = [];
        foreach (user_permissions_definitions() as $key => $definition) {
            $section = (string) ($definition['section'] ?? 'Modules');
            if (!isset($grouped[$section])) {
                $grouped[$section] = [];
            }

            $grouped[$section][$key] = $definition;
        }

        return $grouped;
    }
}
