<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$pos = strpos($c, 'function saveService(');
if ($pos !== false) {
    echo "Found saveService. Context:\n";
    echo substr($c, $pos, 1000) . "\n";
} else {
    echo "saveService NOT found!\n";
}
