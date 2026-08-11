<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$pdo = db();
$cols = $pdo->query("DESCRIBE manual_requests")->fetchAll(PDO::FETCH_COLUMN);
echo "manual_requests columns:\n";
print_r($cols);
