<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$pos = 0;
while (($pos = strpos($c, 'localStorage.setItem', $pos)) !== false) {
    echo "Found localStorage.setItem at $pos: " . substr($c, $pos, 100) . "\n";
    $pos += 20;
}
