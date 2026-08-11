<?php
define('RUNNING_MIGRATION', true);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = db();

// Reset password for user 1 to 'password'
$hash = password_hash('password', PASSWORD_BCRYPT);
$db->prepare("UPDATE users SET password_hash = ? WHERE id = 1")->execute([$hash]);
echo "Updated user 1 password to: password\n";
echo "New hash: $hash\n";

// Verify it works
$stmt = $db->prepare("SELECT password_hash FROM users WHERE id = 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Verify: " . (password_verify('password', $row['password_hash']) ? 'PASS' : 'FAIL') . "\n";

// Now simulate the login query
$stmt = $db->prepare("SELECT u.id, u.business_name, u.email, u.password_hash, u.is_active, w.balance FROM users u LEFT JOIN wallets w ON u.id = w.user_id WHERE u.email = ?");
$stmt->execute(['abuabdillah3916@gmail.com']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "User found: " . ($user ? 'YES' : 'NO') . "\n";
echo "Password verify from DB: " . (password_verify('password', $user['password_hash']) ? 'PASS' : 'FAIL') . "\n";
echo "Is active: " . $user['is_active'] . "\n";
