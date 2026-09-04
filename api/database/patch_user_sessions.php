<?php
/**
 * GemVerify — Session Tables Migration
 * Creates user_sessions and admin_sessions for server-side inactivity enforcement.
 * IDEMPOTENT — safe to run multiple times (uses IF NOT EXISTS guards).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();
$errors = [];

// 1. user_sessions
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_sessions` (
            `id`            INT         NOT NULL AUTO_INCREMENT,
            `jti`           VARCHAR(64) NOT NULL,
            `user_id`       INT         NOT NULL,
            `last_activity` BIGINT      NOT NULL COMMENT 'Unix timestamp of last confirmed activity',
            `expires_at`    BIGINT      NOT NULL COMMENT 'Absolute JWT exp as Unix timestamp',
            `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
            `created_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_jti`         (`jti`),
            KEY `idx_user_active`       (`user_id`, `is_active`),
            KEY `idx_cleanup`           (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] user_sessions table ready.\n";
} catch (Exception $e) {
    $errors[] = "user_sessions: " . $e->getMessage();
    echo "[ERROR] user_sessions: " . $e->getMessage() . "\n";
}

// 2. admin_sessions
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `admin_sessions` (
            `id`            INT         NOT NULL AUTO_INCREMENT,
            `jti`           VARCHAR(64) NOT NULL,
            `admin_id`      INT         NOT NULL,
            `last_activity` BIGINT      NOT NULL,
            `expires_at`    BIGINT      NOT NULL,
            `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
            `created_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_jti`         (`jti`),
            KEY `idx_admin_active`      (`admin_id`, `is_active`),
            KEY `idx_cleanup`           (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] admin_sessions table ready.\n";
} catch (Exception $e) {
    $errors[] = "admin_sessions: " . $e->getMessage();
    echo "[ERROR] admin_sessions: " . $e->getMessage() . "\n";
}

// 3. Verify
try {
    $tables = $db->query("SHOW TABLES LIKE '%sessions'")->fetchAll(PDO::FETCH_COLUMN);
    echo "[VERIFY] Session tables found: " . implode(', ', $tables) . "\n";
    $cols = $db->query("DESCRIBE user_sessions")->fetchAll(PDO::FETCH_COLUMN);
    echo "[VERIFY] user_sessions columns: " . implode(', ', $cols) . "\n";
} catch (Exception $e) {
    echo "[WARN] Verify skipped: " . $e->getMessage() . "\n";
}

// 4. Housekeeping — remove sessions expired >24h ago
try {
    $cutoff = time() - 86400;
    $db->prepare("DELETE FROM user_sessions  WHERE expires_at < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM admin_sessions WHERE expires_at < ?")->execute([$cutoff]);
    echo "[OK] Old session cleanup done.\n";
} catch (Exception $e) {
    echo "[WARN] Cleanup skipped: " . $e->getMessage() . "\n";
}

if (empty($errors)) {
    echo "\n[SUCCESS] Migration complete.\n";
} else {
    echo "\n[FAILED] " . count($errors) . " error(s) occurred.\n";
    exit(1);
}
