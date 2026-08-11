<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$pdo = db();
$cols = $pdo->query("DESCRIBE request_status_history")->fetchAll(PDO::FETCH_COLUMN);
echo "request_status_history columns:\n";
print_r($cols);
