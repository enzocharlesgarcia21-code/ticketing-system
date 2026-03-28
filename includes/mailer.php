<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

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

function sendSmtpEmail(array $toEmails, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): bool
{
    $toEmails = array_values(array_unique(array_filter(array_map('trim', $toEmails), static function ($v) {
        return $v !== '';
    })));

    if (count($toEmails) === 0) {
        error_log('Email skipped: no recipients | subject=' . $subject . ' | uri=' . (string) ($_SERVER['REQUEST_URI'] ?? ''));
        return false;
    }

    try {
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
