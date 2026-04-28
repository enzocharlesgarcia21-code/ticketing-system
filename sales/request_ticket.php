<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

$success_msg = "";
$error_msg = "";

$email = '';
$company_id = '';
$category = '';
$description = '';
$request_subject_title = '';
$hr_concern_type = '';
$priority_selected = '';
$assigned_department_selected = '';
$lapcDepartments = ticket_lapc_departments();
$mhcDepartments = ticket_mhc_departments();
$defaultCategories = ['Hardware', 'Software', 'Documentation', 'Email', 'Internet Concerns', 'Procurement', 'Technical Support'];
$mpdcCategories = ['Engineerings', 'Client Based'];
$lapcDepartmentCategories = [
    'Admin & Legal' => [
        'Phone Plan / Simcard',
        'FleetCard Request',
        'Supplies',
    ],
    'Institutional Sales (Bidding)' => [
        'Documentation',
        'Email',
        'Hardware',
        'Internet Concerns',
        'Procurement',
        'Software',
        'Technical Support',
    ],
    'HR' => [
        'Attendance & Timekeeping',
        'Certificate of Employment',
        'Certificate of Leave',
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
        'Technical Support',
    ],
    'Machineries' => [
        'Documentation',
        'Email',
        'Hardware',
        'Internet Concerns',
        'Procurement',
        'Software',
        'Technical Support',
    ],
];
$mhcDepartmentCategories = [
    'Marketing Creatives' => [
        'Marketing Request',
    ],
];

