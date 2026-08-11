/**
 * GemVerify Admin Portal Logic
 */

const services = [
  {id:'nin-validation',cat:'NIN',name:'NIN Validation',price:500,visible:true,active:true,type:'Manual'},
  {id:'ipe-modification',cat:'NIN',name:'IPE Modification',price:2000,visible:true,active:true,type:'Manual'},
  {id:'personalisation',cat:'NIN',name:'NIN Personalisation',price:2500,visible:true,active:true,type:'Manual'},
  {id:'nin-modification',cat:'NIN',name:'NIN Modification',price:2500,visible:true,active:true,type:'Manual'},
  {id:'dob-5y',cat:'NIN',name:'DOB More Than 5 Years',price:3500,visible:true,active:true,type:'Manual'},
  {id:'delinking',cat:'NIN',name:'Self-Service Delinking',price:3000,visible:true,active:true,type:'Manual'},
  {id:'bvn-license',cat:'BVN',name:'BVN License Creation',price:2500,visible:true,active:true,type:'Manual'},
  {id:'bvn-nonappearance',cat:'BVN',name:'BVN Non-Appearance',price:3000,visible:true,active:true,type:'Manual'},
  {id:'bvn-risk',cat:'BVN',name:'Central Risk Management',price:3000,visible:true,active:true,type:'Manual'},
  {id:'bvn-modification',cat:'BVN',name:'BVN Modification',price:2500,visible:true,active:true,type:'Manual'},
  {id:'jamb-result',cat:'JAMB',name:'JAMB Original Result',price:1500,visible:true,active:true,type:'Manual'},
  {id:'jamb-slip',cat:'JAMB',name:'2026 Exam Slip',price:1000,visible:true,active:true,type:'Manual'},
  {id:'jamb-admission',cat:'JAMB',name:'Admission Letter',price:1500,visible:true,active:true,type:'Manual'},
  {id:'jamb-reprints',cat:'JAMB',name:'Re-Prints / Other',price:1000,visible:true,active:true,type:'Manual'},
  {id:'jamb-original-reprint',cat:'JAMB',name:'Original Result Reprint',price:1000,visible:true,active:true,type:'Manual'},
  {id:'cac-business',cat:'CAC',name:'Business Name Registration',price:5000,visible:true,active:true,type:'Manual'},
  {id:'cac-ltd',cat:'CAC',name:'Company LTD Setup',price:12000,visible:true,active:true,type:'Manual'},
  {id:'cac-verification',cat:'CAC',name:'Business Verification',price:2500,visible:true,active:true,type:'Manual'},
  {id:'tin',cat:'TIN',name:'TIN Registration',price:2000,visible:true,active:true,type:'Manual'},
  {id:'attestation',cat:'Attestation',name:'NIN Attestation',price:3000,visible:true,active:true,type:'Manual'},
  {id:'gemprint-materials',cat:'GemPrint',name:'Printing Materials Shop',price:0,visible:true,active:true,type:'Commerce'},
  {id:'gemprint-services',cat:'GemPrint',name:'Printing Services',price:0,visible:true,active:true,type:'Manual'}
];

let withdrawableProfit = 0;

function login() {
  const loginOverlay = document.getElementById('gv-login-overlay');
  if (loginOverlay) loginOverlay.style.display = 'none';
  const loginSec = document.getElementById('login');
  if (loginSec) loginSec.style.display = 'none';
  const app = document.getElementById('app');
  if (app) app.classList.add('on');
  renderServices();
  updateWithdrawableUI();
}

function logout() {
  const app = document.getElementById('app');
  if (app) app.classList.remove('on');
  const loginOverlay = document.getElementById('gv-login-overlay');
  if (loginOverlay) {
    loginOverlay.style.display = 'flex';
    loginOverlay.classList.remove('gv-exit');
  } else {
    const loginSec = document.getElementById('login');
    if (loginSec) loginSec.style.display = 'grid';
  }
}

