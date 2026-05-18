<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$feedbackRows = [];

$feedbackStmt = $conn->prepare("
    SELECT
        tf.ticket_id,
        et.subject,
        et.category,
        et.assigned_department,
        et.assigned_group,
        COALESCE(
            NULLIF(TRIM(ticket_creator.full_name), ''),
            NULLIF(TRIM(ticket_creator.name), ''),
            NULLIF(TRIM(feedback_requestor.full_name), ''),
            NULLIF(TRIM(feedback_requestor.name), ''),
            NULLIF(TRIM(et.requester_name), '')
        ) AS requester_name,
        COALESCE(
            NULLIF(TRIM(ticket_creator.department), ''),
            NULLIF(TRIM(feedback_requestor.department), ''),
            NULLIF(TRIM(et.department), '')
        ) AS creator_department,
        tf.rating,
        tf.comment,
        tf.created_at
    FROM ticket_feedback tf
    INNER JOIN employee_tickets et ON et.id = tf.ticket_id
    LEFT JOIN users ticket_creator ON ticket_creator.id = et.user_id
    LEFT JOIN users feedback_requestor ON feedback_requestor.id = tf.requestor_id
    WHERE tf.assignee_id = ?
       OR (
            COALESCE(tf.assignee_id, 0) = 0
            AND (et.assigned_to = ? OR et.assigned_user_id = ?)
       )
    ORDER BY tf.created_at DESC, tf.id DESC
");

if ($feedbackStmt) {
    $feedbackStmt->bind_param("iii", $userId, $userId, $userId);
    $feedbackStmt->execute();
    $feedbackRes = $feedbackStmt->get_result();
    while ($feedbackRes && ($row = $feedbackRes->fetch_assoc())) {
        $feedbackRows[] = $row;
    }
    $feedbackStmt->close();
}

function feedback_requester_name(array $row): string
{
    $name = trim((string) ($row['requester_name'] ?? ''));
    return $name !== '' ? $name : 'Requestor';
}

function feedback_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'RQ';
    $parts = preg_split('/\s+/', $name);
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials !== '' ? $initials : 'RQ';
}

