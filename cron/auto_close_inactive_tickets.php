<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ticket_assignment.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}

$startedAt = microtime(true);
$inactivitySeconds = 2 * 60 * 60;
$results = processAutoCloseInactiveTickets($conn, $inactivitySeconds);

echo json_encode([
    'ok' => true,
    'message' => 'Automatic inactive chat ticket close check completed.',
    'inactivity_minutes' => (int) ($inactivitySeconds / 60),
    'closed_count' => count($results),
    'tickets' => $results,
    'finished_at' => date('Y-m-d H:i:s'),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
], JSON_PRETTY_PRINT);
