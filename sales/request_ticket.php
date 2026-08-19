<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

ticket_receiving_availability_ensure_table($conn);

$success_msg = "";
$error_msg = "";

$full_name = '';
$email = '';
$company_id = '';
$category = '';
$description = '';
$request_subject_title = '';
$hr_concern_type = '';
$priority_selected = '';
$assigned_department_selected = '';
$admin_legal_request_for = '';
$sales_position = '';
$sales_region = '';
$lapcDepartments = ticket_receiving_available_departments($conn, '@leadsagri.com');
$mhcDepartments = ticket_receiving_available_departments($conn, '@malvedaholdings.com');
$salesPositionOptions = [
    'Area Supervisor',
    'Sr. Area Manager',
    'Technical and Promo Specialist',
    'Store Sales Technician',
    'Store Clerk',
    'Jr. Agronomist',
    'Seasonal Crop Technician',
    'Over-The-Counter (OTC) Promo Clerk',
    'Sales Coordinator',
    'Seasonal Crop Tech',
    'Store Supervisor',
];
$salesRegionOptions = [
    'CAR & Nueva Vizcaya (Area 811A)',
    'Region 1-A (Area 811B)',
    'Region 1-B (Area 812)',
    'Region 2-A (Area 813A)',
    'Region 2-B (Area 813B)',
    'Region 3-A (Area 814A)',
    'Region 3-B (Area 814B)',
    'Region 4 (Area 815 A&C)',
    'Region 5 (Area 815B)',
    'Region 6-A (Area 821A)',
    'Region 6-B (Area 821B)',
    'Region 6 & Palawan (Area 821C)',
    'Region 7 (Area 822A)',
    'Region 8 (Area 822B)',
    'Region 10 (Area 831A)',
    'Region 9 (Area 831B)',
    'Region 11 & 13 (Area 832A)',
    'Region 12 (Area 832B)',
    'Area 833',
];
$defaultCategories = ['Hardware', 'Software', 'Documentation', 'Email', 'Internet Concerns', 'Procurement'];
$mpdcCategories = ['Engineerings', 'Client Referral', 'Others'];
$lingapCategories = ['Lakbay Kalusugan Request (Medical Mission)', 'Others'];
$othersOnlyCompanyDomains = ['@primestocks.ph', '@leadstech-corp.com', '@gpsci.net', '@farmasee.ph', '@leads-farmex.com', '@leadsav.com'];
$lapcDepartmentCategories = [
    'Admin & Legal' => [
        'Fleetcard',
        'Office Supplies',
        'Termporary Vehicle',
        'Office Supplies(HO,Warehouse Bulacan,Norza)',
        'Repair Concern(HO)',
        'Phone Plan / Simcard',
        'FleetCard Request',
        'Supplies',
        'Others',
    ],
    'Banana Farm Operations' => ['Others'],
    'Diagnostics / Lingap' => [
        'Medical consultations',
        'Laboratory Request',
        'Medicine Request',
        'Back to work Clearance',
        'Medical Reimbursement',
        'Sick Leave Appliccation/Request',
        'Others',
    ],
    'Digital Agri Solutions and Innovations' => ['Others'],
    'E-Commerce' => ['Others'],
    'Executive' => ['Others'],
    'Finance and Accounting' => ['Others'],
    'Institutional Sales (Bidding)' => ['Others'],
    'HR' => [
        'Attendance & Timekeeping',
        'Certificate of Employment',
        'Certificate of Leave',
        'Incident Report',
        'Leave Concern',
        'Medical Cash Advance',
        'Request for Company Property',
        'SSS Sickness and Benefit Concern',
        'Training Request',
        'Others',
    ],
    'IT' => [
        'Documentation',
        'Email',
        'Hardware',
        'Internet Concerns',
        'Procurement',
        'SAP',
        'Software',
        'Others',
    ],
    'Machineries' => ['Others'],
    'Management' => ['Others'],
    'Marketing' => [
        'Marketing Operations',
        'Channel & Campaigns',
        'Others',
    ],
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
    'Technical' => [
        'CPR',
        'MSDS',
        'Technical Information/ Brochure',
        'COA',
        'Certificate of Distributorship',
        'Certificate of Authorized Dealer',
        'Updated Label',
        'Product Presentations',
        'Others',
    ],
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
$lapcAdminLegalRequestCategories = [
    'Aimi Bing Santos (Bing)' => [
        'Fleetcard',
        'Office Supplies',
        'Termporary Vehicle',
        'Others',
    ],
    'Ace Loui Rosal (Ace)' => [
        'Office Supplies(HO,Warehouse Bulacan,Norza)',
        'Repair Concern(HO)',
        'Others',
    ],
    'Cherry Jane Cabote (CJ)' => [
        'Phone Plan / Simcard',
        'FleetCard Request',
        'Supplies',
        'Others',
    ],
    'Others' => ['Others'],
];
$mhcDepartmentCategories = [
    'Marketing Creatives' => [
        'Marketing Request',
        'Others',
    ],
    'IT' => ['Others'],
    'Executive' => ['Others'],
    'Institutional Sales' => ['Others'],
    'Accounting' => ['Others'],
];

$requestGuidanceCompanyMeta = [
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

$requestTicketCompanyOptions = [
    '@leadstech-corp.com' => 'LTC',
    '@gpsci.net' => 'GPCI',
    '@leadsagri.com' => 'LAPC',
    '@leads-farmex.com' => 'FARMEX / LAV',
    '@lingapleads.org' => 'LINGAP',
    '@malvedaholdings.com' => 'MHC',
    '@malvedaproperties.com' => 'MPDC',
];
$requestTicketCompanies = array_keys($requestTicketCompanyOptions);
$selectedRecipientCompany = normalize_sales_recipient_company((string) ($_POST['company_id'] ?? ''));
if ($selectedRecipientCompany === '' && count($requestTicketCompanyOptions) === 1) {
    $selectedRecipientCompany = (string) array_key_first($requestTicketCompanyOptions);
}
$selectedRecipientDepartment = trim((string) ($_POST['assigned_department'] ?? ''));
$initialSalesDepartmentOptions = [];
if ($selectedRecipientCompany === '@leadsagri.com') {
    $initialSalesDepartmentOptions = $lapcDepartments;
} elseif ($selectedRecipientCompany === '@malvedaholdings.com') {
    $initialSalesDepartmentOptions = $mhcDepartments;
}
$initialShowDepartment = ticket_company_requires_department($selectedRecipientCompany);
if ($selectedRecipientDepartment === '' && count($initialSalesDepartmentOptions) === 1) {
    $selectedRecipientDepartment = (string) ($initialSalesDepartmentOptions[0] ?? '');
}

$requestGuidanceCompanyOptions = ticket_receiving_available_company_options($conn);
$requestGuidanceCompanyOptions = array_map(static function ($guidanceLabel): string {
    $guidanceLabel = trim((string) $guidanceLabel);
    if ($guidanceLabel === 'GPSCI') {
        return 'GPCI';
    }
    return str_replace('Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI', $guidanceLabel);
}, $requestGuidanceCompanyOptions);
asort($requestGuidanceCompanyOptions, SORT_NATURAL | SORT_FLAG_CASE);

$requestGuidanceHideCategoriesFor = [
    '@farmasee.ph',
    '@leads-farmex.com',
    '@leadsav.com',
    '@gpsci.net',
    '@leadstech-corp.com',
    '@primestocks.ph',
];
$requestGuidanceCompanies = [];
$requestGuidanceAllowedCompanies = ['@leadsagri.com', '@malvedaholdings.com', '@malvedaproperties.com', '@lingapleads.org'];
foreach ($requestGuidanceCompanyOptions as $guidanceCompanyValue => $guidanceCompanyLabel) {
    if (!in_array((string) $guidanceCompanyValue, $requestGuidanceAllowedCompanies, true)) {
        continue;
    }
    $hideGuidanceCategories = in_array((string) $guidanceCompanyValue, $requestGuidanceHideCategoriesFor, true);
    $guidanceRequiresDepartment = ticket_company_requires_department((string) $guidanceCompanyValue);
    $guidanceDepartments = $guidanceRequiresDepartment
        ? ticket_receiving_available_departments($conn, (string) $guidanceCompanyValue)
        : [];
    if ($guidanceCompanyValue === '@malvedaholdings.com') {
        $guidanceDepartments = array_values(array_filter($guidanceDepartments, static function ($department): bool {
            return (string) $department === 'Marketing Creatives';
        }));
    }
    $guidanceDirectCategories = $defaultCategories;
    if ($guidanceCompanyValue === '@malvedaproperties.com') {
        $guidanceDirectCategories = $mpdcCategories;
    } elseif ($guidanceCompanyValue === '@lingapleads.org') {
        $guidanceDirectCategories = $lingapCategories;
    }
    if ($hideGuidanceCategories) {
        $guidanceDirectCategories = [];
    }

    $guidanceDepartmentRows = [];
    foreach ($guidanceDepartments as $guidanceDepartment) {
        $guidanceCategories = $defaultCategories;
        if ($guidanceCompanyValue === '@malvedaholdings.com' && isset($mhcDepartmentCategories[$guidanceDepartment])) {
            $guidanceCategories = $mhcDepartmentCategories[$guidanceDepartment];
        } elseif ($guidanceCompanyValue === '@leadsagri.com' && isset($lapcDepartmentCategories[$guidanceDepartment])) {
            $guidanceCategories = $lapcDepartmentCategories[$guidanceDepartment];
        }
        if ($hideGuidanceCategories) {
            $guidanceCategories = [];
        }
        $guidanceDepartmentRows[] = [
            'name' => (string) $guidanceDepartment,
            'categories' => array_values($guidanceCategories),
        ];
    }

    $requestGuidanceCompanies[] = [
        'value' => (string) $guidanceCompanyValue,
        'label' => (string) $guidanceCompanyLabel,
        'icon' => (string) ($requestGuidanceCompanyMeta[$guidanceCompanyValue]['icon'] ?? 'fa-building'),
        'tone' => (string) ($requestGuidanceCompanyMeta[$guidanceCompanyValue]['tone'] ?? 'green'),
        'requires_department' => $guidanceRequiresDepartment,
        'departments' => $guidanceDepartmentRows,
        'categories' => array_values($guidanceDirectCategories),
    ];
}
$requestGuidanceOpenCompany = $selectedRecipientCompany !== '' ? $selectedRecipientCompany : '@leadsagri.com';

function derive_name_from_email(string $email): string
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) return 'Sales User';
    $local = explode('@', $email, 2)[0];
    $local = preg_replace('/[^a-zA-Z0-9._-]+/', ' ', $local);
    $local = str_replace(['.', '_', '-'], ' ', (string) $local);
    $local = trim(preg_replace('/\s+/', ' ', $local));
    if ($local === '') return 'Sales User';
    return ucwords(strtolower($local));
}

function normalize_sales_recipient_company(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/\((@\S+)\)/', $value, $matches)) {
        return strtolower(trim((string) ($matches[1] ?? '')));
    }
    if (strpos($value, '@') === 0) {
        return strtolower($value);
    }
    return ticket_normalize_company($value);
}

function find_sales_domain_recipient_ids(mysqli $conn, string $domain): array
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || strpos($domain, '@') !== 0) return [];

    $stmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE role IN ('employee', 'admin')
          AND LOWER(email) LIKE ?
        ORDER BY FIELD(role, 'employee', 'admin'), is_verified DESC, id ASC
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

function sales_request_blank_sap_report(): array
{
    return [
        'name' => '',
        'position' => '',
        'address' => '',
        'department' => '',
        'tin' => '',
    ];
}

function sales_request_extract_sap_reports(array $source): array
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

function sales_request_clean_string_array($value): array
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

function sales_request_blank_email_creation(): array
{
    return [
        'subsidiary' => '',
        'target_department' => '',
        'name' => '',
        'department' => '',
        'designation' => '',
    ];
}

function sales_request_extract_email_creations(array $source): array
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

function sales_request_min_working_deadline(int $workingDays = 3): string
{
    $date = new DateTimeImmutable('today');
    $count = 0;
    while ($count < $workingDays) {
        $date = $date->modify('+1 day');
        if ((int) $date->format('N') < 6) {
            $count++;
        }
    }
    return $date->format('Y-m-d');
}

function sales_request_working_days_between_today(string $targetDate): int
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

function sales_request_is_weekend_date(string $targetDate): bool
{
    try {
        $date = new DateTimeImmutable($targetDate);
    } catch (Exception $e) {
        return true;
    }
    return (int) $date->format('N') >= 6;
}

function sales_request_upload_dir(): string
{
    return __DIR__ . '/../uploads';
}

function sales_request_cleanup_uploaded_files(array $files): void
{
    foreach ($files as $file) {
        $storedPath = trim((string) ($file['stored_path'] ?? ''));
        if ($storedPath !== '' && file_exists($storedPath)) {
            @unlink($storedPath);
        }
    }
}

function sales_request_meta_ensure_table(mysqli $conn): void
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

function sales_request_process_upload_field(
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

    $uploadDir = sales_request_upload_dir();
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        return ['ok' => false, 'error' => 'Unable to prepare the upload folder right now.'];
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
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $oversizeError];
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Unable to upload the ' . $label . ' file right now.'];
        }

        $fileName = function_exists('ticket_pdf_sanitize_original_name')
            ? ticket_pdf_sanitize_original_name((string) $originalName)
            : basename(str_replace('\\', '/', trim((string) $originalName)));
        $fileTmp = trim((string) ($tmpNames[$index] ?? ''));
        $fileSize = (int) ($sizes[$index] ?? 0);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileName === '' || !in_array($fileExt, $allowedTypes, true)) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $unsupportedTypeError];
        }

        if ($fileSize <= 0 || $fileSize > $maxFileBytes) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $oversizeError];
        }

        if ($maxTotalBytes !== null && ($totalUploadedBytes + $fileSize) > $maxTotalBytes) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => $oversizeError];
        }

        if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
            $mime = (string) $finfo->file($fileTmp);
            $allowed = $allowedMimes[$fileExt] ?? [];
            if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                sales_request_cleanup_uploaded_files($uploadedFiles);
                return ['ok' => false, 'error' => $unsupportedTypeError];
            }
        }

        $newFileName = time() . '_' . uniqid('', true) . '.' . $fileExt;
        $uploadPath = $uploadDir . '/' . $newFileName;

        if (!move_uploaded_file($fileTmp, $uploadPath)) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
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

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function finish_ticket_submit_response(bool $isAjax, array $payload = []): void
{
    if (!$isAjax) return;

    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');

    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    ignore_user_abort(true);

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

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    @flush();
}

function sales_email_debug_log(array $context): void
{
    $logPath = __DIR__ . '/../uploads/email_debug.log';
    $entry = [
        'timestamp' => date('Y-m-d H:i:s'),
    ] + $context;
    @file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function sales_clean_email_list(array $emails): array
{
    $clean = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string) $email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[$email] = $email;
        }
    }
    return array_values($clean);
}

