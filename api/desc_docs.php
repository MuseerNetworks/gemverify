<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$pdo = db();
$cols = $pdo->query("DESCRIBE request_documents")->fetchAll(PDO::FETCH_COLUMN);
echo "request_documents columns:\n";
print_r($cols);
