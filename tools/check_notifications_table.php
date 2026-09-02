
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}
require_once 'config/database.php';
$res = $conn->query("DESCRIBE notifications");
if ($res) {
    echo "Table notifications exists.\n";
    while ($row = $res->fetch_assoc()) {
        echo $row['Field'] . " " . $row['Type'] . "\n";
    }
} else {
    echo "Table notifications missing.\n";
}
?>
