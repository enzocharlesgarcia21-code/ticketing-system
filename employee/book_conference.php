<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/conference_booking.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: employee_login.php?redirect=book_conference.php');
    exit();
}

if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ../admin/conference_bookings.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'employee') {
    header('Location: employee_login.php?redirect=book_conference.php');
    exit();
}

conference_booking_ensure_tables($conn);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');
$successMessage = (string) ($_SESSION['conference_booking_success'] ?? '');
unset($_SESSION['conference_booking_success']);

$form = [
    'room_id' => (string) ($_POST['room_id'] ?? ''),
    'booking_date' => (string) ($_POST['booking_date'] ?? $today),
    'start_hour' => (string) ($_POST['start_hour'] ?? '9'),
    'start_minute' => (string) ($_POST['start_minute'] ?? '00'),
    'start_period' => (string) ($_POST['start_period'] ?? 'AM'),
    'end_hour' => (string) ($_POST['end_hour'] ?? '10'),
    'end_minute' => (string) ($_POST['end_minute'] ?? '00'),
    'end_period' => (string) ($_POST['end_period'] ?? 'AM'),
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
            (string) $form['purpose']
        );

        if (!empty($result['ok'])) {
            $roomName = trim((string) (($result['room']['room_name'] ?? '') !== '' ? $result['room']['room_name'] : 'the selected room'));
            $_SESSION['conference_booking_success'] = 'Your booking for ' . $roomName . ' has been saved successfully.';
            header('Location: book_conference.php');
            exit();
        }

        $errorMessage = trim((string) ($result['error'] ?? 'Unable to save the booking right now.'));
    }
}

$rooms = conference_booking_active_rooms($conn);
$allBookings = conference_booking_recent_visible($conn, 20);
$hourOptions = range(1, 12);
$minuteOptions = [];
for ($minute = 0; $minute <= 55; $minute += 5) {
    $minuteOptions[] = sprintf('%02d', $minute);
}

