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
        et.requester_name,
        tf.rating,
        tf.comment,
        tf.created_at
    FROM ticket_feedback tf
    INNER JOIN employee_tickets et ON et.id = tf.ticket_id
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body.employee-feedback-page .feedback-page-shell {
            display: grid;
            gap: 24px;
        }

        body.employee-feedback-page .feedback-hero {
            background: linear-gradient(135deg, #14532d 0%, #166534 52%, #15803d 100%);
            color: #ffffff;
            border-radius: 24px;
            padding: 28px 32px;
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.16);
        }

        body.employee-feedback-page .feedback-hero h1 {
            margin: 0 0 10px;
            font-size: 34px;
            line-height: 1.1;
            font-weight: 800;
        }

        body.employee-feedback-page .feedback-hero p {
            margin: 0;
            max-width: 760px;
            font-size: 16px;
            line-height: 1.65;
            color: rgba(255, 255, 255, 0.88);
        }

        body.employee-feedback-page .feedback-section {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            padding: 24px;
        }

        body.employee-feedback-page .feedback-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        body.employee-feedback-page .feedback-section-title {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-count-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            background: #ecfdf3;
            color: #166534;
            font-size: 14px;
            font-weight: 800;
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
            background: #f8fafc;
            color: #166534;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 16px 18px;
            text-align: left;
            border-bottom: 2px solid #166534;
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
            padding: 18px;
            border-bottom: 1px solid #eef2f7;
            color: #334155;
            font-size: 14px;
            vertical-align: top;
        }

        body.employee-feedback-page .feedback-table tr:last-child td {
            border-bottom: none;
        }

        body.employee-feedback-page .feedback-ticket-id {
            font-weight: 800;
            color: #14532d;
            white-space: nowrap;
        }

        body.employee-feedback-page .feedback-subject {
            min-width: 220px;
            font-weight: 700;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-requestor {
            min-width: 160px;
            font-weight: 600;
        }

        body.employee-feedback-page .feedback-rating {
            min-width: 150px;
        }

        body.employee-feedback-page .feedback-stars {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #f59e0b;
            letter-spacing: 0.02em;
        }

        body.employee-feedback-page .feedback-stars .is-muted {
            color: #cbd5e1;
        }

        body.employee-feedback-page .feedback-rating-value {
            margin-left: 8px;
            color: #475569;
            font-weight: 700;
        }

        body.employee-feedback-page .feedback-comment {
            min-width: 260px;
            line-height: 1.6;
            color: #475569;
            white-space: pre-wrap;
        }

        body.employee-feedback-page .feedback-date {
            white-space: nowrap;
            color: #64748b;
            font-weight: 600;
        }

        body.employee-feedback-page .feedback-empty {
            padding: 42px 22px;
            border-radius: 18px;
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

        @media (max-width: 768px) {
            body.employee-feedback-page .feedback-hero {
                padding: 24px 20px;
                border-radius: 20px;
            }

            body.employee-feedback-page .feedback-hero h1 {
                font-size: 28px;
            }

            body.employee-feedback-page .feedback-section {
                padding: 18px;
                border-radius: 18px;
            }

            body.employee-feedback-page .feedback-section-header {
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
                    <h1>Feedback Page</h1>
                    <p>Review feedback submitted by requestors for tickets you attended. Entries are ordered from newest to oldest so you can quickly scan recent responses.</p>
                </section>

                <section class="feedback-section">
                    <div class="feedback-section-header">
                        <h2 class="feedback-section-title">Received Feedback</h2>
                        <span class="feedback-count-chip"><?= count($feedbackRows); ?></span>
                    </div>

                    <?php if (count($feedbackRows) > 0): ?>
                        <div class="feedback-table-wrap">
                            <table class="feedback-table">
                                <thead>
                                    <tr>
                                        <th>Ticket ID</th>
                                        <th>Subject</th>
                                        <th>Requestor</th>
                                        <th>Rating</th>
                                        <th>Comment</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($feedbackRows as $row): ?>
                                        <?php $ratingValue = max(1, min(5, (int) ($row['rating'] ?? 0))); ?>
                                        <tr>
                                            <td class="feedback-ticket-id">#<?= (int) ($row['ticket_id'] ?? 0); ?></td>
                                            <td class="feedback-subject"><?= htmlspecialchars((string) ($row['subject'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="feedback-requestor"><?= htmlspecialchars(feedback_requester_name($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="feedback-rating">
                                                <span class="feedback-stars" aria-label="<?= $ratingValue; ?> out of 5 stars">
                                                    <?php for ($star = 1; $star <= 5; $star++): ?>
                                                        <i class="fas fa-star <?= $star <= $ratingValue ? '' : 'is-muted'; ?>"></i>
                                                    <?php endfor; ?>
                                                </span>
                                                <span class="feedback-rating-value"><?= $ratingValue; ?>/5</span>
                                            </td>
                                            <td class="feedback-comment"><?= htmlspecialchars(trim((string) ($row['comment'] ?? '')) !== '' ? (string) $row['comment'] : 'No comment provided.', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="feedback-date"><?= htmlspecialchars(date('M d, Y g:i A', strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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

    <script src="../js/employee-dashboard.js"></script>
</body>
</html>
