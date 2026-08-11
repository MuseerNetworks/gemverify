<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
spl_autoload_register(function ($c) {
    $f = __DIR__ . '/../src/' . str_replace('\\', '/', $c) . '.php';
    if (file_exists($f)) require_once $f;
});
echo 'ApiRequestController : ' . (class_exists('Controllers\\ApiRequestController') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'TechHubService       : ' . (class_exists('Services\\TechHubService') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'TechHubClient        : ' . (class_exists('Providers\\TechHubClient') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'All classes resolved.' . PHP_EOL;