$feedbackTotal = count($feedbackRows);
$ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$ratingSum = 0;
foreach ($feedbackRows as $feedbackRow) {
    $rating = max(1, min(5, (int) ($feedbackRow['rating'] ?? 0)));
    $ratingCounts[$rating]++;
    $ratingSum += $rating;
}
$averageRating = $feedbackTotal > 0 ? round($ratingSum / $feedbackTotal, 1) : 0;
$donutSegments = [];
$donutColors = [5 => '#1B5E20', 4 => '#43A047', 3 => '#7CB342', 2 => '#f59e0b', 1 => '#ef4444'];
$donutStart = 0;
foreach ([5, 4, 3, 2, 1] as $rating) {
    $percent = $feedbackTotal > 0 ? ($ratingCounts[$rating] / $feedbackTotal) * 100 : 0;
    if ($percent <= 0) continue;
    $donutEnd = $donutStart + $percent;
    $donutSegments[] = $donutColors[$rating] . ' ' . $donutStart . '% ' . $donutEnd . '%';
    $donutStart = $donutEnd;
}
$donutGradient = count($donutSegments) > 0 ? implode(', ', $donutSegments) : '#e5e7eb 0% 100%';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="../css/view-tickets.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body.employee-feedback-page {
            background: #f8fafc;
        }

        body.employee-feedback-page .dashboard-container {
            background: #f8fafc;
        }

        body.employee-feedback-page .content-wrapper {
            max-width: 1480px;
            padding-top: 6px;
        }

        body.employee-feedback-page .feedback-page-shell {
            display: grid;
            gap: 18px;
        }

        body.employee-feedback-page .feedback-hero {
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-hero-icon {
            display: none;
        }

        body.employee-feedback-page .feedback-hero h1 {
            margin: 0 0 8px;
            font-family: 'Segoe UI', sans-serif;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0;
            color: var(--primary-green);
        }

        body.employee-feedback-page .feedback-hero p {
            margin: 5px 0 0;
            max-width: 760px;
            font-size: 16px;
            line-height: 1.5;
            color: #475569;
        }

        body.employee-feedback-page .feedback-summary-grid {
            display: grid;
            grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
            gap: 24px;
        }

        body.employee-feedback-page .feedback-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
            padding: 22px 26px;
        }

        body.employee-feedback-page .feedback-average-card {
            display: grid;
            grid-template-columns: 1fr;
            align-items: center;
            align-content: center;
            justify-items: center;
            text-align: center;
            gap: 0;
            padding: 22px 24px;
        }

        body.employee-feedback-page .feedback-average-icon {
            width: 88px;
            height: 88px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            border: 1px solid #8cc3a8;
            color: #1B5E20;
            font-size: 38px;
        }

        body.employee-feedback-page .feedback-card-title {
            margin: 0 0 14px;
            font-size: 18px;
            font-weight: 500;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-score-line {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 10px;
            color: #1B5E20;
        }

        body.employee-feedback-page .feedback-score-line strong {
            font-size: 52px;
            line-height: 1;
            letter-spacing: 0;
        }

        body.employee-feedback-page .feedback-score-line span {
            font-size: 24px;
            color: #64748b;
            font-weight: 400;
        }

        body.employee-feedback-page .feedback-score-note {
            margin: 9px 0 0;
            color: #64748b;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-breakdown-card {
            display: grid;
            grid-template-columns: minmax(360px, 1fr) 170px;
            align-items: center;
            gap: 24px;
        }

        body.employee-feedback-page .feedback-breakdown-list {
            display: grid;
            gap: 10px;
        }

        body.employee-feedback-page .feedback-breakdown-row {
            display: grid;
            grid-template-columns: 42px 1fr 48px;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 400;
            color: #475569;
        }

        body.employee-feedback-page .feedback-breakdown-label i {
            color: #334155;
            margin-left: 4px;
            font-size: 12px;
        }

        body.employee-feedback-page .feedback-breakdown-track {
            height: 12px;
            overflow: hidden;
            border-radius: 999px;
            background: #eef2f7;
        }

        body.employee-feedback-page .feedback-breakdown-fill {
            display: block;
            width: var(--rating-width);
            height: 100%;
            border-radius: inherit;
            background: var(--rating-color);
        }

        body.employee-feedback-page .feedback-breakdown-percent {
            color: var(--rating-color);
            text-align: right;
        }

        body.employee-feedback-page .feedback-donut {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: conic-gradient(<?= htmlspecialchars($donutGradient, ENT_QUOTES, 'UTF-8'); ?>);
            position: relative;
        }

        body.employee-feedback-page .feedback-donut::before {
            content: "";
            position: absolute;
            inset: 18px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: inset 0 0 0 1px #e5e7eb;
        }

        body.employee-feedback-page .feedback-donut-center {
            position: relative;
            display: grid;
            place-items: center;
            text-align: center;
            color: #0f172a;
            font-size: 13px;
            line-height: 1.25;
        }

        body.employee-feedback-page .feedback-donut-center strong {
            color: #1B5E20;
            font-size: 28px;
            line-height: 1;
        }

        body.employee-feedback-page .feedback-section {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
            padding: 24px 26px 18px;
        }

        body.employee-feedback-page .feedback-section-header {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }

        body.employee-feedback-page .feedback-section-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: #1B5E20;
            font-size: 20px;
        }

        body.employee-feedback-page .feedback-section-title {
            margin: 0 0 4px;
            font-size: 20px;
            font-weight: 500;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-section-subtitle {
            margin: 0;
            color: #64748b;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-table-wrap {
            overflow-x: auto;
        }

        body.employee-feedback-page .feedback-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        body.employee-feedback-page .feedback-table th {
            background: #f8fbf9;
            color: #1B5E20;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        body.employee-feedback-page .feedback-table th:first-child {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }

        body.employee-feedback-page .feedback-table th:last-child {
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        body.employee-feedback-page .feedback-table td {
            padding: 15px 16px;
            border-bottom: 1px solid #eef2f7;
            color: #334155;
            font-size: 14px;
            vertical-align: middle;
        }

        body.employee-feedback-page .feedback-table tr:last-child td {
            border-bottom: none;
        }

        body.employee-feedback-page .feedback-ticket-row {
            cursor: pointer;
        }

        body.employee-feedback-page .feedback-ticket-row:hover td,
        body.employee-feedback-page .feedback-ticket-row:focus-within td {
            background: #f8fbf9;
        }

        body.employee-feedback-page .feedback-ticket-id {
            font-weight: 500;
            color: #1B5E20;
            white-space: nowrap;
            font-size: 16px;
        }

        body.employee-feedback-page .feedback-ticket-link {
            color: inherit;
            text-decoration: none;
        }

        body.employee-feedback-page .feedback-ticket-link:hover,
        body.employee-feedback-page .feedback-ticket-link:focus {
            text-decoration: underline;
        }

        body.employee-feedback-page .feedback-category-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            max-width: 240px;
            min-height: 34px;
            padding: 6px 12px 6px 8px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            font-weight: 400;
            color: #475569;
            white-space: nowrap;
        }

        body.employee-feedback-page .feedback-category-pill i {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: #1B5E20;
            font-size: 12px;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-person {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 170px;
            font-weight: 400;
            color: #334155;
        }

        body.employee-feedback-page .feedback-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e0f2fe;
            color: #0284c7;
            font-size: 12px;
            font-weight: 400;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-department {
            min-width: 150px;
            color: #475569;
            font-weight: 400;
        }

        body.employee-feedback-page .feedback-rating {
            min-width: 150px;
        }

        body.employee-feedback-page .feedback-stars {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #f59e0b;
            letter-spacing: 0.02em;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-stars .is-muted {
            color: #cbd5e1;
        }

        body.employee-feedback-page .feedback-rating-value {
            margin-left: 8px;
            color: #475569;
            font-weight: 400;
        }

        body.employee-feedback-page .feedback-comment {
            min-width: 260px;
            line-height: 1.6;
            color: #475569;
            white-space: pre-wrap;
        }

        body.employee-feedback-page .feedback-comment.is-empty {
            color: #94a3b8;
            font-style: italic;
        }

        body.employee-feedback-page .feedback-date {
            white-space: nowrap;
            color: #64748b;
            font-weight: 400;
        }

        body.employee-feedback-page .feedback-empty {
            padding: 42px 22px;
            border-radius: 12px;
            border: 1px dashed #cbd5e1;
            background: linear-gradient(180deg, #fcfdfd 0%, #f8fafc 100%);
            text-align: center;
        }

        body.employee-feedback-page .feedback-empty i {
            font-size: 42px;
            color: #94a3b8;
            margin-bottom: 14px;
        }

        body.employee-feedback-page .feedback-empty h2 {
            margin: 0 0 8px;
            font-size: 24px;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-empty p {
            margin: 0;
            font-size: 15px;
            color: #64748b;
        }

        body.employee-feedback-page .feedback-table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 6px 2px;
            color: #64748b;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-pagination {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        body.employee-feedback-page .feedback-page-button {
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #64748b;
        }

        body.employee-feedback-page .feedback-page-current {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1B5E20;
            color: #ffffff;
            font-weight: 400;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .content-wrapper {
                padding-top: 6px;
            }

            body.employee-feedback-page .feedback-hero {
                align-items: flex-start;
                flex-direction: column;
                gap: 16px;
                padding: 24px 20px;
                border-radius: 12px;
            }

            body.employee-feedback-page .feedback-hero h1 {
                font-size: 28px;
            }

            body.employee-feedback-page .feedback-summary-grid,
            body.employee-feedback-page .feedback-breakdown-card,
            body.employee-feedback-page .feedback-average-card {
                grid-template-columns: 1fr;
            }

            body.employee-feedback-page .feedback-breakdown-card {
                justify-items: stretch;
            }

            body.employee-feedback-page .feedback-donut {
                justify-self: center;
            }

            body.employee-feedback-page .feedback-section {
                padding: 18px;
                border-radius: 12px;
            }

            body.employee-feedback-page .feedback-section-header {
                align-items: flex-start;
            }

            body.employee-feedback-page .feedback-table-footer {
                align-items: flex-start;
                flex-direction: column;
            }

        }
    </style>
</head>
<body class="employee-feedback-page">
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">
            <div class="feedback-page-shell">
                <section class="feedback-hero">
                    <div class="feedback-hero-icon" aria-hidden="true">
                        <i class="fas fa-comment-dots"></i>
                    </div>
                    <div>
                        <h1>My Support Feedback</h1>
                        <p>This feedback is only for your own ticket-handling performance as an individual support assignee.</p>
                        <p>Review how requestors rated the tickets you handled, with newest responses shown first.</p>
                    </div>
                </section>

                <section class="feedback-summary-grid" aria-label="Feedback summary">
                    <div class="feedback-card feedback-average-card">
                        <div>
                            <h2 class="feedback-card-title">Average Rating</h2>
                            <div class="feedback-score-line">
                                <strong><?= number_format((float) $averageRating, 1); ?></strong>
                                <span>/ 5</span>
                            </div>
                            <div class="feedback-stars" aria-label="<?= number_format((float) $averageRating, 1); ?> average rating">
                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <i class="fas fa-star <?= $star <= (int) round($averageRating) ? '' : 'is-muted'; ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="feedback-score-note">Based on <?= $feedbackTotal; ?> response<?= $feedbackTotal === 1 ? '' : 's'; ?></p>
                        </div>
                    </div>

                    <div class="feedback-card feedback-breakdown-card">
                        <div>
                            <h2 class="feedback-card-title">Rating Breakdown</h2>
                            <div class="feedback-breakdown-list">
                                <?php foreach ([5, 4, 3, 2, 1] as $rating): ?>
                                    <?php
                                        $percent = $feedbackTotal > 0 ? (int) round(($ratingCounts[$rating] / $feedbackTotal) * 100) : 0;
                                        $color = $donutColors[$rating];
                                    ?>
                                    <div class="feedback-breakdown-row" style="--rating-width: <?= $percent; ?>%; --rating-color: <?= $color; ?>;">
                                        <span class="feedback-breakdown-label"><?= $rating; ?> <i class="fas fa-star"></i></span>
                                        <span class="feedback-breakdown-track"><span class="feedback-breakdown-fill"></span></span>
                                        <span class="feedback-breakdown-percent"><?= $percent; ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="feedback-donut" aria-label="<?= $feedbackTotal; ?> total feedback responses">
                            <div class="feedback-donut-center">
                                <strong><?= $feedbackTotal; ?></strong>
                                <span>Total<br>Responses</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="feedback-section">
                    <?php if (count($feedbackRows) > 0): ?>
                        <div class="feedback-table-wrap">
                            <table class="feedback-table">
                                <thead>
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Category</th>
                                        <th>Creator</th>
                                        <th>Department</th>
                                        <th>Rating</th>
                                        <th>Comment</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feedbackRows as $row): ?>
                                        <?php
                                            $ticketId = (int) ($row['ticket_id'] ?? 0);
                                            $ratingValue = max(1, min(5, (int) ($row['rating'] ?? 0)));
                                            $requesterName = feedback_requester_name($row);
                                            $category = trim((string) ($row['category'] ?? ''));
                                            if ($category === '') $category = trim((string) ($row['subject'] ?? 'General Concern'));
                                            $department = trim((string) ($row['creator_department'] ?? ''));
                                            if ($department === '') $department = 'Department';
                                            $comment = trim((string) ($row['comment'] ?? ''));
                                        ?>
                                        <tr class="feedback-ticket-row" data-ticket-id="<?= $ticketId; ?>" tabindex="0" role="link" aria-label="Open ticket #<?= $ticketId; ?>">
                                            <td class="feedback-ticket-id">
                                                <a class="feedback-ticket-link" href="#ticket-<?= $ticketId; ?>" data-feedback-ticket-link data-ticket-id="<?= $ticketId; ?>">
                                                    #<?= $ticketId; ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="feedback-category-pill">
                                                    <i class="fas fa-tag" aria-hidden="true"></i>
                                                    <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="feedback-person">
                                                    <span class="feedback-avatar"><?= htmlspecialchars(feedback_initials($requesterName), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="feedback-department"><?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="feedback-rating">
                                                <span class="feedback-stars" aria-label="<?= $ratingValue; ?> out of 5 stars">
                                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                                        <i class="fas fa-star <?= $star <= $ratingValue ? '' : 'is-muted'; ?>"></i>
                                                    <?php endfor; ?>
                                                </span>
                                                <span class="feedback-rating-value"><?= $ratingValue; ?>/5</span>
                                            </td>
                                            <td class="feedback-comment <?= $comment === '' ? 'is-empty' : ''; ?>"><?= htmlspecialchars($comment !== '' ? $comment : 'No comment provided.', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="feedback-date"><?= htmlspecialchars(date('M d, Y g:i A', strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="feedback-table-footer">
                            <span>Showing 1 to <?= $feedbackTotal; ?> of <?= $feedbackTotal; ?> entr<?= $feedbackTotal === 1 ? 'y' : 'ies'; ?></span>
                            <span class="feedback-pagination" aria-hidden="true">
                                <span class="feedback-page-button"><i class="fas fa-chevron-left"></i></span>
                                <span class="feedback-page-current">1</span>
                                <span class="feedback-page-button"><i class="fas fa-chevron-right"></i></span>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="feedback-empty">
                            <i class="far fa-comment-dots" aria-hidden="true"></i>
                            <h2>No feedback received yet.</h2>
                            <p>Feedback from resolved tickets you attended will appear here once requestors submit their ratings.</p>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>

    <div id="ticketModal" class="modal-overlay">
        <div class="modal-content" id="modalContent"></div>
    </div>

    <div id="imagePreviewModal" class="image-preview-modal" onclick="TMTicketModal.closeImagePreview(event)">
        <div class="preview-content">
            <button type="button" class="preview-close" onclick="TMTicketModal.closeImagePreview(event)" aria-label="Close preview">X</button>
            <button type="button" class="preview-nav preview-prev" onclick="TMTicketModal.stepImagePreview(-1)" aria-label="Previous attachment"><i class="fas fa-chevron-left"></i></button>
            <img id="previewImage" src="" alt="Preview" class="preview-image">
            <button type="button" class="preview-nav preview-next" onclick="TMTicketModal.stepImagePreview(1)" aria-label="Next attachment"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>

    <script>
    window.TM_CURRENT_USER = <?php echo json_encode([
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['name'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'department' => $_SESSION['department'] ?? null,
        'company' => $_SESSION['company'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.TM_HIDE_QUICK_TAGS = true;
    window.TM_DEPARTMENT_LABEL_TEXT = 'Assigned Department';
    window.TM_DEPARTMENT_REQUIRED = true;
    window.TM_SHOW_DEPARTMENT_USER_SELECT = true;
    window.TM_DEPARTMENT_USERS_ENDPOINT = 'ajax_department_users.php';
    window.TM_COMPANY_DEPARTMENT_OPTIONS = <?php echo json_encode([
        '@leadsagri.com' => array_map(static fn($department) => ['value' => (string) $department, 'label' => (string) $department], ticket_lapc_departments()),
        '@malvedaholdings.com' => array_map(static fn($department) => ['value' => (string) $department, 'label' => (string) $department], ticket_mhc_departments()),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
    <script>
    function openFeedbackTicket(ticketId) {
        if (!ticketId || !window.TMTicketModal || typeof window.TMTicketModal.open !== 'function') {
            return;
        }
        window.TMTicketModal.open(ticketId);
    }

    document.querySelectorAll('.feedback-ticket-row[data-ticket-id]').forEach(function(row) {
        row.addEventListener('click', function(event) {
            var link = event.target && event.target.closest ? event.target.closest('[data-feedback-ticket-link]') : null;
            if (link) {
                event.preventDefault();
            }
            openFeedbackTicket(row.getAttribute('data-ticket-id'));
        });
        row.addEventListener('keydown', function(event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            openFeedbackTicket(row.getAttribute('data-ticket-id'));
        });
    });
    </script>
    <script src="../js/employee-dashboard.js"></script>
</body>
</html>
