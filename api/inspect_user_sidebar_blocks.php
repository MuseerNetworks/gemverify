<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');

$pos = strpos($c, 'title:"NIN Services"');
if ($pos !== false) {
    echo "NIN Services block:\n";
    echo substr($c, $pos - 50, 800) . "\n\n";
}

$pos2 = strpos($c, 'title:"BVN Services"');
if ($pos2 !== false) {
    echo "BVN Services block:\n";
    echo substr($c, $pos2 - 50, 800) . "\n\n";
}
