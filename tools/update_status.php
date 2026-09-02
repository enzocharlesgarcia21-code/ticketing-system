<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}
require_once "config/database.php";
require_once __DIR__ . "/includes/csrf.php";

if(isset($_POST['id'])){
    csrf_validate();
    $id = intval($_POST['id']);
    $status = (string) ($_POST['status'] ?? '');

    $stmt = $conn->prepare("UPDATE tickets SET status = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
    }
}

header("Location: view_tickets.php");
exit();
?>
