<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/csrf.php';

/* Protect page */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

ticket_apply_sla_priority($conn);

$user_id = (int) $_SESSION['user_id'];
$feedbackFlash = isset($_SESSION['feedback_flash']) && is_array($_SESSION['feedback_flash']) ? $_SESSION['feedback_flash'] : null;
if ($feedbackFlash !== null) {
    unset($_SESSION['feedback_flash']);
}
$showFeedbackSuccessModal = $feedbackFlash && (($feedbackFlash['type'] ?? '') === 'success') && !empty($feedbackFlash['message']);

/* Fetch profile context */
$company = '';
$user_department = (string) ($_SESSION['department'] ?? '');
$user_email = (string) ($_SESSION['email'] ?? '');
$userQuery = $conn->query("SELECT company, department, email FROM users WHERE id = $user_id");
if ($userQuery && $row = $userQuery->fetch_assoc()) {
    $company = (string) ($row['company'] ?? '');
    if ($user_department === '') {
        $user_department = (string) ($row['department'] ?? '');
        if ($user_department !== '') $_SESSION['department'] = $user_department;
    }
    if ($user_email === '') {
        $user_email = (string) ($row['email'] ?? '');
        if ($user_email !== '') $_SESSION['email'] = $user_email;
    }
}

/* Ticket Counts (tickets created by this employee) */
$dept = (string) ($_SESSION['department'] ?? '');

$countStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND COALESCE(NULLIF(status,''),'') <> 'Trash'");
$countStmt->bind_param("i", $user_id);
$countStmt->execute();
$total = (int) (($countStmt->get_result()->fetch_assoc()['count'] ?? 0));
$countStmt->close();

$openStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Open'");
$openStmt->bind_param("i", $user_id);
$openStmt->execute();
$open = (int) (($openStmt->get_result()->fetch_assoc()['count'] ?? 0));
$openStmt->close();

$progressStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'In Progress'");
$progressStmt->bind_param("i", $user_id);
$progressStmt->execute();
$progress = (int) (($progressStmt->get_result()->fetch_assoc()['count'] ?? 0));
$progressStmt->close();

$resolvedStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Resolved'");
$resolvedStmt->bind_param("i", $user_id);
$resolvedStmt->execute();
$resolved = (int) (($resolvedStmt->get_result()->fetch_assoc()['count'] ?? 0));
$resolvedStmt->close();

$closedStmt = $conn->prepare("SELECT COUNT(*) AS count FROM employee_tickets WHERE user_id = ? AND status = 'Closed'");
$closedStmt->bind_param("i", $user_id);
$closedStmt->execute();
$closed = (int) (($closedStmt->get_result()->fetch_assoc()['count'] ?? 0));
$closedStmt->close();

$submittedDashboardStats = [
    [
        'variant' => 'total',
        'label' => 'Total Tickets',
        'value' => $total,
        'subtitle' => 'All non-trash tickets in system',
        'icon' => 'fa-stopwatch',
        'href' => 'my_tickets.php',
    ],
    [
        'variant' => 'open',
        'label' => 'Open',
        'value' => $open,
        'subtitle' => 'Awaiting response',
        'icon' => 'fa-folder-open',
        'href' => 'my_tickets.php?status=Open',
    ],
    [
        'variant' => 'progress',
        'label' => 'In Progress',
        'value' => $progress,
        'subtitle' => 'Currently being worked',
        'icon' => 'fa-gear',
        'href' => 'my_tickets.php?status=In+Progress',
    ],
    [
        'variant' => 'resolved',
        'label' => 'Resolved',
        'value' => $resolved,
        'subtitle' => 'Completed tickets',
        'icon' => 'fa-check-circle',
        'href' => 'my_tickets.php?status=Resolved',
    ],
    [
        'variant' => 'closed',
        'label' => 'Closed',
        'value' => $closed,
        'subtitle' => 'Confirmed by requesters',
        'icon' => 'fa-lock',
        'href' => 'my_tickets.php?status=Closed',
    ],
];


/* Recent Tickets (created by this employee) */
$recentStmt = $conn->prepare("
    SELECT
        t.*,
        u.name AS requester_name,
        u.email AS user_email,
        u.department AS user_department,
        u.company AS user_company
    FROM employee_tickets t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.user_id = ?
      AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
    ORDER BY t.created_at DESC
    LIMIT 5
");
$recentStmt->bind_param("i", $user_id);
$recentStmt->execute();
$recent = $recentStmt->get_result();
$raisedTickets = [];
while ($recent && ($row = $recent->fetch_assoc())) {
    $raisedTickets[] = $row;
}
$recentStmt->close();

$receivedTickets = [];
$receivedStmt = $conn->prepare("
    SELECT
        t.*,
        u.name AS requester_name,
        u.email AS user_email,
        u.department AS user_department,
        u.company AS user_company
    FROM employee_tickets t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.user_id <> ?
      AND COALESCE(NULLIF(t.status,''),'') <> 'Trash'
    ORDER BY t.created_at DESC
    LIMIT 80
");
if ($receivedStmt) {
    $receivedStmt->bind_param("i", $user_id);
    $receivedStmt->execute();
    $receivedResult = $receivedStmt->get_result();
    $userContext = [
        'department' => $user_department,
        'company' => $company,
        'email' => $user_email,
    ];
    while ($receivedResult && ($ticketRow = $receivedResult->fetch_assoc())) {
        if (ticket_user_is_handler_candidate($ticketRow, $user_id, $userContext)) {
            $receivedTickets[] = $ticketRow;
        }
    }
    $receivedStmt->close();
}

usort($receivedTickets, static function (array $a, array $b): int {
    $rankA = dashboard_sla_rank((string) ($a['created_at'] ?? ''), (string) ($a['status'] ?? ''), (string) ($a['priority'] ?? ''));
    $rankB = dashboard_sla_rank((string) ($b['created_at'] ?? ''), (string) ($b['status'] ?? ''), (string) ($b['priority'] ?? ''));
    if ($rankA !== $rankB) {
        return $rankA <=> $rankB;
    }

    $dateA = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
    $dateB = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
    if ($rankA < 3) {
        return $dateA <=> $dateB;
    }
    return $dateB <=> $dateA;
});

$assignedStatusCounts = [
    'Open' => 0,
    'In Progress' => 0,
    'Resolved' => 0,
    'Closed' => 0,
];
foreach ($receivedTickets as $ticketRow) {
    $ticketStatus = (string) ($ticketRow['status'] ?? '');
    if (isset($assignedStatusCounts[$ticketStatus])) {
        $assignedStatusCounts[$ticketStatus]++;
    }
}
$assignedTotal = array_sum($assignedStatusCounts);
$assignedDashboardStats = [
    [
        'variant' => 'total',
        'label' => 'Total Tickets',
        'value' => $assignedTotal,
        'subtitle' => 'All assigned non-trash tickets',
        'icon' => 'fa-stopwatch',
        'href' => 'my_task.php',
    ],
    [
        'variant' => 'open',
        'label' => 'Open',
        'value' => $assignedStatusCounts['Open'],
        'subtitle' => 'Awaiting response',
        'icon' => 'fa-folder-open',
        'href' => 'my_task.php?status=Open',
    ],
    [
        'variant' => 'progress',
        'label' => 'In Progress',
        'value' => $assignedStatusCounts['In Progress'],
        'subtitle' => 'Currently being worked',
        'icon' => 'fa-gear',
        'href' => 'my_task.php?status=In+Progress',
    ],
    [
        'variant' => 'resolved',
        'label' => 'Resolved',
        'value' => $assignedStatusCounts['Resolved'],
        'subtitle' => 'Completed tickets',
        'icon' => 'fa-check-circle',
        'href' => 'my_task.php?status=Resolved',
    ],
    [
        'variant' => 'closed',
        'label' => 'Closed',
        'value' => $assignedStatusCounts['Closed'],
        'subtitle' => 'Confirmed by requesters',
        'icon' => 'fa-lock',
        'href' => 'my_task.php?status=Closed',
    ],
];
$dashboardStatSets = [
    'submitted' => $submittedDashboardStats,
    'assigned' => $assignedDashboardStats,
];
$receivedTickets = array_slice($receivedTickets, 0, 5);

function dashboard_status_class(string $status): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($status)));
}

