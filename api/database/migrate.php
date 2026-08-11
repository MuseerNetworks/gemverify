<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/app.php';

// Define DB config manually to connect without specifying database
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'gemverify_db';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Create DB
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `$dbname` created or already exists.\n";
    
    // Use DB
    $pdo->exec("USE `$dbname`");

    $tables = [
        "users" => "
            CREATE TABLE IF NOT EXISTS users (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              business_name VARCHAR(200) NOT NULL,
              email VARCHAR(255) NOT NULL UNIQUE,
              phone VARCHAR(20) NOT NULL,
              password_hash VARCHAR(255) NOT NULL,
              pin_hash VARCHAR(255) NULL,
              account_name VARCHAR(200) NULL,
              account_number VARCHAR(20) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              email_verified_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              deleted_at DATETIME NULL,
              INDEX idx_email (email),
              INDEX idx_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "admins" => "
            CREATE TABLE IF NOT EXISTS admins (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(200) NOT NULL,
              email VARCHAR(255) NOT NULL UNIQUE,
              password_hash VARCHAR(255) NOT NULL,
              role ENUM('super_admin','admin','support') NOT NULL DEFAULT 'admin',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              last_login_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "service_categories" => "
            CREATE TABLE IF NOT EXISTS service_categories (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(100) NOT NULL,
              slug VARCHAR(100) NOT NULL UNIQUE,
              description TEXT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sort_order INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "services" => "
            CREATE TABLE IF NOT EXISTS services (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              category_id BIGINT UNSIGNED NOT NULL,
              name VARCHAR(200) NOT NULL,
              slug VARCHAR(200) NOT NULL UNIQUE,
              description TEXT NULL,
              est_time VARCHAR(50) NULL,
              is_manual TINYINT(1) NOT NULL DEFAULT 1,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sort_order INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (category_id) REFERENCES service_categories(id),
              INDEX idx_slug (slug),
              INDEX idx_category (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "service_pricing" => "
            CREATE TABLE IF NOT EXISTS service_pricing (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              service_id BIGINT UNSIGNED NOT NULL,
              variant_key VARCHAR(100) NULL,
              variant_label VARCHAR(200) NULL,
              price DECIMAL(15,2) NOT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              effective_to DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (service_id) REFERENCES services(id),
              INDEX idx_service_variant (service_id, variant_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "wallets" => "
            CREATE TABLE IF NOT EXISTS wallets (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL UNIQUE,
              balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              ledger_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              currency CHAR(3) NOT NULL DEFAULT 'NGN',
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "transactions" => "
            CREATE TABLE IF NOT EXISTS transactions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL,
              reference VARCHAR(80) NOT NULL UNIQUE,
              type ENUM('credit','debit','refund','hold','hold_release') NOT NULL,
              amount DECIMAL(15,2) NOT NULL,
              balance_before DECIMAL(15,2) NOT NULL,
              balance_after DECIMAL(15,2) NOT NULL,
              description VARCHAR(500) NULL,
              related_request_id BIGINT UNSIGNED NULL,
              status ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'completed',
              idempotency_key VARCHAR(128) NULL UNIQUE,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (user_id) REFERENCES users(id),
              INDEX idx_user (user_id),
              INDEX idx_reference (reference),
              INDEX idx_idempotency (idempotency_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "manual_requests" => "
            CREATE TABLE IF NOT EXISTS manual_requests (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              reference VARCHAR(30) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              service_id BIGINT UNSIGNED NOT NULL,
              pricing_id BIGINT UNSIGNED NOT NULL,
              price_paid DECIMAL(15,2) NOT NULL,
              variant_key VARCHAR(100) NULL,
              transaction_id BIGINT UNSIGNED NULL,
              status ENUM('pending_payment','paid','submitted','under_review','processing','awaiting_info','info_received','completed','rejected','cancelled','refund_pending','refunded') NOT NULL DEFAULT 'submitted',
              assigned_admin_id BIGINT UNSIGNED NULL,
              completion_notes TEXT NULL,
              rejection_reason TEXT NULL,
              additional_info_request TEXT NULL,
              additional_info_response TEXT NULL,
              result_file_id BIGINT UNSIGNED NULL,
              ip_address VARCHAR(45) NULL,
              user_agent TEXT NULL,
              submitted_at DATETIME NULL,
              completed_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (user_id) REFERENCES users(id),
              FOREIGN KEY (service_id) REFERENCES services(id),
              FOREIGN KEY (pricing_id) REFERENCES service_pricing(id),
              FOREIGN KEY (assigned_admin_id) REFERENCES admins(id),
              INDEX idx_reference (reference),
              INDEX idx_user (user_id),
              INDEX idx_service (service_id),
              INDEX idx_status (status),
              INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "request_form_data" => "
            CREATE TABLE IF NOT EXISTS request_form_data (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NOT NULL UNIQUE,
              form_data JSON NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (request_id) REFERENCES manual_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "request_documents" => "
            CREATE TABLE IF NOT EXISTS request_documents (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NOT NULL,
              field_name VARCHAR(100) NOT NULL,
              original_name VARCHAR(255) NOT NULL,
              stored_name VARCHAR(255) NOT NULL,
              mime_type VARCHAR(100) NOT NULL,
              file_size INT UNSIGNED NOT NULL,
              storage_path VARCHAR(500) NOT NULL,
              uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (request_id) REFERENCES manual_requests(id) ON DELETE CASCADE,
              INDEX idx_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "result_files" => "
            CREATE TABLE IF NOT EXISTS result_files (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NOT NULL,
              uploaded_by BIGINT UNSIGNED NOT NULL,
              original_name VARCHAR(255) NOT NULL,
              stored_name VARCHAR(255) NOT NULL,
              mime_type VARCHAR(100) NOT NULL,
              file_size INT UNSIGNED NOT NULL,
              storage_path VARCHAR(500) NOT NULL,
              version INT UNSIGNED NOT NULL DEFAULT 1,
              is_current TINYINT(1) NOT NULL DEFAULT 1,
              uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (request_id) REFERENCES manual_requests(id) ON DELETE CASCADE,
              FOREIGN KEY (uploaded_by) REFERENCES admins(id),
              INDEX idx_request (request_id),
              INDEX idx_current (request_id, is_current)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "request_status_history" => "
            CREATE TABLE IF NOT EXISTS request_status_history (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NOT NULL,
              old_status VARCHAR(50) NULL,
              new_status VARCHAR(50) NOT NULL,
              changed_by_type ENUM('user','admin','system') NOT NULL,
              changed_by_id BIGINT UNSIGNED NULL,
              notes TEXT NULL,
              changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (request_id) REFERENCES manual_requests(id) ON DELETE CASCADE,
              INDEX idx_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "admin_notes" => "
            CREATE TABLE IF NOT EXISTS admin_notes (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NOT NULL,
              admin_id BIGINT UNSIGNED NOT NULL,
              note TEXT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (request_id) REFERENCES manual_requests(id) ON DELETE CASCADE,
              FOREIGN KEY (admin_id) REFERENCES admins(id),
              INDEX idx_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "refunds" => "
            CREATE TABLE IF NOT EXISTS refunds (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NOT NULL UNIQUE,
              transaction_id BIGINT UNSIGNED NOT NULL,
              admin_id BIGINT UNSIGNED NOT NULL,
              amount DECIMAL(15,2) NOT NULL,
              reason TEXT NOT NULL,
              status ENUM('pending','completed','failed') NOT NULL DEFAULT 'completed',
              refunded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (request_id) REFERENCES manual_requests(id),
              FOREIGN KEY (transaction_id) REFERENCES transactions(id),
              FOREIGN KEY (admin_id) REFERENCES admins(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "notifications" => "
            CREATE TABLE IF NOT EXISTS notifications (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL,
              request_id BIGINT UNSIGNED NULL,
              type VARCHAR(80) NOT NULL,
              title VARCHAR(200) NOT NULL,
              body TEXT NOT NULL,
              is_read TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (user_id) REFERENCES users(id),
              FOREIGN KEY (request_id) REFERENCES manual_requests(id) ON DELETE SET NULL,
              INDEX idx_user_unread (user_id, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",
        "audit_logs" => "
            CREATE TABLE IF NOT EXISTS audit_logs (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              request_id BIGINT UNSIGNED NULL,
              actor_type ENUM('user','admin','system') NOT NULL,
              actor_id BIGINT UNSIGNED NULL,
              action VARCHAR(100) NOT NULL,
              old_value JSON NULL,
              new_value JSON NULL,
              notes TEXT NULL,
              ip_address VARCHAR(45) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_request (request_id),
              INDEX idx_actor (actor_type, actor_id),
              INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        "
    ];

    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        echo "Table '$name' created or already exists.\n";
    }

    echo "Migration completed successfully!\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
