<?php
require_once 'config/database.php';

$colRes = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE 'assigned_department'");
if ($colRes && $colRes->num_rows === 1) {
    $col = $colRes->fetch_assoc();
    $type = $col['Type'];
    $isNullable = strtoupper($col['Null']) === 'YES';
    if ($isNullable) {
        $conn->query("UPDATE employee_tickets SET assigned_department = 'Unassigned' WHERE assigned_department IS NULL");
        $conn->query("ALTER TABLE employee_tickets MODIFY assigned_department $type NOT NULL");
        echo "assigned_department set to NOT NULL\n";
    } else {
        echo "assigned_department already NOT NULL\n";
    }
} else {
    echo "assigned_department column not found\n";
}
?> 
