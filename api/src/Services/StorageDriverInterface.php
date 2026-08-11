<?php
namespace Services;

interface StorageDriverInterface {
    public function store(string $sourcePath, string $destination): bool;
    public function retrieve(string $path): string|false;
    public function delete(string $path): bool;
    public function exists(string $path): bool;
    public function getUrl(string $path): string;
}



