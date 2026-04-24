<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/env.php';

function normalizeSmtpConfigValue(string $value): string
{
    $value = trim($value);
    $len = strlen($value);
    if ($len >= 2) {
        $first = $value[0];
        $last = $value[$len - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
    }
    return trim($value);
}

function readSmtpConfigValue(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            $value = (string) $_SERVER[$key];
        } elseif (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            $value = (string) $_ENV[$key];
        } elseif (defined($key) && (string) constant($key) !== '') {
            $value = (string) constant($key);
        } else {
            $value = '';
        }
    }

    return normalizeSmtpConfigValue((string) $value);
}

function smtp_candidate_configs(string $host, string $portRaw, string $secureRaw): array
{
    $candidates = [];
    if ($host !== '') {
        $port = 587;
        if ($portRaw !== '' && ctype_digit($portRaw)) {
            $port = (int) $portRaw;
        } elseif ($secureRaw === 'ssl' || $secureRaw === 'smtps') {
            $port = 465;
        }
        $secure = ($secureRaw === 'ssl' || $secureRaw === 'smtps')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $candidates[] = ['host' => $host, 'port' => $port, 'secure' => $secure];
        return $candidates;
    }

    $candidates[] = ['host' => 'smtp.gmail.com', 'port' => 587, 'secure' => PHPMailer::ENCRYPTION_STARTTLS];
    $candidates[] = ['host' => 'smtp.gmail.com', 'port' => 465, 'secure' => PHPMailer::ENCRYPTION_SMTPS];
    return $candidates;
}

function buildSmtpMailer(array $candidate = []): PHPMailer
{
    $username = readSmtpConfigValue('SMTP_USERNAME');
    $password = readSmtpConfigValue('SMTP_PASSWORD');
    $fromEmail = readSmtpConfigValue('SMTP_FROM_EMAIL');
    $fromName = readSmtpConfigValue('SMTP_FROM_NAME');
    $host = readSmtpConfigValue('SMTP_HOST');
    $portRaw = readSmtpConfigValue('SMTP_PORT');
    $secureRaw = strtolower(readSmtpConfigValue('SMTP_SECURE'));

    if ($username === '') {
        $username = readSmtpConfigValue('GMAIL_USERNAME');
    }
    if ($password === '') {
        $password = readSmtpConfigValue('GMAIL_APP_PASSWORD');
    }
    if ($fromEmail === '') {
        $fromEmail = readSmtpConfigValue('GMAIL_FROM_EMAIL');
    }

    if ($fromEmail === '') {
        $fromEmail = $username;
    }
    if ($fromName === '') {
        $fromName = 'Leads Agri Helpdesk';
    }

    if ($username === '' || $password === '' || $fromEmail === '') {
        throw new Exception('SMTP is not configured (SMTP_USERNAME/SMTP_PASSWORD/SMTP_FROM_EMAIL).');
    }

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = (string) ($candidate['host'] ?? ($host !== '' ? $host : 'smtp.gmail.com'));
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = (string) ($candidate['secure'] ?? (($secureRaw === 'ssl' || $secureRaw === 'smtps') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS));
    $mail->Port = (int) ($candidate['port'] ?? (($portRaw !== '' && ctype_digit($portRaw)) ? (int) $portRaw : (($secureRaw === 'ssl' || $secureRaw === 'smtps') ? 465 : 587)));
    $mail->Timeout = 15;
    $mail->SMTPAutoTLS = true;
    $insecureTls = readSmtpConfigValue('SMTP_INSECURE_TLS');
    if ($insecureTls === '1' || strtolower($insecureTls) === 'true') {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
    }
    $mail->setFrom($fromEmail, $fromName);

    return $mail;
}

function smtp_extract_ticket_id_from_subject(string $subject): int
{
    if (preg_match('/\(#0*(\d+)\)/', $subject, $m)) {
        return (int) $m[1];
    }
    return 0;
}

function smtp_ticket_thread_subject(int $ticketId): string
{
    if ($ticketId <= 0) return '';
    return 'Ticket #' . str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
}

function smtp_message_id_domain(): string
{
    $fromEmail = readSmtpConfigValue('SMTP_FROM_EMAIL');
    if ($fromEmail === '') {
        $fromEmail = readSmtpConfigValue('GMAIL_FROM_EMAIL');
    }
    if ($fromEmail === '') {
        $fromEmail = readSmtpConfigValue('SMTP_USERNAME');
    }
    $domain = '';
    if (strpos($fromEmail, '@') !== false) {
        $domain = substr(strrchr($fromEmail, '@'), 1);
    }
    $domain = strtolower(trim((string) $domain));
    return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : 'leadsagri-helpdesk.local';
}

function smtp_generate_message_id(int $ticketId): string
{
    $suffix = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : str_replace('.', '', uniqid('', true));
    return '<ticket-' . $ticketId . '-' . $suffix . '@' . smtp_message_id_domain() . '>';
}

function smtp_ensure_ticket_thread_columns(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'root_message_id' => "VARCHAR(255) NULL",
        'last_message_id' => "VARCHAR(255) NULL",
        'thread_subject' => "VARCHAR(255) NULL",
    ];
    $existing = [];
    $res = $conn->query("SHOW COLUMNS FROM employee_tickets");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (isset($row['Field'])) {
                $existing[(string) $row['Field']] = true;
            }
        }
        $res->free();
    }
    foreach ($cols as $col => $ddl) {
        if (!isset($existing[$col])) {
            $conn->query("ALTER TABLE employee_tickets ADD COLUMN $col $ddl");
        }
    }
}

