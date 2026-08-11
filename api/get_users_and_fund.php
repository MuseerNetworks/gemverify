<?php
require_once __DIR__ . '/config/database.php';

try {
    $pdo = db();
    
    // List all users and their current balances
    $stmt = $pdo->query("
        SELECT u.id, u.business_name, u.email, u.phone, w.balance 
        FROM users u 
        LEFT JOIN wallets w ON u.id = w.user_id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== USERS IN SYSTEM ===\n";
    foreach ($users as $user) {
        echo "ID: {$user['id']} | Business: {$user['business_name']} | Email: {$user['email']} | Phone: {$user['phone']} | Balance: ₦{$user['balance']}\n";
    }
    
    // Automatically credit all users with ₦100,000 so they can test easily
    echo "\nCrediting all users with ₦100,000 for testing...\n";
    $pdo->exec("UPDATE wallets SET balance = balance + 100000.00, ledger_balance = ledger_balance + 100000.00");
    echo "Done.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
