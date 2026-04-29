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


/* Recent Tickets (created by this employee) */
$recentStmt = $conn->prepare("
    SELECT id, subject, category, status, created_at
    FROM employee_tickets
    WHERE user_id = ?
      AND COALESCE(NULLIF(status,''),'') <> 'Trash'
    ORDER BY created_at DESC
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
            if (count($receivedTickets) >= 5) {
                break;
            }
        }
    }
    $receivedStmt->close();
}

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
            max-width: 1160px;
            padding: 34px 20px 54px;
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

        body.employee-dashboard-page .stat-card {
            min-height: 142px;
            padding: 24px 24px 22px;
            border: 1px solid #e5e7eb;
            border-top: 3px solid #f4c430;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
        }

        body.employee-dashboard-page .stat-icon {
            width: 38px;
            height: 38px;
            margin-bottom: 18px;
            border-radius: 10px;
            font-size: 16px;
        }

        body.employee-dashboard-page .stat-card.total .stat-icon,
        body.employee-dashboard-page .stat-card.progress .stat-icon,
        body.employee-dashboard-page .stat-card.resolved .stat-icon {
            background: #dcfce7;
            color: #11651f;
        }

        body.employee-dashboard-page .stat-card.closed .stat-icon {
            background: #e0e7ff;
            color: #1e40af;
        }

        body.employee-dashboard-page .stat-card.open .stat-icon {
            background: #fef9c3;
            color: #d97706;
        }

        body.employee-dashboard-page .stat-label {
            margin-bottom: 8px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }

        body.employee-dashboard-page .stat-value {
            color: #111827;
            font-size: 30px;
            line-height: 1;
            font-weight: 500;
        }

        body.employee-dashboard-page .dashboard-ticket-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
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
            padding: 14px 12px;
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
            padding: 14px 12px;
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
            width: 70px;
            color: #334155;
            font-weight: 500;
        }

        body.employee-dashboard-page .dashboard-ticket-category {
            max-width: 210px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
        }

        body.employee-dashboard-page .dashboard-ticket-table .status-pill {
            font-weight: 400;
        }

        body.employee-dashboard-page .dashboard-ticket-date {
            width: 116px;
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
                padding: 22px 14px 36px;
            }

            body.employee-dashboard-page .hero-title {
                font-size: 24px;
            }

            body.employee-dashboard-page .stats-grid,
            body.employee-dashboard-page .dashboard-ticket-grid {
                grid-template-columns: 1fr;
            }

            body.employee-dashboard-page .stat-card {
                min-height: 118px;
            }

            body.employee-dashboard-page .dashboard-ticket-panel {
                padding: 18px;
                overflow-x: auto;
            }

            body.employee-dashboard-page .dashboard-ticket-table {
                min-width: 560px;
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
        <div class="feedback-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="feedbackModalTitle">
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
                        <?= htmlspecialchars($_SESSION['department']); ?> Department
                        <?php if (!empty($company)): ?>
                            <span class="company-text">&bull; <?= htmlspecialchars($company); ?></span>
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
            <div class="stats-grid">
                <!-- Total Tickets -->
                <div class="stat-card total">
                    <div class="stat-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="stat-label">Total Tickets</div>
                    <div class="stat-value"><?= $total ?></div>
                </div>

                <!-- Open -->
                <div class="stat-card open">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-label">Open</div>
                    <div class="stat-value"><?= $open ?></div>
                </div>

                <!-- In Progress -->
                <div class="stat-card progress">
                    <div class="stat-icon">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-value"><?= $progress ?></div>
                </div>

                <!-- Resolved -->
                <div class="stat-card resolved">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Resolved</div>
                    <div class="stat-value"><?= $resolved ?></div>
                </div>

                <!-- Closed -->
                <div class="stat-card closed">
                    <div class="stat-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div class="stat-label">Closed</div>
                    <div class="stat-value"><?= $closed ?></div>
                </div>

            </div>

            <!-- 5ï¸âƒ£ RECENT TICKETS SECTION -->
            <div class="dashboard-ticket-grid">
                <section class="dashboard-ticket-panel" aria-labelledby="raisedTicketsTitle">
                    <h2 id="raisedTicketsTitle" class="dashboard-ticket-title">Raised Tickets</h2>
                    <table class="dashboard-ticket-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($raisedTickets) > 0): ?>
                                <?php foreach ($raisedTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <tr class="ticket-row raised-ticket-row" data-id="<?= (int) $row['id']; ?>">
                                        <td class="dashboard-ticket-id">#<?= (int) $row['id']; ?></td>
                                        <td class="dashboard-ticket-category"><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="dashboard-ticket-empty">No raised tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="dashboard-ticket-panel" aria-labelledby="receivedTicketsTitle">
                    <h2 id="receivedTicketsTitle" class="dashboard-ticket-title">Received Tickets</h2>
                    <table class="dashboard-ticket-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th aria-hidden="true"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($receivedTickets) > 0): ?>
                                <?php foreach ($receivedTickets as $row): ?>
                                    <?php $status = (string) ($row['status'] ?? ''); ?>
                                    <tr class="ticket-row received-ticket-row" data-id="<?= (int) $row['id']; ?>">
                                        <td class="dashboard-ticket-id">#<?= (int) $row['id']; ?></td>
                                        <td class="dashboard-ticket-category"><?= htmlspecialchars(dashboard_ticket_category($row), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="status-pill status-<?= htmlspecialchars(dashboard_status_class($status), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="dashboard-ticket-date"><?= htmlspecialchars(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="dashboard-ticket-arrow" aria-hidden="true">&rsaquo;</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="dashboard-ticket-empty">No received tickets found.</td>
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

    </script>

   

</body>
</html>
