<?php
/**
 * TH-29 Patch: Add API Transactions section to admin/index.html
 *
 * Changes:
 *   1. Add nav button inside OPERATIONS nav-sub (after "Rejected")
 *   2. Insert page-api-transactions section HTML (after page-transactions section)
 *   3. Insert fetchApiTransactions() JS (before fetchAdminServices())
 */
declare(strict_types=1);

$file    = 'C:/xampp/htdocs/gemverify/admin/index.html';
$content = file_get_contents($file);
$changed = false;

echo "=== TH-29: Admin API Transactions UI Patch ===\n\n";

// ── 1. Nav button ─────────────────────────────────────────────────────────────
$oldNav = "go('rejected',this)\">⊘　Rejected</button>
    </div>
    </div>";
$newNav = "go('rejected',this)\">⊘　Rejected</button>
    <button data-page=\"api-transactions\" onclick=\"go('api-transactions',this)\">⚡　API Transactions</button>
    </div>
    </div>";

if (str_contains($content, $oldNav)) {
    $content = str_replace($oldNav, $newNav, $content);
    $changed = true;
    echo "[FIX 1] Added 'API Transactions' nav button under OPERATIONS\n";
} elseif (str_contains($content, 'api-transactions')) {
    echo "[SKIP 1] api-transactions nav button already present\n";
} else {
    echo "[ERROR 1] Could not find rejected nav button anchor\n";
}

// ── 2. Page section HTML ──────────────────────────────────────────────────────
// Insert after page-transactions section (before page-gemprint)
$pageSection = <<<'HTML'

<section id="page-api-transactions" class="page"><div class="heading"><div><h1>API Transactions</h1><p>TechHub-powered service requests — NIN, BVN, Self-Service, Personalization, IPE.</p></div><div style="display:flex;gap:8px;align-items:center"><select id="atxn-filter-status" onchange="fetchApiTransactions(1)" style="height:36px;padding:0 10px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:13px"><option value="">All Statuses</option><option value="pending">Pending</option><option value="processing">Processing</option><option value="completed">Completed</option><option value="failed">Failed</option><option value="refunded">Refunded</option></select><input id="atxn-search" type="text" placeholder="Search ref / ticket…" onkeydown="if(event.key==='Enter')fetchApiTransactions(1)" style="height:36px;padding:0 10px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:13px;width:200px"><button class="primary" onclick="fetchApiTransactions(1)" style="height:36px;padding:0 14px;font-size:13px">🔄 Refresh</button></div></div><div id="atxn-stats" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px"></div><div class="card"><table id="atxn-table"><thead><tr><th>GV Reference</th><th>User</th><th>Service</th><th>Variant</th><th>Method</th><th>Status</th><th>Provider</th><th>Amount</th><th>Submitted</th><th>Actions</th></tr></thead><tbody id="atxn-body"><tr><td colspan="10" style="text-align:center;padding:24px;color:var(--muted)">Loading…</td></tr></tbody></table></div><div id="atxn-pagination" style="display:flex;justify-content:center;gap:8px;margin-top:16px"></div>

<!-- API Transaction Detail Modal -->
<div id="atxn-modal" style="display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
<div style="background:var(--card);border-radius:16px;max-width:720px;width:100%;max-height:85vh;overflow-y:auto;padding:28px;position:relative">
<button onclick="document.getElementById('atxn-modal').style.display='none'" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text)">✕</button>
<h2 style="margin:0 0 20px;font-size:18px">API Transaction Detail</h2>
<div id="atxn-modal-content"></div>
</div></div>

<!-- Refund Flag Modal -->
<div id="atxn-refund-modal" style="display:none;position:fixed;inset:0;z-index:210;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px">
<div style="background:var(--card);border-radius:16px;max-width:480px;width:100%;padding:28px;position:relative">
<button onclick="document.getElementById('atxn-refund-modal').style.display='none'" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text)">✕</button>
<h2 style="margin:0 0 16px;font-size:16px">⚠ Flag for Refund</h2>
<p id="atxn-refund-info" style="font-size:13px;margin-bottom:12px;opacity:.7"></p>
<textarea id="atxn-refund-note" placeholder="Reason for refund…" style="width:100%;height:80px;padding:10px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:13px;resize:vertical;box-sizing:border-box"></textarea>
<div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end">
<button onclick="document.getElementById('atxn-refund-modal').style.display='none'" style="padding:8px 16px;border-radius:8px;border:1px solid var(--border);background:none;color:var(--text);cursor:pointer">Cancel</button>
<button id="atxn-refund-confirm" class="primary" style="padding:8px 18px;border-radius:8px">Confirm Refund Flag</button>
</div></div></div>

