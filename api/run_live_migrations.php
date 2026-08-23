<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "=== GemVerify Live Database Migrations Script ===\n\n";

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = db();
    
    // Step 1: Create api_transactions table
    echo "1. Creating api_transactions table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS api_transactions (
            id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            gv_reference          VARCHAR(40) NOT NULL UNIQUE,
            user_id               BIGINT UNSIGNED NOT NULL,
            service_id            BIGINT UNSIGNED NOT NULL,
            pricing_id            BIGINT UNSIGNED NOT NULL,
            variant_key           VARCHAR(100) NULL,
            transaction_id        BIGINT UNSIGNED NOT NULL,
            input_method          VARCHAR(20) NULL,
            input_summary         VARCHAR(500) NULL,
            provider              VARCHAR(50) NOT NULL DEFAULT 'techhub',
            provider_endpoint     VARCHAR(200) NOT NULL,
            provider_ticket_id    VARCHAR(100) NULL,
            provider_txn_id       VARCHAR(200) NULL,
            gv_status             ENUM('pending','processing','completed','failed','refunded') NOT NULL DEFAULT 'pending',
            provider_status       VARCHAR(50) NULL,
            result_type           ENUM('pdf_base64','ticket') NULL,
            result_data           LONGTEXT NULL,
            error_code            VARCHAR(100) NULL,
            error_message         VARCHAR(500) NULL,
            idempotency_key       VARCHAR(128) NOT NULL UNIQUE,
            submitted_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            provider_responded_at DATETIME NULL,
            last_checked_at       DATETIME NULL,
            completed_at          DATETIME NULL,
            refund_issued         TINYINT(1) NOT NULL DEFAULT 0,
            FOREIGN KEY (user_id)        REFERENCES users(id),
            FOREIGN KEY (service_id)     REFERENCES services(id),
            FOREIGN KEY (pricing_id)     REFERENCES service_pricing(id),
            FOREIGN KEY (transaction_id) REFERENCES transactions(id),
            INDEX idx_gv_reference  (gv_reference),
            INDEX idx_user          (user_id),
            INDEX idx_ticket        (provider_ticket_id),
            INDEX idx_status        (gv_status),
            INDEX idx_idempotency   (idempotency_key),
            INDEX idx_submitted     (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "✓ Table 'api_transactions' verified/created.\n";

    // Step 2: Add provider_name column to services if not exists
    echo "\n2. Verifying 'provider_name' column on 'services' table...\n";
    $colExists = $db->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'services'
        AND COLUMN_NAME = 'provider_name'
    ")->fetchColumn();

    if ($colExists) {
        echo "✓ 'provider_name' column already exists.\n";
    } else {
        $db->exec("
            ALTER TABLE services
            ADD COLUMN provider_name VARCHAR(50) NULL DEFAULT NULL
            COMMENT 'Provider name e.g. techhub'
            AFTER is_manual
        ");
        echo "✓ Added 'provider_name' column to 'services' table.\n";
    }

    // Step 3: Seed provider_name and set is_manual = 0 for TechHub services
    echo "\n3. Configuring TechHub-connected services (setting manual=0 and provider=techhub)...\n";
    $techHubServices = [
        'nin-verification',
        'bvn-verification',
        'self-service',
        'personalization',
        'bvn-retrieval',
        'ipe-clearance-single'
    ];

    foreach ($techHubServices as $slug) {
        $stmt = $db->prepare("
            UPDATE services 
            SET provider_name = 'techhub', is_manual = 0 
            WHERE slug = ?
        ");
        $stmt->execute([$slug]);
        echo "✓ Configured service: $slug\n";
    }

    // Step 4: Disable basic NIN pricing variant (id 178)
    echo "\n4. Disabling 'basic' NIN variant (pricing ID 178)...\n";
    $db->exec("UPDATE service_pricing SET is_active = 0 WHERE id = 178");
    echo "✓ Pricing variant ID 178 disabled.\n";

    echo "\n=========================================\n";
    echo "✓ ALL MIGRATIONS EXECUTED SUCCESSFULLY!\n";
    echo "=========================================\n";
    echo "\nIMPORTANT: Please delete this file (run_live_migrations.php) from your server immediately for security.";

} catch (Throwable $t) {
    echo "\n!!! FATAL ERROR DURING MIGRATION !!!\n";
    echo "Message: " . $t->getMessage() . "\n";
    echo "Line: " . $t->getLine() . "\n";
}
