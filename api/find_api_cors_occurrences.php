<?php
// Recurse directories to find CORS headers in api
function searchDir($dir) {
    $it = new RecursiveDirectoryIterator($dir);
    foreach (new RecursiveIteratorIterator($it) as $file) {
        if ($file->isDir()) continue;
        if ($file->getExtension() !== 'php') continue;
        $c = file_get_contents($file->getPathname());
        if (strpos($c, 'Access-Control-Allow-Origin') !== false) {
            echo "Found in: " . $file->getPathname() . "\n";
            // Print matching lines
            $lines = explode("\n", $c);
            foreach ($lines as $num => $line) {
                if (strpos($line, 'Access-Control-Allow-Origin') !== false) {
                    echo "  Line " . ($num + 1) . ": " . trim($line) . "\n";
                }
            }
        }
    }
}
searchDir('C:/xampp/htdocs/gemverify/api');
