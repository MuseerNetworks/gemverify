<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
$tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Exact DB Table Count: " . count($tables) . "\n\n";
foreach ($tables as $i => $t) {
    echo ($i + 1) . ". " . $t . "\n";
}
