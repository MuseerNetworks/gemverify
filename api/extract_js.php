<?php
$html = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$startTag = '<script>';
$endTag = '</script>';

$startPos = strpos($html, $startTag);
$endPos = strrpos($html, $endTag);

if ($startPos === false || $endPos === false) {
    echo "Could not find script block.\n";
    exit(1);
}

$js = substr($html, $startPos + strlen($startTag), $endPos - $startPos - strlen($startTag));
file_put_contents('C:/xampp/htdocs/gemverify/api/admin_script.js', $js);
echo "Extracted JS to admin_script.js. Running node syntax check...\n";
