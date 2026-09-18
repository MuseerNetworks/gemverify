<?php
declare(strict_types=1);

/**
 * Creates the isolated ZenithPay webhook discovery log.
 *
 * This migration intentionally does not touch wallets, transactions, or top-ups.
 * Run once from the CLI: php api/migrations/create_zenithpay_webhook_events.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This migration must be run from the CLI.' . PHP_EOL);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS zenithpay_webhook_events (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    remote_ip        VARCHAR(45) NOT NULL,
    content_type     VARCHAR(128) NOT NULL DEFAULT '',
    payload_sha256   CHAR(64) NOT NULL,
    payload          LONGTEXT NOT NULL,
    processing_status ENUM('received','mapped','rejected') NOT NULL DEFAULT 'received',
    delivery_count   INT UNSIGNED NOT NULL DEFAULT 1,
    received_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_zenithpay_webhook_payload (payload_sha256),
    KEY idx_zenithpay_webhook_status_received (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

try {
    db()->exec($sql);
    echo "[OK] ZenithPay webhook discovery table is ready." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
