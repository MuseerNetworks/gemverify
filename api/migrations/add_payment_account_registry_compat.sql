-- Import once in phpMyAdmin after this release is deployed.
CREATE TABLE IF NOT EXISTS payment_providers (
 provider_key VARCHAR(64) NOT NULL, display_name VARCHAR(100) NOT NULL,
 customer_funding_enabled TINYINT(1) NOT NULL DEFAULT 1, account_assignment_enabled TINYINT(1) NOT NULL DEFAULT 1,
 maintenance_enabled TINYINT(1) NOT NULL DEFAULT 0, display_order INT UNSIGNED NOT NULL DEFAULT 100,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (provider_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS payment_virtual_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, provider_key VARCHAR(64) NOT NULL,
 provider_account_id VARCHAR(128) NULL, account_reference VARCHAR(150) NULL, account_number VARCHAR(32) NOT NULL,
 account_name VARCHAR(255) NULL, bank_name VARCHAR(100) NULL, currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
 status ENUM('pending','active','failed','unknown','disabled') NOT NULL DEFAULT 'pending', provider_metadata JSON NULL, last_credit_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_payment_provider_account (provider_key,account_number),
 UNIQUE KEY uq_payment_user_provider_reference (user_id,provider_key,account_reference), KEY idx_payment_user (user_id,provider_key), KEY idx_payment_status (provider_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO payment_providers (provider_key,display_name,customer_funding_enabled,account_assignment_enabled,maintenance_enabled,display_order) VALUES
 ('katpay','KatPay',1,0,0,20),('zenithpay','ZenithPay',1,1,0,10)
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);
INSERT INTO payment_virtual_accounts (user_id,provider_key,provider_account_id,account_reference,account_number,account_name,bank_name,currency,status,last_credit_at,created_at,updated_at)
SELECT user_id,'katpay',katpay_va_id,COALESCE(NULLIF(katpay_va_id,''),CONCAT('legacy-katpay-',id)),account_number,account_name,bank_name,currency,status,last_credit_at,created_at,updated_at
FROM virtual_accounts WHERE account_number IS NOT NULL AND account_number<>''
ON DUPLICATE KEY UPDATE account_name=VALUES(account_name),bank_name=VALUES(bank_name),status=VALUES(status),last_credit_at=VALUES(last_credit_at),updated_at=VALUES(updated_at);
INSERT INTO payment_virtual_accounts (user_id,provider_key,provider_account_id,account_reference,account_number,account_name,bank_name,currency,status,last_credit_at,created_at,updated_at)
SELECT user_id,'zenithpay',account_reference,account_reference,account_number,account_name,bank_name,'NGN',status,last_credit_at,created_at,updated_at
FROM zenithpay_virtual_accounts WHERE account_number IS NOT NULL AND account_number<>''
ON DUPLICATE KEY UPDATE account_name=VALUES(account_name),bank_name=VALUES(bank_name),status=VALUES(status),last_credit_at=VALUES(last_credit_at),updated_at=VALUES(updated_at);
