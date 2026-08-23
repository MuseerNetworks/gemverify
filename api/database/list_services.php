<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$db = db();
$stmt = $db->query("SELECT id, name, slug, is_manual, provider_name FROM services");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo "ID: {$row['id']}, Slug: {$row['slug']}, Name: {$row['name']}, Manual: {$row['is_manual']}, Provider: {$row['provider_name']}\n";
}
