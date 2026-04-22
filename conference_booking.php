<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/conference_booking.php';

conference_booking_ensure_tables($conn);

function conference_booking_page_week_start(string $date): string
{
    try {
        $value = new DateTime($date);
    } catch (Throwable $e) {
        $value = new DateTime();
    }
    $dayOfWeek = (int) $value->format('N');
    $value->modify('-' . max(0, $dayOfWeek - 1) . ' days');
    return $value->format('Y-m-d');
}

function conference_booking_page_week_days(string $weekStart): array
{
    $days = [];
    $base = new DateTime($weekStart);
    for ($index = 0; $index < 6; $index++) {
        $day = clone $base;
        $day->modify('+' . $index . ' days');
        $days[] = [
            'date' => $day->format('Y-m-d'),
            'weekday' => strtoupper($day->format('D')),
            'short_date' => $day->format('M j'),
        ];
    }
    return $days;
}

function conference_booking_page_week_label(array $weekDays): string
{
    if (count($weekDays) === 0) {
        return '';
    }

    $first = new DateTime((string) $weekDays[0]['date']);
    $last = new DateTime((string) $weekDays[count($weekDays) - 1]['date']);
    if ($first->format('F Y') === $last->format('F Y')) {
        return $first->format('F j') . ' - ' . $last->format('j, Y');
    }
    if ($first->format('Y') === $last->format('Y')) {
        return $first->format('F j') . ' - ' . $last->format('F j, Y');
    }
    return $first->format('F j, Y') . ' - ' . $last->format('F j, Y');
}

function conference_booking_page_slots(): array
{
    return [
        ['start' => '07:00:00', 'end' => '08:00:00', 'label' => '7:00 AM'],
        ['start' => '08:00:00', 'end' => '09:00:00', 'label' => '8:00 AM'],
        ['start' => '09:00:00', 'end' => '10:00:00', 'label' => '9:00 AM'],
        ['start' => '10:00:00', 'end' => '11:00:00', 'label' => '10:00 AM'],
        ['start' => '11:00:00', 'end' => '12:00:00', 'label' => '11:00 AM'],
        ['start' => '12:00:00', 'end' => '13:00:00', 'label' => '12:00 PM'],
        ['start' => '13:00:00', 'end' => '14:00:00', 'label' => '1:00 PM'],
        ['start' => '14:00:00', 'end' => '15:00:00', 'label' => '2:00 PM'],
        ['start' => '15:00:00', 'end' => '16:00:00', 'label' => '3:00 PM'],
        ['start' => '16:00:00', 'end' => '17:00:00', 'label' => '4:00 PM'],
        ['start' => '17:00:00', 'end' => '18:00:00', 'label' => '5:00 PM'],
        ['start' => '18:00:00', 'end' => '19:00:00', 'label' => '6:00 PM'],
    ];
}

function conference_booking_page_add_minutes(string $timeValue, int $minutes): string
{
    try {
        $time = new DateTimeImmutable('2000-01-01 ' . $timeValue);
    } catch (Throwable $e) {
        return $timeValue;
    }

    if ($minutes !== 0) {
        $modifier = ($minutes >= 0 ? '+' : '') . $minutes . ' minutes';
        $time = $time->modify($modifier);
    }

    return $time->format('H:i:s');
}

function conference_booking_page_time_ticks(string $startTime, string $endTime, int $intervalMinutes = 30): array
{
    $ticks = [];

    try {
        $cursor = new DateTimeImmutable('2000-01-01 ' . $startTime);
        $end = new DateTimeImmutable('2000-01-01 ' . $endTime);
    } catch (Throwable $e) {
        return $ticks;
    }

    $intervalMinutes = max(5, $intervalMinutes);
    while ($cursor <= $end) {
        $ticks[] = [
            'time' => $cursor->format('H:i:s'),
            'label' => $cursor->format('g:i A'),
            'is_hour' => $cursor->format('i') === '00',
        ];
        $cursor = $cursor->modify('+' . $intervalMinutes . ' minutes');
    }

    return $ticks;
}

function conference_booking_page_minutes_from_start(string $timeValue, string $startTime): int
{
    try {
        $start = new DateTimeImmutable('2000-01-01 ' . $startTime);
        $time = new DateTimeImmutable('2000-01-01 ' . $timeValue);
    } catch (Throwable $e) {
        return 0;
    }

    return (int) floor(($time->getTimestamp() - $start->getTimestamp()) / 60);
}

function conference_booking_page_clamp_minutes(int $value, int $minimum, int $maximum): int
{
    return max($minimum, min($maximum, $value));
}

function conference_booking_page_scheduler_event(array $booking, string $dayStart, string $dayEnd, int $intervalMinutes = 30): array
{
    $startTime = trim((string) ($booking['start_time'] ?? ''));
    $endTime = trim((string) ($booking['end_time'] ?? ''));
    $bufferEndTime = conference_booking_page_add_minutes($endTime, 30);
    $dayDurationMinutes = max($intervalMinutes, conference_booking_page_minutes_from_start($dayEnd, $dayStart));

    $topMinutes = conference_booking_page_clamp_minutes(
        conference_booking_page_minutes_from_start($startTime, $dayStart),
        0,
        $dayDurationMinutes
    );
    $bookingEndMinutes = conference_booking_page_clamp_minutes(
        conference_booking_page_minutes_from_start($endTime, $dayStart),
        $topMinutes + $intervalMinutes,
        $dayDurationMinutes
    );
    $bufferEndMinutes = conference_booking_page_clamp_minutes(
        conference_booking_page_minutes_from_start($bufferEndTime, $dayStart),
        $bookingEndMinutes,
        $dayDurationMinutes
    );

    $bookingMinutes = max($intervalMinutes, $bookingEndMinutes - $topMinutes);
    $displayMinutes = max($bookingMinutes, $bufferEndMinutes - $topMinutes);
    $bufferMinutes = max(0, $displayMinutes - $bookingMinutes);

    $statusKey = strtolower(trim((string) ($booking['status'] ?? 'booked')));
    $status = $statusKey === 'pending' ? 'pending' : 'booked';

    $purpose = trim((string) ($booking['purpose'] ?? ''));
    $bookedByName = trim((string) ($booking['booked_by_name'] ?? 'Booked'));
    $department = trim((string) ($booking['booked_by_department'] ?? ''));
    $company = conference_booking_company_short_label((string) ($booking['booked_by_company'] ?? ''));
    $roomName = trim((string) ($booking['room_name'] ?? ''));
    $title = $purpose !== '' ? $purpose : ($bookedByName !== '' ? $bookedByName : 'Booked');

    $metaParts = [];
    if ($bookedByName !== '' && strcasecmp($title, $bookedByName) !== 0) {
        $metaParts[] = $bookedByName;
    }
    if ($department !== '') {
        $metaParts[] = $department;
    }
    if ($company !== '') {
        $metaParts[] = $company;
    }

    $tooltipParts = [$title];
    if ($bookedByName !== '' && strcasecmp($title, $bookedByName) !== 0) {
        $tooltipParts[] = 'Booked by ' . $bookedByName;
    }
    if ($roomName !== '') {
        $tooltipParts[] = 'Room: ' . $roomName;
    }
    if ($department !== '') {
        $tooltipParts[] = $department;
    }
    if ($company !== '') {
        $tooltipParts[] = $company;
    }
    $tooltipParts[] = conference_booking_format_time_12h($startTime) . ' - ' . conference_booking_format_time_12h($endTime);
    if ($bufferMinutes > 0) {
        $tooltipParts[] = 'Cleaning buffer until ' . conference_booking_format_time_12h($bufferEndTime);
    }

    $sizeClass = 'is-expanded';
    if ($displayMinutes <= 45) {
        $sizeClass = 'is-mini';
    } elseif ($displayMinutes <= 75) {
        $sizeClass = 'is-compact';
    } elseif ($displayMinutes <= 120) {
        $sizeClass = 'is-medium';
    }

    return [
        'status' => $status,
        'title' => $title,
        'meta' => implode(' | ', $metaParts),
        'time_label' => conference_booking_format_time_12h($startTime) . ' - ' . conference_booking_format_time_12h($endTime),
        'buffer_label' => $bufferMinutes > 0 ? ('Buffer until ' . conference_booking_format_time_12h($bufferEndTime)) : '',
        'top_minutes' => $topMinutes,
        'display_minutes' => $displayMinutes,
        'booking_minutes' => $bookingMinutes,
        'buffer_minutes' => $bufferMinutes,
        'size_class' => $sizeClass,
        'tooltip' => implode(' | ', $tooltipParts),
    ];
}

function conference_booking_page_room_visuals(string $roomName): array
{
    $normalizedRoomName = strtolower(trim($roomName));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $normalizedRoomName);
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'room';
    }

    if ($slug === 'caltex') {
        return [
            'slug' => 'caltex',
            'label_color' => '#166534',
            'booking_color' => '#166534',
        ];
    }

    if ($slug === 'mpdc') {
        return [
            'slug' => 'mpdc',
            'label_color' => '#baccdc',
            'booking_color' => '#baccdc',
        ];
    }

    $fallbackPalette = [
        ['label_color' => '#b45309', 'booking_color' => '#d97706'],
        ['label_color' => '#7c3aed', 'booking_color' => '#8b5cf6'],
        ['label_color' => '#be185d', 'booking_color' => '#ec4899'],
        ['label_color' => '#0f766e', 'booking_color' => '#14b8a6'],
        ['label_color' => '#1d4ed8', 'booking_color' => '#3b82f6'],
        ['label_color' => '#9a3412', 'booking_color' => '#ea580c'],
    ];
    $paletteIndex = abs((int) crc32($slug)) % count($fallbackPalette);
    $palette = $fallbackPalette[$paletteIndex];

    return [
        'slug' => $slug,
        'label_color' => (string) $palette['label_color'],
        'booking_color' => (string) $palette['booking_color'],
    ];
}