function sales_request_clean_email_description(string $description): string
{
    $description = trim((string) $description);
    if ($description === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $description);
    if (!is_array($lines)) {
        return $description;
    }

    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim((string) $line);
        if (preg_match('/^(Position|Region)\s*:/i', $trimmed)) {
            continue;
        }
        $cleanLines[] = (string) $line;
    }

    return trim(implode("\n", $cleanLines));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();
    ticket_ensure_assignment_columns($conn);

    $full_name  = trim((string)($_POST['full_name'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $sales_position = trim((string) ($_POST['sales_position'] ?? ''));
    $sales_region = trim((string) ($_POST['sales_region'] ?? ''));
    $company_id = trim((string)($_POST['company_id'] ?? ''));
    $assigned_department_selected = trim((string)($_POST['assigned_department'] ?? ''));
    $admin_legal_request_for = trim((string)($_POST['admin_legal_request_for'] ?? ''));
    $allowed_categories = $defaultCategories;
    $category   = trim((string)($_POST['category'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $email_creations = sales_request_extract_email_creations($_POST);
    $email_request_type = trim((string) ($_POST['email_request_type'] ?? ''));
    $email_creation_subsidiary = $email_creations[0]['subsidiary'] ?? trim((string) ($_POST['email_creation_subsidiary'] ?? ''));
    $email_creation_target_department = $email_creations[0]['target_department'] ?? trim((string) ($_POST['email_creation_target_department'] ?? ''));
    $email_creation_name = $email_creations[0]['name'] ?? trim((string) ($_POST['email_creation_name'] ?? ''));
    $email_creation_department = $email_creations[0]['department'] ?? trim((string) ($_POST['email_creation_department'] ?? ''));
    $email_creation_designation = $email_creations[0]['designation'] ?? trim((string) ($_POST['email_creation_designation'] ?? ''));
    $request_subject_title = trim((string)($_POST['request_subject_title'] ?? ''));
    $hr_concern_type = trim((string)($_POST['hr_concern_type'] ?? ''));
    $hr_concern_type_other = trim((string)($_POST['hr_concern_type_other'] ?? ''));
    $priority_selected = trim((string)($_POST['priority'] ?? ''));
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
    $requested_materials = sales_request_clean_string_array($_POST['requested_materials'] ?? []);
    $requested_materials_other = trim((string) ($_POST['requested_materials_other'] ?? ''));
    $material_size_unit = trim((string) ($_POST['material_size_unit'] ?? ''));
    $material_size_value = trim((string) ($_POST['material_size_value'] ?? ''));
    $material_size = ($material_size_unit !== '' && $material_size_value !== '')
        ? $material_size_unit . ': ' . $material_size_value
        : trim((string) ($_POST['material_size'] ?? ''));
    $project_deadline = trim((string) ($_POST['project_deadline'] ?? ''));
    $crop = sales_request_clean_string_array($_POST['crop'] ?? []);
    $crop_other = trim((string) ($_POST['crop_other'] ?? ''));
    $sap_reports = sales_request_extract_sap_reports($_POST);
    $sap_name = $sap_reports[0]['name'] ?? trim((string) ($_POST['sap_name'] ?? ''));
    $sap_position = $sap_reports[0]['position'] ?? trim((string) ($_POST['sap_position'] ?? ''));
    $sap_address = $sap_reports[0]['address'] ?? trim((string) ($_POST['sap_address'] ?? ''));
    $sap_department = $sap_reports[0]['department'] ?? trim((string) ($_POST['sap_department'] ?? ''));
    $sap_tin = $sap_reports[0]['tin'] ?? trim((string) ($_POST['sap_tin'] ?? ''));

    $name = $full_name !== '' ? $full_name : derive_name_from_email($email);
    $company = $company_id;
    $department = 'Sales';
    $priority = 'Low';
    $subject = $category !== '' ? ($category . ' Concern') : 'Sales Ticket';
    $normalized_company_id = normalize_sales_recipient_company($company_id);
    $allowed_categories = in_array($normalized_company_id, $othersOnlyCompanyDomains, true)
        ? ['Others']
        : (($normalized_company_id === '@malvedaproperties.com')
        ? $mpdcCategories
        : (($normalized_company_id === '@lingapleads.org')
            ? $lingapCategories
        : (($normalized_company_id === '@malvedaholdings.com' && isset($mhcDepartmentCategories[$assigned_department_selected]))
            ? $mhcDepartmentCategories[$assigned_department_selected]
        : (($normalized_company_id === '@leadsagri.com' && isset($lapcDepartmentCategories[$assigned_department_selected]))
            ? $lapcDepartmentCategories[$assigned_department_selected]
            : $defaultCategories))));
    $isLapcRecipient = ($normalized_company_id === '@leadsagri.com');
    $isMhcRecipient = ($normalized_company_id === '@malvedaholdings.com');
    $requiresDepartment = ticket_company_requires_department($normalized_company_id);
    $assigned_department = $requiresDepartment ? $assigned_department_selected : 'IT';
    $assigned_company = $normalized_company_id;
    $assigned_group = $requiresDepartment ? trim($assigned_department_selected) : 'IT';
    $isLapcHrRecipient = $isLapcRecipient && $assigned_department_selected === 'HR';
    $isLapcItRecipient = $isLapcRecipient && $assigned_department_selected === 'IT';
    $isMhcMarketingRecipient = $isMhcRecipient && $assigned_department_selected === 'Marketing Creatives';
    $isHrAttendanceCategory = ($isLapcHrRecipient && $category === 'Attendance & Timekeeping');
    $isHrLeaveOrOtherCategory = ($isLapcHrRecipient && ($category === 'Leave Concern' || $category === 'Others'));
    $isHrSssCategory = ($isLapcHrRecipient && $category === 'SSS Sickness and Benefit Concern');
    $isHrMedicalCashAdvance = ($isLapcHrRecipient && $category === 'Medical Cash Advance');
    $isHrTrainingRequest = ($isLapcHrRecipient && $category === 'Training Request');
    $isHrCompanyPropertyRequest = ($isLapcHrRecipient && $category === 'Request for Company Property');
    $isHrCertificateEmploymentRequest = ($isLapcHrRecipient && $category === 'Certificate of Employment');
    $isHrCertificateLeaveRequest = ($isLapcHrRecipient && $category === 'Certificate of Leave');
    $isHrIncidentReport = ($isLapcHrRecipient && $category === 'Incident Report');
    $isLapcItEmailRequest = ($isLapcItRecipient && $category === 'Email');
    $isLapcItSapRequest = ($isLapcItRecipient && $category === 'SAP');
    $isLapcMarketingTicket = ($isLapcRecipient && $assigned_department_selected === 'Marketing' && ($category === 'Marketing Operations' || $category === 'Channel & Campaigns'));
    $isLapcSupplyChainTicket = ($isLapcRecipient && $assigned_department_selected === 'Supply Chain' && isset($lapcSupplyChainRequestTypes[$category]));
    $isLapcAdminLegalRecipient = ($isLapcRecipient && $assigned_department_selected === 'Admin & Legal');
    if ($isLapcAdminLegalRecipient && $admin_legal_request_for === 'Others') {
        $category = 'Others';
        $subject = 'Others Concern';
    }
    if ($isLapcAdminLegalRecipient && isset($lapcAdminLegalRequestCategories[$admin_legal_request_for])) {
        $allowed_categories = $lapcAdminLegalRequestCategories[$admin_legal_request_for];
    }
    $requiresKamiAttachment = $isHrAttendanceCategory;
    if ($requiresDepartment) {
        $assigned_user_ids = ticket_find_assignee_ids($conn, $assigned_company, $assigned_group);
    } else {
        $assigned_user_ids = find_sales_domain_recipient_ids($conn, $assigned_company);
    }
    $assigned_user_id = count($assigned_user_ids) > 0 ? (int) $assigned_user_ids[0] : null;
    $allowedDepartments = $requiresDepartment
        ? ticket_company_allowed_groups($assigned_company)
        : [];
    if ($requiresDepartment && $assigned_department === '') {
        $error_msg = "Please select a department.";
    } elseif ($requiresDepartment && !in_array($assigned_department, $allowedDepartments, true)) {
        $error_msg = "Invalid department selected.";
    }
    if ($error_msg === '') {
        if ($assigned_company === '' || !ticket_is_valid_company($assigned_company)) {
            $error_msg = "Ticket Recipient (Company Email Domain) is required.";
        } elseif (!ticket_receiving_is_company_enabled($conn, $assigned_company)) {
            $error_msg = "The selected company is not currently accepting new tickets.";
        } elseif ($requiresDepartment && ($assigned_group === '' || !ticket_is_valid_group_for_company($assigned_company, $assigned_group))) {
            $error_msg = "Invalid department selected for the chosen recipient.";
        } elseif ($requiresDepartment && !ticket_receiving_is_department_enabled($conn, $assigned_company, $assigned_group)) {
            $error_msg = "The selected department is not currently accepting new tickets.";
        } elseif (!$assigned_user_id) {
            $error_msg = $requiresDepartment
                ? "No assignee available for the selected recipient and department."
                : "No assignee available for the selected recipient.";
        }
    }
    if ($error_msg === '' && $isLapcAdminLegalRecipient) {
        if ($admin_legal_request_for === '') {
            $error_msg = "Please choose who the request is for.";
        } elseif (!isset($lapcAdminLegalRequestCategories[$admin_legal_request_for])) {
            $error_msg = "Invalid request for selected.";
        }
    }
    if ($error_msg === '') {
        if ($category === '') {
            $error_msg = "Please choose a category.";
        } elseif (!in_array($category, $allowed_categories, true)) {
            $error_msg = "Invalid category selected.";
        }
    }
    if ($error_msg === '') {
        if ($priority_selected === '') {
            $error_msg = "Please choose the level of urgency.";
        } elseif (!in_array($priority_selected, ['Low', 'Medium', 'High'], true)) {
            $error_msg = "Invalid level of urgency selected.";
        } else {
            $priority = $priority_selected;
        }
    }
    if ($error_msg === '' && $isHrAttendanceCategory && $hr_concern_type === '') {
        $error_msg = "Please choose the type of concern.";
    }
    if ($error_msg === '' && $isHrAttendanceCategory && $hr_concern_type === 'Other' && $hr_concern_type_other === '') {
        $error_msg = "Please enter the type of concern.";
    }
    if ($error_msg === '' && $isLapcItEmailRequest) {
        $allowedEmailRequestTypes = ['creation of email', 'forgot password', 'backup of email'];
        if (!in_array($email_request_type, $allowedEmailRequestTypes, true)) {
            $error_msg = "Please choose the email request type.";
        } elseif ($email_request_type === 'creation of email' && count($email_creations) === 0) {
            $error_msg = "Please complete the Creation of email details.";
        } elseif ($email_request_type === 'creation of email') {
            foreach ($email_creations as $email_creation) {
                if (
                    $email_creation['subsidiary'] === ''
                    || $email_creation['name'] === ''
                    || $email_creation['department'] === ''
                    || $email_creation['designation'] === ''
                ) {
                    $error_msg = "Please complete each Creation of email card before submitting.";
                    break;
                }
            }
        }
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
    if ($error_msg === '' && $isLapcMarketingTicket && !in_array($marketing_subcategory, $lapcMarketingSubcategories[$category] ?? [], true)) {
        $error_msg = "Please choose the Request Type.";
    }
    if ($error_msg === '' && $isLapcSupplyChainTicket && !in_array($marketing_subcategory, $lapcSupplyChainRequestTypes[$category] ?? [], true)) {
        $error_msg = "Please choose a valid Request Type / Concern.";
    }
    if ($error_msg === '' && $isLapcSupplyChainTicket) {
        foreach ($lapcSupplyChainDetailFields[$category] as $fieldLabel) {
            if (in_array($fieldLabel, ['Supporting Photo', 'Supporting Documents'], true)) continue;
            if (trim((string) ($supply_chain_details[$fieldLabel] ?? '')) === '') {
                $error_msg = "Please complete all Supply Chain request details.";
                break;
            }
        }
    }
    if ($error_msg === '' && $isHrLeaveOrOtherCategory) {
        if ($request_subject_title === '') {
            $error_msg = "Please enter the subject/title of request.";
        } else {
            $subject = $request_subject_title;
        }
    }
    if ($error_msg === '' && $isHrMedicalCashAdvance) {
        if ($medical_cash_purpose === '' || $medical_cash_amount === '' || $medical_cash_date_needed === '') {
            $error_msg = "Please complete the Medical Cash Advance form.";
        } else {
            $subject = 'Medical Cash Advance';
            $description = "Medical Cash Advance Request\n"
                . "Purpose: " . $medical_cash_purpose . "\n"
                . "Amount: " . $medical_cash_amount . "\n"
                . "Date Needed: " . $medical_cash_date_needed;
        }
    }
    if ($error_msg === '' && $isHrTrainingRequest) {
        if (
            $training_request_title === ''
            || $training_request_provider === ''
            || $training_request_start_date === ''
            || $training_request_end_date === ''
            || $training_request_venue === ''
            || $training_request_fee === ''
        ) {
            $error_msg = "Please complete the Training Request form.";
        } elseif (strtotime($training_request_end_date) < strtotime($training_request_start_date)) {
            $error_msg = "End date cannot be earlier than start date.";
        } else {
            $subject = 'Training Request';
            $description = "Training Request Form\n"
                . "Training/Seminar Title: " . $training_request_title . "\n"
                . "Provider/Organizer: " . $training_request_provider . "\n"
                . "Start Date of Training/Seminar: " . $training_request_start_date . "\n"
                . "End Date of Training/Seminar: " . $training_request_end_date . "\n"
                . "Venue of Training/Seminar: " . $training_request_venue . "\n"
                . "Registration Fee: " . $training_request_fee;
        }
    }
    if ($error_msg === '' && $isHrCompanyPropertyRequest) {
        $allowedPropertyTypes = ['Company ID', 'Company Lanyard', 'Company Uniform', 'Business Card'];
        $allowedPropertyReasons = ['Lost', 'Replacement', 'No issuance'];
        if (!in_array($company_property_type, $allowedPropertyTypes, true) || !in_array($company_property_reason, $allowedPropertyReasons, true)) {
            $error_msg = "Please complete the Request for Company Property form.";
        } else {
            $subject = 'Request for Company Property';
            $description = "Request for Company Property\n"
                . "Type of Company Property: " . $company_property_type . "\n"
                . "Reason of Request: " . $company_property_reason;
        }
    }
    if ($error_msg === '' && $isHrCertificateEmploymentRequest) {
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
            $error_msg = "Please complete the Certificate of Employment form.";
        } else {
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
    }
    if ($error_msg === '' && $isHrCertificateLeaveRequest) {
        $allowedCertificateLeavePurposes = ['Travel', 'Others'];
        if (
            $certificate_leave_date === ''
            || !in_array($certificate_leave_purpose, $allowedCertificateLeavePurposes, true)
            || ($certificate_leave_purpose === 'Others' && $certificate_leave_purpose_other === '')
        ) {
            $error_msg = "Please complete the Certificate of Leave form.";
        } else {
            $subject = 'Certificate of Leave';
            $certificateLeavePurposeLabel = $certificate_leave_purpose === 'Others'
                ? $certificate_leave_purpose_other
                : $certificate_leave_purpose;
            $description = "Certificate of Leave Request Form\n"
                . "Date of Leave: " . $certificate_leave_date . "\n"
                . "Purpose of Leave: " . $certificateLeavePurposeLabel;
        }
    }
    if ($error_msg === '' && $isHrIncidentReport) {
        if ($incident_summary === '') {
            $error_msg = "Please complete the Incident Report form.";
        } else {
            $subject = 'Incident Report';
            $description = "Incident Report\n"
                . "Short Summary of IR: " . $incident_summary;
            if ($incident_gdrive_link !== '') {
                $description .= "\nGdrive Link (Video): " . $incident_gdrive_link;
            }
        }
    }
    if ($error_msg === '' && $isLapcItSapRequest) {
        if (count($sap_reports) === 0) {
            $error_msg = "Please complete the SAP form.";
        } else {
            $sap_name = $sap_reports[0]['name'] ?? $sap_name;
            $sap_position = $sap_reports[0]['position'] ?? $sap_position;
            $sap_address = $sap_reports[0]['address'] ?? $sap_address;
            $sap_department = $sap_reports[0]['department'] ?? $sap_department;
            $sap_tin = $sap_reports[0]['tin'] ?? $sap_tin;
            foreach ($sap_reports as $sap_report) {
                if (
                    $sap_report['name'] === ''
                ) {
                    $error_msg = "Please complete each SAP employee report before submitting.";
                    break;
                }
            }
        }
        if ($error_msg === '') {
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
    }
    if ($error_msg === '' && $isMhcMarketingRecipient) {
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
            $error_msg = "Please complete the LAPC Marketing request form.";
        } else {
            $deadlineTimestamp = strtotime($project_deadline);
            if ($deadlineTimestamp === false || date('Y-m-d', $deadlineTimestamp) !== $project_deadline) {
                $error_msg = "Please select a valid project deadline.";
            } elseif (sales_request_is_weekend_date($project_deadline) || sales_request_working_days_between_today($project_deadline) < 3) {
                $minimumDeadline = sales_request_min_working_deadline(3);
                $error_msg = 'Project Deadline must be at least 3 working days from today. Earliest valid date is ' . date('F j, Y', strtotime($minimumDeadline)) . '.';
            } else {
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
        }
    }
    if ($error_msg === '' && $isHrSssCategory && $description === '') {
        $description = 'SSS Notification and Benefits Concern submission.';
    }
    if ($error_msg === '' && $isLapcMarketingTicket && $marketing_subcategory !== '') {
        $description = "Request Type: " . $marketing_subcategory . "\n\n" . $description;
    }
    if ($error_msg === '' && $isLapcSupplyChainTicket && $marketing_subcategory !== '') {
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
    if ($error_msg === '' && $isLapcItEmailRequest && $email_request_type === 'creation of email') {
        $subject = 'Creation of email';
        $description = "Email Request\n"
            . "Email Request Type: Creation of email";
        foreach ($email_creations as $index => $email_creation) {
            $description .= "\n\nEmail Details " . ($index + 1) . "\n"
                . "Name: " . $email_creation['name'] . "\n"
                . "Designation: " . $email_creation['designation'] . "\n"
                . "Company: " . $email_creation['subsidiary'] . "\n"
                . "Department: " . $email_creation['department'];
        }
    }
    if ($error_msg === '' && ($requiresKamiAttachment || $isHrMedicalCashAdvance || $isHrIncidentReport)) {
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
                $error_msg = "Supporting Information is required for Medical Cash Advance.";
            } elseif ($isHrIncidentReport) {
                $error_msg = "Attachment is required for Incident Report.";
            } else {
                $error_msg = "Attachment is required for Attendance & Timekeeping.";
            }
        }
    }

    $attachmentName = null;
    $uploadedFiles = [];

    if ($error_msg === '' && isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $attachmentUploadResult = sales_request_process_upload_field(
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
                'doc' => ['application/msword', 'application/vnd.ms-word', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            ],
            5 * 1024 * 1024,
            'Please insert supported files only.',
            'Attachment too large. Maximum total size is 5 MB.'
        );

        if (empty($attachmentUploadResult['ok'])) {
            $error_msg = trim((string) ($attachmentUploadResult['error'] ?? 'Attachment upload failed.'));
        } else {
            foreach ((array) ($attachmentUploadResult['files'] ?? []) as $uploadedAttachmentFile) {
                $uploadedFiles[] = $uploadedAttachmentFile;
                if ($attachmentName === null) {
                    $attachmentName = (string) ($uploadedAttachmentFile['stored_name'] ?? '');
                }
            }
        }
    }

    if ($attachmentName === null && count($uploadedFiles) > 0) {
        $attachmentName = $uploadedFiles[0]['stored_name'];
    }

    if ($error_msg === '' && $isHrSssCategory) {
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
            $uploadResult = sales_request_process_upload_field(
                (string) $config['field'],
                (string) $config['label'],
                !empty($config['required']),
                (int) $config['max_files'],
                10 * 1024 * 1024,
                $sssAllowedTypes,
                $sssAllowedMimes
            );

            if (empty($uploadResult['ok'])) {
                sales_request_cleanup_uploaded_files($uploadedFiles);
                $error_msg = trim((string) ($uploadResult['error'] ?? 'Please complete the required SSS attachments.'));
                break;
            }

            foreach ((array) ($uploadResult['files'] ?? []) as $uploadedSssFile) {
                $uploadedFiles[] = $uploadedSssFile;
                if ($attachmentName === null) {
                    $attachmentName = (string) ($uploadedSssFile['stored_name'] ?? '');
                }
            }
        }
    }

    

    $sales_email = 'sales_guest@leadsagri.com';
    $user_id = null;
    $requesterLookupEmail = strtolower(trim($email));
    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $requesterLookupEmail);
        $stmt->execute();
        $stmt->bind_result($found_user_id);
        if ($stmt->fetch()) {
            $user_id = (int) $found_user_id;
        }
        $stmt->close();
    } else {
        if ($error_msg === '') $error_msg = "System error. Please try again later.";
    }

    if ($error_msg === '' && empty($user_id)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $sales_email);
            $stmt->execute();
            $stmt->bind_result($found_user_id);
            if ($stmt->fetch()) {
                $user_id = (int) $found_user_id;
            }
            $stmt->close();
        } else {
            $error_msg = "System error. Please try again later.";
        }
    }

    if ($error_msg === '' && empty($user_id)) {
        $guest_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $guest_name = 'Sales Department';
        $guest_company = 'Sales';
        $guest_department = 'SALES';
        $guest_role = 'employee';
        $guest_otp = '000000';
        $guest_verified = 1;

        $insert_stmt = $conn->prepare("
            INSERT INTO users (name, email, company, department, password, role, otp_code, is_verified)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($insert_stmt) {
            $insert_stmt->bind_param(
                "sssssssi",
                $guest_name,
                $sales_email,
                $guest_company,
                $guest_department,
                $guest_pass,
                $guest_role,
                $guest_otp,
                $guest_verified
            );
            if ($insert_stmt->execute()) {
                $user_id = (int) $insert_stmt->insert_id;
            } else {
                $error_msg = "System error. Please try again later.";
            }
            $insert_stmt->close();
        } else {
            $error_msg = "System error. Please try again later.";
        }
    }

    
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "A valid email is required.";
    } elseif ($sales_position === '' || !in_array($sales_position, $salesPositionOptions, true)) {
        $error_msg = "Please choose a position.";
    } elseif ($sales_region === '' || !in_array($sales_region, $salesRegionOptions, true)) {
        $error_msg = "Please choose a region.";
    } elseif ($company_id === '' || !in_array($company_id, $requestTicketCompanies, true)) {
        $error_msg = "Ticket Recipient (Company Email Domain) is required.";
    } elseif ($category === '' || !in_array($category, $allowed_categories, true)) {
        $error_msg = "Category is required.";
    } elseif (($isLapcMarketingTicket && !in_array($marketing_subcategory, $lapcMarketingSubcategories[$category] ?? [], true)) || ($isLapcSupplyChainTicket && !in_array($marketing_subcategory, $lapcSupplyChainRequestTypes[$category] ?? [], true))) {
        $error_msg = "Please choose the Request Type.";
    } elseif ($description === '' && !$isLapcSupplyChainTicket && !($isLapcItEmailRequest && $email_request_type === 'creation of email')) {
        $error_msg = "Description is required.";
    }
 
    
    if ($error_msg === '' && $isLapcAdminLegalRecipient && $admin_legal_request_for !== '') {
        $description = "Request For: " . $admin_legal_request_for . "\n" . $description;
    }

    if ($error_msg === '') {
        $description = trim("Position: " . $sales_position . "\nRegion: " . $sales_region . "\n\n" . $description);
    }

    $raw_description = $description;
    $full_description = "REQUESTER NAME: $name\nREQUESTER EMAIL: $email\n\nDESCRIPTION:\n$description";

    

    if (empty($error_msg)) {
        $has_requester_cols = true;
        $cols_to_ensure = [
            'requester_name' => "VARCHAR(255) NULL",
            'requester_email' => "VARCHAR(255) NULL"
        ];

        foreach ($cols_to_ensure as $col => $ddl) {
            $colRes = $conn->query("SHOW COLUMNS FROM employee_tickets LIKE '$col'");
            if (!$colRes || $colRes->num_rows === 0) {
                $alterOk = $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
                if (!$alterOk) {
                    $has_requester_cols = false;
                    break;
                }
            }
        }

        if ($has_requester_cols) {
            $stmt = $conn->prepare("
                INSERT INTO employee_tickets
                (user_id, subject, category, priority, company, department, assigned_department, assigned_company, assigned_group, assigned_user_id, requester_name, requester_email, description, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO employee_tickets
                (user_id, subject, category, priority, company, department, assigned_department, assigned_company, assigned_group, assigned_user_id, description, attachment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
        }

        if(!$stmt){
            $error_msg = "System error. Please try again later.";
        } else {
            if ($has_requester_cols) {
                $stmt->bind_param(
                    "issssssssissss",
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
                    $name,
                    $email,
                    $raw_description,
                    $attachmentName
                );
            } else {
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
                    $full_description,
                    $attachmentName
                );
            }

            if($stmt->execute()){
                $ticket_id = (int) $stmt->insert_id;
                $success_msg = "Ticket successfully submitted! An admin will review it shortly.";

                $ticket_number = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
                $initialAssignmentLabel = notif_assignment_target_label((string) $assigned_company, (string) $assigned_department, 'the selected recipient');
                ticket_record_activity($conn, $ticket_id, 'assignment_created', 'Assigned to ' . $initialAssignmentLabel);

                sales_request_meta_ensure_table($conn);
                $ticketMeta = [];
                if ($isLapcAdminLegalRecipient && $admin_legal_request_for !== '') {
                    $ticketMeta['admin_legal_request_for'] = $admin_legal_request_for;
                }
                if ($isLapcHrRecipient && $hr_concern_type !== '') {
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
                if ($isHrIncidentReport && $incident_gdrive_link !== '') {
                    $ticketMeta['incident_gdrive_link'] = $incident_gdrive_link;
                }
                if ($isMhcMarketingRecipient) {
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

                $notifTargetLabel = notif_assignment_target_label((string) $assigned_company, (string) $assigned_department, 'the selected recipient');
                $employeeTicketNotifMsg = "New ticket #$ticket_number from $name was assigned to your group.";
                $adminTicketNotifMsg = "New ticket #$ticket_number from $name was assigned to $notifTargetLabel.";
                notif_insert_admins($conn, $ticket_id, $adminTicketNotifMsg, 'new_ticket');

                foreach ($assigned_user_ids as $notifyUserId) {
                    $notifyUserId = (int) $notifyUserId;
                    if ($notifyUserId <= 0) continue;
                    notif_insert_system($conn, $notifyUserId, $ticket_id, $employeeTicketNotifMsg, 'dept_assigned');
                }

                finish_ticket_submit_response($isAjax, [
                    'ok' => true,
                    'message' => $success_msg,
                    'ticket_id' => (int) $ticket_id,
                    'ticket_number' => (string) $ticket_number,
                    'email_delivery_pending' => true,
                ]);

                $creatorEmail = '';
                $creatorName = '';
                $creatorStmt = $conn->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
                if ($creatorStmt) {
                    $creatorStmt->bind_param("i", $user_id);
                    $creatorStmt->execute();
                    $creatorRes = $creatorStmt->get_result();
                    $creatorRow = $creatorRes ? $creatorRes->fetch_assoc() : null;
                    $creatorStmt->close();
                    if ($creatorRow) {
                        $creatorName = trim((string) ($creatorRow['name'] ?? ''));
                        $creatorEmail = trim((string) ($creatorRow['email'] ?? ''));
                    }
                }

                $usesSpecificEmailRoute = ticket_uses_specific_email_route($assigned_company, (string) $assigned_group);
                $adminEmails = [];
                $emailFailureGroups = [];
                if (!$usesSpecificEmailRoute && count($adminEmails) === 0) {
                    $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
                    if ($admins) {
                        while ($admin = $admins->fetch_assoc()) {
                            $adminEmails[] = $admin['email'];
                        }
                    }
                }

                $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
                $subjectLine = "Ticket Submitted (#$ticketNumber)";
                $assignedRecipientLabel = ticket_company_display_name((string) $assigned_company);
                if ($assignedRecipientLabel === '') {
                    $assignedRecipientLabel = (string) $assigned_company;
                }

                $attachments = notif_ticket_email_attachments($conn, $ticket_id, (string) ($attachmentName ?? ''));
                $attachmentSummary = notif_ticket_attachment_summary($attachments);
                $emailTicketDescription = sales_request_clean_email_description($raw_description);

                $adminTpl = notif_email_simple('Ticket Submitted', [
                    "Ticket ID: #$ticketNumber",
                    "Title: $subject",
                    "Category: $category",
                    "Current Status: $ticketStatus",
                    "Full Name: $name",
                    "Requester Email: $email",
                    "Assigned Recipient: $assignedRecipientLabel"
                ], 'Open Ticket', notif_ticket_link_admin($ticket_id));
                if ($requiresDepartment) {
                    $adminTpl = notif_email_simple('Ticket Submitted', [
                        "Ticket ID: #$ticketNumber",
                        "Title: $subject",
                        "Category: $category",
                        "Current Status: $ticketStatus",
                        "Full Name: $name",
                        "Requester Email: $email",
                        "Assigned Department: $assigned_department",
                        "Assigned Recipient: $assignedRecipientLabel"
                    ], 'Open Ticket', notif_ticket_link_admin($ticket_id));
                }
                if (count($adminEmails) > 0) {
                    $adminOk = notif_email_send($adminEmails, $subjectLine, (string) $adminTpl['html'], (string) $adminTpl['text'], $attachments);
                    sales_email_debug_log([
                        'event' => 'sales_ticket_admin_email',
                        'ticket_id' => (int) $ticket_id,
                        'creator_user_id' => (int) $user_id,
                        'creator_email' => $creatorEmail,
                        'requester_email' => $email,
                        'assigned_department' => (string) $assigned_department,
                        'assigned_company' => (string) $assigned_company,
                        'recipients' => sales_clean_email_list($adminEmails),
                        'success' => $adminOk,
                        'error' => $adminOk ? '' : (function_exists('smtp_last_error') ? smtp_last_error() : ''),
                    ]);
                    if (!$adminOk) {
                        error_log('Sales ticket email failed (admins) | ticketId=' . (string) $ticket_id . ' recipients=' . implode(',', $adminEmails));
                        $emailFailureGroups[] = 'admins';
                    }
                }

                $assigneeEmails = ticket_assignee_notification_emails($conn, $assigned_user_ids, $assigned_company, (string) $assigned_group, (int) $user_id);
                $ticketSubmittedAt = date('M d, Y h:i A');

                if (count($assigneeEmails) > 0) {
                    $assigneeLines = [
                        "Ticket ID: #$ticketNumber",
                        "Category: $category",
                        "Requestor: $name",
                        "Email: $email",
                        "Position: $sales_position",
                        "Region: $sales_region",
                        "Date Submitted: $ticketSubmittedAt",
                        "Level of Urgency: $priority",
                        "Description:\n$emailTicketDescription"
                    ];
                    if ($attachmentSummary !== '') {
                        $assigneeLines[] = $attachmentSummary;
                    }
                    $assigneeTpl = notif_email_simple('New Ticket Assigned', $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks($ticket_id));
                    $assigneeOk = notif_email_send($assigneeEmails, "New Ticket Assigned (#$ticketNumber)", (string) $assigneeTpl['html'], (string) $assigneeTpl['text'], $attachments);
                    sales_email_debug_log([
                        'event' => 'sales_ticket_assignee_email',
                        'ticket_id' => (int) $ticket_id,
                        'creator_user_id' => (int) $user_id,
                        'creator_email' => $creatorEmail,
                        'requester_email' => $email,
                        'assigned_department' => (string) $assigned_department,
                        'assigned_company' => (string) $assigned_company,
                        'recipients' => sales_clean_email_list($assigneeEmails),
                        'success' => $assigneeOk,
                        'error' => $assigneeOk ? '' : (function_exists('smtp_last_error') ? smtp_last_error() : ''),
                    ]);
                    if (!$assigneeOk) {
                        error_log('Sales ticket email failed (assignees) | ticketId=' . (string) $ticket_id . ' recipients=' . implode(',', $assigneeEmails));
                        $emailFailureGroups[] = 'assignees';
                    }
                } else {
                    sales_email_debug_log([
                        'event' => 'sales_ticket_assignee_email',
                        'ticket_id' => (int) $ticket_id,
                        'creator_user_id' => (int) $user_id,
                        'creator_email' => $creatorEmail,
                        'requester_email' => $email,
                        'assigned_department' => (string) $assigned_department,
                        'assigned_company' => (string) $assigned_company,
                        'recipients' => [],
                        'success' => false,
                        'error' => 'No assignee email recipients found',
                    ]);
                }

                $requesterLines = [
                    "Ticket ID: #$ticketNumber",
                    "Category: $category",
                    "Requestor: $name",
                    "Email: $email",
                    "Position: $sales_position",
                    "Region: $sales_region",
                    "Date Submitted: $ticketSubmittedAt",
                    "Level of Urgency: $priority",
                    "Description:\n$emailTicketDescription"
                ];
                if ($attachmentSummary !== '') {
                    $requesterLines[] = $attachmentSummary;
                }
                $requesterTpl = notif_email_simple('Ticket Submitted', $requesterLines, 'View Ticket', notif_base_url() . '/ticketing/index.php');
                $requesterEmails = sales_clean_email_list([$creatorEmail, $email]);
                $requesterOk = false;
                if (count($requesterEmails) > 0) {
                    $requesterOk = notif_email_send($requesterEmails, "Ticket Submitted (#$ticketNumber)", (string) $requesterTpl['html'], (string) $requesterTpl['text'], $attachments);
                }
                sales_email_debug_log([
                    'event' => 'sales_ticket_requester_email',
                    'ticket_id' => (int) $ticket_id,
                    'creator_user_id' => (int) $user_id,
                    'creator_email' => $creatorEmail,
                    'requester_email' => $email,
                    'assigned_department' => (string) $assigned_department,
                    'assigned_company' => (string) $assigned_company,
                    'recipients' => $requesterEmails,
                    'success' => $requesterOk,
                    'error' => $requesterOk ? '' : (function_exists('smtp_last_error') ? smtp_last_error() : 'No requester email recipient'),
                ]);
                if (!$requesterOk) {
                    error_log('Sales ticket email failed (requester) | ticketId=' . (string) $ticket_id . ' recipient=' . implode(',', $requesterEmails));
                    $emailFailureGroups[] = 'requester';
                }

                if ($isAjax) {
                    exit;
                }

            } else {
                $error_msg = "Failed to submit ticket: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}

if ($isAjax && $_SERVER["REQUEST_METHOD"] == "POST") {
    header('Content-Type: application/json; charset=utf-8');
    if ($success_msg !== '') {
        echo json_encode([
            'ok' => true,
            'message' => $success_msg,
            'ticket_id' => isset($ticket_id) ? (int) $ticket_id : 0,
            'ticket_number' => isset($ticket_number) ? (string) $ticket_number : (isset($ticket_id) ? str_pad((string) ((int) $ticket_id), 6, '0', STR_PAD_LEFT) : '')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $error_msg !== '' ? $error_msg : 'Failed to submit ticket.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sapFormEntries = sales_request_extract_sap_reports($_POST);
if (count($sapFormEntries) === 0) {
    $sapFormEntries = [sales_request_blank_sap_report()];
}
$emailCreationEntries = sales_request_extract_email_creations($_POST);
if (count($emailCreationEntries) === 0) {
    $emailCreationEntries = [sales_request_blank_email_creation()];
}
$normalized_company_id = $selectedRecipientCompany;
$initialSalesCategoryOptions = $defaultCategories;
if (in_array($normalized_company_id, $othersOnlyCompanyDomains, true)) {
    $initialSalesCategoryOptions = ['Others'];
} elseif ($normalized_company_id === '@malvedaproperties.com') {
    $initialSalesCategoryOptions = $mpdcCategories;
} elseif ($normalized_company_id === '@lingapleads.org') {
    $initialSalesCategoryOptions = $lingapCategories;
} elseif ($normalized_company_id === '@malvedaholdings.com' && isset($mhcDepartmentCategories[$selectedRecipientDepartment])) {
    $initialSalesCategoryOptions = $mhcDepartmentCategories[$selectedRecipientDepartment];
} elseif (
    $normalized_company_id === '@leadsagri.com'
    && $selectedRecipientDepartment === 'Admin & Legal'
    && isset($lapcAdminLegalRequestCategories[$admin_legal_request_for])
) {
    $initialSalesCategoryOptions = $lapcAdminLegalRequestCategories[$admin_legal_request_for];
} elseif ($normalized_company_id === '@leadsagri.com' && isset($lapcDepartmentCategories[$selectedRecipientDepartment])) {
    $initialSalesCategoryOptions = $lapcDepartmentCategories[$selectedRecipientDepartment];
}
$initialSalesRoutingComplete = $selectedRecipientCompany !== ''
    && (!$initialShowDepartment || $selectedRecipientDepartment !== '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <link rel="shortcut icon" type="image/png" href="../assets/img/leads-favicon.png?v=3">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Ticket Request | Leads DeskMetamorph</title>
    <!-- Reuse existing CSS or inline minimal styles -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f3f4f6 url('../assets/img/kbkb.jpg') no-repeat -360px center fixed;
            background-size: cover;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
        }
        body.sales-request-ticket-page .sales-employee-navbar {
            background: linear-gradient(90deg, #1B5E20, #144a1e);
            border-bottom: 4px solid #F4C430;
        }
        body.sales-request-ticket-page .sales-employee-navbar .navbar-collapse {
            min-width: 0;
        }
        body.sales-request-ticket-page .sales-employee-navbar .nav-center {
            flex: 1 1 auto;
            justify-content: center;
            min-width: 0;
        }
        body.sales-request-ticket-page .sales-employee-navbar .nav-link {
            white-space: nowrap;
        }
        body.sales-request-ticket-page .sales-employee-navbar .nav-right {
            flex: 0 0 auto;
            justify-content: flex-end;
        }
        .sales-topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background:
                linear-gradient(0deg, rgba(20, 42, 23, 0.16), rgba(20, 42, 23, 0.16)),
                radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.05), transparent 38%),
                linear-gradient(135deg, #214f2a 0%, #1a4726 48%, #183f22 100%);
            border-bottom: 4px solid #d6a329;
            box-shadow: 0 14px 34px rgba(6, 24, 12, 0.22);
        }
        .sales-topbar-inner {
            width: 100%;
            margin: 0 auto;
            padding: 8px 22px 9px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-sizing: border-box;
        }
        .sales-brand-block {
            display: flex;
            align-items: center;
            gap: 18px;
            min-width: 0;
        }
        .sales-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 54px;
            height: 54px;
            flex: 0 0 54px;
        }
        .sales-logo img {
            height: 100%;
            width: 100%;
            object-fit: contain;
            background-color: #ffffff;
            padding: 8px;
            border-radius: 999px;
            box-shadow: 0 8px 18px rgba(6, 24, 12, 0.22);
            display: block;
            box-sizing: border-box;
        }
        .sales-brand-divider {
            width: 1px;
            height: 40px;
            background: rgba(233, 219, 174, 0.58);
            flex: 0 0 1px;
        }
        .sales-nav-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex: 0 0 auto;
            gap: 10px;
        }
        .sales-nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 42px;
            padding: 0 20px;
            color: #f8f6ee;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 0.01em;
            border-radius: 999px;
            border: 1px solid rgba(232, 223, 193, 0.34);
            background: rgba(255, 255, 255, 0.02);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
            transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.2s;
            white-space: nowrap;
        }
        .sales-nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #f6cf62;
            border-color: rgba(229, 191, 89, 0.55);
            transform: translateY(-1px);
        }
        .sales-nav-link-icon {
            color: #f6cf62;
            font-size: 16px;
            line-height: 1;
        }
        .sales-brand {
            display: flex;
            flex-direction: column;
            line-height: 1.08;
            align-items: flex-start;
            text-align: left;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        .sales-brand-title {
            font-weight: 700;
            letter-spacing: 0.01em;
            color: #f8f6ee;
            font-size: 17px;
            text-shadow: 0 1px 0 rgba(0, 0, 0, 0.12);
            line-height: 1.08;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        .sales-brand-subtitle {
            font-size: 13px;
            font-weight: 600;
            color: #e5bf59;
            margin-top: 4px;
            line-height: 1.08;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        @media (max-width: 1280px) {
            body.sales-request-ticket-page .sales-employee-navbar .nav-right {
                justify-content: center;
            }
        }
        @media (max-width: 768px) {
            body.sales-request-ticket-page .sales-employee-navbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 10px;
                padding: 12px 10px 10px;
            }
            body.sales-request-ticket-page .sales-employee-navbar .nav-left {
                width: 100%;
                min-width: 0;
                display: flex;
                align-items: center;
            }
            body.sales-request-ticket-page .sales-employee-navbar .navbar-toggler {
                display: none;
            }
            body.sales-request-ticket-page .sales-employee-navbar .navbar-collapse {
                display: flex;
                width: 100%;
                flex: 0 0 100%;
                margin: 0;
                padding: 0;
                border-top: 1px solid rgba(255, 255, 255, 0.12);
            }
            body.sales-request-ticket-page .sales-employee-navbar .nav-center {
                display: none;
            }
            body.sales-request-ticket-page .sales-employee-navbar .sales-nav-right {
                display: flex;
                width: 100%;
                gap: 8px;
                margin-top: 0;
                justify-content: stretch;
                padding-top: 10px;
            }
            body.sales-request-ticket-page .sales-employee-navbar .sales-nav-link {
                flex: 1 1 0;
                min-width: 0;
                width: auto;
                min-height: 38px;
                padding: 0 10px;
                gap: 7px;
                font-size: 12px;
                line-height: 1;
                white-space: nowrap;
            }
            body.sales-request-ticket-page .sales-employee-navbar .sales-nav-link-icon {
                font-size: 13px;
            }
            body.sales-request-ticket-page .sales-employee-navbar .brand-name {
                font-size: 16px;
            }
            .sales-topbar-inner {
                padding: 8px 12px;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .sales-brand-block {
                gap: 8px;
                align-items: center;
            }
            .sales-logo {
                width: 40px;
                height: 40px;
                flex: 0 0 40px;
            }
            .sales-logo img {
                height: 100%;
                width: 100%;
                padding: 4px;
            }
            .sales-brand-divider {
                height: 28px;
            }
            .sales-brand {
                min-width: 0;
            }
            .sales-brand-title {
                font-size: 15px;
                font-weight: 600;
                text-align: left;
                line-height: 1.08;
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
            }
            .sales-brand-subtitle {
                font-size: 11px;
                color: #FACC15;
                margin-top: 4px;
                text-align: left;
                line-height: 1.08;
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
            }
            .sales-nav-right {
                width: 100%;
                justify-content: stretch;
            }
            .sales-nav-link {
                width: 100%;
                max-width: none;
                justify-content: center;
                border-radius: 999px;
                min-height: 40px;
                padding: 0 14px;
                font-size: 12px;
            }
            .sales-nav-link:hover {
                color: #f6cf62;
                border-color: rgba(229, 191, 89, 0.55);
            }
            .sales-nav-link:active {
                transform: scale(0.98);
            }

            .sales-container {
                margin: 16px auto;
                padding: 22px 16px;
                border-radius: 16px;
                max-width: calc(100vw - 32px);
            }

            .sales-page-header { margin-bottom: 20px; }
            .sales-page-header h1 { font-size: 20px; margin-bottom: 6px; }
            .sales-page-header p { font-size: 14px; }

            .form-row {
                display: flex;
                flex-direction: column;
                gap: 16px;
                margin-bottom: 20px;
            }

            .form-row.two-col {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-grid { gap: 20px; }

            input, select, textarea {
                font-size: 16px;
                border-radius: 14px;
            }

            input, select {
                height: 54px;
            }

            textarea {
                min-height: 140px;
                padding: 14px;
            }

            .file-control {
                width: 100%;
                border: 2px dashed #d1d5db;
                padding: 18px;
                border-radius: 14px;
                background: #f9fafb;
                text-align: center;
                flex-direction: column;
                align-items: center;
                gap: 12px;
            }
            .file-control:hover {
                border-color: rgba(27, 94, 32, 0.45);
                background: #ffffff;
            }
            .file-button {
                width: 100%;
                height: 54px;
                justify-content: center;
                border-radius: 14px;
                padding: 0 14px;
            }
            .file-name {
                width: 100%;
                white-space: normal;
                text-align: center;
            }

            .form-actions {
                display: flex;
                flex-direction: row;
                gap: 12px;
                margin-top: 25px;
                align-items: stretch;
                justify-content: stretch;
            }
            .form-actions button,
            .form-actions .btn-back {
                width: auto;
                flex: 1 1 0;
                height: 48px;
                border-radius: 14px;
                padding: 0 16px;
                font-size: 15px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                text-align: center;
            }
            .form-actions .btn-back {
                flex: 0 0 44%;
            }
            .form-actions .submit-btn {
                flex: 1 1 auto;
            }
        }
        @media (max-width: 640px) {
            .form-row.two-col { grid-template-columns: 1fr; }
        }
        .required-asterisk {
            color: #dc2626;
        }
        body.sales-request-ticket-page,
        body.sales-request-ticket-page input,
        body.sales-request-ticket-page select,
        body.sales-request-ticket-page textarea,
        body.sales-request-ticket-page button,
        body.sales-request-ticket-page option {
            font-family: 'Segoe UI', sans-serif;
        }
        body.sales-request-ticket-page .request-grid-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            align-items: start;
        }
        body.sales-request-ticket-page .request-grid-row:not(.is-single) > .form-group {
            grid-column: auto;
        }
        body.sales-request-ticket-page #recipientRow:has(#departmentGroup:not(.hidden)) {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        body.sales-request-ticket-page #recipientRow:has(#departmentGroup:not(.hidden)) > .form-group {
            grid-column: auto;
        }
        body.sales-request-ticket-page .request-grid-row.is-single {
            grid-template-columns: 1fr;
        }
        body.sales-request-ticket-page .request-grid-row > .full-width {
            grid-column: 1 / -1;
        }
        body.sales-request-ticket-page #salesCategoryRow.is-admin-legal-layout #adminLegalRequestForContainer {
            order: 1;
        }
        body.sales-request-ticket-page #salesCategoryRow.is-admin-legal-layout #priorityGroup {
            order: 2;
        }
        body.sales-request-ticket-page #salesCategoryRow.is-admin-legal-layout #categoryContainer {
            order: 3;
            grid-column: 1 / -1;
        }
        body.sales-request-ticket-page .select-wrapper {
            position: relative;
        }
        body.sales-request-ticket-page .select-wrapper.recipient-dropdown,
        body.sales-request-ticket-page .select-wrapper.department-dropdown,
        body.sales-request-ticket-page .select-wrapper.admin-legal-request-for-dropdown,
        body.sales-request-ticket-page .select-wrapper.category-dropdown,
        body.sales-request-ticket-page .select-wrapper.email-request-type-dropdown,
        body.sales-request-ticket-page .select-wrapper.sales-position-dropdown,
        body.sales-request-ticket-page .select-wrapper.sales-region-dropdown,
        body.sales-request-ticket-page .select-wrapper.marketing-subcategory-dropdown,
        body.sales-request-ticket-page .select-wrapper.priority-dropdown {
            overflow: visible;
        }
        body.sales-request-ticket-page .select-wrapper .form-control,
        body.sales-request-ticket-page .select-wrapper select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            min-height: 50px;
            padding: 0 44px 0 16px;
            border: 2px solid #73a66f;
            border-radius: 16px;
            background: #ffffff;
            color: #334155;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            font-size: 15px;
            font-weight: 400;
            line-height: 1.4;
        }
        body.sales-request-ticket-page select.category-select option {
            font-weight: 400;
            color: #0f172a;
        }
        body.sales-request-ticket-page .select-wrapper .form-control:focus,
        body.sales-request-ticket-page .select-wrapper select:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .select-wrapper .select-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #374151;
            font-size: 14px;
            pointer-events: none;
        }
        body.sales-request-ticket-page .recipient-native-select,
        body.sales-request-ticket-page .department-native-select,
        body.sales-request-ticket-page .admin-legal-request-for-native-select,
        body.sales-request-ticket-page .category-native-select,
        body.sales-request-ticket-page .email-request-type-native-select,
        body.sales-request-ticket-page .sales-position-native-select,
        body.sales-request-ticket-page .sales-region-native-select,
        body.sales-request-ticket-page .marketing-subcategory-native-select,
        body.sales-request-ticket-page .priority-native-select {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger,
        body.sales-request-ticket-page .department-dropdown-trigger,
        body.sales-request-ticket-page .email-request-type-dropdown-trigger,
        body.sales-request-ticket-page .sales-position-dropdown-trigger,
        body.sales-request-ticket-page .sales-region-dropdown-trigger,
        body.sales-request-ticket-page .priority-dropdown-trigger {
            width: 100%;
            min-height: 50px;
            padding: 0 44px 0 16px;
            border: 2px solid #73a66f;
            border-radius: 16px;
            background: #ffffff;
            color: #334155;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            font-size: 15px;
            font-weight: 400;
            line-height: 1.4;
            text-align: left;
            cursor: pointer;
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .department-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .email-request-type-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .sales-position-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .sales-region-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .priority-dropdown-trigger:not(.is-placeholder) {
            font-weight: 400;
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger:focus,
        body.sales-request-ticket-page .department-dropdown-trigger:focus,
        body.sales-request-ticket-page .email-request-type-dropdown-trigger:focus,
        body.sales-request-ticket-page .sales-position-dropdown-trigger:focus,
        body.sales-request-ticket-page .sales-region-dropdown-trigger:focus,
        body.sales-request-ticket-page .priority-dropdown-trigger:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .department-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .email-request-type-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .sales-position-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .sales-region-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .priority-dropdown-trigger.is-placeholder {
            color: #334155;
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger:disabled,
        body.sales-request-ticket-page .department-dropdown-trigger:disabled,
        body.sales-request-ticket-page .email-request-type-dropdown-trigger:disabled,
        body.sales-request-ticket-page .sales-position-dropdown-trigger:disabled,
        body.sales-request-ticket-page .sales-region-dropdown-trigger:disabled,
        body.sales-request-ticket-page .priority-dropdown-trigger:disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }
        body.sales-request-ticket-page .select-wrapper.is-static .recipient-dropdown-trigger,
        body.sales-request-ticket-page .select-wrapper.is-static .department-dropdown-trigger {
            background: #ffffff;
            color: #111827;
            cursor: default;
            pointer-events: none;
        }
        body.sales-request-ticket-page .select-wrapper.is-static .select-icon {
            display: none;
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-trigger,
        body.sales-request-ticket-page .category-dropdown-trigger,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger,
        body.sales-request-ticket-page .concern-type-dropdown-trigger {
            width: 100%;
            min-height: 50px;
            padding: 0 44px 0 16px;
            border: 2px solid #73a66f;
            border-radius: 16px;
            background: #ffffff;
            color: #334155;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            font-size: 15px;
            font-weight: 400;
            line-height: 1.4;
            text-align: left;
            cursor: pointer;
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .category-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger:not(.is-placeholder),
        body.sales-request-ticket-page .concern-type-dropdown-trigger:not(.is-placeholder) {
            font-weight: 400;
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-trigger:focus,
        body.sales-request-ticket-page .category-dropdown-trigger:focus,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger:focus,
        body.sales-request-ticket-page .concern-type-dropdown-trigger:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .category-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .concern-type-dropdown-trigger.is-placeholder {
            color: #334155;
        }
        /* Keep Request Type neutral after a selection; it is not a validation state. */
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger:focus {
            border-color: #d7e0dc;
            box-shadow: none;
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-trigger:disabled,
        body.sales-request-ticket-page .category-dropdown-trigger:disabled,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger:disabled,
        body.sales-request-ticket-page .concern-type-dropdown-trigger:disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }
        body.sales-request-ticket-page .recipient-dropdown-menu,
        body.sales-request-ticket-page .department-dropdown-menu,
        body.sales-request-ticket-page .email-request-type-dropdown-menu,
        body.sales-request-ticket-page .sales-position-dropdown-menu,
        body.sales-request-ticket-page .sales-region-dropdown-menu,
        body.sales-request-ticket-page .priority-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 70;
            display: none;
            max-height: 280px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #d6e2d4;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.14);
        }
        body.sales-request-ticket-page .recipient-dropdown-menu.is-open,
        body.sales-request-ticket-page .department-dropdown-menu.is-open,
        body.sales-request-ticket-page .email-request-type-dropdown-menu.is-open,
        body.sales-request-ticket-page .sales-position-dropdown-menu.is-open,
        body.sales-request-ticket-page .sales-region-dropdown-menu.is-open,
        body.sales-request-ticket-page .priority-dropdown-menu.is-open {
            display: block;
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-menu,
        body.sales-request-ticket-page .category-dropdown-menu,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-menu,
        body.sales-request-ticket-page .concern-type-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 70;
            display: none;
            max-height: 280px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #d6e2d4;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.14);
        }
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-menu.is-open,
        body.sales-request-ticket-page .category-dropdown-menu.is-open,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-menu.is-open,
        body.sales-request-ticket-page .concern-type-dropdown-menu.is-open {
            display: block;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown {
            position: relative;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-native {
            position: absolute;
            inset: 0;
            opacity: 0;
            pointer-events: none;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-trigger {
            width: 100%;
            min-height: 50px;
            padding: 0 44px 0 16px;
            border: 1px solid #d4ddd7;
            border-radius: 16px;
            background: #ffffff;
            color: #0f172a;
            text-align: left;
            font-family: 'Segoe UI', sans-serif;
            font-size: inherit;
            font-weight: 400;
            cursor: pointer;
        }
        body.sales-request-ticket-page #marketingRequestSection .marketing-materials-dropdown-trigger > span {
            font-weight: 400;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-trigger:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .marketing-materials-dropdown.is-open .marketing-materials-dropdown-trigger {
            border-color: #1B5E20;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            z-index: 120;
            max-height: 280px;
            overflow-y: auto;
            padding: 8px 0;
            border: 2px solid #73a66f;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.14);
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-menu[hidden] {
            display: none;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-option {
            width: 100%;
            border: 0;
            padding: 11px 16px;
            background: transparent;
            color: #0f172a;
            text-align: left;
            font-family: 'Segoe UI', sans-serif;
            font-size: inherit;
            font-weight: 400;
            cursor: pointer;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-option:hover,
        body.sales-request-ticket-page .marketing-materials-dropdown-option:focus-visible {
            background: rgba(27, 94, 32, 0.08);
            color: #1B5E20;
            outline: none;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown-option.is-selected {
            border-radius: 12px;
            background: #1B5E20;
            color: #ffffff;
        }
        body.sales-request-ticket-page .marketing-materials-dropdown.is-open .select-icon {
            transform: translateY(-50%) rotate(180deg);
        }
        body.sales-request-ticket-page .recipient-dropdown-option,
        body.sales-request-ticket-page .department-dropdown-option,
        body.sales-request-ticket-page .email-request-type-dropdown-option,
        body.sales-request-ticket-page .sales-position-dropdown-option,
        body.sales-request-ticket-page .sales-region-dropdown-option,
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-option,
        body.sales-request-ticket-page .category-dropdown-option,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-option,
        body.sales-request-ticket-page .concern-type-dropdown-option,
        body.sales-request-ticket-page .priority-dropdown-option {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 12px;
            padding: 12px 14px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 400;
            text-align: left;
            cursor: pointer;
        }
        body.sales-request-ticket-page .recipient-dropdown-option:hover,
        body.sales-request-ticket-page .recipient-dropdown-option:focus,
        body.sales-request-ticket-page .department-dropdown-option:hover,
        body.sales-request-ticket-page .department-dropdown-option:focus,
        body.sales-request-ticket-page .email-request-type-dropdown-option:hover,
        body.sales-request-ticket-page .email-request-type-dropdown-option:focus,
        body.sales-request-ticket-page .sales-position-dropdown-option:hover,
        body.sales-request-ticket-page .sales-position-dropdown-option:focus,
        body.sales-request-ticket-page .sales-region-dropdown-option:hover,
        body.sales-request-ticket-page .sales-region-dropdown-option:focus,
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-option:hover,
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-option:focus,
        body.sales-request-ticket-page .category-dropdown-option:hover,
        body.sales-request-ticket-page .category-dropdown-option:focus,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-option:hover,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-option:focus,
        body.sales-request-ticket-page .concern-type-dropdown-option:hover,
        body.sales-request-ticket-page .concern-type-dropdown-option:focus,
        body.sales-request-ticket-page .priority-dropdown-option:hover,
        body.sales-request-ticket-page .priority-dropdown-option:focus {
            outline: none;
            background: #eef7ef;
        }

        body.sales-request-ticket-page .recipient-dropdown-option.is-selected,
        body.sales-request-ticket-page .department-dropdown-option.is-selected,
        body.sales-request-ticket-page .email-request-type-dropdown-option.is-selected,
        body.sales-request-ticket-page .sales-position-dropdown-option.is-selected,
        body.sales-request-ticket-page .sales-region-dropdown-option.is-selected,
        body.sales-request-ticket-page .admin-legal-request-for-dropdown-option.is-selected,
        body.sales-request-ticket-page .category-dropdown-option.is-selected,
        body.sales-request-ticket-page .marketing-subcategory-dropdown-option.is-selected,
        body.sales-request-ticket-page .concern-type-dropdown-option.is-selected,
        body.sales-request-ticket-page .priority-dropdown-option.is-selected {
            background: #1B5E20;
            color: #ffffff;
            font-weight: 400;
        }

        body.sales-request-ticket-page .category-dropdown-option.is-selected {
            background: #f8fafc;
            color: #0f172a;
            font-weight: 400;
        }
        body.sales-request-ticket-page .category-dropdown-option {
            width: 100%;
            border: 0;
            background: transparent;
            border-radius: 12px;
            padding: 12px 14px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 400;
            text-align: left;
            cursor: pointer;
        }
        body.sales-request-ticket-page .category-dropdown-option:hover,
        body.sales-request-ticket-page .category-dropdown-option:focus {
            outline: none;
            background: #eef7ef;
        }
        body.sales-request-ticket-page .category-dropdown-option.is-selected {
            background: #1B5E20;
            color: #ffffff;
            font-weight: 400;
        }
        .sales-container {
            max-width: 920px;
            margin: 24px auto;
            background: white;
            padding: 0 24px 24px;
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: visible;
        }
        .sales-page-header {
            text-align: center;
            margin-bottom: 0;
            padding: 32px 16px 26px;
        }
        .sales-page-header h1 {
            color: #1B5E20;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: 0;
            margin-bottom: 10px;
        }
        .sales-page-header p {
            color: #6b7280;
        }
        body.sales-request-ticket-page .form-card {
            padding: 0 24px 24px;
            overflow: visible;
            border-top: none !important;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            border-radius: 16px;
            background: #ffffff;
        }
        body.sales-request-ticket-page .form-section-title {
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
        .form-group {
            margin-bottom: 20px;
        }
        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .form-grid .form-group,
        .form-grid .form-row {
            width: 100%;
            margin-bottom: 0;
        }
        .form-grid input,
        .form-grid select,
        .form-grid textarea {
            width: 100%;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #73a66f;
            border-radius: 16px;
            font-size: 14px;
            box-sizing: border-box;
            background-color: #ffffff;
            color: #0f172a;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
        }
        input {
            height: 50px;
            padding: 0 16px;
        }
        body.sales-request-ticket-page .form-control {
            width: 100%;
            border: 2px solid #73a66f;
            border-radius: 16px;
            background-color: #ffffff;
            color: #334155;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            box-sizing: border-box;
            font-size: 15px;
            font-weight: 400;
            line-height: 1.4;
        }
        body.sales-request-ticket-page input.form-control,
        body.sales-request-ticket-page select.form-control {
            height: 50px;
            padding: 0 16px;
        }
        body.sales-request-ticket-page textarea.form-control {
            min-height: 120px;
            padding: 14px 16px;
            resize: vertical;
        }
        body.sales-request-ticket-page input.form-control::placeholder,
        body.sales-request-ticket-page textarea.form-control::placeholder {
            color: #334155;
            opacity: 1;
            font-size: 15px;
            font-weight: 400;
            line-height: 1.4;
        }
        body.sales-request-ticket-page input.form-control:focus,
        body.sales-request-ticket-page select.form-control:focus,
        body.sales-request-ticket-page textarea.form-control:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        .category-select option {
            color: #0f172a;
            font-size: 16px;
            font-weight: 400;
        }
        textarea {
            min-height: 120px;
            padding: 14px 16px;
            resize: vertical;
        }
        button {
            width: 100%;
            padding: 14px;
            background: #1B5E20;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #144a1e;
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
        }
        .hidden {
            display: none !important;
        }
        body.sales-request-ticket-page .hr-extra-group {
            display: none;
        }
        body.sales-request-ticket-page .hr-extra-group.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .kami-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: visible;
            position: relative;
            z-index: 20;
        }
        body.sales-request-ticket-page .kami-group.is-visible {
            display: block;
            margin-top: 16px;
        }
        body.sales-request-ticket-page .kami-banner-head {
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
        body.sales-request-ticket-page .kami-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            overflow: visible;
            position: relative;
        }
        body.sales-request-ticket-page .kami-list .hr-extra-group {
            margin: 0;
        }
        body.sales-request-ticket-page .kami-list .hr-extra-group.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .kami-list .form-group label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .kami-list .select-wrapper {
            max-width: 100%;
        }
        body.sales-request-ticket-page #concernTypeContainer {
            position: relative;
            z-index: 40;
        }
        body.sales-request-ticket-page #concernTypeDropdown {
            position: relative;
            z-index: 40;
        }
        body.sales-request-ticket-page #concernTypeDropdown .concern-type-dropdown-menu.is-open {
            z-index: 240;
        }
        body.sales-request-ticket-page .kami-continuation {
            display: none;
        }
        body.sales-request-ticket-page .other-request-section {
            margin-top: 18px;
        }
        body.sales-request-ticket-page .other-request-section-head {
            display: none;
        }
        body.sales-request-ticket-page .other-request-section-body {
            display: block;
        }
        body.sales-request-ticket-page .other-request-continuation {
            display: none;
        }
        body.sales-request-ticket-page.other-section-active .other-request-section-head {
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
        body.sales-request-ticket-page.other-section-active .other-request-section {
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page.other-section-active .other-request-section-body {
            padding: 20px 24px 24px;
        }
        body.sales-request-ticket-page.other-section-active #otherRequestContinuationHost {
            display: block;
            padding: 0 24px 24px;
            background: #ffffff;
        }
        body.sales-request-ticket-page.other-section-active #otherRequestDetailsSection {
            margin-bottom: 0;
        }
        body.sales-request-ticket-page.other-section-active #otherDescriptionSection {
            display: none !important;
        }
        body.sales-request-ticket-page.other-section-active #otherRequestContinuationHost #descriptionContainer,
        body.sales-request-ticket-page.other-section-active #otherRequestContinuationHost #attachmentContainer {
            margin-top: 0;
            margin-bottom: 0;
            padding-left: 0;
            padding-right: 0;
            border: 0;
            background: #ffffff;
            box-shadow: none;
        }
        body.sales-request-ticket-page.other-section-active #otherRequestContinuationHost #attachmentContainer {
            padding-top: 24px;
        }
        body.sales-request-ticket-page.kami-section-active #kamiBannerContainer {
            margin-bottom: 0;
            box-shadow: none;
        }
        body.sales-request-ticket-page.kami-section-active #kamiBannerContainer .kami-list {
            gap: 0;
            padding-bottom: 0;
        }
        body.sales-request-ticket-page.kami-section-active #kamiContinuationHost {
            display: block;
            padding: 0 24px 24px;
            background: #ffffff;
        }
        body.sales-request-ticket-page.kami-section-active #otherDescriptionSection {
            display: none !important;
        }
        body.sales-request-ticket-page.kami-section-active #kamiContinuationHost #descriptionContainer,
        body.sales-request-ticket-page.kami-section-active #kamiContinuationHost #attachmentContainer,
        body.sales-request-ticket-page.kami-section-active #descriptionContainer,
        body.sales-request-ticket-page.kami-section-active #attachmentContainer {
            margin-top: 0;
            margin-bottom: 0;
            padding-left: 0;
            padding-right: 0;
            border: 0;
            background: #ffffff;
            box-shadow: none;
        }
        body.sales-request-ticket-page.kami-section-active #kamiContinuationHost #attachmentContainer {
            padding-top: 24px;
        }
        body.sales-request-ticket-page.kami-section-active #kamiContinuationHost #descriptionContainer {
            padding-top: 18px;
        }
        body.sales-request-ticket-page .medical-cash-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .medical-cash-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .training-request-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .training-request-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .company-property-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .company-property-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .coe-request-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .coe-request-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .col-request-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .col-request-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .incident-report-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .incident-report-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .sap-request-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .sap-request-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .email-request-group {
            display: none;
            margin-top: 0;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .email-request-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .marketing-request-group {
            display: none;
            margin-top: 0;
            border: 1px solid #dbe4ef;
            border-radius: 22px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        body.sales-request-ticket-page .marketing-request-group.is-visible {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .medical-cash-head {
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
        body.sales-request-ticket-page .training-request-head {
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
        body.sales-request-ticket-page .incident-report-head {
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
        body.sales-request-ticket-page .company-property-head {
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
        body.sales-request-ticket-page .coe-request-head {
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
        body.sales-request-ticket-page .col-request-head {
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
        body.sales-request-ticket-page .sap-request-head {
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
        body.sales-request-ticket-page .email-request-head {
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
        body.sales-request-ticket-page .marketing-request-head {
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
        body.sales-request-ticket-page .medical-cash-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .training-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .incident-report-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .company-property-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .coe-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .col-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .sap-request-list {
            display: grid;
            gap: 18px;
            padding: 22px 32px 16px;
            background: #ffffff;
            border-top: 1px solid rgba(15, 23, 42, 0.10);
        }
        body.sales-request-ticket-page .email-request-list {
            display: block;
            padding: 22px 30px 30px;
            background: transparent;
        }
        body.sales-request-ticket-page .marketing-request-list {
            display: grid;
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
        }
        body.sales-request-ticket-page .sap-request-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 22px 32px 14px;
        }
        body.sales-request-ticket-page .sap-request-panel-copy {
            min-width: 0;
            display: grid;
            gap: 8px;
            justify-items: start;
            text-align: left;
        }
        body.sales-request-ticket-page .sap-request-counter {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 400;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .sap-request-panel-tools {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        body.sales-request-ticket-page .sap-request-switcher {
            min-width: 236px;
        }
        body.sales-request-ticket-page .sap-request-switcher-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #334155;
            font-size: 16px;
            pointer-events: none;
        }
        body.sales-request-ticket-page .sap-request-switcher .form-control {
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
        body.sales-request-ticket-page .sap-request-copy {
            margin: 0;
            padding: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }
        body.sales-request-ticket-page .training-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.sales-request-ticket-page .medical-cash-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.sales-request-ticket-page .incident-report-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.sales-request-ticket-page .col-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.sales-request-ticket-page .marketing-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
        }
        body.sales-request-ticket-page .marketing-request-details-row {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        body.sales-request-ticket-page .marketing-request-inline-row .supply-chain-full-row {
            grid-column: 1 / -1;
        }
        body.sales-request-ticket-page .marketing-request-inline-row .marketing-crop-card {
            grid-column: 2;
        }
        body.sales-request-ticket-page .marketing-crop-inline {
            margin-top: 18px;
        }
        body.sales-request-ticket-page #marketingRequestSection #project_deadline {
            font-weight: 400;
        }
        body.sales-request-ticket-page .medical-cash-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .training-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .incident-report-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .company-property-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .coe-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .col-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .marketing-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.sales-request-ticket-page .marketing-request-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .marketing-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        /* Supply Chain uses the same two-column form layout without a card around
           every input, keeping the request form less visually busy. */
        body.sales-request-ticket-page #supplyChainDetailsFields > .supply-chain-field {
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
        }
        body.sales-request-ticket-page #supplyChainDetailsFields > .supply-chain-field .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page #supplyChainDetailsFields > .supply-chain-field label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page #supplyChainAttachmentHost {
            margin-top: 14px;
        }
        body.sales-request-ticket-page #supplyChainAttachmentHost #attachmentContainer {
            margin: 0;
        }
        body.sales-request-ticket-page .marketing-request-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        body.sales-request-ticket-page .marketing-request-card-title.is-regular-label {
            font-weight: 400;
        }
        body.sales-request-ticket-page .marketing-request-option-list {
            display: grid;
            gap: 14px;
        }
        body.sales-request-ticket-page .marketing-request-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-weight: 500;
            color: #111827;
            cursor: pointer;
        }
        body.sales-request-ticket-page .marketing-request-option input[type="checkbox"],
        body.sales-request-ticket-page .marketing-request-option input[type="radio"],
        body.sales-request-ticket-page .marketing-request-other-row input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: #16a34a;
            flex: 0 0 auto;
        }
        body.sales-request-ticket-page .marketing-size-option {
            align-items: center;
        }
        body.sales-request-ticket-page .marketing-size-option label {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            min-width: 130px;
            margin: 0;
            cursor: pointer;
        }
        body.sales-request-ticket-page .marketing-size-value {
            display: none;
            max-width: 220px;
        }
        body.sales-request-ticket-page .marketing-size-value:not(:disabled) {
            display: block;
        }
        body.sales-request-ticket-page .marketing-request-other-row {
            display: none;
            margin-top: 12px;
        }
        body.sales-request-ticket-page .marketing-request-other-row.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .marketing-request-help {
            display: block;
            margin-top: 8px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }
        body.sales-request-ticket-page .marketing-request-error {
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
        body.sales-request-ticket-page .marketing-request-error.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .email-request-card {
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 0;
            box-shadow: none;
        }
        body.sales-request-ticket-page .email-request-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .email-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .email-creation-fields {
            display: none;
            margin-top: 0;
        }
        body.sales-request-ticket-page .email-creation-fields.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .email-request-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding: 22px 0 14px;
        }
        body.sales-request-ticket-page .email-request-panel-copy {
            min-width: 0;
            display: grid;
            gap: 8px;
            justify-items: start;
            text-align: left;
        }
        body.sales-request-ticket-page .email-request-panel-title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .email-request-copy {
            margin: 0;
            padding: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }
        body.sales-request-ticket-page .email-request-counter {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .email-request-panel-tools {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        body.sales-request-ticket-page .email-request-switcher {
            min-width: 236px;
        }
        body.sales-request-ticket-page .email-request-switcher-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #334155;
            font-size: 16px;
            pointer-events: none;
        }
        body.sales-request-ticket-page .email-request-switcher .form-control {
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
        body.sales-request-ticket-page #emailRequestList {
            display: grid;
            gap: 18px;
            padding: 22px 0 16px;
            background: #ffffff;
            border-top: 1px solid rgba(15, 23, 42, 0.10);
        }
        body.sales-request-ticket-page .email-creation-card {
            border: 1px solid #dbe4ef;
            border-radius: 18px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        body.sales-request-ticket-page .email-creation-card[data-email-card] {
            display: none;
        }
        body.sales-request-ticket-page .email-creation-card[data-email-card].is-active {
            display: block;
        }
        body.sales-request-ticket-page .email-creation-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .email-creation-card > .form-group {
            margin-top: 18px;
        }
        body.sales-request-ticket-page .email-creation-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        body.sales-request-ticket-page .email-creation-card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .email-creation-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 26px 14px;
        }
        body.sales-request-ticket-page .email-creation-inline-row + .email-creation-inline-row {
            margin-top: 24px;
        }
        body.sales-request-ticket-page .email-creation-card .form-control {
            border: 2px solid #73a66f;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            min-height: 50px;
            padding: 0 16px;
            font-size: 15px;
        }
        body.sales-request-ticket-page .email-creation-card .form-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .email-creation-card-delete {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: auto;
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
        body.sales-request-ticket-page .email-creation-card-delete i {
            margin-right: 6px;
        }
        body.sales-request-ticket-page .email-creation-card-delete:hover {
            background: #fff1f1;
            border-color: #e59f9f;
            color: #b91c1c;
        }
        body.sales-request-ticket-page .email-request-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 14px;
            padding: 18px 0 0;
            margin-top: 0;
            min-height: 44px;
        }
        body.sales-request-ticket-page .email-request-add-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: auto;
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
        body.sales-request-ticket-page .email-request-add-btn:hover {
            background: #14532d;
            box-shadow: 0 10px 20px rgba(20, 83, 45, 0.22);
            transform: translateY(-1px);
        }
        body.sales-request-ticket-page .email-request-add-btn i {
            font-size: 13px;
        }
        body.sales-request-ticket-page .email-description-host {
            display: block;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .email-description-host #descriptionContainer {
            margin: 0;
        }
        body.sales-request-ticket-page .email-description-host #descriptionLabel {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .email-description-host #descriptionField {
            min-height: 150px;
            border-radius: 18px;
            background: #ffffff;
        }
        body.sales-request-ticket-page .sap-request-card {
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 0;
            box-shadow: none;
        }
        body.sales-request-ticket-page .sap-request-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .sap-request-card label {
            display: block;
            margin-bottom: 12px;
        }
        body.sales-request-ticket-page .sap-request-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
        }
        body.sales-request-ticket-page .sap-request-card-title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 400;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .sap-request-card-delete {
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
        body.sales-request-ticket-page .sap-request-card-delete i {
            margin-right: 6px;
        }
        body.sales-request-ticket-page .sap-request-card .form-control {
            border: 2px solid #73a66f;
            border-radius: 18px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            min-height: 50px;
            padding: 0 16px;
            font-size: 15px;
        }
        body.sales-request-ticket-page .sap-request-card .form-control:focus {
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .sap-request-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 22px;
            margin-bottom: 18px;
        }
        body.sales-request-ticket-page .sap-request-company-row {
            margin-top: 2px;
            display: block;
        }
        body.sales-request-ticket-page .sap-request-field {
            min-width: 0;
        }
        body.sales-request-ticket-page .sap-request-department-wrap {
            display: none;
            width: 100%;
        }
        body.sales-request-ticket-page .sap-request-department-wrap.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .sap-request-department-field {
            display: none;
        }
        body.sales-request-ticket-page .sap-request-department-field.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .sap-request-actions {
            padding: 20px 20px 20px 0;
            margin-top: 0;
            display: flex;
            justify-content: flex-end;
            position: relative;
            z-index: 2;
            pointer-events: auto;
        }
        body.sales-request-ticket-page .sap-request-actions-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            width: auto;
            position: relative;
            z-index: 2;
            pointer-events: auto;
        }
        body.sales-request-ticket-page .sap-request-add-btn {
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
            position: relative;
            z-index: 3;
            pointer-events: auto;
        }
        body.sales-request-ticket-page .sap-request-add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 22px rgba(27, 94, 32, 0.22);
            filter: brightness(1.03);
        }
        body.sales-request-ticket-page .sap-request-add-btn i {
            margin-right: 8px;
        }
        body.sales-request-ticket-page .col-request-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .incident-report-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .col-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .incident-report-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .incident-report-card .optional-label {
            color: #64748b;
            font-weight: 600;
        }
        body.sales-request-ticket-page .training-request-inline-row .training-request-card {
            min-width: 0;
            margin: 0;
        }
        body.sales-request-ticket-page .medical-cash-inline-row .medical-cash-card {
            min-width: 0;
            margin: 0;
        }
        body.sales-request-ticket-page .col-request-inline-row .col-request-card {
            min-width: 0;
            margin: 0;
        }
        body.sales-request-ticket-page .sap-request-inline-row .sap-request-field {
            min-width: 0;
            margin: 0;
        }
        body.sales-request-ticket-page .medical-cash-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .training-request-card .form-group {
            margin: 0;
        }
        body.sales-request-ticket-page .medical-cash-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .training-request-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .company-property-copy {
            margin: 0;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.7;
        }
        body.sales-request-ticket-page .coe-request-copy {
            margin: 0 0 14px;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.7;
        }
        body.sales-request-ticket-page .company-property-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        body.sales-request-ticket-page .coe-request-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
        }
        body.sales-request-ticket-page .company-property-card-title.is-regular-label,
        body.sales-request-ticket-page .coe-request-card-title.is-regular-label {
            font-weight: 400;
        }
        /* Keep all LAPC HR form field text consistent with the Type of Concern field. */
        body.sales-request-ticket-page :is(#kamiBannerContainer, #medicalCashAdvanceSection, #trainingRequestSection, #companyPropertySection, #coeRequestSection, #colRequestSection, #incidentReportSection) :is(label, span, p, input, select, textarea) {
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.45;
        }
        body.sales-request-ticket-page #marketingRequestSection :is(label, span, p, input, select, textarea, button) {
            font-size: 13px;
            font-weight: 600;
        }
        /* Match the employee MHC dropdown text: regular menu options and a softer selected value. */
        body.sales-request-ticket-page #marketingRequestSection .marketing-materials-dropdown-trigger,
        body.sales-request-ticket-page #marketingRequestSection .marketing-materials-dropdown-trigger > span {
            font-family: 'Segoe UI', sans-serif;
            font-weight: 400;
            color: #334155;
        }
        body.sales-request-ticket-page #marketingRequestSection .marketing-materials-dropdown-option {
            font-family: 'Segoe UI', sans-serif;
            font-weight: 400;
            color: #0f172a;
        }
        body.sales-request-ticket-page #marketingRequestSection .marketing-materials-dropdown-option.is-selected {
            color: #ffffff;
        }
        body.sales-request-ticket-page .company-property-option-list {
            display: grid;
            gap: 18px;
        }
        body.sales-request-ticket-page .coe-request-option-list {
            display: grid;
            gap: 18px;
        }
        body.sales-request-ticket-page .company-property-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-weight: 500;
            color: #111827;
            cursor: pointer;
        }
        body.sales-request-ticket-page .coe-request-option {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            font-weight: 500;
            color: #111827;
            cursor: pointer;
        }
        body.sales-request-ticket-page .company-property-option input[type="radio"] {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: #16a34a;
            flex: 0 0 auto;
        }
        body.sales-request-ticket-page .coe-request-option input[type="radio"] {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: #16a34a;
            flex: 0 0 auto;
        }
        body.sales-request-ticket-page .coe-request-other-row {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 12px;
            align-items: center;
        }
        body.sales-request-ticket-page .coe-request-other-row .form-control {
            min-width: 0;
        }
        body.sales-request-ticket-page .medical-cash-card-copy {
            margin: 0 0 14px;
            color: #0f172a;
            font-size: 14px;
            line-height: 1.6;
        }
        body.sales-request-ticket-page.medical-cash-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.medical-cash-section-active #attachmentContainer {
            margin: 0;
            padding: 0;
            border: none;
            background: transparent;
            box-shadow: none;
        }
        body.sales-request-ticket-page.medical-cash-section-active #attachmentContainer label {
            display: block;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page.medical-cash-section-active #attachmentContainer .form-text {
            display: block;
            margin-top: 8px;
        }
        body.sales-request-ticket-page.training-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.company-property-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.coe-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.col-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.incident-report-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.sap-request-section-active #descriptionContainer {
            display: none !important;
        }
        body.sales-request-ticket-page.marketing-request-section-active #attachmentContainer label {
            display: block;
            margin-bottom: 10px;
        }
        @media (max-width: 768px) {
            body.sales-request-ticket-page .medical-cash-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .training-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .incident-report-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .col-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .marketing-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .marketing-request-inline-row .marketing-crop-card {
                grid-column: 1;
            }
            body.sales-request-ticket-page .sap-request-inline-row,
            body.sales-request-ticket-page .email-creation-inline-row,
            body.sales-request-ticket-page .sap-request-company-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .sap-request-card-top,
            body.sales-request-ticket-page .email-creation-card-top {
                align-items: flex-start;
                flex-direction: column;
            }
            body.sales-request-ticket-page .sap-request-panel-head,
            body.sales-request-ticket-page .email-request-panel-head {
                flex-direction: column;
                align-items: stretch;
            }
            body.sales-request-ticket-page .sap-request-switcher,
            body.sales-request-ticket-page .email-request-switcher {
                min-width: 0;
                width: 100%;
            }
            body.sales-request-ticket-page .sap-request-add-btn,
            body.sales-request-ticket-page .email-request-add-btn {
                width: 100%;
            }
            body.sales-request-ticket-page .sap-request-actions,
            body.sales-request-ticket-page .email-request-actions {
                padding: 16px 0 16px;
            }
            body.sales-request-ticket-page .email-request-list {
                padding: 18px 20px 22px;
            }
        }
        body.sales-request-ticket-page .sss-benefits-group {
            display: none;
            margin-top: 26px;
            border: 1px solid #dbe4ef;
            border-radius: 24px;
            background: #ffffff;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        body.sales-request-ticket-page .sss-benefits-group.is-visible {
            display: block;
        }
        body.sales-request-ticket-page .sss-benefits-note-head {
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
        body.sales-request-ticket-page .sss-benefits-note-body {
            padding: 18px 24px 20px;
            color: #334155;
            line-height: 1.75;
            font-size: 14px;
            border-bottom: 1px solid #dbe4ef;
        }
        body.sales-request-ticket-page .sss-benefits-list {
            display: grid;
            gap: 16px;
            padding: 18px 24px 24px;
        }
        body.sales-request-ticket-page .sss-benefits-card {
            border: 1px solid #dbe4ef;
            border-radius: 20px;
            background: #ffffff;
            padding: 20px 22px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        body.sales-request-ticket-page .sss-benefits-card-title {
            margin: 0 0 8px;
            color: #0f172a;
            font-size: 13px;
            font-weight: 600;
        }
        body.sales-request-ticket-page .sss-benefits-card-copy {
            margin: 0 0 14px;
            color: #64748b;
            font-size: 14px;
            line-height: 1.6;
        }
        body.sales-request-ticket-page .sss-benefits-upload-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        body.sales-request-ticket-page .sss-benefits-upload-btn {
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
        }
        body.sales-request-ticket-page .sss-benefits-upload-btn:hover {
            background: #dcfce7;
        }
        body.sales-request-ticket-page .sss-benefits-file-input {
            display: none;
        }
        body.sales-request-ticket-page .sss-benefits-file-name,
        body.sales-request-ticket-page .sss-benefits-file-empty {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }
        body.sales-request-ticket-page .sss-benefits-file-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        body.sales-request-ticket-page .sss-benefits-file-chip {
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
        body.sales-request-ticket-page .sss-benefits-file-chip-name {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.sales-request-ticket-page .sss-benefits-file-chip-link {
            border: none;
            background: transparent;
            padding: 0;
            color: inherit;
            font: inherit;
            cursor: pointer;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        body.sales-request-ticket-page .sss-benefits-file-chip-remove {
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
        body.sales-request-ticket-page .sss-benefits-error {
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
        body.sales-request-ticket-page .sss-benefits-error.is-visible {
            display: block;
        }
        .form-actions button {
            width: auto;
            padding: 12px 18px;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 8px;
            border: 2px solid #1B5E20;
            background: #ffffff;
            color: #111827;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-back:hover {
            background: #f3f4f6;
            border-color: #14532d;
        }
        body.sales-request-ticket-page .attachment-upload-shell {
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
        body.sales-request-ticket-page .attachment-upload-shell:hover {
            border-color: rgba(27, 94, 32, 0.24);
            background: #ffffff;
        }
        body.sales-request-ticket-page .attachment-upload-shell.is-dragover {
            border-color: #67c86f;
            background: #f4fbf5;
            box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.12);
        }
        .file-control {
            display: flex;
        }
        .file-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-width: 132px;
            height: 48px;
            padding: 0 18px;
            line-height: 1;
            background: #ecfdf5;
            color: #17643a;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            position: relative;
            z-index: 1;
            pointer-events: auto;
            box-sizing: border-box;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }
        .file-button:hover {
            background: #e6fbef;
            border-color: #86efac;
        }
        .file-button[aria-disabled="true"] {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .file-button svg {
            flex: 0 0 auto;
            display: block;
        }
        .file-button span {
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }
        .file-name {
            color: #475569;
            font-size: 14px;
            text-align: left;
            flex: 1 1 180px;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .file-hidden {
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
        body.sales-request-ticket-page .attachment-help-text {
            display: block;
            margin-top: 8px;
            color: #666666;
            font-size: 13px;
            text-align: left;
            line-height: 1.5;
        }
        body.sales-request-ticket-page .attachment-preview-modal {
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
        body.sales-request-ticket-page .attachment-preview-modal.is-visible {
            display: flex;
        }
        body.sales-request-ticket-page .attachment-preview-nav {
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
            transition: background 0.18s ease, border-color 0.18s ease, opacity 0.18s ease, transform 0.18s ease;
        }
        body.sales-request-ticket-page .attachment-preview-nav::before {
            content: "";
            display: block;
            width: 16px;
            height: 16px;
            border: solid currentColor;
            border-width: 0 4px 4px 0;
            box-sizing: border-box;
        }
        body.sales-request-ticket-page .attachment-preview-prev::before {
            transform: rotate(135deg);
            margin-left: 7px;
        }
        body.sales-request-ticket-page .attachment-preview-next::before {
            transform: rotate(-45deg);
            margin-right: 7px;
        }
        body.sales-request-ticket-page .attachment-preview-nav:hover {
            background: #16a34a;
            border-color: rgba(187, 247, 208, 0.72);
            color: #ffffff;
            transform: translateY(-50%) scale(1.04);
        }
        body.sales-request-ticket-page .attachment-preview-nav:disabled {
            display: none;
        }
        body.sales-request-ticket-page .attachment-preview-prev {
            left: 40px;
        }
        body.sales-request-ticket-page .attachment-preview-next {
            right: 40px;
        }
        body.sales-request-ticket-page .attachment-preview-dialog {
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
        body.sales-request-ticket-page .attachment-preview-modal[data-preview-kind="image"] .attachment-preview-dialog {
            width: fit-content;
            max-width: calc(100vw - 276px);
        }
        body.sales-request-ticket-page .attachment-preview-head {
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
        body.sales-request-ticket-page .attachment-preview-title {
            display: none;
        }
        body.sales-request-ticket-page .attachment-preview-title strong,
        body.sales-request-ticket-page .attachment-preview-title span {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.sales-request-ticket-page .attachment-preview-title strong {
            color: #0f172a;
            font-size: 15px;
            font-weight: 800;
        }
        body.sales-request-ticket-page .attachment-preview-title span {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }
        body.sales-request-ticket-page .attachment-preview-close {
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
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
        }
        body.sales-request-ticket-page .attachment-preview-close:hover {
            background: #dc2626;
            border-color: rgba(254, 202, 202, 0.78);
            color: #ffffff;
            transform: scale(1.04);
        }
        body.sales-request-ticket-page .attachment-preview-body {
            min-height: min(280px, calc(100vh - 144px));
            overflow: auto;
            background: transparent;
            border-radius: 8px;
        }
        body.sales-request-ticket-page .attachment-preview-modal[data-preview-kind="image"] .attachment-preview-body {
            overflow: visible;
        }
        body.sales-request-ticket-page .attachment-preview-body img {
            display: block;
            max-width: calc(100vw - 276px);
            max-height: calc(100vh - 144px);
            margin: 0 auto;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.38);
        }
        body.sales-request-ticket-page .attachment-preview-body iframe {
            display: block;
            width: 100%;
            height: min(760px, calc(100vh - 144px));
            border: 0;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.38);
        }
        body.sales-request-ticket-page .attachment-preview-unavailable {
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
        body.sales-request-ticket-page .attachment-preview-word {
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
        body.sales-request-ticket-page .attachment-preview-word p {
            max-width: 820px;
            margin: 0 auto 16px;
            white-space: pre-wrap;
        }
        @media (max-width: 720px) {
            body.sales-request-ticket-page .attachment-preview-modal {
                padding: 72px 68px 28px;
            }
            body.sales-request-ticket-page .attachment-preview-modal[data-preview-kind="image"] .attachment-preview-dialog {
                max-width: calc(100vw - 136px);
            }
            body.sales-request-ticket-page .attachment-preview-nav {
                width: 44px;
                height: 44px;
                font-size: 26px;
            }
            body.sales-request-ticket-page .attachment-preview-body img {
                max-width: calc(100vw - 136px);
                max-height: calc(100vh - 100px);
            }
            body.sales-request-ticket-page .attachment-preview-head {
                top: -18px;
                right: -18px;
            }
            body.sales-request-ticket-page .attachment-preview-close {
                width: 42px;
                height: 42px;
                font-size: 22px;
            }
            body.sales-request-ticket-page .attachment-preview-prev {
                left: 12px;
            }
            body.sales-request-ticket-page .attachment-preview-next {
                right: 12px;
            }
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
            text-decoration: none;
        }
        .back-link:hover {
            color: #1B5E20;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        body.sales-request-ticket-page #ajaxError.alert-error {
            width: 100%;
            box-sizing: border-box;
            margin: 0 0 20px;
            padding: 18px 20px;
            border-radius: 8px;
            background: #fff1f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            text-align: center;
            font-size: 18px;
            font-weight: 500;
            line-height: 1.35;
        }
        body.sales-request-ticket-page .ticket-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.46);
            backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 24px;
            box-sizing: border-box;
        }
        body.sales-request-ticket-page .ticket-modal.show { display: flex; }
        body.sales-request-ticket-page .ticket-modal-content {
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
        body.sales-request-ticket-page .ticket-modal-content::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 50% 10%, rgba(190, 242, 100, 0.24), transparent 22%),
                radial-gradient(circle at 50% 18%, rgba(34, 197, 94, 0.1), transparent 18%);
            pointer-events: none;
        }
        body.sales-request-ticket-page .ticket-modal-spinner {
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
        body.sales-request-ticket-page .ticket-modal-spinner::before {
            content: "";
            width: 40px;
            height: 40px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.96), inset 0 0 0 1px rgba(22, 101, 52, 0.08);
        }
        body.sales-request-ticket-page .ticket-modal-icon {
            width: 66px;
            height: 66px;
            margin: 0 auto 24px;
            border-radius: 999px;
            background: #f0fdf4;
            border: 3px solid #bbf7d0;
            color: #1B5E20;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 900;
            box-shadow: 0 12px 26px rgba(22, 101, 52, 0.12);
            position: relative;
            z-index: 1;
        }
        body.sales-request-ticket-page .ticket-modal-icon.success i {
            line-height: 1;
        }
        body.sales-request-ticket-page .ticket-modal-icon.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
            box-shadow: none;
        }
        body.sales-request-ticket-page .ticket-modal-content h3 {
            margin: 0 0 12px;
            padding: 0;
            font-size: 24px;
            font-weight: 800;
            color: #20274a;
            line-height: 1.15;
            letter-spacing: -0.03em;
            position: relative;
            z-index: 1;
        }
        body.sales-request-ticket-page .ticket-modal-content p {
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
        body.sales-request-ticket-page .ticket-modal-progress {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
        }
        body.sales-request-ticket-page .ticket-modal-progress span {
            display: block;
            width: 22%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #1B5E20, #22c55e);
            transition: width 0.35s ease;
        }
        body.sales-request-ticket-page .ticket-modal-status {
            min-height: 28px;
            color: #238948;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.02em;
            padding: 0;
            position: relative;
            z-index: 1;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
            margin-top: 0;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
            margin-top: 0;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status {
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
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status::before {
            display: none;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
            order: 1;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
            order: 3;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status {
            order: 4;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner {
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
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner::before {
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
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner::after {
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
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-actions {
            margin-top: 0;
            min-height: 0;
            height: 0;
            padding: 0;
            border-top: none;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
            order: 2;
            margin-top: 0;
            margin-bottom: 14px;
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.035em;
            color: #0f172a;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content {
            height: auto;
            min-height: 330px;
            padding: 40px 36px 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
            font-size: 16px;
            line-height: 1.55;
            color: #6b7280;
            max-width: 520px;
            margin-bottom: 0;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-icon.success,
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-icon.error {
            order: 1;
            margin: 0 auto 16px;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content h3,
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-content h3 {
            order: 2;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content h3 {
            font-weight: 600;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content p,
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-content p {
            order: 3;
            margin-bottom: 8px;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-actions,
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-actions {
            order: 4;
        }
        body.sales-request-ticket-page .ticket-modal-actions {
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
        body.sales-request-ticket-page .ticket-modal-content button {
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
        body.sales-request-ticket-page .ticket-modal-content button:hover { background: #144a1e; }
        body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-spinner,
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-icon.success,
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-icon.error {
            display: flex;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-actions,
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-actions {
            visibility: visible;
            opacity: 1;
            pointer-events: auto;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content {
            height: auto;
            min-height: 284px;
            padding-bottom: 28px;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-actions {
            margin-top: 14px;
            padding-top: 18px;
            border-top: 1px solid #e6e8ef;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-status {
            display: none;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-progress {
            display: none;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-progress span { width: 100% !important; }
        body.sales-request-ticket-page .ticket-modal-ticket-label,
        body.sales-request-ticket-page .ticket-modal-ticket-number {
            font-weight: 800;
        }
        body.sales-request-ticket-page .ticket-modal-ticket-label {
            color: #3f4861;
        }
        body.sales-request-ticket-page .ticket-modal-ticket-number {
            color: #14532d;
        }
        body.sales-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-progress span { background: linear-gradient(90deg, #ef4444, #f97316); }
        @keyframes follow-up-feedback-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            body.sales-request-ticket-page .request-grid-row {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            body.sales-request-ticket-page #recipientRow:has(#departmentGroup:not(.hidden)),
            body.sales-request-ticket-page #salesCategoryRow {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            body.sales-request-ticket-page #recipientRow:has(#departmentGroup:not(.hidden)) > .form-group,
            body.sales-request-ticket-page #salesCategoryRow > .form-group {
                grid-column: 1 / -1;
            }
            body.sales-request-ticket-page .ticket-modal-content {
                width: 100%;
                max-width: 380px;
                height: 260px;
                min-height: 260px;
                border-radius: 24px;
                padding: 28px 24px 18px;
            }
            body.sales-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-content {
                height: auto;
                min-height: 276px;
                padding-bottom: 24px;
            }
            body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content {
                min-height: 306px;
                padding: 34px 22px 24px;
            }
            body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content h3 {
                font-size: 24px;
            }
            body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-content p {
                font-size: 15px;
            }
            body.sales-request-ticket-page .ticket-modal[data-state="loading"] .ticket-modal-status {
                margin-top: 24px;
                padding-top: 18px;
            }
            body.sales-request-ticket-page .ticket-modal-content h3 {
                font-size: 18px;
            }
            body.sales-request-ticket-page .ticket-modal-content p,
            body.sales-request-ticket-page .ticket-modal-status {
                font-size: 14px;
            }
            body.sales-request-ticket-page .ticket-modal-spinner,
            body.sales-request-ticket-page .ticket-modal-icon {
                width: 58px;
                height: 58px;
            }
            body.sales-request-ticket-page .ticket-modal-spinner::before {
                width: 34px;
                height: 34px;
            }
            body.sales-request-ticket-page .ticket-modal-icon {
                font-size: 24px;
            }
            .sales-container {
                margin: 16px auto;
                padding: 0 16px 16px;
                border-radius: 14px;
                max-width: calc(100vw - 32px);
            }
            .sales-page-header {
                padding: 24px 8px 18px;
            }
            body.sales-request-ticket-page .form-card {
                padding: 0 16px 16px;
                border-radius: 14px;
                margin: 0;
            }
            body.sales-request-ticket-page .form-section-title {
                margin: 0 -16px 18px;
                padding: 14px 16px;
                border-radius: 14px 14px 0 0;
                font-size: 16px;
            }
            body.sales-request-ticket-page .attachment-upload-shell {
                padding: 10px;
                border-radius: 10px;
                border-style: dashed;
                justify-content: center;
                gap: 10px;
            }
            .file-button {
                width: 100%;
                min-width: 0;
                height: 50px;
                padding: 0 14px;
                border-radius: 14px;
            }
            .file-name {
                width: 100%;
                text-align: center;
                flex-basis: 100%;
            }
        }

        @media (min-width: 900px) and (orientation: landscape) {
            .sales-container {
                max-width: 920px;
                margin: 16px auto;
                padding: 24px;
            }

            .sales-page-header {
                margin-bottom: 16px;
            }

            .sales-page-header h1 {
                margin-bottom: 6px;
            }

            .form-group {
                margin-bottom: 0;
            }

            .form-grid {
                gap: 14px;
            }

            label {
                margin-bottom: 6px;
                font-size: 12px;
            }

            input, select, textarea {
                padding: 9px 12px;
                font-size: 14px;
                border-radius: 14px;
            }

            body.sales-request-ticket-page input.form-control,
            body.sales-request-ticket-page select.form-control {
                height: 46px;
                padding: 0 14px;
                font-size: 14px;
            }

            body.sales-request-ticket-page input.form-control::placeholder,
            body.sales-request-ticket-page textarea.form-control::placeholder {
                font-size: 14px;
            }

            body.sales-request-ticket-page .recipient-dropdown-trigger,
            body.sales-request-ticket-page .department-dropdown-trigger,
            body.sales-request-ticket-page .admin-legal-request-for-dropdown-trigger,
            body.sales-request-ticket-page .category-dropdown-trigger,
            body.sales-request-ticket-page .marketing-subcategory-dropdown-trigger,
            body.sales-request-ticket-page .priority-dropdown-trigger {
                min-height: 46px;
                padding: 0 40px 0 14px;
                border-radius: 14px;
                font-size: 14px;
                line-height: 1.25;
            }

            form {
                display: block;
            }

            textarea[name="description"] {
                height: 106px;
                min-height: 106px;
                padding: 12px 14px;
                font-size: 14px;
                resize: none;
            }

            button {
                width: auto;
                justify-self: end;
                padding: 12px 18px;
            }

            .back-link {
                margin-top: 14px;
            }
        }
        body.sales-request-ticket-page .sales-container {
            max-width: 1280px;
            margin-top: 0;
            padding-top: 0;
        }
        body.sales-request-ticket-page .sales-page-header {
            margin-bottom: 0;
            padding: 18px 16px 14px;
        }
        body.sales-request-ticket-page .request-ticket-layout {
            display: grid;
            grid-template-columns: minmax(360px, 400px) minmax(0, 1fr);
            gap: 24px;
            align-items: start;
        }
        body.sales-request-ticket-page .request-ticket-layout > .form-card {
            width: 100%;
            max-width: none;
            margin: 0;
        }
        body.sales-request-ticket-page .request-guidance-sidebar {
            position: sticky;
            top: 104px;
            display: grid;
            gap: 14px;
            min-width: 0;
        }
        body.sales-request-ticket-page .request-guidance-card,
        body.sales-request-ticket-page .request-tips-card {
            overflow: hidden;
            border: 1px solid #dbe4df;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
        }
        body.sales-request-ticket-page .request-guidance-heading {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 17px 18px 15px;
            border-bottom: 1px solid #e5ebe7;
            background: linear-gradient(180deg, #fff 0%, #fbfefc 100%);
        }
        body.sales-request-ticket-page .request-guidance-heading-icon,
        body.sales-request-ticket-page .request-tips-icon {
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
        body.sales-request-ticket-page .request-guidance-heading h2,
        body.sales-request-ticket-page .request-tips-title {
            margin: 0;
            color: #166534;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .request-guidance-heading p {
            margin: 3px 0 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.45;
        }
        body.sales-request-ticket-page .request-guidance-directory {
            max-height: min(520px, calc(100vh - 400px));
            min-height: 280px;
            overflow-x: hidden;
            overflow-y: auto;
            padding: 10px;
            scrollbar-width: thin;
            scrollbar-color: #9fb1a5 #eef4f0;
        }
        body.sales-request-ticket-page .request-company-guide {
            margin: 0 0 8px;
            border: 1px solid #dfe7e2;
            border-radius: 12px;
            background: #fff;
        }
        body.sales-request-ticket-page .request-company-guide:last-child { margin-bottom: 0; }
        body.sales-request-ticket-page .request-company-guide summary {
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr) 18px;
            gap: 10px;
            align-items: center;
            min-height: 54px;
            padding: 8px 12px;
            cursor: pointer;
            list-style: none;
        }
        body.sales-request-ticket-page .request-company-guide summary::-webkit-details-marker { display: none; }
        body.sales-request-ticket-page .request-company-guide[open] summary {
            border-bottom: 1px solid #e5ebe7;
            background: #f7fcf8;
        }
        body.sales-request-ticket-page .request-company-icon,
        body.sales-request-ticket-page .request-department-icon {
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
        body.sales-request-ticket-page .request-company-icon.is-emerald { background:#e7f8f0; color:#047857; }
        body.sales-request-ticket-page .request-company-icon.is-green { background:#eaf8ee; color:#15803d; }
        body.sales-request-ticket-page .request-company-icon.is-violet { background:#f1eafe; color:#7c3aed; }
        body.sales-request-ticket-page .request-company-icon.is-lime { background:#eff9df; color:#4d7c0f; }
        body.sales-request-ticket-page .request-company-icon.is-rose { background:#fff0f3; color:#e11d48; }
        body.sales-request-ticket-page .request-company-icon.is-blue { background:#eaf2ff; color:#2563eb; }
        body.sales-request-ticket-page .request-company-icon.is-amber { background:#fff7df; color:#b45309; }
        body.sales-request-ticket-page .request-company-icon.is-cyan { background:#e6f8fb; color:#0891b2; }
        body.sales-request-ticket-page .request-company-icon.is-orange { background:#fff0e5; color:#ea580c; }
        body.sales-request-ticket-page .request-company-copy { min-width: 0; }
        body.sales-request-ticket-page .request-company-name {
            display: block;
            color: #14532d;
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
        }
        body.sales-request-ticket-page .request-company-domain {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            color: #64748b;
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.sales-request-ticket-page .request-company-chevron {
            color: #64748b;
            font-size: 11px;
            transition: transform .18s ease;
        }
        body.sales-request-ticket-page .request-company-guide[open] .request-company-chevron { transform: rotate(90deg); }
        body.sales-request-ticket-page .request-department-list { display: grid; }

        @media (max-width: 768px) {
            body.sales-request-ticket-page .request-guidance-directory.is-mobile-initializing .request-company-guide[open] > :not(summary) {
                display: none;
            }

            body.sales-request-ticket-page .request-guidance-directory.is-mobile-initializing .request-company-guide[open] > summary {
                border-bottom: 0;
                background: #ffffff;
            }

            body.sales-request-ticket-page .request-guidance-directory.is-mobile-initializing .request-company-guide[open] .request-company-chevron {
                transform: none;
            }
        }

        body.sales-request-ticket-page .request-department-guide {
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            padding: 11px 12px;
            border-bottom: 1px solid #edf1ee;
        }
        body.sales-request-ticket-page .request-department-guide:last-child { border-bottom: 0; }
        body.sales-request-ticket-page .request-department-icon { background:#f1f5f9; color:#2563eb; }
        body.sales-request-ticket-page .request-department-icon.is-admin { background:#eaf8ee; color:#15803d; }
        body.sales-request-ticket-page .request-department-icon.is-it { background:#eaf2ff; color:#2563eb; }
        body.sales-request-ticket-page .request-department-icon.is-hr { background:#f3e8ff; color:#9333ea; }
        body.sales-request-ticket-page .request-department-icon.is-health { background:#fff0f3; color:#e11d48; }
        body.sales-request-ticket-page .request-department-icon.is-marketing { background:#fce7f3; color:#db2777; }
        body.sales-request-ticket-page .request-department-icon.is-technical { background:#fff0e5; color:#ea580c; }
        body.sales-request-ticket-page .request-department-icon.is-sales { background:#e8f4ff; color:#0369a1; }
        body.sales-request-ticket-page .request-department-icon.is-category { background:#ecfdf3; color:#047857; }
        body.sales-request-ticket-page .request-department-name {
            margin: 0 0 3px;
            color: #1e293b;
            font-size: 12px;
            font-weight: 600;
        }
        body.sales-request-ticket-page .request-category-list {
            margin: 0;
            color: #64748b;
            font-size: 12px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }
        body.sales-request-ticket-page .request-guide-empty {
            margin: 0;
            padding: 13px 14px;
            color: #64748b;
            font-size: 11px;
        }
        body.sales-request-ticket-page .request-tips-card {
            padding: 15px 16px 16px;
            border-color: #cfe8d6;
            background: linear-gradient(180deg, #f3fbf5 0%, #eef9f1 100%);
        }
        body.sales-request-ticket-page .request-tips-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        body.sales-request-ticket-page .request-tips-icon {
            width: 24px;
            height: 24px;
            flex-basis: 24px;
            border: 0;
            font-size: 16px;
        }
        body.sales-request-ticket-page .request-tips-list {
            display: grid;
            gap: 7px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        body.sales-request-ticket-page .request-tips-list li {
            display: grid;
            grid-template-columns: 15px minmax(0, 1fr);
            gap: 7px;
            color: #475569;
            font-size: 11px;
            line-height: 1.4;
        }
        body.sales-request-ticket-page .request-tips-list i { margin-top:2px; color:#15803d; font-size:10px; }
        @media (max-width: 1180px) {
            body.sales-request-ticket-page .sales-container { max-width: 920px; }
            body.sales-request-ticket-page .request-ticket-layout { grid-template-columns: 1fr; }
            body.sales-request-ticket-page .request-guidance-sidebar { position: static; }
            body.sales-request-ticket-page .request-guidance-directory { max-height: 380px; min-height: 0; }
        }

        /* Employee request-page visual parity */
        body.sales-request-ticket-page {
            background: #f7f9f8;
            color: #0f172a;
        }

        body.sales-request-ticket-page .sales-container {
            width: min(100%, 1450px);
            max-width: 1450px;
            margin: 0 auto;
            padding: 18px 28px 42px;
            box-sizing: border-box;
        }

        body.sales-request-ticket-page .sales-page-header {
            margin: 0 0 14px;
            padding: 0;
            text-align: center;
        }

        body.sales-request-ticket-page .sales-page-header h1 {
            margin: 0 0 4px;
            color: #166534;
            font-family: 'Segoe UI', sans-serif;
            font-size: 27px;
            line-height: 1.2;
            font-weight: 700;
        }

        body.sales-request-ticket-page .sales-page-header p {
            margin: 0;
            color: #64748b;
            font-size: 14px;
            line-height: 1.45;
        }

        body.sales-request-ticket-page .request-ticket-layout {
            display: grid;
            grid-template-columns: minmax(390px, 460px) minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }

        body.sales-request-ticket-page .request-guidance-sidebar {
            position: sticky;
            top: 96px;
            display: grid;
            gap: 14px;
            min-width: 0;
        }

        body.sales-request-ticket-page .request-main-column {
            display: grid;
            gap: 14px;
            min-width: 0;
        }

        body.sales-request-ticket-page .request-guidance-card,
        body.sales-request-ticket-page .request-routing-help,
        body.sales-request-ticket-page .request-tips-card-main,
        body.sales-request-ticket-page .request-main-column > .form-card {
            border: 1px solid #e1e7e3;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.045);
        }

        body.sales-request-ticket-page .request-main-column > .form-card {
            border-top: 1px solid #e1e7e3 !important;
        }

        body.sales-request-ticket-page .request-guidance-card {
            overflow: hidden;
        }

        body.sales-request-ticket-page .request-guidance-heading {
            padding: 18px 20px 12px;
            border-bottom: 0;
            background: #ffffff;
        }

        body.sales-request-ticket-page .request-guidance-heading h2 {
            font-size: 15px;
            line-height: 1.35;
        }

        body.sales-request-ticket-page .request-guidance-heading p {
            margin-top: 3px;
            font-size: 12px;
            line-height: 1.5;
        }

        body.sales-request-ticket-page .request-guidance-search {
            position: relative;
            margin: 0 16px 8px;
        }

        body.sales-request-ticket-page .request-guidance-search i {
            position: absolute;
            top: 50%;
            left: 15px;
            z-index: 1;
            color: #64748b;
            font-size: 14px;
            transform: translateY(-50%);
            pointer-events: none;
        }

        body.sales-request-ticket-page .request-guidance-search input {
            width: 100%;
            height: 42px;
            padding: 0 14px 0 43px;
            border: 1px solid #dbe3de;
            border-radius: 9px;
            outline: none;
            background: #ffffff;
            color: #0f172a;
            font: 13px 'Segoe UI', sans-serif;
            box-sizing: border-box;
        }

        body.sales-request-ticket-page .request-guidance-search input:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }

        body.sales-request-ticket-page .request-company-guide.is-search-match > summary,
        body.sales-request-ticket-page .request-department-guide.is-search-match {
            background: #ecfdf3;
            box-shadow: inset 3px 0 0 #16a34a;
        }

        body.sales-request-ticket-page .request-guidance-directory {
            max-height: 510px;
            min-height: 0;
            padding: 0 16px 8px;
        }

        body.sales-request-ticket-page .request-company-guide {
            margin-bottom: 6px;
            border-radius: 11px;
        }

        body.sales-request-ticket-page .request-company-guide summary {
            min-height: 52px;
            padding: 8px 12px;
        }

        body.sales-request-ticket-page .request-company-name {
            font-size: 15px;
        }

        body.sales-request-ticket-page .request-company-domain,
        body.sales-request-ticket-page .request-category-list {
            font-size: 12px;
        }

        body.sales-request-ticket-page .request-department-guide {
            padding: 10px 12px;
        }

        body.sales-request-ticket-page .request-department-guide.is-guidance-extra {
            display: none;
        }

        body.sales-request-ticket-page .request-company-guide.show-all-departments .request-department-guide.is-guidance-extra,
        body.sales-request-ticket-page .request-guidance-directory.is-searching .request-department-guide.is-guidance-extra {
            display: grid;
        }

        body.sales-request-ticket-page .request-view-departments {
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
            font: 600 12px 'Segoe UI', sans-serif;
            text-align: left;
            cursor: pointer;
        }

        body.sales-request-ticket-page .request-view-departments > i:first-child {
            color: #64748b;
            font-size: 14px;
            text-align: center;
        }

        body.sales-request-ticket-page .request-view-departments > i:last-child {
            color: #64748b;
            font-size: 11px;
            transition: transform 0.18s ease;
        }

        body.sales-request-ticket-page .request-company-guide.show-all-departments .request-view-departments > i:last-child {
            transform: rotate(90deg);
        }

        body.sales-request-ticket-page .request-routing-help {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 10px;
            padding: 14px 16px;
            border-color: #f2d679;
            background: #fffdf6;
            box-shadow: none;
        }

        body.sales-request-ticket-page .request-routing-help-icon {
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

        body.sales-request-ticket-page .request-routing-help h2 {
            margin: 0 0 5px;
            color: #8a5b0a;
            font-size: 13px;
            line-height: 1.35;
        }

        body.sales-request-ticket-page .request-routing-help p {
            margin: 0;
            color: #596579;
            font-size: 11.5px;
            line-height: 1.55;
        }

        body.sales-request-ticket-page .request-main-column > .form-card {
            width: 100%;
            max-width: none;
            min-height: 0;
            height: auto;
            margin: 0;
            padding: 20px 24px;
            overflow: visible;
            box-sizing: border-box;
        }

        body.sales-request-ticket-page .request-main-column .form-section-title {
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

        body.sales-request-ticket-page .request-main-column .form-section-title::before {
            content: "\f15c";
            color: #15803d;
            font-family: "Font Awesome 6 Free";
            font-size: 20px;
            font-weight: 900;
        }

        body.sales-request-ticket-page .request-main-column .form-group {
            margin-bottom: 16px;
        }

        body.sales-request-ticket-page .request-main-column .form-group > label {
            display: block;
            margin-bottom: 7px;
            color: #111827;
            font-size: 13px;
            font-weight: 600;
        }

        body.sales-request-ticket-page .request-main-column .request-grid-row {
            gap: 20px;
        }

        body.sales-request-ticket-page .request-main-column .form-control,
        body.sales-request-ticket-page .request-main-column .form-group input:not([type="hidden"]):not([type="file"]),
        body.sales-request-ticket-page .request-main-column .form-group select,
        body.sales-request-ticket-page .request-main-column .form-group textarea,
        body.sales-request-ticket-page .request-main-column .recipient-dropdown-trigger,
        body.sales-request-ticket-page .request-main-column .department-dropdown-trigger,
        body.sales-request-ticket-page .request-main-column .admin-legal-request-for-dropdown-trigger,
        body.sales-request-ticket-page .request-main-column .category-dropdown-trigger,
        body.sales-request-ticket-page .request-main-column .priority-dropdown-trigger,
        body.sales-request-ticket-page .request-main-column .sales-position-dropdown-trigger,
        body.sales-request-ticket-page .request-main-column .sales-region-dropdown-trigger {
            min-height: 48px;
            height: 48px;
            padding: 11px 14px;
            border: 1px solid #d5ddd8;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none;
            color: #1f2937;
            font-size: 14px;
            box-sizing: border-box;
        }

        body.sales-request-ticket-page .request-main-column .department-dropdown-trigger:focus,
        body.sales-request-ticket-page .request-main-column .department-dropdown-trigger[aria-expanded="true"],
        body.sales-request-ticket-page .request-main-column .admin-legal-request-for-dropdown-trigger:focus,
        body.sales-request-ticket-page .request-main-column .admin-legal-request-for-dropdown-trigger[aria-expanded="true"] {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }

        body.sales-request-ticket-page .request-main-column .form-group textarea,
        body.sales-request-ticket-page .request-main-column #descriptionField {
            height: 136px;
            min-height: 136px;
            padding: 14px;
            resize: none;
        }

        body.sales-request-ticket-page .request-main-column .attachment-upload-shell {
            min-height: 64px;
            padding: 10px 12px;
            border: 1px solid #e1e7e3;
            border-radius: 10px;
            background: #ffffff;
        }

        body.sales-request-ticket-page .request-main-column .file-button {
            min-width: 128px;
            height: 42px;
            padding: 0 15px;
            border: 1px solid #bbdfc5;
            border-radius: 8px;
            background: #f0fdf4;
            color: #166534;
            font-size: 13px;
        }

        body.sales-request-ticket-page .request-main-column .attachment-file-name {
            font-size: 13px;
        }

        body.sales-request-ticket-page .request-main-column .form-text {
            margin-top: 7px;
            color: #64748b;
            font-size: 11px;
        }

        body.sales-request-ticket-page .request-main-column .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        body.sales-request-ticket-page .request-main-column .submit-btn {
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

        body.sales-request-ticket-page .request-main-column .submit-btn::before {
            content: "\f1d8";
            font-family: "Font Awesome 6 Free";
            font-size: 14px;
            font-weight: 900;
        }

        body.sales-request-ticket-page .request-tips-card-main {
            padding: 15px 22px 17px;
        }

        body.sales-request-ticket-page .request-tips-card-main .request-tips-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            padding-bottom: 12px;
            border-bottom: 1px solid #e4e9e6;
        }

        body.sales-request-ticket-page .request-tips-card-main .request-tips-icon {
            width: 30px;
            height: 30px;
            flex: 0 0 30px;
            border: 0;
            border-radius: 50%;
            background: #ecfdf3;
            color: #16803d;
            font-size: 15px;
        }

        body.sales-request-ticket-page .request-tips-card-main .request-tips-title {
            color: #166534;
            font-size: 15px;
            font-weight: 700;
        }

        body.sales-request-ticket-page .request-tips-card-main .request-tips-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0;
            margin: 14px 0 0;
            padding: 0;
            list-style: none;
        }

        body.sales-request-ticket-page .request-tips-card-main .request-tips-list li {
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

        body.sales-request-ticket-page .request-tips-card-main .request-tips-list li:first-child { padding-left: 0; }
        body.sales-request-ticket-page .request-tips-card-main .request-tips-list li:last-child { padding-right: 0; }
        body.sales-request-ticket-page .request-tips-card-main .request-tips-list li + li { border-left: 1px solid #dfe5e1; }

        body.sales-request-ticket-page .request-tips-card-main .request-tips-list i {
            margin: 0;
            color: #159447;
            font-size: 27px;
        }

        @media (max-width: 1180px) {
            body.sales-request-ticket-page .sales-container { max-width: 920px; }
            body.sales-request-ticket-page .request-ticket-layout { grid-template-columns: 1fr; }
            body.sales-request-ticket-page .request-guidance-sidebar { position: static; }
            body.sales-request-ticket-page .request-guidance-directory { max-height: 390px; }
        }

        @media (min-width: 1181px) {
            body.sales-request-ticket-page .sales-page-header {
                width: calc(100% - 478px);
                margin-left: 478px;
            }
        }

        @media (max-width: 768px) {
            body.sales-request-ticket-page .sales-container { padding: 14px 12px 86px; }
            body.sales-request-ticket-page .sales-page-header h1 { font-size: 23px; }
            body.sales-request-ticket-page .request-main-column > .form-card { padding: 17px 16px; }
            body.sales-request-ticket-page .request-main-column .form-section-title {
                margin: 0 0 18px;
                padding: 0 0 13px;
                border-bottom: 1px solid #dfe5e1;
                border-radius: 0;
                background: transparent;
                box-shadow: none;
                color: #166534;
                font-size: 14px;
            }
            body.sales-request-ticket-page .request-main-column .request-grid-row { grid-template-columns: 1fr; gap: 0; }
            body.sales-request-ticket-page .request-main-column .submit-btn { width: 100%; min-width: 0; }
            body.sales-request-ticket-page .request-tips-card-main .request-tips-list { grid-template-columns: 1fr; gap: 12px; }
            body.sales-request-ticket-page .request-tips-card-main .request-tips-list li,
            body.sales-request-ticket-page .request-tips-card-main .request-tips-list li:first-child,
            body.sales-request-ticket-page .request-tips-card-main .request-tips-list li:last-child { padding: 0; }
            body.sales-request-ticket-page .request-tips-card-main .request-tips-list li + li {
                padding-top: 12px;
                border-top: 1px solid #e4e9e6;
                border-left: 0;
            }
        }
    </style>
</head>
<body class="sales-request-ticket-page">

<nav class="navbar sales-employee-navbar" aria-label="Sales navigation">
    <div class="nav-left">
        <img src="../assets/img/UPDATEDlogo.png?v=2" alt="Leads Agri Logo" class="logo-icon">
        <div class="brand-name">Leads DeskMetamorph</div>
        <button class="navbar-toggler" id="navbarToggler" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="navbarCollapse">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="navbar-collapse" id="navbarCollapse">
        <div class="nav-center" aria-hidden="true"></div>

        <div class="nav-right sales-nav-right">
            <a class="sales-nav-link" href="../index.php">
                <span class="sales-nav-link-icon" aria-hidden="true"><i class="fa-solid fa-arrow-left"></i></span>
                <span>Back</span>
            </a>
            <a class="sales-nav-link" href="knowledge_base.php">
                <span>Knowledge Base</span>
                <span class="sales-nav-link-icon" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
            </a>
        </div>
    </div>
</nav>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggler = document.getElementById('navbarToggler');
    var collapse = document.getElementById('navbarCollapse');
    if (!toggler || !collapse) return;
    toggler.addEventListener('click', function () {
        var isOpen = collapse.classList.toggle('show');
        toggler.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
});
</script>

<div class="sales-container">
    <div class="sales-page-header">
        <h1>Create a Ticket</h1>
        <p>Please fill out the form below to submit your concern.</p>
    </div>

        <?php if($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error" id="pageError"><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="request-ticket-layout">
        <aside class="request-guidance-sidebar" aria-label="Ticket routing guide">
            <section class="request-guidance-card">
                <div class="request-guidance-heading">
                    <span class="request-guidance-heading-icon" aria-hidden="true"><i class="fas fa-info"></i></span>
                    <div>
                        <h2>Guidelines: Where to Submit Your Concern</h2>
                        <p>Choose a subsidiary, then use the Department field when it appears and select the matching category.</p>
                    </div>
                </div>
                <div class="request-guidance-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="requestGuidanceSearch" placeholder="Search subsidiary or department..." aria-label="Search subsidiary or department">
                </div>
                <div
                    class="request-guidance-directory<?= $selectedRecipientCompany === '' ? ' is-mobile-initializing' : ''; ?>"
                    data-has-selected-company="<?= $selectedRecipientCompany !== '' ? 'true' : 'false'; ?>"
                >
                    <?php foreach ($requestGuidanceCompanies as $guidanceCompany): ?>
                        <?php
                            $guidanceCompanyIsOpen = (string) $guidanceCompany['value'] === $requestGuidanceOpenCompany;
                            $guidanceRequiresDepartment = !empty($guidanceCompany['requires_department']);
                        ?>
                        <details class="request-company-guide"<?= $guidanceCompanyIsOpen ? ' open' : ''; ?>>
                            <summary>
                                <span class="request-company-icon is-<?= htmlspecialchars((string) $guidanceCompany['tone'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><i class="fas <?= htmlspecialchars((string) $guidanceCompany['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                <span class="request-company-copy">
                                    <strong class="request-company-name"><?= htmlspecialchars((string) $guidanceCompany['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="request-company-domain"><?= htmlspecialchars((string) $guidanceCompany['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                                <i class="fas fa-chevron-right request-company-chevron" aria-hidden="true"></i>
                            </summary>
                            <div class="request-department-list">
                                <?php if ($guidanceRequiresDepartment): ?>
                                    <?php if (empty($guidanceCompany['departments'])): ?>
                                        <p class="request-guide-empty">No departments are currently available in the Department dropdown.</p>
                                    <?php endif; ?>
                                    <?php foreach ($guidanceCompany['departments'] as $guidanceDepartmentIndex => $guidanceDepartment): ?>
                                        <?php
                                            $guidanceDepartmentName = (string) ($guidanceDepartment['name'] ?? '');
                                            $guidanceDepartmentLookup = strtolower($guidanceDepartmentName);
                                            $guidanceDepartmentIcon = 'fa-sitemap';
                                            $guidanceDepartmentTone = 'operations';
                                            if (str_contains($guidanceDepartmentLookup, 'admin') || str_contains($guidanceDepartmentLookup, 'legal')) {
                                                $guidanceDepartmentIcon = 'fa-scale-balanced';
                                                $guidanceDepartmentTone = 'admin';
                                            } elseif ($guidanceDepartmentLookup === 'it') {
                                                $guidanceDepartmentIcon = 'fa-desktop';
                                                $guidanceDepartmentTone = 'it';
                                            } elseif ($guidanceDepartmentLookup === 'hr') {
                                                $guidanceDepartmentIcon = 'fa-users';
                                                $guidanceDepartmentTone = 'hr';
                                            } elseif (str_contains($guidanceDepartmentLookup, 'diagnostic') || str_contains($guidanceDepartmentLookup, 'lingap')) {
                                                $guidanceDepartmentIcon = 'fa-heart-pulse';
                                                $guidanceDepartmentTone = 'health';
                                            } elseif (str_contains($guidanceDepartmentLookup, 'marketing')) {
                                                $guidanceDepartmentIcon = 'fa-bullhorn';
                                                $guidanceDepartmentTone = 'marketing';
                                            } elseif (str_contains($guidanceDepartmentLookup, 'technical')) {
                                                $guidanceDepartmentIcon = 'fa-flask';
                                                $guidanceDepartmentTone = 'technical';
                                            } elseif (str_contains($guidanceDepartmentLookup, 'sales') || str_contains($guidanceDepartmentLookup, 'bidding')) {
                                                $guidanceDepartmentIcon = 'fa-file-contract';
                                                $guidanceDepartmentTone = 'sales';
                                            }
                                            $guidanceCategoryText = implode(' • ', array_map('strval', (array) ($guidanceDepartment['categories'] ?? [])));
                                        ?>
                                        <div class="request-department-guide<?= $guidanceDepartmentIndex >= 3 ? ' is-guidance-extra' : ''; ?>">
                                            <span class="request-department-icon is-<?= htmlspecialchars($guidanceDepartmentTone, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><i class="fas <?= htmlspecialchars($guidanceDepartmentIcon, ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                            <div>
                                                <h3 class="request-department-name"><?= htmlspecialchars($guidanceDepartmentName, ENT_QUOTES, 'UTF-8'); ?></h3>
                                                <?php if ($guidanceCategoryText !== ''): ?>
                                                <p class="request-category-list"><?= htmlspecialchars($guidanceCategoryText, ENT_QUOTES, 'UTF-8'); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($guidanceCompany['departments']) > 3): ?>
                                        <button type="button" class="request-view-departments" aria-expanded="false">
                                            <i class="fas fa-border-all" aria-hidden="true"></i>
                                            <span>View all departments</span>
                                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php $guidanceCategoryText = implode(' • ', array_map('strval', (array) ($guidanceCompany['categories'] ?? []))); ?>
                                    <?php if ($guidanceCategoryText !== ''): ?>
                                    <div class="request-department-guide">
                                        <span class="request-department-icon is-category" aria-hidden="true"><i class="fas fa-tags"></i></span>
                                        <div>
                                            <h3 class="request-department-name">Categories</h3>
                                            <p class="request-category-list"><?= htmlspecialchars($guidanceCategoryText, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="request-routing-help" aria-label="Routing help">
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
            <div class="alert alert-error" id="ajaxError" style="display:none;"></div>
            <h3 class="form-section-title">Request Information</h3>

            <div class="form-grid">
            <div class="request-grid-row">
                <div class="form-group">
                    <label>Full Name <span class="required-asterisk">*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="Enter your full name" required value="<?= htmlspecialchars($full_name ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>

                <div class="form-group">
                    <label>Email <span class="required-asterisk">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="Enter your email address" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="request-grid-row">
                <div class="form-group">
                    <label>Position <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper sales-position-dropdown" id="salesPositionDropdown">
                        <select name="sales_position" id="sales_position" class="form-control category-select sales-position-native-select" required data-selected="<?= htmlspecialchars((string) ($sales_position ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled hidden <?= ($sales_position ?? '') === '' ? 'selected' : ''; ?>>Choose position</option>
                            <?php foreach ($salesPositionOptions as $positionOption): ?>
                                <option value="<?= htmlspecialchars($positionOption, ENT_QUOTES, 'UTF-8'); ?>" <?= ($sales_position ?? '') === $positionOption ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($positionOption, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="salesPositionDropdownTrigger" class="sales-position-dropdown-trigger<?= ($sales_position ?? '') === '' ? ' is-placeholder' : ''; ?>" aria-haspopup="listbox" aria-expanded="false"><?= htmlspecialchars(($sales_position ?? '') !== '' ? (string) $sales_position : 'Choose position', ENT_QUOTES, 'UTF-8'); ?></button>
                        <div id="salesPositionDropdownMenu" class="sales-position-dropdown-menu" role="listbox" aria-labelledby="salesPositionDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label>Region <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper sales-region-dropdown" id="salesRegionDropdown">
                        <select name="sales_region" id="sales_region" class="form-control category-select sales-region-native-select" required data-selected="<?= htmlspecialchars((string) ($sales_region ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled hidden <?= ($sales_region ?? '') === '' ? 'selected' : ''; ?>>Choose region</option>
                            <?php foreach ($salesRegionOptions as $regionOption): ?>
                                <option value="<?= htmlspecialchars($regionOption, ENT_QUOTES, 'UTF-8'); ?>" <?= ($sales_region ?? '') === $regionOption ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($regionOption, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="salesRegionDropdownTrigger" class="sales-region-dropdown-trigger<?= ($sales_region ?? '') === '' ? ' is-placeholder' : ''; ?>" aria-haspopup="listbox" aria-expanded="false"><?= htmlspecialchars(($sales_region ?? '') !== '' ? (string) $sales_region : 'Choose region', ENT_QUOTES, 'UTF-8'); ?></button>
                        <div id="salesRegionDropdownMenu" class="sales-region-dropdown-menu" role="listbox" aria-labelledby="salesRegionDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>
            </div>

            <div class="request-grid-row<?= $initialShowDepartment ? '' : ' is-single' ?>" id="recipientRow">
                <div class="form-group" id="recipientGroup">
                    <label>Subsidiaries <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper recipient-dropdown<?= count($requestTicketCompanyOptions) <= 1 ? ' is-static' : '' ?>" id="recipientDropdown">
                        <select name="company_id" id="ticket_recipient" class="form-control recipient-native-select" required>
                            <option value="" disabled <?= $selectedRecipientCompany === '' ? 'selected' : '' ?> hidden>Select a company</option>
                            <?php foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel): ?>
                                <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedRecipientCompany === $companyValue ? 'selected' : '' ?>><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="recipientDropdownTrigger" class="recipient-dropdown-trigger<?= $selectedRecipientCompany === '' ? ' is-placeholder' : '' ?>" aria-haspopup="listbox" aria-expanded="false"<?= count($requestTicketCompanyOptions) <= 1 ? ' disabled' : '' ?>><?= htmlspecialchars(($selectedRecipientCompany !== '' ? ($requestTicketCompanyOptions[$selectedRecipientCompany] ?? 'Select a company') : 'Select a company'), ENT_QUOTES, 'UTF-8'); ?></button>
                        <div id="recipientDropdownMenu" class="recipient-dropdown-menu" role="listbox" aria-labelledby="recipientDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>

                <div class="form-group<?= $initialShowDepartment ? '' : ' hidden' ?>" id="departmentGroup">
                    <label>Assigned Department <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper department-dropdown<?= (count($initialSalesDepartmentOptions) <= 1 && $selectedRecipientCompany !== '' && ticket_company_requires_department($selectedRecipientCompany)) ? ' is-static' : '' ?>" id="departmentDropdown">
                        <select name="assigned_department" id="department" class="form-control department-native-select" required disabled data-selected="<?= htmlspecialchars($selectedRecipientDepartment, ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled <?= $selectedRecipientDepartment === '' ? 'selected' : '' ?> hidden>Choose department</option>
                            <?php foreach ($initialSalesDepartmentOptions as $d): ?>
                                <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $selectedRecipientDepartment === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="departmentDropdownTrigger" class="department-dropdown-trigger<?= $selectedRecipientDepartment === '' ? ' is-placeholder' : '' ?>" aria-haspopup="listbox" aria-expanded="false" disabled><?= htmlspecialchars($selectedRecipientDepartment !== '' ? $selectedRecipientDepartment : 'Choose department', ENT_QUOTES, 'UTF-8'); ?></button>
                        <div id="departmentDropdownMenu" class="department-dropdown-menu" role="listbox" aria-labelledby="departmentDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>
            </div>

            <div class="request-grid-row" id="salesCategoryRow">
                <div class="form-group hidden" id="adminLegalRequestForContainer">
                    <label>Request For <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper admin-legal-request-for-dropdown" id="adminLegalRequestForDropdown">
                        <select name="admin_legal_request_for" id="admin_legal_request_for" class="form-control category-select admin-legal-request-for-native-select" data-selected="<?= htmlspecialchars((string) ($admin_legal_request_for ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled hidden <?= ($admin_legal_request_for ?? '') === '' ? 'selected' : '' ?>>Choose request for</option>
                            <?php foreach (array_keys($lapcAdminLegalRequestCategories) as $requestForOption): ?>
                                <option value="<?= htmlspecialchars($requestForOption, ENT_QUOTES, 'UTF-8'); ?>" <?= ($admin_legal_request_for ?? '') === $requestForOption ? 'selected' : '' ?>><?= htmlspecialchars($requestForOption, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="adminLegalRequestForDropdownTrigger" class="admin-legal-request-for-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false">Choose request for</button>
                        <div id="adminLegalRequestForDropdownMenu" class="admin-legal-request-for-dropdown-menu" role="listbox" aria-labelledby="adminLegalRequestForDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>

                <div class="form-group" id="categoryContainer">
                    <label>Category <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper category-dropdown" id="categoryDropdown">
                        <select name="category" id="sales_category" class="form-control category-select category-native-select" required data-selected="<?= htmlspecialchars((string) ($category ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?= $initialSalesRoutingComplete ? '' : ' disabled'; ?>>
                            <option value="" disabled hidden <?= ($category ?? '') === '' ? 'selected' : '' ?>>Choose category</option>
                            <?php foreach ($initialSalesCategoryOptions as $categoryOption): ?>
                                <option value="<?= htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?= ($category ?? '') === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="categoryDropdownTrigger" class="category-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false"<?= $initialSalesRoutingComplete ? '' : ' disabled'; ?>>Choose category</button>
                        <div id="categoryDropdownMenu" class="category-dropdown-menu" role="listbox" aria-labelledby="categoryDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>

                <div class="form-group" id="priorityGroup">
                    <label>Level of Urgency <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper priority-dropdown" id="priorityDropdown">
                        <select name="priority" id="sales_priority" class="form-control category-select priority-native-select" required data-selected="<?= htmlspecialchars((string) ($priority_selected ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?= $initialSalesRoutingComplete ? '' : ' disabled'; ?>>
                            <option value="" disabled hidden <?= ($priority_selected ?? '') === '' ? 'selected' : '' ?>>Choose level of urgency</option>
                            <option value="Low" <?= ($priority_selected ?? '') === 'Low' ? 'selected' : '' ?>>Low (7 to 9 days)</option>
                            <option value="Medium" <?= ($priority_selected ?? '') === 'Medium' ? 'selected' : '' ?>>Medium (4 to 6 days)</option>
                            <option value="High" <?= ($priority_selected ?? '') === 'High' ? 'selected' : '' ?>>High (1 to 3 days)</option>
                        </select>
                        <button type="button" id="priorityDropdownTrigger" class="priority-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false"<?= $initialSalesRoutingComplete ? '' : ' disabled'; ?>>Choose level of urgency</button>
                        <div id="priorityDropdownMenu" class="priority-dropdown-menu" role="listbox" aria-labelledby="priorityDropdownTrigger">
                            <button type="button" class="priority-dropdown-option<?= ($priority_selected ?? '') === 'Low' ? ' is-selected' : '' ?>" data-value="Low" role="option" aria-selected="<?= ($priority_selected ?? '') === 'Low' ? 'true' : 'false' ?>">LOW (7 to 9 days)</button>
                            <button type="button" class="priority-dropdown-option<?= ($priority_selected ?? '') === 'Medium' ? ' is-selected' : '' ?>" data-value="Medium" role="option" aria-selected="<?= ($priority_selected ?? '') === 'Medium' ? 'true' : 'false' ?>">Medium (4 to 6 days)</button>
                            <button type="button" class="priority-dropdown-option<?= ($priority_selected ?? '') === 'High' ? ' is-selected' : '' ?>" data-value="High" role="option" aria-selected="<?= ($priority_selected ?? '') === 'High' ? 'true' : 'false' ?>">High (1 to 3 days)</button>
                        </div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>
            </div>

            <div class="request-grid-row is-single" id="marketingSubcategoryRow" style="display:none;">
                <div class="form-group hr-extra-group" id="marketingSubcategoryContainer">
                    <label>Request Type <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper marketing-subcategory-dropdown" id="marketingSubcategoryDropdown">
                        <select name="marketing_subcategory" id="marketing_subcategory" class="form-control category-select marketing-subcategory-native-select" disabled data-selected="<?= htmlspecialchars((string) ($_POST['marketing_subcategory'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled hidden selected>Choose request type</option>
                        </select>
                        <button type="button" id="marketingSubcategoryDropdownTrigger" class="marketing-subcategory-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false" disabled>Choose request type</button>
                        <div id="marketingSubcategoryDropdownMenu" class="marketing-subcategory-dropdown-menu" role="listbox" aria-labelledby="marketingSubcategoryDropdownTrigger"></div>
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
                        <div class="select-wrapper concern-type-dropdown" id="concernTypeDropdown">
                            <select name="hr_concern_type" id="hr_concern_type" class="form-control category-select category-native-select" data-selected="<?= htmlspecialchars((string) ($hr_concern_type ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <option value="" disabled hidden <?= ($hr_concern_type ?? '') === '' ? 'selected' : '' ?>>Choose type of concern</option>
                                <option value="KAMI Error: Check IN/OUT" <?= ($hr_concern_type ?? '') === 'KAMI Error: Check IN/OUT' ? 'selected' : '' ?>>KAMI Error: Check IN/OUT</option>
                                <option value="KAMI Error: Failed log in attempts" <?= ($hr_concern_type ?? '') === 'KAMI Error: Failed log in attempts' ? 'selected' : '' ?>>KAMI Error: Failed log in attempts</option>
                                <option value="Unpaid salary" <?= ($hr_concern_type ?? '') === 'Unpaid salary' ? 'selected' : '' ?>>Unpaid salary</option>
                                <option value="Unpaid leave/overtime pay" <?= ($hr_concern_type ?? '') === 'Unpaid leave/overtime pay' ? 'selected' : '' ?>>Unpaid leave/overtime pay</option>
                                <option value="Other" <?= ($hr_concern_type ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                            <button type="button" id="concernTypeDropdownTrigger" class="concern-type-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false">Choose type of concern</button>
                            <div id="concernTypeDropdownMenu" class="concern-type-dropdown-menu" role="listbox" aria-labelledby="concernTypeDropdownTrigger"></div>
                            <i class="fas fa-chevron-down select-icon"></i>
                        </div>
                    </div>
                    <div class="form-group hr-extra-group" id="concernTypeOtherContainer">
                        <label for="hr_concern_type_other">Please specify the type of concern <span class="required-asterisk">*</span></label>
                        <input type="text" name="hr_concern_type_other" id="hr_concern_type_other" class="form-control" value="<?= htmlspecialchars((string) ($hr_concern_type_other ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter type of concern">
                    </div>
                </div>
                <div class="kami-continuation" id="kamiContinuationHost"></div>
            </section>

            <section class="other-request-section" id="otherRequestDetailsSection" style="display:none;">
                <div class="other-request-section-head">Request Details</div>
                <div class="other-request-section-body">
                    <div class="form-group hr-extra-group" id="leaveSubjectContainer">
                        <label id="requestSubjectLabel">Subject/Title of Request <span class="required-asterisk">*</span></label>
                        <input
                            type="text"
                            name="request_subject_title"
                            id="request_subject_title"
                            class="form-control"
                            value="<?= htmlspecialchars((string) ($request_subject_title ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Enter subject/title of request"
                        >
                    </div>
                </div>
                <div class="other-request-continuation" id="otherRequestContinuationHost"></div>
            </section>

            <section class="incident-report-group" id="incidentReportSection">
                <h3 class="incident-report-head">Request Details</h3>
                <div class="incident-report-list">
                    <section class="incident-report-card">
                        <div class="form-group">
                            <label for="incident_summary">Short Summary of IR  (Upload file with signature)<span class="required-asterisk">*</span></label>
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
                                    <select name="certificate_leave_purpose" id="certificate_leave_purpose" class="form-control">
                                        <option value="" disabled hidden <?= (($_POST['certificate_leave_purpose'] ?? '') === '') ? 'selected' : ''; ?>>Choose purpose of leave</option>
                                        <option value="Travel" <?= (($_POST['certificate_leave_purpose'] ?? '') === 'Travel') ? 'selected' : ''; ?>>Travel</option>
                                        <option value="Others" <?= (($_POST['certificate_leave_purpose'] ?? '') === 'Others') ? 'selected' : ''; ?>>Others</option>
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
                    <?php $visibleSapEntry = $sapFormEntries[count($sapFormEntries) - 1] ?? sales_request_blank_sap_report(); ?>
                    <div id="sapSavedReportsHost">
                        <?php for ($savedSapIndex = 0; $savedSapIndex < max(0, count($sapFormEntries) - 1); $savedSapIndex += 1): ?>
                            <?php $savedSapEntry = $sapFormEntries[$savedSapIndex]; ?>
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][name]" value="<?= htmlspecialchars((string) ($savedSapEntry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][position]" value="<?= htmlspecialchars((string) ($savedSapEntry['position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][address]" value="<?= htmlspecialchars((string) ($savedSapEntry['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][department]" value="<?= htmlspecialchars((string) ($savedSapEntry['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][tin]" value="<?= htmlspecialchars((string) ($savedSapEntry['tin'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endfor; ?>
                    </div>
                    <section class="sap-request-card sap-employee-card is-active" data-sap-card>
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
                                        <label for="sap_name_current">Name <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][name]" id="sap_name_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="name">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_position_current">Position</label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][position]" id="sap_position_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="position">
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_address_current">Address</label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][address]" id="sap_address_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="address">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_department_current">Department</label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][department]" id="sap_department_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="department">
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_tin_current">TIN</label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][tin]" id="sap_tin_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['tin'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="tin">
                                    </div>
                                </section>
                            </div>
                        </section>
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
                                <div class="select-wrapper marketing-materials-dropdown" id="areaCodeGroup">
                                    <select name="area_code" id="area_code" class="form-control category-select marketing-materials-dropdown-native" data-selected="<?= htmlspecialchars((string) ($_POST['area_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="" disabled selected hidden>Choose area code</option>
                                        <?php foreach (['811A', '811B', '812', '813A', '813B', '814A', '814B', '815A', '815B', '815C', '821A', '821B', '821C', '822A', '822B', '831A', '831B', '832A', '832B', '833', 'HEAD OFFICE'] as $areaCodeOption): ?>
                                            <option value="<?= htmlspecialchars($areaCodeOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['area_code'] ?? '') === $areaCodeOption) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($areaCodeOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="marketing-materials-dropdown-trigger" id="areaCodeTrigger" aria-haspopup="listbox" aria-expanded="false"><span id="areaCodeTriggerValue">Choose area code</span></button>
                                    <div class="marketing-materials-dropdown-menu" id="areaCodeMenu" role="listbox" hidden></div>
                                    <i class="fas fa-chevron-down select-icon"></i>
                                </div>
                            </div>
                        </section>
                        <section class="marketing-request-card">
                            <div class="form-group">
                                <label for="marketing_department">Department <span class="required-asterisk">*</span></label>
                                <div class="select-wrapper marketing-materials-dropdown" id="marketingDepartmentGroup">
                                    <select name="marketing_department" id="marketing_department" class="form-control category-select marketing-materials-dropdown-native" data-selected="<?= htmlspecialchars((string) ($_POST['marketing_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="" disabled selected hidden>Choose department</option>
                                        <?php foreach (['Marketing Ops', 'Sales', 'Technical', 'Human Resources', 'PCC/GPCI', 'Farmex', 'Farmasee', 'LTC', 'MPDC', 'IT', 'Admin', 'Leads AH/EH', 'Executive/Management'] as $marketingDepartmentOption): ?>
                                            <option value="<?= htmlspecialchars($marketingDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['marketing_department'] ?? '') === $marketingDepartmentOption) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($marketingDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="marketing-materials-dropdown-trigger" id="marketingDepartmentTrigger" aria-haspopup="listbox" aria-expanded="false"><span id="marketingDepartmentTriggerValue">Choose department</span></button>
                                    <div class="marketing-materials-dropdown-menu" id="marketingDepartmentMenu" role="listbox" hidden></div>
                                    <i class="fas fa-chevron-down select-icon"></i>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section class="marketing-request-card">
                        <div class="form-group">
                            <label for="requested_materials">Requested Materials <span class="required-asterisk">*</span></label>
                            <?php $selectedRequestedMaterials = sales_request_clean_string_array($_POST['requested_materials'] ?? []); ?>
                            <div class="select-wrapper marketing-materials-dropdown" id="requestedMaterialsGroup">
                                <select name="requested_materials[]" id="requested_materials" class="form-control marketing-materials-dropdown-native">
                                    <option value="" disabled <?= count($selectedRequestedMaterials) === 0 ? 'selected' : ''; ?> hidden>Choose requested material</option>
                                    <?php foreach (['Social Media Graphics', 'Print Materials (Flyers, Brochures)', 'Video (Short-form)', 'Banners/Taffetas', 'Labels', 'Tarpaulin/Poster', 'Invitation', 'Coupons', 'Sintraboard design', 'Plotsigns', 'Promats Design (shirt, cap, etc)', 'Other'] as $materialOption): ?>
                                        <option value="<?= htmlspecialchars($materialOption, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($materialOption, $selectedRequestedMaterials, true) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($materialOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="marketing-materials-dropdown-trigger" id="requestedMaterialsTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span id="requestedMaterialsTriggerValue">Choose requested material</span>
                                </button>
                                <div class="marketing-materials-dropdown-menu" id="requestedMaterialsMenu" role="listbox" hidden></div>
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
                                    <?php $selectedCrops = sales_request_clean_string_array($_POST['crop'] ?? []); ?>
                                    <div class="select-wrapper marketing-materials-dropdown" id="cropGroup">
                                        <select name="crop[]" id="crop" class="form-control marketing-materials-dropdown-native">
                                            <option value="" disabled <?= count($selectedCrops) === 0 ? 'selected' : ''; ?> hidden>Choose crop</option>
                                            <?php foreach (['Rice', 'Lowland Vegetable', 'Upland Vegetable', 'Sugarcane', 'Corn', 'Mango', 'Other'] as $cropOption): ?>
                                                <option value="<?= htmlspecialchars($cropOption, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($cropOption, $selectedCrops, true) ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($cropOption, ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="marketing-materials-dropdown-trigger" id="cropTrigger" aria-haspopup="listbox" aria-expanded="false"><span id="cropTriggerValue">Choose crop</span></button>
                                        <div class="marketing-materials-dropdown-menu" id="cropMenu" role="listbox" hidden></div>
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
                        <p class="sss-benefits-card-copy">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).</p>
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
                        <p class="sss-benefits-card-copy">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).</p>
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
                        <p class="sss-benefits-card-copy">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).</p>
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
                        <p class="sss-benefits-card-copy">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).</p>
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
                        <p class="sss-benefits-card-copy">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).</p>
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

            <section class="email-request-group" id="emailRequestSection">
                <h3 class="email-request-head">Email Request</h3>
                <div class="email-request-list">
                    <section class="email-request-card">
                        <div class="form-group">
                            <label for="email_request_type">Email Request Type <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper email-request-type-dropdown" id="emailRequestTypeDropdown">
                                <select name="email_request_type" id="email_request_type" class="form-control email-request-type-native-select" data-selected="<?= htmlspecialchars((string) ($_POST['email_request_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose email request type</option>
                                    <option value="creation of email" <?= (($_POST['email_request_type'] ?? '') === 'creation of email') ? 'selected' : ''; ?>>Creation of email</option>
                                    <option value="forgot password" <?= (($_POST['email_request_type'] ?? '') === 'forgot password') ? 'selected' : ''; ?>>Forgot password</option>
                                    <option value="backup of email" <?= (($_POST['email_request_type'] ?? '') === 'backup of email') ? 'selected' : ''; ?>>Backup of email</option>
                                </select>
                                <button type="button" class="email-request-type-dropdown-trigger is-placeholder" id="emailRequestTypeDropdownTrigger" aria-haspopup="listbox" aria-expanded="false">Choose email request type</button>
                                <i class="fas fa-chevron-down select-icon"></i>
                                <div class="email-request-type-dropdown-menu" id="emailRequestTypeDropdownMenu" role="listbox"></div>
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

            <section class="other-request-section" id="otherDescriptionSection" style="display:block;">
                <div class="other-request-section-body">
                    <div class="form-group" id="descriptionContainer">
                        <label id="descriptionLabel">Description <span class="required-asterisk">*</span></label>
                        <textarea name="description" id="descriptionField" rows="5" required placeholder="Describe your issue in detail..."><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div id="attachmentOriginalHost"></div>
                    <div class="form-group" id="attachmentContainer">
                        <label><span id="attachmentLabelText">Attachment</span> <span id="attachmentOptionalText">(Optional)</span><span id="attachmentRequiredAsterisk" class="required-asterisk" style="display:none;">*</span></label>
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
                        <small id="attachmentHelpText" class="form-text attachment-help-text">Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)</small>
                        <div id="attachment-error" style="display:none;margin-top:10px;background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;font-weight:700;"></div>
                        <div id="attachment-toast" role="alert" aria-live="assertive" style="position:fixed;top:18px;right:18px;z-index:9999;display:none;max-width:min(420px, calc(100vw - 36px));background:#991b1b;color:#ffffff;padding:12px 14px;border-radius:12px;box-shadow:0 16px 40px rgba(2,6,23,0.22);font-weight:800;font-size:13px;"></div>
                        <div id="attachment-preview" style="margin-top: 10px;"></div>
                    </div>
                </div>
            </section>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Submit Ticket</button>
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

    <?php endif; ?>
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
        <div class="ticket-modal-icon success" id="ticketModalSuccessIcon"><i class="fas fa-check" aria-hidden="true"></i></div>
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

<script>
var recipient = document.getElementById('ticket_recipient');
var recipientRow = document.getElementById('recipientRow');
var departmentGroup = document.getElementById('departmentGroup');
var recipientGroup = document.getElementById('recipientGroup');
var recipientDropdown = document.getElementById('recipientDropdown');
var recipientTrigger = document.getElementById('recipientDropdownTrigger');
var recipientMenu = document.getElementById('recipientDropdownMenu');
var departmentSelect = document.getElementById('department');
var departmentDropdown = document.getElementById('departmentDropdown');
var departmentTrigger = document.getElementById('departmentDropdownTrigger');
var departmentMenu = document.getElementById('departmentDropdownMenu');
var salesCategoryRow = document.getElementById('salesCategoryRow');
var categorySelect = document.getElementById('sales_category');
var categoryDropdown = document.getElementById('categoryDropdown');
var categoryTrigger = document.getElementById('categoryDropdownTrigger');
var categoryMenu = document.getElementById('categoryDropdownMenu');
var categoryContainer = document.getElementById('categoryContainer');
var adminLegalRequestForContainer = document.getElementById('adminLegalRequestForContainer');
var adminLegalRequestForSelect = document.getElementById('admin_legal_request_for');
var adminLegalRequestForDropdown = document.getElementById('adminLegalRequestForDropdown');
var adminLegalRequestForTrigger = document.getElementById('adminLegalRequestForDropdownTrigger');
var adminLegalRequestForMenu = document.getElementById('adminLegalRequestForDropdownMenu');
var priorityGroup = document.getElementById('priorityGroup');
var prioritySelect = document.getElementById('sales_priority');
var salesPositionSelect = document.getElementById('sales_position');
var salesRegionSelect = document.getElementById('sales_region');
var salesPositionDropdown = document.getElementById('salesPositionDropdown');
var salesPositionTrigger = document.getElementById('salesPositionDropdownTrigger');
var salesPositionMenu = document.getElementById('salesPositionDropdownMenu');
var salesRegionDropdown = document.getElementById('salesRegionDropdown');
var salesRegionTrigger = document.getElementById('salesRegionDropdownTrigger');
var salesRegionMenu = document.getElementById('salesRegionDropdownMenu');
var priorityDropdown = document.getElementById('priorityDropdown');
var priorityTrigger = document.getElementById('priorityDropdownTrigger');
var priorityMenu = document.getElementById('priorityDropdownMenu');
var kamiBannerContainer = document.getElementById('kamiBannerContainer');
var concernTypeContainer = document.getElementById('concernTypeContainer');
var concernTypeSelect = document.getElementById('hr_concern_type');
var concernTypeDropdown = document.getElementById('concernTypeDropdown');
var concernTypeTrigger = document.getElementById('concernTypeDropdownTrigger');
var concernTypeMenu = document.getElementById('concernTypeDropdownMenu');
var concernTypeOtherContainer = document.getElementById('concernTypeOtherContainer');
var concernTypeOtherInput = document.getElementById('hr_concern_type_other');
var leaveSubjectContainer = document.getElementById('leaveSubjectContainer');
var leaveSubjectInput = document.getElementById('request_subject_title');
var medicalCashAdvanceSection = document.getElementById('medicalCashAdvanceSection');
var medicalCashPurposeInput = document.getElementById('medical_cash_purpose');
var medicalCashAmountInput = document.getElementById('medical_cash_amount');
var medicalCashDateNeededInput = document.getElementById('medical_cash_date_needed');
var medicalCashAttachmentHost = document.getElementById('medicalCashAttachmentHost');
var incidentReportSection = document.getElementById('incidentReportSection');
var incidentReportAttachmentHost = document.getElementById('incidentReportAttachmentHost');
var incidentSummaryInput = document.getElementById('incident_summary');
var incidentGdriveLinkInput = document.getElementById('incident_gdrive_link');
var trainingRequestSection = document.getElementById('trainingRequestSection');
var trainingRequestTitleInput = document.getElementById('training_request_title');
var trainingRequestProviderInput = document.getElementById('training_request_provider');
var trainingRequestStartDateInput = document.getElementById('training_request_start_date');
var trainingRequestEndDateInput = document.getElementById('training_request_end_date');
var trainingRequestVenueInput = document.getElementById('training_request_venue');
var trainingRequestFeeInput = document.getElementById('training_request_fee');
var companyPropertySection = document.getElementById('companyPropertySection');
var companyPropertyTypeInputs = Array.from(document.querySelectorAll('input[name="company_property_type"]'));
var companyPropertyReasonInputs = Array.from(document.querySelectorAll('input[name="company_property_reason"]'));
var coeRequestSection = document.getElementById('coeRequestSection');
var coeRequestReasonInputs = Array.from(document.querySelectorAll('input[name="coe_request_reason"]'));
var coeRequestReasonOtherInput = document.getElementById('coe_request_reason_other');
var coeSalaryDetailsInputs = Array.from(document.querySelectorAll('input[name="coe_salary_details"]'));
var coePreferredReleaseDateInput = document.getElementById('coe_preferred_release_date');
var coeDeliveryMethodInputs = Array.from(document.querySelectorAll('input[name="coe_delivery_method"]'));
var coeRemarksInput = document.getElementById('coe_remarks');
var colRequestSection = document.getElementById('colRequestSection');
var certificateLeaveDateInput = document.getElementById('certificate_leave_date');
var certificateLeavePurposeSelect = document.getElementById('certificate_leave_purpose');
var certificateLeavePurposeOtherContainer = document.getElementById('certificateLeavePurposeOtherContainer');
var certificateLeavePurposeOtherInput = document.getElementById('certificate_leave_purpose_other');
var sapRequestSection = document.getElementById('sapRequestSection');
var sapRequestList = document.getElementById('sapRequestList');
var sapSavedReportsHost = document.getElementById('sapSavedReportsHost');
var sapAddEmployeeBtn = document.getElementById('sapAddEmployeeBtn');
var sapEmployeeSwitcher = document.getElementById('sapEmployeeSwitcher');
var sapRequestCounter = document.getElementById('sapRequestCounter');
var emailRequestSection = document.getElementById('emailRequestSection');
var emailDescriptionHost = document.getElementById('emailDescriptionHost');
var marketingDescriptionHost = document.getElementById('marketingDescriptionHost');
var emailRequestTypeSelect = document.getElementById('email_request_type');
var emailRequestTypeDropdown = document.getElementById('emailRequestTypeDropdown');
var emailRequestTypeTrigger = document.getElementById('emailRequestTypeDropdownTrigger');
var emailRequestTypeMenu = document.getElementById('emailRequestTypeDropdownMenu');
var emailCreationFields = document.getElementById('emailCreationFields');
var emailRequestList = document.getElementById('emailRequestList');
var emailCreationTemplate = document.getElementById('emailCreationTemplate');
var emailEmployeeSwitcher = document.getElementById('emailEmployeeSwitcher');
var emailRequestCounter = document.getElementById('emailRequestCounter');
var emailCreationInputs = Array.from(document.querySelectorAll('[data-email-field]'));
var marketingRequestSection = document.getElementById('marketingRequestSection');
var projectNameInput = document.getElementById('project_name');
var areaCodeSelect = document.getElementById('area_code');
var marketingDepartmentSelect = document.getElementById('marketing_department');
var requestedMaterialsSelect = document.getElementById('requested_materials');
var requestedMaterialsWrapper = document.getElementById('requestedMaterialsGroup');
var requestedMaterialsTrigger = document.getElementById('requestedMaterialsTrigger');
var requestedMaterialsTriggerValue = document.getElementById('requestedMaterialsTriggerValue');
var requestedMaterialsMenu = document.getElementById('requestedMaterialsMenu');
var requestedMaterialsInputs = requestedMaterialsSelect ? [requestedMaterialsSelect] : Array.from(document.querySelectorAll('input[name="requested_materials[]"]'));
var requestedMaterialsOtherRow = document.getElementById('requestedMaterialsOtherRow');
var requestedMaterialsOtherInput = document.getElementById('requested_materials_other');
var materialSizeInput = document.getElementById('material_size');
var materialSizeUnitInputs = Array.from(document.querySelectorAll('input[name="material_size_unit"]'));
var materialSizeValueInputs = Array.from(document.querySelectorAll('input[name="material_size_value"]'));
var projectDeadlineInput = document.getElementById('project_deadline');
var projectDeadlineHelp = document.getElementById('projectDeadlineHelp');
var projectDeadlineError = document.getElementById('projectDeadlineError');
var cropSelect = document.getElementById('crop');
var cropInputs = cropSelect ? [cropSelect] : Array.from(document.querySelectorAll('input[name="crop[]"]'));
var cropOtherRow = document.getElementById('cropOtherRow');
var cropOtherInput = document.getElementById('crop_other');
var otherRequestDetailsSection = document.getElementById('otherRequestDetailsSection');
var otherDescriptionSection = document.getElementById('otherDescriptionSection');
var otherDescriptionSectionBody = otherDescriptionSection ? otherDescriptionSection.querySelector('.other-request-section-body') : null;
var kamiContinuationHost = document.getElementById('kamiContinuationHost');
var otherRequestContinuationHost = document.getElementById('otherRequestContinuationHost');
var requestSubjectLabel = document.getElementById('requestSubjectLabel');
var descriptionLabel = document.getElementById('descriptionLabel');
var sssBenefitsContainer = document.getElementById('sssBenefitsContainer');
var descriptionContainer = document.getElementById('descriptionContainer');
var descriptionFieldEl = document.getElementById('descriptionField');
var attachmentOriginalHost = document.getElementById('attachmentOriginalHost');
var attachmentContainer = document.getElementById('attachmentContainer');
var attachmentLabelText = document.getElementById('attachmentLabelText');
var medicalCashAttachmentIntro = document.getElementById('medicalCashAttachmentIntro');
var attachmentOptionalText = document.getElementById('attachmentOptionalText');
var attachmentRequiredAsterisk = document.getElementById('attachmentRequiredAsterisk');
var attachmentHelpText = document.getElementById('attachmentHelpText');
var chooseFileBtnText = document.getElementById('chooseFileBtnText');
var ajaxErrorBanner = document.getElementById('ajaxError');
var priorityLabel = priorityGroup ? priorityGroup.querySelector('label') : null;
var marketingSubcategoryRow = document.getElementById('marketingSubcategoryRow');
var marketingSubcategoryContainer = document.getElementById('marketingSubcategoryContainer');
var marketingSubcategorySelect = document.getElementById('marketing_subcategory');
var marketingSubcategoryDropdown = document.getElementById('marketingSubcategoryDropdown');
var marketingSubcategoryTrigger = document.getElementById('marketingSubcategoryDropdownTrigger');
var marketingSubcategoryMenu = document.getElementById('marketingSubcategoryDropdownMenu');
var supplyChainDetailsRow = document.getElementById('supplyChainDetailsRow');
var supplyChainDetailsFields = document.getElementById('supplyChainDetailsFields');
var supplyChainAttachmentHost = document.getElementById('supplyChainAttachmentHost');
var lapcDepartments = <?= json_encode(array_values($lapcDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var mhcDepartments = <?= json_encode(array_values($mhcDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var emailCreationDepartmentOptionsBySubsidiary = {
    '@leadsagri.com': lapcDepartments,
    '@malvedaholdings.com': mhcDepartments
};
var defaultCategories = <?= json_encode($defaultCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var mpdcCategories = <?= json_encode($mpdcCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lingapCategories = <?= json_encode($lingapCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var othersOnlyCompanyDomains = <?= json_encode($othersOnlyCompanyDomains, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lapcDepartmentCategories = <?= json_encode($lapcDepartmentCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lapcAdminLegalRequestCategories = <?= json_encode($lapcAdminLegalRequestCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var mhcDepartmentCategories = <?= json_encode($mhcDepartmentCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lapcSupplyChainRequestTypes = <?= json_encode($lapcSupplyChainRequestTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lapcSupplyChainDetailFields = <?= json_encode($lapcSupplyChainDetailFields, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var savedSupplyChainDetails = <?= json_encode($_POST['supply_chain_details'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lapcMarketingSubcategories = <?= json_encode([
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
var sssAutoDescription = 'SSS Notification and Benefits Concern submission.';
var sssUploadConfigs = [
    { inputId: 'sssSicknessFormInput', labelId: 'sssSicknessFormName', listId: 'sssSicknessFormList', errorId: 'sssSicknessFormError', label: 'Accomplished SSS Sickness Form', maxFiles: 1 },
    { inputId: 'sssMedicalProceduresInput', labelId: 'sssMedicalProceduresName', listId: 'sssMedicalProceduresList', errorId: 'sssMedicalProceduresError', label: 'Medical Procedures', maxFiles: 5 },
    { inputId: 'sssLaboratoryResultsInput', labelId: 'sssLaboratoryResultsName', listId: 'sssLaboratoryResultsList', errorId: 'sssLaboratoryResultsError', label: 'Laboratory Results', maxFiles: 5 },
    { inputId: 'sssMedicalCertificatesInput', labelId: 'sssMedicalCertificatesName', listId: 'sssMedicalCertificatesList', errorId: 'sssMedicalCertificatesError', label: 'Medical Certificates', maxFiles: 5 },
    { inputId: 'sssDischargeSummaryInput', labelId: 'sssDischargeSummaryName', listId: 'sssDischargeSummaryList', errorId: 'sssDischargeSummaryError', label: 'Discharge Summary/Proof', maxFiles: 5 }
];
var sssUploadState = {};

function getSapCards() {
    if (!sapRequestList) return [];
    return Array.from(sapRequestList.querySelectorAll('[data-sap-card]'));
}

function getCurrentSapCard() {
    var cards = getSapCards();
    return cards.length > 0 ? cards[0] : null;
}

function getSapField(fieldName) {
    var currentCard = getCurrentSapCard();
    return currentCard ? currentCard.querySelector('[data-sap-field="' + fieldName + '"]') : null;
}

function getCurrentSapReportValues() {
    return {
        name: String((getSapField('name') || {}).value || '').trim(),
        position: String((getSapField('position') || {}).value || '').trim(),
        address: String((getSapField('address') || {}).value || '').trim(),
        department: String((getSapField('department') || {}).value || '').trim(),
        tin: String((getSapField('tin') || {}).value || '').trim()
    };
}

function cloneSapReport(report) {
    var source = report || {};
    return {
        name: String(source.name || ''),
        position: String(source.position || ''),
        address: String(source.address || ''),
        department: String(source.department || ''),
        tin: String(source.tin || '')
    };
}

function getFirstIncompleteCurrentSapField() {
    var currentCard = getCurrentSapCard();
    if (!currentCard) return null;
    var fieldOrder = ['name'];
    for (var index = 0; index < fieldOrder.length; index += 1) {
        var input = currentCard.querySelector('[data-sap-field="' + fieldOrder[index] + '"]');
        if (input && !String(input.value || '').trim()) {
            return input;
        }
    }
    return null;
}

function getSapCardDisplayName(report, index) {
    var displayName = report ? String(report.name || '').trim() : '';
    return displayName !== '' ? displayName : ('Employee ' + (index + 1));
}

var savedSapReports = [];
var currentSapViewKey = 'current';
var currentSapDraft = null;

function createHiddenSapInput(index, fieldName, value) {
    if (!sapSavedReportsHost) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'sap_reports[' + index + '][' + fieldName + ']';
    input.value = value || '';
    sapSavedReportsHost.appendChild(input);
}

function renderSavedSapReports() {
    if (!sapSavedReportsHost) return;
    sapSavedReportsHost.innerHTML = '';
    savedSapReports.forEach(function(report, index) {
        createHiddenSapInput(index, 'name', report.name);
        createHiddenSapInput(index, 'position', report.position);
        createHiddenSapInput(index, 'address', report.address);
        createHiddenSapInput(index, 'department', report.department);
        createHiddenSapInput(index, 'tin', report.tin);
    });
}

function setCurrentSapReportValues(report) {
    var safeReport = report || {};
    var nameInput = getSapField('name');
    var positionInput = getSapField('position');
    var addressInput = getSapField('address');
    var departmentInput = getSapField('department');
    var tinInput = getSapField('tin');

    if (nameInput) nameInput.value = String(safeReport.name || '');
    if (positionInput) positionInput.value = String(safeReport.position || '');
    if (addressInput) addressInput.value = String(safeReport.address || '');
    if (departmentInput) departmentInput.value = String(safeReport.department || '');
    if (tinInput) tinInput.value = String(safeReport.tin || '');
}

function updateCurrentSapFieldNames() {
    var currentIndex = savedSapReports.length;
    ['name', 'position', 'address', 'department', 'tin'].forEach(function(fieldName) {
        var input = getSapField(fieldName);
        if (input) {
            input.name = 'sap_reports[' + currentIndex + '][' + fieldName + ']';
        }
    });
}

function clearCurrentSapForm() {
    ['name', 'position', 'address', 'department', 'tin'].forEach(function(fieldName) {
        var input = getSapField(fieldName);
        if (input) {
            input.value = '';
        }
    });
    currentSapDraft = cloneSapReport(getCurrentSapReportValues());
}

function syncSapCardState() {
    if (currentSapViewKey === 'current') {
        currentSapDraft = cloneSapReport(getCurrentSapReportValues());
    }
    var currentReport = cloneSapReport(currentSapDraft || getCurrentSapReportValues());
    var totalEmployees = savedSapReports.length + 1;
    var currentEmployeeNumber = totalEmployees;
    if (currentSapViewKey !== 'current') {
        var selectedIndex = parseInt(currentSapViewKey, 10);
        if (!isNaN(selectedIndex) && selectedIndex >= 0 && selectedIndex < savedSapReports.length) {
            currentEmployeeNumber = selectedIndex + 1;
        } else {
            currentSapViewKey = 'current';
        }
    }
    if (sapRequestCounter) {
        sapRequestCounter.textContent = 'Employee ' + currentEmployeeNumber + ' of ' + totalEmployees;
    }
    if (sapEmployeeSwitcher) {
        sapEmployeeSwitcher.innerHTML = '';
        savedSapReports.forEach(function(report, reportIndex) {
            var option = document.createElement('option');
            option.value = String(reportIndex);
            option.textContent = getSapCardDisplayName(report, reportIndex);
            option.selected = currentSapViewKey === String(reportIndex);
            sapEmployeeSwitcher.appendChild(option);
        });
        var currentOption = document.createElement('option');
        currentOption.value = 'current';
        currentOption.textContent = getSapCardDisplayName(currentReport, savedSapReports.length);
        currentOption.selected = currentSapViewKey === 'current';
        sapEmployeeSwitcher.appendChild(currentOption);
    }
    var currentCard = getCurrentSapCard();
    if (currentCard) {
        currentCard.classList.add('is-active');
        var removeButtons = Array.from(currentCard.querySelectorAll('[data-remove-sap-report]'));
        removeButtons.forEach(function(button) {
            button.style.display = savedSapReports.length > 0 ? '' : 'none';
        });
    }
    updateCurrentSapFieldNames();
}

function initializeSavedSapReportsFromDom() {
    if (!sapSavedReportsHost) return;
    var grouped = {};
    Array.from(sapSavedReportsHost.querySelectorAll('input[type="hidden"]')).forEach(function(input) {
        var match = input.name.match(/^sap_reports\[(\d+)\]\[(name|position|address|department|tin)\]$/);
        if (!match) return;
        var index = match[1];
        var fieldName = match[2];
        if (!grouped[index]) {
            grouped[index] = { name: '', position: '', address: '', department: '', tin: '' };
        }
        grouped[index][fieldName] = input.value || '';
    });
    savedSapReports = Object.keys(grouped).sort(function(a, b) {
        return parseInt(a, 10) - parseInt(b, 10);
    }).map(function(index) {
        return grouped[index];
    });
    currentSapDraft = cloneSapReport(getCurrentSapReportValues());
    renderSavedSapReports();
}

function saveCurrentSapEmployee() {
    var incompleteField = getFirstIncompleteCurrentSapField();
    if (incompleteField) {
        showSapAddEmployeeError(incompleteField);
        return false;
    }
    var report = getCurrentSapReportValues();
    savedSapReports.push(report);
    renderSavedSapReports();
    clearCurrentSapForm();
    currentSapViewKey = 'current';
    currentSapDraft = cloneSapReport(getCurrentSapReportValues());
    syncSapCardState();
    var firstInput = getSapField('name');
    if (firstInput) {
        firstInput.focus();
    }
    setInlineFormError('');
    return true;
}

function removeLastSavedSapEmployee() {
    if (savedSapReports.length === 0) return;
    savedSapReports.pop();
    renderSavedSapReports();
    currentSapViewKey = 'current';
    currentSapDraft = cloneSapReport(getCurrentSapReportValues());
    syncSapCardState();
}

function loadSavedSapReportIntoCurrentForm(reportIndex) {
    if (currentSapViewKey === 'current') {
        currentSapDraft = cloneSapReport(getCurrentSapReportValues());
    }
    var numericIndex = parseInt(reportIndex, 10);
    if (isNaN(numericIndex) || numericIndex < 0 || numericIndex >= savedSapReports.length) {
        currentSapViewKey = 'current';
        setCurrentSapReportValues(cloneSapReport(currentSapDraft || getCurrentSapReportValues()));
        syncSapCardState();
        return;
    }
    currentSapViewKey = String(numericIndex);
    setCurrentSapReportValues(savedSapReports[numericIndex]);
    syncSapCardState();
}

function showSapAddEmployeeError(field) {
    setInlineFormError('Please complete the current SAP employee report before adding another employee.');
    if (sapRequestSection) {
        sapRequestSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (field) {
        try { field.focus(); } catch (focusError) {}
    }
}

function addSapCard() {
    return saveCurrentSapEmployee();
}

function syncRequestGridRows() {
    if (recipientRow && departmentGroup) {
        var departmentVisible = !departmentGroup.classList.contains('hidden') && window.getComputedStyle(departmentGroup).display !== 'none';
        recipientRow.classList.toggle('is-single', !departmentVisible);
    }
    if (salesCategoryRow && categoryContainer && priorityGroup) {
        var urgencyVisible = !priorityGroup.classList.contains('hidden');
        salesCategoryRow.classList.toggle('is-single', !urgencyVisible);
    }
}

function isLapcRecipientValue(value) {
    return normalizeRecipientCompany(value) === '@leadsagri.com';
}

function isMhcRecipientValue(value) {
    return normalizeRecipientCompany(value) === '@malvedaholdings.com';
}

function normalizeRecipientCompany(value) {
    var raw = String(value || '').trim();
    var lower = raw.toLowerCase();
    var domainMatch = raw.match(/\((@\S+)\)/);
    if (domainMatch && domainMatch[1]) {
        return String(domainMatch[1]).trim().toLowerCase();
    }
    if (lower.indexOf('@') === 0) return lower;
    if (lower === 'lapc' || lower.indexOf('leadsagri.com') !== -1 || lower.indexOf('leads agricultural products') !== -1) {
        return '@leadsagri.com';
    }
    if (lower === 'mhc' || lower.indexOf('malvedaholdings.com') !== -1 || lower.indexOf('malveda holdings') !== -1) {
        return '@malvedaholdings.com';
    }
    return lower;
}

function closeRequestedMaterialsDropdown() {
    if (!requestedMaterialsWrapper || !requestedMaterialsTrigger || !requestedMaterialsMenu) return;
    requestedMaterialsWrapper.classList.remove('is-open');
    requestedMaterialsTrigger.setAttribute('aria-expanded', 'false');
    requestedMaterialsMenu.hidden = true;
}

function syncRequestedMaterialsTriggerLabel() {
    if (!requestedMaterialsSelect || !requestedMaterialsTriggerValue) return;
    var selectedOption = requestedMaterialsSelect.options[requestedMaterialsSelect.selectedIndex];
    requestedMaterialsTriggerValue.textContent = selectedOption && selectedOption.value
        ? String(selectedOption.textContent || '').trim()
        : 'Choose requested material';
}

function renderRequestedMaterialsDropdownOptions() {
    if (!requestedMaterialsSelect || !requestedMaterialsMenu) return;
    var currentValue = String(requestedMaterialsSelect.value || '');
    requestedMaterialsMenu.innerHTML = '';
    Array.from(requestedMaterialsSelect.options).forEach(function(option) {
        if (!option.value) return;
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'marketing-materials-dropdown-option' + (currentValue === option.value ? ' is-selected' : '');
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', currentValue === option.value ? 'true' : 'false');
        item.textContent = String(option.textContent || option.value).trim();
        item.addEventListener('click', function() {
            requestedMaterialsSelect.value = option.value;
            requestedMaterialsSelect.dispatchEvent(new Event('change', { bubbles: true }));
            closeRequestedMaterialsDropdown();
            requestedMaterialsTrigger.focus();
        });
        requestedMaterialsMenu.appendChild(item);
    });
    syncRequestedMaterialsTriggerLabel();
}

function setupRequestedMaterialsDropdown() {
    if (!requestedMaterialsWrapper || !requestedMaterialsTrigger || !requestedMaterialsMenu || !requestedMaterialsSelect) return;
    requestedMaterialsTrigger.addEventListener('click', function() {
        var shouldOpen = requestedMaterialsMenu.hidden;
        closeRequestedMaterialsDropdown();
        if (!shouldOpen || requestedMaterialsSelect.disabled) return;
        renderRequestedMaterialsDropdownOptions();
        requestedMaterialsWrapper.classList.add('is-open');
        requestedMaterialsTrigger.setAttribute('aria-expanded', 'true');
        requestedMaterialsMenu.hidden = false;
    });
    requestedMaterialsSelect.addEventListener('change', function() {
        syncRequestedMaterialsTriggerLabel();
        renderRequestedMaterialsDropdownOptions();
    });
    document.addEventListener('click', function(event) {
        if (!requestedMaterialsWrapper.contains(event.target)) closeRequestedMaterialsDropdown();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeRequestedMaterialsDropdown();
    });
    renderRequestedMaterialsDropdownOptions();
}

setupRequestedMaterialsDropdown();

function setupMarketingSelectDropdown(selectId, wrapperId, triggerId, valueId, menuId, placeholder) {
    var select = document.getElementById(selectId);
    var wrapper = document.getElementById(wrapperId);
    var trigger = document.getElementById(triggerId);
    var value = document.getElementById(valueId);
    var menu = document.getElementById(menuId);
    if (!select || !wrapper || !trigger || !value || !menu) return;

    function close() {
        wrapper.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        menu.hidden = true;
    }
    function syncLabel() {
        var option = select.options[select.selectedIndex];
        value.textContent = option && option.value ? String(option.textContent || '').trim() : placeholder;
    }
    function render() {
        var currentValue = String(select.value || '');
        menu.innerHTML = '';
        Array.from(select.options).forEach(function(option) {
            if (!option.value) return;
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'marketing-materials-dropdown-option' + (currentValue === option.value ? ' is-selected' : '');
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', currentValue === option.value ? 'true' : 'false');
            item.textContent = String(option.textContent || option.value).trim();
            item.addEventListener('click', function() {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                close();
                trigger.focus();
            });
            menu.appendChild(item);
        });
        syncLabel();
        trigger.disabled = !!select.disabled;
    }
    trigger.addEventListener('click', function() {
        var shouldOpen = menu.hidden;
        close();
        if (!shouldOpen || select.disabled) return;
        render();
        wrapper.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        menu.hidden = false;
    });
    select.addEventListener('change', function() {
        syncLabel();
        render();
    });
    document.addEventListener('click', function(event) {
        if (!wrapper.contains(event.target)) close();
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') close();
    });
    render();
}

setupMarketingSelectDropdown('area_code', 'areaCodeGroup', 'areaCodeTrigger', 'areaCodeTriggerValue', 'areaCodeMenu', 'Choose area code');
setupMarketingSelectDropdown('marketing_department', 'marketingDepartmentGroup', 'marketingDepartmentTrigger', 'marketingDepartmentTriggerValue', 'marketingDepartmentMenu', 'Choose department');
setupMarketingSelectDropdown('crop', 'cropGroup', 'cropTrigger', 'cropTriggerValue', 'cropMenu', 'Choose crop');

function closeDepartmentDropdown() {
    if (!departmentMenu || !departmentTrigger) return;
    departmentMenu.classList.remove('is-open');
    departmentTrigger.setAttribute('aria-expanded', 'false');
}

function closeCategoryDropdown() {
    if (!categoryMenu || !categoryTrigger) return;
    categoryMenu.classList.remove('is-open');
    categoryTrigger.setAttribute('aria-expanded', 'false');
}

function closeAdminLegalRequestForDropdown() {
    if (!adminLegalRequestForMenu || !adminLegalRequestForTrigger) return;
    adminLegalRequestForMenu.classList.remove('is-open');
    adminLegalRequestForTrigger.setAttribute('aria-expanded', 'false');
}

function closeMarketingSubcategoryDropdown() {
    if (!marketingSubcategoryMenu || !marketingSubcategoryTrigger) return;
    marketingSubcategoryMenu.classList.remove('is-open');
    marketingSubcategoryTrigger.setAttribute('aria-expanded', 'false');
}

function closePriorityDropdown() {
    if (!priorityMenu || !priorityTrigger) return;
    priorityMenu.classList.remove('is-open');
    priorityTrigger.setAttribute('aria-expanded', 'false');
}

function closeEmailRequestTypeDropdown() {
    if (!emailRequestTypeMenu || !emailRequestTypeTrigger) return;
    emailRequestTypeMenu.classList.remove('is-open');
    emailRequestTypeTrigger.setAttribute('aria-expanded', 'false');
}

function closeSalesPositionDropdown() {
    if (!salesPositionMenu || !salesPositionTrigger) return;
    salesPositionMenu.classList.remove('is-open');
    salesPositionTrigger.setAttribute('aria-expanded', 'false');
}

function closeSalesRegionDropdown() {
    if (!salesRegionMenu || !salesRegionTrigger) return;
    salesRegionMenu.classList.remove('is-open');
    salesRegionTrigger.setAttribute('aria-expanded', 'false');
}

function closeConcernTypeDropdown() {
    if (!concernTypeMenu || !concernTypeTrigger) return;
    concernTypeMenu.classList.remove('is-open');
    concernTypeTrigger.setAttribute('aria-expanded', 'false');
}

function closeRecipientDropdown() {
    if (!recipientMenu || !recipientTrigger) return;
    recipientMenu.classList.remove('is-open');
    recipientTrigger.setAttribute('aria-expanded', 'false');
}

function setStaticDropdownState(wrapper, trigger, menu, isStatic) {
    if (wrapper) {
        wrapper.classList.toggle('is-static', !!isStatic);
    }
    if (trigger) {
        trigger.dataset.staticDisplay = isStatic ? '1' : '0';
    }
    if (menu && isStatic) {
        menu.classList.remove('is-open');
    }
}

function syncRecipientTriggerLabel() {
    if (!recipientTrigger || !recipient) return;
    var selectedOption = recipient.options[recipient.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Select a Company';
    recipientTrigger.textContent = label;
    recipientTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseRecipient(optionValue, shouldDispatchChange) {
    if (!recipient) return;
    recipient.value = optionValue;
    syncRecipientTriggerLabel();
    if (recipientMenu) {
        Array.from(recipientMenu.querySelectorAll('.recipient-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeRecipientDropdown();
    if (shouldDispatchChange) {
        recipient.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function buildRecipientDropdown() {
    if (!recipient || !recipientMenu) return;
    var options = Array.from(recipient.options).filter(function(option) {
        return !!option.value;
    });
    if (options.length === 1 && String(recipient.value || '') === '') {
        recipient.value = String(options[0].value || '');
    }
    var selectedValue = String(recipient.value || '');
    recipientMenu.innerHTML = '';
    options.forEach(function(option) {
        var optionValue = String(option.value);
        var optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = 'recipient-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
        optionButton.setAttribute('data-value', optionValue);
        optionButton.setAttribute('role', 'option');
        optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
        optionButton.textContent = option.textContent;
        optionButton.addEventListener('click', function() {
            chooseRecipient(optionValue, true);
        });
        recipientMenu.appendChild(optionButton);
    });
    setStaticDropdownState(recipientDropdown, recipientTrigger, recipientMenu, options.length <= 1);
    if (recipientTrigger) {
        recipientTrigger.disabled = !!recipient.disabled || options.length <= 1;
    }
    syncRecipientTriggerLabel();
}

function syncDepartmentTriggerLabel() {
    if (!departmentTrigger || !departmentSelect) return;
    var selectedOption = departmentSelect.options[departmentSelect.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Choose department';
    departmentTrigger.textContent = label;
    departmentTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseDepartment(optionValue, shouldDispatchChange) {
    if (!departmentSelect) return;
    departmentSelect.value = optionValue;
    departmentSelect.setAttribute('data-selected', optionValue);
    syncDepartmentTriggerLabel();
    if (departmentMenu) {
        Array.from(departmentMenu.querySelectorAll('.department-dropdown-option')).forEach(function(button) {
            button.classList.toggle('is-selected', String(button.getAttribute('data-value') || '') === optionValue);
        });
    }
    closeDepartmentDropdown();
    if (shouldDispatchChange) {
        departmentSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function syncCategoryTriggerLabel() {
    if (!categoryTrigger || !categorySelect) return;
    var selectedOption = categorySelect.options[categorySelect.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Choose category';
    categoryTrigger.textContent = label;
    categoryTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseCategory(optionValue, shouldDispatchChange) {
    if (!categorySelect || !areRoutingSelectionsComplete()) return;
    categorySelect.value = optionValue;
    categorySelect.setAttribute('data-selected', optionValue);
    syncCategoryTriggerLabel();
    if (categoryMenu) {
        Array.from(categoryMenu.querySelectorAll('.category-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeCategoryDropdown();
    if (shouldDispatchChange) {
        categorySelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function syncAdminLegalRequestForTriggerLabel() {
    if (!adminLegalRequestForTrigger || !adminLegalRequestForSelect) return;
    var selectedOption = adminLegalRequestForSelect.options[adminLegalRequestForSelect.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Choose request for';
    adminLegalRequestForTrigger.textContent = label;
    adminLegalRequestForTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseAdminLegalRequestFor(optionValue, shouldDispatchChange) {
    if (!adminLegalRequestForSelect) return;
    adminLegalRequestForSelect.value = optionValue;
    adminLegalRequestForSelect.setAttribute('data-selected', optionValue);
    syncAdminLegalRequestForTriggerLabel();
    if (adminLegalRequestForMenu) {
        Array.from(adminLegalRequestForMenu.querySelectorAll('.admin-legal-request-for-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeAdminLegalRequestForDropdown();
    if (shouldDispatchChange) {
        adminLegalRequestForSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function renderAdminLegalRequestForDropdownOptions() {
    if (!adminLegalRequestForSelect) return;
    var selectedValue = String(adminLegalRequestForSelect.getAttribute('data-selected') || adminLegalRequestForSelect.value || '');
    if (adminLegalRequestForMenu) {
        adminLegalRequestForMenu.innerHTML = '';
    }
    Array.from(adminLegalRequestForSelect.options).forEach(function(option) {
        var optionValue = String(option.value || '');
        if (optionValue === '') return;
        if (adminLegalRequestForMenu) {
            var optionButton = document.createElement('button');
            optionButton.type = 'button';
            optionButton.className = 'admin-legal-request-for-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
            optionButton.setAttribute('data-value', optionValue);
            optionButton.setAttribute('role', 'option');
            optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
            optionButton.textContent = option.textContent || optionValue;
            optionButton.addEventListener('click', function() {
                chooseAdminLegalRequestFor(optionValue, true);
            });
            adminLegalRequestForMenu.appendChild(optionButton);
        }
    });
    syncAdminLegalRequestForTriggerLabel();
}

function syncMarketingSubcategoryTriggerLabel() {
    if (!marketingSubcategoryTrigger || !marketingSubcategorySelect) return;
    var selectedOption = marketingSubcategorySelect.options[marketingSubcategorySelect.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Choose request type';
    marketingSubcategoryTrigger.textContent = label;
    marketingSubcategoryTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseMarketingSubcategory(optionValue, shouldDispatchChange) {
    if (!marketingSubcategorySelect) return;
    marketingSubcategorySelect.value = optionValue;
    marketingSubcategorySelect.setAttribute('data-selected', optionValue);
    syncMarketingSubcategoryTriggerLabel();
    if (marketingSubcategoryMenu) {
        Array.from(marketingSubcategoryMenu.querySelectorAll('.marketing-subcategory-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeMarketingSubcategoryDropdown();
    if (shouldDispatchChange) {
        marketingSubcategorySelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function syncEmailRequestTypeTriggerLabel() {
    if (!emailRequestTypeTrigger || !emailRequestTypeSelect) return;
    var selectedOption = emailRequestTypeSelect.options[emailRequestTypeSelect.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Choose email request type';
    emailRequestTypeTrigger.textContent = label;
    emailRequestTypeTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseEmailRequestType(optionValue, shouldDispatchChange) {
    if (!emailRequestTypeSelect) return;
    emailRequestTypeSelect.value = optionValue;
    emailRequestTypeSelect.setAttribute('data-selected', optionValue);
    syncEmailRequestTypeTriggerLabel();
    if (emailRequestTypeMenu) {
        Array.from(emailRequestTypeMenu.querySelectorAll('.email-request-type-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeEmailRequestTypeDropdown();
    if (shouldDispatchChange) {
        emailRequestTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function renderEmailRequestTypeDropdownOptions() {
    if (!emailRequestTypeSelect || !emailRequestTypeMenu) return;
    var selectedValue = String(emailRequestTypeSelect.getAttribute('data-selected') || emailRequestTypeSelect.value || '');
    emailRequestTypeMenu.innerHTML = '';
    Array.from(emailRequestTypeSelect.options).forEach(function(option) {
        var optionValue = String(option.value || '');
        if (optionValue === '') return;
        var optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = 'email-request-type-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
        optionButton.setAttribute('data-value', optionValue);
        optionButton.setAttribute('role', 'option');
        optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
        optionButton.textContent = option.textContent || optionValue;
        optionButton.addEventListener('click', function() {
            chooseEmailRequestType(optionValue, true);
        });
        emailRequestTypeMenu.appendChild(optionButton);
    });
    syncEmailRequestTypeTriggerLabel();
}

function syncConcernTypeTriggerLabel() {
    if (!concernTypeTrigger || !concernTypeSelect) return;
    var selectedOption = concernTypeSelect.options[concernTypeSelect.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Choose type of concern';
    concernTypeTrigger.textContent = label;
    concernTypeTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseConcernType(optionValue, shouldDispatchChange) {
    if (!concernTypeSelect) return;
    concernTypeSelect.value = optionValue;
    concernTypeSelect.setAttribute('data-selected', optionValue);
    syncConcernTypeTriggerLabel();
    if (concernTypeMenu) {
        Array.from(concernTypeMenu.querySelectorAll('.concern-type-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeConcernTypeDropdown();
    if (shouldDispatchChange) {
        concernTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function renderConcernTypeDropdownOptions() {
    if (!concernTypeSelect || !concernTypeMenu) return;
    var selectedValue = String(concernTypeSelect.getAttribute('data-selected') || concernTypeSelect.value || '');
    concernTypeMenu.innerHTML = '';
    Array.from(concernTypeSelect.options).forEach(function(option) {
        var optionValue = String(option.value || '');
        if (optionValue === '') return;
        var optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = 'concern-type-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
        optionButton.setAttribute('data-value', optionValue);
        optionButton.setAttribute('role', 'option');
        optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
        optionButton.textContent = option.textContent || optionValue;
        optionButton.addEventListener('click', function() {
            chooseConcernType(optionValue, true);
        });
        concernTypeMenu.appendChild(optionButton);
    });
    syncConcernTypeTriggerLabel();
}

function syncSimpleSalesDropdownTrigger(selectEl, triggerEl, placeholder) {
    if (!selectEl || !triggerEl) return;
    var selectedOption = selectEl.options[selectEl.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : placeholder;
    triggerEl.textContent = label;
    triggerEl.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function chooseSimpleSalesDropdown(selectEl, menuEl, triggerEl, optionClass, optionValue, shouldDispatchChange, closeFn) {
    if (!selectEl) return;
    selectEl.value = optionValue;
    selectEl.setAttribute('data-selected', optionValue);
    syncSimpleSalesDropdownTrigger(selectEl, triggerEl, selectEl.id === 'sales_region' ? 'Choose region' : 'Choose position');
    if (menuEl) {
        Array.from(menuEl.querySelectorAll('.' + optionClass)).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closeFn();
    if (shouldDispatchChange) {
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function renderSimpleSalesDropdownOptions(selectEl, menuEl, triggerEl, optionClass, placeholder, chooseFn) {
    if (!selectEl || !menuEl) return;
    var selectedValue = String(selectEl.getAttribute('data-selected') || selectEl.value || '');
    menuEl.innerHTML = '';
    Array.from(selectEl.options).forEach(function(option) {
        var optionValue = String(option.value || '');
        if (optionValue === '') return;
        var optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = optionClass + (selectedValue === optionValue ? ' is-selected' : '');
        optionButton.setAttribute('data-value', optionValue);
        optionButton.setAttribute('role', 'option');
        optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
        optionButton.textContent = option.textContent || optionValue;
        optionButton.addEventListener('click', function() {
            chooseFn(optionValue, true);
        });
        menuEl.appendChild(optionButton);
    });
    syncSimpleSalesDropdownTrigger(selectEl, triggerEl, placeholder);
}

function chooseSalesPosition(optionValue, shouldDispatchChange) {
    chooseSimpleSalesDropdown(salesPositionSelect, salesPositionMenu, salesPositionTrigger, 'sales-position-dropdown-option', optionValue, shouldDispatchChange, closeSalesPositionDropdown);
}

function chooseSalesRegion(optionValue, shouldDispatchChange) {
    chooseSimpleSalesDropdown(salesRegionSelect, salesRegionMenu, salesRegionTrigger, 'sales-region-dropdown-option', optionValue, shouldDispatchChange, closeSalesRegionDropdown);
}

function renderSalesPositionDropdownOptions() {
    renderSimpleSalesDropdownOptions(salesPositionSelect, salesPositionMenu, salesPositionTrigger, 'sales-position-dropdown-option', 'Choose position', chooseSalesPosition);
}

function renderSalesRegionDropdownOptions() {
    renderSimpleSalesDropdownOptions(salesRegionSelect, salesRegionMenu, salesRegionTrigger, 'sales-region-dropdown-option', 'Choose region', chooseSalesRegion);
}

function syncPriorityTriggerLabel() {
    if (!priorityTrigger || !prioritySelect) return;
    var selectedOption = prioritySelect.options[prioritySelect.selectedIndex];
    var placeholderOption = Array.from(prioritySelect.options).find(function(option) {
        return !option.value;
    });
    var placeholder = placeholderOption ? placeholderOption.textContent : 'Choose level of urgency';
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : placeholder;
    priorityTrigger.textContent = label;
    priorityTrigger.classList.toggle('is-placeholder', !(selectedOption && selectedOption.value));
}

function choosePriority(optionValue, shouldDispatchChange) {
    if (!prioritySelect || !areRoutingSelectionsComplete()) return;
    prioritySelect.value = optionValue;
    prioritySelect.setAttribute('data-selected', optionValue);
    syncPriorityTriggerLabel();
    if (priorityMenu) {
        Array.from(priorityMenu.querySelectorAll('.priority-dropdown-option')).forEach(function(button) {
            var isSelected = String(button.getAttribute('data-value') || '') === optionValue;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });
    }
    closePriorityDropdown();
    if (shouldDispatchChange) {
        prioritySelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function renderPriorityDropdownOptions() {
    if (!prioritySelect || !priorityMenu) return;
    var selectedValue = String(prioritySelect.value || '');
    priorityMenu.innerHTML = '';
    Array.from(prioritySelect.options).forEach(function(option) {
        if (!option.value) return;
        var optionValue = String(option.value);
        var isSelected = selectedValue === optionValue;
        var optionButton = document.createElement('button');
        optionButton.type = 'button';
        optionButton.className = 'priority-dropdown-option' + (isSelected ? ' is-selected' : '');
        optionButton.setAttribute('data-value', optionValue);
        optionButton.setAttribute('role', 'option');
        optionButton.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        optionButton.textContent = option.textContent;
        priorityMenu.appendChild(optionButton);
    });
    if (priorityTrigger) {
        priorityTrigger.disabled = prioritySelect.disabled;
        if (prioritySelect.disabled) {
            closePriorityDropdown();
        }
    }
    syncPriorityTriggerLabel();
}

function populateDepartments(options) {
    if (!departmentSelect) return;
    var selectedValue = String(departmentSelect.getAttribute('data-selected') || departmentSelect.value || '');
    departmentSelect.innerHTML = '<option value="" disabled selected hidden>Choose department</option>';
    if (departmentMenu) {
        departmentMenu.innerHTML = '';
    }
    options.forEach(function(optionValue) {
        var option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionValue;
        if (selectedValue !== '' && selectedValue === optionValue) {
            option.selected = true;
        }
        departmentSelect.appendChild(option);

        if (departmentMenu) {
            var optionButton = document.createElement('button');
            optionButton.type = 'button';
            optionButton.className = 'department-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
            optionButton.setAttribute('data-value', optionValue);
            optionButton.setAttribute('role', 'option');
            optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
            optionButton.textContent = optionValue;
            optionButton.addEventListener('click', function() {
                chooseDepartment(optionValue, true);
            });
            departmentMenu.appendChild(optionButton);
        }
    });
    syncDepartmentTriggerLabel();
}

function toggleDepartmentField() {
    if (!recipient || !departmentGroup || !recipientGroup || !departmentSelect) return;
    var value = String(recipient.value || '');

    if (isLapcRecipientValue(value)) {
        populateDepartments(lapcDepartments);
        departmentGroup.style.display = '';
        departmentGroup.classList.remove('hidden');
        if (recipientRow) recipientRow.classList.remove('is-single');
        recipientGroup.classList.remove('full-width');
        departmentSelect.disabled = false;
        departmentSelect.setAttribute('required', 'required');
        if (departmentTrigger) departmentTrigger.disabled = false;
    } else if (isMhcRecipientValue(value)) {
        populateDepartments(mhcDepartments);
        departmentGroup.style.display = '';
        departmentGroup.classList.remove('hidden');
        if (recipientRow) recipientRow.classList.remove('is-single');
        recipientGroup.classList.remove('full-width');
        departmentSelect.disabled = false;
        departmentSelect.setAttribute('required', 'required');
        if (departmentTrigger) departmentTrigger.disabled = false;
    } else {
        departmentGroup.style.display = 'none';
        departmentGroup.classList.add('hidden');
        if (recipientRow) recipientRow.classList.add('is-single');
        recipientGroup.classList.add('full-width');
        departmentSelect.value = '';
        departmentSelect.setAttribute('data-selected', '');
        departmentSelect.disabled = true;
        departmentSelect.removeAttribute('required');
        if (departmentTrigger) departmentTrigger.disabled = true;
        syncDepartmentTriggerLabel();
        closeDepartmentDropdown();
    }
    var departmentOptions = Array.from(departmentSelect.options).filter(function(option) {
        return String(option.value || '') !== '';
    });
    setStaticDropdownState(departmentDropdown, departmentTrigger, departmentMenu, false);
    if (departmentTrigger) {
        departmentTrigger.disabled = !!departmentSelect.disabled;
    }
    syncRequestGridRows();
}

function populateSalesCategories(options) {
    if (!categorySelect) return;
    var selectedValue = String(categorySelect.getAttribute('data-selected') || categorySelect.value || '');
    var normalizedOptions = Array.isArray(options)
        ? options
        : Array.from(categorySelect.options)
            .map(function(option) { return String(option.value || ''); })
            .filter(function(optionValue) { return optionValue !== ''; });
    categorySelect.innerHTML = '<option value="" disabled hidden selected>Choose category</option>';
    if (categoryMenu) {
        categoryMenu.innerHTML = '';
    }
    normalizedOptions.forEach(function(optionValue){
        var opt = document.createElement('option');
        opt.value = optionValue;
        opt.textContent = optionValue;
        if (selectedValue !== '' && selectedValue === optionValue) {
            opt.selected = true;
        }
        categorySelect.appendChild(opt);

        if (categoryMenu) {
            var optionButton = document.createElement('button');
            optionButton.type = 'button';
            optionButton.className = 'category-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
            optionButton.setAttribute('data-value', optionValue);
            optionButton.setAttribute('role', 'option');
            optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
            optionButton.textContent = optionValue;
            optionButton.addEventListener('click', function() {
                chooseCategory(optionValue, true);
            });
            categoryMenu.appendChild(optionButton);
        }
    });
    if (selectedValue !== '' && normalizedOptions.indexOf(selectedValue) === -1) {
        categorySelect.value = '';
    }
    categorySelect.setAttribute('data-selected', '');
    syncCategoryTriggerLabel();
}

function populateMarketingSubcategories(options) {
    if (!marketingSubcategorySelect) return;
    var selectedValue = String(marketingSubcategorySelect.getAttribute('data-selected') || marketingSubcategorySelect.value || '');
    var normalizedOptions = Array.isArray(options) ? options : [];
    marketingSubcategorySelect.innerHTML = '<option value="" disabled hidden selected>Choose request type</option>';
    if (marketingSubcategoryMenu) {
        marketingSubcategoryMenu.innerHTML = '';
    }
    normalizedOptions.forEach(function(optionValue) {
        var option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionValue;
        if (selectedValue !== '' && selectedValue === optionValue) {
            option.selected = true;
        }
        marketingSubcategorySelect.appendChild(option);

        if (marketingSubcategoryMenu) {
            var optionButton = document.createElement('button');
            optionButton.type = 'button';
            optionButton.className = 'marketing-subcategory-dropdown-option' + (selectedValue === optionValue ? ' is-selected' : '');
            optionButton.setAttribute('data-value', optionValue);
            optionButton.setAttribute('role', 'option');
            optionButton.setAttribute('aria-selected', selectedValue === optionValue ? 'true' : 'false');
            optionButton.textContent = optionValue;
            optionButton.addEventListener('click', function() {
                chooseMarketingSubcategory(optionValue, true);
            });
            marketingSubcategoryMenu.appendChild(optionButton);
        }
    });
    if (selectedValue !== '' && normalizedOptions.indexOf(selectedValue) === -1) {
        marketingSubcategorySelect.value = '';
        marketingSubcategorySelect.setAttribute('data-selected', '');
    }
    syncMarketingSubcategoryTriggerLabel();
}

function shouldShowMarketingSubcategory() {
    if (!recipient || !departmentSelect || !categorySelect) return false;
    var selectedCategory = String(categorySelect.value || '');
    if (!isLapcRecipientValue(String(recipient.value || ''))) return false;
    var departmentValue = String(departmentSelect.value || '');
    return (departmentValue === 'Marketing' && Object.prototype.hasOwnProperty.call(lapcMarketingSubcategories, selectedCategory))
        || (departmentValue === 'Supply Chain' && Object.prototype.hasOwnProperty.call(lapcSupplyChainRequestTypes, selectedCategory));
}

function areRoutingSelectionsComplete() {
    if (!recipient || String(recipient.value || '') === '') return false;
    var normalizedRecipient = normalizeRecipientCompany(recipient.value);
    var requiresDepartment = normalizedRecipient === '@leadsagri.com'
        || normalizedRecipient === '@malvedaholdings.com';
    return !requiresDepartment || (departmentSelect && String(departmentSelect.value || '') !== '');
}

function toggleMarketingSubcategory() {
    if (!marketingSubcategoryRow || !marketingSubcategoryContainer || !marketingSubcategorySelect) return;
    var selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
    var shouldShow = shouldShowMarketingSubcategory();
    marketingSubcategoryRow.style.display = shouldShow ? '' : 'none';
    marketingSubcategoryContainer.classList.toggle('is-visible', shouldShow);
    if (shouldShow) {
        marketingSubcategorySelect.disabled = false;
        marketingSubcategorySelect.setAttribute('required', 'required');
        if (marketingSubcategoryTrigger) marketingSubcategoryTrigger.disabled = false;
        var requestTypeOptions = String(departmentSelect.value || '') === 'Supply Chain'
            ? (lapcSupplyChainRequestTypes[selectedCategory] || [])
            : (lapcMarketingSubcategories[selectedCategory] || []);
        populateMarketingSubcategories(requestTypeOptions);
    } else {
        marketingSubcategorySelect.value = '';
        marketingSubcategorySelect.setAttribute('data-selected', '');
        marketingSubcategorySelect.disabled = true;
        marketingSubcategorySelect.removeAttribute('required');
        if (marketingSubcategoryTrigger) marketingSubcategoryTrigger.disabled = true;
        populateMarketingSubcategories([]);
        closeMarketingSubcategoryDropdown();
    }
    toggleSupplyChainDetails();
}

function toggleSupplyChainDetails() {
    if (!supplyChainDetailsRow || !supplyChainDetailsFields || !recipient || !departmentSelect || !categorySelect || !marketingSubcategorySelect) return;
    var category = String(categorySelect.value || '');
    var shouldShow = isLapcRecipientValue(String(recipient.value || ''))
        && String(departmentSelect.value || '') === 'Supply Chain'
        && Object.prototype.hasOwnProperty.call(lapcSupplyChainDetailFields, category)
        && String(marketingSubcategorySelect.value || '') !== '';
    supplyChainDetailsRow.classList.toggle('is-visible', shouldShow);
    toggleSupplyChainOptionalSections(shouldShow);
    supplyChainDetailsFields.innerHTML = '';
    if (!shouldShow) return;

    (lapcSupplyChainDetailFields[category] || []).forEach(function(label, index) {
        if (/^(Supporting Photo|Supporting Documents)$/i.test(label)) return;
        var group = document.createElement('div');
        group.className = 'supply-chain-field';
        var formGroup = document.createElement('div');
        formGroup.className = 'form-group';
        var fieldLabel = document.createElement('label');
        fieldLabel.textContent = label;
        var isLongText = /Purpose|Special Requirements|Details\/Photos|Reason|Issue\/Concern|Specific Inquiry/i.test(label);
        if (isLongText) group.classList.add('supply-chain-full-row');
        var field = document.createElement(isLongText ? 'textarea' : 'input');
        field.className = 'form-control';
        field.name = 'supply_chain_details[' + label + ']';
        field.required = true;
        field.value = String(savedSupplyChainDetails[label] || '');
        if (!isLongText && /Date\/Time/i.test(label)) field.type = 'datetime-local';
        else if (!isLongText && /Date/i.test(label)) field.type = 'date';
        else if (!isLongText) field.type = 'text';
        if (isLongText) field.rows = 3;
        var requiredMark = document.createElement('span');
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
        var supportAttachmentLabel = (lapcSupplyChainDetailFields[String(categorySelect.value || '')] || []).find(function(label) {
            return /^(Supporting Photo|Supporting Documents)$/i.test(label);
        });
        var showStandardAttachment = String(categorySelect.value || '') === 'Delivery Concern / Exception';
        var shouldShowAttachment = !!supportAttachmentLabel || showStandardAttachment;
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
        if (descriptionFieldEl) descriptionFieldEl.removeAttribute('required');
    } else if (descriptionContainer && descriptionContainer.dataset.supplyChainHidden === 'true') {
        descriptionContainer.style.display = '';
        delete descriptionContainer.dataset.supplyChainHidden;
        if (attachmentContainer) {
            if (attachmentOriginalHost) moveAttachmentContainer(attachmentOriginalHost);
            attachmentContainer.style.display = '';
            delete attachmentContainer.dataset.supplyChainHidden;
        }
        if (attachmentLabelText) attachmentLabelText.textContent = 'Attachment';
        if (descriptionFieldEl) descriptionFieldEl.setAttribute('required', 'required');
    }
}

function toggleCategoryField() {
    if (!recipient || !categorySelect) return;
    var value = String(recipient.value || '');
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    var options = defaultCategories;
    var normalizedRecipient = normalizeRecipientCompany(value);
    var isAdminLegalSelection = isLapcRecipientValue(value) && departmentValue === 'Admin & Legal';
    var requestForValue = adminLegalRequestForSelect ? String(adminLegalRequestForSelect.value || '') : '';
    if (salesCategoryRow) {
        salesCategoryRow.classList.toggle('is-admin-legal-layout', isAdminLegalSelection);
    }
    if (adminLegalRequestForContainer && adminLegalRequestForSelect) {
        adminLegalRequestForContainer.style.display = isAdminLegalSelection ? '' : 'none';
        adminLegalRequestForContainer.classList.toggle('hidden', !isAdminLegalSelection);
        adminLegalRequestForSelect.disabled = !isAdminLegalSelection;
        if (isAdminLegalSelection) {
            adminLegalRequestForSelect.setAttribute('required', 'required');
            if (adminLegalRequestForTrigger) adminLegalRequestForTrigger.disabled = false;
            renderAdminLegalRequestForDropdownOptions();
        } else {
            adminLegalRequestForSelect.value = '';
            adminLegalRequestForSelect.setAttribute('data-selected', '');
            adminLegalRequestForSelect.removeAttribute('required');
            if (adminLegalRequestForTrigger) adminLegalRequestForTrigger.disabled = true;
            closeAdminLegalRequestForDropdown();
            syncAdminLegalRequestForTriggerLabel();
        }
    }
    if (!areRoutingSelectionsComplete()) {
        categorySelect.value = '';
        categorySelect.setAttribute('data-selected', '');
        categorySelect.disabled = true;
        categorySelect.removeAttribute('required');
        if (categoryTrigger) categoryTrigger.disabled = true;
        populateSalesCategories([]);
        toggleMarketingSubcategory();
        syncRequestGridRows();
        return;
    }
    if (categoryContainer) categoryContainer.style.display = '';
    if (isAdminLegalSelection) {
        if (requestForValue === '') {
            if (categoryContainer) categoryContainer.style.display = 'none';
            categorySelect.value = '';
            categorySelect.setAttribute('data-selected', '');
            categorySelect.disabled = true;
            categorySelect.removeAttribute('required');
            if (categoryTrigger) categoryTrigger.disabled = true;
            populateSalesCategories([]);
        } else if (requestForValue === 'Others') {
            if (categoryContainer) categoryContainer.style.display = 'none';
            populateSalesCategories(['Others']);
            categorySelect.disabled = false;
            categorySelect.value = 'Others';
            categorySelect.setAttribute('data-selected', 'Others');
            categorySelect.removeAttribute('required');
            if (categoryTrigger) categoryTrigger.disabled = true;
        } else {
            populateSalesCategories(lapcAdminLegalRequestCategories[requestForValue] || []);
            categorySelect.disabled = false;
            categorySelect.setAttribute('required', 'required');
            if (categoryTrigger) categoryTrigger.disabled = false;
        }
        closeCategoryDropdown();
        toggleMarketingSubcategory();
        syncRequestGridRows();
        return;
    }
    if (othersOnlyCompanyDomains.indexOf(normalizedRecipient) !== -1) {
        options = ['Others'];
    } else if (normalizedRecipient === '@malvedaproperties.com') {
        options = mpdcCategories;
    } else if (normalizedRecipient === '@lingapleads.org') {
        options = lingapCategories;
    } else if (isMhcRecipientValue(value) && mhcDepartmentCategories[departmentValue]) {
        options = mhcDepartmentCategories[departmentValue];
    } else if (isAdminLegalSelection) {
        options = requestForValue && lapcAdminLegalRequestCategories[requestForValue]
            ? lapcAdminLegalRequestCategories[requestForValue]
            : [];
    } else if (isLapcRecipientValue(value) && lapcDepartmentCategories[departmentValue]) {
        options = lapcDepartmentCategories[departmentValue];
    }
    if (isAdminLegalSelection && requestForValue === '') {
        categorySelect.value = '';
        categorySelect.setAttribute('data-selected', '');
        categorySelect.disabled = true;
        categorySelect.removeAttribute('required');
        if (categoryTrigger) {
            categoryTrigger.disabled = true;
        }
    } else {
        categorySelect.disabled = false;
        categorySelect.setAttribute('required', 'required');
        if (categoryTrigger) {
            categoryTrigger.disabled = false;
        }
    }
    populateSalesCategories(options);
    toggleMarketingSubcategory();
    syncRequestGridRows();
}

function togglePriorityField() {
    if (!priorityGroup || !prioritySelect || !recipient) return;
    setPriorityOptions('hr');
    priorityGroup.classList.remove('hidden');
    var routingSelectionsComplete = areRoutingSelectionsComplete();
    prioritySelect.disabled = !routingSelectionsComplete;
    if (routingSelectionsComplete) {
        prioritySelect.setAttribute('required', 'true');
    } else {
        prioritySelect.value = '';
        prioritySelect.setAttribute('data-selected', '');
        prioritySelect.removeAttribute('required');
    }
    if (priorityTrigger) priorityTrigger.disabled = !routingSelectionsComplete;
    renderPriorityDropdownOptions();
    syncRequestGridRows();
}

function isLapcHrSelection() {
    var recipientValue = recipient ? String(recipient.value || '') : '';
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    return isLapcRecipientValue(recipientValue) && departmentValue === 'HR';
}

function isLapcItSelection() {
    var recipientValue = recipient ? String(recipient.value || '') : '';
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    return isLapcRecipientValue(recipientValue) && departmentValue === 'IT';
}

function isLapcMarketingRequestTypeSelection() {
    var recipientValue = recipient ? String(recipient.value || '') : '';
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    return isLapcRecipientValue(recipientValue) && (departmentValue === 'Marketing' || departmentValue === 'Supply Chain');
}

function isLapcMarketingSelection() {
    var recipientValue = recipient ? String(recipient.value || '') : '';
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    return isMhcRecipientValue(recipientValue) && departmentValue === 'Marketing Creatives';
}

function setPriorityOptions(mode) {
    if (!prioritySelect) return;
    var modeKey = String(mode || 'hr');
    var desired = modeKey === 'marketing'
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
    var currentSignature = Array.from(prioritySelect.options).map(function(option) {
        return String(option.value || '') + ':' + String(option.textContent || '');
    }).join('|');
    var nextSignature = desired.map(function(option) {
        return String(option.value || '') + ':' + String(option.text || '');
    }).join('|');
    if (currentSignature !== nextSignature) {
        var selectedValue = String(prioritySelect.getAttribute('data-selected') || prioritySelect.value || '');
        prioritySelect.innerHTML = '';
        desired.forEach(function(optionConfig, index) {
            var option = document.createElement('option');
            option.value = optionConfig.value;
            option.textContent = optionConfig.text;
            if (index === 0) {
                option.disabled = true;
                option.hidden = true;
                option.selected = true;
            }
            prioritySelect.appendChild(option);
        });
        prioritySelect.value = selectedValue;
        if (prioritySelect.value !== selectedValue) {
            prioritySelect.value = '';
        }
    }
    prioritySelect.setAttribute('data-selected', '');
    if (priorityLabel) {
        priorityLabel.innerHTML = 'Level of Urgency <span class="required-asterisk">*</span>';
    }
    renderPriorityDropdownOptions();
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
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
}

function addWorkingDays(startDate, workingDays) {
    var next = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
    var count = 0;
    while (count < workingDays) {
        next.setDate(next.getDate() + 1);
        var day = next.getDay();
        if (day !== 0 && day !== 6) {
            count++;
        }
    }
    return next;
}

function workingDaysFromToday(deadlineValue) {
    if (!deadlineValue) return -1;
    var parts = String(deadlineValue).split('-').map(function(part) { return parseInt(part, 10); });
    if (parts.length !== 3 || parts.some(function(part) { return !isFinite(part); })) return -1;
    var target = new Date(parts[0], parts[1] - 1, parts[2]);
    var today = new Date();
    var cursor = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    if (target <= cursor) return 0;
    var days = 0;
    while (cursor < target) {
        cursor.setDate(cursor.getDate() + 1);
        var day = cursor.getDay();
        if (day !== 0 && day !== 6) {
            days++;
        }
    }
    return days;
}

function validateProjectDeadline(showMessage) {
    if (!projectDeadlineInput) return true;
    var value = String(projectDeadlineInput.value || '');
    var minimumDate = addWorkingDays(new Date(), 3);
    var minimumIso = formatIsoDate(minimumDate);
    projectDeadlineInput.min = minimumIso;
    if (projectDeadlineHelp) {
        projectDeadlineHelp.textContent = 'Must be at least 3 working days from today. Earliest valid date is ' + minimumDate.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }) + '.';
    }
    var message = '';
    if (value !== '') {
        var parts = value.split('-').map(function(part) { return parseInt(part, 10); });
        var target = parts.length === 3 ? new Date(parts[0], parts[1] - 1, parts[2]) : null;
        var day = target ? target.getDay() : -1;
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
    var requestedOtherSelected = requestedMaterialsSelect
        ? String(requestedMaterialsSelect.value || '') === 'Other'
        : requestedMaterialsInputs.some(function(input) {
            return input.checked && input.value === 'Other';
        });
    var cropOtherSelected = cropSelect
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
    var selectedUnit = materialSizeUnitInputs.find(function(input) { return input.checked; });
    var selectedValue = '';
    materialSizeValueInputs.forEach(function(input) {
        var row = input.closest('.marketing-size-option');
        var rowUnit = row ? row.querySelector('input[name="material_size_unit"]') : null;
        var isSelected = !!(rowUnit && rowUnit.checked);
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

function setSssUploadError(config, message) {
    var errorNode = document.getElementById(config.errorId);
    if (!errorNode) return;
    if (!message) {
        errorNode.textContent = '';
        errorNode.classList.remove('is-visible');
        return;
    }
    errorNode.textContent = message;
    errorNode.classList.add('is-visible');
}

function updateSssUploadSummary(config) {
    var labelNode = document.getElementById(config.labelId);
    var listNode = document.getElementById(config.listId);
    var files = Array.from((sssUploadState[config.inputId] && sssUploadState[config.inputId].files) || []);

    if (labelNode) {
        labelNode.textContent = files.length === 0 ? 'No file chosen' : (files.length === 1 ? '1 file selected' : files.length + ' files selected');
    }

    if (!listNode) return;
    listNode.innerHTML = '';
    if (files.length === 0) {
        return;
    }

    files.forEach(function(file, index) {
        var chip = document.createElement('div');
        chip.className = 'sss-benefits-file-chip';

        var chipName = document.createElement('button');
        chipName.type = 'button';
        chipName.className = 'sss-benefits-file-chip-name sss-benefits-file-chip-link';
        chipName.textContent = file.name || ('File ' + (index + 1));
        chipName.addEventListener('click', function() {
            openSssUploadPreview(file);
        });

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'sss-benefits-file-chip-remove';
        removeBtn.textContent = 'x';
        removeBtn.addEventListener('click', function() {
            removeSssUploadFile(config, index);
        });

        chip.appendChild(chipName);
        chip.appendChild(removeBtn);
        listNode.appendChild(chip);
    });
}

function syncSssInputFiles(config) {
    var input = document.getElementById(config.inputId);
    if (!input) return;
    var state = sssUploadState[config.inputId] || { files: [] };
    var localDt = new DataTransfer();
    state.files.forEach(function(file) {
        localDt.items.add(file);
    });
    input.files = localDt.files;
    updateSssUploadSummary(config);
}

function removeSssUploadFile(config, index) {
    var state = sssUploadState[config.inputId];
    if (!state) return;
    state.files.splice(index, 1);
    setSssUploadError(config, '');
    syncSssInputFiles(config);
}

function openSssUploadPreview(file) {
    if (!file) return;
    var previewUrl = URL.createObjectURL(file);
    openInlineAttachmentPreview(file, previewUrl, true);
}

function mergeSssUploadFiles(config, incomingFiles) {
    var state = sssUploadState[config.inputId] || { files: [] };
    var nextFiles = state.files.slice();
    var selectedFiles = Array.from(incomingFiles || []);

    if (nextFiles.length + selectedFiles.length > config.maxFiles) {
        setSssUploadError(config, config.maxFiles === 1
            ? 'Only 1 file is allowed for ' + config.label + '.'
            : 'You can upload up to ' + config.maxFiles + ' files for ' + config.label + '.');
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

function refreshEmailCreationInputs() {
    emailCreationInputs = emailRequestList
        ? Array.from(emailRequestList.querySelectorAll('[data-email-field]'))
        : [];
}

function getEmailCreationCards() {
    if (!emailRequestList) return [];
    return Array.from(emailRequestList.querySelectorAll('[data-email-card]'));
}

function getEmailCreationCardDisplayName(card, index) {
    var nameInput = card ? card.querySelector('[data-email-field="name"]') : null;
    var displayName = nameInput ? String(nameInput.value || '').trim() : '';
    return displayName !== '' ? displayName : ('Email ' + (index + 1));
}

function getEmailCreationDepartmentOptions(subsidiaryValue) {
    var normalizedValue = String(subsidiaryValue || '').trim().toLowerCase();
    if (Object.prototype.hasOwnProperty.call(emailCreationDepartmentOptionsBySubsidiary, normalizedValue)) {
        return emailCreationDepartmentOptionsBySubsidiary[normalizedValue] || [];
    }
    return normalizedValue !== '' ? ['IT'] : [];
}

function syncEmailCreationDepartmentSelect(card, keepExistingValue) {
    var subsidiarySelect = card ? card.querySelector('[data-email-subsidiary-select]') : null;
    var departmentSelect = card ? card.querySelector('[data-email-target-department-select]') : null;
    if (!departmentSelect) return;
    var currentValue = keepExistingValue ? String(departmentSelect.value || '').trim() : '';
    var options = getEmailCreationDepartmentOptions(subsidiarySelect ? subsidiarySelect.value : '');
    departmentSelect.innerHTML = '<option value="" disabled selected hidden>Choose department</option>';
    options.forEach(function(optionValue) {
        var option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionValue;
        if (currentValue !== '' && currentValue === optionValue) {
            option.selected = true;
        }
        departmentSelect.appendChild(option);
    });
    if (currentValue !== '' && options.indexOf(currentValue) === -1) {
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

var activeEmailCardIndex = 0;

function applyEmailCreationInputState() {
    refreshEmailCreationInputs();
    var shouldShowEmailCreation = emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
    emailCreationInputs.forEach(function(input) {
        if (!input) return;
        if (shouldShowEmailCreation) {
            input.setAttribute('required', 'required');
        } else {
            input.removeAttribute('required');
            if (!emailRequestSection || !emailRequestSection.classList.contains('is-visible')) {
                input.value = '';
            }
        }
    });
}

function setActiveEmailCard(index) {
    var emailCards = getEmailCreationCards();
    if (emailCards.length === 0) {
        activeEmailCardIndex = 0;
        return;
    }
    var normalizedIndex = Math.max(0, Math.min(index, emailCards.length - 1));
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
            var option = document.createElement('option');
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
    var emailCards = getEmailCreationCards();
    syncAllEmailCreationDepartmentSelects();
    emailCards.forEach(function(card) {
        var title = card.querySelector('[data-email-card-title]');
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
    var nextIndex = Date.now();
    var templateMarkup = emailCreationTemplate.innerHTML.replace(/__INDEX__/g, String(nextIndex));
    emailRequestList.insertAdjacentHTML('beforeend', templateMarkup);
    syncEmailCreationCardState();
    var emailCards = getEmailCreationCards();
    var newestCardIndex = emailCards.length - 1;
    var newestCard = emailCards[newestCardIndex] || null;
    setActiveEmailCard(newestCardIndex);
    var firstInput = newestCard ? newestCard.querySelector('[data-email-field="name"]') : null;
    if (firstInput) firstInput.focus();
}

function findFirstIncompleteEmailCreationInput(card) {
    var orderedFields = ['name', 'designation', 'subsidiary', 'department'];
    for (var i = 0; i < orderedFields.length; i += 1) {
        var input = card ? card.querySelector('[data-email-field="' + orderedFields[i] + '"]') : null;
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
        var target = event.target;
        if (!(target instanceof Element)) return;
        var addButton = target.closest('[data-add-email-card]');
        if (addButton) {
            addEmailCreationCard();
            return;
        }
        var removeButton = target.closest('[data-remove-email-card]');
        if (!removeButton) return;
        var emailCards = getEmailCreationCards();
        if (emailCards.length <= 1) return;
        var card = removeButton.closest('[data-email-card]');
        if (card) {
            var removedIndex = emailCards.indexOf(card);
            card.remove();
            if (removedIndex <= activeEmailCardIndex) {
                activeEmailCardIndex = Math.max(0, activeEmailCardIndex - 1);
            }
            syncEmailCreationCardState();
        }
    });

    emailRequestList.addEventListener('input', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        if (target.matches('[data-email-field="name"]')) {
            setActiveEmailCard(activeEmailCardIndex);
        }
    });

    emailRequestList.addEventListener('change', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        if (target.matches('[data-email-subsidiary-select]')) {
            syncEmailCreationDepartmentSelect(target.closest('[data-email-card]'), false);
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

function restoreDescriptionContainer() {
    if (!descriptionContainer || !otherDescriptionSectionBody) return;
    if (descriptionContainer.parentNode !== otherDescriptionSectionBody) {
        otherDescriptionSectionBody.insertBefore(descriptionContainer, attachmentOriginalHost || null);
    }
}

function syncAttachmentCopy(mode) {
    var modeKey = String(mode || 'default');
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
        medicalCashAttachmentIntro.style.display = modeKey === 'medical' ? 'block' : 'none';
    }
    if (chooseFileBtnText) {
        chooseFileBtnText.textContent = (modeKey === 'kami' || modeKey === 'medical' || modeKey === 'marketing') ? 'Add file' : 'Choose File';
    }
}

function toggleHrExtraFields() {
    var shouldShow = isLapcHrSelection();
    var selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
    var shouldShowMarketingRequest = isLapcMarketingSelection() && selectedCategory === 'Marketing Request';
    var shouldShowConcernType = shouldShow && selectedCategory === 'Attendance & Timekeeping';
    var shouldShowConcernTypeOther = shouldShowConcernType && concernTypeSelect && String(concernTypeSelect.value || '') === 'Other';
    var shouldShowLeaveSubject = shouldShow && (selectedCategory === 'Leave Concern' || selectedCategory === 'Others');
    var shouldShowOtherDetailsStyle = shouldShowLeaveSubject;
    var shouldShowSssBenefits = shouldShow && selectedCategory === 'SSS Sickness and Benefit Concern';
    var shouldShowMedicalCashAdvance = shouldShow && selectedCategory === 'Medical Cash Advance';
    var shouldShowTrainingRequest = shouldShow && selectedCategory === 'Training Request';
    var shouldShowCompanyPropertyRequest = shouldShow && selectedCategory === 'Request for Company Property';
    var shouldShowCoeRequest = shouldShow && selectedCategory === 'Certificate of Employment';
    var shouldShowColRequest = shouldShow && selectedCategory === 'Certificate of Leave';
    var shouldShowIncidentReport = shouldShow && selectedCategory === 'Incident Report';
    var shouldShowEmailRequest = isLapcItSelection() && selectedCategory === 'Email';
    var shouldShowEmailCreation = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
    var shouldShowEmailDefault = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === '';
    var shouldShowEmailForgotPassword = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'forgot password';
    var shouldShowEmailBackup = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'backup of email';
    var shouldShowSapRequest = isLapcItSelection() && selectedCategory === 'SAP';
    var shouldRequireKamiAttachment = shouldShowConcernType;
    var shouldRequireMedicalAttachment = shouldShowMedicalCashAdvance;
    var shouldRequireIncidentAttachment = shouldShowIncidentReport;
    toggleMarketingSubcategory();

    document.body.classList.toggle('kami-section-active', shouldShowConcernType);
    document.body.classList.toggle('other-section-active', shouldShowOtherDetailsStyle);
    document.body.classList.toggle('medical-cash-section-active', shouldShowMedicalCashAdvance);
    document.body.classList.toggle('training-request-section-active', shouldShowTrainingRequest);
    document.body.classList.toggle('company-property-section-active', shouldShowCompanyPropertyRequest);
    document.body.classList.toggle('coe-request-section-active', shouldShowCoeRequest);
    document.body.classList.toggle('col-request-section-active', shouldShowColRequest);
    document.body.classList.toggle('incident-report-section-active', shouldShowIncidentReport);
    document.body.classList.toggle('email-request-section-active', shouldShowEmailRequest);
    document.body.classList.toggle('sap-request-section-active', shouldShowSapRequest);
    document.body.classList.toggle('marketing-request-section-active', shouldShowMarketingRequest);

    togglePriorityField();

    if (kamiBannerContainer) kamiBannerContainer.classList.toggle('is-visible', shouldShowConcernType);
    if (medicalCashAdvanceSection) medicalCashAdvanceSection.classList.toggle('is-visible', shouldShowMedicalCashAdvance);
    if (trainingRequestSection) trainingRequestSection.classList.toggle('is-visible', shouldShowTrainingRequest);
    if (companyPropertySection) companyPropertySection.classList.toggle('is-visible', shouldShowCompanyPropertyRequest);
    if (coeRequestSection) coeRequestSection.classList.toggle('is-visible', shouldShowCoeRequest);
    if (colRequestSection) colRequestSection.classList.toggle('is-visible', shouldShowColRequest);
    if (incidentReportSection) incidentReportSection.classList.toggle('is-visible', shouldShowIncidentReport);
    var shouldShowCertificateLeavePurposeOther = shouldShowColRequest && certificateLeavePurposeSelect && String(certificateLeavePurposeSelect.value || '') === 'Others';
    if (emailRequestSection) emailRequestSection.classList.toggle('is-visible', shouldShowEmailRequest);
    if (emailCreationFields) emailCreationFields.classList.toggle('is-visible', !!shouldShowEmailCreation);
    if (sapRequestSection) sapRequestSection.classList.toggle('is-visible', shouldShowSapRequest);
    if (marketingRequestSection) marketingRequestSection.classList.toggle('is-visible', shouldShowMarketingRequest);
    if (concernTypeContainer) concernTypeContainer.classList.toggle('is-visible', shouldShowConcernType);
    if (concernTypeOtherContainer) concernTypeOtherContainer.classList.toggle('is-visible', shouldShowConcernTypeOther);
    if (leaveSubjectContainer) leaveSubjectContainer.classList.toggle('is-visible', shouldShowLeaveSubject);
    if (otherRequestDetailsSection) otherRequestDetailsSection.style.display = shouldShowLeaveSubject ? '' : 'none';
    if (requestSubjectLabel) requestSubjectLabel.innerHTML = 'Subject/Title of Request <span class="required-asterisk">*</span>';
    if (descriptionLabel) {
        descriptionLabel.innerHTML = shouldShowMarketingRequest
            ? 'Brief Description of Request <span class="required-asterisk">*</span>'
            : (shouldShowOtherDetailsStyle
                ? 'Detailed Description of Request or Concern <span class="required-asterisk">*</span>'
                : 'Description <span class="required-asterisk">*</span>');
    }
    if (sssBenefitsContainer) sssBenefitsContainer.classList.toggle('is-visible', shouldShowSssBenefits);

    if (descriptionContainer) descriptionContainer.style.display = (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowIncidentReport || shouldShowSapRequest || shouldShowEmailCreation) ? 'none' : '';
    if (attachmentContainer) attachmentContainer.style.display = (shouldShowSssBenefits || shouldShowSapRequest || shouldShowEmailRequest) ? 'none' : '';
    if (otherDescriptionSection) otherDescriptionSection.style.display = shouldShowSssBenefits ? 'none' : '';

    if (attachmentInput) attachmentInput.disabled = shouldShowSssBenefits || shouldShowEmailRequest;
    if (chooseBtn) {
        var isAttachmentPickerDisabled = shouldShowSssBenefits || shouldShowEmailRequest;
        chooseBtn.setAttribute('aria-disabled', isAttachmentPickerDisabled ? 'true' : 'false');
        chooseBtn.tabIndex = isAttachmentPickerDisabled ? -1 : 0;
    }
    if (attachmentOptionalText) attachmentOptionalText.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment || shouldRequireIncidentAttachment) ? 'none' : '';
    if (attachmentRequiredAsterisk) attachmentRequiredAsterisk.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment || shouldRequireIncidentAttachment) ? '' : 'none';
    syncAttachmentCopy(shouldShowMarketingRequest ? 'marketing' : (shouldShowMedicalCashAdvance ? 'medical' : (shouldRequireKamiAttachment ? 'kami' : 'default')));

    if (concernTypeSelect) {
        if (shouldShowConcernType) {
            concernTypeSelect.setAttribute('required', 'required');
        } else {
            concernTypeSelect.removeAttribute('required');
            concernTypeSelect.value = '';
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
            leaveSubjectInput.value = '';
        }
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
    if (certificateLeavePurposeOtherContainer) certificateLeavePurposeOtherContainer.classList.toggle('is-visible', shouldShowCertificateLeavePurposeOther);
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
    if (sapRequestList) {
        Array.from(sapRequestList.querySelectorAll('[data-sap-field]')).forEach(function(input) {
            if (!(input instanceof HTMLElement)) return;
            var fieldName = String(input.getAttribute('data-sap-field') || '');
            if (shouldShowSapRequest && fieldName === 'name') input.setAttribute('required', 'required');
            else input.removeAttribute('required');
        });
    }
    if (emailRequestTypeSelect) {
        if (shouldShowEmailRequest) {
            emailRequestTypeSelect.disabled = false;
            emailRequestTypeSelect.setAttribute('required', 'required');
        } else {
            emailRequestTypeSelect.disabled = true;
            emailRequestTypeSelect.removeAttribute('required');
            emailRequestTypeSelect.value = '';
        }
    }
    applyEmailCreationInputState();
    if (coeRequestReasonOtherInput) {
        var otherCoeSelected = coeRequestReasonInputs.some(function(input) { return input.checked && input.value === 'Other'; });
        if (shouldShowCoeRequest && otherCoeSelected) coeRequestReasonOtherInput.setAttribute('required', 'required');
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

    sssUploadConfigs.forEach(function(config) {
        var input = document.getElementById(config.inputId);
        if (!input) return;
        input.disabled = !shouldShowSssBenefits;
        if (!shouldShowSssBenefits) {
            setSssUploadError(config, '');
        }
    });

    if (descriptionFieldEl) {
            if (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowIncidentReport || shouldShowSapRequest || shouldShowEmailCreation) {
                descriptionFieldEl.removeAttribute('required');
            if (shouldShowSssBenefits && descriptionFieldEl.value.trim() === '') {
                descriptionFieldEl.value = sssAutoDescription;
                descriptionFieldEl.setAttribute('data-auto-filled', 'true');
            }
        } else {
            descriptionFieldEl.setAttribute('required', 'required');
            if (descriptionFieldEl.getAttribute('data-auto-filled') === 'true' && descriptionFieldEl.value === sssAutoDescription) {
                descriptionFieldEl.value = '';
            }
            descriptionFieldEl.removeAttribute('data-auto-filled');
        }
    }

    if (shouldShowConcernType && kamiContinuationHost) {
        moveDescriptionContainer(kamiContinuationHost);
        moveAttachmentContainer(kamiContinuationHost);
    } else if (shouldShowMarketingRequest && marketingDescriptionHost) {
        moveDescriptionContainer(marketingDescriptionHost);
    } else if ((shouldShowEmailDefault || shouldShowEmailForgotPassword || shouldShowEmailBackup) && emailDescriptionHost) {
        moveDescriptionContainer(emailDescriptionHost);
    } else if (shouldShowOtherDetailsStyle && otherRequestContinuationHost) {
        moveDescriptionContainer(otherRequestContinuationHost);
        moveAttachmentContainer(otherRequestContinuationHost);
    } else {
        restoreDescriptionContainer();
    }

    if (shouldShowIncidentReport && incidentReportAttachmentHost) {
        moveAttachmentContainer(incidentReportAttachmentHost);
    } else if (shouldShowMedicalCashAdvance && medicalCashAttachmentHost) {
        moveAttachmentContainer(medicalCashAttachmentHost);
    } else if (shouldShowConcernType && kamiContinuationHost) {
        moveAttachmentContainer(kamiContinuationHost);
    } else if (shouldShowOtherDetailsStyle && otherRequestContinuationHost) {
        moveAttachmentContainer(otherRequestContinuationHost);
    } else if (attachmentOriginalHost) {
        moveAttachmentContainer(attachmentOriginalHost);
    }
    toggleSupplyChainOptionalSections(!!(supplyChainDetailsRow && supplyChainDetailsRow.classList.contains('is-visible')));
}

if (recipient) recipient.addEventListener('change', function() {
    syncRecipientTriggerLabel();
    toggleDepartmentField();
    toggleCategoryField();
    toggleHrExtraFields();
});
if (recipientTrigger) {
    recipientTrigger.addEventListener('click', function() {
        if (!recipientMenu) return;
        var nextState = !recipientMenu.classList.contains('is-open');
        if (!nextState) {
            closeRecipientDropdown();
            return;
        }
        closeDepartmentDropdown();
        closeAdminLegalRequestForDropdown();
        closeCategoryDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        recipientMenu.classList.add('is-open');
        recipientTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (departmentTrigger) {
    departmentTrigger.addEventListener('click', function() {
        if (departmentTrigger.disabled || !departmentMenu) return;
        var nextState = !departmentMenu.classList.contains('is-open');
        if (!nextState) {
            closeDepartmentDropdown();
            return;
        }
        closeRecipientDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        departmentMenu.classList.add('is-open');
        departmentTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (adminLegalRequestForTrigger) {
    adminLegalRequestForTrigger.addEventListener('click', function() {
        if (adminLegalRequestForTrigger.disabled || !adminLegalRequestForMenu) return;
        renderAdminLegalRequestForDropdownOptions();
        var nextState = !adminLegalRequestForMenu.classList.contains('is-open');
        if (!nextState) {
            closeAdminLegalRequestForDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        adminLegalRequestForMenu.classList.add('is-open');
        adminLegalRequestForTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (categoryTrigger) {
    categoryTrigger.addEventListener('click', function() {
        if (categoryTrigger.disabled || !categoryMenu) return;
        toggleCategoryField();
        var nextState = !categoryMenu.classList.contains('is-open');
        if (!nextState) {
            closeCategoryDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        categoryMenu.classList.add('is-open');
        categoryTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (marketingSubcategoryTrigger) {
    marketingSubcategoryTrigger.addEventListener('click', function() {
        if (marketingSubcategoryTrigger.disabled || !marketingSubcategoryMenu) return;
        var nextState = !marketingSubcategoryMenu.classList.contains('is-open');
        if (!nextState) {
            closeMarketingSubcategoryDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        marketingSubcategoryMenu.classList.add('is-open');
        marketingSubcategoryTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (priorityTrigger) {
    priorityTrigger.addEventListener('click', function() {
        if (!areRoutingSelectionsComplete()) {
            togglePriorityField();
            return;
        }
        if (priorityTrigger.disabled || !priorityMenu) return;
        renderPriorityDropdownOptions();
        var nextState = !priorityMenu.classList.contains('is-open');
        if (!nextState) {
            closePriorityDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        priorityMenu.classList.add('is-open');
        priorityTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (emailRequestTypeTrigger) {
    emailRequestTypeTrigger.addEventListener('click', function() {
        if (emailRequestTypeTrigger.disabled || !emailRequestTypeMenu) return;
        renderEmailRequestTypeDropdownOptions();
        var nextState = !emailRequestTypeMenu.classList.contains('is-open');
        if (!nextState) {
            closeEmailRequestTypeDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        emailRequestTypeMenu.classList.add('is-open');
        emailRequestTypeTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (salesPositionTrigger) {
    salesPositionTrigger.addEventListener('click', function() {
        if (salesPositionTrigger.disabled || !salesPositionMenu) return;
        renderSalesPositionDropdownOptions();
        var nextState = !salesPositionMenu.classList.contains('is-open');
        if (!nextState) {
            closeSalesPositionDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesRegionDropdown();
        closeConcernTypeDropdown();
        salesPositionMenu.classList.add('is-open');
        salesPositionTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (salesRegionTrigger) {
    salesRegionTrigger.addEventListener('click', function() {
        if (salesRegionTrigger.disabled || !salesRegionMenu) return;
        renderSalesRegionDropdownOptions();
        var nextState = !salesRegionMenu.classList.contains('is-open');
        if (!nextState) {
            closeSalesRegionDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeConcernTypeDropdown();
        salesRegionMenu.classList.add('is-open');
        salesRegionTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (concernTypeTrigger) {
    concernTypeTrigger.addEventListener('click', function() {
        if (concernTypeTrigger.disabled || !concernTypeMenu) return;
        renderConcernTypeDropdownOptions();
        var nextState = !concernTypeMenu.classList.contains('is-open');
        if (!nextState) {
            closeConcernTypeDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        closeAdminLegalRequestForDropdown();
        closeMarketingSubcategoryDropdown();
        closePriorityDropdown();
        closeEmailRequestTypeDropdown();
        closeSalesPositionDropdown();
        closeSalesRegionDropdown();
        concernTypeMenu.classList.add('is-open');
        concernTypeTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (emailRequestTypeSelect) {
    emailRequestTypeSelect.addEventListener('change', function() {
        emailRequestTypeSelect.setAttribute('data-selected', String(emailRequestTypeSelect.value || ''));
        syncEmailRequestTypeTriggerLabel();
        renderEmailRequestTypeDropdownOptions();
    });
    renderEmailRequestTypeDropdownOptions();
}
if (salesPositionSelect) {
    salesPositionSelect.addEventListener('change', function() {
        salesPositionSelect.setAttribute('data-selected', String(salesPositionSelect.value || ''));
        renderSalesPositionDropdownOptions();
    });
    renderSalesPositionDropdownOptions();
}
if (salesRegionSelect) {
    salesRegionSelect.addEventListener('change', function() {
        salesRegionSelect.setAttribute('data-selected', String(salesRegionSelect.value || ''));
        renderSalesRegionDropdownOptions();
    });
    renderSalesRegionDropdownOptions();
}
if (priorityMenu) {
    priorityMenu.addEventListener('click', function(event) {
        var optionButton = event.target.closest('.priority-dropdown-option');
        if (!optionButton || !priorityMenu.contains(optionButton)) return;
        choosePriority(String(optionButton.getAttribute('data-value') || ''), true);
    });
}
document.addEventListener('click', function(event) {
    if (!recipientDropdown && !departmentDropdown && !adminLegalRequestForDropdown && !categoryDropdown && !marketingSubcategoryDropdown && !priorityDropdown && !emailRequestTypeDropdown && !salesPositionDropdown && !salesRegionDropdown && !concernTypeDropdown) return;
    if (
        (recipientDropdown && recipientDropdown.contains(event.target))
        || (departmentDropdown && departmentDropdown.contains(event.target))
        || (adminLegalRequestForDropdown && adminLegalRequestForDropdown.contains(event.target))
        || (categoryDropdown && categoryDropdown.contains(event.target))
        || (marketingSubcategoryDropdown && marketingSubcategoryDropdown.contains(event.target))
        || (priorityDropdown && priorityDropdown.contains(event.target))
        || (emailRequestTypeDropdown && emailRequestTypeDropdown.contains(event.target))
        || (salesPositionDropdown && salesPositionDropdown.contains(event.target))
        || (salesRegionDropdown && salesRegionDropdown.contains(event.target))
        || (concernTypeDropdown && concernTypeDropdown.contains(event.target))
    ) return;
    closeRecipientDropdown();
    closeDepartmentDropdown();
    closeAdminLegalRequestForDropdown();
    closeCategoryDropdown();
    closeMarketingSubcategoryDropdown();
    closePriorityDropdown();
    closeEmailRequestTypeDropdown();
    closeSalesPositionDropdown();
    closeSalesRegionDropdown();
    closeConcernTypeDropdown();
});
if (departmentSelect) departmentSelect.addEventListener('change', function() {
    syncDepartmentTriggerLabel();
    toggleCategoryField();
    toggleHrExtraFields();
    syncCurrentSapDepartment();
});
if (categorySelect) categorySelect.addEventListener('change', function() {
    syncCategoryTriggerLabel();
    toggleMarketingSubcategory();
    toggleHrExtraFields();
});
if (adminLegalRequestForSelect) adminLegalRequestForSelect.addEventListener('change', function() {
    adminLegalRequestForSelect.setAttribute('data-selected', String(adminLegalRequestForSelect.value || ''));
    categorySelect.value = '';
    categorySelect.setAttribute('data-selected', '');
    toggleCategoryField();
    syncAdminLegalRequestForTriggerLabel();
    toggleHrExtraFields();
});
if (marketingSubcategorySelect) marketingSubcategorySelect.addEventListener('change', function() {
    syncMarketingSubcategoryTriggerLabel();
    toggleSupplyChainDetails();
});
if (emailRequestTypeSelect) emailRequestTypeSelect.addEventListener('change', function() {
    toggleHrExtraFields();
    syncEmailCreationCardState();
});
if (prioritySelect) prioritySelect.addEventListener('change', function() {
    prioritySelect.setAttribute('data-selected', String(prioritySelect.value || ''));
    renderPriorityDropdownOptions();
});
if (concernTypeSelect) concernTypeSelect.addEventListener('change', function() {
    concernTypeSelect.setAttribute('data-selected', String(concernTypeSelect.value || ''));
    syncConcernTypeTriggerLabel();
    renderConcernTypeDropdownOptions();
    toggleHrExtraFields();
});
if (concernTypeSelect) {
    renderConcernTypeDropdownOptions();
}
if (certificateLeavePurposeSelect) certificateLeavePurposeSelect.addEventListener('change', function() {
    toggleHrExtraFields();
});
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
buildRecipientDropdown();
toggleDepartmentField();
syncRecipientTriggerLabel();
syncDepartmentTriggerLabel();
toggleCategoryField();
syncCategoryTriggerLabel();
toggleMarketingSubcategory();
renderPriorityDropdownOptions();
toggleHrExtraFields();
syncEmailCreationCardState();
initializeSavedSapReportsFromDom();
syncSapCardState();

if (sapAddEmployeeBtn) {
    sapAddEmployeeBtn.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();
        var incompleteField = getFirstIncompleteCurrentSapField();
        if (incompleteField) {
            showSapAddEmployeeError(incompleteField);
            return false;
        }
        return addSapCard();
    });
}
if (sapEmployeeSwitcher) {
    sapEmployeeSwitcher.addEventListener('change', function() {
        var selectedValue = String(sapEmployeeSwitcher.value || 'current');
        if (selectedValue === 'current') {
            currentSapViewKey = 'current';
            setCurrentSapReportValues(cloneSapReport(currentSapDraft || getCurrentSapReportValues()));
            syncSapCardState();
            return;
        }
        loadSavedSapReportIntoCurrentForm(selectedValue);
    });
}
if (sapRequestList) {
    sapRequestList.addEventListener('click', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        var removeButton = target.closest('[data-remove-sap-report]');
        if (!removeButton) return;
        removeLastSavedSapEmployee();
    });
    sapRequestList.addEventListener('input', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        currentSapViewKey = 'current';
        if (target.matches('[data-sap-field="name"]')) {
            syncSapCardState();
        }
    });
    sapRequestList.addEventListener('change', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        currentSapViewKey = 'current';
        if (target.matches('[data-sap-field="name"]')) {
            syncSapCardState();
        }
    });
}

if (concernTypeSelect) {
    var selectedConcernType = String(concernTypeSelect.getAttribute('data-selected') || '');
    if (selectedConcernType !== '') {
        concernTypeSelect.value = selectedConcernType;
    }
    concernTypeSelect.setAttribute('data-selected', '');
}

sssUploadConfigs.forEach(function(config) {
    var input = document.getElementById(config.inputId);
    if (!input) return;
    sssUploadState[config.inputId] = { files: Array.from(input.files || []) };
    updateSssUploadSummary(config);
    input.addEventListener('change', function() {
        setInlineFormError('');
        var files = Array.from(input.files || []);
        if (files.length === 0) {
            syncSssInputFiles(config);
            return;
        }
        mergeSssUploadFiles(config, files);
    });
});

var attachmentInput = document.getElementById('attachments');
var chooseBtn = document.getElementById('choose-file-btn');
var attachmentPreviewModal = document.getElementById('attachmentPreviewModal');
var attachmentPreviewBody = document.getElementById('attachmentPreviewBody');
var attachmentPreviewTitle = document.getElementById('attachmentPreviewTitle');
var attachmentPreviewMeta = document.getElementById('attachmentPreviewMeta');
var attachmentPreviewClose = document.getElementById('attachmentPreviewClose');
var attachmentPreviewPrev = document.getElementById('attachmentPreviewPrev');
var attachmentPreviewNext = document.getElementById('attachmentPreviewNext');
var activeAttachmentPreviewItems = [];
var activeAttachmentPreviewIndex = -1;
var activeAttachmentPreviewUrl = '';
var activeAttachmentPreviewIsTemporary = false;
var MAX_BYTES = 5 * 1024 * 1024;
var MAX_FILES = 5;
var ALLOWED_EXT = ['jpg','jpeg','png','pdf','doc','docx'];
var SSS_ALLOWED_EXT = ['jpg','jpeg','png','pdf','doc','docx'];
var SSS_MAX_FILE_BYTES = 10 * 1024 * 1024;

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
    if (attachmentPreviewPrev) {
        attachmentPreviewPrev.disabled = !hasMultipleFiles;
    }
    if (attachmentPreviewNext) {
        attachmentPreviewNext.disabled = !hasMultipleFiles;
    }
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

function getExt(name) {
    var parts = String(name || '').toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
}

function getMainAttachmentFiles() {
    var input = document.getElementById('attachments');
    return Array.from((input && input.files) || []);
}

function showMainAttachmentError(message) {
    var errorBox = document.getElementById('attachment-error');
    if (!errorBox) return;
    errorBox.textContent = message || '';
    errorBox.style.display = message ? 'block' : 'none';
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
    return { message: firstErrorMessage, config: firstErrorConfig };
}

window.TMSalesResetAttachments = function() {
    var input = document.getElementById('attachments');
    var fileName = document.getElementById('file-name');
    var previewBox = document.getElementById('attachment-preview');
    if (input) input.value = '';
    if (fileName) fileName.textContent = 'No file chosen';
    if (previewBox) previewBox.innerHTML = '';
    showMainAttachmentError('');
};
window.TMSalesResetSssUploads = function() {
    resetSssUploads();
};
window.TMSalesRefreshHrUi = function() {
    toggleDepartmentField();
    toggleCategoryField();
    toggleHrExtraFields();
    setInlineFormError('');
};

toggleHrExtraFields();

var formEl = attachmentInput ? attachmentInput.closest('form') : null;
if (formEl) {
    formEl.addEventListener('submit', function (e) {
        var selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
        var isLapcHrSelected = isLapcHrSelection();
        var isLapcItSelected = isLapcItSelection();
        var isLapcAdminLegalSelected = recipient && departmentSelect
            && isLapcRecipientValue(String(recipient.value || ''))
            && String(departmentSelect.value || '') === 'Admin & Legal';
        var isLapcMarketingRequestTypeSelected = isLapcMarketingRequestTypeSelection();
        var isLapcMarketingSelected = isLapcMarketingSelection();
        var isKamiAttachmentRequired = isLapcHrSelected && selectedCategory === 'Attendance & Timekeeping';
        var isHrSssSelected = isLapcHrSelected && selectedCategory === 'SSS Sickness and Benefit Concern';
        var isHrMedicalCashAdvanceSelected = isLapcHrSelected && selectedCategory === 'Medical Cash Advance';
        var isIncidentReportAttachmentRequired = isLapcHrSelected && selectedCategory === 'Incident Report';
        var mainAttachmentFiles = getMainAttachmentFiles();
        var badType = mainAttachmentFiles.find(function (file) {
            var ext = getExt(file && file.name);
            return ALLOWED_EXT.indexOf(ext) === -1;
        });
        var total = 0;
        mainAttachmentFiles.forEach(function (f) { total += (f && f.size) ? f.size : 0; });

        setInlineFormError('');

        if (salesPositionSelect && !String(salesPositionSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose a position.');
            return;
        }
        if (salesRegionSelect && !String(salesRegionSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose a region.');
            return;
        }
        if (prioritySelect && !String(prioritySelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the level of urgency.');
            return;
        }
        if (isLapcAdminLegalSelected && adminLegalRequestForSelect && !String(adminLegalRequestForSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose who the request is for.');
            return;
        }
        if (isLapcAdminLegalSelected && categorySelect && !String(categorySelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose a category.');
            return;
        }
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && !String(emailRequestTypeSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the email request type.');
            return;
        }
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email') {
            var emailCards = getEmailCreationCards();
            if (emailCards.length === 0) {
                e.preventDefault();
                setInlineFormError('Please complete the Creation of email details.');
                return;
            }
            for (var emailCardIndex = 0; emailCardIndex < emailCards.length; emailCardIndex += 1) {
                var incompleteEmailCreationField = findFirstIncompleteEmailCreationInput(emailCards[emailCardIndex]);
                if (incompleteEmailCreationField) {
                    e.preventDefault();
                    setActiveEmailCard(emailCardIndex);
                    setInlineFormError('Please complete each Creation of email card before submitting.');
                    try { incompleteEmailCreationField.focus(); } catch (focusError) {}
                    return;
                }
            }
        }
        var requiresLapcRequestType = isLapcMarketingRequestTypeSelected && (Object.prototype.hasOwnProperty.call(lapcMarketingSubcategories, selectedCategory) || Object.prototype.hasOwnProperty.call(lapcSupplyChainRequestTypes, selectedCategory));
        if (requiresLapcRequestType && marketingSubcategorySelect && !String(marketingSubcategorySelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the Request Type / Concern.');
            return;
        }
        if (isLapcMarketingSelected) {
            syncMaterialSizeInput();
            var hasRequestedMaterial = requestedMaterialsSelect
                ? String(requestedMaterialsSelect.value || '').trim() !== ''
                : requestedMaterialsInputs.some(function(input) { return input.checked; });
            var hasCrop = cropSelect
                ? String(cropSelect.value || '').trim() !== ''
                : cropInputs.some(function(input) { return input.checked; });
            var requestedOtherSelected = requestedMaterialsSelect
                ? String(requestedMaterialsSelect.value || '') === 'Other'
                : requestedMaterialsInputs.some(function(input) { return input.checked && input.value === 'Other'; });
            var cropOtherSelected = cropSelect
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
            if (descriptionFieldEl && !String(descriptionFieldEl.value || '').trim()) {
                e.preventDefault();
                setInlineFormError('Please enter the Brief Description of Request.');
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
        if (isHrMedicalCashAdvanceSelected) {
            if (medicalCashPurposeInput && !String(medicalCashPurposeInput.value || '').trim()) {
                e.preventDefault();
                setInlineFormError('Please complete the Medical Cash Advance form.');
                return;
            }
            if (medicalCashAmountInput && !String(medicalCashAmountInput.value || '').trim()) {
                e.preventDefault();
                setInlineFormError('Please complete the Medical Cash Advance form.');
                return;
            }
            if (medicalCashDateNeededInput && !String(medicalCashDateNeededInput.value || '').trim()) {
                e.preventDefault();
                setInlineFormError('Please complete the Medical Cash Advance form.');
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
            var hasPropertyType = companyPropertyTypeInputs.some(function(input) { return input.checked; });
            var hasPropertyReason = companyPropertyReasonInputs.some(function(input) { return input.checked; });
            if (!hasPropertyType || !hasPropertyReason) {
                e.preventDefault();
                setInlineFormError('Please complete the Request for Company Property form.');
                return;
            }
        }
        if (isLapcHrSelected && selectedCategory === 'Certificate of Employment') {
            var coeReasonSelected = coeRequestReasonInputs.find(function(input) { return input.checked; });
            var hasSalarySelected = coeSalaryDetailsInputs.some(function(input) { return input.checked; });
            var hasDeliverySelected = coeDeliveryMethodInputs.some(function(input) { return input.checked; });
            if (!coeReasonSelected || !hasSalarySelected || !hasDeliverySelected || !String((coePreferredReleaseDateInput && coePreferredReleaseDateInput.value) || '').trim() || !String((coeRemarksInput && coeRemarksInput.value) || '').trim()) {
                e.preventDefault();
                setInlineFormError('Please complete the Certificate of Employment form.');
                return;
            }
            if (coeReasonSelected.value === 'Other' && coeRequestReasonOtherInput && !String(coeRequestReasonOtherInput.value || '').trim()) {
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
            if (isIncidentReportAttachmentRequired && mainAttachmentFiles.length === 0) {
                e.preventDefault();
                showMainAttachmentError('Attachment is required for Incident Report.');
                return;
            }
        }
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && !String(emailRequestTypeSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the email request type.');
            return;
        }
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email') {
            var emailCards = getEmailCreationCards();
            if (emailCards.length === 0) {
                e.preventDefault();
                setInlineFormError('Please complete the Creation of email details.');
                return;
            }
            for (var emailCardIndex = 0; emailCardIndex < emailCards.length; emailCardIndex += 1) {
                var incompleteEmailCreationField = findFirstIncompleteEmailCreationInput(emailCards[emailCardIndex]);
                if (incompleteEmailCreationField) {
                    e.preventDefault();
                    setActiveEmailCard(emailCardIndex);
                    setInlineFormError('Please complete each Creation of email card before submitting.');
                    try { incompleteEmailCreationField.focus(); } catch (focusError) {}
                    return;
                }
            }
        }
        if (isLapcItSelected && selectedCategory === 'SAP') {
            var sapCards = getSapCards();
            for (var sapIndex = 0; sapIndex < sapCards.length; sapIndex += 1) {
                var sapCard = sapCards[sapIndex];
                var requiredSapFields = ['name'];
                for (var fieldIndex = 0; fieldIndex < requiredSapFields.length; fieldIndex += 1) {
                    var fieldName = requiredSapFields[fieldIndex];
                    var sapInput = sapCard ? sapCard.querySelector('[data-sap-field="' + fieldName + '"]') : null;
                    if (sapInput && !String(sapInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete each SAP employee report before submitting.');
                        try { sapInput.focus(); } catch (focusError) {}
                        return;
                    }
                }
            }
        }
        if (isHrSssSelected) {
            var sssUploadValidation = validateSssUploads();
            if (sssUploadValidation.message !== '') {
                e.preventDefault();
                if (sssUploadValidation.config) {
                    var sssErrorEl = document.getElementById(sssUploadValidation.config.errorId);
                    if (sssErrorEl) {
                        sssErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                return;
            }
        }
        if (isKamiAttachmentRequired && mainAttachmentFiles.length === 0) {
            e.preventDefault();
            showMainAttachmentError('Attachment is required for Attendance & Timekeeping.');
            return;
        }
        if (isHrMedicalCashAdvanceSelected && mainAttachmentFiles.length === 0) {
            e.preventDefault();
            showMainAttachmentError('Supporting Information is required for Medical Cash Advance.');
            return;
        }
        if (!isHrSssSelected && (mainAttachmentFiles.length > MAX_FILES || badType || total > MAX_BYTES)) {
            e.preventDefault();
            showMainAttachmentError(mainAttachmentFiles.length > MAX_FILES ? 'Maximum 5 attachments allowed.' : (badType ? 'Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.' : 'Attachment too large. Max 5MB total.'));
            return;
        }
        showMainAttachmentError('');
    });
}
</script>

<script>
(function() {
    function runSapFallback() {
        var sapSection = document.getElementById('sapRequestSection');
        var sapList = document.getElementById('sapRequestList');
        var sapSavedHost = document.getElementById('sapSavedReportsHost');
        var sapCounter = document.getElementById('sapRequestCounter');
        var sapSwitcher = document.getElementById('sapEmployeeSwitcher');
        var addButton = document.getElementById('sapAddEmployeeBtn');
        var ajaxError = document.getElementById('ajaxError');

        if (!sapSection || !sapList || !sapSavedHost || !addButton) return;

        var currentCard = sapList.querySelector('[data-sap-card]');
        if (!currentCard) return;

        var addButtonClone = addButton.cloneNode(true);
        addButton.parentNode.replaceChild(addButtonClone, addButton);
        addButton = addButtonClone;

        var removeButton = currentCard.querySelector('[data-remove-sap-report]');
        if (removeButton) {
            var removeClone = removeButton.cloneNode(true);
            removeButton.parentNode.replaceChild(removeClone, removeButton);
            removeButton = removeClone;
        }
        var currentViewKey = 'current';
        var currentDraft = null;

        function getField(fieldName) {
            return currentCard.querySelector('[data-sap-field="' + fieldName + '"]');
        }

        function getSavedReports() {
            var grouped = {};
            Array.from(sapSavedHost.querySelectorAll('input[type="hidden"]')).forEach(function(input) {
                var match = String(input.name || '').match(/^sap_reports\[(\d+)\]\[(name|position|address|department|tin)\]$/);
                if (!match) return;
                var index = match[1];
                var field = match[2];
                if (!grouped[index]) {
                    grouped[index] = { name: '', position: '', address: '', department: '', tin: '' };
                }
                grouped[index][field] = input.value || '';
            });
            return Object.keys(grouped).sort(function(a, b) {
                return parseInt(a, 10) - parseInt(b, 10);
            }).map(function(index) {
                return grouped[index];
            });
        }

        function renderSavedReports(reports) {
            sapSavedHost.innerHTML = '';
            reports.forEach(function(report, index) {
                ['name', 'position', 'address', 'department', 'tin'].forEach(function(field) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'sap_reports[' + index + '][' + field + ']';
                    input.value = report[field] || '';
                    sapSavedHost.appendChild(input);
                });
            });
        }

        function setCurrentValues(report) {
            var safeReport = report || {};
            var nameInput = getField('name');
            var positionInput = getField('position');
            var addressInput = getField('address');
            var departmentInput = getField('department');
            var tinInput = getField('tin');

            if (nameInput) nameInput.value = String(safeReport.name || '');
            if (positionInput) positionInput.value = String(safeReport.position || '');
            if (addressInput) addressInput.value = String(safeReport.address || '');
            if (departmentInput) departmentInput.value = String(safeReport.department || '');
            if (tinInput) tinInput.value = String(safeReport.tin || '');
        }

        function syncCurrentFieldNames(savedReports) {
            var currentIndex = savedReports.length;
            ['name', 'position', 'address', 'department', 'tin'].forEach(function(field) {
                var input = getField(field);
                if (input) {
                    input.name = 'sap_reports[' + currentIndex + '][' + field + ']';
                }
            });
        }

        function getCurrentValues() {
            return {
                name: String((getField('name') || {}).value || '').trim(),
                position: String((getField('position') || {}).value || '').trim(),
                address: String((getField('address') || {}).value || '').trim(),
                department: String((getField('department') || {}).value || '').trim(),
                tin: String((getField('tin') || {}).value || '').trim()
            };
        }

        function cloneReport(report) {
            var source = report || {};
            return {
                name: String(source.name || ''),
                position: String(source.position || ''),
                address: String(source.address || ''),
                department: String(source.department || ''),
                tin: String(source.tin || '')
            };
        }

        function clearCurrentValues() {
            ['name', 'position', 'address', 'department', 'tin'].forEach(function(field) {
                var input = getField(field);
                if (input) {
                    input.value = '';
                }
            });
            currentDraft = cloneReport(getCurrentValues());
        }

        function showSapError(message, field) {
            if (ajaxError) {
                ajaxError.textContent = message;
                ajaxError.style.display = 'block';
                ajaxError.setAttribute('tabindex', '-1');
            }
            sapSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (field) {
                try { field.focus(); } catch (error) {}
            }
        }

        function clearSapError() {
            if (!ajaxError) return;
            ajaxError.style.display = 'none';
            ajaxError.textContent = '';
        }

        function getFirstIncompleteField() {
            var requiredFields = ['name'];
            for (var i = 0; i < requiredFields.length; i += 1) {
                var input = getField(requiredFields[i]);
                if (input && !String(input.value || '').trim()) {
                    return input;
                }
            }
            return null;
        }

        function syncUi() {
            var savedReports = getSavedReports();
            if (currentViewKey === 'current') {
                currentDraft = cloneReport(getCurrentValues());
            }
            var totalEmployees = savedReports.length + 1;
            var currentEmployeeNumber = totalEmployees;
            if (currentViewKey !== 'current') {
                var selectedIndex = parseInt(currentViewKey, 10);
                if (!isNaN(selectedIndex) && selectedIndex >= 0 && selectedIndex < savedReports.length) {
                    currentEmployeeNumber = selectedIndex + 1;
                } else {
                    currentViewKey = 'current';
                }
            }

            if (sapCounter) {
                sapCounter.textContent = 'Employee ' + currentEmployeeNumber + ' of ' + totalEmployees;
            }

            if (sapSwitcher) {
                sapSwitcher.innerHTML = '';
                savedReports.forEach(function(report, index) {
                    var option = document.createElement('option');
                    option.value = String(index);
                    option.textContent = String(report.name || '').trim() !== '' ? report.name : ('Employee ' + (index + 1));
                    option.selected = currentViewKey === String(index);
                    sapSwitcher.appendChild(option);
                });

                var currentValues = cloneReport(currentDraft || getCurrentValues());
                var currentOption = document.createElement('option');
                currentOption.value = 'current';
                currentOption.selected = currentViewKey === 'current';
                currentOption.textContent = String(currentValues.name || '').trim() !== '' ? currentValues.name : ('Employee ' + totalEmployees);
                sapSwitcher.appendChild(currentOption);
            }

            if (removeButton) {
                removeButton.style.display = totalEmployees > 1 ? '' : 'none';
            }

            syncCurrentFieldNames(savedReports);
        }

        addButton.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            var incompleteField = getFirstIncompleteField();
            if (incompleteField) {
                showSapError('Please complete the current SAP employee report before adding another employee.', incompleteField);
                return false;
            }

            var savedReports = getSavedReports();
            savedReports.push(getCurrentValues());
            renderSavedReports(savedReports);
            clearCurrentValues();
            currentViewKey = 'current';
            currentDraft = cloneReport(getCurrentValues());
            clearSapError();
            syncUi();

            var firstInput = getField('name');
            if (firstInput) {
                try { firstInput.focus(); } catch (error) {}
            }
            return false;
        });

        if (removeButton) {
            removeButton.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();

                var savedReports = getSavedReports();
                if (savedReports.length === 0) {
                    syncUi();
                    return false;
                }

                savedReports.pop();
                renderSavedReports(savedReports);
                currentViewKey = 'current';
                currentDraft = cloneReport(getCurrentValues());
                clearSapError();
                syncUi();
                return false;
            });
        }

        if (sapSwitcher) {
            sapSwitcher.addEventListener('change', function() {
                var selectedValue = String(sapSwitcher.value || 'current');
                var savedReports = getSavedReports();
                if (selectedValue === 'current') {
                    currentViewKey = 'current';
                    setCurrentValues(cloneReport(currentDraft || getCurrentValues()));
                    syncUi();
                    return;
                }

                var selectedIndex = parseInt(selectedValue, 10);
                if (isNaN(selectedIndex) || selectedIndex < 0 || selectedIndex >= savedReports.length) {
                    currentViewKey = 'current';
                    setCurrentValues(cloneReport(currentDraft || getCurrentValues()));
                    syncUi();
                    return;
                }

                if (currentViewKey === 'current') {
                    currentDraft = cloneReport(getCurrentValues());
                }
                currentViewKey = String(selectedIndex);
                setCurrentValues(savedReports[selectedIndex]);
                syncUi();
            });
        }

        ['name', 'position', 'address', 'department', 'tin'].forEach(function(field) {
            var input = getField(field);
            if (!input) return;
            input.addEventListener('input', function() {
                currentViewKey = 'current';
                clearSapError();
                syncUi();
            });
            input.addEventListener('change', function() {
                currentViewKey = 'current';
                clearSapError();
                syncUi();
            });
        });

        currentDraft = cloneReport(getCurrentValues());
        syncUi();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runSapFallback);
    } else {
        runSapFallback();
    }
})();

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
    var descriptionField = form ? form.querySelector('textarea[name="description"]') : null;
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

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function showSuccessState(ticketNumber, emailWarning) {
        if (!modal) return;
        clearLoadingTimers();
        var ticketLine = ticketNumber
            ? ('<br><span class="ticket-modal-ticket-label">Ticket ID:</span> <span class="ticket-modal-ticket-number">#' + ticketNumber + '</span>')
            : '';
        var message = 'Your request has been saved.<br>Our team will get back to you soon.' + ticketLine;
        if (emailWarning) {
            message += '<br><br><span class="ticket-modal-ticket-label">' + escapeHtml(emailWarning) + '</span>';
        }
        setModalState('success', emailWarning ? 'Ticket Saved, Email Not Sent' : 'Ticket Submitted Successfully', message, '', 100);
    }

    function validateDescription() {
        if (!descriptionField) return true;
        if (!descriptionField.hasAttribute('required')) {
            descriptionField.setCustomValidity('');
            return true;
        }
        var value = String(descriptionField.value || '').trim();
        descriptionField.setCustomValidity(value === '' ? 'Description is required.' : '');
        return value !== '';
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
                window.location.href = '../index.php';
                return;
            }
            closeModal();
        });
    }
    if (descriptionField) {
        descriptionField.addEventListener('input', validateDescription);
        descriptionField.addEventListener('blur', validateDescription);
    }

    form.addEventListener('submit', function(e) {
        if (e.defaultPrevented) return;
        if (!validateDescription()) {
            e.preventDefault();
            if (typeof descriptionField.reportValidity === 'function') {
                descriptionField.reportValidity();
            }
            return;
        }
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
                        showSuccessState(data.ticket_number || '', data.email_warning || '');
                    }, waitMs);
                } else {
                    showSuccessState(data.ticket_number || '', data.email_warning || '');
                }
            }
            form.reset();
            if (typeof window.TMSalesResetAttachments === 'function') window.TMSalesResetAttachments();
            if (typeof window.TMSalesResetSssUploads === 'function') window.TMSalesResetSssUploads();
            if (typeof window.TMSalesRefreshHrUi === 'function') window.TMSalesRefreshHrUi();
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
</script>

<script>
(function () {
    var pageError = document.getElementById('pageError');
    if (!pageError) return;
    window.requestAnimationFrame(function () {
        pageError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('attachments');
    const fileName = document.getElementById('file-name');
    const preview = document.getElementById('attachment-preview');
    const errorBox = document.getElementById('attachment-error');
    const chooseBtn = document.getElementById('choose-file-btn');
    const attachmentShell = document.querySelector('#attachmentContainer .attachment-upload-shell');

    console.log('[MAIN ATTACHMENT] controller loaded');
    console.log('[MAIN ATTACHMENT] input found:', input);

    if (!input || !fileName) {
        console.error('[MAIN ATTACHMENT] Missing #attachments or #file-name');
        return;
    }

    const allowedExt = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
    const maxFiles = 5;
    const maxTotalSize = 5 * 1024 * 1024;
    let selectedFiles = [];
    let objectUrls = [];

    function createTransfer() {
        try {
            return new DataTransfer();
        } catch (error) {
            return null;
        }
    }

    function showError(message) {
        if (errorBox) {
            errorBox.textContent = message || '';
            errorBox.style.display = message ? 'block' : 'none';
        }
    }

    function clearPreview() {
        objectUrls.forEach(function (url) {
            try { URL.revokeObjectURL(url); } catch (error) {}
        });
        objectUrls = [];
        if (preview) preview.innerHTML = '';
    }

    function resetFiles(message = '') {
        selectedFiles = [];
        input.value = '';
        fileName.textContent = 'No file chosen';
        clearPreview();
        showError(message);
    }

    function syncInputFiles() {
        const transfer = createTransfer();
        if (!transfer) return;
        selectedFiles.forEach(function (file) {
            transfer.items.add(file);
        });
        input.files = transfer.files;
    }

    function updateSummary() {
        if (!selectedFiles.length) {
            fileName.textContent = 'No file chosen';
        } else if (selectedFiles.length === 1) {
            fileName.textContent = '1 file selected';
        } else {
            fileName.textContent = selectedFiles.length + ' files selected';
        }
    }

    function getFileKind(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if ((file.type || '').startsWith('image/') || ['jpg', 'jpeg', 'png'].includes(ext)) return 'image';
        if (ext === 'pdf') return 'PDF';
        if (['doc', 'docx'].includes(ext)) return 'DOC';
        return 'FILE';
    }

    function openSelectedFilePreview(index) {
        if (typeof openInlineAttachmentPreview !== 'function') return;
        const galleryItems = selectedFiles.map(function (file) {
            let url = '';
            try {
                url = URL.createObjectURL(file);
            } catch (error) {}
            return { file: file, url: url };
        }).filter(function (item) {
            return !!item.url;
        });
        if (!galleryItems[index]) return;
        openInlineAttachmentPreview(galleryItems[index].file, galleryItems[index].url, true, galleryItems, index);
    }

    function removeFileAt(index) {
        selectedFiles = selectedFiles.filter(function (_, fileIndex) {
            return fileIndex !== index;
        });
        syncInputFiles();
        renderFiles(selectedFiles);
    }

    function renderFiles(files) {
        showError('');
        selectedFiles = Array.from(files || []);
        syncInputFiles();
        updateSummary();
        clearPreview();

        if (!selectedFiles.length) {
            return;
        }

        if (preview) {
            selectedFiles.forEach(function (file, index) {
                const url = URL.createObjectURL(file);
                objectUrls.push(url);

                const row = document.createElement('div');
                row.className = 'attachment-preview-item';
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.justifyContent = 'space-between';
                row.style.gap = '12px';
                row.style.padding = '10px 12px';
                row.style.border = '1px solid #e5e7eb';
                row.style.borderRadius = '10px';
                row.style.background = '#f8fafc';
                row.style.marginBottom = '10px';

                const left = document.createElement('button');
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
                    openSelectedFilePreview(index);
                });

                const icon = document.createElement('div');
                icon.style.width = '36px';
                icon.style.height = '36px';
                icon.style.borderRadius = '10px';
                icon.style.display = 'flex';
                icon.style.alignItems = 'center';
                icon.style.justifyContent = 'center';
                icon.style.background = 'transparent';
                icon.style.color = '#16a34a';
                icon.style.fontWeight = '900';
                icon.style.fontSize = '11px';
                icon.style.flex = '0 0 36px';

                if (getFileKind(file) === 'image') {
                    const img = document.createElement('img');
                    img.src = url;
                    img.alt = '';
                    img.style.width = '28px';
                    img.style.height = '28px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    icon.style.background = '#ffffff';
                    icon.appendChild(img);
                } else {
                    icon.textContent = getFileKind(file);
                }

                const meta = document.createElement('div');
                meta.style.display = 'flex';
                meta.style.flexDirection = 'column';
                meta.style.minWidth = '0';

                const name = document.createElement('div');
                name.textContent = file.name;
                name.style.fontWeight = '700';
                name.style.color = '#0f172a';
                name.style.fontSize = '13px';
                name.style.overflow = 'hidden';
                name.style.textOverflow = 'ellipsis';
                name.style.whiteSpace = 'nowrap';

                meta.appendChild(name);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = 'x';
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
                removeBtn.style.flex = '0 0 40px';
                removeBtn.addEventListener('click', function () {
                    removeFileAt(index);
                });

                left.appendChild(icon);
                left.appendChild(meta);
                row.appendChild(left);
                row.appendChild(removeBtn);
                preview.appendChild(row);
            });
        }
    }

    function validateFiles(files) {
        if (!files.length) return '';
        if (files.length > maxFiles) {
            return 'You can upload a maximum of 5 files only.';
        }
        const totalSize = files.reduce((sum, file) => sum + file.size, 0);
        if (totalSize > maxTotalSize) {
            return 'Attachment too large. Maximum total size is 5 MB.';
        }
        for (const file of files) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!allowedExt.includes(ext)) {
                return 'Please insert supported files only.';
            }
        }
        return '';
    }

    function sameFile(a, b) {
        return !!(a && b)
            && a.name === b.name
            && a.size === b.size
            && a.lastModified === b.lastModified;
    }

    function mergeSelectedFiles(filesLike) {
        const incomingFiles = Array.from(filesLike || []);
        if (!incomingFiles.length) return;
        const mergedFiles = selectedFiles.slice();
        let skippedDuplicates = 0;
        let skippedLimit = 0;
        incomingFiles.forEach(function (file) {
            const isDuplicate = mergedFiles.some(function (existingFile) {
                return sameFile(existingFile, file);
            });
            if (isDuplicate) {
                skippedDuplicates += 1;
                return;
            }
            if (mergedFiles.length >= maxFiles) {
                skippedLimit += 1;
                return;
            }
            mergedFiles.push(file);
        });

        const error = validateFiles(mergedFiles);
        if (error) {
            showError(error);
            return;
        }
        renderFiles(mergedFiles);
        if (skippedLimit > 0) {
            showError('Maximum 5 attachments allowed. Extra files were not added.');
        } else if (skippedDuplicates > 0) {
            showError('');
        }
    }

    window.TMSalesResetAttachments = function () {
        resetFiles();
    };

    input.addEventListener('change', function () {
        console.log('[MAIN ATTACHMENT] change fired');

        const files = Array.from(input.files || []);
        console.log('[MAIN ATTACHMENT] selected files:', files.map(file => file.name));

        if (!files.length) {
            resetFiles();
            return;
        }

        mergeSelectedFiles(files);
    });

    if (chooseBtn) {
        chooseBtn.addEventListener('click', function (event) {
            event.preventDefault();
            input.click();
        });
    }

    if (attachmentShell) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
            attachmentShell.addEventListener(eventName, function (event) {
                if (input.disabled) return;
                event.preventDefault();
                attachmentShell.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach(function (eventName) {
            attachmentShell.addEventListener(eventName, function (event) {
                if (input.disabled) return;
                event.preventDefault();
                attachmentShell.classList.remove('is-dragover');
            });
        });
        attachmentShell.addEventListener('drop', function (event) {
            if (input.disabled) return;
            const droppedFiles = event.dataTransfer ? event.dataTransfer.files : null;
            if (!droppedFiles || !droppedFiles.length) return;
            mergeSelectedFiles(droppedFiles);
        });
    }
});
</script>

<script>
(function () {
    var search = document.getElementById('requestGuidanceSearch');
    var directory = document.querySelector('.request-guidance-directory');
    if (!directory) return;

    var companyGuides = Array.prototype.slice.call(directory.querySelectorAll('.request-company-guide'));
    var isFreshMobileVisit = window.matchMedia('(max-width: 768px)').matches
        && directory.dataset.hasSelectedCompany !== 'true';

    if (isFreshMobileVisit) {
        companyGuides.forEach(function (guide) {
            guide.open = false;
        });
    }
    directory.classList.remove('is-mobile-initializing');

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
            if (query && !guide.hidden) {
                guide.open = true;
            } else if (!query) {
                guide.open = guide.dataset.initiallyOpen === 'true';
                departments.forEach(function (department) {
                    department.style.display = '';
                });
            }
        });
    });
})();
</script>

</body>
</html>

