<?php
/**
 * GemVerify - Patch: Multi-Provider Routing & S8V Failure Fee Columns
 *
 * Adds:
 *   - services.failure_penalty_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
 *   - services.provider_name        VARCHAR(50)   NOT NULL DEFAULT 'techhub'
 *   - api_transactions.penalty_deducted DECIMAL(10,2) NOT NULL DEFAULT 0.00
 *   - api_transactions.refund_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00
 *
 * Safe to run multiple times (idempotent checks via information_schema).
 *
 * Usage: 
 *   CLI: php api/database/patch_s8v_columns.php
 *   Web: https://<domain>/api/database/patch_s8v_columns.php
 */

declare(strict_types=1);

header('Content-Type: text/plain');

define('RUNNING_MIGRATION', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

$pass = 0;
$fail = 0;

function colExists(PDO $db, string $table, string $col): bool
{
    $count = $db->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
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
echo "  GemVerify - Patch: Multi-Provider & S8V Fee Columns\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "==========================================================\n\n";

// Column 1: services.failure_penalty_fee
if (colExists($db, 'services', 'failure_penalty_fee')) {
    echo "[SKIP] services.failure_penalty_fee already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add services.failure_penalty_fee', "
        ALTER TABLE services
        ADD COLUMN failure_penalty_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
        AFTER is_manual
    ");
}

// Column 2: services.provider_name
if (colExists($db, 'services', 'provider_name')) {
    echo "[SKIP] services.provider_name already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add services.provider_name', "
        ALTER TABLE services
        ADD COLUMN provider_name VARCHAR(50) NOT NULL DEFAULT 'techhub'
        AFTER failure_penalty_fee
    ");
}

// Column 3: api_transactions.penalty_deducted
if (colExists($db, 'api_transactions', 'penalty_deducted')) {
    echo "[SKIP] api_transactions.penalty_deducted already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add api_transactions.penalty_deducted', "
        ALTER TABLE api_transactions
        ADD COLUMN penalty_deducted DECIMAL(10,2) NOT NULL DEFAULT 0.00
        AFTER fee
    ");
}

// Column 4: api_transactions.refund_amount
if (colExists($db, 'api_transactions', 'refund_amount')) {
    echo "[SKIP] api_transactions.refund_amount already exists\n";
    $pass++;
} else {
    runAlter($db, 'Add api_transactions.refund_amount', "
        ALTER TABLE api_transactions
        ADD COLUMN refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00
        AFTER penalty_deducted
    ");
}

echo "\n----------------------------------------------------------\n";
echo "Results: {$pass} Passed, {$fail} Failed.\n";
echo "==========================================================\n";
