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

if (!function_exists('kb_ensure_image_path_column_supports_multiple')) {
    function kb_ensure_image_path_column_supports_multiple($conn) {
        if (!$conn || !($conn instanceof mysqli)) {
            return;
        }

        $result = $conn->query("SHOW COLUMNS FROM knowledge_base LIKE 'image_path'");
        if (!$result || $result->num_rows === 0) {
            return;
        }

        $column = $result->fetch_assoc();
        $type = strtolower((string) ($column['Type'] ?? ''));
        if ($type === '') {
            return;
        }

        if (strpos($type, 'text') !== false || strpos($type, 'blob') !== false) {
            return;
        }

        $conn->query("ALTER TABLE knowledge_base MODIFY COLUMN image_path TEXT NULL");
    }
}

if (!function_exists('kb_ensure_article_views_table')) {
    function kb_ensure_article_views_table($conn) {
        if (!$conn || !($conn instanceof mysqli)) {
            return;
        }

        $conn->query("
            CREATE TABLE IF NOT EXISTS kb_article_views (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                article_id INT NOT NULL,
                viewer_key VARCHAR(190) NOT NULL,
                viewer_role VARCHAR(32) NOT NULL DEFAULT 'guest',
                user_id INT NULL,
                viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_article_viewer (article_id, viewer_key),
                UNIQUE KEY uniq_article_user (article_id, user_id),
                KEY idx_article_id (article_id),
                KEY idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('kb_current_registered_viewer_id')) {
    function kb_current_registered_viewer_id() {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $role = trim((string) ($_SESSION['role'] ?? ''));
        if ($userId <= 0 || $role !== 'employee') {
            return 0;
        }

        return $userId;
    }
}

if (!function_exists('kb_unique_views_count_sql')) {
    function kb_unique_views_count_sql(string $articleIdExpr = 'knowledge_base.id'): string
    {
        $articleIdExpr = trim($articleIdExpr);
        if ($articleIdExpr === '') {
            $articleIdExpr = 'knowledge_base.id';
        }

        return "(SELECT COUNT(DISTINCT v.user_id)
            FROM kb_article_views v
            WHERE v.article_id = {$articleIdExpr}
              AND v.user_id IS NOT NULL
              AND v.viewer_role = 'employee')";
    }
}

if (!function_exists('kb_register_article_view')) {
    function kb_register_article_view($conn, $article_id) {
        if (!$conn || !($conn instanceof mysqli)) {
            return false;
        }

        $article_id = (int) $article_id;
        if ($article_id <= 0) {
            return false;
        }

        kb_ensure_article_views_table($conn);

        $user_id = kb_current_registered_viewer_id();
        if ($user_id <= 0) {
            return false;
        }

        $viewer_key = 'user:' . $user_id;
        $viewer_role = 'employee';

        $insert = $conn->prepare("INSERT IGNORE INTO kb_article_views (article_id, viewer_key, viewer_role, user_id) VALUES (?, ?, ?, ?)");
        if (!$insert) {
            return false;
        }

        $insert->bind_param("issi", $article_id, $viewer_key, $viewer_role, $user_id);
        if (!$insert->execute()) {
            $insert->close();
            return false;
        }

        $is_new_view = $insert->affected_rows > 0;
        $insert->close();

        if (!$is_new_view) {
            return false;
        }

        return true;
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

if (!function_exists('kb_extract_image_paths')) {
    function kb_extract_image_paths($value) {
        if (is_array($value)) {
            $paths = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $paths = $decoded;
            } else {
                $paths = [$raw];
            }
        }

        $normalized_paths = [];
        foreach ($paths as $path) {
            $normalized = kb_normalize_relative_path($path);
            if ($normalized !== '') {
                $normalized_paths[] = $normalized;
            }
        }

        return array_values(array_unique($normalized_paths));
    }
}

if (!function_exists('kb_store_uploaded_images')) {
    function kb_store_uploaded_images($files, &$error_message = '') {
        $error_message = '';

        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            $single_path = kb_store_uploaded_image($files, $error_message);
            if ($single_path === false) {
                return false;
            }
            return is_string($single_path) ? [$single_path] : [];
        }

        $stored_paths = [];
        $count = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            $file = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];

            $stored_path = kb_store_uploaded_image($file, $error_message);
            if ($stored_path === false) {
                foreach ($stored_paths as $path) {
                    kb_delete_uploaded_file($path);
                }
                return false;
            }

            if (is_string($stored_path)) {
                $stored_paths[] = $stored_path;
            }
        }

        return $stored_paths;
    }
}

if (!function_exists('kb_encode_image_paths')) {
    function kb_encode_image_paths($paths) {
        $normalized_paths = kb_extract_image_paths($paths);
        if (count($normalized_paths) === 0) {
            return null;
        }
        if (count($normalized_paths) === 1) {
            return $normalized_paths[0];
        }
        return json_encode($normalized_paths);
    }
}

if (!function_exists('kb_delete_uploaded_file')) {
    function kb_delete_uploaded_file($relative_path) {
        $paths = kb_extract_image_paths($relative_path);
        foreach ($paths as $path) {
            $absolute_path = kb_absolute_path($path);
            if ($absolute_path !== '' && is_file($absolute_path)) {
                @unlink($absolute_path);
            }
        }
    }
}

if (!function_exists('kb_resolve_asset_url')) {
    function kb_resolve_asset_url($relative_path, $prefix = '../') {
        $paths = kb_extract_image_paths($relative_path);
        if (count($paths) === 0) {
            return null;
        }

        $normalized = $paths[0];
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

if (!function_exists('kb_resolve_asset_urls')) {
    function kb_resolve_asset_urls($relative_paths, $prefix = '../') {
        $urls = [];
        foreach (kb_extract_image_paths($relative_paths) as $path) {
            $url = kb_resolve_asset_url($path, $prefix);
            if ($url !== null && $url !== '') {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }
}
