<?php

function activity_logs_ensure_table(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NULL,
            activity_type VARCHAR(64) NOT NULL,
            activity_description VARCHAR(500) NOT NULL,
            module_name VARCHAR(120) NOT NULL,
            reference_id VARCHAR(120) NULL DEFAULT NULL,
            ip_address VARCHAR(45) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_activity_user_created (user_id, created_at),
            KEY idx_activity_type (activity_type),
            KEY idx_activity_module (module_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function activity_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $ip = trim(explode(',', (string) $candidate)[0]);
        if ($ip !== '') return substr($ip, 0, 45);
    }

    return '';
}

function activity_log(mysqli $conn, ?int $userId, string $activityType, string $description, string $moduleName, $referenceId = null): void
{
    activity_logs_ensure_table($conn);

    $activityType = strtoupper(trim($activityType));
    $description = trim($description);
    $moduleName = trim($moduleName);
    $reference = $referenceId === null ? null : substr(trim((string) $referenceId), 0, 120);
    $ip = activity_client_ip();

    if ($activityType === '' || $description === '' || $moduleName === '') {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO activity_logs (user_id, activity_type, activity_description, module_name, reference_id, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) return;

    $uid = $userId !== null && $userId > 0 ? $userId : null;
    $stmt->bind_param("isssss", $uid, $activityType, $description, $moduleName, $reference, $ip);
    $stmt->execute();
    $stmt->close();
}