</section>
HTML;

$oldPageAnchor = '<section id="page-gemprint"';
$newPageAnchor = $pageSection . "\n<section id=\"page-gemprint\"";

if (str_contains($content, $oldPageAnchor) && !str_contains($content, 'id="page-api-transactions"')) {
    $content = str_replace($oldPageAnchor, $newPageAnchor, $content);
    $changed = true;
    echo "[FIX 2] Inserted page-api-transactions section HTML\n";
} elseif (str_contains($content, 'id="page-api-transactions"')) {
    echo "[SKIP 2] page-api-transactions section already present\n";
} else {
    echo "[ERROR 2] page-gemprint anchor not found\n";
}

// ── 3. JS Functions ───────────────────────────────────────────────────────────
$jsBlock = <<<'JSBLOCK'
// ── API Transactions Admin (TH-27/28/29) ─────────────────────────────────────
let atxnCurrentPage = 1;
let atxnCurrentRef  = null;

async function fetchApiTransactions(page = 1) {
  atxnCurrentPage = page;
  const token  = localStorage.getItem('gv_admin_token');
  if (!token) return;
  const status = document.getElementById('atxn-filter-status')?.value || '';
  const search = document.getElementById('atxn-search')?.value?.trim() || '';
  const params = new URLSearchParams({ page });
  if (status) params.set('status', status);
  if (search) params.set('search', search);

  const tbody = document.getElementById('atxn-body');
  if (tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--muted)">Loading…</td></tr>';

  try {
    const res  = await fetch(`../api/admin/api-transactions?${params}`, {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    const json = await res.json();
    if (!res.ok || !json.success) {
      if (tbody) tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:var(--danger)">Error loading transactions.</td></tr>`;
      return;
    }
    renderAtxnTable(json.data?.items || json.items || []);
    renderAtxnPagination(json.data?.total_pages || json.total_pages || 1, page);
    fetchApiTxnStats();
  } catch (e) {
    if (tbody) tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;color:var(--danger)">Network error.</td></tr>`;
  }
}

function renderAtxnTable(items) {
  const tbody = document.getElementById('atxn-body');
  if (!tbody) return;
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:24px;color:var(--muted)">No API transactions found.</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(tx => {
    const statusColor = {
      pending:'#f59e0b', processing:'#3b82f6', completed:'#22c55e',
      failed:'#ef4444', refunded:'#8b5cf6'
    }[tx.gv_status] || 'var(--muted)';
    const method = tx.input_method ? tx.input_method.replace('by_','') : '—';
    return `<tr>
      <td style="font-family:monospace;font-size:12px">${tx.gv_reference}</td>
      <td><div style="font-size:13px">${tx.user?.business_name||'—'}</div><div style="font-size:11px;opacity:.6">${tx.user?.email||''}</div></td>
      <td><div style="font-size:13px">${tx.service?.name||'—'}</div></td>
      <td style="font-size:12px;opacity:.8">${tx.variant_key||'—'}</td>
      <td style="font-size:12px;text-transform:capitalize">${method}</td>
      <td><span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${statusColor}22;color:${statusColor}">${tx.gv_status}</span></td>
      <td style="font-size:12px">${tx.provider_ticket_id ? `<span title="${tx.provider_ticket_id}" style="font-family:monospace">${tx.provider_ticket_id.substring(0,12)}…</span>` : (tx.result_type==='pdf_base64'?'<span style="color:#22c55e">PDF Sync</span>':'—')}</td>
      <td style="font-weight:600">₦${Number(tx.price_paid||0).toLocaleString()}</td>
      <td style="font-size:11px;opacity:.7">${tx.submitted_at ? tx.submitted_at.substring(0,16) : '—'}</td>
      <td style="white-space:nowrap">
        <button onclick="viewAtxnDetail('${tx.gv_reference}')" style="padding:4px 10px;border-radius:6px;font-size:11px;border:1px solid var(--border);cursor:pointer;background:none;color:var(--text)">Detail</button>
        ${(tx.gv_status==='failed'||tx.gv_status==='pending')&&!tx.refund_issued ? `<button onclick="openAtxnRefundModal('${tx.gv_reference}','${tx.user?.business_name||''}',${tx.price_paid||0})" style="padding:4px 10px;border-radius:6px;font-size:11px;border:1px solid #ef4444;color:#ef4444;background:none;cursor:pointer;margin-left:4px">Refund</button>` : ''}
      </td>
    </tr>`;
  }).join('');
}

