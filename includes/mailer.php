<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

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

    return trim((string) $value);
}

function buildSmtpMailer(): PHPMailer
{
    $username = readSmtpConfigValue('SMTP_USERNAME');
    $password = readSmtpConfigValue('SMTP_PASSWORD');
    $fromEmail = readSmtpConfigValue('SMTP_FROM_EMAIL');
    $fromName = readSmtpConfigValue('SMTP_FROM_NAME');

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
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    $mail->setFrom($fromEmail, $fromName);

    return $mail;
}

function sendSmtpEmail(array $toEmails, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): bool
{
    $toEmails = array_values(array_unique(array_filter(array_map('trim', $toEmails), static function ($v) {
        return $v !== '';
    })));

    if (count($toEmails) === 0) {
        return false;
    }

    try {
        $mail = buildSmtpMailer();
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
    } catch (\Throwable $e) {
        error_log('Email send failed: ' . $e->getMessage());
        return false;
    }
}
