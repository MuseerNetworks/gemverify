<?php
// Test admin login API directly
$url = 'http://localhost/gemverify/api/admin/auth/login';
$data = json_encode(['email' => 'admin@gemverify.com', 'password' => 'password123']);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "cURL Error: $error\n";
echo "Response: $response\n\n";

// Also check what admin emails exist in the DB
require_once __DIR__ . '/src/Core/Database.php';
$db = (new Core\Database())->getConnection();
$stmt = $db->query("SELECT id, name, email, role, is_active FROM admins LIMIT 10");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "=== ADMINS IN DATABASE ===\n";
foreach ($admins as $a) {
    echo "ID:{$a['id']} | {$a['name']} | {$a['email']} | Role:{$a['role']} | Active:{$a['is_active']}\n";
}
