<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$pdo = db();
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("TRUNCATE TABLE service_pricing");
$pdo->exec("TRUNCATE TABLE services");
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
