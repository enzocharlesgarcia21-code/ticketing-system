<?php
require_once __DIR__ . '/notification_service.php';
require_once __DIR__ . '/ticket_assignment.php';

function conference_booking_ensure_tables(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS conference_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_name VARCHAR(150) NOT NULL UNIQUE,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            saturday_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_conference_rooms_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS conference_bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            booker_email VARCHAR(190) NULL,
            booker_company VARCHAR(150) NULL,
            booker_department VARCHAR(190) NULL,
            room_id INT NOT NULL,
            booking_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            purpose TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'Booked',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_conference_booking_room_schedule (room_id, booking_date, start_time, end_time),
            KEY idx_conference_booking_user_date (user_id, booking_date),
            CONSTRAINT fk_conference_booking_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_conference_booking_room FOREIGN KEY (room_id) REFERENCES conference_rooms(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $roomColumns = [
        'room_name' => "VARCHAR(150) NOT NULL",
        'description' => "TEXT NULL",
        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
        'saturday_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
        'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    $existingRoomColumns = [];
    $saturdayColumnAdded = false;
    $roomRes = $conn->query("SHOW COLUMNS FROM conference_rooms");
    if ($roomRes) {
        while ($row = $roomRes->fetch_assoc()) {
            if (isset($row['Field'])) {
                $existingRoomColumns[(string) $row['Field']] = true;
            }
        }
        $roomRes->free();
    }
    foreach ($roomColumns as $column => $ddl) {
        if (!isset($existingRoomColumns[$column])) {
            $conn->query("ALTER TABLE conference_rooms ADD COLUMN $column $ddl");
            if ($column === 'saturday_enabled') {
                $saturdayColumnAdded = true;
            }
        }
    }

    $bookingColumns = [
        'user_id' => "INT NULL",
        'booker_email' => "VARCHAR(190) NULL",
        'booker_company' => "VARCHAR(150) NULL",
        'booker_department' => "VARCHAR(190) NULL",
        'room_id' => "INT NOT NULL",
        'booking_date' => "DATE NOT NULL",
        'start_time' => "TIME NOT NULL",
        'end_time' => "TIME NOT NULL",
        'purpose' => "TEXT NOT NULL",
        'status' => "VARCHAR(50) NOT NULL DEFAULT 'Booked'",
        'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    $existingBookingColumns = [];
    $bookingRes = $conn->query("SHOW COLUMNS FROM conference_bookings");
    if ($bookingRes) {
        while ($row = $bookingRes->fetch_assoc()) {
            if (isset($row['Field'])) {
                $existingBookingColumns[(string) $row['Field']] = true;
            }
        }
        $bookingRes->free();
    }
    foreach ($bookingColumns as $column => $ddl) {
        if (!isset($existingBookingColumns[$column])) {
            $conn->query("ALTER TABLE conference_bookings ADD COLUMN $column $ddl");
        }
    }

    if (isset($existingBookingColumns['user_id'])) {
        $userIdColumn = $conn->query("SHOW COLUMNS FROM conference_bookings LIKE 'user_id'");
        $userIdMeta = $userIdColumn ? $userIdColumn->fetch_assoc() : null;
        if ($userIdColumn instanceof mysqli_result) {
            $userIdColumn->free();
        }
        if ($userIdMeta && strtoupper((string) ($userIdMeta['Null'] ?? '')) !== 'YES') {
            $conn->query("ALTER TABLE conference_bookings MODIFY COLUMN user_id INT NULL");
        }
    }

    if ($saturdayColumnAdded) {
        conference_booking_seed_default_saturday_availability($conn);
    }

    conference_booking_seed_default_rooms($conn);
}

function conference_booking_seed_default_rooms(mysqli $conn): void
{
    conference_booking_migrate_default_room_names($conn);

    $defaults = [
        ['room_name' => 'MPDC', 'description' => 'MPDC conference room for meetings and coordination sessions.', 'saturday_enabled' => 1],
        ['room_name' => 'Caltex', 'description' => 'Caltex conference room for team discussions and scheduled bookings.', 'saturday_enabled' => 0],
    ];

    foreach ($defaults as $room) {
        $roomName = trim((string) ($room['room_name'] ?? ''));
        if ($roomName === '') {
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO conference_rooms (room_name, description, is_active, saturday_enabled)
            SELECT ?, ?, 1, ?
            WHERE NOT EXISTS (
                SELECT 1
                FROM conference_rooms
                WHERE room_name = ?
                LIMIT 1
            )
        ");
        if (!$stmt) {
            continue;
        }

        $description = trim((string) ($room['description'] ?? ''));
        $saturdayEnabled = (int) ($room['saturday_enabled'] ?? 0) === 1 ? 1 : 0;
        $stmt->bind_param("ssis", $roomName, $description, $saturdayEnabled, $roomName);
        $stmt->execute();
        $stmt->close();
    }
}

function conference_booking_seed_default_saturday_availability(mysqli $conn): void
{
    $defaults = [
        'MPDC' => 1,
        'Caltex' => 0,
    ];

    $stmt = $conn->prepare("
        UPDATE conference_rooms
        SET saturday_enabled = ?
        WHERE room_name = ?
    ");
    if (!$stmt) {
        return;
    }

    foreach ($defaults as $roomName => $saturdayEnabled) {
        $enabled = (int) $saturdayEnabled === 1 ? 1 : 0;
        $name = (string) $roomName;
        $stmt->bind_param("is", $enabled, $name);
        $stmt->execute();
    }

    $stmt->close();
}

function conference_booking_migrate_default_room_names(mysqli $conn): void
{
    $legacyMap = [
        'Conference Room A' => [
            'room_name' => 'MPDC',
            'description' => 'MPDC conference room for meetings and coordination sessions.',
        ],
        'Conference Room B' => [
            'room_name' => 'Caltex',
            'description' => 'Caltex conference room for team discussions and scheduled bookings.',
        ],
    ];

    foreach ($legacyMap as $oldName => $replacement) {
        $newName = trim((string) ($replacement['room_name'] ?? ''));
        $newDescription = trim((string) ($replacement['description'] ?? ''));
        if ($newName === '') {
            continue;
        }

        $oldStmt = $conn->prepare("
            SELECT id
            FROM conference_rooms
            WHERE room_name = ?
            LIMIT 1
        ");
        if (!$oldStmt) {
            continue;
        }
        $oldStmt->bind_param("s", $oldName);
        $oldStmt->execute();
        $oldRes = $oldStmt->get_result();
        $oldRow = $oldRes ? $oldRes->fetch_assoc() : null;
        $oldStmt->close();

        if (!$oldRow || (int) ($oldRow['id'] ?? 0) <= 0) {
            continue;
        }

        $newStmt = $conn->prepare("
            SELECT id
            FROM conference_rooms
            WHERE room_name = ?
            LIMIT 1
        ");
        if (!$newStmt) {
            continue;
        }
        $newStmt->bind_param("s", $newName);
        $newStmt->execute();
        $newRes = $newStmt->get_result();
        $newRow = $newRes ? $newRes->fetch_assoc() : null;
        $newStmt->close();

        if ($newRow && (int) ($newRow['id'] ?? 0) > 0) {
            continue;
        }

        $roomId = (int) ($oldRow['id'] ?? 0);
        $updateStmt = $conn->prepare("
            UPDATE conference_rooms
            SET room_name = ?, description = ?
            WHERE id = ?
            LIMIT 1
        ");
        if (!$updateStmt) {
            continue;
        }
        $updateStmt->bind_param("ssi", $newName, $newDescription, $roomId);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

function conference_booking_active_rooms(mysqli $conn): array
{
    conference_booking_ensure_tables($conn);

    $rooms = [];
    $res = $conn->query("
        SELECT id, room_name, description, is_active, saturday_enabled, created_at
        FROM conference_rooms
        WHERE is_active = 1
        ORDER BY room_name ASC, id ASC
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $rooms[] = $row;
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return $rooms;
}

function conference_booking_company_department_map(): array
{
    return [
        '@leads-farmex.com' => [],
        '@farmasee.ph' => [],
        '@gpsci.net' => [],
        '@leadsanimalhealth.com' => [],
        '@leadsagri.com' => ticket_lapc_departments(),
        '@leads-eh.com' => [],
        '@leadsav.com' => [],
        '@leadstech-corp.com' => [],
        '@lingapleads.org' => [],
        '@malvedaholdings.com' => [],
        '@malvedaproperties.com' => [],
        '@primestocks.ph' => [],
    ];
}

function conference_booking_company_options(): array
{
    return array_keys(conference_booking_company_department_map());
}

function conference_booking_company_label_map(): array
{
    return [
        '@leads-farmex.com' => 'FARMEX (@leads-farmex.com)',
        '@farmasee.ph' => 'FARMASEE (@farmasee.ph)',
        '@gpsci.net' => 'GPSCI (@gpsci.net)',
        '@leadsanimalhealth.com' => 'LAH (@leadsanimalhealth.com)',
        '@leadsagri.com' => 'LAPC (@leadsagri.com)',
        '@leads-eh.com' => 'LEH (@leads-eh.com)',
        '@leadsav.com' => 'LAV (@leadsav.com)',
        '@leadstech-corp.com' => 'LTC (@leadstech-corp.com)',
        '@lingapleads.org' => 'LINGAP (@lingapleads.org)',
        '@malvedaholdings.com' => 'MHC (@malvedaholdings.com)',
        '@malvedaproperties.com' => 'MPDC (@malvedaproperties.com)',
        '@primestocks.ph' => 'PCC (@primestocks.ph)',
    ];
}

function conference_booking_company_short_label(string $company): string
{
    $company = trim($company);
    if ($company === '') {
        return '';
    }

    $labelMap = conference_booking_company_label_map();
    $label = trim((string) ($labelMap[$company] ?? $company));
    if ($label === '') {
        return '';
    }

    if (preg_match('/^([^(]+)\s*\(/', $label, $matches)) {
        return trim((string) ($matches[1] ?? ''));
    }

    return $label;
}

function conference_booking_find_room(mysqli $conn, int $roomId): ?array
{
    conference_booking_ensure_tables($conn);
    if ($roomId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, room_name, description, is_active, saturday_enabled
        FROM conference_rooms
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $res = $stmt->get_result();
    $room = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $room ?: null;
}

function conference_room_all(mysqli $conn): array
{
    conference_booking_ensure_tables($conn);

    $rooms = [];
    $res = $conn->query("
        SELECT id, room_name, description, is_active, saturday_enabled, created_at
        FROM conference_rooms
        ORDER BY is_active DESC, room_name ASC, id ASC
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $rooms[] = $row;
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return $rooms;
}

function conference_room_normalize_name(string $roomName): string
{
    $roomName = trim(preg_replace('/\s+/', ' ', $roomName));
    return $roomName;
}

function conference_room_name_exists(mysqli $conn, string $roomName, int $excludeRoomId = 0): bool
{
    conference_booking_ensure_tables($conn);

    $roomName = conference_room_normalize_name($roomName);
    if ($roomName === '') {
        return false;
    }

    $sql = "
        SELECT id
        FROM conference_rooms
        WHERE UPPER(TRIM(room_name)) = UPPER(TRIM(?))
    ";
    $types = "s";
    $params = [$roomName];

    if ($excludeRoomId > 0) {
        $sql .= " AND id <> ? ";
        $types .= "i";
        $params[] = $excludeRoomId;
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $bind = [];
    $bind[] = $types;
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();

    return (bool) $exists;
}

function insertRoom(mysqli $conn, string $name, string $description, int $status, int $saturdayEnabled = 0): array
{
    conference_booking_ensure_tables($conn);

    $name = conference_room_normalize_name($name);
    $description = trim($description);
    $status = $status === 1 ? 1 : 0;
    $saturdayEnabled = $saturdayEnabled === 1 ? 1 : 0;

    if ($name === '') {
        return ['ok' => false, 'error' => 'Room name is required.'];
    }
    if (conference_room_name_exists($conn, $name)) {
        return ['ok' => false, 'error' => 'A conference room with that name already exists.'];
    }

    $stmt = $conn->prepare("
        INSERT INTO conference_rooms (room_name, description, is_active, saturday_enabled)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to add the room right now.'];
    }

    $stmt->bind_param("ssii", $name, $description, $status, $saturdayEnabled);
    $ok = $stmt->execute();
    $roomId = $ok ? (int) $stmt->insert_id : 0;
    $errno = (int) $stmt->errno;
    $stmt->close();

    if (!$ok || $roomId <= 0) {
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'A conference room with that name already exists.'];
        }
        return ['ok' => false, 'error' => 'Unable to add the room right now.'];
    }

    return ['ok' => true, 'room_id' => $roomId];
}

function updateRoom(mysqli $conn, int $id, string $name, string $description, int $status, ?int $saturdayEnabled = null): array
{
    conference_booking_ensure_tables($conn);

    $id = (int) $id;
    $name = conference_room_normalize_name($name);
    $description = trim($description);
    $status = $status === 1 ? 1 : 0;

    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid conference room selected.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'Room name is required.'];
    }
    $room = conference_booking_find_room($conn, $id);
    if (!$room) {
        return ['ok' => false, 'error' => 'Conference room not found.'];
    }
    if (conference_room_name_exists($conn, $name, $id)) {
        return ['ok' => false, 'error' => 'A conference room with that name already exists.'];
    }

    if ($saturdayEnabled === null) {
        $saturdayEnabled = (int) ($room['saturday_enabled'] ?? 0) === 1 ? 1 : 0;
    } else {
        $saturdayEnabled = $saturdayEnabled === 1 ? 1 : 0;
    }

    $stmt = $conn->prepare("
        UPDATE conference_rooms
        SET room_name = ?, description = ?, is_active = ?, saturday_enabled = ?
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to update the room right now.'];
    }

    $stmt->bind_param("ssiii", $name, $description, $status, $saturdayEnabled, $id);
    $ok = $stmt->execute();
    $errno = (int) $stmt->errno;
    $stmt->close();

    if (!$ok) {
        if ($errno === 1062) {
            return ['ok' => false, 'error' => 'A conference room with that name already exists.'];
        }
        return ['ok' => false, 'error' => 'Unable to update the room right now.'];
    }

    return ['ok' => true, 'room_id' => $id];
}

function deleteRoom(mysqli $conn, int $id): array
{
    conference_booking_ensure_tables($conn);

    $id = (int) $id;
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'Invalid conference room selected.'];
    }

    $room = conference_booking_find_room($conn, $id);
    if (!$room) {
        return ['ok' => false, 'error' => 'Conference room not found.'];
    }

    $stmt = $conn->prepare("
        DELETE FROM conference_rooms
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to delete the room right now.'];
    }

    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $errno = (int) $stmt->errno;
    $affected = (int) $stmt->affected_rows;
    $stmt->close();

    if (!$ok || $affected <= 0) {
        if ($errno === 1451) {
            return ['ok' => false, 'error' => 'This room cannot be deleted because it is already used in conference bookings.'];
        }
        return ['ok' => false, 'error' => 'Unable to delete the room right now.'];
    }

    return [
        'ok' => true,
        'room_id' => $id,
        'room_name' => trim((string) ($room['room_name'] ?? 'the selected room')),
    ];
}

function conference_booking_parse_time_parts($hourRaw, $minuteRaw, $periodRaw): ?string
{
    $hour = (int) $hourRaw;
    $minute = (int) $minuteRaw;
    $period = strtoupper(trim((string) $periodRaw));

    if ($hour < 1 || $hour > 12) {
        return null;
    }
    if ($minute < 0 || $minute > 59) {
        return null;
    }
    if ($period !== 'AM' && $period !== 'PM') {
        return null;
    }

    if ($period === 'AM') {
        $hour24 = ($hour === 12) ? 0 : $hour;
    } else {
        $hour24 = ($hour === 12) ? 12 : ($hour + 12);
    }

    return sprintf('%02d:%02d:00', $hour24, $minute);
}

function conference_booking_format_time_12h(string $timeValue): string
{
    $timeValue = trim($timeValue);
    if ($timeValue === '') {
        return '-';
    }

    $timestamp = strtotime($timeValue);
    if ($timestamp === false) {
        return $timeValue;
    }

    return date('g:i A', $timestamp);
}

function conference_booking_buffer_interval(): string
{
    return '00:30:00';
}

function conference_booking_is_weekend(string $bookingDate): bool
{
    $dayOfWeek = conference_booking_day_of_week($bookingDate);
    return $dayOfWeek >= 6;
}

function conference_booking_day_of_week(string $bookingDate): int
{
    $bookingDate = trim($bookingDate);
    if ($bookingDate === '') {
        return 0;
    }

    $timestamp = strtotime($bookingDate);
    if ($timestamp === false) {
        return 0;
    }

    return (int) date('N', $timestamp);
}

function conference_booking_is_saturday(string $bookingDate): bool
{
    return conference_booking_day_of_week($bookingDate) === 6;
}

function conference_booking_is_sunday(string $bookingDate): bool
{
    return conference_booking_day_of_week($bookingDate) === 7;
}

function conference_booking_room_supports_booking_date(?array $room, string $bookingDate): bool
{
    $dayOfWeek = conference_booking_day_of_week($bookingDate);
    if ($dayOfWeek === 0) {
        return false;
    }
    if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
        return true;
    }
    if ($dayOfWeek === 6) {
        return $room !== null && (int) ($room['saturday_enabled'] ?? 0) === 1;
    }

    return false;
}

function conference_booking_is_within_allowed_hours(string $startTime, string $endTime): bool
{
    $startTime = trim($startTime);
    $endTime = trim($endTime);
    if ($startTime === '' || $endTime === '') {
        return false;
    }

    return strcmp($startTime, '07:00:00') >= 0 && strcmp($endTime, '18:00:00') <= 0;
}

function conference_booking_schedule_validation_error(string $bookingDate, string $startTime, string $endTime, ?array $room = null): string
{
    if (conference_booking_is_sunday($bookingDate)) {
        return 'Sunday booking is not available. Please choose Monday to Saturday only.';
    }

    if (conference_booking_is_saturday($bookingDate) && !conference_booking_room_supports_booking_date($room, $bookingDate)) {
        return 'This room is not available for Saturday bookings.';
    }

    if (!conference_booking_is_within_allowed_hours($startTime, $endTime)) {
        return 'This time is not available. Conference room bookings are only allowed from 7:00 AM to 6:00 PM.';
    }

    return '';
}

function conference_booking_blocking_statuses(): array
{
    return ['Booked', 'Pending', 'Approved', 'Confirmed'];
}

function conference_booking_blocking_status_sql(): string
{
    $parts = [];
    foreach (conference_booking_blocking_statuses() as $status) {
        $parts[] = "'" . strtoupper(trim($status)) . "'";
    }
    return implode(', ', $parts);
}

function conference_booking_find_conflict(
    mysqli $conn,
    int $roomId,
    string $bookingDate,
    string $startTime,
    string $endTime,
    int $excludeBookingId = 0,
    bool $forUpdate = false
): ?array {
    conference_booking_ensure_tables($conn);

    if ($roomId <= 0 || $bookingDate === '' || $startTime === '' || $endTime === '') {
        return null;
    }

    $requestedStart = strtotime($bookingDate . ' ' . $startTime);
    $requestedEnd = strtotime($bookingDate . ' ' . $endTime);
    if ($requestedStart === false || $requestedEnd === false) {
        return null;
    }

    $sql = "
        SELECT
            b.id,
            b.user_id,
            b.room_id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            r.room_name,
            u.name AS booked_by_name
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        LEFT JOIN users u ON u.id = b.user_id
        WHERE b.room_id = ?
          AND b.booking_date = ?
          AND UPPER(TRIM(COALESCE(b.status, ''))) <> 'CANCELLED'
    ";

    $types = "is";
    $params = [$roomId, $bookingDate];

    if ($excludeBookingId > 0) {
        $sql .= " AND b.id <> ? ";
        $types .= "i";
        $params[] = $excludeBookingId;
    }

    $sql .= " ORDER BY b.start_time ASC, b.id ASC";
    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $bind = [];
    $bind[] = $types;
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $bufferSeconds = 30 * 60;
    $conflict = null;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existingStart = trim((string) ($row['start_time'] ?? ''));
            $existingEnd = trim((string) ($row['end_time'] ?? ''));
            if ($existingStart === '' || $existingEnd === '') {
                continue;
            }

            $existingStartTs = strtotime($bookingDate . ' ' . $existingStart);
            $existingEndTs = strtotime($bookingDate . ' ' . $existingEnd);
            if ($existingStartTs === false || $existingEndTs === false) {
                continue;
            }

            $existingBufferedEndTs = $existingEndTs + $bufferSeconds;
            if ($requestedStart < $existingBufferedEndTs && $requestedEnd > $existingStartTs) {
                $conflict = $row;
                break;
            }
        }
    }
    $stmt->close();

    return $conflict ?: null;
}

function conference_booking_add_minutes_to_time(string $timeValue, int $minutes): ?string
{
    $timeValue = trim($timeValue);
    if ($timeValue === '') {
        return null;
    }

    $timestamp = strtotime('1970-01-01 ' . $timeValue);
    if ($timestamp === false) {
        return null;
    }

    return date('H:i:s', $timestamp + ($minutes * 60));
}

function conference_booking_conflict_message(array $conflict, string $requestedStartTime = '', string $requestedEndTime = ''): string
{
    $existingStart = trim((string) ($conflict['start_time'] ?? ''));
    $existingEnd = trim((string) ($conflict['end_time'] ?? ''));
    $existingStartLabel = conference_booking_format_time_12h($existingStart);
    $existingEndLabel = conference_booking_format_time_12h($existingEnd);
    $nextAvailableTime = conference_booking_add_minutes_to_time($existingEnd, 30);
    $nextAvailableLabel = $nextAvailableTime !== null ? conference_booking_format_time_12h($nextAvailableTime) : '';

    $isBufferOnlyConflict =
        $requestedStartTime !== '' &&
        $existingEnd !== '' &&
        strcmp($requestedStartTime, $existingEnd) >= 0 &&
        $nextAvailableTime !== null &&
        strcmp($requestedStartTime, $nextAvailableTime) < 0;

    if ($isBufferOnlyConflict && $nextAvailableLabel !== '') {
        return 'This room is not available yet. A 30-minute cleaning buffer is required after the previous booking. Please choose ' . $nextAvailableLabel . ' or later.';
    }

    if ($existingStart !== '' && $existingEnd !== '') {
        return 'This time is already not available. The room is already booked from ' . $existingStartLabel . ' to ' . $existingEndLabel . '.';
    }

    return 'This time is already not available for the selected room.';
}

function conference_booking_find_by_id(mysqli $conn, int $bookingId, bool $forUpdate = false): ?array
{
    conference_booking_ensure_tables($conn);
    if ($bookingId <= 0) {
        return null;
    }

    $sql = "
        SELECT
            b.id,
            b.user_id,
            b.room_id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            b.created_at,
            b.updated_at,
            r.room_name,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(b.booker_email), ''), 'Public Booking') AS booked_by_name,
            COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(b.booker_email), ''), '') AS booked_by_email,
            COALESCE(NULLIF(TRIM(u.company), ''), NULLIF(TRIM(b.booker_company), ''), '') AS booked_by_company,
            CASE
                WHEN b.user_id IS NULL THEN NULLIF(TRIM(b.booker_department), '')
                ELSE COALESCE(NULLIF(TRIM(u.department), ''), NULLIF(TRIM(b.booker_department), ''), 'Unassigned')
            END AS booked_by_department
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        LEFT JOIN users u ON u.id = b.user_id
        WHERE b.id = ?
        LIMIT 1
    ";
    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function conference_booking_employee_link(): string
{
    return notif_base_url() . '/ticketing/conference_booking.php?view=my-bookings';
}

function conference_booking_delete_notification_message(array $booking): string
{
    $roomName = trim((string) ($booking['room_name'] ?? 'the selected conference room'));
    $dateValue = trim((string) ($booking['booking_date'] ?? ''));
    $dateLabel = $dateValue !== '' ? date('M d, Y', strtotime($dateValue)) : 'the selected date';
    $startLabel = conference_booking_format_time_12h((string) ($booking['start_time'] ?? ''));
    $endLabel = conference_booking_format_time_12h((string) ($booking['end_time'] ?? ''));

    return 'Your conference booking for ' . $roomName . ' on ' . $dateLabel . ' from ' . $startLabel . ' to ' . $endLabel . ' was deleted by an administrator.';
}

function conference_booking_admin_notification_message(
    array $room,
    string $bookingDate,
    string $startTime,
    string $endTime,
    string $purpose,
    string $bookerName,
    string $bookerCompany = '',
    string $bookerDepartment = ''
): string
{
    $roomName = trim((string) ($room['room_name'] ?? 'the selected conference room'));
    $dateLabel = $bookingDate !== '' ? date('M d, Y', strtotime($bookingDate)) : 'the selected date';
    $startLabel = conference_booking_format_time_12h($startTime);
    $endLabel = conference_booking_format_time_12h($endTime);
    $bookerName = trim($bookerName) !== '' ? trim($bookerName) : 'A user';
    $bookerCompany = trim(notif_replace_company_domains($bookerCompany));
    $bookerDepartment = trim($bookerDepartment);
    $purpose = trim($purpose);

    $requesterParts = array_values(array_filter([$bookerCompany, $bookerDepartment], static function ($value) {
        return trim((string) $value) !== '';
    }));
    $requesterLabel = $requesterParts ? ' (' . implode(' | ', $requesterParts) . ')' : '';
    $purposeLabel = $purpose !== '' ? ' Purpose: ' . $purpose . '.' : '';

    return $bookerName . ' booked ' . $roomName . ' for ' . $dateLabel . ' from ' . $startLabel . ' to ' . $endLabel . $requesterLabel . '.' . $purposeLabel;
}

function conference_booking_insert_user_notification(mysqli $conn, int $userId, string $message, string $title): bool
{
    $userId = (int) $userId;
    if ($userId <= 0 || trim($message) === '') {
        return false;
    }

    notif_ensure_action_type_column($conn);
    notif_ensure_title_column($conn);

    $ticketId = 0;
    $type = 'conference_booking_deleted';
    $actionType = 'update';
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, ticket_id, title, message, type, action_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iissss", $userId, $ticketId, $title, $message, $type, $actionType);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function conference_booking_send_delete_email(array $booking): bool
{
    $email = trim((string) ($booking['booked_by_email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $title = 'Conference Booking Deleted';
    $bookedBy = trim((string) ($booking['booked_by_name'] ?? 'Employee'));
    $roomName = trim((string) ($booking['room_name'] ?? 'Conference Room'));
    $dateValue = trim((string) ($booking['booking_date'] ?? ''));
    $dateLabel = $dateValue !== '' ? date('M d, Y', strtotime($dateValue)) : 'the selected date';
    $startLabel = conference_booking_format_time_12h((string) ($booking['start_time'] ?? ''));
    $endLabel = conference_booking_format_time_12h((string) ($booking['end_time'] ?? ''));
    $purpose = trim((string) ($booking['purpose'] ?? ''));

    $lines = [
        'Hello ' . $bookedBy . ',',
        'An administrator removed your conference room booking.',
        'Room: ' . $roomName,
        'Date: ' . $dateLabel,
        'Time: ' . $startLabel . ' to ' . $endLabel,
    ];
    if ($purpose !== '') {
        $lines[] = 'Purpose: ' . $purpose;
    }
    $lines[] = 'Please create a new booking if you still need the room.';

    $mail = notif_email_simple($title, $lines, 'View My Bookings', conference_booking_employee_link());
    return notif_email_send([$email], $title, (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''));
}

function conference_booking_send_create_email(array $booking): bool
{
    $email = trim((string) ($booking['booked_by_email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $title = 'Conference Booking Confirmed';
    $bookedBy = trim((string) ($booking['booked_by_name'] ?? 'Employee'));
    $roomName = trim((string) ($booking['room_name'] ?? 'Conference Room'));
    $dateValue = trim((string) ($booking['booking_date'] ?? ''));
    $dateLabel = $dateValue !== '' ? date('M d, Y', strtotime($dateValue)) : 'the selected date';
    $startLabel = conference_booking_format_time_12h((string) ($booking['start_time'] ?? ''));
    $endLabel = conference_booking_format_time_12h((string) ($booking['end_time'] ?? ''));
    $purpose = trim((string) ($booking['purpose'] ?? ''));

    $lines = [
        'Hello ' . $bookedBy . ',',
        'Your conference booking has been created successfully.',
        'Room: ' . $roomName,
        'Date: ' . $dateLabel,
        'Time: ' . $startLabel . ' to ' . $endLabel,
    ];
    if ($purpose !== '') {
        $lines[] = 'Purpose: ' . $purpose;
    }
    $lines[] = 'You can view your bookings anytime from the conference booking page.';

    $mail = notif_email_simple($title, $lines, 'View My Bookings', conference_booking_employee_link());
    return notif_email_send([$email], $title, (string) ($mail['html'] ?? ''), (string) ($mail['text'] ?? ''));
}

function conference_booking_delete(mysqli $conn, int $bookingId, int $deletedByUserId = 0): array
{
    conference_booking_ensure_tables($conn);

    if ($bookingId <= 0) {
        return ['ok' => false, 'error' => 'Invalid conference booking selected.'];
    }

    try {
        $conn->begin_transaction();

        $booking = conference_booking_find_by_id($conn, $bookingId, true);
        if (!$booking) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Conference booking not found.'];
        }

        $stmt = $conn->prepare("DELETE FROM conference_bookings WHERE id = ? LIMIT 1");
        if (!$stmt) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to delete the booking right now.'];
        }

        $stmt->bind_param("i", $bookingId);
        $ok = $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        if (!$ok || $affected <= 0) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to delete the booking right now.'];
        }

        $title = 'Conference Booking Deleted';
        $message = conference_booking_delete_notification_message($booking);
        conference_booking_insert_user_notification($conn, (int) ($booking['user_id'] ?? 0), $message, $title);

        $conn->commit();

        $emailed = conference_booking_send_delete_email($booking);

        return [
            'ok' => true,
            'booking' => $booking,
            'emailed' => $emailed,
            'deleted_by_user_id' => $deletedByUserId,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to delete the booking right now.'];
    }
}

function conference_booking_update_admin(
    mysqli $conn,
    int $bookingId,
    int $roomId,
    string $bookingDate,
    string $startTime,
    string $endTime,
    string $purpose
): array {
    conference_booking_ensure_tables($conn);

    $purpose = trim($purpose);
    if ($bookingId <= 0) {
        return ['ok' => false, 'error' => 'Invalid conference booking selected.'];
    }
    if ($roomId <= 0) {
        return ['ok' => false, 'error' => 'Please choose a conference room.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
        return ['ok' => false, 'error' => 'Please select a valid booking date.'];
    }
    if ($startTime === '' || $endTime === '') {
        return ['ok' => false, 'error' => 'Please select both the start and end time.'];
    }
    if ($purpose === '') {
        return ['ok' => false, 'error' => 'Please enter the purpose of the meeting.'];
    }
    if (strcmp($endTime, $startTime) <= 0) {
        return ['ok' => false, 'error' => 'End time must be later than start time.'];
    }
    $room = conference_booking_find_room($conn, $roomId);
    if (!$room || (int) ($room['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'The selected room is not available for booking.'];
    }

    $scheduleError = conference_booking_schedule_validation_error($bookingDate, $startTime, $endTime, $room);
    if ($scheduleError !== '') {
        return ['ok' => false, 'error' => $scheduleError];
    }

    try {
        $conn->begin_transaction();

        $booking = conference_booking_find_by_id($conn, $bookingId, true);
        if (!$booking) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Conference booking not found.'];
        }

        if (strcasecmp((string) ($booking['status'] ?? ''), 'cancelled') === 0) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Cancelled bookings can no longer be edited.'];
        }

        $conflict = conference_booking_find_conflict($conn, $roomId, $bookingDate, $startTime, $endTime, $bookingId, true);
        if ($conflict) {
            $conn->rollback();
            return [
                'ok' => false,
                'error' => conference_booking_conflict_message($conflict, $startTime, $endTime),
                'conflict' => $conflict,
            ];
        }

        $stmt = $conn->prepare("
            UPDATE conference_bookings
            SET room_id = ?, booking_date = ?, start_time = ?, end_time = ?, purpose = ?
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to update the booking right now.'];
        }

        $stmt->bind_param("issssi", $roomId, $bookingDate, $startTime, $endTime, $purpose, $bookingId);
        $ok = $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        if (!$ok) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to update the booking right now.'];
        }

        $updatedBooking = conference_booking_find_by_id($conn, $bookingId, true);
        $conn->commit();

        return [
            'ok' => true,
            'booking' => $updatedBooking ?: $booking,
            'changed' => $affected > 0,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to update the booking right now.'];
    }
}

function conference_booking_cancel(mysqli $conn, int $bookingId): array
{
    conference_booking_ensure_tables($conn);

    if ($bookingId <= 0) {
        return ['ok' => false, 'error' => 'Invalid conference booking selected.'];
    }

    try {
        $conn->begin_transaction();

        $booking = conference_booking_find_by_id($conn, $bookingId, true);
        if (!$booking) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Conference booking not found.'];
        }

        if (strcasecmp((string) ($booking['status'] ?? ''), 'cancelled') === 0) {
            $conn->commit();
            return ['ok' => true, 'booking' => $booking, 'changed' => false];
        }

        $stmt = $conn->prepare("
            UPDATE conference_bookings
            SET status = 'Cancelled'
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to cancel the booking right now.'];
        }

        $stmt->bind_param("i", $bookingId);
        $ok = $stmt->execute();
        $affected = (int) $stmt->affected_rows;
        $stmt->close();

        if (!$ok) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to cancel the booking right now.'];
        }

        $booking['status'] = 'Cancelled';
        $conn->commit();

        return [
            'ok' => true,
            'booking' => $booking,
            'changed' => $affected > 0,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to cancel the booking right now.'];
    }
}

function conference_booking_create(
    mysqli $conn,
    int $userId,
    int $roomId,
    string $bookingDate,
    string $startTime,
    string $endTime,
    string $purpose,
    string $bookerEmail = '',
    string $bookerCompany = '',
    string $bookerDepartment = ''
): array {
    conference_booking_ensure_tables($conn);

    $purpose = trim($purpose);
    $bookerEmail = trim($bookerEmail);
    $bookerCompany = ticket_normalize_company(trim($bookerCompany));
    $bookerDepartment = trim($bookerDepartment);
    if ($roomId <= 0) {
        return ['ok' => false, 'error' => 'Please choose a conference room.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
        return ['ok' => false, 'error' => 'Please select a valid booking date.'];
    }
    if ($startTime === '' || $endTime === '') {
        return ['ok' => false, 'error' => 'Please select both the start and end time.'];
    }
    if ($purpose === '') {
        return ['ok' => false, 'error' => 'Please enter the purpose of the meeting.'];
    }
    if (strcmp($endTime, $startTime) <= 0) {
        return ['ok' => false, 'error' => 'End time must be later than start time.'];
    }
    $companyDepartmentMap = conference_booking_company_department_map();
    $allowedCompanies = array_keys($companyDepartmentMap);
    if ($userId <= 0) {
        if ($bookerEmail === '' || !filter_var($bookerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email for the booker.'];
        }
        if ($bookerCompany === '' || !in_array($bookerCompany, $allowedCompanies, true)) {
            return ['ok' => false, 'error' => 'Please choose a valid company.'];
        }
        $allowedDepartments = $companyDepartmentMap[$bookerCompany] ?? [];
        if (count($allowedDepartments) > 0) {
            if ($bookerDepartment === '') {
                return ['ok' => false, 'error' => 'Please choose a department for the selected company.'];
            }
            if (!in_array($bookerDepartment, $allowedDepartments, true)) {
                return ['ok' => false, 'error' => 'Please choose a valid department for the selected company.'];
            }
        } else {
            $bookerDepartment = '';
        }
    } elseif ($bookerCompany !== '') {
        if (!in_array($bookerCompany, $allowedCompanies, true)) {
            return ['ok' => false, 'error' => 'Please choose a valid company.'];
        }
        $allowedDepartments = $companyDepartmentMap[$bookerCompany] ?? [];
        if ($bookerDepartment !== '' && count($allowedDepartments) > 0 && !in_array($bookerDepartment, $allowedDepartments, true)) {
            return ['ok' => false, 'error' => 'Please choose a valid department for the selected company.'];
        }
        if (count($allowedDepartments) === 0) {
            $bookerDepartment = '';
        }
    }

    $room = conference_booking_find_room($conn, $roomId);
    if (!$room || (int) ($room['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'The selected room is not available for booking.'];
    }

    $scheduleError = conference_booking_schedule_validation_error($bookingDate, $startTime, $endTime, $room);
    if ($scheduleError !== '') {
        return ['ok' => false, 'error' => $scheduleError];
    }

    try {
        $conn->begin_transaction();
        $bookingUserId = $userId > 0 ? $userId : null;

        $conflict = conference_booking_find_conflict($conn, $roomId, $bookingDate, $startTime, $endTime, 0, true);
        if ($conflict) {
            $conn->rollback();
            return [
                'ok' => false,
                'error' => conference_booking_conflict_message($conflict, $startTime, $endTime),
                'conflict' => $conflict,
            ];
        }

        $stmt = $conn->prepare("
            INSERT INTO conference_bookings (user_id, booker_email, booker_company, booker_department, room_id, booking_date, start_time, end_time, purpose, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Booked')
        ");
        if (!$stmt) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to save the booking right now.'];
        }

        $stmt->bind_param("isssissss", $bookingUserId, $bookerEmail, $bookerCompany, $bookerDepartment, $roomId, $bookingDate, $startTime, $endTime, $purpose);
        $ok = $stmt->execute();
        $bookingId = $ok ? (int) $stmt->insert_id : 0;
        $stmt->close();

        if (!$ok || $bookingId <= 0) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Unable to save the booking right now.'];
        }

        $bookerName = '';
        if ($bookingUserId > 0) {
            $booker = notif_user_contact($conn, $bookingUserId);
            $bookerName = trim((string) ($booker['name'] ?? ''));
            if ($bookerDepartment === '' && trim((string) ($booker['department'] ?? '')) !== '') {
                $bookerDepartment = trim((string) ($booker['department'] ?? ''));
            }
            if ($bookerCompany === '' && trim((string) ($booker['company'] ?? '')) !== '') {
                $bookerCompany = trim((string) ($booker['company'] ?? ''));
            }
        }
        if ($bookerName === '' && $bookerEmail !== '') {
            $bookerName = $bookerEmail;
        }

        $adminMessage = conference_booking_admin_notification_message(
            $room,
            $bookingDate,
            $startTime,
            $endTime,
            $purpose,
            $bookerName,
            $bookerCompany,
            $bookerDepartment
        );
        notif_insert_admins($conn, $bookingId, $adminMessage, 'conference_booking', 'update', 'Conference Booking');

        $conn->commit();

        $createdBooking = conference_booking_find_by_id($conn, $bookingId);
        $emailed = conference_booking_send_create_email($createdBooking ?: [
            'booked_by_email' => $bookerEmail,
            'booked_by_name' => $bookerName !== '' ? $bookerName : ($bookerEmail !== '' ? $bookerEmail : 'Employee'),
            'room_name' => (string) ($room['room_name'] ?? 'Conference Room'),
            'booking_date' => $bookingDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'purpose' => $purpose,
        ]);

        return [
            'ok' => true,
            'booking_id' => $bookingId,
            'room' => $room,
            'emailed' => $emailed,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to save the booking right now.'];
    }
}

function conference_booking_user_bookings(mysqli $conn, int $userId, int $limit = 8): array
{
    conference_booking_ensure_tables($conn);
    if ($userId <= 0) {
        return [];
    }

    $limit = max(1, $limit);
    $stmt = $conn->prepare("
        SELECT
            b.id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            b.created_at,
            r.room_name
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        WHERE b.user_id = ?
        ORDER BY
            CASE WHEN b.booking_date >= CURDATE() THEN 0 ELSE 1 END ASC,
            CASE WHEN b.booking_date >= CURDATE() THEN b.booking_date END ASC,
            CASE WHEN b.booking_date >= CURDATE() THEN b.start_time END ASC,
            CASE WHEN b.booking_date < CURDATE() THEN b.booking_date END DESC,
            CASE WHEN b.booking_date < CURDATE() THEN b.start_time END DESC,
            b.id DESC
        LIMIT ?
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function conference_booking_schedule_for_date(mysqli $conn, string $bookingDate, int $limit = 20): array
{
    conference_booking_ensure_tables($conn);

    $bookingDate = trim($bookingDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate)) {
        return [];
    }

    $limit = max(1, $limit);
    $stmt = $conn->prepare("
        SELECT
            b.id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            b.created_at,
            r.room_name,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(b.booker_email), ''), 'Public Booking') AS booked_by_name,
            COALESCE(NULLIF(TRIM(u.company), ''), NULLIF(TRIM(b.booker_company), ''), '') AS booked_by_company,
            CASE
                WHEN b.user_id IS NULL THEN NULLIF(TRIM(b.booker_department), '')
                ELSE COALESCE(NULLIF(TRIM(u.department), ''), NULLIF(TRIM(b.booker_department), ''), 'Unassigned')
            END AS booked_by_department
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        LEFT JOIN users u ON u.id = b.user_id
        WHERE b.booking_date = ?
          AND UPPER(TRIM(COALESCE(b.status, ''))) <> 'CANCELLED'
        ORDER BY r.room_name ASC, b.start_time ASC, b.id ASC
        LIMIT ?
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("si", $bookingDate, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function conference_booking_schedule_between(mysqli $conn, string $startDate, string $endDate, int $roomId = 0): array
{
    conference_booking_ensure_tables($conn);

    $startDate = trim($startDate);
    $endDate = trim($endDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return [];
    }

    $sql = "
        SELECT
            b.id,
            b.room_id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            b.created_at,
            r.room_name,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(b.booker_email), ''), 'Public Booking') AS booked_by_name,
            COALESCE(NULLIF(TRIM(u.company), ''), NULLIF(TRIM(b.booker_company), ''), '') AS booked_by_company,
            CASE
                WHEN b.user_id IS NULL THEN NULLIF(TRIM(b.booker_department), '')
                ELSE COALESCE(NULLIF(TRIM(u.department), ''), NULLIF(TRIM(b.booker_department), ''), 'Unassigned')
            END AS booked_by_department
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        LEFT JOIN users u ON u.id = b.user_id
        WHERE b.booking_date BETWEEN ? AND ?
          AND UPPER(TRIM(COALESCE(b.status, ''))) <> 'CANCELLED'
          AND (
                b.user_id IS NOT NULL
                OR NULLIF(TRIM(COALESCE(b.booker_email, '')), '') IS NOT NULL
          )
    ";
    $types = "ss";
    $params = [$startDate, $endDate];

    if ($roomId > 0) {
        $sql .= " AND b.room_id = ? ";
        $types .= "i";
        $params[] = $roomId;
    }

    $sql .= " ORDER BY b.booking_date ASC, b.start_time ASC, b.id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $bind = [];
    $bind[] = $types;
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function conference_booking_recent_visible(mysqli $conn, int $limit = 20): array
{
    conference_booking_ensure_tables($conn);

    $limit = max(1, $limit);
    $stmt = $conn->prepare("
        SELECT
            b.id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            b.created_at,
            r.room_name,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(b.booker_email), ''), 'Public Booking') AS booked_by_name,
            COALESCE(NULLIF(TRIM(u.company), ''), NULLIF(TRIM(b.booker_company), ''), '') AS booked_by_company,
            CASE
                WHEN b.user_id IS NULL THEN NULLIF(TRIM(b.booker_department), '')
                ELSE COALESCE(NULLIF(TRIM(u.department), ''), NULLIF(TRIM(b.booker_department), ''), 'Unassigned')
            END AS booked_by_department
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        LEFT JOIN users u ON u.id = b.user_id
        WHERE UPPER(TRIM(COALESCE(b.status, ''))) <> 'CANCELLED'
        ORDER BY
            CASE WHEN b.booking_date >= CURDATE() THEN 0 ELSE 1 END ASC,
            CASE WHEN b.booking_date >= CURDATE() THEN b.booking_date END ASC,
            CASE WHEN b.booking_date >= CURDATE() THEN b.start_time END ASC,
            CASE WHEN b.booking_date < CURDATE() THEN b.booking_date END DESC,
            CASE WHEN b.booking_date < CURDATE() THEN b.start_time END DESC,
            b.id DESC
        LIMIT ?
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function conference_booking_admin_bookings(mysqli $conn): array
{
    conference_booking_ensure_tables($conn);

    $rows = [];
    $res = $conn->query("
        SELECT
            b.id,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.purpose,
            b.status,
            b.created_at,
            b.updated_at,
            r.room_name,
            COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(b.booker_email), ''), 'Public Booking') AS booked_by_name,
            COALESCE(NULLIF(TRIM(u.email), ''), NULLIF(TRIM(b.booker_email), ''), '') AS booked_by_email,
            COALESCE(NULLIF(TRIM(u.company), ''), NULLIF(TRIM(b.booker_company), ''), '') AS booked_by_company,
            CASE
                WHEN b.user_id IS NULL THEN NULLIF(TRIM(b.booker_department), '')
                ELSE COALESCE(NULLIF(TRIM(u.department), ''), NULLIF(TRIM(b.booker_department), ''), 'Unassigned')
            END AS booked_by_department
        FROM conference_bookings b
        INNER JOIN conference_rooms r ON r.id = b.room_id
        LEFT JOIN users u ON u.id = b.user_id
        ORDER BY
            CASE WHEN b.booking_date >= CURDATE() THEN 0 ELSE 1 END ASC,
            CASE WHEN b.booking_date >= CURDATE() THEN b.booking_date END ASC,
            CASE WHEN b.booking_date >= CURDATE() THEN b.start_time END ASC,
            CASE WHEN b.booking_date < CURDATE() THEN b.booking_date END DESC,
            CASE WHEN b.booking_date < CURDATE() THEN b.start_time END DESC,
            b.id DESC
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return $rows;
}
