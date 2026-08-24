<?php
require 'config/database.php';
$sql = "ALTER TABLE employee_tickets ADD COLUMN company VARCHAR(255) NOT NULL AFTER user_id";
if ($conn->query($sql) === TRUE) {
    echo "Column 'company' added successfully";
} else {
    echo "Error adding column: " . $conn->error;
}
?>