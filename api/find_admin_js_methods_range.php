<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$pos_render = strpos($c, 'function renderServices()');
$pos_save = strpos($c, 'function saveService(');
if ($pos_render !== false && $pos_save !== false) {
    echo "renderServices starts at: $pos_render\n";
    echo "saveService starts at: $pos_save\n";
    // Find the end of saveService
    $brace_count = 1;
    $idx = $pos_save;
    while ($c[$idx] !== '{' && $idx < strlen($c)) {
        $idx++;
    }
    $idx++;
    while ($brace_count > 0 && $idx < strlen($c)) {
        if ($c[$idx] === '{') $brace_count++;
        if ($c[$idx] === '}') $brace_count--;
        $idx++;
    }
    echo "saveService ends at: $idx\n";
    echo "Total block length: " . ($idx - $pos_render) . "\n";
    echo "Block excerpt:\n" . substr($c, $pos_render, 200) . "\n...\n" . substr($c, $idx - 200, 200) . "\n";
} else {
    echo "Not found!\n";
}