function go(page, btn) {
  window.scrollTo(0, 0);
  document.querySelectorAll('.page').forEach(x => x.classList.remove('active'));
  const p = document.getElementById('page-' + page);
  if (p) p.classList.add('active');
  document.querySelectorAll('.nav button[data-page]').forEach(x => x.classList.remove('active'));
  let activeBtn = btn;
  if (activeBtn) {
    activeBtn.classList.add('active');
  } else {
    activeBtn = document.querySelector('.nav button[data-page="' + page + '"]');
    if (activeBtn) activeBtn.classList.add('active');
  }
  openNavCatFor(activeBtn);
  closeSidebar();
  window.scrollTo(0, 0);
}

function toggleSidebar() {
  const side = document.getElementById('side');
  if (side) side.classList.toggle('show');
}

function closeSidebar() {
  const side = document.getElementById('side');
  if (side) side.classList.remove('show');
}

function toggleNavCat(el) {
  const group = el.closest('.nav-group');
  if (group) group.classList.toggle('open');
}

function openNavCatFor(btn) {
  if (!btn) return;
  const group = btn.closest('.nav-group');
  if (group) group.classList.add('open');
}

function money(n) {
  return n ? new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN', maximumFractionDigits: 0 }).format(n) : '—';
}

function renderServices() {
  const grid = document.getElementById('serviceGrid');
  if (!grid) return;
  const q = (document.getElementById('serviceSearch')?.value || '').toLowerCase();
  const cat = document.getElementById('catFilter')?.value || '';
  const arr = services.filter(s => (!q || s.name.toLowerCase().includes(q)) && (!cat || s.cat === cat));
  grid.innerHTML = arr.map(s => `
    <div class="service">
      <div class="service-top">
        <div class="ico">${s.cat==='NIN'?'N':s.cat==='BVN'?'B':s.cat==='JAMB'?'J':s.cat==='CAC'?'C':s.cat==='TIN'?'T':s.cat==='GemPrint'?'G':'A'}</div>
        <button class="toggle ${s.visible?'on':''}" onclick="toggleVisible('${s.id}')"><i></i></button>
      </div>
      <h3>${s.name}</h3>
      <p>${s.cat} · ${s.type} · ${s.visible?'Visible to users':'Hidden from users'}</p>
      <div class="service-row">
        <div>
          <small style="color:var(--gv-muted);font-size:9px">USER PRICE</small>
          <div class="price">${money(s.price)}</div>
        </div>
        <button class="secondary" onclick="openService('${s.id}')">Edit</button>
      </div>
    </div>`).join('');
}

function toggleVisible(id) {
  const s = services.find(x => x.id === id);
  if (s) { s.visible = !s.visible; renderServices(); }
}

function openService(id) {
  const s = id ? services.find(x => x.id === id) : { id: '', cat: 'NIN', name: '', price: 0, visible: true, active: true, type: 'Manual' };
  document.getElementById('modalTitle').textContent = id ? 'Edit Service' : 'Add Service';
  document.getElementById('modalBody').innerHTML = `
    <div class="field"><label>Service Name</label><input id="mName" value="${s.name}"></div>
    <div class="field"><label>Category</label><select id="mCat">${['NIN','BVN','JAMB','CAC','TIN','Attestation','GemPrint'].map(x => `<option ${x===s.cat?'selected':''}>${x}</option>`).join('')}</select></div>
    <div class="field"><label>User Price (NGN)</label><input id="mPrice" type="number" value="${s.price}"></div>
    <div class="field"><label>Processing Type</label><select id="mType"><option ${s.type==='Manual'?'selected':''}>Manual</option><option ${s.type==='API'?'selected':''}>API</option><option ${s.type==='Commerce'?'selected':''}>Commerce</option></select></div>
    <div style="display:flex;gap:20px"><label><input id="mVisible" type="checkbox" ${s.visible?'checked':''}> Visible to users</label><label><input id="mActive" type="checkbox" ${s.active?'checked':''}> Active</label></div>`;
  document.getElementById('modalFoot').innerHTML = `<button class="secondary" onclick="closeModal()">Cancel</button><button class="primary" onclick="saveService('${s.id}')">Save Changes</button>`;
  document.getElementById('modal').classList.add('show');
}

