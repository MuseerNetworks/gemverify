/* ZenithPay bank-account activation UI. Loaded after the portal application. */
(function () {
  'use strict';
  const api = '../api';
  const token = () => localStorage.getItem('gv_token') || '';
  const request = async (url, options) => {
    const res = await fetch(api + url, Object.assign({ headers: { Authorization: 'Bearer ' + token() } }, options || {}));
    return res.json();
  };
  const remove = () => document.getElementById('gv-zenithpay-card')?.remove();
  const card = (html) => {
    remove(); const el = document.createElement('section'); el.id = 'gv-zenithpay-card';
    el.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:9998;width:min(390px,calc(100vw - 32px));padding:20px;border-radius:18px;background:#102044;color:#fff;box-shadow:0 18px 48px rgba(0,0,0,.28);font:14px system-ui,sans-serif';
    el.innerHTML = html; document.body.appendChild(el); return el;
  };
  const activate = () => {
    const el = card('<button id="gv-z-close" style="float:right;border:0;background:none;color:#fff;font-size:18px">×</button><h3 style="margin:0 0 8px">Activate your bank account</h3><p style="margin:0 0 14px;line-height:1.45;color:#dbeafe">Enter your 11-digit BVN to create your bank account for wallet funding. Your existing wallet balance stays unchanged.</p><input id="gv-z-bvn" inputmode="numeric" maxlength="11" placeholder="Enter your BVN" style="box-sizing:border-box;width:100%;padding:12px;border-radius:10px;border:1px solid #5270a9;background:#fff;color:#102044"><button id="gv-z-submit" style="margin-top:10px;width:100%;padding:12px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-weight:700">Activate bank account</button><p id="gv-z-error" style="margin:9px 0 0;color:#fecaca;font-size:12px"></p>');
    el.querySelector('#gv-z-close').onclick = remove;
    el.querySelector('#gv-z-submit').onclick = async () => {
      const bvn = el.querySelector('#gv-z-bvn').value.replace(/\D/g, ''); const error = el.querySelector('#gv-z-error');
      if (!/^\d{11}$/.test(bvn)) { error.textContent = 'Enter exactly 11 digits.'; return; }
      const btn = el.querySelector('#gv-z-submit'); btn.disabled = true; btn.textContent = 'Activating…';
      try { const json = await request('/user/wallet/zenithpay/activate', {method:'POST',headers:{Authorization:'Bearer '+token(),'Content-Type':'application/json'},body:JSON.stringify({bvn})}); if (!json.success) throw new Error(json.message || 'Activation failed.'); await load(); }
      catch (err) { error.textContent = err.message; btn.disabled = false; btn.textContent = 'Activate bank account'; }
    };
  };
  const load = async () => {
    if (!token()) return;
    try {
      const json = await request('/user/wallet'); if (!json.success || !json.data?.funding) return;
      const f = json.data.funding, z = f.zenithpay || {};
      if (!f.katpay_funding_enabled) {
        document.querySelectorAll('button').forEach((button) => {
          const label = (button.textContent || '').trim().toLowerCase();
          if (label === 'fund wallet' || label === 'top up' || label === 'add funds') button.style.display = 'none';
        });
      }
      if (z.status === 'activation_required' || z.status === 'failed') { activate(); return; }
      if (z.status === 'pending' || z.status === 'unknown') { card('<h3 style="margin:0 0 8px">Bank account activation</h3><p style="margin:0;color:#dbeafe;line-height:1.45">Your bank account is being confirmed. Please check back shortly.</p>'); return; }
      remove();
    } catch (_) {}
  };
  window.addEventListener('load', () => setTimeout(load, 1200));
})();
