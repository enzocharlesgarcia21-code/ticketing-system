<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/conference_booking.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

conference_booking_ensure_tables($conn);

$rooms = conference_room_all($conn);
$totalRooms = count($rooms);
$activeRooms = 0;
foreach ($rooms as $roomRow) {
    if ((int) ($roomRow['is_active'] ?? 0) === 1) {
        $activeRooms++;
    }
}
$inactiveRooms = max(0, $totalRooms - $activeRooms);

$successMessage = (string) ($_SESSION['conference_room_flash_success'] ?? '');
$errorMessage = (string) ($_SESSION['conference_room_flash_error'] ?? '');
$addOld = is_array($_SESSION['conference_room_add_old'] ?? null) ? $_SESSION['conference_room_add_old'] : [];
$editOld = is_array($_SESSION['conference_room_edit_old'] ?? null) ? $_SESSION['conference_room_edit_old'] : [];
unset(
    $_SESSION['conference_room_flash_success'],
    $_SESSION['conference_room_flash_error'],
    $_SESSION['conference_room_add_old'],
    $_SESSION['conference_room_edit_old']
);

$panel = trim((string) ($_GET['panel'] ?? ''));
$editRoomId = (int) ($_GET['edit'] ?? 0);
$editRoom = $editRoomId > 0 ? conference_booking_find_room($conn, $editRoomId) : null;
if ($editRoomId > 0 && !$editRoom && $errorMessage === '') {
    $errorMessage = 'Conference room not found.';
}

$addForm = [
    'room_name' => trim((string) ($addOld['room_name'] ?? '')),
    'description' => trim((string) ($addOld['description'] ?? '')),
    'is_active' => isset($addOld['is_active']) ? (int) $addOld['is_active'] : 1,
];

$editForm = null;
if ($editRoom) {
    $editForm = [
        'id' => $editRoomId,
        'room_name' => trim((string) ($editRoom['room_name'] ?? '')),
        'description' => trim((string) ($editRoom['description'] ?? '')),
        'is_active' => (int) ($editRoom['is_active'] ?? 0),
    ];
    if ((int) ($editOld['id'] ?? 0) === $editRoomId) {
        $editForm['room_name'] = trim((string) ($editOld['room_name'] ?? $editForm['room_name']));
        $editForm['description'] = trim((string) ($editOld['description'] ?? $editForm['description']));
        $editForm['is_active'] = isset($editOld['is_active']) ? (int) $editOld['is_active'] : $editForm['is_active'];
    }
}

$showAddForm = ($panel === 'add');
$showEditForm = ($editForm !== null);

function conference_room_status_badge_class(int $isActive): string
{
    return $isActive === 1 ? 'room-status-active' : 'room-status-inactive';
}

