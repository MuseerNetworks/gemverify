<?php
/**
 * Database Migration: Create enrollment_banks table and seed default banks
 */

declare(strict_types=1);

define('RUNNING_MIGRATION', true);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();

echo "Creating enrollment_banks table...\n";

$db->exec("
    CREATE TABLE IF NOT EXISTS enrollment_banks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$defaultBanks = [
    "Access Bank",
    "AGENCY",
    "BOA BANK",
    "FCMB Bank",
    "FIRST BANK",
    "GT BANK",
    "HERITAGE BANK",
    "JAIZ BANK",
    "KEYSTONE BANK",
    "Stanbic IBTC bank",
    "UBA",
    "Zenith Bank",
    "Wema Bank",
    "Union Bank",
    "Fidelity Bank"
];

$stmt = $db->prepare("INSERT IGNORE INTO enrollment_banks (name, is_active, sort_order) VALUES (?, 1, ?)");

$order = 1;
foreach ($defaultBanks as $bank) {
    $stmt->execute([$bank, $order++]);
}

echo "enrollment_banks table ready and seeded successfully!\n";
