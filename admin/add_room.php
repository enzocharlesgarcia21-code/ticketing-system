<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/conference_booking.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_rooms.php');
    exit();
}

csrf_validate();
conference_booking_ensure_tables($conn);

$roomName = (string) ($_POST['room_name'] ?? '');
$description = (string) ($_POST['description'] ?? '');
$isActive = ((string) ($_POST['is_active'] ?? '0') === '1') ? 1 : 0;

$_SESSION['conference_room_add_old'] = [
    'room_name' => $roomName,
    'description' => $description,
    'is_active' => $isActive,
];

$result = insertRoom($conn, $roomName, $description, $isActive);
if (!empty($result['ok'])) {
    unset($_SESSION['conference_room_add_old']);
    $_SESSION['conference_room_flash_success'] = 'Room added successfully.';
    header('Location: manage_rooms.php');
    exit();
}

$_SESSION['conference_room_flash_error'] = trim((string) ($result['error'] ?? 'Unable to add the room right now.'));
header('Location: manage_rooms.php?panel=add#roomFormPanel');
exit();
