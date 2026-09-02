<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/auth_security.php';

$identifier = 'security-test-' . bin2hex(random_bytes(12)) . '@invalid.local';
$purpose = 'security_test';
$rateScope = 'security_test_rate';
$rateIdentifier = bin2hex(random_bytes(12));
$failures = [];

try {
    $issued = auth_otp_issue($conn, $purpose, $identifier, 300, 60, 3);
    if (empty($issued['ok']) || !preg_match('/^\d{6}$/', (string) ($issued['otp'] ?? ''))) {
        $failures[] = 'Could not issue a six-digit OTP challenge.';
    } else {
        $otp = (string) $issued['otp'];
        $identifierHash = auth_identifier_hash($identifier);
        $stmt = $conn->prepare('SELECT otp_hash, attempts FROM auth_otp_challenges WHERE purpose = ? AND identifier_hash = ?');
        $stmt->bind_param('ss', $purpose, $identifierHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (string) ($row['otp_hash'] ?? '') === $otp || !password_verify($otp, (string) ($row['otp_hash'] ?? ''))) {
            $failures[] = 'OTP challenge was not stored as a one-way password hash.';
        }

        $cooldown = auth_otp_issue($conn, $purpose, $identifier, 300, 60, 3);
        if (!empty($cooldown['ok']) || (int) ($cooldown['cooldown'] ?? 0) < 1) {
            $failures[] = 'OTP resend cooldown was not enforced.';
        }

        auth_otp_verify($conn, $purpose, $identifier, $otp === '000000' ? '000001' : '000000');
        $attemptStmt = $conn->prepare('SELECT attempts FROM auth_otp_challenges WHERE purpose = ? AND identifier_hash = ?');
        $attemptStmt->bind_param('ss', $purpose, $identifierHash);
        $attemptStmt->execute();
        $attempts = (int) ($attemptStmt->get_result()->fetch_assoc()['attempts'] ?? 0);
        $attemptStmt->close();
        if ($attempts !== 1) $failures[] = 'Invalid OTP attempt was not recorded.';
        if (empty(auth_otp_verify($conn, $purpose, $identifier, $otp)['ok'])) {
            $failures[] = 'Valid OTP was not accepted.';
        }
    }

    $first = auth_rate_limit_consume($conn, $rateScope, $rateIdentifier, 2, 300, 300);
    $second = auth_rate_limit_consume($conn, $rateScope, $rateIdentifier, 2, 300, 300);
    $third = auth_rate_limit_consume($conn, $rateScope, $rateIdentifier, 2, 300, 300);
    if (empty($first['allowed']) || empty($second['allowed']) || !empty($third['allowed'])) {
        $failures[] = 'Rate limiter did not block after the configured attempt count.';
    }
} finally {
    auth_otp_delete($conn, $purpose, $identifier);
    auth_rate_limit_clear($conn, $rateScope, $rateIdentifier);
}

if ($failures) {
    fwrite(STDERR, "Authentication security integration failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Authentication security integration tests passed.\n";
