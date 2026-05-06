<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/conference_booking.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

conference_booking_ensure_tables($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'update_conference_booking') {
        csrf_validate();

        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $roomId = (int) ($_POST['room_id'] ?? 0);
        $bookingDate = trim((string) ($_POST['booking_date'] ?? ''));
        $startHour = trim((string) ($_POST['start_hour'] ?? ''));
        $startMinute = trim((string) ($_POST['start_minute'] ?? ''));
        $startPeriod = trim((string) ($_POST['start_period'] ?? ''));
        $endHour = trim((string) ($_POST['end_hour'] ?? ''));
        $endMinute = trim((string) ($_POST['end_minute'] ?? ''));
        $endPeriod = trim((string) ($_POST['end_period'] ?? ''));
        $startTime = conference_booking_parse_time_parts($startHour, $startMinute, $startPeriod);
        $endTime = conference_booking_parse_time_parts($endHour, $endMinute, $endPeriod);
        $purpose = trim((string) ($_POST['purpose'] ?? ''));

        $_SESSION['conference_booking_edit_old'] = [
            'booking_id' => $bookingId,
            'room_id' => $roomId,
            'booking_date' => $bookingDate,
            'start_time' => $startTime ?: '',
            'end_time' => $endTime ?: '',
            'start_hour' => $startHour,
            'start_minute' => $startMinute,
            'start_period' => $startPeriod,
            'end_hour' => $endHour,
            'end_minute' => $endMinute,
            'end_period' => $endPeriod,
            'purpose' => $purpose,
        ];

        if ($startTime === null || $endTime === null) {
            $_SESSION['conference_booking_flash_error'] = 'Please choose a valid start and end time.';
            $_SESSION['conference_booking_edit_modal_open'] = 1;
            header('Location: conference_bookings.php');
            exit();
        }

        $result = conference_booking_update_admin($conn, $bookingId, $roomId, $bookingDate, $startTime, $endTime, $purpose);
        if (!empty($result['ok'])) {
            unset($_SESSION['conference_booking_edit_old'], $_SESSION['conference_booking_edit_modal_open']);
            $booking = (array) ($result['booking'] ?? []);
            $roomName = trim((string) ($booking['room_name'] ?? 'the selected room'));
            $_SESSION['conference_booking_flash_success'] = 'Booking for ' . $roomName . ' was updated successfully.';
        } else {
            $_SESSION['conference_booking_flash_error'] = trim((string) ($result['error'] ?? 'Unable to update the booking right now.'));
            $_SESSION['conference_booking_edit_modal_open'] = 1;
        }

        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'cancel_conference_booking') {
        csrf_validate();

        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $result = conference_booking_cancel($conn, $bookingId);
        if (!empty($result['ok'])) {
            $booking = (array) ($result['booking'] ?? []);
            $roomName = trim((string) ($booking['room_name'] ?? 'the selected room'));
            $_SESSION['conference_booking_flash_success'] = 'Booking for ' . $roomName . ' was cancelled successfully.';
        } else {
            $_SESSION['conference_booking_flash_error'] = trim((string) ($result['error'] ?? 'Unable to cancel the booking right now.'));
        }

        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'delete_conference_booking') {
        csrf_validate();

        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $result = conference_booking_delete($conn, $bookingId, (int) ($_SESSION['user_id'] ?? 0));
        if (!empty($result['ok'])) {
            $booking = (array) ($result['booking'] ?? []);
            $roomName = trim((string) ($booking['room_name'] ?? 'the selected room'));
            $bookedBy = trim((string) ($booking['booked_by_name'] ?? 'the requestor'));
            $emailNote = !empty($result['emailed']) ? ' The requestor was also notified by email.' : '';
            $_SESSION['conference_booking_flash_success'] = 'Booking for ' . $roomName . ' by ' . $bookedBy . ' was deleted successfully.' . $emailNote;
        } else {
            $_SESSION['conference_booking_flash_error'] = trim((string) ($result['error'] ?? 'Unable to delete the booking right now.'));
        }

        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'add_conference_room') {
        csrf_validate();

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
        } else {
            $_SESSION['conference_room_flash_error'] = trim((string) ($result['error'] ?? 'Unable to add the room right now.'));
        }

        $_SESSION['conference_room_modal_open'] = 1;
        $_SESSION['conference_room_modal_view'] = 'form';
        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'update_conference_room') {
        csrf_validate();

        $roomId = (int) ($_POST['room_id'] ?? 0);
        $roomName = (string) ($_POST['room_name'] ?? '');
        $description = (string) ($_POST['description'] ?? '');
        $isActive = ((string) ($_POST['is_active'] ?? '0') === '1') ? 1 : 0;

        $_SESSION['conference_room_edit_old'] = [
            'id' => $roomId,
            'room_name' => $roomName,
            'description' => $description,
            'is_active' => $isActive,
        ];

        $result = updateRoom($conn, $roomId, $roomName, $description, $isActive);
        if (!empty($result['ok'])) {
            unset($_SESSION['conference_room_edit_old']);
            $_SESSION['conference_room_flash_success'] = 'Room updated successfully.';
        } else {
            $_SESSION['conference_room_flash_error'] = trim((string) ($result['error'] ?? 'Unable to update the room right now.'));
        }

        $_SESSION['conference_room_modal_open'] = 1;
        $_SESSION['conference_room_modal_view'] = !empty($result['ok']) ? 'list' : 'form';
        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'toggle_conference_room_status') {
        csrf_validate();

        $roomId = (int) ($_POST['room_id'] ?? 0);
        $isActive = ((string) ($_POST['is_active'] ?? '0') === '1') ? 1 : 0;
        $unavailableReason = trim((string) ($_POST['unavailable_reason'] ?? ''));
        $room = conference_booking_find_room($conn, $roomId);

        if (!$room) {
            $_SESSION['conference_room_flash_error'] = 'Conference room not found.';
        } elseif ($isActive === 0 && $unavailableReason === '') {
            $_SESSION['conference_room_flash_error'] = 'Please add a reason before turning the room off.';
        } else {
            $result = updateRoom(
                $conn,
                $roomId,
                (string) ($room['room_name'] ?? ''),
                (string) ($room['description'] ?? ''),
                $isActive
            );

            if (!empty($result['ok'])) {
                conference_room_update_unavailable_reason($conn, $roomId, $isActive === 1 ? '' : $unavailableReason);
                $roomName = trim((string) ($room['room_name'] ?? 'the selected room'));
                $_SESSION['conference_room_flash_success'] = $roomName . ' is now ' . ($isActive === 1 ? 'available for booking.' : 'unavailable for booking.');
            } else {
                $_SESSION['conference_room_flash_error'] = trim((string) ($result['error'] ?? 'Unable to update the room status right now.'));
            }
        }

        $_SESSION['conference_room_modal_open'] = 1;
        $_SESSION['conference_room_modal_view'] = 'list';
        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'toggle_conference_room_saturday') {
        csrf_validate();

        $roomId = (int) ($_POST['room_id'] ?? 0);
        $saturdayEnabled = ((string) ($_POST['saturday_enabled'] ?? '0') === '1') ? 1 : 0;
        $room = conference_booking_find_room($conn, $roomId);

        if (!$room) {
            $_SESSION['conference_room_flash_error'] = 'Conference room not found.';
        } else {
            $result = updateRoom(
                $conn,
                $roomId,
                (string) ($room['room_name'] ?? ''),
                (string) ($room['description'] ?? ''),
                (int) ($room['is_active'] ?? 0),
                $saturdayEnabled
            );

            if (!empty($result['ok'])) {
                $roomName = trim((string) ($room['room_name'] ?? 'the selected room'));
                $_SESSION['conference_room_flash_success'] = $roomName . ' Saturday booking is now ' . ($saturdayEnabled === 1 ? 'enabled.' : 'disabled.');
            } else {
                $_SESSION['conference_room_flash_error'] = trim((string) ($result['error'] ?? 'Unable to update the Saturday booking setting right now.'));
            }
        }

        $_SESSION['conference_room_modal_open'] = 1;
        $_SESSION['conference_room_modal_view'] = 'list';
        header('Location: conference_bookings.php');
        exit();
    }

    if ($action === 'delete_conference_room') {
        csrf_validate();

        $roomId = (int) ($_POST['room_id'] ?? 0);
        $result = deleteRoom($conn, $roomId);
        if (!empty($result['ok'])) {
            $roomName = trim((string) ($result['room_name'] ?? 'the selected room'));
            $_SESSION['conference_room_flash_success'] = 'Room ' . $roomName . ' was deleted successfully.';
        } else {
            $_SESSION['conference_room_flash_error'] = trim((string) ($result['error'] ?? 'Unable to delete the room right now.'));
        }

        $_SESSION['conference_room_modal_open'] = 1;
        $_SESSION['conference_room_modal_view'] = 'list';
        header('Location: conference_bookings.php');
        exit();
    }
}

$rooms = conference_booking_active_rooms($conn);
$allRooms = conference_room_all($conn);
$roomIdByName = [];
foreach ($allRooms as $roomOption) {
    $roomOptionName = trim((string) ($roomOption['room_name'] ?? ''));
    if ($roomOptionName !== '' && !isset($roomIdByName[$roomOptionName])) {
        $roomIdByName[$roomOptionName] = (int) ($roomOption['id'] ?? 0);
    }
}
$bookings = conference_booking_admin_bookings($conn);
$todayDate = date('Y-m-d');
$totalBookings = count($bookings);
$upcomingBookings = 0;
$roomTodayCounts = [];
$roomTotalCounts = [];
$roomsBookedToday = [];
foreach ($bookings as $booking) {
    $bookingDate = trim((string) ($booking['booking_date'] ?? ''));
    $roomName = trim((string) ($booking['room_name'] ?? ''));
    if ($bookingDate >= $todayDate) {
        $upcomingBookings++;
    }
    if ($roomName !== '') {
        $roomTotalCounts[$roomName] = (int) ($roomTotalCounts[$roomName] ?? 0) + 1;
        if ($bookingDate === $todayDate) {
            $roomTodayCounts[$roomName] = (int) ($roomTodayCounts[$roomName] ?? 0) + 1;
            $roomsBookedToday[$roomName] = true;
        }
    }
}
$totalRooms = count($allRooms);
$activeRoomCount = 0;
foreach ($allRooms as $roomRow) {
    if ((int) ($roomRow['is_active'] ?? 0) === 1) {
        $activeRoomCount++;
    }
}
$inactiveRoomCount = max(0, $totalRooms - $activeRoomCount);
$roomsBookedTodayCount = count($roomsBookedToday);
$bookingsTodayCount = array_sum($roomTodayCounts);
$successMessage = (string) ($_SESSION['conference_booking_flash_success'] ?? '');
$errorMessage = (string) ($_SESSION['conference_booking_flash_error'] ?? '');
unset($_SESSION['conference_booking_flash_success'], $_SESSION['conference_booking_flash_error']);

$bookingEditOld = is_array($_SESSION['conference_booking_edit_old'] ?? null) ? $_SESSION['conference_booking_edit_old'] : [];
$openBookingEditModal = !empty($_SESSION['conference_booking_edit_modal_open']);
unset($_SESSION['conference_booking_edit_old'], $_SESSION['conference_booking_edit_modal_open']);

$roomSuccessMessage = (string) ($_SESSION['conference_room_flash_success'] ?? '');
$roomErrorMessage = (string) ($_SESSION['conference_room_flash_error'] ?? '');
$openRoomModalView = (string) ($_SESSION['conference_room_modal_view'] ?? '');
$roomAddOld = is_array($_SESSION['conference_room_add_old'] ?? null) ? $_SESSION['conference_room_add_old'] : [];
$roomEditOld = is_array($_SESSION['conference_room_edit_old'] ?? null) ? $_SESSION['conference_room_edit_old'] : [];
$openRoomModal = !empty($_SESSION['conference_room_modal_open']) || $roomSuccessMessage !== '' || $roomErrorMessage !== '';
unset(
    $_SESSION['conference_room_flash_success'],
    $_SESSION['conference_room_flash_error'],
    $_SESSION['conference_room_modal_view'],
    $_SESSION['conference_room_add_old'],
    $_SESSION['conference_room_edit_old'],
    $_SESSION['conference_room_modal_open']
);

$roomFormMode = 'add';
$roomFormState = [
    'room_id' => 0,
    'room_name' => trim((string) ($roomAddOld['room_name'] ?? '')),
    'description' => trim((string) ($roomAddOld['description'] ?? '')),
    'is_active' => isset($roomAddOld['is_active']) ? (int) $roomAddOld['is_active'] : 1,
];

if ((int) ($roomEditOld['id'] ?? 0) > 0) {
    $roomFormMode = 'edit';
    $roomFormState = [
        'room_id' => (int) ($roomEditOld['id'] ?? 0),
        'room_name' => trim((string) ($roomEditOld['room_name'] ?? '')),
        'description' => trim((string) ($roomEditOld['description'] ?? '')),
        'is_active' => isset($roomEditOld['is_active']) ? (int) $roomEditOld['is_active'] : 1,
    ];
}

if ($openRoomModalView !== 'form' && $openRoomModalView !== 'list') {
    $openRoomModalView = $roomFormMode === 'edit' ? 'form' : 'list';
}

$bookingFormState = [
    'booking_id' => (int) ($bookingEditOld['booking_id'] ?? 0),
    'room_id' => (int) ($bookingEditOld['room_id'] ?? 0),
    'booking_date' => trim((string) ($bookingEditOld['booking_date'] ?? '')),
    'start_time' => trim((string) ($bookingEditOld['start_time'] ?? '')),
    'end_time' => trim((string) ($bookingEditOld['end_time'] ?? '')),
    'purpose' => trim((string) ($bookingEditOld['purpose'] ?? '')),
    'start_hour' => trim((string) ($bookingEditOld['start_hour'] ?? '')),
    'start_minute' => trim((string) ($bookingEditOld['start_minute'] ?? '')),
    'start_period' => trim((string) ($bookingEditOld['start_period'] ?? '')),
    'end_hour' => trim((string) ($bookingEditOld['end_hour'] ?? '')),
    'end_minute' => trim((string) ($bookingEditOld['end_minute'] ?? '')),
    'end_period' => trim((string) ($bookingEditOld['end_period'] ?? '')),
];

