<?php
require 'config/database.php';
$res = $conn->query("SHOW COLUMNS FROM employee_tickets");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>