function conference_booking_page_slot_payload(array $bookings, string $slotStart, string $slotEnd, bool $allRooms, array $rooms = [], string $slotDate = ''): array
{
    $matches = [];
    foreach ($bookings as $booking) {
        $bookingStart = trim((string) ($booking['start_time'] ?? ''));
        $bookingEnd = trim((string) ($booking['end_time'] ?? ''));
        if ($bookingStart === '' || $bookingEnd === '') {
            continue;
        }
        $affectedEnd = conference_booking_page_add_minutes($bookingEnd, 30);
        if (strcmp($bookingStart, $slotEnd) < 0 && strcmp($affectedEnd, $slotStart) > 0) {
            $matches[] = $booking;
        }
    }

    if ($allRooms && count($rooms) > 1) {
        $matchesByRoomId = [];
        foreach ($matches as $booking) {
            $roomId = (int) ($booking['room_id'] ?? 0);
            if ($roomId <= 0) {
                continue;
            }
            if (!isset($matchesByRoomId[$roomId])) {
                $matchesByRoomId[$roomId] = [];
            }
            $matchesByRoomId[$roomId][] = $booking;
        }

        $segments = [];
        $bookedCount = 0;
        $unavailableCount = 0;
        $tooltipLines = [];
        foreach ($rooms as $room) {
            $roomId = (int) ($room['id'] ?? 0);
            $roomName = trim((string) ($room['room_name'] ?? 'Room'));
            $roomMatches = ($roomId > 0 && isset($matchesByRoomId[$roomId])) ? (array) $matchesByRoomId[$roomId] : [];
            $roomSupportsDate = $slotDate === '' || conference_booking_room_supports_booking_date($room, $slotDate);

            if (count($roomMatches) > 0) {
                $bookedCount++;
                $booking = $roomMatches[0];
                $bookedByName = trim((string) ($booking['booked_by_name'] ?? 'Booked'));
                if ($bookedByName === '') {
                    $bookedByName = 'Booked';
                }
                $segments[] = [
                    'room' => $roomName,
                    'state' => 'Booked',
                    'status' => 'booked',
                    'tooltip' => $roomName . ': ' . $bookedByName
                        . ' - ' . conference_booking_format_time_12h((string) ($booking['start_time'] ?? ''))
                        . ' to ' . conference_booking_format_time_12h((string) ($booking['end_time'] ?? '')),
                ];
                $tooltipLines[] = $roomName . ': ' . trim((string) ($booking['booked_by_name'] ?? 'Booked'));
            } elseif (!$roomSupportsDate) {
                $unavailableCount++;
                $segments[] = [
                    'room' => $roomName,
                    'state' => 'Saturday Off',
                    'status' => 'unavailable',
                    'tooltip' => 'This room is not available for Saturday bookings.',
                ];
                $tooltipLines[] = $roomName . ': Saturday unavailable';
            } else {
                $segments[] = [
                    'room' => $roomName,
                    'state' => 'Available',
                    'status' => 'available',
                    'tooltip' => 'Available',
                ];
                $tooltipLines[] = $roomName . ': Available';
            }
        }

        if (($bookedCount > 0 && $bookedCount < count($segments)) || $unavailableCount > 0) {
            return [
                'status' => 'mixed',
                'title' => '',
                'subtitle' => '',
                'room_label' => '',
                'segments' => $segments,
                'tooltip' => implode(' | ', $tooltipLines),
            ];
        }
    }

    if (count($matches) === 0) {
        $room = (!$allRooms && count($rooms) === 1) ? $rooms[0] : null;
        if ($room && $slotDate !== '' && !conference_booking_room_supports_booking_date($room, $slotDate)) {
            return [
                'status' => 'unavailable',
                'title' => 'Unavailable',
                'subtitle' => 'Saturday booking disabled',
                'room_label' => '',
                'segments' => [],
                'tooltip' => 'This room is not available for Saturday bookings.',
            ];
        }
        return ['status' => 'available', 'title' => 'Available', 'subtitle' => '', 'room_label' => '', 'segments' => [], 'tooltip' => 'Available'];
    }

    if ($allRooms && count($matches) > 1) {
        $tooltipLines = [];
        $roomNames = [];
        foreach ($matches as $booking) {
            $roomName = trim((string) ($booking['room_name'] ?? 'Room'));
            $tooltipLines[] = $roomName . ': ' . trim((string) ($booking['booked_by_name'] ?? 'Booked'));
            if ($roomName !== '' && !in_array($roomName, $roomNames, true)) {
                $roomNames[] = $roomName;
            }
        }
        return [
            'status' => 'booked',
            'title' => 'Fully Booked',
            'subtitle' => '',
            'room_label' => '',
            'segments' => [],
            'tooltip' => implode(' | ', $tooltipLines),
        ];
    }

    $booking = $matches[0];
    $status = strtolower(trim((string) ($booking['status'] ?? 'booked'))) === 'pending' ? 'pending' : 'booked';
    $title = trim((string) ($booking['booked_by_name'] ?? 'Booked'));
    if ($title === '') {
        $title = 'Booked';
    }
    $department = trim((string) ($booking['booked_by_department'] ?? ''));
    $company = conference_booking_company_short_label((string) ($booking['booked_by_company'] ?? ''));
    $roomName = trim((string) ($booking['room_name'] ?? ''));
    if ($department !== '' && $company !== '') {
        $subtitle = $department . ' | ' . $company;
    } elseif ($company !== '') {
        $subtitle = $company;
    } elseif ($department !== '') {
        $subtitle = $department;
    } else {
        $subtitle = '';
    }

    return [
        'status' => $status,
        'title' => $title,
        'subtitle' => $subtitle,
        'room_label' => $roomName !== '' ? 'Room: ' . $roomName : '',
        'segments' => [],
        'tooltip' => $title
            . ($roomName !== '' ? ' - ' . $roomName : '')
            . ' - ' . conference_booking_format_time_12h((string) ($booking['start_time'] ?? ''))
            . ' to ' . conference_booking_format_time_12h((string) ($booking['end_time'] ?? '')),
    ];
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');
$companyDepartmentMap = conference_booking_company_department_map();
$companyLabelMap = conference_booking_company_label_map();
$companyOptions = conference_booking_company_options();
$successMessage = (string) ($_SESSION['conference_booking_success'] ?? '');
unset($_SESSION['conference_booking_success']);

$form = [
    'booker_email' => (string) ($_POST['booker_email'] ?? (string) ($_SESSION['email'] ?? '')),
    'booker_company' => (string) ($_POST['booker_company'] ?? ticket_normalize_company((string) ($_SESSION['company'] ?? ''))),
    'booker_department' => (string) ($_POST['booker_department'] ?? (string) ($_SESSION['department'] ?? '')),
    'room_id' => (string) ($_POST['room_id'] ?? ''),
    'booking_date' => (string) ($_POST['booking_date'] ?? $today),
    'start_hour' => (string) ($_POST['start_hour'] ?? '7'),
    'start_minute' => (string) ($_POST['start_minute'] ?? '00'),
    'start_period' => (string) ($_POST['start_period'] ?? 'AM'),
    'end_hour' => (string) ($_POST['end_hour'] ?? '6'),
    'end_minute' => (string) ($_POST['end_minute'] ?? '00'),
    'end_period' => (string) ($_POST['end_period'] ?? 'PM'),
    'purpose' => (string) ($_POST['purpose'] ?? ''),
];
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $startTime = conference_booking_parse_time_parts($form['start_hour'], $form['start_minute'], $form['start_period']);
    $endTime = conference_booking_parse_time_parts($form['end_hour'], $form['end_minute'], $form['end_period']);

    if ($startTime === null || $endTime === null) {
        $errorMessage = 'Please choose a valid start and end time.';
    } else {
        $result = conference_booking_create(
            $conn,
            $userId,
            (int) $form['room_id'],
            trim($form['booking_date']),
            $startTime,
            $endTime,
            (string) $form['purpose'],
            (string) $form['booker_email'],
            (string) $form['booker_company'],
            (string) $form['booker_department']
        );

        if (!empty($result['ok'])) {
            $roomName = trim((string) (($result['room']['room_name'] ?? '') !== '' ? $result['room']['room_name'] : 'the selected room'));
            $_SESSION['conference_booking_success'] = 'Your booking for ' . $roomName . ' has been saved successfully.';
            $redirectQuery = [];
            if (trim((string) $form['booking_date']) !== '') {
                $redirectQuery['week_of'] = trim((string) $form['booking_date']);
            }
            if ((int) $form['room_id'] > 0) {
                $redirectQuery['room_filter'] = (int) $form['room_id'];
            }
            header('Location: conference_booking.php' . (count($redirectQuery) > 0 ? ('?' . http_build_query($redirectQuery)) : ''));
            exit();
        }

        $errorMessage = trim((string) ($result['error'] ?? 'Unable to save the booking right now.'));
    }
}

$rooms = conference_booking_active_rooms($conn);
$roomsById = [];
$roomSaturdayMap = [];
foreach ($rooms as $room) {
    $roomId = (int) ($room['id'] ?? 0);
    $roomsById[$roomId] = $room;
    $roomSaturdayMap[(string) $roomId] = (int) ($room['saturday_enabled'] ?? 0) === 1 ? 1 : 0;
}

$selectedRoomFilter = isset($_GET['room_filter']) ? (int) $_GET['room_filter'] : ((int) $form['room_id'] > 0 ? (int) $form['room_id'] : 0);
if ($selectedRoomFilter > 0 && !isset($roomsById[$selectedRoomFilter])) {
    $selectedRoomFilter = 0;
}

