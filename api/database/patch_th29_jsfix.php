<?php
/**
 * TH-29 JS Injection Fix: inject the full fetchApiTransactions() JS block
 * into admin/index.html immediately before fetchAdminServices().
 */
declare(strict_types=1);

$file    = 'C:/xampp/htdocs/gemverify/admin/index.html';
$content = file_get_contents($file);

echo "=== TH-29 JS Fix: Injecting API Transactions JS ===\n\n";

// The full JS block to inject
$jsBlock = <<<'JSBLOCK'
// ── API Transactions Admin (TH-27/28/29) ─────────────────────────────────────
let atxnCurrentPage = 1;
let atxnCurrentRef  = null;

async function fetchApiTransactions(page) {
  page = page || 1;
  atxnCurrentPage = page;
  var token  = localStorage.getItem('gv_admin_token');
  if (!token) return;
  var status = (document.getElementById('atxn-filter-status') && document.getElementById('atxn-filter-status').value) || '';
  var search = ((document.getElementById('atxn-search') && document.getElementById('atxn-search').value) || '').trim();
  var qs = 'page=' + page;
  if (status) qs += '&status=' + encodeURIComponent(status);
  if (search) qs += '&search=' + encodeURIComponent(search);

  var tbody = document.getElementById('atxn-body');
  if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--muted)">Loading\u2026</td></tr>';

  fetch('../api/admin/api-transactions?' + qs, {
    headers: { 'Authorization': 'Bearer ' + token }
  }).then(function(res){ return res.json(); }).then(function(json){
    if (!json.success) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--danger)">Error loading transactions.</td></tr>';
      return;
    }
    var items = (json.data && json.data.items) || json.items || [];
    var totalPages = (json.data && json.data.total_pages) || json.total_pages || 1;
    renderAtxnTable(items);
    renderAtxnPagination(totalPages, page);
    fetchApiTxnStats();
  }).catch(function(e){
    if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--danger)">Network error.</td></tr>';
  });
}

function renderAtxnTable(items) {
  var tbody = document.getElementById('atxn-body');
  if (!tbody) return;
  if (!items || !items.length) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--muted)">No API transactions found.</td></tr>';
    return;
  }
  var statusColors = { pending:'#f59e0b', processing:'#3b82f6', completed:'#22c55e', failed:'#ef4444', refunded:'#8b5cf6' };
  tbody.innerHTML = items.map(function(tx) {
    var sc = statusColors[tx.gv_status] || 'var(--muted)';
    var method = tx.input_method ? tx.input_method.replace('by_','') : '\u2014';
    var ticket = tx.provider_ticket_id
      ? '<span title="' + tx.provider_ticket_id + '" style="font-family:monospace">' + tx.provider_ticket_id.substring(0,12) + '\u2026</span>'
      : (tx.result_type === 'pdf_base64' ? '<span style="color:#22c55e">PDF Sync</span>' : '\u2014');
    var refundBtn = (tx.gv_status==='failed'||tx.gv_status==='pending') && !tx.refund_issued
      ? '<button onclick="openAtxnRefundModal(\'' + tx.gv_reference + '\',\'' + ((tx.user && tx.user.business_name)||'').replace(/'/g,"\\'") + '\',' + (tx.price_paid||0) + ')" style="padding:4px 10px;border-radius:6px;font-size:11px;border:1px solid #ef4444;color:#ef4444;background:none;cursor:pointer;margin-left:4px">Refund</button>'
      : '';
    return '<tr>'
      + '<td style="font-family:monospace;font-size:12px">' + tx.gv_reference + '</td>'
      + '<td><div style="font-size:13px">' + ((tx.user && tx.user.business_name)||'\u2014') + '</div><div style="font-size:11px;opacity:.6">' + ((tx.user && tx.user.email)||'') + '</div></td>'
      + '<td style="font-size:13px">' + ((tx.service && tx.service.name)||'\u2014') + '</td>'
      + '<td style="font-size:12px;opacity:.8">' + (tx.variant_key||'\u2014') + '</td>'
      + '<td style="font-size:12px;text-transform:capitalize">' + method + '</td>'
      + '<td><span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:' + sc + '22;color:' + sc + '">' + tx.gv_status + '</span></td>'
      + '<td style="font-size:12px">' + ticket + '</td>'
      + '<td style="font-weight:600">\u20a6' + Number(tx.price_paid||0).toLocaleString() + '</td>'
      + '<td style="font-size:11px;opacity:.7">' + ((tx.submitted_at||'').substring(0,16)||'\u2014') + '</td>'
      + '<td style="white-space:nowrap"><button onclick="viewAtxnDetail(\'' + tx.gv_reference + '\')" style="padding:4px 10px;border-radius:6px;font-size:11px;border:1px solid var(--border);cursor:pointer;background:none;color:var(--text)">Detail</button>' + refundBtn + '</td>'
      + '</tr>';
  }).join('');
}

