<?php
/**
 * GemVerify — Performance Optimization Migration
 * 
 * Adds composite indexes on manual_requests and api_transactions:
 * - (user_id, submitted_at)
 * - (user_id, service_id, submitted_at)
 *
 * Safe to run multiple times (idempotent).
 *
 * Usage: php patch_history_indexes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

function indexExists(PDO $db, string $table, string $indexName): bool {
    $stmt = $db->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $stmt->execute([$indexName]);
    return (bool) $stmt->fetch();
}

function addIndexIfMissing(PDO $db, string $table, string $indexName, string $columns): void {
    if (indexExists($db, $table, $indexName)) {
        echo "[EXISTS] Index '{$indexName}' on '{$table}' already exists.\n";
        return;
    }
    try {
        $db->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columns})");
        echo "[CREATED] Index '{$indexName}' on '{$table}' ({$columns}) created successfully.\n";
    } catch (PDOException $e) {
        echo "[FAIL] Could not create index '{$indexName}' on '{$table}': " . $e->getMessage() . "\n";
    }
}

echo "=================================================================\n";
echo "  GemVerify — History Performance Index Migration\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "=================================================================\n\n";

// 1. manual_requests composite indexes
echo "--- 1. Optimizing manual_requests ---\n";
addIndexIfMissing($db, 'manual_requests', 'idx_mr_user_submitted', 'user_id, submitted_at');
addIndexIfMissing($db, 'manual_requests', 'idx_mr_user_service_submitted', 'user_id, service_id, submitted_at');

// 2. api_transactions composite indexes
echo "\n--- 2. Optimizing api_transactions ---\n";
addIndexIfMissing($db, 'api_transactions', 'idx_at_user_submitted', 'user_id, submitted_at');
addIndexIfMissing($db, 'api_transactions', 'idx_at_user_service_submitted', 'user_id, service_id, submitted_at');

echo "\n=================================================================\n";
echo "  Index migration completed!\n";
echo "=================================================================\n";
