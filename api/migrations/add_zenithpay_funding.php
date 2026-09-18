<?php
/**
 * ZenithPay instant wallet funding schema.
 * Run once through CLI or execute the SQL statements in phpMyAdmin.
 */
require __DIR__ . '/../config/database.php';

$db = db();

try {
    $columns = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('first_name', $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER business_name");
    }
    if (!in_array('last_name', $columns, true)) {
        $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(150) NULL AFTER first_name");
    }

    // Existing registrations stored the two signup fields as one business_name.
    $db->exec("UPDATE users
        SET first_name = NULLIF(SUBSTRING_INDEX(TRIM(business_name), ' ', 1), ''),
            last_name = NULLIF(TRIM(SUBSTRING(TRIM(business_name), LENGTH(SUBSTRING_INDEX(TRIM(business_name), ' ', 1)) + 1)), '')
        WHERE (first_name IS NULL OR first_name = '')
           OR (last_name IS NULL OR last_name = '')");

    $db->exec("CREATE TABLE IF NOT EXISTS zenithpay_virtual_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        account_reference VARCHAR(100) NULL,
        account_number VARCHAR(32) NULL,
        account_name VARCHAR(255) NULL,
        bank_name VARCHAR(100) NULL,
        customer_email VARCHAR(255) NULL,
        status ENUM('pending','active','failed','unknown','disabled') NOT NULL DEFAULT 'pending',
        provider_response JSON NULL,
        last_error VARCHAR(500) NULL,
        last_credit_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_zenith_user (user_id),
        UNIQUE KEY uq_zenith_reference (account_reference),
        UNIQUE KEY uq_zenith_account_number (account_number),
        KEY idx_zenith_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS zenithpay_webhook_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        remote_ip VARCHAR(45) NOT NULL,
        content_type VARCHAR(128) NULL,
        payload_sha256 CHAR(64) NOT NULL,
        payload LONGTEXT NOT NULL,
        processing_status ENUM('received','mapped','rejected') NOT NULL DEFAULT 'received',
        delivery_count INT UNSIGNED NOT NULL DEFAULT 1,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_zenithpay_webhook_payload (payload_sha256),
        KEY idx_zenithpay_webhook_status_received (processing_status, received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS zenithpay_deposits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        transaction_id VARCHAR(128) NOT NULL,
        order_id VARCHAR(128) NULL,
        user_id BIGINT UNSIGNED NULL,
        zenithpay_virtual_account_id BIGINT UNSIGNED NULL,
        account_reference VARCHAR(100) NULL,
        account_number VARCHAR(32) NULL,
        gross_amount DECIMAL(15,2) NULL,
        settlement_amount DECIMAL(15,2) NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
        payment_status VARCHAR(40) NOT NULL,
        processing_status ENUM('received','credited','duplicate','unmatched','ignored','rejected','failed') NOT NULL DEFAULT 'received',
        session_id VARCHAR(160) NULL,
        credited_tx_id BIGINT UNSIGNED NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_zenith_transaction (transaction_id),
        KEY idx_zenith_deposit_user (user_id, received_at),
        KEY idx_zenith_deposit_status (processing_status, received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS payment_gateway_settings (
        setting_key VARCHAR(100) NOT NULL,
        setting_value VARCHAR(255) NOT NULL,
        updated_by BIGINT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT IGNORE INTO payment_gateway_settings (setting_key, setting_value) VALUES
        ('katpay_funding_enabled', '1'), ('zenithpay_activation_enabled', '1')");

    echo "[OK] ZenithPay funding schema is ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . "\n");
    exit(1);
}
