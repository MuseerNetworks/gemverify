<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = db();
    echo "Creating admin_withdrawals table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_withdrawals (
            id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference          VARCHAR(50) NOT NULL UNIQUE,
            admin_id           BIGINT UNSIGNED NOT NULL,
            amount             DECIMAL(15,2) NOT NULL,
            bank_code          VARCHAR(20) NOT NULL,
            bank_name          VARCHAR(100) NULL,
            account_number     VARCHAR(20) NOT NULL,
            account_name       VARCHAR(150) NOT NULL,
            description        VARCHAR(255) NULL,
            katpay_reference   VARCHAR(100) NULL,
            status             ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
            response_payload   LONGTEXT NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id),
            INDEX idx_reference (reference),
            INDEX idx_admin (admin_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "✓ Table 'admin_withdrawals' created/verified successfully.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
