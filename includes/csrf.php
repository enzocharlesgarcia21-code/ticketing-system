<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Generates (or returns existing) CSRF token and stores it in the session.
 * Uses random_bytes() + bin2hex() as required for cryptographically secure tokens.
 */
function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

/*
 * Returns a hidden input field containing the CSRF token for use in HTML forms.
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/*
 * Validates the CSRF token on POST requests.
 * If missing/invalid, the request is stopped with an error response.
 */
function csrf_validate(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $sessionToken = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
    $token = '';

    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    $token = trim((string) $token);

    $ok = ($sessionToken !== '' && $token !== '' && hash_equals($sessionToken, $token));
    if ($ok) {
        return;
    }

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $wantsJson = stripos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';

    http_response_code(419);
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token. Please refresh the page and try again.']);
        exit;
    }

    echo 'Invalid CSRF token. Please refresh the page and try again.';
    exit;
}

