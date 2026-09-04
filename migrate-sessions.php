<?php
/**
 * GemVerify — One-Time Session Tables Migration
 * Visit this URL once in your browser to create the tables.
 * THIS FILE AUTO-DELETES ITSELF after running.
 *
 * SECURITY: Protected by a secret key in the URL.
 * URL: https://gemverify.com.ng/migrate-sessions.php?key=GV_MIGRATE_2026
 */

define('MIGRATION_KEY', 'GV_MIGRATE_2026');

if (($_GET['key'] ?? '') !== MIGRATION_KEY) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2>');
}

require_once __DIR__ . '/api/config/app.php';
require_once __DIR__ . '/api/config/database.php';

$db     = db();
$errors = [];
$log    = [];

// user_sessions
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `user_sessions` (
        `id`              INT         NOT NULL AUTO_INCREMENT,
        `jti`             VARCHAR(64) NOT NULL,
        `user_id`         INT         NOT NULL,
        `last_activity`   BIGINT      NOT NULL,
        `expires_at`      BIGINT      NOT NULL,
        `disconnected_at` BIGINT      NULL DEFAULT NULL,
        `is_active`       TINYINT(1)  NOT NULL DEFAULT 1,
        `created_at`      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_jti`       (`jti`),
        KEY `idx_user_active`     (`user_id`, `is_active`),
        KEY `idx_cleanup`         (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $cols = $db->query("DESCRIBE user_sessions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('disconnected_at', $cols)) {
        $db->exec("ALTER TABLE `user_sessions` ADD COLUMN `disconnected_at` BIGINT NULL DEFAULT NULL AFTER `expires_at`");
    }
    $log[] = ['ok', 'user_sessions table created/verified with disconnected_at'];
} catch (Exception $e) {
    $errors[] = $e->getMessage();
    $log[]    = ['err', 'user_sessions: ' . $e->getMessage()];
}

// admin_sessions
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `admin_sessions` (
        `id`              INT         NOT NULL AUTO_INCREMENT,
        `jti`             VARCHAR(64) NOT NULL,
        `admin_id`        INT         NOT NULL,
        `last_activity`   BIGINT      NOT NULL,
        `expires_at`      BIGINT      NOT NULL,
        `disconnected_at` BIGINT      NULL DEFAULT NULL,
        `is_active`       TINYINT(1)  NOT NULL DEFAULT 1,
        `created_at`      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_jti`       (`jti`),
        KEY `idx_admin_active`    (`admin_id`, `is_active`),
        KEY `idx_cleanup`         (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $adminCols = $db->query("DESCRIBE admin_sessions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('disconnected_at', $adminCols)) {
        $db->exec("ALTER TABLE `admin_sessions` ADD COLUMN `disconnected_at` BIGINT NULL DEFAULT NULL AFTER `expires_at`");
    }
    $log[] = ['ok', 'admin_sessions table created/verified with disconnected_at'];
} catch (Exception $e) {
    $errors[] = $e->getMessage();
    $log[]    = ['err', 'admin_sessions: ' . $e->getMessage()];
}

// Verify
try {
    $tables = $db->query("SHOW TABLES LIKE '%sessions'")->fetchAll(PDO::FETCH_COLUMN);
    $log[]  = ['ok', 'Tables in DB: ' . implode(', ', $tables)];
} catch (Exception $e) {
    $log[]  = ['warn', 'Verify skipped: ' . $e->getMessage()];
}

$success = empty($errors);

// Self-delete this file
@unlink(__FILE__);

?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>GemVerify Migration</title>
<style>
  body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px}
  .ok{color:#198754;font-weight:bold}
  .err{color:#dc3545;font-weight:bold}
  .warn{color:#856404}
  .box{border:1px solid #ccc;border-radius:8px;padding:20px;margin-top:20px}
  h2{margin-top:0}
</style></head><body>
<div class="box">
  <h2><?= $success ? '✅ Migration Complete' : '❌ Migration Failed' ?></h2>
  <ul>
  <?php foreach ($log as [$type, $msg]): ?>
    <li class="<?= $type ?>"><?= htmlspecialchars($msg) ?></li>
  <?php endforeach; ?>
  </ul>
  <?php if ($success): ?>
    <p><strong>Both session tables are ready.</strong><br>
    GemVerify session security is now active.<br>
    <em>This file has been automatically deleted for security.</em></p>
  <?php else: ?>
    <p>Check the errors above. You can use phpMyAdmin as an alternative.</p>
  <?php endif; ?>
</div>
</body></html>