function saveService(id) {
  let s = id ? services.find(x => x.id === id) : null;
  if (!s) { s = { id: 'custom-' + Date.now() }; services.push(s); }
  s.name = document.getElementById('mName').value.trim() || 'Unnamed Service';
  s.cat = document.getElementById('mCat').value;
  s.price = Number(document.getElementById('mPrice').value) || 0;
  s.type = document.getElementById('mType').value;
  s.visible = document.getElementById('mVisible').checked;
  s.active = document.getElementById('mActive').checked;
  closeModal();
  renderServices();
}

function openRequest(ref, service) {
  document.getElementById('modalTitle').textContent = service + ' · ' + ref;
  document.getElementById('modalBody').innerHTML = `
    <div style="background:var(--gv-surface);border:1px solid var(--gv-line);padding:14px;border-radius:13px;margin-bottom:16px">
      <b style="font-size:13px">Customer Submitted Information</b>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:13px;font-size:11px">
        <div><small style="color:var(--gv-muted)">User</small><br><b>Ahmad Musa</b></div>
        <div><small style="color:var(--gv-muted)">Payment</small><br><span class="pill green">Paid</span></div>
        <div><small style="color:var(--gv-muted)">Reference</small><br><b>${ref}</b></div>
        <div><small style="color:var(--gv-muted)">Status</small><br><span class="pill blue">Processing</span></div>
      </div>
    </div>
    <div class="field"><label>Upload Result / Document</label><input type="file" accept=".pdf,.jpg,.jpeg,.png"></div>
    <div class="field"><label>Admin Note</label><textarea rows="3" placeholder="Add processing note..."></textarea></div>`;
  document.getElementById('modalFoot').innerHTML = `<button class="secondary" onclick="closeModal()">Save Draft</button><button class="primary" onclick="alert('Demo: request marked completed and result made available to the user.');closeModal()">Upload & Mark Completed</button>`;
  document.getElementById('modal').classList.add('show');
}

function updateWithdrawableUI() {
  const value = money(withdrawableProfit);
  ['withdrawableStat', 'withdrawablePanel', 'withdrawablePage'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  });
}

function openWithdraw() {
  document.getElementById('modalTitle').textContent = 'Withdraw Company Profit';
  document.getElementById('modalBody').innerHTML = `
    <div style="background:#eff6ff;border:1px solid #dbeafe;padding:14px;border-radius:13px;margin-bottom:16px">
      <small style="color:var(--gv-muted);font-weight:800">AVAILABLE FOR WITHDRAWAL</small>
      <div style="font-size:24px;font-weight:900;margin-top:4px">${money(withdrawableProfit)}</div>
      <p style="font-size:10px;color:var(--gv-muted);margin:5px 0 0">This balance represents settled company funds only. Customer wallet balances are excluded.</p>
    </div>
    <div class="field"><label>Withdrawal Amount (NGN)</label><input id="withdrawAmount" type="number" min="1" max="${withdrawableProfit}" placeholder="Enter amount"></div>
    <div class="field"><label>Destination</label><select id="withdrawDestination"><option>Company Settlement Account</option><option>Approved Business Bank Account</option></select></div>
    <div class="field"><label>Reason / Reference</label><input id="withdrawReason" placeholder="e.g. Weekly profit settlement"></div>
    <div style="background:#fff7e6;color:#92400e;border:1px solid #fde68a;padding:11px;border-radius:11px;font-size:10px">
      Security rule: withdrawals should require backend balance validation, admin re-authentication/2FA, and an immutable audit record.
    </div>`;
  document.getElementById('modalFoot').innerHTML = `
    <button class="secondary" onclick="closeModal()">Cancel</button>
    <button class="primary" onclick="submitWithdraw()">Review Withdrawal</button>`;
  document.getElementById('modal').classList.add('show');
}