function renderAtxnPagination(totalPages, currentPage) {
  var container = document.getElementById('atxn-pagination');
  if (!container) return;
  if (totalPages <= 1) { container.innerHTML = ''; return; }
  var html = '';
  var max = Math.min(totalPages, 10);
  for (var i = 1; i <= max; i++) {
    var style = i === currentPage
      ? 'background:var(--primary);color:#fff;'
      : 'background:var(--card);color:var(--text);';
    html += '<button onclick="fetchApiTransactions(' + i + ')" style="padding:6px 12px;border-radius:8px;border:1px solid var(--border);cursor:pointer;' + style + 'font-size:13px">' + i + '</button>';
  }
  container.innerHTML = html;
}

function fetchApiTxnStats() {
  var token = localStorage.getItem('gv_admin_token');
  if (!token) return;
  fetch('../api/admin/api-transactions/stats', {
    headers: { 'Authorization': 'Bearer ' + token }
  }).then(function(res){ return res.json(); }).then(function(json){
    if (!json.success) return;
    var data  = json.data || json;
    var today = data.today || {};
    var stats = document.getElementById('atxn-stats');
    if (!stats) return;
    stats.innerHTML =
      '<div class="stat"><small>Today\'s Requests</small><strong>' + (today.cnt||0) + '</strong></div>' +
      '<div class="stat"><small>Today\'s Revenue</small><strong>\u20a6' + Number(today.revenue||0).toLocaleString() + '</strong></div>' +
      '<div class="stat"><small>Pending / Processing</small><strong style="color:#f59e0b">' + (data.pending_count||0) + '</strong></div>' +
      '<div class="stat"><small>Failed (Unrefunded)</small><strong style="color:#ef4444">' + (data.failed_pending_refund||0) + '</strong></div>';
  }).catch(function(e){});
}

