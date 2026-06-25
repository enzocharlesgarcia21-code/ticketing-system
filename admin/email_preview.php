<?php
require_once '../config/database.php';
require_once '../includes/notification_service.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: admin_login.php');
    exit();
}

$sampleTicketId = 595;
$sampleTicketNumber = str_pad((string) $sampleTicketId, 6, '0', STR_PAD_LEFT);
$employeeTicketUrl = notif_ticket_link_employee_tickets($sampleTicketId);
$employeeTaskUrl = notif_ticket_link_employee_tasks($sampleTicketId);
$chatUrl = notif_ticket_link_employee_chat($sampleTicketId);

function preview_template(
    string $group,
    string $audience,
    string $subject,
    string $title,
    array $lines,
    string $ctaLabel,
    string $ctaUrl
): array {
    $mail = notif_email_simple($title, $lines, $ctaLabel, $ctaUrl);
    return [
        'group' => $group,
        'audience' => $audience,
        'subject' => $subject,
        'html' => (string) ($mail['html'] ?? ''),
        'text' => (string) ($mail['text'] ?? ''),
    ];
}

$ticketSubmittedLines = [
    'Ticket ID: #' . $sampleTicketNumber,
    'Category: Leave Request',
    'Requestor: Enzo Garcia (LAPC-IT)',
    'Email: enzogarcia@leadsagri.com',
    'Date Submitted: May 16, 2025 10:30 AM',
    'Level of Urgency: Medium (4 to 6 days)',
    'Description: Vacation leave request.',
];

$assigneeAssignedLines = [
    'Ticket ID: #' . $sampleTicketNumber,
    'Category: Leave Request',
    'Requestor: Enzo Garcia (LAPC-IT)',
    'Email: enzogarcia@leadsagri.com',
    'Date Submitted: May 16, 2025 10:30 AM',
    'Level of Urgency: Medium (4 to 6 days)',
    'Description: chel',
];

$updateLines = [
    'Ticket has been updated.',
    'Ticket ID: #' . $sampleTicketNumber,
    'Category: Leave Request',
    'Current Status: On-going',
    'Handled By: Mikaela Reyes (LAPC-HR)',
    'Assignee Email: mikaelareyes@leadsagri.com',
    'Date Submitted: May 16, 2025 10:30 AM',
    'Level of Urgency: Medium (4 to 6 days)',
    'Description: Vacation leave request.',
];

$reassignedLines = [
    'Ticket has been updated.',
    'Ticket ID: #' . $sampleTicketNumber,
    'Subject: Laptop login issue',
    'Requester: Enzo Garcia',
    'Requester Email: enzogarcia@leadsagri.com',
    'From: HR',
    'To: IT',
    'Category: Hardware',
];

$resolvedLines = [
    'Ticket has been updated.',
    'Ticket ID: #' . $sampleTicketNumber,
    'Resolved By: Matthew Pascua',
    'Subject: Laptop login issue',
    'Category: Hardware',
    'Current Status: Resolved',
];

$closedLines = [
    'Ticket ID: #' . $sampleTicketNumber,
    'Current status: Closed',
    'Closure reason: No response after resolution follow-up',
    'Last chat activity: May 16, 2025 04:30 PM',
    'Subject: Laptop login issue',
    'Category: Hardware',
];

