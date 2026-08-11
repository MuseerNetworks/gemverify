<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$pos = strpos($c, 'id="catFilter"');
if ($pos !== false) {
    echo "Found catFilter at $pos:\n";
    echo substr($c, $pos - 50, 200) . "\n";
} else {
    echo "catFilter NOT found!\n";
}
