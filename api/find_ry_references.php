<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
// Let's search for references to ry as a react component or variable
preg_match_all("/\bry\b/i", $c, $matches, PREG_OFFSET_CAPTURE);
foreach ($matches[0] as $match) {
    $pos = $match[1];
    echo "Found 'ry' at offset $pos: " . substr($c, $pos - 30, 70) . "\n";
}
