<?php

if (!function_exists('kb_normalize_relative_path')) {
    function kb_normalize_relative_path($path) {
        $normalized = trim((string) $path);
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = preg_replace('#^(\./)+#', '', $normalized);
        $normalized = preg_replace('#^(\.\./)+#', '', $normalized);
        $normalized = ltrim($normalized, '/');

        return $normalized;
    }
}

if (!function_exists('kb_absolute_path')) {
    function kb_absolute_path($relative_path) {
        $normalized = kb_normalize_relative_path($relative_path);
        if ($normalized === '') {
            return '';
        }

        return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    }
}

if (!function_exists('kb_upload_error_message')) {
    function kb_upload_error_message($error_code) {
        switch ((int) $error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The selected image is too large.';
            case UPLOAD_ERR_PARTIAL:
                return 'The image upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return '';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'The server is missing a temporary upload folder.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not save the uploaded image.';
            case UPLOAD_ERR_EXTENSION:
                return 'The upload was blocked by a server extension.';
            default:
                return 'Unable to upload the selected image right now.';
        }
    }
}

if (!function_exists('kb_store_uploaded_image')) {
    function kb_store_uploaded_image($file, &$error_message = '') {
        $error_message = '';

        if (!is_array($file) || empty($file['name'])) {
            return null;
        }

        $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($upload_error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($upload_error !== UPLOAD_ERR_OK) {
            $error_message = kb_upload_error_message($upload_error);
            return false;
        }

        $original_name = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($extension, $allowed_extensions, true)) {
            $error_message = 'Only JPG, JPEG, PNG, GIF, and WEBP images are allowed.';
            return false;
        }

        $upload_dir_relative = 'uploads/kb_images';
        $upload_dir_absolute = kb_absolute_path($upload_dir_relative);

        if ($upload_dir_absolute === '') {
            $error_message = 'The upload folder could not be prepared.';
            return false;
        }

        if (!is_dir($upload_dir_absolute) && !mkdir($upload_dir_absolute, 0777, true)) {
            $error_message = 'The upload folder could not be created.';
            return false;
        }

        $new_filename = uniqid('kb_', true) . '.' . $extension;
        $target_absolute = $upload_dir_absolute . DIRECTORY_SEPARATOR . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $target_absolute)) {
            $error_message = 'The uploaded image could not be saved.';
            return false;
        }

        return $upload_dir_relative . '/' . $new_filename;
    }
}

if (!function_exists('kb_delete_uploaded_file')) {
    function kb_delete_uploaded_file($relative_path) {
        $absolute_path = kb_absolute_path($relative_path);
        if ($absolute_path !== '' && is_file($absolute_path)) {
            @unlink($absolute_path);
        }
    }
}

if (!function_exists('kb_resolve_asset_url')) {
    function kb_resolve_asset_url($relative_path, $prefix = '../') {
        $normalized = kb_normalize_relative_path($relative_path);
        if ($normalized === '') {
            return null;
        }

        $candidates = [$normalized];

        if (stripos($normalized, 'uploads/') !== 0) {
            $candidates[] = 'uploads/kb_images/' . ltrim($normalized, '/');
        }

        foreach ($candidates as $candidate) {
            if (is_file(kb_absolute_path($candidate))) {
                return $prefix . $candidate;
            }
        }

        return $prefix . $normalized;
    }
}
