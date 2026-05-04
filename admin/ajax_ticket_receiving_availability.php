<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/user_permissions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if (!user_permissions_can_manage($conn)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only the super admin can manage ticket receiving availability.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

csrf_validate();
ticket_receiving_availability_ensure_table($conn);

$itemType = trim((string) ($_POST['item_type'] ?? ''));
$companyKey = ticket_normalize_company((string) ($_POST['company_key'] ?? ''));
$departmentName = trim((string) ($_POST['department_name'] ?? ''));
$receivingEnabled = in_array((string) ($_POST['receiving_enabled'] ?? '0'), ['1', 'true', 'on'], true);

if (!in_array($itemType, ['company', 'department'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid availability target.']);
    exit;
}

if ($companyKey === '' || !array_key_exists($companyKey, ticket_request_company_options())) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid company selected.']);
    exit;
}

if ($itemType === 'department') {
    if (!ticket_company_requires_department($companyKey)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This company does not use department-level ticket routing.']);
        exit;
    }

    if ($departmentName === '' || !in_array($departmentName, ticket_company_allowed_groups($companyKey), true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid department selected.']);
        exit;
    }

    if ($receivingEnabled && !ticket_receiving_is_company_enabled($conn, $companyKey)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Turn on the subsidiary first before enabling its departments.']);
        exit;
    }
} else {
    $departmentName = '';
}

$updated = ticket_receiving_set_enabled(
    $conn,
    $companyKey,
    $departmentName,
    $receivingEnabled,
    (int) ($_SESSION['user_id'] ?? 0)
);

if (!$updated) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save ticket receiving availability right now.']);
    exit;
}

$targetLabel = $itemType === 'department'
    ? ticket_company_display_name($companyKey) . ' - ' . $departmentName
    : ticket_company_display_name($companyKey);

echo json_encode([
    'ok' => true,
    'message' => ($receivingEnabled ? 'Enabled' : 'Disabled') . ' ticket receiving for ' . $targetLabel . '.',
]);
