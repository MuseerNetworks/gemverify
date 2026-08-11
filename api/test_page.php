<?php
$ch = curl_init('http://localhost/gemverify/admin/index.html');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Admin index.html HTTP CODE: " . $code . "\n";
echo "Length: " . strlen($res) . "\n";
