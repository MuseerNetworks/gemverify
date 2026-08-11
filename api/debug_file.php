<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/storage.php';

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});
require_once __DIR__ . '/src/Helpers/Response.php';
require_once __DIR__ . '/src/Helpers/JWT.php';

$db = db();
$pricing = new Services\PricingService($db);
$wallet = new Services\WalletService($db);
$driver = new Services\LocalStorageDriver(STORAGE_BASE_PATH);
$storage = new Services\FileStorageService($driver);
$audit = new Services\AuditService($db);
$notif = new Services\NotificationService($db);
$svc = new Services\RequestService($db, $pricing, $wallet, $storage, $audit, $notif);

try {
    $_FILES = [
        'applicant_photo' => [
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => __FILE__,
            'error' => UPLOAD_ERR_OK,
            'size' => 100
        ]
    ];
    $res = $svc->submit(5, 'nin-enrollment', null, ['surname'=>'TEST', 'pin'=>'1234'], $_FILES, bin2hex(random_bytes(8)));
    print_r($res);
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