function renderAtxnPagination(totalPages, currentPage) {
  const container = document.getElementById('atxn-pagination');
  if (!container || totalPages <= 1) { if(container) container.innerHTML=''; return; }
  let html = '';
  for (let i = 1; i <= Math.min(totalPages, 10); i++) {
    const active = i === currentPage ? 'background:var(--primary);color:#fff;' : 'background:var(--card);color:var(--text);';
    html += `<button onclick="fetchApiTransactions(${i})" style="padding:6px 12px;border-radius:8px;border:1px solid var(--border);cursor:pointer;${active}font-size:13px">${i}</button>`;
  }
  container.innerHTML = html;
}

async function fetchApiTxnStats() {
  const token = localStorage.getItem('gv_admin_token');
  if (!token) return;
  try {
    const res  = await fetch('../api/admin/api-transactions/stats', {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    const json = await res.json();
    if (!res.ok || !json.success) return;
    const data  = json.data || json;
    const today = data.today || {};
    const stats = document.getElementById('atxn-stats');
    if (!stats) return;
    stats.innerHTML = `
      <div class="stat"><small>Today's Requests</small><strong>${today.cnt||0}</strong></div>
      <div class="stat"><small>Today's Revenue</small><strong>₦${Number(today.revenue||0).toLocaleString()}</strong></div>
      <div class="stat"><small>Pending / Processing</small><strong style="color:#f59e0b">${data.pending_count||0}</strong></div>
      <div class="stat"><small>Failed (Unrefunded)</small><strong style="color:#ef4444">${data.failed_pending_refund||0}</strong></div>
    `;
  } catch (e) { /* silent */ }
}

async function viewAtxnDetail(ref) {
  atxnCurrentRef = ref;
  const token = localStorage.getItem('gv_admin_token');
  const modal   = document.getElementById('atxn-modal');
  const content = document.getElementById('atxn-modal-content');
  if (!modal || !content || !token) return;
  content.innerHTML = '<div style="text-align:center;padding:32px;opacity:.6">Loading…</div>';
  modal.style.display = 'flex';
  try {
    const res  = await fetch(`../api/admin/api-transactions/${ref}`, {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    const json = await res.json();
    if (!res.ok || !json.success) {
      content.innerHTML = `<div style="color:var(--danger)">Failed to load: ${json.message||'Unknown error'}</div>`;
      return;
    }
    const tx = json.data || json;
    const statusColor = {
      pending:'#f59e0b', processing:'#3b82f6', completed:'#22c55e',
      failed:'#ef4444', refunded:'#8b5cf6'
    }[tx.gv_status] || 'var(--muted)';
    content.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
        <div><strong>GV Reference</strong><br><code style="font-size:12px">${tx.gv_reference}</code></div>
        <div><strong>Status</strong><br><span style="color:${statusColor};font-weight:600;text-transform:capitalize">${tx.gv_status}</span></div>
        <div><strong>Service</strong><br>${tx.service?.name||'—'}</div>
        <div><strong>Variant</strong><br>${tx.variant_key||'—'}</div>
        <div><strong>User</strong><br>${tx.user?.business_name||'—'}<br><span style="opacity:.6;font-size:11px">${tx.user?.email||''}</span></div>
        <div><strong>Amount Paid</strong><br><span style="font-weight:700">₦${Number(tx.price_paid||0).toLocaleString()}</span></div>
        <div><strong>Input Method</strong><br>${tx.input_method||'—'}</div>
        <div><strong>Result Type</strong><br>${tx.result_type||'—'}</div>
        <div><strong>Provider</strong><br>${tx.provider||'techhub'}</div>
        <div><strong>Provider Status</strong><br>${tx.provider_status||'—'}</div>
        <div><strong>Ticket ID</strong><br><code style="font-size:11px">${tx.provider_ticket_id||'—'}</code></div>
        <div><strong>Provider TxnID</strong><br><code style="font-size:11px">${tx.provider_txn_id||'—'}</code></div>
        <div><strong>Submitted</strong><br>${tx.submitted_at||'—'}</div>
        <div><strong>Completed</strong><br>${tx.completed_at||'—'}</div>
        <div><strong>Last Polled</strong><br>${tx.last_checked_at||'—'}</div>
        <div><strong>Refund Issued</strong><br>${tx.refund_issued?'<span style="color:#8b5cf6">Yes</span>':'No'}</div>
        ${tx.error_code ? `<div style="grid-column:1/-1"><strong>Error</strong><br><span style="color:#ef4444">[${tx.error_code}] ${tx.error_message||''}</span></div>` : ''}
        ${tx.input_summary ? `<div style="grid-column:1/-1"><strong>Input Summary (masked)</strong><br><code style="font-size:11px;opacity:.8">${tx.input_summary}</code></div>` : ''}
        ${tx.has_result ? '<div style="grid-column:1/-1"><span style="color:#22c55e">✓ Result data available</span></div>' : ''}
      </div>
      ${(!tx.refund_issued && (tx.gv_status==='failed'||tx.gv_status==='pending')) ?
        `<div style="margin-top:16px;display:flex;justify-content:flex-end">
           <button onclick="openAtxnRefundModal('${tx.gv_reference}','${tx.user?.business_name||''}',${tx.price_paid||0})" style="padding:8px 18px;border-radius:8px;border:1px solid #ef4444;color:#ef4444;background:none;cursor:pointer">Flag for Refund</button>
         </div>` : ''}
    `;
  } catch (e) {
    content.innerHTML = `<div style="color:var(--danger)">Network error: ${e.message}</div>`;
  }
}

function openAtxnRefundModal(ref, userName, price) {
  atxnCurrentRef = ref;
  const info = document.getElementById('atxn-refund-info');
  const note = document.getElementById('atxn-refund-note');
  const modal = document.getElementById('atxn-refund-modal');
  if (info) info.textContent = `Flag ${ref} (${userName}) — ₦${Number(price).toLocaleString()} for wallet refund. This marks the transaction and sets status to "refunded". Process actual wallet credit separately.`;
  if (note) note.value = '';
  if (modal) modal.style.display = 'flex';
  // Close detail modal if open
  document.getElementById('atxn-modal').style.display = 'none';
  
  const btn = document.getElementById('atxn-refund-confirm');
  if (btn) btn.onclick = () => confirmAtxnRefund(ref);
}

async function confirmAtxnRefund(ref) {
  const note  = document.getElementById('atxn-refund-note')?.value?.trim();
  const token = localStorage.getItem('gv_admin_token');
  if (!note) { alert('Please enter a reason.'); return; }
  const btn = document.getElementById('atxn-refund-confirm');
  if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }
  try {
    const res  = await fetch(`../api/admin/api-transactions/${ref}/refund-flag`, {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ note })
    });
    const json = await res.json();
    if (res.ok && json.success) {
      document.getElementById('atxn-refund-modal').style.display = 'none';
      fetchApiTransactions(atxnCurrentPage);
      alert(`✓ ${ref} flagged for refund. Process wallet credit for user_id=${json.data?.user_id||json.user_id}.`);
    } else {
      alert('Error: ' + (json.message || 'Unknown error'));
    }
  } catch (e) {
    alert('Network error: ' + e.message);
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Confirm Refund Flag'; }
  }
}

JSBLOCK;

$oldJsAnchor = 'async function fetchAdminServices()';
$newJsAnchor = $jsBlock . 'async function fetchAdminServices()';

if (str_contains($content, $oldJsAnchor) && !str_contains($content, 'fetchApiTransactions')) {
    $content = str_replace($oldJsAnchor, $newJsAnchor, $content);
    $changed = true;
    echo "[FIX 3] Injected fetchApiTransactions() JS block\n";
} elseif (str_contains($content, 'fetchApiTransactions')) {
    echo "[SKIP 3] fetchApiTransactions() already present\n";
} else {
    echo "[ERROR 3] fetchAdminServices() JS anchor not found\n";
}

// Also wire fetchApiTransactions to go() page switch
// Find where go() calls page-specific loaders (if any) or add to a switch/if block
$goLoadHook = "if(page==='requests'";
$newGoLoadHook = "if(page==='api-transactions'){fetchApiTransactions(1);}
 if(page==='requests'";
if (str_contains($content, $goLoadHook) && !str_contains($content, "page==='api-transactions'")) {
    $content = str_replace($goLoadHook, $newGoLoadHook, $content);
    $changed = true;
    echo "[FIX 4] Wired fetchApiTransactions() to go() page switch\n";
} else {
    // Try alternative: look for onload pattern
    $dashboardLoad = "if(page==='dashboard'";
    if (str_contains($content, $dashboardLoad) && !str_contains($content, "page==='api-transactions'")) {
        $content = str_replace($dashboardLoad, "if(page==='api-transactions'){fetchApiTransactions(1);}\n if(page==='dashboard'", $content);
        $changed = true;
        echo "[FIX 4b] Wired fetchApiTransactions() to go() via dashboard branch\n";
    } else {
        echo "[INFO 4] go() trigger not wired (data loaded on nav click via onclick - acceptable)\n";
    }
}

// ── Write ─────────────────────────────────────────────────────────────────────
if ($changed) {
    file_put_contents($file, $content);
    echo "\n[WRITE] Saved. Size: " . number_format(strlen($content)) . " bytes\n";
} else {
    echo "\n[INFO] No changes written.\n";
}

// ── Verify ────────────────────────────────────────────────────────────────────
echo "\n=== Verification ===\n";
$final = file_get_contents($file);
echo "Nav button: "           . (str_contains($final, '"api-transactions"') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Page section: "         . (str_contains($final, 'id="page-api-transactions"') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Stats widgets: "        . (str_contains($final, 'atxn-stats') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Table: "                . (str_contains($final, 'atxn-table') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Detail modal: "         . (str_contains($final, 'atxn-modal') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Refund modal: "         . (str_contains($final, 'atxn-refund-modal') ? 'YES ✓' : 'NO ✗') . "\n";
echo "fetchApiTransactions: " . (str_contains($final, 'fetchApiTransactions') ? 'YES ✓' : 'NO ✗') . "\n";
echo "fetchApiTxnStats: "     . (str_contains($final, 'fetchApiTxnStats') ? 'YES ✓' : 'NO ✗') . "\n";
echo "viewAtxnDetail: "       . (str_contains($final, 'viewAtxnDetail') ? 'YES ✓' : 'NO ✗') . "\n";
echo "openAtxnRefundModal: "  . (str_contains($final, 'openAtxnRefundModal') ? 'YES ✓' : 'NO ✗') . "\n";
echo "confirmAtxnRefund: "    . (str_contains($final, 'confirmAtxnRefund') ? 'YES ✓' : 'NO ✗') . "\n";
echo "renderAtxnTable: "      . (str_contains($final, 'renderAtxnTable') ? 'YES ✓' : 'NO ✗') . "\n";
echo "renderAtxnPagination: " . (str_contains($final, 'renderAtxnPagination') ? 'YES ✓' : 'NO ✗') . "\n";
echo "API endpoint wired: "   . (str_contains($final, 'api/admin/api-transactions') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Status filter: "        . (str_contains($final, 'atxn-filter-status') ? 'YES ✓' : 'NO ✗') . "\n";
echo "Search input: "         . (str_contains($final, 'atxn-search') ? 'YES ✓' : 'NO ✗') . "\n";
