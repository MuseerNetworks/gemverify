<?php
/**
 * GemVerify — KatPay Integration Migration
 * Phase A: Create wallet_topups table
 * Run once: C:\xampp\php\php.exe api\migrations\create_wallet_topups.php
 */
require __DIR__ . '/../config/database.php';

$db = db();

$sql = "
CREATE TABLE IF NOT EXISTS `wallet_topups` (
  `id`                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `user_id`             INT UNSIGNED      NOT NULL,
  `merchant_reference`  VARCHAR(64)       NOT NULL,
  `katpay_uuid`         VARCHAR(64)       NULL     DEFAULT NULL,
  `delivery_id`         VARCHAR(128)      NULL     DEFAULT NULL COMMENT 'X-KatPay-Delivery-Id for idempotency',
  `amount`              DECIMAL(12,2)     NOT NULL,
  `amount_received`     DECIMAL(12,2)     NULL     DEFAULT NULL,
  `currency`            VARCHAR(8)        NOT NULL DEFAULT 'NGN',
  `status`              ENUM(
                          'pending',
                          'processing',
                          'completed',
                          'partial',
                          'expired',
                          'cancelled',
                          'failed'
                        )                 NOT NULL DEFAULT 'pending',
  `checkout_url`        VARCHAR(512)      NULL     DEFAULT NULL,
  `payment_account`     JSON              NULL     DEFAULT NULL COMMENT 'account_number, bank_name from KatPay',
  `callback_payload`    JSON              NULL     DEFAULT NULL COMMENT 'raw verified callback body for audit',
  `credited_tx_id`      INT UNSIGNED      NULL     DEFAULT NULL COMMENT 'FK to transactions.id once credited',
  `admin_note`          TEXT              NULL     DEFAULT NULL COMMENT 'Admin note for partial/failed topups',
  `expires_at`          DATETIME          NULL     DEFAULT NULL,
  `completed_at`        DATETIME          NULL     DEFAULT NULL,
  `created_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE  KEY `uq_merchant_reference` (`merchant_reference`),
  UNIQUE  KEY `uq_delivery_id`        (`delivery_id`),
  INDEX       `idx_user_id`           (`user_id`),
  INDEX       `idx_status`            (`status`),
  INDEX       `idx_created_at`        (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $db->exec($sql);
    echo "[OK] wallet_topups table created successfully.\n";
} catch (\PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