function dashboard_ticket_category(array $row): string
{
    $category = trim((string) ($row['category'] ?? ''));
    if ($category !== '') return $category;
    $subject = trim((string) ($row['subject'] ?? ''));
    return $subject !== '' ? $subject : 'General Concern';
}

function dashboard_source_label(array $row): string
{
    $sourceEmail = trim((string) (($row['requester_email'] ?? '') !== '' ? $row['requester_email'] : ($row['user_email'] ?? '')));
    $sourceCompanyRaw = (string) (($row['company'] ?? '') !== '' ? $row['company'] : ($row['user_company'] ?? ''));
    if ($sourceCompanyRaw === '' && $sourceEmail !== '' && strpos($sourceEmail, '@') !== false) {
        $sourceCompanyRaw = '@' . strtolower(substr(strrchr($sourceEmail, '@'), 1));
    }
    $sourceCompany = ticket_normalize_company($sourceCompanyRaw);
    $sourceDept = trim((string) (($row['department'] ?? '') !== '' ? $row['department'] : ($row['user_department'] ?? '')));

    if ($sourceCompany === '@leadsagri.com' && $sourceDept !== '') {
        return ticket_department_display_name($sourceDept);
    }

    $companyLabel = ticket_company_display_name($sourceCompanyRaw);
    if ($companyLabel !== '') {
        return $companyLabel;
    }

    if ($sourceDept !== '') {
        return ticket_department_display_name($sourceDept);
    }

    return '-';
}

function dashboard_requester_info(array $row): array
{
    $name = trim((string) (($row['requester_name'] ?? '') !== '' ? $row['requester_name'] : ($row['user_name'] ?? '')));
    $email = trim((string) (($row['requester_email'] ?? '') !== '' ? $row['requester_email'] : ($row['user_email'] ?? '')));
    $description = (string) ($row['description'] ?? '');

    if ($description !== '') {
        if ($name === '' && preg_match('/REQUESTER NAME:\s*(.+)$/im', $description, $m)) {
            $name = trim($m[1]);
        }
        if ($email === '' && preg_match('/REQUESTER EMAIL:\s*(.+)$/im', $description, $m)) {
            $email = trim($m[1]);
        }
    }

    return [
        'name' => $name !== '' ? $name : 'Unknown Requester',
        'email' => $email,
    ];
}

function dashboard_sla_rank(string $createdAt, string $status, string $priority = ''): int
{
    $statusKey = strtolower(trim($status));
    if ($statusKey === 'resolved' || $statusKey === 'closed') return 3;

    $priorityKey = strtolower(trim($priority));
    if ($priorityKey === 'critical') return 0;
    if ($priorityKey === 'high') return 1;

    $createdAt = trim($createdAt);
    if ($createdAt === '') return 3;

    try {
        $created = new DateTimeImmutable($createdAt);
    } catch (Throwable $e) {
        return 3;
    }

    $now = new DateTimeImmutable('now');
    $createdDay = $created->setTime(0, 0, 0);
    $nowDay = $now->setTime(0, 0, 0);
    $diff = $nowDay->diff($createdDay);
    $days = (int) ($diff->days ?? 0);
    if ($diff->invert !== 1) $days = 0;

    if ($days >= 7) return 0;
    if ($days >= 4) return 1;
    return 2;
}