function conference_room_status_text(int $isActive): string
{
    return $isActive === 1 ? 'Active' : 'Inactive';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Conference Rooms | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/admin.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .manage-rooms-page {
            max-width: 1380px;
            margin: 0 auto;
            padding: 34px 20px 42px;
        }
        .manage-rooms-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 26px;
        }
        .manage-rooms-title h1 {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 32px;
            font-weight: 800;
        }
        .manage-rooms-title p {
            margin: 0;
            max-width: 780px;
            color: #64748b;
            font-size: 15px;
            line-height: 1.6;
        }
        .manage-rooms-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
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
        }
        .room-page-btn-secondary:hover {
            color: #166534;
            border-color: #bbf7d0;
            background: #f8fff9;
        }
        .manage-rooms-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .room-stat {
            background: #ffffff;
            border-radius: 18px;
            padding: 20px 22px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
        }
        .room-stat-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }
        .room-stat-value {
            color: #0f172a;
            font-size: 30px;
            font-weight: 800;
        }
        .room-alert {
            margin-bottom: 20px;
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
        .room-form-card,
        .room-table-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .room-form-card {
            margin-bottom: 24px;
        }
        .room-card-head {
            padding: 20px 22px;
            border-bottom: 1px solid #e5e7eb;
        }
        .room-card-head h2 {
            margin: 0 0 6px;
            font-size: 18px;
            color: #0f172a;
        }
        .room-card-head p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
            line-height: 1.55;
        }
        .room-form {
            padding: 22px;
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
            resize: vertical;
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
            gap: 12px;
            flex-wrap: wrap;
        }
        .room-submit-btn {
            border: none;
            cursor: pointer;
        }
        .room-table-wrap {
            overflow-x: auto;
        }
        .room-table {
            width: 100%;
            border-collapse: collapse;
        }
        .room-table th,
        .room-table td {
            padding: 16px 18px;
            text-align: left;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }
        .room-table th {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
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
            margin-bottom: 4px;
        }
        .room-secondary {
            color: #64748b;
            font-size: 13px;
            line-height: 1.55;
        }
        .room-description-copy {
            max-width: 420px;
            color: #475569;
            line-height: 1.6;
        }
        .room-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 96px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .room-status-badge.room-status-active {
            background: #dcfce7;
            color: #166534;
        }
        .room-status-badge.room-status-inactive {
            background: #e2e8f0;
            color: #475569;
        }
        .room-edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 9px 14px;
            border-radius: 12px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }
        .room-edit-btn:hover {
            background: #dcfce7;
            border-color: #86efac;
            color: #14532d;
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
        @media (max-width: 980px) {
            .manage-rooms-header {
                flex-direction: column;
            }
            .manage-rooms-actions {
                width: 100%;
                justify-content: flex-start;
            }
            .manage-rooms-stats {
                grid-template-columns: 1fr;
            }
            .room-form-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 760px) {
            .room-form,
            .room-card-head {
                padding: 18px;
            }
            .room-form-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .room-page-btn,
            .room-submit-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/admin_navbar.php'; ?>

    <main class="manage-rooms-page">
        <div class="manage-rooms-header">
            <div class="manage-rooms-title">
                <h1>Manage Conference Rooms</h1>
                <p>Add new conference rooms, update room details, and control whether a room stays available for future bookings. Rooms marked inactive will remain in booking history but will no longer appear in the employee booking form.</p>
            </div>
            <div class="manage-rooms-actions">
                <a href="conference_bookings.php" class="room-page-btn room-page-btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Bookings</span>
                </a>
                <a href="manage_rooms.php?panel=add#roomFormPanel" class="room-page-btn room-page-btn-primary">
                    <i class="fas fa-plus"></i>
                    <span>Add Room</span>
                </a>
            </div>
        </div>

        <div class="manage-rooms-stats">
            <div class="room-stat">
                <div class="room-stat-label">Total Rooms</div>
                <div class="room-stat-value"><?php echo $totalRooms; ?></div>
            </div>
            <div class="room-stat">
                <div class="room-stat-label">Active Rooms</div>
                <div class="room-stat-value"><?php echo $activeRooms; ?></div>
            </div>
            <div class="room-stat">
                <div class="room-stat-label">Inactive Rooms</div>
                <div class="room-stat-value"><?php echo $inactiveRooms; ?></div>
            </div>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="room-alert room-alert-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="room-alert room-alert-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($showAddForm || $showEditForm): ?>
            <section id="roomFormPanel" class="room-form-card" aria-labelledby="roomFormTitle">
                <div class="room-card-head">
                    <h2 id="roomFormTitle"><?php echo $showEditForm ? 'Edit Room' : 'Add New Room'; ?></h2>
                    <p><?php echo $showEditForm ? 'Update the room name, details, and active status for this conference room.' : 'Create a new conference room that employees can use in the booking portal.'; ?></p>
                </div>
                <?php if ($showEditForm && $editForm !== null): ?>
                    <form method="POST" action="update_room.php" class="room-form" autocomplete="off">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="room_id" value="<?php echo (int) ($editForm['id'] ?? 0); ?>">
                        <div class="room-form-grid">
                            <div class="room-form-group">
                                <label for="edit_room_name">Room Name</label>
                                <input type="text" id="edit_room_name" name="room_name" class="room-input" value="<?php echo htmlspecialchars((string) ($editForm['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="room-form-group">
                                <label for="edit_is_active">Status</label>
                                <div class="room-toggle-wrap">
                                    <label class="room-toggle" for="edit_is_active">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" id="edit_is_active" name="is_active" value="1" <?php echo ((int) ($editForm['is_active'] ?? 0) === 1) ? 'checked' : ''; ?>>
                                        <span class="room-toggle-slider"></span>
                                        <span class="room-toggle-text">Available for booking</span>
                                    </label>
                                </div>
                            </div>
                            <div class="room-form-group room-form-group-description">
                                <label for="edit_description">Description</label>
                                <textarea id="edit_description" name="description" class="room-textarea" placeholder="Optional details about the room"><?php echo htmlspecialchars((string) ($editForm['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>
                        <div class="room-form-actions">
                            <button type="submit" class="room-page-btn room-page-btn-primary room-submit-btn">
                                <i class="fas fa-floppy-disk"></i>
                                <span>Save Changes</span>
                            </button>
                            <a href="manage_rooms.php" class="room-page-btn room-page-btn-secondary">
                                <i class="fas fa-xmark"></i>
                                <span>Cancel</span>
                            </a>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="POST" action="add_room.php" class="room-form" autocomplete="off">
                        <?= csrf_field(); ?>
                        <div class="room-form-grid">
                            <div class="room-form-group">
                                <label for="room_name">Room Name</label>
                                <input type="text" id="room_name" name="room_name" class="room-input" value="<?php echo htmlspecialchars((string) ($addForm['room_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            <div class="room-form-group">
                                <label for="add_is_active">Status</label>
                                <div class="room-toggle-wrap">
                                    <label class="room-toggle" for="add_is_active">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" id="add_is_active" name="is_active" value="1" <?php echo ((int) ($addForm['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                        <span class="room-toggle-slider"></span>
                                        <span class="room-toggle-text">Available for booking</span>
                                    </label>
                                </div>
                            </div>
                            <div class="room-form-group room-form-group-description">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" class="room-textarea" placeholder="Optional details about the room"><?php echo htmlspecialchars((string) ($addForm['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>
                        <div class="room-form-actions">
                            <button type="submit" class="room-page-btn room-page-btn-primary room-submit-btn">
                                <i class="fas fa-plus"></i>
                                <span>Save Room</span>
                            </button>
                            <a href="manage_rooms.php" class="room-page-btn room-page-btn-secondary">
                                <i class="fas fa-xmark"></i>
                                <span>Cancel</span>
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="room-table-card" aria-labelledby="roomTableTitle">
            <div class="room-card-head">
                <h2 id="roomTableTitle">All Conference Rooms</h2>
                <p>View each room in the `conference_rooms` table, including whether it is currently active for new bookings.</p>
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rooms as $room): ?>
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
                                        <span class="room-status-badge <?php echo conference_room_status_badge_class((int) ($room['is_active'] ?? 0)); ?>">
                                            <?php echo htmlspecialchars(conference_room_status_text((int) ($room['is_active'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="manage_rooms.php?edit=<?php echo $roomId; ?>#roomFormPanel" class="room-edit-btn">
                                            <i class="fas fa-pen-to-square"></i>
                                            <span>Edit</span>
                                        </a>
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
    </main>
</body>
</html>
