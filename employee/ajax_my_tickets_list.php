<?php
require_once '../config/database.php';
require_once '../includes/ticket_assignment.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'employee') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

ticket_apply_sla_priority($conn);

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$page = (int) ($_GET['page'] ?? 1);
$limit = (int) ($_GET['limit'] ?? 10);

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM employee_tickets
    WHERE user_id = ?
");
if (!$countStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$countStmt->bind_param("i", $user_id);
$countStmt->execute();
$countResult = $countStmt->get_result();
$countRow = $countResult ? $countResult->fetch_assoc() : null;
$countStmt->close();

$total = (int) ($countRow['total'] ?? 0);
$totalPages = (int) ceil($total / $limit);
if ($totalPages < 1) $totalPages = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT *
    FROM employee_tickets
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ?, ?
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'System error']);
    exit;
}

$stmt->bind_param("iii", $user_id, $offset, $limit);
$stmt->execute();
$result = $stmt->get_result();

$rowsHtml = '';
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $rowsHtml .= '<tr class="ticket-row" data-id="' . (int) $row['id'] . '" style="cursor:pointer;">';
        $rowsHtml .= '<td data-label="ID">#' . (int) $row['id'] . '</td>';
        $rowsHtml .= '<td data-label="Category" class="subject-cell"><strong>' . h((string) ($row['category'] ?? '')) . '</strong></td>';
        $rowsHtml .= '<td data-label="Status"><span class="status-pill status-' . strtolower(str_replace(' ', '-', (string) ($row['status'] ?? ''))) . '">' . h((string) ($row['status'] ?? '')) . '</span></td>';
        $rowsHtml .= '<td data-label="Attachment">';
        if (!empty($row['attachment'])) {
            $rowsHtml .= '<a href="../uploads/' . rawurlencode((string) $row['attachment']) . '" target="_blank" class="attachment-link"><i class="fas fa-paperclip"></i> View</a>';
        } else {
            $rowsHtml .= '<span class="no-file">-</span>';
        }
        $rowsHtml .= '</td>';
        $rowsHtml .= '<td data-label="Date">' . h(date("M d, Y", strtotime((string) ($row['created_at'] ?? 'now')))) . '</td>';
        $rowsHtml .= '</tr>';
    }
} else {
    $rowsHtml = '<tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 40px;"><div class="empty-state"><i class="fas fa-ticket-alt" style="font-size: 48px; margin-bottom: 16px; color: #cbd5e1;"></i><p>No tickets submitted yet.</p></div></td></tr>';
}
$stmt->close();

$showingFrom = $total > 0 ? ($offset + 1) : 0;
$showingTo = min($offset + $limit, $total);

$paginationHtml = '';
if ($total > 0) {
    $paginationHtml .= '<div class="pagination-glass">';
    $paginationHtml .= '<div class="pagination-summary">Showing ' . number_format($showingFrom) . ' - ' . number_format($showingTo) . ' of ' . number_format($total) . ' tickets</div>';
    if ($totalPages > 1) {
        $paginationHtml .= '<a href="#" data-page="' . max(1, $page - 1) . '" class="page-btn prev' . ($page <= 1 ? ' disabled' : '') . '">&lsaquo; Previous</a>';
        $paginationHtml .= '<div class="page-numbers">';

        $window = 2;
        $startPage = max(1, $page - $window);
        $endPage = min($totalPages, $page + $window);

        if ($startPage > 1) {
            $paginationHtml .= '<a href="#" data-page="1" class="page-btn' . ($page === 1 ? ' active' : '') . '">1</a>';
            if ($startPage > 2) {
                $paginationHtml .= '<span class="page-ellipsis">...</span>';
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $paginationHtml .= '<a href="#" data-page="' . $i . '" class="page-btn' . ($i === $page ? ' active' : '') . '">' . $i . '</a>';
        }

        if ($endPage < $totalPages) {
            if ($endPage < ($totalPages - 1)) {
                $paginationHtml .= '<span class="page-ellipsis">...</span>';
            }
            $paginationHtml .= '<a href="#" data-page="' . $totalPages . '" class="page-btn' . ($page === $totalPages ? ' active' : '') . '">' . $totalPages . '</a>';
        }

        $paginationHtml .= '</div>';
        $paginationHtml .= '<a href="#" data-page="' . min($totalPages, $page + 1) . '" class="page-btn next' . ($page >= $totalPages ? ' disabled' : '') . '">Next &rsaquo;</a>';
    }
    $paginationHtml .= '</div>';
}

echo json_encode([
    'ok' => true,
    'rows_html' => $rowsHtml,
    'pagination_html' => $paginationHtml,
    'page' => $page,
    'total_pages' => $totalPages,
]);
