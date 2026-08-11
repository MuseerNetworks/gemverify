<?php
require 'C:/xampp/htdocs/gemverify/api/config/database.php';
$db = db();
$tables = ['users', 'wallets', 'transactions', 'manual_requests', 'request_documents', 'result_files', 'refunds', 'notifications', 'audit_logs'];
foreach ($tables as $t) {
    echo $t . ': ' . $db->query("SELECT COUNT(*) FROM $t")->fetchColumn() . "\n";
}
