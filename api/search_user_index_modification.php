<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$pos1 = stripos($c, 'modification-ipe');
if ($pos1 !== false) {
    echo "Found modification-ipe at: " . substr($c, $pos1 - 40, 100) . "\n";
} else {
    echo "modification-ipe NOT found in user/index.html\n";
}

$pos2 = stripos($c, 'Modification IPE');
if ($pos2 !== false) {
    echo "Found Modification IPE at: " . substr($c, $pos2 - 40, 100) . "\n";
} else {
    echo "Modification IPE NOT found in user/index.html\n";
}

$pos3 = stripos($c, '\bry\b');
if ($pos3 !== false) {
    echo "Found boundary ry at: " . substr($c, $pos3 - 40, 100) . "\n";
}
