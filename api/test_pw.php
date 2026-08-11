<?php
$hashes = [
    'abuabdillah3916@gmail.com' => '$2y$10$/Ki2CWRVKfkogqv/txUUAOVV0EgB/bH2aieh7o7BEFapw7CgD1r9C',
    'test_verify_user@gemverify.com' => '$2y$10$KtLll1x6hmzTFxNwEoNQyu8zqwt0C5oqy6GZG1Cp/VALzy4z4LRC.'
];
$tries = ['password123','password','GemVerify2026','admin123','123456','gemverify','test123','Gemverify123','Pass@123'];
foreach ($hashes as $email => $hash) {
    echo "== $email ==\n";
    foreach ($tries as $pw) {
        if (password_verify($pw, $hash)) {
            echo "  MATCH: $pw\n";
        }
    }
}
echo "done\n";
