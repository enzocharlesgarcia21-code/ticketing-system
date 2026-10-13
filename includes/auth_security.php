<?php

require_once __DIR__ . '/security.php';

function auth_security_ensure_tables(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    $conn->query("CREATE TABLE IF NOT EXISTS auth_otp_challenges (
        purpose VARCHAR(32) NOT NULL,
        identifier_hash CHAR(64) NOT NULL,
        otp_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        sent_at DATETIME NOT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
        verified_at DATETIME NULL,
        PRIMARY KEY (purpose, identifier_hash),
        KEY idx_auth_otp_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS auth_rate_limits (
        action_key CHAR(64) NOT NULL PRIMARY KEY,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_auth_rate_expiry (blocked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Legacy OTP columns contained reversible codes. New challenges are stored
    // only as one-way hashes in auth_otp_challenges.
    $conn->query('UPDATE users SET otp_code = NULL WHERE otp_code IS NOT NULL');
    $conn->query('UPDATE users SET reset_otp = NULL WHERE reset_otp IS NOT NULL');
    $done = true;
}

function auth_identifier_hash(string $identifier): string
{
    return hash('sha256', strtolower(trim($identifier)));
}

function auth_rate_limit_consume(mysqli $conn, string $scope, string $identifier, int $limit, int $windowSeconds, int $blockSeconds): array
{
    auth_security_ensure_tables($conn);
    $key = hash('sha256', $scope . '|' . strtolower(trim($identifier)));
    $now = new DateTimeImmutable('now');
    $stmt = $conn->prepare('SELECT attempts, window_started_at, blocked_until FROM auth_rate_limits WHERE action_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row['blocked_until']) && strtotime((string) $row['blocked_until']) > $now->getTimestamp()) {
        return ['allowed' => false, 'retry_after' => max(1, strtotime((string) $row['blocked_until']) - $now->getTimestamp())];
    }

    $windowStarted = $row ? strtotime((string) $row['window_started_at']) : 0;
    $attempts = $row ? (int) $row['attempts'] : 0;
    if ($windowStarted <= 0 || ($now->getTimestamp() - $windowStarted) >= $windowSeconds) {
        $attempts = 0;
        $windowStarted = $now->getTimestamp();
    }
    $attempts++;
    $blockedUntil = null;
    $allowed = $attempts <= $limit;
    if (!$allowed) {
        $blockedUntil = $now->modify('+' . $blockSeconds . ' seconds')->format('Y-m-d H:i:s');
    }
    $windowText = date('Y-m-d H:i:s', $windowStarted);
    $upsert = $conn->prepare("INSERT INTO auth_rate_limits (action_key, attempts, window_started_at, blocked_until)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), window_started_at = VALUES(window_started_at), blocked_until = VALUES(blocked_until)");
    $upsert->bind_param('siss', $key, $attempts, $windowText, $blockedUntil);
    $upsert->execute();
    $upsert->close();

    return ['allowed' => $allowed, 'retry_after' => $allowed ? 0 : $blockSeconds];
}

function auth_rate_limit_clear(mysqli $conn, string $scope, string $identifier): void
{
    auth_security_ensure_tables($conn);
    $key = hash('sha256', $scope . '|' . strtolower(trim($identifier)));
    $stmt = $conn->prepare('DELETE FROM auth_rate_limits WHERE action_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();
}

function auth_rate_limit_or_error(mysqli $conn, string $scope, string $identifier, int $limit, int $windowSeconds, int $blockSeconds): ?string
{
    $state = auth_rate_limit_consume($conn, $scope, $identifier, $limit, $windowSeconds, $blockSeconds);
    if (!empty($state['allowed'])) return null;
    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: ' . (int) $state['retry_after']);
    }
    return 'Too many attempts. Please wait before trying again.';
}

function auth_otp_issue(mysqli $conn, string $purpose, string $identifier, int $ttlSeconds = 300, int $cooldownSeconds = 60, int $maxAttempts = 5): array
{
    auth_security_ensure_tables($conn);
    $identifierHash = auth_identifier_hash($identifier);
    $check = $conn->prepare('SELECT sent_at FROM auth_otp_challenges WHERE purpose = ? AND identifier_hash = ? LIMIT 1');
    $check->bind_param('ss', $purpose, $identifierHash);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if ($row) {
        $elapsed = time() - strtotime((string) $row['sent_at']);
        if ($elapsed >= 0 && $elapsed < $cooldownSeconds) {
            return ['ok' => false, 'cooldown' => $cooldownSeconds - $elapsed];
        }
    }

    $otp = (string) random_int(100000, 999999);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
    $sentAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO auth_otp_challenges (purpose, identifier_hash, otp_hash, expires_at, sent_at, attempts, max_attempts, verified_at)
        VALUES (?, ?, ?, ?, ?, 0, ?, NULL)
        ON DUPLICATE KEY UPDATE otp_hash = VALUES(otp_hash), expires_at = VALUES(expires_at), sent_at = VALUES(sent_at), attempts = 0, max_attempts = VALUES(max_attempts), verified_at = NULL");
    $stmt->bind_param('sssssi', $purpose, $identifierHash, $hash, $expiresAt, $sentAt, $maxAttempts);
    $ok = $stmt->execute();
    $stmt->close();
    return ['ok' => $ok, 'otp' => $ok ? $otp : '', 'cooldown' => 0];
}

function auth_otp_verify(mysqli $conn, string $purpose, string $identifier, string $otp): array
{
    auth_security_ensure_tables($conn);
    if (!preg_match('/^\d{6}$/', $otp)) {
        return ['ok' => false, 'error' => 'Invalid OTP code.'];
    }
    $identifierHash = auth_identifier_hash($identifier);
    $stmt = $conn->prepare('SELECT otp_hash, expires_at, attempts, max_attempts, verified_at FROM auth_otp_challenges WHERE purpose = ? AND identifier_hash = ? LIMIT 1');
    $stmt->bind_param('ss', $purpose, $identifierHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !empty($row['verified_at'])) return ['ok' => false, 'error' => 'Invalid or expired OTP code.'];
    if (strtotime((string) $row['expires_at']) <= time()) return ['ok' => false, 'error' => 'OTP has expired.'];
    if ((int) $row['attempts'] >= (int) $row['max_attempts']) return ['ok' => false, 'error' => 'Too many invalid OTP attempts. Request a new code.'];

    if (!password_verify($otp, (string) $row['otp_hash'])) {
        $update = $conn->prepare('UPDATE auth_otp_challenges SET attempts = attempts + 1 WHERE purpose = ? AND identifier_hash = ?');
        $update->bind_param('ss', $purpose, $identifierHash);
        $update->execute();
        $update->close();
        return ['ok' => false, 'error' => 'Invalid OTP code.'];
    }

    $verified = $conn->prepare('UPDATE auth_otp_challenges SET verified_at = NOW() WHERE purpose = ? AND identifier_hash = ?');
    $verified->bind_param('ss', $purpose, $identifierHash);
    $verified->execute();
    $verified->close();
    return ['ok' => true, 'error' => ''];
}

function auth_otp_delete(mysqli $conn, string $purpose, string $identifier): void
{
    auth_security_ensure_tables($conn);
    $identifierHash = auth_identifier_hash($identifier);
    $stmt = $conn->prepare('DELETE FROM auth_otp_challenges WHERE purpose = ? AND identifier_hash = ?');
    $stmt->bind_param('ss', $purpose, $identifierHash);
    $stmt->execute();
    $stmt->close();
}
