<?php
namespace Services;

use RuntimeException;

class FileStorageService {
    private StorageDriverInterface $driver;
    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'txt', 'csv'];
    private array $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf', 'text/plain', 'text/csv'];

    public function __construct(StorageDriverInterface $driver) {
        $this->driver = $driver;
    }

    public function storeDocument(array $fileArray, string $fieldName, string $requestRef, int $maxSize = 5242880): array {
        return $this->processUpload($fileArray, $fieldName, $requestRef, 'documents', $maxSize);
    }

    public function storeResult(array $fileArray, string $requestRef, int $version, int $maxSize = 10485760): array {
        return $this->processUpload($fileArray, 'result_file', $requestRef, 'results', $maxSize);
    }

    private function processUpload(array $fileArray, string $fieldName, string $requestRef, string $type, int $maxSize): array {
        if (!isset($fileArray['tmp_name']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException("File upload error.");
        }

        if ($fileArray['size'] > $maxSize) {
            throw new RuntimeException("File size exceeds limit of {$maxSize} bytes.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileArray['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            throw new RuntimeException("Invalid MIME type: {$mimeType}");
        }

        $ext = strtolower(pathinfo($fileArray['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            throw new RuntimeException("Invalid file extension: {$ext}");
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = "{$type}/{$requestRef}/{$storedName}";

        if (!$this->driver->store($fileArray['tmp_name'], $destination)) {
            throw new RuntimeException("Failed to store file.");
        }

        return [
            'field_name' => $fieldName,
            'original_name' => $fileArray['name'],
            'stored_name' => $storedName,
            'mime_type' => $mimeType,
            'file_size' => (int)$fileArray['size'],
            'storage_path' => $destination
        ];
    }

    public function serveFile(string $storagePath, string $filename, string $mimeType): never {
        $content = $this->driver->retrieve($storagePath);
        if ($content === false) {
            header("HTTP/1.1 404 Not Found");
            exit;
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . basename($filename) . '"');
        header('Content-Length: ' . strlen($content));
        
        echo $content;
        exit;
    }

    public function deleteFile(string $storagePath): bool {
        return $this->driver->delete($storagePath);
    }
}



