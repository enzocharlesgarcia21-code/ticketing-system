<?php
require_once 'config/database.php';

$sql = "CREATE TABLE IF NOT EXISTS kb_related_articles (
    article_id INT NOT NULL,
    related_article_id INT NOT NULL,
    PRIMARY KEY (article_id, related_article_id),
    FOREIGN KEY (article_id) REFERENCES knowledge_base(id) ON DELETE CASCADE,
    FOREIGN KEY (related_article_id) REFERENCES knowledge_base(id) ON DELETE CASCADE
)";

if ($conn->query($sql) === TRUE) {
    echo "Table kb_related_articles created successfully";
} else {
    echo "Error creating table: " . $conn->error;
}
?>