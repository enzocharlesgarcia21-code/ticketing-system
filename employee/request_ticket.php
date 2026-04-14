<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

require_once '../includes/mailer.php';
require_once '../includes/csrf.php';
require_once '../includes/ticket_assignment.php';
require_once '../includes/notification_service.php';

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

    $uploadDir = request_ticket_upload_dir();
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
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Each ' . $label . ' file must be 10 MB or smaller.'];
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Unable to upload the ' . $label . ' file right now.'];
        }

        $fileName = trim((string) $originalName);
        $fileTmp = trim((string) ($tmpNames[$index] ?? ''));
        $fileSize = (int) ($sizes[$index] ?? 0);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileName === '' || !in_array($fileExt, $allowedTypes, true)) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Please upload only JPG, PNG, PDF, DOC, or DOCX files for ' . $label . '.'];
        }

        if ($fileSize <= 0 || $fileSize > $maxFileBytes) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
            return ['ok' => false, 'error' => 'Each ' . $label . ' file must be 10 MB or smaller.'];
        }

        if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
            $mime = (string) $finfo->file($fileTmp);
            $allowed = $allowedMimes[$fileExt] ?? [];
            if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                request_ticket_cleanup_uploaded_files($uploadedFiles);
                return ['ok' => false, 'error' => 'Please upload only JPG, PNG, PDF, DOC, or DOCX files for ' . $label . '.'];
            }
        }

        $newFileName = time() . '_' . uniqid('', true) . '.' . $fileExt;
        $uploadPath = $uploadDir . '/' . $newFileName;

        if (!move_uploaded_file($fileTmp, $uploadPath)) {
            request_ticket_cleanup_uploaded_files($uploadedFiles);
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
        'HR' => [
            'Attendance & Timekeeping',
            'Certificate of Employment',
            'Certificate of Leave',
            'Leave Concern',
            'Medical Cash Advance',
            'Request for Company Property',
            'SSS Sickness and Benefit Concern',
            'SAP',
            'Training Request',
            'Others',
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
    $sap_name = trim((string) ($_POST['sap_name'] ?? ''));
    $sap_position = trim((string) ($_POST['sap_position'] ?? ''));
    $sap_immediate_head = trim((string) ($_POST['sap_immediate_head'] ?? ''));
    $sap_department = trim((string) ($_POST['sap_department'] ?? ''));
    $sap_company = trim((string) ($_POST['sap_company'] ?? ''));
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
    $isLapcHrTicket = ($assigned_company === '@leadsagri.com' && $assigned_group === 'HR');
    $isHrAttendanceCategory = ($isLapcHrTicket && $category === 'Attendance & Timekeeping');
    $isHrLeaveOrOtherCategory = ($isLapcHrTicket && ($category === 'Leave Concern' || $category === 'Others'));
    $isHrSssCategory = ($isLapcHrTicket && $category === 'SSS Sickness and Benefit Concern');
    $isHrMedicalCashAdvance = ($isLapcHrTicket && $category === 'Medical Cash Advance');
    $isHrTrainingRequest = ($isLapcHrTicket && $category === 'Training Request');
    $isHrCompanyPropertyRequest = ($isLapcHrTicket && $category === 'Request for Company Property');
    $isHrCertificateEmploymentRequest = ($isLapcHrTicket && $category === 'Certificate of Employment');
    $isHrCertificateLeaveRequest = ($isLapcHrTicket && $category === 'Certificate of Leave');
    $isHrSapRequest = ($isLapcHrTicket && $category === 'SAP');
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
    } elseif ($priority === '') {
        $priority = 'Low';
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
    if ($isHrSapRequest) {
        if ($sap_name === '' || $sap_position === '' || $sap_immediate_head === '' || $sap_department === '' || $sap_company === '') {
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

        $subject = 'SAP';
        $description = "SAP Form\n"
            . "Name: " . $sap_name . "\n"
            . "Position: " . $sap_position . "\n"
            . "Immediate Head: " . $sap_immediate_head . "\n"
            . "Department: " . $sap_department . "\n"
            . "Company: " . $sap_company;
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
        $error_msg = '';
        $maxBytes = 5 * 1024 * 1024;
        $maxFiles = 5;
        $selectedFiles = 0;
        $totalBytes = 0;
        $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf', 'docx'];
        $allowedMimes = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
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
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Maximum 5 attachments allowed.'], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = 'Maximum 5 attachments allowed.';
            header("Location: request_ticket.php");
            exit();
        }
        for ($i = 0; $i < $count; $i++) {
            $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $error_msg = 'Attachment too large. Max 5MB total.';
                break;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $error_msg = 'Attachment upload failed. Please try again.';
                break;
            }

            $fileName = (string)($_FILES['attachments']['name'][$i] ?? '');
            $fileTmp = (string)($_FILES['attachments']['tmp_name'][$i] ?? '');
            $fileSize = (int)($_FILES['attachments']['size'][$i] ?? 0);
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedTypes, true)) {
                $error_msg = $unsupportedAttachmentMessage;
                break;
            }
            if ($fileSize <= 0 || $fileSize > $maxBytes) {
                $error_msg = 'Attachment too large. Max 5MB total.';
                break;
            }
            if (($totalBytes + $fileSize) > $maxBytes) {
                $error_msg = 'Attachment too large. Max 5MB total.';
                break;
            }
            if ($finfo && $fileTmp !== '' && is_file($fileTmp)) {
                $mime = (string) $finfo->file($fileTmp);
                $allowed = $allowedMimes[$fileExt] ?? [];
                if ($mime !== '' && count($allowed) > 0 && !in_array($mime, $allowed, true)) {
                    $error_msg = $unsupportedAttachmentMessage;
                    break;
                }
            }

            if (!is_dir("../uploads")) {
                mkdir("../uploads", 0777, true);
            }

            $newFileName = time() . "_" . uniqid() . "." . $fileExt;
            $uploadPath  = "../uploads/" . $newFileName;

            if (move_uploaded_file($fileTmp, $uploadPath)) {
                $movedPaths[] = $uploadPath;
                $totalBytes += $fileSize;
                $uploadedFiles[] = [
                    'stored_name' => $newFileName,
                    'original_name' => $fileName,
                    'stored_path' => $uploadPath,
                ];
                if ($attachmentName === NULL) {
                    $attachmentName = $newFileName;
                }
            } else {
                $error_msg = 'Failed to save attachment. Please try again.';
                break;
            }
        }
        if ($error_msg !== '') {
            foreach ($movedPaths as $p) {
                if (is_string($p) && $p !== '' && file_exists($p)) {
                    unlink($p);
                }
            }
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $error_msg], JSON_UNESCAPED_UNICODE);
                exit();
            }
            $_SESSION['error'] = $error_msg;
            header("Location: request_ticket.php");
            exit();
        }
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
    if ($isHrSapRequest) {
        $ticketMeta['sap_name'] = $sap_name;
        $ticketMeta['sap_position'] = $sap_position;
        $ticketMeta['sap_immediate_head'] = $sap_immediate_head;
        $ticketMeta['sap_department'] = $sap_department;
        $ticketMeta['sap_company'] = $sap_company;
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

    $adminEmails = [];
    $admins = $conn->query("SELECT email FROM users WHERE role = 'admin' AND email <> ''");
    if ($admins) {
        while ($admin = $admins->fetch_assoc()) {
            $adminEmails[] = $admin['email'];
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

    $adminOk = notif_email_send($adminEmails, $adminSubject, (string) $adminTpl['html'], (string) $adminTpl['text'], $attachments);
    if (!$adminOk) {
        error_log('Ticket email failed (admins) | ticketId=' . (string) $ticket_id);
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
            background: linear-gradient(135deg, #67c86f, #57b861);
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
            border: 1px dashed #d9e6db;
            border-radius: 16px;
            background: #ffffff;
            padding: 10px;
        }
        body.employee-request-ticket-page .attachment-dropzone {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 96px;
            border: 1px dashed #e3e8ef;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdfb 100%);
            text-align: center;
            cursor: pointer;
            transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
        }
        body.employee-request-ticket-page .attachment-dropzone:hover {
            border-color: #b7d8bf;
            background: #fcfffd;
            box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.06);
        }
        body.employee-request-ticket-page .attachment-dropzone.is-dragover {
            border-color: #67c86f;
            background: #f4fbf5;
            box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.12);
        }
        body.employee-request-ticket-page .attachment-dropzone-icon {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #17643a;
            background: #f0faf2;
            font-size: 18px;
        }
        body.employee-request-ticket-page .attachment-dropzone-copy {
            color: #0f172a;
            font-size: 13px;
            line-height: 1.45;
            font-weight: 500;
        }
        body.employee-request-ticket-page .attachment-hidden-button {
            display: none;
        }
        body.employee-request-ticket-page .attachment-file-name {
            margin-top: 10px;
            color: #64748b;
            font-size: 12px;
            text-align: center;
            word-break: break-word;
        }
        body.employee-request-ticket-page .attachment-help-text {
            display: block;
            margin-top: 10px;
            color: #64748b;
            font-size: 11px;
            text-align: center;
            line-height: 1.45;
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
            background: linear-gradient(135deg, #67c86f, #57b861);
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
        body.employee-request-ticket-page .medical-cash-head {
            margin: 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .training-request-head {
            margin: 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .company-property-head {
            margin: 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .coe-request-head {
            margin: 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .col-request-head {
            margin: 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .sap-request-head {
            margin: 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
            font-family: inherit;
        }
        body.employee-request-ticket-page .form-card {
            padding: 0 24px 24px;
            overflow: hidden;
        }
        body.employee-request-ticket-page .form-section-title {
            margin: 0 -24px 22px;
            padding: 18px 24px;
            background: linear-gradient(135deg, #67c86f, #57b861);
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
            gap: 14px;
            padding: 18px 24px 24px;
            background: transparent;
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
        body.employee-request-ticket-page .sap-request-card {
            border: 1px solid #dbe4ef;
            border-radius: 14px;
            background: #ffffff;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        body.employee-request-ticket-page .sap-request-card .form-group {
            margin: 0;
        }
        body.employee-request-ticket-page .sap-request-card label {
            display: block;
            margin-bottom: 10px;
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
            background: linear-gradient(135deg, #67c86f, #57b861);
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
                background: linear-gradient(180deg, #1f7a36 0%, #16602a 100%);
                color: #ffffff;
                border-radius: 14px 14px 0 0;
                box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.12);
                font-size: 16px;
            }

            body.employee-request-ticket-page .form-group {
                margin-bottom: 14px;
            }

            body.employee-request-ticket-page .request-grid-row {
                grid-template-columns: 1fr;
                gap: 0;
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

            <!-- 4️⃣ REQUEST TICKET PAGE – REDESIGN -->
            <div class="page-header" style="text-align: center; margin-bottom: 40px;">
                <h1 class="page-title">Create a Ticket</h1>
                <p class="page-subtitle">Please fill out the form below.</p>
            </div>

            <div class="form-card">
                <form id="ticketForm" method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="alert alert-error" id="ajaxError" style="background:#fee2e2;color:#991b1b;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid #fecaca;font-weight:700; display:none;"></div>
                    
                    <!-- 🔹 Request Information -->
                    <h3 class="form-section-title">Request Information</h3>

                    <div class="request-grid-row" id="recipientDepartmentRow">
                        <div class="form-group">
                            <label>Ticket Recipient <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper">
                                <select name="assigned_company" id="assigned_company" class="form-control" required>
                                    <option value="" disabled selected hidden>Choose recipient</option>
                                    <option value="@leads-farmex.com">FARMEX (@leads-farmex.com)</option>
                                    <option value="@farmasee.ph">FARMASEE (@farmasee.ph)</option>
                                    <option value="@gpsci.net">GPSCI (@gpsci.net)</option>
                                    <option value="@leadsagri.com">LAPC (@leadsagri.com)</option>
                                    <option value="@leadsav.com">LAV (@leadsav.com)</option>
                                    <option value="@leadstech-corp.com">LTC (@leadstech-corp.com)</option>
                                    <option value="@lingapleads.org">LINGAP (@lingapleads.org)</option>
                                    <option value="@malvedaholdings.com">MHC (@malvedaholdings.com)</option>
                                    <option value="@malvedaproperties.com">MPDC (@malvedaproperties.com)</option>
                                    <option value="@primestocks.ph">PCC (@primestocks.ph)</option>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="form-group" id="departmentContainer">
                            <label>Assigned Department <span class="required-asterisk">*</span></label>
                            <div class="select-wrapper">
                                <select name="assigned_group" id="assigned_group" class="form-control" required disabled data-selected="<?= htmlspecialchars((string) ($_POST['assigned_group'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <option value="" disabled selected hidden>Choose department</option>
                                </select>
                                <i class="fas fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>

                    <div class="request-grid-row" id="categoryUrgencyRow">
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

                    <section class="sap-request-group" id="sapRequestSection">
                        <h3 class="sap-request-head">SAP Form</h3>
                        <div class="sap-request-list">
                            <section class="sap-request-card">
                                <div class="form-group">
                                    <label for="sap_name">Name <span class="required-asterisk">*</span></label>
                                    <input type="text" name="sap_name" id="sap_name" class="form-control" value="<?= htmlspecialchars((string) ($_POST['sap_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <section class="sap-request-card">
                                <div class="form-group">
                                    <label for="sap_position">Position <span class="required-asterisk">*</span></label>
                                    <input type="text" name="sap_position" id="sap_position" class="form-control" value="<?= htmlspecialchars((string) ($_POST['sap_position'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <section class="sap-request-card">
                                <div class="form-group">
                                    <label for="sap_immediate_head">Immediate Head <span class="required-asterisk">*</span></label>
                                    <input type="text" name="sap_immediate_head" id="sap_immediate_head" class="form-control" value="<?= htmlspecialchars((string) ($_POST['sap_immediate_head'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <section class="sap-request-card">
                                <div class="form-group">
                                    <label for="sap_department">Department <span class="required-asterisk">*</span></label>
                                    <input type="text" name="sap_department" id="sap_department" class="form-control" value="<?= htmlspecialchars((string) ($_POST['sap_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
                                </div>
                            </section>
                            <section class="sap-request-card">
                                <div class="form-group">
                                    <label for="sap_company">Company <span class="required-asterisk">*</span></label>
                                    <input type="text" name="sap_company" id="sap_company" class="form-control" value="<?= htmlspecialchars((string) ($_POST['sap_company'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your answer">
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
                            <div class="form-group" id="descriptionContainer">
                                <label id="descriptionLabel">Description <span class="required-asterisk">*</span></label>
                                <textarea name="description" id="descriptionField" class="form-control" placeholder="Describe your issue in detail..." style="resize:none;" required></textarea>
                            </div>
                            <div id="attachmentOriginalHost"></div>
                            <div class="form-group" id="attachmentContainer">
                                <label><span id="attachmentLabelText">Attachment</span> <span id="attachmentOptionalText">(Optional)</span><span id="attachmentRequiredAsterisk" class="required-asterisk" style="display:none;">*</span></label>
                                <p class="medical-cash-card-copy" id="medicalCashAttachmentIntro" style="display:none;">Please upload any medical document relevant your request as attachment. Thank you.</p>
                                <div class="attachment-upload-shell file-control">
                                    <div class="attachment-dropzone" id="choose-file-btn" tabindex="0" role="button" aria-label="Drag and drop files or click to upload">
                                        <span class="attachment-dropzone-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                                        <div class="attachment-dropzone-copy">Drag &amp; drop files or click to upload</div>
                                    </div>
                                    <button type="button" class="attachment-hidden-button" aria-hidden="true" tabindex="-1">
                                        <span id="chooseFileBtnText">Choose File</span>
                                    </button>
                                    <div id="file-name" class="attachment-file-name file-name">No file chosen</div>
                                    <input type="file" name="attachments[]" id="attachments" class="file-hidden" multiple accept=".jpg,.jpeg,.png,.pdf,.docx" style="display:none;">
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

    <div id="successModal" class="ticket-modal" aria-hidden="true">
        <div class="ticket-modal-content" role="dialog" aria-modal="true" aria-labelledby="successModalTitle">
            <div class="ticket-modal-spinner" aria-hidden="true"></div>
            <div class="ticket-modal-icon success" id="ticketModalSuccessIcon">✓</div>
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
        const sapRequestSection = document.getElementById('sapRequestSection');
        const sapNameInput = document.getElementById('sap_name');
        const sapPositionInput = document.getElementById('sap_position');
        const sapImmediateHeadInput = document.getElementById('sap_immediate_head');
        const sapDepartmentInput = document.getElementById('sap_department');
        const sapCompanyInput = document.getElementById('sap_company');
        const otherRequestDetailsSection = document.getElementById('otherRequestDetailsSection');
        const otherDescriptionSection = document.getElementById('otherDescriptionSection');
        const requestSubjectLabel = document.getElementById('requestSubjectLabel');
        const descriptionLabel = document.getElementById('descriptionLabel');
        const sssBenefitsContainer = document.getElementById('sssBenefitsContainer');
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
            'HR' => [
                'Attendance & Timekeeping',
                'Certificate of Employment',
                'Certificate of Leave',
                'Leave Concern',
                'Medical Cash Advance',
                'Request for Company Property',
                'SSS Sickness and Benefit Concern',
                'SAP',
                'Training Request',
                'Others',
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
        }
        function toggleDepartment() {
            if (!recipientDropdown || !departmentContainer || !departmentSelect) return;
            const value = String(recipientDropdown.value || '');
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
        function syncRequestGridRows() {
            if (recipientDepartmentRow && departmentContainer) {
                recipientDepartmentRow.classList.toggle('is-single', departmentContainer.style.display === 'none');
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
        function moveAttachmentContainer(targetHost) {
            if (!attachmentContainer || !targetHost) return;
            if (attachmentContainer.parentNode !== targetHost) {
                targetHost.appendChild(attachmentContainer);
            }
        }
        function syncAttachmentCopy(mode) {
            const modeKey = String(mode || 'default');
            if (attachmentLabelText) {
                attachmentLabelText.textContent = (modeKey === 'kami' || modeKey === 'medical') ? 'Supporting Information' : 'Attachment';
            }
            if (attachmentHelpText) {
                attachmentHelpText.textContent = modeKey === 'kami'
                    ? 'Upload up to 5 supported files. Max 10 MB per file.'
                    : (modeKey === 'medical'
                        ? 'Please upload any medical document relevant to your request. Supported formats: JPG, PNG, PDF, DOCX (Max 5 files).'
                        : 'Supported formats: JPG, PNG, PDF, DOCX (Max 5 files)');
            }
            if (medicalCashAttachmentIntro) {
                medicalCashAttachmentIntro.style.display = modeKey === 'medical' ? 'block' : 'none';
            }
            if (chooseFileBtnText) {
                chooseFileBtnText.textContent = (modeKey === 'kami' || modeKey === 'medical') ? 'Add file' : 'Choose File';
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
                const empty = document.createElement('span');
                empty.className = 'sss-benefits-file-empty';
                empty.textContent = 'No file chosen';
                list.appendChild(empty);
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
            const previewWindow = window.open(previewUrl, '_blank');
            if (!previewWindow) {
                window.location.href = previewUrl;
            }
            window.setTimeout(function() {
                try { URL.revokeObjectURL(previewUrl); } catch (e) {}
            }, 60000);
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
        function toggleHrExtraFields() {
            if (!urgencyContainer || !priorityHidden) return;
            const shouldShow = isLapcHrSelection();
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
            const shouldShowSapRequest = shouldShow && selectedCategory === 'SAP';
            const shouldRequireKamiAttachment = shouldShowConcernType;
            const shouldRequireMedicalAttachment = shouldShowMedicalCashAdvance;
            document.body.classList.toggle('kami-section-active', shouldShowConcernType);
            document.body.classList.toggle('other-section-active', shouldShowOtherDetailsStyle);
            document.body.classList.toggle('medical-cash-section-active', shouldShowMedicalCashAdvance);
            document.body.classList.toggle('training-request-section-active', shouldShowTrainingRequest);
            document.body.classList.toggle('company-property-section-active', shouldShowCompanyPropertyRequest);
            document.body.classList.toggle('coe-request-section-active', shouldShowCoeRequest);
            document.body.classList.toggle('col-request-section-active', shouldShowColRequest);
            document.body.classList.toggle('sap-request-section-active', shouldShowSapRequest);
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
            if (sapRequestSection) {
                sapRequestSection.classList.toggle('is-visible', shouldShowSapRequest);
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
            if (requestSubjectLabel) {
                requestSubjectLabel.innerHTML = 'Subject/Title of Request <span class="required-asterisk">*</span>';
            }
            if (descriptionLabel) {
                descriptionLabel.innerHTML = shouldShowOtherDetailsStyle
                    ? 'Detailed Description of Request or Concern <span class="required-asterisk">*</span>'
                    : 'Description <span class="required-asterisk">*</span>';
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
                descriptionContainer.style.display = (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowSapRequest) ? 'none' : '';
            }
            if (attachmentContainer) {
                attachmentContainer.style.display = shouldShowSssBenefits ? 'none' : '';
            }
            const attachmentFieldInput = document.getElementById('attachments');
            const attachmentFieldButton = document.getElementById('choose-file-btn');
            if (attachmentFieldInput) {
                attachmentFieldInput.disabled = shouldShowSssBenefits;
            }
            if (attachmentFieldButton) {
                attachmentFieldButton.disabled = shouldShowSssBenefits;
            }
            if (attachmentOptionalText) {
                attachmentOptionalText.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment) ? 'none' : '';
            }
            if (attachmentRequiredAsterisk) {
                attachmentRequiredAsterisk.style.display = (shouldRequireKamiAttachment || shouldRequireMedicalAttachment) ? '' : 'none';
            }
            syncAttachmentCopy(shouldShowMedicalCashAdvance ? 'medical' : (shouldRequireKamiAttachment ? 'kami' : 'default'));
            urgencyContainer.classList.toggle('is-visible', shouldShow);
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
                if (shouldShow) {
                    urgencySelect.setAttribute('required', 'required');
                } else {
                    urgencySelect.removeAttribute('required');
                }
            }
            if (descriptionField) {
                if (shouldShowSssBenefits || shouldShowMedicalCashAdvance || shouldShowTrainingRequest || shouldShowCompanyPropertyRequest || shouldShowCoeRequest || shouldShowColRequest || shouldShowSapRequest) {
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
            [sapNameInput, sapPositionInput, sapImmediateHeadInput, sapDepartmentInput, sapCompanyInput].forEach(function(input) {
                if (!input) return;
                if (shouldShowSapRequest) input.setAttribute('required', 'required');
                else input.removeAttribute('required');
            });
            if (coeRequestReasonOtherInput) {
                const otherSelected = coeRequestReasonInputs.some(function(input) {
                    return input.checked && input.value === 'Other';
                });
                if (shouldShowCoeRequest && otherSelected) coeRequestReasonOtherInput.setAttribute('required', 'required');
                else coeRequestReasonOtherInput.removeAttribute('required');
            }

            if (!shouldShow) {
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
                toggleCategories();
                toggleHrExtraFields();
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
        var attachmentInput = document.getElementById('attachments');
        var chooseBtn = document.getElementById('choose-file-btn');
        var fileNameEl = document.getElementById('file-name');
        var preview = document.getElementById('attachment-preview');
        var errorEl = document.getElementById('attachment-error');
        var toastEl = document.getElementById('attachment-toast');
        var dt = new DataTransfer();
        var objectUrls = [];
        var MAX_BYTES = 5 * 1024 * 1024;
        var MAX_FILES = 5;
        var ALLOWED_EXT = ['jpg','jpeg','png','pdf','docx'];
        var SSS_ALLOWED_EXT = ['jpg','jpeg','png','pdf','doc','docx'];
        var SSS_MAX_FILE_BYTES = 10 * 1024 * 1024;
        var UNSUPPORTED_FILE_MESSAGE = 'Please insert supported files only.';
        var toastTimer = null;

        if (chooseBtn) {
            chooseBtn.addEventListener('click', function () {
                if (attachmentInput) attachmentInput.click();
            });
            ['dragenter', 'dragover'].forEach(function(eventName) {
                chooseBtn.addEventListener(eventName, function () {
                    chooseBtn.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function(eventName) {
                chooseBtn.addEventListener(eventName, function () {
                    chooseBtn.classList.remove('is-dragover');
                });
            });
        }

        function clearObjectUrls() {
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

        function syncFiles() {
            if (!attachmentInput) return;
            attachmentInput.files = dt.files;
            if (fileNameEl) {
                var n = dt.files.length;
                var isKamiMode = !!(attachmentLabelText && attachmentLabelText.textContent === 'Supporting Information');
                fileNameEl.textContent = n === 0
                    ? (isKamiMode ? '' : 'No file chosen')
                    : (n === 1 ? dt.files[0].name : (n + ' files selected'));
            }
            if (!preview) return;
            clearObjectUrls();
            preview.innerHTML = '';
            Array.from(dt.files).forEach(function (file, idx) {
                var url = URL.createObjectURL(file);
                objectUrls.push(url);

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

                var left = document.createElement('a');
                left.href = url;
                left.target = '_blank';
                left.rel = 'noopener';
                left.style.display = 'flex';
                left.style.alignItems = 'center';
                left.style.gap = '10px';
                left.style.minWidth = '0';
                left.style.flex = '1 1 auto';
                left.style.textDecoration = 'none';
                left.style.cursor = 'pointer';

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
                removeBtn.textContent = '×';
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

        if (attachmentInput) {
            attachmentInput.addEventListener('change', function (e) {
                var selectedFiles = Array.from(e.target.files || []);
                var blockedMax = false;
                var hasUnsupportedType = false;
                var validFiles = [];

                selectedFiles.forEach(function (file) {
                    var ext = getExt(file && file.name);
                    if (ALLOWED_EXT.indexOf(ext) === -1) {
                        hasUnsupportedType = true;
                        return;
                    }
                    validFiles.push(file);
                });

                if (hasUnsupportedType) {
                    attachmentInput.value = '';
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
                var isHrSssSelected = false;
                var selectedCategory = '';
                if (recipientDropdown && departmentSelect && categorySelect) {
                    selectedCategory = String(categorySelect.value || '');
                    isLapcHrSelected =
                        String(recipientDropdown.value || '') === '@leadsagri.com' &&
                        String(departmentSelect.value || '') === 'HR';
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
                if (isLapcHrSelected && selectedCategory === 'SAP') {
                    if (sapNameInput && !String(sapNameInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the SAP form.');
                        return;
                    }
                    if (sapPositionInput && !String(sapPositionInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the SAP form.');
                        return;
                    }
                    if (sapImmediateHeadInput && !String(sapImmediateHeadInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the SAP form.');
                        return;
                    }
                    if (sapDepartmentInput && !String(sapDepartmentInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the SAP form.');
                        return;
                    }
                    if (sapCompanyInput && !String(sapCompanyInput.value || '').trim()) {
                        e.preventDefault();
                        setInlineFormError('Please complete the SAP form.');
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
