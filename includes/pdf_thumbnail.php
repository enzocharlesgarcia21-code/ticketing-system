<?php

function ticket_pdf_uploads_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
}

function ticket_pdf_thumbnail_dir(): string
{
    return ticket_pdf_uploads_dir() . DIRECTORY_SEPARATOR . 'thumbnails';
}

function ticket_pdf_safe_stored_name(string $storedName): bool
{
    $storedName = trim($storedName);
    return $storedName !== ''
        && basename($storedName) === $storedName
        && preg_match('/^[A-Za-z0-9._-]+\.pdf$/i', $storedName) === 1;
}

function ticket_pdf_normalize_stored_name(string $storedName): string
{
    return basename(str_replace('\\', '/', trim($storedName)));
}

function ticket_pdf_sanitize_original_name(string $name): string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name);
    $name = preg_replace('/[^A-Za-z0-9 ._()\\[\\]-]+/', '_', (string) $name);
    $name = preg_replace('/\s+/', ' ', (string) $name);
    return trim((string) $name);
}

function ticket_pdf_thumbnail_file_name(string $storedName): string
{
    return sha1(strtolower(trim($storedName))) . '.jpg';
}

function ticket_pdf_thumbnail_path(string $storedName): string
{
    return ticket_pdf_thumbnail_dir() . DIRECTORY_SEPARATOR . ticket_pdf_thumbnail_file_name($storedName);
}

function ticket_pdf_thumbnail_relative_path(string $storedName): string
{
    return 'uploads/thumbnails/' . ticket_pdf_thumbnail_file_name($storedName);
}

function ticket_pdf_is_valid_pdf_file(string $path): bool
{
    if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
        return false;
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        if ($mime !== '' && !in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            return false;
        }
    }

    return true;
}

function ticket_pdf_ensure_upload_guards(): void
{
    $rules = "Options -Indexes\n"
        . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|asp|aspx|jsp)$\">\n"
        . "    Require all denied\n"
        . "</FilesMatch>\n";

    $uploadDir = ticket_pdf_uploads_dir();
    if (is_dir($uploadDir) && !is_file($uploadDir . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents($uploadDir . DIRECTORY_SEPARATOR . '.htaccess', $rules);
    }

    $thumbnailDir = ticket_pdf_thumbnail_dir();
    if (!is_dir($thumbnailDir)) {
        @mkdir($thumbnailDir, 0777, true);
    }
    if (is_dir($thumbnailDir) && !is_file($thumbnailDir . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents($thumbnailDir . DIRECTORY_SEPARATOR . '.htaccess', $rules);
    }
}

function ticket_pdf_generate_thumbnail(string $storedName): array
{
    $storedName = ticket_pdf_normalize_stored_name($storedName);
    if (!ticket_pdf_safe_stored_name($storedName)) {
        return ['ok' => false, 'reason' => 'invalid_pdf_name'];
    }

    $pdfPath = ticket_pdf_uploads_dir() . DIRECTORY_SEPARATOR . $storedName;
    if (!ticket_pdf_is_valid_pdf_file($pdfPath)) {
        return ['ok' => false, 'reason' => 'invalid_pdf_file'];
    }

    ticket_pdf_ensure_upload_guards();
    $thumbnailPath = ticket_pdf_thumbnail_path($storedName);
    if (is_file($thumbnailPath)) {
        return [
            'ok' => true,
            'thumbnail_path' => $thumbnailPath,
            'thumbnail_relative_path' => ticket_pdf_thumbnail_relative_path($storedName),
            'reused' => true,
        ];
    }

    if (!class_exists('Imagick')) {
        return ['ok' => false, 'reason' => 'imagick_missing'];
    }

    try {
        $pdf = new Imagick();
        $pdf->setResolution(160, 160);
        $pdf->readImage($pdfPath . '[0]');
        $pdf->setImageBackgroundColor('white');
        $pdf = $pdf->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $pdf->setImageFormat('jpeg');
        $pdf->setImageCompressionQuality(84);
        $pdf->thumbnailImage(420, 560, true, true);
        $pdf->writeImage($thumbnailPath);
        $pdf->clear();
        $pdf->destroy();

        return [
            'ok' => is_file($thumbnailPath),
            'thumbnail_path' => $thumbnailPath,
            'thumbnail_relative_path' => ticket_pdf_thumbnail_relative_path($storedName),
            'reused' => false,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'thumbnail_failed'];
    }
}

function ticket_pdf_attachment_meta(string $storedName, string $urlPrefix = '../'): array
{
    $storedName = ticket_pdf_normalize_stored_name($storedName);
    $isPdf = ticket_pdf_safe_stored_name($storedName);
    $relativePath = $isPdf ? ticket_pdf_thumbnail_relative_path($storedName) : '';
    $thumbnailPath = $isPdf ? ticket_pdf_thumbnail_path($storedName) : '';

    return [
        'is_pdf' => $isPdf,
        'thumbnail_available' => $isPdf && is_file($thumbnailPath),
        'thumbnail_url' => ($isPdf && is_file($thumbnailPath)) ? $urlPrefix . $relativePath : '',
    ];
}
