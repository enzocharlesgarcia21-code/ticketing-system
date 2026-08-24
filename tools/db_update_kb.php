<?php
require_once 'config/database.php';

$queries = [
    "ALTER TABLE knowledge_base ADD COLUMN article_links TEXT NULL DEFAULT NULL",
    "ALTER TABLE knowledge_base ADD COLUMN article_presentation VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE knowledge_base ADD COLUMN article_video TEXT NULL DEFAULT NULL"
];

foreach ($queries as $query) {
    if ($conn->query($query) === TRUE) {
        echo "Successfully executed: $query\n";
    } else {
        echo "Error executing $query: " . $conn->error . "\n";
    }
}
?>