$templates = [
    preview_template('New Ticket', 'Requester / Employee', 'Ticket Submitted (#' . $sampleTicketNumber . ')', 'Ticket Submitted', $ticketSubmittedLines, 'View Ticket', $employeeTicketUrl),
    preview_template('New Ticket', 'Assignee', 'New Ticket Assigned (#' . $sampleTicketNumber . ')', 'New Ticket Assigned', $assigneeAssignedLines, 'View Ticket', $employeeTaskUrl),

    preview_template('Assignment / Updates', 'Requester', 'Ticket Claimed (#' . $sampleTicketNumber . ')', 'Ticket Claimed', $updateLines, 'View Ticket', $employeeTicketUrl),
    preview_template('Assignment / Updates', 'Assignee', 'Ticket Assigned (#' . $sampleTicketNumber . ')', 'Ticket Assigned', $assigneeAssignedLines, 'View Ticket', $employeeTaskUrl),
    preview_template('Assignment / Updates', 'Assignee', 'Ticket Reassigned (#' . $sampleTicketNumber . ')', 'Ticket Reassigned', $reassignedLines, 'View Ticket', $employeeTaskUrl),

    preview_template('Lifecycle', 'Requester', 'Ticket Resolved (#' . $sampleTicketNumber . ')', 'Ticket Resolved', $resolvedLines, 'View Ticket', $employeeTicketUrl),
    preview_template('Lifecycle', 'Assignee', 'Ticket Resolved (#' . $sampleTicketNumber . ')', 'Ticket Resolved', $resolvedLines, 'View Task', $employeeTaskUrl),
    preview_template('Lifecycle', 'Requester / Admin', 'Ticket Closed (#' . $sampleTicketNumber . ')', 'Ticket Closed', $closedLines, 'View Ticket', $employeeTicketUrl),

    preview_template('Chat / SLA', 'Employee', 'Pending Chat (#' . $sampleTicketNumber . ')', 'Pending Chat', [
        'Ticket ID: #' . $sampleTicketNumber,
        'You have a pending chat reply that needs your attention.',
        'Subject: Laptop login issue',
    ], 'Open Chat', $chatUrl),
    preview_template('Chat / SLA', 'Assignee', 'SLA Escalation (#' . $sampleTicketNumber . ')', 'SLA Escalation', [
        'Ticket #' . $sampleTicketNumber . ' SLA status has been escalated to At Risk.',
        'Immediate attention is required.',
        'Previous SLA status: On Track',
        'Subject: Laptop login issue',
    ], 'View Ticket', $employeeTaskUrl),
];

$groups = [];
foreach ($templates as $template) {
    $groups[$template['group']][] = $template;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Notification Preview</title>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f5f7fb;
            color: #0f172a;
        }
        .preview-page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 34px 28px 52px;
        }
        .preview-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 24px;
        }
        .preview-header h1 {
            margin: 0;
            font-size: 32px;
            line-height: 1.1;
            letter-spacing: -0.01em;
        }
        .preview-header p {
            margin: 8px 0 0;
            color: #64748b;
            font-size: 15px;
            max-width: 760px;
        }
        .preview-back {
            color: #166534;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid #d8e2ec;
            background: #ffffff;
            border-radius: 12px;
            padding: 11px 16px;
            white-space: nowrap;
        }
        .preview-group {
            margin-top: 28px;
        }
        .preview-group h2 {
            margin: 0 0 14px;
            font-size: 18px;
        }
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(430px, 1fr));
            gap: 18px;
        }
        .preview-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.07);
        }
        .preview-meta {
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            background: #fbfdff;
        }
        .preview-meta strong {
            display: block;
            font-size: 14px;
            margin-bottom: 4px;
        }
        .preview-meta span {
            display: block;
            color: #64748b;
            font-size: 12px;
            line-height: 1.4;
        }
        .preview-frame {
            width: 100%;
            height: 610px;
            border: 0;
            display: block;
            background: #ffffff;
        }
        @media (max-width: 620px) {
            .preview-page { padding: 24px 14px 40px; }
            .preview-header { align-items: flex-start; flex-direction: column; }
            .preview-grid { grid-template-columns: 1fr; }
            .preview-frame { height: 660px; }
        }
    </style>
</head>
<body>
    <main class="preview-page">
        <div class="preview-header">
            <div>
                <h1>Email Notification Preview</h1>
                <p>All major notification templates are rendered below with sample ticket data. These previews do not send emails.</p>
            </div>
            <a class="preview-back" href="dashboard.php">Back to Dashboard</a>
        </div>

        <?php foreach ($groups as $groupName => $items): ?>
            <section class="preview-group">
                <h2><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8'); ?></h2>
                <div class="preview-grid">
                    <?php foreach ($items as $item): ?>
                        <article class="preview-card">
                            <div class="preview-meta">
                                <strong><?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span>Audience: <?= htmlspecialchars($item['audience'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <iframe
                                class="preview-frame"
                                title="<?= htmlspecialchars($item['subject'], ENT_QUOTES, 'UTF-8'); ?>"
                                srcdoc="<?= htmlspecialchars($item['html'], ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </main>
</body>
</html>