$referenceWeekDate = trim((string) ($_GET['week_of'] ?? $form['booking_date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $referenceWeekDate)) {
    $referenceWeekDate = $today;
}
$weekStartDate = conference_booking_page_week_start($referenceWeekDate);
$weekDays = conference_booking_page_week_days($weekStartDate);
$weekEndDate = (string) ($weekDays[count($weekDays) - 1]['date'] ?? $weekStartDate);
$weekLabel = conference_booking_page_week_label($weekDays);
$previousWeekDate = (new DateTime($weekStartDate))->modify('-7 days')->format('Y-m-d');
$nextWeekDate = (new DateTime($weekStartDate))->modify('+7 days')->format('Y-m-d');
$todayWeekDate = conference_booking_page_week_start($today);
$todayDayOfWeek = (int) date('N', strtotime($today));
$highlightTodayDate = ($todayDayOfWeek >= 1 && $todayDayOfWeek <= 6 && $weekStartDate === $todayWeekDate) ? $today : '';
$weekBookings = conference_booking_schedule_between($conn, $weekStartDate, $weekEndDate, $selectedRoomFilter);
$isCurrentWeek = $weekStartDate === $todayWeekDate;
$schedulerStartTime = '07:00:00';
$schedulerEndTime = '18:00:00';
$schedulerIntervalMinutes = 30;
$schedulerRooms = $selectedRoomFilter > 0 && isset($roomsById[$selectedRoomFilter])
    ? [$roomsById[$selectedRoomFilter]]
    : array_values($rooms);
$schedulerLaneCount = max(1, count($schedulerRooms));
$schedulerTimeTicks = conference_booking_page_time_ticks($schedulerStartTime, $schedulerEndTime, $schedulerIntervalMinutes);
$schedulerGridTicks = $schedulerTimeTicks;
$schedulerIntervalCount = count($schedulerGridTicks);
$schedulerViewLabel = $selectedRoomFilter > 0 && isset($roomsById[$selectedRoomFilter])
    ? trim((string) ($roomsById[$selectedRoomFilter]['room_name'] ?? 'Selected Room')) . ' only'
    : 'All active rooms';
$schedulerBookingCount = count($weekBookings);
$schedulerEventsByDateRoom = [];
foreach ($weekDays as $day) {
    $dayKey = (string) ($day['date'] ?? '');
    if ($dayKey === '') {
        continue;
    }

    if (!isset($schedulerEventsByDateRoom[$dayKey])) {
        $schedulerEventsByDateRoom[$dayKey] = [];
    }

    foreach ($schedulerRooms as $room) {
        $roomId = (int) ($room['id'] ?? 0);
        if ($roomId <= 0) {
            continue;
        }
        $schedulerEventsByDateRoom[$dayKey][$roomId] = [];
    }
}
foreach ($weekBookings as $booking) {
    $dayKey = trim((string) ($booking['booking_date'] ?? ''));
    $roomId = (int) ($booking['room_id'] ?? 0);
    if ($dayKey === '' || $roomId <= 0 || !isset($schedulerEventsByDateRoom[$dayKey][$roomId])) {
        continue;
    }

    $schedulerEventsByDateRoom[$dayKey][$roomId][] = conference_booking_page_scheduler_event(
        $booking,
        $schedulerStartTime,
        $schedulerEndTime,
        $schedulerIntervalMinutes
    );
}
$bookingsByDate = [];
foreach ($weekBookings as $booking) {
    $dateKey = (string) ($booking['booking_date'] ?? '');
    if ($dateKey === '') {
        continue;
    }
    if (!isset($bookingsByDate[$dateKey])) {
        $bookingsByDate[$dateKey] = [];
    }
    $bookingsByDate[$dateKey][] = $booking;
}
$myBookings = $userId > 0 ? conference_booking_user_bookings($conn, $userId, 20) : [];
$slotRows = conference_booking_page_slots();
$hourOptions = range(1, 12);
$minuteOptions = [];
for ($minute = 0; $minute <= 55; $minute += 5) {
    $minuteOptions[] = sprintf('%02d', $minute);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Conference | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --booking-green: #166534;
            --booking-green-soft: #eaf9ec;
            --booking-green-border: #b9efc0;
            --booking-gold: #e8b629;
            --booking-red: #ef4444;
            --booking-yellow: #facc15;
            --booking-shadow: 0 18px 44px rgba(15, 23, 42, 0.08);
        }

        * { box-sizing: border-box; }

        body.conference-booking-public-page {
            margin: 0;
            min-height: 100vh;
            background: #f3f4f6 url('assets/img/leadss.jpg') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            color: #0f172a;
            overflow-x: hidden;
        }

        body.conference-booking-public-page.modal-open {
            overflow: hidden;
        }

        body.conference-booking-public-page::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(980px 560px at 14% 56%, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0) 66%),
                linear-gradient(90deg, rgba(255, 255, 255, 0.00) 0%, rgba(231, 240, 247, 0.16) 62%, rgba(231, 240, 247, 0.28) 100%);
            pointer-events: none;
            z-index: 0;
        }

        body.conference-booking-public-page .dashboard-container {
            position: relative;
            z-index: 1;
            padding: 18px 10px 30px;
        }

        body.conference-booking-public-page .content-wrapper {
            width: 100%;
            max-width: 1720px;
            margin: 0 auto;
        }

        body.conference-booking-public-page .public-shell {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(255, 255, 255, 0.76);
            border-radius: 32px;
            box-shadow: 0 20px 54px rgba(2, 6, 23, 0.14);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            padding: 18px 16px 24px;
            position: relative;
            overflow: hidden;
        }

        body.conference-booking-public-page .public-shell::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, rgba(22, 163, 74, 0.24), rgba(255, 255, 255, 0) 45%, rgba(15, 23, 42, 0.08));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        body.conference-booking-public-page .page-topbar {
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 16px;
            margin-bottom: 6px;
        }

        body.conference-booking-public-page .topbar-side {
            display: flex;
            align-items: center;
            width: 100%;
        }

        body.conference-booking-public-page .topbar-side.left { justify-content: flex-start; }
        body.conference-booking-public-page .topbar-side.right { justify-content: flex-end; }

        body.conference-booking-public-page .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-height: 56px;
            padding: 0 24px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 800;
            color: var(--booking-green);
            background: rgba(240, 253, 244, 0.92);
            border: 1px solid rgba(187, 247, 208, 0.96);
            box-shadow: 0 10px 24px rgba(22, 101, 52, 0.08);
        }

        body.conference-booking-public-page .brand-chip {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            justify-self: end;
            color: var(--booking-green);
            font-weight: 700;
            font-size: 16px;
            text-align: right;
        }

        body.conference-booking-public-page .brand-chip img {
            width: 46px;
            height: 46px;
            object-fit: contain;
        }

        body.conference-booking-public-page .page-header {
            text-align: center;
            margin-bottom: 22px;
        }

        body.conference-booking-public-page .page-title {
            margin: 0;
            color: var(--booking-green);
            font-size: clamp(34px, 4vw, 50px);
            line-height: 1.05;
            font-weight: 600;
            letter-spacing: -0.04em;
        }

        body.conference-booking-public-page .page-subtitle {
            margin: 12px 0 0;
            color: #475569;
            font-size: 16px;
            line-height: 1.6;
        }

        body.conference-booking-public-page .alert {
            max-width: 1280px;
            margin: 0 auto 18px;
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid transparent;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }

        body.conference-booking-public-page .alert-success {
            background: #ecfdf3;
            color: #166534;
            border-color: #bbf7d0;
        }

        body.conference-booking-public-page .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }

        body.conference-booking-public-page .booking-layout {
            display: block;
            width: 100%;
            max-width: 1660px;
            margin: 8px auto 0;
            padding: 0;
        }

        body.conference-booking-public-page .booking-panel {
            background: rgba(255, 255, 255, 0.96);
            border-radius: 22px;
            border: 1px solid rgba(226, 232, 240, 0.86);
            box-shadow: var(--booking-shadow);
            overflow: hidden;
            position: relative;
            width: 100%;
        }

        body.conference-booking-public-page .booking-panel::before {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--booking-gold), #f6e087);
        }

        body.conference-booking-public-page .panel-body {
            padding: 22px 22px 20px;
        }

        body.conference-booking-public-page .panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 22px 14px;
            color: #1e293b;
            flex-wrap: wrap;
        }

        body.conference-booking-public-page .panel-header-actions {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        body.conference-booking-public-page .panel-header-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: #eefcf0;
            color: var(--booking-green);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
        }

        body.conference-booking-public-page .panel-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        body.conference-booking-public-page .panel-divider {
            border-top: 1px solid #e5e7eb;
            margin: 0 22px;
        }

        body.conference-booking-public-page .required-asterisk {
            color: #dc2626;
            font-weight: 800;
            margin-left: 4px;
        }

        body.conference-booking-public-page .booking-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 16px;
        }

        body.conference-booking-public-page .booking-grid.single {
            grid-template-columns: 1fr;
        }

        body.conference-booking-public-page .booking-grid.date-time-row {
            grid-template-columns: minmax(220px, 1.1fr) auto minmax(0, 1fr) minmax(0, 1fr);
            align-items: start;
            margin-top: 16px;
        }

        body.conference-booking-public-page .form-group {
            margin: 0;
        }

        body.conference-booking-public-page .form-group label {
            display: block;
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
            text-transform: uppercase;
        }

        body.conference-booking-public-page .icon-field {
            position: relative;
        }

        body.conference-booking-public-page .date-field-clickable {
            cursor: pointer;
        }

        body.conference-booking-public-page .date-field-clickable .form-control {
            cursor: pointer;
        }

        body.conference-booking-public-page .date-field-clickable input[type="date"] {
            position: relative;
        }

        body.conference-booking-public-page .icon-field .field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 19px;
            pointer-events: none;
        }

        body.conference-booking-public-page .icon-field .field-icon.textarea {
            top: 14px;
            transform: none;
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }

        body.conference-booking-public-page .icon-field .form-control,
        body.conference-booking-public-page .icon-field textarea {
            padding-left: 44px;
        }

        body.conference-booking-public-page .form-control,
        body.conference-booking-public-page textarea {
            width: 100%;
            min-height: 52px;
            padding: 0 16px;
            border-radius: 14px;
            border: 1px solid #dbe2ea;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        body.conference-booking-public-page textarea {
            min-height: 190px;
            padding-top: 14px;
            padding-bottom: 16px;
            line-height: 1.5;
            resize: none;
        }

        body.conference-booking-public-page .purpose-row {
            margin-top: 18px;
        }

        body.conference-booking-public-page .purpose-field {
            position: relative;
        }

        body.conference-booking-public-page .purpose-field .field-icon.textarea {
            top: 18px;
            left: 18px;
            font-size: 21px;
            color: #667085;
        }

        body.conference-booking-public-page .purpose-field textarea.form-control {
            min-height: 150px !important;
            height: 150px;
            padding: 18px 18px 18px 56px !important;
            line-height: 1.6;
            display: block;
        }

        body.conference-booking-public-page .purpose-field textarea.form-control::placeholder {
            color: #6b7280;
            line-height: 1.6;
        }

        body.conference-booking-public-page textarea::placeholder {
            line-height: 1.5;
        }

        body.conference-booking-public-page .form-control:focus,
        body.conference-booking-public-page textarea:focus {
            outline: none;
            border-color: var(--booking-green);
            box-shadow: 0 0 0 4px rgba(22, 101, 52, 0.12);
        }

        body.conference-booking-public-page .time-group {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        body.conference-booking-public-page .booking-rule-tooltip {
            position: relative;
            align-self: start;
            justify-self: center;
            margin-top: 36px;
        }

        body.conference-booking-public-page .booking-rule-trigger {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid #cfe8d6;
            background: #ebf9ee;
            color: var(--booking-green);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 18px rgba(22, 101, 52, 0.08);
            cursor: help;
        }

        body.conference-booking-public-page .booking-rule-trigger i {
            font-size: 16px;
        }

        body.conference-booking-public-page .booking-rule-popup {
            position: absolute;
            top: calc(100% + 10px);
            left: 50%;
            transform: translateX(-50%) translateY(8px);
            width: 260px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid #dbe2ea;
            color: #1f2937;
            font-size: 13px;
            line-height: 1.6;
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.14);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
            z-index: 8;
        }

        body.conference-booking-public-page .booking-rule-popup::before {
            content: "";
            position: absolute;
            top: -7px;
            left: 50%;
            width: 12px;
            height: 12px;
            background: #ffffff;
            border-top: 1px solid #dbe2ea;
            border-left: 1px solid #dbe2ea;
            transform: translateX(-50%) rotate(45deg);
        }

        body.conference-booking-public-page .booking-rule-tooltip:hover .booking-rule-popup,
        body.conference-booking-public-page .booking-rule-tooltip:focus-within .booking-rule-popup {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
        }

        body.conference-booking-public-page .form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-top: 18px;
        }

        body.conference-booking-public-page .btn-lite,
        body.conference-booking-public-page .btn-submit {
            min-height: 50px;
            border-radius: 14px;
            padding: 0 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
        }

        body.conference-booking-public-page .btn-lite {
            background: #ffffff;
            color: #334155;
            border-color: #e5e7eb;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
        }

        body.conference-booking-public-page .btn-submit {
            background: var(--booking-green);
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(22, 101, 52, 0.22);
        }

        body.conference-booking-public-page .btn-submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            box-shadow: none;
        }

        body.conference-booking-public-page .availability-toolbar {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 18px;
            align-items: center;
            margin-bottom: 14px;
        }

        body.conference-booking-public-page .week-nav {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-self: start;
        }

        body.conference-booking-public-page .week-nav-link {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #1f2937;
            border: 1px solid #e5e7eb;
            background: #ffffff;
        }

        body.conference-booking-public-page .week-label {
            font-size: 17px;
            font-weight: 700;
            color: #1f2937;
            padding: 0 4px;
        }

        body.conference-booking-public-page .availability-filter {
            min-width: 170px;
        }

        body.conference-booking-public-page .panel-header-actions .availability-filter {
            min-width: 190px;
        }

        body.conference-booking-public-page .availability-table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            border: 1px solid #9ca3af;
            border-radius: 14px;
            background: #ffffff;
        }

        body.conference-booking-public-page .availability-table {
            width: 100%;
            min-width: 0;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        body.conference-booking-public-page .availability-table th,
        body.conference-booking-public-page .availability-table td {
            border-right: 1px solid #9ca3af;
            border-bottom: 1px solid #9ca3af;
            padding: 0;
            vertical-align: middle;
            text-align: center;
        }

        body.conference-booking-public-page .availability-table th:last-child,
        body.conference-booking-public-page .availability-table td:last-child {
            border-right: 0;
        }

        body.conference-booking-public-page .availability-table tr:last-child td {
            border-bottom: 0;
        }

        body.conference-booking-public-page .availability-table thead th {
            background: #f8fafc;
            padding: 12px 10px 10px;
            color: #1f2937;
            font-size: 14px;
            font-weight: 700;
        }

        body.conference-booking-public-page .availability-table thead th.today-highlight {
            background: #f8fafc;
            color: var(--booking-green);
            box-shadow: inset 0 0 0 2px var(--booking-green);
        }

        body.conference-booking-public-page .availability-table .day-date {
            display: block;
            margin-top: 6px;
            color: #64748b;
            font-weight: 500;
        }

        body.conference-booking-public-page .time-col {
            width: 100px;
            min-width: 100px;
            background: #ffffff;
            font-weight: 700;
            color: #1f2937;
            padding: 18px 10px !important;
        }

        body.conference-booking-public-page .slot-cell {
            min-width: 0;
            height: 94px;
            padding: 8px;
            background: #ffffff;
        }

        body.conference-booking-public-page .slot-cell.today-highlight {
            background: #ffffff;
        }

        body.conference-booking-public-page .slot-card {
            width: 100%;
            height: 100%;
            border-radius: 12px;
            padding: 12px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-size: 12px;
            line-height: 1.3;
            font-weight: 600;
            overflow-wrap: anywhere;
            word-break: break-word;
            text-align: center;
        }

        body.conference-booking-public-page .slot-card.mixed {
            padding: 0;
            gap: 0;
            background: transparent;
            overflow: hidden;
        }

        body.conference-booking-public-page .slot-segments {
            width: 100%;
            height: 100%;
            display: grid;
            grid-template-columns: repeat(var(--segment-count, 2), minmax(0, 1fr));
        }

        body.conference-booking-public-page .slot-segment {
            min-width: 0;
            height: 100%;
            padding: 10px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            text-align: center;
        }

        body.conference-booking-public-page .slot-segment + .slot-segment {
            border-left: 0;
        }

        body.conference-booking-public-page .slot-segment.available {
            background: #eaf9ec;
            color: #166534;
        }

        body.conference-booking-public-page .slot-segment.booked {
            background: #ffe8ea;
            color: #dc2626;
        }

        body.conference-booking-public-page .slot-segment.unavailable {
            background: #f1f5f9;
            color: #64748b;
        }

        body.conference-booking-public-page .slot-card.available {
            background: #eaf9ec;
            color: #166534;
        }

        body.conference-booking-public-page .slot-card.booked {
            background: #ffe8ea;
            color: #dc2626;
            align-items: flex-start;
            justify-content: center;
            text-align: left;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
        }

        body.conference-booking-public-page .slot-card.booked.fully-booked {
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        body.conference-booking-public-page .slot-card.closed {
            background: #f8fafc;
            color: #64748b;
        }

        body.conference-booking-public-page .slot-card.unavailable {
            background: #f1f5f9;
            color: #64748b;
        }

        body.conference-booking-public-page .slot-title {
            display: block;
            width: 100%;
            font-size: 12px;
            line-height: 1.3;
            font-weight: 700;
        }

        body.conference-booking-public-page .slot-subtitle {
            display: block;
            width: 100%;
            font-size: 11px;
            line-height: 1.3;
            font-weight: 500;
            opacity: 0.92;
        }

        body.conference-booking-public-page .slot-room-label {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(220, 38, 38, 0.1);
            color: #b91c1c;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
        }

        body.conference-booking-public-page .slot-segment-room {
            display: block;
            width: 100%;
            font-size: 11px;
            line-height: 1.2;
            font-weight: 700;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        body.conference-booking-public-page .slot-segment-state {
            display: block;
            width: 100%;
            font-size: 10px;
            line-height: 1.2;
            font-weight: 600;
            opacity: 0.92;
        }

        body.conference-booking-public-page .slot-card.available .slot-title,
        body.conference-booking-public-page .slot-card.unavailable .slot-title,
        body.conference-booking-public-page .slot-card.closed .slot-title,
        body.conference-booking-public-page .slot-card.booked.fully-booked .slot-title {
            text-align: center;
        }

        body.conference-booking-public-page .booking-inline-note {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #b91c1c;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.5;
        }

        body.conference-booking-public-page .availability-legend {
            margin: 0;
            display: flex;
            justify-content: flex-end;
            min-width: 0;
        }

        body.conference-booking-public-page .legend-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
            padding: 12px 16px;
            width: fit-content;
            max-width: 100%;
        }

        body.conference-booking-public-page .legend-row {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 18px;
        }

        body.conference-booking-public-page .legend-title {
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
            text-transform: uppercase;
            flex: 0 0 auto;
            white-space: nowrap;
        }

        body.conference-booking-public-page .legend-items {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: nowrap;
            flex: 0 0 auto;
        }

        body.conference-booking-public-page .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-weight: 500;
            white-space: nowrap;
        }

        body.conference-booking-public-page .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 999px;
            display: inline-block;
        }

        body.conference-booking-public-page .legend-dot.available { background: #48c774; }
        body.conference-booking-public-page .legend-dot.booked { background: #ff5f6d; }
        body.conference-booking-public-page .legend-dot.unavailable { background: #cbd5e1; }

        body.conference-booking-public-page .legend-tooltip {
            position: relative;
            display: inline-flex;
            align-items: center;
            flex: 0 0 auto;
        }

        body.conference-booking-public-page .legend-tooltip-trigger {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: help;
            box-shadow: 0 8px 18px rgba(59, 130, 246, 0.12);
        }

        body.conference-booking-public-page .legend-tooltip-popup {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 240px;
            max-width: 280px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #2563eb;
            font-size: 13px;
            line-height: 1.5;
            font-weight: 600;
            box-shadow: 0 18px 34px rgba(59, 130, 246, 0.18);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(8px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
            z-index: 12;
        }

        body.conference-booking-public-page .legend-tooltip-popup::before {
            content: "";
            position: absolute;
            top: -7px;
            right: 10px;
            width: 12px;
            height: 12px;
            background: #eff6ff;
            border-top: 1px solid #bfdbfe;
            border-left: 1px solid #bfdbfe;
            transform: rotate(45deg);
        }

        body.conference-booking-public-page .legend-tooltip:hover .legend-tooltip-popup,
        body.conference-booking-public-page .legend-tooltip:focus-within .legend-tooltip-popup {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        body.conference-booking-public-page .scheduler-header {
            justify-content: space-between;
            gap: 18px;
        }

        body.conference-booking-public-page .scheduler-heading {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            flex: 1 1 360px;
        }

        body.conference-booking-public-page .scheduler-heading-copy {
            min-width: 0;
        }

        body.conference-booking-public-page .scheduler-heading-copy h3 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        body.conference-booking-public-page .scheduler-heading-copy p {
            margin: 6px 0 0;
            color: #5b6b80;
            font-size: 14px;
            line-height: 1.55;
        }

        body.conference-booking-public-page .scheduler-header .panel-header-actions {
            flex: 1 1 100%;
            gap: 12px;
            align-items: center;
            margin-left: 0;
            justify-content: space-between;
            width: 100%;
        }

        body.conference-booking-public-page .scheduler-header-right {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex: 1 1 auto;
            justify-content: flex-end;
            flex-wrap: wrap;
            min-width: 0;
        }

        body.conference-booking-public-page .scheduler-header-controls {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            flex: 0 1 auto;
            flex-wrap: wrap;
        }

        body.conference-booking-public-page .scheduler-header .scheduler-header-legend {
            margin-right: auto;
        }

        body.conference-booking-public-page .scheduler-header .availability-filter {
            min-width: 220px;
        }

        body.conference-booking-public-page .week-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px;
            border-radius: 18px;
            border: 1px solid #d9e6db;
            background: linear-gradient(180deg, #f7fbf8 0%, #eef7f0 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92);
            flex-wrap: nowrap;
        }

        body.conference-booking-public-page .week-nav-link {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #166534;
            border: 1px solid #d9e6db;
            background: #ffffff;
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.04);
            transition: transform 0.18s ease, background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
        }

        body.conference-booking-public-page .week-nav-link:hover {
            transform: translateY(-1px);
            background: var(--booking-green);
            border-color: var(--booking-green);
            color: #ffffff;
        }

        body.conference-booking-public-page .week-nav-link.is-wide {
            width: auto;
            min-width: 96px;
            padding: 0 16px;
            font-size: 13px;
            font-weight: 800;
        }

        body.conference-booking-public-page .week-nav-link.is-current {
            background: var(--booking-green);
            border-color: var(--booking-green);
            color: #ffffff;
        }

        body.conference-booking-public-page .week-label {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            padding: 0 10px;
            white-space: nowrap;
            letter-spacing: -0.01em;
        }

        body.conference-booking-public-page .scheduler-toolbar {
            display: grid;
            grid-template-columns: minmax(280px, 1fr) auto;
            gap: 18px;
            align-items: center;
            margin-bottom: 18px;
        }

        body.conference-booking-public-page .scheduler-summary-card {
            display: grid;
            gap: 8px;
            padding: 18px 20px;
            border-radius: 22px;
            border: 1px solid rgba(209, 230, 214, 0.95);
            background: linear-gradient(135deg, #f8fdf8 0%, #eef8ef 42%, #ffffff 100%);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.05);
        }

        body.conference-booking-public-page .scheduler-summary-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #166534;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        body.conference-booking-public-page .scheduler-summary-value {
            font-size: 24px;
            line-height: 1.1;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.03em;
        }

        body.conference-booking-public-page .scheduler-summary-meta {
            color: #5b6b80;
            font-size: 13px;
            line-height: 1.65;
        }

        body.conference-booking-public-page .availability-legend {
            margin: 0;
            display: flex;
            justify-content: flex-end;
            min-width: 0;
        }

        body.conference-booking-public-page .legend-card {
            border: 1px solid #d9e6db;
            border-radius: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fcf9 100%);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.04);
            padding: 14px 18px;
            width: fit-content;
            max-width: 100%;
        }

        body.conference-booking-public-page .legend-row {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        body.conference-booking-public-page .legend-title {
            font-size: 12px;
            font-weight: 800;
            color: #166534;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            white-space: nowrap;
        }

        body.conference-booking-public-page .legend-items {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        body.conference-booking-public-page .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-weight: 600;
            font-size: 13px;
            white-space: nowrap;
        }

        body.conference-booking-public-page .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 999px;
            display: inline-block;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }

        body.conference-booking-public-page .legend-dot.available {
            background: linear-gradient(180deg, #dff7e5 0%, #b7edc3 100%);
            border: 1px solid #86efac;
        }

        body.conference-booking-public-page .legend-dot.booked {
            background: linear-gradient(180deg, #34d399 0%, #15803d 100%);
            border: 1px solid #15803d;
        }

        body.conference-booking-public-page .legend-dot.pending {
            background: linear-gradient(180deg, #f7d36c 0%, #dca11e 100%);
            border: 1px solid #dca11e;
        }

        body.conference-booking-public-page .legend-dot.unavailable {
            background: linear-gradient(180deg, #e2e8f0 0%, #cbd5e1 100%);
            border: 1px solid #cbd5e1;
        }

        body.conference-booking-public-page .legend-tooltip {
            position: relative;
            display: inline-flex;
            align-items: center;
            flex: 0 0 auto;
        }

        body.conference-booking-public-page .legend-tooltip-trigger {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            border: 1px solid #cfe8d6;
            background: #ebf9ee;
            color: var(--booking-green);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: help;
            box-shadow: 0 8px 18px rgba(22, 101, 52, 0.1);
        }

        body.conference-booking-public-page .legend-tooltip-popup {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 260px;
            max-width: 320px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            font-size: 13px;
            line-height: 1.55;
            font-weight: 700;
            box-shadow: 0 18px 34px rgba(22, 101, 52, 0.14);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(8px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
            z-index: 12;
        }

        body.conference-booking-public-page .legend-tooltip-popup::before {
            content: "";
            position: absolute;
            top: -7px;
            right: 10px;
            width: 12px;
            height: 12px;
            background: #f0fdf4;
            border-top: 1px solid #bbf7d0;
            border-left: 1px solid #bbf7d0;
            transform: rotate(45deg);
        }

        body.conference-booking-public-page .scheduler-board-wrap {
            width: 100%;
            overflow: auto;
            border-radius: 28px;
            border: 1px solid #d9e6db;
            background: linear-gradient(180deg, #f6fbf7 0%, #ffffff 32%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 20px 40px rgba(15, 23, 42, 0.06);
        }

        body.conference-booking-public-page .scheduler-board {
            --scheduler-slot-height: 42px;
            --scheduler-minute-height: calc(var(--scheduler-slot-height) / 30);
            min-width: calc(96px + (6 * var(--scheduler-day-min-width, 240px)));
        }

        body.conference-booking-public-page .scheduler-board-head,
        body.conference-booking-public-page .scheduler-board-body {
            display: grid;
            grid-template-columns: 96px repeat(6, minmax(var(--scheduler-day-min-width, 240px), 1fr));
        }

        body.conference-booking-public-page .scheduler-board-head {
            position: sticky;
            top: 0;
            z-index: 8;
            background: linear-gradient(135deg, #1f6f3a 0%, #14532d 100%);
            box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.1);
        }

        body.conference-booking-public-page .scheduler-time-head {
            position: sticky;
            left: 0;
            z-index: 11;
            padding: 18px 14px;
            display: grid;
            gap: 4px;
            align-content: start;
            background: linear-gradient(180deg, #1d6335 0%, #14532d 100%);
            color: #ffffff;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
        }

        body.conference-booking-public-page .scheduler-time-head-label {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        body.conference-booking-public-page .scheduler-time-head-note {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 700;
        }

        body.conference-booking-public-page .scheduler-day-head {
            min-width: 0;
            color: #ffffff;
            border-left: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
        }

        body.conference-booking-public-page .scheduler-day-head.is-today {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
            box-shadow: inset 0 0 0 2px rgba(255, 255, 255, 0.55);
        }

        body.conference-booking-public-page .scheduler-day-head-main {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 18px 18px 12px;
            min-height: 72px;
        }

        body.conference-booking-public-page .scheduler-day-name {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        body.conference-booking-public-page .scheduler-day-date {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.84);
            font-weight: 600;
        }

        body.conference-booking-public-page .scheduler-day-badge {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        body.conference-booking-public-page .scheduler-room-heads {
            display: grid;
            grid-template-columns: repeat(var(--scheduler-lane-count, 1), minmax(0, 1fr));
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.06);
        }

        body.conference-booking-public-page .scheduler-room-head {
            min-width: 0;
            display: grid;
            gap: 4px;
            padding: 11px 12px 13px;
            border-left: 1px solid rgba(255, 255, 255, 0.08);
        }

        body.conference-booking-public-page .scheduler-room-head:first-child {
            border-left: 0;
        }

        body.conference-booking-public-page .scheduler-room-head.is-disabled {
            background: rgba(15, 23, 42, 0.16);
        }

        body.conference-booking-public-page .scheduler-room-head-name {
            font-size: 13px;
            line-height: 1.35;
            font-weight: 800;
            color: #ffffff;
        }

        body.conference-booking-public-page .scheduler-room-head-state {
            font-size: 11px;
            line-height: 1.3;
            color: rgba(255, 255, 255, 0.74);
            font-weight: 600;
        }

        body.conference-booking-public-page .scheduler-board-body {
            background: #ffffff;
            min-height: calc(var(--scheduler-interval-count, 1) * var(--scheduler-slot-height));
        }

        body.conference-booking-public-page .scheduler-time-rail {
            position: sticky;
            left: 0;
            z-index: 6;
            display: grid;
            grid-template-rows: repeat(var(--scheduler-interval-count, 1), var(--scheduler-slot-height));
            align-content: start;
            min-height: calc(var(--scheduler-interval-count, 1) * var(--scheduler-slot-height));
            background: linear-gradient(180deg, #f8fbf8 0%, #eff6f0 100%);
            border-right: 1px solid #d9e6db;
        }

        body.conference-booking-public-page .scheduler-time-slot {
            position: relative;
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            padding: 6px 12px 0;
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
        }

        body.conference-booking-public-page .scheduler-time-slot::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            border-bottom: 1px solid #e2ede4;
        }

        body.conference-booking-public-page .scheduler-time-slot.is-hour {
            color: #166534;
            font-weight: 800;
        }

        body.conference-booking-public-page .scheduler-time-end {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            transform: translateY(50%);
            display: flex;
            justify-content: flex-end;
            padding: 0 12px;
            color: #166534;
            font-size: 12px;
            font-weight: 800;
            pointer-events: none;
        }

        body.conference-booking-public-page .scheduler-day-column {
            position: relative;
            border-left: 1px solid #dfeae1;
            background: linear-gradient(180deg, #fbfefb 0%, #ffffff 28%);
        }

        body.conference-booking-public-page .scheduler-day-column.is-today {
            background: linear-gradient(180deg, rgba(22, 101, 52, 0.08) 0%, rgba(255, 255, 255, 0.98) 26%);
        }

        body.conference-booking-public-page .scheduler-day-lanes {
            display: grid;
            grid-template-columns: repeat(var(--scheduler-lane-count, 1), minmax(0, 1fr));
            min-height: calc(var(--scheduler-interval-count, 1) * var(--scheduler-slot-height));
        }

        body.conference-booking-public-page .scheduler-room-lane {
            position: relative;
            min-height: calc(var(--scheduler-interval-count, 1) * var(--scheduler-slot-height));
            border-left: 1px solid #e2ede4;
            background:
                repeating-linear-gradient(
                    to bottom,
                    transparent 0 calc(var(--scheduler-slot-height) - 1px),
                    rgba(214, 227, 216, 0.95) calc(var(--scheduler-slot-height) - 1px) var(--scheduler-slot-height)
                ),
                linear-gradient(180deg, rgba(22, 101, 52, 0.045) 0%, rgba(255, 255, 255, 0.96) 24%, rgba(250, 253, 250, 0.95) 100%);
        }

        body.conference-booking-public-page .scheduler-room-lane:first-child {
            border-left: 0;
        }

        body.conference-booking-public-page .scheduler-room-lane.is-disabled {
            background:
                repeating-linear-gradient(
                    135deg,
                    rgba(203, 213, 225, 0.3) 0 12px,
                    rgba(241, 245, 249, 0.8) 12px 24px
                ),
                linear-gradient(180deg, rgba(226, 232, 240, 0.72) 0%, rgba(248, 250, 252, 0.98) 100%);
        }

        body.conference-booking-public-page .scheduler-disabled-block {
            position: absolute;
            left: 12px;
            right: 12px;
            top: 16px;
            z-index: 4;
            display: grid;
            gap: 4px;
            padding: 14px 12px;
            border-radius: 18px;
            border: 1px solid rgba(203, 213, 225, 0.95);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
        }

        body.conference-booking-public-page .scheduler-disabled-title {
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        body.conference-booking-public-page .scheduler-disabled-copy {
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
            font-weight: 600;
        }

        body.conference-booking-public-page .scheduler-event {
            position: absolute;
            left: 8px;
            right: 8px;
            z-index: 5;
            border-radius: 18px;
            border: 1px solid transparent;
            overflow: hidden;
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.16);
            display: flex;
            flex-direction: column;
        }

        body.conference-booking-public-page .scheduler-event.status-booked {
            background: linear-gradient(180deg, #22a65a 0%, #166534 100%);
            border-color: rgba(255, 255, 255, 0.18);
            color: #ffffff;
        }

        body.conference-booking-public-page .scheduler-event.status-pending {
            background: linear-gradient(180deg, #f7d36c 0%, #dca11e 100%);
            border-color: rgba(255, 255, 255, 0.28);
            color: #4a3500;
        }

        body.conference-booking-public-page .scheduler-event-main {
            display: grid;
            gap: 6px;
            padding: 12px 12px 10px;
            min-height: 0;
        }

        body.conference-booking-public-page .scheduler-event-title {
            font-size: 14px;
            line-height: 1.35;
            font-weight: 800;
            overflow-wrap: anywhere;
            word-break: break-word;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            overflow: hidden;
        }

        body.conference-booking-public-page .scheduler-event-meta {
            font-size: 12px;
            line-height: 1.45;
            opacity: 0.94;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        body.conference-booking-public-page .scheduler-event-time {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            max-width: 100%;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            font-size: 11px;
            line-height: 1.25;
            font-weight: 800;
        }

        body.conference-booking-public-page .scheduler-event-buffer {
            margin-top: auto;
            padding: 8px 12px 10px;
            background: rgba(255, 255, 255, 0.14);
            border-top: 1px dashed rgba(255, 255, 255, 0.28);
            font-size: 11px;
            font-weight: 700;
            line-height: 1.35;
        }

        body.conference-booking-public-page .scheduler-event.status-pending .scheduler-event-time {
            background: rgba(255, 255, 255, 0.3);
        }

        body.conference-booking-public-page .scheduler-event.status-pending .scheduler-event-buffer {
            background: rgba(255, 255, 255, 0.22);
            border-top-color: rgba(74, 53, 0, 0.18);
        }

        body.conference-booking-public-page .scheduler-event.is-medium .scheduler-event-title {
            -webkit-line-clamp: 2;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-main {
            gap: 4px;
            padding: 10px 10px 8px;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-title {
            font-size: 12px;
            -webkit-line-clamp: 2;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-meta {
            font-size: 11px;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-buffer {
            padding: 6px 10px 8px;
            font-size: 10px;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-main {
            gap: 4px;
            padding: 8px 9px 7px;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-title {
            font-size: 11px;
            -webkit-line-clamp: 2;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-meta {
            display: none;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-time {
            padding: 3px 7px;
            font-size: 10px;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-buffer {
            padding: 5px 8px 6px;
            font-size: 9px;
        }

        body.conference-booking-public-page .scheduler-empty {
            padding: 56px 24px;
            text-align: center;
            color: #64748b;
        }

        body.conference-booking-public-page .scheduler-empty i {
            font-size: 40px;
            color: #94a3b8;
            margin-bottom: 14px;
        }

        body.conference-booking-public-page .scheduler-empty strong {
            display: block;
            margin-bottom: 8px;
            color: #0f172a;
            font-size: 18px;
            font-weight: 800;
        }

        body.conference-booking-public-page .scheduler-empty p {
            margin: 0;
            font-size: 14px;
            line-height: 1.65;
        }

        @media (max-width: 1180px) {
            body.conference-booking-public-page .scheduler-header {
                align-items: flex-start;
            }

            body.conference-booking-public-page .scheduler-header .panel-header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            body.conference-booking-public-page .scheduler-toolbar {
                grid-template-columns: 1fr;
            }

            body.conference-booking-public-page .availability-legend {
                justify-content: flex-start;
            }
        }

        @media (max-width: 860px) {
            body.conference-booking-public-page .scheduler-heading {
                align-items: flex-start;
            }

            body.conference-booking-public-page .scheduler-header-right,
            body.conference-booking-public-page .scheduler-header-controls,
            body.conference-booking-public-page .scheduler-header .panel-header-actions {
                flex-direction: column;
                align-items: stretch;
            }

            body.conference-booking-public-page .scheduler-header-right,
            body.conference-booking-public-page .scheduler-header-controls {
                width: 100%;
            }

            body.conference-booking-public-page .scheduler-header .panel-header-actions > * {
                width: 100%;
            }

            body.conference-booking-public-page .scheduler-header .availability-filter {
                min-width: 0;
            }

            body.conference-booking-public-page .week-nav {
                width: 100%;
                justify-content: space-between;
            }

            body.conference-booking-public-page .week-label {
                flex: 1 1 auto;
                text-align: center;
                padding: 0 4px;
            }

            body.conference-booking-public-page .scheduler-board {
                --scheduler-slot-height: 38px;
            }

            body.conference-booking-public-page .scheduler-board-head,
            body.conference-booking-public-page .scheduler-board-body {
                grid-template-columns: 84px repeat(6, minmax(210px, 1fr));
            }

            body.conference-booking-public-page .scheduler-day-head-main {
                padding: 15px 12px 10px;
                min-height: 64px;
            }

            body.conference-booking-public-page .scheduler-room-head {
                padding: 10px 9px 11px;
            }

            body.conference-booking-public-page .scheduler-event {
                left: 6px;
                right: 6px;
                border-radius: 16px;
            }
        }

        @media (max-width: 640px) {
            body.conference-booking-public-page .scheduler-heading-copy h3 {
                font-size: 20px;
            }

            body.conference-booking-public-page .scheduler-heading-copy p {
                font-size: 13px;
            }

            body.conference-booking-public-page .scheduler-summary-card {
                padding: 16px 16px 15px;
            }

            body.conference-booking-public-page .scheduler-summary-value {
                font-size: 20px;
            }

            body.conference-booking-public-page .legend-row {
                align-items: flex-start;
            }

            body.conference-booking-public-page .week-nav-link {
                width: 38px;
                height: 38px;
                border-radius: 12px;
            }

            body.conference-booking-public-page .week-nav-link.is-wide {
                min-width: 88px;
                padding: 0 12px;
            }

            body.conference-booking-public-page .scheduler-board-wrap {
                border-radius: 22px;
            }

            body.conference-booking-public-page .scheduler-board {
                --scheduler-slot-height: 34px;
            }

            body.conference-booking-public-page .scheduler-board-head,
            body.conference-booking-public-page .scheduler-board-body {
                grid-template-columns: 74px repeat(6, minmax(180px, 1fr));
            }

            body.conference-booking-public-page .scheduler-time-head {
                padding: 16px 10px;
            }

            body.conference-booking-public-page .scheduler-time-head-label {
                font-size: 16px;
            }

            body.conference-booking-public-page .scheduler-time-slot {
                padding: 5px 8px 0;
                font-size: 11px;
            }

            body.conference-booking-public-page .scheduler-time-end {
                padding: 0 8px;
                font-size: 11px;
            }

            body.conference-booking-public-page .scheduler-day-name {
                font-size: 16px;
            }

            body.conference-booking-public-page .scheduler-day-date {
                font-size: 12px;
            }

            body.conference-booking-public-page .scheduler-room-head-name {
                font-size: 12px;
            }

            body.conference-booking-public-page .scheduler-room-head-state {
                font-size: 10px;
            }
        }

        body.conference-booking-public-page .booking-modal-overlay[hidden] {
            display: none !important;
        }

        body.conference-booking-public-page .booking-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            z-index: 2000;
        }

        body.conference-booking-public-page .booking-modal {
            width: min(1100px, 100%);
            min-height: 0;
            max-height: calc(100vh - 32px);
            overflow: hidden;
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid rgba(226, 232, 240, 0.92);
            border-radius: 24px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.24);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        body.conference-booking-public-page .booking-modal::before {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--booking-gold), #f6e087);
        }

        body.conference-booking-public-page .booking-modal-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 22px 24px 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        body.conference-booking-public-page .booking-modal-title {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
        }

        body.conference-booking-public-page .booking-modal-subtitle {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 14px;
            font-weight: 400;
        }

        body.conference-booking-public-page .booking-modal-close {
            margin-left: auto;
            width: 44px;
            height: 44px;
            border-radius: 14px;
            border: 1px solid #dbe2ea;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        body.conference-booking-public-page .booking-modal-body {
            flex: 0 1 auto;
            padding: 22px 28px 22px;
            max-height: calc(100vh - 130px);
            overflow-y: auto;
            scrollbar-gutter: stable;
        }

        @media (max-width: 1100px) {
            body.conference-booking-public-page .booking-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 860px) {
            body.conference-booking-public-page .booking-grid,
            body.conference-booking-public-page .availability-toolbar,
            body.conference-booking-public-page .availability-legend {
                grid-template-columns: 1fr;
            }

            body.conference-booking-public-page .booking-grid.date-time-row {
                grid-template-columns: 1fr;
            }

            body.conference-booking-public-page .availability-legend {
                justify-content: flex-start;
            }

            body.conference-booking-public-page .panel-header-actions {
                width: 100%;
                justify-content: flex-end;
            }

            body.conference-booking-public-page .booking-rule-tooltip {
                margin-top: -4px;
                justify-self: start;
            }

            body.conference-booking-public-page .booking-rule-popup {
                left: 0;
                transform: translateX(0) translateY(8px);
            }

            body.conference-booking-public-page .booking-rule-popup::before {
                left: 18px;
                transform: rotate(45deg);
            }

            body.conference-booking-public-page .booking-rule-tooltip:hover .booking-rule-popup,
            body.conference-booking-public-page .booking-rule-tooltip:focus-within .booking-rule-popup {
                transform: translateX(0) translateY(0);
            }
        }

        @media (max-width: 640px) {
            body.conference-booking-public-page .dashboard-container {
                padding: 14px 12px 24px;
            }

            body.conference-booking-public-page .public-shell {
                padding: 14px 12px 20px;
                border-radius: 22px;
            }

            body.conference-booking-public-page .page-topbar {
                grid-template-columns: 1fr;
                justify-items: stretch;
                margin-bottom: 12px;
            }

            body.conference-booking-public-page .topbar-side.left {
                justify-content: flex-start;
            }

            body.conference-booking-public-page .topbar-side.right {
                display: flex;
                justify-content: center;
            }

            body.conference-booking-public-page .back-link,
            body.conference-booking-public-page .brand-chip {
                width: 100%;
            }

            body.conference-booking-public-page .panel-body,
            body.conference-booking-public-page .panel-header {
                padding-left: 14px;
                padding-right: 14px;
            }

            body.conference-booking-public-page .booking-modal-overlay {
                padding: 12px;
            }

            body.conference-booking-public-page .booking-modal-header,
            body.conference-booking-public-page .booking-modal-body {
                padding-left: 14px;
                padding-right: 14px;
            }

            body.conference-booking-public-page .panel-divider {
                margin: 0 14px;
            }

            body.conference-booking-public-page .form-actions {
                flex-direction: column;
                align-items: stretch;
            }

            body.conference-booking-public-page .btn-lite,
            body.conference-booking-public-page .btn-submit {
                width: 100%;
            }
        }

        /* UI refresh: conference booking reference alignment (logic unchanged) */
        body.conference-booking-public-page {
            background: #eef2f2;
            color: #111827;
        }

        body.conference-booking-public-page .dashboard-container {
            width: 100%;
            max-width: none;
            padding: 18px 28px 24px;
        }

        body.conference-booking-public-page .content-wrapper {
            width: min(100%, 1180px);
            max-width: 1180px;
            margin: 0 auto;
        }

        body.conference-booking-public-page::before {
            background: none;
        }

        body.conference-booking-public-page .public-shell {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 26px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            padding: 0;
            width: 100%;
        }

        body.conference-booking-public-page .page-topbar,
        body.conference-booking-public-page .page-header,
        body.conference-booking-public-page .page-breadcrumb,
        body.conference-booking-public-page .booking-layout {
            padding-left: 16px;
            padding-right: 16px;
        }

        body.conference-booking-public-page .page-topbar {
            padding-top: 18px;
            margin-bottom: 6px;
        }

        body.conference-booking-public-page .back-link {
            min-height: 54px;
            padding: 0 18px;
            background: #eff7f1;
            border: 1px solid #cfe1d5;
            color: #166534;
            font-size: 15px;
            font-weight: 800;
            gap: 8px;
        }

        body.conference-booking-public-page .brand-chip {
            font-size: 16px;
            font-weight: 800;
            color: #166534;
        }

        body.conference-booking-public-page .brand-chip img {
            width: 44px;
            height: 44px;
        }

        body.conference-booking-public-page .page-header {
            margin: 0;
            text-align: center;
            padding-top: 8px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        body.conference-booking-public-page .page-title {
            font-size: clamp(42px, 5vw, 64px);
            line-height: 1.05;
            font-weight: 600;
            letter-spacing: -0.045em;
            color: #166534;
        }

        body.conference-booking-public-page .page-subtitle {
            margin-top: 8px;
            font-size: 16px;
            color: #6b7280;
            line-height: 1.45;
        }

        body.conference-booking-public-page .page-breadcrumb {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 66px;
            border-bottom: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 17px;
            font-weight: 500;
            background: #ffffff;
        }

        body.conference-booking-public-page .crumb-home {
            color: #16a34a;
            font-size: 20px;
            width: 26px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        body.conference-booking-public-page .crumb-sep {
            color: #94a3b8;
            font-weight: 700;
        }

        body.conference-booking-public-page .crumb-item.is-current {
            color: #111827;
            font-weight: 500;
        }

        body.conference-booking-public-page .booking-layout {
            margin-top: 0;
            padding-bottom: 22px;
            width: 100%;
            max-width: 1120px;
            margin-left: auto;
            margin-right: auto;
        }

        body.conference-booking-public-page .booking-panel {
            border-radius: 0 0 22px 22px;
            border: none;
            box-shadow: none;
            background: transparent;
        }

        body.conference-booking-public-page .booking-panel::before {
            display: none;
        }

        body.conference-booking-public-page .panel-header.scheduler-header {
            padding: 20px 0 16px;
            border-bottom: 1px solid #e5e7eb;
            display: grid;
            grid-template-columns: 1fr;
            grid-template-areas:
                "actions"
                "legend";
            gap: 16px;
            align-items: center;
        }

        body.conference-booking-public-page .scheduler-heading {
            grid-area: heading;
            align-items: flex-start;
            gap: 0;
            flex: 1 1 420px;
        }

        body.conference-booking-public-page .scheduler-heading-copy h3 {
            font-size: 28px;
            line-height: 1.15;
            font-weight: 600;
        }

        body.conference-booking-public-page .scheduler-heading-copy p {
            margin-top: 6px;
            font-size: 14px;
            line-height: 1.45;
            color: #6b7280;
            max-width: 420px;
        }

        body.conference-booking-public-page .panel-header-icon {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: #e8f3ea;
            color: #166534;
            font-size: 16px;
            flex: 0 0 34px;
        }

        body.conference-booking-public-page .scheduler-header .panel-header-actions {
            grid-area: actions;
            width: 100%;
            margin-left: 0;
            justify-content: flex-start;
            gap: 6px;
            flex-wrap: nowrap;
        }

        body.conference-booking-public-page .week-nav {
            position: relative;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            box-shadow: 0 3px 8px rgba(15, 23, 42, 0.04);
            padding: 2px 5px;
            gap: 3px;
            flex: 0 0 auto;
            min-height: 36px;
            overflow: visible;
        }

        body.conference-booking-public-page .week-nav-calendar-wrap {
            position: relative;
            display: inline-flex;
            flex: 0 0 auto;
        }

        body.conference-booking-public-page .week-nav-calendar {
            width: 24px;
            height: 24px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            color: #166534;
            font-size: 11px;
            background: #ecfdf3;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.18s ease, color 0.18s ease;
        }

        body.conference-booking-public-page .week-nav-calendar:hover,
        body.conference-booking-public-page .week-nav-calendar:focus-visible {
            background: #dcfce7;
            color: #14532d;
        }

        body.conference-booking-public-page .week-nav-calendar-input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            padding: 0;
            margin: 0;
            border: 0;
            opacity: 0;
            pointer-events: none;
        }

        body.conference-booking-public-page .week-nav-link {
            width: 24px;
            height: 24px;
            border-radius: 7px;
            border: none;
            background: #ffffff;
            color: #111827;
            box-shadow: none;
            font-size: 11px;
        }

        body.conference-booking-public-page .week-nav-link.is-wide {
            min-width: 74px;
            height: 28px;
            padding: 0 10px;
            border: 1px solid #166534;
            background: #166534;
            color: #ffffff;
        }

        body.conference-booking-public-page .week-nav-link:hover {
            transform: none;
            background: #14532d;
            color: #ffffff;
            border-color: #14532d;
        }

        body.conference-booking-public-page .week-nav-link.is-current {
            background: #166534;
            border-color: #166534;
            color: #fff;
        }

        body.conference-booking-public-page .week-label {
            font-size: 12px;
            color: #111827;
            font-weight: 500;
            letter-spacing: -0.01em;
            min-width: 124px;
            text-align: center;
        }

        body.conference-booking-public-page .availability-filter {
            min-width: 0;
            width: 160px;
            flex: 0 0 160px;
        }

        body.conference-booking-public-page .availability-filter .form-control {
            min-height: 36px;
            height: 36px;
            border-radius: 9px;
            border-color: #e5e7eb;
            font-size: 12px;
            font-weight: 500;
            color: #111827;
        }

        body.conference-booking-public-page .panel-header .btn-submit {
            min-height: 36px;
            height: 36px;
            border-radius: 9px;
            padding: 0 12px;
            font-size: 12px;
            font-weight: 600;
            background: #166534;
            color: #ffffff;
            box-shadow: 0 3px 8px rgba(22, 101, 52, 0.16);
            flex: 0 0 auto;
            white-space: nowrap;
        }

        body.conference-booking-public-page .panel-header .btn-submit i {
            font-size: 11px;
        }

        body.conference-booking-public-page .panel-divider {
            display: none;
        }

        body.conference-booking-public-page .panel-body {
            padding: 14px 0 0;
        }

        body.conference-booking-public-page .scheduler-toolbar {
            grid-template-columns: minmax(280px, 1fr);
            gap: 14px;
            margin-bottom: 14px;
        }

        body.conference-booking-public-page .scheduler-summary-card {
            border-radius: 16px;
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            box-shadow: none;
            padding: 18px 20px;
            gap: 6px;
        }

        body.conference-booking-public-page .scheduler-summary-label {
            font-size: 12px;
            letter-spacing: 0.14em;
            font-weight: 800;
        }

        body.conference-booking-public-page .scheduler-summary-value {
            font-size: 22px;
            font-weight: 600;
            color: #111827;
        }

        body.conference-booking-public-page .scheduler-summary-meta {
            font-size: 16px;
            color: #6b7280;
            line-height: 1.5;
        }

        body.conference-booking-public-page .legend-card {
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            background: #fff;
            box-shadow: none;
            padding: 8px 12px;
        }

        body.conference-booking-public-page .legend-row {
            flex-wrap: nowrap;
            gap: 10px;
        }

        body.conference-booking-public-page .legend-title {
            color: #166534;
            font-size: 11px;
        }

        body.conference-booking-public-page .legend-items {
            gap: 12px;
        }

        body.conference-booking-public-page .legend-item {
            font-size: 12px;
        }

        body.conference-booking-public-page .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 3px;
            box-shadow: none;
        }

        body.conference-booking-public-page .legend-dot.available {
            background: #ffffff;
            border: 1px solid #e5e7eb;
        }

        body.conference-booking-public-page .legend-dot.booked {
            background: #16a34a;
            border: 1px solid #16a34a;
        }

        body.conference-booking-public-page .legend-dot.caltex {
            background: linear-gradient(180deg, #d8f3dd 0%, #c9efcf 100%);
            border: 1px solid #9dd7aa;
        }

        body.conference-booking-public-page .legend-dot.mpdc {
            background: linear-gradient(180deg, #6da4e0 0%, #4d84c8 100%);
            border: 1px solid #4d84c8;
        }

        body.conference-booking-public-page .legend-dot.is-room {
            background: var(--legend-room-color, #16a34a);
            border: 1px solid var(--legend-room-color, #16a34a);
        }

        body.conference-booking-public-page .legend-tooltip {
            display: none;
        }

        body.conference-booking-public-page .scheduler-board-wrap {
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            box-shadow: none;
            background: #fff;
        }

        body.conference-booking-public-page .scheduler-board-head {
            background: #166534;
        }

        body.conference-booking-public-page .scheduler-time-head {
            background: #166534;
            padding: 12px 12px 10px;
        }

        body.conference-booking-public-page .scheduler-time-head-label {
            font-size: 16px;
        }

        body.conference-booking-public-page .scheduler-day-head-main {
            min-height: 44px;
            padding: 10px 10px 8px;
            gap: 8px;
        }

        body.conference-booking-public-page .scheduler-day-name {
            font-size: 16px;
            font-weight: 700;
        }

        body.conference-booking-public-page .scheduler-day-date {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.92);
        }

        body.conference-booking-public-page .scheduler-day-badge {
            font-size: 10px;
            min-height: 24px;
            padding: 0 10px;
        }

        body.conference-booking-public-page .scheduler-room-heads {
            display: grid;
            grid-template-columns: repeat(var(--scheduler-lane-count, 1), minmax(0, 1fr));
            background: rgba(255, 255, 255, 0.12);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        body.conference-booking-public-page .scheduler-room-head {
            min-width: 0;
            display: grid;
            gap: 2px;
            padding: 10px 14px 9px;
            border-left: 1px solid rgba(20, 83, 45, 0.12);
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.96) 0%, rgba(220, 252, 231, 0.88) 100%);
        }

        body.conference-booking-public-page .scheduler-room-head-name {
            color: #166534 !important;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        body.conference-booking-public-page .scheduler-room-head-name.room-caltex {
            color: #2f7d32 !important;
        }

        body.conference-booking-public-page .scheduler-room-head-name.room-mpdc {
            color: #2d5f8f !important;
        }

        body.conference-booking-public-page .scheduler-room-head.room-caltex {
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.98) 0%, rgba(220, 252, 231, 0.9) 100%);
            border-left-color: rgba(22, 101, 52, 0.1);
        }

        body.conference-booking-public-page .scheduler-room-head.room-caltex .scheduler-room-head-name {
            color: #2f7d32 !important;
        }

        body.conference-booking-public-page .scheduler-room-head.room-caltex .scheduler-room-head-state {
            color: rgba(22, 83, 52, 0.84);
        }

        body.conference-booking-public-page .scheduler-room-head.room-mpdc {
            background: linear-gradient(180deg, #6da4e0 0%, #4d84c8 100%);
            border-left-color: rgba(77, 132, 200, 0.28);
        }

        body.conference-booking-public-page .scheduler-room-head.room-mpdc .scheduler-room-head-name,
        body.conference-booking-public-page .scheduler-room-head.room-mpdc .scheduler-room-head-state {
            color: #eff6ff !important;
        }

        body.conference-booking-public-page .scheduler-room-head-state {
            color: rgba(22, 83, 52, 0.78);
            font-size: 11px;
            font-weight: 500;
        }

        body.conference-booking-public-page .scheduler-time-rail {
            background: #ecf5ed;
            border-right: 1px solid #e5e7eb;
        }

        body.conference-booking-public-page .scheduler-time-slot {
            padding: 5px 10px 0;
            font-size: 11px;
            color: #64806b;
        }

        body.conference-booking-public-page .scheduler-time-slot.is-hour {
            color: #166534;
            font-size: 14px;
            font-weight: 800;
        }

        body.conference-booking-public-page .scheduler-time-slot::after {
            border-bottom-color: #e5e7eb;
        }

        body.conference-booking-public-page .scheduler-day-column,
        body.conference-booking-public-page .scheduler-room-lane {
            background: #fff;
            border-left-color: #e5e7eb;
        }


        body.conference-booking-public-page .scheduler-room-lane {
            --scheduler-booking-color: #16a34a;
            background:
                repeating-linear-gradient(
                    to bottom,
                    transparent 0 calc(var(--scheduler-slot-height) - 1px),
                    #e5e7eb calc(var(--scheduler-slot-height) - 1px) var(--scheduler-slot-height)
                ),
                #ffffff;
        }

        body.conference-booking-public-page .scheduler-room-lane.room-caltex {
            --scheduler-booking-color: #166534;
            background:
                repeating-linear-gradient(
                    to bottom,
                    transparent 0 calc(var(--scheduler-slot-height) - 1px),
                    rgba(220, 252, 231, 0.92) calc(var(--scheduler-slot-height) - 1px) var(--scheduler-slot-height)
                ),
                linear-gradient(180deg, rgba(240, 253, 244, 0.72) 0%, rgba(255, 255, 255, 0.98) 22%);
        }

        body.conference-booking-public-page .scheduler-room-lane.room-mpdc {
            --scheduler-booking-color: #6da4e0;
            background:
                repeating-linear-gradient(
                    to bottom,
                    transparent 0 calc(var(--scheduler-slot-height) - 1px),
                    rgba(209, 225, 247, 0.92) calc(var(--scheduler-slot-height) - 1px) var(--scheduler-slot-height)
                ),
                linear-gradient(180deg, rgba(228, 238, 252, 0.88) 0%, rgba(255, 255, 255, 0.98) 24%);
        }

        body.conference-booking-public-page .scheduler-event {
            --scheduler-event-gap: 3px;
            border-radius: 14px;
            left: 10px;
            right: 10px;
            overflow: hidden;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.12);
        }

        body.conference-booking-public-page .scheduler-event.status-booked {
            background: var(--scheduler-booking-color, #16a34a);
            border-color: var(--scheduler-booking-color, #16a34a);
            color: #ffffff;
        }

        body.conference-booking-public-page .scheduler-room-lane.room-caltex .scheduler-event.status-booked {
            background: linear-gradient(180deg, #d8f3dd 0%, #c9efcf 100%);
            border-color: rgba(34, 94, 56, 0.16);
            color: #1f4d31;
            box-shadow: inset 3px 0 0 #2f7d32;
        }

        body.conference-booking-public-page .scheduler-room-lane.room-mpdc .scheduler-event.status-booked {
            background: linear-gradient(180deg, #6da4e0 0%, #4d84c8 100%);
            border-color: rgba(77, 132, 200, 0.28);
            color: #ffffff;
            box-shadow: inset 3px 0 0 #2f65b0;
        }

        body.conference-booking-public-page .scheduler-event.room-caltex.status-booked {
            background: linear-gradient(180deg, #d8f3dd 0%, #c9efcf 100%);
            border-color: rgba(34, 94, 56, 0.16);
            color: #1f4d31;
            box-shadow: inset 3px 0 0 #2f7d32;
        }

        body.conference-booking-public-page .scheduler-event.room-mpdc.status-booked {
            background: linear-gradient(180deg, #6da4e0 0%, #4d84c8 100%);
            border-color: rgba(77, 132, 200, 0.28);
            color: #ffffff;
            box-shadow: inset 3px 0 0 #2f65b0;
        }

        body.conference-booking-public-page .scheduler-event.room-mpdc .scheduler-event-title,
        body.conference-booking-public-page .scheduler-event.room-mpdc .scheduler-event-meta,
        body.conference-booking-public-page .scheduler-event.room-mpdc .scheduler-event-buffer {
            color: #ffffff !important;
            opacity: 1;
        }

        body.conference-booking-public-page .scheduler-event.room-mpdc .scheduler-event-time {
            background: rgba(255, 255, 255, 0.54);
            color: #204c82 !important;
            opacity: 1;
        }

        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-title,
        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-meta,
        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-buffer,
        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-time {
            color: #1f4d31 !important;
            opacity: 1;
        }

        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-time {
            background: rgba(84, 148, 102, 0.18);
        }

        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-buffer {
            background: rgba(84, 148, 102, 0.12);
            border-top-color: rgba(84, 148, 102, 0.28);
        }

        body.conference-booking-public-page .scheduler-event.status-pending {
            background: #ef4444;
            border-color: #ef4444;
            color: #fff;
        }

        body.conference-booking-public-page .scheduler-event-main {
            gap: 5px;
            padding: 10px 12px 8px;
        }

        body.conference-booking-public-page .scheduler-event-title {
            font-size: 12px;
            font-weight: 800;
            -webkit-line-clamp: 3;
            line-height: 1.25;
        }

        body.conference-booking-public-page .scheduler-event-meta {
            font-size: 11px;
            line-height: 1.35;
            color: currentColor;
            opacity: 0.94;
        }

        body.conference-booking-public-page .scheduler-event-time {
            font-size: 10px;
            padding: 4px 10px;
            color: currentColor;
            background: rgba(255, 255, 255, 0.26);
            font-weight: 800;
        }

        body.conference-booking-public-page .scheduler-event-buffer {
            font-size: 10px;
            padding: 7px 12px 8px;
            background: rgba(255, 255, 255, 0.16);
            color: currentColor;
            border-top: 1px dashed rgba(255, 255, 255, 0.45);
            opacity: 0.92;
        }

        body.conference-booking-public-page .scheduler-event.room-mpdc .scheduler-event-time {
            background: rgba(255, 255, 255, 0.5);
            color: #20496d;
        }

        body.conference-booking-public-page .scheduler-event.room-caltex .scheduler-event-time {
            background: rgba(255, 255, 255, 0.28);
            color: #ffffff;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-main {
            gap: 4px;
            padding: 10px 10px 7px;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-main {
            gap: 3px;
            padding: 8px 9px 6px;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-title,
        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-title {
            font-size: 11px;
            line-height: 1.25;
            -webkit-line-clamp: 2;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-meta {
            display: block;
            font-size: 10px;
            line-height: 1.2;
            opacity: 0.98;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-meta {
            display: none;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-time {
            font-size: 9px;
            padding: 3px 8px;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-time {
            font-size: 9px;
            padding: 2px 7px;
        }

        body.conference-booking-public-page .scheduler-event.is-compact .scheduler-event-buffer {
            font-size: 9px;
            padding: 4px 8px 5px;
            opacity: 1;
        }

        body.conference-booking-public-page .scheduler-event.is-mini .scheduler-event-buffer {
            font-size: 8px;
            padding: 3px 7px 4px;
            opacity: 1;
        }

        @media (max-width: 1180px) {
            body.conference-booking-public-page .panel-header.scheduler-header {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "heading"
                    "actions"
                    "legend";
            }

            body.conference-booking-public-page .scheduler-header .panel-header-actions {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            body.conference-booking-public-page .scheduler-toolbar {
                grid-template-columns: 1fr;
            }

            body.conference-booking-public-page .availability-legend {
                justify-content: flex-start;
            }

            body.conference-booking-public-page .legend-row {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body class="conference-booking-public-page">
    <div class="dashboard-container">
        <div class="content-wrapper">
            <div class="public-shell">
                <div class="page-topbar">
                    <div class="topbar-side left">
                        <a href="index.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            <span>Back to Home</span>
                        </a>
                    </div>
                    <div class="topbar-side right">
                        <div class="brand-chip">
                            <img src="assets/img/leads-logo.png" alt="Leads Agri Logo">
                            <span>Leads Agri Helpdesk</span>
                        </div>
                    </div>
                </div>

                <div class="page-header">
                    <h2 class="page-title">Conference Room Scheduler</h2>
                    <p class="page-subtitle">Track room availability in a full weekly planner and reserve time without losing the current booking logic.</p>
                </div>

                <?php if ($successMessage !== ''): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                <?php endif; ?>

                <div class="booking-layout">
                    <section class="booking-panel">
                        <div class="panel-header scheduler-header">
                            <div class="panel-header-actions">
                                <div class="availability-legend scheduler-header-legend">
                                    <div class="legend-card">
                                        <div class="legend-row">
                                            <div class="legend-title">Legend</div>
                                            <div class="legend-items">
                                                <span class="legend-item"><span class="legend-dot available"></span>Open</span>
                                                <span class="legend-item"><span class="legend-dot caltex"></span>Caltex</span>
                                                <span class="legend-item"><span class="legend-dot mpdc"></span>MPDC</span>
                                            </div>
                                            <div class="legend-tooltip">
                                                <span class="legend-tooltip-trigger" tabindex="0" aria-label="View timezone note">
                                                    <i class="fas fa-circle-info"></i>
                                                </span>
                                                <div class="legend-tooltip-popup">All times are in your local time zone. Saturday availability depends on each room's admin setting.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="scheduler-header-right">
                                    <div class="scheduler-header-controls">
                                        <div class="week-nav">
                                            <span class="week-nav-calendar-wrap">
                                                <button
                                                    type="button"
                                                    class="week-nav-calendar"
                                                    id="openWeekPickerBtn"
                                                    aria-label="Choose a date to view that week"
                                                    title="Choose a date to view that week"
                                                >
                                                    <i class="far fa-calendar-days"></i>
                                                </button>
                                                <input
                                                    type="date"
                                                    id="weekNavDatePicker"
                                                    class="week-nav-calendar-input"
                                                    value="<?= htmlspecialchars($referenceWeekDate, ENT_QUOTES, 'UTF-8'); ?>"
                                                    aria-hidden="true"
                                                    tabindex="-1"
                                                >
                                            </span>
                                            <a class="week-nav-link" href="?<?= htmlspecialchars(http_build_query(['week_of' => $previousWeekDate, 'room_filter' => $selectedRoomFilter]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous week">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                            <span class="week-label"><?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <a class="week-nav-link" href="?<?= htmlspecialchars(http_build_query(['week_of' => $nextWeekDate, 'room_filter' => $selectedRoomFilter]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next week">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </div>
                                        <div class="select-wrapper availability-filter">
                                            <select id="availabilityRoomFilter" class="form-control">
                                                <option value="0" <?= $selectedRoomFilter === 0 ? 'selected' : ''; ?>>All Rooms</option>
                                                <?php foreach ($rooms as $room): ?>
                                                    <?php $roomId = (int) ($room['id'] ?? 0); ?>
                                                    <option value="<?= $roomId; ?>" <?= $selectedRoomFilter === $roomId ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                        </div>
                                    </div>
                                    <button type="button" class="btn-submit" id="openBookingModalBtn" <?= count($rooms) === 0 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-plus"></i>
                                        <span>Add New Booking</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="panel-divider"></div>
                        <div class="panel-body">
                            <div class="scheduler-toolbar">
                                <div class="scheduler-summary-card">
                                    <span class="scheduler-summary-label">
                                        <i class="fas fa-seedling"></i>
                                        <span>Current View</span>
                                    </span>
                                    <div class="scheduler-summary-value"><?= htmlspecialchars($schedulerViewLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="scheduler-summary-meta">
                                        <?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        |
                                        <?= (int) $schedulerBookingCount; ?> booking<?= $schedulerBookingCount === 1 ? '' : 's'; ?> scheduled this week.
                                        A 30-minute cleanup buffer is shown as a lighter tail on each booking block.
                                    </div>
                                </div>
                            </div>

                            <?php if (count($schedulerRooms) > 0 && $schedulerIntervalCount > 0): ?>
                                <div class="scheduler-board-wrap">
                                    <div
                                        class="scheduler-board"
                                        style="--scheduler-lane-count: <?= $schedulerLaneCount; ?>; --scheduler-interval-count: <?= max(1, $schedulerIntervalCount); ?>; --scheduler-day-min-width: <?= 220 + max(0, $schedulerLaneCount - 1) * 120; ?>px;"
                                    >
                                        <div class="scheduler-board-head">
                                            <div class="scheduler-time-head">
                                                <span class="scheduler-time-head-label">Time</span>
                                                <span class="scheduler-time-head-note">30-minute rows</span>
                                            </div>
                                            <?php foreach ($weekDays as $day): ?>
                                                <?php
                                                    $dayDate = (string) ($day['date'] ?? '');
                                                    $isTodayColumn = $highlightTodayDate !== '' && $dayDate === $highlightTodayDate;
                                                ?>
                                                <div class="scheduler-day-head <?= $isTodayColumn ? 'is-today' : ''; ?>">
                                                    <div class="scheduler-day-head-main">
                                                        <span class="scheduler-day-name"><?= htmlspecialchars(date('D', strtotime($dayDate)), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="scheduler-day-date"><?= htmlspecialchars(date('d M Y', strtotime($dayDate)), ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php if ($isTodayColumn): ?>
                                                            <span class="scheduler-day-badge">Today</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="scheduler-room-heads">
                                                        <?php foreach ($schedulerRooms as $room): ?>
                                                            <?php
                                                                $roomSupportsDate = conference_booking_room_supports_booking_date($room, $dayDate);
                                                                $roomHeadState = $roomSupportsDate ? 'Open for booking' : 'Saturday disabled';
                                                                $roomVisuals = conference_booking_page_room_visuals((string) ($room['room_name'] ?? 'room'));
                                                                $roomSlug = (string) ($roomVisuals['slug'] ?? 'room');
                                                            ?>
                                                            <div class="scheduler-room-head room-<?= htmlspecialchars($roomSlug, ENT_QUOTES, 'UTF-8'); ?> <?= $roomSupportsDate ? '' : 'is-disabled'; ?>">
                                                                <span class="scheduler-room-head-name room-<?= htmlspecialchars($roomSlug, ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars((string) ($room['room_name'] ?? 'Room'), ENT_QUOTES, 'UTF-8'); ?></span>
                                                                <span class="scheduler-room-head-state"><?= htmlspecialchars($roomHeadState, ENT_QUOTES, 'UTF-8'); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="scheduler-board-body">
                                            <div class="scheduler-time-rail">
                                                <?php foreach ($schedulerGridTicks as $tick): ?>
                                                    <div class="scheduler-time-slot <?= !empty($tick['is_hour']) ? 'is-hour' : ''; ?>">
                                                        <span><?= htmlspecialchars((string) ($tick['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php foreach ($weekDays as $day): ?>
                                                <?php
                                                    $dayDate = (string) ($day['date'] ?? '');
                                                    $isTodayColumn = $highlightTodayDate !== '' && $dayDate === $highlightTodayDate;
                                                ?>
                                                <div class="scheduler-day-column <?= $isTodayColumn ? 'is-today' : ''; ?>">
                                                    <div class="scheduler-day-lanes">
                                                        <?php foreach ($schedulerRooms as $room): ?>
                                                            <?php
                                                                $roomId = (int) ($room['id'] ?? 0);
                                                                $roomSupportsDate = conference_booking_room_supports_booking_date($room, $dayDate);
                                                                $roomEvents = (array) ($schedulerEventsByDateRoom[$dayDate][$roomId] ?? []);
                                                                $roomVisuals = conference_booking_page_room_visuals((string) ($room['room_name'] ?? 'room'));
                                                                $roomSlug = (string) ($roomVisuals['slug'] ?? 'room');
                                                                $roomBookingColor = (string) ($roomVisuals['booking_color'] ?? '#16a34a');
                                                            ?>
                                                            <div class="scheduler-room-lane room-<?= htmlspecialchars($roomSlug, ENT_QUOTES, 'UTF-8'); ?> <?= $roomSupportsDate ? '' : 'is-disabled'; ?>">
                                                                <?php if (!$roomSupportsDate): ?>
                                                                    <div class="scheduler-disabled-block">
                                                                        <span class="scheduler-disabled-title">Unavailable</span>
                                                                        <span class="scheduler-disabled-copy">Saturday booking is disabled for this room.</span>
                                                                    </div>
                                                                <?php endif; ?>

                                                                <?php foreach ($roomEvents as $event): ?>
                                                                    <?php
                                                                        $eventStatus = (string) ($event['status'] ?? 'booked');
                                                                        $eventInlineStyle = '';
                                                                        if ($eventStatus === 'booked') {
                                                                            $eventInlineStyle = '--scheduler-booking-color:' . $roomBookingColor . ';';
                                                                        }
                                                                        $eventInlineStyle .= 'top: calc((var(--scheduler-minute-height) * ' . (int) ($event['top_minutes'] ?? 0) . ') + (var(--scheduler-event-gap) / 2));';
                                                                        $eventInlineStyle .= ' height: calc((var(--scheduler-minute-height) * ' . (int) ($event['display_minutes'] ?? 30) . ') - var(--scheduler-event-gap));';
                                                                    ?>
                                                                    <article
                                                                        class="scheduler-event room-<?= htmlspecialchars($roomSlug, ENT_QUOTES, 'UTF-8'); ?> status-<?= htmlspecialchars($eventStatus, ENT_QUOTES, 'UTF-8'); ?> <?= htmlspecialchars((string) ($event['size_class'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                        style="<?= htmlspecialchars($eventInlineStyle, ENT_QUOTES, 'UTF-8'); ?>"
                                                                        title="<?= htmlspecialchars((string) ($event['tooltip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                    >
                                                                        <div class="scheduler-event-main">
                                                                            <div class="scheduler-event-title"><?= htmlspecialchars((string) ($event['title'] ?? 'Booked'), ENT_QUOTES, 'UTF-8'); ?></div>
                                                                            <?php if (trim((string) ($event['meta'] ?? '')) !== ''): ?>
                                                                                <div class="scheduler-event-meta"><?= htmlspecialchars((string) ($event['meta'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                                            <?php endif; ?>
                                                                            <div class="scheduler-event-time"><?= htmlspecialchars((string) ($event['time_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                                        </div>
                                                                        <?php if ((int) ($event['buffer_minutes'] ?? 0) > 0): ?>
                                                                            <div class="scheduler-event-buffer">
                                                                                <span><?= htmlspecialchars((string) ($event['buffer_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </article>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="scheduler-empty">
                                    <i class="fas fa-calendar-xmark"></i>
                                    <strong>No active conference rooms are available.</strong>
                                    <p>Ask an administrator to activate at least one room so the scheduler and booking form can be used.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                </div>

            </div>
                <div class="booking-modal-overlay" id="bookingModalOverlay" <?= $errorMessage !== '' ? '' : 'hidden'; ?>>
                    <div class="booking-modal" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
                        <div class="booking-modal-header">
                            <span class="panel-header-icon"><i class="far fa-calendar-alt"></i></span>
                            <div>
                                <h2 class="booking-modal-title" id="bookingModalTitle">New Booking</h2>
                                <p class="booking-modal-subtitle">Complete the form below to reserve a conference room.</p>
                            </div>
                            <button type="button" class="booking-modal-close" id="closeBookingModalBtn" aria-label="Close booking form">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="booking-modal-body">
                            <?php if ($errorMessage !== ''): ?>
                                <div class="alert alert-error">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" autocomplete="off">
                                <?= csrf_field(); ?>

                                <div class="booking-grid">
                                    <div class="form-group">
                                        <label for="booker_email">EMAIL<span class="required-asterisk">*</span></label>
                                        <div class="icon-field">
                                            <span class="field-icon"><i class="far fa-envelope"></i></span>
                                            <input
                                                type="email"
                                                id="booker_email"
                                                name="booker_email"
                                                class="form-control"
                                                value="<?= htmlspecialchars((string) $form['booker_email'], ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="Enter email address"
                                                required
                                            >
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="booker_company">COMPANY<span class="required-asterisk">*</span></label>
                                        <div class="icon-field select-wrapper">
                                            <span class="field-icon"><i class="far fa-building"></i></span>
                                            <select id="booker_company" name="booker_company" class="form-control" required>
                                                <option value="" disabled <?= (string) $form['booker_company'] === '' ? 'selected' : ''; ?> hidden>Select company</option>
                                                <?php foreach ($companyOptions as $companyOption): ?>
                                                    <option value="<?= htmlspecialchars($companyOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (string) $form['booker_company'] === (string) $companyOption ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars((string) ($companyLabelMap[$companyOption] ?? $companyOption), ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="booker_department">DEPARTMENT<span id="bookerDepartmentAsterisk" class="required-asterisk">*</span></label>
                                        <div class="icon-field select-wrapper">
                                            <span class="field-icon"><i class="fas fa-users"></i></span>
                                            <select id="booker_department" name="booker_department" class="form-control">
                                                <option value="" selected>Select company first</option>
                                            </select>
                                            <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label for="room_id">CONFERENCE ROOM<span class="required-asterisk">*</span></label>
                                        <div class="icon-field select-wrapper">
                                            <span class="field-icon"><i class="far fa-clipboard"></i></span>
                                            <select id="room_id" name="room_id" class="form-control" required <?= count($rooms) === 0 ? 'disabled' : ''; ?>>
                                                <option value="" disabled <?= (string) $form['room_id'] === '' ? 'selected' : ''; ?> hidden>Select a room</option>
                                                <?php foreach ($rooms as $room): ?>
                                                    <?php $roomId = (int) ($room['id'] ?? 0); ?>
                                                    <option value="<?= $roomId; ?>" data-saturday-enabled="<?= (int) ($room['saturday_enabled'] ?? 0) === 1 ? '1' : '0'; ?>" <?= (string) $roomId === (string) $form['room_id'] ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="booking-grid date-time-row">
                                    <div class="form-group">
                                        <label for="booking_date">BOOKING DATE<span class="required-asterisk">*</span></label>
                                        <div class="icon-field date-field-clickable" id="bookingDateField">
                                            <span class="field-icon"><i class="far fa-calendar"></i></span>
                                            <input
                                                type="date"
                                                id="booking_date"
                                                name="booking_date"
                                                class="form-control"
                                                min="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>"
                                                value="<?= htmlspecialchars((string) $form['booking_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                                required
                                            >
                                        </div>
                                    </div>

                                    <div class="booking-rule-tooltip">
                                        <button type="button" class="booking-rule-trigger" aria-label="View booking schedule rules">
                                            <i class="fas fa-info"></i>
                                        </button>
                                        <div class="booking-rule-popup" role="tooltip">
                                            Monday to Friday are available for active rooms.<br>
                                            Saturday depends on the selected room's availability setting.<br>
                                            Bookings are allowed from 7:00 AM to 6:00 PM.
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>START TIME<span class="required-asterisk">*</span></label>
                                        <div class="time-group">
                                            <div class="select-wrapper">
                                                <select name="start_hour" class="form-control" required>
                                                    <?php foreach ($hourOptions as $hour): ?>
                                                        <option value="<?= $hour; ?>" <?= (string) $hour === (string) $form['start_hour'] ? 'selected' : ''; ?>><?= sprintf('%02d', $hour); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                            </div>
                                            <div class="select-wrapper">
                                                <select name="start_minute" class="form-control" required>
                                                    <?php foreach ($minuteOptions as $minute): ?>
                                                        <option value="<?= $minute; ?>" <?= (string) $minute === (string) $form['start_minute'] ? 'selected' : ''; ?>><?= $minute; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                            </div>
                                            <div class="select-wrapper">
                                                <select name="start_period" class="form-control" required>
                                                    <option value="AM" <?= strtoupper((string) $form['start_period']) === 'AM' ? 'selected' : ''; ?>>AM</option>
                                                    <option value="PM" <?= strtoupper((string) $form['start_period']) === 'PM' ? 'selected' : ''; ?>>PM</option>
                                                </select>
                                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group">
                                        <label>END TIME<span class="required-asterisk">*</span></label>
                                        <div class="time-group">
                                            <div class="select-wrapper">
                                                <select name="end_hour" class="form-control" required>
                                                    <?php foreach ($hourOptions as $hour): ?>
                                                        <option value="<?= $hour; ?>" <?= (string) $hour === (string) $form['end_hour'] ? 'selected' : ''; ?>><?= sprintf('%02d', $hour); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                            </div>
                                            <div class="select-wrapper">
                                                <select name="end_minute" class="form-control" required>
                                                    <?php foreach ($minuteOptions as $minute): ?>
                                                        <option value="<?= $minute; ?>" <?= (string) $minute === (string) $form['end_minute'] ? 'selected' : ''; ?>><?= $minute; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                            </div>
                                            <div class="select-wrapper">
                                                <select name="end_period" class="form-control" required>
                                                    <option value="AM" <?= strtoupper((string) $form['end_period']) === 'AM' ? 'selected' : ''; ?>>AM</option>
                                                    <option value="PM" <?= strtoupper((string) $form['end_period']) === 'PM' ? 'selected' : ''; ?>>PM</option>
                                                </select>
                                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="booking-inline-note" id="bookingSaturdayNote" hidden>
                                    This room is not available for Saturday bookings.
                                </div>

                                <div class="booking-grid single purpose-row">
                                    <div class="form-group">
                                        <label for="purpose">PURPOSE / DESCRIPTION<span class="required-asterisk">*</span></label>
                                        <div class="icon-field purpose-field">
                                            <span class="field-icon textarea"><i class="far fa-edit"></i></span>
                                            <textarea id="purpose" name="purpose" class="form-control" placeholder="What is the meeting for?" required><?= htmlspecialchars((string) $form['purpose'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="button" class="btn-lite" id="clearBookingBtn">
                                        <i class="fas fa-rotate-left"></i>
                                        <span>Clear</span>
                                    </button>
                                    <button type="submit" class="btn-submit" <?= count($rooms) === 0 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Save Booking</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
        </div>
    </div>
    <script>
        (function () {
            const bookingForm = document.querySelector('#bookingModalOverlay form');
            const bookerEmailInput = document.getElementById('booker_email');
            const companySelect = document.getElementById('booker_company');
            const departmentSelect = document.getElementById('booker_department');
            const departmentAsterisk = document.getElementById('bookerDepartmentAsterisk');
            const roomSelect = document.getElementById('room_id');
            const bookingDateField = document.getElementById('bookingDateField');
            const bookingDateInput = document.getElementById('booking_date');
            const purposeInput = document.getElementById('purpose');
            const startHourSelect = document.querySelector('select[name="start_hour"]');
            const startMinuteSelect = document.querySelector('select[name="start_minute"]');
            const startPeriodSelect = document.querySelector('select[name="start_period"]');
            const endHourSelect = document.querySelector('select[name="end_hour"]');
            const endMinuteSelect = document.querySelector('select[name="end_minute"]');
            const endPeriodSelect = document.querySelector('select[name="end_period"]');
            const availabilityRoomFilter = document.getElementById('availabilityRoomFilter');
            const openWeekPickerBtn = document.getElementById('openWeekPickerBtn');
            const weekNavDatePicker = document.getElementById('weekNavDatePicker');
            const bookingModalOverlay = document.getElementById('bookingModalOverlay');
            const openBookingModalBtn = document.getElementById('openBookingModalBtn');
            const closeBookingModalBtn = document.getElementById('closeBookingModalBtn');
            const clearBookingBtn = document.getElementById('clearBookingBtn');
            const bookingSaturdayNote = document.getElementById('bookingSaturdayNote');
            const departmentMap = <?= json_encode($companyDepartmentMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const roomSaturdayMap = <?= json_encode($roomSaturdayMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const selectedDepartment = <?= json_encode((string) $form['booker_department'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const currentWeekOf = <?= json_encode($referenceWeekDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const shouldOpenBookingModal = <?= json_encode($errorMessage !== '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            const params = new URLSearchParams(window.location.search);

            if (!companySelect || !departmentSelect) {
                return;
            }

            function openBookingModal() {
                if (!bookingModalOverlay) {
                    return;
                }
                bookingModalOverlay.hidden = false;
                document.body.classList.add('modal-open');
            }

            function closeBookingModal() {
                if (!bookingModalOverlay) {
                    return;
                }
                bookingModalOverlay.hidden = true;
                document.body.classList.remove('modal-open');
            }

            function resetDepartment(label) {
                departmentSelect.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = label;
                option.selected = true;
                option.disabled = true;
                departmentSelect.appendChild(option);
            }

            function populateDepartmentOptions(companyValue) {
                const departments = Array.isArray(departmentMap[companyValue]) ? departmentMap[companyValue] : [];
                const currentValue = departmentSelect.getAttribute('data-selected') || selectedDepartment || '';

                if (!companyValue) {
                    resetDepartment('Select company first');
                    departmentSelect.disabled = true;
                    departmentSelect.removeAttribute('required');
                    if (departmentAsterisk) departmentAsterisk.style.display = 'none';
                    return;
                }

                if (departments.length === 0) {
                    resetDepartment('No department available');
                    departmentSelect.disabled = true;
                    departmentSelect.removeAttribute('required');
                    if (departmentAsterisk) departmentAsterisk.style.display = 'none';
                    return;
                }

                departmentSelect.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Select department';
                placeholder.hidden = true;
                placeholder.disabled = true;
                placeholder.selected = true;
                departmentSelect.appendChild(placeholder);

                departments.forEach(function (department) {
                    const option = document.createElement('option');
                    option.value = department;
                    option.textContent = department;
                    if (currentValue && currentValue === department) {
                        option.selected = true;
                        placeholder.selected = false;
                    }
                    departmentSelect.appendChild(option);
                });

                departmentSelect.disabled = false;
                departmentSelect.setAttribute('required', 'required');
                if (departmentAsterisk) departmentAsterisk.style.display = 'inline';
            }

            companySelect.addEventListener('change', function () {
                departmentSelect.setAttribute('data-selected', '');
                populateDepartmentOptions(companySelect.value);
            });

            departmentSelect.setAttribute('data-selected', selectedDepartment);
            populateDepartmentOptions(companySelect.value);

            if (openBookingModalBtn) {
                openBookingModalBtn.addEventListener('click', openBookingModal);
            }

            if (closeBookingModalBtn) {
                closeBookingModalBtn.addEventListener('click', closeBookingModal);
            }

            if (bookingModalOverlay) {
                bookingModalOverlay.addEventListener('click', function (event) {
                    if (event.target === bookingModalOverlay) {
                        closeBookingModal();
                    }
                });
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && bookingModalOverlay && !bookingModalOverlay.hidden) {
                    closeBookingModal();
                }
            });

            if (availabilityRoomFilter) {
                availabilityRoomFilter.addEventListener('change', function () {
                    const params = new URLSearchParams(window.location.search);
                    params.set('week_of', currentWeekOf);
                    params.set('room_filter', availabilityRoomFilter.value || '0');
                    window.location.href = 'conference_booking.php?' + params.toString();
                });
            }

            if (openWeekPickerBtn && weekNavDatePicker) {
                openWeekPickerBtn.addEventListener('click', function () {
                    if (typeof weekNavDatePicker.showPicker === 'function') {
                        weekNavDatePicker.showPicker();
                        return;
                    }

                    weekNavDatePicker.focus();
                    weekNavDatePicker.click();
                });

                weekNavDatePicker.addEventListener('change', function () {
                    const selectedDate = String(weekNavDatePicker.value || '').trim();
                    if (!selectedDate) {
                        return;
                    }

                    const nextParams = new URLSearchParams(window.location.search);
                    nextParams.set('week_of', selectedDate);
                    nextParams.set('room_filter', availabilityRoomFilter ? (availabilityRoomFilter.value || '0') : String(params.get('room_filter') || '0'));
                    window.location.href = 'conference_booking.php?' + nextParams.toString();
                });
            }

            function isSaturdayDateValue(dateValue) {
                if (!dateValue) {
                    return false;
                }

                const parsed = new Date(dateValue + 'T00:00:00');
                if (Number.isNaN(parsed.getTime())) {
                    return false;
                }

                return parsed.getDay() === 6;
            }

            function syncSaturdayBookingMessage() {
                if (!roomSelect || !bookingDateInput) {
                    return true;
                }

                const roomId = String(roomSelect.value || '');
                const isSaturday = isSaturdayDateValue(String(bookingDateInput.value || ''));
                const saturdayEnabled = roomId !== '' && String(roomSaturdayMap[roomId] || '0') === '1';
                const message = roomId !== '' && isSaturday && !saturdayEnabled
                    ? 'This room is not available for Saturday bookings.'
                    : '';

                roomSelect.setCustomValidity(message);
                bookingDateInput.setCustomValidity(message);

                if (bookingSaturdayNote) {
                    bookingSaturdayNote.hidden = message === '';
                    bookingSaturdayNote.textContent = message;
                }

                return message === '';
            }

            if (roomSelect) {
                roomSelect.addEventListener('change', syncSaturdayBookingMessage);
            }

            if (bookingDateInput) {
                bookingDateInput.addEventListener('change', syncSaturdayBookingMessage);
                bookingDateInput.addEventListener('input', syncSaturdayBookingMessage);
            }

            if (bookingDateField && bookingDateInput) {
                let bookingDatePickerOpening = false;

                function openBookingDatePicker() {
                    if (bookingDatePickerOpening) {
                        return;
                    }

                    bookingDatePickerOpening = true;
                    bookingDateInput.focus();

                    if (typeof bookingDateInput.showPicker === 'function') {
                        try {
                            bookingDateInput.showPicker();
                        } catch (error) {
                            bookingDateInput.click();
                        }
                    } else {
                        bookingDateInput.click();
                    }

                    window.setTimeout(function () {
                        bookingDatePickerOpening = false;
                    }, 0);
                }

                bookingDateField.addEventListener('mousedown', function (event) {
                    if (event.target !== bookingDateInput) {
                        event.preventDefault();
                    }
                });

                bookingDateField.addEventListener('click', function (event) {
                    if (event.target === bookingDateInput) {
                        return;
                    }

                    openBookingDatePicker();
                });
            }

            if (clearBookingBtn) {
                clearBookingBtn.addEventListener('click', function (event) {
                    event.preventDefault();

                    if (bookingForm) {
                        bookingForm.reset();
                    }

                    if (bookerEmailInput) {
                        bookerEmailInput.value = '';
                    }

                    if (companySelect) {
                        companySelect.value = '';
                    }

                    if (departmentSelect) {
                        departmentSelect.setAttribute('data-selected', '');
                        populateDepartmentOptions('');
                    }

                    if (roomSelect) {
                        roomSelect.value = '';
                    }

                    if (bookingDateInput) {
                        bookingDateInput.value = '';
                    }

                    if (purposeInput) {
                        purposeInput.value = '';
                    }

                    if (startHourSelect) startHourSelect.value = '7';
                    if (startMinuteSelect) startMinuteSelect.value = '00';
                    if (startPeriodSelect) startPeriodSelect.value = 'AM';
                    if (endHourSelect) endHourSelect.value = '6';
                    if (endMinuteSelect) endMinuteSelect.value = '00';
                    if (endPeriodSelect) endPeriodSelect.value = 'PM';

                    openBookingModal();

                    if (bookerEmailInput) {
                        bookerEmailInput.focus();
                    }

                    syncSaturdayBookingMessage();
                });
            }

            if (bookingForm) {
                bookingForm.addEventListener('submit', function (event) {
                    if (!syncSaturdayBookingMessage()) {
                        event.preventDefault();
                        if (roomSelect) {
                            roomSelect.reportValidity();
                        } else if (bookingDateInput) {
                            bookingDateInput.reportValidity();
                        }
                    }
                });
            }

            if (shouldOpenBookingModal) {
                openBookingModal();
            }

            syncSaturdayBookingMessage();
        })();
    </script>
</body>
</html>