function submitWithdraw() {
  const amount = Number(document.getElementById('withdrawAmount').value);
  if (!amount || amount <= 0) { alert('Enter a valid withdrawal amount.'); return; }
  if (amount > withdrawableProfit) { alert('Withdrawal exceeds settled withdrawable profit.'); return; }
  const destination = document.getElementById('withdrawDestination').value;
  const reason = document.getElementById('withdrawReason').value.trim() || 'Profit withdrawal';
  document.getElementById('modalTitle').textContent = 'Confirm Profit Withdrawal';
  document.getElementById('modalBody').innerHTML = `
    <div style="padding:15px;border:1px solid var(--gv-line);border-radius:13px">
      <div style="display:flex;justify-content:space-between;margin-bottom:9px"><span>Amount</span><b>${money(amount)}</b></div>
      <div style="display:flex;justify-content:space-between;margin-bottom:9px"><span>Destination</span><b>${destination}</b></div>
      <div style="display:flex;justify-content:space-between"><span>Reason</span><b>${reason}</b></div>
    </div>
    <div class="field"><label>Admin Password / Re-authentication</label><input id="reauth" type="password" placeholder="Required in production backend"></div>`;
  document.getElementById('modalFoot').innerHTML = `
    <button class="secondary" onclick="closeModal()">Cancel</button>
    <button class="primary" onclick="confirmWithdraw(${amount})">Confirm Withdrawal</button>`;
}

function confirmWithdraw(amount) {
  const auth = document.getElementById('reauth').value;
  if (!auth) { alert('Admin re-authentication is required.'); return; }
  if (amount > withdrawableProfit) { alert('Balance changed. Recalculate withdrawal before continuing.'); return; }
  withdrawableProfit -= amount;
  updateWithdrawableUI();
  closeModal();
  alert('Demo withdrawal recorded. Production implementation must create a server-side withdrawal transaction, lock the balance, require 2FA, and write an audit log.');
}

function refreshProviderBalances() {
  alert('Demo refresh completed. Production backend should fetch balances from each provider API and store the latest sync timestamp.');
}

function filterProviders() {
  const q = (document.getElementById('providerSearch')?.value || '').toLowerCase();
  document.querySelectorAll('#providerTable tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

function viewProvider(name) {
  document.getElementById('modalTitle').textContent = name + ' — Provider Balance';
  document.getElementById('modalBody').innerHTML = `
    <div class="grid4" style="grid-template-columns:1fr 1fr">
      <div class="stat"><small>Available Balance</small><strong>₦620,000</strong></div>
      <div class="stat"><small>Status</small><strong style="font-size:18px">Healthy</strong></div>
    </div>
    <div style="margin-top:14px;padding:13px;border:1px solid var(--gv-line);border-radius:12px">
      <b>Operational Controls</b>
      <p style="font-size:10px;color:var(--gv-muted)">Provider balance should be read from the provider's authenticated API. Admin should not manually edit the live provider balance.</p>
    </div>`;
  document.getElementById('modalFoot').innerHTML = '<button class="secondary" onclick="closeModal()">Close</button>';
  document.getElementById('modal').classList.add('show');
}

function openKpiPage(page) {
  const pages = {
    'registered-accounts': ['Registered Accounts', 'users'],
    'active-today': ['Active Today', 'users'],
    'wallet-liability': ['Wallet Liability', 'wallet-funding'],
    'revenue-today': ['Revenue Today', 'revenue'],
    'profit-today': ['Profit Today', 'profit'],
    'successful-txn': ['Successful Transactions', 'transactions'],
    'failed-txns': ['Failed Transactions', 'transactions'],
    'withdrawable-profit': ['Withdrawable Profit', 'profit'],
    'provider-balance': ['Providers Balance', 'provider-balance']
  };
  const item = pages[page];
  if (item) go(item[1]);
}

function closeKpiDetail() {
  const m = document.getElementById('kpiDetailModal');
  if (m) { m.classList.remove('show'); m.setAttribute('aria-hidden', 'true'); }
}

function openProviderIntelligence() {
  go('provider-balance');
}

function closeModal() {
  const m = document.getElementById('modal');
  if (m) m.classList.remove('show');
}

document.addEventListener('DOMContentLoaded', function () {
  const m = document.getElementById('modal');
  if (m) {
    m.addEventListener('click', e => {
      if (e.target.id === 'modal') closeModal();
    });
  }
});
