<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';
require_once '../includes/pdf_thumbnail.php';

ticket_receiving_availability_ensure_table($conn);

$lapcDepartments = ticket_receiving_available_departments($conn, '@leadsagri.com');
$pccDepartments = ticket_receiving_available_departments($conn, '@primestocks.ph');
$mhcDepartments = ticket_receiving_available_departments($conn, '@malvedaholdings.com');
$requestTicketCompanyOptions = ticket_receiving_available_company_options($conn);
$requestTicketCompanyOptions = array_map(static function ($label): string {
    $label = trim((string) $label);
    if ($label === 'GPSCI') {
        return 'GPCI';
    }
    return str_replace('Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI', $label);
}, $requestTicketCompanyOptions);
asort($requestTicketCompanyOptions, SORT_NATURAL | SORT_FLAG_CASE);
$requestTicketCompanies = array_keys($requestTicketCompanyOptions);
$selectedAssignedCompany = trim((string) ($_POST['assigned_company'] ?? ''));
if ($selectedAssignedCompany === '' && count($requestTicketCompanyOptions) === 1) {
    $selectedAssignedCompany = (string) array_key_first($requestTicketCompanyOptions);
}
$selectedAssignedGroup = trim((string) ($_POST['assigned_group'] ?? ''));
$initialDepartmentOptions = [];
if ($selectedAssignedCompany === '@leadsagri.com') {
    $initialDepartmentOptions = $lapcDepartments;
} elseif ($selectedAssignedCompany === '@primestocks.ph') {
    $initialDepartmentOptions = $pccDepartments;
} elseif ($selectedAssignedCompany === '@malvedaholdings.com') {
    $initialDepartmentOptions = $mhcDepartments;
}
if ($selectedAssignedGroup === '' && count($initialDepartmentOptions) === 1) {
    $selectedAssignedGroup = (string) ($initialDepartmentOptions[0] ?? '');
}

$requestTicketDefaultCategories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Others'];
$requestTicketMpdcCategories = ['Engineerings', 'Client Referral', 'Others'];
$requestTicketLingapCategories = ['Lakbay Kalusugan Request (Medical Mission)', 'Others'];
$requestTicketOthersOnlyCompanies = ['@primestocks.ph', '@leadstech-corp.com', '@gpsci.net', '@farmasee.ph', '@leads-farmex.com', '@leadsav.com'];
$requestTicketLapcDepartmentCategories = [
    'Admin & Legal' => ['Fleetcard', 'Office Supplies', 'Temporary Vehicle', 'Office Supplies(HO,Warehouse Bulacan,Norza)', 'Repair Concern(HO)', 'Phone Plan / Simcard', 'FleetCard Request', 'Supplies', 'Others'],
    'Banana Farm Operations' => ['Others'],
    'Diagnostics / Lingap' => ['Medical consultations', 'Laboratory Request', 'Medicine Request', 'Back to work Clearance', 'Medical Reimbursement', 'Sick Leave Appliccation/Request', 'Others'],
    'Digital Agri Solutions and Innovations' => ['Others'],
    'E-Commerce' => ['Others'],
    'Executive' => ['Others'],
    'Finance and Accounting' => ['Others'],
    'Institutional Sales (Bidding)' => ['Others'],
    'HR' => ['Attendance & Timekeeping', 'Certificate of Employment', 'Certificate of Leave', 'Incident Report', 'Leave Concern', 'Medical Cash Advance', 'Request for Company Property', 'SSS Sickness and Benefit Concern', 'Training Request', 'Others'],
    'IT' => ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'SAP', 'Software', 'Others'],
    'Machineries' => ['Others'],
    'Management' => ['Others'],
    'Marketing' => ['Marketing Operations', 'Channel & Campaigns', 'Others'],
    'New Business Segment' => ['Others'],
    'Seed Production' => ['Others'],
    'Supply Chain' => [
        'Product / Material Request',
        'Inventory Concern',
        'Delivery / Dispatch Request',
        'Transportation / Trucking Request',
        'Delivery Concern / Exception',
        'Product Return / Retrieval',
        'Documentation Request',
        'Supplier / Vendor Concern',
        'Demand / Replenishment Request',
        'Logistics / Supply Chain Inquiry',
    ],
    'Supply Chain Innovation' => ['Others'],
    'Technical' => ['CPR', 'MSDS', 'Technical Information/ Brochure', 'COA', 'Certificate of Distributorship', 'Certificate of Authorized Dealer', 'Updated Label', 'Product Presentations', 'Others'],
];
$lapcSupplyChainRequestTypes = [
    'Product / Material Request' => ['Request for Seeds', 'Fertilizer', 'Packaging Materials', 'Warehouse Supplies', 'Other Materials'],
    'Inventory Concern' => ['Stock Availability', 'Inventory Discrepancy', 'Missing Stock', 'Excess Stock', 'Damaged Stock', 'Lot/Batch Concern'],
    'Delivery / Dispatch Request' => ['Delivery Scheduling', 'Delivery Rescheduling', 'Urgent Delivery', 'Additional Delivery', 'Delivery Follow-up'],
    'Transportation / Trucking Request' => ['Truck Request', 'Truck Assignment', 'Truck Rescheduling', 'Truck Replacement', 'Delivery Vehicle Concern'],
    'Delivery Concern / Exception' => ['Late Delivery', 'Failed Delivery', 'Wrong Product', 'Short/Over Delivery', 'Damaged Product', 'Missing Documents'],
    'Product Return / Retrieval' => ['Product Return', 'Damaged Return', 'Excess Return', 'Pull-out/Retrieval'],
    'Documentation Request' => ['DR', 'Delivery Documents', 'POD', 'Shipping Documents', 'NSQCS Documents', 'Other Supply Chain Documents'],
    'Supplier / Vendor Concern' => ['Supplier Delivery', 'Supplier Shortage', 'Supplier Delay', 'Product Quality Issue', 'Supplier Coordination'],
    'Demand / Replenishment Request' => ['Stock Replenishment', 'Allocation Request', 'Regional Stock Requirement', 'Urgent Stock Requirement'],
    'Logistics / Supply Chain Inquiry' => ['Delivery Status', 'Inventory Status', 'Order Status', 'Shipment Tracking', 'General Inquiry'],
];
$lapcSupplyChainDetailFields = [
    'Product / Material Request' => ['Product/Material Name', 'SKU/Code', 'Quantity', 'UOM', 'Purpose', 'Required Date', 'Destination'],
    'Inventory Concern' => ['Product', 'SKU', 'Lot/Batch No.', 'System Qty', 'Actual Qty', 'Variance', 'Warehouse', 'Supporting Photo'],
    'Delivery / Dispatch Request' => ['Customer/DOP', 'Delivery Location', 'SO/DR No.', 'Product', 'Quantity', 'Required Delivery Date', 'Priority'],
    'Transportation / Trucking Request' => ['Origin', 'Destination', 'Truck Type', 'Required Date/Time', 'Quantity/CBM/Tonnage', 'Special Requirements'],
    'Delivery Concern / Exception' => ['DR/SO No.', 'Delivery Date', 'Customer/DOP', 'Product', 'Issue Type', 'Quantity Affected', 'Details/Photos'],
    'Product Return / Retrieval' => ['Product', 'Quantity', 'Lot/Batch', 'Reason', 'Origin', 'Return Destination', 'Supporting Documents'],
    'Documentation Request' => ['Document Type', 'Reference No.', 'Product', 'Customer/DOP', 'Delivery Date', 'Required Date'],
    'Supplier / Vendor Concern' => ['Supplier', 'PO No.', 'Product', 'Quantity', 'Expected Date', 'Issue/Concern'],
    'Demand / Replenishment Request' => ['Product', 'Required Quantity', 'Destination/Region', 'Required Date', 'Current Stock', 'Reason'],
    'Logistics / Supply Chain Inquiry' => ['Reference No.', 'Product', 'Location', 'Date', 'Specific Inquiry'],
];
$requestTicketLapcAdminLegalRequestCategories = [
    'Aimi Bing Santos (Bing)' => ['Fleetcard', 'Office Supplies', 'Temporary Vehicle', 'Others'],
    'Ace Loui Rosal (Ace)' => ['Office Supplies(HO,Warehouse Bulacan,Norza)', 'Repair Concern(HO)', 'Others'],
    'Cherry Jane Cabote (CJ)' => ['Phone Plan / Simcard', 'FleetCard Request', 'Supplies', 'Others'],
    'Others' => ['Others'],
];
$requestTicketMhcDepartmentCategories = [
    'Marketing Creatives' => ['Marketing Request', 'Others'],
    'IT' => ['Others'],
    'Executive' => ['Others'],
    'Institutional Sales' => ['Others'],
    'Accounting' => ['Others'],
];
$requestTicketSidebarCompanyMeta = [
    '@farmasee.ph' => ['icon' => 'fa-store', 'tone' => 'emerald'],
    '@leads-farmex.com' => ['icon' => 'fa-seedling', 'tone' => 'green'],
    '@gpsci.net' => ['icon' => 'fa-flask', 'tone' => 'violet'],
    '@leadsagri.com' => ['icon' => 'fa-leaf', 'tone' => 'lime'],
    '@lingapleads.org' => ['icon' => 'fa-hand-holding-heart', 'tone' => 'rose'],
    '@leadstech-corp.com' => ['icon' => 'fa-microchip', 'tone' => 'blue'],
    '@malvedaholdings.com' => ['icon' => 'fa-building', 'tone' => 'amber'],
    '@malvedaproperties.com' => ['icon' => 'fa-helmet-safety', 'tone' => 'cyan'],
    '@primestocks.ph' => ['icon' => 'fa-industry', 'tone' => 'orange'],
];

$requestTicketHideGuidanceCategoriesFor = [
    '@farmasee.ph',
    '@leads-farmex.com',
    '@leadsav.com',
    '@gpsci.net',
    '@leadstech-corp.com',
    '@primestocks.ph',
];
$requestTicketSidebarCompanies = [];
$requestTicketSidebarAllowedCompanies = ['@leadsagri.com', '@malvedaholdings.com', '@malvedaproperties.com', '@lingapleads.org'];
foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel) {
    if (!in_array((string) $companyValue, $requestTicketSidebarAllowedCompanies, true)) {
        continue;
    }
    $hideSidebarCategories = in_array((string) $companyValue, $requestTicketHideGuidanceCategoriesFor, true);
    $sidebarRequiresDepartment = ticket_company_requires_department((string) $companyValue);
    $sidebarDepartments = $sidebarRequiresDepartment
        ? ticket_receiving_available_departments($conn, (string) $companyValue)
        : [];
    if ($companyValue === '@malvedaholdings.com') {
        $sidebarDepartments = array_values(array_filter($sidebarDepartments, static function ($department): bool {
            return (string) $department === 'Marketing Creatives';
        }));
    }

    $sidebarDirectCategories = $requestTicketDefaultCategories;
    if ($companyValue === '@malvedaproperties.com') {
        $sidebarDirectCategories = $requestTicketMpdcCategories;
    } elseif ($companyValue === '@lingapleads.org') {
        $sidebarDirectCategories = $requestTicketLingapCategories;
    }
    if ($hideSidebarCategories) {
        $sidebarDirectCategories = [];
    }

    $sidebarDepartmentRows = [];
    foreach ($sidebarDepartments as $sidebarDepartment) {
        $sidebarCategories = $requestTicketDefaultCategories;
        if ($companyValue === '@malvedaholdings.com' && isset($requestTicketMhcDepartmentCategories[$sidebarDepartment])) {
            $sidebarCategories = $requestTicketMhcDepartmentCategories[$sidebarDepartment];
        } elseif ($companyValue === '@leadsagri.com' && isset($requestTicketLapcDepartmentCategories[$sidebarDepartment])) {
            $sidebarCategories = $requestTicketLapcDepartmentCategories[$sidebarDepartment];
        }
        if ($hideSidebarCategories) {
            $sidebarCategories = [];
        }

        $sidebarDepartmentRows[] = [
            'name' => (string) $sidebarDepartment,
            'categories' => array_values($sidebarCategories),
        ];
    }

    $requestTicketSidebarCompanies[] = [
        'value' => (string) $companyValue,
        'label' => (string) $companyLabel,
        'icon' => (string) ($requestTicketSidebarCompanyMeta[$companyValue]['icon'] ?? 'fa-building'),
        'tone' => (string) ($requestTicketSidebarCompanyMeta[$companyValue]['tone'] ?? 'green'),
        'requires_department' => $sidebarRequiresDepartment,
        'departments' => $sidebarDepartmentRows,
        'categories' => array_values($sidebarDirectCategories),
    ];
}

$requestTicketSidebarOpenCompany = $selectedAssignedCompany !== '' ? $selectedAssignedCompany : '@leadsagri.com';

function find_domain_recipient_ids(mysqli $conn, string $domain): array
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '@') !== 0) return [];

    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE role = 'employee'
          AND LOWER(email) LIKE ?
        ORDER BY is_verified DESC, id ASC
    ");
    if (!$stmt) return [];

    $emailLike = '%' . $domain;
    $stmt->bind_param("s", $emailLike);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    $stmt->close();

    return array_values(array_unique($ids));
}

function request_ticket_admin_legal_recipient_email_map(): array
{
    return [
        'Aimi Bing Santos (Bing)' => 'asantos@leadsagri.com',
        'Ace Loui Rosal (Ace)' => 'arosal@leadsagri.com',
        'Cherry Jane Cabote (CJ)' => 'yang@leadsagri.com',
    ];
}

function request_ticket_user_id_by_email(mysqli $conn, string $email): int
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 0;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    if (!$stmt) return 0;

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ? (int) ($row['id'] ?? 0) : 0;
}

function request_ticket_admin_legal_selected_recipient(mysqli $conn, string $requestFor): array
{
    $emailMap = request_ticket_admin_legal_recipient_email_map();
    $email = strtolower(trim((string) ($emailMap[$requestFor] ?? '')));
    $userId = $email !== '' ? request_ticket_user_id_by_email($conn, $email) : 0;

    return [
        'name' => $requestFor,
        'email' => $email,
        'user_id' => $userId,
    ];
}

function request_ticket_admin_legal_all_recipients(mysqli $conn): array
{
    $recipients = [];
    foreach (request_ticket_admin_legal_recipient_email_map() as $name => $email) {
        $normalizedEmail = strtolower(trim((string) $email));
        if ($normalizedEmail === '' || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $recipients[] = [
            'name' => (string) $name,
            'email' => $normalizedEmail,
            'user_id' => request_ticket_user_id_by_email($conn, $normalizedEmail),
        ];
    }
    return $recipients;
}

function request_ticket_upload_dir(): string
{
    return __DIR__ . '/../uploads';
}

function request_ticket_debug_log(string $message, array $context = []): void
{
    $logDir = request_ticket_upload_dir();
    if (!is_dir($logDir) && !@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
        return;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (count($context) > 0) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }
    @file_put_contents($logDir . '/request_ticket_upload_debug.log', $line . PHP_EOL, FILE_APPEND);
}

function request_ticket_cleanup_uploaded_files(array $files): void
{
    foreach ($files as $file) {
        $storedPath = trim((string) ($file['stored_path'] ?? ''));
        if ($storedPath !== '' && file_exists($storedPath)) {
            @unlink($storedPath);
        }
    }
}

function request_ticket_meta_ensure_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS ticket_request_meta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        meta_key VARCHAR(100) NOT NULL,
        meta_value TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ticket_meta (ticket_id, meta_key),
        INDEX idx_ticket_request_meta_ticket (ticket_id),
        CONSTRAINT fk_ticket_request_meta_ticket FOREIGN KEY (ticket_id) REFERENCES employee_tickets(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function request_ticket_process_upload_field(
    string $fieldName,
    string $label,
    bool $required,
    int $maxFiles,
    int $maxFileBytes,
    array $allowedTypes,
    array $allowedMimes,
    ?int $maxTotalBytes = null,
    ?string $unsupportedTypeError = null,
    ?string $oversizeError = null
): array {
    $unsupportedTypeError = trim((string) $unsupportedTypeError);
    if ($unsupportedTypeError === '') {
        $unsupportedTypeError = 'Please upload only JPG, PNG, PDF, DOC, or DOCX files for ' . $label . '.';
    }
    $oversizeError = trim((string) $oversizeError);
    if ($oversizeError === '') {
        $oversizeError = 'Each ' . $label . ' file must be 10 MB or smaller.';
    }

    if (!isset($_FILES[$fieldName])) {
        if ($required) {
            return ['ok' => false, 'error' => 'Please upload the ' . $label . '.'];
        }
        return ['ok' => true, 'files' => []];
    }

    $names = $_FILES[$fieldName]['name'] ?? [];
    $tmpNames = $_FILES[$fieldName]['tmp_name'] ?? [];
    $sizes = $_FILES[$fieldName]['size'] ?? [];
    $errors = $_FILES[$fieldName]['error'] ?? [];

    if (!is_array($names)) {
        $names = [$names];
        $tmpNames = [$_FILES[$fieldName]['tmp_name'] ?? ''];
        $sizes = [$_FILES[$fieldName]['size'] ?? 0];
        $errors = [$_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE];
    }

    $selectedFiles = 0;
    foreach ($errors as $errorCode) {
        if ((int) $errorCode !== UPLOAD_ERR_NO_FILE) {
            $selectedFiles++;
        }
    }

    if ($required && $selectedFiles === 0) {
        return ['ok' => false, 'error' => 'Please upload the ' . $label . '.'];
    }

    if ($selectedFiles === 0) {
        return ['ok' => true, 'files' => []];
    }

    if ($selectedFiles > $maxFiles) {
        return [
            'ok' => false,
            'error' => $maxFiles === 1
                ? 'Only 1 file is allowed for ' . $label . '.'
                : 'You can upload up to ' . $maxFiles . ' files for ' . $label . '.',
        ];
    }

    $uploadDir = request_ticket_upload_dir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Unable to prepare the upload folder right now.'];
    }
    if (function_exists('ticket_pdf_ensure_upload_guards')) {
        ticket_pdf_ensure_upload_guards();
    }

    $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
    $uploadedFiles = [];
    $totalUploadedBytes = 0;

    foreach ($names as $index => $originalName) {
        $errorCode = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $oversizeError];
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Unable to upload the ' . $label . ' file right now.'];
        }

        $fileName = function_exists('ticket_pdf_sanitize_original_name')
            ? ticket_pdf_sanitize_original_name((string) $originalName)
            : basename(str_replace('\\', '/', trim((string) $originalName)));
        $fileTmp = trim((string) ($tmpNames[$index] ?? ''));
        $fileSize = (int) ($sizes[$index] ?? 0);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileName === '' || !in_array($fileExt, $allowedTypes, true)) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $unsupportedTypeError];
        }

        if ($fileSize <= 0 || $fileSize > $maxFileBytes) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $oversizeError];
        }

        if ($maxTotalBytes !== null && ($totalUploadedBytes + $fileSize) > $maxTotalBytes) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $oversizeError];
        }

        if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
            $mime = (string) $finfo->file($fileTmp);
            $allowed = $allowedMimes[$fileExt] ?? [];
            if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                request_ticket_cleanup_uploaded_files($uploadedFiles);
                return ['ok' => false, 'error' => $unsupportedTypeError];
            }
        }

        $newFileName = time() . '_' . uniqid('', true) . '.' . $fileExt;
        $uploadPath = $uploadDir . '/' . $newFileName;

        if (!move_uploaded_file($fileTmp, $uploadPath)) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Unable to save the ' . $label . ' file right now.'];
        }

        if ($fileExt === 'pdf' && function_exists('ticket_pdf_generate_thumbnail')) {
            ticket_pdf_generate_thumbnail($newFileName);
        }

        $uploadedFiles[] = [
            'stored_name' => $newFileName,
            'original_name' => $label . ' - ' . $fileName,
            'stored_path' => $uploadPath,
        ];
        $totalUploadedBytes += $fileSize;
    }

    if ($required && count($uploadedFiles) === 0) {
        return ['ok' => false, 'error' => 'Please upload the ' . $label . '.'];
    }

    return ['ok' => true, 'files' => $uploadedFiles];
}

function request_ticket_blank_sap_report(): array
{
    return [
        'name' => '',
        'position' => '',
        'address' => '',
        'department' => '',
        'tin' => '',
    ];
}

function request_ticket_extract_sap_reports(array $source): array
{
    $reports = [];
    $structuredReports = $source['sap_reports'] ?? null;

    if (is_array($structuredReports)) {
        foreach ($structuredReports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $normalizedReport = [
                'name' => trim((string) ($report['name'] ?? '')),
                'position' => trim((string) ($report['position'] ?? '')),
                'address' => trim((string) ($report['address'] ?? '')),
                'department' => trim((string) ($report['department'] ?? '')),
                'tin' => trim((string) ($report['tin'] ?? '')),
            ];

            if (implode('', $normalizedReport) === '') {
                continue;
            }

            $reports[] = $normalizedReport;
        }
    }

    if (count($reports) === 0) {
        $legacyReport = [
            'name' => trim((string) ($source['sap_name'] ?? '')),
            'position' => trim((string) ($source['sap_position'] ?? '')),
            'address' => trim((string) ($source['sap_address'] ?? '')),
            'department' => trim((string) ($source['sap_department'] ?? '')),
            'tin' => trim((string) ($source['sap_tin'] ?? '')),
        ];

        if (implode('', $legacyReport) !== '') {
            $reports[] = $legacyReport;
        }
    }

    return $reports;
}

function request_ticket_clean_string_array($value): array
{
    $items = is_array($value) ? $value : [];
    $clean = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $clean[] = $item;
        }
    }
    return array_values(array_unique($clean));
}

function request_ticket_blank_email_creation(): array
{
    return [
        'subsidiary' => '',
        'target_department' => '',
        'name' => '',
        'department' => '',
        'designation' => '',
    ];
}

function request_ticket_extract_email_creations(array $source): array
{
    $entries = [];
    $structuredEntries = $source['email_creations'] ?? null;

    if (is_array($structuredEntries)) {
        foreach ($structuredEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalizedEntry = [
                'subsidiary' => trim((string) ($entry['subsidiary'] ?? '')),
                'target_department' => trim((string) ($entry['target_department'] ?? '')),
                'name' => trim((string) ($entry['name'] ?? '')),
                'department' => trim((string) ($entry['department'] ?? '')),
                'designation' => trim((string) ($entry['designation'] ?? '')),
            ];

            if (implode('', $normalizedEntry) === '') {
                continue;
            }

            $entries[] = $normalizedEntry;
        }
    }

    if (count($entries) === 0) {
        $legacyEntry = [
            'subsidiary' => trim((string) ($source['email_creation_subsidiary'] ?? '')),
            'target_department' => trim((string) ($source['email_creation_target_department'] ?? '')),
            'name' => trim((string) ($source['email_creation_name'] ?? '')),
            'department' => trim((string) ($source['email_creation_department'] ?? '')),
            'designation' => trim((string) ($source['email_creation_designation'] ?? '')),
        ];

        if (implode('', $legacyEntry) !== '') {
            $entries[] = $legacyEntry;
        }
    }

    return $entries;
}

function request_ticket_min_working_deadline(int $workingDays = 3): string
{
    $date = new DateTimeImmutable('today');
    $count = 0;
    while ($count < $workingDays) {
        $date = $date->modify('+1 day');
        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek < 6) {
            $count++;
        }
    }
    return $date->format('Y-m-d');
}

function request_ticket_working_days_between_today(string $targetDate): int
{
    try {
        $target = new DateTimeImmutable($targetDate);
    } catch (Exception $e) {
        return -1;
    }

    $today = new DateTimeImmutable('today');
    if ($target <= $today) {
        return 0;
    }

    $days = 0;
    for ($date = $today->modify('+1 day'); $date <= $target; $date = $date->modify('+1 day')) {
        if ((int) $date->format('N') < 6) {
            $days++;
        }
    }
    return $days;
}

function request_ticket_is_weekend_date(string $targetDate): bool
{
    try {
        $date = new DateTimeImmutable($targetDate);
    } catch (Exception $e) {
        return true;
    }
    return (int) $date->format('N') >= 6;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: employee_login.php");
    exit();
}

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function finish_ticket_submit_response(bool $isAjax, array $payload = []): void
{
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    ignore_user_abort(true);

    if ($isAjax) {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Connection: close');
            header('Content-Encoding: none');
            header('Content-Length: ' . strlen((string) $body));
        }
        echo $body;
    } else {
        if (!headers_sent()) {
            header("Location: my_tickets.php");
        }
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    @flush();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    request_ticket_debug_log('Employee request POST received', [
        'is_ajax' => $isAjax,
        'post_keys' => array_values(array_keys($_POST)),
        'file_keys' => array_values(array_keys($_FILES)),
        'attachments_present' => isset($_FILES['attachments']),
        'attachment_names' => isset($_FILES['attachments']['name']) ? array_values((array) $_FILES['attachments']['name']) : [],
        'attachment_errors' => isset($_FILES['attachments']['error']) ? array_values((array) $_FILES['attachments']['error']) : [],
    ]);
    csrf_validate();

    ticket_ensure_assignment_columns($conn);

    $user_id    = $_SESSION['user_id'];
    $default_categories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Others'];
    $mpdc_categories = ['Engineerings', 'Client Referral', 'Others'];
    $lingap_categories = ['Lakbay Kalusugan Request (Medical Mission)', 'Others'];
    $others_only_companies = ['@primestocks.ph', '@leadstech-corp.com', '@gpsci.net', '@farmasee.ph', '@leads-farmex.com', '@leadsav.com'];
    $lapc_department_categories = [
        'Admin & Legal' => ['Fleetcard', 'Office Supplies', 'Temporary Vehicle', 'Office Supplies(HO,Warehouse Bulacan,Norza)', 'Repair Concern(HO)', 'Phone Plan / Simcard', 'FleetCard Request', 'Supplies', 'Others'],
        'Banana Farm Operations' => ['Others'],
        'Diagnostics / Lingap' => ['Medical consultations', 'Laboratory Request', 'Medicine Request', 'Back to work Clearance', 'Medical Reimbursement', 'Sick Leave Appliccation/Request', 'Others'],
        'Digital Agri Solutions and Innovations' => ['Others'],
        'E-Commerce' => ['Others'],
        'Executive' => ['Others'],
        'Finance and Accounting' => ['Others'],
        'Institutional Sales (Bidding)' => ['Others'],
        'HR' => ['Attendance & Timekeeping', 'Certificate of Employment', 'Certificate of Leave', 'Incident Report', 'Leave Concern', 'Medical Cash Advance', 'Request for Company Property', 'SSS Sickness and Benefit Concern', 'Training Request', 'Others'],
        'IT' => ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'SAP', 'Software', 'Others'],
        'Machineries' => ['Others'],
        'Management' => ['Others'],
        'Marketing' => ['Marketing Operations', 'Channel & Campaigns', 'Others'],
        'New Business Segment' => ['Others'],
        'Seed Production' => ['Others'],
        'Supply Chain' => [
            'Product / Material Request',
            'Inventory Concern',
            'Delivery / Dispatch Request',
            'Transportation / Trucking Request',
            'Delivery Concern / Exception',
            'Product Return / Retrieval',
            'Documentation Request',
            'Supplier / Vendor Concern',
            'Demand / Replenishment Request',
            'Logistics / Supply Chain Inquiry',
        ],
        'Supply Chain Innovation' => ['Others'],
        'Technical' => ['CPR', 'MSDS', 'Technical Information/ Brochure', 'COA', 'Certificate of Distributorship', 'Certificate of Authorized Dealer', 'Updated Label', 'Product Presentations', 'Others'],
    ];
    $lapc_admin_legal_request_categories = [
        'Aimi Bing Santos (Bing)' => ['Fleetcard', 'Office Supplies', 'Temporary Vehicle', 'Others'],
        'Ace Loui Rosal (Ace)' => ['Office Supplies(HO,Warehouse Bulacan,Norza)', 'Repair Concern(HO)', 'Others'],
        'Cherry Jane Cabote (CJ)' => ['Phone Plan / Simcard', 'FleetCard Request', 'Supplies', 'Others'],
    ];
    $mhc_department_categories = [
        'Marketing Creatives' => ['Marketing Request', 'Others'],
        'IT' => ['Others'],
        'Executive' => ['Others'],
        'Institutional Sales' => ['Others'],
        'Accounting' => ['Others'],
    ];
    $category = trim((string) ($_POST['category'] ?? ''));
    $admin_legal_request_for = trim((string) ($_POST['admin_legal_request_for'] ?? ''));
    $request_subject_title = trim((string) ($_POST['request_subject_title'] ?? ''));
    $hr_concern_type = trim((string) ($_POST['hr_concern_type'] ?? ''));
    $hr_concern_type_other = trim((string) ($_POST['hr_concern_type_other'] ?? ''));
    $medical_cash_purpose = trim((string) ($_POST['medical_cash_purpose'] ?? ''));
    $medical_cash_amount = trim((string) ($_POST['medical_cash_amount'] ?? ''));
    $medical_cash_date_needed = trim((string) ($_POST['medical_cash_date_needed'] ?? ''));
    $training_request_title = trim((string) ($_POST['training_request_title'] ?? ''));
    $training_request_provider = trim((string) ($_POST['training_request_provider'] ?? ''));
    $training_request_start_date = trim((string) ($_POST['training_request_start_date'] ?? ''));
    $training_request_end_date = trim((string) ($_POST['training_request_end_date'] ?? ''));
    $training_request_venue = trim((string) ($_POST['training_request_venue'] ?? ''));
    $training_request_fee = trim((string) ($_POST['training_request_fee'] ?? ''));
    $company_property_type = trim((string) ($_POST['company_property_type'] ?? ''));
    $company_property_reason = trim((string) ($_POST['company_property_reason'] ?? ''));
    $coe_request_reason = trim((string) ($_POST['coe_request_reason'] ?? ''));
    $coe_request_reason_other = trim((string) ($_POST['coe_request_reason_other'] ?? ''));
    $coe_salary_details = trim((string) ($_POST['coe_salary_details'] ?? ''));
    $coe_preferred_release_date = trim((string) ($_POST['coe_preferred_release_date'] ?? ''));
    $coe_delivery_method = trim((string) ($_POST['coe_delivery_method'] ?? ''));
    $coe_remarks = trim((string) ($_POST['coe_remarks'] ?? ''));
    $certificate_leave_date = trim((string) ($_POST['certificate_leave_date'] ?? ''));
    $certificate_leave_purpose = trim((string) ($_POST['certificate_leave_purpose'] ?? ''));
    $certificate_leave_purpose_other = trim((string) ($_POST['certificate_leave_purpose_other'] ?? ''));
    $incident_summary = trim((string) ($_POST['incident_summary'] ?? ''));
    $incident_gdrive_link = trim((string) ($_POST['incident_gdrive_link'] ?? ''));
    $project_name = trim((string) ($_POST['project_name'] ?? ''));
    $area_code = trim((string) ($_POST['area_code'] ?? ''));
    $marketing_department = trim((string) ($_POST['marketing_department'] ?? ''));
    $marketing_subcategory = trim((string) ($_POST['marketing_subcategory'] ?? ''));
    $supply_chain_details = is_array($_POST['supply_chain_details'] ?? null) ? $_POST['supply_chain_details'] : [];
    $requested_materials = request_ticket_clean_string_array($_POST['requested_materials'] ?? []);
    $requested_materials_other = trim((string) ($_POST['requested_materials_other'] ?? ''));
    $material_size_unit = trim((string) ($_POST['material_size_unit'] ?? ''));
    $material_size_value = trim((string) ($_POST['material_size_value'] ?? ''));
    $material_size = ($material_size_unit !== '' && $material_size_value !== '')
        ? $material_size_unit . ': ' . $material_size_value
        : trim((string) ($_POST['material_size'] ?? ''));
    $project_deadline = trim((string) ($_POST['project_deadline'] ?? ''));
    $crop = request_ticket_clean_string_array($_POST['crop'] ?? []);
    $crop_other = trim((string) ($_POST['crop_other'] ?? ''));
    $sap_reports = request_ticket_extract_sap_reports($_POST);
    $email_creations = request_ticket_extract_email_creations($_POST);
    $sap_name = $sap_reports[0]['name'] ?? trim((string) ($_POST['sap_name'] ?? ''));
    $sap_position = $sap_reports[0]['position'] ?? trim((string) ($_POST['sap_position'] ?? ''));
    $sap_address = $sap_reports[0]['address'] ?? trim((string) ($_POST['sap_address'] ?? ''));
    $sap_department = $sap_reports[0]['department'] ?? trim((string) ($_POST['sap_department'] ?? ''));
    $sap_tin = $sap_reports[0]['tin'] ?? trim((string) ($_POST['sap_tin'] ?? ''));
    $priority = trim((string) ($_POST['priority'] ?? ''));
    $company = $_SESSION['company'] ?? '';
    if (empty($company)) {
        $c_stmt = $conn->prepare("SELECT company FROM users WHERE id = ?");
        if ($c_stmt) {
            $c_stmt->bind_param("i", $user_id);
            $c_stmt->execute();
            $c_res = $c_stmt->get_result();
            if ($c_row = $c_res->fetch_assoc()) {
                $company = $c_row['company'] ?? $company;
                if (!empty($company)) {
                    $_SESSION['company'] = $company;
                }
            }
            $c_stmt->close();
        }
    }
    $department = $_SESSION['department'] ?? '';
    if (empty($department)) {
        $dept_stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
        if ($dept_stmt) {
            $dept_stmt->bind_param("i", $user_id);
            $dept_stmt->execute();
            $dept_res = $dept_stmt->get_result();
            if ($dept_row = $dept_res->fetch_assoc()) {
                $department = $dept_row['department'] ?? $department;
                if (!empty($department)) {
                    $_SESSION['department'] = $department;
                }
            }
            $dept_stmt->close();
        }
    }
    $assigned_company = isset($_POST['assigned_company']) ? trim((string) $_POST['assigned_company']) : '';
    $assigned_group = isset($_POST['assigned_group']) ? trim((string) $_POST['assigned_group']) : '';
    $assigned_company = ticket_normalize_company($assigned_company);
    $allowed_categories = $default_categories;
    if (in_array($assigned_company, $others_only_companies, true)) {
        $allowed_categories = ['Others'];
    } elseif ($assigned_company === '@malvedaproperties.com') {
        $allowed_categories = $mpdc_categories;
    } elseif ($assigned_company === '@lingapleads.org') {
        $allowed_categories = $lingap_categories;
    } elseif ($assigned_company === '@malvedaholdings.com' && isset($mhc_department_categories[$assigned_group])) {
        $allowed_categories = $mhc_department_categories[$assigned_group];
    } elseif ($assigned_company === '@leadsagri.com' && isset($lapc_department_categories[$assigned_group])) {
        $allowed_categories = $lapc_department_categories[$assigned_group];
    }
    $isLapcAdminLegalTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'Admin & Legal');
    $adminLegalSelectedRecipient = ['name' => '', 'email' => '', 'user_id' => 0];
    $adminLegalBroadcastRecipients = [];
    if ($isLapcAdminLegalTicket) {
        if ($admin_legal_request_for === 'Others') {
            $allowed_categories = ['Others'];
            if ($category === '') {
                $category = 'Others';
            }
            $adminLegalBroadcastRecipients = request_ticket_admin_legal_all_recipients($conn);
            if (count($adminLegalBroadcastRecipients) === 0) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'No Admin & Legal recipients are available for this request.'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                $_SESSION['error'] = 'No Admin & Legal recipients are available for this request.';
                header("Location: request_ticket.php");
                exit();
            }
        } elseif (!isset($lapc_admin_legal_request_categories[$admin_legal_request_for])) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please choose who this Admin & Legal request is for.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please choose who this Admin & Legal request is for.';
            header("Location: request_ticket.php");
            exit();
        }
        if ($admin_legal_request_for !== 'Others') {
            $adminLegalSelectedRecipient = request_ticket_admin_legal_selected_recipient($conn, $admin_legal_request_for);
            if ((int) ($adminLegalSelectedRecipient['user_id'] ?? 0) <= 0) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'The selected Admin & Legal request recipient does not have a registered account.'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                $_SESSION['error'] = 'The selected Admin & Legal request recipient does not have a registered account.';
                header("Location: request_ticket.php");
                exit();
            }
            $allowed_categories = $lapc_admin_legal_request_categories[$admin_legal_request_for];
        }
    }
    $requiresDepartment = ticket_company_requires_department($assigned_company);
    $allowedDepartments = $requiresDepartment ? ticket_company_allowed_groups($assigned_company) : [];
    $routing_group = $requiresDepartment ? trim($assigned_group) : 'IT';
    $assigned_group = $routing_group;
    $assigned_department = $requiresDepartment ? $routing_group : 'IT';
    $description = trim((string) ($_POST['description'] ?? ''));
    $email_request_type = trim((string) ($_POST['email_request_type'] ?? ''));
    $email_creation_subsidiary = $email_creations[0]['subsidiary'] ?? trim((string) ($_POST['email_creation_subsidiary'] ?? ''));
    $email_creation_target_department = $email_creations[0]['target_department'] ?? trim((string) ($_POST['email_creation_target_department'] ?? ''));
    $email_creation_name = $email_creations[0]['name'] ?? trim((string) ($_POST['email_creation_name'] ?? ''));
    $email_creation_department = $email_creations[0]['department'] ?? trim((string) ($_POST['email_creation_department'] ?? ''));
    $email_creation_designation = $email_creations[0]['designation'] ?? trim((string) ($_POST['email_creation_designation'] ?? ''));
    $isLapcHrTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'HR');
    $isLapcItTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'IT');
    $isMhcMarketingTicket = ($assigned_company === '@malvedaholdings.com' && $assigned_group === 'Marketing Creatives');
    $isHrAttendanceCategory = ($isLapcHrTicket && $category === 'Attendance & Timekeeping');
    $isHrLeaveOrOtherCategory = ($isLapcHrTicket && ($category === 'Leave Concern' || $category === 'Others'));
    $isHrSssCategory = ($isLapcHrTicket && $category === 'SSS Sickness and Benefit Concern');
    $isHrMedicalCashAdvance = ($isLapcHrTicket && $category === 'Medical Cash Advance');
    $isHrTrainingRequest = ($isLapcHrTicket && $category === 'Training Request');
    $isHrCompanyPropertyRequest = ($isLapcHrTicket && $category === 'Request for Company Property');
    $isHrCertificateEmploymentRequest = ($isLapcHrTicket && $category === 'Certificate of Employment');
    $isHrCertificateLeaveRequest = ($isLapcHrTicket && $category === 'Certificate of Leave');
    $isHrIncidentReport = ($isLapcHrTicket && $category === 'Incident Report');
    $isLapcItEmailRequest = ($isLapcItTicket && $category === 'Email');
    $isLapcItSapRequest = ($isLapcItTicket && $category === 'SAP');
    $isLapcMarketingTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'Marketing' && ($category === 'Marketing Operations' || $category === 'Channel & Campaigns'));
    $isLapcSupplyChainTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'Supply Chain' && isset($lapcSupplyChainRequestTypes[$category]));
    $requiresKamiAttachment = $isHrAttendanceCategory;

    if ($category === '' || !in_array($category, $allowed_categories, true)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please select a valid category.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please select a valid category.';
        header("Location: request_ticket.php");
        exit();
    }
    if (!in_array($priority, ['Low', 'Medium', 'High'], true)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please choose the level of urgency.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please choose the level of urgency.';
        header("Location: request_ticket.php");
        exit();
    }

    if ($isLapcItEmailRequest) {
        $allowedEmailRequestTypes = ['creation of email', 'forgot password', 'backup of email'];
        if (!in_array($email_request_type, $allowedEmailRequestTypes, true)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please choose the email request type.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please choose the email request type.';
            header("Location: request_ticket.php");
            exit();
        }
        if (
            $email_request_type === 'creation of email'
            && count($email_creations) === 0
        ) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Creation of email details.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Creation of email details.';
            header("Location: request_ticket.php");
            exit();
        }
        if ($email_request_type === 'creation of email') {
            foreach ($email_creations as $email_creation) {
                if (
                    $email_creation['subsidiary'] === ''
                    || $email_creation['name'] === ''
                    || $email_creation['department'] === ''
                    || $email_creation['designation'] === ''
                ) {
                    if ($isAjax) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(400);
                        echo json_encode(['ok' => false, 'error' => 'Please complete each Creation of email card before submitting.'], JSON_UNESCAPED_UNICODE);
                        exit();
                    }
                    $_SESSION['error'] = 'Please complete each Creation of email card before submitting.';
                    header("Location: request_ticket.php");
                    exit();
                }
            }
        }
    }

    if ($isHrAttendanceCategory && $hr_concern_type === '') {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please choose the type of concern.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please choose the type of concern.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($isHrAttendanceCategory && $hr_concern_type === 'Other' && $hr_concern_type_other === '') {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please enter the type of concern.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please enter the type of concern.';
        header("Location: request_ticket.php");
        exit();
    }

    $lapcMarketingSubcategories = [
        'Marketing Operations' => [
            'Promo materials',
            'Samples',
            'Product Return Request (RPRR)',
            'Cash Advance (CA) update',
            'Cash Advance Liquidation (CAL) update',
            'Request for Cheque (RFC) payment update',
            'Claims/ Incentive update - Distributor Programs',
            'Claims / incentive update - Dealer Program',
            'Claims/ incentive update - Farmer Program',
            'Distributor enrollment update',
            'Dealer enrollment update',
            'Farmer enrollment update',
            'Report update - Demand Creation Activities',
            'Report update - Monthly Sales reports',
            'Report update - Crop Status',
            'Report update - Market Inventory Report',
            'KAMI topics/ walk-thru',
        ],
        'Channel & Campaigns' => [
            'Program update - Distributor',
            'Program update - Dealer',
            'Program update - Farmer',
            'Pricing review/ adjustments',
            'Special Projects - Jackpot All Stars',
            'Special Projects - Farmasee Physical Stores',
            'Regional facilitation concerns',
        ],
    ];
    if ($isLapcMarketingTicket && !in_array($marketing_subcategory, $lapcMarketingSubcategories[$category] ?? [], true)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please select a valid sub-category.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please select a valid sub-category.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($isLapcSupplyChainTicket && !in_array($marketing_subcategory, $lapcSupplyChainRequestTypes[$category] ?? [], true)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Please choose a valid Request Type / Concern.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Please choose a valid Request Type / Concern.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($isLapcSupplyChainTicket) {
        foreach ($lapcSupplyChainDetailFields[$category] as $fieldLabel) {
            if (in_array($fieldLabel, ['Supporting Photo', 'Supporting Documents'], true)) continue;
            if (trim((string) ($supply_chain_details[$fieldLabel] ?? '')) === '') {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Please complete all Supply Chain request details.'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                $_SESSION['error'] = 'Please complete all Supply Chain request details.';
                header("Location: request_ticket.php");
                exit();
            }
        }
    }

    if ($isHrSssCategory && $description === '') {
        $description = 'SSS Notification and Benefits Concern submission.';
    }

    $subject = $category . ' Concern';
    if ($isLapcMarketingTicket && $marketing_subcategory !== '') {
        $description = "Sub-Category: " . $marketing_subcategory . "\n\n" . $description;
    }
    if ($isLapcSupplyChainTicket && $marketing_subcategory !== '') {
        $description = "Request Type / Concern: " . $marketing_subcategory . "\n\n" . $description;
        $detailLines = [];
        foreach ($lapcSupplyChainDetailFields[$category] as $fieldLabel) {
            $fieldValue = trim((string) ($supply_chain_details[$fieldLabel] ?? ''));
            if ($fieldValue !== '') $detailLines[] = $fieldLabel . ': ' . $fieldValue;
        }
        if (count($detailLines) > 0) {
            $description = "Supply Chain Details:\n" . implode("\n", $detailLines) . "\n\n" . $description;
        }
    }
    if ($isLapcAdminLegalTicket && $admin_legal_request_for !== '') {
        $description = "Request For: " . $admin_legal_request_for . "\n\n" . $description;
    }
    if ($isHrLeaveOrOtherCategory) {
        if ($request_subject_title === '') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please enter the subject/title of request.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please enter the subject/title of request.';
            header("Location: request_ticket.php");
            exit();
        }
        $subject = $request_subject_title;
    }
    if ($isLapcItEmailRequest && $email_request_type === 'creation of email') {
        $subject = 'Creation of email';
        $description = "Email Request\n"
            . "Email Request Type: Creation of email";
        foreach ($email_creations as $index => $email_creation) {
            $description .= "\n\nEmail " . ($index + 1) . "\n"
                . "Name: " . $email_creation['name'] . "\n"
                . "Designation: " . $email_creation['designation'] . "\n"
                . "Company: " . $email_creation['subsidiary'] . "\n"
                . "Department: " . $email_creation['department'];
        }
    }

    if ($isHrMedicalCashAdvance) {
        if ($medical_cash_purpose === '' || $medical_cash_amount === '' || $medical_cash_date_needed === '') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Medical Cash Advance form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Medical Cash Advance form.';
            header("Location: request_ticket.php");
            exit();
        }

        $subject = 'Medical Cash Advance';
        $description = "Medical Cash Advance Request\n"
            . "Purpose: " . $medical_cash_purpose . "\n"
            . "Amount: " . $medical_cash_amount . "\n"
            . "Date Needed: " . $medical_cash_date_needed;
    }

    if ($isHrTrainingRequest) {
        if (
            $training_request_title === ''
            || $training_request_provider === ''
            || $training_request_start_date === ''
            || $training_request_end_date === ''
            || $training_request_venue === ''
            || $training_request_fee === ''
        ) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Training Request form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Training Request form.';
            header("Location: request_ticket.php");
            exit();
        }
        if (strtotime($training_request_end_date) < strtotime($training_request_start_date)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'End date cannot be earlier than start date.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'End date cannot be earlier than start date.';
            header("Location: request_ticket.php");
            exit();
        }

        $subject = 'Training Request';
        $description = "Training Request Form\n"
            . "Training/Seminar Title: " . $training_request_title . "\n"
            . "Provider/Organizer: " . $training_request_provider . "\n"
            . "Start Date of Training/Seminar: " . $training_request_start_date . "\n"
            . "End Date of Training/Seminar: " . $training_request_end_date . "\n"
            . "Venue of Training/Seminar: " . $training_request_venue . "\n"
            . "Registration Fee: " . $training_request_fee;
    }
    if ($isHrCompanyPropertyRequest) {
        $allowedPropertyTypes = ['Company ID', 'Company Lanyard', 'Company Uniform', 'Business Card'];
        $allowedPropertyReasons = ['Lost', 'Replacement', 'No issuance'];
        if (!in_array($company_property_type, $allowedPropertyTypes, true) || !in_array($company_property_reason, $allowedPropertyReasons, true)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Request for Company Property form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Request for Company Property form.';
            header("Location: request_ticket.php");
            exit();
        }

        $subject = 'Request for Company Property';
        $description = "Request for Company Property\n"
            . "Type of Company Property: " . $company_property_type . "\n"
            . "Reason of Request: " . $company_property_reason;
    }
    if ($isHrCertificateEmploymentRequest) {
        $allowedCoeReasons = ['Bank Loan', 'Car Loan', 'Housing Loan', 'Motor Loan', 'School Requirement', 'Travel - Local', 'Travel - International', 'Other'];
        $allowedSalaryChoices = ['Yes', 'No'];
        $allowedDeliveryMethods = ['Electronic copy only', 'Printed copy to be picked up at HR Office', 'Courier c/o Admin'];
        if (
            !in_array($coe_request_reason, $allowedCoeReasons, true)
            || ($coe_request_reason === 'Other' && $coe_request_reason_other === '')
            || !in_array($coe_salary_details, $allowedSalaryChoices, true)
            || $coe_preferred_release_date === ''
            || !in_array($coe_delivery_method, $allowedDeliveryMethods, true)
            || $coe_remarks === ''
        ) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Certificate of Employment form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Certificate of Employment form.';
            header("Location: request_ticket.php");
            exit();
        }

        $subject = 'Certificate of Employment';
        $reasonLabel = $coe_request_reason === 'Other'
            ? ('Other - ' . $coe_request_reason_other)
            : $coe_request_reason;
        $description = "Certificate of Employment Request Form\n"
            . "Reason of COE Request: " . $reasonLabel . "\n"
            . "Need salary details included: " . $coe_salary_details . "\n"
            . "Preferred Date of Release: " . $coe_preferred_release_date . "\n"
            . "Preferred Delivery Method: " . $coe_delivery_method . "\n"
            . "Remarks or Special Instructions: " . $coe_remarks;
    }
    if ($isHrCertificateLeaveRequest) {
        $allowedCertificateLeavePurposes = ['Travel', 'Others'];
        if (
            $certificate_leave_date === ''
            || !in_array($certificate_leave_purpose, $allowedCertificateLeavePurposes, true)
            || ($certificate_leave_purpose === 'Others' && $certificate_leave_purpose_other === '')
        ) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Certificate of Leave form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Certificate of Leave form.';
            header("Location: request_ticket.php");
            exit();
        }

        $subject = 'Certificate of Leave';
        $certificateLeavePurposeLabel = $certificate_leave_purpose === 'Others'
            ? $certificate_leave_purpose_other
            : $certificate_leave_purpose;
        $description = "Certificate of Leave Request Form\n"
            . "Date of Leave: " . $certificate_leave_date . "\n"
            . "Purpose of Leave: " . $certificateLeavePurposeLabel;
    }
    if ($isHrIncidentReport) {
        if ($incident_summary === '') {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the Incident Report form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the Incident Report form.';
            header("Location: request_ticket.php");
            exit();
        }

        $subject = 'Incident Report';
        $description = "Incident Report\n"
            . "Short Summary of IR: " . $incident_summary;
        if ($incident_gdrive_link !== '') {
            $description .= "\nGdrive Link (Video): " . $incident_gdrive_link;
        }
    }
    if ($isLapcItSapRequest) {
        if (count($sap_reports) === 0) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the SAP form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the SAP form.';
            header("Location: request_ticket.php");
            exit();
        }

        foreach ($sap_reports as $sap_report) {
            if (
                $sap_report['name'] === ''
            ) {
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Please complete each SAP employee report before submitting.'], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                $_SESSION['error'] = 'Please complete each SAP employee report before submitting.';
                header("Location: request_ticket.php");
                exit();
            }
        }

        $subject = 'SAP';
        $description = "SAP Form";
        foreach ($sap_reports as $index => $sap_report) {
            $description .= "\n\nEmployee Details " . ($index + 1) . "\n"
                . "Name: " . $sap_report['name'] . "\n"
                . "Position: " . $sap_report['position'] . "\n"
                . "Address: " . $sap_report['address'] . "\n"
                . "Department: " . $sap_report['department'] . "\n"
                . "TIN: " . $sap_report['tin'];
        }
    }

    if ($isMhcMarketingTicket) {
        $allowedAreaCodes = [
            '811A', '811B', '812', '813A', '813B', '814A', '814B', '815A', '815B', '815C',
            '821A', '821B', '821C', '822A', '822B', '831A', '831B', '832A', '832B', '833',
            'HEAD OFFICE',
        ];
        $allowedMarketingDepartments = [
            'Marketing Ops',
            'Sales',
            'Technical',
            'Human Resources',
            'PCC/GPCI',
            'Farmex',
            'Farmasee',
            'LTC',
            'MPDC',
            'IT',
            'Admin',
            'Leads AH/EH',
            'Executive/Management',
        ];
        $allowedRequestedMaterials = [
            'Social Media Graphics',
            'Print Materials (Flyers, Brochures)',
            'Video (Short-form)',
            'Banners/Taffetas',
            'Labels',
            'Tarpaulin/Poster',
            'Invitation',
            'Coupons',
            'Sintraboard design',
            'Plotsigns',
            'Promats Design (shirt, cap, etc)',
            'Other',
        ];
        $allowedCrops = [
            'Rice',
            'Lowland Vegetable',
            'Upland Vegetable',
            'Sugarcane',
            'Corn',
            'Mango',
            'Other',
        ];
        $invalidMarketingFields = (
            $project_name === ''
            || !in_array($area_code, $allowedAreaCodes, true)
            || !in_array($marketing_department, $allowedMarketingDepartments, true)
            || count($requested_materials) === 0
            || count(array_diff($requested_materials, $allowedRequestedMaterials)) > 0
            || (in_array('Other', $requested_materials, true) && $requested_materials_other === '')
            || $material_size === ''
            || $project_deadline === ''
            || count($crop) === 0
            || count(array_diff($crop, $allowedCrops)) > 0
            || (in_array('Other', $crop, true) && $crop_other === '')
            || $description === ''
        );
        if ($invalidMarketingFields) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please complete the LAPC Marketing request form.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please complete the LAPC Marketing request form.';
            header("Location: request_ticket.php");
            exit();
        }

        $deadlineTimestamp = strtotime($project_deadline);
        if ($deadlineTimestamp === false || date('Y-m-d', $deadlineTimestamp) !== $project_deadline) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please select a valid project deadline.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please select a valid project deadline.';
            header("Location: request_ticket.php");
            exit();
        }

        $minimumDeadline = request_ticket_min_working_deadline(3);
        if (request_ticket_is_weekend_date($project_deadline) || request_ticket_working_days_between_today($project_deadline) < 3) {
            $deadlineMessage = 'Project Deadline must be at least 3 working days from today. Earliest valid date is ' . date('F j, Y', strtotime($minimumDeadline)) . '.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $deadlineMessage], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = $deadlineMessage;
            header("Location: request_ticket.php");
            exit();
        }

        $requestedMaterialsDisplay = array_values(array_filter($requested_materials, static function($item) {
            return $item !== 'Other';
        }));
        if (in_array('Other', $requested_materials, true) && $requested_materials_other !== '') {
            $requestedMaterialsDisplay[] = 'Other: ' . $requested_materials_other;
        }
        $cropDisplay = array_values(array_filter($crop, static function($item) {
            return $item !== 'Other';
        }));
        if (in_array('Other', $crop, true) && $crop_other !== '') {
            $cropDisplay[] = 'Other: ' . $crop_other;
        }

        $subject = 'Marketing Request - ' . $project_name;
        $description = "MHC Marketing Request\n"
            . "Project Name: " . $project_name . "\n"
            . "Area Code: " . $area_code . "\n"
            . "Department: " . $marketing_department . "\n"
            . "Requested Materials: " . implode(', ', $requestedMaterialsDisplay) . "\n"
            . "Size of Material: " . $material_size . "\n"
            . "Project Deadline: " . $project_deadline . "\n"
            . "Crop: " . implode(', ', $cropDisplay) . "\n"
            . "Brief Description of Request: " . trim((string) ($_POST['description'] ?? ''));
    }

    if ($assigned_company === '' || !ticket_is_valid_company($assigned_company)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid ticket recipient selected.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Invalid ticket recipient selected.';
        header("Location: request_ticket.php");
        exit();
    }
    if (!ticket_receiving_is_company_enabled($conn, $assigned_company)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'The selected company is not currently accepting new tickets.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'The selected company is not currently accepting new tickets.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($requiresDepartment && ($assigned_group === '' || !in_array($assigned_group, $allowedDepartments, true))) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid assigned department selected for the chosen recipient.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Invalid assigned department selected for the chosen recipient.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($requiresDepartment && !ticket_receiving_is_department_enabled($conn, $assigned_company, $assigned_group)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'The selected department is not currently accepting new tickets.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'The selected department is not currently accepting new tickets.';
        header("Location: request_ticket.php");
        exit();
    }
    if ($description === '' && !$isLapcSupplyChainTicket) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Description is required.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'Description is required.';
        header("Location: request_ticket.php");
        exit();
    }

    if ($requiresKamiAttachment || $isHrMedicalCashAdvance || $isHrIncidentReport) {
        $hasKamiAttachment = false;
        if (isset($_FILES['attachments']) && isset($_FILES['attachments']['error']) && is_array($_FILES['attachments']['error'])) {
            foreach ($_FILES['attachments']['error'] as $attachmentError) {
                if ((int) $attachmentError !== UPLOAD_ERR_NO_FILE) {
                    $hasKamiAttachment = true;
                    break;
                }
            }
        }
        if (!$hasKamiAttachment) {
            if ($isHrMedicalCashAdvance) {
                $attachmentRequiredMessage = 'Supporting Information is required for Medical Cash Advance.';
            } elseif ($isHrIncidentReport) {
                $attachmentRequiredMessage = 'Attachment is required for Incident Report.';
            } else {
                $attachmentRequiredMessage = 'Attachment is required for Attendance & Timekeeping.';
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $attachmentRequiredMessage], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = $attachmentRequiredMessage;
            header("Location: request_ticket.php");
            exit();
        }
    }

    if ($requiresDepartment) {
        $assigned_user_ids = ticket_find_assignee_ids($conn, $assigned_company, $routing_group);
    } else {
        $assigned_user_ids = find_domain_recipient_ids($conn, $assigned_company);
    }
    if ($isLapcAdminLegalTicket) {
        $selectedAdminLegalUserId = (int) ($adminLegalSelectedRecipient['user_id'] ?? 0);
        if ($admin_legal_request_for === 'Others') {
            $assigned_user_ids = array_values(array_unique(array_filter(array_map(static function (array $recipient): int {
                return (int) ($recipient['user_id'] ?? 0);
            }, $adminLegalBroadcastRecipients))));
        } else {
            $assigned_user_ids = $selectedAdminLegalUserId > 0 ? [$selectedAdminLegalUserId] : [];
        }
        $assigned_user_ids = array_values(array_unique(array_merge(
            $assigned_user_ids,
            ticket_find_assignee_ids($conn, '@primestocks.ph', 'Admin & Legal', false)
        )));
    }
    // Do not auto-assign request tickets to a specific user on creation.
    // The ticket stays routed to the target company/department and only gets
    // locked to a person once someone replies or changes the status.
    $assigned_user_id = null;
    if (count($assigned_user_ids) === 0) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'No user found for the selected ticket recipient.'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $_SESSION['error'] = 'No user found for the selected ticket recipient.';
        header("Location: request_ticket.php");
        exit();
    }

    $companyAliasesMap = [
        'MHC' => ['MHC', 'Malveda Holdings Corporation - MHC'],
        'GPCI' => ['GPCI', 'GPCI', 'Golden Primestocks Chemical Inc - GPCI', 'Golden Primestocks Chemical Inc - GPCI'],
        'LAPC' => ['LAPC', 'Leads Animal Health - LAH', 'LEADS Animal Health - LAH'],
        'PCC' => ['PCC', 'Primestocks Chemical Corporation - PCC', 'FARMASEE'],
        'MPDC' => ['MPDC', 'Malveda Properties & Development Corporation - MPDC'],
        'LINGAP' => ['LINGAP', 'LINGAP LEADS FOUNDATION - Lingap'],
        'LTC' => ['LTC', 'Leads Tech Corporation - LTC'],
        'FARMEX' => ['FARMEX', 'Farmex Corp'],
    ];
    $assigned_company_key = strtoupper(trim((string) $assigned_company));
    $companyAliases = [$assigned_company];
    if ($assigned_company_key === 'FARMEX CORP') $assigned_company_key = 'FARMEX';
    if ($assigned_company_key === 'FARMASEE') $assigned_company_key = 'PCC';
    if (isset($companyAliasesMap[$assigned_company_key])) {
        $companyAliases = array_merge($companyAliases, $companyAliasesMap[$assigned_company_key]);
    }
    $companyAliases = array_values(array_unique(array_filter(array_map('trim', $companyAliases), static function($v){ return $v !== ''; })));

    $attachmentName = NULL;
    $uploadedFiles = [];
    $unsupportedAttachmentMessage = 'Please insert supported files only.';

    /* ================= FILE UPLOAD ================= */

    if (isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        request_ticket_debug_log('Employee attachment upload received', [
            'names' => array_values((array) ($_FILES['attachments']['name'] ?? [])),
            'sizes' => array_values((array) ($_FILES['attachments']['size'] ?? [])),
            'errors' => array_values((array) ($_FILES['attachments']['error'] ?? [])),
        ]);

        $attachmentUploadResult = request_ticket_process_upload_field(
            'attachments',
            'Attachment',
            false,
            5,
            5 * 1024 * 1024,
            ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'],
            [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            ],
            5 * 1024 * 1024,
            $unsupportedAttachmentMessage,
            'Attachment too large. Maximum total size is 5 MB.'
        );

        if (empty($attachmentUploadResult['ok'])) {
            request_ticket_debug_log('Employee attachment upload failed', [
                'error' => trim((string) ($attachmentUploadResult['error'] ?? 'Attachment upload failed.')),
            ]);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => trim((string) ($attachmentUploadResult['error'] ?? 'Attachment upload failed.'))], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = trim((string) ($attachmentUploadResult['error'] ?? 'Attachment upload failed.'));
            header("Location: request_ticket.php");
            exit();
        }

        foreach ((array) ($attachmentUploadResult['files'] ?? []) as $uploadedAttachmentFile) {
            $uploadedFiles[] = $uploadedAttachmentFile;
            if ($attachmentName === NULL) {
                $attachmentName = (string) ($uploadedAttachmentFile['stored_name'] ?? '');
            }
        }

        request_ticket_debug_log('Employee attachment upload saved', [
            'stored_names' => array_values(array_map(static function ($file): string {
                return (string) ($file['stored_name'] ?? '');
            }, (array) ($attachmentUploadResult['files'] ?? []))),
        ]);
    }

    if ($isHrSssCategory) {
        $sssAllowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
        $sssAllowedMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ];
        $sssUploadConfigs = [
            ['field' => 'sss_sickness_form', 'label' => 'Accomplished SSS Sickness Form', 'required' => true, 'max_files' => 1],
            ['field' => 'sss_medical_procedures', 'label' => 'Medical Procedures', 'required' => true, 'max_files' => 5],
            ['field' => 'sss_laboratory_results', 'label' => 'Laboratory Results', 'required' => true, 'max_files' => 5],
            ['field' => 'sss_medical_certificates', 'label' => 'Medical Certificates', 'required' => true, 'max_files' => 5],
            ['field' => 'sss_discharge_summary', 'label' => 'Discharge Summary/Proof', 'required' => true, 'max_files' => 5],
        ];

        foreach ($sssUploadConfigs as $config) {
            $uploadResult = request_ticket_process_upload_field(
                (string) $config['field'],
                (string) $config['label'],
                !empty($config['required']),
                (int) $config['max_files'],
                10 * 1024 * 1024,
                $sssAllowedTypes,
                $sssAllowedMimes
            );

            if (empty($uploadResult['ok'])) {
                request_ticket_cleanup_uploaded_files($uploadedFiles);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => trim((string) ($uploadResult['error'] ?? 'Please complete the required SSS attachments.'))], JSON_UNESCAPED_UNICODE);
                    exit();
                }
                $_SESSION['error'] = trim((string) ($uploadResult['error'] ?? 'Please complete the required SSS attachments.'));
                header("Location: request_ticket.php");
                exit();
            }

            foreach ((array) ($uploadResult['files'] ?? []) as $uploadedSssFile) {
                $uploadedFiles[] = $uploadedSssFile;
                if ($attachmentName === NULL) {
                    $attachmentName = (string) ($uploadedSssFile['stored_name'] ?? '');
                }
            }
        }
    }

    /* ================= INSERT INTO DATABASE ================= */

    $stmt = $conn->prepare("
        INSERT INTO employee_tickets
        (user_id, subject, category, priority, company, department, assigned_department, assigned_company, assigned_group, assigned_user_id, description, attachment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if(!$stmt){
        die("Prepare Failed: " . $conn->error);
    }

    $stmt->bind_param(
        "issssssssiss",
        $user_id,
        $subject,
        $category,
        $priority,
        $company,
        $department,
        $assigned_department,
        $assigned_company,
        $assigned_group,
        $assigned_user_id,
        $description,
        $attachmentName
    );

    if(!$stmt->execute()){
        die("Execute Failed: " . $stmt->error);
    }
    
    $ticket_id = $stmt->insert_id;

    $stmt->close();

    $initialAssignmentLabel = notif_assignment_target_label((string) $assigned_company, (string) $assigned_department, $requiresDepartment ? 'the selected department' : 'the selected recipient');
    ticket_record_activity($conn, (int) $ticket_id, 'assignment_created', 'Assigned to ' . $initialAssignmentLabel);

    request_ticket_meta_ensure_table($conn);
    $ticketMeta = [];
    if ($isLapcHrTicket && $hr_concern_type !== '') {
        $ticketMeta['hr_concern_type'] = $hr_concern_type;
    }
    if ($isHrAttendanceCategory && $hr_concern_type === 'Other' && $hr_concern_type_other !== '') {
        $ticketMeta['hr_concern_type_other'] = $hr_concern_type_other;
    }
    if ($isHrMedicalCashAdvance) {
        $ticketMeta['medical_cash_purpose'] = $medical_cash_purpose;
        $ticketMeta['medical_cash_amount'] = $medical_cash_amount;
        $ticketMeta['medical_cash_date_needed'] = $medical_cash_date_needed;
    }
    if ($isHrTrainingRequest) {
        $ticketMeta['training_request_title'] = $training_request_title;
        $ticketMeta['training_request_provider'] = $training_request_provider;
        $ticketMeta['training_request_start_date'] = $training_request_start_date;
        $ticketMeta['training_request_end_date'] = $training_request_end_date;
        $ticketMeta['training_request_venue'] = $training_request_venue;
        $ticketMeta['training_request_fee'] = $training_request_fee;
    }
    if ($isHrCompanyPropertyRequest) {
        $ticketMeta['company_property_type'] = $company_property_type;
        $ticketMeta['company_property_reason'] = $company_property_reason;
    }
    if ($isHrCertificateEmploymentRequest) {
        $ticketMeta['coe_request_reason'] = $coe_request_reason;
        $ticketMeta['coe_request_reason_other'] = $coe_request_reason_other;
        $ticketMeta['coe_salary_details'] = $coe_salary_details;
        $ticketMeta['coe_preferred_release_date'] = $coe_preferred_release_date;
        $ticketMeta['coe_delivery_method'] = $coe_delivery_method;
        $ticketMeta['coe_remarks'] = $coe_remarks;
    }
    if ($isHrCertificateLeaveRequest) {
        $ticketMeta['certificate_leave_date'] = $certificate_leave_date;
        $ticketMeta['certificate_leave_purpose'] = $certificate_leave_purpose;
        if ($certificate_leave_purpose === 'Others' && $certificate_leave_purpose_other !== '') {
            $ticketMeta['certificate_leave_purpose_other'] = $certificate_leave_purpose_other;
        }
    }
    if ($isLapcItSapRequest) {
        $ticketMeta['sap_name'] = $sap_name;
        $ticketMeta['sap_position'] = $sap_position;
        $ticketMeta['sap_address'] = $sap_address;
        $ticketMeta['sap_department'] = $sap_department;
        $ticketMeta['sap_tin'] = $sap_tin;
        $ticketMeta['sap_reports'] = json_encode($sap_reports, JSON_UNESCAPED_UNICODE);
    }
    if ($isLapcItEmailRequest) {
        $ticketMeta['email_request_type'] = $email_request_type;
        if ($email_request_type === 'creation of email') {
            $ticketMeta['email_creation_subsidiary'] = $email_creation_subsidiary;
            $ticketMeta['email_creation_target_department'] = $email_creation_target_department;
            $ticketMeta['email_creation_name'] = $email_creation_name;
            $ticketMeta['email_creation_department'] = $email_creation_department;
            $ticketMeta['email_creation_designation'] = $email_creation_designation;
            $ticketMeta['email_creations'] = json_encode($email_creations, JSON_UNESCAPED_UNICODE);
        }
    }
    if ($isLapcMarketingTicket) {
        $ticketMeta['marketing_subcategory'] = $marketing_subcategory;
    }
    if ($isLapcSupplyChainTicket) {
        $ticketMeta['supply_chain_request_type'] = $marketing_subcategory;
        $ticketMeta['supply_chain_details'] = json_encode($supply_chain_details, JSON_UNESCAPED_UNICODE);
    }
    if ($isLapcAdminLegalTicket && $admin_legal_request_for !== '') {
        $ticketMeta['admin_legal_request_for'] = $admin_legal_request_for;
    }
    if ($isMhcMarketingTicket) {
        $ticketMeta['project_name'] = $project_name;
        $ticketMeta['area_code'] = $area_code;
        $ticketMeta['marketing_department'] = $marketing_department;
        $ticketMeta['requested_materials'] = json_encode($requested_materials, JSON_UNESCAPED_UNICODE);
        $ticketMeta['requested_materials_other'] = $requested_materials_other;
        $ticketMeta['material_size'] = $material_size;
        $ticketMeta['project_deadline'] = $project_deadline;
        $ticketMeta['crop'] = json_encode($crop, JSON_UNESCAPED_UNICODE);
        $ticketMeta['crop_other'] = $crop_other;
    }
    if ($isHrIncidentReport && $incident_gdrive_link !== '') {
        $ticketMeta['incident_gdrive_link'] = $incident_gdrive_link;
    }
    if (count($ticketMeta) > 0) {
        $metaStmt = $conn->prepare("INSERT INTO ticket_request_meta (ticket_id, meta_key, meta_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        if ($metaStmt) {
            foreach ($ticketMeta as $metaKey => $metaValue) {
                $metaStmt->bind_param("iss", $ticket_id, $metaKey, $metaValue);
                $metaStmt->execute();
            }
            $metaStmt->close();
        }
    }

    if (count($uploadedFiles) > 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS ticket_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) DEFAULT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ticket_id (ticket_id),
            CONSTRAINT fk_ticket_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES employee_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $attStmt = $conn->prepare("INSERT INTO ticket_attachments (ticket_id, stored_name, original_name) VALUES (?, ?, ?)");
        if ($attStmt) {
            foreach ($uploadedFiles as $f) {
                $stored = (string)($f['stored_name'] ?? '');
                $orig = (string)($f['original_name'] ?? '');
                if ($stored === '') continue;
                $attStmt->bind_param("iss", $ticket_id, $stored, $orig);
                $attStmt->execute();
            }
            $attStmt->close();
        }
    }

    /* ================= NOTIFICATIONS & EMAILS ================= */
    // 1. Get User Details
    $user_stmt = $conn->prepare("SELECT name, company FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result();
    $user_data = $user_res->fetch_assoc();
    $user_name = $user_data['name'] ?? 'Unknown User';
    $user_company = $user_data['company'] ?? 'Unknown Company';
    $user_stmt->close();

    // 2. System Notifications
    $ticket_number = notif_ticket_number((int) $ticket_id);
    $ticketStatus = 'Open';
    $statusStmt = $conn->prepare("SELECT status FROM employee_tickets WHERE id = ? LIMIT 1");
    if ($statusStmt) {
        $statusStmt->bind_param("i", $ticket_id);
        $statusStmt->execute();
        $statusRes = $statusStmt->get_result();
        $statusRow = $statusRes ? $statusRes->fetch_assoc() : null;
        $statusStmt->close();
        if ($statusRow && isset($statusRow['status']) && trim((string) $statusRow['status']) !== '') {
            $ticketStatus = (string) $statusRow['status'];
        }
    }

    $notifTargetLabel = $isLapcAdminLegalTicket && $admin_legal_request_for !== ''
        ? $admin_legal_request_for
        : notif_assignment_target_label((string) $assigned_company, (string) $assigned_department, $requiresDepartment ? 'the selected department' : 'the selected recipient');
    $employeeTicketNotifMsg = $isLapcAdminLegalTicket
        ? "New ticket #$ticket_number from $user_name was assigned to you."
        : "New ticket #$ticket_number from $user_name was assigned to your group.";
    $adminTicketNotifMsg = "New ticket #$ticket_number from $user_name was assigned to $notifTargetLabel.";
    foreach ($assigned_user_ids as $notifyUserId) {
        $notifyUserId = (int) $notifyUserId;
        if ($notifyUserId <= 0 || (!$isLapcAdminLegalTicket && $notifyUserId === (int) $user_id)) continue;
        notif_insert_system($conn, $notifyUserId, (int) $ticket_id, $employeeTicketNotifMsg, 'dept_assigned');
    }
    if (!$isLapcAdminLegalTicket) {
        notif_insert_admins($conn, (int) $ticket_id, $adminTicketNotifMsg, 'new_ticket');
    }

    /* ================= SUCCESS RESPONSE ================= */

    $ticketNumber = $ticket_number;
    finish_ticket_submit_response($isAjax, [
        'ok' => true,
        'message' => 'Ticket successfully submitted!',
        'ticket_id' => (int) $ticket_id,
        'ticket_number' => (string) $ticketNumber
    ]);

    $ticketDetails = null;
    $ticketStmt = $conn->prepare("
        SELECT t.subject, t.description, t.assigned_department, t.created_at, u.email, u.name
        FROM employee_tickets t
        JOIN users u ON t.user_id = u.id
        WHERE t.id = ?
    ");
    if ($ticketStmt) {
        $ticketStmt->bind_param("i", $ticket_id);
        $ticketStmt->execute();
        $ticketRes = $ticketStmt->get_result();
        $ticketDetails = $ticketRes ? $ticketRes->fetch_assoc() : null;
        $ticketStmt->close();
    }

    $requesterName = (string) ($ticketDetails['name'] ?? ($user_name ?? ($_SESSION['name'] ?? 'Unknown')));
    $employeeEmail = (string) ($ticketDetails['email'] ?? '');
    $createdAt = (string) ($ticketDetails['created_at'] ?? '');
    $ticketSubject = (string) ($ticketDetails['subject'] ?? $subject);
    $ticketDescription = (string) ($ticketDetails['description'] ?? ($description ?? ''));
    $ticketAssignedDept = (string) ($ticketDetails['assigned_department'] ?? $assigned_department);

    $ticketNumberSafe = htmlspecialchars($ticketNumber);
    $requesterNameSafe = htmlspecialchars($requesterName);
    $ticketSubjectSafe = htmlspecialchars($ticketSubject);
    $ticketDescriptionSafe = nl2br(htmlspecialchars($ticketDescription));
    $ticketAssignedDeptSafe = htmlspecialchars($ticketAssignedDept);
    $createdAtSafe = htmlspecialchars($createdAt);
    $attachments = notif_ticket_email_attachments($conn, (int) $ticket_id, (string) ($attachmentName ?? ''));
    $attachmentSummary = notif_ticket_attachment_summary($attachments);
    $emailTicketDescription = ticket_email_description_for_notification($ticketDescription);

    $assigneeEmailExcludeUserId = $isLapcAdminLegalTicket ? 0 : (int) $user_id;
    $assigneeEmails = ticket_assignee_notification_emails($conn, $assigned_user_ids, $assigned_company, $assigned_group, $assigneeEmailExcludeUserId, $isLapcAdminLegalTicket);
    if ($isLapcAdminLegalTicket && $admin_legal_request_for === 'Others') {
        $broadcastEmails = array_values(array_unique(array_filter(array_map(static function (array $recipient): string {
            return strtolower(trim((string) ($recipient['email'] ?? '')));
        }, $adminLegalBroadcastRecipients), static function (string $email): bool {
            return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        })));
        $assigneeEmails = array_values(array_unique(array_merge($assigneeEmails, $broadcastEmails)));
    }
    if (count($assigneeEmails) > 0) {
        $assigneeLines = [
            "Ticket ID: #$ticketNumber",
            "Category: $category",
            "Requestor: $requesterName",
            "Email: $employeeEmail",
            "Date Submitted: $createdAt",
            "Level of Urgency: $priority",
            "Description:\n$emailTicketDescription"
        ];
        if ($attachmentSummary !== '') {
            $assigneeLines[] = $attachmentSummary;
        }
        $assigneeTpl = notif_email_simple('New Ticket Assigned', $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks((int) $ticket_id));
        notif_email_send($assigneeEmails, "New Ticket Assigned (#$ticketNumber)", (string) $assigneeTpl['html'], (string) $assigneeTpl['text'], $attachments);
    }

    if ($employeeEmail !== '') {
        $employeeSubject = "Ticket Submitted (#$ticketNumber)";
        $employeeLines = [
            "Ticket ID: #$ticketNumber",
            "Category: $category",
            "Requestor: $requesterName",
            "Email: $employeeEmail",
            "Date Submitted: $createdAt",
            "Level of Urgency: $priority",
            "Description:\n$emailTicketDescription"
        ];
        if ($attachmentSummary !== '') {
            $employeeLines[] = $attachmentSummary;
        }
        $employeeTpl = notif_email_simple('Ticket Submitted', $employeeLines, 'View Ticket', notif_ticket_link_employee_tickets((int) $ticket_id));

        $employeeOk = notif_email_send([$employeeEmail], $employeeSubject, (string) $employeeTpl['html'], (string) $employeeTpl['text'], $attachments);
        if (!$employeeOk) {
            error_log('Ticket email failed (employee) | ticketId=' . (string) $ticket_id);
        }
    } else {
        error_log('Ticket email skipped (employee email empty) | ticketId=' . (string) $ticket_id);
    }

    exit();
}

$sapFormEntries = request_ticket_extract_sap_reports($_POST);
if (count($sapFormEntries) === 0) {
    $sapFormEntries = [request_ticket_blank_sap_report()];
}
$emailCreationEntries = request_ticket_extract_email_creations($_POST);
if (count($emailCreationEntries) === 0) {
    $emailCreationEntries = [request_ticket_blank_email_creation()];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Ticket | Leads DeskMetamorph</title>
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body.employee-request-ticket-page,
        body.employee-request-ticket-page input,
        body.employee-request-ticket-page select,
        body.employee-request-ticket-page textarea,
        body.employee-request-ticket-page button,
        body.employee-request-ticket-page option {
            font-family: 'Segoe UI', sans-serif;
        }
        body.employee-request-ticket-page .request-grid-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            align-items: start;
        }
        body.employee-request-ticket-page .request-grid-row.is-single {
            grid-template-columns: 1fr;
        }
        body.employee-request-ticket-page #categoryUrgencyRow.is-admin-legal-layout #adminLegalRequestForContainer {
            order: 1;
        }
        body.employee-request-ticket-page #categoryUrgencyRow.is-admin-legal-layout #urgencyContainer {
            order: 2;
        }
        body.employee-request-ticket-page #categoryUrgencyRow.is-admin-legal-layout #categoryContainer {
            order: 3;
            grid-column: 1 / -1;
        }
        body.employee-request-ticket-page .select-wrapper {
            position: relative;
        }
        body.employee-request-ticket-page .select-wrapper.is-open {
            z-index: 60;
        }
        body.employee-request-ticket-page .custom-select-trigger {
            width: 100%;
            text-align: left;
            cursor: pointer;
        }
        body.employee-request-ticket-page .custom-select-trigger[disabled] {
            cursor: not-allowed;
            opacity: 0.68;
            background: #f8fafc;
        }
        body.employee-request-ticket-page .select-wrapper.is-static .custom-select-trigger {
            cursor: default;
            pointer-events: none;
            opacity: 1;
            background: #ffffff;
            color: #111827;
        }
        body.employee-request-ticket-page .select-wrapper.is-static .select-icon {
            display: none;
        }
        body.employee-request-ticket-page .custom-select-value {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.employee-request-ticket-page .custom-select-menu {
            position: static;
            width: 100%;
            margin-top: 8px;
            max-height: 280px;
            overflow-y: auto;
            background: #ffffff;
            border: 2px solid #73a66f;
            border-radius: 16px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.14);
            padding: 8px 0;
            z-index: 35;
        }
        body.employee-request-ticket-page .custom-select-menu[hidden] {
            display: none;
        }
        body.employee-request-ticket-page #assignedGroupWrapper .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            width: auto;
            margin-top: 0;
            z-index: 90;
        }
        body.employee-request-ticket-page #assignedCompanyWrapper .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            width: auto;
            margin-top: 0;
            z-index: 90;
        }
        body.employee-request-ticket-page #categoryWrapper .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            width: auto;
            margin-top: 0;
            z-index: 90;
        }
        body.employee-request-ticket-page #adminLegalRequestForWrapper .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            width: auto;
            margin-top: 0;
            z-index: 90;
        }
        body.employee-request-ticket-page #marketingSubcategoryWrapper .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            width: auto;
            margin-top: 0;
            z-index: 90;
        }
        body.employee-request-ticket-page #emailRequestTypeWrapper .custom-select-menu,
        body.employee-request-ticket-page #areaCodeWrapper .custom-select-menu,
        body.employee-request-ticket-page #marketingDepartmentWrapper .custom-select-menu,
        body.employee-request-ticket-page #requestedMaterialsGroup .custom-select-menu,
        body.employee-request-ticket-page #cropGroup .custom-select-menu,
        body.employee-request-ticket-page #concernTypeWrapper .custom-select-menu,
        body.employee-request-ticket-page #urgencyWrapper .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            width: auto;
            margin-top: 0;
            z-index: 120;
        }
        body.employee-request-ticket-page .select-wrapper.is-open .select-icon {
            transform: translateY(-50%) rotate(180deg);
        }
        body.employee-request-ticket-page .custom-select-option {
            width: 100%;
            border: 0;
            background: transparent;
            text-align: left;
            padding: 11px 16px;
            color: #0f172a;
            font-size: 15px;
            line-height: 1.35;
            cursor: pointer;
            transition: background 0.14s ease, color 0.14s ease;
        }
        body.employee-request-ticket-page .custom-select-option:hover,
        body.employee-request-ticket-page .custom-select-option:focus-visible {
            background: rgba(27, 94, 32, 0.08);
            color: #1b5e20;
            outline: none;
        }
        body.employee-request-ticket-page .custom-select-option.is-selected {
            background: #1B5E20;
            color: #ffffff;
            font-weight: 400;
            border-radius: 12px;
        }
        body.employee-request-ticket-page .custom-select-native {
            position: absolute;
            inset: 0;
            opacity: 0;
            pointer-events: none;
        }
        body.employee-request-ticket-page .select-wrapper .form-control {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            min-height: 50px;
            padding: 0 44px 0 16px;
            border: 2px solid #73a66f;
            border-radius: 16px;
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page .select-wrapper .form-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.employee-request-ticket-page .form-control:focus,
        body.employee-request-ticket-page .form-group input:focus,
        body.employee-request-ticket-page .form-group textarea:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.employee-request-ticket-page .select-wrapper .select-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #374151;
            font-size: 14px;
            pointer-events: none;
        }
        body.employee-request-ticket-page .required-asterisk {
            color: #dc2626;
        }
        body.employee-request-ticket-page textarea.form-control {
            resize: none;
            border: 2px solid #73a66f;
            border-radius: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page .hr-extra-group {
            display: none;
        }
        body.employee-request-ticket-page .hr-extra-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page #urgencyContainer {
            display: block !important;
        }
        body.employee-request-ticket-page .sss-benefits-group {
            display: none;
            margin-top: 26px;
            border: 1px solid #dbe4ef;
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        body.employee-request-ticket-page .sss-benefits-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .sss-benefits-note {
            padding: 0;
        }
        body.employee-request-ticket-page .sss-benefits-note-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 800;
            line-height: 1.25;
        }
        body.employee-request-ticket-page .sss-benefits-note-body {
            padding: 18px 24px 20px;
            color: #334155;
            line-height: 1.75;
            font-size: 14px;
            font-family: inherit;
            border-bottom: 1px solid #dbe4ef;
        }
        body.employee-request-ticket-page .sss-benefits-list {
            display: grid;
            gap: 16px;
            padding: 18px 24px 24px;
        }
        body.employee-request-ticket-page .sss-benefits-card {
            border: 1px solid #dbe4ef;
            border-radius: 20px;
            background: #ffffff;
            padding: 20px 22px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page .sss-benefits-card.is-required {
            border-color: #fca5a5;
        }
        body.employee-request-ticket-page .sss-benefits-card-title {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sss-benefits-card-copy {
            margin: 0 0 14px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sss-benefits-upload-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        body.employee-request-ticket-page .sss-benefits-upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 16px;
            border-radius: 12px;
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #166534;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sss-benefits-upload-btn:hover {
            background: #dcfce7;
        }
        body.employee-request-ticket-page .sss-benefits-file-input {
            display: none;
        }
        body.employee-request-ticket-page .sss-benefits-file-name {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            word-break: break-word;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sss-benefits-file-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        body.employee-request-ticket-page .sss-benefits-file-empty {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sss-benefits-file-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            max-width: 100%;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
            font-size: 13px;
            line-height: 1.3;
            box-shadow: 0 6px 14px rgba(22, 101, 52, 0.08);
        }
        body.employee-request-ticket-page .sss-benefits-file-chip-name {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.employee-request-ticket-page .attachment-upload-shell {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px;
            border: 1px solid #dbe4ef;
            border-radius: 16px;
            background: #f8fafc;
            box-sizing: border-box;
            flex-wrap: wrap;
            position: relative;
        }
        body.employee-request-ticket-page .attachment-upload-shell:hover {
            border-color: rgba(27, 94, 32, 0.24);
            background: #ffffff;
        }
        body.employee-request-ticket-page .attachment-upload-shell.is-dragover {
            border-color: #67c86f;
            background: #f4fbf5;
            box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.12);
        }
        body.employee-request-ticket-page .file-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-width: 132px;
            height: 48px;
            padding: 0 18px;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            background: #ecfdf5;
            color: #17643a;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            position: relative;
            z-index: 1;
            pointer-events: auto;
            box-sizing: border-box;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }
        body.employee-request-ticket-page .file-button:hover {
            background: #e6fbef;
            border-color: #86efac;
        }
        body.employee-request-ticket-page .file-button[aria-disabled="true"] {
            opacity: 0.6;
            cursor: not-allowed;
        }
        body.employee-request-ticket-page .file-button svg {
            flex: 0 0 auto;
        }
        body.employee-request-ticket-page .file-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
            opacity: 0;
            pointer-events: none;
        }
        body.employee-request-ticket-page .attachment-file-name {
            color: #475569;
            font-size: 14px;
            text-align: left;
            word-break: break-word;
            flex: 1 1 180px;
            min-width: 0;
        }
        body.employee-request-ticket-page .attachment-help-text {
            display: block;
            margin-top: 8px;
            color: #666666;
            font-size: 13px;
            text-align: left;
            line-height: 1.5;
        }
        body.employee-request-ticket-page #attachment-preview .attachment-remove-button {
            width: 40px !important;
            min-width: 40px !important;
            max-width: 40px !important;
            height: 40px !important;
            min-height: 40px !important;
            padding: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            flex: 0 0 40px !important;
            line-height: 1 !important;
        }
        body.employee-request-ticket-page .sss-benefits-file-chip-link {
            border: none;
            background: transparent;
            padding: 0;
            color: inherit;
            font: inherit;
            cursor: pointer;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        body.employee-request-ticket-page .sss-benefits-file-chip-link:hover {
            color: #14532d;
        }
        body.employee-request-ticket-page .sss-benefits-file-chip-remove {
            width: 22px;
            height: 22px;
            border: none;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            font-size: 12px;
            font-weight: 800;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        body.employee-request-ticket-page .sss-benefits-file-chip-remove:hover {
            background: #bbf7d0;
        }
        body.employee-request-ticket-page .sss-benefits-error {
            display: none;
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #b91c1c;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.45;
        }
        body.employee-request-ticket-page .sss-benefits-error.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .kami-group {
            display: none;
            margin-top: 16px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: visible;
            position: relative;
            z-index: 20;
        }
        body.employee-request-ticket-page .kami-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .kami-banner-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .kami-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            overflow: visible;
            position: relative;
        }
        body.employee-request-ticket-page .kami-list .hr-extra-group {
            margin: 0;
        }
        body.employee-request-ticket-page .kami-list .hr-extra-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .kami-list .form-group label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .kami-list .select-wrapper {
            max-width: 100%;
        }
        body.employee-request-ticket-page #concernTypeContainer {
            position: relative;
            z-index: 40;
        }
        body.employee-request-ticket-page #concernTypeWrapper {
            position: relative;
            z-index: 40;
        }
        body.employee-request-ticket-page #concernTypeWrapper.is-open {
            z-index: 220;
        }
        body.employee-request-ticket-page #concernTypeWrapper .custom-select-menu {
            z-index: 240;
        }
        body.employee-request-ticket-page .medical-cash-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .medical-cash-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .training-request-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .training-request-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .company-property-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .company-property-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .coe-request-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .coe-request-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .col-request-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .col-request-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .incident-report-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .incident-report-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .sap-request-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .sap-request-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .email-request-group {
            display: none;
            margin-top: 18px;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .email-request-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page.email-request-section-active #emailRequestSection {
            margin-top: 18px;
        }
        body.employee-request-ticket-page .marketing-request-group {
            display: none;
            margin-top: 18px;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page .marketing-request-group.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .medical-cash-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .training-request-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .incident-report-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .company-property-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .coe-request-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .col-request-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sap-request-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .email-request-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .marketing-request-head {
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .form-card {
            padding: 0 24px 24px;
            overflow: hidden;
            border-top: none !important;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page .request-ticket-layout {
            display: grid;
            grid-template-columns: minmax(390px, 450px) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }
        body.employee-request-ticket-page .request-ticket-layout > .form-card {
            width: 100%;
            max-width: none;
            margin: 0;
        }
        body.employee-request-ticket-page .request-guidance-sidebar {
            position: sticky;
            top: 104px;
            display: grid;
            gap: 14px;
            min-width: 0;
        }
        body.employee-request-ticket-page .request-guidance-card,
        body.employee-request-ticket-page .request-tips-card {
            overflow: hidden;
            border: 1px solid #dbe4df;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }
        body.employee-request-ticket-page .request-guidance-heading {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 17px 18px 15px;
            border-bottom: 1px solid #e5ebe7;
            background: linear-gradient(180deg, #ffffff 0%, #fbfefc 100%);
        }
        body.employee-request-ticket-page .request-guidance-heading-icon,
        body.employee-request-ticket-page .request-tips-icon {
            width: 28px;
            height: 28px;
            flex: 0 0 28px;
            border: 2px solid #147233;
            border-radius: 999px;
            color: #147233;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        body.employee-request-ticket-page .request-guidance-heading h2,
        body.employee-request-ticket-page .request-tips-title {
            margin: 0;
            color: #14532d;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .request-guidance-heading p {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
        }
        body.employee-request-ticket-page .request-guidance-directory {
            max-height: min(520px, calc(100vh - 400px));
            min-height: 280px;
            overflow-x: hidden;
            overflow-y: auto;
            padding: 10px;
            scrollbar-width: thin;
            scrollbar-color: #9fb1a5 #eef4f0;
        }
        body.employee-request-ticket-page .request-guidance-directory::-webkit-scrollbar {
            width: 7px;
        }
        body.employee-request-ticket-page .request-guidance-directory::-webkit-scrollbar-track {
            background: #eef4f0;
            border-radius: 999px;
        }
        body.employee-request-ticket-page .request-guidance-directory::-webkit-scrollbar-thumb {
            background: #9fb1a5;
            border-radius: 999px;
        }
        body.employee-request-ticket-page .request-company-guide {
            margin: 0 0 8px;
            border: 1px solid #dfe7e2;
            border-radius: 12px;
            background: #ffffff;
        }
        body.employee-request-ticket-page .request-company-guide:last-child {
            margin-bottom: 0;
        }
        body.employee-request-ticket-page .request-company-guide summary {
            position: relative;
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr) 18px;
            gap: 10px;
            align-items: center;
            min-height: 54px;
            padding: 8px 12px;
            cursor: pointer;
            list-style: none;
        }
        body.employee-request-ticket-page .request-company-guide summary::-webkit-details-marker {
            display: none;
        }
        body.employee-request-ticket-page .request-company-guide[open] summary {
            border-bottom: 1px solid #e5ebe7;
            background: #f7fcf8;
        }
        body.employee-request-ticket-page .request-company-icon,
        body.employee-request-ticket-page .request-department-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            background: #eaf8ee;
            color: #147233;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }
        body.employee-request-ticket-page .request-company-icon.is-emerald { background: #e7f8f0; color: #047857; }
        body.employee-request-ticket-page .request-company-icon.is-green { background: #eaf8ee; color: #15803d; }
        body.employee-request-ticket-page .request-company-icon.is-violet { background: #f1eafe; color: #7c3aed; }
        body.employee-request-ticket-page .request-company-icon.is-lime { background: #eff9df; color: #4d7c0f; }
        body.employee-request-ticket-page .request-company-icon.is-rose { background: #fff0f3; color: #e11d48; }
        body.employee-request-ticket-page .request-company-icon.is-blue { background: #eaf2ff; color: #2563eb; }
        body.employee-request-ticket-page .request-company-icon.is-amber { background: #fff7df; color: #b45309; }
        body.employee-request-ticket-page .request-company-icon.is-cyan { background: #e6f8fb; color: #0891b2; }
        body.employee-request-ticket-page .request-company-icon.is-orange { background: #fff0e5; color: #ea580c; }
        body.employee-request-ticket-page .request-company-copy {
            min-width: 0;
        }
        body.employee-request-ticket-page .request-company-name {
            display: block;
            color: #14532d;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .request-company-domain {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            color: #64748b;
            font-size: 12px;
            line-height: 1.3;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.employee-request-ticket-page .request-company-chevron {
            color: #64748b;
            font-size: 11px;
            transition: transform 0.18s ease;
        }
        body.employee-request-ticket-page .request-company-guide[open] .request-company-chevron {
            transform: rotate(90deg);
        }
        body.employee-request-ticket-page .request-department-list {
            display: grid;
            gap: 0;
        }
        body.employee-request-ticket-page .request-department-guide {
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 11px 12px;
            border-bottom: 1px solid #edf1ee;
        }
        body.employee-request-ticket-page .request-department-guide:last-child {
            border-bottom: 0;
        }
        body.employee-request-ticket-page .request-department-icon {
            background: #f1f5f9;
            color: #2563eb;
        }
        body.employee-request-ticket-page .request-department-icon.is-admin { background: #eaf8ee; color: #15803d; }
        body.employee-request-ticket-page .request-department-icon.is-it { background: #eaf2ff; color: #2563eb; }
        body.employee-request-ticket-page .request-department-icon.is-hr { background: #f3e8ff; color: #9333ea; }
        body.employee-request-ticket-page .request-department-icon.is-health { background: #fff0f3; color: #e11d48; }
        body.employee-request-ticket-page .request-department-icon.is-marketing { background: #fce7f3; color: #db2777; }
        body.employee-request-ticket-page .request-department-icon.is-technical { background: #fff0e5; color: #ea580c; }
        body.employee-request-ticket-page .request-department-icon.is-accounting { background: #fff7df; color: #b45309; }
        body.employee-request-ticket-page .request-department-icon.is-supply { background: #e6f8fb; color: #0891b2; }
        body.employee-request-ticket-page .request-department-icon.is-agriculture { background: #eff9df; color: #4d7c0f; }
        body.employee-request-ticket-page .request-department-icon.is-machinery { background: #eef2f7; color: #334155; }
        body.employee-request-ticket-page .request-department-icon.is-management { background: #f1eafe; color: #7c3aed; }
        body.employee-request-ticket-page .request-department-icon.is-sales { background: #e8f4ff; color: #0369a1; }
        body.employee-request-ticket-page .request-department-icon.is-operations { background: #eef2f7; color: #475569; }
        body.employee-request-ticket-page .request-department-icon.is-category { background: #ecfdf3; color: #047857; }
        body.employee-request-ticket-page .request-department-name {
            margin: 0 0 3px;
            color: #1e293b;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.35;
        }
        body.employee-request-ticket-page .request-category-list {
            margin: 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }
        body.employee-request-ticket-page .request-guide-empty {
            margin: 0;
            padding: 13px 14px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.45;
        }
        body.employee-request-ticket-page .request-tips-card {
            padding: 15px 16px 16px;
            border-color: #cfe8d6;
            background: linear-gradient(180deg, #f3fbf5 0%, #eef9f1 100%);
        }
        body.employee-request-ticket-page .request-tips-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .request-tips-icon {
            width: 24px;
            height: 24px;
            flex-basis: 24px;
            border: 0;
            font-size: 16px;
        }
        body.employee-request-ticket-page .request-tips-list {
            display: grid;
            gap: 7px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        body.employee-request-ticket-page .request-tips-list li {
            display: grid;
            grid-template-columns: 15px minmax(0, 1fr);
            gap: 7px;
            color: #475569;
            font-size: 11px;
            line-height: 1.4;
        }
        body.employee-request-ticket-page .request-tips-list i {
            margin-top: 2px;
            color: #15803d;
            font-size: 10px;
        }
        body.employee-request-ticket-page .form-section-title {
            margin: 0 -24px 22px;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        @media (max-width: 1180px) {
            body.employee-request-ticket-page .request-ticket-layout {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .request-guidance-sidebar {
                position: static;
            }
            body.employee-request-ticket-page .request-guidance-directory {
                max-height: 380px;
                min-height: 0;
            }
        }
        @media (min-width: 1181px) {
            body.employee-request-ticket-page .request-ticket-layout {
                align-items: stretch;
            }
            body.employee-request-ticket-page .request-guidance-sidebar,
            body.employee-request-ticket-page .request-ticket-layout > .form-card {
                height: 100%;
            }
            body.employee-request-ticket-page .request-ticket-layout > .form-card > #ticketForm {
                display: flex;
                flex-direction: column;
                min-height: 100%;
            }
            body.employee-request-ticket-page .request-ticket-layout > .form-card .form-actions {
                margin-top: auto;
                padding-top: 40px;
            }
        }
        body.employee-request-ticket-page .medical-cash-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .training-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .incident-report-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .incident-report-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.employee-request-ticket-page .company-property-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .coe-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .col-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .sap-request-list {
            display: grid;
            gap: 18px;
            padding: 22px 32px 16px;
            background: #ffffff;
            border-top: 1px solid rgba(15, 23, 42, 0.10);
        }
        body.employee-request-ticket-page .email-request-list {
            display: grid;
            gap: 14px;
            padding: 22px 30px 30px;
            background: transparent;
        }
        body.employee-request-ticket-page .marketing-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.employee-request-ticket-page .sap-request-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 22px 32px 14px;
        }
        body.employee-request-ticket-page .email-request-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 22px 0 14px;
        }
        body.employee-request-ticket-page .sap-request-panel-copy {
            min-width: 0;
            display: grid;
            gap: 8px;
            justify-items: start;
            text-align: left;
        }
        body.employee-request-ticket-page .email-request-panel-copy {
            min-width: 0;
            display: grid;
            gap: 8px;
            justify-items: start;
            text-align: left;
        }
        body.employee-request-ticket-page .email-request-panel-title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .email-request-copy {
            margin: 0;
            padding: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }
        body.employee-request-ticket-page .sap-request-counter,
        body.employee-request-ticket-page .email-request-counter {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .sap-request-panel-tools,
        body.employee-request-ticket-page .email-request-panel-tools {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        body.employee-request-ticket-page .sap-request-switcher,
        body.employee-request-ticket-page .email-request-switcher {
            min-width: 236px;
        }
        body.employee-request-ticket-page .sap-request-switcher-icon,
        body.employee-request-ticket-page .email-request-switcher-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #334155;
            font-size: 16px;
            pointer-events: none;
        }
        body.employee-request-ticket-page .sap-request-switcher .form-control,
        body.employee-request-ticket-page .email-request-switcher .form-control {
            min-height: 48px;
            padding-left: 44px;
            padding-right: 44px;
            border: 1px solid #d4ddec;
            border-radius: 16px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
            font-weight: 700;
            font-size: 15px;
            color: #0f172a;
            background: #ffffff;
        }
        body.employee-request-ticket-page .training-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.employee-request-ticket-page .medical-cash-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.employee-request-ticket-page .col-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.employee-request-ticket-page .marketing-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.employee-request-ticket-page .marketing-request-details-row {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        body.employee-request-ticket-page .marketing-request-inline-row .supply-chain-full-row {
            grid-column: 1 / -1;
        }
        body.employee-request-ticket-page .marketing-request-inline-row .marketing-crop-card {
            grid-column: 2;
        }
        body.employee-request-ticket-page .marketing-crop-inline {
            margin-top: 18px;
        }
        body.employee-request-ticket-page #marketingRequestSection #project_deadline {
            font-weight: 400;
        }
        body.employee-request-ticket-page .medical-cash-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .training-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .incident-report-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .company-property-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .coe-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .col-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .email-request-card {
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 0;
            box-shadow: none;
        }
        body.employee-request-ticket-page .email-request-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .email-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .email-creation-fields {
            display: none;
            margin-top: 0;
        }
        body.employee-request-ticket-page .email-creation-fields.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .email-request-list {
            display: block;
        }
        body.employee-request-ticket-page #emailRequestList {
            display: grid;
            gap: 18px;
            padding: 22px 0 16px;
            background: #ffffff;
            border-top: 1px solid rgba(15, 23, 42, 0.10);
        }
        body.employee-request-ticket-page .email-description-host {
            margin-top: 18px;
        }
        body.employee-request-ticket-page .email-creation-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 26px 14px;
        }
        body.employee-request-ticket-page .email-creation-inline-row + .email-creation-inline-row {
            margin-top: 24px;
        }
        body.employee-request-ticket-page .email-creation-card {
            border: 1px solid #dbe4ef;
            border-radius: 18px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page .email-creation-card[data-email-card] {
            display: none;
        }
        body.employee-request-ticket-page .email-creation-card[data-email-card].is-active {
            display: block;
        }
        body.employee-request-ticket-page .email-creation-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .email-creation-card > .form-group {
            margin-top: 18px;
        }
        body.employee-request-ticket-page .email-creation-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        body.employee-request-ticket-page .email-creation-card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .email-creation-card .form-control {
            border: 2px solid #73a66f;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            min-height: 50px;
            padding: 0 16px;
            font-size: 15px;
        }
        body.employee-request-ticket-page .email-creation-card .form-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.employee-request-ticket-page .email-creation-card-delete {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            height: 38px;
            padding: 0 12px;
            border: 1px solid #f3b8b8;
            border-radius: 10px;
            background: #fff8f8;
            color: #c24141;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }
        body.employee-request-ticket-page .email-creation-card-delete i {
            margin-right: 6px;
        }
        body.employee-request-ticket-page .email-creation-card-delete:hover {
            background: #fff1f1;
            border-color: #e59f9f;
            color: #b91c1c;
        }
        body.employee-request-ticket-page .email-request-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 14px;
            padding: 18px 0 0;
            margin-top: 0;
            min-height: 44px;
        }
        body.employee-request-ticket-page .email-request-add-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: none;
            border-radius: 12px;
            background: #166534;
            color: #ffffff;
            padding: 14px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(22, 101, 52, 0.18);
            transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }
        body.employee-request-ticket-page .email-request-add-btn:hover {
            background: #14532d;
            box-shadow: 0 10px 20px rgba(20, 83, 45, 0.22);
            transform: translateY(-1px);
        }
        body.employee-request-ticket-page .email-request-add-btn i {
            font-size: 13px;
        }
        body.employee-request-ticket-page .marketing-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .marketing-request-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .marketing-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        /* Supply Chain uses the same two-column form layout without a card around
           every input, keeping the request form less visually busy. */
        body.employee-request-ticket-page #supplyChainDetailsFields > .supply-chain-field {
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
        }
        body.employee-request-ticket-page #supplyChainDetailsFields > .supply-chain-field .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page #supplyChainDetailsFields > .supply-chain-field label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page #supplyChainAttachmentHost {
            margin-top: 14px;
        }
        body.employee-request-ticket-page #supplyChainAttachmentHost #attachmentContainer {
            margin: 0;
        }
        body.employee-request-ticket-page .marketing-request-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        body.employee-request-ticket-page .marketing-request-card-title.is-regular-label {
            font-weight: 400;
        }
        body.employee-request-ticket-page .marketing-request-option-list {
            display: grid;
            gap: 14px;
        }
        body.employee-request-ticket-page .marketing-request-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-weight: 500;
            color: #111827;
            cursor: pointer;
        }
        body.employee-request-ticket-page .marketing-request-option input[type="checkbox"],
        body.employee-request-ticket-page .marketing-request-option input[type="radio"],
        body.employee-request-ticket-page .marketing-request-other-row input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: #16a34a;
            flex: 0 0 auto;
        }
        body.employee-request-ticket-page .marketing-size-option {
            align-items: center;
        }
        body.employee-request-ticket-page .marketing-size-option label {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 130px;
            margin: 0;
            cursor: pointer;
        }
        body.employee-request-ticket-page .marketing-size-value {
            display: none;
            max-width: 220px;
        }
        body.employee-request-ticket-page .marketing-size-value:not(:disabled) {
            display: block;
        }
        body.employee-request-ticket-page .marketing-request-other-row {
            display: none;
            margin-top: 12px;
        }
        body.employee-request-ticket-page .marketing-request-other-row.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .marketing-request-help {
            display: block;
            margin-top: 8px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }
        body.employee-request-ticket-page .marketing-request-error {
            display: none;
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #fecaca;
            background: #fff1f2;
            color: #b91c1c;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.45;
        }
        body.employee-request-ticket-page .marketing-request-error.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .sap-request-card {
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 0;
            box-shadow: none;
        }
        body.employee-request-ticket-page .sap-request-card[data-sap-card] {
            display: none;
        }
        body.employee-request-ticket-page .sap-request-card[data-sap-card].is-active {
            display: block;
        }
        body.employee-request-ticket-page .sap-request-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .sap-request-card label {
            display: block;
            margin-bottom: 12px;
        }
        body.employee-request-ticket-page .sap-request-subhead {
            margin: 0;
            padding: 22px 24px 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.35;
        }
        body.employee-request-ticket-page .sap-request-copy {
            margin: 0;
            padding: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }
        body.employee-request-ticket-page .sap-request-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }
        body.employee-request-ticket-page .sap-request-card-title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 600;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .sap-request-card .form-control {
            border: 2px solid #73a66f;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            min-height: 50px;
            padding: 0 16px;
            font-size: 15px;
        }
        body.employee-request-ticket-page .sap-request-card .form-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.employee-request-ticket-page .sap-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 22px;
            margin-bottom: 18px;
        }
        body.employee-request-ticket-page .sap-request-company-row {
            margin-top: 2px;
            display: block;
        }
        body.employee-request-ticket-page .sap-request-card-delete {
            min-width: 72px;
            height: 38px;
            padding: 0 12px;
            border: 1px solid #f3b8b8;
            border-radius: 10px;
            background: #fff8f8;
            color: #c24141;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }
        body.employee-request-ticket-page .sap-request-card-delete i {
            margin-right: 6px;
        }
        body.employee-request-ticket-page .sap-request-card-delete:hover {
            background: #fff1f1;
            border-color: #e59f9f;
            color: #b91c1c;
        }
        body.employee-request-ticket-page .sap-request-department-wrap {
            display: none;
            width: 100%;
        }
        body.employee-request-ticket-page .sap-request-department-wrap.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .sap-request-department-field {
            display: none;
        }
        body.employee-request-ticket-page .sap-request-department-field.is-visible {
            display: block;
        }
        body.employee-request-ticket-page .sap-request-actions {
            padding: 20px 20px 20px 0;
            margin-top: 0;
            display: flex;
            justify-content: flex-end;
        }
        body.employee-request-ticket-page .sap-request-actions-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            width: auto;
        }
        body.employee-request-ticket-page .sap-request-add-btn {
            min-height: 40px;
            min-width: 168px;
            padding: 0 16px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, #1B5E20 0%, #144a1e 100%);
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.01em;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(27, 94, 32, 0.18);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        body.employee-request-ticket-page .sap-request-add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(27, 94, 32, 0.22);
            filter: brightness(1.03);
        }
        body.employee-request-ticket-page .sap-request-add-btn i {
            margin-right: 8px;
        }
        body.employee-request-ticket-page .col-request-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .col-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .training-request-inline-row .training-request-card {
            min-width: 0;
            margin: 0;
        }
        body.employee-request-ticket-page .medical-cash-inline-row .medical-cash-card {
            min-width: 0;
            margin: 0;
        }
        body.employee-request-ticket-page .col-request-inline-row .col-request-card {
            min-width: 0;
            margin: 0;
        }
        body.employee-request-ticket-page .medical-cash-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .training-request-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .incident-report-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .medical-cash-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .training-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .incident-report-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .incident-report-card .optional-label {
            color: #64748b;
            font-weight: 600;
        }
        body.employee-request-ticket-page .company-property-copy {
            margin: 0;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.7;
        }
        body.employee-request-ticket-page .coe-request-copy {
            margin: 0 0 14px;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.7;
        }
        body.employee-request-ticket-page .company-property-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        body.employee-request-ticket-page .coe-request-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        body.employee-request-ticket-page .company-property-card-title.is-regular-label,
        body.employee-request-ticket-page .coe-request-card-title.is-regular-label {
            font-weight: 400;
        }
        /* Keep all LAPC HR form field text consistent with the Type of Concern field. */
        body.employee-request-ticket-page :is(#kamiBannerContainer, #medicalCashAdvanceSection, #trainingRequestSection, #companyPropertySection, #coeRequestSection, #colRequestSection, #incidentReportSection) :is(label, span, p, input, select, textarea) {
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.45;
        }
        body.employee-request-ticket-page #marketingRequestSection :is(label, span, p, input, select, textarea, button) {
            font-size: 13px;
            font-weight: 600;
        }
        body.employee-request-ticket-page #marketingRequestSection .custom-select-option {
            font-weight: 400;
        }
        body.employee-request-ticket-page .company-property-option-list {
            display: grid;
            gap: 18px;
        }
        body.employee-request-ticket-page .coe-request-option-list {
            display: grid;
            gap: 18px;
        }
        body.employee-request-ticket-page .company-property-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-weight: 500;
            color: #111827;
            cursor: pointer;
        }
        body.employee-request-ticket-page .coe-request-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-weight: 500;
            color: #111827;
            cursor: pointer;
        }
        body.employee-request-ticket-page .company-property-option input[type="radio"] {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: #16a34a;
            flex: 0 0 auto;
        }
        body.employee-request-ticket-page .coe-request-option input[type="radio"] {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: #16a34a;
            flex: 0 0 auto;
        }
        body.employee-request-ticket-page .coe-request-other-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 12px;
            align-items: center;
        }
        body.employee-request-ticket-page .coe-request-other-row .form-control {
            min-width: 0;
        }
        body.employee-request-ticket-page .medical-cash-card-copy {
            margin: 0 0 14px;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.6;
        }
        body.employee-request-ticket-page.medical-cash-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.medical-cash-section-active #attachmentContainer {
            margin: 0;
            padding: 0;
            border: none;
            background: transparent;
            box-shadow: none;
        }
        body.employee-request-ticket-page.medical-cash-section-active #attachmentContainer label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page.medical-cash-section-active #attachmentContainer .form-text {
            display: block;
            margin-top: 8px;
        }
        body.employee-request-ticket-page.training-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.company-property-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.coe-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.col-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.incident-report-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.sap-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.employee-request-ticket-page.marketing-request-section-active #attachmentContainer label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .attachment-preview-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding: 104px 138px 40px;
            background: rgba(0, 0, 0, 0.84);
            backdrop-filter: blur(2px);
            z-index: 10000;
            box-sizing: border-box;
        }
        body.employee-request-ticket-page .attachment-preview-modal.is-visible {
            display: flex;
        }
        body.employee-request-ticket-page .attachment-preview-nav {
            position: absolute;
            top: 50%;
            width: 60px;
            height: 60px;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.72);
            color: #ffffff;
            font-size: 0;
            line-height: 1;
            cursor: pointer;
            z-index: 2;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.24);
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
        }
        body.employee-request-ticket-page .attachment-preview-nav::before {
            content: "";
            display: block;
            width: 14px;
            height: 14px;
            border-top: 4px solid currentColor;
            border-right: 4px solid currentColor;
            box-sizing: border-box;
        }
        body.employee-request-ticket-page .attachment-preview-prev {
            left: 40px;
        }
        body.employee-request-ticket-page .attachment-preview-next {
            right: 40px;
        }
        body.employee-request-ticket-page .attachment-preview-prev::before {
            transform: rotate(-135deg);
            margin-left: 5px;
        }
        body.employee-request-ticket-page .attachment-preview-next::before {
            transform: rotate(45deg);
            margin-right: 5px;
        }
        body.employee-request-ticket-page .attachment-preview-nav:hover {
            background: #16a34a;
            border-color: rgba(187, 247, 208, 0.72);
            color: #ffffff;
            transform: translateY(-50%) scale(1.04);
        }
        body.employee-request-ticket-page .attachment-preview-nav:disabled {
            display: none;
        }
        body.employee-request-ticket-page .attachment-preview-dialog {
            position: relative;
            width: min(1386px, 100%);
            max-height: calc(100vh - 144px);
            display: flex;
            flex-direction: column;
            overflow: visible;
            border-radius: 8px;
            background: transparent;
            box-shadow: none;
        }
        body.employee-request-ticket-page .attachment-preview-modal[data-preview-kind="image"] .attachment-preview-dialog {
            width: fit-content;
            max-width: calc(100vw - 276px);
        }
        body.employee-request-ticket-page .attachment-preview-head {
            position: absolute;
            top: -22px;
            right: -22px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0;
            padding: 0;
            border: 0;
            background: transparent;
            z-index: 3;
            pointer-events: none;
        }
        body.employee-request-ticket-page .attachment-preview-title {
            display: none;
        }
        body.employee-request-ticket-page .attachment-preview-title strong,
        body.employee-request-ticket-page .attachment-preview-title span {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.employee-request-ticket-page .attachment-preview-title strong {
            color: #0f172a;
            font-size: 15px;
            font-weight: 800;
        }
        body.employee-request-ticket-page .attachment-preview-title span {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }
        body.employee-request-ticket-page .attachment-preview-close {
            width: 50px;
            height: 50px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.92);
            color: #ffffff;
            font-size: 26px;
            font-weight: 900;
            line-height: 1;
            cursor: pointer;
            flex: 0 0 auto;
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.30);
            pointer-events: auto;
            text-transform: uppercase;
            transition: background 0.18s ease, transform 0.18s ease;
        }
        body.employee-request-ticket-page .attachment-preview-close:hover {
            background: #dc2626;
            border-color: rgba(254, 202, 202, 0.78);
            color: #ffffff;
            transform: scale(1.04);
        }
        body.employee-request-ticket-page .attachment-preview-body {
            min-height: min(280px, calc(100vh - 144px));
            overflow: auto;
            background: transparent;
            border-radius: 8px;
        }
        body.employee-request-ticket-page .attachment-preview-modal[data-preview-kind="image"] .attachment-preview-body {
            overflow: visible;
        }
        body.employee-request-ticket-page .attachment-preview-body img {
            display: block;
            max-width: calc(100vw - 276px);
            max-height: calc(100vh - 144px);
            margin: 0 auto;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.38);
        }
        body.employee-request-ticket-page .attachment-preview-body iframe {
            display: block;
            width: 100%;
            height: min(760px, calc(100vh - 144px));
            border: 0;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.38);
        }
        body.employee-request-ticket-page .attachment-preview-unavailable {
            min-height: min(520px, calc(100vh - 144px));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            line-height: 1.5;
        }
        body.employee-request-ticket-page .attachment-preview-word {
            display: block;
            width: 100%;
            min-height: min(760px, calc(100vh - 144px));
            padding: 34px 42px;
            overflow: auto;
            background: #f8fafc;
            color: #111827;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 16px;
            font-weight: 400;
            line-height: 1.75;
            text-align: left;
            border-radius: 8px;
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.38);
        }
        body.employee-request-ticket-page .attachment-preview-word p {
            max-width: 820px;
            margin: 0 auto 16px;
            white-space: pre-wrap;
        }
        @media (max-width: 768px) {
            body.employee-request-ticket-page .medical-cash-inline-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .training-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .incident-report-inline-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .col-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .marketing-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .marketing-request-inline-row .marketing-crop-card {
                grid-column: 1;
            }
            body.employee-request-ticket-page .attachment-preview-modal {
                padding: 72px 68px 28px;
            }
            body.employee-request-ticket-page .attachment-preview-modal[data-preview-kind="image"] .attachment-preview-dialog {
                max-width: calc(100vw - 136px);
            }
            body.employee-request-ticket-page .attachment-preview-body img {
                max-width: calc(100vw - 136px);
                max-height: calc(100vh - 100px);
            }
            body.employee-request-ticket-page .attachment-preview-nav {
                width: 44px;
                height: 44px;
            }
            body.employee-request-ticket-page .attachment-preview-prev {
                left: 12px;
            }
            body.employee-request-ticket-page .attachment-preview-next {
                right: 12px;
            }
            body.employee-request-ticket-page .attachment-preview-head {
                top: -18px;
                right: -18px;
            }
            body.employee-request-ticket-page .attachment-preview-close {
                width: 42px;
                height: 42px;
                font-size: 22px;
            }
        }
        body.employee-request-ticket-page .other-request-section {
            margin-top: 18px;
        }
        body.employee-request-ticket-page .other-request-section-head {
            display: none;
        }
        body.employee-request-ticket-page .other-request-section-body {
            display: block;
        }
        body.employee-request-ticket-page.other-section-active .other-request-section {
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.employee-request-ticket-page.other-section-active .other-request-section-head {
            display: block;
            margin: 0;
            padding: 18px 24px;
            background: #1B5E20;
            box-shadow: inset 0 4px 0 #F4C430;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page.other-section-active .other-request-section-body {
            padding: 20px 24px 24px;
        }
        body.employee-request-ticket-page.other-section-active .other-request-section .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page.other-section-active #otherRequestDetailsSection {
            margin-bottom: 0;
            border-bottom: none;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page.other-section-active #otherDescriptionSection {
            margin-top: 0;
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page.other-section-active #otherDescriptionSection .other-request-section-body {
            padding-top: 0;
        }
        body.employee-request-ticket-page.other-section-active .other-request-section textarea.form-control,
        body.employee-request-ticket-page.other-section-active .other-request-section input.form-control {
            border-radius: 14px;
        }
        body.employee-request-ticket-page.other-section-active #otherDescriptionSection #attachmentContainer {
            margin-top: 18px;
        }
        body.employee-request-ticket-page.other-section-active #otherDescriptionSection #attachmentContainer .form-text {
            display: block;
            margin-top: 8px;
        }
        body.employee-request-ticket-page.kami-section-active #kamiBannerContainer {
            margin-bottom: 0;
            border-bottom-left-radius: 0;
            border-bottom-right-radius: 0;
            border-bottom: none;
            box-shadow: none;
        }
        body.employee-request-ticket-page.kami-section-active #kamiBannerContainer .kami-list {
            gap: 0;
            padding-bottom: 0;
        }
        body.employee-request-ticket-page.kami-section-active #otherDescriptionSection {
            margin-top: 0;
        }
        body.employee-request-ticket-page.kami-section-active #descriptionContainer,
        body.employee-request-ticket-page.kami-section-active #attachmentContainer {
            margin-top: 0;
            margin-bottom: 0;
            padding: 18px 24px 0;
            border-left: 1px solid #dbe4ef;
            border-right: 1px solid #dbe4ef;
            background: #ffffff;
            box-shadow: none;
        }
        body.employee-request-ticket-page.kami-section-active #descriptionContainer label,
        body.employee-request-ticket-page.kami-section-active #attachmentContainer label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page.kami-section-active #attachmentContainer {
            padding-bottom: 24px;
            border-bottom: 1px solid #dbe4ef;
            border-bottom-left-radius: 22px;
            border-bottom-right-radius: 22px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        body.employee-request-ticket-page.kami-section-active #attachmentContainer .form-text {
            display: block;
            margin-top: 8px;
        }
        body.employee-request-ticket-page .ticket-modal {
            position: fixed;
            inset: 0;
            background: rgba(71, 85, 105, 0.42);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 24px;
            box-sizing: border-box;
        }
        body.employee-request-ticket-page .ticket-modal.show { display: flex; }
        body.employee-request-ticket-page .ticket-modal-content {
            width: min(500px, calc(100vw - 48px));
            max-width: calc(100vw - 40px);
            height: 260px;
            min-height: 260px;
            background: linear-gradient(180deg, #ffffff 0%, #fcfefd 100%);
            border-radius: 28px;
            padding: 30px 40px 18px;
            text-align: center;
            border: none;
            box-shadow: 0 28px 64px rgba(15, 23, 42, 0.16);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        body.employee-request-ticket-page .ticket-modal-content::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 50% 10%, rgba(190, 242, 100, 0.24), transparent 22%),
                radial-gradient(circle at 50% 18%, rgba(34, 197, 94, 0.1), transparent 18%);
            pointer-events: none;
        }
        body.employee-request-ticket-page .ticket-modal-spinner {
            width: 66px;
            height: 66px;
            margin: 0 auto 24px;
            border-radius: 999px;
            background: conic-gradient(#1b8a43 0deg, #23b256 155deg, #b6e85b 245deg, #1b8a43 360deg);
            display: none;
            align-items: center;
            justify-content: center;
            animation: ticket-loading-spin 1s linear infinite;
            box-shadow: 0 16px 32px rgba(34, 197, 94, 0.22), 0 0 26px rgba(163, 230, 53, 0.18);
            position: relative;
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal-spinner::before {
            content: "";
            width: 40px;
            height: 40px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.96), inset 0 0 0 1px rgba(22, 101, 52, 0.08);
        }
        body.employee-request-ticket-page .ticket-modal-icon {
            width: 66px;
            height: 66px;
            margin: 0 auto 24px;
            border-radius: 999px;
            background: transparent;
            border: 3px solid #d9f0cd;
            color: #1B5E20;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 600;
            box-shadow: 0 0 0 12px rgba(187, 247, 208, 0.22), 0 0 34px rgba(74, 222, 128, 0.28);
            position: relative;
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal-icon.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
            box-shadow: none;
        }
        body.employee-request-ticket-page .ticket-modal-content h3 {
            margin: 0 0 12px;
            padding: 0;
            font-size: 24px;
            font-weight: 600;
            color: #303957;
            line-height: 1.15;
            letter-spacing: -0.03em;
            position: relative;
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal-content p {
            margin: 0 auto 20px;
            color: #697089;
            font-size: 15px;
            font-weight: 500;
            line-height: 1.5;
            max-width: 340px;
            padding: 0;
            position: relative;
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal-progress {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
        }
        body.employee-request-ticket-page .ticket-modal-progress span {
            display: block;
            width: 22%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #1B5E20, #22c55e);
            transition: width 0.35s ease;
        }
        body.employee-request-ticket-page .ticket-modal-status {
            min-height: 28px;
            color: #238948;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.02em;
            padding: 0;
            position: relative;
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
            margin-top: 0;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
            margin-top: 0;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status {
            margin-top: 30px;
            padding-top: 20px;
            width: 100%;
            min-height: 1px;
            border-top: 1px solid #e5e7eb;
            display: block;
            font-size: 0;
            line-height: 0;
            color: transparent;
            overflow: hidden;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status::before {
            display: none;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
            order: 1;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
            order: 3;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status {
            order: 4;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner {
            order: 1;
            width: 64px;
            height: 64px;
            margin: 0 auto 18px;
            border-radius: 999px;
            background: transparent;
            border: 7px solid rgba(34, 197, 94, 0.18);
            border-top-color: #22c55e;
            border-right-color: #16a34a;
            border-bottom-color: rgba(34, 197, 94, 0.26);
            border-left-color: rgba(34, 197, 94, 0.08);
            box-shadow:
                0 0 0 14px rgba(34, 197, 94, 0.08),
                0 0 38px rgba(74, 222, 128, 0.28),
                0 18px 42px rgba(22, 101, 52, 0.12);
            isolation: isolate;
            position: relative;
            animation: follow-up-feedback-spin 1s linear infinite;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner::before {
            content: "";
            position: absolute;
            inset: 9px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow:
                0 0 0 10px rgba(255, 255, 255, 0.98),
                inset 0 0 0 1px rgba(15, 23, 42, 0.03);
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner::after {
            content: "";
            position: absolute;
            inset: -7px;
            border-radius: 999px;
            background: conic-gradient(from 0deg, rgba(255, 255, 255, 0) 0deg 220deg, rgba(255, 255, 255, 0.95) 255deg 290deg, rgba(134, 239, 172, 0.35) 315deg 345deg, rgba(255, 255, 255, 0) 360deg);
            -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 13px), #000 calc(100% - 12px));
            mask: radial-gradient(farthest-side, transparent calc(100% - 13px), #000 calc(100% - 12px));
            animation: follow-up-feedback-spin 0.9s linear infinite;
            pointer-events: none;
            z-index: 0;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-actions {
            margin-top: 0;
            min-height: 0;
            height: 0;
            padding: 0;
            border-top: none;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
            order: 2;
            margin-top: 0;
            margin-bottom: 14px;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.035em;
            color: #0f172a;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content {
            height: auto;
            min-height: 330px;
            padding: 40px 36px 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
            font-size: 16px;
            line-height: 1.55;
            color: #6b7280;
            max-width: 520px;
            margin-bottom: 0;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-icon.success,
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-icon.error {
            order: 1;
            margin: 0 auto 16px;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content h3,
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-content h3 {
            order: 2;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content h3 {
            font-weight: 600;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content p,
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-content p {
            order: 3;
            margin-bottom: 8px;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-actions,
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-actions {
            order: 4;
        }
        body.employee-request-ticket-page .ticket-modal-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: auto;
            width: 100%;
            min-height: 44px;
            padding: 10px 0 0;
            border-top: 1px solid #e6e8ef;
            visibility: hidden;
            opacity: 0;
            pointer-events: none;
            position: relative;
            z-index: 1;
        }
        body.employee-request-ticket-page .ticket-modal-content button {
            width: 136px;
            min-width: 0;
            height: 40px;
            border: 1px solid rgba(20, 74, 30, 0.28);
            background: #1B5E20;
            color: #ffffff;
            border-radius: 12px;
            padding: 0 18px;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
        }
        body.employee-request-ticket-page .ticket-modal-content button:hover {
            background: #144a1e;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner,
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-icon.success,
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-icon.error {
            display: flex;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-actions,
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-actions {
            visibility: visible;
            opacity: 1;
            pointer-events: auto;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content {
            height: auto;
            min-height: 284px;
            padding-bottom: 28px;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-actions {
            margin-top: 14px;
            padding-top: 18px;
            border-top: 1px solid #e6e8ef;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-status {
            display: none;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-progress {
            display: none;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-progress span { width: 100% !important; }
        body.employee-request-ticket-page .ticket-modal-ticket-label,
        body.employee-request-ticket-page .ticket-modal-ticket-number {
            font-weight: 800;
        }
        body.employee-request-ticket-page .ticket-modal-ticket-label {
            color: #3f4861;
        }
        body.employee-request-ticket-page .ticket-modal-ticket-number {
            color: #166534;
            font-weight: 800;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-progress span { background: linear-gradient(90deg, #ef4444, #f97316); }
        @keyframes follow-up-feedback-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            body.employee-request-ticket-page .ticket-modal-content {
                width: 100%;
                max-width: 380px;
                height: 260px;
                min-height: 260px;
                border-radius: 24px;
                padding: 28px 24px 18px;
            }
            body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content {
                height: auto;
                min-height: 276px;
                padding-bottom: 24px;
            }
            body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content {
                min-height: 306px;
                padding: 34px 22px 24px;
            }
            body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
                font-size: 24px;
            }
            body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
                font-size: 15px;
            }
            body.employee-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status {
                margin-top: 24px;
                padding-top: 18px;
            }
            body.employee-request-ticket-page .ticket-modal-content h3 {
                font-size: 18px;
            }
            body.employee-request-ticket-page .ticket-modal-content p,
            body.employee-request-ticket-page .ticket-modal-status {
                font-size: 14px;
            }
            body.employee-request-ticket-page .ticket-modal-spinner,
            body.employee-request-ticket-page .ticket-modal-icon {
                width: 58px;
                height: 58px;
            }
            body.employee-request-ticket-page .ticket-modal-spinner::before {
                width: 34px;
                height: 34px;
            }
            body.employee-request-ticket-page .ticket-modal-icon {
                font-size: 24px;
            }
            body.employee-request-ticket-page .dashboard-container {
                padding: 12px;
            }

            body.employee-request-ticket-page .page-header {
                margin-bottom: 18px !important;
            }

            body.employee-request-ticket-page .form-card {
                padding: 0 16px 16px;
                border-radius: 14px;
                margin: 0;
            }

            body.employee-request-ticket-page .form-section-title {
                margin: 0 -16px 18px;
                padding: 14px 16px;
                background: #1B5E20;
                box-shadow: inset 0 4px 0 #F4C430, inset 0 -1px 0 rgba(255, 255, 255, 0.12);
                color: #ffffff;
                border-radius: 14px 14px 0 0;
                font-size: 16px;
            }

            body.employee-request-ticket-page .form-group {
                margin-bottom: 14px;
            }

            body.employee-request-ticket-page .request-grid-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            body.employee-request-ticket-page .sap-request-inline-row,
            body.employee-request-ticket-page .email-creation-inline-row,
            body.employee-request-ticket-page .sap-request-company-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .sap-request-card-top,
            body.employee-request-ticket-page .email-creation-card-top {
                align-items: flex-start;
                flex-direction: column;
            }
            body.employee-request-ticket-page .sap-request-panel-head,
            body.employee-request-ticket-page .email-request-panel-head {
                flex-direction: column;
                align-items: stretch;
            }
            body.employee-request-ticket-page .sap-request-switcher,
            body.employee-request-ticket-page .email-request-switcher {
                min-width: 0;
                width: 100%;
            }
            body.employee-request-ticket-page .sap-request-add-btn,
            body.employee-request-ticket-page .email-request-add-btn {
                width: 100%;
            }
            body.employee-request-ticket-page .sap-request-actions,
            body.employee-request-ticket-page .email-request-actions {
                padding: 16px 0 16px;
            }

            body.employee-request-ticket-page .form-control,
            body.employee-request-ticket-page .form-group input,
            body.employee-request-ticket-page .form-group select,
            body.employee-request-ticket-page .form-group textarea {
                height: 50px;
                padding: 12px 16px;
                font-size: 15px;
                border-radius: 16px;
                border: 2px solid #73a66f;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            }

            body.employee-request-ticket-page textarea.form-control {
                height: auto;
                min-height: 120px;
                padding: 14px 16px;
                resize: none;
            }

            body.employee-request-ticket-page .file-control {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
                padding: 10px !important;
                border-radius: 10px !important;
                border: 1px dashed #D1D5DB !important;
                background: #F9FAFB !important;
                flex-wrap: wrap;
            }

            body.employee-request-ticket-page .file-button {
                padding: 8px 12px !important;
                border-radius: 8px !important;
                font-size: 14px !important;
            }

            body.employee-request-ticket-page .file-name {
                font-size: 13px;
                flex: 1 1 140px;
                min-width: 0;
            }

            body.employee-request-ticket-page .form-text {
                margin-top: 6px;
                font-size: 11px;
            }

            body.employee-request-ticket-page .form-actions {
                margin-top: 18px;
            }

            body.employee-request-ticket-page .btn-submit {
                display: flex;
                align-items: center;
                justify-content: center;
                text-align: center;
                width: 100%;
                padding: 14px;
                font-size: 16px;
                border-radius: 12px;
                margin-top: 10px;
            }

            body.employee-request-ticket-page .tm-global-chat-fab {
                right: 12px;
                bottom: 80px;
                width: 42px !important;
                max-width: 42px !important;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
                padding: 0 !important;
                border-radius: 999px;
                justify-content: center;
                gap: 0;
            }

            body.employee-request-ticket-page .tm-global-chat-fab .tm-global-chat-label {
                display: none;
            }

            body.employee-request-ticket-page .tm-global-chat-fab i {
                font-size: 16px;
            }
        }

        body.employee-request-ticket-page .mobile-sidebar,
        body.employee-request-ticket-page .mobile-sidebar-overlay {
            display: none;
        }

        @media (max-width: 768px) {
            body.employee-request-ticket-page #navbarCollapse,
            body.employee-request-ticket-page.sidebar-open #navbarCollapse {
                display: none !important;
            }

            body.employee-request-ticket-page.sidebar-open .tm-global-chat-fab {
                opacity: 0;
                pointer-events: none;
                transform: translateY(8px);
            }

            body.employee-request-ticket-page .mobile-sidebar {
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

            body.employee-request-ticket-page .mobile-sidebar.active {
                right: 0;
            }

            body.employee-request-ticket-page .mobile-sidebar-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            body.employee-request-ticket-page .mobile-sidebar-header img {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: #ffffff;
                padding: 4px;
                object-fit: contain;
                flex: 0 0 36px;
            }

            body.employee-request-ticket-page .mobile-sidebar-header span {
                color: #ffffff;
                font-size: 15px;
                font-weight: 700;
                line-height: 1.2;
            }

            body.employee-request-ticket-page .mobile-sidebar a {
                color: #ffffff;
                text-decoration: none;
                font-size: 16px;
                font-weight: 500;
                min-height: 44px;
                display: flex;
                align-items: center;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-request-ticket-page .mobile-sidebar a.active,
            body.employee-request-ticket-page .mobile-sidebar a:hover {
                background: rgba(255, 255, 255, 0.12);
            }

            body.employee-request-ticket-page .mobile-sidebar-footer {
                margin: 0 0 8px;
                padding: 0 0 14px;
                border-top: 0;
                border-bottom: 1px solid rgba(255, 255, 255, 0.18);
                display: flex;
                align-items: center;
                gap: 12px;
                flex: 0 0 auto;
            }

            body.employee-request-ticket-page .mobile-sidebar-icon-link,
            body.employee-request-ticket-page .mobile-sidebar-user-btn {
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

            body.employee-request-ticket-page .mobile-sidebar-icon-link {
                width: 44px;
                min-width: 44px;
                position: relative;
            }

            body.employee-request-ticket-page .mobile-sidebar-icon-link i,
            body.employee-request-ticket-page .mobile-sidebar-user-btn i {
                font-size: 16px;
            }

            body.employee-request-ticket-page .mobile-sidebar-badge {
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

            body.employee-request-ticket-page .mobile-sidebar-user {
                position: relative;
            }

            body.employee-request-ticket-page .mobile-sidebar-user-btn {
                gap: 10px;
                padding: 0 16px;
                cursor: pointer;
            }

            body.employee-request-ticket-page .mobile-sidebar-user-menu {
                position: absolute;
                right: 0;
                top: calc(100% + 10px);
                bottom: auto;
                min-width: 170px;
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 16px 30px rgba(15, 23, 42, 0.18);
                padding: 8px;
                display: none;
                flex-direction: column;
                gap: 4px;
            }

            body.employee-request-ticket-page .mobile-sidebar-user-menu.show {
                display: flex;
            }

            body.employee-request-ticket-page .mobile-sidebar-user-menu a {
                min-height: 40px;
                color: #0f172a;
                font-size: 14px;
                font-weight: 600;
                padding: 10px 12px;
                border-radius: 10px;
            }

            body.employee-request-ticket-page .mobile-sidebar-user-menu a:hover {
                background: #f1f5f9;
            }

            body.employee-request-ticket-page .mobile-sidebar-overlay {
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

            body.employee-request-ticket-page .mobile-sidebar-overlay.active {
                opacity: 1;
                visibility: visible;
            }

            body.employee-request-ticket-page .nav-left,
            body.employee-request-ticket-page .navbar-toggler {
                position: relative;
                z-index: 2105;
            }
        }

        /* Reference-aligned request ticket layout */
        body.employee-request-ticket-page {
            background: #f7f9f8;
        }

        body.employee-request-ticket-page .dashboard-container {
            padding: 22px 8px 42px;
        }

        body.employee-request-ticket-page .content-wrapper {
            width: 100%;
            max-width: none;
            margin: 0 auto;
        }

        body.employee-request-ticket-page .request-page-header {
            width: 100%;
            margin: 0 0 14px !important;
            padding: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transform: none;
        }

        body.employee-request-ticket-page .request-page-header h1 {
            margin: 0 0 4px;
            color: #166534;
            font-size: 27px;
            line-height: 1.2;
            font-weight: 700;
        }

        body.employee-request-ticket-page .request-page-header p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.45;
        }

        body.employee-request-ticket-page .request-ticket-layout {
            display: grid;
            grid-template-columns: minmax(390px, 460px) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        body.employee-request-ticket-page .request-guidance-sidebar {
            position: sticky;
            top: 96px;
            display: grid;
            gap: 14px;
            height: auto !important;
            min-width: 0;
        }

        body.employee-request-ticket-page .request-main-column {
            display: grid;
            gap: 14px;
            min-width: 0;
        }

        body.employee-request-ticket-page .request-ticket-layout > .form-card,
        body.employee-request-ticket-page .request-main-column > .form-card {
            height: auto !important;
            min-height: 0;
        }

        body.employee-request-ticket-page .request-guidance-card,
        body.employee-request-ticket-page .request-routing-help,
        body.employee-request-ticket-page .request-tips-card-main,
        body.employee-request-ticket-page .request-main-column > .form-card {
            border: 1px solid #e1e7e3;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.045);
        }

        body.employee-request-ticket-page .request-guidance-card {
            overflow: hidden;
        }

        body.employee-request-ticket-page .request-guidance-heading {
            padding: 18px 20px 12px;
            border-bottom: 0;
        }

        body.employee-request-ticket-page .request-guidance-title {
            font-size: 15px;
            line-height: 1.35;
        }

        body.employee-request-ticket-page .request-guidance-copy {
            margin-top: 3px;
            font-size: 12px;
            line-height: 1.5;
        }

        body.employee-request-ticket-page .request-guidance-search {
            position: relative;
            margin: 0 16px 8px;
        }

        body.employee-request-ticket-page .request-guidance-search i {
            position: absolute;
            top: 50%;
            left: 15px;
            z-index: 1;
            color: #64748b;
            font-size: 14px;
            transform: translateY(-50%);
            pointer-events: none;
        }

        body.employee-request-ticket-page .request-guidance-search input {
            width: 100%;
            height: 42px;
            padding: 0 14px 0 43px;
            border: 1px solid #dbe3de;
            border-radius: 9px;
            outline: none;
            background: #ffffff;
            color: #0f172a;
            font-size: 13px;
            box-sizing: border-box;
        }

        body.employee-request-ticket-page .request-guidance-search input:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }

        body.employee-request-ticket-page .request-guidance-no-results {
            margin: 0 16px 16px;
            padding: 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
            text-align: center;
        }

        body.employee-request-ticket-page .request-company-guide.is-search-match > summary,
        body.employee-request-ticket-page .request-department-guide.is-search-match {
            background: #ecfdf3;
            box-shadow: inset 3px 0 0 #16a34a;
        }

        body.employee-request-ticket-page .request-guidance-directory {
            max-height: 510px;
            min-height: 0;
            padding: 0 16px 8px;
        }

        body.employee-request-ticket-page .request-company-guide {
            margin-bottom: 6px;
            border-radius: 11px;
        }

        body.employee-request-ticket-page .request-company-guide summary {
            min-height: 52px;
            padding: 8px 12px;
        }

        body.employee-request-ticket-page .request-company-guide-name {
            font-size: 15px;
        }

        body.employee-request-ticket-page .request-company-guide-domain,
        body.employee-request-ticket-page .request-department-guide-copy {
            font-size: 12px;
        }

        body.employee-request-ticket-page .request-department-guide {
            padding: 10px 12px;
        }

        body.employee-request-ticket-page .request-department-guide.is-guidance-extra {
            display: none;
        }

        body.employee-request-ticket-page .request-company-guide.show-all-departments .request-department-guide.is-guidance-extra,
        body.employee-request-ticket-page .request-guidance-directory.is-searching .request-department-guide.is-guidance-extra {
            display: grid;
        }

        body.employee-request-ticket-page .request-guidance-directory.is-searching .request-view-departments {
            display: none;
        }

        body.employee-request-ticket-page .request-guidance-directory .request-department-guide[hidden],
        body.employee-request-ticket-page .request-guidance-directory .request-company-guide[hidden] {
            display: none !important;
        }

        body.employee-request-ticket-page .request-view-departments {
            width: 100%;
            min-height: 45px;
            display: grid;
            grid-template-columns: 30px minmax(0, 1fr) auto;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 0;
            border-top: 1px solid #e5e7eb;
            background: #ffffff;
            color: #1f2937;
            font-size: 12px;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
        }

        body.employee-request-ticket-page .request-view-departments > i:first-child {
            color: #64748b;
            font-size: 14px;
            text-align: center;
        }

        body.employee-request-ticket-page .request-view-departments > i:last-child {
            color: #64748b;
            font-size: 11px;
            transition: transform 0.18s ease;
        }

        body.employee-request-ticket-page .request-company-guide.show-all-departments .request-view-departments > i:last-child {
            transform: rotate(90deg);
        }

        body.employee-request-ticket-page .request-routing-help {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 10px;
            padding: 14px 16px;
            border-color: #f2d679;
            background: #fffdf6;
            box-shadow: none;
        }

        body.employee-request-ticket-page .request-routing-help-icon {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1.5px solid #eab308;
            border-radius: 50%;
            color: #d39e00;
            font-size: 13px;
        }

        body.employee-request-ticket-page .request-routing-help h2 {
            margin: 0 0 5px;
            color: #8a5b0a;
            font-size: 13px;
            line-height: 1.35;
        }

        body.employee-request-ticket-page .request-routing-help p {
            margin: 0;
            color: #596579;
            font-size: 11.5px;
            line-height: 1.55;
        }

        body.employee-request-ticket-page .request-main-column > .form-card {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 20px 24px;
            overflow: visible;
            box-sizing: border-box;
        }

        body.employee-request-ticket-page .request-main-column .form-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 20px;
            padding: 0 0 15px;
            border-bottom: 1px solid #dfe5e1;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            color: #166534;
            font-size: 15px;
            line-height: 1.3;
            font-weight: 700;
            text-transform: uppercase;
        }

        body.employee-request-ticket-page .request-main-column .form-section-title::before {
            content: "\f15c";
            color: #15803d;
            font-family: "Font Awesome 6 Free";
            font-size: 20px;
            font-weight: 900;
        }

        body.employee-request-ticket-page .request-main-column .request-destination-heading {
            margin: 0 0 14px;
        }

        body.employee-request-ticket-page .request-main-column .request-destination-heading-main {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #166534;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            line-height: 1.35;
            text-transform: uppercase;
        }

        body.employee-request-ticket-page .request-main-column .request-destination-heading-main::before {
            content: "\f3c5";
            color: #15803d;
            font-family: "Font Awesome 6 Free";
            font-size: 13px;
            font-weight: 900;
        }

        body.employee-request-ticket-page .request-main-column .request-destination-heading-main::after {
            content: "";
            height: 1px;
            flex: 1 1 auto;
            background: #dfe8e2;
        }

        body.employee-request-ticket-page .request-main-column .request-destination-heading p {
            margin: 4px 0 0;
            color: #64748b;
            font-size: 11.5px;
            line-height: 1.45;
        }

        body.employee-request-ticket-page .request-main-column .form-group {
            margin-bottom: 16px;
        }

        body.employee-request-ticket-page .request-main-column .form-group > label {
            margin-bottom: 7px;
            color: #111827;
            font-size: 13px;
            font-weight: 600;
        }

        body.employee-request-ticket-page .request-main-column .request-grid-row {
            gap: 20px;
        }

        body.employee-request-ticket-page .request-main-column .form-control,
        body.employee-request-ticket-page .request-main-column .form-group input,
        body.employee-request-ticket-page .request-main-column .form-group select,
        body.employee-request-ticket-page .request-main-column .form-group textarea {
            min-height: 48px;
            height: 48px;
            padding: 11px 14px;
            border: 1px solid #d5ddd8;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none;
            color: #1f2937;
            font-size: 14px;
        }

        body.employee-request-ticket-page .request-main-column .form-control:focus,
        body.employee-request-ticket-page .request-main-column .form-group input:focus,
        body.employee-request-ticket-page .request-main-column .form-group select:focus,
        body.employee-request-ticket-page .request-main-column .form-group textarea:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }

        body.employee-request-ticket-page .request-main-column textarea.form-control,
        body.employee-request-ticket-page .request-main-column #descriptionField {
            height: 136px;
            min-height: 136px;
            padding: 14px;
            resize: none;
        }

        body.employee-request-ticket-page .request-main-column .attachment-upload-shell {
            min-height: 64px;
            padding: 10px 12px;
            border: 1px solid #e1e7e3 !important;
            border-radius: 10px !important;
            background: #ffffff !important;
        }

        body.employee-request-ticket-page .request-main-column .file-button {
            min-width: 128px;
            height: 42px;
            padding: 0 15px !important;
            border: 1px solid #bbdfc5 !important;
            border-radius: 8px !important;
            background: #f0fdf4 !important;
            color: #166534;
            font-size: 13px !important;
        }

        body.employee-request-ticket-page .request-main-column .attachment-file-name {
            font-size: 13px;
        }

        body.employee-request-ticket-page .request-main-column .form-text {
            margin-top: 7px;
            color: #64748b;
            font-size: 11px;
        }

        body.employee-request-ticket-page .request-main-column .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        body.employee-request-ticket-page .request-main-column .btn-submit {
            width: auto;
            min-width: 180px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            margin: 0;
            padding: 0 22px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        body.employee-request-ticket-page .request-main-column .btn-submit::before {
            content: "\f1d8";
            font-family: "Font Awesome 6 Free";
            font-size: 14px;
            font-weight: 900;
        }

        body.employee-request-ticket-page .request-tips-card-main {
            padding: 15px 22px 17px;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e4e9e6;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-icon {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #ecfdf3;
            color: #16803d;
            font-size: 15px;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-title {
            margin: 0;
            color: #166534;
            font-size: 15px;
            font-weight: 700;
        }

        /* Keep the page's three primary guidance headings visually consistent. */
        body.employee-request-ticket-page .request-guidance-heading-title,
        body.employee-request-ticket-page .request-main-column .form-section-title,
        body.employee-request-ticket-page .request-tips-card-main .request-tips-title {
            font-family: 'Segoe UI', sans-serif;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.3;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0;
            margin: 14px 0 0;
            padding: 0;
            list-style: none;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-list li {
            min-width: 0;
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            align-items: center;
            gap: 10px;
            padding: 0 20px;
            color: #465469;
            font-size: 12px;
            line-height: 1.45;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-list li:first-child {
            padding-left: 0;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-list li:last-child {
            padding-right: 0;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-list li + li {
            border-left: 1px solid #dfe5e1;
        }

        body.employee-request-ticket-page .request-tips-card-main .request-tips-list i {
            margin: 0;
            color: #159447;
            font-size: 27px;
        }

        body.employee-request-ticket-page .request-guidance-heading {
            width: 100%;
            border: 0;
            font-family: inherit;
            text-align: left;
            box-sizing: border-box;
            cursor: default;
        }

        body.employee-request-ticket-page .request-guidance-heading-copy {
            display: block;
            flex: 1 1 auto;
            min-width: 0;
        }

        body.employee-request-ticket-page .request-guidance-heading-title {
            display: block;
            margin: 0;
            color: #166534;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.3;
        }

        body.employee-request-ticket-page .request-guidance-heading-description {
            display: block;
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.45;
        }

        body.employee-request-ticket-page .request-guidance-toggle-icon {
            display: none;
        }

        body.employee-request-ticket-page .request-guidance-body {
            display: block;
        }

        @media (max-width: 1180px) {
            body.employee-request-ticket-page .request-ticket-layout {
                grid-template-columns: 1fr;
            }

            body.employee-request-ticket-page .request-guidance-sidebar {
                position: static;
            }

            body.employee-request-ticket-page .request-guidance-directory {
                max-height: 390px;
            }
        }

        @media (min-width: 1181px) {
            body.employee-request-ticket-page .request-page-header {
                width: calc(100% - 478px);
                margin-left: 478px !important;
            }
        }

        @media (max-width: 768px) {
            body.employee-request-ticket-page .dashboard-container {
                padding: 14px 12px 86px;
            }

            body.employee-request-ticket-page .request-page-header {
                margin: 0 0 12px !important;
            }

            body.employee-request-ticket-page .request-page-header h1 {
                font-size: 23px;
            }

            body.employee-request-ticket-page .request-guidance-heading {
                display: grid;
                grid-template-columns: 24px minmax(0, 1fr) 12px;
                align-items: center;
                gap: 10px;
                height: 70px;
                min-height: 70px;
                padding: 10px 12px;
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }

            body.employee-request-ticket-page .request-guidance-sidebar {
                grid-template-columns: minmax(0, 1fr);
                justify-items: stretch;
                width: 100%;
                max-width: 100%;
            }

            body.employee-request-ticket-page .request-routing-help {
                order: 1;
            }

            body.employee-request-ticket-page .request-guidance-card {
                order: 2;
            }

            body.employee-request-ticket-page .request-mobile-info-card {
                width: 100% !important;
                max-width: 100% !important;
                inline-size: 100% !important;
                margin: 0;
                align-self: stretch;
                border-width: 1px !important;
                border-style: solid !important;
                border-radius: 14px !important;
                box-shadow: none !important;
                overflow: hidden !important;
                box-sizing: border-box !important;
            }

            body.employee-request-ticket-page .request-guidance-card.request-mobile-info-card:not(.is-expanded),
            body.employee-request-ticket-page .request-routing-help.request-mobile-info-card {
                height: 72px !important;
                min-height: 72px !important;
                max-height: 72px !important;
                block-size: 72px !important;
                min-block-size: 72px !important;
                max-block-size: 72px !important;
            }

            body.employee-request-ticket-page .request-guidance-heading:focus-visible {
                outline: 3px solid rgba(22, 163, 74, 0.22);
                outline-offset: -3px;
            }

            body.employee-request-ticket-page .request-guidance-toggle-icon {
                display: inline-block;
                flex: 0 0 auto;
                margin: 0;
                color: #166534;
                font-size: 13px;
                transition: transform 0.2s ease;
            }

            body.employee-request-ticket-page .request-routing-help {
                grid-template-columns: 24px minmax(0, 1fr) 12px;
                align-items: center;
                gap: 10px;
                padding: 10px 12px;
                -webkit-text-size-adjust: none;
                text-size-adjust: none;
            }

            body.employee-request-ticket-page .request-routing-help::after {
                width: 12px;
                content: "";
            }

            body.employee-request-ticket-page .request-guidance-heading-icon,
            body.employee-request-ticket-page .request-routing-help-icon {
                width: 24px;
                height: 24px;
                border-width: 1.5px;
                font-size: 11px;
                box-sizing: border-box;
            }

            body.employee-request-ticket-page .request-guidance-heading-copy,
            body.employee-request-ticket-page .request-routing-help > div {
                min-width: 0;
            }

            body.employee-request-ticket-page .request-guidance-heading-title,
            body.employee-request-ticket-page .request-routing-help h2 {
                margin: 0;
                overflow: hidden;
                color: #166534;
                font-family: inherit;
                font-size: 11px;
                font-weight: 700;
                line-height: 1.25;
                white-space: nowrap;
                text-overflow: ellipsis;
            }

            body.employee-request-ticket-page .request-routing-help h2 {
                color: #8a5b0a;
                font-size: 11px !important;
                font-weight: 700 !important;
                line-height: 1.25;
            }

            body.employee-request-ticket-page .request-guidance-heading-description,
            body.employee-request-ticket-page .request-routing-help p {
                display: -webkit-box;
                margin: 2px 0 0;
                overflow: hidden;
                color: #64748b;
                font-family: inherit;
                font-size: 9px;
                font-weight: 400;
                line-height: 1.35;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
            }

            body.employee-request-ticket-page .request-routing-help p {
                color: #596579;
                font-size: 9px !important;
                line-height: 1.35;
            }

            body.employee-request-ticket-page .request-guidance-card:not(.is-expanded) .request-guidance-body {
                display: none;
            }

            body.employee-request-ticket-page .request-guidance-card.is-expanded .request-guidance-toggle-icon {
                transform: rotate(180deg);
            }

            body.employee-request-ticket-page .request-main-column > .form-card {
                padding: 17px 16px;
            }

            body.employee-request-ticket-page .request-main-column .form-section-title {
                margin: 0 0 18px;
                padding: 0 0 13px;
                border-bottom: 1px solid #dfe5e1;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                color: #166534;
                font-size: 14px;
            }

            body.employee-request-ticket-page .request-main-column .request-grid-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            body.employee-request-ticket-page .request-main-column .form-control,
            body.employee-request-ticket-page .request-main-column .form-group input,
            body.employee-request-ticket-page .request-main-column .form-group select,
            body.employee-request-ticket-page .request-main-column .form-group textarea {
                border: 1px solid #d5ddd8;
                border-radius: 10px;
                box-shadow: none;
            }

            body.employee-request-ticket-page .request-main-column .btn-submit {
                width: 100%;
                min-width: 0;
            }

            body.employee-request-ticket-page .request-tips-card-main .request-tips-list {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            body.employee-request-ticket-page .request-tips-card-main .request-tips-list li,
            body.employee-request-ticket-page .request-tips-card-main .request-tips-list li:first-child,
            body.employee-request-ticket-page .request-tips-card-main .request-tips-list li:last-child {
                padding: 0;
            }

            body.employee-request-ticket-page .request-tips-card-main .request-tips-list li + li {
                padding-top: 12px;
                border-top: 1px solid #e4e9e6;
                border-left: 0;
            }
        }

        /* Employee page background-only consistency override. */
        body.employee-request-ticket-page {
            background-image:
                radial-gradient(circle at -4% 10%, rgba(217, 240, 223, 0.78) 0 160px, transparent 161px),
                radial-gradient(circle at 9% 39%, rgba(233, 247, 236, 0.78) 0 44px, transparent 45px),
                radial-gradient(circle at 97% 40%, rgba(221, 242, 226, 0.76) 0 84px, transparent 85px),
                radial-gradient(circle at 105% 80%, rgba(213, 237, 220, 0.82) 0 170px, transparent 171px),
                radial-gradient(ellipse at 23% 106%, rgba(225, 244, 229, 0.82) 0 180px, transparent 181px),
                radial-gradient(circle at 1% 2%, transparent 0 110px, rgba(205, 230, 213, 0.25) 111px 113px, transparent 114px),
                radial-gradient(circle at 2% 4%, transparent 0 165px, rgba(205, 230, 213, 0.17) 166px 168px, transparent 169px),
                radial-gradient(circle at 98% 58%, transparent 0 165px, rgba(202, 229, 211, 0.22) 166px 168px, transparent 169px),
                radial-gradient(circle at 100% 60%, transparent 0 215px, rgba(202, 229, 211, 0.15) 216px 218px, transparent 219px),
                linear-gradient(145deg, rgba(239, 249, 242, 0.78) 0%, rgba(255, 255, 255, 0.98) 20%, rgba(255, 255, 255, 0.99) 60%, rgba(239, 249, 242, 0.84) 100%) !important;
            background-repeat: no-repeat !important;
            background-attachment: fixed !important;
        }

        body.employee-request-ticket-page::before,
        body.employee-request-ticket-page::after {
            content: "" !important;
            position: fixed !important;
            pointer-events: none !important;
            z-index: 0 !important;
            background-image: radial-gradient(circle, rgba(105, 163, 123, 0.2) 1.2px, transparent 1.55px);
        }

        body.employee-request-ticket-page::before {
            left: 2%;
            top: 54%;
            width: 100px;
            height: 112px;
            background-size: 16px 16px;
        }

        body.employee-request-ticket-page::after {
            right: 4%;
            top: 128px;
            width: 104px;
            height: 104px;
            background-size: 15px 15px;
        }

        body.employee-request-ticket-page .dashboard-container,
        body.employee-request-ticket-page .content-wrapper {
            position: relative !important;
            z-index: 1 !important;
            background: transparent !important;
        }
        /* Use only the shared image background; do not leave an old overlay or page seam. */
        body.employee-request-ticket-page {
            background-image: url('../assets/img/dashboard_bg.jpg') !important;
            background-repeat: no-repeat !important;
            background-size: cover !important;
            background-position: center -28px !important;
            background-attachment: fixed !important;
        }
        body.employee-request-ticket-page::before,
        body.employee-request-ticket-page::after {
            content: none !important;
        }

        /* Compact phone layout matching the mobile request-ticket reference. */
        @media (max-width: 768px) {
            body.employee-request-ticket-page {
                min-height: 100vh;
                background: #eef1f1 !important;
            }

            body.employee-request-ticket-page .navbar {
                min-height: 4px !important;
                height: 4px !important;
                padding: 0 !important;
                border-bottom: 4px solid #f4c430 !important;
                background: #174f25 !important;
                box-shadow: none !important;
                overflow: visible;
            }

            body.employee-request-ticket-page .navbar > * {
                display: none !important;
            }

            body.employee-request-ticket-page .dashboard-container {
                max-width: none;
                min-height: calc(100vh - 4px);
                margin: 0;
                padding: 24px 22px 58px;
                background-color: #eef1f1 !important;
                background-image:
                    linear-gradient(180deg, rgba(255, 255, 255, 0.22) 0, rgba(255, 255, 255, 0.72) 112px, #eef1f1 160px),
                    url('../assets/img/dashboard_bg.jpg') !important;
                background-repeat: no-repeat !important;
                background-position: center top !important;
                background-size: 100% 160px, cover !important;
                box-sizing: border-box;
            }

            body.employee-request-ticket-page .content-wrapper {
                display: block;
            }

            body.employee-request-ticket-page .request-page-header {
                margin: 0 0 22px !important;
            }

            body.employee-request-ticket-page .request-page-header h1 {
                margin-bottom: 7px;
                color: #075d25;
                font-size: 19px;
                line-height: 1.15;
                font-weight: 750;
            }

            body.employee-request-ticket-page .request-page-header p {
                color: #334155;
                font-size: 10px;
                line-height: 1.35;
            }

            body.employee-request-ticket-page .request-ticket-layout {
                display: grid;
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }

            body.employee-request-ticket-page .request-guidance-sidebar {
                display: block;
            }

            body.employee-request-ticket-page .request-routing-help,
            body.employee-request-ticket-page .request-tips-card-main,
            body.employee-request-ticket-page .tm-global-chat-fab {
                display: none !important;
            }

            body.employee-request-ticket-page .request-guidance-card {
                order: initial;
                border: 1px solid #dfe5e1 !important;
                border-radius: 13px !important;
                background: rgba(255, 255, 255, 0.96);
                box-shadow: 0 7px 17px rgba(15, 23, 42, 0.08) !important;
            }

            body.employee-request-ticket-page .request-guidance-card.request-mobile-info-card:not(.is-expanded) {
                height: 70px !important;
                min-height: 70px !important;
                max-height: 70px !important;
            }

            body.employee-request-ticket-page .request-guidance-heading {
                height: 68px;
                min-height: 68px;
                grid-template-columns: 25px minmax(0, 1fr) 12px;
                gap: 9px;
                padding: 9px 13px;
            }

            body.employee-request-ticket-page .request-guidance-heading-icon {
                width: 21px;
                height: 21px;
                border-color: #087330;
                color: #087330;
                font-size: 10px;
            }

            body.employee-request-ticket-page .request-guidance-heading-title {
                color: #075d25;
                font-size: 10px;
                white-space: normal;
                text-overflow: clip;
            }

            body.employee-request-ticket-page .request-guidance-heading-description {
                margin-top: 5px;
                color: #263448;
                font-size: 9px;
                line-height: 1.42;
            }

            body.employee-request-ticket-page .request-guidance-toggle-icon {
                color: #075d25;
                font-size: 10px;
            }

            body.employee-request-ticket-page .request-main-column {
                display: block;
            }

            body.employee-request-ticket-page .request-main-column > .form-card {
                padding: 15px 14px 13px;
                border: 1px solid #dfe5e1;
                border-radius: 13px;
                background: rgba(255, 255, 255, 0.97);
                box-shadow: 0 7px 18px rgba(15, 23, 42, 0.07);
            }

            body.employee-request-ticket-page .request-main-column .form-section-title {
                gap: 8px;
                margin: 0 0 13px;
                padding: 0 0 11px;
                color: #075d25;
                font-size: 8px;
                line-height: 1.2;
            }

            body.employee-request-ticket-page .request-main-column .form-section-title::before {
                font-size: 15px;
            }

            body.employee-request-ticket-page .request-main-column .form-group {
                margin-bottom: 12px;
            }

            body.employee-request-ticket-page .request-main-column .form-group > label {
                margin-bottom: 5px;
                font-size: 9px;
                line-height: 1.25;
            }

            body.employee-request-ticket-page .required-asterisk {
                font-size: 9px;
            }

            body.employee-request-ticket-page .request-main-column .form-control,
            body.employee-request-ticket-page .request-main-column .form-group input,
            body.employee-request-ticket-page .request-main-column .form-group select,
            body.employee-request-ticket-page .request-main-column .form-group textarea {
                min-height: 34px;
                height: 34px;
                padding: 8px 10px;
                border: 1px solid #d5ddd8;
                border-radius: 6px;
                color: #263448;
                font-size: 9px;
            }

            body.employee-request-ticket-page .select-wrapper .select-icon {
                right: 10px;
                font-size: 8px;
            }

            body.employee-request-ticket-page .request-main-column textarea.form-control,
            body.employee-request-ticket-page .request-main-column #descriptionField {
                height: 86px;
                min-height: 86px;
                padding: 9px 10px;
            }

            body.employee-request-ticket-page .request-main-column .attachment-upload-shell {
                min-height: 36px;
                padding: 5px 7px;
                border-radius: 7px !important;
            }

            body.employee-request-ticket-page .request-main-column .file-button {
                min-width: 92px;
                height: 27px;
                min-height: 27px;
                padding: 0 9px !important;
                border: 0 !important;
                border-radius: 5px !important;
                background: #eafaf0 !important;
                font-size: 9px !important;
            }

            body.employee-request-ticket-page .request-main-column .file-button svg {
                width: 11px;
                height: 11px;
            }

            body.employee-request-ticket-page .request-main-column .attachment-file-name {
                font-size: 9px;
            }

            body.employee-request-ticket-page #attachment-preview .attachment-remove-button {
                width: 32px !important;
                min-width: 32px !important;
                max-width: 32px !important;
                height: 32px !important;
                min-height: 32px !important;
                flex-basis: 32px !important;
                border-radius: 8px !important;
                font-size: 16px !important;
            }

            body.employee-request-ticket-page .request-main-column .form-text {
                margin-top: 5px;
                font-size: 7px;
            }

            body.employee-request-ticket-page .request-main-column .form-actions {
                margin-top: 12px;
            }

            body.employee-request-ticket-page .request-main-column .btn-submit {
                min-height: 37px;
                height: 37px;
                padding: 0 15px;
                border-radius: 6px;
                font-size: 11px;
                box-shadow: 0 4px 10px rgba(7, 93, 37, 0.16);
            }

            body.employee-request-ticket-page .request-main-column .btn-submit::before {
                font-size: 11px;
            }
        }
    </style>
</head>
<body class="employee-request-ticket-page">

    <!-- 2️⃣ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-error" id="pageError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700;">
                    <?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="page-header request-page-header">
                <h1 class="page-title">Create a Ticket</h1>
                <p class="page-subtitle">Please fill out the form below to submit your concern.</p>
            </div>

            <div class="request-ticket-layout">
                <aside class="request-guidance-sidebar" aria-label="Ticket routing guide">
                    <section class="request-guidance-card request-mobile-info-card" id="requestGuidanceCard">
                        <button type="button" class="request-guidance-heading" id="requestGuidanceToggle" aria-expanded="false" aria-controls="requestGuidanceBody">
                            <span class="request-guidance-heading-icon" aria-hidden="true"><i class="fas fa-info"></i></span>
                            <span class="request-guidance-heading-copy">
                                <span class="request-guidance-heading-title" role="heading" aria-level="2">Guidelines: Where to Submit Your Concern</span>
                                <span class="request-guidance-heading-description">Choose a subsidiary, then use the Department field when it appears and select the matching category.</span>
                            </span>
                            <i class="fas fa-chevron-down request-guidance-toggle-icon" aria-hidden="true"></i>
                        </button>
                        <div class="request-guidance-body" id="requestGuidanceBody">
                        <div class="request-guidance-search">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input type="search" id="requestGuidanceSearch" placeholder="Search subsidiary, department, or category..." aria-label="Search subsidiary, department, or category">
                        </div>
                        <div class="request-guidance-directory">
                            <?php foreach ($requestTicketSidebarCompanies as $sidebarCompany): ?>
                                <?php
                                    $sidebarCompanyIsOpen = (string) $sidebarCompany['value'] === $requestTicketSidebarOpenCompany;
                                    $sidebarCompanyRequiresDepartment = !empty($sidebarCompany['requires_department']);
                                    $sidebarCompanyIcon = (string) ($sidebarCompany['icon'] ?? 'fa-building');
                                    $sidebarCompanyTone = (string) ($sidebarCompany['tone'] ?? 'green');
                                ?>
                                <details class="request-company-guide"<?= $sidebarCompanyIsOpen ? ' open' : ''; ?>>
                                    <summary>
                                        <span class="request-company-icon is-<?= htmlspecialchars($sidebarCompanyTone, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><i class="fas <?= htmlspecialchars($sidebarCompanyIcon, ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                        <span class="request-company-copy">
                                            <strong class="request-company-name"><?= htmlspecialchars((string) $sidebarCompany['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <span class="request-company-domain"><?= htmlspecialchars((string) $sidebarCompany['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </span>
                                        <i class="fas fa-chevron-right request-company-chevron" aria-hidden="true"></i>
                                    </summary>
                                    <div class="request-department-list">
                                        <?php if ($sidebarCompanyRequiresDepartment): ?>
                                            <?php if (empty($sidebarCompany['departments'])): ?>
                                                <p class="request-guide-empty">No departments are currently available in the Department dropdown.</p>
                                            <?php endif; ?>
                                            <?php foreach ($sidebarCompany['departments'] as $sidebarDepartmentIndex => $sidebarDepartment): ?>
                                                <?php
                                                    $sidebarDepartmentName = (string) ($sidebarDepartment['name'] ?? '');
                                                    $sidebarDepartmentLookup = strtolower($sidebarDepartmentName);
                                                    $sidebarDepartmentIcon = 'fa-sitemap';
                                                    $sidebarDepartmentTone = 'operations';
                                                    if (str_contains($sidebarDepartmentLookup, 'admin') || str_contains($sidebarDepartmentLookup, 'legal')) {
                                                        $sidebarDepartmentIcon = 'fa-scale-balanced';
                                                        $sidebarDepartmentTone = 'admin';
                                                    } elseif ($sidebarDepartmentLookup === 'it' || str_contains($sidebarDepartmentLookup, 'digital')) {
                                                        $sidebarDepartmentIcon = 'fa-desktop';
                                                        $sidebarDepartmentTone = 'it';
                                                    } elseif ($sidebarDepartmentLookup === 'hr') {
                                                        $sidebarDepartmentIcon = 'fa-users';
                                                        $sidebarDepartmentTone = 'hr';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'diagnostic') || str_contains($sidebarDepartmentLookup, 'lingap')) {
                                                        $sidebarDepartmentIcon = 'fa-heart-pulse';
                                                        $sidebarDepartmentTone = 'health';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'marketing') || str_contains($sidebarDepartmentLookup, 'e-commerce')) {
                                                        $sidebarDepartmentIcon = 'fa-bullhorn';
                                                        $sidebarDepartmentTone = 'marketing';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'technical')) {
                                                        $sidebarDepartmentIcon = 'fa-flask';
                                                        $sidebarDepartmentTone = 'technical';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'account')) {
                                                        $sidebarDepartmentIcon = 'fa-calculator';
                                                        $sidebarDepartmentTone = 'accounting';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'supply')) {
                                                        $sidebarDepartmentIcon = 'fa-truck';
                                                        $sidebarDepartmentTone = 'supply';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'farm') || str_contains($sidebarDepartmentLookup, 'seed')) {
                                                        $sidebarDepartmentIcon = 'fa-tractor';
                                                        $sidebarDepartmentTone = 'agriculture';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'machiner')) {
                                                        $sidebarDepartmentIcon = 'fa-gears';
                                                        $sidebarDepartmentTone = 'machinery';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'executive') || str_contains($sidebarDepartmentLookup, 'management') || str_contains($sidebarDepartmentLookup, 'business')) {
                                                        $sidebarDepartmentIcon = 'fa-briefcase';
                                                        $sidebarDepartmentTone = 'management';
                                                    } elseif (str_contains($sidebarDepartmentLookup, 'institutional') || str_contains($sidebarDepartmentLookup, 'bidding') || str_contains($sidebarDepartmentLookup, 'sales')) {
                                                        $sidebarDepartmentIcon = 'fa-file-contract';
                                                        $sidebarDepartmentTone = 'sales';
                                                    }
                                                    $sidebarCategoryText = implode('  •  ', array_map('strval', (array) ($sidebarDepartment['categories'] ?? [])));
                                                ?>
                                                <div class="request-department-guide<?= $sidebarDepartmentIndex >= 3 ? ' is-guidance-extra' : ''; ?>">
                                                    <span class="request-department-icon is-<?= htmlspecialchars($sidebarDepartmentTone, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><i class="fas <?= htmlspecialchars($sidebarDepartmentIcon, ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                                    <div>
                                                        <h3 class="request-department-name"><?= htmlspecialchars($sidebarDepartmentName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                                        <?php if ($sidebarCategoryText !== ''): ?>
                                                        <p class="request-category-list"><?= htmlspecialchars($sidebarCategoryText, ENT_QUOTES, 'UTF-8'); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count($sidebarCompany['departments']) > 3): ?>
                                                <button type="button" class="request-view-departments" aria-expanded="false">
                                                    <i class="fas fa-border-all" aria-hidden="true"></i>
                                                    <span>View all departments</span>
                                                    <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php $sidebarCategoryText = implode('  •  ', array_map('strval', (array) ($sidebarCompany['categories'] ?? []))); ?>
                                            <?php if ($sidebarCategoryText !== ''): ?>
                                            <div class="request-department-guide request-direct-category-guide">
                                                <span class="request-department-icon is-category" aria-hidden="true"><i class="fas fa-tags"></i></span>
                                                <div>
                                                    <h3 class="request-department-name">Categories</h3>
                                                    <p class="request-category-list"><?= htmlspecialchars($sidebarCategoryText, ENT_QUOTES, 'UTF-8'); ?></p>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                        <p class="request-guidance-no-results" id="requestGuidanceNoResults" hidden>No matching subsidiary, department, or category found.</p>
                        </div>
                    </section>

                    <section class="request-routing-help request-mobile-info-card" aria-label="Routing help">
                        <span class="request-routing-help-icon" aria-hidden="true"><i class="fas fa-question"></i></span>
                        <div>
                            <h2>Not sure where to send your concern?</h2>
                            <p>Select the department “Others” under the most relevant subsidiary or choose the category “Others”.</p>
                        </div>
                    </section>
                </aside>

                <div class="request-main-column">
                <div class="form-card">
                <form id="ticketForm" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="alert alert-error" id="ajaxError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700; display:none;"></div>
                    
                    <!-- 🔹 Request Information -->
                    <h3 class="form-section-title">Request Information</h3>

                    <div class="request-destination-heading" aria-labelledby="ticketDestinationHeading">
                        <div class="request-destination-heading-main" id="ticketDestinationHeading">Ticket destination</div>
                        <p>Choose where you want this ticket to be routed.</p>
                    </div>

                    <div class="request-grid-row is-single" id="recipientDepartmentRow">
                        <div class="form-group">
                            <label>Subsidiaries <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper" id="assignedCompanyWrapper">
                                <select name="assigned_company" id="assigned_company" class="form-control custom-select-native" required data-selected="<?= htmlspecialchars($selectedAssignedCompany, ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled <?= $selectedAssignedCompany === '' ? 'selected' : ''; ?> hidden>Select a company</option>
                                    <?php foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel): ?>
                                        <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedAssignedCompany === $companyValue ? 'selected' : ''; ?>><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="form-control custom-select-trigger" id="assignedCompanyTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="custom-select-value" id="assignedCompanyTriggerValue">Select a company</span>
                                </button>
                                <div class="custom-select-menu" id="assignedCompanyMenu" role="listbox" hidden></div>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="form-group" id="departmentContainer" style="display:none;">
                            <label>Department <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper" id="assignedGroupWrapper">
                                <select name="assigned_group" id="assigned_group" class="form-control custom-select-native" required disabled data-selected="<?= htmlspecialchars($selectedAssignedGroup, ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled <?= $selectedAssignedGroup === '' ? 'selected' : ''; ?> hidden>Select department</option>
                                    <?php foreach ($initialDepartmentOptions as $departmentOption): ?>
                                        <option value="<?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedAssignedGroup === $departmentOption ? 'selected' : ''; ?>><?= htmlspecialchars($departmentOption, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="form-control custom-select-trigger" id="assignedGroupTrigger" aria-haspopup="listbox" aria-expanded="false" disabled>
                                    <span class="custom-select-value" id="assignedGroupTriggerValue">Select department</span>
                                </button>
                                <div class="custom-select-menu" id="assignedGroupMenu" role="listbox" hidden></div>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>

                    <div class="request-grid-row is-single" id="categoryUrgencyRow">
                        <div class="form-group" id="adminLegalRequestForContainer" style="display:none;">
                            <label>Request For <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper" id="adminLegalRequestForWrapper">
                                <select name="admin_legal_request_for" id="admin_legal_request_for" class="form-control custom-select-native" disabled data-selected="<?= htmlspecialchars((string) ($_POST['admin_legal_request_for'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose request for</option>
                                    <?php foreach (['Aimi Bing Santos (Bing)', 'Ace Loui Rosal (Ace)', 'Cherry Jane Cabote (CJ)', 'Others'] as $requestForOption): ?>
                                        <option value="<?= htmlspecialchars($requestForOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['admin_legal_request_for'] ?? '') === $requestForOption) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($requestForOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="form-control custom-select-trigger" id="adminLegalRequestForTrigger" aria-haspopup="listbox" aria-expanded="false" disabled>
                                    <span class="custom-select-value" id="adminLegalRequestForTriggerValue">Choose request for</span>
                                </button>
                                <div class="custom-select-menu" id="adminLegalRequestForMenu" role="listbox" hidden></div>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                        <div class="form-group" id="categoryContainer">
                            <label>Category <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper" id="categoryWrapper">
                                <select name="category" id="category_select" class="form-control custom-select-native" required data-selected="<?= htmlspecialchars((string) ($_POST['category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose category</option>
                                    <option value="Documentation">Documentation</option>
                                    <option value="Email">Email</option>
                                    <option value="Hardware">Hardware</option>
                                    <option value="Internet Concerns">Internet Concerns</option>
                                    <option value="Procurement">Procurement</option>
                                    <option value="Software">Software</option>
                                    <option value="Others">Others</option>
                                </select>
                                <button type="button" class="form-control custom-select-trigger" id="categoryTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="custom-select-value" id="categoryTriggerValue">Choose category</span>
                                </button>
                                <div class="custom-select-menu" id="categoryMenu" role="listbox" hidden></div>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="form-group hr-extra-group is-visible" id="urgencyContainer">
                            <label>Level of Urgency <span class="required-asterisk">*</span></label>
                        <input
                            type="hidden"
                            name="priority"
                            id="priority_hidden"
                            value="<?= htmlspecialchars((string) ($_POST['priority'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        >
                        <div class="select-wrapper" id="urgencyWrapper">
                            <select id="urgencySelect" class="form-control custom-select-native" required>
                                <option value="" disabled selected hidden>Choose level of urgency</option>
                                <option value="Low">Low (7 to 9 days)</option>
                                <option value="Medium">Medium (4 to 6 days)</option>
                                <option value="High">High (1 to 3 days)</option>
                            </select>
                            <button type="button" class="form-control custom-select-trigger" id="urgencyTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-value" id="urgencyTriggerValue">Choose level of urgency</span>
                            </button>
                            <div class="custom-select-menu" id="urgencyMenu" role="listbox" hidden></div>
                            <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>

                    <div class="request-grid-row is-single" id="marketingSubcategoryRow" style="display:none;">
                        <div class="form-group hr-extra-group" id="marketingSubcategoryContainer">
                            <label>Request Type <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper" id="marketingSubcategoryWrapper">
                                <select name="marketing_subcategory" id="marketing_subcategory" class="form-control custom-select-native" disabled data-selected="<?= htmlspecialchars((string) ($_POST['marketing_subcategory'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose sub-category</option>
                                </select>
                                <button type="button" class="form-control custom-select-trigger" id="marketingSubcategoryTrigger" aria-haspopup="listbox" aria-expanded="false" disabled>
                                    <span class="custom-select-value" id="marketingSubcategoryTriggerValue">Choose sub-category</span>
                                </button>
                                <div class="custom-select-menu" id="marketingSubcategoryMenu" role="listbox" hidden></div>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>

                    <section class="marketing-request-group" id="supplyChainDetailsRow">
                        <h3 class="marketing-request-head">Supply Chain Request</h3>
                        <div class="marketing-request-list">
                            <div class="marketing-request-inline-row" id="supplyChainDetailsFields"></div>
                            <div id="supplyChainAttachmentHost"></div>
                        </div>
                    </section>

                    <section class="kami-group" id="kamiBannerContainer">
                        <h3 class="kami-banner-head">Attendance and Timekeeping (KAMI)</h3>
                        <div class="kami-list">
                            <div class="form-group hr-extra-group" id="concernTypeContainer">
                                <label>Type of Concern <span class="required-asterisk">*</span></label>
                                <div class="select-wrapper" id="concernTypeWrapper">
                                    <select name="hr_concern_type" id="hr_concern_type" class="form-control custom-select-native" data-selected="<?= htmlspecialchars((string) ($_POST['hr_concern_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="" disabled selected hidden>Choose type of concern</option>
                                        <option value="KAMI Error: Check IN/OUT">KAMI Error: Check IN/OUT</option>
                                        <option value="KAMI Error: Failed log in attempts">KAMI Error: Failed log in attempts</option>
                                        <option value="Unpaid salary">Unpaid salary</option>
                                        <option value="Unpaid leave/overtime pay">Unpaid leave/overtime pay</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    <button type="button" class="form-control custom-select-trigger" id="concernTypeTrigger" aria-haspopup="listbox" aria-expanded="false">
                                        <span class="custom-select-value" id="concernTypeTriggerValue">Choose type of concern</span>
                                    </button>
                                    <i class="fas fa-chevron-down select-icon"></i>
                                    <div class="custom-select-menu" id="concernTypeMenu" role="listbox" hidden></div>
                                </div>
                            </div>
                            <div class="form-group hr-extra-group" id="concernTypeOtherContainer">
                                <label for="hr_concern_type_other">Please specify the type of concern <span class="required-asterisk">*</span></label>
                                <input type="text" name="hr_concern_type_other" id="hr_concern_type_other" class="form-control" value="<?= htmlspecialchars((string) ($_POST['hr_concern_type_other'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter type of concern">
                            </div>
                        </div>
                    </section>

                    <section class="other-request-section" id="otherRequestDetailsSection">
                        <div class="other-request-section-head">Request Details</div>
                        <div class="other-request-section-body">
                            <div class="form-group hr-extra-group" id="leaveSubjectContainer">
                                <label id="requestSubjectLabel">Subject/Title of Request <span class="required-asterisk">*</span></label>
                                <input
                                    type="text"
                                    name="request_subject_title"
                                    id="request_subject_title"
                                    class="form-control"
                                    value="<?= htmlspecialchars((string) ($_POST['request_subject_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Enter subject/title of request"
                                >
                            </div>
                        </div>
                    </section>

                    <section class="incident-report-group" id="incidentReportSection">
                        <h3 class="incident-report-head">Request Details</h3>
                        <div class="incident-report-list">
                            <section class="incident-report-card">
                                <div class="form-group">
                                    <label for="incident_summary">Short Summary of IR (Upload file with signature) <span class="required-asterisk">*</span></label>
                                    <textarea name="incident_summary" id="incident_summary" class="form-control" placeholder="Provide a short summary of the incident report..." style="resize:none;" rows="4"><?= htmlspecialchars((string) ($_POST['incident_summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                            </section>
                            <section class="incident-report-card">
                                <div id="incidentReportAttachmentHost"></div>
                            </section>
                            <section class="incident-report-card">
                                <div class="form-group">
                                    <label for="incident_gdrive_link">Google Drive Link <span class="optional-label">(Optional)</span></label>
                                    <input type="url" name="incident_gdrive_link" id="incident_gdrive_link" class="form-control" value="<?= htmlspecialchars((string) ($_POST['incident_gdrive_link'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Paste Google Drive video link">
                                </div>
                            </section>
                        </div>
                    </section>

                    <section class="medical-cash-group" id="medicalCashAdvanceSection">
                        <h3 class="medical-cash-head">Medical Cash Advance</h3>
                        <div class="medical-cash-list">
                            <section class="medical-cash-card">
                                <div class="form-group">
                                    <label for="medical_cash_purpose">Purpose: <span class="required-asterisk">*</span></label>
                                    <input type="text" name="medical_cash_purpose" id="medical_cash_purpose" class="form-control" value="<?= htmlspecialchars((string) ($_POST['medical_cash_purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <div class="medical-cash-inline-row">
                                <section class="medical-cash-card">
                                    <div class="form-group">
                                        <label for="medical_cash_amount">Amount. <span class="required-asterisk">*</span></label>
                                        <input type="text" name="medical_cash_amount" id="medical_cash_amount" class="form-control" value="<?= htmlspecialchars((string) ($_POST['medical_cash_amount'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                    </div>
                                </section>
                                <section class="medical-cash-card">
                                    <div class="form-group">
                                        <label for="medical_cash_date_needed">Date Needed: <span class="required-asterisk">*</span></label>
                                        <input type="date" name="medical_cash_date_needed" id="medical_cash_date_needed" class="form-control" value="<?= htmlspecialchars((string) ($_POST['medical_cash_date_needed'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </section>
                            </div>
                            <section class="medical-cash-card">
                                <div id="medicalCashAttachmentHost"></div>
                            </section>
                        </div>
                    </section>

                    <section class="training-request-group" id="trainingRequestSection">
                        <h3 class="training-request-head">Training Request Form</h3>
                        <div class="training-request-list">
                            <section class="training-request-card">
                                <div class="form-group">
                                    <label for="training_request_title">Training/Seminar Title: <span class="required-asterisk">*</span></label>
                                    <input type="text" name="training_request_title" id="training_request_title" class="form-control" value="<?= htmlspecialchars((string) ($_POST['training_request_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <section class="training-request-card">
                                <div class="form-group">
                                    <label for="training_request_provider">Provider/Organizer: <span class="required-asterisk">*</span></label>
                                    <input type="text" name="training_request_provider" id="training_request_provider" class="form-control" value="<?= htmlspecialchars((string) ($_POST['training_request_provider'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <div class="training-request-inline-row">
                                <section class="training-request-card">
                                    <div class="form-group">
                                        <label for="training_request_start_date">Start Date of Training/Seminar: <span class="required-asterisk">*</span></label>
                                        <input type="date" name="training_request_start_date" id="training_request_start_date" class="form-control" value="<?= htmlspecialchars((string) ($_POST['training_request_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </section>
                                <section class="training-request-card">
                                    <div class="form-group">
                                        <label for="training_request_end_date">End Date of Training/Seminar: <span class="required-asterisk">*</span></label>
                                        <input type="date" name="training_request_end_date" id="training_request_end_date" class="form-control" value="<?= htmlspecialchars((string) ($_POST['training_request_end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </section>
                            </div>
                            <section class="training-request-card">
                                <div class="form-group">
                                    <label for="training_request_venue">Venue of Training/Seminar: <span class="required-asterisk">*</span></label>
                                    <input type="text" name="training_request_venue" id="training_request_venue" class="form-control" value="<?= htmlspecialchars((string) ($_POST['training_request_venue'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <section class="training-request-card">
                                <div class="form-group">
                                    <label for="training_request_fee">Registration Fee: <span class="required-asterisk">*</span></label>
                                    <input type="text" name="training_request_fee" id="training_request_fee" class="form-control" value="<?= htmlspecialchars((string) ($_POST['training_request_fee'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                        </div>
                    </section>

                    <section class="company-property-group" id="companyPropertySection">
                        <h3 class="company-property-head">Request for Company Property</h3>
                        <div class="company-property-list">
                            <section class="company-property-card">
                                <p class="company-property-copy">First issuance of company property is free. Payment is required for requests due to lost or replacement.</p>
                            </section>
                            <section class="company-property-card">
                                <span class="company-property-card-title is-regular-label">Type of Company Property: <span class="required-asterisk">*</span></span>
                                <div class="company-property-option-list">
                                    <?php foreach (['Company ID', 'Company Lanyard', 'Company Uniform', 'Business Card'] as $propertyOption): ?>
                                        <label class="company-property-option">
                                            <input type="radio" name="company_property_type" value="<?= htmlspecialchars($propertyOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['company_property_type'] ?? '') === $propertyOption) ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars($propertyOption, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <section class="company-property-card">
                                <span class="company-property-card-title is-regular-label">Reason of Request: <span class="required-asterisk">*</span></span>
                                <div class="company-property-option-list">
                                    <?php foreach (['Lost', 'Replacement', 'No issuance'] as $reasonOption): ?>
                                        <label class="company-property-option">
                                            <input type="radio" name="company_property_reason" value="<?= htmlspecialchars($reasonOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['company_property_reason'] ?? '') === $reasonOption) ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars($reasonOption, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        </div>
                    </section>

                    <section class="coe-request-group" id="coeRequestSection">
                        <h3 class="coe-request-head">Certificate of Employment Request Form</h3>
                        <div class="coe-request-list">
                            <section class="coe-request-card">
                                <span class="coe-request-card-title">Reason of COE Request <span class="required-asterisk">*</span></span>
                                <div class="coe-request-option-list">
                                    <?php foreach (['Bank Loan', 'Car Loan', 'Housing Loan', 'Motor Loan', 'School Requirement', 'Travel - Local', 'Travel - International'] as $coeReasonOption): ?>
                                        <label class="coe-request-option">
                                            <input type="radio" name="coe_request_reason" value="<?= htmlspecialchars($coeReasonOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['coe_request_reason'] ?? '') === $coeReasonOption) ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars($coeReasonOption, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    <div class="coe-request-other-row">
                                        <label class="coe-request-option">
                                            <input type="radio" name="coe_request_reason" value="Other" <?= (($_POST['coe_request_reason'] ?? '') === 'Other') ? 'checked' : ''; ?>>
                                            <span>Other:</span>
                                        </label>
                                        <input type="text" name="coe_request_reason_other" id="coe_request_reason_other" class="form-control" value="<?= htmlspecialchars((string) ($_POST['coe_request_reason_other'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                    </div>
                                </div>
                            </section>
                            <section class="coe-request-card">
                                <span class="coe-request-card-title">Do you need salary details included in the COE? <span class="required-asterisk">*</span></span>
                                <div class="coe-request-option-list">
                                    <?php foreach (['Yes', 'No'] as $coeSalaryOption): ?>
                                        <label class="coe-request-option">
                                            <input type="radio" name="coe_salary_details" value="<?= htmlspecialchars($coeSalaryOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['coe_salary_details'] ?? '') === $coeSalaryOption) ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars($coeSalaryOption, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <section class="coe-request-card">
                                <span class="coe-request-card-title">Preferred Date of Release <span class="required-asterisk">*</span></span>
                                <p class="coe-request-copy">Note that processing may take up to 3 to 5 working days.</p>
                                <div class="form-group">
                                    <input type="date" name="coe_preferred_release_date" id="coe_preferred_release_date" class="form-control" value="<?= htmlspecialchars((string) ($_POST['coe_preferred_release_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </section>
                            <section class="coe-request-card">
                                <span class="coe-request-card-title">Preferred Delivery Method: <span class="required-asterisk">*</span></span>
                                <p class="coe-request-copy">E-copy will be sent to your e-mail once available.</p>
                                <div class="coe-request-option-list">
                                    <?php foreach (['Electronic copy only', 'Printed copy to be picked up at HR Office', 'Courier c/o Admin'] as $coeDeliveryOption): ?>
                                        <label class="coe-request-option">
                                            <input type="radio" name="coe_delivery_method" value="<?= htmlspecialchars($coeDeliveryOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['coe_delivery_method'] ?? '') === $coeDeliveryOption) ? 'checked' : ''; ?>>
                                            <span><?= htmlspecialchars($coeDeliveryOption, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                            <section class="coe-request-card">
                                <span class="coe-request-card-title">Remarks or Special Instructions: <span class="required-asterisk">*</span></span>
                                <p class="coe-request-copy">Use this space to provide any special requests or important information regarding your COE. This may include preferred wording, specific addresses (e.g., bank name, embassy), urgent deadlines, or other relevant instructions that will help us process your request accurately.</p>
                                <div class="form-group">
                                    <input type="text" name="coe_remarks" id="coe_remarks" class="form-control" value="<?= htmlspecialchars((string) ($_POST['coe_remarks'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                        </div>
                    </section>

                    <section class="col-request-group" id="colRequestSection">
                        <h3 class="col-request-head">Certificate of Leave Request Form</h3>
                        <div class="col-request-list">
                            <div class="col-request-inline-row">
                                <section class="col-request-card">
                                    <div class="form-group">
                                        <label for="certificate_leave_date">Date of Leave <span class="required-asterisk">*</span></label>
                                        <input type="date" name="certificate_leave_date" id="certificate_leave_date" class="form-control" value="<?= htmlspecialchars((string) ($_POST['certificate_leave_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </section>
                                <section class="col-request-card">
                                    <div class="form-group">
                                        <label for="certificate_leave_purpose">Purpose <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper">
                                            <select name="certificate_leave_purpose" id="certificate_leave_purpose" class="form-control" data-selected="<?= htmlspecialchars((string) ($_POST['certificate_leave_purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <option value="" disabled selected hidden>Choose purpose of leave</option>
                                                <option value="Travel">Travel</option>
                                                <option value="Others">Others</option>
                                            </select>
                                            <i class="fas fa-chevron-down select-icon"></i>
                                        </div>
                                    </div>
                                </section>
                            </div>
                            <section class="col-request-card hr-extra-group" id="certificateLeavePurposeOtherContainer">
                                <div class="form-group">
                                    <label for="certificate_leave_purpose_other">Please specify the purpose of leave <span class="required-asterisk">*</span></label>
                                    <input type="text" name="certificate_leave_purpose_other" id="certificate_leave_purpose_other" class="form-control" value="<?= htmlspecialchars((string) ($_POST['certificate_leave_purpose_other'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter purpose of leave">
                                </div>
                            </section>
                        </div>
                    </section>

                    <section class="email-request-group" id="emailRequestSection">
                        <h3 class="email-request-head">Email Request</h3>
                        <div class="email-request-list">
                            <section class="email-request-card">
                                <div class="form-group">
                                    <label for="email_request_type">Email Request Type <span class="required-asterisk">*</span></label>
                                    <div class="select-wrapper" id="emailRequestTypeWrapper">
                                        <select name="email_request_type" id="email_request_type" class="form-control custom-select-native" data-selected="<?= htmlspecialchars((string) ($_POST['email_request_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            <option value="" disabled selected hidden>Choose email request type</option>
                                            <option value="creation of email" <?= (($_POST['email_request_type'] ?? '') === 'creation of email') ? 'selected' : ''; ?>>Creation of email</option>
                                            <option value="forgot password" <?= (($_POST['email_request_type'] ?? '') === 'forgot password') ? 'selected' : ''; ?>>Forgot password</option>
                                            <option value="backup of email" <?= (($_POST['email_request_type'] ?? '') === 'backup of email') ? 'selected' : ''; ?>>Backup of email</option>
                                        </select>
                                        <button type="button" class="form-control custom-select-trigger" id="emailRequestTypeTrigger" aria-haspopup="listbox" aria-expanded="false">
                                            <span class="custom-select-value" id="emailRequestTypeTriggerValue">Choose email request type</span>
                                        </button>
                                        <i class="fas fa-chevron-down select-icon"></i>
                                        <div class="custom-select-menu" id="emailRequestTypeMenu" role="listbox" hidden></div>
                                    </div>
                                </div>
                                <div class="email-description-host" id="emailDescriptionHost"></div>
                                <div class="email-creation-fields" id="emailCreationFields">
                                    <div class="email-request-panel-head">
                                        <div class="email-request-panel-copy">
                                            <p class="email-request-counter" id="emailRequestCounter">Email 1 of <?= count($emailCreationEntries); ?></p>
                                            <p class="email-request-copy">Request one or more company email accounts under a single ticket.</p>
                                        </div>
                                        <div class="email-request-panel-tools">
                                            <div class="select-wrapper email-request-switcher">
                                                <span class="email-request-switcher-icon" aria-hidden="true"><i class="fas fa-envelope"></i></span>
                                                <select id="emailEmployeeSwitcher" class="form-control">
                                                    <?php foreach ($emailCreationEntries as $emailIndex => $emailEntry): ?>
                                                        <?php $emailDisplayName = trim((string) ($emailEntry['name'] ?? '')); ?>
                                                        <option value="<?= $emailIndex; ?>">
                                                            <?= htmlspecialchars($emailDisplayName !== '' ? $emailDisplayName : ('Email ' . ($emailIndex + 1)), ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <i class="fas fa-chevron-down select-icon"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="emailRequestList">
                                        <?php foreach ($emailCreationEntries as $emailIndex => $emailEntry): ?>
                                            <section class="email-creation-card <?= $emailIndex === 0 ? 'is-active' : ''; ?>" data-email-card>
                                                <div class="email-creation-card-top">
                                                    <h4 class="email-creation-card-title" data-email-card-title>Email Details</h4>
                                                    <button type="button" class="email-creation-card-delete" data-remove-email-card aria-label="Delete email entry">
                                                        <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                                        <span>Remove</span>
                                                    </button>
                                                </div>
                                                <div class="email-creation-inline-row">
                                                    <div class="form-group">
                                                        <label for="email_creation_name_<?= $emailIndex; ?>">Name <span class="required-asterisk">*</span></label>
                                                        <input type="text" name="email_creations[<?= $emailIndex; ?>][name]" id="email_creation_name_<?= $emailIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($emailEntry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-email-field="name">
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="email_creation_designation_<?= $emailIndex; ?>">Designation <span class="required-asterisk">*</span></label>
                                                        <input type="text" name="email_creations[<?= $emailIndex; ?>][designation]" id="email_creation_designation_<?= $emailIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($emailEntry['designation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-email-field="designation">
                                                    </div>
                                                </div>
                                                <div class="email-creation-inline-row">
                                                    <div class="form-group">
                                                        <label for="email_creation_subsidiary_<?= $emailIndex; ?>">Company <span class="required-asterisk">*</span></label>
                                                        <input type="text" name="email_creations[<?= $emailIndex; ?>][subsidiary]" id="email_creation_subsidiary_<?= $emailIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($emailEntry['subsidiary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-email-field="subsidiary">
                                                    </div>
                                                    <div class="form-group">
                                                        <label for="email_creation_department_<?= $emailIndex; ?>">Department <span class="required-asterisk">*</span></label>
                                                        <input type="text" name="email_creations[<?= $emailIndex; ?>][department]" id="email_creation_department_<?= $emailIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($emailEntry['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-email-field="department">
                                                    </div>
                                                </div>
                                                <div class="email-request-actions">
                                                    <button type="button" class="email-request-add-btn" data-add-email-card>
                                                        <i class="fas fa-plus"></i>
                                                        <span>Add Email</span>
                                                    </button>
                                                </div>
                                            </section>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </section>
                    <template id="emailCreationTemplate">
                        <section class="email-creation-card" data-email-card>
                            <div class="email-creation-card-top">
                                <h4 class="email-creation-card-title" data-email-card-title>Email Details</h4>
                                <button type="button" class="email-creation-card-delete" data-remove-email-card aria-label="Delete email entry">
                                    <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                    <span>Remove</span>
                                </button>
                            </div>
                            <div class="email-creation-inline-row">
                                <div class="form-group">
                                    <label for="email_creation_name___INDEX__">Name <span class="required-asterisk">*</span></label>
                                    <input type="text" name="email_creations[__INDEX__][name]" id="email_creation_name___INDEX__" class="form-control" value="" placeholder="Your answer" data-email-field="name">
                                </div>
                                <div class="form-group">
                                    <label for="email_creation_designation___INDEX__">Designation <span class="required-asterisk">*</span></label>
                                    <input type="text" name="email_creations[__INDEX__][designation]" id="email_creation_designation___INDEX__" class="form-control" value="" placeholder="Your answer" data-email-field="designation">
                                </div>
                            </div>
                            <div class="email-creation-inline-row">
                                <div class="form-group">
                                    <label for="email_creation_subsidiary___INDEX__">Company <span class="required-asterisk">*</span></label>
                                    <input type="text" name="email_creations[__INDEX__][subsidiary]" id="email_creation_subsidiary___INDEX__" class="form-control" value="" placeholder="Your answer" data-email-field="subsidiary">
                                </div>
                                <div class="form-group">
                                    <label for="email_creation_department___INDEX__">Department <span class="required-asterisk">*</span></label>
                                    <input type="text" name="email_creations[__INDEX__][department]" id="email_creation_department___INDEX__" class="form-control" value="" placeholder="Your answer" data-email-field="department">
                                </div>
                            </div>
                            <div class="email-request-actions">
                                <button type="button" class="email-request-add-btn" data-add-email-card>
                                    <i class="fas fa-plus"></i>
                                    <span>Add Email</span>
                                </button>
                            </div>
                        </section>
                    </template>

                    <section class="sap-request-group" id="sapRequestSection">
                        <h3 class="sap-request-head">SAP Form</h3>
                        <div class="sap-request-panel-head">
                            <div class="sap-request-panel-copy">
                                <p class="sap-request-counter" id="sapRequestCounter">Employee 1 of <?= count($sapFormEntries); ?></p>
                                <p class="sap-request-copy">Add one or more employee reports under a single SAP ticket.</p>
                            </div>
                            <div class="sap-request-panel-tools">
                                <div class="select-wrapper sap-request-switcher">
                                    <span class="sap-request-switcher-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                                    <select id="sapEmployeeSwitcher" class="form-control">
                                        <?php foreach ($sapFormEntries as $sapIndex => $sapEntry): ?>
                                            <?php $sapDisplayName = trim((string) ($sapEntry['name'] ?? '')); ?>
                                            <option value="<?= $sapIndex; ?>">
                                                <?= htmlspecialchars($sapDisplayName !== '' ? $sapDisplayName : ('Employee ' . ($sapIndex + 1)), ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-chevron-down select-icon"></i>
                                </div>
                            </div>
                        </div>
                        <div class="sap-request-list" id="sapRequestList">
                            <?php foreach ($sapFormEntries as $sapIndex => $sapEntry): ?>
                                <section class="sap-request-card sap-employee-card <?= $sapIndex === 0 ? 'is-active' : ''; ?>" data-sap-card>
                                    <div class="sap-request-card-top">
                                        <h4 class="sap-request-card-title" data-sap-report-title>Employee Details</h4>
                                        <button type="button" class="sap-request-card-delete" data-remove-sap-report aria-label="Delete employee">
                                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                            <span>Remove</span>
                                        </button>
                                    </div>
                                    <div class="sap-request-inline-row">
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_name_<?= $sapIndex; ?>">Name <span class="required-asterisk">*</span></label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][name]" id="sap_name_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="name">
                                            </div>
                                        </section>
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_position_<?= $sapIndex; ?>">Position</label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][position]" id="sap_position_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="position">
                                            </div>
                                        </section>
                                    </div>
                                    <div class="sap-request-inline-row">
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_address_<?= $sapIndex; ?>">Address</label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][address]" id="sap_address_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="address">
                                            </div>
                                        </section>
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_department_<?= $sapIndex; ?>">Department</label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][department]" id="sap_department_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="department">
                                            </div>
                                        </section>
                                    </div>
                                    <div class="sap-request-inline-row">
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_tin_<?= $sapIndex; ?>">TIN</label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][tin]" id="sap_tin_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['tin'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="tin">
                                            </div>
                                        </section>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                        <div class="sap-request-actions">
                            <div class="sap-request-actions-group">
                                <button type="button" class="sap-request-add-btn" id="sapAddEmployeeBtn">
                                    <i class="fas fa-plus"></i>
                                    Add Employee
                                </button>
                            </div>
                        </div>
                    </section>

                    <template id="sapRequestTemplate">
                        <section class="sap-request-card sap-employee-card" data-sap-card>
                            <div class="sap-request-card-top">
                                <h4 class="sap-request-card-title" data-sap-report-title>Employee Details</h4>
                                <button type="button" class="sap-request-card-delete" data-remove-sap-report aria-label="Delete employee">
                                    <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                    <span>Remove</span>
                                </button>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_name___INDEX__">Name <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[__INDEX__][name]" id="sap_name___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="name">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_position___INDEX__">Position</label>
                                        <input type="text" name="sap_reports[__INDEX__][position]" id="sap_position___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="position">
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_address___INDEX__">Address</label>
                                        <input type="text" name="sap_reports[__INDEX__][address]" id="sap_address___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="address">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_department___INDEX__">Department</label>
                                        <input type="text" name="sap_reports[__INDEX__][department]" id="sap_department___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="department">
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_tin___INDEX__">TIN</label>
                                        <input type="text" name="sap_reports[__INDEX__][tin]" id="sap_tin___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="tin">
                                    </div>
                                </section>
                            </div>
                        </section>
                    </template>

                    <section class="marketing-request-group" id="marketingRequestSection">
                        <h3 class="marketing-request-head">Marketing Request</h3>
                        <div class="marketing-request-list">
                            <section class="marketing-request-card">
                                <div class="form-group">
                                    <label for="project_name">Project Name <span class="required-asterisk">*</span></label>
                                    <input type="text" name="project_name" id="project_name" class="form-control" value="<?= htmlspecialchars((string) ($_POST['project_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>

                            <div class="marketing-request-inline-row">
                                <section class="marketing-request-card">
                                    <div class="form-group">
                                        <label for="area_code">Area Code <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper" id="areaCodeWrapper">
                                            <select name="area_code" id="area_code" class="form-control custom-select-native" data-selected="<?= htmlspecialchars((string) ($_POST['area_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <option value="" disabled selected hidden>Choose area code</option>
                                                <?php foreach (['811A', '811B', '812', '813A', '813B', '814A', '814B', '815A', '815B', '815C', '821A', '821B', '821C', '822A', '822B', '831A', '831B', '832A', '832B', '833', 'HEAD OFFICE'] as $areaCodeOption): ?>
                                                    <option value="<?= htmlspecialchars($areaCodeOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['area_code'] ?? '') === $areaCodeOption) ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars($areaCodeOption, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="form-control custom-select-trigger" id="areaCodeTrigger" aria-haspopup="listbox" aria-expanded="false">
                                                <span class="custom-select-value" id="areaCodeTriggerValue">Choose area code</span>
                                            </button>
                                            <div class="custom-select-menu" id="areaCodeMenu" role="listbox" hidden></div>
                                            <i class="fas fa-chevron-down select-icon"></i>
                                        </div>
                                    </div>
                                </section>
                                <section class="marketing-request-card">
                                    <div class="form-group">
                                        <label for="marketing_department">Department <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper" id="marketingDepartmentWrapper">
                                            <select name="marketing_department" id="marketing_department" class="form-control custom-select-native" data-selected="<?= htmlspecialchars((string) ($_POST['marketing_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <option value="" disabled selected hidden>Choose department</option>
                                                <?php foreach (['Marketing Ops', 'Sales', 'Technical', 'Human Resources', 'PCC/GPCI', 'Farmex', 'Farmasee', 'LTC', 'MPDC', 'IT', 'Admin', 'Leads AH/EH', 'Executive/Management'] as $marketingDepartmentOption): ?>
                                                    <option value="<?= htmlspecialchars($marketingDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['marketing_department'] ?? '') === $marketingDepartmentOption) ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars($marketingDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="form-control custom-select-trigger" id="marketingDepartmentTrigger" aria-haspopup="listbox" aria-expanded="false">
                                                <span class="custom-select-value" id="marketingDepartmentTriggerValue">Choose department</span>
                                            </button>
                                            <div class="custom-select-menu" id="marketingDepartmentMenu" role="listbox" hidden></div>
                                            <i class="fas fa-chevron-down select-icon"></i>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <section class="marketing-request-card">
                                <div class="form-group">
                                    <label for="requested_materials">Requested Materials <span class="required-asterisk">*</span></label>
                                    <?php $selectedRequestedMaterials = request_ticket_clean_string_array($_POST['requested_materials'] ?? []); ?>
                                    <div class="select-wrapper" id="requestedMaterialsGroup">
                                        <select name="requested_materials[]" id="requested_materials" class="form-control custom-select-native">
                                            <option value="" disabled <?= count($selectedRequestedMaterials) === 0 ? 'selected' : ''; ?> hidden>Choose requested material</option>
                                            <?php foreach (['Social Media Graphics', 'Print Materials (Flyers, Brochures)', 'Video (Short-form)', 'Banners/Taffetas', 'Labels', 'Tarpaulin/Poster', 'Invitation', 'Coupons', 'Sintraboard design', 'Plotsigns', 'Promats Design (shirt, cap, etc)', 'Other'] as $materialOption): ?>
                                                <option value="<?= htmlspecialchars($materialOption, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($materialOption, $selectedRequestedMaterials, true) ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($materialOption, ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="form-control custom-select-trigger" id="requestedMaterialsTrigger" aria-haspopup="listbox" aria-expanded="false">
                                            <span class="custom-select-value" id="requestedMaterialsTriggerValue">Choose requested material</span>
                                        </button>
                                        <div class="custom-select-menu" id="requestedMaterialsMenu" role="listbox" hidden></div>
                                        <i class="fas fa-chevron-down select-icon"></i>
                                    </div>
                                    <div class="marketing-request-other-row" id="requestedMaterialsOtherRow">
                                        <label for="requested_materials_other">Other requested material <span class="required-asterisk">*</span></label>
                                        <input type="text" name="requested_materials_other" id="requested_materials_other" class="form-control" value="<?= htmlspecialchars((string) ($_POST['requested_materials_other'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Please specify">
                                    </div>
                                </div>
                            </section>

                            <div class="marketing-request-inline-row">
                                <section class="marketing-request-card">
                                    <div class="form-group">
                                        <span class="marketing-request-card-title is-regular-label">Size of Material <span class="required-asterisk">*</span></span>
                                        <?php
                                            $selectedMaterialSizeUnit = trim((string) ($_POST['material_size_unit'] ?? ''));
                                            $selectedMaterialSizeInput = trim((string) ($_POST['material_size_value'] ?? ''));
                                            if ($selectedMaterialSizeUnit === '' && !empty($_POST['material_size'])) {
                                                $savedMaterialSize = (string) $_POST['material_size'];
                                                foreach (['Inches', 'Feet', 'Centimeters'] as $savedSizeOption) {
                                                    if (stripos($savedMaterialSize, $savedSizeOption . ':') === 0) {
                                                        $selectedMaterialSizeUnit = $savedSizeOption;
                                                        $selectedMaterialSizeInput = trim(substr($savedMaterialSize, strlen($savedSizeOption) + 1));
                                                        break;
                                                    }
                                                }
                                            }
                                            $selectedMaterialSizeValue = ($selectedMaterialSizeUnit !== '' && $selectedMaterialSizeInput !== '') ? $selectedMaterialSizeUnit . ': ' . $selectedMaterialSizeInput : '';
                                        ?>
                                        <input type="hidden" name="material_size" id="material_size" value="<?= htmlspecialchars($selectedMaterialSizeValue, ENT_QUOTES, 'UTF-8'); ?>">
                                        <small class="marketing-request-help">Select one size unit, then enter the measurement.</small>
                                        <div class="marketing-request-option-list marketing-size-options">
                                            <?php foreach (['Inches', 'Feet', 'Centimeters'] as $sizeOption): ?>
                                                <div class="marketing-request-option marketing-size-option">
                                                    <label>
                                                        <input type="radio" name="material_size_unit" value="<?= htmlspecialchars($sizeOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedMaterialSizeUnit === $sizeOption ? 'checked' : ''; ?>>
                                                        <span><?= htmlspecialchars($sizeOption, ENT_QUOTES, 'UTF-8'); ?>:</span>
                                                    </label>
                                                    <input type="text" name="material_size_value" class="form-control marketing-size-value" value="<?= $selectedMaterialSizeUnit === $sizeOption ? htmlspecialchars($selectedMaterialSizeInput, ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="Enter size" <?= $selectedMaterialSizeUnit === $sizeOption ? '' : 'disabled'; ?>>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </section>
                                <section class="marketing-request-card">
                                    <div class="form-group">
                                        <label for="project_deadline">Project Deadline <span class="required-asterisk">*</span></label>
                                        <small class="marketing-request-help" id="projectDeadlineHelp">Must be at least 3 working days from today.</small>
                                        <input type="date" name="project_deadline" id="project_deadline" class="form-control" value="<?= htmlspecialchars((string) ($_POST['project_deadline'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <div class="marketing-request-error" id="projectDeadlineError"></div>

                                        <div class="marketing-crop-inline">
                                            <label for="crop">Crop <span class="required-asterisk">*</span></label>
                                            <?php $selectedCrops = request_ticket_clean_string_array($_POST['crop'] ?? []); ?>
                                            <div class="select-wrapper" id="cropGroup">
                                                <select name="crop[]" id="crop" class="form-control custom-select-native">
                                                    <option value="" disabled <?= count($selectedCrops) === 0 ? 'selected' : ''; ?> hidden>Choose crop</option>
                                                    <?php foreach (['Rice', 'Lowland Vegetable', 'Upland Vegetable', 'Sugarcane', 'Corn', 'Mango', 'Other'] as $cropOption): ?>
                                                        <option value="<?= htmlspecialchars($cropOption, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($cropOption, $selectedCrops, true) ? 'selected' : ''; ?>>
                                                            <?= htmlspecialchars($cropOption, ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="form-control custom-select-trigger" id="cropTrigger" aria-haspopup="listbox" aria-expanded="false">
                                                    <span class="custom-select-value" id="cropTriggerValue">Choose crop</span>
                                                </button>
                                                <div class="custom-select-menu" id="cropMenu" role="listbox" hidden></div>
                                                <i class="fas fa-chevron-down select-icon"></i>
                                            </div>
                                            <div class="marketing-request-other-row" id="cropOtherRow">
                                                <label for="crop_other">Other crop <span class="required-asterisk">*</span></label>
                                                <input type="text" name="crop_other" id="crop_other" class="form-control" value="<?= htmlspecialchars((string) ($_POST['crop_other'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Please specify">
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>
                            <section class="marketing-request-card" id="marketingDescriptionCard">
                                <div id="marketingDescriptionHost"></div>
                            </section>
                        </div>
                    </section>

                    <div class="sss-benefits-group" id="sssBenefitsContainer">
                        <section class="sss-benefits-note">
                            <div class="sss-benefits-note-head">SSS Notification and Benefits Concern</div>
                            <div class="sss-benefits-note-body">
                                <div>Please upload accomplished files and necessary supporting documents.</div>
                                <div>File title: [File Name] - [Last Name, First Name] ex. Application Form - Dela Cruz, Juan</div>
                            </div>
                        </section>

                        <div class="sss-benefits-list">
                            <section class="sss-benefits-card">
                                <h4 class="sss-benefits-card-title">Accomplished SSS Sickness Form <span class="required-asterisk">*</span></h4>
                                <p class="sss-benefits-card-copy">Upload 1 supported file. Max 10 MB.</p>
                                <div class="sss-benefits-upload-row">
                                    <label class="sss-benefits-upload-btn" for="sssSicknessFormInput">
                                        <i class="fas fa-upload"></i>
                                        <span>Add file</span>
                                    </label>
                                    <input type="file" id="sssSicknessFormInput" name="sss_sickness_form" class="sss-benefits-file-input" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                                    <span class="sss-benefits-file-name" id="sssSicknessFormName">No file chosen</span>
                                </div>
                                <div class="sss-benefits-file-list" id="sssSicknessFormList"></div>
                                <div class="sss-benefits-error" id="sssSicknessFormError"></div>
                            </section>

                            <section class="sss-benefits-card">
                                <h4 class="sss-benefits-card-title">Medical Procedures <span class="required-asterisk">*</span></h4>
                                <p class="sss-benefits-card-copy">Upload up to 5 supported files. Max 10 MB per file.</p>
                                <div class="sss-benefits-upload-row">
                                    <label class="sss-benefits-upload-btn" for="sssMedicalProceduresInput">
                                        <i class="fas fa-upload"></i>
                                        <span>Add file</span>
                                    </label>
                                    <input type="file" id="sssMedicalProceduresInput" name="sss_medical_procedures[]" class="sss-benefits-file-input" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" multiple>
                                    <span class="sss-benefits-file-name" id="sssMedicalProceduresName">No file chosen</span>
                                </div>
                                <div class="sss-benefits-file-list" id="sssMedicalProceduresList"></div>
                                <div class="sss-benefits-error" id="sssMedicalProceduresError"></div>
                            </section>

                            <section class="sss-benefits-card">
                                <h4 class="sss-benefits-card-title">Laboratory Results <span class="required-asterisk">*</span></h4>
                                <p class="sss-benefits-card-copy">Upload up to 5 supported files. Max 10 MB per file.</p>
                                <div class="sss-benefits-upload-row">
                                    <label class="sss-benefits-upload-btn" for="sssLaboratoryResultsInput">
                                        <i class="fas fa-upload"></i>
                                        <span>Add file</span>
                                    </label>
                                    <input type="file" id="sssLaboratoryResultsInput" name="sss_laboratory_results[]" class="sss-benefits-file-input" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" multiple>
                                    <span class="sss-benefits-file-name" id="sssLaboratoryResultsName">No file chosen</span>
                                </div>
                                <div class="sss-benefits-file-list" id="sssLaboratoryResultsList"></div>
                                <div class="sss-benefits-error" id="sssLaboratoryResultsError"></div>
                            </section>

                            <section class="sss-benefits-card">
                                <h4 class="sss-benefits-card-title">Medical Certificates <span class="required-asterisk">*</span></h4>
                                <p class="sss-benefits-card-copy">Upload up to 5 supported files. Max 10 MB per file.</p>
                                <div class="sss-benefits-upload-row">
                                    <label class="sss-benefits-upload-btn" for="sssMedicalCertificatesInput">
                                        <i class="fas fa-upload"></i>
                                        <span>Add file</span>
                                    </label>
                                    <input type="file" id="sssMedicalCertificatesInput" name="sss_medical_certificates[]" class="sss-benefits-file-input" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" multiple>
                                    <span class="sss-benefits-file-name" id="sssMedicalCertificatesName">No file chosen</span>
                                </div>
                                <div class="sss-benefits-file-list" id="sssMedicalCertificatesList"></div>
                                <div class="sss-benefits-error" id="sssMedicalCertificatesError"></div>
                            </section>

                            <section class="sss-benefits-card">
                                <h4 class="sss-benefits-card-title">Discharge Summary/Proof <span class="required-asterisk">*</span></h4>
                                <p class="sss-benefits-card-copy">Upload up to 5 supported files. Max 10 MB per file.</p>
                                <div class="sss-benefits-upload-row">
                                    <label class="sss-benefits-upload-btn" for="sssDischargeSummaryInput">
                                        <i class="fas fa-upload"></i>
                                        <span>Add file</span>
                                    </label>
                                    <input type="file" id="sssDischargeSummaryInput" name="sss_discharge_summary[]" class="sss-benefits-file-input" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" multiple>
                                    <span class="sss-benefits-file-name" id="sssDischargeSummaryName">No file chosen</span>
                                </div>
                                <div class="sss-benefits-file-list" id="sssDischargeSummaryList"></div>
                                <div class="sss-benefits-error" id="sssDischargeSummaryError"></div>
                            </section>
                        </div>
                    </div>

                    <section class="other-request-section" id="otherDescriptionSection">
                        <div class="other-request-section-body">
                            <div id="descriptionOriginalHost"></div>
                            <div class="form-group" id="descriptionContainer">
                                <label id="descriptionLabel">Description <span class="required-asterisk">*</span></label>
                                <textarea name="description" id="descriptionField" class="form-control" placeholder="Describe your issue in detail..." style="resize:none;" required></textarea>
                            </div>
                            <div id="attachmentOriginalHost"></div>
                            <div class="form-group" id="attachmentContainer">
                                <label><span id="attachmentLabelText">Attachment</span> <span id="attachmentOptionalText">(Optional)</span><span id="attachmentRequiredAsterisk" class="required-asterisk" style="display:none;">*</span></label>
                                <p class="medical-cash-card-copy" id="medicalCashAttachmentIntro" style="display:none;"></p>
                                <div class="attachment-upload-shell file-control">
                                    <button type="button" id="choose-file-btn" class="file-button" aria-label="Choose file">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M20 17.5A3.5 3.5 0 0 1 16.5 21H7a5 5 0 0 1-1-9.9V11a6 6 0 0 1 11.53-1.999.75.75 0 1 1-1.4.55A4.5 4.5 0 0 0 7.75 11v.77a.75.75 0 0 1-.63.74A3.5 3.5 0 0 0 7 19.5h9.5A2 2 0 0 0 18.5 15a.75.75 0 1 1 1.5 0zM12 7.5a.75.75 0 0 1 .75.75V12h1.94a.75.75 0 1 1 0 1.5H12.75v1.94a.75.75 0 0 1-1.5 0V13.5H9.31a.75.75 0 1 1 0-1.5h1.94V8.25A.75.75 0 0 1 12 7.5z"/>
                                        </svg>
                                        <span id="chooseFileBtnText">Choose File</span>
                                    </button>
                                    <input type="file" name="attachments[]" id="attachments" class="file-hidden" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" aria-label="Choose attachment files">
                                    <span id="file-name" class="attachment-file-name file-name">No file chosen</span>
                                </div>
                                <small class="form-text attachment-help-text" id="attachmentHelpText">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)</small>
                                <div id="attachment-error" style="display:none;margin-top:10px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;font-weight:700;"></div>
                                <div id="attachment-toast" role="alert" aria-live="assertive" style="position:fixed;top:18px;right:18px;z-index:9999;display:none;max-width:min(420px, calc(100vw - 36px));background:#991b1b;color:#ffffff;padding:12px 14px;border-radius:12px;box-shadow:0 16px 40px rgba(2,6,23,0.22);font-weight:800;font-size:13px;"></div>
                                <div id="attachment-preview" style="margin-top: 10px;"></div>
                            </div>
                        </div>
                    </section>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Submit Ticket</button>
                    </div>
                </form>
                </div>
                <section class="request-tips-card request-tips-card-main" aria-labelledby="requestTipsTitle">
                    <div class="request-tips-head">
                        <span class="request-tips-icon" aria-hidden="true"><i class="far fa-lightbulb"></i></span>
                        <h2 class="request-tips-title" id="requestTipsTitle">Tips Before Submitting</h2>
                    </div>
                    <ul class="request-tips-list">
                        <li><i class="far fa-check-circle" aria-hidden="true"></i><span>Select the correct<br>subsidiary first.</span></li>
                        <li><i class="far fa-check-circle" aria-hidden="true"></i><span>If the Department field appears,<br>choose the best matching department.</span></li>
                        <li><i class="far fa-check-circle" aria-hidden="true"></i><span>Select the most<br>appropriate category.</span></li>
                    </ul>
                </section>
                </div>
            </div>

        </div>
    </div>

    <div id="attachmentPreviewModal" class="attachment-preview-modal" aria-hidden="true">
        <button type="button" class="attachment-preview-nav attachment-preview-prev" id="attachmentPreviewPrev" aria-label="Previous attachment"></button>
        <button type="button" class="attachment-preview-nav attachment-preview-next" id="attachmentPreviewNext" aria-label="Next attachment"></button>
        <div class="attachment-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="attachmentPreviewTitle">
            <div class="attachment-preview-head">
                <div class="attachment-preview-title">
                    <strong id="attachmentPreviewTitle">Attachment Preview</strong>
                    <span id="attachmentPreviewMeta"></span>
                </div>
                <button type="button" class="attachment-preview-close" id="attachmentPreviewClose" aria-label="Close attachment preview">x</button>
            </div>
            <div class="attachment-preview-body" id="attachmentPreviewBody"></div>
        </div>
    </div>

    <div id="successModal" class="ticket-modal" aria-hidden="true">
        <div class="ticket-modal-content" role="dialog" aria-modal="true" aria-labelledby="successModalTitle">
            <div class="ticket-modal-spinner" aria-hidden="true"></div>
            <div class="ticket-modal-icon success" id="ticketModalSuccessIcon" aria-hidden="true">&#10003;</div>
            <div class="ticket-modal-icon error" id="ticketModalErrorIcon">!</div>
            <h3 id="successModalTitle">Submitting Ticket</h3>
            <p id="successModalDesc">Almost there. We are finalizing your request...</p>
            <div class="ticket-modal-progress"><span id="ticketModalProgressBar"></span></div>
            <div class="ticket-modal-status" id="ticketModalStatus">Finalizing your request</div>
            <div class="ticket-modal-actions" id="ticketModalActions">
                <button type="button" id="ticketModalDoneBtn">Done</button>
            </div>
        </div>
    </div>

    <script src="../js/employee-dashboard.js"></script>

    <script>
    if (!document.body.classList.contains('employee-shared-mobile-sidebar-page')) {
    (function () {
        const menuBtn = document.getElementById('navbarToggler');
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('mobileSidebarOverlay');
        const mobileUserBtn = document.getElementById('mobileSidebarUserBtn');
        const mobileUserMenu = document.getElementById('mobileSidebarUserMenu');
        const desktopNotifBadge = document.getElementById('notifBadge');
        const mobileNotifBadge = document.getElementById('mobileSidebarNotifBadge');
        const navbarCollapse = document.getElementById('navbarCollapse');

        function closeSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            if (mobileUserMenu) mobileUserMenu.classList.remove('show');
            sidebar.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('aria-hidden', 'true');
            if (navbarCollapse) navbarCollapse.classList.remove('show');
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
                if (typeof event.stopImmediatePropagation === 'function') {
                    event.stopImmediatePropagation();
                }
                if (navbarCollapse) navbarCollapse.classList.remove('show');
                const shouldOpen = !sidebar.classList.contains('active');
                sidebar.classList.toggle('active', shouldOpen);
                overlay.classList.toggle('active', shouldOpen);
                document.body.classList.toggle('sidebar-open', shouldOpen);
                sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
                overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
            }, true);

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

            window.addEventListener('resize', function () {
                if (window.innerWidth > 768) {
                    closeSidebar();
                }
            });

            syncMobileNotifBadge();
            if (desktopNotifBadge && typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver(syncMobileNotifBadge);
                observer.observe(desktopNotifBadge, { attributes: true, childList: true, subtree: true });
            }
        }
    })();
    }
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const recipientDropdown = document.getElementById('assigned_company');
        const recipientWrapper = document.getElementById('assignedCompanyWrapper');
        const recipientTrigger = document.getElementById('assignedCompanyTrigger');
        const recipientTriggerValue = document.getElementById('assignedCompanyTriggerValue');
        const recipientMenu = document.getElementById('assignedCompanyMenu');
        const recipientDepartmentRow = document.getElementById('recipientDepartmentRow');
        const departmentContainer = document.getElementById('departmentContainer');
        const departmentSelect = document.getElementById('assigned_group');
        const departmentWrapper = document.getElementById('assignedGroupWrapper');
        const departmentTrigger = document.getElementById('assignedGroupTrigger');
        const departmentTriggerValue = document.getElementById('assignedGroupTriggerValue');
        const departmentMenu = document.getElementById('assignedGroupMenu');
        const categoryUrgencyRow = document.getElementById('categoryUrgencyRow');
        const adminLegalRequestForContainer = document.getElementById('adminLegalRequestForContainer');
        const adminLegalRequestForSelect = document.getElementById('admin_legal_request_for');
        const adminLegalRequestForWrapper = document.getElementById('adminLegalRequestForWrapper');
        const adminLegalRequestForTrigger = document.getElementById('adminLegalRequestForTrigger');
        const adminLegalRequestForTriggerValue = document.getElementById('adminLegalRequestForTriggerValue');
        const adminLegalRequestForMenu = document.getElementById('adminLegalRequestForMenu');
        const categoryContainer = document.getElementById('categoryContainer');
        const categorySelect = document.getElementById('category_select');
        const categoryWrapper = document.getElementById('categoryWrapper');
        const categoryTrigger = document.getElementById('categoryTrigger');
        const categoryTriggerValue = document.getElementById('categoryTriggerValue');
        const categoryMenu = document.getElementById('categoryMenu');
        const marketingSubcategoryRow = document.getElementById('marketingSubcategoryRow');
        const marketingSubcategoryContainer = document.getElementById('marketingSubcategoryContainer');
        const marketingSubcategorySelect = document.getElementById('marketing_subcategory');
        const marketingSubcategoryWrapper = document.getElementById('marketingSubcategoryWrapper');
        const marketingSubcategoryTrigger = document.getElementById('marketingSubcategoryTrigger');
        const marketingSubcategoryTriggerValue = document.getElementById('marketingSubcategoryTriggerValue');
        const marketingSubcategoryMenu = document.getElementById('marketingSubcategoryMenu');
        const supplyChainDetailsRow = document.getElementById('supplyChainDetailsRow');
        const supplyChainDetailsFields = document.getElementById('supplyChainDetailsFields');
        const supplyChainAttachmentHost = document.getElementById('supplyChainAttachmentHost');
        const kamiBannerContainer = document.getElementById('kamiBannerContainer');
        const concernTypeContainer = document.getElementById('concernTypeContainer');
        const concernTypeSelect = document.getElementById('hr_concern_type');
        const concernTypeWrapper = document.getElementById('concernTypeWrapper');
        const concernTypeTrigger = document.getElementById('concernTypeTrigger');
        const concernTypeTriggerValue = document.getElementById('concernTypeTriggerValue');
        const concernTypeMenu = document.getElementById('concernTypeMenu');
        const concernTypeOtherContainer = document.getElementById('concernTypeOtherContainer');
        const concernTypeOtherInput = document.getElementById('hr_concern_type_other');
        const leaveSubjectContainer = document.getElementById('leaveSubjectContainer');
        const leaveSubjectInput = document.getElementById('request_subject_title');
        const medicalCashAdvanceSection = document.getElementById('medicalCashAdvanceSection');
        const medicalCashPurposeInput = document.getElementById('medical_cash_purpose');
        const medicalCashAmountInput = document.getElementById('medical_cash_amount');
        const medicalCashDateNeededInput = document.getElementById('medical_cash_date_needed');
        const medicalCashAttachmentHost = document.getElementById('medicalCashAttachmentHost');
        const incidentReportSection = document.getElementById('incidentReportSection');
        const incidentReportAttachmentHost = document.getElementById('incidentReportAttachmentHost');
        const incidentSummaryInput = document.getElementById('incident_summary');
        const incidentGdriveLinkInput = document.getElementById('incident_gdrive_link');
        const trainingRequestSection = document.getElementById('trainingRequestSection');
        const trainingRequestTitleInput = document.getElementById('training_request_title');
        const trainingRequestProviderInput = document.getElementById('training_request_provider');
        const trainingRequestStartDateInput = document.getElementById('training_request_start_date');
        const trainingRequestEndDateInput = document.getElementById('training_request_end_date');
        const trainingRequestVenueInput = document.getElementById('training_request_venue');
        const trainingRequestFeeInput = document.getElementById('training_request_fee');
        const companyPropertySection = document.getElementById('companyPropertySection');
        const companyPropertyTypeInputs = Array.from(document.querySelectorAll('input[name="company_property_type"]'));
        const companyPropertyReasonInputs = Array.from(document.querySelectorAll('input[name="company_property_reason"]'));
        const coeRequestSection = document.getElementById('coeRequestSection');
        const coeRequestReasonInputs = Array.from(document.querySelectorAll('input[name="coe_request_reason"]'));
        const coeRequestReasonOtherInput = document.getElementById('coe_request_reason_other');
        const coeSalaryDetailsInputs = Array.from(document.querySelectorAll('input[name="coe_salary_details"]'));
        const coePreferredReleaseDateInput = document.getElementById('coe_preferred_release_date');
        const coeDeliveryMethodInputs = Array.from(document.querySelectorAll('input[name="coe_delivery_method"]'));
        const coeRemarksInput = document.getElementById('coe_remarks');
        const colRequestSection = document.getElementById('colRequestSection');
        const certificateLeaveDateInput = document.getElementById('certificate_leave_date');
        const certificateLeavePurposeSelect = document.getElementById('certificate_leave_purpose');
        const certificateLeavePurposeOtherContainer = document.getElementById('certificateLeavePurposeOtherContainer');
        const certificateLeavePurposeOtherInput = document.getElementById('certificate_leave_purpose_other');
        const emailRequestSection = document.getElementById('emailRequestSection');
        const emailRequestTypeSelect = document.getElementById('email_request_type');
        const emailRequestTypeWrapper = document.getElementById('emailRequestTypeWrapper');
        const emailRequestTypeTrigger = document.getElementById('emailRequestTypeTrigger');
        const emailRequestTypeTriggerValue = document.getElementById('emailRequestTypeTriggerValue');
        const emailRequestTypeMenu = document.getElementById('emailRequestTypeMenu');
        const emailCreationFields = document.getElementById('emailCreationFields');
        const emailRequestList = document.getElementById('emailRequestList');
        const emailCreationTemplate = document.getElementById('emailCreationTemplate');
        const emailEmployeeSwitcher = document.getElementById('emailEmployeeSwitcher');
        const emailRequestCounter = document.getElementById('emailRequestCounter');
        const sapRequestSection = document.getElementById('sapRequestSection');
        const sapRequestList = document.getElementById('sapRequestList');
        const sapRequestTemplate = document.getElementById('sapRequestTemplate');
        const sapAddEmployeeBtn = document.getElementById('sapAddEmployeeBtn');
        const sapEmployeeSwitcher = document.getElementById('sapEmployeeSwitcher');
        const sapRequestCounter = document.getElementById('sapRequestCounter');
        const marketingRequestSection = document.getElementById('marketingRequestSection');
        const projectNameInput = document.getElementById('project_name');
        const areaCodeSelect = document.getElementById('area_code');
        const areaCodeWrapper = document.getElementById('areaCodeWrapper');
        const areaCodeTrigger = document.getElementById('areaCodeTrigger');
        const areaCodeTriggerValue = document.getElementById('areaCodeTriggerValue');
        const areaCodeMenu = document.getElementById('areaCodeMenu');
        const marketingDepartmentSelect = document.getElementById('marketing_department');
        const marketingDepartmentWrapper = document.getElementById('marketingDepartmentWrapper');
        const marketingDepartmentTrigger = document.getElementById('marketingDepartmentTrigger');
        const marketingDepartmentTriggerValue = document.getElementById('marketingDepartmentTriggerValue');
        const marketingDepartmentMenu = document.getElementById('marketingDepartmentMenu');
        const requestedMaterialsSelect = document.getElementById('requested_materials');
        const requestedMaterialsWrapper = document.getElementById('requestedMaterialsGroup');
        const requestedMaterialsTrigger = document.getElementById('requestedMaterialsTrigger');
        const requestedMaterialsTriggerValue = document.getElementById('requestedMaterialsTriggerValue');
        const requestedMaterialsMenu = document.getElementById('requestedMaterialsMenu');
        const requestedMaterialsInputs = requestedMaterialsSelect ? [requestedMaterialsSelect] : Array.from(document.querySelectorAll('input[name="requested_materials[]"]'));
        const requestedMaterialsOtherRow = document.getElementById('requestedMaterialsOtherRow');
        const requestedMaterialsOtherInput = document.getElementById('requested_materials_other');
        const materialSizeInput = document.getElementById('material_size');
        const materialSizeUnitInputs = Array.from(document.querySelectorAll('input[name="material_size_unit"]'));
        const materialSizeValueInputs = Array.from(document.querySelectorAll('input[name="material_size_value"]'));
        const projectDeadlineInput = document.getElementById('project_deadline');
        const projectDeadlineHelp = document.getElementById('projectDeadlineHelp');
        const projectDeadlineError = document.getElementById('projectDeadlineError');
        const cropSelect = document.getElementById('crop');
        const cropWrapper = document.getElementById('cropGroup');
        const cropTrigger = document.getElementById('cropTrigger');
        const cropTriggerValue = document.getElementById('cropTriggerValue');
        const cropMenu = document.getElementById('cropMenu');
        const cropInputs = cropSelect ? [cropSelect] : Array.from(document.querySelectorAll('input[name="crop[]"]'));
        const cropOtherRow = document.getElementById('cropOtherRow');
        const cropOtherInput = document.getElementById('crop_other');
        const otherRequestDetailsSection = document.getElementById('otherRequestDetailsSection');
        const otherDescriptionSection = document.getElementById('otherDescriptionSection');
        const requestSubjectLabel = document.getElementById('requestSubjectLabel');
        const descriptionLabel = document.getElementById('descriptionLabel');
        const sssBenefitsContainer = document.getElementById('sssBenefitsContainer');
        const descriptionOriginalHost = document.getElementById('descriptionOriginalHost');
        const emailDescriptionHost = document.getElementById('emailDescriptionHost');
        const marketingDescriptionHost = document.getElementById('marketingDescriptionHost');
        const descriptionContainer = document.getElementById('descriptionContainer');
        const descriptionField = document.getElementById('descriptionField');
        const attachmentOriginalHost = document.getElementById('attachmentOriginalHost');
        const attachmentContainer = document.getElementById('attachmentContainer');
        const attachmentLabelText = document.getElementById('attachmentLabelText');
        const medicalCashAttachmentIntro = document.getElementById('medicalCashAttachmentIntro');
        const attachmentOptionalText = document.getElementById('attachmentOptionalText');
        const attachmentRequiredAsterisk = document.getElementById('attachmentRequiredAsterisk');
        const attachmentHelpText = document.getElementById('attachmentHelpText');
        const chooseFileBtnText = document.getElementById('chooseFileBtnText');
        const ajaxErrorBanner = document.getElementById('ajaxError');
        const urgencyContainer = document.getElementById('urgencyContainer');
        const priorityHidden = document.getElementById('priority_hidden');
        const urgencySelect = document.getElementById('urgencySelect');
        const urgencyWrapper = document.getElementById('urgencyWrapper');
        const urgencyTrigger = document.getElementById('urgencyTrigger');
        const urgencyTriggerValue = document.getElementById('urgencyTriggerValue');
        const urgencyMenu = document.getElementById('urgencyMenu');
        const urgencyLabel = urgencyContainer ? urgencyContainer.querySelector('label') : null;
        const sssAutoDescription = 'SSS Notification and Benefits Concern submission.';
        const sssUploadConfigs = [
            { inputId: 'sssSicknessFormInput', labelId: 'sssSicknessFormName', listId: 'sssSicknessFormList', errorId: 'sssSicknessFormError', label: 'Accomplished SSS Sickness Form', maxFiles: 1 },
            { inputId: 'sssMedicalProceduresInput', labelId: 'sssMedicalProceduresName', listId: 'sssMedicalProceduresList', errorId: 'sssMedicalProceduresError', label: 'Medical Procedures', maxFiles: 5 },
            { inputId: 'sssLaboratoryResultsInput', labelId: 'sssLaboratoryResultsName', listId: 'sssLaboratoryResultsList', errorId: 'sssLaboratoryResultsError', label: 'Laboratory Results', maxFiles: 5 },
            { inputId: 'sssMedicalCertificatesInput', labelId: 'sssMedicalCertificatesName', listId: 'sssMedicalCertificatesList', errorId: 'sssMedicalCertificatesError', label: 'Medical Certificates', maxFiles: 5 },
            { inputId: 'sssDischargeSummaryInput', labelId: 'sssDischargeSummaryName', listId: 'sssDischargeSummaryList', errorId: 'sssDischargeSummaryError', label: 'Discharge Summary/Proof', maxFiles: 5 }
        ];
        const sssUploadState = {};
        const lapcDepartments = <?= json_encode(array_values($lapcDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const pccDepartments = <?= json_encode(array_values($pccDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mhcDepartments = <?= json_encode(array_values($mhcDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const emailCreationDepartmentOptionsBySubsidiary = {
            '@leadsagri.com': lapcDepartments,
            '@primestocks.ph': pccDepartments,
            '@malvedaholdings.com': mhcDepartments
        };
        const defaultCategories = <?= json_encode($requestTicketDefaultCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mpdcCategories = <?= json_encode($requestTicketMpdcCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lingapCategories = <?= json_encode($requestTicketLingapCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const othersOnlyCompanies = <?= json_encode($requestTicketOthersOnlyCompanies, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lapcDepartmentCategories = <?= json_encode($requestTicketLapcDepartmentCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lapcAdminLegalRequestCategories = <?= json_encode($requestTicketLapcAdminLegalRequestCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mhcDepartmentCategories = <?= json_encode($requestTicketMhcDepartmentCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lapcSupplyChainRequestTypes = <?= json_encode($lapcSupplyChainRequestTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lapcSupplyChainDetailFields = <?= json_encode($lapcSupplyChainDetailFields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const savedSupplyChainDetails = <?= json_encode($_POST['supply_chain_details'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lapcMarketingSubcategories = <?= json_encode([
            'Marketing Operations' => [
                'Promo materials',
                'Samples',
                'Product Return Request (RPRR)',
                'Cash Advance (CA) update',
                'Cash Advance Liquidation (CAL) update',
                'Request for Cheque (RFC) payment update',
                'Claims/ Incentive update - Distributor Programs',
                'Claims / incentive update - Dealer Program',
                'Claims/ incentive update - Farmer Program',
                'Distributor enrollment update',
                'Dealer enrollment update',
                'Farmer enrollment update',
                'Report update - Demand Creation Activities',
                'Report update - Monthly Sales reports',
                'Report update - Crop Status',
                'Report update - Market Inventory Report',
                'KAMI topics/ walk-thru',
            ],
            'Channel & Campaigns' => [
                'Program update - Distributor',
                'Program update - Dealer',
                'Program update - Farmer',
                'Pricing review/ adjustments',
                'Special Projects - Jackpot All Stars',
                'Special Projects - Farmasee Physical Stores',
                'Regional facilitation concerns',
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        function closeAreaCodeDropdown() {
            if (!areaCodeWrapper || !areaCodeTrigger || !areaCodeMenu) return;
            areaCodeWrapper.classList.remove('is-open');
            areaCodeTrigger.setAttribute('aria-expanded', 'false');
            areaCodeMenu.hidden = true;
        }
        function syncAreaCodeTriggerLabel() {
            if (!areaCodeSelect || !areaCodeTriggerValue) return;
            const selectedOption = areaCodeSelect.options[areaCodeSelect.selectedIndex];
            const placeholderOption = areaCodeSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose area code').trim();
            areaCodeTriggerValue.textContent = nextLabel || 'Choose area code';
        }
        function renderAreaCodeDropdownOptions() {
            if (!areaCodeSelect || !areaCodeMenu || !areaCodeTrigger) return;
            const currentValue = String(areaCodeSelect.value || '');
            const options = Array.from(areaCodeSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            areaCodeMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    areaCodeSelect.value = optionValue;
                    areaCodeSelect.setAttribute('data-selected', optionValue);
                    syncAreaCodeTriggerLabel();
                    renderAreaCodeDropdownOptions();
                    closeAreaCodeDropdown();
                    areaCodeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    areaCodeTrigger.focus();
                });
                areaCodeMenu.appendChild(item);
            });
            syncAreaCodeTriggerLabel();
            areaCodeTrigger.disabled = !!areaCodeSelect.disabled;
            if (areaCodeSelect.disabled) {
                closeAreaCodeDropdown();
            }
        }
        function closeMarketingDepartmentDropdown() {
            if (!marketingDepartmentWrapper || !marketingDepartmentTrigger || !marketingDepartmentMenu) return;
            marketingDepartmentWrapper.classList.remove('is-open');
            marketingDepartmentTrigger.setAttribute('aria-expanded', 'false');
            marketingDepartmentMenu.hidden = true;
        }
        function syncMarketingDepartmentTriggerLabel() {
            if (!marketingDepartmentSelect || !marketingDepartmentTriggerValue) return;
            const selectedOption = marketingDepartmentSelect.options[marketingDepartmentSelect.selectedIndex];
            const placeholderOption = marketingDepartmentSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose department').trim();
            marketingDepartmentTriggerValue.textContent = nextLabel || 'Choose department';
        }
        function renderMarketingDepartmentDropdownOptions() {
            if (!marketingDepartmentSelect || !marketingDepartmentMenu || !marketingDepartmentTrigger) return;
            const currentValue = String(marketingDepartmentSelect.value || '');
            const options = Array.from(marketingDepartmentSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            marketingDepartmentMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    marketingDepartmentSelect.value = optionValue;
                    marketingDepartmentSelect.setAttribute('data-selected', optionValue);
                    syncMarketingDepartmentTriggerLabel();
                    renderMarketingDepartmentDropdownOptions();
                    closeMarketingDepartmentDropdown();
                    marketingDepartmentSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    marketingDepartmentTrigger.focus();
                });
                marketingDepartmentMenu.appendChild(item);
            });
            syncMarketingDepartmentTriggerLabel();
            marketingDepartmentTrigger.disabled = !!marketingDepartmentSelect.disabled;
            if (marketingDepartmentSelect.disabled) {
                closeMarketingDepartmentDropdown();
            }
        }
        function closeRequestedMaterialsDropdown() {
            if (!requestedMaterialsWrapper || !requestedMaterialsTrigger || !requestedMaterialsMenu) return;
            requestedMaterialsWrapper.classList.remove('is-open');
            requestedMaterialsTrigger.setAttribute('aria-expanded', 'false');
            requestedMaterialsMenu.hidden = true;
        }
        function syncRequestedMaterialsTriggerLabel() {
            if (!requestedMaterialsSelect || !requestedMaterialsTriggerValue) return;
            const selectedOption = requestedMaterialsSelect.options[requestedMaterialsSelect.selectedIndex];
            const placeholderOption = requestedMaterialsSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose requested material').trim();
            requestedMaterialsTriggerValue.textContent = nextLabel || 'Choose requested material';
        }
        function renderRequestedMaterialsDropdownOptions() {
            if (!requestedMaterialsSelect || !requestedMaterialsMenu || !requestedMaterialsTrigger) return;
            const currentValue = String(requestedMaterialsSelect.value || '');
            const options = Array.from(requestedMaterialsSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            requestedMaterialsMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    requestedMaterialsSelect.value = optionValue;
                    syncRequestedMaterialsTriggerLabel();
                    renderRequestedMaterialsDropdownOptions();
                    closeRequestedMaterialsDropdown();
                    requestedMaterialsSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    requestedMaterialsTrigger.focus();
                });
                requestedMaterialsMenu.appendChild(item);
            });
            syncRequestedMaterialsTriggerLabel();
            requestedMaterialsTrigger.disabled = !!requestedMaterialsSelect.disabled;
            if (requestedMaterialsSelect.disabled) {
                closeRequestedMaterialsDropdown();
            }
        }
        function closeCropDropdown() {
            if (!cropWrapper || !cropTrigger || !cropMenu) return;
            cropWrapper.classList.remove('is-open');
            cropTrigger.setAttribute('aria-expanded', 'false');
            cropMenu.hidden = true;
        }
        function syncCropTriggerLabel() {
            if (!cropSelect || !cropTriggerValue) return;
            const selectedOption = cropSelect.options[cropSelect.selectedIndex];
            const placeholderOption = cropSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose crop').trim();
            cropTriggerValue.textContent = nextLabel || 'Choose crop';
        }
        function renderCropDropdownOptions() {
            if (!cropSelect || !cropMenu || !cropTrigger) return;
            const currentValue = String(cropSelect.value || '');
            const options = Array.from(cropSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            cropMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    cropSelect.value = optionValue;
                    syncCropTriggerLabel();
                    renderCropDropdownOptions();
                    closeCropDropdown();
                    cropSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    cropTrigger.focus();
                });
                cropMenu.appendChild(item);
            });
            syncCropTriggerLabel();
            cropTrigger.disabled = !!cropSelect.disabled;
            if (cropSelect.disabled) {
                closeCropDropdown();
            }
        }
        function closeRecipientDropdown() {
            if (!recipientWrapper || !recipientTrigger || !recipientMenu) return;
            recipientWrapper.classList.remove('is-open');
            recipientTrigger.setAttribute('aria-expanded', 'false');
            recipientMenu.hidden = true;
        }
        function setStaticSelectState(wrapper, trigger, menu, isStatic) {
            if (wrapper) {
                wrapper.classList.toggle('is-static', !!isStatic);
            }
            if (trigger) {
                trigger.dataset.staticDisplay = isStatic ? '1' : '0';
            }
            if (menu && isStatic) {
                menu.hidden = true;
            }
        }
        function syncRecipientTriggerLabel() {
            if (!recipientDropdown || !recipientTriggerValue) return;
            const selectedOption = recipientDropdown.options[recipientDropdown.selectedIndex];
            const placeholderOption = recipientDropdown.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Select a company').trim();
            recipientTriggerValue.textContent = nextLabel || 'Select a company';
        }
        function renderRecipientDropdownOptions() {
            if (!recipientDropdown || !recipientMenu || !recipientTrigger) return;
            const options = Array.from(recipientDropdown.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            if (options.length === 1 && String(recipientDropdown.value || '') === '') {
                recipientDropdown.value = String(options[0].value || '');
                recipientDropdown.setAttribute('data-selected', String(options[0].value || ''));
            }
            const currentValue = String(recipientDropdown.value || '');
            recipientMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    recipientDropdown.value = optionValue;
                    recipientDropdown.setAttribute('data-selected', optionValue);
                    syncRecipientTriggerLabel();
                    renderRecipientDropdownOptions();
                    closeRecipientDropdown();
                    recipientDropdown.dispatchEvent(new Event('change', { bubbles: true }));
                    recipientTrigger.focus();
                });
                recipientMenu.appendChild(item);
            });
            setStaticSelectState(recipientWrapper, recipientTrigger, recipientMenu, options.length <= 1);
            syncRecipientTriggerLabel();
            recipientTrigger.disabled = !!recipientDropdown.disabled || options.length <= 1;
            if (recipientDropdown.disabled) {
                closeRecipientDropdown();
            }
        }
        function closeDepartmentDropdown() {
            if (!departmentWrapper || !departmentTrigger || !departmentMenu) return;
            departmentWrapper.classList.remove('is-open');
            departmentTrigger.setAttribute('aria-expanded', 'false');
            departmentMenu.hidden = true;
        }
        function syncDepartmentTriggerLabel() {
            if (!departmentSelect || !departmentTriggerValue) return;
            const selectedOption = departmentSelect.options[departmentSelect.selectedIndex];
            const placeholderOption = departmentSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Select department').trim();
            departmentTriggerValue.textContent = nextLabel || 'Select department';
        }
        function renderDepartmentDropdownOptions() {
            if (!departmentSelect || !departmentMenu || !departmentTrigger) return;
            const options = Array.from(departmentSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            const currentValue = String(departmentSelect.value || '');
            departmentMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    departmentSelect.value = optionValue;
                    departmentSelect.setAttribute('data-selected', optionValue);
                    syncDepartmentTriggerLabel();
                    renderDepartmentDropdownOptions();
                    closeDepartmentDropdown();
                    departmentSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    departmentTrigger.focus();
                });
                departmentMenu.appendChild(item);
            });
            setStaticSelectState(departmentWrapper, departmentTrigger, departmentMenu, false);
            syncDepartmentTriggerLabel();
            departmentTrigger.disabled = !!departmentSelect.disabled;
            if (departmentSelect.disabled) {
                closeDepartmentDropdown();
            }
        }
        function populateDepartments(options) {
            if (!departmentSelect) return;
            const selectedValue = String(departmentSelect.getAttribute('data-selected') || departmentSelect.value || '');
            departmentSelect.innerHTML = '<option value="" disabled selected hidden>Select department</option>';
            options.forEach(function(optionValue) {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue;
                if (selectedValue !== '' && selectedValue === optionValue) {
                    option.selected = true;
                }
                departmentSelect.appendChild(option);
            });
            if (selectedValue !== '' && !options.includes(selectedValue)) {
                departmentSelect.value = '';
            }
            renderDepartmentDropdownOptions();
        }
        function closeCategoryDropdown() {
            if (!categoryWrapper || !categoryTrigger || !categoryMenu) return;
            categoryWrapper.classList.remove('is-open');
            categoryTrigger.setAttribute('aria-expanded', 'false');
            categoryMenu.hidden = true;
        }
        function closeAdminLegalRequestForDropdown() {
            if (!adminLegalRequestForWrapper || !adminLegalRequestForTrigger || !adminLegalRequestForMenu) return;
            adminLegalRequestForWrapper.classList.remove('is-open');
            adminLegalRequestForTrigger.setAttribute('aria-expanded', 'false');
            adminLegalRequestForMenu.hidden = true;
        }
        function syncAdminLegalRequestForTriggerLabel() {
            if (!adminLegalRequestForSelect || !adminLegalRequestForTriggerValue) return;
            const selectedOption = adminLegalRequestForSelect.options[adminLegalRequestForSelect.selectedIndex];
            const placeholderOption = adminLegalRequestForSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose request for').trim();
            adminLegalRequestForTriggerValue.textContent = nextLabel || 'Choose request for';
        }
        function renderAdminLegalRequestForDropdownOptions() {
            if (!adminLegalRequestForSelect || !adminLegalRequestForMenu || !adminLegalRequestForTrigger) return;
            const currentValue = String(adminLegalRequestForSelect.value || '');
            const options = Array.from(adminLegalRequestForSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            adminLegalRequestForMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    adminLegalRequestForSelect.value = optionValue;
                    adminLegalRequestForSelect.setAttribute('data-selected', optionValue);
                    syncAdminLegalRequestForTriggerLabel();
                    renderAdminLegalRequestForDropdownOptions();
                    closeAdminLegalRequestForDropdown();
                    adminLegalRequestForSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    adminLegalRequestForTrigger.focus();
                });
                adminLegalRequestForMenu.appendChild(item);
            });
            syncAdminLegalRequestForTriggerLabel();
            adminLegalRequestForTrigger.disabled = !!adminLegalRequestForSelect.disabled;
            if (adminLegalRequestForSelect.disabled) {
                closeAdminLegalRequestForDropdown();
            }
        }
        function syncCategoryTriggerLabel() {
            if (!categorySelect || !categoryTriggerValue) return;
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const placeholderOption = categorySelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose category').trim();
            categoryTriggerValue.textContent = nextLabel || 'Choose category';
        }
        function renderCategoryDropdownOptions() {
            if (!categorySelect || !categoryMenu || !categoryTrigger) return;
            const currentValue = String(categorySelect.value || '');
            const options = Array.from(categorySelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            categoryMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    categorySelect.value = optionValue;
                    categorySelect.setAttribute('data-selected', optionValue);
                    syncCategoryTriggerLabel();
                    renderCategoryDropdownOptions();
                    closeCategoryDropdown();
                    categorySelect.dispatchEvent(new Event('change', { bubbles: true }));
                    categoryTrigger.focus();
                });
                categoryMenu.appendChild(item);
            });
            syncCategoryTriggerLabel();
            categoryTrigger.disabled = !!categorySelect.disabled;
            if (categorySelect.disabled) {
                closeCategoryDropdown();
            }
        }
        function closeMarketingSubcategoryDropdown() {
            if (!marketingSubcategoryWrapper || !marketingSubcategoryTrigger || !marketingSubcategoryMenu) return;
            marketingSubcategoryWrapper.classList.remove('is-open');
            marketingSubcategoryTrigger.setAttribute('aria-expanded', 'false');
            marketingSubcategoryMenu.hidden = true;
        }
        function closeEmailRequestTypeDropdown() {
            if (!emailRequestTypeWrapper || !emailRequestTypeTrigger || !emailRequestTypeMenu) return;
            emailRequestTypeWrapper.classList.remove('is-open');
            emailRequestTypeTrigger.setAttribute('aria-expanded', 'false');
            emailRequestTypeMenu.hidden = true;
        }
        function syncEmailRequestTypeTriggerLabel() {
            if (!emailRequestTypeSelect || !emailRequestTypeTriggerValue) return;
            const selectedOption = emailRequestTypeSelect.options[emailRequestTypeSelect.selectedIndex];
            const placeholderOption = emailRequestTypeSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose email request type').trim();
            emailRequestTypeTriggerValue.textContent = nextLabel || 'Choose email request type';
        }
        function renderEmailRequestTypeDropdownOptions() {
            if (!emailRequestTypeSelect || !emailRequestTypeMenu || !emailRequestTypeTrigger) return;
            const currentValue = String(emailRequestTypeSelect.value || '');
            const options = Array.from(emailRequestTypeSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            emailRequestTypeMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    emailRequestTypeSelect.value = optionValue;
                    emailRequestTypeSelect.setAttribute('data-selected', optionValue);
                    syncEmailRequestTypeTriggerLabel();
                    renderEmailRequestTypeDropdownOptions();
                    closeEmailRequestTypeDropdown();
                    emailRequestTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    emailRequestTypeTrigger.focus();
                });
                emailRequestTypeMenu.appendChild(item);
            });
            syncEmailRequestTypeTriggerLabel();
            emailRequestTypeTrigger.disabled = !!emailRequestTypeSelect.disabled;
            if (emailRequestTypeSelect.disabled) {
                closeEmailRequestTypeDropdown();
            }
        }
        function syncMarketingSubcategoryTriggerLabel() {
            if (!marketingSubcategorySelect || !marketingSubcategoryTriggerValue) return;
            const selectedOption = marketingSubcategorySelect.options[marketingSubcategorySelect.selectedIndex];
            const placeholderOption = marketingSubcategorySelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose sub-category').trim();
            marketingSubcategoryTriggerValue.textContent = nextLabel || 'Choose sub-category';
        }
        function renderMarketingSubcategoryDropdownOptions() {
            if (!marketingSubcategorySelect || !marketingSubcategoryMenu || !marketingSubcategoryTrigger) return;
            const currentValue = String(marketingSubcategorySelect.value || '');
            const options = Array.from(marketingSubcategorySelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            marketingSubcategoryMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    marketingSubcategorySelect.value = optionValue;
                    marketingSubcategorySelect.setAttribute('data-selected', optionValue);
                    syncMarketingSubcategoryTriggerLabel();
                    renderMarketingSubcategoryDropdownOptions();
                    closeMarketingSubcategoryDropdown();
                    marketingSubcategorySelect.dispatchEvent(new Event('change', { bubbles: true }));
                    marketingSubcategoryTrigger.focus();
                });
                marketingSubcategoryMenu.appendChild(item);
            });
            syncMarketingSubcategoryTriggerLabel();
            marketingSubcategoryTrigger.disabled = !!marketingSubcategorySelect.disabled;
            if (marketingSubcategorySelect.disabled) {
                closeMarketingSubcategoryDropdown();
            }
        }
        function populateMarketingSubcategories(options) {
            if (!marketingSubcategorySelect) return;
            const selectedValue = String(marketingSubcategorySelect.getAttribute('data-selected') || marketingSubcategorySelect.value || '');
            marketingSubcategorySelect.innerHTML = '<option value="" disabled selected hidden>Choose sub-category</option>';
            options.forEach(function(optionValue) {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue;
                if (selectedValue !== '' && selectedValue === optionValue) {
                    option.selected = true;
                }
                marketingSubcategorySelect.appendChild(option);
            });
            if (selectedValue !== '' && !options.includes(selectedValue)) {
                marketingSubcategorySelect.value = '';
                marketingSubcategorySelect.setAttribute('data-selected', '');
            }
            renderMarketingSubcategoryDropdownOptions();
        }
        function shouldShowMarketingSubcategory() {
            if (!recipientDropdown || !departmentSelect || !categorySelect) return false;
            const recipientValue = String(recipientDropdown.value || '');
            const departmentValue = String(departmentSelect.value || '');
            const selectedCategory = String(categorySelect.value || '');
            if (recipientValue !== '@leadsagri.com') return false;
            return (departmentValue === 'Marketing' && Object.prototype.hasOwnProperty.call(lapcMarketingSubcategories, selectedCategory))
                || (departmentValue === 'Supply Chain' && Object.prototype.hasOwnProperty.call(lapcSupplyChainRequestTypes, selectedCategory));
        }
        function toggleMarketingSubcategory() {
            if (!marketingSubcategoryRow || !marketingSubcategoryContainer || !marketingSubcategorySelect) return;
            const selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
            const shouldShow = shouldShowMarketingSubcategory();
            marketingSubcategoryRow.style.display = shouldShow ? '' : 'none';
            marketingSubcategoryContainer.classList.toggle('is-visible', shouldShow);
            if (shouldShow) {
                marketingSubcategorySelect.disabled = false;
                marketingSubcategorySelect.setAttribute('required', 'required');
                const requestTypeOptions = String(departmentSelect.value || '') === 'Supply Chain'
                    ? (lapcSupplyChainRequestTypes[selectedCategory] || [])
                    : (lapcMarketingSubcategories[selectedCategory] || []);
                populateMarketingSubcategories(requestTypeOptions);
            } else {
                marketingSubcategorySelect.value = '';
                marketingSubcategorySelect.setAttribute('data-selected', '');
                marketingSubcategorySelect.disabled = true;
                marketingSubcategorySelect.removeAttribute('required');
                populateMarketingSubcategories([]);
                closeMarketingSubcategoryDropdown();
            }
            toggleSupplyChainDetails();
        }

        function toggleSupplyChainDetails() {
            if (!supplyChainDetailsRow || !supplyChainDetailsFields || !recipientDropdown || !departmentSelect || !categorySelect || !marketingSubcategorySelect) return;
            const category = String(categorySelect.value || '');
            const shouldShow = String(recipientDropdown.value || '') === '@leadsagri.com'
                && String(departmentSelect.value || '') === 'Supply Chain'
                && Object.prototype.hasOwnProperty.call(lapcSupplyChainDetailFields, category)
                && String(marketingSubcategorySelect.value || '') !== '';
            supplyChainDetailsRow.classList.toggle('is-visible', shouldShow);
            toggleSupplyChainOptionalSections(shouldShow);
            supplyChainDetailsFields.innerHTML = '';
            if (!shouldShow) return;

            (lapcSupplyChainDetailFields[category] || []).forEach(function(label, index) {
                if (/^(Supporting Photo|Supporting Documents)$/i.test(label)) return;
                const group = document.createElement('div');
                group.className = 'supply-chain-field';
                const formGroup = document.createElement('div');
                formGroup.className = 'form-group';
                const fieldLabel = document.createElement('label');
                fieldLabel.textContent = label;
                const isLongText = /Purpose|Special Requirements|Details\/Photos|Reason|Issue\/Concern|Specific Inquiry/i.test(label);
                if (isLongText) group.classList.add('supply-chain-full-row');
                const field = document.createElement(isLongText ? 'textarea' : 'input');
                field.className = 'form-control';
                field.name = 'supply_chain_details[' + label + ']';
                field.required = true;
                field.value = String(savedSupplyChainDetails[label] || '');
                if (!isLongText && /Date\/Time/i.test(label)) field.type = 'datetime-local';
                else if (!isLongText && /Date/i.test(label)) field.type = 'date';
                else if (!isLongText) field.type = 'text';
                if (isLongText) field.rows = 3;
                const requiredMark = document.createElement('span');
                requiredMark.className = 'required-asterisk';
                requiredMark.textContent = '*';
                fieldLabel.appendChild(document.createTextNode(' '));
                fieldLabel.appendChild(requiredMark);
                formGroup.append(fieldLabel, field);
                group.appendChild(formGroup);
                supplyChainDetailsFields.appendChild(group);
            });
        }

        function toggleSupplyChainOptionalSections(isSupplyChainRequest) {
            if (isSupplyChainRequest) {
                const supportAttachmentLabel = (lapcSupplyChainDetailFields[String(categorySelect.value || '')] || []).find(function(label) {
                    return /^(Supporting Photo|Supporting Documents)$/i.test(label);
                });
                const showStandardAttachment = String(categorySelect.value || '') === 'Delivery Concern / Exception';
                const shouldShowAttachment = !!supportAttachmentLabel || showStandardAttachment;
                if (descriptionContainer) {
                    descriptionContainer.style.display = 'none';
                    descriptionContainer.dataset.supplyChainHidden = 'true';
                }
                if (attachmentContainer) {
                    if (shouldShowAttachment && supplyChainAttachmentHost) {
                        moveAttachmentContainer(supplyChainAttachmentHost);
                    }
                    attachmentContainer.style.display = shouldShowAttachment ? '' : 'none';
                    attachmentContainer.dataset.supplyChainHidden = 'true';
                }
                if (attachmentLabelText) attachmentLabelText.textContent = supportAttachmentLabel || 'Attachment';
                if (descriptionField) descriptionField.removeAttribute('required');
            } else if (descriptionContainer && descriptionContainer.dataset.supplyChainHidden === 'true') {
                descriptionContainer.style.display = '';
                delete descriptionContainer.dataset.supplyChainHidden;
                if (attachmentContainer) {
                    if (attachmentOriginalHost) moveAttachmentContainer(attachmentOriginalHost);
                    attachmentContainer.style.display = '';
                    delete attachmentContainer.dataset.supplyChainHidden;
                }
                if (attachmentLabelText) attachmentLabelText.textContent = 'Attachment';
                if (descriptionField) descriptionField.setAttribute('required', 'required');
            }
        }
        function toggleDepartment() {
            if (!recipientDropdown || !departmentContainer || !departmentSelect) return;
            const value = String(recipientDropdown.value || '');
            const shouldShowDepartment = value === '@leadsagri.com' || value === '@primestocks.ph' || value === '@malvedaholdings.com';
            if (value === '@leadsagri.com') {
                populateDepartments(lapcDepartments);
                departmentContainer.style.display = 'block';
                departmentSelect.disabled = false;
                departmentSelect.setAttribute('required', 'required');
            } else if (value === '@primestocks.ph') {
                populateDepartments(pccDepartments);
                departmentContainer.style.display = 'block';
                departmentSelect.disabled = false;
                departmentSelect.setAttribute('required', 'required');
            } else if (value === '@malvedaholdings.com') {
                populateDepartments(mhcDepartments);
                departmentContainer.style.display = 'block';
                departmentSelect.disabled = false;
                departmentSelect.setAttribute('required', 'required');
            } else {
                departmentContainer.style.display = 'none';
                departmentSelect.value = '';
                departmentSelect.disabled = true;
                departmentSelect.removeAttribute('required');
                departmentSelect.setAttribute('data-selected', '');
            }
            renderDepartmentDropdownOptions();
            if (recipientDepartmentRow) {
                recipientDepartmentRow.classList.toggle('is-single', !shouldShowDepartment);
            }
        }
        function populateCategories(options) {
            if (!categorySelect) return;
            const selectedValue = String(categorySelect.getAttribute('data-selected') || categorySelect.value || '');
            categorySelect.innerHTML = '<option value="" disabled selected hidden>Choose category</option>';
            options.forEach(function(optionValue) {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue;
                if (selectedValue !== '' && selectedValue === optionValue) {
                    option.selected = true;
                }
                categorySelect.appendChild(option);
            });
            if (selectedValue !== '' && !options.includes(selectedValue)) {
                categorySelect.value = '';
            }
            renderCategoryDropdownOptions();
        }
        function isLapcAdminLegalSelected() {
            const recipientValue = recipientDropdown ? String(recipientDropdown.value || '') : '';
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            return recipientValue === '@leadsagri.com' && departmentValue === 'Admin & Legal';
        }
        function areRoutingSelectionsComplete() {
            if (!recipientDropdown || String(recipientDropdown.value || '') === '') return false;
            return !departmentSelect || departmentSelect.disabled || String(departmentSelect.value || '') !== '';
        }
        function getCategoryOptions() {
            if (!recipientDropdown) return defaultCategories;
            const recipientValue = String(recipientDropdown.value || '');
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            const requestForValue = adminLegalRequestForSelect ? String(adminLegalRequestForSelect.value || '') : '';
            if (othersOnlyCompanies.indexOf(recipientValue) !== -1) {
                return ['Others'];
            }
            if (recipientValue === '@malvedaproperties.com') {
                return mpdcCategories;
            }
            if (recipientValue === '@lingapleads.org') {
                return lingapCategories;
            }
            if (recipientValue === '@malvedaholdings.com' && Object.prototype.hasOwnProperty.call(mhcDepartmentCategories, departmentValue)) {
                return mhcDepartmentCategories[departmentValue];
            }
            if (recipientValue === '@leadsagri.com'
                && departmentValue === 'Admin & Legal'
                && Object.prototype.hasOwnProperty.call(lapcAdminLegalRequestCategories, requestForValue)) {
                return lapcAdminLegalRequestCategories[requestForValue];
            }
            if (recipientValue === '@leadsagri.com' && Object.prototype.hasOwnProperty.call(lapcDepartmentCategories, departmentValue)) {
                return lapcDepartmentCategories[departmentValue];
            }
            return defaultCategories;
        }
        function toggleCategories() {
            if (!recipientDropdown || !categorySelect) return;
            const adminLegalSelected = isLapcAdminLegalSelected();
            const requestForValue = adminLegalRequestForSelect ? String(adminLegalRequestForSelect.value || '') : '';
            const adminLegalOthersSelected = adminLegalSelected && requestForValue === 'Others';
            if (categoryUrgencyRow) {
                categoryUrgencyRow.classList.toggle('is-admin-legal-layout', adminLegalSelected);
            }
            if (adminLegalRequestForContainer && adminLegalRequestForSelect) {
                adminLegalRequestForContainer.style.display = adminLegalSelected ? '' : 'none';
                adminLegalRequestForSelect.disabled = !adminLegalSelected;
                adminLegalRequestForTrigger.disabled = !adminLegalSelected;
                if (adminLegalSelected) {
                    adminLegalRequestForSelect.setAttribute('required', 'required');
                } else {
                    adminLegalRequestForSelect.value = '';
                    adminLegalRequestForSelect.setAttribute('data-selected', '');
                    adminLegalRequestForSelect.removeAttribute('required');
                    closeAdminLegalRequestForDropdown();
                }
                renderAdminLegalRequestForDropdownOptions();
            }
            const routingSelectionsComplete = areRoutingSelectionsComplete();
            if (urgencySelect) {
                urgencySelect.disabled = !routingSelectionsComplete;
                if (routingSelectionsComplete) {
                    urgencySelect.setAttribute('required', 'required');
                } else {
                    urgencySelect.value = '';
                    urgencySelect.removeAttribute('required');
                    if (priorityHidden) priorityHidden.value = '';
                }
                renderUrgencyDropdownOptions();
            }
            if (!routingSelectionsComplete) {
                if (categoryContainer) categoryContainer.style.display = '';
                categorySelect.value = '';
                categorySelect.setAttribute('data-selected', '');
                categorySelect.disabled = true;
                categorySelect.removeAttribute('required');
                populateCategories([]);
                toggleMarketingSubcategory();
                syncRequestGridRows();
                return;
            }
            if (adminLegalSelected && requestForValue === '') {
                if (categoryContainer) categoryContainer.style.display = 'none';
                categorySelect.value = '';
                categorySelect.setAttribute('data-selected', '');
                categorySelect.disabled = true;
                categorySelect.removeAttribute('required');
                populateCategories([]);
                toggleMarketingSubcategory();
                toggleHrExtraFields();
                syncRequestGridRows();
                return;
            }
            if (adminLegalOthersSelected) {
                populateCategories(['Others']);
                categorySelect.disabled = false;
                categorySelect.value = 'Others';
                categorySelect.setAttribute('data-selected', 'Others');
                categorySelect.removeAttribute('required');
                if (categoryContainer) categoryContainer.style.display = 'none';
                toggleMarketingSubcategory();
                toggleHrExtraFields();
                syncRequestGridRows();
                return;
            }
            if (categoryContainer) categoryContainer.style.display = '';
            categorySelect.disabled = false;
            categorySelect.setAttribute('required', 'required');
            populateCategories(getCategoryOptions());
            syncRequestGridRows();
        }
        function closeUrgencyDropdown() {
            if (!urgencyWrapper || !urgencyTrigger || !urgencyMenu) return;
            urgencyWrapper.classList.remove('is-open');
            urgencyTrigger.setAttribute('aria-expanded', 'false');
            urgencyMenu.hidden = true;
        }
        function syncUrgencyTriggerLabel() {
            if (!urgencySelect || !urgencyTriggerValue) return;
            const selectedOption = urgencySelect.options[urgencySelect.selectedIndex];
            const placeholderOption = urgencySelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose level of urgency').trim();
            urgencyTriggerValue.textContent = nextLabel || 'Choose level of urgency';
        }
        function renderUrgencyDropdownOptions() {
            if (!urgencySelect || !urgencyMenu || !urgencyTrigger) return;
            const currentValue = String(urgencySelect.value || '');
            const options = Array.from(urgencySelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            urgencyMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    urgencySelect.value = optionValue;
                    if (priorityHidden) priorityHidden.value = optionValue;
                    syncUrgencyTriggerLabel();
                    renderUrgencyDropdownOptions();
                    closeUrgencyDropdown();
                    urgencySelect.dispatchEvent(new Event('change', { bubbles: true }));
                    urgencyTrigger.focus();
                });
                urgencyMenu.appendChild(item);
            });
            syncUrgencyTriggerLabel();
            urgencyTrigger.disabled = !!urgencySelect.disabled;
            if (urgencySelect.disabled) {
                closeUrgencyDropdown();
            }
        }
        function syncUrgencyInputs() {
            if (!priorityHidden || !urgencySelect) return;
            const selectedPriority = String(priorityHidden.value || '');
            const availableValues = Array.from(urgencySelect.options).map(function(option) {
                return String(option.value || '');
            });

            if (selectedPriority === '' || availableValues.indexOf(selectedPriority) === -1) {
                urgencySelect.value = '';
                syncUrgencyTriggerLabel();
                renderUrgencyDropdownOptions();
                return;
            }

            urgencySelect.value = selectedPriority;
            syncUrgencyTriggerLabel();
            renderUrgencyDropdownOptions();
        }
        function closeConcernTypeDropdown() {
            if (!concernTypeWrapper || !concernTypeTrigger || !concernTypeMenu) return;
            concernTypeWrapper.classList.remove('is-open');
            concernTypeTrigger.setAttribute('aria-expanded', 'false');
            concernTypeMenu.hidden = true;
        }
        function syncConcernTypeTriggerLabel() {
            if (!concernTypeSelect || !concernTypeTriggerValue) return;
            const selectedOption = concernTypeSelect.options[concernTypeSelect.selectedIndex];
            const placeholderOption = concernTypeSelect.querySelector('option[value=""]');
            const nextLabel = selectedOption && String(selectedOption.value || '') !== ''
                ? String(selectedOption.textContent || '').trim()
                : String((placeholderOption && placeholderOption.textContent) || 'Choose type of concern').trim();
            concernTypeTriggerValue.textContent = nextLabel || 'Choose type of concern';
        }
        function renderConcernTypeDropdownOptions() {
            if (!concernTypeSelect || !concernTypeMenu || !concernTypeTrigger) return;
            const currentValue = String(concernTypeSelect.value || '');
            const options = Array.from(concernTypeSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
            concernTypeMenu.innerHTML = '';
            options.forEach(function(option) {
                const optionValue = String(option.value || '');
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'custom-select-option' + (currentValue === optionValue ? ' is-selected' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', currentValue === optionValue ? 'true' : 'false');
                item.textContent = String(option.textContent || optionValue);
                item.addEventListener('click', function() {
                    concernTypeSelect.value = optionValue;
                    concernTypeSelect.setAttribute('data-selected', optionValue);
                    syncConcernTypeTriggerLabel();
                    renderConcernTypeDropdownOptions();
                    closeConcernTypeDropdown();
                    concernTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    concernTypeTrigger.focus();
                });
                concernTypeMenu.appendChild(item);
            });
            syncConcernTypeTriggerLabel();
            concernTypeTrigger.disabled = !!concernTypeSelect.disabled;
            if (concernTypeSelect.disabled) {
                closeConcernTypeDropdown();
            }
        }
        function isLapcHrSelection() {
            const recipientValue = recipientDropdown ? String(recipientDropdown.value || '') : '';
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            return recipientValue === '@leadsagri.com' && departmentValue === 'HR';
        }
        function isLapcItSelection() {
            const recipientValue = recipientDropdown ? String(recipientDropdown.value || '') : '';
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            return recipientValue === '@leadsagri.com' && departmentValue === 'IT';
        }
        function isMhcMarketingSelection() {
            const recipientValue = recipientDropdown ? String(recipientDropdown.value || '') : '';
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            return recipientValue === '@malvedaholdings.com' && departmentValue === 'Marketing Creatives';
        }
        function setUrgencyOptions(mode) {
            if (!urgencySelect) return;
            const modeKey = String(mode || 'hr');
            const desired = modeKey === 'marketing'
                ? [
                    { value: '', text: 'Choose urgency level' },
                    { value: '1', text: '1' },
                    { value: '2', text: '2' },
                    { value: '3', text: '3' },
                    { value: '4', text: '4' },
                    { value: '5', text: '5' },
                    { value: '6', text: '6' },
                    { value: '7', text: '7' },
                    { value: '8', text: '8' },
                    { value: '9', text: '9' },
                    { value: '10', text: '10' }
                ]
                : [
                    { value: '', text: 'Choose level of urgency' },
                    { value: 'Low', text: 'Low (7 to 9 days)' },
                    { value: 'Medium', text: 'Medium (4 to 6 days)' },
                    { value: 'High', text: 'High (1 to 3 days)' }
                ];
            const currentValues = Array.from(urgencySelect.options).map(function(option) {
                return String(option.value || '') + ':' + String(option.textContent || '');
            }).join('|');
            const nextValues = desired.map(function(option) {
                return String(option.value || '') + ':' + String(option.text || '');
            }).join('|');
            if (currentValues === nextValues) {
                syncUrgencyInputs();
                return;
            }

            const selectedValue = priorityHidden ? String(priorityHidden.value || '') : String(urgencySelect.value || '');
            urgencySelect.innerHTML = '';
            desired.forEach(function(optionConfig, index) {
                const option = document.createElement('option');
                option.value = optionConfig.value;
                option.textContent = optionConfig.text;
                if (index === 0) {
                    option.disabled = true;
                    option.hidden = true;
                    option.selected = true;
                }
                urgencySelect.appendChild(option);
            });
            urgencySelect.value = selectedValue;
            if (urgencySelect.value !== selectedValue) {
                urgencySelect.value = '';
                if (priorityHidden) priorityHidden.value = '';
            }
            if (urgencyLabel) {
                urgencyLabel.innerHTML = modeKey === 'marketing'
                    ? 'Urgency Level <span class="required-asterisk">*</span>'
                    : 'Level of Urgency <span class="required-asterisk">*</span>';
            }
            syncUrgencyInputs();
        }
        function syncRequestGridRows() {
            if (recipientDepartmentRow && departmentContainer) {
                const departmentVisible = departmentContainer.style.display !== 'none';
                recipientDepartmentRow.classList.toggle('is-single', !departmentVisible);
            }
            if (categoryUrgencyRow && categoryContainer && urgencyContainer) {
                const urgencyVisible = urgencyContainer.classList.contains('is-visible');
                categoryUrgencyRow.classList.toggle('is-single', !urgencyVisible);
            }
        }
        function setInlineFormError(message) {
            if (!ajaxErrorBanner) return;
            if (!message) {
                ajaxErrorBanner.style.display = 'none';
                ajaxErrorBanner.textContent = '';
                return;
            }
            ajaxErrorBanner.textContent = message;
            ajaxErrorBanner.style.display = 'block';
            ajaxErrorBanner.setAttribute('tabindex', '-1');
            window.requestAnimationFrame(function() {
                ajaxErrorBanner.scrollIntoView({ behavior: 'smooth', block: 'center' });
                try { ajaxErrorBanner.focus({ preventScroll: true }); } catch (e) {}
            });
        }
        function formatIsoDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }
        function addWorkingDays(startDate, workingDays) {
            const next = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
            let count = 0;
            while (count < workingDays) {
                next.setDate(next.getDate() + 1);
                const day = next.getDay();
                if (day !== 0 && day !== 6) {
                    count++;
                }
            }
            return next;
        }
        function workingDaysFromToday(deadlineValue) {
            if (!deadlineValue) return -1;
            const parts = String(deadlineValue).split('-').map(function(part) { return parseInt(part, 10); });
            if (parts.length !== 3 || parts.some(function(part) { return !isFinite(part); })) return -1;
            const target = new Date(parts[0], parts[1] - 1, parts[2]);
            const today = new Date();
            const cursor = new Date(today.getFullYear(), today.getMonth(), today.getDate());
            if (target <= cursor) return 0;
            let days = 0;
            while (cursor < target) {
                cursor.setDate(cursor.getDate() + 1);
                const day = cursor.getDay();
                if (day !== 0 && day !== 6) {
                    days++;
                }
            }
            return days;
        }
        function validateProjectDeadline(showMessage) {
            if (!projectDeadlineInput) return true;
            const value = String(projectDeadlineInput.value || '');
            const minimumDate = addWorkingDays(new Date(), 3);
            const minimumIso = formatIsoDate(minimumDate);
            projectDeadlineInput.min = minimumIso;
            if (projectDeadlineHelp) {
                projectDeadlineHelp.textContent = 'Must be at least 3 working days from today. Earliest valid date is ' + minimumDate.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) + '.';
            }
            let message = '';
            if (value !== '') {
                const parts = value.split('-').map(function(part) { return parseInt(part, 10); });
                const target = parts.length === 3 ? new Date(parts[0], parts[1] - 1, parts[2]) : null;
                const day = target ? target.getDay() : -1;
                if (!target || !isFinite(target.getTime())) {
                    message = 'Please select a valid project deadline.';
                } else if (day === 0 || day === 6 || workingDaysFromToday(value) < 3) {
                    message = 'Project Deadline must be at least 3 working days from today. Earliest valid date is ' + minimumDate.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) + '.';
                }
            }
            if (projectDeadlineError) {
                projectDeadlineError.textContent = message;
                projectDeadlineError.classList.toggle('is-visible', message !== '' && showMessage !== false);
            }
            return message === '';
        }
        function syncMarketingOtherInputs() {
            const requestedOtherSelected = requestedMaterialsSelect
                ? String(requestedMaterialsSelect.value || '') === 'Other'
                : requestedMaterialsInputs.some(function(input) {
                    return input.checked && input.value === 'Other';
                });
            const cropOtherSelected = cropSelect
                ? String(cropSelect.value || '') === 'Other'
                : cropInputs.some(function(input) {
                    return input.checked && input.value === 'Other';
                });
            if (requestedMaterialsOtherRow) {
                requestedMaterialsOtherRow.classList.toggle('is-visible', requestedOtherSelected);
            }
            if (requestedMaterialsOtherInput) {
                if (requestedOtherSelected) requestedMaterialsOtherInput.setAttribute('required', 'required');
                else {
                    requestedMaterialsOtherInput.removeAttribute('required');
                    requestedMaterialsOtherInput.value = '';
                }
            }
            if (cropOtherRow) {
                cropOtherRow.classList.toggle('is-visible', cropOtherSelected);
            }
            if (cropOtherInput) {
                if (cropOtherSelected) cropOtherInput.setAttribute('required', 'required');
                else {
                    cropOtherInput.removeAttribute('required');
                    cropOtherInput.value = '';
                }
            }
        }
        function syncMaterialSizeInput() {
            if (!materialSizeInput) return;
            const selectedUnit = materialSizeUnitInputs.find(function(input) { return input.checked; });
            let selectedValue = '';
            materialSizeValueInputs.forEach(function(input) {
                const row = input.closest('.marketing-size-option');
                const rowUnit = row ? row.querySelector('input[name="material_size_unit"]') : null;
                const isSelected = !!(rowUnit && rowUnit.checked);
                input.disabled = !isSelected;
                if (isSelected) {
                    input.setAttribute('required', 'required');
                    selectedValue = String(input.value || '').trim();
                } else {
                    input.removeAttribute('required');
                    input.value = '';
                }
            });
            materialSizeInput.value = selectedUnit && selectedValue !== ''
                ? String(selectedUnit.value || '').trim() + ': ' + selectedValue
                : '';
        }
        function syncEmailCreationFields() {
            const shouldShowEmailCreation = emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
            if (emailCreationFields) {
                emailCreationFields.classList.toggle('is-visible', shouldShowEmailCreation);
            }
            applyEmailCreationInputState();
            getEmailCreationAddButtons().forEach(function(button) {
                button.disabled = !shouldShowEmailCreation;
            });
        }
        function applyEmailCreationInputState() {
            const shouldShowEmailCreation = emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
            getEmailCreationCards().forEach(function(card) {
                Array.from(card.querySelectorAll('[data-email-field]')).forEach(function(input) {
                    if (!input) return;
                    input.disabled = !shouldShowEmailCreation;
                    if (shouldShowEmailCreation && card.classList.contains('is-active')) {
                        input.setAttribute('required', 'required');
                    } else {
                        input.removeAttribute('required');
                        if (!shouldShowEmailCreation) input.value = '';
                    }
                });
            });
        }
        function getEmailCreationCards() {
            if (!emailRequestList) return [];
            return Array.from(emailRequestList.querySelectorAll('[data-email-card]'));
        }
        function getEmailCreationAddButtons() {
            if (!emailRequestList) return [];
            return Array.from(emailRequestList.querySelectorAll('[data-add-email-card]'));
        }
        function getEmailCreationCardValues(card) {
            const fieldNames = ['name', 'designation', 'subsidiary', 'department'];
            const values = {};
            fieldNames.forEach(function(fieldName) {
                const input = card ? card.querySelector('[data-email-field="' + fieldName + '"]') : null;
                values[fieldName] = input ? String(input.value || '').trim() : '';
            });
            return values;
        }
        function getEmailCreationDepartmentOptions(subsidiaryValue) {
            const normalizedValue = String(subsidiaryValue || '').trim().toLowerCase();
            if (Object.prototype.hasOwnProperty.call(emailCreationDepartmentOptionsBySubsidiary, normalizedValue)) {
                return emailCreationDepartmentOptionsBySubsidiary[normalizedValue] || [];
            }
            return normalizedValue !== '' ? ['IT'] : [];
        }
        function syncEmailCreationDepartmentSelect(card, keepExistingValue) {
            const subsidiarySelect = card ? card.querySelector('[data-email-subsidiary-select]') : null;
            const departmentSelect = card ? card.querySelector('[data-email-target-department-select]') : null;
            if (!departmentSelect) return;
            const currentValue = keepExistingValue ? String(departmentSelect.value || '').trim() : '';
            const options = getEmailCreationDepartmentOptions(subsidiarySelect ? subsidiarySelect.value : '');
            departmentSelect.innerHTML = '<option value="" disabled selected hidden>Select department</option>';
            options.forEach(function(optionValue) {
                const option = document.createElement('option');
                option.value = optionValue;
                option.textContent = optionValue;
                if (currentValue !== '' && currentValue === optionValue) {
                    option.selected = true;
                }
                departmentSelect.appendChild(option);
            });
            if (currentValue !== '' && !options.includes(currentValue)) {
                departmentSelect.value = '';
            }
            if (currentValue === '' && options.length === 1) {
                departmentSelect.value = options[0];
            }
        }
        function syncAllEmailCreationDepartmentSelects() {
            getEmailCreationCards().forEach(function(card) {
                syncEmailCreationDepartmentSelect(card, true);
            });
        }
        function getEmailCreationCardDisplayName(card, index) {
            const nameInput = card ? card.querySelector('[data-email-field="name"]') : null;
            const displayName = nameInput ? String(nameInput.value || '').trim() : '';
            return displayName !== '' ? displayName : ('Email ' + (index + 1));
        }
        let activeEmailCardIndex = 0;
        function setActiveEmailCard(index) {
            const emailCards = getEmailCreationCards();
            if (emailCards.length === 0) {
                activeEmailCardIndex = 0;
                return;
            }
            const normalizedIndex = Math.max(0, Math.min(index, emailCards.length - 1));
            activeEmailCardIndex = normalizedIndex;
            emailCards.forEach(function(card, cardIndex) {
                card.classList.toggle('is-active', cardIndex === normalizedIndex);
            });
            if (emailRequestCounter) {
                emailRequestCounter.textContent = 'Email ' + (normalizedIndex + 1) + ' of ' + emailCards.length;
            }
            if (emailEmployeeSwitcher) {
                emailEmployeeSwitcher.innerHTML = '';
                emailCards.forEach(function(card, cardIndex) {
                    const option = document.createElement('option');
                    option.value = String(cardIndex);
                    option.textContent = getEmailCreationCardDisplayName(card, cardIndex);
                    if (cardIndex === normalizedIndex) {
                        option.selected = true;
                    }
                    emailEmployeeSwitcher.appendChild(option);
                });
            }
            applyEmailCreationInputState();
        }
        function syncEmailCreationCardState() {
            const emailCards = getEmailCreationCards();
            syncAllEmailCreationDepartmentSelects();
            emailCards.forEach(function(card, index) {
                const title = card.querySelector('[data-email-card-title]');
                if (title) {
                    title.textContent = 'Email Details';
                }
                Array.from(card.querySelectorAll('[data-remove-email-card]')).forEach(function(button) {
                    button.style.display = emailCards.length > 1 ? '' : 'none';
                });
            });
            if (activeEmailCardIndex > emailCards.length - 1) {
                activeEmailCardIndex = Math.max(0, emailCards.length - 1);
            }
            setActiveEmailCard(activeEmailCardIndex);
        }
        function addEmailCreationCard() {
            if (!emailRequestList || !emailCreationTemplate) return;
            const nextIndex = Date.now();
            const templateMarkup = emailCreationTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
            emailRequestList.insertAdjacentHTML('beforeend', templateMarkup);
            syncEmailCreationCardState();
            syncEmailCreationFields();
            const emailCards = getEmailCreationCards();
            const newestCardIndex = emailCards.length - 1;
            const newestCard = emailCards[newestCardIndex] || null;
            setActiveEmailCard(newestCardIndex);
            const firstInput = newestCard ? newestCard.querySelector('[data-email-field="name"]') : null;
            if (firstInput) firstInput.focus();
        }
        function findFirstIncompleteEmailCreationInput(card) {
            const orderedFields = ['name', 'designation', 'subsidiary', 'department'];
            for (let i = 0; i < orderedFields.length; i++) {
                const input = card ? card.querySelector('[data-email-field="' + orderedFields[i] + '"]') : null;
                if (input && !input.disabled && !String(input.value || '').trim()) {
                    return input;
                }
            }
            return null;
        }
        if (emailEmployeeSwitcher) {
            emailEmployeeSwitcher.addEventListener('change', function() {
                setActiveEmailCard(parseInt(String(emailEmployeeSwitcher.value || '0'), 10) || 0);
            });
        }
        if (emailRequestList) {
            emailRequestList.addEventListener('click', function(event) {
                const target = event.target;
                if (!(target instanceof Element)) return;
                const addButton = target.closest('[data-add-email-card]');
                if (addButton) {
                    addEmailCreationCard();
                    return;
                }
                const removeButton = target.closest('[data-remove-email-card]');
                if (!removeButton) return;
                const emailCards = getEmailCreationCards();
                if (emailCards.length <= 1) return;
                const card = removeButton.closest('[data-email-card]');
                if (card) {
                    const removedIndex = emailCards.indexOf(card);
                    card.remove();
                    if (removedIndex <= activeEmailCardIndex) {
                        activeEmailCardIndex = Math.max(0, activeEmailCardIndex - 1);
                    }
                    syncEmailCreationCardState();
                    syncEmailCreationFields();
                }
            });
            emailRequestList.addEventListener('change', function(event) {
                const target = event.target;
                if (!(target instanceof Element)) return;
                if (target.matches('[data-email-subsidiary-select]')) {
                    syncEmailCreationDepartmentSelect(target.closest('[data-email-card]'), false);
                }
                if (target.matches('[data-email-field="name"]')) {
                    setActiveEmailCard(activeEmailCardIndex);
                }
            });
        }
        syncEmailCreationCardState();
        function moveAttachmentContainer(targetHost) {
            if (!attachmentContainer || !targetHost) return;
            if (attachmentContainer.parentNode !== targetHost) {
                targetHost.appendChild(attachmentContainer);
            }
        }
        function moveDescriptionContainer(targetHost) {
            if (!descriptionContainer || !targetHost) return;
            if (descriptionContainer.parentNode !== targetHost) {
                targetHost.appendChild(descriptionContainer);
            }
        }
        function syncAttachmentCopy(mode) {
            const modeKey = String(mode || 'default');
            if (attachmentLabelText) {
                attachmentLabelText.textContent = modeKey === 'marketing'
                    ? 'Attach File'
                    : ((modeKey === 'kami' || modeKey === 'medical') ? 'Supporting Information' : 'Attachment');
            }
            if (attachmentHelpText) {
                attachmentHelpText.textContent = modeKey === 'marketing'
                    ? 'Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)'
                    : (modeKey === 'kami'
                    ? 'Upload up to 5 supported files. Max 10 MB per file.'
                    : (modeKey === 'medical'
                        ? 'Please upload any medical document relevant to your request. Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).'
                        : 'Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)'));
            }
            if (medicalCashAttachmentIntro) {
                medicalCashAttachmentIntro.style.display = 'none';
            }
            if (chooseFileBtnText) {
                chooseFileBtnText.textContent = (modeKey === 'kami' || modeKey === 'medical' || modeKey === 'marketing') ? 'Add file' : 'Choose File';
            }
        }
        function setSssUploadError(config, message) {
            const errorEl = document.getElementById(config.errorId);
            if (!errorEl) return;
            if (!message) {
                errorEl.textContent = '';
                errorEl.classList.remove('is-visible');
                return;
            }
            errorEl.textContent = message;
            errorEl.classList.add('is-visible');
        }
        function updateSssUploadSummary(config) {
            const label = document.getElementById(config.labelId);
            const list = document.getElementById(config.listId);
            const files = Array.from((sssUploadState[config.inputId] && sssUploadState[config.inputId].files) || []);

            if (label) {
                label.textContent = files.length === 0
                    ? 'No file chosen'
                    : (files.length === 1 ? '1 file selected' : files.length + ' files selected');
            }

            if (!list) return;
            list.innerHTML = '';
            if (files.length === 0) {
                return;
            }

            files.forEach(function(file, index) {
                const chip = document.createElement('div');
                chip.className = 'sss-benefits-file-chip';

                const name = document.createElement('button');
                name.type = 'button';
                name.className = 'sss-benefits-file-chip-name sss-benefits-file-chip-link';
                name.textContent = file.name || ('File ' + (index + 1));
                name.setAttribute('title', 'Open ' + (file.name || ('file ' + (index + 1))));
                name.addEventListener('click', function() {
                    openSssUploadPreview(file);
                });

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'sss-benefits-file-chip-remove';
                removeBtn.textContent = 'x';
                removeBtn.setAttribute('aria-label', 'Remove ' + (file.name || ('file ' + (index + 1))));
                removeBtn.addEventListener('click', function() {
                    removeSssUploadFile(config, index);
                });

                chip.appendChild(name);
                chip.appendChild(removeBtn);
                list.appendChild(chip);
            });
        }
        function syncSssInputFiles(config) {
            const input = document.getElementById(config.inputId);
            if (!input) return;
            const state = sssUploadState[config.inputId] || { files: [] };
            const dtLocal = new DataTransfer();
            state.files.forEach(function(file) {
                dtLocal.items.add(file);
            });
            input.files = dtLocal.files;
            updateSssUploadSummary(config);
        }
        function removeSssUploadFile(config, index) {
            const state = sssUploadState[config.inputId];
            if (!state) return;
            state.files.splice(index, 1);
            setSssUploadError(config, '');
            syncSssInputFiles(config);
        }
        function openSssUploadPreview(file) {
            if (!file) return;
            const previewUrl = URL.createObjectURL(file);
            openInlineAttachmentPreview(file, previewUrl, true);
        }
        function mergeSssUploadFiles(config, incomingFiles) {
            const state = sssUploadState[config.inputId] || { files: [] };
            const nextFiles = state.files.slice();
            const selectedFiles = Array.from(incomingFiles || []);

            if (nextFiles.length + selectedFiles.length > config.maxFiles) {
                const message = config.maxFiles === 1
                    ? 'Only 1 file is allowed for ' + config.label + '.'
                    : 'You can upload up to ' + config.maxFiles + ' files for ' + config.label + '.';
                setSssUploadError(config, message);
                setInlineFormError('');
                syncSssInputFiles(config);
                return false;
            }

            selectedFiles.forEach(function(file) {
                nextFiles.push(file);
            });

            sssUploadState[config.inputId] = { files: nextFiles };
            setSssUploadError(config, '');
            syncSssInputFiles(config);
            return true;
        }
        function resetSssUploads() {
            sssUploadConfigs.forEach(function(config) {
                sssUploadState[config.inputId] = { files: [] };
                setSssUploadError(config, '');
                syncSssInputFiles(config);
            });
        }
        function getSapCards() {
            if (!sapRequestList) return [];
            return Array.from(sapRequestList.querySelectorAll('[data-sap-card]'));
        }
        function getSapCardValues(card) {
            const fieldNames = ['name', 'position', 'address', 'department', 'tin'];
            const values = {};
            fieldNames.forEach(function(fieldName) {
                const input = card ? card.querySelector('[data-sap-field="' + fieldName + '"]') : null;
                values[fieldName] = input ? String(input.value || '').trim() : '';
            });
            return values;
        }
        function getSapCardDisplayName(card, index) {
            const nameInput = card ? card.querySelector('[data-sap-field="name"]') : null;
            const displayName = nameInput ? String(nameInput.value || '').trim() : '';
            return displayName !== '' ? displayName : ('Employee ' + (index + 1));
        }
        let activeSapCardIndex = 0;
        function setActiveSapCard(index) {
            const sapCards = getSapCards();
            if (sapCards.length === 0) {
                activeSapCardIndex = 0;
                return;
            }
            const normalizedIndex = Math.max(0, Math.min(index, sapCards.length - 1));
            activeSapCardIndex = normalizedIndex;
            sapCards.forEach(function(card, cardIndex) {
                card.classList.toggle('is-active', cardIndex === normalizedIndex);
            });
            if (sapRequestCounter) {
                sapRequestCounter.textContent = 'Employee ' + (normalizedIndex + 1) + ' of ' + sapCards.length;
            }
            if (sapEmployeeSwitcher) {
                sapEmployeeSwitcher.innerHTML = '';
                sapCards.forEach(function(card, cardIndex) {
                    const option = document.createElement('option');
                    option.value = String(cardIndex);
                    option.textContent = getSapCardDisplayName(card, cardIndex);
                    if (cardIndex === normalizedIndex) {
                        option.selected = true;
                    }
                    sapEmployeeSwitcher.appendChild(option);
                });
            }
        }
        function syncSapCardState() {
            const sapCards = getSapCards();
            sapCards.forEach(function(card, index) {
                const title = card.querySelector('[data-sap-report-title]');
                if (title) {
                    title.textContent = 'Employee Details';
                }
                const removeButtons = Array.from(card.querySelectorAll('[data-remove-sap-report]'));
                removeButtons.forEach(function(button) {
                    button.style.display = sapCards.length > 1 ? '' : 'none';
                });
            });
            if (activeSapCardIndex > sapCards.length - 1) {
                activeSapCardIndex = Math.max(0, sapCards.length - 1);
            }
            setActiveSapCard(activeSapCardIndex);
        }
        function addSapCard() {
            if (!sapRequestList || !sapRequestTemplate) return;
            const nextIndex = Date.now();
            const templateMarkup = sapRequestTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
            sapRequestList.insertAdjacentHTML('beforeend', templateMarkup);
            syncSapCardState();
            const sapCards = getSapCards();
            const newestCardIndex = sapCards.length - 1;
            const newestCard = sapCards[newestCardIndex] || null;
            setActiveSapCard(newestCardIndex);
            const firstInput = newestCard ? newestCard.querySelector('[data-sap-field="name"]') : null;
            if (firstInput) {
                firstInput.focus();
            }
        }
        function findFirstEmptySapInput(card) {
            const orderedFields = ['name'];
            for (let i = 0; i < orderedFields.length; i++) {
                const input = card ? card.querySelector('[data-sap-field="' + orderedFields[i] + '"]') : null;
                if (input && !input.disabled && !String(input.value || '').trim()) {
                    return input;
                }
            }
            return null;
        }
        if (sapAddEmployeeBtn) {
            sapAddEmployeeBtn.addEventListener('click', function() {
                addSapCard();
            });
        }
        if (sapEmployeeSwitcher) {
            sapEmployeeSwitcher.addEventListener('change', function() {
                setActiveSapCard(parseInt(String(sapEmployeeSwitcher.value || '0'), 10) || 0);
            });
        }
        if (sapRequestList) {
            sapRequestList.addEventListener('click', function(event) {
                const target = event.target;
                if (!(target instanceof Element)) return;
                const removeButton = target.closest('[data-remove-sap-report]');
                if (!removeButton) return;
                const sapCards = getSapCards();
                if (sapCards.length <= 1) return;
                const card = removeButton.closest('[data-sap-card]');
                if (card) {
                    const removedIndex = sapCards.indexOf(card);
                    card.remove();
                    if (removedIndex <= activeSapCardIndex) {
                        activeSapCardIndex = Math.max(0, activeSapCardIndex - 1);
                    }
                    syncSapCardState();
                }
            });
            sapRequestList.addEventListener('change', function(event) {
                const target = event.target;
                if (!(target instanceof Element)) return;
                if (target.matches('[data-sap-field="name"]')) {
                    setActiveSapCard(activeSapCardIndex);
                }
            });
        }
        syncSapCardState();
        function toggleHrExtraFields() {
            if (!urgencyContainer || !priorityHidden) return;
            const shouldShow = isLapcHrSelection();
            const selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
            const isMhcMarketingDepartment = isMhcMarketingSelection();
            const shouldShowMarketingRequest = isMhcMarketingDepartment && selectedCategory === 'Marketing Request';
            const shouldShowUrgency = true;
            const shouldShowConcernType = shouldShow && selectedCategory === 'Attendance & Timekeeping';
            const shouldShowConcernTypeOther = shouldShowConcernType && concernTypeSelect && String(concernTypeSelect.value || '') === 'Other';
            const shouldShowLeaveSubject = shouldShow && (selectedCategory === 'Leave Concern' || selectedCategory === 'Others');
            const shouldShowOtherDetailsStyle = shouldShow && (selectedCategory === 'Leave Concern' || selectedCategory === 'Others');
            const shouldShowSssBenefits = shouldShow && selectedCategory === 'SSS Sickness and Benefit Concern';
            const shouldShowMedicalCashAdvance = shouldShow && selectedCategory === 'Medical Cash Advance';
            const shouldShowTrainingRequest = shouldShow && selectedCategory === 'Training Request';
            const shouldShowCompanyPropertyRequest = shouldShow && selectedCategory === 'Request for Company Property';
            const shouldShowCoeRequest = shouldShow && selectedCategory === 'Certificate of Employment';
            const shouldShowColRequest = shouldShow && selectedCategory === 'Certificate of Leave';
            const shouldShowIncidentReport = shouldShow && selectedCategory === 'Incident Report';
            const shouldShowEmailRequest = isLapcItSelection() && selectedCategory === 'Email';
            const shouldShowEmailCreation = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
            const shouldShowEmailDefault = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === '';
            const shouldShowEmailForgotPassword = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'forgot password';
            const shouldShowEmailBackup = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'backup of email';
            const shouldShowSapRequest = isLapcItSelection() && selectedCategory === 'SAP';
            const shouldRequireKamiAttachment = shouldShowConcernType;
            const shouldRequireMedicalAttachment = shouldShowMedicalCashAdvance;
            const shouldRequireIncidentAttachment = shouldShowIncidentReport;
            setUrgencyOptions('hr');
            document.body.classList.toggle('kami-section-active', shouldShowConcernType);
            document.body.classList.toggle('other-section-active', shouldShowOtherDetailsStyle);
            document.body.classList.toggle('medical-cash-section-active', shouldShowMedicalCashAdvance);
            document.body.classList.toggle('training-request-section-active', shouldShowTrainingRequest);
            document.body.classList.toggle('company-property-section-active', shouldShowCompanyPropertyRequest);
            document.body.classList.toggle('coe-request-section-active', shouldShowCoeRequest);
            document.body.classList.toggle('col-request-section-active', shouldShowColRequest);
            document.body.classList.toggle('incident-report-section-active', shouldShowIncidentReport);
            document.body.classList.toggle('sap-request-section-active', shouldShowSapRequest);
            document.body.classList.toggle('email-request-section-active', shouldShowEmailRequest);
            document.body.classList.toggle('marketing-request-section-active', shouldShowMarketingRequest);
            if (kamiBannerContainer) {
                kamiBannerContainer.classList.toggle('is-visible', shouldShowConcernType);
            }
            if (medicalCashAdvanceSection) {
                medicalCashAdvanceSection.classList.toggle('is-visible', shouldShowMedicalCashAdvance);
            }
            if (trainingRequestSection) {
                trainingRequestSection.classList.toggle('is-visible', shouldShowTrainingRequest);
            }
            if (companyPropertySection) {
                companyPropertySection.classList.toggle('is-visible', shouldShowCompanyPropertyRequest);
            }
            if (coeRequestSection) {
                coeRequestSection.classList.toggle('is-visible', shouldShowCoeRequest);
            }
            if (colRequestSection) {
                colRequestSection.classList.toggle('is-visible', shouldShowColRequest);
            }
            if (incidentReportSection) {
                incidentReportSection.classList.toggle('is-visible', shouldShowIncidentReport);
            }
            const shouldShowCertificateLeavePurposeOther = shouldShowColRequest && certificateLeavePurposeSelect && String(certificateLeavePurposeSelect.value || '') === 'Others';
            if (emailRequestSection) {
                emailRequestSection.classList.toggle('is-visible', shouldShowEmailRequest);
            }
            if (sapRequestSection) {
                sapRequestSection.classList.toggle('is-visible', shouldShowSapRequest);
            }
            if (marketingRequestSection) {
                marketingRequestSection.classList.toggle('is-visible', shouldShowMarketingRequest);
            }
            if (concernTypeContainer) {
                concernTypeContainer.classList.toggle('is-visible', shouldShowConcernType);
            }
            if (concernTypeOtherContainer) {
                concernTypeOtherContainer.classList.toggle('is-visible', shouldShowConcernTypeOther);
            }
            if (leaveSubjectContainer) {
                leaveSubjectContainer.classList.toggle('is-visible', shouldShowLeaveSubject);
            }
            if (otherRequestDetailsSection) {
                otherRequestDetailsSection.style.display = shouldShowLeaveSubject ? '' : 'none';
            }
            if (otherDescriptionSection) {
                otherDescriptionSection.style.display = shouldShowSssBenefits ? 'none' : '';
            }
            if (shouldShowMarketingRequest && marketingDescriptionHost) {
                moveDescriptionContainer(marketingDescriptionHost);
            } else if ((shouldShowEmailDefault || shouldShowEmailForgotPassword || shouldShowEmailBackup) && emailDescriptionHost) {
                moveDescriptionContainer(emailDescriptionHost);
            } else if (descriptionOriginalHost) {
                moveDescriptionContainer(descriptionOriginalHost);
            }
            if (requestSubjectLabel) {
                requestSubjectLabel.innerHTML = 'Subject/Title of Request <span class="required-asterisk">*</span>';
            }
            if (descriptionLabel) {
                descriptionLabel.innerHTML = shouldShowMarketingRequest
                    ? 'Brief Description of Request <span class="required-asterisk">*</span>'
                    : (shouldShowOtherDetailsStyle
                        ? 'Detailed Description of Request or Concern <span class="required-asterisk">*</span>'
                        : 'Description <span class="required-asterisk">*</span>');
            }
            if (sssBenefitsContainer) {
                sssBenefitsContainer.classList.toggle('is-visible', shouldShowSssBenefits);
            }
            sssUploadConfigs.forEach(function(config) {
                const input = document.getElementById(config.inputId);
                if (!input) return;
                input.disabled = !shouldShowSssBenefits;
                if (!shouldShowSssBenefits) {
                    setSssUploadError(config, '');
                }
            });
            if (descriptionContainer) {
                descriptionContainer.style.display = (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowIncidentReport || shouldShowSapRequest || shouldShowEmailCreation) ? 'none' : '';
            }
            if (attachmentContainer) {
                attachmentContainer.style.display = (shouldShowSssBenefits || shouldShowSapRequest || shouldShowEmailRequest || shouldShowMarketingRequest) ? 'none' : '';
            }
            const attachmentFieldInput = document.getElementById('attachments');
            const attachmentFieldButton = document.getElementById('choose-file-btn');
            if (attachmentFieldInput) {
                attachmentFieldInput.disabled = shouldShowSssBenefits || shouldShowEmailRequest || shouldShowMarketingRequest;
            }
            if (attachmentFieldButton) {
                attachmentFieldButton.setAttribute('aria-disabled', (shouldShowSssBenefits || shouldShowEmailRequest || shouldShowMarketingRequest) ? 'true' : 'false');
                attachmentFieldButton.tabIndex = (shouldShowSssBenefits || shouldShowEmailRequest || shouldShowMarketingRequest) ? -1 : 0;
            }
            if (attachmentOptionalText) {
                attachmentOptionalText.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment || shouldRequireIncidentAttachment) ? 'none' : '';
            }
            if (attachmentRequiredAsterisk) {
                attachmentRequiredAsterisk.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment || shouldRequireIncidentAttachment) ? '' : 'none';
            }
            syncAttachmentCopy(shouldShowMarketingRequest ? 'marketing' : (shouldShowMedicalCashAdvance ? 'medical' : (shouldRequireKamiAttachment ? 'kami' : 'default')));
            urgencyContainer.classList.toggle('is-visible', shouldShowUrgency);
            if (categoryUrgencyRow) {
                categoryUrgencyRow.classList.toggle('is-single', !shouldShowUrgency);
            }
            if (concernTypeSelect) {
                if (shouldShowConcernType) {
                    concernTypeSelect.setAttribute('required', 'required');
                } else {
                    concernTypeSelect.removeAttribute('required');
                }
            }
            if (concernTypeOtherInput) {
                if (shouldShowConcernTypeOther) {
                    concernTypeOtherInput.setAttribute('required', 'required');
                } else {
                    concernTypeOtherInput.removeAttribute('required');
                    concernTypeOtherInput.value = '';
                }
            }
            if (leaveSubjectInput) {
                if (shouldShowLeaveSubject) {
                    leaveSubjectInput.setAttribute('required', 'required');
                } else {
                    leaveSubjectInput.removeAttribute('required');
                }
            }
            if (urgencySelect) {
                if (shouldShowUrgency) {
                    urgencySelect.setAttribute('required', 'required');
                } else {
                    urgencySelect.removeAttribute('required');
                    closeUrgencyDropdown();
                }
            }
            if (descriptionField) {
                if (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowIncidentReport || shouldShowSapRequest || shouldShowEmailCreation) {
                    descriptionField.removeAttribute('required');
                    if (shouldShowSssBenefits && descriptionField.value.trim() === '') {
                        descriptionField.value = sssAutoDescription;
                        descriptionField.setAttribute('data-auto-filled', 'true');
                    }
                } else {
                    descriptionField.setAttribute('required', 'required');
                    if (descriptionField.getAttribute('data-auto-filled') === 'true' && descriptionField.value === sssAutoDescription) {
                        descriptionField.value = '';
                    }
                    descriptionField.removeAttribute('data-auto-filled');
                }
            }

            if (!shouldShowConcernType && concernTypeSelect) {
                concernTypeSelect.value = '';
            }
            if (!shouldShowLeaveSubject && leaveSubjectInput) {
                leaveSubjectInput.value = '';
            }
            if (medicalCashPurposeInput) {
                if (shouldShowMedicalCashAdvance) medicalCashPurposeInput.setAttribute('required', 'required');
                else medicalCashPurposeInput.removeAttribute('required');
            }
            if (medicalCashAmountInput) {
                if (shouldShowMedicalCashAdvance) medicalCashAmountInput.setAttribute('required', 'required');
                else medicalCashAmountInput.removeAttribute('required');
            }
            if (medicalCashDateNeededInput) {
                if (shouldShowMedicalCashAdvance) medicalCashDateNeededInput.setAttribute('required', 'required');
                else medicalCashDateNeededInput.removeAttribute('required');
            }
            [trainingRequestTitleInput, trainingRequestProviderInput, trainingRequestStartDateInput, trainingRequestEndDateInput, trainingRequestVenueInput, trainingRequestFeeInput].forEach(function(input) {
                if (!input) return;
                if (shouldShowTrainingRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            companyPropertyTypeInputs.forEach(function(input) {
                if (shouldShowCompanyPropertyRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            companyPropertyReasonInputs.forEach(function(input) {
                if (shouldShowCompanyPropertyRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            coeRequestReasonInputs.forEach(function(input) {
                if (shouldShowCoeRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            coeSalaryDetailsInputs.forEach(function(input) {
                if (shouldShowCoeRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            coeDeliveryMethodInputs.forEach(function(input) {
                if (shouldShowCoeRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            if (coePreferredReleaseDateInput) {
                if (shouldShowCoeRequest) coePreferredReleaseDateInput.setAttribute('required', 'required');
                else coePreferredReleaseDateInput.removeAttribute('required');
            }
            if (coeRemarksInput) {
                if (shouldShowCoeRequest) coeRemarksInput.setAttribute('required', 'required');
                else coeRemarksInput.removeAttribute('required');
            }
            if (certificateLeaveDateInput) {
                if (shouldShowColRequest) certificateLeaveDateInput.setAttribute('required', 'required');
                else certificateLeaveDateInput.removeAttribute('required');
            }
            if (certificateLeavePurposeSelect) {
                if (shouldShowColRequest) certificateLeavePurposeSelect.setAttribute('required', 'required');
                else certificateLeavePurposeSelect.removeAttribute('required');
            }
            if (certificateLeavePurposeOtherContainer) {
                certificateLeavePurposeOtherContainer.classList.toggle('is-visible', shouldShowCertificateLeavePurposeOther);
            }
            if (certificateLeavePurposeOtherInput) {
                if (shouldShowCertificateLeavePurposeOther) {
                    certificateLeavePurposeOtherInput.setAttribute('required', 'required');
                } else {
                    certificateLeavePurposeOtherInput.removeAttribute('required');
                    certificateLeavePurposeOtherInput.value = '';
                }
            }
            [incidentSummaryInput].forEach(function(input) {
                if (!input) return;
                if (shouldShowIncidentReport) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            if (incidentGdriveLinkInput) {
                incidentGdriveLinkInput.disabled = !shouldShowIncidentReport;
                incidentGdriveLinkInput.removeAttribute('required');
                if (!shouldShowIncidentReport) {
                    incidentGdriveLinkInput.value = '';
                }
            }
            if (emailRequestTypeSelect) {
                if (shouldShowEmailRequest) {
                    emailRequestTypeSelect.setAttribute('required', 'required');
                    emailRequestTypeSelect.disabled = false;
                } else {
                    emailRequestTypeSelect.removeAttribute('required');
                    emailRequestTypeSelect.disabled = true;
                    emailRequestTypeSelect.value = '';
                }
            }
            syncEmailCreationFields();
            if (sapRequestList) {
                Array.from(sapRequestList.querySelectorAll('[data-sap-field]')).forEach(function(input) {
                    if (!input) return;
                    if (shouldShowSapRequest && String(input.getAttribute('data-sap-field') || '') === 'name') input.setAttribute('required', 'required');
                    else input.removeAttribute('required');
                });
            }
            if (coeRequestReasonOtherInput) {
                const otherSelected = coeRequestReasonInputs.some(function(input) {
                    return input.checked && input.value === 'Other';
                });
                if (shouldShowCoeRequest && otherSelected) coeRequestReasonOtherInput.setAttribute('required', 'required');
                else coeRequestReasonOtherInput.removeAttribute('required');
            }
            [projectNameInput, areaCodeSelect, marketingDepartmentSelect, materialSizeInput, projectDeadlineInput].forEach(function(input) {
                if (!input) return;
                if (shouldShowMarketingRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            materialSizeUnitInputs.forEach(function(input) {
                input.disabled = !shouldShowMarketingRequest;
            });
            materialSizeValueInputs.forEach(function(input) {
                if (!shouldShowMarketingRequest) {
                    input.disabled = true;
                    input.removeAttribute('required');
                }
            });
            requestedMaterialsInputs.forEach(function(input) {
                input.disabled = !shouldShowMarketingRequest;
            });
            cropInputs.forEach(function(input) {
                input.disabled = !shouldShowMarketingRequest;
            });
            if (projectDeadlineInput) {
                projectDeadlineInput.disabled = !shouldShowMarketingRequest;
                validateProjectDeadline(false);
            }
            syncMarketingOtherInputs();
            syncMaterialSizeInput();
            if (!shouldShowMarketingRequest) {
                if (requestedMaterialsOtherInput) requestedMaterialsOtherInput.removeAttribute('required');
                if (cropOtherInput) cropOtherInput.removeAttribute('required');
            }


            if (!shouldShowUrgency) {
                priorityHidden.value = '';
            }

            if (shouldShowIncidentReport && incidentReportAttachmentHost) {
                moveAttachmentContainer(incidentReportAttachmentHost);
            } else if (shouldShowMedicalCashAdvance && medicalCashAttachmentHost) {
                moveAttachmentContainer(medicalCashAttachmentHost);
            } else if (attachmentOriginalHost) {
                moveAttachmentContainer(attachmentOriginalHost);
            }

            syncUrgencyInputs();
            toggleSupplyChainOptionalSections(!!(supplyChainDetailsRow && supplyChainDetailsRow.classList.contains('is-visible')));
            syncRequestGridRows();
        }
        if (recipientDropdown) {
            recipientDropdown.addEventListener('change', function() {
                recipientDropdown.setAttribute('data-selected', String(recipientDropdown.value || ''));
                syncRecipientTriggerLabel();
                renderRecipientDropdownOptions();
                toggleDepartment();
                toggleCategories();
                toggleMarketingSubcategory();
                toggleHrExtraFields();
            });
        }
        if (recipientTrigger && recipientMenu && recipientWrapper) {
            recipientTrigger.addEventListener('click', function() {
                if (recipientTrigger.disabled) return;
                const shouldOpen = recipientMenu.hidden;
                closeRecipientDropdown();
                if (!shouldOpen) return;
                closeDepartmentDropdown();
                closeCategoryDropdown();
                closeAdminLegalRequestForDropdown();
                recipientWrapper.classList.add('is-open');
                recipientTrigger.setAttribute('aria-expanded', 'true');
                recipientMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!recipientWrapper.contains(event.target)) {
                    closeRecipientDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeRecipientDropdown();
                }
            });
            renderRecipientDropdownOptions();
        }
        if (departmentSelect) {
            departmentSelect.addEventListener('change', function() {
                departmentSelect.setAttribute('data-selected', String(departmentSelect.value || ''));
                syncDepartmentTriggerLabel();
                renderDepartmentDropdownOptions();
                toggleCategories();
                toggleMarketingSubcategory();
                toggleHrExtraFields();
            });
        }
        if (departmentTrigger && departmentMenu && departmentWrapper) {
            departmentTrigger.addEventListener('click', function() {
                if (departmentTrigger.disabled) return;
                const shouldOpen = departmentMenu.hidden;
                closeDepartmentDropdown();
                if (!shouldOpen) return;
                closeRecipientDropdown();
                closeCategoryDropdown();
                closeAdminLegalRequestForDropdown();
                closeMarketingSubcategoryDropdown();
                departmentWrapper.classList.add('is-open');
                departmentTrigger.setAttribute('aria-expanded', 'true');
                departmentMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!departmentWrapper.contains(event.target)) {
                    closeDepartmentDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeDepartmentDropdown();
                }
            });
        }
        if (areaCodeTrigger && areaCodeMenu && areaCodeWrapper) {
            areaCodeTrigger.addEventListener('click', function() {
                if (areaCodeTrigger.disabled) return;
                const shouldOpen = areaCodeMenu.hidden;
                closeAreaCodeDropdown();
                if (!shouldOpen) return;
                areaCodeWrapper.classList.add('is-open');
                areaCodeTrigger.setAttribute('aria-expanded', 'true');
                areaCodeMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!areaCodeWrapper.contains(event.target)) {
                    closeAreaCodeDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeAreaCodeDropdown();
                }
            });
        }
        if (areaCodeSelect) {
            areaCodeSelect.addEventListener('change', function() {
                areaCodeSelect.setAttribute('data-selected', String(areaCodeSelect.value || ''));
                syncAreaCodeTriggerLabel();
                renderAreaCodeDropdownOptions();
            });
            renderAreaCodeDropdownOptions();
        }
        if (marketingDepartmentTrigger && marketingDepartmentMenu && marketingDepartmentWrapper) {
            marketingDepartmentTrigger.addEventListener('click', function() {
                if (marketingDepartmentTrigger.disabled) return;
                const shouldOpen = marketingDepartmentMenu.hidden;
                closeMarketingDepartmentDropdown();
                if (!shouldOpen) return;
                marketingDepartmentWrapper.classList.add('is-open');
                marketingDepartmentTrigger.setAttribute('aria-expanded', 'true');
                marketingDepartmentMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!marketingDepartmentWrapper.contains(event.target)) {
                    closeMarketingDepartmentDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeMarketingDepartmentDropdown();
                }
            });
        }
        if (marketingDepartmentSelect) {
            marketingDepartmentSelect.addEventListener('change', function() {
                marketingDepartmentSelect.setAttribute('data-selected', String(marketingDepartmentSelect.value || ''));
                syncMarketingDepartmentTriggerLabel();
                renderMarketingDepartmentDropdownOptions();
            });
            renderMarketingDepartmentDropdownOptions();
        }
        if (requestedMaterialsTrigger && requestedMaterialsMenu && requestedMaterialsWrapper) {
            requestedMaterialsTrigger.addEventListener('click', function() {
                if (requestedMaterialsTrigger.disabled) return;
                const shouldOpen = requestedMaterialsMenu.hidden;
                closeRequestedMaterialsDropdown();
                if (!shouldOpen) return;
                requestedMaterialsWrapper.classList.add('is-open');
                requestedMaterialsTrigger.setAttribute('aria-expanded', 'true');
                requestedMaterialsMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!requestedMaterialsWrapper.contains(event.target)) {
                    closeRequestedMaterialsDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeRequestedMaterialsDropdown();
                }
            });
        }
        if (requestedMaterialsSelect) {
            requestedMaterialsSelect.addEventListener('change', function() {
                syncRequestedMaterialsTriggerLabel();
                renderRequestedMaterialsDropdownOptions();
            });
            renderRequestedMaterialsDropdownOptions();
        }
        if (cropTrigger && cropMenu && cropWrapper) {
            cropTrigger.addEventListener('click', function() {
                if (cropTrigger.disabled) return;
                const shouldOpen = cropMenu.hidden;
                closeCropDropdown();
                if (!shouldOpen) return;
                cropWrapper.classList.add('is-open');
                cropTrigger.setAttribute('aria-expanded', 'true');
                cropMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!cropWrapper.contains(event.target)) {
                    closeCropDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeCropDropdown();
                }
            });
        }
        if (cropSelect) {
            cropSelect.addEventListener('change', function() {
                syncCropTriggerLabel();
                renderCropDropdownOptions();
            });
            renderCropDropdownOptions();
        }
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                categorySelect.setAttribute('data-selected', String(categorySelect.value || ''));
                syncCategoryTriggerLabel();
                renderCategoryDropdownOptions();
                toggleMarketingSubcategory();
                toggleHrExtraFields();
            });
        }
        if (adminLegalRequestForSelect) {
            adminLegalRequestForSelect.addEventListener('change', function() {
                adminLegalRequestForSelect.setAttribute('data-selected', String(adminLegalRequestForSelect.value || ''));
                syncAdminLegalRequestForTriggerLabel();
                renderAdminLegalRequestForDropdownOptions();
                if (categorySelect) {
                    categorySelect.value = '';
                    categorySelect.setAttribute('data-selected', '');
                }
                toggleCategories();
                toggleMarketingSubcategory();
                toggleHrExtraFields();
            });
        }
        if (adminLegalRequestForTrigger && adminLegalRequestForMenu && adminLegalRequestForWrapper) {
            adminLegalRequestForTrigger.addEventListener('click', function() {
                if (adminLegalRequestForTrigger.disabled) return;
                const shouldOpen = adminLegalRequestForMenu.hidden;
                closeAdminLegalRequestForDropdown();
                if (!shouldOpen) return;
                closeRecipientDropdown();
                closeDepartmentDropdown();
                closeCategoryDropdown();
                closeMarketingSubcategoryDropdown();
                closeUrgencyDropdown();
                adminLegalRequestForWrapper.classList.add('is-open');
                adminLegalRequestForTrigger.setAttribute('aria-expanded', 'true');
                adminLegalRequestForMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!adminLegalRequestForWrapper.contains(event.target)) {
                    closeAdminLegalRequestForDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeAdminLegalRequestForDropdown();
                }
            });
            renderAdminLegalRequestForDropdownOptions();
        }
        if (categoryTrigger && categoryMenu && categoryWrapper) {
            categoryTrigger.addEventListener('click', function() {
                if (categoryTrigger.disabled) return;
                const shouldOpen = categoryMenu.hidden;
                closeCategoryDropdown();
                if (!shouldOpen) return;
                closeRecipientDropdown();
                closeDepartmentDropdown();
                closeAdminLegalRequestForDropdown();
                closeMarketingSubcategoryDropdown();
                categoryWrapper.classList.add('is-open');
                categoryTrigger.setAttribute('aria-expanded', 'true');
                categoryMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!categoryWrapper.contains(event.target)) {
                    closeCategoryDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeCategoryDropdown();
                }
            });
            renderCategoryDropdownOptions();
        }
        if (marketingSubcategorySelect) {
            marketingSubcategorySelect.addEventListener('change', function() {
                marketingSubcategorySelect.setAttribute('data-selected', String(marketingSubcategorySelect.value || ''));
                syncMarketingSubcategoryTriggerLabel();
                renderMarketingSubcategoryDropdownOptions();
                toggleSupplyChainDetails();
            });
        }
        if (marketingSubcategoryTrigger && marketingSubcategoryMenu && marketingSubcategoryWrapper) {
            marketingSubcategoryTrigger.addEventListener('click', function() {
                if (marketingSubcategoryTrigger.disabled) return;
                const shouldOpen = marketingSubcategoryMenu.hidden;
                closeMarketingSubcategoryDropdown();
                if (!shouldOpen) return;
                closeRecipientDropdown();
                closeDepartmentDropdown();
                closeCategoryDropdown();
                closeAdminLegalRequestForDropdown();
                closeUrgencyDropdown();
                marketingSubcategoryWrapper.classList.add('is-open');
                marketingSubcategoryTrigger.setAttribute('aria-expanded', 'true');
                marketingSubcategoryMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!marketingSubcategoryWrapper.contains(event.target)) {
                    closeMarketingSubcategoryDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeMarketingSubcategoryDropdown();
                }
            });
            renderMarketingSubcategoryDropdownOptions();
        }
        if (emailRequestTypeSelect) {
            emailRequestTypeSelect.addEventListener('change', function() {
                emailRequestTypeSelect.setAttribute('data-selected', String(emailRequestTypeSelect.value || ''));
                syncEmailRequestTypeTriggerLabel();
                renderEmailRequestTypeDropdownOptions();
            });
        }
        if (emailRequestTypeTrigger && emailRequestTypeMenu && emailRequestTypeWrapper) {
            emailRequestTypeTrigger.addEventListener('click', function() {
                if (emailRequestTypeTrigger.disabled) return;
                const shouldOpen = emailRequestTypeMenu.hidden;
                closeEmailRequestTypeDropdown();
                if (!shouldOpen) return;
                closeRecipientDropdown();
                closeDepartmentDropdown();
                closeCategoryDropdown();
                closeAdminLegalRequestForDropdown();
                closeMarketingSubcategoryDropdown();
                closeUrgencyDropdown();
                emailRequestTypeWrapper.classList.add('is-open');
                emailRequestTypeTrigger.setAttribute('aria-expanded', 'true');
                emailRequestTypeMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!emailRequestTypeWrapper.contains(event.target)) {
                    closeEmailRequestTypeDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeEmailRequestTypeDropdown();
                }
            });
            renderEmailRequestTypeDropdownOptions();
        }
        if (urgencySelect) {
            urgencySelect.addEventListener('change', function() {
                if (!priorityHidden) return;
                priorityHidden.value = String(urgencySelect.value || '');
                syncUrgencyInputs();
            });
            renderUrgencyDropdownOptions();
        }
        if (urgencyTrigger && urgencyMenu && urgencyWrapper) {
            urgencyTrigger.addEventListener('click', function() {
                if (urgencyTrigger.disabled) return;
                const shouldOpen = urgencyMenu.hidden;
                closeUrgencyDropdown();
                if (!shouldOpen) return;
                closeAdminLegalRequestForDropdown();
                closeMarketingSubcategoryDropdown();
                urgencyWrapper.classList.add('is-open');
                urgencyTrigger.setAttribute('aria-expanded', 'true');
                urgencyMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!urgencyWrapper.contains(event.target)) {
                    closeUrgencyDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeUrgencyDropdown();
                }
            });
        }
        if (concernTypeSelect) {
            const selectedConcernType = String(concernTypeSelect.getAttribute('data-selected') || '');
            if (selectedConcernType !== '') {
                concernTypeSelect.value = selectedConcernType;
            }
            concernTypeSelect.addEventListener('change', function() {
                concernTypeSelect.setAttribute('data-selected', String(concernTypeSelect.value || ''));
                syncConcernTypeTriggerLabel();
                renderConcernTypeDropdownOptions();
                toggleHrExtraFields();
            });
            renderConcernTypeDropdownOptions();
        }
        if (concernTypeTrigger && concernTypeMenu && concernTypeWrapper) {
            concernTypeTrigger.addEventListener('click', function() {
                if (concernTypeTrigger.disabled) return;
                const shouldOpen = concernTypeMenu.hidden;
                closeConcernTypeDropdown();
                if (!shouldOpen) return;
                renderConcernTypeDropdownOptions();
                closeRecipientDropdown();
                closeDepartmentDropdown();
                closeCategoryDropdown();
                closeAdminLegalRequestForDropdown();
                closeMarketingSubcategoryDropdown();
                closeEmailRequestTypeDropdown();
                closeUrgencyDropdown();
                concernTypeWrapper.classList.add('is-open');
                concernTypeTrigger.setAttribute('aria-expanded', 'true');
                concernTypeMenu.hidden = false;
            });
            document.addEventListener('click', function(event) {
                if (!concernTypeWrapper.contains(event.target)) {
                    closeConcernTypeDropdown();
                }
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeConcernTypeDropdown();
                }
            });
        }
        if (certificateLeavePurposeSelect) {
            const selectedCertificateLeavePurpose = String(certificateLeavePurposeSelect.getAttribute('data-selected') || '');
            if (selectedCertificateLeavePurpose !== '') {
                certificateLeavePurposeSelect.value = selectedCertificateLeavePurpose;
            }
            certificateLeavePurposeSelect.addEventListener('change', function() {
                toggleHrExtraFields();
            });
        }
        if (emailRequestTypeSelect) {
            emailRequestTypeSelect.addEventListener('change', function() {
                toggleHrExtraFields();
            });
        }
        requestedMaterialsInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                syncMarketingOtherInputs();
            });
        });
        cropInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                syncMarketingOtherInputs();
            });
        });
        materialSizeUnitInputs.forEach(function(input) {
            input.addEventListener('change', syncMaterialSizeInput);
        });
        materialSizeValueInputs.forEach(function(input) {
            input.addEventListener('input', syncMaterialSizeInput);
        });
        if (projectDeadlineInput) {
            projectDeadlineInput.addEventListener('change', function() {
                validateProjectDeadline(true);
            });
        }

        sssUploadConfigs.forEach(function(config) {
            const input = document.getElementById(config.inputId);
            if (!input) return;

            sssUploadState[config.inputId] = { files: Array.from(input.files || []) };
            updateSssUploadSummary(config);

            input.addEventListener('change', function() {
                setInlineFormError('');
                const files = Array.from(input.files || []);
                if (files.length === 0) {
                    syncSssInputFiles(config);
                    return;
                }
                mergeSssUploadFiles(config, files);
            });
        });

        toggleDepartment();
        toggleCategories();
        toggleMarketingSubcategory();
        toggleHrExtraFields();
        syncRequestGridRows();
        var attachmentShell = document.querySelector('#attachmentContainer .attachment-upload-shell');
        var attachmentInput = document.getElementById('attachments');
        var chooseBtn = document.getElementById('choose-file-btn');
        var fileNameEl = document.getElementById('file-name');
        var preview = document.getElementById('attachment-preview');
        var errorEl = document.getElementById('attachment-error');
        var toastEl = document.getElementById('attachment-toast');
        var attachmentPreviewModal = document.getElementById('attachmentPreviewModal');
        var attachmentPreviewBody = document.getElementById('attachmentPreviewBody');
        var attachmentPreviewTitle = document.getElementById('attachmentPreviewTitle');
        var attachmentPreviewMeta = document.getElementById('attachmentPreviewMeta');
        var attachmentPreviewClose = document.getElementById('attachmentPreviewClose');
        var attachmentPreviewPrev = document.getElementById('attachmentPreviewPrev');
        var attachmentPreviewNext = document.getElementById('attachmentPreviewNext');
        var dt = new DataTransfer();
        var objectUrls = [];
        var normalAttachmentPreviewItems = [];
        var activeAttachmentPreviewItems = [];
        var activeAttachmentPreviewIndex = -1;
        var activeAttachmentPreviewUrl = '';
        var activeAttachmentPreviewIsTemporary = false;
        var MAX_BYTES = 5 * 1024 * 1024;
        var MAX_FILES = 5;
        var ALLOWED_EXT = ['jpg','jpeg','png','pdf','doc','docx'];
        var SSS_ALLOWED_EXT = ['jpg','jpeg','png','pdf','doc','docx'];
        var SSS_MAX_FILE_BYTES = 10 * 1024 * 1024;
        var UNSUPPORTED_FILE_MESSAGE = 'Please insert supported files only.';
        var toastTimer = null;

        function openAttachmentPicker() {
            if (!attachmentInput || attachmentInput.disabled) return;
            try {
                if (typeof attachmentInput.showPicker === 'function') {
                    attachmentInput.showPicker();
                    return;
                }
            } catch (e) {}
            attachmentInput.click();
        }

        if (chooseBtn) {
            chooseBtn.addEventListener('click', function (event) {
                event.preventDefault();
                openAttachmentPicker();
            });
            chooseBtn.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                openAttachmentPicker();
            });
        }

        if (attachmentShell) {
            ['dragenter', 'dragover'].forEach(function(eventName) {
                attachmentShell.addEventListener(eventName, function(event) {
                    if (!attachmentInput || attachmentInput.disabled) return;
                    event.preventDefault();
                    attachmentShell.classList.add('is-dragover');
                });
            });
            ['dragleave', 'dragend', 'drop'].forEach(function(eventName) {
                attachmentShell.addEventListener(eventName, function(event) {
                    if (!attachmentInput || attachmentInput.disabled) return;
                    event.preventDefault();
                    attachmentShell.classList.remove('is-dragover');
                });
            });
            attachmentShell.addEventListener('drop', function(event) {
                if (!attachmentInput || attachmentInput.disabled) return;
                var droppedFiles = event.dataTransfer ? event.dataTransfer.files : null;
                if (!droppedFiles || !droppedFiles.length) return;
                addAttachmentFiles(droppedFiles);
            });
        }

        function clearObjectUrls() {
            closeInlineAttachmentPreview();
            while (objectUrls.length) {
                try { URL.revokeObjectURL(objectUrls.pop()); } catch (e) {}
            }
        }

        function formatSize(bytes) {
            var b = Number(bytes || 0);
            if (!isFinite(b) || b < 0) b = 0;
            if (b < 1024) return b + ' B';
            var kb = b / 1024;
            if (kb < 1024) return (Math.round(kb * 10) / 10) + ' KB';
            var mb = kb / 1024;
            return (Math.round(mb * 10) / 10) + ' MB';
        }

        function getAttachmentPreviewKind(file) {
            var ext = getExt(file && file.name);
            var type = String((file && file.type) || '').toLowerCase();
            if (type.indexOf('image/') === 0 || ['jpg', 'jpeg', 'png'].indexOf(ext) !== -1) {
                return 'image';
            }
            if (type === 'application/pdf' || ext === 'pdf') {
                return 'pdf';
            }
            if (['doc', 'docx'].indexOf(ext) !== -1 || type === 'application/msword' || type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                return 'word';
            }
            return 'unsupported';
        }

        function readBlobAsArrayBuffer(blob) {
            return new Promise(function(resolve, reject) {
                var reader = new FileReader();
                reader.onload = function() { resolve(reader.result); };
                reader.onerror = function() { reject(reader.error || new Error('Unable to read file.')); };
                reader.readAsArrayBuffer(blob);
            });
        }

        function readZipUint16(bytes, offset) {
            return bytes[offset] | (bytes[offset + 1] << 8);
        }

        function readZipUint32(bytes, offset) {
            return (bytes[offset] | (bytes[offset + 1] << 8) | (bytes[offset + 2] << 16) | (bytes[offset + 3] << 24)) >>> 0;
        }

        function findDocxEntry(bytes, entryName) {
            var minOffset = Math.max(0, bytes.length - 66000);
            var eocdOffset = -1;
            for (var i = bytes.length - 22; i >= minOffset; i -= 1) {
                if (readZipUint32(bytes, i) === 0x06054b50) {
                    eocdOffset = i;
                    break;
                }
            }
            if (eocdOffset < 0) return null;

            var centralDirectorySize = readZipUint32(bytes, eocdOffset + 12);
            var centralDirectoryOffset = readZipUint32(bytes, eocdOffset + 16);
            var centralDirectoryEnd = centralDirectoryOffset + centralDirectorySize;
            var decoder = new TextDecoder('utf-8');

            for (var offset = centralDirectoryOffset; offset < centralDirectoryEnd;) {
                if (readZipUint32(bytes, offset) !== 0x02014b50) break;

                var method = readZipUint16(bytes, offset + 10);
                var compressedSize = readZipUint32(bytes, offset + 20);
                var uncompressedSize = readZipUint32(bytes, offset + 24);
                var nameLength = readZipUint16(bytes, offset + 28);
                var extraLength = readZipUint16(bytes, offset + 30);
                var commentLength = readZipUint16(bytes, offset + 32);
                var localHeaderOffset = readZipUint32(bytes, offset + 42);
                var nameStart = offset + 46;
                var name = decoder.decode(bytes.subarray(nameStart, nameStart + nameLength));

                if (name === entryName) {
                    if (readZipUint32(bytes, localHeaderOffset) !== 0x04034b50) return null;
                    var localNameLength = readZipUint16(bytes, localHeaderOffset + 26);
                    var localExtraLength = readZipUint16(bytes, localHeaderOffset + 28);
                    var dataStart = localHeaderOffset + 30 + localNameLength + localExtraLength;
                    return {
                        method: method,
                        compressedSize: compressedSize,
                        uncompressedSize: uncompressedSize,
                        data: bytes.subarray(dataStart, dataStart + compressedSize)
                    };
                }

                offset += 46 + nameLength + extraLength + commentLength;
            }
            return null;
        }

        function inflateDocxEntry(entry) {
            if (!entry) return Promise.reject(new Error('Document content was not found.'));
            if (entry.method === 0) {
                return Promise.resolve(entry.data);
            }
            if (entry.method !== 8 || typeof DecompressionStream !== 'function') {
                return Promise.reject(new Error('Word preview is not supported by this browser.'));
            }
            var stream = new Blob([entry.data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
            return new Response(stream).arrayBuffer().then(function(buffer) {
                return new Uint8Array(buffer);
            });
        }

        function renderDocxTextPreview(file, target) {
            var ext = getExt(file && file.name);
            if (ext !== 'docx') {
                target.textContent = 'Preview is available for DOCX files only. This DOC file remains attached to this ticket.';
                return;
            }

            target.textContent = 'Loading Word preview...';
            readBlobAsArrayBuffer(file)
                .then(function(buffer) {
                    var bytes = new Uint8Array(buffer);
                    return inflateDocxEntry(findDocxEntry(bytes, 'word/document.xml'));
                })
                .then(function(xmlBytes) {
                    var xml = new TextDecoder('utf-8').decode(xmlBytes);
                    var xmlDoc = new DOMParser().parseFromString(xml, 'application/xml');
                    var paragraphs = Array.from(xmlDoc.getElementsByTagName('w:p')).map(function(paragraph) {
                        return Array.from(paragraph.getElementsByTagName('w:t')).map(function(node) {
                            return node.textContent || '';
                        }).join('');
                    }).filter(function(text) {
                        return String(text || '').trim() !== '';
                    });

                    target.textContent = '';
                    target.classList.add('attachment-preview-word');
                    if (paragraphs.length === 0) {
                        target.textContent = 'This Word document has no readable text preview, but it remains attached to this ticket.';
                        return;
                    }

                    paragraphs.forEach(function(text) {
                        var p = document.createElement('p');
                        p.textContent = text;
                        target.appendChild(p);
                    });
                })
                .catch(function() {
                    target.textContent = 'Unable to generate a Word preview in this browser, but the file remains attached to this ticket.';
                });
        }

        function closeInlineAttachmentPreview() {
            if (attachmentPreviewModal) {
                attachmentPreviewModal.classList.remove('is-visible');
                attachmentPreviewModal.setAttribute('aria-hidden', 'true');
                attachmentPreviewModal.removeAttribute('data-preview-kind');
            }
            if (attachmentPreviewBody) {
                attachmentPreviewBody.innerHTML = '';
            }
            if (activeAttachmentPreviewIsTemporary && activeAttachmentPreviewUrl) {
                try { URL.revokeObjectURL(activeAttachmentPreviewUrl); } catch (e) {}
            }
            activeAttachmentPreviewUrl = '';
            activeAttachmentPreviewIsTemporary = false;
            activeAttachmentPreviewItems = [];
            activeAttachmentPreviewIndex = -1;
        }

        function updateAttachmentPreviewNav() {
            var hasMultipleFiles = activeAttachmentPreviewItems.length > 1;
            if (attachmentPreviewPrev) attachmentPreviewPrev.disabled = !hasMultipleFiles;
            if (attachmentPreviewNext) attachmentPreviewNext.disabled = !hasMultipleFiles;
        }

        function openInlineAttachmentPreview(file, url, isTemporaryUrl, galleryItems, galleryIndex) {
            if (!attachmentPreviewModal || !attachmentPreviewBody || !url) return;
            closeInlineAttachmentPreview();

            activeAttachmentPreviewUrl = url;
            activeAttachmentPreviewIsTemporary = !!isTemporaryUrl;
            activeAttachmentPreviewItems = Array.isArray(galleryItems) ? galleryItems : [];
            activeAttachmentPreviewIndex = Number.isInteger(galleryIndex) ? galleryIndex : -1;

            if (attachmentPreviewTitle) {
                attachmentPreviewTitle.textContent = (file && file.name) ? file.name : 'Attachment Preview';
            }
            if (attachmentPreviewMeta) {
                var metaText = file ? formatSize(file.size || 0) : '';
                if (activeAttachmentPreviewItems.length > 1 && activeAttachmentPreviewIndex >= 0) {
                    metaText += ' - ' + (activeAttachmentPreviewIndex + 1) + ' of ' + activeAttachmentPreviewItems.length;
                }
                attachmentPreviewMeta.textContent = metaText;
            }

            var kind = getAttachmentPreviewKind(file);
            attachmentPreviewModal.setAttribute('data-preview-kind', kind);
            if (kind === 'image') {
                var img = document.createElement('img');
                img.src = url;
                img.alt = (file && file.name) ? file.name : 'Attachment preview';
                attachmentPreviewBody.appendChild(img);
            } else if (kind === 'pdf') {
                var frame = document.createElement('iframe');
                frame.src = url;
                frame.title = (file && file.name) ? file.name : 'Attachment preview';
                attachmentPreviewBody.appendChild(frame);
            } else if (kind === 'word') {
                var wordPreview = document.createElement('div');
                wordPreview.className = 'attachment-preview-unavailable attachment-preview-word';
                attachmentPreviewBody.appendChild(wordPreview);
                renderDocxTextPreview(file, wordPreview);
            } else {
                var message = document.createElement('div');
                message.className = 'attachment-preview-unavailable';
                message.textContent = 'Preview is not available for this file type, but it remains attached to this ticket.';
                attachmentPreviewBody.appendChild(message);
            }

            attachmentPreviewModal.classList.add('is-visible');
            attachmentPreviewModal.setAttribute('aria-hidden', 'false');
            updateAttachmentPreviewNav();
        }

        function openAttachmentPreviewAt(index) {
            if (!activeAttachmentPreviewItems.length) return;
            var nextIndex = (index + activeAttachmentPreviewItems.length) % activeAttachmentPreviewItems.length;
            var item = activeAttachmentPreviewItems[nextIndex];
            if (!item || !item.url) return;
            openInlineAttachmentPreview(item.file, item.url, false, activeAttachmentPreviewItems, nextIndex);
        }

        function openNormalAttachmentPreviewAt(index) {
            if (!normalAttachmentPreviewItems.length) return;
            var item = normalAttachmentPreviewItems[index];
            if (!item || !item.url) return;
            openInlineAttachmentPreview(item.file, item.url, false, normalAttachmentPreviewItems, index);
        }

        function showPreviousAttachmentPreview() {
            if (activeAttachmentPreviewIndex < 0) return;
            openAttachmentPreviewAt(activeAttachmentPreviewIndex - 1);
        }

        function showNextAttachmentPreview() {
            if (activeAttachmentPreviewIndex < 0) return;
            openAttachmentPreviewAt(activeAttachmentPreviewIndex + 1);
        }

        if (attachmentPreviewClose) {
            attachmentPreviewClose.addEventListener('click', closeInlineAttachmentPreview);
        }
        if (attachmentPreviewPrev) {
            attachmentPreviewPrev.addEventListener('click', function(event) {
                event.stopPropagation();
                showPreviousAttachmentPreview();
            });
        }
        if (attachmentPreviewNext) {
            attachmentPreviewNext.addEventListener('click', function(event) {
                event.stopPropagation();
                showNextAttachmentPreview();
            });
        }
        if (attachmentPreviewModal) {
            attachmentPreviewModal.addEventListener('click', function(event) {
                if (event.target === attachmentPreviewModal) {
                    closeInlineAttachmentPreview();
                }
            });
        }
        document.addEventListener('keydown', function(event) {
            if (!attachmentPreviewModal || !attachmentPreviewModal.classList.contains('is-visible')) return;
            if (event.key === 'Escape') {
                closeInlineAttachmentPreview();
            } else if (event.key === 'ArrowLeft') {
                showPreviousAttachmentPreview();
            } else if (event.key === 'ArrowRight') {
                showNextAttachmentPreview();
            }
        });

        function syncFiles() {
            if (!attachmentInput) return;
            attachmentInput.files = dt.files;
            if (fileNameEl) {
                var n = dt.files.length;
                fileNameEl.textContent = n === 0 ? 'No file chosen' : (n === 1 ? dt.files[0].name : (n + ' files selected'));
            }
            if (!preview) return;
            clearObjectUrls();
            normalAttachmentPreviewItems = [];
            preview.innerHTML = '';
            Array.from(dt.files).forEach(function (file, idx) {
                var url = URL.createObjectURL(file);
                objectUrls.push(url);
                normalAttachmentPreviewItems.push({ file: file, url: url });

                var row = document.createElement('div');
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.justifyContent = 'space-between';
                row.style.gap = '12px';
                row.style.padding = '10px 12px';
                row.style.border = '1px solid #e5e7eb';
                row.style.borderRadius = '10px';
                row.style.background = '#f8fafc';
                row.style.marginBottom = '10px';

                var left = document.createElement('button');
                left.type = 'button';
                left.style.display = 'flex';
                left.style.alignItems = 'center';
                left.style.gap = '10px';
                left.style.minWidth = '0';
                left.style.flex = '1 1 auto';
                left.style.padding = '0';
                left.style.border = '0';
                left.style.background = 'transparent';
                left.style.textAlign = 'left';
                left.style.cursor = 'pointer';
                left.addEventListener('click', function () {
                    openNormalAttachmentPreviewAt(idx);
                });

                var icon = document.createElement('div');
                icon.style.width = '36px';
                icon.style.height = '36px';
                icon.style.borderRadius = '10px';
                icon.style.display = 'flex';
                icon.style.alignItems = 'center';
                icon.style.justifyContent = 'center';
                icon.style.background = 'transparent';
                icon.style.color = '#16a34a';
                icon.style.fontWeight = '900';

                if (file.type && file.type.startsWith('image/')) {
                    var img = document.createElement('img');
                    img.src = url;
                    img.alt = '';
                    img.style.width = '28px';
                    img.style.height = '28px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    icon.style.background = '#ffffff';
                    icon.appendChild(img);
                } else {
                    icon.textContent = 'FILE';
                }

                var meta = document.createElement('div');
                meta.style.display = 'flex';
                meta.style.flexDirection = 'column';
                meta.style.minWidth = '0';

                var name = document.createElement('div');
                name.textContent = file.name;
                name.style.fontWeight = '700';
                name.style.color = '#0f172a';
                name.style.fontSize = '13px';
                name.style.overflow = 'hidden';
                name.style.textOverflow = 'ellipsis';
                name.style.whiteSpace = 'nowrap';

                meta.appendChild(name);

                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'attachment-remove-button';
                removeBtn.textContent = '\u00d7';
                removeBtn.setAttribute('aria-label', 'Remove ' + file.name);
                removeBtn.style.border = '1px solid #e2e8f0';
                removeBtn.style.background = '#ffffff';
                removeBtn.style.color = '#ef4444';
                removeBtn.style.fontWeight = '900';
                removeBtn.style.width = '40px';
                removeBtn.style.height = '40px';
                removeBtn.style.padding = '0';
                removeBtn.style.borderRadius = '10px';
                removeBtn.style.cursor = 'pointer';
                removeBtn.style.fontSize = '18px';
                removeBtn.style.lineHeight = '1';
                removeBtn.addEventListener('click', function () {
                    try { URL.revokeObjectURL(url); } catch (e) {}
                    var ndt = new DataTransfer();
                    Array.from(dt.files).forEach(function (f, i) {
                        if (i !== idx) ndt.items.add(f);
                    });
                    dt = ndt;
                    if (attachmentInput) attachmentInput.value = '';
                    syncFiles();
                });

                left.appendChild(icon);
                left.appendChild(meta);

                row.appendChild(left);
                row.appendChild(removeBtn);
                preview.appendChild(row);
            });
        }

        function showToast(msg) {
            if (!toastEl) return;
            if (!msg) {
                toastEl.style.display = 'none';
                toastEl.textContent = '';
                if (toastTimer) window.clearTimeout(toastTimer);
                toastTimer = null;
                return;
            }
            toastEl.textContent = msg;
            toastEl.style.display = 'block';
            if (toastTimer) window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(function () {
                if (!toastEl) return;
                toastEl.style.display = 'none';
                toastEl.textContent = '';
                toastTimer = null;
            }, 4000);
        }

        function showError(msg) {
            if (!errorEl) return;
            if (!msg) {
                errorEl.style.display = 'none';
                errorEl.textContent = '';
                showToast('');
                return;
            }
            errorEl.textContent = msg;
            errorEl.style.display = 'block';
            showToast(msg);
        }

        window.TMEmployeeResetAttachments = function () {
            dt = new DataTransfer();
            if (attachmentInput) attachmentInput.value = '';
            syncFiles();
            showError('');
        };
        window.TMEmployeeResetSssUploads = function () {
            resetSssUploads();
        };

        function getExt(name) {
            var parts = String(name || '').toLowerCase().split('.');
            return parts.length > 1 ? parts.pop() : '';
        }

        function addAttachmentFiles(selectedFiles) {
            var blockedMax = false;
            var hasUnsupportedType = false;
            var validFiles = [];

            Array.from(selectedFiles || []).forEach(function (file) {
                var ext = getExt(file && file.name);
                if (ALLOWED_EXT.indexOf(ext) === -1) {
                    hasUnsupportedType = true;
                    return;
                }
                validFiles.push(file);
            });

            if (hasUnsupportedType) {
                if (attachmentInput) attachmentInput.value = '';
                showError(UNSUPPORTED_FILE_MESSAGE);
                return;
            }

            validFiles.forEach(function (file) {
                if (dt.files.length >= MAX_FILES) {
                    blockedMax = true;
                    return;
                }
                var nextTotal = (file && file.size || 0);
                Array.from(dt.files).forEach(function (f) { nextTotal += (f && f.size) ? f.size : 0; });
                if (nextTotal > MAX_BYTES) {
                    showError('Attachment too large. Max 5MB total.');
                    return;
                }
                var exists = Array.from(dt.files).some(function (f) {
                    return f.name === file.name && f.size === file.size && f.lastModified === file.lastModified;
                });
                if (!exists) dt.items.add(file);
            });

            if (attachmentInput) attachmentInput.value = '';
            if (blockedMax) {
                showError('Maximum 5 attachments allowed. Extra files were not added.');
            } else {
                showError('');
            }
            syncFiles();
        }

        if (attachmentInput) {
            attachmentInput.addEventListener('change', function (e) {
                addAttachmentFiles(e.target.files || []);
            });
        }

        function validateSssUploads() {
            var firstErrorMessage = '';
            var firstErrorConfig = null;
            for (var i = 0; i < sssUploadConfigs.length; i++) {
                var config = sssUploadConfigs[i];
                var input = document.getElementById(config.inputId);
                if (!input || input.disabled) continue;

                var files = Array.from(input.files || []);
                if (files.length === 0) {
                    var requiredMessage = 'Please upload the ' + config.label + '.';
                    setSssUploadError(config, requiredMessage);
                    if (!firstErrorMessage) {
                        firstErrorMessage = requiredMessage;
                        firstErrorConfig = config;
                    }
                    continue;
                }
                if (files.length > config.maxFiles) {
                    var maxMessage = config.maxFiles === 1
                        ? 'Only 1 file is allowed for ' + config.label + '.'
                        : 'You can upload up to ' + config.maxFiles + ' files for ' + config.label + '.';
                    setSssUploadError(config, maxMessage);
                    if (!firstErrorMessage) {
                        firstErrorMessage = maxMessage;
                        firstErrorConfig = config;
                    }
                    continue;
                }

                for (var index = 0; index < files.length; index++) {
                    var file = files[index];
                    var ext = getExt(file && file.name);
                    if (SSS_ALLOWED_EXT.indexOf(ext) === -1) {
                        var typeMessage = 'Please upload only JPG, PNG, PDF, DOC, or DOCX files for ' + config.label + '.';
                        setSssUploadError(config, typeMessage);
                        if (!firstErrorMessage) {
                            firstErrorMessage = typeMessage;
                            firstErrorConfig = config;
                        }
                        break;
                    }
                    if ((file && file.size ? file.size : 0) > SSS_MAX_FILE_BYTES) {
                        var sizeMessage = 'Each ' + config.label + ' file must be 10 MB or smaller.';
                        setSssUploadError(config, sizeMessage);
                        if (!firstErrorMessage) {
                            firstErrorMessage = sizeMessage;
                            firstErrorConfig = config;
                        }
                        break;
                    }
                }
                if (!firstErrorConfig || firstErrorConfig.inputId !== config.inputId) {
                    setSssUploadError(config, '');
                }
            }
            return {
                message: firstErrorMessage,
                config: firstErrorConfig
            };
        }

        var formEl = attachmentInput ? attachmentInput.closest('form') : null;
        if (formEl) {
            formEl.addEventListener('submit', function (e) {
                var isKamiAttachmentRequired = false;
                var isLapcHrSelected = false;
                var isLapcItSelected = false;
                var isLapcMarketingSelected = false;
                var isMhcMarketingSelected = false;
                var isHrSssSelected = false;
                var isIncidentReportAttachmentRequired = false;
                var selectedCategory = '';
                if (recipientDropdown && departmentSelect && categorySelect) {
                    selectedCategory = String(categorySelect.value || '');
                    isLapcHrSelected =
                        String(recipientDropdown.value || '') === '@leadsagri.com' &&
                        String(departmentSelect.value || '') === 'HR';
                    isLapcItSelected =
                        String(recipientDropdown.value || '') === '@leadsagri.com' &&
                        String(departmentSelect.value || '') === 'IT';
                    isLapcMarketingSelected =
                        String(recipientDropdown.value || '') === '@leadsagri.com' &&
                        String(departmentSelect.value || '') === 'Marketing';
                    isMhcMarketingSelected =
                        String(recipientDropdown.value || '') === '@malvedaholdings.com' &&
                        String(departmentSelect.value || '') === 'Marketing Creatives';
                    isKamiAttachmentRequired =
                        isLapcHrSelected &&
                        selectedCategory === 'Attendance & Timekeeping';
                    isHrSssSelected =
                        isLapcHrSelected &&
                        selectedCategory === 'SSS Sickness and Benefit Concern';
                    isIncidentReportAttachmentRequired =
                        isLapcHrSelected &&
                        selectedCategory === 'Incident Report';
                }
                setInlineFormError('');
                var badType = Array.from(dt.files).find(function (file) {
                    var ext = getExt(file && file.name);
                    return ALLOWED_EXT.indexOf(ext) === -1;
                });
                var total = 0;
                Array.from(dt.files).forEach(function (f) { total += (f && f.size) ? f.size : 0; });
                if (isLapcHrSelected && urgencySelect && !String(urgencySelect.value || '').trim()) {
                    e.preventDefault();
                    setInlineFormError('Please choose the level of urgency.');
                    return;
                }
                var requiresLapcRequestType = (isLapcMarketingSelected && Object.prototype.hasOwnProperty.call(lapcMarketingSubcategories, selectedCategory))
                    || (recipientDropdown && departmentSelect && String(recipientDropdown.value || '') === '@leadsagri.com' && String(departmentSelect.value || '') === 'Supply Chain' && Object.prototype.hasOwnProperty.call(lapcSupplyChainRequestTypes, selectedCategory));
                if (requiresLapcRequestType && marketingSubcategorySelect && !String(marketingSubcategorySelect.value || '').trim()) {
                    e.preventDefault();
                    setInlineFormError('Please choose the Request Type / Concern.');
                    return;
                }
                if (isMhcMarketingSelected) {
                    syncMaterialSizeInput();
                    const hasRequestedMaterial = requestedMaterialsSelect
                        ? String(requestedMaterialsSelect.value || '').trim() !== ''
                        : requestedMaterialsInputs.some(function(input) { return input.checked; });
                    const hasCrop = cropSelect
                        ? String(cropSelect.value || '').trim() !== ''
                        : cropInputs.some(function(input) { return input.checked; });
                    const requestedOtherSelected = requestedMaterialsSelect
                        ? String(requestedMaterialsSelect.value || '') === 'Other'
                        : requestedMaterialsInputs.some(function(input) { return input.checked && input.value === 'Other'; });
                    const cropOtherSelected = cropSelect
                        ? String(cropSelect.value || '') === 'Other'
                        : cropInputs.some(function(input) { return input.checked && input.value === 'Other'; });
                    if (!String((projectNameInput && projectNameInput.value) || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please enter the Project Name.');
                        return;
                    }
                    if (!String((areaCodeSelect && areaCodeSelect.value) || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please choose the Area Code.');
                        return;
                    }
                    if (!String((marketingDepartmentSelect && marketingDepartmentSelect.value) || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please choose the Department.');
                        return;
                    }
                    if (!hasRequestedMaterial) {
                        e.preventDefault();
                        setInlineFormError('Please choose a Requested Materials option.');
                        return;
                    }
                    if (requestedOtherSelected && requestedMaterialsOtherInput && !String(requestedMaterialsOtherInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please specify the other requested material.');
                        return;
                    }
                    if (!String((materialSizeInput && materialSizeInput.value) || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please enter the Size of Material.');
                        return;
                    }
                    if (!String((projectDeadlineInput && projectDeadlineInput.value) || '').trim() || !validateProjectDeadline(true)) {
                        e.preventDefault();
                        setInlineFormError('Project Deadline must be at least 3 working days from today.');
                        return;
                    }
                    if (!hasCrop) {
                        e.preventDefault();
                        setInlineFormError('Please choose a Crop option.');
                        return;
                    }
                    if (cropOtherSelected && cropOtherInput && !String(cropOtherInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please specify the other crop.');
                        return;
                    }
                    if (descriptionField && !String(descriptionField.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please enter the Brief Description of Request.');
                        return;
                    }
                    if (urgencySelect && !String(urgencySelect.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please choose the Urgency Level.');
                        return;
                    }
                }
                if (isKamiAttachmentRequired && concernTypeSelect && !String(concernTypeSelect.value || '').trim()) {
                    e.preventDefault();
                    setInlineFormError('Please choose the type of concern.');
                    return;
                }
                if (isKamiAttachmentRequired && concernTypeSelect && String(concernTypeSelect.value || '') === 'Other' && concernTypeOtherInput && !String(concernTypeOtherInput.value || '').trim()) {
                    e.preventDefault();
                    setInlineFormError('Please enter the type of concern.');
                    return;
                }
                if (isLapcHrSelected && (selectedCategory === 'Leave Concern' || selectedCategory === 'Others') && leaveSubjectInput && !String(leaveSubjectInput.value || '').trim()) {
                    e.preventDefault();
                    setInlineFormError('Please enter the subject/title of request.');
                    return;
                }
                if (isLapcHrSelected && selectedCategory === 'Medical Cash Advance') {
                    if (medicalCashPurposeInput && !String(medicalCashPurposeInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please enter the purpose for Medical Cash Advance.');
                        return;
                    }
                    if (medicalCashAmountInput && !String(medicalCashAmountInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please enter the amount for Medical Cash Advance.');
                        return;
                    }
                    if (medicalCashDateNeededInput && !String(medicalCashDateNeededInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please select the date needed for Medical Cash Advance.');
                        return;
                    }
                }
                if (isLapcHrSelected && selectedCategory === 'Training Request') {
                    if (trainingRequestTitleInput && !String(trainingRequestTitleInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Training Request form.');
                        return;
                    }
                    if (trainingRequestProviderInput && !String(trainingRequestProviderInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Training Request form.');
                        return;
                    }
                    if (trainingRequestStartDateInput && !String(trainingRequestStartDateInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Training Request form.');
                        return;
                    }
                    if (trainingRequestEndDateInput && !String(trainingRequestEndDateInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Training Request form.');
                        return;
                    }
                    if (trainingRequestVenueInput && !String(trainingRequestVenueInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Training Request form.');
                        return;
                    }
                    if (trainingRequestFeeInput && !String(trainingRequestFeeInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Training Request form.');
                        return;
                    }
                    if (trainingRequestStartDateInput && trainingRequestEndDateInput && String(trainingRequestStartDateInput.value || '') !== '' && String(trainingRequestEndDateInput.value || '') !== '' && new Date(trainingRequestEndDateInput.value) < new Date(trainingRequestStartDateInput.value)) {
                        e.preventDefault();
                        setInlineFormError('End date cannot be earlier than start date.');
                        return;
                    }
                }
                if (isLapcHrSelected && selectedCategory === 'Request for Company Property') {
                    const hasPropertyType = companyPropertyTypeInputs.some(function(input) { return input.checked; });
                    const hasPropertyReason = companyPropertyReasonInputs.some(function(input) { return input.checked; });
                    if (!hasPropertyType || !hasPropertyReason) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Request for Company Property form.');
                        return;
                    }
                }
                if (isLapcHrSelected && selectedCategory === 'Certificate of Employment') {
                    const coeReason = coeRequestReasonInputs.find(function(input) { return input.checked; });
                    const hasSalaryChoice = coeSalaryDetailsInputs.some(function(input) { return input.checked; });
                    const hasDeliveryMethod = coeDeliveryMethodInputs.some(function(input) { return input.checked; });
                    if (!coeReason || !hasSalaryChoice || !hasDeliveryMethod || !String((coePreferredReleaseDateInput && coePreferredReleaseDateInput.value) || '').trim() || !String((coeRemarksInput && coeRemarksInput.value) || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Certificate of Employment form.');
                        return;
                    }
                    if (coeReason.value === 'Other' && coeRequestReasonOtherInput && !String(coeRequestReasonOtherInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Certificate of Employment form.');
                        return;
                    }
                }
                if (isLapcHrSelected && selectedCategory === 'Certificate of Leave') {
                    if (certificateLeaveDateInput && !String(certificateLeaveDateInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Certificate of Leave form.');
                        return;
                    }
                    if (certificateLeavePurposeSelect && !String(certificateLeavePurposeSelect.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Certificate of Leave form.');
                        return;
                    }
                    if (certificateLeavePurposeSelect && String(certificateLeavePurposeSelect.value || '') === 'Others' && certificateLeavePurposeOtherInput && !String(certificateLeavePurposeOtherInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Certificate of Leave form.');
                        return;
                    }
                }
                if (isLapcHrSelected && selectedCategory === 'Incident Report') {
                    if (incidentSummaryInput && !String(incidentSummaryInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Incident Report form.');
                        return;
                    }
                    if (isIncidentReportAttachmentRequired && dt.files.length === 0) {
                        e.preventDefault();
                        showError('Attachment is required for Incident Report.');
                        return;
                    }
                }
                if (isLapcItSelected && selectedCategory === 'SAP') {
                    const sapCards = getSapCards();
                    let hasCompleteSapEntry = false;
                    for (let sapIndex = 0; sapIndex < sapCards.length; sapIndex++) {
                        const sapValues = getSapCardValues(sapCards[sapIndex]);
                        const filledSapFields = Object.values(sapValues).filter(function(value) {
                            return value !== '';
                        }).length;
                        if (filledSapFields === 0) {
                            continue;
                        }
                        const sapNameInput = sapCards[sapIndex].querySelector('[data-sap-field="name"]');
                        if (!sapNameInput || !String(sapNameInput.value || '').trim()) {
                            e.preventDefault();
                            setInlineFormError('Please complete each SAP employee report before submitting.');
                            if (sapNameInput) sapNameInput.focus();
                            return;
                        }
                        hasCompleteSapEntry = true;
                    }
                    if (!hasCompleteSapEntry) {
                        e.preventDefault();
                        setInlineFormError('Please complete the SAP form.');
                        return;
                    }
                }
                if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && !String(emailRequestTypeSelect.value || '').trim()) {
                    e.preventDefault();
                    setInlineFormError('Please choose the email request type.');
                    return;
                }
                if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email') {
                    const emailCards = getEmailCreationCards();
                    let hasCompleteEmailEntry = false;
                    for (let emailIndex = 0; emailIndex < emailCards.length; emailIndex++) {
                        const emailValues = getEmailCreationCardValues(emailCards[emailIndex]);
                        const filledEmailFields = Object.values(emailValues).filter(function(value) {
                            return value !== '';
                        }).length;
                        if (filledEmailFields === 0) {
                            continue;
                        }
                        const incompleteEmailCreationField = findFirstIncompleteEmailCreationInput(emailCards[emailIndex]);
                        if (incompleteEmailCreationField) {
                            e.preventDefault();
                            setInlineFormError('Please complete each Creation of email card before submitting.');
                            try { incompleteEmailCreationField.focus(); } catch (focusError) {}
                            return;
                        }
                        hasCompleteEmailEntry = true;
                    }
                    if (!hasCompleteEmailEntry) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Creation of email details.');
                        return;
                    }
                }
                if (isHrSssSelected) {
                    var sssUploadValidation = validateSssUploads();
                    if (sssUploadValidation.message !== '') {
                        e.preventDefault();
                        setInlineFormError('');
                        if (sssUploadValidation.config) {
                            var sssErrorEl = document.getElementById(sssUploadValidation.config.errorId);
                            if (sssErrorEl) {
                                sssErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }
                        return;
                    }
                }
                if (isKamiAttachmentRequired && dt.files.length === 0) {
                    e.preventDefault();
                    showError('Attachment is required for Attendance & Timekeeping.');
                    return;
                }
                if (isLapcHrSelected && selectedCategory === 'Medical Cash Advance' && dt.files.length === 0) {
                    e.preventDefault();
                    showError('Supporting Information is required for Medical Cash Advance.');
                    return;
                }
                if (!isHrSssSelected && (dt.files.length > MAX_FILES || badType || total > MAX_BYTES)) {
                    e.preventDefault();
                    showError(dt.files.length > MAX_FILES ? 'Maximum 5 attachments allowed.' : (badType ? UNSUPPORTED_FILE_MESSAGE : 'Attachment too large. Max 5MB total.'));
                    return;
                }
                showError('');
            });
        }
    });
    </script>

    <script>
    function closeModal(){
        var m = document.getElementById('successModal');
        if (!m) return;
        m.classList.remove('show');
        m.setAttribute('aria-hidden', 'true');
        m.setAttribute('data-state', '');
        var t = document.getElementById('successModalTitle');
        var d = document.getElementById('successModalDesc');
        var s = document.getElementById('ticketModalStatus');
        var p = document.getElementById('ticketModalProgressBar');
        var doneBtn = document.getElementById('ticketModalDoneBtn');
        if (t) t.textContent = 'Submitting Ticket';
        if (d) d.textContent = 'Almost there. We are finalizing your request...';
        if (s) s.textContent = 'Finalizing your request';
        if (p) p.style.width = '94%';
        if (doneBtn) doneBtn.textContent = 'Done';
    }

    (function () {
        var form = document.getElementById('ticketForm');
        var modal = document.getElementById('successModal');
        var ajaxError = document.getElementById('ajaxError');
        var doneBtn = document.getElementById('ticketModalDoneBtn');
        var statusText = document.getElementById('ticketModalStatus');
        var progressBar = document.getElementById('ticketModalProgressBar');
        var loadingTimers = [];
        var successRedirectTimer = null;
        var loadingStartedAt = 0;
        var MIN_LOADING_MS = 600;
        if (!form) return;

        function clearLoadingTimers() {
            while (loadingTimers.length) {
                window.clearTimeout(loadingTimers.pop());
            }
            if (successRedirectTimer) {
                window.clearTimeout(successRedirectTimer);
                successRedirectTimer = null;
            }
        }

        function setModalState(state, title, desc, status, progress) {
            if (!modal) return;
            modal.setAttribute('data-state', state || '');
            var t = document.getElementById('successModalTitle');
            var d = document.getElementById('successModalDesc');
            if (t && title) t.textContent = title;
            if (d && desc != null) d.innerHTML = desc;
            if (statusText) statusText.textContent = status || '';
            if (progressBar && progress != null) progressBar.style.width = String(progress) + '%';
        }

        function revealErrorBanner(message) {
            if (!ajaxError) return;
            ajaxError.textContent = message;
            ajaxError.style.display = 'block';
            ajaxError.setAttribute('tabindex', '-1');
            window.requestAnimationFrame(function () {
                ajaxError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                try { ajaxError.focus({ preventScroll: true }); } catch (e) {}
            });
        }

        function startLoadingSequence() {
            loadingStartedAt = Date.now();
            clearLoadingTimers();
            setModalState('loading', 'Submitting Ticket', 'Almost there. We are finalizing your request...', 'Finalizing your request', 94);
        }

        function showSuccessState(ticketNumber) {
            if (!modal) return;
            clearLoadingTimers();
            var ticketLine = ticketNumber
                ? ('<br><span class="ticket-modal-ticket-label">Ticket ID:</span> <span class="ticket-modal-ticket-number">#' + ticketNumber + '</span>')
                : '';
            setModalState('success', 'Ticket Submitted Successfully', 'Your request has been sent.<br>Our team will get back to you soon.' + ticketLine, '', 100);
        }

        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
        }
        if (doneBtn) {
            doneBtn.addEventListener('click', function () {
                if (!modal) return;
                var state = modal.getAttribute('data-state') || '';
                if (state === 'success') {
                    clearLoadingTimers();
                    window.location.href = 'my_tickets.php';
                    return;
                }
                closeModal();
            });
        }

        form.addEventListener('submit', function(e) {
            if (e.defaultPrevented) return;
            e.preventDefault();
            if (ajaxError) ajaxError.style.display = 'none';

            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            if (modal) {
                modal.classList.add('show');
                modal.setAttribute('aria-hidden', 'false');
                startLoadingSequence();
            }

            var formData = new FormData(form);

            fetch("request_ticket.php", {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" },
                body: formData
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    clearLoadingTimers();
                    var msg = (data && data.error) ? data.error : 'Failed to submit ticket.';
                    if (modal) {
                        modal.classList.remove('show');
                        modal.setAttribute('aria-hidden', 'true');
                        modal.setAttribute('data-state', '');
                    }
                    revealErrorBanner(msg);
                    if (doneBtn) doneBtn.textContent = 'Close';
                    return;
                }

                if (modal) {
                    var elapsed = loadingStartedAt ? (Date.now() - loadingStartedAt) : 0;
                    var waitMs = Math.max(0, MIN_LOADING_MS - elapsed);
                    if (waitMs > 0) {
                        successRedirectTimer = window.setTimeout(function () {
                            successRedirectTimer = null;
                            showSuccessState(data.ticket_number || '');
                        }, waitMs);
                    } else {
                        showSuccessState(data.ticket_number || '');
                    }
                }
                form.reset();
                if (typeof window.TMEmployeeResetAttachments === 'function') window.TMEmployeeResetAttachments();
                if (typeof window.TMEmployeeResetSssUploads === 'function') window.TMEmployeeResetSssUploads();
              })
            .catch(function () {
                clearLoadingTimers();
                if (modal) {
                    modal.classList.remove('show');
                    modal.setAttribute('aria-hidden', 'true');
                    modal.setAttribute('data-state', '');
                }
                revealErrorBanner('Failed to submit ticket.');
                if (doneBtn) doneBtn.textContent = 'Close';
            })
            .finally(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    })();

    (function () {
        var pageError = document.getElementById('pageError');
        if (!pageError) return;
        window.requestAnimationFrame(function () {
            pageError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    })();

    (function () {
        var guidanceCard = document.getElementById('requestGuidanceCard');
        var guidanceToggle = document.getElementById('requestGuidanceToggle');
        var mobileGuidanceQuery = window.matchMedia('(max-width: 768px)');
        var search = document.getElementById('requestGuidanceSearch');
        var directory = document.querySelector('.request-guidance-directory');
        var noResults = document.getElementById('requestGuidanceNoResults');

        function setGuidanceExpanded(expanded) {
            if (!guidanceCard || !guidanceToggle) return;
            guidanceCard.classList.toggle('is-expanded', expanded);
            guidanceToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        function syncGuidanceDisplay(event) {
            var isMobile = event ? event.matches : mobileGuidanceQuery.matches;
            setGuidanceExpanded(!isMobile);
            if (guidanceToggle) guidanceToggle.tabIndex = isMobile ? 0 : -1;
        }

        syncGuidanceDisplay();

        if (guidanceToggle) {
            guidanceToggle.addEventListener('click', function () {
                if (!mobileGuidanceQuery.matches) return;
                setGuidanceExpanded(!guidanceCard.classList.contains('is-expanded'));
            });
        }

        if (typeof mobileGuidanceQuery.addEventListener === 'function') {
            mobileGuidanceQuery.addEventListener('change', syncGuidanceDisplay);
        } else if (typeof mobileGuidanceQuery.addListener === 'function') {
            mobileGuidanceQuery.addListener(syncGuidanceDisplay);
        }

        if (!directory) return;

        var companyGuides = Array.prototype.slice.call(directory.querySelectorAll('.request-company-guide'));
        companyGuides.forEach(function (guide) {
            guide.dataset.initiallyOpen = guide.open ? 'true' : 'false';
        });

        directory.querySelectorAll('.request-view-departments').forEach(function (button) {
            button.addEventListener('click', function () {
                var guide = button.closest('.request-company-guide');
                if (!guide) return;

                var expanded = guide.classList.toggle('show-all-departments');
                var label = button.querySelector('span');
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                if (label) label.textContent = expanded ? 'Show fewer departments' : 'View all departments';
            });
        });

        if (!search) return;

        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            directory.classList.toggle('is-searching', query !== '');
            var visibleCompanies = 0;

            companyGuides.forEach(function (guide) {
                var summary = guide.querySelector('summary');
                var companyText = summary ? summary.textContent.toLowerCase() : '';
                var companyMatches = query !== '' && companyText.indexOf(query) !== -1;
                var departmentMatches = false;
                var departments = Array.prototype.slice.call(guide.querySelectorAll('.request-department-guide'));

                departments.forEach(function (department) {
                    var departmentMatchesQuery = query !== '' && department.textContent.toLowerCase().indexOf(query) !== -1;
                    var matches = query === '' || companyMatches || departmentMatchesQuery;
                    department.hidden = !matches;
                    department.style.display = matches ? '' : 'none';
                    department.classList.toggle('is-search-match', departmentMatchesQuery);
                    if (departmentMatchesQuery) departmentMatches = true;
                });

                guide.hidden = Boolean(query && !companyMatches && !departmentMatches);
                guide.classList.toggle('is-search-match', companyMatches);
                if (!guide.hidden) visibleCompanies += 1;
                if (query && !guide.hidden) {
                    guide.open = true;
                } else if (!query) {
                    guide.open = guide.dataset.initiallyOpen === 'true';
                    departments.forEach(function (department) {
                        department.style.display = '';
                    });
                }
            });

            if (noResults) noResults.hidden = query === '' || visibleCompanies > 0;
        });
    })();
    </script>
</body>
</html>


