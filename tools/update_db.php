<?php
$conn = new mysqli('127.0.0.1', 'root', '', 'ticketing_system', 3306);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if columns exist
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'reset_otp'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE users ADD COLUMN reset_otp VARCHAR(10) NULL";
    if ($conn->query($sql) === TRUE) {
        echo "Added reset_otp column.\n";
    } else {
        echo "Error adding reset_otp: " . $conn->error . "\n";
    }
} else {
    echo "reset_otp column already exists.\n";
}

$result = $conn->query("SHOW COLUMNS FROM users LIKE 'reset_otp_expiry'");
if ($result->num_rows == 0) {
    $sql = "ALTER TABLE users ADD COLUMN reset_otp_expiry DATETIME NULL";
    if ($conn->query($sql) === TRUE) {
        echo "Added reset_otp_expiry column.\n";
    } else {
        echo "Error adding reset_otp_expiry: " . $conn->error . "\n";
    }
} else {
    echo "reset_otp_expiry column already exists.\n";
}

$conn->close();
?>