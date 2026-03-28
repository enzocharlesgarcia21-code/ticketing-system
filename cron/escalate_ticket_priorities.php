<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ticket_assignment.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}

$startedAt = microtime(true);
ticket_apply_sla_priority($conn);

echo json_encode([
    'ok' => true,
    'message' => 'Automatic priority escalation check completed.',
    'finished_at' => date('Y-m-d H:i:s'),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
], JSON_PRETTY_PRINT);