function dashboard_sla_badge_html(string $createdAt, string $status, string $priority = ''): string
{
    $rank = dashboard_sla_rank($createdAt, $status, $priority);
    if ($rank === 0) {
        return '<span class="badge badge-high">Breach</span>';
    }
    if ($rank === 1) {
        return '<span class="badge badge-medium">At Risk</span>';
    }
    if ($rank === 2) {
        return '<span class="badge badge-low">On Track</span>';
    }
    return '-';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard | Leads Agri Helpdesk</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <!-- Optional: Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body.employee-dashboard-page .feedback-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 3200;
        }

        body.employee-dashboard-page .feedback-modal-overlay.is-visible {
            display: flex;
        }

        body.employee-dashboard-page .feedback-modal-dialog {
            position: relative;
            width: min(100%, 560px);
            background: #ffffff;
            border-radius: 22px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.24);
            overflow: hidden;
            border: 1px solid rgba(203, 213, 225, 0.8);
            text-align: center;
        }

        body.employee-dashboard-page .feedback-modal-header {
            padding: 36px 36px 34px;
            background: #ffffff;
            color: #111827;
            position: relative;
        }

        body.employee-dashboard-page .feedback-modal-success-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 26px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 4px solid #bbf7b5;
            background: #ecfdf5;
            color: #0f5f24;
            font-size: 44px;
            font-weight: 500;
            line-height: 1;
        }

        body.employee-dashboard-page .feedback-modal-title {
            margin: 0 0 16px;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 800;
        }

        body.employee-dashboard-page .feedback-modal-subtitle {
            margin: 0;
            font-size: 18px;
            line-height: 1.45;
            color: #4b5563;
        }

        body.employee-dashboard-page .feedback-modal-body {
            padding: 22px 36px 28px;
            border-top: 1px solid #e5e7eb;
        }

        body.employee-dashboard-page .feedback-modal-body .feedback-actions {
            display: flex;
            justify-content: center;
            gap: 0;
        }

        body.employee-dashboard-page .feedback-modal-body .feedback-submit-btn {
            min-width: 170px;
            min-height: 52px;
            border-radius: 16px;
            background: #11651f;
            font-size: 18px;
            box-shadow: 0 14px 28px rgba(17, 101, 31, 0.22);
        }

        body.employee-dashboard-page .feedback-ticket-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            max-width: 100%;
            padding: 12px 16px;
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid #dbe4ee;
            color: #0f172a;
            margin-bottom: 18px;
        }

        body.employee-dashboard-page .feedback-ticket-chip strong {
            font-size: 15px;
            font-weight: 800;
            color: #14532d;
        }

        body.employee-dashboard-page .feedback-ticket-chip span {
            font-size: 14px;
            color: #475569;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        body.employee-dashboard-page .feedback-flash {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
        }

        body.employee-dashboard-page .feedback-flash.is-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        body.employee-dashboard-page .feedback-flash.is-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        body.employee-dashboard-page .feedback-form {
            display: grid;
            gap: 18px;
        }

        body.employee-dashboard-page .feedback-label {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: #334155;
            text-transform: uppercase;
        }

        body.employee-dashboard-page .feedback-stars {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        body.employee-dashboard-page .feedback-star-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        body.employee-dashboard-page .feedback-star {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            border: 1px solid #dbe4ee;
            background: #ffffff;
            color: #cbd5e1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.18s ease, border-color 0.18s ease, color 0.18s ease, background 0.18s ease;
        }

        body.employee-dashboard-page .feedback-star:hover,
        body.employee-dashboard-page .feedback-star:focus-visible {
            transform: translateY(-1px);
            border-color: #f4c430;
            color: #f59e0b;
            background: #fffbea;
        }

        body.employee-dashboard-page .feedback-star.is-active {
            border-color: #f4c430;
            color: #f59e0b;
            background: #fff7d6;
            box-shadow: 0 10px 22px rgba(245, 158, 11, 0.18);
        }

        body.employee-dashboard-page .feedback-textarea {
            width: 100%;
            min-height: 130px;
            resize: vertical;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid #dbe4ee;
            background: #ffffff;
            color: #0f172a;
            font-size: 15px;
            line-height: 1.55;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }

        body.employee-dashboard-page .feedback-textarea:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.12);
        }

        body.employee-dashboard-page .feedback-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        body.employee-dashboard-page .feedback-cancel-btn {
            min-width: 132px;
            min-height: 48px;
            border: 1px solid #dbe4ee;
            border-radius: 14px;
            background: #ffffff;
            color: #334155;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
        }

        body.employee-dashboard-page .feedback-submit-btn {
            min-width: 168px;
            min-height: 48px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, #166534 0%, #15803d 100%);
            color: #ffffff;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 16px 30px rgba(22, 101, 52, 0.24);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        body.employee-dashboard-page .feedback-submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 34px rgba(22, 101, 52, 0.28);
        }

        body.employee-dashboard-page .feedback-submit-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }


        body.employee-dashboard-page {
            background: #f8fafc;
        }

        body.employee-dashboard-page .dashboard-container {
            width: min(calc(100% - 72px), 1480px);
            max-width: none;
            padding: 34px 0 54px;
        }

        body.employee-dashboard-page .content-wrapper {
            display: grid;
            gap: 24px;
        }

        body.employee-dashboard-page .hero-section {
            margin: 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
        }

        body.employee-dashboard-page .hero-copy {
            min-width: 0;
            flex: 1 1 auto;
        }

        body.employee-dashboard-page .hero-action {
            flex: 0 0 auto;
            align-self: flex-start;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 20px;
            border-radius: 14px;
            background: #1B5E20;
            color: #ffffff;
            border: 1px solid rgba(20, 74, 30, 0.28);
            box-shadow: 0 14px 28px rgba(27, 94, 32, 0.18);
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        body.employee-dashboard-page .hero-action:hover {
            background: #166534;
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 18px 32px rgba(27, 94, 32, 0.22);
        }

        body.employee-dashboard-page .hero-title {
            margin: 0 0 10px;
            color: #0f5f24;
            font-size: 30px;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: 0;
        }

        body.employee-dashboard-page .hero-dept {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            padding: 5px 12px;
            border-radius: 8px;
            background: #eef2f7;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        body.employee-dashboard-page .company-text {
            color: #64748b;
        }

        body.employee-dashboard-page .hero-subtitle {
            margin: 0;
            color: #64748b;
            font-size: 16px;
        }

        body.employee-dashboard-page .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
            margin: 10px 0 0;
        }

        body.employee-dashboard-page .cards-panel {
            padding: 18px 18px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
        }

        body.employee-dashboard-page .card-filter-row {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin: 0;
            flex-wrap: nowrap;
        }

        body.employee-dashboard-page .card-filter-label {
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        body.employee-dashboard-page .card-filter-dropdown {
            position: relative;
            flex: 0 0 auto;
        }

        body.employee-dashboard-page .card-filter-trigger {
            min-width: 190px;
            height: 40px;
            padding: 0 38px 0 12px;
            border: 1px solid #dbe4ee;
            border-radius: 10px;
            background: #ffffff;
            color: #0f172a;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
        }

        body.employee-dashboard-page .card-filter-trigger-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .card-filter-trigger-icon {
            width: 18px;
            text-align: center;
            color: #166534;
            flex: 0 0 18px;
        }

        body.employee-dashboard-page .card-filter-trigger-caret {
            color: #475569;
            font-size: 12px;
            flex: 0 0 auto;
        }

        body.employee-dashboard-page .card-filter-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 190px;
            padding: 6px;
            border: 1px solid #dbe4ee;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
            z-index: 20;
        }

        body.employee-dashboard-page .card-filter-menu[hidden] {
            display: none;
        }

        body.employee-dashboard-page .card-filter-option {
            width: 100%;
            min-height: 38px;
            padding: 0 10px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            font-weight: 500;
            text-align: left;
            cursor: pointer;
        }

        body.employee-dashboard-page .card-filter-option:hover {
            background: #f8fafc;
        }

        body.employee-dashboard-page .card-filter-option-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .card-filter-option-icon {
            width: 18px;
            text-align: center;
            color: #166534;
            flex: 0 0 18px;
        }

        body.employee-dashboard-page .card-filter-option-check {
            color: #16a34a;
            font-size: 12px;
            opacity: 0;
        }

        body.employee-dashboard-page .card-filter-option.is-active .card-filter-option-check {
            opacity: 1;
        }

        body.employee-dashboard-page .stats-grid[hidden] {
            display: none;
        }

        body.employee-dashboard-page .stat-card {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 168px;
            padding: 18px 20px 16px;
            border: 1px solid #e0e8f2;
            border-radius: 18px;
            background:
                linear-gradient(90deg, var(--stat-accent, #4ade80) 0 7px, #ffffff 7px 100%);
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
        }

        body.employee-dashboard-page .stat-card::after {
            content: none;
        }

        body.employee-dashboard-page .stat-card:hover,
        body.employee-dashboard-page .stat-card:focus-visible {
            border-color: var(--stat-accent, #f4c430);
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.12);
            transform: translateY(-2px);
            outline: none;
        }

        body.employee-dashboard-page .stat-card.total {
            --stat-accent: #22c55e;
            --stat-icon-bg: #e4f5ea;
            --stat-icon-color: #087029;
            --stat-chip-bg: #ecfdf3;
            --stat-chip-color: #11651f;
        }

        body.employee-dashboard-page .stat-card.open {
            --stat-accent: #4fb7ff;
            --stat-icon-bg: #fffde9;
            --stat-icon-color: #f2b500;
            --stat-chip-bg: #edf7ff;
            --stat-chip-color: #2d9bf0;
        }

        body.employee-dashboard-page .stat-card.progress {
            --stat-accent: #9b6bff;
            --stat-icon-bg: #e4f5ea;
            --stat-icon-color: #087029;
            --stat-chip-bg: #f5edff;
            --stat-chip-color: #7c3aed;
        }

        body.employee-dashboard-page .stat-card.resolved {
            --stat-accent: #ffab2e;
            --stat-icon-bg: #e4f5ea;
            --stat-icon-color: #0b6b35;
            --stat-chip-bg: #fff4e8;
            --stat-chip-color: #f97316;
        }

        body.employee-dashboard-page .stat-card.closed {
            --stat-accent: #94a3b8;
            --stat-icon-bg: #eef3f9;
            --stat-icon-color: #475569;
            --stat-chip-bg: #f8fafc;
            --stat-chip-color: #334155;
        }

        body.employee-dashboard-page .stat-main {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            min-width: 0;
        }

        body.employee-dashboard-page .stat-copy {
            min-width: 0;
        }

        body.employee-dashboard-page .stat-icon {
            flex: 0 0 auto;
            width: 52px;
            height: 52px;
            margin-bottom: 0;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--stat-icon-bg, #e4f5ea);
            color: var(--stat-icon-color, #087029);
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.42);
            font-size: 22px;
        }

        body.employee-dashboard-page .stat-label {
            margin: 2px 0 6px;
            color: #081635;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
        }

        body.employee-dashboard-page .stat-value {
            color: #17213d;
            font-size: 40px;
            line-height: 1;
            font-weight: 500;
            letter-spacing: 0;
        }

        body.employee-dashboard-page .stat-subtext {
            margin-top: 12px;
            color: #58708f;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.35;
        }

        body.employee-dashboard-page .stat-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: max-content;
            margin-top: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--stat-chip-bg, #ecfdf3);
            color: var(--stat-chip-color, #11651f);
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            transition: transform 0.16s ease;
        }

        body.employee-dashboard-page .stat-card:hover .stat-action,
        body.employee-dashboard-page .stat-card:focus-visible .stat-action {
            transform: translateX(2px);
        }

        @media (max-width: 1400px) {
            body.employee-dashboard-page .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 992px) {
            body.employee-dashboard-page .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            body.employee-dashboard-page .stat-card {
                min-height: 204px;
            }
        }

        body.employee-dashboard-page .dashboard-ticket-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 22px;
        }

        body.employee-dashboard-page .dashboard-ticket-panel {
            min-width: 0;
            padding: 24px 24px 22px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
        }

        body.employee-dashboard-page .dashboard-ticket-title {
            margin: 0 0 18px;
            color: #111827;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }

        body.employee-dashboard-page .dashboard-ticket-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        body.employee-dashboard-page .dashboard-ticket-table th {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #1B5E20;
            color: #1B5E20;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-align: left;
        }

        body.employee-dashboard-page .dashboard-ticket-table th:first-child {
            border-bottom-left-radius: 8px;
            border-top-left-radius: 8px;
        }

        body.employee-dashboard-page .dashboard-ticket-table th:last-child {
            border-bottom-right-radius: 8px;
            border-top-right-radius: 8px;
        }

        body.employee-dashboard-page .dashboard-ticket-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #edf2f7;
            color: #334155;
            font-size: 13px;
            vertical-align: middle;
        }

        body.employee-dashboard-page .dashboard-ticket-table tr:last-child td {
            border-bottom: 0;
        }

        body.employee-dashboard-page .dashboard-ticket-table tr.ticket-row {
            cursor: pointer;
        }

        body.employee-dashboard-page .dashboard-ticket-table tr.ticket-row:hover td {
            background: #f8fafc;
        }

        body.employee-dashboard-page .dashboard-ticket-id {
            width: 116px;
            color: #334155;
            font-weight: 500;
        }

        body.employee-dashboard-page .dashboard-ticket-category {
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
        }

        body.employee-dashboard-page .dashboard-ticket-requester {
            min-width: 230px;
        }

        body.employee-dashboard-page .dashboard-ticket-requester strong {
            display: block;
            color: #172b4d;
            font-size: 14px;
            line-height: 1.25;
        }

        body.employee-dashboard-page .dashboard-ticket-requester small {
            display: block;
            margin-top: 3px;
            color: #0f2f57;
            font-size: 12px;
            line-height: 1.25;
        }

        body.employee-dashboard-page .dashboard-ticket-department {
            min-width: 110px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .dashboard-ticket-table .status-pill {
            font-weight: 400;
        }

        body.employee-dashboard-page .dashboard-ticket-sla {
            width: 120px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 26px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 400;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            box-sizing: border-box;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-low {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-high {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-medium {
            background: #ffedd5;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        body.employee-dashboard-page .dashboard-ticket-sla .badge-critical {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        body.employee-dashboard-page .dashboard-ticket-date {
            width: 150px;
            white-space: nowrap;
        }

        body.employee-dashboard-page .dashboard-ticket-arrow {
            width: 24px;
            color: #64748b;
            font-size: 18px;
            font-weight: 900;
            text-align: right;
        }

        body.employee-dashboard-page .dashboard-ticket-empty {
            padding: 34px 12px;
            color: #94a3b8;
            text-align: center;
            font-weight: 700;
        }


        body.employee-dashboard-page .feedback-modal-dialog.feedback-modal-dialog-success {
            width: min(100%, 560px);
            max-height: calc(100vh - 44px);
            border-radius: 18px;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-header {
            padding: 28px 44px 24px;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-success-icon {
            width: 62px;
            height: 62px;
            margin-bottom: 18px;
            font-size: 36px;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-title {
            margin-bottom: 10px;
            font-size: 28px;
            color: #006d2c;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-subtitle {
            font-size: 16px;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-body {
            padding: 22px 40px 26px;
            display: grid;
            gap: 18px;
            overflow-y: auto;
            max-height: calc(100vh - 250px);
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-flash.is-success {
            margin: 0;
            padding: 14px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-align: left;
            font-size: 15px;
            font-weight: 500;
            line-height: 1.45;
        }

        body.employee-dashboard-page .feedback-success-message-icon {
            width: 46px;
            height: 46px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            background: #dcfce7;
            color: #00a63a;
            font-size: 24px;
        }

        body.employee-dashboard-page .feedback-success-message-copy strong {
            display: inline;
            color: #006d2c;
            font-weight: 800;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-actions {
            justify-content: center;
        }

        body.employee-dashboard-page .feedback-modal-dialog-success .feedback-submit-btn {
            width: auto;
            min-width: 170px;
            min-height: 48px;
            border-radius: 12px;
            font-size: 15px;
        }

        @media (max-width: 768px) {
            body.employee-dashboard-page .feedback-modal-overlay {
                padding: 12px;
            }

            body.employee-dashboard-page .feedback-modal-dialog.feedback-modal-dialog-success {
                width: min(100%, 94vw);
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-header,
            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-modal-body {
                padding-left: 18px;
                padding-right: 18px;
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-flash.is-success {
                align-items: flex-start;
            }

            body.employee-dashboard-page .feedback-modal-dialog-success .feedback-submit-btn {
                width: 100%;
            }
        }

        body.employee-dashboard-page .mobile-sidebar,
        body.employee-dashboard-page .mobile-sidebar-overlay {
            display: none;
        }

        @media (max-width: 768px) {
            body.employee-dashboard-page .hero-section {
                flex-direction: column;
                align-items: stretch;
            }

            body.employee-dashboard-page .hero-action {
                width: 100%;
            }

            body.employee-dashboard-page #navbarCollapse,
            body.employee-dashboard-page.sidebar-open #navbarCollapse {
                display: none !important;
            }

            body.employee-dashboard-page.sidebar-open .tm-global-chat-fab {
                opacity: 0;
                pointer-events: none;
                transform: translateY(8px);
            }

            body.employee-dashboard-page .mobile-sidebar {
                position: fixed;
                top: 0;
                right: -260px;
                width: 260px;
                height: 100vh;
                background: #1B5E20;
                padding: 20px;
                transition: right 0.3s ease;
                z-index: 2000;
                display: flex;
                flex-direction: column;
                gap: 18px;
                box-shadow: 12px 0 28px rgba(15, 23, 42, 0.25);
            }

            body.employee-dashboard-page .mobile-sidebar.active {
                right: 0;
            }

            body.employee-dashboard-page .mobile-sidebar-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            body.employee-dashboard-page .mobile-sidebar-header img {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #ffffff;
                padding: 4px;
                object-fit: contain;
                flex: 0 0 36px;
            }

            body.employee-dashboard-page .mobile-sidebar-header span {
                color: #ffffff;
                font-size: 15px;
                font-weight: 700;
                line-height: 1.2;
            }

            body.employee-dashboard-page .mobile-sidebar a {
                color: white;
                text-decoration: none;
                font-size: 16px;
                font-weight: 500;
                min-height: 44px;
                display: flex;
                align-items: center;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-dashboard-page .mobile-sidebar a.active,
            body.employee-dashboard-page .mobile-sidebar a:hover {
                background: rgba(255, 255, 255, 0.12);
            }

            body.employee-dashboard-page .mobile-sidebar-footer {
                margin-top: auto;
                padding-top: 14px;
                border-top: 1px solid rgba(255, 255, 255, 0.18);
                display: flex;
                align-items: center;
                gap: 12px;
            }

            body.employee-dashboard-page .mobile-sidebar-icon-link,
            body.employee-dashboard-page .mobile-sidebar-user-btn {
                min-height: 44px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.28);
                color: #ffffff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
            }

            body.employee-dashboard-page .mobile-sidebar-icon-link {
                width: 44px;
                min-width: 44px;
                position: relative;
            }

            body.employee-dashboard-page .mobile-sidebar-icon-link i,
            body.employee-dashboard-page .mobile-sidebar-user-btn i {
                font-size: 16px;
            }

            body.employee-dashboard-page .mobile-sidebar-badge {
                position: absolute;
                top: -6px;
                right: -4px;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                border-radius: 999px;
                background: #ff4d4f;
                color: #ffffff;
                font-size: 11px;
                font-weight: 800;
                display: none;
                align-items: center;
                justify-content: center;
                line-height: 1;
                border: 2px solid #1B5E20;
            }

            body.employee-dashboard-page .mobile-sidebar-user {
                position: relative;
            }

            body.employee-dashboard-page .mobile-sidebar-user-btn {
                gap: 10px;
                padding: 0 16px;
                cursor: pointer;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu {
                position: absolute;
                right: 0;
                bottom: calc(100% + 10px);
                min-width: 170px;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 16px 30px rgba(15, 23, 42, 0.18);
                padding: 8px;
                display: none;
                flex-direction: column;
                gap: 4px;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu.show {
                display: flex;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu a {
                min-height: 40px;
                color: #0f172a;
                font-size: 14px;
                font-weight: 600;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-dashboard-page .mobile-sidebar-user-menu a:hover {
                background: #f1f5f9;
            }

            body.employee-dashboard-page .mobile-sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.4);
                opacity: 0;
                visibility: hidden;
                transition: 0.3s;
                z-index: 1500;
                display: block;
            }

            body.employee-dashboard-page .mobile-sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            body.employee-dashboard-page .nav-left,
            body.employee-dashboard-page .navbar-toggler {
                position: relative;
                z-index: 2105;
            }

            body.employee-dashboard-page .tm-global-chat-fab {
                right: 12px;
                bottom: 12px;
                width: 42px !important;
                max-width: 42px !important;
                height: 42px;
                min-height: 42px;
                min-width: 42px;
                padding: 0 !important;
                border-radius: 999px;
                gap: 0;
                flex: 0 0 42px;
                justify-content: center;
                transition: opacity 0.2s ease, transform 0.2s ease, background 0.2s ease;
            }

            body.employee-dashboard-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-dashboard-page .tm-global-chat-fab i {
                font-size: 16px;
            }

            body.employee-dashboard-page .tm-global-chat-fab .chat-badge {
                top: -4px;
                right: -4px;
            }

            body.employee-dashboard-page .dashboard-container {
                width: auto;
                padding: 22px 14px 36px;
            }

            body.employee-dashboard-page .hero-title {
                font-size: 24px;
            }

            body.employee-dashboard-page .stats-grid,
            body.employee-dashboard-page .dashboard-ticket-grid {
                grid-template-columns: 1fr;
            }

            body.employee-dashboard-page .cards-panel {
                padding: 14px;
                border-radius: 16px;
            }

            body.employee-dashboard-page .card-filter-row {
                justify-content: flex-start;
                margin-bottom: 8px;
            }

            body.employee-dashboard-page .stat-card {
                min-height: 148px;
                padding: 14px 16px 12px;
                border-radius: 16px;
            }

            body.employee-dashboard-page .stat-icon {
                width: 46px;
                height: 46px;
                font-size: 20px;
            }

            body.employee-dashboard-page .stat-value {
                font-size: 36px;
            }

            body.employee-dashboard-page .stat-subtext {
                margin-top: 10px;
                font-size: 13px;
            }

            body.employee-dashboard-page .dashboard-ticket-panel {
                padding: 18px;
                overflow-x: auto;
            }

            body.employee-dashboard-page .dashboard-ticket-table {
                min-width: 1040px;
            }

            body.employee-dashboard-page .recent-section .table-responsive table thead {
                display: none;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row {
                display: grid;
                grid-template-columns: 1fr;
                grid-template-areas:
                    "id"
                    "category"
                    "title"
                    "date"
                    "arrow";
                gap: 1px;
                padding: 8px;
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 2px 6px rgba(0,0,0,0.04);
                border: 1px solid #dbe4ee;
                cursor: pointer;
                transition: all 0.2s ease;
                min-height: 104px;
                align-content: start;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row:hover {
                border-color: #1B5E20;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row:active {
                transform: scale(0.98);
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row td {
                display: block;
                padding: 0;
                border: none;
                text-align: left;
            }

            body.employee-dashboard-page .recent-section .table-responsive table tbody tr.ticket-row td::before {
                display: none;
            }

            body.employee-dashboard-page .recent-ticket-id {
                grid-area: id;
                font-size: 10px;
                font-weight: 700;
                color: #0f172a;
            }

            body.employee-dashboard-page .recent-ticket-status {
                display: none;
            }

            body.employee-dashboard-page .recent-ticket-category {
                grid-area: category;
                font-size: 10px;
                color: #6b7280;
                font-weight: 600;
            }

            body.employee-dashboard-page .recent-ticket-title {
                grid-area: title;
                font-size: 10px;
                color: #1f2937;
                line-height: 1.15;
            }

            body.employee-dashboard-page .recent-ticket-date {
                grid-area: date;
                font-size: 9px;
                color: #9ca3af;
                margin-top: 1px;
            }

            body.employee-dashboard-page .recent-ticket-arrow {
                display: block;
                grid-area: arrow;
                justify-self: end;
                align-self: end;
                font-size: 18px;
                font-weight: 700;
                color: #64748b;
                line-height: 1;
            }

            body.employee-dashboard-page .recent-ticket-status .status-pill {
                padding: 1px 6px;
                border-radius: 999px;
                font-size: 9px;
                font-weight: 700;
                white-space: nowrap;
            }

            body.employee-dashboard-page .recent-mobile-pagination {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                margin-top: 8px;
            }

            body.employee-dashboard-page .recent-mobile-page-btn {
                min-width: 32px;
                height: 32px;
                padding: 0 8px;
                border: 1px solid #dbe4ee;
                border-radius: 999px;
                background: #ffffff;
                color: #475569;
                font-size: 11px;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }

            body.employee-dashboard-page .recent-mobile-page-btn.is-active {
                background: #1B5E20;
                border-color: #1B5E20;
                color: #ffffff;
            }

            body.employee-dashboard-page .recent-mobile-page-btn:disabled {
                opacity: 0.45;
                cursor: default;
            }
        }
    </style>
</head>
<body class="employee-dashboard-page">

    <!-- 2ï¸âƒ£ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div id="mobileSidebar" class="mobile-sidebar" aria-hidden="true">
        <div class="mobile-sidebar-header">
            <img src="../assets/img/UPDATEDlogo.png" alt="Logo">
            <span>Leads Agri</span>
        </div>
        <a href="dashboard.php" class="active">Dashboard</a>
        <a href="request_ticket.php">Create Ticket</a>
        <a href="my_task.php">Assigned Tickets</a>
        <a href="my_tickets.php">My Submitted Tickets</a>
        <a href="feedback.php">Feedback</a>
        <a href="knowledge_base.php">Knowledge Base</a>
        <div class="mobile-sidebar-footer">
            <a href="notifications.php" class="mobile-sidebar-icon-link" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span id="mobileSidebarNotifBadge" class="mobile-sidebar-badge"></span>
            </a>
            <div class="mobile-sidebar-user">
                <button type="button" id="mobileSidebarUserBtn" class="mobile-sidebar-user-btn" aria-label="Account menu">
                    <i class="fas fa-user"></i>
                    <i class="fas fa-chevron-down" style="font-size: 11px;"></i>
                </button>
                <div id="mobileSidebarUserMenu" class="mobile-sidebar-user-menu">
                    <a href="my_profile.php">My Profile</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </div>

    <div id="mobileSidebarOverlay" class="mobile-sidebar-overlay" aria-hidden="true"></div>
    <?php if ($showFeedbackSuccessModal): ?>
    <div
        id="feedbackModalOverlay"
        class="feedback-modal-overlay is-visible"
        aria-hidden="false"
    >
        <div class="feedback-modal-dialog feedback-modal-dialog-success" role="dialog" aria-modal="true" aria-labelledby="feedbackModalTitle">
            <div class="feedback-modal-header">
                <div class="feedback-modal-success-icon" aria-hidden="true">&#10003;</div>
                <h2 id="feedbackModalTitle" class="feedback-modal-title">Feedback Submitted</h2>
                <p class="feedback-modal-subtitle">Your feedback has been submitted.<br>Thank you for sharing your support experience.</p>
            </div>
            <div class="feedback-modal-body">
                <div class="feedback-actions">
                    <button type="button" class="feedback-submit-btn" id="feedbackModalDismissBtn">Done</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">

                        <!-- 3ï¸âƒ£ HERO SECTION -->
            <div class="hero-section">
                <div class="hero-copy">
                    <h1 class="hero-title">Welcome back, <?= htmlspecialchars($_SESSION['name']); ?></h1>
                    <div class="hero-dept">
                        <?php
                            $heroDepartment = trim((string) ($_SESSION['department'] ?? ''));
                            $heroCompanyLabel = ticket_company_display_name((string) $company);
                        ?>
                        <?php if ($heroDepartment !== ''): ?>
                            <?= htmlspecialchars($heroDepartment); ?> Department
                            <?php if ($heroCompanyLabel !== ''): ?>
                                <span class="company-text">&bull; <?= htmlspecialchars($heroCompanyLabel); ?></span>
                            <?php endif; ?>
                        <?php elseif ($heroCompanyLabel !== ''): ?>
                            <?= htmlspecialchars($heroCompanyLabel); ?>
                        <?php endif; ?>
                    </div>
                    <p class="hero-subtitle">Here's an overview of your helpdesk activity.</p>
                </div>
                <a href="request_ticket.php" class="hero-action">
                    <i class="fas fa-plus-circle" aria-hidden="true"></i>
                    <span>Create Ticket</span>
                </a>
            </div>

            <!-- 4ï¸âƒ£ STATISTICS CARDS -->
            <div class="cards-panel">
                <div class="card-filter-row">
                    <label class="card-filter-label" for="dashboardCardFilter">Show cards:</label>
                    <div class="card-filter-dropdown">
                        <button type="button" class="card-filter-trigger" id="dashboardCardFilter" aria-haspopup="true" aria-expanded="false">
                            <span class="card-filter-trigger-label">
                                <i class="fas fa-file-lines card-filter-trigger-icon" aria-hidden="true"></i>
                                <span id="dashboardCardFilterText">My Submitted Tickets</span>
                            </span>
                            <i class="fas fa-chevron-down card-filter-trigger-caret" aria-hidden="true"></i>
                        </button>
                        <div class="card-filter-menu" id="dashboardCardFilterMenu" hidden>
                            <button type="button" class="card-filter-option is-active" data-card-filter-value="submitted" data-card-filter-label="My Submitted Tickets" data-card-filter-icon="fa-file-lines">
                                <span class="card-filter-option-label">
                                    <i class="fas fa-file-lines card-filter-option-icon" aria-hidden="true"></i>
                                    <span>My Submitted Tickets</span>
                                </span>
                                <i class="fas fa-check card-filter-option-check" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="card-filter-option" data-card-filter-value="assigned" data-card-filter-label="Assigned Tickets" data-card-filter-icon="fa-user-check">
                                <span class="card-filter-option-label">
                                    <i class="fas fa-user-check card-filter-option-icon" aria-hidden="true"></i>
                                    <span>Assigned Tickets</span>
                                </span>
                                <i class="fas fa-check card-filter-option-check" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <?php foreach ($dashboardStatSets as $setKey => $stats): ?>
                    <div class="stats-grid" data-card-set="<?= htmlspecialchars($setKey, ENT_QUOTES, 'UTF-8') ?>" <?= $setKey === 'submitted' ? '' : 'hidden' ?>>
                        <?php foreach ($stats as $stat): ?>
                            <a class="stat-card <?= htmlspecialchars($stat['variant'], ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($stat['href'], ENT_QUOTES, 'UTF-8') ?>" aria-label="View <?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?> tickets">
                                <div class="stat-main">
                                    <div class="stat-icon">
                                        <i class="fas <?= htmlspecialchars($stat['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                    </div>
                                    <div class="stat-copy">
                                        <div class="stat-label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="stat-value"><?= (int) $stat['value'] ?></div>
                                    </div>
                                </div>
                                <div class="stat-subtext"><?= htmlspecialchars($stat['subtitle'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="stat-action">View tickets <i class="fas fa-arrow-right" aria-hidden="true"></i></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 5ï¸âƒ£ RECENT TICKETS SECTION -->
            <div class="dashboard-ticket-grid">
                <section class="dashboard-ticket-panel" aria-labelledby="receivedTicketsTitle">
                    <h2 id="receivedTicketsTitle" class="dashboard-ticket-title">Assigned Tickets</h2>
                    <table class="dashboard-ticket-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Requested By</th>
                                <th>From</th>
                                <th>Status</th>
                                <th>SLA</th>
                                <th>Date Created</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($receivedTickets) > 0): ?>
                                <?php foreach ($receivedTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <?php $requester = dashboard_requester_info($row); ?>
                                    <tr class="ticket-row received-ticket-row" data-id="<?= (int) $row['id']; ?>">
                                        <td class="dashboard-ticket-id">#<?= str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td class="dashboard-ticket-category"><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-requester">
                                            <strong><?= htmlspecialchars($requester['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if ($requester['email'] !== ''): ?>
                                                <small><?= htmlspecialchars($requester['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="dashboard-ticket-department"><?= htmlspecialchars(dashboard_source_label($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-ticket-sla"><?= dashboard_sla_badge_html((string) ($row['created_at'] ?? ''), $status, (string) ($row['priority'] ?? '')); ?></td>
                                        <td class="dashboard-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="dashboard-ticket-empty">No received tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="dashboard-ticket-panel" aria-labelledby="raisedTicketsTitle">
                    <h2 id="raisedTicketsTitle" class="dashboard-ticket-title">My Submitted Tickets</h2>
                    <table class="dashboard-ticket-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Requested By</th>
                                <th>From</th>
                                <th>Status</th>
                                <th>SLA</th>
                                <th>Date Created</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($raisedTickets) > 0): ?>
                                <?php foreach ($raisedTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <?php $requester = dashboard_requester_info($row); ?>
                                    <tr class="ticket-row raised-ticket-row" data-id="<?= (int) $row['id']; ?>">
                                        <td class="dashboard-ticket-id">#<?= str_pad((string) (int) $row['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td class="dashboard-ticket-category"><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-requester">
                                            <strong><?= htmlspecialchars($requester['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if ($requester['email'] !== ''): ?>
                                                <small><?= htmlspecialchars($requester['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="dashboard-ticket-department"><?= htmlspecialchars(dashboard_source_label($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-ticket-sla"><?= dashboard_sla_badge_html((string) ($row['created_at'] ?? ''), $status, (string) ($row['priority'] ?? '')); ?></td>
                                        <td class="dashboard-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="dashboard-ticket-empty">No raised tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>

            <div class="recent-section" style="display:none;">
                <div class="section-header">
                    <h2 class="section-title">Recent Tickets</h2>
                </div>

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Reported Concern</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent->num_rows > 0): ?>
                                <?php while($row = $recent->fetch_assoc()) { ?>
                                <tr class="ticket-row" data-id="<?= (int) $row['id']; ?>" style="cursor:pointer;">
                                    <td class="recent-ticket-id" data-label="ID">#<?= $row['id']; ?></td>
                                    <td class="recent-ticket-category" data-label="Category"><?= htmlspecialchars($row['category']); ?></td>
                                    <td class="recent-ticket-title" data-label="Reported Concern"><?= htmlspecialchars($row['subject']); ?></td>
                                    <td class="recent-ticket-status" data-label="Status">
                                        <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $row['status'])); ?>">
                                            <?= htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>

                                    <td class="recent-ticket-date" data-label="Date"><?= date("M d, Y", strtotime($row['created_at'])); ?></td>
                                    <td class="recent-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                </tr>
                                <?php } ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; color: #94a3b8; padding: 30px;">
                                        No recent tickets found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="recentMobilePagination" class="recent-mobile-pagination" aria-label="Recent tickets pagination"></div>
            </div>

        </div>
    </div>

    <!-- JS Script -->
    <script src="../js/employee-dashboard.js"></script>
    <script>
    (function () {
        var feedbackModal = document.getElementById('feedbackModalOverlay');
        var closeBtn = document.getElementById('feedbackModalCloseBtn');
        var dismissBtn = document.getElementById('feedbackModalDismissBtn');
        if (feedbackModal) {
            function closeFeedbackModal() {
                feedbackModal.classList.remove('is-visible');
                feedbackModal.setAttribute('aria-hidden', 'true');
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', closeFeedbackModal);
            }
            if (dismissBtn) {
                dismissBtn.addEventListener('click', closeFeedbackModal);
            }
            feedbackModal.addEventListener('click', function (event) {
                if (event.target === feedbackModal) {
                    closeFeedbackModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && feedbackModal.classList.contains('is-visible')) {
                    closeFeedbackModal();
                }
            });
        }
    })();

    (function () {
        const menuBtn = document.getElementById('navbarToggler');
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('mobileSidebarOverlay');
        const mobileUserBtn = document.getElementById('mobileSidebarUserBtn');
        const mobileUserMenu = document.getElementById('mobileSidebarUserMenu');
        const desktopNotifBadge = document.getElementById('notifBadge');
        const mobileNotifBadge = document.getElementById('mobileSidebarNotifBadge');

        function closeSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            if (mobileUserMenu) mobileUserMenu.classList.remove('show');
            sidebar.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function syncMobileNotifBadge() {
            if (!desktopNotifBadge || !mobileNotifBadge) return;
            const desktopText = (desktopNotifBadge.textContent || '').trim();
            const desktopVisible = desktopNotifBadge.style.display !== 'none' && desktopText !== '';
            mobileNotifBadge.textContent = desktopText;
            mobileNotifBadge.style.display = desktopVisible ? 'inline-flex' : 'none';
        }

        if (menuBtn && sidebar && overlay) {
            menuBtn.addEventListener('click', function (event) {
                if (window.innerWidth > 768) return;
                event.preventDefault();
                event.stopPropagation();
                const shouldOpen = !sidebar.classList.contains('active');
                sidebar.classList.toggle('active', shouldOpen);
                overlay.classList.toggle('active', shouldOpen);
                document.body.classList.toggle('sidebar-open', shouldOpen);
                sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
                overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
            });

            overlay.addEventListener('click', function () {
                if (window.innerWidth > 768) return;
                closeSidebar();
            });

            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth > 768) return;
                    closeSidebar();
                });
            });

            if (mobileUserBtn && mobileUserMenu) {
                mobileUserBtn.addEventListener('click', function (event) {
                    if (window.innerWidth > 768) return;
                    event.stopPropagation();
                    mobileUserMenu.classList.toggle('show');
                });

                document.addEventListener('click', function (event) {
                    if (window.innerWidth > 768) return;
                    if (!mobileUserMenu.contains(event.target) && !mobileUserBtn.contains(event.target)) {
                        mobileUserMenu.classList.remove('show');
                    }
                });
            }

            syncMobileNotifBadge();
            if (desktopNotifBadge && typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver(syncMobileNotifBadge);
                observer.observe(desktopNotifBadge, { attributes: true, childList: true, subtree: true });
            }
        }
    })();

    (function () {
        const tbody = document.querySelector('.recent-section table tbody');
        const pagination = document.getElementById('recentMobilePagination');
        if (!tbody || !pagination) return;

        const rows = Array.from(tbody.querySelectorAll('tr.ticket-row'));
        if (!rows.length) {
            pagination.style.display = 'none';
            return;
        }

        const perPageMobile = 4;
        let currentPage = 1;

        function renderPagination(totalPages) {
            if (window.innerWidth > 768 || totalPages <= 1) {
                pagination.innerHTML = '';
                pagination.style.display = 'none';
                rows.forEach(function (row) { row.style.display = ''; });
                return;
            }

            pagination.style.display = 'flex';
            pagination.innerHTML = '';

            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.className = 'recent-mobile-page-btn';
            prevBtn.textContent = '<';
            prevBtn.disabled = currentPage === 1;
            prevBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage--;
                    updateRecentCards();
                }
            });
            pagination.appendChild(prevBtn);

            for (let page = 1; page <= totalPages; page++) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'recent-mobile-page-btn' + (page === currentPage ? ' is-active' : '');
                btn.textContent = String(page);
                btn.addEventListener('click', function () {
                    currentPage = page;
                    updateRecentCards();
                });
                pagination.appendChild(btn);
            }

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.className = 'recent-mobile-page-btn';
            nextBtn.textContent = '>';
            nextBtn.disabled = currentPage === totalPages;
            nextBtn.addEventListener('click', function () {
                if (currentPage < totalPages) {
                    currentPage++;
                    updateRecentCards();
                }
            });
            pagination.appendChild(nextBtn);
        }

        function updateRecentCards() {
            const isMobile = window.innerWidth <= 768;
            const totalPages = Math.max(1, Math.ceil(rows.length / perPageMobile));
            if (currentPage > totalPages) currentPage = totalPages;

            rows.forEach(function (row, index) {
                if (!isMobile) {
                    row.style.display = '';
                    return;
                }
                const start = (currentPage - 1) * perPageMobile;
                const end = start + perPageMobile;
                row.style.display = index >= start && index < end ? '' : 'none';
            });

            renderPagination(totalPages);
        }

        window.addEventListener('resize', updateRecentCards);
        updateRecentCards();
    })();

    document.querySelectorAll('.raised-ticket-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (!id) return;
            window.location.href = 'my_tickets.php?ticket_id=' + encodeURIComponent(id);
        });
    });

    document.querySelectorAll('.received-ticket-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (!id) return;
            window.location.href = 'my_task.php?ticket_id=' + encodeURIComponent(id);
        });
    });

    (function () {
        var filter = document.getElementById('dashboardCardFilter');
        var filterText = document.getElementById('dashboardCardFilterText');
        var filterMenu = document.getElementById('dashboardCardFilterMenu');
        var filterIcon = filter ? filter.querySelector('.card-filter-trigger-icon') : null;
        var options = document.querySelectorAll('[data-card-filter-value]');
        var grids = document.querySelectorAll('[data-card-set]');
        if (!filter || !filterMenu || !filterText || !filterIcon || !grids.length || !options.length) return;

        function setActiveCardSet(value, label, iconClass) {
            grids.forEach(function (grid) {
                grid.hidden = grid.getAttribute('data-card-set') !== value;
            });
            options.forEach(function (option) {
                option.classList.toggle('is-active', option.getAttribute('data-card-filter-value') === value);
            });
            filterText.textContent = label;
            filterIcon.className = 'fas ' + iconClass + ' card-filter-trigger-icon';
            filter.setAttribute('data-card-filter-value', value);
        }

        function closeMenu() {
            filterMenu.hidden = true;
            filter.setAttribute('aria-expanded', 'false');
        }

        filter.addEventListener('click', function () {
            var shouldOpen = filterMenu.hidden;
            filterMenu.hidden = !shouldOpen;
            filter.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        });

        options.forEach(function (option) {
            option.addEventListener('click', function () {
                setActiveCardSet(
                    option.getAttribute('data-card-filter-value') || 'submitted',
                    option.getAttribute('data-card-filter-label') || 'My Submitted Tickets',
                    option.getAttribute('data-card-filter-icon') || 'fa-file-lines'
                );
                closeMenu();
            });
        });

        document.addEventListener('click', function (event) {
            if (!filterMenu.hidden && !event.target.closest('.card-filter-dropdown')) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !filterMenu.hidden) {
                closeMenu();
            }
        });
    })();

    </script>

   

</body>
</html>
