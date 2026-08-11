<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$pos = 0;
$count = 0;
while (($pos = strpos($c, 'getServicePrice', $pos)) !== false) {
    echo "Found at offset $pos: " . substr($c, $pos - 40, 100) . "\n";
    $pos += 15;
    $count++;
}
echo "Total getServicePrice occurrences: $count\n";
