<?php
/**
 * GemVerify — Patch: Missing api_transactions columns + ENUM expansion
 *
 * Adds:
 *   - provider_financial_status  VARCHAR(50)  NULL
 *   - reconciliation_notes       VARCHAR(1000) NULL
 *   - synced_at                  DATETIME     NULL
 *   - synced_by                  VARCHAR(100) NULL
 *
 * Expands gv_status ENUM to include "reconciliation_required".
 *
 * Safe to run multiple times (idempotent checks via information_schema).
 *
 * Usage: php api/database/patch_missing_columns.php
 */

declare(strict_types=1);

header('Content-Type: text/plain');

define('RUNNING_MIGRATION', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

$pass = 0;
$fail = 0;

function colExists(PDO $db, string $col): bool
{
    $count = $db->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'api_transactions'
          AND COLUMN_NAME  = '{$col}'
    ")->fetchColumn();
    return (int)$count > 0;
}

function runAlter(PDO $db, string $label, string $sql): void
{
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

echo "==========================================================\n";
echo "  GemVerify - Patch: Missing api_transactions Columns\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "==========================================================\n\n";

// Column 1: provider_financial_status
if (colExists($db, 'provider_financial_status')) {
    echo "[SKIP] provider_financial_status already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add provider_financial_status', "
        ALTER TABLE api_transactions
        ADD COLUMN provider_financial_status VARCHAR(50) NULL
            COMMENT 'charged | not_charged | reversed | unknown'
        AFTER provider_status
    ");
}

// Column 2: reconciliation_notes
if (colExists($db, 'reconciliation_notes')) {
    echo "[SKIP] reconciliation_notes already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add reconciliation_notes', "
        ALTER TABLE api_transactions
        ADD COLUMN reconciliation_notes VARCHAR(1000) NULL
            COMMENT 'Admin/system notes on ambiguous or contested transactions'
        AFTER error_message
    ");
}

// Column 3: synced_at
if (colExists($db, 'synced_at')) {
    echo "[SKIP] synced_at already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add synced_at', "
        ALTER TABLE api_transactions
        ADD COLUMN synced_at DATETIME NULL
            COMMENT 'When admin last performed a live provider sync'
        AFTER completed_at
    ");
}

// Column 4: synced_by
if (colExists($db, 'synced_by')) {
    echo "[SKIP] synced_by already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add synced_by', "
        ALTER TABLE api_transactions
        ADD COLUMN synced_by VARCHAR(100) NULL
            COMMENT 'Who performed the sync: admin_ID or system'
        AFTER synced_at
    ");
}

// Expand gv_status ENUM (always run - idempotent)
echo "\n[...] Expanding gv_status ENUM to include 'reconciliation_required'...\n";
runAlter($db, 'Expand gv_status ENUM', "
    ALTER TABLE api_transactions
    MODIFY COLUMN gv_status ENUM(
        'pending',
        'processing',
        'completed',
        'failed',
        'refunded',
        'reconciliation_required'
    ) NOT NULL DEFAULT 'pending'
    COMMENT 'reconciliation_required = provider state ambiguous; admin must review'
");

// Verify results
echo "\n==========================================================\n";
echo "  Verification\n";
echo "==========================================================\n";

$cols = $db->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'api_transactions'
      AND COLUMN_NAME IN (
        'provider_financial_status',
        'reconciliation_notes',
        'synced_at',
        'synced_by',
        'gv_status'
      )
    ORDER BY ORDINAL_POSITION
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($cols as $col) {
    echo "  " . str_pad($col['COLUMN_NAME'], 30) . $col['COLUMN_TYPE'] . "\n";
}

echo "\n==========================================================\n";
echo "  PASS: {$pass}  |  FAIL: {$fail}\n";
echo "==========================================================\n";

if ($fail === 0) {
    echo "\n OK ALL PATCHES APPLIED SUCCESSFULLY.\n";
    echo "  You can now safely delete this file from the server.\n";
} else {
    echo "\n WARNING: Some patches failed - review errors above.\n";
}
