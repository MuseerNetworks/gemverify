<?php
namespace Helpers;

use PDO;
use Exception;

/**
 * SchemaHelper — Self-Healing Database Migration Utility
 *
 * Automatically verifies and adds required schema columns at runtime across
 * local, staging, and production environments. Idempotent and fail-safe.
 */
class SchemaHelper
{
    private static bool $ensured = false;

    /**
     * Ensure columns required for Multi-Provider Routing and Failure Processing Fee exist.
     *
     * @param PDO|null $db
     */
    public static function ensureProviderColumns(?PDO $db = null): void
    {
        if (self::$ensured) {
            return;
        }

        try {
            if ($db === null) {
                if (function_exists('db')) {
                    $db = db();
                } else {
                    return;
                }
            }

            // 1. services table columns
            $svcCols = $db->query("
                SELECT COLUMN_NAME 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'services'
            ")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($svcCols)) {
                if (!in_array('failure_penalty_fee', $svcCols, true)) {
                    $db->exec("ALTER TABLE `services` ADD COLUMN `failure_penalty_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `is_manual`");
                }
                if (!in_array('provider_name', $svcCols, true)) {
                    $db->exec("ALTER TABLE `services` ADD COLUMN `provider_name` VARCHAR(50) NOT NULL DEFAULT 'techhub' AFTER `failure_penalty_fee`");
                }
            }

            // 2. api_transactions table columns
            $txCols = $db->query("
                SELECT COLUMN_NAME 
                FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'api_transactions'
            ")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($txCols)) {
                if (!in_array('penalty_deducted', $txCols, true)) {
                    $db->exec("ALTER TABLE `api_transactions` ADD COLUMN `penalty_deducted` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `fee`");
                }
                if (!in_array('refund_amount', $txCols, true)) {
                    $db->exec("ALTER TABLE `api_transactions` ADD COLUMN `refund_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `penalty_deducted`");
                }
            }

            self::$ensured = true;
        } catch (Exception $e) {
            error_log('[SchemaHelper] ensureProviderColumns warning: ' . $e->getMessage());
        }
    }
}