$requestTicketCompanyOptions = [
    '@leadstech-corp.com' => 'LTC',
    '@gpsci.net' => 'GPSCI',
    '@leadsagri.com' => 'LAPC',
    '@leads-farmex.com' => 'FARMEX',
    '@malvedaholdings.com' => 'MHC',
    '@malvedaproperties.com' => 'MPDC',
];
$requestTicketCompanies = array_keys($requestTicketCompanyOptions);

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
        'immediate_head' => '',
        'department' => '',
        'company' => '',
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
                'immediate_head' => trim((string) ($report['immediate_head'] ?? '')),
                'department' => trim((string) ($report['department'] ?? '')),
                'company' => trim((string) ($report['company'] ?? '')),
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
            'immediate_head' => trim((string) ($source['sap_immediate_head'] ?? '')),
            'department' => trim((string) ($source['sap_department'] ?? '')),
            'company' => trim((string) ($source['sap_company'] ?? '')),
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
    array $allowedMimes
): array {
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

    foreach ($names as $index => $originalName) {
        $errorCode = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Each ' . $label . ' file must be 10 MB or smaller.'];
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Unable to upload the ' . $label . ' file right now.'];
        }

        $fileName = trim((string) $originalName);
        $fileTmp = trim((string) ($tmpNames[$index] ?? ''));
        $fileSize = (int) ($sizes[$index] ?? 0);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileName === '' || !in_array($fileExt, $allowedTypes, true)) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Please upload only JPG, PNG, PDF, DOC, or DOCX files for ' . $label . '.'];
        }

        if ($fileSize <= 0 || $fileSize > $maxFileBytes) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Each ' . $label . ' file must be 10 MB or smaller.'];
        }

        if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
            $mime = (string) $finfo->file($fileTmp);
            $allowed = $allowedMimes[$fileExt] ?? [];
            if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                sales_request_cleanup_uploaded_files($uploadedFiles);
                return ['ok' => false, 'error' => 'Please upload only JPG, PNG, PDF, DOC, or DOCX files for ' . $label . '.'];
            }
        }

        $newFileName = time() . '_' . uniqid('', true) . '.' . $fileExt;
        $uploadPath = $uploadDir . '/' . $newFileName;

        if (!move_uploaded_file($fileTmp, $uploadPath)) {
            sales_request_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Unable to save the ' . $label . ' file right now.'];
        }

        $uploadedFiles[] = [
            'stored_name' => $newFileName,
            'original_name' => $label . ' - ' . $fileName,
            'stored_path' => $uploadPath,
        ];
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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_validate();
    ticket_ensure_assignment_columns($conn);

    $email      = trim((string)($_POST['email'] ?? ''));
    $company_id = trim((string)($_POST['company_id'] ?? ''));
    $assigned_department_selected = trim((string)($_POST['assigned_department'] ?? ''));
    $allowed_categories = $defaultCategories;
    $category   = trim((string)($_POST['category'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $email_request_type = trim((string) ($_POST['email_request_type'] ?? ''));
    $email_creation_name = trim((string) ($_POST['email_creation_name'] ?? ''));
    $email_creation_department = trim((string) ($_POST['email_creation_department'] ?? ''));
    $email_creation_designation = trim((string) ($_POST['email_creation_designation'] ?? ''));
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
    $project_name = trim((string) ($_POST['project_name'] ?? ''));
    $area_code = trim((string) ($_POST['area_code'] ?? ''));
    $marketing_department = trim((string) ($_POST['marketing_department'] ?? ''));
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
    $sap_immediate_head = $sap_reports[0]['immediate_head'] ?? trim((string) ($_POST['sap_immediate_head'] ?? ''));
    $sap_department = $sap_reports[0]['department'] ?? trim((string) ($_POST['sap_department'] ?? ''));
    $sap_company = $sap_reports[0]['company'] ?? trim((string) ($_POST['sap_company'] ?? ''));

    $name = derive_name_from_email($email);
    $company = $company_id;
    $department = 'Sales';
    $priority = 'Low';
    $subject = $category !== '' ? ($category . ' Concern') : 'Sales Ticket';
    $normalized_company_id = normalize_sales_recipient_company($company_id);
    $allowed_categories = ($normalized_company_id === '@malvedaproperties.com')
        ? $mpdcCategories
        : (($normalized_company_id === '@malvedaholdings.com' && isset($mhcDepartmentCategories[$assigned_department_selected]))
            ? $mhcDepartmentCategories[$assigned_department_selected]
        : (($normalized_company_id === '@leadsagri.com' && isset($lapcDepartmentCategories[$assigned_department_selected]))
            ? $lapcDepartmentCategories[$assigned_department_selected]
            : $defaultCategories));
    $isLapcRecipient = ($normalized_company_id === '@leadsagri.com');
    $isMhcRecipient = ($normalized_company_id === '@malvedaholdings.com');
    $requiresDepartment = $isLapcRecipient || $isMhcRecipient;
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
    $isLapcItEmailRequest = ($isLapcItRecipient && $category === 'Email');
    $isLapcItSapRequest = ($isLapcItRecipient && $category === 'SAP');
    $requiresKamiAttachment = $isHrAttendanceCategory;
    if ($requiresDepartment) {
        $assigned_user_ids = ticket_find_assignee_ids($conn, $assigned_company, $assigned_group);
    } else {
        $assigned_user_ids = find_sales_domain_recipient_ids($conn, $assigned_company);
    }
    $assigned_user_id = count($assigned_user_ids) > 0 ? (int) $assigned_user_ids[0] : null;
    $allowedDepartments = $isMhcRecipient ? ticket_mhc_departments() : ticket_lapc_departments();
    if ($requiresDepartment && $assigned_department === '') {
        $error_msg = "Please select a department.";
    } elseif ($requiresDepartment && !in_array($assigned_department, $allowedDepartments, true)) {
        $error_msg = "Invalid department selected.";
    }
    if ($error_msg === '') {
        if ($assigned_company === '' || !ticket_is_valid_company($assigned_company)) {
            $error_msg = "Ticket Recipient (Company Email Domain) is required.";
        } elseif ($requiresDepartment && ($assigned_group === '' || !ticket_is_valid_group_for_company($assigned_company, $assigned_group))) {
            $error_msg = "Invalid department selected for the chosen recipient.";
        } elseif (!$assigned_user_id) {
            $error_msg = $requiresDepartment
                ? "No assignee available for the selected recipient and department."
                : "No assignee available for the selected recipient.";
        }
    }
    if ($error_msg === '' && $isLapcHrRecipient) {
        if ($priority_selected === '') {
            $error_msg = "Please choose the level of urgency.";
        } elseif (!in_array($priority_selected, ['Low', 'Medium', 'High'], true)) {
            $error_msg = "Invalid level of urgency selected.";
        } else {
            $priority = $priority_selected;
        }
    }
    if ($error_msg === '' && $isMhcMarketingRecipient) {
        if ($priority_selected === '') {
            $error_msg = "Please choose the urgency level.";
        } elseif (!in_array($priority_selected, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'], true)) {
            $error_msg = "Invalid urgency level selected.";
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
        } elseif (
            $email_request_type === 'creation of email'
            && ($email_creation_name === '' || $email_creation_department === '' || $email_creation_designation === '')
        ) {
            $error_msg = "Please complete the Creation of email details.";
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
    if ($error_msg === '' && $isLapcItSapRequest) {
        if (count($sap_reports) === 0) {
            $error_msg = "Please complete the SAP form.";
        } else {
            $sap_name = $sap_reports[0]['name'] ?? $sap_name;
            $sap_position = $sap_reports[0]['position'] ?? $sap_position;
            $sap_immediate_head = $sap_reports[0]['immediate_head'] ?? $sap_immediate_head;
            $sap_department = $sap_reports[0]['department'] ?? $sap_department;
            $sap_company = $sap_reports[0]['company'] ?? $sap_company;
            foreach ($sap_reports as $sap_report) {
                $sapCompanyRequiresDepartment = ($sap_report['company'] === '@leadsagri.com');
                if (
                    $sap_report['name'] === ''
                    || $sap_report['position'] === ''
                    || $sap_report['immediate_head'] === ''
                    || $sap_report['company'] === ''
                    || ($sapCompanyRequiresDepartment && $sap_report['department'] === '')
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
                    . "Full Name: " . $sap_report['name'] . "\n"
                    . "Position: " . $sap_report['position'] . "\n"
                    . "Immediate Supervisor: " . $sap_report['immediate_head'] . "\n"
                    . "Department: " . $sap_report['department'] . "\n"
                    . "Company: " . $sap_report['company'];
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
    if ($error_msg === '' && $isLapcItEmailRequest && $email_request_type === 'creation of email') {
        $subject = 'Creation of email';
        $description = "Email Request\n"
            . "Email Request Type: Creation of email\n"
            . "Name: " . $email_creation_name . "\n"
            . "Department: " . $email_creation_department . "\n"
            . "Designation: " . $email_creation_designation;
    }
    if ($error_msg === '' && ($requiresKamiAttachment || $isHrMedicalCashAdvance)) {
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
            $error_msg = $isHrMedicalCashAdvance
                ? "Supporting Information is required for Medical Cash Advance."
                : "Attachment is required for Attendance & Timekeeping.";
        }
    }

    $attachmentName = null;
    $uploadedFiles = [];

    /* ================= FILE UPLOAD ================= */

    if ($error_msg === '' && isset($_FILES['attachments']) && isset($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
        $maxBytes = 5 * 1024 * 1024;
        $maxFiles = 5;
        $selectedFiles = 0;
        $totalBytes = 0;
        $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
        $allowedMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/vnd.ms-word', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ];
        $finfo = null;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
        }
        $movedPaths = [];
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $selectedFiles++;
        }
        if ($selectedFiles > $maxFiles) {
            $error_msg = "Maximum 5 attachments allowed.";
        }
        for ($i = 0; $i < $count; $i++) {
            if ($error_msg !== '') break;
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $error_msg = "Attachment too large. Max 5MB per file.";
                break;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $error_msg = "Attachment upload failed. Please try again.";
                break;
            }

            $origName = (string)($_FILES['attachments']['name'][$i] ?? '');
            $fileTmp = (string)($_FILES['attachments']['tmp_name'][$i] ?? '');
            $fileSize = (int)($_FILES['attachments']['size'][$i] ?? 0);
            $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedTypes, true)) {
                $error_msg = "Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.";
                break;
            }
            if ($fileSize <= 0 || $fileSize > $maxBytes) {
                $error_msg = "Attachment too large. Max 5MB total.";
                break;
            }
            if (($totalBytes + $fileSize) > $maxBytes) {
                $error_msg = "Attachment too large. Max 5MB total.";
                break;
            }
            if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
                $mime = (string) $finfo->file($fileTmp);
                $allowed = $allowedMimes[$fileExt] ?? [];
                if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                    $error_msg = "Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.";
                    break;
                }
            }

            if (!is_dir("../uploads")) {
                mkdir("../uploads", 0777, true);
            }
            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath = "../uploads/" . $newFileName;
            if (!move_uploaded_file($fileTmp, $uploadPath)) {
                $error_msg = "Failed to save attachment. Please try again.";
                break;
            }
            $movedPaths[] = $uploadPath;
            $totalBytes += $fileSize;
            $uploadedFiles[] = ['stored_name' => $newFileName, 'original_name' => $origName];
        }

        if ($error_msg !== '') {
            foreach ($movedPaths as $p) {
                if (is_string($p) && $p !== '' && file_exists($p)) {
                    unlink($p);
                }
            }
            $uploadedFiles = [];
        }
    }

    if (count($uploadedFiles) > 0) {
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

    /* ================= GET/CREATE SALES GUEST USER ================= */

    $sales_email = 'sales_guest@leadsagri.com';
    $user_id = null;
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
        if ($error_msg === '') $error_msg = "System error. Please try again later.";
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

    /* ================= BASIC VALIDATION ================= */
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_msg = "A valid email is required.";
    } elseif ($company_id === '' || !in_array($company_id, $requestTicketCompanies, true)) {
        $error_msg = "Ticket Recipient (Company Email Domain) is required.";
    } elseif ($category === '' || !in_array($category, $allowed_categories, true)) {
        $error_msg = "Category is required.";
    } elseif ($description === '' && !($isLapcItEmailRequest && $email_request_type === 'creation of email')) {
        $error_msg = "Description is required.";
    }

    /* ================= PREPARE DESCRIPTION ================= */
    
    $raw_description = $description;
    $full_description = "REQUESTER NAME: $name\nREQUESTER EMAIL: $email\n\nDESCRIPTION:\n$description";

    /* ================= INSERT INTO DATABASE ================= */

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

                sales_request_meta_ensure_table($conn);
                $ticketMeta = [];
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
                    $ticketMeta['sap_immediate_head'] = $sap_immediate_head;
                    $ticketMeta['sap_department'] = $sap_department;
                    $ticketMeta['sap_company'] = $sap_company;
                    $ticketMeta['sap_reports'] = json_encode($sap_reports, JSON_UNESCAPED_UNICODE);
                }
                if ($isLapcItEmailRequest) {
                    $ticketMeta['email_request_type'] = $email_request_type;
                    if ($email_request_type === 'creation of email') {
                        $ticketMeta['email_creation_name'] = $email_creation_name;
                        $ticketMeta['email_creation_department'] = $email_creation_department;
                        $ticketMeta['email_creation_designation'] = $email_creation_designation;
                    }
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
                    'ticket_number' => (string) $ticket_number
                ]);

                $usesSpecificEmailRoute = ticket_uses_specific_email_route($assigned_company, (string) $assigned_group);
                $adminEmails = [];
                if (!$usesSpecificEmailRoute && count($adminEmails) === 0) {
                    $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
                    if ($admins) {
                        while ($admin = $admins->fetch_assoc()) {
                            $adminEmails[] = $admin['email'];
                        }
                    }
                }

                $ticketNumber = str_pad((string) $ticket_id, 6, '0', STR_PAD_LEFT);
                $subjectLine = "New Sales Ticket (#$ticketNumber)";
                $assignedRecipientLabel = ticket_company_display_name((string) $assigned_company);
                if ($assignedRecipientLabel === '') {
                    $assignedRecipientLabel = (string) $assigned_company;
                }

                $attachments = notif_ticket_email_attachments($conn, $ticket_id, (string) ($attachmentName ?? ''));
                $attachmentSummary = notif_ticket_attachment_summary($attachments);

                $adminTpl = notif_email_simple('New Sales Ticket', [
                    "Ticket ID: #$ticketNumber",
                    "Title: $subject",
                    "Category: $category",
                    "Status: $ticketStatus",
                    "Requester: $email",
                    "Assigned Recipient: $assignedRecipientLabel"
                ], 'Open Ticket', notif_ticket_link_admin($ticket_id));
                if ($requiresDepartment) {
                    $adminTpl = notif_email_simple('New Sales Ticket', [
                        "Ticket ID: #$ticketNumber",
                        "Title: $subject",
                        "Category: $category",
                        "Status: $ticketStatus",
                        "Requester: $email",
                        "Assigned Department: $assigned_department",
                        "Assigned Recipient: $assignedRecipientLabel"
                    ], 'Open Ticket', notif_ticket_link_admin($ticket_id));
                }
                if (count($adminEmails) > 0) {
                    notif_email_send($adminEmails, $subjectLine, (string) $adminTpl['html'], (string) $adminTpl['text'], $attachments);
                }

                $assigneeEmails = ticket_assignee_notification_emails($conn, $assigned_user_ids, $assigned_company, (string) $assigned_group);
                if (count($assigneeEmails) > 0) {
                    $assigneeLines = [
                        "Ticket ID: #$ticketNumber",
                        "Category: $category",
                        "Status: $ticketStatus",
                        "Requester: $email",
                        "Assigned Recipient: $assignedRecipientLabel",
                        "Description:\n$raw_description"
                    ];
                    if ($requiresDepartment) {
                        array_splice($assigneeLines, 4, 0, ["Assigned Department: $assigned_department"]);
                    }
                    if ($attachmentSummary !== '') {
                        $assigneeLines[] = $attachmentSummary;
                    }
                    $assigneeTpl = notif_email_simple('Ticket Assigned', $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks($ticket_id));
                    notif_email_send($assigneeEmails, "New Ticket Assigned (#$ticketNumber)", (string) $assigneeTpl['html'], (string) $assigneeTpl['text'], $attachments);
                }

                $requesterLines = [
                    "Ticket ID: #$ticketNumber",
                    "Category: $category",
                    "Status: $ticketStatus",
                    "Assigned Recipient: $assignedRecipientLabel",
                    "Description:\n$raw_description"
                ];
                if ($requiresDepartment) {
                    array_splice($requesterLines, 3, 0, ["Assigned Department: $assigned_department"]);
                }
                if ($attachmentSummary !== '') {
                    $requesterLines[] = $attachmentSummary;
                }
                $requesterTpl = notif_email_simple('Ticket Submitted', $requesterLines, 'Go To Helpdesk', notif_base_url() . '/ticketing/index.php');
                notif_email_send([$email], "Ticket Submitted (#$ticketNumber)", (string) $requesterTpl['html'], (string) $requesterTpl['text'], $attachments);

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
$normalized_company_id = normalize_sales_recipient_company((string) $company_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Ticket Request | Leads Agri Helpdesk</title>
    <!-- Reuse existing CSS or inline minimal styles -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/employee-dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f3f4f6 url('../assets/img/leadss.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            margin: 0;
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
        @media (max-width: 768px) {
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

            .header { margin-bottom: 20px; }
            .header h1 { font-size: 20px; margin-bottom: 6px; }
            .header p { font-size: 14px; }

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
        body.sales-request-ticket-page .request-grid-row.is-single {
            grid-template-columns: 1fr;
        }
        body.sales-request-ticket-page .request-grid-row > .full-width {
            grid-column: 1 / -1;
        }
        body.sales-request-ticket-page .select-wrapper {
            position: relative;
        }
        body.sales-request-ticket-page .select-wrapper.recipient-dropdown,
        body.sales-request-ticket-page .select-wrapper.department-dropdown,
        body.sales-request-ticket-page .select-wrapper.category-dropdown,
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
        body.sales-request-ticket-page .category-native-select,
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
        body.sales-request-ticket-page .priority-dropdown-trigger:not(.is-placeholder) {
            font-weight: 400;
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger:focus,
        body.sales-request-ticket-page .department-dropdown-trigger:focus,
        body.sales-request-ticket-page .priority-dropdown-trigger:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .department-dropdown-trigger.is-placeholder,
        body.sales-request-ticket-page .priority-dropdown-trigger.is-placeholder {
            color: #334155;
        }
        body.sales-request-ticket-page .recipient-dropdown-trigger:disabled,
        body.sales-request-ticket-page .department-dropdown-trigger:disabled,
        body.sales-request-ticket-page .priority-dropdown-trigger:disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }
        body.sales-request-ticket-page .category-dropdown-trigger {
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
        body.sales-request-ticket-page .category-dropdown-trigger:not(.is-placeholder) {
            font-weight: 400;
        }
        body.sales-request-ticket-page .category-dropdown-trigger:focus {
            outline: none;
            border-color: #1B5E20;
            box-shadow: 0 0 0 4px rgba(27, 94, 32, 0.12);
        }
        body.sales-request-ticket-page .category-dropdown-trigger.is-placeholder {
            color: #334155;
        }
        body.sales-request-ticket-page .category-dropdown-trigger:disabled {
            background: #f8fafc;
            color: #94a3b8;
            cursor: not-allowed;
        }
        body.sales-request-ticket-page .recipient-dropdown-menu,
        body.sales-request-ticket-page .department-dropdown-menu,
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
        body.sales-request-ticket-page .priority-dropdown-menu.is-open {
            display: block;
        }
        body.sales-request-ticket-page .category-dropdown-menu {
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
        body.sales-request-ticket-page .category-dropdown-menu.is-open {
            display: block;
        }
        body.sales-request-ticket-page .recipient-dropdown-option,
        body.sales-request-ticket-page .department-dropdown-option,
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
        body.sales-request-ticket-page .priority-dropdown-option:hover,
        body.sales-request-ticket-page .priority-dropdown-option:focus {
            outline: none;
            background: #eef7ef;
        }
        body.sales-request-ticket-page .recipient-dropdown-option.is-selected,
        body.sales-request-ticket-page .department-dropdown-option.is-selected,
        body.sales-request-ticket-page .priority-dropdown-option.is-selected {
            background: #1B5E20;
            color: #ffffff;
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
        .header {
            text-align: center;
            margin-bottom: 0;
            padding: 32px 16px 26px;
        }
        .header h1 {
            color: #1B5E20;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: 0;
            margin-bottom: 10px;
        }
        .header p {
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
            overflow: hidden;
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
            display: grid;
            gap: 22px;
            margin: 28px 38px 32px;
            padding: 34px 46px 36px;
            border: 1px solid #dbe4ef;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.04);
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
            font-weight: 800;
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
        body.sales-request-ticket-page .marketing-request-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
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
        body.sales-request-ticket-page .email-description-host {
            display: block;
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
        body.sales-request-ticket-page .email-creation-fields {
            display: none;
            gap: 18px;
            margin-top: 18px;
        }
        body.sales-request-ticket-page .email-creation-fields.is-visible {
            display: grid;
        }
        body.sales-request-ticket-page .email-creation-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 18px;
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
            font-weight: 600;
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
        }
        body.sales-request-ticket-page .sap-request-actions-group {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            width: auto;
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
        body.sales-request-ticket-page .col-request-card label {
            display: block;
            margin-bottom: 10px;
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
            body.sales-request-ticket-page .col-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .marketing-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .sap-request-inline-row,
            body.sales-request-ticket-page .sap-request-company-row {
                grid-template-columns: 1fr;
            }
            body.sales-request-ticket-page .sap-request-card-top {
                align-items: flex-start;
                flex-direction: column;
            }
            body.sales-request-ticket-page .sap-request-panel-head {
                flex-direction: column;
                align-items: stretch;
            }
            body.sales-request-ticket-page .sap-request-switcher {
                min-width: 0;
                width: 100%;
            }
            body.sales-request-ticket-page .sap-request-add-btn {
                width: 100%;
            }
            body.sales-request-ticket-page .sap-request-actions {
                padding: 16px 0 16px;
            }
            body.sales-request-ticket-page .email-request-list {
                margin: 20px 18px 24px;
                padding: 24px 20px 26px;
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
            font-size: 17px;
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
        .file-name {
            color: #475569;
            font-size: 14px;
            text-align: left;
            word-break: break-word;
            flex: 1 1 180px;
            min-width: 0;
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
            margin-top: 2px;
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
            margin: 0 auto 24px;
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
            padding-top: 0;
            border-top: none;
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
        @keyframes ticket-loading-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            body.sales-request-ticket-page .request-grid-row {
                grid-template-columns: 1fr;
                gap: 0;
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
            .header {
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

            .header {
                margin-bottom: 16px;
            }

            .header h1 {
                margin-bottom: 6px;
            }

            .form-group {
                margin-bottom: 0;
            }

            label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            input, select, textarea {
                padding: 10px 12px;
            }

            form {
                display: block;
            }

            textarea[name="description"] {
                height: 120px;
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
    </style>
</head>
<body class="sales-request-ticket-page">

<header class="sales-topbar">
    <div class="sales-topbar-inner">
        <div class="sales-brand-block">
            <div class="sales-logo">
                <img src="../assets/img/UPDATEDlogo.png?v=2" alt="Leads Agri Logo">
            </div>
            <div class="sales-brand-divider" aria-hidden="true"></div>
            <div class="sales-brand">
                <div class="sales-brand-title">Leads Agri Helpdesk</div>
                <div class="sales-brand-subtitle">Sales Ticket Request</div>
            </div>
        </div>
        <div class="sales-nav-right">
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
</header>

<div class="sales-container">
    <div class="header">
        <h1>Create a Ticket </h1>
        <p>Please fill out the form below.</p>
    </div>

        <?php if($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>

        <?php if($error_msg): ?>
            <div class="alert alert-error" id="pageError"><?= htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="form-card">
        <form id="ticketForm" method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <div class="alert alert-error" id="ajaxError" style="display:none;"></div>
            <h3 class="form-section-title">Request Information</h3>

            <div class="form-grid">
            <div class="form-group">
                <label>Your Email <span class="required-asterisk">*</span></label>
                <input type="email" name="email" class="form-control" placeholder="Enter your email address" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="request-grid-row is-single" id="recipientRow">
                <div class="form-group" id="recipientGroup">
                    <label>Assign to <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper recipient-dropdown" id="recipientDropdown">
                        <select name="company_id" id="ticket_recipient" class="form-control recipient-native-select" required>
                            <option value="" disabled <?= $normalized_company_id === '' ? 'selected' : '' ?> hidden>Select Recipient</option>
                            <?php foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel): ?>
                                <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= (normalize_sales_recipient_company((string) ($company_id ?? '')) === $companyValue) ? 'selected' : '' ?>><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="recipientDropdownTrigger" class="recipient-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false">Select Recipient</button>
                        <div id="recipientDropdownMenu" class="recipient-dropdown-menu" role="listbox" aria-labelledby="recipientDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>

                <div class="form-group hidden" id="departmentGroup">
                    <label>Assigned Department <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper department-dropdown" id="departmentDropdown">
                        <select name="assigned_department" id="department" class="form-control department-native-select" required disabled data-selected="<?= htmlspecialchars((string) $assigned_department_selected, ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled <?= $assigned_department_selected === '' ? 'selected' : '' ?> hidden>Choose department</option>
                            <?php foreach (($normalized_company_id === '@malvedaholdings.com' ? $mhcDepartments : $lapcDepartments) as $d): ?>
                                <option value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?>" <?= $assigned_department_selected === $d ? 'selected' : '' ?>><?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="departmentDropdownTrigger" class="department-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false" disabled>Choose department</button>
                        <div id="departmentDropdownMenu" class="department-dropdown-menu" role="listbox" aria-labelledby="departmentDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>
            </div>

            <div class="request-grid-row is-single" id="salesCategoryRow">
                <div class="form-group" id="categoryContainer">
                    <label>Category <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper category-dropdown" id="categoryDropdown">
                        <select name="category" id="sales_category" class="form-control category-select category-native-select" required data-selected="<?= htmlspecialchars((string) ($category ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled hidden <?= ($category ?? '') === '' ? 'selected' : '' ?>>Choose category</option>
                            <?php foreach (($normalized_company_id === '@malvedaproperties.com' ? $mpdcCategories : $defaultCategories) as $categoryOption): ?>
                                <option value="<?= htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>" <?= ($category ?? '') === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="categoryDropdownTrigger" class="category-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false">Choose category</button>
                        <div id="categoryDropdownMenu" class="category-dropdown-menu" role="listbox" aria-labelledby="categoryDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>

                <div class="form-group hidden" id="priorityGroup">
                    <label>Level of Urgency <span class="required-asterisk">*</span></label>
                    <div class="select-wrapper priority-dropdown" id="priorityDropdown">
                        <select name="priority" id="sales_priority" class="form-control category-select priority-native-select" disabled data-selected="<?= htmlspecialchars((string) ($priority_selected ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <option value="" disabled hidden <?= ($priority_selected ?? '') === '' ? 'selected' : '' ?>>Choose level of urgency</option>
                            <option value="Low" <?= ($priority_selected ?? '') === 'Low' ? 'selected' : '' ?>>Low - General Inquiry</option>
                            <option value="Medium" <?= ($priority_selected ?? '') === 'Medium' ? 'selected' : '' ?>>Medium - Needs action within a few days</option>
                            <option value="High" <?= ($priority_selected ?? '') === 'High' ? 'selected' : '' ?>>High - Time-sensitive or urgent</option>
                        </select>
                        <button type="button" id="priorityDropdownTrigger" class="priority-dropdown-trigger is-placeholder" aria-haspopup="listbox" aria-expanded="false" disabled>Choose level of urgency</button>
                        <div id="priorityDropdownMenu" class="priority-dropdown-menu" role="listbox" aria-labelledby="priorityDropdownTrigger"></div>
                        <i class="fas fa-chevron-down select-icon"></i>
                    </div>
                </div>
            </div>

            <section class="kami-group" id="kamiBannerContainer">
                <h3 class="kami-banner-head">Attendance and Timekeeping (KAMI)</h3>
                <div class="kami-list">
                    <div class="form-group hr-extra-group" id="concernTypeContainer">
                        <label>Type of Concern <span class="required-asterisk">*</span></label>
                        <div class="select-wrapper">
                            <select name="hr_concern_type" id="hr_concern_type" class="form-control category-select" data-selected="<?= htmlspecialchars((string) ($hr_concern_type ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <option value="" disabled hidden <?= ($hr_concern_type ?? '') === '' ? 'selected' : '' ?>>Choose type of concern</option>
                                <option value="KAMI Error: Check IN/OUT" <?= ($hr_concern_type ?? '') === 'KAMI Error: Check IN/OUT' ? 'selected' : '' ?>>KAMI Error: Check IN/OUT</option>
                                <option value="KAMI Error: Failed log in attempts" <?= ($hr_concern_type ?? '') === 'KAMI Error: Failed log in attempts' ? 'selected' : '' ?>>KAMI Error: Failed log in attempts</option>
                                <option value="Unpaid salary" <?= ($hr_concern_type ?? '') === 'Unpaid salary' ? 'selected' : '' ?>>Unpaid salary</option>
                                <option value="Unpaid leave/overtime pay" <?= ($hr_concern_type ?? '') === 'Unpaid leave/overtime pay' ? 'selected' : '' ?>>Unpaid leave/overtime pay</option>
                                <option value="Other" <?= ($hr_concern_type ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
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
                            value="<?= htmlspecialchars((string) ($request_subject_title ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Enter subject/title of request"
                        >
                    </div>
                </div>
                <div class="other-request-continuation" id="otherRequestContinuationHost"></div>
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
                        <span class="company-property-card-title">Type of Company Property: <span class="required-asterisk">*</span></span>
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
                        <span class="company-property-card-title">Reason of Request: <span class="required-asterisk">*</span></span>
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
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][immediate_head]" value="<?= htmlspecialchars((string) ($savedSapEntry['immediate_head'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][company]" value="<?= htmlspecialchars((string) ($savedSapEntry['company'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="sap_reports[<?= $savedSapIndex; ?>][department]" value="<?= htmlspecialchars((string) ($savedSapEntry['department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
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
                                        <label for="sap_name_current">Full Name <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][name]" id="sap_name_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="name">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_position_current">Position <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][position]" id="sap_position_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="position">
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_immediate_head_current">Immediate Supervisor <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][immediate_head]" id="sap_immediate_head_current" class="form-control" value="<?= htmlspecialchars((string) ($visibleSapEntry['immediate_head'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="immediate_head">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_company_current">Company <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper">
                                            <select name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][company]" id="sap_company_current" class="form-control" data-sap-field="company">
                                                <option value="" disabled <?= (($visibleSapEntry['company'] ?? '') === '') ? 'selected' : ''; ?>>Select a company</option>
                                                <?php foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel): ?>
                                                    <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= (($visibleSapEntry['company'] ?? '') === $companyValue) ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <i class="fas fa-chevron-down select-icon"></i>
                                        </div>
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-company-row">
                                <section class="sap-request-field sap-request-department-wrap <?= (($visibleSapEntry['company'] ?? '') === '@leadsagri.com') ? 'is-visible' : ''; ?>" data-sap-department-wrap>
                                    <div class="form-group">
                                        <label for="sap_department_current">Department <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper sap-request-department-field <?= (($visibleSapEntry['company'] ?? '') === '@leadsagri.com') ? 'is-visible' : ''; ?>" data-sap-department-field>
                                            <select name="sap_reports[<?= max(0, count($sapFormEntries) - 1); ?>][department]" id="sap_department_current" class="form-control" data-sap-field="department" <?= (($visibleSapEntry['company'] ?? '') === '@leadsagri.com') ? '' : 'disabled'; ?>>
                                                <option value="" disabled <?= (($visibleSapEntry['department'] ?? '') === '') ? 'selected' : ''; ?>>Choose department</option>
                                                <?php foreach ($lapcDepartments as $sapDepartmentOption): ?>
                                                    <option value="<?= htmlspecialchars($sapDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($visibleSapEntry['department'] ?? '') === $sapDepartmentOption) ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars($sapDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <i class="fas fa-chevron-down select-icon"></i>
                                        </div>
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
                <h3 class="marketing-request-head">MHC Marketing Request</h3>
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
                                <div class="select-wrapper">
                                    <select name="area_code" id="area_code" class="form-control category-select" data-selected="<?= htmlspecialchars((string) ($_POST['area_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="" disabled selected hidden>Choose area code</option>
                                        <?php foreach (['811A', '811B', '812', '813A', '813B', '814A', '814B', '815A', '815B', '815C', '821A', '821B', '821C', '822A', '822B', '831A', '831B', '832A', '832B', '833', 'HEAD OFFICE'] as $areaCodeOption): ?>
                                            <option value="<?= htmlspecialchars($areaCodeOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['area_code'] ?? '') === $areaCodeOption) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($areaCodeOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-chevron-down select-icon"></i>
                                </div>
                            </div>
                        </section>
                        <section class="marketing-request-card">
                            <div class="form-group">
                                <label for="marketing_department">Department <span class="required-asterisk">*</span></label>
                                <div class="select-wrapper">
                                    <select name="marketing_department" id="marketing_department" class="form-control category-select" data-selected="<?= htmlspecialchars((string) ($_POST['marketing_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="" disabled selected hidden>Choose department</option>
                                        <?php foreach (['Marketing Ops', 'Sales', 'Technical', 'Human Resources', 'PCC/GPCI', 'Farmex', 'Farmasee', 'LTC', 'MPDC', 'IT', 'Admin', 'Leads AH/EH', 'Executive/Management'] as $marketingDepartmentOption): ?>
                                            <option value="<?= htmlspecialchars($marketingDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($_POST['marketing_department'] ?? '') === $marketingDepartmentOption) ? 'selected' : ''; ?>>
                                                <?= htmlspecialchars($marketingDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-chevron-down select-icon"></i>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section class="marketing-request-card">
                        <div class="form-group">
                            <label for="requested_materials">Requested Materials <span class="required-asterisk">*</span></label>
                            <?php $selectedRequestedMaterials = sales_request_clean_string_array($_POST['requested_materials'] ?? []); ?>
                            <div class="select-wrapper" id="requestedMaterialsGroup">
                                <select name="requested_materials[]" id="requested_materials" class="form-control">
                                    <option value="" disabled <?= count($selectedRequestedMaterials) === 0 ? 'selected' : ''; ?> hidden>Choose requested material</option>
                                    <?php foreach (['Social Media Graphics', 'Print Materials (Flyers, Brochures)', 'Video (Short-form)', 'Banners/Taffetas', 'Labels', 'Tarpaulin/Poster', 'Invitation', 'Coupons', 'Sintraboard design', 'Plotsigns', 'Promats Design (shirt, cap, etc)', 'Other'] as $materialOption): ?>
                                        <option value="<?= htmlspecialchars($materialOption, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($materialOption, $selectedRequestedMaterials, true) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($materialOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
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
                                <span class="marketing-request-card-title">Size of Material <span class="required-asterisk">*</span></span>
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
                                <small class="marketing-request-help">Select one size unit, then enter the measurement.</small>
                            </div>
                        </section>
                        <section class="marketing-request-card">
                            <div class="form-group">
                                <label for="project_deadline">Project Deadline <span class="required-asterisk">*</span></label>
                                <input type="date" name="project_deadline" id="project_deadline" class="form-control" value="<?= htmlspecialchars((string) ($_POST['project_deadline'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <small class="marketing-request-help" id="projectDeadlineHelp">Must be at least 3 working days from today.</small>
                                <div class="marketing-request-error" id="projectDeadlineError"></div>
                            </div>
                        </section>
                    </div>

                    <section class="marketing-request-card">
                        <div class="form-group">
                            <label for="crop">Crop <span class="required-asterisk">*</span></label>
                            <?php $selectedCrops = sales_request_clean_string_array($_POST['crop'] ?? []); ?>
                            <div class="select-wrapper" id="cropGroup">
                                <select name="crop[]" id="crop" class="form-control">
                                    <option value="" disabled <?= count($selectedCrops) === 0 ? 'selected' : ''; ?> hidden>Choose crop</option>
                                    <?php foreach (['Rice', 'Lowland Vegetable', 'Upland Vegetable', 'Sugarcane', 'Corn', 'Mango', 'Other'] as $cropOption): ?>
                                        <option value="<?= htmlspecialchars($cropOption, ENT_QUOTES, 'UTF-8'); ?>" <?= in_array($cropOption, $selectedCrops, true) ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($cropOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                            <div class="marketing-request-other-row" id="cropOtherRow">
                                <label for="crop_other">Other crop <span class="required-asterisk">*</span></label>
                                <input type="text" name="crop_other" id="crop_other" class="form-control" value="<?= htmlspecialchars((string) ($_POST['crop_other'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Please specify">
                            </div>
                        </div>
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
                            <div class="select-wrapper">
                                <select name="email_request_type" id="email_request_type" class="form-control" data-selected="<?= htmlspecialchars((string) ($_POST['email_request_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose email request type</option>
                                    <option value="creation of email" <?= (($_POST['email_request_type'] ?? '') === 'creation of email') ? 'selected' : ''; ?>>Creation of email</option>
                                    <option value="forgot password" <?= (($_POST['email_request_type'] ?? '') === 'forgot password') ? 'selected' : ''; ?>>Forgot password</option>
                                    <option value="backup of email" <?= (($_POST['email_request_type'] ?? '') === 'backup of email') ? 'selected' : ''; ?>>Backup of email</option>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </section>
                    <div class="email-creation-fields" id="emailCreationFields">
                        <div class="email-creation-inline-row">
                            <div class="form-group">
                                <label for="email_creation_name">Name <span class="required-asterisk">*</span></label>
                                <input type="text" name="email_creation_name" id="email_creation_name" class="form-control" value="<?= htmlspecialchars((string) ($_POST['email_creation_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                            </div>
                            <div class="form-group">
                                <label for="email_creation_department">Department <span class="required-asterisk">*</span></label>
                                <input type="text" name="email_creation_department" id="email_creation_department" class="form-control" value="<?= htmlspecialchars((string) ($_POST['email_creation_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="email_creation_designation">Designation <span class="required-asterisk">*</span></label>
                            <input type="text" name="email_creation_designation" id="email_creation_designation" class="form-control" value="<?= htmlspecialchars((string) ($_POST['email_creation_designation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                        </div>
                    </div>
                    <div class="email-description-host" id="emailDescriptionHost"></div>
                </div>
            </section>

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
                            <input type="file" name="attachments[]" id="attachments" class="file-hidden" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
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
var priorityGroup = document.getElementById('priorityGroup');
var prioritySelect = document.getElementById('sales_priority');
var priorityDropdown = document.getElementById('priorityDropdown');
var priorityTrigger = document.getElementById('priorityDropdownTrigger');
var priorityMenu = document.getElementById('priorityDropdownMenu');
var kamiBannerContainer = document.getElementById('kamiBannerContainer');
var concernTypeContainer = document.getElementById('concernTypeContainer');
var concernTypeSelect = document.getElementById('hr_concern_type');
var concernTypeOtherContainer = document.getElementById('concernTypeOtherContainer');
var concernTypeOtherInput = document.getElementById('hr_concern_type_other');
var leaveSubjectContainer = document.getElementById('leaveSubjectContainer');
var leaveSubjectInput = document.getElementById('request_subject_title');
var medicalCashAdvanceSection = document.getElementById('medicalCashAdvanceSection');
var medicalCashPurposeInput = document.getElementById('medical_cash_purpose');
var medicalCashAmountInput = document.getElementById('medical_cash_amount');
var medicalCashDateNeededInput = document.getElementById('medical_cash_date_needed');
var medicalCashAttachmentHost = document.getElementById('medicalCashAttachmentHost');
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
var emailRequestTypeSelect = document.getElementById('email_request_type');
var emailCreationFields = document.getElementById('emailCreationFields');
var emailCreationInputs = Array.from(document.querySelectorAll('[name="email_creation_name"], [name="email_creation_department"], [name="email_creation_designation"]'));
var marketingRequestSection = document.getElementById('marketingRequestSection');
var projectNameInput = document.getElementById('project_name');
var areaCodeSelect = document.getElementById('area_code');
var marketingDepartmentSelect = document.getElementById('marketing_department');
var requestedMaterialsSelect = document.getElementById('requested_materials');
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
var lapcDepartments = <?= json_encode(array_values($lapcDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var mhcDepartments = <?= json_encode(array_values($mhcDepartments), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var defaultCategories = <?= json_encode($defaultCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var mpdcCategories = <?= json_encode($mpdcCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var lapcDepartmentCategories = <?= json_encode($lapcDepartmentCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var mhcDepartmentCategories = <?= json_encode($mhcDepartmentCategories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
    var departmentInput = getSapField('department');
    return {
        name: String((getSapField('name') || {}).value || '').trim(),
        position: String((getSapField('position') || {}).value || '').trim(),
        immediate_head: String((getSapField('immediate_head') || {}).value || '').trim(),
        company: String((getSapField('company') || {}).value || '').trim(),
        department: String((departmentInput && departmentInput.value) || '').trim()
    };
}

function getFirstIncompleteCurrentSapField() {
    var currentCard = getCurrentSapCard();
    if (!currentCard) return null;
    var fieldOrder = ['name', 'position', 'immediate_head', 'company'];
    for (var index = 0; index < fieldOrder.length; index += 1) {
        var input = currentCard.querySelector('[data-sap-field="' + fieldOrder[index] + '"]');
        if (input && !String(input.value || '').trim()) {
            return input;
        }
    }
    var companyInput = currentCard.querySelector('[data-sap-field="company"]');
    var departmentInput = currentCard.querySelector('[data-sap-field="department"]');
    if (
        companyInput
        && String(companyInput.value || '') === '@leadsagri.com'
        && departmentInput
        && !departmentInput.disabled
        && !String(departmentInput.value || '').trim()
    ) {
        return departmentInput;
    }
    return null;
}

function getSapCardDisplayName(report, index) {
    var displayName = report ? String(report.name || '').trim() : '';
    return displayName !== '' ? displayName : ('Employee ' + (index + 1));
}

var savedSapReports = [];

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
        createHiddenSapInput(index, 'immediate_head', report.immediate_head);
        createHiddenSapInput(index, 'company', report.company);
        createHiddenSapInput(index, 'department', report.department);
    });
}

function updateCurrentSapFieldNames() {
    var currentIndex = savedSapReports.length;
    ['name', 'position', 'immediate_head', 'company', 'department'].forEach(function(fieldName) {
        var input = getSapField(fieldName);
        if (input) {
            input.name = 'sap_reports[' + currentIndex + '][' + fieldName + ']';
        }
    });
}

function clearCurrentSapForm() {
    ['name', 'position', 'immediate_head', 'company'].forEach(function(fieldName) {
        var input = getSapField(fieldName);
        if (input) {
            input.value = '';
        }
    });
    var departmentInput = getSapField('department');
    if (departmentInput) {
        departmentInput.value = '';
    }
    syncSapDepartmentVisibility(getCurrentSapCard());
}

function syncCurrentSapDepartment() {
    syncSapDepartmentVisibility(getCurrentSapCard());
}

function syncSapDepartmentVisibility(card) {
    if (!card) return;
    var companyInput = card.querySelector('[data-sap-field="company"]');
    var departmentWrap = card.querySelector('[data-sap-department-wrap]');
    var departmentField = card.querySelector('[data-sap-department-field]');
    var departmentInput = card.querySelector('[data-sap-field="department"]');
    var sapSectionActive = !sapRequestSection || sapRequestSection.classList.contains('is-visible');
    var shouldShowDepartment = sapSectionActive && companyInput && String(companyInput.value || '') === '@leadsagri.com';
    if (departmentWrap) {
        departmentWrap.classList.toggle('is-visible', shouldShowDepartment);
    }
    if (departmentField) {
        departmentField.classList.toggle('is-visible', shouldShowDepartment);
    }
    if (departmentInput) {
        departmentInput.disabled = !shouldShowDepartment;
        if (shouldShowDepartment) {
            departmentInput.setAttribute('required', 'required');
        } else {
            departmentInput.removeAttribute('required');
            departmentInput.value = '';
        }
    }
}

function syncSapCardState() {
    var currentReport = getCurrentSapReportValues();
    var totalEmployees = savedSapReports.length + 1;
    var currentEmployeeNumber = totalEmployees;
    if (sapRequestCounter) {
        sapRequestCounter.textContent = 'Employee ' + currentEmployeeNumber + ' of ' + totalEmployees;
    }
    if (sapEmployeeSwitcher) {
        sapEmployeeSwitcher.innerHTML = '';
        savedSapReports.forEach(function(report, reportIndex) {
            var option = document.createElement('option');
            option.value = String(reportIndex);
            option.textContent = getSapCardDisplayName(report, reportIndex);
            sapEmployeeSwitcher.appendChild(option);
        });
        var currentOption = document.createElement('option');
        currentOption.value = 'current';
        currentOption.textContent = getSapCardDisplayName(currentReport, savedSapReports.length);
        currentOption.selected = true;
        sapEmployeeSwitcher.appendChild(currentOption);
    }
    var currentCard = getCurrentSapCard();
    if (currentCard) {
        currentCard.classList.add('is-active');
        var removeButtons = Array.from(currentCard.querySelectorAll('[data-remove-sap-report]'));
        removeButtons.forEach(function(button) {
            button.style.display = savedSapReports.length > 0 ? '' : 'none';
        });
        syncSapDepartmentVisibility(currentCard);
    }
    updateCurrentSapFieldNames();
}

function initializeSavedSapReportsFromDom() {
    if (!sapSavedReportsHost) return;
    var grouped = {};
    Array.from(sapSavedReportsHost.querySelectorAll('input[type="hidden"]')).forEach(function(input) {
        var match = input.name.match(/^sap_reports\[(\d+)\]\[(name|position|immediate_head|company|department)\]$/);
        if (!match) return;
        var index = match[1];
        var fieldName = match[2];
        if (!grouped[index]) {
            grouped[index] = { name: '', position: '', immediate_head: '', company: '', department: '' };
        }
        grouped[index][fieldName] = input.value || '';
    });
    savedSapReports = Object.keys(grouped).sort(function(a, b) {
        return parseInt(a, 10) - parseInt(b, 10);
    }).map(function(index) {
        return grouped[index];
    });
    renderSavedSapReports();
}

function saveCurrentSapEmployee() {
    var incompleteField = getFirstIncompleteCurrentSapField();
    if (incompleteField) {
        setInlineFormError('Please complete the current SAP employee report before adding another employee.');
        try { incompleteField.focus(); } catch (focusError) {}
        return false;
    }
    var report = getCurrentSapReportValues();
    savedSapReports.push(report);
    renderSavedSapReports();
    clearCurrentSapForm();
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
    syncSapCardState();
}

function addSapCard() {
    saveCurrentSapEmployee();
}

function syncRequestGridRows() {
    if (recipientRow && departmentGroup) {
        var departmentVisible = departmentGroup.style.display !== 'none' && !departmentGroup.classList.contains('hidden');
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

function closePriorityDropdown() {
    if (!priorityMenu || !priorityTrigger) return;
    priorityMenu.classList.remove('is-open');
    priorityTrigger.setAttribute('aria-expanded', 'false');
}

function closeRecipientDropdown() {
    if (!recipientMenu || !recipientTrigger) return;
    recipientMenu.classList.remove('is-open');
    recipientTrigger.setAttribute('aria-expanded', 'false');
}

function syncRecipientTriggerLabel() {
    if (!recipientTrigger || !recipient) return;
    var selectedOption = recipient.options[recipient.selectedIndex];
    var label = selectedOption && selectedOption.value ? selectedOption.textContent : 'Select Recipient';
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
    var selectedValue = String(recipient.value || '');
    recipientMenu.innerHTML = '';
    Array.from(recipient.options).forEach(function(option) {
        if (!option.value) return;
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
    if (!categorySelect) return;
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
    if (!prioritySelect) return;
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
        optionButton.addEventListener('click', function() {
            choosePriority(optionValue, true);
        });
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
        departmentGroup.style.display = 'block';
        departmentGroup.classList.remove('hidden');
        recipientGroup.classList.remove('full-width');
        departmentSelect.disabled = false;
        departmentSelect.setAttribute('required', 'required');
        if (departmentTrigger) departmentTrigger.disabled = false;
    } else if (isMhcRecipientValue(value)) {
        populateDepartments(mhcDepartments);
        departmentGroup.style.display = 'block';
        departmentGroup.classList.remove('hidden');
        recipientGroup.classList.remove('full-width');
        departmentSelect.disabled = false;
        departmentSelect.setAttribute('required', 'required');
        if (departmentTrigger) departmentTrigger.disabled = false;
    } else {
        departmentGroup.style.display = 'none';
        departmentGroup.classList.add('hidden');
        recipientGroup.classList.add('full-width');
        departmentSelect.value = '';
        departmentSelect.setAttribute('data-selected', '');
        departmentSelect.disabled = true;
        departmentSelect.removeAttribute('required');
        if (departmentTrigger) departmentTrigger.disabled = true;
        syncDepartmentTriggerLabel();
        closeDepartmentDropdown();
    }
    syncRequestGridRows();
}

function populateSalesCategories(options) {
    if (!categorySelect) return;
    var selectedValue = String(categorySelect.getAttribute('data-selected') || categorySelect.value || '');
    categorySelect.innerHTML = '<option value="" disabled hidden selected>Choose category</option>';
    if (categoryMenu) {
        categoryMenu.innerHTML = '';
    }
    options.forEach(function(optionValue){
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
    if (selectedValue !== '' && options.indexOf(selectedValue) === -1) {
        categorySelect.value = '';
    }
    categorySelect.setAttribute('data-selected', '');
    syncCategoryTriggerLabel();
}

function toggleCategoryField() {
    if (!recipient || !categorySelect) return;
    var value = String(recipient.value || '');
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    var options = defaultCategories;
    if (value === '@malvedaproperties.com' || value === 'MPDC (@malvedaproperties.com)') {
        options = mpdcCategories;
    } else if (isMhcRecipientValue(value) && mhcDepartmentCategories[departmentValue]) {
        options = mhcDepartmentCategories[departmentValue];
    } else if (isLapcRecipientValue(value) && lapcDepartmentCategories[departmentValue]) {
        options = lapcDepartmentCategories[departmentValue];
    }
    populateSalesCategories(options);
}

function togglePriorityField() {
    if (!priorityGroup || !prioritySelect || !recipient) return;
    var recipientValue = String(recipient.value || '');
    var departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
    var isMarketing = isMhcRecipientValue(recipientValue) && departmentValue === 'Marketing Creatives';
    var shouldShow = isLapcRecipientValue(recipientValue) && departmentValue === 'HR';
    if (isMarketing) {
        shouldShow = true;
    }

    if (shouldShow) {
        setPriorityOptions(isMarketing ? 'marketing' : 'hr');
        priorityGroup.classList.remove('hidden');
        prioritySelect.disabled = false;
        prioritySelect.setAttribute('required', 'true');
        if (priorityTrigger) priorityTrigger.disabled = false;
    } else {
        priorityGroup.classList.add('hidden');
        prioritySelect.value = '';
        prioritySelect.setAttribute('data-selected', '');
        prioritySelect.disabled = true;
        prioritySelect.removeAttribute('required');
        if (priorityTrigger) priorityTrigger.disabled = true;
        closePriorityDropdown();
    }
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
            { value: 'Low', text: 'Low - General Inquiry' },
            { value: 'Medium', text: 'Medium - Needs action within a few days' },
            { value: 'High', text: 'High - Time-sensitive or urgent' }
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
        priorityLabel.innerHTML = modeKey === 'marketing'
            ? 'Urgency Level <span class="required-asterisk">*</span>'
            : 'Level of Urgency <span class="required-asterisk">*</span>';
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
    var shouldShowMarketingRequest = isLapcMarketingSelection();
    var selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
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
    var shouldShowEmailRequest = isLapcItSelection() && selectedCategory === 'Email';
    var shouldShowEmailCreation = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
    var shouldShowEmailDefault = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === '';
    var shouldShowEmailForgotPassword = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'forgot password';
    var shouldShowEmailBackup = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'backup of email';
    var shouldShowSapRequest = isLapcItSelection() && selectedCategory === 'SAP';
    var shouldRequireKamiAttachment = shouldShowConcernType;
    var shouldRequireMedicalAttachment = shouldShowMedicalCashAdvance;

    document.body.classList.toggle('kami-section-active', shouldShowConcernType);
    document.body.classList.toggle('other-section-active', shouldShowOtherDetailsStyle);
    document.body.classList.toggle('medical-cash-section-active', shouldShowMedicalCashAdvance);
    document.body.classList.toggle('training-request-section-active', shouldShowTrainingRequest);
    document.body.classList.toggle('company-property-section-active', shouldShowCompanyPropertyRequest);
    document.body.classList.toggle('coe-request-section-active', shouldShowCoeRequest);
    document.body.classList.toggle('col-request-section-active', shouldShowColRequest);
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

    if (descriptionContainer) descriptionContainer.style.display = (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowSapRequest || shouldShowEmailCreation) ? 'none' : '';
    if (attachmentContainer) attachmentContainer.style.display = (shouldShowSssBenefits || shouldShowSapRequest || shouldShowEmailRequest) ? 'none' : '';
    if (otherDescriptionSection) otherDescriptionSection.style.display = shouldShowSssBenefits ? 'none' : '';

    if (attachmentInput) attachmentInput.disabled = shouldShowSssBenefits || shouldShowEmailRequest;
    if (chooseBtn) chooseBtn.disabled = shouldShowSssBenefits || shouldShowEmailRequest;
    if (attachmentOptionalText) attachmentOptionalText.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment) ? 'none' : '';
    if (attachmentOptionalText && shouldShowMarketingRequest) attachmentOptionalText.style.display = 'none';
    if (attachmentRequiredAsterisk) attachmentRequiredAsterisk.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment) ? '' : 'none';
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
    if (sapRequestList) {
        Array.from(sapRequestList.querySelectorAll('[data-sap-field]')).forEach(function(input) {
            if (!(input instanceof HTMLElement)) return;
            if (shouldShowSapRequest) input.setAttribute('required', 'required');
            else input.removeAttribute('required');
        });
        syncSapDepartmentVisibility(getCurrentSapCard());
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
    emailCreationInputs.forEach(function(input) {
        if (!input) return;
        if (shouldShowEmailCreation) {
            input.setAttribute('required', 'required');
        } else {
            input.removeAttribute('required');
            if (!shouldShowEmailRequest) input.value = '';
        }
    });
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
        if (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowSapRequest || shouldShowEmailCreation) {
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
    } else if ((shouldShowEmailDefault || shouldShowEmailForgotPassword || shouldShowEmailBackup) && emailDescriptionHost) {
        moveDescriptionContainer(emailDescriptionHost);
    } else if (shouldShowOtherDetailsStyle && otherRequestContinuationHost) {
        moveDescriptionContainer(otherRequestContinuationHost);
        moveAttachmentContainer(otherRequestContinuationHost);
    } else {
        restoreDescriptionContainer();
    }

    if (shouldShowMedicalCashAdvance && medicalCashAttachmentHost) {
        moveAttachmentContainer(medicalCashAttachmentHost);
    } else if (shouldShowConcernType && kamiContinuationHost) {
        moveAttachmentContainer(kamiContinuationHost);
    } else if (shouldShowOtherDetailsStyle && otherRequestContinuationHost) {
        moveAttachmentContainer(otherRequestContinuationHost);
    } else if (attachmentOriginalHost) {
        moveAttachmentContainer(attachmentOriginalHost);
    }
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
        closeCategoryDropdown();
        closePriorityDropdown();
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
        closePriorityDropdown();
        departmentMenu.classList.add('is-open');
        departmentTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (categoryTrigger) {
    categoryTrigger.addEventListener('click', function() {
        if (categoryTrigger.disabled || !categoryMenu) return;
        var nextState = !categoryMenu.classList.contains('is-open');
        if (!nextState) {
            closeCategoryDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closePriorityDropdown();
        categoryMenu.classList.add('is-open');
        categoryTrigger.setAttribute('aria-expanded', 'true');
    });
}
if (priorityTrigger) {
    priorityTrigger.addEventListener('click', function() {
        if (priorityTrigger.disabled || !priorityMenu) return;
        var nextState = !priorityMenu.classList.contains('is-open');
        if (!nextState) {
            closePriorityDropdown();
            return;
        }
        closeRecipientDropdown();
        closeDepartmentDropdown();
        closeCategoryDropdown();
        priorityMenu.classList.add('is-open');
        priorityTrigger.setAttribute('aria-expanded', 'true');
    });
}
document.addEventListener('click', function(event) {
    if (!recipientDropdown && !departmentDropdown && !categoryDropdown && !priorityDropdown) return;
    if (
        (recipientDropdown && recipientDropdown.contains(event.target))
        || (departmentDropdown && departmentDropdown.contains(event.target))
        || (categoryDropdown && categoryDropdown.contains(event.target))
        || (priorityDropdown && priorityDropdown.contains(event.target))
    ) return;
    closeRecipientDropdown();
    closeDepartmentDropdown();
    closeCategoryDropdown();
    closePriorityDropdown();
});
if (departmentSelect) departmentSelect.addEventListener('change', function() {
    syncDepartmentTriggerLabel();
    toggleCategoryField();
    toggleHrExtraFields();
    syncCurrentSapDepartment();
});
if (categorySelect) categorySelect.addEventListener('change', function() {
    syncCategoryTriggerLabel();
    toggleHrExtraFields();
});
if (emailRequestTypeSelect) emailRequestTypeSelect.addEventListener('change', function() {
    toggleHrExtraFields();
});
if (prioritySelect) prioritySelect.addEventListener('change', function() {
    prioritySelect.setAttribute('data-selected', String(prioritySelect.value || ''));
    renderPriorityDropdownOptions();
});
if (concernTypeSelect) concernTypeSelect.addEventListener('change', function() {
    toggleHrExtraFields();
});
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
renderPriorityDropdownOptions();
toggleHrExtraFields();
initializeSavedSapReportsFromDom();
syncSapCardState();

if (sapAddEmployeeBtn) {
    sapAddEmployeeBtn.addEventListener('click', function(event) {
        event.preventDefault();
        event.stopPropagation();
        if (getFirstIncompleteCurrentSapField()) {
            saveCurrentSapEmployee();
            return;
        }
        addSapCard();
    });
}
if (sapEmployeeSwitcher) {
    sapEmployeeSwitcher.addEventListener('change', function() {
        sapEmployeeSwitcher.value = 'current';
        syncSapCardState();
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
        if (target.matches('[data-sap-field="name"]')) {
            syncSapCardState();
        }
    });
    sapRequestList.addEventListener('change', function(event) {
        var target = event.target;
        if (!(target instanceof Element)) return;
        if (target.matches('[data-sap-field="company"]')) {
            syncSapDepartmentVisibility(target.closest('[data-sap-card]'));
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
var toastTimer = null;

if (chooseBtn) {
    chooseBtn.addEventListener('click', function () {
        if (attachmentInput && !attachmentInput.disabled) attachmentInput.click();
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

        var size = document.createElement('div');
        var kb = Math.round((file.size || 0) / 1024);
        size.textContent = kb + ' KB';
        size.style.color = '#64748b';
        size.style.fontSize = '12px';
        size.style.fontWeight = '600';

        meta.appendChild(name);
        meta.appendChild(size);

        var right = document.createElement('div');
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = 'x';
        removeBtn.style.border = '1px solid #e2e8f0';
        removeBtn.style.background = '#ffffff';
        removeBtn.style.color = '#ef4444';
        removeBtn.style.fontWeight = '800';
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
            syncFiles();
        });
        right.appendChild(removeBtn);

        left.appendChild(icon);
        left.appendChild(meta);

        row.appendChild(left);
        row.appendChild(right);
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

function getExt(name) {
    var parts = String(name || '').toLowerCase().split('.');
    return parts.length > 1 ? parts.pop() : '';
}

if (attachmentInput) {
    attachmentInput.addEventListener('change', function (e) {
        var blockedMax = false;
        var hasUnsupportedType = false;
        var validFiles = [];
        Array.from(e.target.files || []).forEach(function (file) {
            var ext = getExt(file && file.name);
            if (ALLOWED_EXT.indexOf(ext) === -1) {
                hasUnsupportedType = true;
                return;
            }
            validFiles.push(file);
        });
        if (hasUnsupportedType) {
            attachmentInput.value = '';
            showError('Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.');
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
        attachmentInput.value = '';
        if (blockedMax) {
            showError('Maximum 5 attachments allowed. Extra files were not added.');
        } else {
            showError('');
        }
        syncFiles();
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
    return { message: firstErrorMessage, config: firstErrorConfig };
}

window.TMSalesResetAttachments = function() {
    dt = new DataTransfer();
    syncFiles();
    showError('');
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
syncFiles();

var formEl = attachmentInput ? attachmentInput.closest('form') : null;
if (formEl) {
    formEl.addEventListener('submit', function (e) {
        var selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
        var isLapcHrSelected = isLapcHrSelection();
        var isLapcItSelected = isLapcItSelection();
        var isLapcMarketingSelected = isLapcMarketingSelection();
        var isKamiAttachmentRequired = isLapcHrSelected && selectedCategory === 'Attendance & Timekeeping';
        var isHrSssSelected = isLapcHrSelected && selectedCategory === 'SSS Sickness and Benefit Concern';
        var isHrMedicalCashAdvanceSelected = isLapcHrSelected && selectedCategory === 'Medical Cash Advance';
        var badType = Array.from(dt.files).find(function (file) {
            var ext = getExt(file && file.name);
            return ALLOWED_EXT.indexOf(ext) === -1;
        });
        var total = 0;
        Array.from(dt.files).forEach(function (f) { total += (f && f.size) ? f.size : 0; });

        setInlineFormError('');

        if (isLapcHrSelected && prioritySelect && !String(prioritySelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the level of urgency.');
            return;
        }
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && !String(emailRequestTypeSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the email request type.');
            return;
        }
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email') {
            var incompleteEmailCreationField = emailCreationInputs.find(function(input) {
                return input && !String(input.value || '').trim();
            });
            if (incompleteEmailCreationField) {
                e.preventDefault();
                setInlineFormError('Please complete the Creation of email details.');
                try { incompleteEmailCreationField.focus(); } catch (focusError) {}
                return;
            }
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
            if (prioritySelect && !String(prioritySelect.value || '').trim()) {
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
        if (isLapcItSelected && selectedCategory === 'Email' && emailRequestTypeSelect && !String(emailRequestTypeSelect.value || '').trim()) {
            e.preventDefault();
            setInlineFormError('Please choose the email request type.');
            return;
        }
        if (isLapcItSelected && selectedCategory === 'SAP') {
            var sapCards = getSapCards();
            for (var sapIndex = 0; sapIndex < sapCards.length; sapIndex += 1) {
                var sapCard = sapCards[sapIndex];
                var requiredSapFields = ['name', 'position', 'immediate_head', 'company'];
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
                var sapCompanyInput = sapCard ? sapCard.querySelector('[data-sap-field="company"]') : null;
                var sapDepartmentInput = sapCard ? sapCard.querySelector('[data-sap-field="department"]') : null;
                if (
                    sapCompanyInput
                    && String(sapCompanyInput.value || '') === '@leadsagri.com'
                    && sapDepartmentInput
                    && !String(sapDepartmentInput.value || '').trim()
                ) {
                    e.preventDefault();
                    setInlineFormError('Please complete each SAP employee report before submitting.');
                    try { sapDepartmentInput.focus(); } catch (focusError) {}
                    return;
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
        if (isKamiAttachmentRequired && dt.files.length === 0) {
            e.preventDefault();
            showError('Attachment is required for Attendance & Timekeeping.');
            return;
        }
        if (isHrMedicalCashAdvanceSelected && dt.files.length === 0) {
            e.preventDefault();
            showError('Supporting Information is required for Medical Cash Advance.');
            return;
        }
        if (!isHrSssSelected && (dt.files.length > MAX_FILES || badType || total > MAX_BYTES)) {
            e.preventDefault();
            showError(dt.files.length > MAX_FILES ? 'Maximum 5 attachments allowed.' : (badType ? 'Unsupported attachment type. Allowed: JPG, PNG, PDF, DOC, DOCX.' : 'Attachment too large. Max 5MB total.'));
            return;
        }
        showError('');
    });
}
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

    function showSuccessState(ticketNumber) {
        if (!modal) return;
        clearLoadingTimers();
        var ticketLine = ticketNumber
            ? ('<br><span class="ticket-modal-ticket-label">Ticket ID:</span> <span class="ticket-modal-ticket-number">#' + ticketNumber + '</span>')
            : '';
        setModalState('success', 'Ticket Submitted Successfully', 'Your request has been sent.<br>Our team will get back to you soon.' + ticketLine, '', 100);
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
                        showSuccessState(data.ticket_number || '');
                    }, waitMs);
                } else {
                    showSuccessState(data.ticket_number || '');
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

</body>
</html>