function smtp_prepare_ticket_threading(int $ticketId, string $subject): ?array
{
    if ($ticketId <= 0) return null;

    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return null;
    }

    smtp_ensure_ticket_thread_columns($conn);

    $rootMessageId = '';
    $lastMessageId = '';
    $threadSubject = '';
    $stmt = $conn->prepare("SELECT root_message_id, last_message_id, thread_subject FROM employee_tickets WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $ticketId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) {
            $rootMessageId = trim((string) ($row['root_message_id'] ?? ''));
            $lastMessageId = trim((string) ($row['last_message_id'] ?? ''));
            $threadSubject = trim((string) ($row['thread_subject'] ?? ''));
        }
    }

    $isRoot = false;
    $normalizedThreadSubject = smtp_ticket_thread_subject($ticketId);
    if ($normalizedThreadSubject === '') {
        $normalizedThreadSubject = $subject;
    }

    if ($rootMessageId === '') {
        $rootMessageId = smtp_generate_message_id($ticketId);
        $threadSubject = $normalizedThreadSubject;
        $isRoot = true;
        $update = $conn->prepare("UPDATE employee_tickets SET root_message_id = ?, last_message_id = ?, thread_subject = ? WHERE id = ?");
        if ($update) {
            $update->bind_param("sssi", $rootMessageId, $rootMessageId, $threadSubject, $ticketId);
            $update->execute();
            $update->close();
        }
    }

    if ($threadSubject !== $normalizedThreadSubject) {
        $threadSubject = $normalizedThreadSubject;
        $update = $conn->prepare("UPDATE employee_tickets SET thread_subject = ? WHERE id = ?");
        if ($update) {
            $update->bind_param("si", $threadSubject, $ticketId);
            $update->execute();
            $update->close();
        }
    }

    return [
        'ticket_id' => $ticketId,
        'subject' => $threadSubject,
        'message_id' => $isRoot ? $rootMessageId : smtp_generate_message_id($ticketId),
        'root_message_id' => $rootMessageId,
        'last_message_id' => $lastMessageId !== '' ? $lastMessageId : $rootMessageId,
        'is_root' => $isRoot,
    ];
}

function smtp_record_ticket_thread_message(int $ticketId, string $messageId): void
{
    if ($ticketId <= 0 || trim($messageId) === '') return;

    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return;
    }

    smtp_ensure_ticket_thread_columns($conn);
    $stmt = $conn->prepare("UPDATE employee_tickets SET last_message_id = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("si", $messageId, $ticketId);
        $stmt->execute();
        $stmt->close();
    }
}

function sendSmtpEmail(array $toEmails, string $subject, string $htmlBody, string $textBody = '', array $attachments = [], array $options = []): bool
{
    $toEmails = array_values(array_unique(array_filter(array_map('trim', $toEmails), static function ($v) {
        return $v !== '';
    })));

    if (count($toEmails) === 0) {
        error_log('Email skipped: no recipients | subject=' . $subject . ' | uri=' . (string) ($_SERVER['REQUEST_URI'] ?? ''));
        return false;
    }

    try {
        $ticketId = isset($options['ticket_id']) ? (int) $options['ticket_id'] : smtp_extract_ticket_id_from_subject($subject);
        $threading = smtp_prepare_ticket_threading($ticketId, $subject);
        if ($threading && !empty($threading['subject'])) {
            $subject = (string) $threading['subject'];
        }

        $host = readSmtpConfigValue('SMTP_HOST');
        $portRaw = readSmtpConfigValue('SMTP_PORT');
        $secureRaw = strtolower(readSmtpConfigValue('SMTP_SECURE'));
        $errors = [];

        foreach (smtp_candidate_configs($host, $portRaw, $secureRaw) as $candidate) {
            try {
                $mail = buildSmtpMailer($candidate);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                if ($threading) {
                    $mail->MessageID = (string) $threading['message_id'];
                    if (empty($threading['is_root'])) {
                        $rootMessageId = (string) $threading['root_message_id'];
                        $replyToMessageId = trim((string) ($threading['last_message_id'] ?? ''));
                        if ($replyToMessageId === '') {
                            $replyToMessageId = $rootMessageId;
                        }
                        $mail->addCustomHeader('In-Reply-To', $replyToMessageId);
                        $references = $rootMessageId;
                        if ($replyToMessageId !== '' && $replyToMessageId !== $rootMessageId) {
                            $references .= ' ' . $replyToMessageId;
                        }
                        $mail->addCustomHeader('References', trim($references));
                    }
                }
                if ($textBody !== '') {
                    $mail->AltBody = $textBody;
                }

                foreach ($attachments as $att) {
                    if (!is_array($att) || !isset($att['path'])) {
                        continue;
                    }
                    $path = (string) $att['path'];
                    $name = isset($att['name']) ? (string) $att['name'] : '';
                    if ($path === '') {
                        continue;
                    }
                    if ($name !== '') {
                        $mail->addAttachment($path, $name);
                    } else {
                        $mail->addAttachment($path);
                    }
                }

                $mail->addAddress($toEmails[0]);
                for ($i = 1; $i < count($toEmails); $i++) {
                    $mail->addBCC($toEmails[$i]);
                }

                $mail->send();
                if ($threading) {
                    smtp_record_ticket_thread_message((int) $threading['ticket_id'], (string) $threading['message_id']);
                }
                return true;
            } catch (\Throwable $attemptError) {
                $errors[] = ($candidate['host'] ?? 'smtp') . ':' . (string) ($candidate['port'] ?? '') . ' ' . $attemptError->getMessage();
            }
        }

        throw new Exception(implode(' | ', $errors));
    } catch (\Throwable $e) {
        error_log('Email send failed: ' . $e->getMessage() . ' | subject=' . $subject . ' | toCount=' . count($toEmails) . ' | uri=' . (string) ($_SERVER['REQUEST_URI'] ?? ''));
        return false;
    }
}
