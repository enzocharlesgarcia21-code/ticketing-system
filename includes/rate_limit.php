<?php
/**
 * Rate Limiting Utility — fault-tolerant (fails open if table unavailable).
 */

function rate_limit_check(mysqli $conn, string $identifier, string $action, int $maxAttempts, int $windowSeconds): array
{
    $fallback = ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after_sec' => 0];

    // Try to ensure the backing table exists (may fail if DB user lacks CREATE)
    @$conn->query("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            action VARCHAR(50) NOT NULL,
            attempts INT NOT NULL DEFAULT 1,
            first_attempt DATETIME NOT NULL,
            last_attempt DATETIME NOT NULL,
            UNIQUE KEY idx_identifier_action (identifier, action),
            INDEX idx_last_attempt (last_attempt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', time() - $windowSeconds);

    $cleanupStmt = $conn->prepare("DELETE FROM rate_limits WHERE action = ? AND last_attempt < ?");
    if ($cleanupStmt) {
        $cleanupStmt->bind_param("ss", $action, $cutoff);
        $cleanupStmt->execute();
        $cleanupStmt->close();
    }

    $fetchStmt = $conn->prepare("SELECT id, attempts, first_attempt FROM rate_limits WHERE identifier = ? AND action = ?");
    if (!$fetchStmt) {
        return $fallback;
    }
    $fetchStmt->bind_param("ss", $identifier, $action);
    $fetchStmt->execute();
    $result = $fetchStmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $fetchStmt->close();

    if ($row) {
        $firstAttemptTime = strtotime($row['first_attempt']);
        $windowStart = time() - $windowSeconds;

        if ($firstAttemptTime < $windowStart) {
            $resetStmt = $conn->prepare("UPDATE rate_limits SET attempts = 1, first_attempt = ?, last_attempt = ? WHERE id = ?");
            if ($resetStmt) {
                $resetStmt->bind_param("ssi", $now, $now, $row['id']);
                $resetStmt->execute();
                $resetStmt->close();
            }
            return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after_sec' => 0];
        }

        $currentAttempts = (int) $row['attempts'];
        if ($currentAttempts >= $maxAttempts) {
            $retryAfter = $firstAttemptTime + $windowSeconds - time();
            return ['allowed' => false, 'remaining' => 0, 'retry_after_sec' => max(0, $retryAfter)];
        }

        $newAttempts = $currentAttempts + 1;
        $incrStmt = $conn->prepare("UPDATE rate_limits SET attempts = ?, last_attempt = ? WHERE id = ?");
        if ($incrStmt) {
            $incrStmt->bind_param("isi", $newAttempts, $now, $row['id']);
            $incrStmt->execute();
            $incrStmt->close();
        }
        return ['allowed' => true, 'remaining' => max(0, $maxAttempts - $newAttempts), 'retry_after_sec' => 0];
    }

    $insertStmt = $conn->prepare("INSERT INTO rate_limits (identifier, action, attempts, first_attempt, last_attempt) VALUES (?, ?, 1, ?, ?)");
    if ($insertStmt) {
        $insertStmt->bind_param("ssss", $identifier, $action, $now, $now);
        $insertStmt->execute();
        $insertStmt->close();
    }
    return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after_sec' => 0];
}

function rate_limit_clear(mysqli $conn, string $identifier, string $action): void
{
    $stmt = $conn->prepare("DELETE FROM rate_limits WHERE identifier = ? AND action = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $identifier, $action);
        $stmt->execute();
        $stmt->close();
    }
}

function rate_limit_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}
