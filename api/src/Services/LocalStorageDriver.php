<?php
namespace Services;

class LocalStorageDriver implements StorageDriverInterface {
    private string $basePath;

    public function __construct(string $basePath) {
        $this->basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
    }

    public function store(string $sourcePath, string $destination): bool {
        $fullDest = $this->basePath . ltrim($destination, '/\\');
        $dir = dirname($fullDest);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (is_uploaded_file($sourcePath)) {
            return move_uploaded_file($sourcePath, $fullDest);
        }
        return copy($sourcePath, $fullDest);
    }

    public function retrieve(string $path): string|false {
        $fullPath = $this->basePath . ltrim($path, '/\\');
        if (file_exists($fullPath)) {
            return file_get_contents($fullPath);
        }
        return false;
    }

    public function delete(string $path): bool {
        $fullPath = $this->basePath . ltrim($path, '/\\');
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    public function exists(string $path): bool {
        $fullPath = $this->basePath . ltrim($path, '/\\');
        return file_exists($fullPath);
    }

    public function getUrl(string $path): string {
        return '/api/files/' . ltrim($path, '/\\');
    }
}



