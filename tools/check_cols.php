<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}
require 'config/database.php';
$res = $conn->query("SHOW COLUMNS FROM employee_tickets");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>
