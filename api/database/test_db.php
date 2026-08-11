<?php
define('RUNNING_MIGRATION', true);
require_once 'C:/xampp/htdocs/gemverify/api/config/app.php';
require_once 'C:/xampp/htdocs/gemverify/api/config/database.php';
try {
    $db = db();
    echo 'DB OK: ' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
} catch (Exception $e) {
    echo 'DB FAIL: ' . $e->getMessage() . "\n";
}
