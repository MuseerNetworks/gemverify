<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
$pos_start = strpos($c, 'const services=[');
if ($pos_start !== false) {
    echo "const services starts at: $pos_start\n";
    // Walk to the closing bracket of the array
    $brace_count = 1;
    $idx = $pos_start + 15; // after const services=[
    while ($c[$idx] !== ']' && $idx < strlen($c)) {
        $idx++;
    }
    $idx++; // skip ]
    // Skip trailing semicolon
    if ($c[$idx] === ';') $idx++;
    echo "const services ends at: $idx\n";
    echo "Total list length: " . ($idx - $pos_start) . "\n";
    echo "Excerpt:\n" . substr($c, $pos_start, 150) . "\n...\n" . substr($c, $idx - 150, 150) . "\n";
} else {
    echo "const services=[ NOT found!\n";
}
