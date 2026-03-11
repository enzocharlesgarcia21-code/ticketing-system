<?php
require_once '../config/database.php';

header('Content-Type: application/json');

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$exclude_id = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

$sql = "SELECT id, title FROM knowledge_base WHERE title LIKE ? AND id != ? LIMIT 10";
$stmt = $conn->prepare($sql);
$searchTerm = "%" . $query . "%";
$stmt->bind_param("si", $searchTerm, $exclude_id);
$stmt->execute();
$result = $stmt->get_result();

$articles = [];
while ($row = $result->fetch_assoc()) {
    $articles[] = [
        'id' => $row['id'],
        'title' => htmlspecialchars($row['title'])
    ];
}

echo json_encode($articles);
?>