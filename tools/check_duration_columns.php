<?php
require_once 'config/database.php';

// Check if columns exist
$result = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE 'started_at'");
if ($result->num_rows == 0) {
    echo "Adding started_at column...\n";
    $conn->query("ALTER TABLE employee_tickets ADD COLUMN started_at DATETIME NULL");
} else {
    echo "started_at column exists.\n";
}

$result = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE 'resolved_at'");
if ($result->num_rows == 0) {
    echo "Adding resolved_at column...\n";
    $conn->query("ALTER TABLE employee_tickets ADD COLUMN resolved_at DATETIME NULL");
} else {
    echo "resolved_at column exists.\n";
}

echo "Done.\n";
?>