function viewAtxnDetail(ref) {
  atxnCurrentRef = ref;
  var token   = localStorage.getItem('gv_admin_token');
  var modal   = document.getElementById('atxn-modal');
  var content = document.getElementById('atxn-modal-content');
  if (!modal || !content || !token) return;
  content.innerHTML = '<div style="text-align:center;padding:32px;opacity:.6">Loading\u2026</div>';
  modal.style.display = 'flex';
  var statusColors = { pending:'#f59e0b', processing:'#3b82f6', completed:'#22c55e', failed:'#ef4444', refunded:'#8b5cf6' };
  fetch('../api/admin/api-transactions/' + ref, {
    headers: { 'Authorization': 'Bearer ' + token }
  }).then(function(res){ return res.json(); }).then(function(json){
    if (!json.success) {
      content.innerHTML = '<div style="color:var(--danger)">Failed to load: ' + (json.message||'Unknown error') + '</div>';
      return;
    }
    var tx = json.data || json;
    var sc = statusColors[tx.gv_status] || 'var(--muted)';
    var errorRow = tx.error_code ? '<div style="grid-column:1/-1"><strong>Error</strong><br><span style="color:#ef4444">[' + tx.error_code + '] ' + (tx.error_message||'') + '</span></div>' : '';
    var summaryRow = tx.input_summary ? '<div style="grid-column:1/-1"><strong>Input Summary (masked)</strong><br><code style="font-size:11px;opacity:.8">' + tx.input_summary + '</code></div>' : '';
    var resultRow  = tx.has_result ? '<div style="grid-column:1/-1"><span style="color:#22c55e">\u2713 Result data available</span></div>' : '';
    var refundBtn  = (!tx.refund_issued && (tx.gv_status==='failed'||tx.gv_status==='pending'))
      ? '<div style="margin-top:16px;display:flex;justify-content:flex-end"><button onclick="openAtxnRefundModal(\'' + tx.gv_reference + '\',\'' + ((tx.user && tx.user.business_name)||'').replace(/'/g,"\\'") + '\',' + (tx.price_paid||0) + ')" style="padding:8px 18px;border-radius:8px;border:1px solid #ef4444;color:#ef4444;background:none;cursor:pointer">Flag for Refund</button></div>'
      : '';
    content.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">'
      + '<div><strong>GV Reference</strong><br><code style="font-size:12px">' + tx.gv_reference + '</code></div>'
      + '<div><strong>Status</strong><br><span style="color:' + sc + ';font-weight:600;text-transform:capitalize">' + tx.gv_status + '</span></div>'
      + '<div><strong>Service</strong><br>' + ((tx.service && tx.service.name)||'\u2014') + '</div>'
      + '<div><strong>Variant</strong><br>' + (tx.variant_key||'\u2014') + '</div>'
      + '<div><strong>User</strong><br>' + ((tx.user && tx.user.business_name)||'\u2014') + '<br><span style="opacity:.6;font-size:11px">' + ((tx.user && tx.user.email)||'') + '</span></div>'
      + '<div><strong>Amount Paid</strong><br><span style="font-weight:700">\u20a6' + Number(tx.price_paid||0).toLocaleString() + '</span></div>'
      + '<div><strong>Input Method</strong><br>' + (tx.input_method||'\u2014') + '</div>'
      + '<div><strong>Result Type</strong><br>' + (tx.result_type||'\u2014') + '</div>'
      + '<div><strong>Provider</strong><br>' + (tx.provider||'techhub') + '</div>'
      + '<div><strong>Provider Status</strong><br>' + (tx.provider_status||'\u2014') + '</div>'
      + '<div><strong>Ticket ID</strong><br><code style="font-size:11px">' + (tx.provider_ticket_id||'\u2014') + '</code></div>'
      + '<div><strong>Provider TxnID</strong><br><code style="font-size:11px">' + (tx.provider_txn_id||'\u2014') + '</code></div>'
      + '<div><strong>Submitted</strong><br>' + (tx.submitted_at||'\u2014') + '</div>'
      + '<div><strong>Completed</strong><br>' + (tx.completed_at||'\u2014') + '</div>'
      + '<div><strong>Last Polled</strong><br>' + (tx.last_checked_at||'\u2014') + '</div>'
      + '<div><strong>Refund Issued</strong><br>' + (tx.refund_issued ? '<span style="color:#8b5cf6">Yes</span>' : 'No') + '</div>'
      + errorRow + summaryRow + resultRow
      + '</div>' + refundBtn;
  }).catch(function(e){
    content.innerHTML = '<div style="color:var(--danger)">Network error: ' + e.message + '</div>';
  });
}

function openAtxnRefundModal(ref, userName, price) {
  atxnCurrentRef = ref;
  var info  = document.getElementById('atxn-refund-info');
  var note  = document.getElementById('atxn-refund-note');
  var modal = document.getElementById('atxn-refund-modal');
  if (info) info.textContent = 'Flag ' + ref + ' (' + userName + ') \u2014 \u20a6' + Number(price).toLocaleString() + ' for wallet refund. This marks the transaction and sets status to "refunded". Process actual wallet credit separately.';
  if (note) note.value = '';
  if (modal) modal.style.display = 'flex';
  document.getElementById('atxn-modal').style.display = 'none';
  var btn = document.getElementById('atxn-refund-confirm');
  if (btn) btn.onclick = function(){ confirmAtxnRefund(ref); };
}

function confirmAtxnRefund(ref) {
  var noteEl = document.getElementById('atxn-refund-note');
  var note   = noteEl ? noteEl.value.trim() : '';
  var token  = localStorage.getItem('gv_admin_token');
  if (!note) { alert('Please enter a reason.'); return; }
  var btn = document.getElementById('atxn-refund-confirm');
  if (btn) { btn.disabled = true; btn.textContent = 'Processing\u2026'; }
  fetch('../api/admin/api-transactions/' + ref + '/refund-flag', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
    body: JSON.stringify({ note: note })
  }).then(function(res){ return res.json(); }).then(function(json){
    if (json.success) {
      document.getElementById('atxn-refund-modal').style.display = 'none';
      fetchApiTransactions(atxnCurrentPage);
      var uid = (json.data && json.data.user_id) || json.user_id || '?';
      alert('\u2713 ' + ref + ' flagged for refund. Process wallet credit for user_id=' + uid + '.');
    } else {
      alert('Error: ' + (json.message || 'Unknown error'));
    }
  }).catch(function(e){
    alert('Network error: ' + e.message);
  }).finally(function(){
    if (btn) { btn.disabled = false; btn.textContent = 'Confirm Refund Flag'; }
  });
}

