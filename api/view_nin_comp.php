<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/user/index.html');
$pos = strpos($c, 'function $g({onProceed:e,dbPricing:pricingMap}){');
if ($pos !== false) {
    echo substr($c, $pos, 6000) . "\n";
}
