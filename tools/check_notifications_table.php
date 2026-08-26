
<?php
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