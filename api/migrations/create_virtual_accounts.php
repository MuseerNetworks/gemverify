<?php
/**
 * GemVerify — KatPay Virtual Accounts Migration
 * Creates the virtual_accounts table for static per-user virtual bank accounts.
 * Run once: C:\xampp\php\php.exe api\migrations\create_virtual_accounts.php
 */
require __DIR__ . '/../config/database.php';

$db = db();

$sql = "
CREATE TABLE IF NOT EXISTS `virtual_accounts` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED    NOT NULL UNIQUE,
  `katpay_va_id`     VARCHAR(64)     NULL     DEFAULT NULL COMMENT 'KatPay internal virtual account ID',
  `account_number`   VARCHAR(20)     NULL     DEFAULT NULL,
  `account_name`     VARCHAR(128)    NULL     DEFAULT NULL,
  `bank_name`        VARCHAR(64)     NULL     DEFAULT NULL,
  `bank_code`        VARCHAR(20)     NULL     DEFAULT NULL,
  `currency`         VARCHAR(8)      NOT NULL DEFAULT 'NGN',
  `status`           ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
  `raw_response`     JSON            NULL     DEFAULT NULL COMMENT 'Full KatPay creation response',
  `last_credit_at`   DATETIME        NULL     DEFAULT NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE  KEY `uq_user_id`       (`user_id`),
  UNIQUE  KEY `uq_account_number`(`account_number`),
  INDEX       `idx_status`       (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $db->exec($sql);
    echo "[OK] virtual_accounts table created successfully.\n";
} catch (\PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
