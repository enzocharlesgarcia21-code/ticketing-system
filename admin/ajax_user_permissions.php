<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/user_permissions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if (!user_permissions_can_manage($conn)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the super admin can manage user access.']);
    exit;
}

user_permissions_ensure_table($conn);

$hasFullNameCol = false;
$fullNameRes = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
if ($fullNameRes && $fullNameRes->num_rows > 0) {
    $hasFullNameCol = true;
}

$userId = (int) ($_REQUEST['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid user selected.']);
    exit;
}

$displayNameExpr = $hasFullNameCol
    ? "COALESCE(NULLIF(full_name,''), NULLIF(name,''), '')"
    : "COALESCE(NULLIF(name,''), '')";

$userStmt = $conn->prepare("
    SELECT id, $displayNameExpr AS display_name, email, role, department
    FROM users
    WHERE id = ?
    LIMIT 1
");
if (!$userStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error.']);
    exit;
}

$userStmt->bind_param('i', $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();
$targetUser = $userResult ? $userResult->fetch_assoc() : null;
$userStmt->close();

if (!$targetUser) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'User not found.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_validate();

    $rawPermissions = isset($_POST['permissions']) && is_array($_POST['permissions'])
        ? $_POST['permissions']
        : [];

    $toSave = [];
    foreach (user_permissions_definitions() as $key => $definition) {
        $rawValue = $rawPermissions[$key] ?? '0';
        $toSave[$key] = in_array((string) $rawValue, ['1', 'true', 'on'], true) ? 1 : 0;
    }

    $saved = user_permissions_save_for_user($conn, $userId, $toSave);
    if (!$saved) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to save user access right now.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'User access updated successfully.',
    ]);
    exit;
}

$definitions = [];
foreach (user_permissions_grouped_definitions() as $section => $items) {
    foreach ($items as $key => $definition) {
        $definitions[] = [
            'key' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'section' => $section,
            'nav_label' => (string) ($definition['nav_label'] ?? $definition['label'] ?? $key),
            'path' => (string) ($definition['path'] ?? ''),
        ];
    }
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => (int) ($targetUser['id'] ?? 0),
        'name' => (string) ($targetUser['display_name'] ?? ''),
        'email' => (string) ($targetUser['email'] ?? ''),
        'role' => (string) ($targetUser['role'] ?? ''),
        'department' => (string) ($targetUser['department'] ?? ''),
    ],
    'definitions' => $definitions,
    'permissions' => user_permissions_get_for_user($conn, $userId),
]);
