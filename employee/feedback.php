<?php
require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';

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
        COALESCE(
            NULLIF(TRIM(ticket_creator.company), ''),
            NULLIF(TRIM(feedback_requestor.company), ''),
            NULLIF(TRIM(et.company), ''),
            NULLIF(TRIM(et.assigned_company), '')
        ) AS creator_company,
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

function feedback_department_label(array $row): string
{
    $department = trim((string) ($row['creator_department'] ?? ''));
    if ($department !== '') {
        return $department;
    }

    $companyKey = feedback_company_key($row);
    if ($companyKey !== '' && function_exists('ticket_company_requires_department') && !ticket_company_requires_department($companyKey)) {
        return feedback_company_label($row);
    }

    return 'Department';
}

function feedback_company_label(array $row): string
{
    $company = trim((string) ($row['creator_company'] ?? ''));
    if (function_exists('ticket_company_display_name')) {
        $display = trim((string) ticket_company_display_name($company));
        if ($display !== '') {
            return $display;
        }
    }
    return $company !== '' ? $company : 'Company';
}

function feedback_company_key(array $row): string
{
    $company = trim((string) ($row['creator_company'] ?? ''));
    if ($company === '') {
        return '';
    }
    return function_exists('ticket_normalize_company') ? ticket_normalize_company($company) : strtolower($company);
}

function feedback_created_at_datetime(array $row): ?DateTimeImmutable
{
    $createdAt = trim((string) ($row['created_at'] ?? ''));
    if ($createdAt === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($createdAt, new DateTimeZone('Asia/Manila'));
    } catch (Throwable $e) {
        return null;
    }
}

function feedback_matches_time_filter(array $row, string $timeFilter): bool
{
    if ($timeFilter === 'all') {
        return true;
    }

    $createdAt = feedback_created_at_datetime($row);
    if (!$createdAt) {
        return false;
    }

    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $tomorrow = $today->modify('+1 day');

    if ($timeFilter === '7_days') {
        $start = $today->modify('-6 days');
        return $createdAt >= $start && $createdAt < $tomorrow;
    }

    if ($timeFilter === '30_days') {
        $start = $today->modify('-29 days');
        return $createdAt >= $start && $createdAt < $tomorrow;
    }

    if ($timeFilter === 'this_month') {
        $start = $today->modify('first day of this month');
        return $createdAt >= $start && $createdAt < $tomorrow;
    }

    return true;
}

