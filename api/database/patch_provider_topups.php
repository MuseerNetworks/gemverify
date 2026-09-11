<?php
if (php_sapi_name() !== 'cli') { die("CLI only.\n"); }
require_once __DIR__ . '/../config/app.php';
$pdo = new PDO("mysql:host=localhost;dbname=gemverify_db;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
echo "Connected.\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS provider_topups (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider     VARCHAR(50)     NOT NULL,
    amount       DECIMAL(12,2)   NOT NULL,
    currency     VARCHAR(10)     NOT NULL DEFAULT 'NGN',
    reference    VARCHAR(100)    NULL,
    note         TEXT            NULL,
    recorded_by  BIGINT UNSIGNED NULL,
    topped_up_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_provider (provider),
    INDEX idx_topped_up_at (topped_up_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
echo "Table provider_topups created.\n";
$cols = $pdo->query("DESCRIBE provider_topups")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) { echo "  {$c['Field']} ({$c['Type']})\n"; }
echo "Done.\n";
