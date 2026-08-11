<?php

require_once __DIR__ . '/app.php';

define('STORAGE_DOCUMENTS_PATH', STORAGE_BASE_PATH . '/documents');
define('STORAGE_RESULTS_PATH', STORAGE_BASE_PATH . '/results');

$dirs = [STORAGE_DOCUMENTS_PATH, STORAGE_RESULTS_PATH];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function storage_path(string $type, string $filename = '') {
    if ($type === 'documents') {
        return STORAGE_DOCUMENTS_PATH . ($filename ? '/' . $filename : '');
    } elseif ($type === 'results') {
        return STORAGE_RESULTS_PATH . ($filename ? '/' . $filename : '');
    }
    return STORAGE_BASE_PATH . '/' . $type . ($filename ? '/' . $filename : '');
}

function storage_url(string $type, string $filename) {
    return rtrim(API_BASE_PATH, '/') . "/files/{$type}/{$filename}";
}