$hourOptions = range(1, 12);
$minuteOptions = [];
for ($minute = 0; $minute <= 55; $minute += 5) {
    $minuteOptions[] = sprintf('%02d', $minute);
}

function conference_admin_time_select_parts(string $timeValue, string $defaultHour, string $defaultMinute, string $defaultPeriod): array
{
    $timeValue = trim($timeValue);
    if ($timeValue === '') {
        return [
            'hour' => $defaultHour,
            'minute' => $defaultMinute,
            'period' => $defaultPeriod,
        ];
    }

    $timestamp = strtotime($timeValue);
    if ($timestamp === false) {
        return [
            'hour' => $defaultHour,
            'minute' => $defaultMinute,
            'period' => $defaultPeriod,
        ];
    }

    return [
        'hour' => date('g', $timestamp),
        'minute' => date('i', $timestamp),
        'period' => date('A', $timestamp),
    ];
}

$bookingStartParts = conference_admin_time_select_parts(
    (string) ($bookingFormState['start_time'] ?? ''),
    (string) ($bookingFormState['start_hour'] !== '' ? $bookingFormState['start_hour'] : '9'),
    (string) ($bookingFormState['start_minute'] !== '' ? $bookingFormState['start_minute'] : '00'),
    (string) ($bookingFormState['start_period'] !== '' ? strtoupper((string) $bookingFormState['start_period']) : 'AM')
);
$bookingEndParts = conference_admin_time_select_parts(
    (string) ($bookingFormState['end_time'] ?? ''),
    (string) ($bookingFormState['end_hour'] !== '' ? $bookingFormState['end_hour'] : '10'),
    (string) ($bookingFormState['end_minute'] !== '' ? $bookingFormState['end_minute'] : '00'),
    (string) ($bookingFormState['end_period'] !== '' ? strtoupper((string) $bookingFormState['end_period']) : 'AM')
);

function conference_admin_status_badge_class(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'completed' || $status === 'done' || $status === 'finished') {
        return 'status-ok';
    }
    if ($status === 'approved' || $status === 'confirmed') {
        return 'status-ok';
    }
    if ($status === 'pending') {
        return 'status-pending';
    }
    if ($status === 'cancelled') {
        return 'status-cancelled';
    }
    return 'status-booked';
}

function conference_room_status_text(int $isActive): string
{
    return $isActive === 1 ? 'Available for booking' : 'Unavailable for booking';
}

function conference_room_saturday_text(int $saturdayEnabled): string
{
    return $saturdayEnabled === 1 ? 'Saturday Enabled' : 'Saturday Disabled';
}

