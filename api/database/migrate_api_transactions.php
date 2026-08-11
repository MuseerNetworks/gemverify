<?php
/**
 * GemVerify — TechHub Integration Migration
 * 
 * Creates the api_transactions table, adds provider_name to services,
 * seeds TechHub provider associations, and disables unmapped pricing variants.
 *
 * Safe to run multiple times (idempotent).
 *
 * Usage: php migrate_api_transactions.php
 */

declare(strict_types=1);

define('RUNNING_MIGRATION', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

$pass = 0;
$fail = 0;

function migrate(PDO $db, string $label, string $sql): void {
    global $pass, $fail;
    try {
        $db->exec($sql);
        echo "[PASS] {$label}\n";
        $pass++;
    } catch (PDOException $e) {
        echo "[FAIL] {$label}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function check(PDO $db, string $label, string $sql, $expected = null): void {
    global $pass, $fail;
    try {
        $result = $db->query($sql)->fetchColumn();
        if ($expected !== null) {
            if ($result == $expected) {
                echo "[PASS] {$label}: {$result}\n";
                $pass++;
            } else {
                echo "[FAIL] {$label}: expected={$expected}, got={$result}\n";
                $fail++;
            }
        } else {
            echo "[INFO] {$label}: {$result}\n";
            $pass++;
        }
    } catch (PDOException $e) {
        echo "[FAIL] {$label}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "=================================================================\n";
echo "  GemVerify — TechHub Integration Migration\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=================================================================\n\n";

// ── Step 1: Create api_transactions table ─────────────────────────────────
echo "--- Step 1: api_transactions table ---\n";

migrate($db, 'Create api_transactions', "
    CREATE TABLE IF NOT EXISTS api_transactions (
        id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

        -- GemVerify identity
        gv_reference          VARCHAR(40) NOT NULL UNIQUE
                              COMMENT 'GemVerify reference e.g. GVA-20260809-A1B2C3D4',
        user_id               BIGINT UNSIGNED NOT NULL,
        service_id            BIGINT UNSIGNED NOT NULL,
        pricing_id            BIGINT UNSIGNED NOT NULL,
        variant_key           VARCHAR(100) NULL,
        transaction_id        BIGINT UNSIGNED NOT NULL
                              COMMENT 'FK to transactions table (the wallet debit)',

        -- Request metadata
        input_method          VARCHAR(20) NULL
                              COMMENT 'by_nin | by_phone | by_demo | NULL for async services',
        input_summary         VARCHAR(500) NULL
                              COMMENT 'Masked summary e.g. NIN: 12345***901',

        -- Provider identity
        provider              VARCHAR(50) NOT NULL DEFAULT 'techhub',
        provider_endpoint     VARCHAR(200) NOT NULL
                              COMMENT 'Exact TechHub endpoint path called',
        provider_ticket_id    VARCHAR(100) NULL
                              COMMENT 'ticket_id from async TechHub response',
        provider_txn_id       VARCHAR(200) NULL
                              COMMENT 'transaction_id from TechHub response',

        -- Status
        gv_status             ENUM('pending','processing','completed','failed','refunded')
                              NOT NULL DEFAULT 'pending',
        provider_status       VARCHAR(50) NULL
                              COMMENT 'Raw status string from provider e.g. pending/success/failed',

        -- Result
        result_type           ENUM('pdf_base64','ticket') NULL,
        result_data           LONGTEXT NULL
                              COMMENT 'Base64 PDF for slips; JSON result object for async',

        -- Error tracking
        error_code            VARCHAR(100) NULL,
        error_message         VARCHAR(500) NULL,

        -- Idempotency
        idempotency_key       VARCHAR(128) NOT NULL UNIQUE,

        -- Timestamps
        submitted_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        provider_responded_at DATETIME NULL,
        last_checked_at       DATETIME NULL
                              COMMENT 'Last status-poll timestamp for async services',
        completed_at          DATETIME NULL,

        -- Refund flag
        refund_issued         TINYINT(1) NOT NULL DEFAULT 0,

        -- Foreign keys
        FOREIGN KEY (user_id)        REFERENCES users(id),
        FOREIGN KEY (service_id)     REFERENCES services(id),
        FOREIGN KEY (pricing_id)     REFERENCES service_pricing(id),
        FOREIGN KEY (transaction_id) REFERENCES transactions(id),

        -- Indexes
        INDEX idx_gv_reference  (gv_reference),
        INDEX idx_user          (user_id),
        INDEX idx_ticket        (provider_ticket_id),
        INDEX idx_status        (gv_status),
        INDEX idx_idempotency   (idempotency_key),
        INDEX idx_submitted     (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Tracks every TechHub API call — sync PDF slips and async service submissions'
");

// ── Step 2: Add provider_name to services ─────────────────────────────────
echo "\n--- Step 2: provider_name column on services ---\n";

// Check if column already exists
$colExists = $db->query("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'services'
    AND COLUMN_NAME = 'provider_name'
")->fetchColumn();

if ($colExists) {
    echo "[SKIP] provider_name column already exists on services\n";
    $pass++;
} else {
    migrate($db, 'Add provider_name column to services', "
        ALTER TABLE services
        ADD COLUMN provider_name VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Provider name e.g. techhub — NULL for manual services'
        AFTER is_manual
    ");
}

// ── Step 3: Seed provider_name for TechHub-connected services ─────────────
echo "\n--- Step 3: Seed provider_name for TechHub services ---\n";

$techHubSlugs = [
    'nin-verification',
    'bvn-verification',
    'self-service',
    'personalization',
    'bvn-retrieval',
    'ipe-clearance-single',
];

foreach ($techHubSlugs as $slug) {
    try {
        $stmt = $db->prepare("UPDATE services SET provider_name = 'techhub' WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        $affected = $stmt->rowCount();
        if ($affected > 0) {
            echo "[PASS] Set provider_name='techhub' for slug: {$slug}\n";
        } else {
            // Already set or slug not found
            $exists = $db->prepare("SELECT COUNT(*) FROM services WHERE slug = :slug");
            $exists->execute(['slug' => $slug]);
            if ($exists->fetchColumn() > 0) {
                echo "[SKIP] provider_name already set for slug: {$slug}\n";
            } else {
                echo "[WARN] Service slug not found: {$slug}\n";
            }
        }
        $pass++;
    } catch (PDOException $e) {
        echo "[FAIL] Seeding provider for {$slug}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

// ── Step 4: Disable 'basic' NIN pricing variant ───────────────────────────
echo "\n--- Step 4: Disable 'basic' NIN variant ---\n";

try {
    // Verify this is actually the correct row before disabling
    $basicRow = $db->query("
        SELECT p.id, p.variant_key, p.price, p.is_active, s.slug
        FROM service_pricing p
        JOIN services s ON p.service_id = s.id
        WHERE p.id = 178
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$basicRow) {
        echo "[WARN] Pricing row id=178 not found — skipping\n";
    } elseif ($basicRow['slug'] !== 'nin-verification' || $basicRow['variant_key'] !== 'basic') {
        echo "[FAIL] Safety check failed: row 178 is not the 'basic' variant of 'nin-verification'\n";
        echo "       Found: slug={$basicRow['slug']}, variant={$basicRow['variant_key']}\n";
        echo "       Aborting step 4 to prevent unintended data change.\n";
        $fail++;
    } elseif ($basicRow['is_active'] == 0) {
        echo "[SKIP] 'basic' variant (id=178) already disabled\n";
        $pass++;
    } else {
        $db->exec("UPDATE service_pricing SET is_active = 0 WHERE id = 178");
        echo "[PASS] Disabled 'basic' NIN variant (id=178, price=₦{$basicRow['price']})\n";
        $pass++;
    }
} catch (PDOException $e) {
    echo "[FAIL] Disabling basic variant: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Step 5: Verification checks ───────────────────────────────────────────
echo "\n--- Step 5: Verification ---\n";

check($db, 'api_transactions table exists',
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_transactions'",
    1
);

check($db, 'provider_name column exists on services',
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'provider_name'",
    1
);

check($db, 'TechHub-connected services count',
    "SELECT COUNT(*) FROM services WHERE provider_name = 'techhub'",
    6
);

check($db, "basic NIN variant is disabled",
    "SELECT is_active FROM service_pricing WHERE id = 178",
    '0'
);

check($db, 'NIN Validation remains manual (is_manual=1)',
    "SELECT is_manual FROM services WHERE slug = 'nin-validation'",
    '1'
);

check($db, 'self-service provider_name',
    "SELECT provider_name FROM services WHERE slug = 'self-service'",
    'techhub'
);

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n=================================================================\n";
echo "  Migration complete: {$pass} passed, {$fail} failed\n";
echo "=================================================================\n";

if ($fail > 0) {
    exit(1);
}
exit(0);
