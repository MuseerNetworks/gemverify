<?php
require __DIR__ . '/config/database.php';

$email       = 'abuabdillah3916@gmail.com';
$newPassword = '1234567890';
$hash        = password_hash($newPassword, PASSWORD_BCRYPT);

$pdo  = db();
$stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
$stmt->execute([$hash, $email]);

echo "Rows updated: " . $stmt->rowCount() . PHP_EOL;

// Verify immediately
$stmt2 = $pdo->prepare('SELECT password_hash FROM users WHERE email = ?');
$stmt2->execute([$email]);
$row = $stmt2->fetch();

echo "Hash verify:  " . (password_verify($newPassword, $row['password_hash']) ? 'PASS ✅' : 'FAIL ❌') . PHP_EOL;
echo "New password: $newPassword" . PHP_EOL;
