<?php

function security_env_int(string $name, int $default, int $minimum = 1): int
{
    $raw = getenv($name);
    if ($raw === false || !ctype_digit((string) $raw)) {
        return $default;
    }
    return max($minimum, (int) $raw);
}

function security_client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function security_send_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self' https: data: blob:; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' https: data: blob:; font-src 'self' https: data:; connect-src 'self' https:; frame-src 'self' https: blob:");

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function security_clear_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function security_enforce_session_lifetime(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $now = time();
    $idleLimit = security_env_int('SESSION_IDLE_TIMEOUT', 1800, 300);
    $absoluteLimit = security_env_int('SESSION_ABSOLUTE_TIMEOUT', 43200, 1800);
    $createdAt = (int) ($_SESSION['_security_created_at'] ?? 0);
    $lastActivity = (int) ($_SESSION['_security_last_activity'] ?? 0);

    if ($createdAt > 0 && (($now - $createdAt) > $absoluteLimit || ($lastActivity > 0 && ($now - $lastActivity) > $idleLimit))) {
        security_clear_session();
        session_start();
        $_SESSION['_security_expired'] = true;
        $_SESSION['_security_created_at'] = $now;
    } elseif ($createdAt <= 0) {
        $_SESSION['_security_created_at'] = $now;
    }

    $_SESSION['_security_last_activity'] = $now;
}

function security_regenerate_authenticated_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    session_regenerate_id(true);
    $_SESSION['_security_created_at'] = time();
    $_SESSION['_security_last_activity'] = time();
    unset($_SESSION['_security_expired']);
}

function security_require_cli(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

