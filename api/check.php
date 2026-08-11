<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

$db = db();
$s = $db->query("SELECT COUNT(*) FROM services")->fetchColumn();
$c = $db->query("SELECT COUNT(*) FROM service_categories")->fetchColumn();
$a = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
echo "Categories: $c, Services: $s, Admins: $a\n";