JSBLOCK;

// Use a highly unique anchor — the exact comment + let services = [] pattern
$anchor = "let services = [];\n\nasync function fetchAdminServices()";

if (str_contains($content, $anchor)) {
    $content = str_replace($anchor, $jsBlock . "let services = [];\n\nasync function fetchAdminServices()", $content);
    file_put_contents($file, $content);
    echo "[FIX 3] Injected full JS block before fetchAdminServices()\n";
} else {
    // Try simpler anchor
    $anchor2 = "async function fetchAdminServices()";
    if (str_contains($content, $anchor2)) {
        $content = str_replace($anchor2, $jsBlock . "async function fetchAdminServices()", $content);
        file_put_contents($file, $content);
        echo "[FIX 3b] Injected JS using fetchAdminServices() anchor\n";
    } else {
        echo "[ERROR] Could not find JS injection anchor\n";
        exit(1);
    }
}

// ── Verify all ────────────────────────────────────────────────────────────────
echo "\n=== Final Verification ===\n";
$final = file_get_contents($file);
$checks = [
    'Nav button'                 => 'data-page="api-transactions"',
    'Page section'               => 'id="page-api-transactions"',
    'Stats widget'               => 'atxn-stats',
    'Table body'                 => 'atxn-body',
    'Detail modal'               => 'id="atxn-modal"',
    'Refund modal'               => 'id="atxn-refund-modal"',
    'function fetchApiTransactions' => 'function fetchApiTransactions',
    'function fetchApiTxnStats'  => 'function fetchApiTxnStats',
    'function renderAtxnTable'   => 'function renderAtxnTable',
    'function renderAtxnPagination' => 'function renderAtxnPagination',
    'function viewAtxnDetail'    => 'function viewAtxnDetail',
    'function openAtxnRefundModal' => 'function openAtxnRefundModal',
    'function confirmAtxnRefund' => 'function confirmAtxnRefund',
    'API endpoint'               => '../api/admin/api-transactions',
    'Status filter'              => 'atxn-filter-status',
    'Search input'               => 'atxn-search',
];
$pass = $fail = 0;
foreach ($checks as $label => $needle) {
    $found = str_contains($final, $needle);
    echo ($found ? '[PASS]' : '[FAIL]') . " $label\n";
    $found ? $pass++ : $fail++;
}
echo "\nResult: {$pass} PASS, {$fail} FAIL | File size: " . number_format(strlen($final)) . " bytes\n";
if ($fail === 0) echo "\nTH-29 COMPLETE ✓\n";