function conference_booking_status_badge_class(string $status): string
{
    $status = strtolower(trim($status));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Conference | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body.employee-book-conference-page .content-wrapper {
            max-width: 1040px;
            margin: 0 auto;
        }
        body.employee-book-conference-page .alert {
            max-width: 920px;
            margin: 0 auto 22px;
            border-radius: 16px;
            padding: 16px 18px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid transparent;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }
        body.employee-book-conference-page .alert i {
            margin-top: 2px;
        }
        body.employee-book-conference-page .alert-success {
            background: #ecfdf3;
            color: #166534;
            border-color: #bbf7d0;
        }
        body.employee-book-conference-page .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        body.employee-book-conference-page .conference-intro {
            max-width: 920px;
            margin: 0 auto 28px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
        }
        body.employee-book-conference-page .conference-panel {
            background: linear-gradient(180deg, #ffffff 0%, #fbfffd 100%);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 20px;
            padding: 22px 24px;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.06);
        }
        body.employee-book-conference-page .conference-panel h3 {
            margin: 0 0 10px;
            font-size: 18px;
            color: #14532d;
        }
        body.employee-book-conference-page .conference-panel p {
            margin: 0;
            color: #64748b;
            line-height: 1.6;
            font-size: 14px;
        }
        body.employee-book-conference-page .room-list {
            display: grid;
            gap: 12px;
        }
        body.employee-book-conference-page .room-item {
            border: 1px solid rgba(187, 247, 208, 0.95);
            background: #f0fdf4;
            border-radius: 16px;
            padding: 14px 16px;
        }
        body.employee-book-conference-page .room-item-title {
            font-weight: 700;
            color: #14532d;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        body.employee-book-conference-page .room-item-title i {
            color: #1b5e20;
        }
        body.employee-book-conference-page .room-item-desc {
            font-size: 13px;
            color: #5b6b80;
            line-height: 1.55;
        }
        body.employee-book-conference-page .booking-form-card {
            max-width: 920px;
        }
        body.employee-book-conference-page .booking-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }
        body.employee-book-conference-page .time-group {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        body.employee-book-conference-page .booking-hint {
            margin-top: 8px;
            font-size: 12px;
            color: #64748b;
        }
        body.employee-book-conference-page .bookings-card {
            max-width: 920px;
            margin: 28px auto 0;
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 18px;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        body.employee-book-conference-page .bookings-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        body.employee-book-conference-page .bookings-card-header h3 {
            margin: 0;
            font-size: 18px;
            color: #14532d;
        }
        body.employee-book-conference-page .bookings-card-header span {
            font-size: 13px;
            color: #64748b;
        }
        body.employee-book-conference-page .table-responsive {
            margin: 0;
        }
        body.employee-book-conference-page .table-responsive table {
            margin: 0;
        }
        body.employee-book-conference-page .status-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 90px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        body.employee-book-conference-page .status-chip.status-booked {
            background: #dcfce7;
            color: #166534;
        }
        body.employee-book-conference-page .status-chip.status-ok {
            background: #dbeafe;
            color: #1d4ed8;
        }
        body.employee-book-conference-page .status-chip.status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        body.employee-book-conference-page .status-chip.status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
        }
        body.employee-book-conference-page .purpose-cell {
            max-width: 320px;
            color: #475569;
            line-height: 1.55;
        }
        body.employee-book-conference-page .empty-state {
            padding: 44px 20px;
            text-align: center;
            color: #64748b;
        }
        body.employee-book-conference-page .empty-state i {
            font-size: 34px;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        @media (max-width: 860px) {
            body.employee-book-conference-page .conference-intro,
            body.employee-book-conference-page .booking-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 640px) {
            body.employee-book-conference-page .time-group {
                grid-template-columns: 1fr;
            }
            body.employee-book-conference-page .conference-panel,
            body.employee-book-conference-page .bookings-card-header {
                padding: 18px;
            }
            body.employee-book-conference-page .purpose-cell {
                max-width: none;
            }
        }
    </style>
</head>
<body class="employee-book-conference-page">
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">
            <div class="page-header" style="text-align: center; margin-bottom: 32px;">
                <h1 class="page-title">Book Conference</h1>
                <p class="page-subtitle">Reserve an active conference room using 12-hour time selection with an automatic 30-minute cleanup buffer.</p>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <div class="conference-intro">
                <div class="conference-panel">
                    <h3>Booking Rules</h3>
                    <p>Bookings are tied to one room at a time, only active rooms can be reserved, and overlapping schedules are blocked automatically. Every booking also includes a 30-minute buffer after the selected end time before the same room can be booked again.</p>
                </div>
                <div class="conference-panel">
                    <h3>Available Rooms</h3>
                    <div class="room-list">
                        <?php if (count($rooms) > 0): ?>
                            <?php foreach ($rooms as $room): ?>
                                <div class="room-item">
                                    <div class="room-item-title">
                                        <i class="fas fa-door-open"></i>
                                        <span><?= htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="room-item-desc">
                                        <?= htmlspecialchars(trim((string) ($room['description'] ?? 'Ready to accept new bookings.')), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="room-item">
                                <div class="room-item-title">
                                    <i class="fas fa-door-closed"></i>
                                    <span>No active rooms</span>
                                </div>
                                <div class="room-item-desc">There are no active conference rooms available for booking right now.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-card booking-form-card">
                <form method="POST" autocomplete="off">
                    <?= csrf_field(); ?>
                    <h3 class="form-section-title">New Booking</h3>

                    <div class="booking-grid">
                        <div class="form-group">
                            <label for="room_id">Conference Room</label>
                            <div class="select-wrapper">
                                <select id="room_id" name="room_id" class="form-control" required <?= count($rooms) === 0 ? 'disabled' : ''; ?>>
                                    <option value="">Select a room</option>
                                    <?php foreach ($rooms as $room): ?>
                                        <?php $roomId = (int) ($room['id'] ?? 0); ?>
                                        <option value="<?= $roomId; ?>" <?= (string) $roomId === (string) $form['room_id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars((string) ($room['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="select-icon"><i class="fas fa-chevron-down"></i></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="booking_date">Booking Date</label>
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

                    <div class="booking-grid">
                        <div class="form-group">
                            <label>Start Time</label>
                            <div class="time-group">
                                <div class="select-wrapper">
                                    <select name="start_hour" class="form-control" required>
                                        <?php foreach ($hourOptions as $hour): ?>
                                            <option value="<?= $hour; ?>" <?= (string) $hour === (string) $form['start_hour'] ? 'selected' : ''; ?>><?= $hour; ?></option>
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
                            <label>End Time</label>
                            <div class="time-group">
                                <div class="select-wrapper">
                                    <select name="end_hour" class="form-control" required>
                                        <?php foreach ($hourOptions as $hour): ?>
                                            <option value="<?= $hour; ?>" <?= (string) $hour === (string) $form['end_hour'] ? 'selected' : ''; ?>><?= $hour; ?></option>
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
                            <div class="booking-hint">Times are shown in 12-hour format here and saved in the database using proper `TIME` values.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="purpose">Purpose / Description</label>
                        <textarea id="purpose" name="purpose" class="form-control" placeholder="What is the meeting for?" required><?= htmlspecialchars((string) $form['purpose'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit" <?= count($rooms) === 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-calendar-check"></i>
                            <span>Save Booking</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="bookings-card">
                <div class="bookings-card-header">
                    <h3>All Recent Books</h3>
                    <span>Latest conference bookings across all departments</span>
                </div>
                <?php if (count($allBookings) > 0): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <th>Booked By</th>
                                    <th>Department</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allBookings as $booking): ?>
                                    <tr>
                                        <td data-label="Room"><?= htmlspecialchars((string) ($booking['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="Booked By"><?= htmlspecialchars((string) ($booking['booked_by_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="Department"><?= htmlspecialchars((string) ($booking['booked_by_department'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="Date"><?= htmlspecialchars(date('M d, Y', strtotime((string) ($booking['booking_date'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="Time">
                                            <?= htmlspecialchars(conference_booking_format_time_12h((string) ($booking['start_time'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                            -
                                            <?= htmlspecialchars(conference_booking_format_time_12h((string) ($booking['end_time'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-plus"></i>
                        <div>No conference room bookings have been created yet.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>
</body>
</html>
