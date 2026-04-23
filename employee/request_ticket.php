<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';
require_once '../includes/pdf_thumbnail.php';

$lapcDepartments = ticket_lapc_departments();

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
        'immediate_head' => '',
        'department' => '',
        'company' => '',
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
    $default_categories = ['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support'];
    $mpdc_categories = ['Engineerings', 'Client Based'];
    $lapc_department_categories = [
        'Admin & Legal' => [
            'Phone Plan / Simcard',
            'FleetCard Request',
            'Supplies',
        ],
        'Bidding' => [
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
        'Marketing' => [
            'Marketing Request',
        ],
    ];
    $category = trim((string) ($_POST['category'] ?? ''));
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
    $project_name = trim((string) ($_POST['project_name'] ?? ''));
    $area_code = trim((string) ($_POST['area_code'] ?? ''));
    $marketing_department = trim((string) ($_POST['marketing_department'] ?? ''));
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
    $sap_name = $sap_reports[0]['name'] ?? trim((string) ($_POST['sap_name'] ?? ''));
    $sap_position = $sap_reports[0]['position'] ?? trim((string) ($_POST['sap_position'] ?? ''));
    $sap_immediate_head = $sap_reports[0]['immediate_head'] ?? trim((string) ($_POST['sap_immediate_head'] ?? ''));
    $sap_department = $sap_reports[0]['department'] ?? trim((string) ($_POST['sap_department'] ?? ''));
    $sap_company = $sap_reports[0]['company'] ?? trim((string) ($_POST['sap_company'] ?? ''));
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
    if ($assigned_company === '@malvedaproperties.com') {
        $allowed_categories = $mpdc_categories;
    } elseif ($assigned_company === '@leadsagri.com' && isset($lapc_department_categories[$assigned_group])) {
        $allowed_categories = $lapc_department_categories[$assigned_group];
    }
    $requiresDepartment = (strtolower((string) $assigned_company) === '@leadsagri.com');
    $allowedDepartments = $lapcDepartments;
    $routing_group = $requiresDepartment ? trim($assigned_group) : 'IT';
    $assigned_group = $routing_group;
    $assigned_department = $requiresDepartment ? $routing_group : 'IT';
    $description = trim((string) ($_POST['description'] ?? ''));
    $email_request_type = trim((string) ($_POST['email_request_type'] ?? ''));
    $email_creation_name = trim((string) ($_POST['email_creation_name'] ?? ''));
    $email_creation_department = trim((string) ($_POST['email_creation_department'] ?? ''));
    $email_creation_designation = trim((string) ($_POST['email_creation_designation'] ?? ''));
    $isLapcHrTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'HR');
    $isLapcItTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'IT');
    $isLapcMarketingTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'Marketing');
    $isHrAttendanceCategory = ($isLapcHrTicket && $category === 'Attendance & Timekeeping');
    $isHrLeaveOrOtherCategory = ($isLapcHrTicket && ($category === 'Leave Concern' || $category === 'Others'));
    $isHrSssCategory = ($isLapcHrTicket && $category === 'SSS Sickness and Benefit Concern');
    $isHrMedicalCashAdvance = ($isLapcHrTicket && $category === 'Medical Cash Advance');
    $isHrTrainingRequest = ($isLapcHrTicket && $category === 'Training Request');
    $isHrCompanyPropertyRequest = ($isLapcHrTicket && $category === 'Request for Company Property');
    $isHrCertificateEmploymentRequest = ($isLapcHrTicket && $category === 'Certificate of Employment');
    $isHrCertificateLeaveRequest = ($isLapcHrTicket && $category === 'Certificate of Leave');
    $isLapcItEmailRequest = ($isLapcItTicket && $category === 'Email');
    $isLapcItSapRequest = ($isLapcItTicket && $category === 'SAP');
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
    if ($isLapcHrTicket) {
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
    } elseif ($isLapcMarketingTicket) {
        if (!in_array($priority, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'], true)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Please choose the urgency level.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Please choose the urgency level.';
            header("Location: request_ticket.php");
            exit();
        }
    } elseif ($priority === '') {
        $priority = 'Low';
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
            && ($email_creation_name === '' || $email_creation_department === '' || $email_creation_designation === '')
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

    if ($isHrSssCategory && $description === '') {
        $description = 'SSS Notification and Benefits Concern submission.';
    }

    $subject = $category . ' Concern';
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
            . "Email Request Type: Creation of email\n"
            . "Name: " . $email_creation_name . "\n"
            . "Department: " . $email_creation_department . "\n"
            . "Designation: " . $email_creation_designation;
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
            $sapCompanyRequiresDepartment = ($sap_report['company'] === '@leadsagri.com');
            if (
                $sap_report['name'] === ''
                || $sap_report['position'] === ''
                || $sap_report['immediate_head'] === ''
                || $sap_report['company'] === ''
                || ($sapCompanyRequiresDepartment && $sap_report['department'] === '')
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
                . "Full Name: " . $sap_report['name'] . "\n"
                . "Position: " . $sap_report['position'] . "\n"
                . "Immediate Supervisor: " . $sap_report['immediate_head'] . "\n"
                . "Department: " . $sap_report['department'] . "\n"
                . "Company: " . $sap_report['company'];
        }
    }

    if ($isLapcMarketingTicket) {
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
        $description = "LAPC Marketing Request\n"
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
    if ($description === '') {
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

    if ($requiresKamiAttachment || $isHrMedicalCashAdvance) {
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
            $attachmentRequiredMessage = $isHrMedicalCashAdvance
                ? 'Supporting Information is required for Medical Cash Advance.'
                : 'Attachment is required for Attendance & Timekeeping.';
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
        'GPCI' => ['GPCI', 'GPSCI', 'Golden Primestocks Chemical Inc - GPSCI', 'Golden Primestocks Chemical Inc - GPCI'],
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
    if ($isLapcMarketingTicket) {
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

    $notifTargetLabel = notif_assignment_target_label((string) $assigned_company, (string) $assigned_department, $requiresDepartment ? 'the selected department' : 'the selected recipient');
    $employeeTicketNotifMsg = "New ticket #$ticket_number from $user_name was assigned to your group.";
    $adminTicketNotifMsg = "New ticket #$ticket_number from $user_name was assigned to $notifTargetLabel.";
    foreach ($assigned_user_ids as $notifyUserId) {
        $notifyUserId = (int) $notifyUserId;
        if ($notifyUserId <= 0 || $notifyUserId === (int) $user_id) continue;
        notif_insert_system($conn, $notifyUserId, (int) $ticket_id, $employeeTicketNotifMsg, 'dept_assigned');
    }
    notif_insert_admins($conn, (int) $ticket_id, $adminTicketNotifMsg, 'new_ticket');

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

    $usesSpecificEmailRoute = ticket_uses_specific_email_route($assigned_company, $assigned_group);
    $adminEmails = [];
    if (!$usesSpecificEmailRoute) {
        $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
        if ($admins) {
            while ($admin = $admins->fetch_assoc()) {
                $adminEmails[] = $admin['email'];
            }
        }
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

    $adminSubject = "New Ticket Submitted (#$ticketNumber)";
    $adminTpl = notif_email_simple('New Ticket Submitted', [
        "Ticket ID: #$ticketNumber",
        "Title: $ticketSubject",
        "Category: $category",
        "Priority: $priority",
        "Status: $ticketStatus",
        "Assigned Department: $ticketAssignedDept",
        "Requested by: $requesterName",
        "Requester Email: $employeeEmail"
    ], 'Open Ticket', notif_ticket_link_admin((int) $ticket_id));

    if (count($adminEmails) > 0) {
        $adminOk = notif_email_send($adminEmails, $adminSubject, (string) $adminTpl['html'], (string) $adminTpl['text'], $attachments);
        if (!$adminOk) {
            error_log('Ticket email failed (admins) | ticketId=' . (string) $ticket_id);
        }
    }

    $assigneeEmails = ticket_assignee_notification_emails($conn, $assigned_user_ids, $assigned_company, $assigned_group, (int) $user_id);
    if (count($assigneeEmails) > 0) {
        $assigneeLines = [
            "Ticket ID: #$ticketNumber",
            "Category: $category",
            "Status: $ticketStatus",
            "Requested by: $requesterName",
            "Description:\n$ticketDescription"
        ];
        if ($attachmentSummary !== '') {
            $assigneeLines[] = $attachmentSummary;
        }
        $assigneeTpl = notif_email_simple('Ticket Assigned', $assigneeLines, 'View Ticket', notif_ticket_link_employee_tasks((int) $ticket_id));
        notif_email_send($assigneeEmails, "New Ticket Assigned (#$ticketNumber)", (string) $assigneeTpl['html'], (string) $assigneeTpl['text'], $attachments);
    }

    if ($employeeEmail !== '') {
        $employeeSubject = "Ticket Submitted (#$ticketNumber)";
        $employeeLines = [
            "Ticket ID: #$ticketNumber",
            "Category: $category",
            "Status: $ticketStatus",
            "Assigned Department: $ticketAssignedDept",
            "Description:\n$ticketDescription"
        ];
        if ($attachmentSummary !== '') {
            $employeeLines[] = $attachmentSummary;
        }
        $employeeTpl = notif_email_simple('Ticket Submitted', $employeeLines, 'View My Tickets', notif_ticket_link_employee_tickets((int) $ticket_id));

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

$requestTicketCompanyOptions = [
    '@leads-farmex.com' => 'FARMEX (@leads-farmex.com)',
    '@farmasee.ph' => 'FARMASEE (@farmasee.ph)',
    '@gpsci.net' => 'GPSCI (@gpsci.net)',
    '@leadsagri.com' => 'LAPC (@leadsagri.com)',
    '@leadsav.com' => 'LAV (@leadsav.com)',
    '@leadstech-corp.com' => 'LTC (@leadstech-corp.com)',
    '@lingapleads.org' => 'LINGAP (@lingapleads.org)',
    '@malvedaholdings.com' => 'MHC (@malvedaholdings.com)',
    '@malvedaproperties.com' => 'MPDC (@malvedaproperties.com)',
    '@primestocks.ph' => 'PCC (@primestocks.ph)',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Ticket | Leads Agri Helpdesk</title>
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
        body.employee-request-ticket-page .select-wrapper {
            position: relative;
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
        body.employee-request-ticket-page .custom-select-value {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.employee-request-ticket-page .custom-select-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
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
            background: rgba(27, 94, 32, 0.12);
            color: #14532d;
            font-weight: 700;
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
            font-size: 17px;
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
            overflow: hidden;
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
        body.employee-request-ticket-page .sap-request-panel-copy {
            min-width: 0;
            display: grid;
            gap: 8px;
            justify-items: start;
            text-align: left;
        }
        body.employee-request-ticket-page .sap-request-counter {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 800;
            line-height: 1.3;
        }
        body.employee-request-ticket-page .sap-request-panel-tools {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        body.employee-request-ticket-page .sap-request-switcher {
            min-width: 236px;
        }
        body.employee-request-ticket-page .sap-request-switcher-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #334155;
            font-size: 16px;
            pointer-events: none;
        }
        body.employee-request-ticket-page .sap-request-switcher .form-control {
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
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 24px 30px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
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
            gap: 14px;
            margin-top: 18px;
        }
        body.employee-request-ticket-page .email-creation-fields.is-visible {
            display: grid;
        }
        body.employee-request-ticket-page .email-description-host {
            margin-top: 18px;
        }
        body.employee-request-ticket-page .email-creation-inline-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 14px;
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
        body.employee-request-ticket-page .marketing-request-card-title {
            display: block;
            margin-bottom: 16px;
            font-weight: 700;
            color: #0f172a;
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
        body.employee-request-ticket-page .medical-cash-card label {
            display: block;
            margin-bottom: 10px;
        }
        body.employee-request-ticket-page .training-request-card label {
            display: block;
            margin-bottom: 10px;
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
            body.employee-request-ticket-page .col-request-inline-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .marketing-request-inline-row {
                grid-template-columns: 1fr;
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
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
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
            font-weight: 900;
            box-shadow: none;
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
            font-weight: 800;
            color: #20274a;
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
            margin-top: 2px;
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
            margin: 0 auto 24px;
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
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-status {
            display: none;
        }
        body.employee-request-ticket-page .ticket-modal[data-state="success"] .ticket-modal-progress span { width: 100% !important; }
        body.employee-request-ticket-page .ticket-modal[data-state="error"] .ticket-modal-progress span { background: linear-gradient(90deg, #ef4444, #f97316); }
        @keyframes ticket-loading-spin {
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
            body.employee-request-ticket-page .sap-request-company-row {
                grid-template-columns: 1fr;
            }
            body.employee-request-ticket-page .sap-request-card-top {
                align-items: flex-start;
                flex-direction: column;
            }
            body.employee-request-ticket-page .sap-request-panel-head {
                flex-direction: column;
                align-items: stretch;
            }
            body.employee-request-ticket-page .sap-request-switcher {
                min-width: 0;
                width: 100%;
            }
            body.employee-request-ticket-page .sap-request-add-btn {
                width: 100%;
            }
            body.employee-request-ticket-page .sap-request-actions {
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
    </style>
</head>
<body class="employee-request-ticket-page">

    <!-- 2ï¸âƒ£ TOP NAVIGATION BAR -->
    <?php include '../includes/employee_navbar.php'; ?>

    <div class="dashboard-container">
        <div class="content-wrapper">
            <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-error" id="pageError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700;">
                    <?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- 4ï¸âƒ£ REQUEST TICKET PAGE â€“ REDESIGN -->
            <div class="page-header" style="text-align: center; margin-bottom: 40px;">
                <h1 class="page-title">Create a Ticket</h1>
                <p class="page-subtitle">Please fill out the form below.</p>
            </div>

            <div class="form-card">
                <form id="ticketForm" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="alert alert-error" id="ajaxError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700; display:none;"></div>
                    
                    <!-- ðŸ”¹ Request Information -->
                    <h3 class="form-section-title">Request Information</h3>

                    <div class="request-grid-row is-single" id="recipientDepartmentRow">
                        <div class="form-group">
                            <label>Assign to <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper">
                                <select name="assigned_company" id="assigned_company" class="form-control" required>
                                    <option value="" disabled selected hidden>Choose recipient</option>
                                    <option value="@leads-farmex.com">FARMEX</option>
                                    <option value="@farmasee.ph">FARMASEE</option>
                                    <option value="@gpsci.net">GPSCI</option>
                                    <option value="@leadsagri.com">LAPC</option>
                                    <option value="@leadsav.com">LAV</option>
                                    <option value="@leadstech-corp.com">LTC</option>
                                    <option value="@lingapleads.org">LINGAP</option>
                                    <option value="@malvedaholdings.com">MHC</option>
                                    <option value="@malvedaproperties.com">MPDC</option>
                                    <option value="@primestocks.ph">PCC</option>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="form-group" id="departmentContainer" style="display:none;">
                            <label>Assigned Department <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper" id="assignedGroupWrapper">
                                <select name="assigned_group" id="assigned_group" class="form-control custom-select-native" required disabled data-selected="<?= htmlspecialchars((string) ($_POST['assigned_group'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose department</option>
                                </select>
                                <button type="button" class="form-control custom-select-trigger" id="assignedGroupTrigger" aria-haspopup="listbox" aria-expanded="false" disabled>
                                    <span class="custom-select-value" id="assignedGroupTriggerValue">Choose department</span>
                                </button>
                                <div class="custom-select-menu" id="assignedGroupMenu" role="listbox" hidden></div>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>

                    <div class="request-grid-row is-single" id="categoryUrgencyRow">
                        <div class="form-group" id="categoryContainer">
                            <label>Category <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper">
                                <select name="category" id="category_select" class="form-control" required data-selected="<?= htmlspecialchars((string) ($_POST['category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose category</option>
                                    <option value="Documentation">Documentation</option>
                                    <option value="Email">Email</option>
                                    <option value="Hardware">Hardware</option>
                                    <option value="Internet Concerns">Internet Concerns</option>
                                    <option value="Procurement">Procurement</option>
                                    <option value="Software">Software</option>
                                    <option value="Technical Support">Technical Support</option>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="form-group hr-extra-group" id="urgencyContainer">
                            <label>Level of Urgency <span class="required-asterisk">*</span></label>
                        <input
                            type="hidden"
                            name="priority"
                            id="priority_hidden"
                            value="<?= htmlspecialchars((string) ($_POST['priority'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        >
                        <div class="select-wrapper">
                            <select id="urgencySelect" class="form-control">
                                <option value="" disabled selected hidden>Choose level of urgency</option>
                                <option value="Low">Low - General Inquiry</option>
                                <option value="Medium">Medium - Needs action within a few days</option>
                                <option value="High">High - Time-sensitive or urgent</option>
                                </select>
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
                                    <select name="hr_concern_type" id="hr_concern_type" class="form-control" data-selected="<?= htmlspecialchars((string) ($_POST['hr_concern_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="" disabled selected hidden>Choose type of concern</option>
                                        <option value="KAMI Error: Check IN/OUT">KAMI Error: Check IN/OUT</option>
                                        <option value="KAMI Error: Failed log in attempts">KAMI Error: Failed log in attempts</option>
                                        <option value="Unpaid salary">Unpaid salary</option>
                                        <option value="Unpaid leave/overtime pay">Unpaid leave/overtime pay</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    <i class="fas fa-chevron-down select-icon"></i>
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
                                                <label for="sap_name_<?= $sapIndex; ?>">Full Name <span class="required-asterisk">*</span></label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][name]" id="sap_name_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="name">
                                            </div>
                                        </section>
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_position_<?= $sapIndex; ?>">Position <span class="required-asterisk">*</span></label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][position]" id="sap_position_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="position">
                                            </div>
                                        </section>
                                    </div>
                                    <div class="sap-request-inline-row">
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_immediate_head_<?= $sapIndex; ?>">Immediate Supervisor <span class="required-asterisk">*</span></label>
                                                <input type="text" name="sap_reports[<?= $sapIndex; ?>][immediate_head]" id="sap_immediate_head_<?= $sapIndex; ?>" class="form-control" value="<?= htmlspecialchars((string) ($sapEntry['immediate_head'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer" data-sap-field="immediate_head">
                                            </div>
                                        </section>
                                        <section class="sap-request-field">
                                            <div class="form-group">
                                                <label for="sap_company_<?= $sapIndex; ?>">Company <span class="required-asterisk">*</span></label>
                                                <div class="select-wrapper">
                                                    <select name="sap_reports[<?= $sapIndex; ?>][company]" id="sap_company_<?= $sapIndex; ?>" class="form-control" data-sap-field="company">
                                                        <option value="" disabled <?= (($sapEntry['company'] ?? '') === '') ? 'selected' : ''; ?>>Choose company</option>
                                                        <?php foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel): ?>
                                                            <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>" <?= (($sapEntry['company'] ?? '') === $companyValue) ? 'selected' : ''; ?>>
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
                                        <section class="sap-request-field sap-request-department-wrap <?= (($sapEntry['company'] ?? '') === '@leadsagri.com') ? 'is-visible' : ''; ?>" data-sap-department-wrap>
                                            <div class="form-group">
                                                <label for="sap_department_<?= $sapIndex; ?>">Department <span class="required-asterisk">*</span></label>
                                                <div class="select-wrapper sap-request-department-field <?= (($sapEntry['company'] ?? '') === '@leadsagri.com') ? 'is-visible' : ''; ?>" data-sap-department-field>
                                                    <select name="sap_reports[<?= $sapIndex; ?>][department]" id="sap_department_<?= $sapIndex; ?>" class="form-control" data-sap-field="department">
                                                        <option value="" disabled <?= (($sapEntry['department'] ?? '') === '') ? 'selected' : ''; ?>>Choose department</option>
                                                        <?php foreach ($lapcDepartments as $sapDepartmentOption): ?>
                                                            <option value="<?= htmlspecialchars($sapDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>" <?= (($sapEntry['department'] ?? '') === $sapDepartmentOption) ? 'selected' : ''; ?>>
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
                                        <label for="sap_name___INDEX__">Full Name <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[__INDEX__][name]" id="sap_name___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="name">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_position___INDEX__">Position <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[__INDEX__][position]" id="sap_position___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="position">
                                    </div>
                                </section>
                            </div>
                            <div class="sap-request-inline-row">
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_immediate_head___INDEX__">Immediate Supervisor <span class="required-asterisk">*</span></label>
                                        <input type="text" name="sap_reports[__INDEX__][immediate_head]" id="sap_immediate_head___INDEX__" class="form-control" value="" placeholder="Your answer" data-sap-field="immediate_head">
                                    </div>
                                </section>
                                <section class="sap-request-field">
                                    <div class="form-group">
                                        <label for="sap_company___INDEX__">Company <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper">
                                            <select name="sap_reports[__INDEX__][company]" id="sap_company___INDEX__" class="form-control" data-sap-field="company">
                                                <option value="" disabled selected>Choose company</option>
                                                <?php foreach ($requestTicketCompanyOptions as $companyValue => $companyLabel): ?>
                                                    <option value="<?= htmlspecialchars($companyValue, ENT_QUOTES, 'UTF-8'); ?>">
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
                                <section class="sap-request-field sap-request-department-wrap" data-sap-department-wrap>
                                    <div class="form-group">
                                        <label for="sap_department___INDEX__">Department <span class="required-asterisk">*</span></label>
                                        <div class="select-wrapper sap-request-department-field" data-sap-department-field>
                                            <select name="sap_reports[__INDEX__][department]" id="sap_department___INDEX__" class="form-control" data-sap-field="department">
                                                <option value="" disabled selected>Choose department</option>
                                                <?php foreach ($lapcDepartments as $sapDepartmentOption): ?>
                                                    <option value="<?= htmlspecialchars($sapDepartmentOption, ENT_QUOTES, 'UTF-8'); ?>">
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
                    </template>

                    <section class="marketing-request-group" id="marketingRequestSection">
                        <h3 class="marketing-request-head">LAPC Marketing Request</h3>
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
                                            <select name="area_code" id="area_code" class="form-control" data-selected="<?= htmlspecialchars((string) ($_POST['area_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
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
                                            <select name="marketing_department" id="marketing_department" class="form-control" data-selected="<?= htmlspecialchars((string) ($_POST['marketing_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
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
                                    <?php $selectedRequestedMaterials = request_ticket_clean_string_array($_POST['requested_materials'] ?? []); ?>
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
                                    <?php $selectedCrops = request_ticket_clean_string_array($_POST['crop'] ?? []); ?>
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
    document.addEventListener('DOMContentLoaded', function() {
        const recipientDropdown = document.getElementById('assigned_company');
        const recipientDepartmentRow = document.getElementById('recipientDepartmentRow');
        const departmentContainer = document.getElementById('departmentContainer');
        const departmentSelect = document.getElementById('assigned_group');
        const departmentWrapper = document.getElementById('assignedGroupWrapper');
        const departmentTrigger = document.getElementById('assignedGroupTrigger');
        const departmentTriggerValue = document.getElementById('assignedGroupTriggerValue');
        const departmentMenu = document.getElementById('assignedGroupMenu');
        const categoryUrgencyRow = document.getElementById('categoryUrgencyRow');
        const categoryContainer = document.getElementById('categoryContainer');
        const categorySelect = document.getElementById('category_select');
        const kamiBannerContainer = document.getElementById('kamiBannerContainer');
        const concernTypeContainer = document.getElementById('concernTypeContainer');
        const concernTypeSelect = document.getElementById('hr_concern_type');
        const concernTypeOtherContainer = document.getElementById('concernTypeOtherContainer');
        const concernTypeOtherInput = document.getElementById('hr_concern_type_other');
        const leaveSubjectContainer = document.getElementById('leaveSubjectContainer');
        const leaveSubjectInput = document.getElementById('request_subject_title');
        const medicalCashAdvanceSection = document.getElementById('medicalCashAdvanceSection');
        const medicalCashPurposeInput = document.getElementById('medical_cash_purpose');
        const medicalCashAmountInput = document.getElementById('medical_cash_amount');
        const medicalCashDateNeededInput = document.getElementById('medical_cash_date_needed');
        const medicalCashAttachmentHost = document.getElementById('medicalCashAttachmentHost');
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
        const emailCreationFields = document.getElementById('emailCreationFields');
        const emailCreationInputs = Array.from(document.querySelectorAll('[name="email_creation_name"], [name="email_creation_department"], [name="email_creation_designation"]'));
        const sapRequestSection = document.getElementById('sapRequestSection');
        const sapRequestList = document.getElementById('sapRequestList');
        const sapRequestTemplate = document.getElementById('sapRequestTemplate');
        const sapAddEmployeeBtn = document.getElementById('sapAddEmployeeBtn');
        const sapEmployeeSwitcher = document.getElementById('sapEmployeeSwitcher');
        const sapRequestCounter = document.getElementById('sapRequestCounter');
        const marketingRequestSection = document.getElementById('marketingRequestSection');
        const projectNameInput = document.getElementById('project_name');
        const areaCodeSelect = document.getElementById('area_code');
        const marketingDepartmentSelect = document.getElementById('marketing_department');
        const requestedMaterialsSelect = document.getElementById('requested_materials');
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
        const defaultCategories = <?= json_encode(['Documentation', 'Email', 'Hardware', 'Internet Concerns', 'Procurement', 'Software', 'Technical Support'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mpdcCategories = <?= json_encode(['Engineerings', 'Client Based'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lapcDepartmentCategories = <?= json_encode([
            'Admin & Legal' => [
                'Phone Plan / Simcard',
                'FleetCard Request',
                'Supplies',
            ],
            'Bidding' => [
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
            'Marketing' => [
                'Marketing Request',
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
                : String((placeholderOption && placeholderOption.textContent) || 'Choose department').trim();
            departmentTriggerValue.textContent = nextLabel || 'Choose department';
        }
        function renderDepartmentDropdownOptions() {
            if (!departmentSelect || !departmentMenu || !departmentTrigger) return;
            const currentValue = String(departmentSelect.value || '');
            const options = Array.from(departmentSelect.options).filter(function(option) {
                return String(option.value || '') !== '';
            });
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
            syncDepartmentTriggerLabel();
            departmentTrigger.disabled = !!departmentSelect.disabled;
            if (departmentSelect.disabled) {
                closeDepartmentDropdown();
            }
        }
        function populateDepartments(options) {
            if (!departmentSelect) return;
            const selectedValue = String(departmentSelect.getAttribute('data-selected') || departmentSelect.value || '');
            departmentSelect.innerHTML = '<option value="" disabled selected hidden>Choose department</option>';
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
        function toggleDepartment() {
            if (!recipientDropdown || !departmentContainer || !departmentSelect) return;
            const value = String(recipientDropdown.value || '');
            const shouldShowDepartment = value === '@leadsagri.com';
            if (value === '@leadsagri.com') {
                populateDepartments(lapcDepartments);
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
        }
        function getCategoryOptions() {
            if (!recipientDropdown) return defaultCategories;
            const recipientValue = String(recipientDropdown.value || '');
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            if (recipientValue === '@malvedaproperties.com') {
                return mpdcCategories;
            }
            if (recipientValue === '@leadsagri.com' && Object.prototype.hasOwnProperty.call(lapcDepartmentCategories, departmentValue)) {
                return lapcDepartmentCategories[departmentValue];
            }
            return defaultCategories;
        }
        function toggleCategories() {
            if (!recipientDropdown || !categorySelect) return;
            populateCategories(getCategoryOptions());
        }
        function syncUrgencyInputs() {
            if (!priorityHidden || !urgencySelect) return;
            const selectedPriority = String(priorityHidden.value || '');
            const availableValues = Array.from(urgencySelect.options).map(function(option) {
                return String(option.value || '');
            });

            if (selectedPriority === '' || availableValues.indexOf(selectedPriority) === -1) {
                urgencySelect.value = '';
                return;
            }

            urgencySelect.value = selectedPriority;
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
        function isLapcMarketingSelection() {
            const recipientValue = recipientDropdown ? String(recipientDropdown.value || '') : '';
            const departmentValue = departmentSelect ? String(departmentSelect.value || '') : '';
            return recipientValue === '@leadsagri.com' && departmentValue === 'Marketing';
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
                    { value: 'Low', text: 'Low - General Inquiry' },
                    { value: 'Medium', text: 'Medium - Needs action within a few days' },
                    { value: 'High', text: 'High - Time-sensitive or urgent' }
                ];
            const currentValues = Array.from(urgencySelect.options).map(function(option) {
                return String(option.value || '') + ':' + String(option.textContent || '');
            }).join('|');
            const nextValues = desired.map(function(option) {
                return String(option.value || '') + ':' + String(option.text || '');
            }).join('|');
            if (currentValues === nextValues) return;

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
            emailCreationInputs.forEach(function(input) {
                if (!input) return;
                input.disabled = !shouldShowEmailCreation;
                if (shouldShowEmailCreation) {
                    input.setAttribute('required', 'required');
                } else {
                    input.removeAttribute('required');
                    input.value = '';
                }
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
            const fieldNames = ['name', 'position', 'immediate_head', 'department', 'company'];
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
                syncSapDepartmentVisibility(card);
            });
            if (activeSapCardIndex > sapCards.length - 1) {
                activeSapCardIndex = Math.max(0, sapCards.length - 1);
            }
            setActiveSapCard(activeSapCardIndex);
        }
        function syncSapDepartmentVisibility(card) {
            if (!card) return;
            const companyInput = card.querySelector('[data-sap-field="company"]');
            const departmentWrap = card.querySelector('[data-sap-department-wrap]');
            const departmentField = card.querySelector('[data-sap-department-field]');
            const departmentInput = card.querySelector('[data-sap-field="department"]');
            const shouldShowDepartment = companyInput && String(companyInput.value || '') === '@leadsagri.com';
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
            const orderedFields = ['name', 'position', 'immediate_head', 'department', 'company'];
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
                if (target.matches('[data-sap-field="company"]')) {
                    const card = target.closest('[data-sap-card]');
                    syncSapDepartmentVisibility(card);
                }
            });
            sapRequestList.addEventListener('input', function(event) {
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
            const shouldShowMarketingRequest = isLapcMarketingSelection();
            const shouldShowUrgency = shouldShow || shouldShowMarketingRequest;
            const selectedCategory = categorySelect ? String(categorySelect.value || '') : '';
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
            const shouldShowEmailRequest = isLapcItSelection() && selectedCategory === 'Email';
            const shouldShowEmailCreation = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'creation of email';
            const shouldShowEmailDefault = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === '';
            const shouldShowEmailForgotPassword = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'forgot password';
            const shouldShowEmailBackup = shouldShowEmailRequest && emailRequestTypeSelect && String(emailRequestTypeSelect.value || '') === 'backup of email';
            const shouldShowSapRequest = isLapcItSelection() && selectedCategory === 'SAP';
            const shouldRequireKamiAttachment = shouldShowConcernType;
            const shouldRequireMedicalAttachment = shouldShowMedicalCashAdvance;
            setUrgencyOptions(shouldShowMarketingRequest ? 'marketing' : 'hr');
            document.body.classList.toggle('kami-section-active', shouldShowConcernType);
            document.body.classList.toggle('other-section-active', shouldShowOtherDetailsStyle);
            document.body.classList.toggle('medical-cash-section-active', shouldShowMedicalCashAdvance);
            document.body.classList.toggle('training-request-section-active', shouldShowTrainingRequest);
            document.body.classList.toggle('company-property-section-active', shouldShowCompanyPropertyRequest);
            document.body.classList.toggle('coe-request-section-active', shouldShowCoeRequest);
            document.body.classList.toggle('col-request-section-active', shouldShowColRequest);
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
            if ((shouldShowEmailDefault || shouldShowEmailForgotPassword || shouldShowEmailBackup) && emailDescriptionHost) {
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
                descriptionContainer.style.display = (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowSapRequest || shouldShowEmailCreation) ? 'none' : '';
            }
            if (attachmentContainer) {
                attachmentContainer.style.display = (shouldShowSssBenefits || shouldShowSapRequest) ? 'none' : '';
            }
            const attachmentFieldInput = document.getElementById('attachments');
            const attachmentFieldButton = document.getElementById('choose-file-btn');
            if (attachmentFieldInput) {
                attachmentFieldInput.disabled = shouldShowSssBenefits;
            }
            if (attachmentFieldButton) {
                attachmentFieldButton.setAttribute('aria-disabled', shouldShowSssBenefits ? 'true' : 'false');
                attachmentFieldButton.tabIndex = shouldShowSssBenefits ? -1 : 0;
            }
            if (attachmentOptionalText) {
                attachmentOptionalText.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment) ? 'none' : '';
            }
            if (attachmentRequiredAsterisk) {
                attachmentRequiredAsterisk.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment) ? '' : 'none';
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
                }
            }
            if (descriptionField) {
                if (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowSapRequest || shouldShowEmailCreation) {
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
                    if (shouldShowSapRequest) input.setAttribute('required', 'required');
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

            if (attachmentOptionalText && shouldShowMarketingRequest) {
                attachmentOptionalText.style.display = 'none';
            }

            if (!shouldShowUrgency) {
                priorityHidden.value = '';
            }

            if (shouldShowMedicalCashAdvance && medicalCashAttachmentHost) {
                moveAttachmentContainer(medicalCashAttachmentHost);
            } else if (attachmentOriginalHost) {
                moveAttachmentContainer(attachmentOriginalHost);
            }

            syncUrgencyInputs();
            syncRequestGridRows();
        }
        if (recipientDropdown) {
            recipientDropdown.addEventListener('change', function() {
                toggleDepartment();
                toggleCategories();
                toggleHrExtraFields();
            });
        }
        if (departmentSelect) {
            departmentSelect.addEventListener('change', function() {
                departmentSelect.setAttribute('data-selected', String(departmentSelect.value || ''));
                syncDepartmentTriggerLabel();
                renderDepartmentDropdownOptions();
                toggleCategories();
                toggleHrExtraFields();
            });
        }
        if (departmentTrigger && departmentMenu && departmentWrapper) {
            departmentTrigger.addEventListener('click', function() {
                if (departmentTrigger.disabled) return;
                const shouldOpen = departmentMenu.hidden;
                closeDepartmentDropdown();
                if (!shouldOpen) return;
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
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                toggleHrExtraFields();
            });
        }
        if (urgencySelect) {
            urgencySelect.addEventListener('change', function() {
                if (!priorityHidden) return;
                priorityHidden.value = String(urgencySelect.value || 'Low');
                syncUrgencyInputs();
            });
        }
        if (concernTypeSelect) {
            const selectedConcernType = String(concernTypeSelect.getAttribute('data-selected') || '');
            if (selectedConcernType !== '') {
                concernTypeSelect.value = selectedConcernType;
            }
            concernTypeSelect.addEventListener('change', function() {
                toggleHrExtraFields();
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
                removeBtn.textContent = 'x';
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
                var isHrSssSelected = false;
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
                    isKamiAttachmentRequired =
                        isLapcHrSelected &&
                        selectedCategory === 'Attendance & Timekeeping';
                    isHrSssSelected =
                        isLapcHrSelected &&
                        selectedCategory === 'SSS Sickness and Benefit Concern';
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
                if (isLapcMarketingSelected) {
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
                        if (filledSapFields < 5) {
                            const departmentVisible = sapCards[sapIndex].querySelector('[data-sap-department-field]')?.classList.contains('is-visible');
                            if (!departmentVisible && filledSapFields === 4) {
                                hasCompleteSapEntry = true;
                                continue;
                            }
                            e.preventDefault();
                            setInlineFormError('Please complete each SAP employee report before submitting.');
                            const firstEmptySapInput = findFirstEmptySapInput(sapCards[sapIndex]);
                            if (firstEmptySapInput) {
                                firstEmptySapInput.focus();
                            }
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
                    const incompleteEmailCreationField = emailCreationInputs.find(function(input) {
                        return input && !String(input.value || '').trim();
                    });
                    if (incompleteEmailCreationField) {
                        e.preventDefault();
                        setInlineFormError('Please complete the Creation of email details.');
                        try { incompleteEmailCreationField.focus(); } catch (focusError) {}
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

        function showSuccessState() {
            if (!modal) return;
            clearLoadingTimers();
                    setModalState('success', 'Ticket Submitted Successfully', 'Your request has been sent.<br>Our team will get back to you soon.', '', 100);
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
                            showSuccessState();
                        }, waitMs);
                    } else {
                        showSuccessState();
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
    </script>
</body>
</html>