function feedback_department_options_from_map(array $departmentMap, string $companyFilter = ''): array
{
    $out = [];
    if ($companyFilter !== '' && function_exists('ticket_company_allowed_groups')) {
        foreach (ticket_company_allowed_groups($companyFilter) as $departmentValue) {
            $departmentValue = trim((string) $departmentValue);
            if ($departmentValue !== '') {
                $out[$departmentValue] = $departmentValue;
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    $source = [];
    if ($companyFilter !== '' && isset($departmentMap[$companyFilter])) {
        $source[$companyFilter] = $departmentMap[$companyFilter];
    } else {
        $source = $departmentMap;
    }

    foreach ($source as $departmentOptions) {
        foreach ((array) $departmentOptions as $departmentOption) {
            $departmentValue = is_array($departmentOption)
                ? trim((string) ($departmentOption['value'] ?? ($departmentOption['label'] ?? '')))
                : trim((string) $departmentOption);
            if ($departmentValue !== '') {
                $out[$departmentValue] = $departmentValue;
            }
        }
    }

    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

$feedbackAllRows = $feedbackRows;
$hasAnyFeedbackRows = count($feedbackAllRows) > 0;

function feedback_page_url(int $page): string
{
    return 'feedback.php?page=' . max(1, $page);
}

function render_feedback_pagination(int $page, int $totalPages, int $showingFrom, int $showingTo, int $totalRecords): string
{
    if ($totalRecords <= 0) {
        return '';
    }

    $html = '<div class="pagination-glass">';
    $html .= '<div class="pagination-summary">Showing ' . number_format($showingFrom) . ' - ' . number_format($showingTo) . ' of ' . number_format($totalRecords) . ' entries</div>';

    if ($totalPages > 1) {
        $html .= '<a href="' . htmlspecialchars(feedback_page_url(max(1, $page - 1)), ENT_QUOTES, 'UTF-8') . '" class="page-btn prev' . ($page <= 1 ? ' disabled' : '') . '">&lsaquo; Previous</a>';
        $html .= '<div class="page-numbers">';

        $window = 2;
        $startPage = max(1, $page - $window);
        $endPage = min($totalPages, $page + $window);

        if ($startPage > 1) {
            $html .= '<a href="' . htmlspecialchars(feedback_page_url(1), ENT_QUOTES, 'UTF-8') . '" class="page-btn' . ($page === 1 ? ' active' : '') . '">1</a>';
            if ($startPage > 2) {
                $html .= '<span class="page-ellipsis">...</span>';
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $html .= '<a href="' . htmlspecialchars(feedback_page_url($i), ENT_QUOTES, 'UTF-8') . '" class="page-btn' . ($i === $page ? ' active' : '') . '">' . $i . '</a>';
        }

        if ($endPage < $totalPages) {
            if ($endPage < ($totalPages - 1)) {
                $html .= '<span class="page-ellipsis">...</span>';
            }
            $html .= '<a href="' . htmlspecialchars(feedback_page_url($totalPages), ENT_QUOTES, 'UTF-8') . '" class="page-btn' . ($page === $totalPages ? ' active' : '') . '">' . $totalPages . '</a>';
        }

        $html .= '</div>';
        $html .= '<a href="' . htmlspecialchars(feedback_page_url(min($totalPages, $page + 1)), ENT_QUOTES, 'UTF-8') . '" class="page-btn next' . ($page >= $totalPages ? ' disabled' : '') . '">Next &rsaquo;</a>';
    }

    $html .= '<span class="feedback-mobile-page-counter" aria-label="Page ' . $page . ' of ' . $totalPages . '">' . $page . ' of ' . $totalPages . '</span>';
    $html .= '</div>';

    return $html;
}

$feedbackTotal = count($feedbackRows);
$feedbackPerPage = 5;
$feedbackTotalPages = max(1, (int) ceil($feedbackTotal / $feedbackPerPage));
$feedbackPage = max(1, (int) ($_GET['page'] ?? 1));
if ($feedbackPage > $feedbackTotalPages) {
    $feedbackPage = $feedbackTotalPages;
}
$feedbackOffset = ($feedbackPage - 1) * $feedbackPerPage;
$feedbackPageRows = array_slice($feedbackRows, $feedbackOffset, $feedbackPerPage);
$feedbackStart = $feedbackTotal > 0 ? $feedbackOffset + 1 : 0;
$feedbackEnd = min($feedbackTotal, $feedbackOffset + count($feedbackPageRows));
$ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$ratingSum = 0;
foreach ($feedbackRows as $feedbackRow) {
    $rating = max(1, min(5, (int) ($feedbackRow['rating'] ?? 0)));
    $ratingCounts[$rating]++;
    $ratingSum += $rating;
}
$averageRating = $feedbackTotal > 0 ? round($ratingSum / $feedbackTotal, 1) : 0;
$excellentFeedbackTotal = $ratingCounts[5];
$adviceFeedbackTotal = max(0, $feedbackTotal - $excellentFeedbackTotal);
$excellentFeedbackPercent = $feedbackTotal > 0 ? (int) round(($excellentFeedbackTotal / $feedbackTotal) * 100) : 0;
$adviceFeedbackPercent = $feedbackTotal > 0 ? (int) round(($adviceFeedbackTotal / $feedbackTotal) * 100) : 0;
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
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback | Leads DeskMetamorph</title>
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
            max-width: 1420px;
            padding-top: 4px;
        }

        body.employee-feedback-page .feedback-page-shell {
            display: grid;
            gap: 16px;
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
            margin: 0;
            max-width: 760px;
            font-size: 16px;
            line-height: 1.5;
            color: var(--text-gray);
        }

        body.employee-feedback-page .feedback-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        body.employee-feedback-page .feedback-card {
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 9px;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.045);
            padding: 22px 26px;
        }

        body.employee-feedback-page .feedback-average-card {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            gap: 20px;
        }

        body.employee-feedback-page .feedback-summary-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: #1B5E20;
            font-size: 27px;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-card-title {
            margin: 0 0 10px;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-score-line {
            display: flex;
            align-items: baseline;
            justify-content: flex-start;
            gap: 9px;
            color: #0b2540;
        }

        body.employee-feedback-page .feedback-score-line strong {
            font-size: 38px;
            line-height: 1;
            letter-spacing: 0;
            color: #0b2540;
            font-weight: 600;
        }

        body.employee-feedback-page .feedback-score-line span {
            font-size: 24px;
            color: #64748b;
            font-weight: 400;
        }

        body.employee-feedback-page .feedback-score-note {
            margin: 8px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        body.employee-feedback-page .feedback-section {
            background: #ffffff;
            border: 1px solid #eef2f7;
            border-radius: 9px;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.045);
            padding: 22px 24px 16px;
        }

        body.employee-feedback-page .feedback-section-header {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 16px;
            margin-bottom: 16px;
        }

        body.employee-feedback-page .feedback-section-icon {
            width: 48px;
            height: 48px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: #1B5E20;
            font-size: 18px;
        }

        body.employee-feedback-page .feedback-section-title {
            margin: 0 0 4px;
            font-size: 18px;
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

        body.employee-feedback-page .feedback-swipe-guide {
            display: none;
        }

        body.employee-feedback-page .feedback-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        body.employee-feedback-page .feedback-table th {
            background: #fbfcfd;
            color: #1B5E20;
            font-family: 'Segoe UI', sans-serif;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            padding: 13px 16px;
            text-align: left;
            border-bottom: 1px solid #1B5E20;
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

        body.employee-feedback-page .feedback-ticket-row td {
            cursor: pointer;
        }

        body.employee-feedback-page .feedback-ticket-row:hover td,
        body.employee-feedback-page .feedback-ticket-row:focus-within td {
            background: #f8fbf9;
        }

        body.employee-feedback-page .feedback-ticket-id {
            font-weight: 500;
            color: #0f172a;
            white-space: nowrap;
            font-size: 15px;
        }

        body.employee-feedback-page .feedback-ticket-link {
            color: inherit;
            text-decoration: none;
            display: inline-block;
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
            min-height: 30px;
            padding: 5px 11px 5px 8px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            font-weight: 400;
            color: #475569;
            white-space: nowrap;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-category-pill i {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ecfdf5;
            color: #1B5E20;
            font-size: 11px;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-person {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-width: 170px;
            font-weight: 400;
            color: #334155;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e0f2fe;
            color: #0284c7;
            font-size: 11px;
            font-weight: 400;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-department {
            min-width: 150px;
            color: #475569;
            font-weight: 400;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-rating {
            min-width: 220px;
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

        body.employee-feedback-page .feedback-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-height: 30px;
            padding: 5px 11px;
            border-radius: 4px;
            background: #f0fdf4;
            color: #1B5E20;
            font-size: 14px;
            font-weight: 500;
        }

        body.employee-feedback-page .feedback-advice-box {
            max-width: 330px;
            padding: 10px 12px;
            border-radius: 4px;
            background: #fff7ed;
            color: #0f172a;
            line-height: 1.4;
            font-size: 14px;
        }

        body.employee-feedback-page .feedback-advice-box i {
            color: #f59e0b;
            margin-right: 8px;
        }

        body.employee-feedback-page .feedback-advice-box strong {
            display: inline;
            font-weight: 700;
        }

        body.employee-feedback-page .feedback-advice-text {
            display: block;
            margin: 3px 0 0 25px;
            color: #334155;
            font-size: 13px;
        }

        body.employee-feedback-page .feedback-comment.is-empty {
            color: #94a3b8;
            font-style: italic;
        }

        body.employee-feedback-page .feedback-date {
            white-space: nowrap;
            color: #64748b;
            font-weight: 400;
            font-size: 14px;
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
            margin: 0 0 6px;
            font-size: 24px;
            color: #0f172a;
        }

        body.employee-feedback-page .feedback-empty p {
            margin: 0;
            font-size: 14px;
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
            width: 28px;
            height: 28px;
            border: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #64748b;
            text-decoration: none;
        }

        body.employee-feedback-page .feedback-page-button.is-disabled {
            color: #cbd5e1;
            pointer-events: none;
        }

        body.employee-feedback-page .feedback-page-current {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1B5E20;
            color: #ffffff;
            font-weight: 400;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page {
                overflow-x: hidden;
            }

            body.employee-feedback-page .dashboard-container {
                overflow-x: hidden;
            }

            body.employee-feedback-page .content-wrapper {
                width: 100%;
                max-width: 100%;
                padding: 16px 14px 92px;
                overflow-x: hidden;
                box-sizing: border-box;
            }

            body.employee-feedback-page .feedback-page-shell {
                gap: 14px;
            }

            body.employee-feedback-page .feedback-hero {
                padding: 0 2px 4px;
                border-radius: 0;
            }

            body.employee-feedback-page .feedback-hero h1 {
                margin-bottom: 8px;
                font-size: 20px;
                line-height: 1.16;
            }

            body.employee-feedback-page .feedback-hero p {
                max-width: 100%;
                margin-top: 4px;
                font-size: 14px;
                line-height: 1.4;
            }

            body.employee-feedback-page .feedback-summary-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            body.employee-feedback-page .feedback-card {
                border-radius: 16px;
                padding: 16px;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
            }

            body.employee-feedback-page .feedback-average-card {
                grid-template-columns: 1fr;
                min-height: 0;
                justify-items: start;
                text-align: left;
            }

            body.employee-feedback-page .feedback-card-title {
                margin-bottom: 10px;
                font-size: 18px;
                font-weight: 700;
            }

            body.employee-feedback-page .feedback-score-line {
                justify-content: flex-start;
            }

            body.employee-feedback-page .feedback-score-line strong {
                font-size: 42px;
            }

            body.employee-feedback-page .feedback-score-line span {
                font-size: 18px;
            }

            body.employee-feedback-page .feedback-breakdown-card {
                grid-template-columns: 1fr;
                gap: 14px;
                min-height: 0;
                justify-items: stretch;
            }

            body.employee-feedback-page .feedback-breakdown-list {
                gap: 9px;
            }

            body.employee-feedback-page .feedback-breakdown-row {
                grid-template-columns: 44px minmax(0, 1fr) 42px;
                gap: 9px;
                font-size: 12px;
            }

            body.employee-feedback-page .feedback-breakdown-track {
                height: 9px;
            }

            body.employee-feedback-page .feedback-donut {
                width: 118px;
                height: 118px;
                justify-self: center;
            }

            body.employee-feedback-page .feedback-donut::before {
                inset: 15px;
            }

            body.employee-feedback-page .feedback-donut-center strong {
                font-size: 24px;
            }

            body.employee-feedback-page .feedback-section {
                width: 100%;
                padding: 14px;
                border-radius: 16px;
                overflow: hidden;
                box-sizing: border-box;
            }

            body.employee-feedback-page .feedback-section-header {
                align-items: flex-start;
                margin-bottom: 12px;
                gap: 9px;
            }

            body.employee-feedback-page .feedback-section-icon {
                width: 38px;
                height: 38px;
                border-radius: 12px;
                font-size: 14px;
            }

            body.employee-feedback-page .feedback-section-title {
                font-size: 18px;
                line-height: 1.2;
            }

            body.employee-feedback-page .feedback-section-subtitle {
                font-size: 12px;
                line-height: 1.4;
            }

            body.employee-feedback-page .feedback-table-wrap {
                overflow: visible;
            }

            body.employee-feedback-page .feedback-table,
            body.employee-feedback-page .feedback-table tbody,
            body.employee-feedback-page .feedback-table tr,
            body.employee-feedback-page .feedback-table td {
                display: block;
                width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            body.employee-feedback-page .feedback-table {
                border-collapse: separate;
                border-spacing: 0;
            }

            body.employee-feedback-page .feedback-table thead {
                display: none;
            }

            body.employee-feedback-page .feedback-table tbody {
                display: grid;
                gap: 12px;
            }

            body.employee-feedback-page .feedback-ticket-row {
                position: relative;
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas:
                    "id date"
                    "category category"
                    "person person"
                    "department department"
                    "rating rating";
                gap: 10px 12px;
                padding: 16px;
                border: 1px solid #dfe7ef;
                border-radius: 16px;
                background: #ffffff;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.045);
            }

            body.employee-feedback-page .feedback-ticket-row:hover td,
            body.employee-feedback-page .feedback-ticket-row:focus-within td {
                background: transparent;
            }

            body.employee-feedback-page .feedback-table td {
                padding: 0;
                border: 0;
                font-size: 12px;
            }

            body.employee-feedback-page .feedback-ticket-id {
                grid-area: id;
                color: #0f172a;
                font-size: 14px;
                font-weight: 900;
            }

            body.employee-feedback-page .feedback-ticket-row td:nth-child(2) {
                grid-area: category;
            }

            body.employee-feedback-page .feedback-category-pill {
                max-width: 100%;
                width: 100%;
                min-height: 38px;
                justify-content: flex-start;
                padding: 7px 12px 7px 8px;
                border-radius: 13px;
                font-size: 14px;
                font-weight: 700;
                overflow: hidden;
            }

            body.employee-feedback-page .feedback-category-pill span,
            body.employee-feedback-page .feedback-category-pill {
                min-width: 0;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            body.employee-feedback-page .feedback-ticket-row td:nth-child(3) {
                grid-area: person;
            }

            body.employee-feedback-page .feedback-person {
                width: 100%;
                min-width: 0;
                gap: 9px;
                color: #0f172a;
                font-size: 14px;
                font-weight: 800;
            }

            body.employee-feedback-page .feedback-avatar {
                width: 36px;
                height: 36px;
                font-size: 12px;
                font-weight: 800;
            }

            body.employee-feedback-page .feedback-department {
                grid-area: department;
                min-width: 0;
                color: #475569;
                font-size: 12px;
                font-weight: 700;
                line-height: 1.35;
                white-space: normal;
            }

            body.employee-feedback-page .feedback-rating {
                grid-area: rating;
                min-width: 0;
                width: 100%;
                justify-self: stretch;
                display: block;
                align-items: center;
                gap: 6px;
                padding: 0;
                border-radius: 0;
                background: transparent;
                color: inherit;
            }

            body.employee-feedback-page .feedback-rating .feedback-stars {
                gap: 2px;
                font-size: 11px;
            }

            body.employee-feedback-page .feedback-rating-value {
                margin-left: 2px;
                color: #92400e;
                font-size: 12px;
                font-weight: 900;
                white-space: nowrap;
            }

            body.employee-feedback-page .feedback-date {
                grid-area: date;
                justify-self: end;
                color: #64748b;
                font-size: 12px;
                font-weight: 700;
                line-height: 1.35;
                white-space: normal;
                text-align: right;
            }

            body.employee-feedback-page .feedback-table-footer {
                align-items: center;
                flex-direction: row;
                justify-content: space-between;
                gap: 9px;
                padding: 14px 2px 0;
                font-size: 12px;
            }

            body.employee-feedback-page .feedback-pagination {
                gap: 6px;
            }

            body.employee-feedback-page .feedback-page-button,
            body.employee-feedback-page .feedback-page-current {
                width: 30px;
                height: 30px;
            }

        }

        /* Final feedback-page skin matching the provided mockup. Kept last so it wins over legacy page rules above. */
        body.employee-feedback-page {
            background:
                radial-gradient(circle at 91% 5%, rgba(213, 239, 214, .8) 0 150px, transparent 151px),
                radial-gradient(circle at 68% 30%, rgba(204, 234, 208, .72) 0 210px, transparent 211px),
                linear-gradient(180deg, #fbfdfb 0%, #f7faf8 48%, #ffffff 100%) !important;
            color: #10233d;
        }

        body.employee-feedback-page .dashboard-container {
            background: transparent !important;
        }

        body.employee-feedback-page .content-wrapper {
            max-width: 1510px !important;
            padding: 76px 38px 44px !important;
        }

        body.employee-feedback-page .feedback-page-shell {
            gap: 22px !important;
        }

        body.employee-feedback-page .feedback-hero {
            position: relative !important;
            min-height: 178px !important;
            display: flex !important;
            align-items: center !important;
            gap: 26px !important;
            padding: 26px 360px 18px 12px !important;
            overflow: hidden !important;
        }

        body.employee-feedback-page .feedback-hero::before {
            content: "";
            position: absolute;
            inset: -70px -20px auto auto;
            width: 520px;
            height: 260px;
            border-radius: 48% 0 0 55%;
            background: rgba(219, 240, 219, .72);
        }

        body.employee-feedback-page .feedback-hero::after {
            content: "";
            position: absolute;
            left: -35px;
            top: 88px;
            width: 150px;
            height: 110px;
            background-image: radial-gradient(#d7e7dc 1.6px, transparent 1.7px);
            background-size: 15px 15px;
            opacity: .75;
        }

        body.employee-feedback-page .feedback-hero > * {
            position: relative;
            z-index: 1;
        }

        body.employee-feedback-page .feedback-hero-icon {
            width: 78px !important;
            height: 78px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 50% !important;
            background: #dcf5df !important;
            color: #16831f !important;
            font-size: 34px !important;
            flex: 0 0 auto !important;
        }

        body.employee-feedback-page .feedback-hero h1 {
            margin: 0 0 12px !important;
            color: #0d3d1c !important;
            font-size: 35px !important;
            line-height: 1.08 !important;
            font-weight: 800 !important;
        }

        body.employee-feedback-page .feedback-hero p {
            color: #3d4e69 !important;
            font-size: 17px !important;
            max-width: 760px !important;
        }

        body.employee-feedback-page .feedback-hero-art {
            position: absolute;
            right: 120px;
            bottom: 8px;
            width: 330px;
            height: 165px;
            z-index: 1;
            pointer-events: none;
        }

        body.employee-feedback-page .feedback-bubble-main {
            position: absolute;
            left: 36px;
            top: 32px;
            width: 98px;
            height: 86px;
            border-radius: 46% 46% 46% 40%;
            background: linear-gradient(145deg, #48a543, #086b22);
            box-shadow: 0 20px 30px rgba(17, 106, 33, .22);
        }

        body.employee-feedback-page .feedback-bubble-main::before {
            content: "";
            position: absolute;
            right: 18px;
            bottom: -17px;
            border-width: 20px 0 0 22px;
            border-style: solid;
            border-color: transparent transparent transparent #086b22;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            content: "\f111  \f111  \f111";
            position: absolute;
            left: 28px;
            top: 33px;
            color: #ffffff;
            font-family: "Font Awesome 6 Free";
            font-size: 14px;
            font-weight: 900;
            letter-spacing: 8px;
        }

        body.employee-feedback-page .feedback-bubble-small {
            position: absolute;
            left: 146px;
            top: 16px;
            width: 70px;
            height: 42px;
            border-radius: 8px;
            background: #ffc400;
            box-shadow: 0 12px 22px rgba(245, 158, 11, .22);
        }

        body.employee-feedback-page .feedback-bubble-small::before {
            content: "";
            position: absolute;
            right: 15px;
            bottom: -12px;
            border-width: 14px 0 0 16px;
            border-style: solid;
            border-color: transparent transparent transparent #ffc400;
        }

        body.employee-feedback-page .feedback-bubble-small::after {
            content: "";
            position: absolute;
            left: 15px;
            top: 15px;
            width: 40px;
            height: 7px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 14px 0 rgba(255, 255, 255, .72);
        }

        body.employee-feedback-page .feedback-rating-card-art {
            position: absolute;
            right: 22px;
            bottom: 22px;
            width: 175px;
            height: 78px;
            border-radius: 9px;
            background: #ffffff;
            box-shadow: 0 16px 34px rgba(15, 23, 42, .16);
            transform: rotate(2deg);
        }

        body.employee-feedback-page .feedback-rating-card-art::before {
            content: "\f005 \f005 \f005 \f005 \f005";
            position: absolute;
            left: 25px;
            top: 25px;
            color: #ffc400;
            font-family: "Font Awesome 6 Free";
            font-size: 17px;
            font-weight: 900;
            letter-spacing: 5px;
        }

        body.employee-feedback-page .feedback-rating-card-art::after {
            content: "";
            position: absolute;
            left: 26px;
            right: 26px;
            bottom: 19px;
            height: 4px;
            border-radius: 999px;
            background: #e5ebee;
            box-shadow: 0 11px 0 #edf2f4;
        }

        body.employee-feedback-page .feedback-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 18px !important;
        }

        body.employee-feedback-page .feedback-card,
        body.employee-feedback-page .feedback-section,
        body.employee-feedback-page .feedback-bottom-callout {
            border: 1px solid #dbe6de !important;
            border-radius: 14px !important;
            background: rgba(255, 255, 255, .91) !important;
            box-shadow: 0 18px 44px rgba(19, 55, 31, .08) !important;
        }

        body.employee-feedback-page .feedback-card {
            position: relative !important;
            min-height: 148px !important;
            padding: 34px !important;
            overflow: hidden !important;
        }

        body.employee-feedback-page .feedback-card::after {
            content: "";
            position: absolute;
            right: 24px;
            bottom: 26px;
            width: 132px;
            height: 52px;
            background:
                linear-gradient(160deg, transparent 0 15%, rgba(38,181,37,.85) 16% 18%, transparent 19% 33%, rgba(38,181,37,.9) 34% 36%, transparent 37% 52%, rgba(38,181,37,.9) 53% 55%, transparent 56% 100%),
                linear-gradient(180deg, rgba(33,181,44,.12), rgba(33,181,44,.02));
            clip-path: polygon(0 92%, 18% 68%, 34% 48%, 52% 43%, 72% 23%, 100% 0, 100% 100%, 0 100%);
        }

        body.employee-feedback-page .feedback-average-card {
            gap: 22px !important;
        }

        body.employee-feedback-page .feedback-summary-icon {
            width: 70px !important;
            height: 70px !important;
            background: #dcf5df !important;
            color: #248718 !important;
            font-size: 31px !important;
        }

        body.employee-feedback-page .feedback-card-title {
            color: #10233d !important;
            font-size: 16px !important;
            font-weight: 800 !important;
        }

        body.employee-feedback-page .feedback-score-line strong {
            color: #086222 !important;
            font-size: 42px !important;
            font-weight: 800 !important;
        }

        body.employee-feedback-page .feedback-score-note {
            color: #40516b !important;
            font-size: 15px !important;
        }

        body.employee-feedback-page .feedback-section {
            padding: 26px 30px 22px !important;
        }

        body.employee-feedback-page .feedback-section-header {
            justify-content: space-between !important;
            margin-bottom: 22px !important;
        }

        body.employee-feedback-page .feedback-section-title-wrap {
            display: inline-flex;
            align-items: center;
            gap: 15px;
        }

        body.employee-feedback-page .feedback-section-title-wrap i {
            color: #16831f;
            font-size: 22px;
        }

        body.employee-feedback-page .feedback-section-title {
            color: #10233d !important;
            font-size: 20px !important;
            font-weight: 800 !important;
        }

        body.employee-feedback-page .feedback-toolbar {
            display: inline-flex;
            align-items: center;
            gap: 18px;
        }

        body.employee-feedback-page .feedback-filter-btn,
        body.employee-feedback-page .feedback-export-btn {
            min-width: 170px;
            min-height: 42px;
            border: 1px solid #d9e2e6;
            border-radius: 14px;
            background: #ffffff;
            color: #12233d;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 0 16px;
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        body.employee-feedback-page .feedback-export-btn {
            min-width: 112px;
            justify-content: center;
        }

        body.employee-feedback-page .feedback-filter-btn i:first-child,
        body.employee-feedback-page .feedback-export-btn i {
            color: #0b6b27;
        }

        body.employee-feedback-page .feedback-table-wrap {
            border: 1px solid #d9e2dd !important;
            border-radius: 14px !important;
            overflow: hidden !important;
        }

        body.employee-feedback-page .feedback-table th {
            background: linear-gradient(180deg, #f3f8f3, #eef5ef) !important;
            color: #0b6b27 !important;
            border-bottom: 1px solid #d9e2dd !important;
            padding: 18px 20px !important;
            font-size: 13px !important;
            letter-spacing: 0 !important;
        }

        body.employee-feedback-page .feedback-table th:first-child,
        body.employee-feedback-page .feedback-table th:last-child {
            border-radius: 0 !important;
        }

        body.employee-feedback-page .feedback-table td {
            padding: 22px 20px !important;
            border-bottom: 1px solid #e8eee9 !important;
            color: #10233d !important;
            font-size: 15px !important;
        }

        body.employee-feedback-page .feedback-ticket-id {
            color: #086222 !important;
            font-weight: 800 !important;
        }

        body.employee-feedback-page .feedback-category-pill {
            min-height: 34px !important;
            border-color: #dce6ee !important;
            background: #ffffff !important;
            color: #33455d !important;
            font-size: 15px !important;
        }

        body.employee-feedback-page .feedback-category-pill i {
            background: transparent !important;
            color: #16831f !important;
            font-size: 14px !important;
        }

        body.employee-feedback-page .feedback-avatar {
            width: 34px !important;
            height: 34px !important;
            background: #dff0ff !important;
            color: #2893e5 !important;
            font-weight: 700 !important;
        }

        body.employee-feedback-page .feedback-status-pill {
            min-height: 34px !important;
            padding: 6px 13px !important;
            background: #dff5e2 !important;
            color: #156c1c !important;
            border-radius: 7px !important;
            font-weight: 700 !important;
        }

        body.employee-feedback-page .feedback-action-cell {
            width: 38px;
            text-align: right;
            color: #15314b !important;
            font-size: 18px !important;
        }

        body.employee-feedback-page .feedback-table-footer {
            padding: 20px 10px 0 !important;
            color: #10233d !important;
            font-size: 15px !important;
        }

        body.employee-feedback-page .feedback-bottom-callout {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            align-items: center;
            gap: 28px;
            padding: 22px 30px;
            background: linear-gradient(90deg, rgba(237,247,238,.96), rgba(255,255,255,.94)) !important;
        }

        body.employee-feedback-page .feedback-callout-left,
        body.employee-feedback-page .feedback-callout-right {
            display: flex;
            align-items: center;
            gap: 20px;
            min-width: 0;
        }

        body.employee-feedback-page .feedback-callout-right {
            justify-content: space-between;
            border-left: 1px solid #cbd8d0;
            padding-left: 42px;
        }

        body.employee-feedback-page .feedback-callout-icon {
            width: 74px;
            height: 74px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #1B5E20;
            color: #ffffff;
            font-size: 32px;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-bottom-callout h3 {
            margin: 0 0 9px;
            color: #0b5420;
            font-size: 19px;
            font-weight: 800;
        }

        body.employee-feedback-page .feedback-bottom-callout p {
            margin: 0;
            color: #3d4e69;
            font-size: 15px;
            line-height: 1.45;
        }

        body.employee-feedback-page .feedback-create-ticket-btn {
            min-height: 42px;
            border-radius: 8px;
            background: #1B5E20;
            color: #ffffff;
            padding: 0 20px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            white-space: nowrap;
        }

        @media (max-width: 1100px) {
            body.employee-feedback-page .feedback-hero {
                padding-right: 20px !important;
            }

            body.employee-feedback-page .feedback-hero-art {
                display: none;
            }

            body.employee-feedback-page .feedback-summary-grid,
            body.employee-feedback-page .feedback-bottom-callout {
                grid-template-columns: 1fr !important;
            }

            body.employee-feedback-page .feedback-section-header,
            body.employee-feedback-page .feedback-toolbar {
                align-items: stretch !important;
                flex-direction: column !important;
            }

            body.employee-feedback-page .feedback-callout-right {
                border-left: 0;
                border-top: 1px solid #cbd8d0;
                padding-left: 0;
                padding-top: 22px;
            }
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .content-wrapper {
                padding: 18px 14px 92px !important;
            }

            body.employee-feedback-page .feedback-hero {
                min-height: 0 !important;
                padding: 16px 2px 6px !important;
                gap: 14px !important;
            }

            body.employee-feedback-page .feedback-hero-icon {
                width: 58px !important;
                height: 58px !important;
                font-size: 24px !important;
            }

            body.employee-feedback-page .feedback-hero h1 {
                font-size: 24px !important;
            }

            body.employee-feedback-page .feedback-card {
                min-height: 0 !important;
                padding: 18px !important;
            }

            body.employee-feedback-page .feedback-section {
                padding: 18px 14px !important;
            }

            body.employee-feedback-page .feedback-filter-btn,
            body.employee-feedback-page .feedback-export-btn {
                width: 100%;
            }

            body.employee-feedback-page .feedback-table-wrap {
                border: 0 !important;
                border-radius: 0 !important;
                overflow: visible !important;
            }

            body.employee-feedback-page .feedback-action-cell {
                display: none !important;
            }

            body.employee-feedback-page .feedback-bottom-callout {
                padding: 18px;
            }

            body.employee-feedback-page .feedback-callout-left,
            body.employee-feedback-page .feedback-callout-right {
                align-items: flex-start;
            }
        }

        /* Compact system scale: keeps the mockup composition but matches the rest of the employee UI density. */
        body.employee-feedback-page .content-wrapper {
            max-width: 1360px !important;
            padding: 42px 28px 34px !important;
        }

        body.employee-feedback-page .feedback-page-shell {
            gap: 16px !important;
        }

        body.employee-feedback-page .feedback-hero {
            min-height: 132px !important;
            gap: 18px !important;
            padding: 18px 300px 12px 8px !important;
        }

        body.employee-feedback-page .feedback-hero::before {
            width: 430px;
            height: 220px;
        }

        body.employee-feedback-page .feedback-hero::after {
            top: 78px;
            width: 120px;
            height: 88px;
            background-size: 13px 13px;
        }

        body.employee-feedback-page .feedback-hero-icon {
            width: 56px !important;
            height: 56px !important;
            font-size: 23px !important;
        }

        body.employee-feedback-page .feedback-hero h1 {
            margin-bottom: 8px !important;
            font-size: 26px !important;
            font-weight: 700 !important;
            line-height: 1.14 !important;
        }

        body.employee-feedback-page .feedback-hero p {
            font-size: 14px !important;
            line-height: 1.45 !important;
        }

        body.employee-feedback-page .feedback-hero-art {
            right: 92px;
            bottom: 0;
            width: 250px;
            height: 128px;
            transform: scale(.88);
            transform-origin: right bottom;
        }

        body.employee-feedback-page .feedback-summary-grid {
            gap: 14px !important;
        }

        body.employee-feedback-page .feedback-card,
        body.employee-feedback-page .feedback-section,
        body.employee-feedback-page .feedback-bottom-callout {
            border-radius: 10px !important;
            box-shadow: 0 10px 24px rgba(19, 55, 31, .055) !important;
        }

        body.employee-feedback-page .feedback-card {
            min-height: 116px !important;
            padding: 20px 22px !important;
        }

        body.employee-feedback-page .feedback-card::after {
            right: 18px;
            bottom: 18px;
            width: 96px;
            height: 38px;
            opacity: .78;
        }

        body.employee-feedback-page .feedback-average-card {
            gap: 16px !important;
        }

        body.employee-feedback-page .feedback-summary-icon {
            width: 54px !important;
            height: 54px !important;
            font-size: 23px !important;
        }

        body.employee-feedback-page .feedback-card-title {
            margin-bottom: 8px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-score-line strong {
            font-size: 34px !important;
            font-weight: 700 !important;
        }

        body.employee-feedback-page .feedback-score-note {
            margin-top: 6px !important;
            font-size: 13px !important;
            line-height: 1.35 !important;
        }

        body.employee-feedback-page .feedback-section {
            padding: 20px 22px 16px !important;
        }

        body.employee-feedback-page .feedback-section-header {
            margin-bottom: 16px !important;
            gap: 14px !important;
        }

        body.employee-feedback-page .feedback-section-title-wrap {
            gap: 10px;
        }

        body.employee-feedback-page .feedback-section-title-wrap i {
            font-size: 18px;
        }

        body.employee-feedback-page .feedback-section-title {
            font-size: 17px !important;
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-toolbar {
            gap: 10px;
        }

        body.employee-feedback-page .feedback-filter-btn,
        body.employee-feedback-page .feedback-export-btn {
            min-width: 146px;
            min-height: 36px;
            border-radius: 10px;
            padding: 0 12px;
            font-size: 13px;
            font-weight: 500;
        }

        body.employee-feedback-page .feedback-export-btn {
            min-width: 96px;
        }

        body.employee-feedback-page .feedback-table-wrap {
            border-radius: 10px !important;
        }

        body.employee-feedback-page .feedback-table th {
            padding: 14px 16px !important;
            font-size: 12px !important;
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-table td {
            padding: 15px 16px !important;
            font-size: 13px !important;
            font-weight: 400 !important;
        }

        body.employee-feedback-page .feedback-ticket-id {
            font-size: 13px !important;
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-category-pill {
            min-height: 28px !important;
            padding: 4px 10px 4px 8px !important;
            font-size: 13px !important;
            font-weight: 400 !important;
        }

        body.employee-feedback-page .feedback-category-pill i {
            width: auto !important;
            height: auto !important;
            font-size: 12px !important;
        }

        body.employee-feedback-page .feedback-person {
            gap: 8px !important;
            font-size: 13px !important;
            font-weight: 400 !important;
        }

        body.employee-feedback-page .feedback-avatar {
            width: 28px !important;
            height: 28px !important;
            font-size: 11px !important;
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-department,
        body.employee-feedback-page .feedback-date {
            font-size: 13px !important;
            font-weight: 400 !important;
        }

        body.employee-feedback-page .feedback-status-pill {
            min-height: 28px !important;
            padding: 4px 10px !important;
            border-radius: 6px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            gap: 7px !important;
        }

        body.employee-feedback-page .feedback-advice-box {
            max-width: 280px !important;
            padding: 8px 10px !important;
            font-size: 13px !important;
            font-weight: 400 !important;
        }

        body.employee-feedback-page .feedback-advice-box strong {
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-advice-text {
            font-size: 12px !important;
        }

        body.employee-feedback-page .feedback-action-cell {
            width: 28px;
            font-size: 14px !important;
        }

        body.employee-feedback-page .feedback-table-footer {
            padding: 14px 6px 0 !important;
            font-size: 13px !important;
        }

        body.employee-feedback-page .feedback-page-button,
        body.employee-feedback-page .feedback-page-current {
            width: 30px !important;
            height: 30px !important;
            font-size: 12px !important;
        }

        body.employee-feedback-page .feedback-page-current {
            font-weight: 600 !important;
        }

        body.employee-feedback-page .feedback-bottom-callout {
            gap: 22px;
            padding: 16px 22px;
        }

        body.employee-feedback-page .feedback-callout-left,
        body.employee-feedback-page .feedback-callout-right {
            gap: 14px;
        }

        body.employee-feedback-page .feedback-callout-right {
            padding-left: 28px;
        }

        body.employee-feedback-page .feedback-callout-icon {
            width: 54px;
            height: 54px;
            font-size: 23px;
        }

        body.employee-feedback-page .feedback-bottom-callout h3 {
            margin-bottom: 6px;
            font-size: 15px;
            font-weight: 600;
        }

        body.employee-feedback-page .feedback-bottom-callout p {
            font-size: 13px;
            line-height: 1.4;
        }

        body.employee-feedback-page .feedback-create-ticket-btn {
            min-height: 36px;
            border-radius: 7px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 1100px) {
            body.employee-feedback-page .feedback-hero {
                padding-right: 8px !important;
            }
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .content-wrapper {
                padding: 14px 12px 88px !important;
            }

            body.employee-feedback-page .feedback-hero {
                padding: 10px 2px 4px !important;
            }

            body.employee-feedback-page .feedback-hero h1 {
                font-size: 21px !important;
            }

            body.employee-feedback-page .feedback-hero p {
                font-size: 13px !important;
            }

            body.employee-feedback-page .feedback-card,
            body.employee-feedback-page .feedback-section,
            body.employee-feedback-page .feedback-bottom-callout {
                padding: 14px !important;
            }
        }

        /* Icon cleanup: remove overlapping decorative charts and align the feedback artwork with the system mockup. */
        body.employee-feedback-page .feedback-card::after {
            display: none !important;
            content: none !important;
        }

        body.employee-feedback-page .feedback-hero-icon {
            position: relative !important;
        }

        body.employee-feedback-page .feedback-hero-icon i {
            font-size: 22px !important;
            transform: translateX(-2px);
        }

        body.employee-feedback-page .feedback-hero-icon::after {
            content: "\f005";
            position: absolute;
            right: 12px;
            bottom: 13px;
            color: #16831f;
            font-family: "Font Awesome 6 Free";
            font-size: 15px;
            font-weight: 900;
            text-shadow: 0 0 0 #dcf5df;
        }

        body.employee-feedback-page .feedback-hero-art {
            right: 116px;
            bottom: 2px;
            width: 300px;
            height: 138px;
            transform: none;
        }

        body.employee-feedback-page .feedback-bubble-main {
            left: 28px;
            top: 28px;
            width: 82px;
            height: 72px;
            z-index: 2;
        }

        body.employee-feedback-page .feedback-bubble-main::before {
            right: 14px;
            bottom: -13px;
            border-width: 16px 0 0 18px;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            left: 23px;
            top: 27px;
            font-size: 12px;
            letter-spacing: 7px;
        }

        body.employee-feedback-page .feedback-bubble-small {
            left: 146px;
            top: 8px;
            width: 60px;
            height: 36px;
            z-index: 4;
        }

        body.employee-feedback-page .feedback-bubble-small::after {
            left: 13px;
            top: 12px;
            width: 34px;
            height: 6px;
            box-shadow: 0 12px 0 rgba(255, 255, 255, .72);
        }

        body.employee-feedback-page .feedback-rating-card-art {
            right: 22px;
            bottom: 24px;
            width: 158px;
            height: 70px;
            z-index: 3;
        }

        body.employee-feedback-page .feedback-rating-card-art::before {
            left: 22px;
            top: 23px;
            font-size: 14px;
            letter-spacing: 5px;
        }

        body.employee-feedback-page .feedback-rating-card-art::after {
            left: 22px;
            right: 22px;
            bottom: 15px;
            height: 4px;
            box-shadow: 0 10px 0 #edf2f4;
        }

        @media (max-width: 1100px) {
            body.employee-feedback-page .feedback-hero-art {
                display: none;
            }
        }

        /* Final icon polish and table action cleanup. */
        body.employee-feedback-page .feedback-bubble-main {
            left: 30px !important;
            top: 38px !important;
            width: 64px !important;
            height: 56px !important;
            border-radius: 42% 42% 42% 38% !important;
        }

        body.employee-feedback-page .feedback-bubble-main::before {
            right: 10px !important;
            bottom: -9px !important;
            border-width: 12px 0 0 13px !important;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            left: 17px !important;
            top: 22px !important;
            width: 8px !important;
            height: 8px !important;
            box-shadow: 17px 0 0 #ffffff, 34px 0 0 #ffffff !important;
        }

        body.employee-feedback-page .feedback-action-cell {
            display: none !important;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            left: 14px !important;
            top: 22px !important;
            width: 8px !important;
            height: 8px !important;
            box-shadow: 16px 0 0 #ffffff, 32px 0 0 #ffffff !important;
        }

        /* Final artwork alignment fix. */
        body.employee-feedback-page .feedback-hero-icon::after {
            display: none !important;
            content: none !important;
        }

        body.employee-feedback-page .feedback-hero-icon i {
            transform: none !important;
        }

        body.employee-feedback-page .feedback-hero-art {
            right: 104px !important;
            bottom: 12px !important;
            width: 330px !important;
            height: 138px !important;
        }

        body.employee-feedback-page .feedback-bubble-main {
            left: 12px !important;
            top: 26px !important;
            width: 76px !important;
            height: 66px !important;
        }

        body.employee-feedback-page .feedback-bubble-main::before {
            right: 12px !important;
            bottom: -11px !important;
            border-width: 14px 0 0 16px !important;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            left: 21px !important;
            top: 25px !important;
            font-size: 11px !important;
            letter-spacing: 6px !important;
        }

        body.employee-feedback-page .feedback-bubble-small {
            left: 168px !important;
            top: 10px !important;
            z-index: 4 !important;
        }

        body.employee-feedback-page .feedback-rating-card-art {
            right: 18px !important;
            bottom: 26px !important;
            width: 158px !important;
            height: 66px !important;
            z-index: 3 !important;
        }

        /* Hero artwork detail fix: three chat dots and compact yellow message marker. */
        body.employee-feedback-page .feedback-bubble-main::after {
            content: "" !important;
            left: 22px !important;
            top: 27px !important;
            width: 11px !important;
            height: 11px !important;
            border-radius: 50% !important;
            background: #ffffff !important;
            box-shadow: 20px 0 0 #ffffff, 40px 0 0 #ffffff !important;
            letter-spacing: 0 !important;
        }

        body.employee-feedback-page .feedback-bubble-small {
            left: 170px !important;
            top: 14px !important;
            width: 56px !important;
            height: 34px !important;
            border-radius: 7px !important;
        }

        body.employee-feedback-page .feedback-bubble-small::before {
            right: 13px !important;
            bottom: -9px !important;
            border-width: 11px 0 0 13px !important;
        }

        body.employee-feedback-page .feedback-bubble-small::after {
            left: 12px !important;
            top: 11px !important;
            width: 32px !important;
            height: 5px !important;
            border-radius: 999px !important;
            background: #ffffff !important;
            box-shadow: 0 10px 0 rgba(255, 255, 255, .78) !important;
        }

        /* Final hero artwork alignment: keep all pieces on one balanced baseline. */
        body.employee-feedback-page .feedback-hero-art {
            right: 128px !important;
            bottom: 18px !important;
            width: 300px !important;
            height: 124px !important;
        }

        body.employee-feedback-page .feedback-bubble-main {
            left: 18px !important;
            top: 34px !important;
            width: 74px !important;
            height: 64px !important;
            border-radius: 44% 44% 44% 40% !important;
        }

        body.employee-feedback-page .feedback-bubble-main::before {
            right: 13px !important;
            bottom: -10px !important;
            border-width: 13px 0 0 15px !important;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            left: 20px !important;
            top: 25px !important;
            width: 10px !important;
            height: 10px !important;
            box-shadow: 19px 0 0 #ffffff, 38px 0 0 #ffffff !important;
        }

        body.employee-feedback-page .feedback-rating-card-art {
            right: 14px !important;
            bottom: 22px !important;
            width: 154px !important;
            height: 64px !important;
            transform: rotate(2deg) !important;
        }

        body.employee-feedback-page .feedback-rating-card-art::before {
            left: 22px !important;
            top: 22px !important;
            font-size: 13px !important;
            letter-spacing: 5px !important;
        }

        body.employee-feedback-page .feedback-rating-card-art::after {
            left: 22px !important;
            right: 22px !important;
            bottom: 13px !important;
            height: 4px !important;
            box-shadow: 0 9px 0 #edf2f4 !important;
        }

        body.employee-feedback-page .feedback-bubble-small {
            left: 126px !important;
            top: 14px !important;
            width: 56px !important;
            height: 34px !important;
            z-index: 5 !important;
        }

        body.employee-feedback-page .feedback-bubble-small::before {
            right: 12px !important;
            bottom: -9px !important;
            border-width: 11px 0 0 13px !important;
        }

        body.employee-feedback-page .feedback-bubble-small::after {
            left: 12px !important;
            top: 11px !important;
            width: 32px !important;
            height: 5px !important;
            box-shadow: 0 10px 0 rgba(255, 255, 255, .78) !important;
        }

        /* Lift the whole feedback header group to reduce the empty band below the navbar. */
        body.employee-feedback-page .content-wrapper {
            padding-top: 22px !important;
        }

        body.employee-feedback-page .feedback-hero {
            min-height: 104px !important;
            padding-top: 6px !important;
            padding-bottom: 4px !important;
            margin-bottom: 2px !important;
            align-items: center !important;
        }

        body.employee-feedback-page .feedback-hero::before {
            inset: -94px -20px auto auto !important;
        }

        body.employee-feedback-page .feedback-hero::after {
            top: 58px !important;
        }

        body.employee-feedback-page .feedback-hero-art {
            bottom: 0 !important;
        }

        body.employee-feedback-page .feedback-summary-grid {
            margin-top: 0 !important;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .content-wrapper {
                padding-top: 10px !important;
            }

            body.employee-feedback-page .feedback-hero {
                min-height: 82px !important;
            }
        }

        /* Small final upward nudge requested for the feedback header group. */
        body.employee-feedback-page .content-wrapper {
            padding-top: 12px !important;
        }

        body.employee-feedback-page .feedback-hero {
            min-height: 96px !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }

        body.employee-feedback-page .feedback-hero::after {
            top: 50px !important;
        }

        body.employee-feedback-page .feedback-hero-art {
            bottom: -4px !important;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .content-wrapper {
                padding-top: 8px !important;
            }
        }

        /* Final header lift and yellow marker visibility fix. */
        body.employee-feedback-page .content-wrapper {
            padding-top: 2px !important;
        }

        body.employee-feedback-page .feedback-hero {
            min-height: 88px !important;
        }

        body.employee-feedback-page .feedback-hero::after {
            top: 44px !important;
        }

        body.employee-feedback-page .feedback-hero-art {
            bottom: -8px !important;
        }

        body.employee-feedback-page .feedback-bubble-small {
            top: 20px !important;
            width: 62px !important;
            height: 38px !important;
            overflow: visible !important;
        }

        body.employee-feedback-page .feedback-bubble-small::before {
            bottom: -10px !important;
        }

        body.employee-feedback-page .feedback-bubble-small::after {
            left: 13px !important;
            top: 12px !important;
            width: 36px !important;
            height: 5px !important;
            box-shadow: 0 11px 0 rgba(255, 255, 255, .78) !important;
        }

        body.employee-feedback-page .feedback-bubble-main::after {
            left: 50% !important;
            top: 50% !important;
            width: 10px !important;
            height: 10px !important;
            transform: translate(-50%, -50%) !important;
            box-shadow: -22px 0 0 #ffffff, 22px 0 0 #ffffff !important;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .content-wrapper {
                padding-top: 4px !important;
            }
        }

        body.employee-feedback-page .feedback-toolbar {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 10px !important;
            margin: 0 !important;
        }

        body.employee-feedback-page .feedback-filter-control {
            position: relative;
            min-width: 154px;
            height: 36px;
            border: 1px solid #d9e2e6;
            border-radius: 10px;
            background: #ffffff;
            color: #12233d;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            box-sizing: border-box;
        }

        body.employee-feedback-page .feedback-filter-control > i:first-child {
            color: #0b6b27;
            font-size: 13px;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-filter-control > i:last-child {
            color: #12233d;
            font-size: 12px;
            pointer-events: none;
            flex: 0 0 auto;
        }

        body.employee-feedback-page .feedback-filter-select {
            min-width: 0;
            flex: 1 1 auto;
            width: 100%;
            border: 0;
            outline: 0;
            background: transparent;
            color: #12233d;
            font: inherit;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.2;
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
        }

        body.employee-feedback-page .feedback-filter-control:first-child {
            min-width: 160px;
        }

        body.employee-feedback-page .feedback-filter-control.is-disabled {
            background: #f8faf8;
            color: #94a3b8;
            opacity: .72;
        }

        body.employee-feedback-page .feedback-filter-control.is-disabled > i {
            color: #94a3b8 !important;
        }

        body.employee-feedback-page .feedback-filter-control.is-disabled .feedback-filter-select {
            color: #94a3b8;
            cursor: not-allowed;
        }

        body.employee-feedback-page .feedback-clear-filters-btn {
            height: 36px;
            min-width: 84px;
            border: 1px solid #d9e2e6;
            border-radius: 10px;
            background: #ffffff;
            color: #12233d;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 13px;
            font-size: 13px;
            font-weight: 500;
            line-height: 1;
            cursor: pointer;
            box-sizing: border-box;
        }

        body.employee-feedback-page .feedback-clear-filters-btn i {
            color: #0b6b27;
            font-size: 12px;
        }

        body.employee-feedback-page .feedback-clear-filters-btn:hover:not(:disabled) {
            border-color: rgba(11, 107, 39, .42);
            background: #f7fbf7;
        }

        body.employee-feedback-page .feedback-clear-filters-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        body.employee-feedback-page.feedback-filter-loading .feedback-section,
        body.employee-feedback-page.feedback-filter-loading .feedback-summary-grid {
            opacity: .58;
            pointer-events: none;
            transition: opacity .16s ease;
        }

        body.employee-feedback-page .feedback-filter-select::-ms-expand {
            display: none;
        }

        body.employee-feedback-page .dashboard-container {
            max-width: 1780px !important;
            padding-left: 30px !important;
            padding-right: 30px !important;
        }

        body.employee-feedback-page .content-wrapper {
            max-width: none !important;
            padding-left: 30px !important;
            padding-right: 30px !important;
        }

        body.employee-feedback-page .feedback-hero h1 {
            color: #145a24 !important;
        }
        body.employee-feedback-page .feedback-hero::before,
        body.employee-feedback-page .feedback-hero::after {
            content: none !important;
            background: none !important;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .feedback-toolbar {
                width: 100%;
            }

            body.employee-feedback-page .feedback-filter-control {
                width: 100%;
                min-width: 0;
            }

            body.employee-feedback-page .feedback-clear-filters-btn {
                width: 100%;
            }
        }

        /* Feedback table-only override: match My Submitted Tickets while keeping this page scoped. */
        body.employee-feedback-page .feedback-page-shell {
            width: 100% !important;
            max-width: 1500px !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }

        body.employee-feedback-page .feedback-table-wrap {
            border: 0 !important;
            border-radius: 0 !important;
            overflow-x: auto !important;
            overflow-y: visible !important;
        }

        body.employee-feedback-page .feedback-table {
            width: 100% !important;
            table-layout: fixed !important;
        }

        body.employee-feedback-page .feedback-table th {
            background: #fbfcfd !important;
            border-bottom: 1px solid #1B5E20 !important;
            padding: 14px 16px !important;
            color: #0b6b27 !important;
            font-size: 12px !important;
            font-weight: 500 !important;
        }

        body.employee-feedback-page .feedback-table th:first-child {
            border-top-left-radius: 8px !important;
            border-bottom-left-radius: 8px !important;
        }

        body.employee-feedback-page .feedback-table th:last-child {
            border-top-right-radius: 8px !important;
            border-bottom-right-radius: 8px !important;
        }

        body.employee-feedback-page .feedback-table td {
            padding: 15px 16px !important;
            border-bottom: 1px solid #edf2f7 !important;
            font-size: 13px !important;
        }

        body.employee-feedback-page .feedback-table th:nth-child(1),
        body.employee-feedback-page .feedback-table td:nth-child(1) {
            width: 92px !important;
        }

        body.employee-feedback-page .feedback-table th:nth-child(2),
        body.employee-feedback-page .feedback-table td:nth-child(2) {
            width: 16% !important;
        }

        body.employee-feedback-page .feedback-table th:nth-child(3),
        body.employee-feedback-page .feedback-table td:nth-child(3) {
            width: 16% !important;
        }

        body.employee-feedback-page .feedback-table th:nth-child(4),
        body.employee-feedback-page .feedback-table td:nth-child(4) {
            width: 14% !important;
        }

        body.employee-feedback-page .feedback-table th:nth-child(5),
        body.employee-feedback-page .feedback-table td:nth-child(5) {
            width: 24% !important;
        }

        body.employee-feedback-page .feedback-table th:nth-child(6),
        body.employee-feedback-page .feedback-table td:nth-child(6) {
            width: 150px !important;
        }

        body.employee-feedback-page .feedback-category-pill,
        body.employee-feedback-page .feedback-person,
        body.employee-feedback-page .feedback-department,
        body.employee-feedback-page .feedback-rating,
        body.employee-feedback-page .feedback-date {
            max-width: 100% !important;
            min-width: 0 !important;
            font-size: 13px !important;
        }

        body.employee-feedback-page .feedback-advice-box {
            max-width: 250px !important;
            font-size: 12px !important;
        }

        body.employee-feedback-page .feedback-table-footer {
            display: block !important;
            padding: 18px 0 0 !important;
        }

        body.employee-feedback-page .feedback-table-footer .pagination-glass {
            width: 100%;
            min-height: 46px;
            margin: 0;
            justify-content: space-between;
            gap: 10px;
        }

        body.employee-feedback-page .feedback-table-footer .pagination-summary {
            flex: 1 1 auto;
        }

        body.employee-feedback-page .feedback-table-footer .page-numbers {
            min-width: 0;
            justify-content: center;
            gap: 8px;
        }

        body.employee-feedback-page .feedback-table-footer .page-btn.active {
            background: #1B5E20 !important;
            border-color: #1B5E20 !important;
            box-shadow: 0 10px 18px rgba(27, 94, 32, 0.22) !important;
        }

        @media (max-width: 768px) {
            body.employee-feedback-page .dashboard-container {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 8px 6px 24px !important;
                box-sizing: border-box !important;
            }

            body.employee-feedback-page .content-wrapper {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 4px 6px 88px !important;
                box-sizing: border-box !important;
            }

            body.employee-feedback-page .feedback-page-shell {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
            }

            body.employee-feedback-page .feedback-table-footer .pagination-glass {
                display: grid;
                grid-template-columns: auto minmax(0, 1fr) auto;
                gap: 10px;
                padding: 14px 0 4px;
            }

            body.employee-feedback-page .feedback-table td {
                width: 100% !important;
            }

            body.employee-feedback-page .feedback-advice-box {
                max-width: 100% !important;
            }

            body.employee-feedback-page .feedback-table-footer .pagination-summary {
                grid-column: 1 / -1;
                width: 100%;
                font-size: 14px;
                line-height: 1.35;
                text-align: left;
            }

            body.employee-feedback-page .feedback-table-footer .page-numbers {
                min-width: 0;
                justify-content: flex-start;
                overflow-x: auto;
                padding: 2px 2px 6px;
                scrollbar-width: none;
            }

            body.employee-feedback-page .feedback-table-footer .page-numbers::-webkit-scrollbar {
                display: none;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn {
                flex: 0 0 auto;
                min-width: 44px;
                height: 44px;
                padding: 0 14px;
                border-radius: 999px;
                font-size: 14px;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.prev,
            body.employee-feedback-page .feedback-table-footer .page-btn.next {
                min-width: 44px;
                width: 44px;
                padding: 0;
                overflow: hidden;
                color: transparent;
                white-space: nowrap;
                position: relative;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.prev::before,
            body.employee-feedback-page .feedback-table-footer .page-btn.next::before {
                position: absolute;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #334155;
                font-size: 20px;
                font-weight: 900;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.prev::before {
                content: "\2039";
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.next::before {
                content: "\203A";
            }

            /* Keep every feedback record evenly aligned as a mobile card. */
            body.employee-feedback-page .feedback-table,
            body.employee-feedback-page .feedback-table tbody {
                display: block !important;
                width: 100% !important;
            }

            body.employee-feedback-page .feedback-table tbody {
                display: grid !important;
                gap: 12px !important;
            }

            body.employee-feedback-page .feedback-ticket-row {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) auto !important;
                grid-template-areas:
                    "id date"
                    "category category"
                    "person person"
                    "department department"
                    "rating rating" !important;
                gap: 12px !important;
                width: 100% !important;
                min-height: 0 !important;
                padding: 16px !important;
                border: 1px solid #dbe6de !important;
                border-radius: 14px !important;
                background: #ffffff !important;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05) !important;
            }

            body.employee-feedback-page .feedback-ticket-row > td {
                display: block !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: none !important;
                padding: 0 !important;
                border: 0 !important;
                text-align: left !important;
            }

            body.employee-feedback-page .feedback-ticket-row > td:nth-child(1) { grid-area: id !important; }
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(2) { grid-area: category !important; }
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(3) { grid-area: person !important; }
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(4) { grid-area: department !important; }
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(5) { grid-area: rating !important; }
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(6) {
                grid-area: date !important;
                width: auto !important;
                justify-self: end !important;
                text-align: right !important;
            }

            body.employee-feedback-page .feedback-category-pill,
            body.employee-feedback-page .feedback-person,
            body.employee-feedback-page .feedback-rating,
            body.employee-feedback-page .feedback-advice-box {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
            }

            body.employee-feedback-page .feedback-category-pill {
                min-height: 40px !important;
                padding: 8px 12px !important;
                white-space: normal !important;
                overflow-wrap: anywhere;
            }

            body.employee-feedback-page .feedback-person {
                min-height: 38px;
                align-items: center !important;
            }

            body.employee-feedback-page .feedback-department {
                padding-top: 10px !important;
                border-top: 1px solid #e5ebe7 !important;
                white-space: normal !important;
            }

            body.employee-feedback-page .feedback-rating {
                padding-top: 10px !important;
                border-top: 1px solid #e5ebe7 !important;
            }

            body.employee-feedback-page .feedback-advice-box {
                padding: 12px !important;
                border-radius: 10px !important;
                line-height: 1.45 !important;
                overflow-wrap: anywhere;
            }

            body.employee-feedback-page .feedback-callout-left,
            body.employee-feedback-page .feedback-callout-right {
                width: 100%;
                align-items: center !important;
            }

            body.employee-feedback-page .feedback-callout-right {
                padding: 14px 0 0 !important;
            }

            body.employee-feedback-page .tm-global-chat-fab {
                position: fixed !important;
                right: 12px !important;
                bottom: 12px !important;
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px !important;
                height: 42px !important;
                min-height: 42px !important;
                padding: 0 !important;
                border-radius: 999px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 0 !important;
            }

            body.employee-feedback-page .tm-global-chat-fab .tm-global-chat-label {
                display: none !important;
            }

            /* Conference-scheduler table pattern for mobile feedback. */
            body.employee-feedback-page .feedback-swipe-guide {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin: 0 0 10px;
                padding: 9px 12px;
                border: 1px solid #d9e6db;
                border-radius: 12px;
                background: #f6fbf7;
                color: #476653;
                font-size: 12px;
                font-weight: 600;
                line-height: 1.35;
                text-align: center;
            }

            body.employee-feedback-page .feedback-swipe-guide i {
                color: #166534;
                font-size: 14px;
                flex: 0 0 auto;
            }

            body.employee-feedback-page .feedback-table-wrap {
                width: 100% !important;
                overflow-x: auto !important;
                overflow-y: hidden !important;
                border: 1px solid #e5e7eb !important;
                border-radius: 14px !important;
                background: #ffffff !important;
                touch-action: pan-x pan-y;
                overscroll-behavior-x: contain;
                overscroll-behavior-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            body.employee-feedback-page .feedback-table {
                display: table !important;
                width: 980px !important;
                min-width: 980px !important;
                table-layout: fixed !important;
                border-collapse: separate !important;
                border-spacing: 0 !important;
            }

            body.employee-feedback-page .feedback-table thead {
                display: table-header-group !important;
            }

            body.employee-feedback-page .feedback-table thead tr {
                display: table-row !important;
                width: auto !important;
            }

            body.employee-feedback-page .feedback-table tbody {
                display: table-row-group !important;
            }

            body.employee-feedback-page .feedback-ticket-row {
                display: table-row !important;
                width: auto !important;
                min-height: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 0 !important;
                background: #ffffff !important;
                box-shadow: none !important;
            }

            body.employee-feedback-page .feedback-table th,
            body.employee-feedback-page .feedback-ticket-row > td {
                display: table-cell !important;
                grid-area: auto !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                height: auto !important;
                padding: 12px !important;
                border: 0 !important;
                border-right: 1px solid #e5e7eb !important;
                border-bottom: 1px solid #e5e7eb !important;
                border-radius: 0 !important;
                vertical-align: middle !important;
                text-align: left !important;
                white-space: normal !important;
                overflow-wrap: anywhere;
            }

            body.employee-feedback-page .feedback-table th {
                position: sticky;
                top: 0;
                z-index: 4;
                height: 48px !important;
                background: #166534 !important;
                color: #ffffff !important;
                border-bottom-color: #14532d !important;
                font-size: 12px !important;
                font-weight: 800 !important;
                letter-spacing: 0 !important;
                text-transform: none !important;
                line-height: 1.25 !important;
                white-space: nowrap !important;
                overflow-wrap: normal !important;
                word-break: normal !important;
            }

            body.employee-feedback-page .feedback-table th:first-child,
            body.employee-feedback-page .feedback-ticket-row > td:first-child {
                position: sticky;
                left: 0;
                width: 105px !important;
                min-width: 105px !important;
            }

            body.employee-feedback-page .feedback-table th:first-child {
                z-index: 6;
                background: #166534 !important;
            }

            body.employee-feedback-page .feedback-ticket-row > td:first-child {
                z-index: 2;
                background: #f3f8f3 !important;
                color: #166534 !important;
                font-weight: 800 !important;
            }

            body.employee-feedback-page .feedback-table th:nth-child(2) { width: 170px !important; }
            body.employee-feedback-page .feedback-table th:nth-child(3) { width: 170px !important; }
            body.employee-feedback-page .feedback-table th:nth-child(4) { width: 135px !important; }
            body.employee-feedback-page .feedback-table th:nth-child(5) { width: 230px !important; }
            body.employee-feedback-page .feedback-table th:nth-child(6) { width: 170px !important; }

            body.employee-feedback-page .feedback-table th:last-child,
            body.employee-feedback-page .feedback-ticket-row > td:last-child {
                border-right: 0 !important;
            }

            body.employee-feedback-page .feedback-ticket-row:last-child > td {
                border-bottom: 0 !important;
            }

            body.employee-feedback-page .feedback-category-pill,
            body.employee-feedback-page .feedback-person,
            body.employee-feedback-page .feedback-rating,
            body.employee-feedback-page .feedback-advice-box {
                width: auto !important;
                max-width: 100% !important;
                min-width: 0 !important;
            }

            body.employee-feedback-page .feedback-category-pill,
            body.employee-feedback-page .feedback-person {
                white-space: normal !important;
            }

            body.employee-feedback-page .feedback-department,
            body.employee-feedback-page .feedback-rating {
                padding: 12px !important;
                border-top: 0 !important;
            }

            body.employee-feedback-page .feedback-date {
                justify-self: auto !important;
                text-align: left !important;
            }

            body.employee-feedback-page .feedback-advice-box {
                padding: 9px 10px !important;
                border-radius: 8px !important;
            }

            body.employee-feedback-page .feedback-summary-grid {
                gap: 7px !important;
            }

            body.employee-feedback-page .feedback-card.feedback-average-card {
                position: relative;
                display: block !important;
                min-height: 86px !important;
                padding: 9px 8px !important;
                border-radius: 7px !important;
                box-shadow: 0 3px 10px rgba(15, 23, 42, 0.08) !important;
            }

            body.employee-feedback-page .feedback-summary-icon {
                position: absolute;
                top: 10px;
                left: 9px;
                width: 27px !important;
                height: 27px !important;
                flex: 0 0 27px !important;
                font-size: 12px !important;
            }

            body.employee-feedback-page .feedback-card.feedback-average-card > div:last-child {
                min-width: 0;
                padding-left: 33px;
            }

            body.employee-feedback-page .feedback-card-title {
                margin: 0 0 3px !important;
                font-size: 8px !important;
                line-height: 1.2 !important;
                white-space: nowrap;
            }

            body.employee-feedback-page .feedback-score-line strong {
                font-size: 23px !important;
                line-height: 1 !important;
            }

            body.employee-feedback-page .feedback-score-note {
                margin: 5px 0 0 -33px !important;
                font-size: 7px !important;
                line-height: 1.25 !important;
                white-space: nowrap;
            }
        }

        /* Phone reference layout: compact feedback table. */
        body.employee-feedback-page .feedback-mobile-page-counter {
            display: none;
        }

        @media (max-width: 767px) {
            body.employee-feedback-page .feedback-swipe-guide {
                display: none !important;
            }

            body.employee-feedback-page .feedback-section {
                padding: 10px 7px 0 !important;
                border: 1px solid #e5ebe7 !important;
                border-radius: 11px !important;
                background: #ffffff !important;
                box-shadow: 0 7px 22px rgba(15, 23, 42, .06) !important;
                overflow: hidden !important;
            }

            body.employee-feedback-page .feedback-section-header {
                margin: 0 2px 9px !important;
            }

            body.employee-feedback-page .feedback-section-title {
                font-size: 13px !important;
                font-weight: 750 !important;
            }

            body.employee-feedback-page .feedback-table-wrap {
                width: 100% !important;
                overflow: hidden !important;
                border: 0 !important;
                border-radius: 0 !important;
                background: #ffffff !important;
                touch-action: auto;
            }

            body.employee-feedback-page .feedback-table {
                display: table !important;
                width: 100% !important;
                min-width: 0 !important;
                table-layout: fixed !important;
                border-collapse: collapse !important;
                border-spacing: 0 !important;
            }

            body.employee-feedback-page .feedback-table thead {
                display: table-header-group !important;
            }

            body.employee-feedback-page .feedback-table thead tr,
            body.employee-feedback-page .feedback-table tbody .feedback-ticket-row {
                display: table-row !important;
                width: auto !important;
                min-height: 0 !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 0 !important;
                background: #ffffff !important;
                box-shadow: none !important;
            }

            body.employee-feedback-page .feedback-table tbody {
                display: table-row-group !important;
                width: auto !important;
            }

            body.employee-feedback-page .feedback-table th,
            body.employee-feedback-page .feedback-ticket-row > td {
                display: table-cell !important;
                position: static !important;
                grid-area: auto !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
                margin: 0 !important;
                border: 0 !important;
                border-right: 0 !important;
                border-radius: 0 !important;
                vertical-align: middle !important;
                text-align: center !important;
                white-space: normal !important;
                overflow: hidden !important;
                overflow-wrap: anywhere;
            }

            body.employee-feedback-page .feedback-table th {
                position: static !important;
                top: auto !important;
                left: auto !important;
                z-index: auto !important;
                height: 36px !important;
                padding: 5px 2px !important;
                border-bottom: 1px solid #b9d6bf !important;
                background: #ffffff !important;
                color: #17642b !important;
                font-size: 7px !important;
                font-weight: 800 !important;
                line-height: 1.1 !important;
                letter-spacing: 0 !important;
                text-transform: uppercase !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
            }

            body.employee-feedback-page .feedback-ticket-row > td {
                height: 54px !important;
                padding: 5px 2px !important;
                border-bottom: 1px solid #edf1ee !important;
                background: #ffffff !important;
                color: #24364d !important;
                font-size: 7px !important;
                font-weight: 600 !important;
                line-height: 1.2 !important;
            }

            body.employee-feedback-page .feedback-table th:nth-child(1),
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(1) { width: 12% !important; }
            body.employee-feedback-page .feedback-table th:nth-child(2),
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(2) { width: 18% !important; }
            body.employee-feedback-page .feedback-table th:nth-child(3),
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(3) { width: 18% !important; }
            body.employee-feedback-page .feedback-table th:nth-child(4),
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(4) { width: 15% !important; }
            body.employee-feedback-page .feedback-table th:nth-child(5),
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(5) { width: 23% !important; }
            body.employee-feedback-page .feedback-table th:nth-child(6),
            body.employee-feedback-page .feedback-ticket-row > td:nth-child(6) { width: 14% !important; }

            body.employee-feedback-page .feedback-ticket-row > td:first-child {
                color: #172033 !important;
                font-size: 7.5px !important;
                font-weight: 800 !important;
                font-variant-numeric: tabular-nums;
            }

            body.employee-feedback-page .feedback-category-pill {
                display: -webkit-box !important;
                width: 100% !important;
                min-width: 0 !important;
                min-height: 0 !important;
                max-width: 100% !important;
                padding: 0 !important;
                border: 0 !important;
                border-radius: 0 !important;
                background: transparent !important;
                color: inherit !important;
                font-size: 7px !important;
                font-weight: 650 !important;
                line-height: 1.2 !important;
                white-space: normal !important;
                overflow: hidden !important;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
            }

            body.employee-feedback-page .feedback-category-pill i,
            body.employee-feedback-page .feedback-avatar {
                display: none !important;
            }

            body.employee-feedback-page .feedback-person,
            body.employee-feedback-page .feedback-department,
            body.employee-feedback-page .feedback-date {
                display: block !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                padding: 0 !important;
                border: 0 !important;
                color: inherit !important;
                font-size: 6.5px !important;
                font-weight: 600 !important;
                line-height: 1.2 !important;
                text-align: center !important;
                white-space: normal !important;
                overflow: hidden !important;
                text-overflow: ellipsis;
            }

            body.employee-feedback-page .feedback-rating {
                display: block !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                padding: 0 !important;
                border: 0 !important;
                background: transparent !important;
            }

            body.employee-feedback-page .feedback-status-pill {
                display: inline-flex !important;
                min-height: 17px !important;
                max-width: 100% !important;
                gap: 2px !important;
                padding: 3px 4px !important;
                border-radius: 999px !important;
                font-size: 6px !important;
                line-height: 1 !important;
                white-space: normal !important;
            }

            body.employee-feedback-page .feedback-advice-box {
                display: -webkit-box !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                max-height: 34px !important;
                padding: 3px !important;
                border-radius: 5px !important;
                font-size: 6px !important;
                line-height: 1.2 !important;
                overflow: hidden !important;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 3;
            }

            body.employee-feedback-page .feedback-advice-box i {
                display: none !important;
            }

            body.employee-feedback-page .feedback-advice-box strong {
                font-size: inherit !important;
            }

            body.employee-feedback-page .feedback-advice-text {
                display: inline !important;
                margin: 0 !important;
                color: inherit !important;
                font-size: inherit !important;
            }

            body.employee-feedback-page .feedback-table-footer {
                margin: 0 !important;
                padding: 0 !important;
            }

            body.employee-feedback-page .feedback-table-footer .pagination-glass {
                display: grid !important;
                grid-template-columns: 1fr auto 1fr !important;
                grid-template-areas: "prev counter next";
                align-items: center !important;
                width: 100% !important;
                min-height: 42px !important;
                gap: 8px !important;
                margin: 0 !important;
                padding: 6px 5px !important;
                border: 0 !important;
                background: #ffffff !important;
                box-shadow: none !important;
            }

            body.employee-feedback-page .feedback-table-footer .pagination-summary,
            body.employee-feedback-page .feedback-table-footer .page-numbers {
                display: none !important;
            }

            body.employee-feedback-page .feedback-table-footer .feedback-mobile-page-counter {
                display: block;
                grid-area: counter;
                color: #29415e;
                font-size: 9px;
                font-weight: 800;
                white-space: nowrap;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.prev,
            body.employee-feedback-page .feedback-table-footer .page-btn.next {
                position: static !important;
                display: inline-flex !important;
                width: auto !important;
                min-width: 0 !important;
                height: 30px !important;
                padding: 0 3px !important;
                border: 0 !important;
                background: transparent !important;
                color: #17642b !important;
                font-size: 9px !important;
                font-weight: 700 !important;
                overflow: visible !important;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.prev {
                grid-area: prev;
                justify-self: start;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.next {
                grid-area: next;
                justify-self: end;
            }

            body.employee-feedback-page .feedback-table-footer .page-btn.prev::before,
            body.employee-feedback-page .feedback-table-footer .page-btn.next::before {
                display: none !important;
                content: none !important;
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
                    <div>
                        <h1>My Support Feedback</h1>
                        <p>View feedback and advice from our team on the tickets you've submitted.</p>
                    </div>
                </section>

                <section class="feedback-summary-grid" aria-label="Feedback summary">
                    <div class="feedback-card feedback-average-card">
                        <div class="feedback-summary-icon" aria-hidden="true">
                            <i class="far fa-star"></i>
                        </div>
                        <div>
                            <h2 class="feedback-card-title">Excellent Feedback</h2>
                            <div class="feedback-score-line">
                                <strong><?= $excellentFeedbackTotal; ?></strong>
                            </div>
                            <p class="feedback-score-note"><?= $excellentFeedbackPercent; ?>% of total feedback</p>
                        </div>
                    </div>

                    <div class="feedback-card feedback-average-card">
                        <div class="feedback-summary-icon" aria-hidden="true">
                            <i class="far fa-comment-dots"></i>
                        </div>
                        <div>
                            <h2 class="feedback-card-title">Advice to Improve</h2>
                            <div class="feedback-score-line">
                                <strong><?= $adviceFeedbackTotal; ?></strong>
                            </div>
                            <p class="feedback-score-note"><?= $adviceFeedbackPercent; ?>% of total feedback</p>
                        </div>
                    </div>

                    <div class="feedback-card feedback-average-card">
                        <div class="feedback-summary-icon" aria-hidden="true">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <h2 class="feedback-card-title">Total Feedback</h2>
                            <div class="feedback-score-line">
                                <strong><?= $feedbackTotal; ?></strong>
                            </div>
                            <p class="feedback-score-note">From <?= $feedbackTotal; ?> resolved ticket<?= $feedbackTotal === 1 ? '' : 's'; ?></p>
                        </div>
                    </div>
                </section>

                <section class="feedback-section">
                    <?php if ($hasAnyFeedbackRows): ?>
                        <div class="feedback-section-header">
                            <div class="feedback-section-title-wrap">
                                <i class="far fa-clipboard" aria-hidden="true"></i>
                                <h2 class="feedback-section-title">Recent Feedback</h2>
                            </div>
                        </div>
                        <?php if (count($feedbackRows) > 0): ?>
                            <div class="feedback-swipe-guide" role="note">
                                <i class="fas fa-hand-pointer" aria-hidden="true"></i>
                                <span>Swipe left or right on the table to see all feedback details.</span>
                            </div>
                            <div class="feedback-table-wrap">
                                <table class="feedback-table">
                                    <thead>
                                        <tr>
                                            <th>Ticket ID</th>
                                            <th>Category</th>
                                            <th>Requestor</th>
                                            <th>Department</th>
                                            <th>Feedback</th>
                                            <th>Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody id="feedbackTableBody">
                                        <?php foreach ($feedbackPageRows as $row): ?>
                                        <?php
                                            $ticketId = (int) ($row['ticket_id'] ?? 0);
                                            $displayTicketId = '#' . $ticketId;
                                            $ratingValue = max(1, min(5, (int) ($row['rating'] ?? 0)));
                                            $requesterName = feedback_requester_name($row);
                                            $category = trim((string) ($row['category'] ?? ''));
                                            if ($category === '') $category = trim((string) ($row['subject'] ?? 'General Concern'));
                                            $department = feedback_department_label($row);
                                            $comment = trim((string) ($row['comment'] ?? ''));
                                            $isExcellent = $ratingValue >= 5;
                                        ?>
                                        <tr class="feedback-ticket-row" data-ticket-id="<?= $ticketId; ?>" tabindex="0" role="button" aria-label="Open ticket <?= htmlspecialchars($displayTicketId, ENT_QUOTES, 'UTF-8'); ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;">
                                            <td class="feedback-ticket-id" data-ticket-id="<?= $ticketId; ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;">
                                                <span class="feedback-ticket-link" data-ticket-id="<?= $ticketId; ?>" role="button" tabindex="-1" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;">
                                                    <?= htmlspecialchars($displayTicketId, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td data-ticket-id="<?= $ticketId; ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;">
                                                <span class="feedback-category-pill">
                                                    <i class="fas fa-desktop" aria-hidden="true"></i>
                                                    <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td data-ticket-id="<?= $ticketId; ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;">
                                                <span class="feedback-person">
                                                    <span class="feedback-avatar"><?= htmlspecialchars(feedback_initials($requesterName), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?= htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="feedback-department" data-ticket-id="<?= $ticketId; ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;"><?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="feedback-rating" data-ticket-id="<?= $ticketId; ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;">
                                                <?php if ($isExcellent): ?>
                                                    <span class="feedback-status-pill">
                                                        <i class="fas fa-star" aria-hidden="true"></i>
                                                        Excellent
                                                    </span>
                                                <?php else: ?>
                                                    <div class="feedback-advice-box">
                                                        <i class="far fa-lightbulb" aria-hidden="true"></i>
                                                        <strong>Advice to improve:</strong>
                                                        <span class="feedback-advice-text"><?= htmlspecialchars($comment !== '' ? $comment : 'No comment provided.', ENT_QUOTES, 'UTF-8'); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="feedback-date" data-ticket-id="<?= $ticketId; ?>" onclick="openFeedbackTicketModal(<?= $ticketId; ?>); return false;"><?= htmlspecialchars(date('M d, Y g:i A', strtotime((string) ($row['created_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="feedback-table-footer" aria-label="Feedback pagination">
                                <?= render_feedback_pagination($feedbackPage, $feedbackTotalPages, $feedbackStart, $feedbackEnd, $feedbackTotal); ?>
                            </div>
                        <?php else: ?>
                            <div class="feedback-empty">
                                <i class="fas fa-filter" aria-hidden="true"></i>
                                <h2>No feedback matches these filters.</h2>
                                <p>Try selecting another department or date range.</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="feedback-empty">
                            <i class="far fa-comment-dots" aria-hidden="true"></i>
                            <h2>No feedback received yet.</h2>
                            <p>Feedback from resolved tickets you attended will appear here once requestors submit their ratings.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="feedback-bottom-callout" aria-label="Feedback help">
                    <div class="feedback-callout-left">
                        <span class="feedback-callout-icon" aria-hidden="true"><i class="fas fa-headset"></i></span>
                        <div>
                            <h3>We value your feedback!</h3>
                            <p>Your feedback helps us improve our services and support.</p>
                        </div>
                    </div>
                    <div class="feedback-callout-right">
                        <div>
                            <h3>Need more help?</h3>
                            <p>If you need further assistance, feel free to create a new ticket.</p>
                        </div>
                        <a class="feedback-create-ticket-btn" href="request_ticket.php">
                            <i class="fas fa-ticket-alt" aria-hidden="true"></i>
                            Create Ticket
                        </a>
                    </div>
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
    window.TM_HIDE_UPDATE_TAB = true;
    window.TM_HIDE_ADMIN_CHAT = true;
    window.TM_HIDE_QUICK_TAGS = true;
    window.TM_DEPARTMENT_LABEL_TEXT = 'Assigned Department';
    window.TM_DEPARTMENT_REQUIRED = true;
    window.TM_SHOW_DEPARTMENT_USER_SELECT = true;
    window.TM_DEPARTMENT_USERS_ENDPOINT = 'ajax_department_users.php';
    window.TM_COMPANY_DEPARTMENT_OPTIONS = <?php echo json_encode(
        ticket_company_department_option_map(),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
    </script>
    <script src="../js/ticket-modal.js?v=<?php echo time(); ?>"></script>
    <script>
    function openFeedbackTicketModal(ticketId) {
        var parsedTicketId = parseInt(ticketId || 0, 10);
        if (!parsedTicketId) {
            return;
        }
        if (window.TMTicketModal && typeof window.TMTicketModal.open === 'function') {
            window.TMTicketModal.open(parsedTicketId);
        }
    }

    document.addEventListener('click', function(event) {
        var target = event.target && event.target.closest ? event.target.closest('[data-ticket-id]') : null;
        if (!target) {
            return;
        }
        var row = target.closest('.feedback-ticket-row[data-ticket-id]');
        if (!row) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        openFeedbackTicketModal(target.getAttribute('data-ticket-id') || row.getAttribute('data-ticket-id'));
    }, true);

    document.addEventListener('keydown', function(event) {
        var row = event.target && event.target.closest ? event.target.closest('.feedback-ticket-row[data-ticket-id]') : null;
        if (!row || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }
        event.preventDefault();
        openFeedbackTicketModal(row.getAttribute('data-ticket-id'));
    });

    </script>
    <script src="../js/employee-dashboard.js"></script>
</body>
</html>


