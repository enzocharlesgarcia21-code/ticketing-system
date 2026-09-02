<?php

function private_attachment_project_root(): string
{
    return dirname(__DIR__);
}

function private_attachment_preferred_dir(): string
{
    $configured = trim((string) (getenv('PRIVATE_UPLOAD_DIR') ?: ''));
    if ($configured !== '') return rtrim($configured, '/\\');
    return dirname(private_attachment_project_root()) . DIRECTORY_SEPARATOR . 'ticketing_private_uploads';
}

function private_attachment_fallback_dir(): string
{
    return private_attachment_project_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private_uploads';
}

function private_attachment_storage_dir(): string
{
    static $selected = null;
    if ($selected !== null) return $selected;
    foreach ([private_attachment_preferred_dir(), private_attachment_fallback_dir()] as $candidate) {
        if ((is_dir($candidate) || @mkdir($candidate, 0700, true)) && is_writable($candidate)) {
            $selected = $candidate;
            return $selected;
        }
    }
    $selected = private_attachment_fallback_dir();
    return $selected;
}

function private_attachment_safe_name(string $storedName): string
{
    $input = trim($storedName);
    if ($input === '' || strpos($input, '/') !== false || strpos($input, '\\') !== false) {
        return '';
    }
    $name = basename($input);
    return preg_match('/^[A-Za-z0-9._-]+$/', $name) ? $name : '';
}

function private_attachment_resolve_path(string $storedName): string
{
    $name = private_attachment_safe_name($storedName);
    if ($name === '') return '';
    $roots = [private_attachment_preferred_dir(), private_attachment_fallback_dir(), private_attachment_project_root() . DIRECTORY_SEPARATOR . 'uploads'];
    foreach (array_unique($roots) as $root) {
        $candidate = $root . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) return $candidate;
    }
    return '';
}

function private_attachment_url(int $ticketId, string $storedName, bool $download = false): string
{
    if ($ticketId <= 0 || private_attachment_safe_name($storedName) === '') return '';
    return '../download_attachment.php?ticket_id=' . rawurlencode((string) $ticketId)
        . '&file=' . rawurlencode(private_attachment_safe_name($storedName))
        . ($download ? '&download=1' : '');
}
