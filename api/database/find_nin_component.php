<?php
$html = file_get_contents('C:/xampp/htdocs/gemverify/admin/index.html');
echo "File size: " . number_format(strlen($html)) . " bytes\n\n";

$checks = [
    'fetchApiTransactions'   => 'function fetchApiTransactions',
    'fetchApiTxnStats'       => 'fetchApiTxnStats',
    'renderAtxnTable'        => 'renderAtxnTable',
    'renderAtxnPagination'   => 'renderAtxnPagination',
    'viewAtxnDetail'         => 'viewAtxnDetail',
    'openAtxnRefundModal'    => 'openAtxnRefundModal',
    'confirmAtxnRefund'      => 'confirmAtxnRefund',
    'api/admin/api-transactions' => '../api/admin/api-transactions',
    'atxn-stats'             => 'atxn-stats',
    'atxn-body'              => 'atxn-body',
    'atxn-filter-status'     => 'atxn-filter-status',
    'atxn-search'            => 'atxn-search',
    'atxn-modal'             => 'atxn-modal',
    'atxn-refund-modal'      => 'atxn-refund-modal',
    'page-api-transactions'  => 'id="page-api-transactions"',
    'nav-button'             => 'data-page="api-transactions"',
];

foreach ($checks as $label => $needle) {
    $found = str_contains($html, $needle);
    $pos   = strpos($html, $needle);
    echo "[" . ($found ? 'YES ✓' : 'NO  ✗') . "] $label" . ($found ? " (pos: $pos)" : '') . "\n";
}

// Find where fetchApiTransactions is defined (to see if it's the full version or a stub)
$pos = strpos($html, 'fetchApiTransactions');
if ($pos !== false) {
    echo "\n--- Context around fetchApiTransactions ---\n";
    echo substr($html, max(0, $pos-30), 500) . "\n";
}
