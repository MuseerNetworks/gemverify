<?php
$c = file_get_contents('C:/xampp/htdocs/gemverify/api/database/seed.php');
// Let's write a simple regex or parser to pull all service name and slug combinations from seed.php
preg_match_all("/'name'\s*=>\s*['\"]([^'\"]+)['\"]\s*,\s*'slug'\s*=>\s*['\"]([^'\"]+)['\"]/i", $c, $matches);
foreach ($matches[1] as $idx => $name) {
    echo "Name: {$name} | Slug: {$matches[2][$idx]}\n";
}