function conference_admin_booking_status_text(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'completed' || $status === 'done' || $status === 'finished') {
        return 'Completed';
    }
    if ($status === 'pending') {
        return 'Pending';
    }
    if ($status === 'cancelled') {
        return 'Cancelled';
    }
    return 'Scheduled';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conference Bookings | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .conference-admin-page {
            max-width: 1460px;
            margin: 0 auto;
            padding: 34px 24px 48px;
        }
        .conference-admin-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 24px;
        }
        .conference-admin-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }
        .conference-admin-title h1 {
            margin: 0 0 10px;
            color: #111827;
            font-size: 2.05rem;
            line-height: 1.1;
            font-weight: 600;
            letter-spacing: -0.03em;
        }
        .conference-admin-title p {
            margin: 0;
            color: #475569;
            font-size: 17px;
            line-height: 1.65;
            max-width: 760px;
        }
        .conference-manage-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 12px 22px;
            border-radius: 18px;
            background: linear-gradient(135deg, #166534, #15803d);
            color: #ffffff;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            box-shadow: 0 16px 34px rgba(21, 128, 61, 0.2);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .conference-manage-btn:hover {
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 20px 38px rgba(21, 128, 61, 0.26);
        }
        .conference-admin-stats {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr) minmax(0, 1fr);
            gap: 20px;
            margin-bottom: 26px;
        }
        .conference-alert {
            margin-bottom: 20px;
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid transparent;
            font-size: 14px;
            line-height: 1.7;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
        }
        .conference-alert-success {
            background: #ecfdf3;
            color: #166534;
            border-color: #bbf7d0;
        }
        .conference-alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        .conference-stat {
            position: relative;
            --stat-accent: #86efac;
            --stat-accent-strong: #16a34a;
            --stat-surface: rgba(236, 253, 245, 0.72);
            --stat-shadow: rgba(34, 197, 94, 0.16);
            min-height: 172px;
            background:
                radial-gradient(circle at top left, var(--stat-surface) 0%, rgba(255, 255, 255, 0.98) 28%, #ffffff 72%),
                #ffffff;
            border-radius: 30px;
            padding: 20px 24px 18px;
            border: 1px solid rgba(226, 232, 240, 0.92);
            box-shadow: 0 28px 48px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }
        .conference-stat::before {
            content: "";
            position: absolute;
            inset: 14px auto 14px 0;
            width: 7px;
            border-radius: 999px;
            background: linear-gradient(180deg, var(--stat-accent) 0%, var(--stat-accent-strong) 100%);
            box-shadow: 0 0 24px var(--stat-shadow);
        }
        .conference-stat-head {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
            position: relative;
            z-index: 1;
        }
        .conference-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--stat-accent-strong);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.88) 0%, rgba(255, 255, 255, 0.68) 100%);
            border: 1px solid rgba(255, 255, 255, 0.88);
            box-shadow: 0 16px 34px var(--stat-shadow);
            flex-shrink: 0;
        }
        .conference-stat-icon i {
            font-size: 22px;
        }
        .conference-stat-label {
            margin: 0;
            color: #0f172a;
            font-size: 16px;
            font-weight: 500;
            line-height: 1.35;
            letter-spacing: -0.02em;
            text-transform: none;
        }
        .conference-stat-value {
            position: relative;
            z-index: 1;
            color: #0f172a;
            font-size: 52px;
            line-height: 0.95;
            font-weight: 600;
            letter-spacing: -0.06em;
        }
        .conference-stat-meta {
            position: relative;
            z-index: 1;
            margin-top: 12px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.55;
            max-width: 260px;
        }
        .conference-stat-rooms {
            --stat-accent: #86efac;
            --stat-accent-strong: #16a34a;
            --stat-surface: rgba(236, 253, 245, 0.82);
            --stat-shadow: rgba(34, 197, 94, 0.18);
        }
        .conference-stat-total {
            --stat-accent: #7dd3fc;
            --stat-accent-strong: #0ea5e9;
            --stat-surface: rgba(224, 242, 254, 0.88);
            --stat-shadow: rgba(14, 165, 233, 0.2);
        }
        .conference-stat-upcoming {
            --stat-accent: #fdba74;
            --stat-accent-strong: #f59e0b;
            --stat-surface: rgba(255, 247, 237, 0.92);
            --stat-shadow: rgba(245, 158, 11, 0.18);
        }
        .conference-layout {
            display: grid;
            grid-template-columns: 220px minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }
        .conference-rooms-card,
        .conference-bookings-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 28px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 26px 56px rgba(15, 23, 42, 0.08);
            overflow: hidden;
            backdrop-filter: blur(12px);
        }
        .conference-rooms-card {
            padding: 22px 14px 18px;
        }
        .conference-bookings-card {
            display: flex;
            flex-direction: column;
        }
        .conference-rooms-head {
            padding: 0 12px 14px;
        }
        .conference-rooms-head h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #111827;
            font-weight: 600;
        }
        .conference-rooms-head p {
            margin: 0;
            color: #475569;
            font-size: 13px;
            line-height: 1.7;
        }
        .conference-room-list {
            display: grid;
            gap: 12px;
            padding: 0 6px;
        }
        .conference-room-item {
            appearance: none;
            width: 100%;
            text-align: left;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 14px;
            border-radius: 18px;
            border: 1px solid #d7f7df;
            background: linear-gradient(180deg, #fbfff9 0%, #f5fff3 100%);
            padding: 18px 16px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92);
            cursor: pointer;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .conference-room-item:focus-visible {
            outline: none;
            border-color: #16a34a;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.16);
        }
        .conference-room-item:hover,
        .conference-room-item.is-active {
            transform: translateY(-1px);
            border-color: #86efac;
            box-shadow: 0 16px 30px rgba(22, 101, 52, 0.12);
        }
        .conference-room-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(22, 163, 74, 0.12);
            color: #166534;
            font-size: 18px;
        }
        .conference-room-copy {
            min-width: 0;
        }
        .conference-room-copy h3 {
            margin: 0 0 4px;
            color: #111827;
            font-size: 17px;
            font-weight: 600;
        }
        .conference-room-copy p {
            margin: 0;
            color: #475569;
            font-size: 13px;
            line-height: 1.6;
        }
        .conference-room-arrow {
            color: #94a3b8;
            font-size: 15px;
        }
        .conference-bookings-shell {
            padding: 8px 10px 20px;
            background: linear-gradient(180deg, #fcfbfd 0%, #fbfcfe 100%);
        }
        .conference-card-head {
            padding: 20px 10px 10px;
            border-bottom: 1px solid #eceef3;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .conference-card-head h2 {
            margin: 0;
            font-size: 24px;
            color: #111827;
            font-weight: 600;
            letter-spacing: -0.03em;
        }
        .conference-card-head p {
            margin: 8px 0 0;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.7;
            max-width: 760px;
        }
        .conference-card-head-copy {
            min-width: 0;
        }
        .conference-card-head-tools {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            align-items: stretch;
            width: 100%;
            border: 1px solid #e8e8ef;
            border-radius: 22px;
            background: #ffffff;
            overflow: visible;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        }
        .conference-filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 8px 4px 0;
        }
        .conference-filter-reset {
            min-width: 78px;
            height: 44px;
            border-radius: 14px;
            border: 1px solid #d7dee8;
            background: #ffffff;
            color: #475569;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: border-color 0.2s ease, color 0.2s ease, background 0.2s ease;
        }
        .conference-filter-reset:hover {
            border-color: #7fb78d;
            color: #14532d;
            background: #f8fff9;
        }
        .conference-search {
            position: relative;
            min-width: 0;
        }
        .conference-search::after {
            content: "";
            position: absolute;
            top: 12px;
            right: 0;
            bottom: 12px;
            width: 1px;
            background: #ececf2;
        }
        .conference-search i {
            position: absolute;
            top: 50%;
            left: 18px;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 16px;
        }
        .conference-search input {
            width: 100%;
            min-height: 50px;
            border: none;
            background: transparent;
            padding: 12px 18px 12px 48px;
            color: #0f172a;
            font-size: 15px;
        }
        .conference-search input:focus,
        .conference-filter-select:focus {
            outline: none;
        }
        .conference-search:focus-within,
        .conference-filter-pill:focus-within,
        .conference-date-filter:focus-within {
            box-shadow: inset 0 0 0 2px rgba(22, 163, 74, 0.15);
        }
        .conference-date-filter {
            position: relative;
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            min-width: 168px;
            border: 1px solid #7fb78d;
            border-radius: 16px;
            background: #ffffff;
            overflow: hidden;
        }
        .conference-date-input {
            width: 100%;
            min-height: 44px;
            border: none;
            background: transparent;
            color: #1f2937;
            font-size: 14px;
            font-weight: 500;
            padding: 0 14px;
            cursor: pointer;
        }
        .conference-date-input::-webkit-calendar-picker-indicator {
            cursor: pointer;
            opacity: 1;
        }
        .conference-date-input:focus {
            outline: none;
        }
        .conference-filter-pill {
            position: relative;
            display: flex;
            align-items: stretch;
            min-height: 44px;
            color: #1f2937;
            background: #ffffff;
            min-width: 158px;
            border: 1px solid #7fb78d;
            border-radius: 16px;
            z-index: 2;
            overflow: visible;
        }
        .conference-filter-pill::before {
            content: none;
        }
        .conference-filter-select {
            display: none;
        }
        .conference-filter-trigger {
            width: 100%;
            min-height: 44px;
            padding: 0 14px;
            border: none;
            background: #ffffff;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-align: left;
            border-radius: 16px;
        }
        .conference-filter-trigger:hover {
            background: #ffffff;
        }
        .conference-filter-text {
            flex: 1 1 auto;
        }
        .conference-filter-caret {
            color: #1f2937;
            font-size: 14px;
            pointer-events: none;
        }
        .conference-filter-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            padding: 0;
            border-radius: 0 0 12px 12px;
            border: 1px solid #cfd8e3;
            background: #ffffff;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
            display: none;
            flex-direction: column;
            z-index: 60;
            overflow: hidden;
        }
        .conference-filter-menu.is-open {
            display: flex;
        }
        .conference-filter-option {
            width: 100%;
            border: none;
            background: #ffffff;
            color: #1f2937;
            border-radius: 0;
            min-height: 42px;
            padding: 9px 14px;
            font-size: 15px;
            font-weight: 400;
            line-height: 1.35;
            text-align: left;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .conference-filter-option + .conference-filter-option {
            border-top: 1px solid #e5e7eb;
        }
        .conference-filter-option:hover,
        .conference-filter-option.is-active {
            background: #8b8b8b;
            color: #ffffff;
        }
        .conference-table-wrap {
            overflow: visible;
            border-radius: 28px;
            border: 1px solid #ece8f1;
            background: #ffffff;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.05);
        }
        .conference-table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 6px 0;
            flex-wrap: wrap;
        }
        .conference-pagination-summary {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }
        .conference-pagination-controls {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            min-width: 420px;
        }
        .conference-pagination-controls .pagination-glass {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: nowrap;
        }
        .conference-pagination-controls .page-numbers {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 224px;
            gap: 6px;
        }
        .conference-pagination-controls .page-btn {
            min-width: 38px;
            height: 38px;
            padding: 0 13px;
            border-radius: 999px;
            border: 1px solid #d8e2ec;
            background: #ffffff;
            color: #334155;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: border-color 0.2s ease, background 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .conference-pagination-controls .page-btn.prev,
        .conference-pagination-controls .page-btn.next {
            min-width: 92px;
            padding: 0 16px;
            font-weight: 700;
        }
        .conference-pagination-controls .page-btn:hover:not(.active):not(.disabled) {
            background: #f8fafc;
            border-color: #cfd9e3;
            transform: translateY(-1px);
        }
        .conference-pagination-controls .page-btn.active {
            background: #166534;
            border-color: #166534;
            color: #ffffff;
            box-shadow: 0 8px 22px rgba(22, 101, 52, 0.18);
        }
        .conference-pagination-controls .page-btn.disabled {
            opacity: 0.45;
            background: #ffffff;
            border-color: #d8e2ec;
            cursor: not-allowed;
            transform: none;
        }
        .conference-pagination-controls .pagination-ellipsis {
            min-width: 18px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.08em;
        }
        .conference-pagination-empty {
            color: #9ca3af;
            font-size: 14px;
        }
        .conference-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .conference-table th,
        .conference-table td {
            padding: 20px 10px;
            text-align: left;
            border-bottom: 1px solid #eceef3;
            vertical-align: top;
        }
        .conference-table thead th {
            font-size: 16px;
            letter-spacing: -0.01em;
            color: #111827;
            font-weight: 500;
            background: #ffffff;
            white-space: nowrap;
        }
        .conference-table tbody td {
            border-bottom: 0;
        }
        .conference-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .conference-table tbody tr:hover {
            background: #fcfdfb;
        }
        .conference-table th:nth-child(1),
        .conference-table td:nth-child(1) {
            width: 10%;
            min-width: 96px;
        }
        .conference-table th:nth-child(2),
        .conference-table td:nth-child(2) {
            width: 22%;
            min-width: 180px;
        }
        .conference-table th:nth-child(3),
        .conference-table td:nth-child(3) {
            width: 12%;
            min-width: 104px;
        }
        .conference-table th:nth-child(4),
        .conference-table td:nth-child(4) {
            width: 20%;
            min-width: 170px;
        }
        .conference-table th:nth-child(5),
        .conference-table td:nth-child(5) {
            width: 13%;
            min-width: 118px;
        }
        .conference-table th:nth-child(6),
        .conference-table td:nth-child(6) {
            width: 15%;
            min-width: 148px;
            text-align: center;
        }
        .conference-table th:nth-child(7),
        .conference-table td:nth-child(7) {
            width: 8%;
            min-width: 82px;
            text-align: center;
        }
        .conference-booking-id {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            padding: 10px 18px;
            border-radius: 14px;
            background: linear-gradient(135deg, #166534, #15803d);
            color: #ffffff;
            font-size: 17px;
            font-weight: 600;
            line-height: 1;
            margin-bottom: 0;
            box-shadow: 0 12px 24px rgba(22, 101, 52, 0.18);
        }
        .booking-id-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0;
        }
        .booking-primary {
            color: #111827;
            font-weight: 500;
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 6px;
        }
        .booking-secondary {
            color: #6b7280;
            font-size: 13px;
            line-height: 1.65;
            word-break: break-word;
        }
        .booking-secondary.booking-secondary-line {
            line-height: 1.55;
        }
        .booking-secondary-line {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .booking-secondary-line::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #94a3b8;
            margin-top: 8px;
            flex-shrink: 0;
        }
        .booking-room-tag {
            display: inline-flex;
            align-items: center;
            gap: 0;
            padding: 8px 14px;
            border-radius: 999px;
            background: #eff7f0;
            color: #14532d;
            border: 1px solid #bad8c2;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
        }
        .booking-room-cell {
            display: inline-flex;
            align-items: center;
        }
        .booking-schedule-date {
            color: #111827;
            font-size: 15px;
            font-weight: 400;
            line-height: 1.45;
            margin-bottom: 4px;
        }
        .booking-schedule-time {
            color: #0f172a;
            font-size: 14px;
            font-weight: 500;
            line-height: 1.45;
            margin-bottom: 4px;
            white-space: normal;
        }
        .purpose-copy {
            max-width: none;
            color: #0f172a;
            font-size: 15px;
            font-weight: 400;
            line-height: 1.6;
            word-break: break-word;
        }
        .status-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 122px;
            padding: 9px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .status-chip::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            font-size: 13px;
        }
        .status-chip.status-booked {
            background: #eef7ef;
            color: #14532d;
            border-color: #bfd8c4;
        }
        .status-chip.status-ok {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }
        .status-chip.status-pending {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }
        .status-chip.status-pending::before {
            content: "\f017";
        }
        .status-chip.status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fecaca;
        }
        .status-chip.status-cancelled::before {
            content: "\f00d";
        }
        .booking-actions {
            display: flex;
            justify-content: center;
            position: relative;
        }
        .booking-action-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 164px;
            padding: 8px;
            border-radius: 16px;
            border: 1px solid rgba(226, 232, 240, 0.95);
            background: #ffffff;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.14);
            display: none;
            flex-direction: column;
            gap: 4px;
            z-index: 30;
        }
        .booking-action-menu.is-open {
            display: flex;
        }
        .booking-action-item {
            width: 100%;
            border: none;
            background: transparent;
            color: #0f172a;
            border-radius: 12px;
            min-height: 40px;
            padding: 9px 12px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .booking-action-item i {
            width: 16px;
            text-align: center;
            color: #64748b;
        }
        .booking-action-item:hover {
            background: #f8fafc;
            color: #166534;
        }
        .booking-action-item:hover i {
            color: inherit;
        }
        .booking-action-item.booking-action-item-danger:hover {
            color: #b91c1c;
            background: #fef2f2;
        }
        .delete-booking-btn {
            width: 40px;
            height: 40px;
            border: 1px solid #e0e4ec;
            background: #ffffff;
            color: #475569;
            border-radius: 14px;
            padding: 0;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.04);
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
        }
        .delete-booking-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #166534;
            transform: translateY(-1px);
        }
        .booking-edit-dialog {
            width: min(440px, calc(100vw - 28px));
            max-height: min(820px, calc(100vh - 28px));
        }
        .booking-edit-dialog .room-modal-copy h2 {
            font-weight: 600;
        }
        .booking-edit-form {
            flex: 1 1 auto;
            min-height: 0;
            padding: 18px 20px 24px;
            overflow-y: auto;
        }
        .booking-edit-form .room-form-actions {
            justify-content: flex-end;
        }
        .booking-edit-dialog .room-form-card {
            min-height: 620px;
        }
        .booking-edit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }
        .booking-edit-grid .time-group {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .booking-edit-grid .select-wrapper {
            position: relative;
        }
        .booking-edit-grid .select-wrapper .room-input {
            appearance: none;
            padding-right: 40px;
        }
        .booking-edit-grid .select-icon {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 13px;
            pointer-events: none;
        }
        .booking-edit-grid .room-form-group.room-form-group-full {
            grid-column: 1 / -1;
        }
        .booking-edit-note {
            margin: 0;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }
        body.room-modal-active {
            overflow: hidden;
        }
        .room-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 22px;
            z-index: 5600;
        }
        .room-modal.is-open {
            display: flex;
        }
        .room-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(4px);
        }
        .room-modal-dialog {
            position: relative;
            width: min(1120px, calc(100vw - 28px));
            max-height: min(920px, calc(100vh - 18px));
            background: #f8fafc;
            border-radius: 24px;
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.28);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            z-index: 1;
        }
        .room-delete-confirm {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(6px);
            z-index: 7000;
        }
        .room-delete-confirm.is-open {
            display: flex;
        }
        .room-delete-confirm-card {
            width: min(528px, calc(100vw - 32px));
            background: #ffffff;
            border-radius: 32px;
            box-shadow: 0 34px 72px rgba(15, 23, 42, 0.28);
            overflow: hidden;
            border: 1px solid rgba(226, 232, 240, 0.92);
        }
        .room-delete-confirm-body {
            padding: 28px 34px 24px;
            text-align: center;
        }
        .room-delete-confirm-icon {
            width: 74px;
            height: 74px;
            margin: 0 auto 20px;
            border-radius: 999px;
            border: 3px solid #f4a655;
            color: #f4a655;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            line-height: 1;
            background: linear-gradient(180deg, #fffdfa 0%, #fff7ed 100%);
        }
        .room-delete-confirm-title {
            margin: 0 0 10px;
            color: #273047;
            font-size: 22px;
            font-weight: 800;
            line-height: 1.2;
        }
        .room-delete-confirm-copy {
            margin: 0;
            color: #64748b;
            font-size: 15px;
            line-height: 1.65;
        }
        .room-delete-confirm-actions {
            padding: 14px 30px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #ffffff;
        }
        .room-delete-confirm-btn {
            min-width: 132px;
            min-height: 40px;
            padding: 8px 16px;
            border-radius: 12px;
            border: 2px solid transparent;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .room-delete-confirm-btn:hover {
            transform: translateY(-1px);
        }
        .room-delete-confirm-btn-delete {
            background: #e9415a;
            border-color: #e9415a;
            color: #ffffff;
            box-shadow: none;
        }
        .room-delete-confirm-btn-delete:hover {
            background: #dc354f;
            border-color: #dc354f;
            box-shadow: 0 8px 18px rgba(225, 29, 72, 0.16);
        }
        .room-delete-confirm-btn-cancel {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #273047;
        }
        .room-delete-confirm-btn-cancel:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }
        .room-modal-header {
            padding: 22px 24px 20px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            border-bottom: 1px solid #e5e7eb;
            background: #ffffff;
        }
        .room-modal-copy h2 {
            margin: 0 0 6px;
            color: #0f172a;
            font-size: 24px;
            font-weight: 800;
        }
        .room-modal-copy p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            max-width: 760px;
        }
        .room-modal-close {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid #d8e1ec;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            font-size: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .room-modal-close:hover {
            background: #f8fafc;
            border-color: #bbf7d0;
            color: #166534;
        }
        .room-modal-body {
            flex: 1 1 auto;
            min-height: 0;
            min-height: 620px;
            padding: 22px;
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-gutter: stable;
            display: grid;
            gap: 20px;
        }
        .room-alert {
            padding: 15px 18px;
            border-radius: 16px;
            border: 1px solid transparent;
            font-size: 14px;
            line-height: 1.6;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }
        .room-alert-success {
            background: #ecfdf3;
            color: #166534;
            border-color: #bbf7d0;
        }
        .room-alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        .room-modal-tabs {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            padding: 0 4px 8px;
            border-bottom: 1px solid #dbe4ef;
        }
        .room-modal-tab {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 190px;
            min-height: 42px;
            padding: 0 14px 12px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .room-modal-tab::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -9px;
            height: 4px;
            border-radius: 999px;
            background: transparent;
            transition: background 0.2s ease;
        }
        .room-modal-tab:hover {
            color: #0f172a;
        }
        .room-modal-tab.is-active {
            color: #0f172a;
        }
        .room-modal-tab.is-active::after {
            background: linear-gradient(135deg, #22c55e, #16a34a);
        }
        .room-modal-panel[hidden] {
            display: none !important;
        }
        .room-modal-panel {
            min-height: 480px;
        }
        .room-page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 46px;
            padding: 11px 16px;
            border-radius: 14px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            border: 1px solid transparent;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .room-page-btn:hover {
            transform: translateY(-1px);
        }
        .room-page-btn-primary {
            background: linear-gradient(135deg, #166534, #15803d);
            color: #ffffff;
            box-shadow: 0 12px 26px rgba(21, 128, 61, 0.22);
        }
        .room-page-btn-primary:hover {
            color: #ffffff;
            box-shadow: 0 16px 30px rgba(21, 128, 61, 0.28);
        }
        .room-page-btn-secondary {
            background: #ffffff;
            border-color: rgba(203, 213, 225, 0.96);
            color: #0f172a;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
            cursor: pointer;
        }
        .room-page-btn-secondary:hover {
            color: #166534;
            border-color: #bbf7d0;
            background: #f8fff9;
        }
        .room-page-btn.is-hidden {
            display: none;
        }
        .room-form-card,
        .room-table-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            min-height: 480px;
        }
        .room-form-card {
            display: flex;
            flex-direction: column;
        }
        .room-table-card {
            display: flex;
            flex-direction: column;
        }
        .room-card-head {
            padding: 16px 18px;
            border-bottom: 1px solid #e5e7eb;
        }
        .room-card-head h2 {
            margin: 0 0 4px;
            font-size: 16px;
            color: #0f172a;
        }
        .room-card-head p {
            margin: 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }
        .room-form {
            padding: 22px;
            overflow: visible;
        }
        .room-form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(220px, 0.8fr);
            gap: 18px 20px;
        }
        .room-form-group {
            display: grid;
            gap: 8px;
        }
        .room-form-group.room-form-group-description {
            grid-column: 1 / -1;
        }
        .room-form-group label {
            color: #334155;
            font-size: 14px;
            font-weight: 700;
        }
        .room-input,
        .room-textarea {
            width: 100%;
            border: 1px solid #d8e1ec;
            border-radius: 14px;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
            padding: 13px 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .room-textarea {
            min-height: 120px;
            resize: none;
            line-height: 1.55;
        }
        .room-input:focus,
        .room-textarea:focus {
            outline: none;
            border-color: #15803d;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(21, 128, 61, 0.12);
        }
        .room-toggle-wrap {
            min-height: 48px;
            display: flex;
            align-items: center;
            padding: 0 2px;
        }
        .room-toggle {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
        }
        .room-toggle input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .room-toggle-slider {
            position: relative;
            width: 56px;
            height: 30px;
            border-radius: 999px;
            background: #cbd5e1;
            box-shadow: inset 0 2px 4px rgba(15, 23, 42, 0.1);
            transition: background 0.2s ease;
            flex-shrink: 0;
        }
        .room-toggle-slider::after {
            content: "";
            position: absolute;
            top: 3px;
            left: 3px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.16);
            transition: transform 0.2s ease;
        }
        .room-toggle input[type="checkbox"]:checked + .room-toggle-slider {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }
        .room-toggle input[type="checkbox"]:checked + .room-toggle-slider::after {
            transform: translateX(26px);
        }
        .room-toggle-text {
            line-height: 1.4;
        }
        .room-form-actions {
            margin-top: 22px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }
        .room-submit-btn {
            border: none;
            cursor: pointer;
            order: 2;
        }
        .room-table-wrap {
            flex: 1 1 auto;
            overflow-x: auto;
            overflow-y: visible;
            scrollbar-gutter: stable;
        }
        .room-table {
            width: 100%;
            border-collapse: collapse;
        }
        .room-table th,
        .room-table td {
            padding: 12px 14px;
            text-align: left;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }
        .room-table th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #166534;
            font-weight: 800;
            background: #f8fafc;
            white-space: nowrap;
        }
        .room-table tbody tr:hover {
            background: #fbfdff;
        }
        .room-primary {
            color: #0f172a;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 3px;
        }
        .room-secondary {
            color: #64748b;
            font-size: 11px;
            line-height: 1.45;
        }
        .room-description-copy {
            max-width: 320px;
            color: #475569;
            font-size: 13px;
            line-height: 1.5;
        }
        .room-status-toggle-form {
            margin: 0;
        }
        .room-status-toggle-wrap {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .room-status-toggle-button {
            position: relative;
            width: 58px;
            height: 32px;
            border: none;
            border-radius: 999px;
            background: #cbd5e1;
            box-shadow: inset 0 2px 4px rgba(15, 23, 42, 0.1);
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .room-status-toggle-button:hover {
            transform: translateY(-1px);
        }
        .room-status-toggle-button::after {
            content: "";
            position: absolute;
            top: 3px;
            left: 3px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.16);
            transition: transform 0.2s ease;
        }
        .room-status-toggle-button.is-active {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }
        .room-status-toggle-button.is-active::after {
            transform: translateX(26px);
        }
        .room-status-toggle-button:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(21, 128, 61, 0.18);
        }
        .room-status-toggle-text {
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.35;
        }
        .room-unavailable-note {
            flex-basis: 100%;
            margin-top: 4px;
            max-width: 260px;
            color: #991b1b;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.45;
        }
        .room-unavailable-reason-field {
            width: 100%;
            min-height: 118px;
            resize: vertical;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 13px 14px;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.5;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .room-unavailable-reason-field:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.12);
        }
        #roomUnavailableReasonConfirm .room-delete-confirm-actions {
            padding-top: 12px;
            justify-content: center;
        }
        #roomUnavailableReasonConfirm .room-delete-confirm-btn {
            min-width: 124px;
            min-height: 38px;
            border-radius: 999px;
            font-size: 13px;
        }
        .room-edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .room-edit-btn:hover {
            background: #dcfce7;
            border-color: #86efac;
            color: #14532d;
        }
        .room-table-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        .room-delete-form {
            margin: 0;
        }
        .room-delete-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #b91c1c;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .room-delete-btn:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .room-empty {
            padding: 46px 20px;
            text-align: center;
            color: #64748b;
        }
        .room-empty i {
            font-size: 34px;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        .empty-bookings {
            padding: 46px 20px;
            text-align: center;
            color: #64748b;
        }
        .empty-bookings i {
            font-size: 34px;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        @media (max-width: 1080px) {
            .conference-admin-stats {
                grid-template-columns: 1fr;
            }
            .conference-layout {
                grid-template-columns: 1fr;
            }
            .conference-card-head-tools {
                grid-template-columns: 1fr;
            }
            .conference-search::after,
            .conference-filter-pill::before {
                display: none;
            }
            .conference-filter-pill {
                border-top: 1px solid #ececf2;
            }
        }
        @media (max-width: 760px) {
            .conference-admin-header {
                flex-direction: column;
            }
            .conference-admin-actions {
                width: 100%;
                justify-content: flex-start;
            }
            .conference-admin-page {
                padding-inline: 14px;
            }
            .conference-stat {
                min-height: 156px;
                padding: 18px 18px 16px;
                border-radius: 24px;
            }
            .conference-stat-head {
                gap: 10px;
                margin-bottom: 16px;
            }
            .conference-stat-icon {
                width: 42px;
                height: 42px;
                border-radius: 16px;
            }
            .conference-stat-label {
                font-size: 15px;
            }
            .conference-stat-value {
                font-size: 42px;
            }
            .conference-stat-meta {
                font-size: 13px;
                margin-top: 10px;
            }
            .conference-admin-title h1 {
                font-size: 2.05rem;
            }
            .conference-manage-btn {
                width: 100%;
                justify-content: center;
            }
            .conference-rooms-card,
            .conference-bookings-card {
                border-radius: 22px;
            }
            .conference-card-head,
            .conference-bookings-shell {
                padding-left: 18px;
                padding-right: 18px;
            }
            .conference-card-head h2 {
                font-size: 19px;
            }
            .conference-search input,
            .conference-filter-pill,
            .conference-date-filter {
                min-height: 58px;
            }
            .conference-filter-reset {
                width: auto;
            }
            .conference-table th,
            .conference-table td {
                padding: 20px 18px;
            }
            .conference-table-footer {
                flex-direction: column;
                align-items: center;
            }
            .conference-pagination-controls {
                justify-content: center;
                min-width: 0;
                width: 100%;
            }
            .conference-pagination-controls .pagination-glass {
                max-width: 100%;
                gap: 8px;
                overflow-x: auto;
                padding-bottom: 4px;
            }
            .conference-pagination-controls .page-numbers {
                min-width: 0;
                gap: 8px;
            }
            .conference-pagination-controls .page-btn {
                min-width: 38px;
                height: 38px;
                padding: 0 13px;
                font-size: 13px;
            }
            .conference-pagination-controls .page-btn.prev,
            .conference-pagination-controls .page-btn.next {
                min-width: 92px;
                padding: 0 14px;
            }
            .conference-pagination-controls .pagination-ellipsis {
                min-width: 18px;
                height: 38px;
                font-size: 16px;
            }
            .conference-table-wrap {
                overflow-x: auto;
            }
            .conference-table {
                min-width: 920px;
            }
            .booking-edit-grid {
                grid-template-columns: 1fr;
            }
            .room-modal {
                padding: 12px;
            }
            .room-modal-dialog {
                width: min(100vw - 16px, 1120px);
                max-height: calc(100vh - 12px);
                border-radius: 20px;
            }
            .room-modal-header,
            .room-modal-body,
            .room-card-head,
            .room-form {
                padding: 18px;
            }
            .room-modal-body,
            .room-modal-panel,
            .room-form-card,
            .room-table-card {
                min-height: auto;
            }
            .room-modal-tabs,
            .room-form-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .room-modal-tab {
                text-align: left;
                padding-bottom: 10px;
            }
            .room-form-grid {
                grid-template-columns: 1fr;
            }
            .room-page-btn,
            .room-submit-btn {
                width: 100%;
            }
            .room-delete-confirm-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/admin_navbar.php'; ?>

    <main class="conference-admin-page">
        <div class="conference-admin-header">
            <div class="conference-admin-title">
                <h1>Conference Bookings</h1>
                <p>Manage and monitor all room reservations in one place.</p>
            </div>
            <div class="conference-admin-actions">
                <button type="button" class="conference-manage-btn" id="openManageRoomsModal">
                    <i class="fas fa-door-open"></i>
                    <span>Manage Rooms</span>
                </button>
            </div>
        </div>

        <div class="conference-admin-stats">
            <div class="conference-stat conference-stat-rooms">
                <div class="conference-stat-head">
                    <span class="conference-stat-icon" aria-hidden="true"><i class="fas fa-door-open"></i></span>
                    <div class="conference-stat-label">Active Rooms</div>
                </div>
                <div class="conference-stat-value"><?php echo $roomsBookedTodayCount; ?></div>
                <div class="conference-stat-meta">Rooms with reservations today</div>
            </div>
            <div class="conference-stat conference-stat-total">
                <div class="conference-stat-head">
                    <span class="conference-stat-icon" aria-hidden="true"><i class="fas fa-calendar-check"></i></span>
                    <div class="conference-stat-label">Total Bookings</div>
                </div>
                <div class="conference-stat-value"><?php echo $totalBookings; ?></div>
                <div class="conference-stat-meta">All reservations stored</div>
            </div>
            <div class="conference-stat conference-stat-upcoming">
                <div class="conference-stat-head">
                    <span class="conference-stat-icon" aria-hidden="true"><i class="fas fa-clock"></i></span>
                    <div class="conference-stat-label">Upcoming Bookings</div>
                </div>
                <div class="conference-stat-value"><?php echo $upcomingBookings; ?></div>
                <div class="conference-stat-meta">Scheduled for today and upcoming</div>
            </div>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="conference-alert conference-alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="conference-alert conference-alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="conference-layout">
            <section class="conference-rooms-card" aria-labelledby="conferenceRoomsTitle">
                <div class="conference-rooms-head">
                    <h2 id="conferenceRoomsTitle">Active Rooms</h2>
                    <p>Manage and monitor all room reservations in one place.</p>
                </div>
                <div class="conference-room-list">
                    <?php if (count($rooms) > 0): ?>
                        <?php foreach ($rooms as $room): ?>
                            <?php
                                $roomName = trim((string) ($room['room_name'] ?? 'Conference Room'));
                                $todayCount = (int) ($roomTodayCounts[$roomName] ?? 0);
                            ?>
                            <button type="button" class="conference-room-item room-filter-trigger" data-room-filter="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="conference-room-icon"><i class="fas fa-door-open"></i></span>
                                <span class="conference-room-copy">
                                    <h3><?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <p><?php echo $todayCount; ?> booking<?php echo $todayCount === 1 ? '' : 's'; ?> today</p>
                                </span>
                                <span class="conference-room-arrow"><i class="fas fa-angle-right"></i></span>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <article class="conference-room-item" style="cursor: default;">
                            <span class="conference-room-icon"><i class="fas fa-door-closed"></i></span>
                            <span class="conference-room-copy">
                                <h3>No active rooms</h3>
                                <p>No active conference rooms are currently available in the database.</p>
                            </span>
                            <span class="conference-room-arrow"><i class="fas fa-angle-right"></i></span>
                        </article>
                    <?php endif; ?>
                </div>
            </section>

            <section class="conference-bookings-card" aria-labelledby="conferenceBookingsTitle">
                <div class="conference-card-head">
                    <div class="conference-card-head-copy">
                        <h2 id="conferenceBookingsTitle">All Conference Bookings</h2>
                        <p>Review upcoming and recent reservations with room, schedule, and purpose details.</p>
                    </div>
                    <div class="conference-card-head-tools">
                        <label class="conference-search" for="bookingSearchInput">
                            <i class="fas fa-magnifying-glass"></i>
                            <input type="search" id="bookingSearchInput" placeholder="Search email or room...">
                        </label>
                        <div class="conference-filter-actions">
                            <label class="conference-date-filter" for="bookingDateFilter">
                                <input type="date" id="bookingDateFilter" class="conference-date-input" aria-label="Filter bookings by date">
                            </label>
                            <div class="conference-filter-pill" data-filter-dropdown>
                                <select id="bookingStatusFilter" class="conference-filter-select">
                                    <option value="all">Status</option>
                                    <option value="scheduled">Scheduled</option>
                                    <option value="pending">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                                <button type="button" class="conference-filter-trigger" data-filter-trigger aria-haspopup="listbox" aria-expanded="false">
                                    <span class="conference-filter-text" data-filter-current>Status</span>
                                    <span class="conference-filter-caret"><i class="fas fa-chevron-down"></i></span>
                                </button>
                                <div class="conference-filter-menu" role="listbox">
                                    <button type="button" class="conference-filter-option" data-filter-value="scheduled">Scheduled</button>
                                    <button type="button" class="conference-filter-option" data-filter-value="pending">Ongoing</button>
                                    <button type="button" class="conference-filter-option" data-filter-value="completed">Completed</button>
                                    <button type="button" class="conference-filter-option" data-filter-value="cancelled">Cancelled</button>
                                </div>
                            </div>
                            <button type="button" class="conference-filter-reset" id="bookingFiltersReset" aria-label="Clear booking search and filters">
                                <span>Clear</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="conference-bookings-shell">
                    <?php if (count($bookings) > 0): ?>
                        <div class="conference-table-wrap">
                            <table class="conference-table">
                                <thead>
                                    <tr>
                                        <th>Booking ID</th>
                                        <th>Booked By</th>
                                        <th>Room</th>
                                        <th>Schedule</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="conferenceBookingsTableBody">
                                    <?php foreach ($bookings as $booking): ?>
                                        <?php
                                            $bookingId = (int) ($booking['id'] ?? 0);
                                            $roomName = trim((string) ($booking['room_name'] ?? 'Conference Room'));
                                            $bookingRoomId = (int) ($roomIdByName[$roomName] ?? 0);
                                            $bookedByName = trim((string) ($booking['booked_by_name'] ?? 'Unknown'));
                                            $bookedByEmail = trim((string) ($booking['booked_by_email'] ?? ''));
                                            $bookedByDepartment = trim((string) ($booking['booked_by_department'] ?? ''));
                                            $bookedByCompany = conference_booking_company_short_label((string) ($booking['booked_by_company'] ?? ''));
                                            $purposeText = trim((string) ($booking['purpose'] ?? ''));
                                            $bookingDateValue = trim((string) ($booking['booking_date'] ?? ''));
                                            $startTimeValue = trim((string) ($booking['start_time'] ?? ''));
                                            $endTimeValue = trim((string) ($booking['end_time'] ?? ''));
                                            $createdAtValue = trim((string) ($booking['created_at'] ?? 'now'));
                                            $statusClass = conference_admin_status_badge_class((string) ($booking['status'] ?? 'booked'));
                                            $statusText = conference_admin_booking_status_text((string) ($booking['status'] ?? 'booked'));
                                            $isCancelledBooking = strtolower(trim((string) ($booking['status'] ?? ''))) === 'cancelled';
                                            $bookingDateDisplay = $bookingDateValue !== '' ? date('M d, Y', strtotime($bookingDateValue)) : 'No date';
                                            $scheduleTimeDisplay =
                                                conference_booking_format_time_12h($startTimeValue) . ' - ' .
                                                conference_booking_format_time_12h($endTimeValue);
                                            $createdDateDisplay = $createdAtValue !== '' ? date('M d, Y', strtotime($createdAtValue)) : '';
                                            $createdTimeDisplay = $createdAtValue !== '' ? date('h:i A', strtotime($createdAtValue)) : '';
                                            $userMetaLines = [];
                                            if ($bookedByEmail !== '' && strcasecmp($bookedByEmail, $bookedByName) !== 0) {
                                                $userMetaLines[] = $bookedByEmail;
                                            } elseif ($createdDateDisplay !== '') {
                                                $createdMeta = $createdDateDisplay;
                                                if ($createdTimeDisplay !== '') {
                                                    $createdMeta .= ' · ' . $createdTimeDisplay;
                                                }
                                                $userMetaLines[] = $createdMeta;
                                            }

                                            $searchHaystack = implode(' ', array_filter([
                                                $roomName,
                                                $bookedByName,
                                                $bookedByEmail,
                                                $bookedByDepartment,
                                                $bookedByCompany,
                                                $purposeText,
                                                $bookingDateValue,
                                            ]));
                                        ?>
                                        <tr
                                            class="conference-booking-row"
                                            data-search="<?php echo htmlspecialchars(strtolower($searchHaystack), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-room="<?php echo htmlspecialchars(strtolower($roomName), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-user="<?php echo htmlspecialchars(strtolower($bookedByName), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-booking-date="<?php echo htmlspecialchars($bookingDateValue, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-date="<?php echo htmlspecialchars($bookingDateValue . ' ' . $startTimeValue, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo htmlspecialchars(strtolower($statusText), ENT_QUOTES, 'UTF-8'); ?>"
                                        >
                                            <td>
                                                <div class="booking-id-stack">
                                                    <span class="conference-booking-id">#<?php echo $bookingId; ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="booking-primary"><?php echo htmlspecialchars($bookedByName, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php if (count($userMetaLines) > 0): ?>
                                                    <?php foreach ($userMetaLines as $metaLine): ?>
                                                        <div class="booking-secondary booking-secondary-line"><?php echo htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="booking-secondary booking-secondary-line">No extra profile details</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="booking-room-cell">
                                                    <span class="booking-room-tag"><?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="booking-schedule-date"><?php echo htmlspecialchars($bookingDateDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="booking-schedule-time"><?php echo htmlspecialchars($scheduleTimeDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                                            </td>
                                            <td class="purpose-copy">
                                                <?php echo htmlspecialchars($purposeText !== '' ? $purposeText : 'No purpose provided', ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td>
                                                <span class="status-chip <?php echo $statusClass; ?>">
                                                    <?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="booking-actions">
                                                    <button
                                                        type="button"
                                                        class="delete-booking-btn booking-action-trigger"
                                                        aria-label="Open actions for booking #<?php echo $bookingId; ?>"
                                                        aria-expanded="false"
                                                    >
                                                        <i class="fas fa-ellipsis"></i>
                                                    </button>
                                                    <div class="booking-action-menu" role="menu" aria-label="Booking actions">
                                                        <button
                                                            type="button"
                                                            class="booking-action-item booking-edit-trigger"
                                                            data-booking-id="<?php echo $bookingId; ?>"
                                                            data-booking-room-id="<?php echo $bookingRoomId; ?>"
                                                            data-booking-room="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-date-raw="<?php echo htmlspecialchars($bookingDateValue, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-start-hour="<?php echo htmlspecialchars(date('g', strtotime($startTimeValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-start-minute="<?php echo htmlspecialchars(date('i', strtotime($startTimeValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-start-period="<?php echo htmlspecialchars(date('A', strtotime($startTimeValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-end-hour="<?php echo htmlspecialchars(date('g', strtotime($endTimeValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-end-minute="<?php echo htmlspecialchars(date('i', strtotime($endTimeValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-end-period="<?php echo htmlspecialchars(date('A', strtotime($endTimeValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-purpose="<?php echo htmlspecialchars($purposeText, ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <i class="fas fa-pen-to-square"></i>
                                                            <span>Edit</span>
                                                        </button>
                                                        <?php if (!$isCancelledBooking): ?>
                                                            <button
                                                                type="button"
                                                                class="booking-action-item booking-cancel-trigger"
                                                                data-booking-id="<?php echo $bookingId; ?>"
                                                                data-booking-room="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-booking-date="<?php echo htmlspecialchars(date('M d, Y', strtotime($bookingDateValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-booking-time="<?php echo htmlspecialchars(
                                                                    conference_booking_format_time_12h($startTimeValue) . ' - ' .
                                                                    conference_booking_format_time_12h($endTimeValue),
                                                                    ENT_QUOTES,
                                                                    'UTF-8'
                                                                ); ?>"
                                                            >
                                                                <i class="fas fa-ban"></i>
                                                                <span>Cancel</span>
                                                            </button>
                                                        <?php endif; ?>
                                                        <button
                                                            type="button"
                                                            class="booking-action-item booking-delete-trigger booking-action-item-danger"
                                                            data-booking-id="<?php echo $bookingId; ?>"
                                                            data-booking-room="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-date="<?php echo htmlspecialchars(date('M d, Y', strtotime($bookingDateValue)), ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-booking-time="<?php echo htmlspecialchars(
                                                                conference_booking_format_time_12h($startTimeValue) . ' - ' .
                                                                conference_booking_format_time_12h($endTimeValue),
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            ); ?>"
                                                            aria-label="Delete booking #<?php echo $bookingId; ?>"
                                                        >
                                                            <i class="fas fa-trash"></i>
                                                            <span>Delete</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="conference-table-footer" id="conferenceTableFooter">
                            <div class="conference-pagination-summary" id="conferencePaginationSummary">Showing 0 bookings</div>
                            <div class="conference-pagination-controls" id="conferencePaginationControls"></div>
                        </div>
                    <?php else: ?>
                        <div class="empty-bookings">
                            <i class="fas fa-calendar-days"></i>
                            <div>No conference room bookings have been created yet.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <div class="room-delete-confirm" id="bookingDeleteConfirm" aria-hidden="true">
        <div class="room-delete-confirm-card" role="dialog" aria-modal="true" aria-labelledby="bookingDeleteConfirmTitle">
            <div class="room-delete-confirm-body">
                <div class="room-delete-confirm-icon" aria-hidden="true"><i class="fas fa-exclamation"></i></div>
                <h3 class="room-delete-confirm-title" id="bookingDeleteConfirmTitle">Delete conference booking?</h3>
                <p class="room-delete-confirm-copy" id="bookingDeleteConfirmCopy">This booking will be removed, and the requestor will be notified.</p>
            </div>
            <form method="post" class="room-delete-confirm-actions" id="bookingDeleteConfirmForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete_conference_booking" id="bookingDeleteConfirmAction">
                <input type="hidden" name="booking_id" id="bookingDeleteConfirmBookingId" value="">
                <button type="submit" class="room-delete-confirm-btn room-delete-confirm-btn-delete" id="bookingDeleteConfirmSubmit">Delete</button>
                <button type="button" class="room-delete-confirm-btn room-delete-confirm-btn-cancel" id="bookingDeleteConfirmCancel">Cancel</button>
            </form>
        </div>
    </div>

    <div id="bookingEditModal" class="room-modal<?php echo $openBookingEditModal ? ' is-open' : ''; ?>" aria-hidden="<?php echo $openBookingEditModal ? 'false' : 'true'; ?>">
        <div class="room-modal-backdrop" data-booking-modal-close></div>
        <div class="room-modal-dialog booking-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="bookingEditModalTitle">
            <div class="room-modal-header">
                <div class="room-modal-copy">
                    <h2 id="bookingEditModalTitle">Edit Conference Booking</h2>
                    <p>Update the selected booking details without leaving the conference bookings page.</p>
                </div>
                <button type="button" class="room-modal-close" data-booking-modal-close aria-label="Close booking edit modal">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <form method="post" action="conference_bookings.php" class="booking-edit-form" id="bookingEditForm" autocomplete="off">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="update_conference_booking">
                <input type="hidden" name="booking_id" id="bookingEditBookingId" value="<?php echo (int) ($bookingFormState['booking_id'] ?? 0); ?>">

                <div class="room-form-card">
                    <div class="room-card-head">
                        <h2 id="bookingEditFormTitle">Booking Details</h2>
                        <p id="bookingEditFormHelp">Adjust the room, schedule, or purpose for this booking.</p>
                    </div>
                    <div class="room-form">
                        <div class="booking-edit-grid">
                            <div class="room-form-group">
                                <label for="bookingEditRoomId">Room</label>
                                <select id="bookingEditRoomId" name="room_id" class="room-input" required>
                                    <option value="">Select a room</option>
                                    <?php foreach ($allRooms as $roomOption): ?>
                                        <?php
                                            $roomOptionId = (int) ($roomOption['id'] ?? 0);
                                            $roomOptionName = trim((string) ($roomOption['room_name'] ?? ''));
                                            $roomOptionActive = (int) ($roomOption['is_active'] ?? 0) === 1;
                                        ?>
                                        <option
                                            value="<?php echo $roomOptionId; ?>"
                                            <?php echo ((int) ($bookingFormState['room_id'] ?? 0) === $roomOptionId) ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($roomOptionName . ($roomOptionActive ? '' : ' (Inactive)'), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="room-form-group">
                                <label for="bookingEditDate">Booking Date</label>
                                <input type="date" id="bookingEditDate" name="booking_date" class="room-input" value="<?php echo htmlspecialchars((string) ($bookingFormState['booking_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="room-form-group">
                                <label>Start Time</label>
                                <div class="time-group">
                                    <div class="select-wrapper">
                                        <select id="bookingEditStartHour" name="start_hour" class="room-input" required>
                                            <?php foreach ($hourOptions as $hour): ?>
                                                <option value="<?php echo $hour; ?>" <?php echo (string) $hour === (string) ($bookingStartParts['hour'] ?? '') ? 'selected' : ''; ?>><?php echo $hour; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                    <div class="select-wrapper">
                                        <select id="bookingEditStartMinute" name="start_minute" class="room-input" required>
                                            <?php foreach ($minuteOptions as $minute): ?>
                                                <option value="<?php echo $minute; ?>" <?php echo (string) $minute === (string) ($bookingStartParts['minute'] ?? '') ? 'selected' : ''; ?>><?php echo $minute; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                    <div class="select-wrapper">
                                        <select id="bookingEditStartPeriod" name="start_period" class="room-input" required>
                                            <option value="AM" <?php echo strtoupper((string) ($bookingStartParts['period'] ?? '')) === 'AM' ? 'selected' : ''; ?>>AM</option>
                                            <option value="PM" <?php echo strtoupper((string) ($bookingStartParts['period'] ?? '')) === 'PM' ? 'selected' : ''; ?>>PM</option>
                                        </select>
                                        <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>
                            </div>
                            <div class="room-form-group">
                                <label>End Time</label>
                                <div class="time-group">
                                    <div class="select-wrapper">
                                        <select id="bookingEditEndHour" name="end_hour" class="room-input" required>
                                            <?php foreach ($hourOptions as $hour): ?>
                                                <option value="<?php echo $hour; ?>" <?php echo (string) $hour === (string) ($bookingEndParts['hour'] ?? '') ? 'selected' : ''; ?>><?php echo $hour; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                    <div class="select-wrapper">
                                        <select id="bookingEditEndMinute" name="end_minute" class="room-input" required>
                                            <?php foreach ($minuteOptions as $minute): ?>
                                                <option value="<?php echo $minute; ?>" <?php echo (string) $minute === (string) ($bookingEndParts['minute'] ?? '') ? 'selected' : ''; ?>><?php echo $minute; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                    <div class="select-wrapper">
                                        <select id="bookingEditEndPeriod" name="end_period" class="room-input" required>
                                            <option value="AM" <?php echo strtoupper((string) ($bookingEndParts['period'] ?? '')) === 'AM' ? 'selected' : ''; ?>>AM</option>
                                            <option value="PM" <?php echo strtoupper((string) ($bookingEndParts['period'] ?? '')) === 'PM' ? 'selected' : ''; ?>>PM</option>
                                        </select>
                                        <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                    </div>
                                </div>
                            </div>
                            <div class="room-form-group room-form-group-description room-form-group-full">
                                <label for="bookingEditPurpose">Purpose</label>
                                <textarea id="bookingEditPurpose" name="purpose" class="room-textarea" placeholder="Enter the purpose of this booking" required><?php echo htmlspecialchars((string) ($bookingFormState['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="room-form-group room-form-group-full">
                                <p class="booking-edit-note">A 30-minute buffer is still enforced after every booking when checking for schedule conflicts.</p>
                            </div>
                        </div>

                        <div class="room-form-actions">
                            <button type="submit" class="room-page-btn room-page-btn-primary room-submit-btn">
                                <i class="fas fa-floppy-disk"></i>
                                <span>Save Changes</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="manageRoomsModal" class="room-modal<?php echo $openRoomModal ? ' is-open' : ''; ?>" aria-hidden="<?php echo $openRoomModal ? 'false' : 'true'; ?>">
        <div class="room-modal-backdrop" data-room-modal-close></div>
        <div class="room-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="roomModalTitle">
            <div class="room-modal-header">
                <div class="room-modal-copy">
                    <h2 id="roomModalTitle">Manage Conference Rooms</h2>
                    <p>Add new conference rooms and update room details without leaving the conference bookings page.</p>
                </div>
                <button type="button" class="room-modal-close" data-room-modal-close aria-label="Close manage rooms modal">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>

            <div class="room-modal-body">
                <?php if ($roomSuccessMessage !== ''): ?>
                    <div class="room-alert room-alert-success"><?php echo htmlspecialchars($roomSuccessMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <?php if ($roomErrorMessage !== ''): ?>
                    <div class="room-alert room-alert-error"><?php echo htmlspecialchars($roomErrorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>

                <div class="room-modal-tabs" role="tablist" aria-label="Manage conference room views">
                    <button
                        type="button"
                        class="room-modal-tab<?php echo $openRoomModalView === 'form' ? ' is-active' : ''; ?>"
                        id="roomTabForm"
                        data-room-tab="form"
                        role="tab"
                        aria-selected="<?php echo $openRoomModalView === 'form' ? 'true' : 'false'; ?>"
                        aria-controls="roomFormPanel"
                    >
                        Add Room
                    </button>
                    <button
                        type="button"
                        class="room-modal-tab<?php echo $openRoomModalView === 'list' ? ' is-active' : ''; ?>"
                        id="roomTabList"
                        data-room-tab="list"
                        role="tab"
                        aria-selected="<?php echo $openRoomModalView === 'list' ? 'true' : 'false'; ?>"
                        aria-controls="roomListPanel"
                    >
                        Conference Rooms
                    </button>
                </div>

                <section class="room-form-card room-modal-panel" id="roomFormPanel" data-room-panel="form" aria-labelledby="roomFormTitle"<?php echo $openRoomModalView === 'list' ? ' hidden' : ''; ?>>
                    <div class="room-card-head">
                        <h2 id="roomFormTitle"><?php echo $roomFormMode === 'edit' ? 'Edit Room' : 'Add New Room'; ?></h2>
                        <p id="roomFormHelp"><?php echo $roomFormMode === 'edit' ? 'Update the room name and description for this conference room.' : 'Create a new conference room that employees can use in the booking portal.'; ?></p>
                    </div>
                    <form method="POST" action="conference_bookings.php" class="room-form" autocomplete="off" id="roomManageForm">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" id="roomFormAction" value="<?php echo $roomFormMode === 'edit' ? 'update_conference_room' : 'add_conference_room'; ?>">
                        <input type="hidden" name="room_id" id="roomFormRoomId" value="<?php echo (int) ($roomFormState['room_id'] ?? 0); ?>">
                        <input type="hidden" name="is_active" id="roomFormActiveValue" value="<?php echo (int) ($roomFormState['is_active'] ?? 1) === 1 ? '1' : '0'; ?>">

                        <div class="room-form-grid">
                            <div class="room-form-group">
                                <label for="roomFormName">Room Name</label>
                                <input type="text" id="roomFormName" name="room_name" class="room-input" value="<?php echo htmlspecialchars((string) ($roomFormState['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="room-form-group room-form-group-description">
                                <label for="roomFormDescription">Description</label>
                                <textarea id="roomFormDescription" name="description" class="room-textarea" placeholder="Optional details about the room"><?php echo htmlspecialchars((string) ($roomFormState['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>

                        <div class="room-form-actions">
                            <button type="submit" class="room-page-btn room-page-btn-primary room-submit-btn">
                                <i class="fas fa-floppy-disk"></i>
                                <span id="roomFormSubmitText"><?php echo $roomFormMode === 'edit' ? 'Save Changes' : 'Save Room'; ?></span>
                            </button>
                            <button type="button" id="roomFormCancelEdit" class="room-page-btn room-page-btn-secondary<?php echo $roomFormMode === 'edit' ? '' : ' is-hidden'; ?>">
                                <i class="fas fa-rotate-left"></i>
                                <span>Cancel Edit</span>
                            </button>
                        </div>
                    </form>
                </section>

                <section class="room-table-card room-modal-panel" id="roomListPanel" data-room-panel="list" aria-labelledby="roomTableTitle"<?php echo $openRoomModalView === 'list' ? '' : ' hidden'; ?>>
                    <div class="room-card-head">
                        <h2 id="roomTableTitle">All Conference Rooms</h2>
                        <p>Review room names, descriptions, active status, and Saturday availability, then edit any room directly from this modal.</p>
                    </div>

                    <?php if ($totalRooms > 0): ?>
                        <div class="room-table-wrap">
                            <table class="room-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Room Name</th>
                                        <th>Description</th>
                                        <th>Status</th>
                                        <th>Saturday</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allRooms as $room): ?>
                                        <?php $roomId = (int) ($room['id'] ?? 0); ?>
                                        <tr>
                                            <td>
                                                <div class="room-primary">#<?php echo $roomId; ?></div>
                                                <div class="room-secondary"><?php echo htmlspecialchars(date('M d, Y', strtotime((string) ($room['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></div>
                                            </td>
                                            <td>
                                                <div class="room-primary"><?php echo htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            </td>
                                            <td class="room-description-copy">
                                                <?php echo htmlspecialchars(trim((string) ($room['description'] ?? '')) !== '' ? (string) $room['description'] : 'No description provided.', ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td>
                                                <?php $isActive = (int) ($room['is_active'] ?? 0) === 1; ?>
                                                <form method="post" class="room-status-toggle-form">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="toggle_conference_room_status">
                                                    <input type="hidden" name="room_id" value="<?php echo $roomId; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $isActive ? '0' : '1'; ?>">
                                                    <input type="hidden" name="unavailable_reason" value="">
                                                    <div class="room-status-toggle-wrap">
                                                        <button
                                                            type="submit"
                                                            class="room-status-toggle-button <?php echo $isActive ? 'is-active' : ''; ?>"
                                                            data-room-status-toggle
                                                            data-room-name="<?php echo htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                            aria-label="<?php echo $isActive ? 'Mark room as unavailable for booking' : 'Mark room as available for booking'; ?>"
                                                            title="<?php echo $isActive ? 'Set as unavailable for booking' : 'Set as available for booking'; ?>"
                                                        ></button>
                                                        <span class="room-status-toggle-text">
                                                            <?php echo htmlspecialchars(conference_room_status_text((int) ($room['is_active'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                        <?php if (!$isActive && trim((string) ($room['unavailable_reason'] ?? '')) !== ''): ?>
                                                            <div class="room-unavailable-note">
                                                                Reason: <?php echo htmlspecialchars((string) ($room['unavailable_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </form>
                                            </td>
                                            <td>
                                                <?php $isSaturdayEnabled = (int) ($room['saturday_enabled'] ?? 0) === 1; ?>
                                                <form method="post" class="room-status-toggle-form">
                                                    <?= csrf_field(); ?>
                                                    <input type="hidden" name="action" value="toggle_conference_room_saturday">
                                                    <input type="hidden" name="room_id" value="<?php echo $roomId; ?>">
                                                    <input type="hidden" name="saturday_enabled" value="<?php echo $isSaturdayEnabled ? '0' : '1'; ?>">
                                                    <div class="room-status-toggle-wrap">
                                                        <button
                                                            type="submit"
                                                            class="room-status-toggle-button <?php echo $isSaturdayEnabled ? 'is-active' : ''; ?>"
                                                            aria-label="<?php echo $isSaturdayEnabled ? 'Disable Saturday booking for this room' : 'Enable Saturday booking for this room'; ?>"
                                                            title="<?php echo $isSaturdayEnabled ? 'Disable Saturday booking' : 'Enable Saturday booking'; ?>"
                                                        ></button>
                                                        <span class="room-status-toggle-text">
                                                            <?php echo htmlspecialchars(conference_room_saturday_text((int) ($room['saturday_enabled'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    </div>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="room-table-actions">
                                                    <button
                                                        type="button"
                                                        class="room-edit-btn room-edit-trigger"
                                                        data-room-id="<?php echo $roomId; ?>"
                                                        data-room-name="<?php echo htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-room-description="<?php echo htmlspecialchars((string) ($room['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-room-active="<?php echo (int) ($room['is_active'] ?? 0); ?>"
                                                        data-room-saturday-enabled="<?php echo (int) ($room['saturday_enabled'] ?? 0); ?>"
                                                    >
                                                        <i class="fas fa-pen-to-square"></i>
                                                        <span>Edit</span>
                                                    </button>
                                                    <form method="post" class="room-delete-form">
                                                        <button
                                                            type="button"
                                                            class="room-delete-btn room-delete-trigger"
                                                            data-room-id="<?php echo $roomId; ?>"
                                                            data-room-name="<?php echo htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <i class="fas fa-trash"></i>
                                                            <span>Delete</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="room-empty">
                            <i class="fas fa-door-open"></i>
                            <div>No conference rooms have been created yet.</div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <div class="room-delete-confirm" id="roomDeleteConfirm" aria-hidden="true">
                <div class="room-delete-confirm-card" role="dialog" aria-modal="true" aria-labelledby="roomDeleteConfirmTitle">
                    <div class="room-delete-confirm-body">
                        <div class="room-delete-confirm-icon" aria-hidden="true"><i class="fas fa-exclamation"></i></div>
                        <h3 class="room-delete-confirm-title" id="roomDeleteConfirmTitle">Delete conference room?</h3>
                        <p class="room-delete-confirm-copy" id="roomDeleteConfirmCopy">This room will be removed from the conference room list.</p>
                    </div>
                    <form method="post" class="room-delete-confirm-actions" id="roomDeleteConfirmForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete_conference_room">
                        <input type="hidden" name="room_id" id="roomDeleteConfirmRoomId" value="">
                        <button type="submit" class="room-delete-confirm-btn room-delete-confirm-btn-delete">Delete</button>
                        <button type="button" class="room-delete-confirm-btn room-delete-confirm-btn-cancel" id="roomDeleteConfirmCancel">Cancel</button>
                    </form>
                </div>
            </div>
            <div class="room-delete-confirm" id="roomUnavailableReasonConfirm" aria-hidden="true">
                <div class="room-delete-confirm-card" role="dialog" aria-modal="true" aria-labelledby="roomUnavailableReasonTitle">
                    <div class="room-delete-confirm-body">
                        <div class="room-delete-confirm-icon" aria-hidden="true"><i class="fas fa-note-sticky"></i></div>
                        <h3 class="room-delete-confirm-title" id="roomUnavailableReasonTitle">Turn room off?</h3>
                        <p class="room-delete-confirm-copy" id="roomUnavailableReasonCopy">Add a note explaining why this room will be unavailable for booking.</p>
                        <textarea id="roomUnavailableReasonInput" class="room-unavailable-reason-field" placeholder="Enter reason..." required></textarea>
                    </div>
                    <div class="room-delete-confirm-actions">
                        <button type="button" class="room-delete-confirm-btn room-delete-confirm-btn-delete" id="roomUnavailableReasonSubmit">Turn Off Room</button>
                        <button type="button" class="room-delete-confirm-btn room-delete-confirm-btn-cancel" id="roomUnavailableReasonCancel">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('manageRoomsModal');
            const bookingEditModal = document.getElementById('bookingEditModal');
            const openButton = document.getElementById('openManageRoomsModal');
            const closeTargets = document.querySelectorAll('[data-room-modal-close]');
            const bookingModalCloseTargets = document.querySelectorAll('[data-booking-modal-close]');
            const addButton = document.getElementById('roomModalAddBtn');
            const roomTabButtons = document.querySelectorAll('[data-room-tab]');
            const roomPanels = document.querySelectorAll('[data-room-panel]');
            const editButtons = document.querySelectorAll('.room-edit-trigger');
            const deleteButtons = document.querySelectorAll('.room-delete-trigger');
            const bookingActionTriggers = document.querySelectorAll('.booking-action-trigger');
            const bookingEditButtons = document.querySelectorAll('.booking-edit-trigger');
            const bookingDeleteButtons = document.querySelectorAll('.booking-delete-trigger');
            const bookingCancelButtons = document.querySelectorAll('.booking-cancel-trigger');
            const cancelEditButton = document.getElementById('roomFormCancelEdit');
            const bookingEditCancelBtn = document.getElementById('bookingEditCancelBtn');
            const roomFormAction = document.getElementById('roomFormAction');
            const roomFormRoomId = document.getElementById('roomFormRoomId');
            const roomFormName = document.getElementById('roomFormName');
            const roomFormDescription = document.getElementById('roomFormDescription');
            const roomFormActiveValue = document.getElementById('roomFormActiveValue');
            const roomFormTitle = document.getElementById('roomFormTitle');
            const roomFormHelp = document.getElementById('roomFormHelp');
            const roomFormSubmitText = document.getElementById('roomFormSubmitText');
            const bookingEditBookingId = document.getElementById('bookingEditBookingId');
            const bookingEditRoomId = document.getElementById('bookingEditRoomId');
            const bookingEditDate = document.getElementById('bookingEditDate');
            const bookingEditStartHour = document.getElementById('bookingEditStartHour');
            const bookingEditStartMinute = document.getElementById('bookingEditStartMinute');
            const bookingEditStartPeriod = document.getElementById('bookingEditStartPeriod');
            const bookingEditEndHour = document.getElementById('bookingEditEndHour');
            const bookingEditEndMinute = document.getElementById('bookingEditEndMinute');
            const bookingEditEndPeriod = document.getElementById('bookingEditEndPeriod');
            const bookingEditPurpose = document.getElementById('bookingEditPurpose');
            const deleteConfirm = document.getElementById('roomDeleteConfirm');
            const deleteConfirmRoomId = document.getElementById('roomDeleteConfirmRoomId');
            const deleteConfirmCopy = document.getElementById('roomDeleteConfirmCopy');
            const deleteConfirmCancel = document.getElementById('roomDeleteConfirmCancel');
            const unavailableReasonConfirm = document.getElementById('roomUnavailableReasonConfirm');
            const unavailableReasonTitle = document.getElementById('roomUnavailableReasonTitle');
            const unavailableReasonCopy = document.getElementById('roomUnavailableReasonCopy');
            const unavailableReasonInput = document.getElementById('roomUnavailableReasonInput');
            const unavailableReasonSubmit = document.getElementById('roomUnavailableReasonSubmit');
            const unavailableReasonCancel = document.getElementById('roomUnavailableReasonCancel');
            const bookingDeleteConfirm = document.getElementById('bookingDeleteConfirm');
            const bookingDeleteConfirmTitle = document.getElementById('bookingDeleteConfirmTitle');
            const bookingDeleteConfirmBookingId = document.getElementById('bookingDeleteConfirmBookingId');
            const bookingDeleteConfirmAction = document.getElementById('bookingDeleteConfirmAction');
            const bookingDeleteConfirmCopy = document.getElementById('bookingDeleteConfirmCopy');
            const bookingDeleteConfirmSubmit = document.getElementById('bookingDeleteConfirmSubmit');
            const bookingDeleteConfirmCancel = document.getElementById('bookingDeleteConfirmCancel');
            const bookingSearchInput = document.getElementById('bookingSearchInput');
            const bookingDateFilter = document.getElementById('bookingDateFilter');
            const bookingStatusFilter = document.getElementById('bookingStatusFilter');
            const bookingFiltersReset = document.getElementById('bookingFiltersReset');
            const bookingsTableBody = document.getElementById('conferenceBookingsTableBody');
            const conferenceTableFooter = document.getElementById('conferenceTableFooter');
            const conferencePaginationSummary = document.getElementById('conferencePaginationSummary');
            const conferencePaginationControls = document.getElementById('conferencePaginationControls');
            const bookingRows = Array.from(document.querySelectorAll('.conference-booking-row'));
            const roomFilterButtons = document.querySelectorAll('.room-filter-trigger');
            const bookingsPerPage = 5;
            let currentBookingPage = 1;
            let pendingUnavailableForm = null;
            const roomFormDefaults = <?php echo json_encode([
                'mode' => $roomFormMode,
                'room_id' => (int) ($roomFormState['room_id'] ?? 0),
                'room_name' => (string) ($roomFormState['room_name'] ?? ''),
                'description' => (string) ($roomFormState['description'] ?? ''),
                'is_active' => (int) ($roomFormState['is_active'] ?? 1),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const roomModalDefaultView = <?php echo json_encode($openRoomModalView, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const bookingFormDefaults = <?php echo json_encode([
                'booking_id' => (int) ($bookingFormState['booking_id'] ?? 0),
                'room_id' => (int) ($bookingFormState['room_id'] ?? 0),
                'booking_date' => (string) ($bookingFormState['booking_date'] ?? ''),
                'start_time' => (string) ($bookingFormState['start_time'] ?? ''),
                'end_time' => (string) ($bookingFormState['end_time'] ?? ''),
                'purpose' => (string) ($bookingFormState['purpose'] ?? ''),
                'start_hour' => (string) ($bookingStartParts['hour'] ?? '9'),
                'start_minute' => (string) ($bookingStartParts['minute'] ?? '00'),
                'start_period' => (string) ($bookingStartParts['period'] ?? 'AM'),
                'end_hour' => (string) ($bookingEndParts['hour'] ?? '10'),
                'end_minute' => (string) ($bookingEndParts['minute'] ?? '00'),
                'end_period' => (string) ($bookingEndParts['period'] ?? 'AM'),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const shouldOpenModal = <?php echo $openRoomModal ? 'true' : 'false'; ?>;
            const shouldOpenBookingEditModal = <?php echo $openBookingEditModal ? 'true' : 'false'; ?>;

            if (!modal || !bookingEditModal || !roomFormAction || !roomFormRoomId || !roomFormName || !roomFormDescription || !roomFormActiveValue || !roomFormTitle || !roomFormHelp || !roomFormSubmitText || !cancelEditButton || !bookingEditBookingId || !bookingEditRoomId || !bookingEditDate || !bookingEditStartHour || !bookingEditStartMinute || !bookingEditStartPeriod || !bookingEditEndHour || !bookingEditEndMinute || !bookingEditEndPeriod || !bookingEditPurpose || !deleteConfirm || !deleteConfirmRoomId || !deleteConfirmCopy || !deleteConfirmCancel || !unavailableReasonConfirm || !unavailableReasonTitle || !unavailableReasonCopy || !unavailableReasonInput || !unavailableReasonSubmit || !unavailableReasonCancel || !bookingDeleteConfirm || !bookingDeleteConfirmTitle || !bookingDeleteConfirmBookingId || !bookingDeleteConfirmAction || !bookingDeleteConfirmCopy || !bookingDeleteConfirmSubmit || !bookingDeleteConfirmCancel) {
                return;
            }

            function syncBodyModalState() {
                const hasOpenOverlay =
                    modal.classList.contains('is-open') ||
                    bookingEditModal.classList.contains('is-open') ||
                    deleteConfirm.classList.contains('is-open') ||
                    unavailableReasonConfirm.classList.contains('is-open') ||
                    bookingDeleteConfirm.classList.contains('is-open');
                document.body.classList.toggle('room-modal-active', hasOpenOverlay);
            }

            function closeBookingActionMenus(exceptMenu) {
                document.querySelectorAll('.booking-action-menu.is-open').forEach(function (menu) {
                    if (exceptMenu && menu === exceptMenu) {
                        return;
                    }
                    menu.classList.remove('is-open');
                    const actionRoot = menu.closest('.booking-actions');
                    const trigger = actionRoot ? actionRoot.querySelector('.booking-action-trigger') : null;
                    if (trigger) {
                        trigger.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            function openModal() {
                closeBookingActionMenus();
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                syncBodyModalState();
            }

            function closeModal() {
                closeDeleteConfirm();
                closeUnavailableReasonConfirm();
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                syncBodyModalState();
            }

            function openBookingEditDialog() {
                closeBookingActionMenus();
                bookingEditModal.classList.add('is-open');
                bookingEditModal.setAttribute('aria-hidden', 'false');
                syncBodyModalState();
            }

            function closeBookingEditDialog() {
                bookingEditModal.classList.remove('is-open');
                bookingEditModal.setAttribute('aria-hidden', 'true');
                syncBodyModalState();
            }

            function setBookingEditMode(data) {
                bookingEditBookingId.value = String(data.booking_id || '');
                bookingEditRoomId.value = String(data.room_id || '');
                bookingEditDate.value = String(data.booking_date || '');
                bookingEditStartHour.value = String(data.start_hour || '');
                bookingEditStartMinute.value = String(data.start_minute || '');
                bookingEditStartPeriod.value = String(data.start_period || 'AM').toUpperCase();
                bookingEditEndHour.value = String(data.end_hour || '');
                bookingEditEndMinute.value = String(data.end_minute || '');
                bookingEditEndPeriod.value = String(data.end_period || 'AM').toUpperCase();
                bookingEditPurpose.value = String(data.purpose || '');
            }

            function openBookingActionConfirm(mode, bookingId, roomName, bookingDate, bookingTime) {
                bookingDeleteConfirmBookingId.value = String(bookingId || '');
                const detailParts = [];

                if (roomName) {
                    detailParts.push(roomName);
                }

                if (bookingDate) {
                    detailParts.push(bookingDate);
                }

                if (bookingTime) {
                    detailParts.push(bookingTime);
                }

                if (mode === 'cancel') {
                    bookingDeleteConfirmTitle.textContent = 'Cancel conference booking?';
                    bookingDeleteConfirmAction.value = 'cancel_conference_booking';
                    bookingDeleteConfirmSubmit.textContent = 'Cancel Booking';
                    bookingDeleteConfirmCopy.textContent = 'This will cancel the selected booking.';
                } else {
                    bookingDeleteConfirmTitle.textContent = 'Delete conference booking?';
                    bookingDeleteConfirmAction.value = 'delete_conference_booking';
                    bookingDeleteConfirmSubmit.textContent = 'Delete';
                    bookingDeleteConfirmCopy.textContent = 'This will delete the selected booking.';
                }

                closeBookingActionMenus();
                bookingDeleteConfirm.classList.add('is-open');
                bookingDeleteConfirm.setAttribute('aria-hidden', 'false');
                syncBodyModalState();
            }

            function closeBookingDeleteConfirm() {
                bookingDeleteConfirm.classList.remove('is-open');
                bookingDeleteConfirm.setAttribute('aria-hidden', 'true');
                bookingDeleteConfirmBookingId.value = '';
                bookingDeleteConfirmAction.value = 'delete_conference_booking';
                bookingDeleteConfirmTitle.textContent = 'Delete conference booking?';
                bookingDeleteConfirmSubmit.textContent = 'Delete';
                syncBodyModalState();
            }

            function openDeleteConfirm(roomId, roomName) {
                deleteConfirmRoomId.value = String(roomId || '');
                deleteConfirmCopy.textContent = roomName
                    ? 'This will permanently remove "' + roomName + '" from the conference room list.'
                    : 'This will permanently remove the selected conference room from the conference room list.';
                deleteConfirm.classList.add('is-open');
                deleteConfirm.setAttribute('aria-hidden', 'false');
                syncBodyModalState();
            }

            function closeDeleteConfirm() {
                deleteConfirm.classList.remove('is-open');
                deleteConfirm.setAttribute('aria-hidden', 'true');
                deleteConfirmRoomId.value = '';
                syncBodyModalState();
            }

            function openUnavailableReasonConfirm(form, roomName) {
                pendingUnavailableForm = form;
                unavailableReasonInput.value = '';
                unavailableReasonTitle.textContent = roomName ? 'Turn off ' + roomName + '?' : 'Turn room off?';
                unavailableReasonCopy.textContent = 'Add a note explaining why this room will be unavailable for booking.';
                unavailableReasonConfirm.classList.add('is-open');
                unavailableReasonConfirm.setAttribute('aria-hidden', 'false');
                syncBodyModalState();
                setTimeout(function () {
                    unavailableReasonInput.focus();
                }, 30);
            }

            function closeUnavailableReasonConfirm() {
                unavailableReasonConfirm.classList.remove('is-open');
                unavailableReasonConfirm.setAttribute('aria-hidden', 'true');
                unavailableReasonInput.value = '';
                pendingUnavailableForm = null;
                syncBodyModalState();
            }

            function setRoomModalTab(view) {
                roomTabButtons.forEach(function (button) {
                    const isActive = button.getAttribute('data-room-tab') === view;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                roomPanels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-room-panel') !== view;
                });
            }

            function setAddMode(preserveValues) {
                roomFormAction.value = 'add_conference_room';
                roomFormRoomId.value = '';
                roomFormTitle.textContent = 'Add New Room';
                roomFormHelp.textContent = 'Create a new conference room that employees can use in the booking portal.';
                roomFormSubmitText.textContent = 'Save Room';
                cancelEditButton.classList.add('is-hidden');

                if (!preserveValues) {
                    roomFormName.value = '';
                    roomFormDescription.value = '';
                    roomFormActiveValue.value = '1';
                }
            }

            function setEditMode(data) {
                roomFormAction.value = 'update_conference_room';
                roomFormRoomId.value = String(data.room_id || '');
                roomFormTitle.textContent = 'Edit Room';
                roomFormHelp.textContent = 'Update the room name and description for this conference room.';
                roomFormSubmitText.textContent = 'Save Changes';
                cancelEditButton.classList.remove('is-hidden');
                roomFormName.value = String(data.room_name || '');
                roomFormDescription.value = String(data.description || '');
                roomFormActiveValue.value = String(String(data.is_active || '0') === '1' || Number(data.is_active || 0) === 1 ? '1' : '0');
            }

            function closeFilterDropdowns(exceptDropdown) {
                document.querySelectorAll('[data-filter-dropdown]').forEach(function (dropdown) {
                    if (exceptDropdown && dropdown === exceptDropdown) {
                        return;
                    }

                    const trigger = dropdown.querySelector('[data-filter-trigger]');
                    const menu = dropdown.querySelector('.conference-filter-menu');
                    if (trigger) {
                        trigger.setAttribute('aria-expanded', 'false');
                    }
                    if (menu) {
                        menu.classList.remove('is-open');
                    }
                });
            }

            function syncFilterDropdown(dropdown) {
                if (!dropdown) {
                    return;
                }

                const select = dropdown.querySelector('.conference-filter-select');
                const current = dropdown.querySelector('[data-filter-current]');
                if (!select || !current) {
                    return;
                }

                const selectedOption = select.options[select.selectedIndex];
                const fixedLabel = current.getAttribute('data-filter-label');
                current.textContent = fixedLabel !== null && fixedLabel !== ''
                    ? fixedLabel
                    : (selectedOption ? selectedOption.textContent : '');

                dropdown.querySelectorAll('.conference-filter-option').forEach(function (optionButton) {
                    optionButton.classList.toggle('is-active', optionButton.getAttribute('data-filter-value') === select.value);
                });
            }

            function renderBookingPagination(filteredRows) {
                if (!conferencePaginationSummary || !conferencePaginationControls || !conferenceTableFooter) {
                    filteredRows.forEach(function (row, index) {
                        row.hidden = index >= bookingsPerPage;
                    });
                    return;
                }

                const totalRows = filteredRows.length;
                const totalPages = Math.max(1, Math.ceil(totalRows / bookingsPerPage));
                currentBookingPage = Math.min(Math.max(currentBookingPage, 1), totalPages);

                conferenceTableFooter.hidden = false;
                conferencePaginationControls.innerHTML = '';

                if (totalRows === 0) {
                    conferencePaginationSummary.textContent = 'No bookings match your current filters.';
                    return;
                }

                const startIndex = (currentBookingPage - 1) * bookingsPerPage;
                const endIndex = Math.min(startIndex + bookingsPerPage, totalRows);

                filteredRows.forEach(function (row, index) {
                    row.hidden = index < startIndex || index >= endIndex;
                });

                conferencePaginationSummary.textContent = 'Showing ' + (startIndex + 1) + '-' + endIndex + ' of ' + totalRows + ' bookings';

                const paginationShell = document.createElement('div');
                paginationShell.className = 'pagination-glass';
                const pageNumbers = document.createElement('div');
                pageNumbers.className = 'page-numbers';

                function createPaginationButton(label, page, disabled, isActive, extraClass, target) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'page-btn' + (extraClass ? ' ' + extraClass : '') + (isActive ? ' active' : '') + (disabled ? ' disabled' : '');
                    button.textContent = label;
                    button.disabled = disabled;
                    button.setAttribute('aria-label', isActive ? 'Current page, page ' + page : 'Go to page ' + page);
                    if (isActive) {
                        button.setAttribute('aria-current', 'page');
                    }
                    button.addEventListener('click', function () {
                        if (disabled || page === currentBookingPage) {
                            return;
                        }
                        currentBookingPage = page;
                        renderBookingPagination(filteredRows);
                    });
                    target.appendChild(button);
                }

                function appendEllipsis() {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'pagination-ellipsis';
                    ellipsis.textContent = '...';
                    pageNumbers.appendChild(ellipsis);
                }

                function paginationPages(page, totalPages) {
                    const pages = [];
                    if (totalPages <= 5) {
                        for (let i = 1; i <= totalPages; i += 1) {
                            pages.push(i);
                        }
                        return pages;
                    }

                    pages.push(1);
                    let windowStart = Math.max(2, page - 1);
                    let windowEnd = Math.min(totalPages - 1, page + 1);

                    if (page <= 3) {
                        windowStart = 2;
                        windowEnd = 3;
                    } else if (page >= totalPages - 2) {
                        windowStart = totalPages - 2;
                        windowEnd = totalPages - 1;
                    }

                    if (windowStart > 2) {
                        pages.push('ellipsis');
                    }
                    for (let i = windowStart; i <= windowEnd; i += 1) {
                        pages.push(i);
                    }
                    if (windowEnd < totalPages - 1) {
                        pages.push('ellipsis');
                    }
                    pages.push(totalPages);
                    return pages;
                }

                createPaginationButton('‹ Previous', Math.max(1, currentBookingPage - 1), currentBookingPage === 1, false, 'prev', paginationShell);

                paginationPages(currentBookingPage, totalPages).forEach(function (paginationItem) {
                    if (paginationItem === 'ellipsis') {
                        appendEllipsis();
                        return;
                    }

                    createPaginationButton(String(paginationItem), paginationItem, false, paginationItem === currentBookingPage, '', pageNumbers);
                });

                paginationShell.appendChild(pageNumbers);
                createPaginationButton('Next ›', Math.min(totalPages, currentBookingPage + 1), currentBookingPage === totalPages, false, 'next', paginationShell);
                conferencePaginationControls.appendChild(paginationShell);
            }

            function applyBookingFilters(resetPage) {
                if (!bookingsTableBody || bookingRows.length === 0) {
                    return;
                }

                if (resetPage) {
                    currentBookingPage = 1;
                }

                const term = bookingSearchInput ? bookingSearchInput.value.trim().toLowerCase() : '';
                const selectedDate = bookingDateFilter ? bookingDateFilter.value.trim() : '';
                const statusFilter = bookingStatusFilter ? bookingStatusFilter.value.trim().toLowerCase() : 'all';
                const sortedRows = bookingRows.slice();
                const filteredRows = [];

                sortedRows.forEach(function (row) {
                    bookingsTableBody.appendChild(row);
                    const haystack = String(row.dataset.search || '');
                    const rowBookingDate = String(row.dataset.bookingDate || '');
                    const rowStatus = String(row.dataset.status || '').toLowerCase();
                    const matchesSearch = term === '' || haystack.indexOf(term) !== -1;
                    const matchesDate = selectedDate === '' || rowBookingDate === selectedDate;
                    const matchesStatus = statusFilter === 'all' || rowStatus === statusFilter;
                    row.hidden = true;

                    if (matchesSearch && matchesDate && matchesStatus) {
                        filteredRows.push(row);
                    }
                });

                renderBookingPagination(filteredRows);

                roomFilterButtons.forEach(function (button) {
                    const roomFilter = String(button.getAttribute('data-room-filter') || '').trim().toLowerCase();
                    button.classList.toggle('is-active', term !== '' && roomFilter === term);
                });
            }

            if (openButton) {
                openButton.addEventListener('click', function () {
                    setAddMode(false);
                    setRoomModalTab('form');
                    openModal();
                });
            }

            if (addButton) {
                addButton.addEventListener('click', function () {
                    setAddMode(false);
                    setRoomModalTab('form');
                    roomFormName.focus();
                });
            }

            if (cancelEditButton) {
                cancelEditButton.addEventListener('click', function () {
                    setAddMode(false);
                    setRoomModalTab('form');
                    roomFormName.focus();
                });
            }

            roomTabButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetView = button.getAttribute('data-room-tab') || 'form';

                    if (targetView === 'form') {
                        setAddMode(false);
                    }

                    setRoomModalTab(targetView);
                });
            });

            editButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setEditMode({
                        room_id: button.getAttribute('data-room-id') || '',
                        room_name: button.getAttribute('data-room-name') || '',
                        description: button.getAttribute('data-room-description') || '',
                        is_active: button.getAttribute('data-room-active') || '0'
                    });
                    setRoomModalTab('form');
                    openModal();
                    roomFormName.focus();
                });
            });

            bookingActionTriggers.forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    const actionRoot = button.closest('.booking-actions');
                    const menu = actionRoot ? actionRoot.querySelector('.booking-action-menu') : null;
                    if (!menu) {
                        return;
                    }
                    const shouldOpen = !menu.classList.contains('is-open');
                    closeBookingActionMenus(shouldOpen ? menu : null);
                    menu.classList.toggle('is-open', shouldOpen);
                    button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                });
            });

            bookingEditButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setBookingEditMode({
                        booking_id: button.getAttribute('data-booking-id') || '',
                        room_id: button.getAttribute('data-booking-room-id') || '',
                        booking_date: button.getAttribute('data-booking-date-raw') || '',
                        start_hour: button.getAttribute('data-booking-start-hour') || '',
                        start_minute: button.getAttribute('data-booking-start-minute') || '',
                        start_period: button.getAttribute('data-booking-start-period') || 'AM',
                        end_hour: button.getAttribute('data-booking-end-hour') || '',
                        end_minute: button.getAttribute('data-booking-end-minute') || '',
                        end_period: button.getAttribute('data-booking-end-period') || 'AM',
                        purpose: button.getAttribute('data-booking-purpose') || ''
                    });
                    openBookingEditDialog();
                    bookingEditRoomId.focus();
                });
            });

            deleteButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    openDeleteConfirm(
                        button.getAttribute('data-room-id') || '',
                        button.getAttribute('data-room-name') || ''
                    );
                });
            });

            bookingDeleteButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    openBookingActionConfirm(
                        'delete',
                        button.getAttribute('data-booking-id') || '',
                        button.getAttribute('data-booking-room') || '',
                        button.getAttribute('data-booking-date') || '',
                        button.getAttribute('data-booking-time') || ''
                    );
                });
            });

            bookingCancelButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    openBookingActionConfirm(
                        'cancel',
                        button.getAttribute('data-booking-id') || '',
                        button.getAttribute('data-booking-room') || '',
                        button.getAttribute('data-booking-date') || '',
                        button.getAttribute('data-booking-time') || ''
                    );
                });
            });

            document.querySelectorAll('.room-status-toggle-form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    const actionInput = form.querySelector('input[name="action"]');
                    const activeInput = form.querySelector('input[name="is_active"]');
                    const reasonInput = form.querySelector('input[name="unavailable_reason"]');
                    if (!actionInput || actionInput.value !== 'toggle_conference_room_status' || !activeInput) {
                        return;
                    }

                    if (String(activeInput.value || '') === '0' && reasonInput && reasonInput.value.trim() === '') {
                        const toggleButton = form.querySelector('[data-room-status-toggle]');
                        const roomName = toggleButton ? String(toggleButton.getAttribute('data-room-name') || '').trim() : '';
                        event.preventDefault();
                        openUnavailableReasonConfirm(form, roomName);
                    }
                });
            });

            unavailableReasonSubmit.addEventListener('click', function () {
                const reason = unavailableReasonInput.value.trim();
                if (reason === '') {
                    unavailableReasonInput.focus();
                    return;
                }
                if (!pendingUnavailableForm) {
                    closeUnavailableReasonConfirm();
                    return;
                }

                const reasonInput = pendingUnavailableForm.querySelector('input[name="unavailable_reason"]');
                if (reasonInput) {
                    reasonInput.value = reason;
                }
                const formToSubmit = pendingUnavailableForm;
                pendingUnavailableForm = null;
                unavailableReasonConfirm.classList.remove('is-open');
                unavailableReasonConfirm.setAttribute('aria-hidden', 'true');
                syncBodyModalState();
                formToSubmit.submit();
            });

            unavailableReasonCancel.addEventListener('click', closeUnavailableReasonConfirm);

            closeTargets.forEach(function (target) {
                target.addEventListener('click', closeModal);
            });

            bookingModalCloseTargets.forEach(function (target) {
                target.addEventListener('click', closeBookingEditDialog);
            });

            deleteConfirmCancel.addEventListener('click', closeDeleteConfirm);
            bookingDeleteConfirmCancel.addEventListener('click', closeBookingDeleteConfirm);
            if (bookingEditCancelBtn) {
                bookingEditCancelBtn.addEventListener('click', closeBookingEditDialog);
            }

            if (bookingSearchInput) {
                bookingSearchInput.addEventListener('input', function () {
                    applyBookingFilters(true);
                });
            }

            if (bookingDateFilter) {
                bookingDateFilter.addEventListener('change', function () {
                    applyBookingFilters(true);
                });
            }

            if (bookingStatusFilter) {
                bookingStatusFilter.addEventListener('change', function () {
                    applyBookingFilters(true);
                });
            }

            if (bookingFiltersReset) {
                bookingFiltersReset.addEventListener('click', function () {
                    if (bookingSearchInput) {
                        bookingSearchInput.value = '';
                    }
                    if (bookingDateFilter) {
                        bookingDateFilter.value = '';
                    }
                    if (bookingStatusFilter) {
                        bookingStatusFilter.value = 'all';
                        const statusDropdown = bookingStatusFilter.closest('[data-filter-dropdown]');
                        syncFilterDropdown(statusDropdown);
                    }
                    closeFilterDropdowns();
                    applyBookingFilters(true);
                });
            }

            document.querySelectorAll('[data-filter-dropdown]').forEach(function (dropdown) {
                const trigger = dropdown.querySelector('[data-filter-trigger]');
                const menu = dropdown.querySelector('.conference-filter-menu');
                const select = dropdown.querySelector('.conference-filter-select');

                syncFilterDropdown(dropdown);

                if (trigger && menu) {
                    trigger.addEventListener('click', function (event) {
                        event.stopPropagation();
                        const shouldOpen = !menu.classList.contains('is-open');
                        closeFilterDropdowns(shouldOpen ? dropdown : null);
                        menu.classList.toggle('is-open', shouldOpen);
                        trigger.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
                    });
                }

                dropdown.querySelectorAll('.conference-filter-option').forEach(function (optionButton) {
                    optionButton.addEventListener('click', function (event) {
                        event.stopPropagation();
                        if (!select) {
                            return;
                        }

                        select.value = optionButton.getAttribute('data-filter-value') || '';
                        syncFilterDropdown(dropdown);
                        closeFilterDropdowns();
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });

                if (select) {
                    select.addEventListener('change', function () {
                        syncFilterDropdown(dropdown);
                    });
                }
            });

            roomFilterButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    if (!bookingSearchInput) {
                        return;
                    }
                    const roomFilter = String(button.getAttribute('data-room-filter') || '').trim();
                    bookingSearchInput.value = bookingSearchInput.value.trim().toLowerCase() === roomFilter.toLowerCase() ? '' : roomFilter;
                    applyBookingFilters(true);
                });
            });

            deleteConfirm.addEventListener('click', function (event) {
                if (event.target === deleteConfirm) {
                    closeDeleteConfirm();
                }
            });

            unavailableReasonConfirm.addEventListener('click', function (event) {
                if (event.target === unavailableReasonConfirm) {
                    closeUnavailableReasonConfirm();
                }
            });

            bookingDeleteConfirm.addEventListener('click', function (event) {
                if (event.target === bookingDeleteConfirm) {
                    closeBookingDeleteConfirm();
                }
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('.booking-actions')) {
                    closeBookingActionMenus();
                }
                if (!event.target.closest('[data-filter-dropdown]')) {
                    closeFilterDropdowns();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && bookingDeleteConfirm.classList.contains('is-open')) {
                    closeBookingDeleteConfirm();
                    return;
                }
                if (event.key === 'Escape' && bookingEditModal.classList.contains('is-open')) {
                    closeBookingEditDialog();
                    return;
                }
                if (event.key === 'Escape' && deleteConfirm.classList.contains('is-open')) {
                    closeDeleteConfirm();
                    return;
                }
                if (event.key === 'Escape' && unavailableReasonConfirm.classList.contains('is-open')) {
                    closeUnavailableReasonConfirm();
                    return;
                }
                if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                    return;
                }
                if (event.key === 'Escape') {
                    closeBookingActionMenus();
                    closeFilterDropdowns();
                }
            });

            if (shouldOpenModal) {
                if (String(roomFormDefaults.mode || '') === 'edit' && Number(roomFormDefaults.room_id || 0) > 0) {
                    setEditMode(roomFormDefaults);
                } else {
                    setAddMode(true);
                }
                setRoomModalTab(roomModalDefaultView === 'form' ? 'form' : 'list');
                openModal();
            } else {
                setAddMode(false);
                setRoomModalTab(roomModalDefaultView === 'form' ? 'form' : 'list');
            }

            if (shouldOpenBookingEditModal && Number(bookingFormDefaults.booking_id || 0) > 0) {
                setBookingEditMode(bookingFormDefaults);
                openBookingEditDialog();
            }

            applyBookingFilters(true);
        });
    </script>
</body>
</html>
