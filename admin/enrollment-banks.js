/**
 * GemVerify Admin — BVN Enrollment Banks Management
 */
(function () {
  'use strict';

  let banks = [];
  let filterText = '';
  let filterStatus = '';

  const token = () => localStorage.getItem('gv_admin_token') || '';

  async function api(path, options = {}) {
    const url = '../api' + path;
    const fetcher = typeof window.gvAdminFetch === 'function' ? window.gvAdminFetch : fetch;
    
    let res;
    try {
      res = await fetcher(url, {
        ...options,
        headers: {
          'Authorization': 'Bearer ' + token(),
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...(options.headers || {})
        }
      });
    } catch (netErr) {
      throw new Error('Network error: ' + (netErr.message || 'Could not reach server'));
    }

    let json;
    const rawText = await res.text();
    try {
      json = JSON.parse(rawText);
    } catch (parseErr) {
      if (rawText && rawText.includes('Database connection failed')) {
        throw new Error('Database connection failed. Please ensure MySQL is running in XAMPP.');
      }
      throw new Error('Server returned an invalid response (HTTP ' + res.status + ').');
    }

    if (!res.ok || json.success === false) {
      throw new Error(json.message || 'API request failed (HTTP ' + res.status + ').');
    }

    if (!json.data || typeof json.data !== 'object') {
      throw new Error('Server returned unexpected data format.');
    }

    return json.data;
  }

  function setMsg(msg, isError = false) {
    const el = document.getElementById('enrollmentBankMsg');
    if (!el) return;
    el.textContent = msg || '';
    el.style.color = isError ? '#ef4444' : '#10b981';
    if (msg) {
      setTimeout(() => { if (el.textContent === msg) el.textContent = ''; }, 4000);
    }
  }

  window.loadEnrollmentBanks = async function () {
    const tbody = document.getElementById('enrollment-banks-tbody');
    const summary = document.getElementById('enrollment-banks-summary');
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted)">Loading banks...</td></tr>';
    }
    try {
      const data = await api('/admin/enrollment-banks');
      banks = Array.isArray(data.banks) ? data.banks : [];
      window._enrollmentBanks = banks;
      renderEnrollmentBanks();
    } catch (err) {
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:24px;color:#ef4444;font-weight:500">Failed to load banks: ${escapeHtml(err.message)}</td></tr>`;
      }
      setMsg(err.message, true);
    }
  };

  window.filterEnrollmentBanks = function () {
    const searchInput = document.getElementById('enrollmentBankSearch');
    const statusSelect = document.getElementById('enrollmentBankFilter');
    filterText = (searchInput ? searchInput.value : '').trim().toLowerCase();
    filterStatus = (statusSelect ? statusSelect.value : '');
    renderEnrollmentBanks();
  };

  function renderEnrollmentBanks() {
    const tbody = document.getElementById('enrollment-banks-tbody');
    const summary = document.getElementById('enrollment-banks-summary');
    if (!tbody) return;

    let filtered = banks.filter(b => {
      const matchText = !filterText || b.name.toLowerCase().includes(filterText);
      const matchStatus = !filterStatus || (filterStatus === 'active' && Number(b.is_active) === 1) || (filterStatus === 'hidden' && Number(b.is_active) === 0);
      return matchText && matchStatus;
    });

    const totalCount = banks.length;
    const activeCount = banks.filter(b => Number(b.is_active) === 1).length;
    const hiddenCount = totalCount - activeCount;

    if (summary) {
      summary.textContent = `${activeCount} visible to users · ${hiddenCount} hidden · ${totalCount} total`;
    }

    if (!filtered.length) {
      tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--muted)">No banks match your search.</td></tr>';
      return;
    }

    tbody.innerHTML = filtered.map((b, idx) => {
      const isActive = Number(b.is_active) === 1;
      const statusBadge = isActive
        ? '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:rgba(16,185,129,0.12);color:#059669"><span style="width:6px;height:6px;border-radius:50%;background:#059669"></span> Visible</span>'
        : '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:rgba(100,116,139,0.12);color:#64748b"><span style="width:6px;height:6px;border-radius:50%;background:#64748b"></span> Hidden</span>';

      const toggleBtn = isActive
        ? `<button type="button" onclick="window.toggleEnrollmentBank(${b.id}, 0)" style="padding:4px 8px;font-size:12px;border-radius:6px;border:1px solid #cbd5e1;background:var(--card);color:#475569;cursor:pointer" title="Hide this bank from user dropdown">👁️ Hide</button>`
        : `<button type="button" onclick="window.toggleEnrollmentBank(${b.id}, 1)" style="padding:4px 8px;font-size:12px;border-radius:6px;border:1px solid #059669;background:rgba(16,185,129,0.08);color:#059669;font-weight:600;cursor:pointer" title="Make visible to users">✓ Show</button>`;

      return `
        <tr>
          <td style="color:var(--muted);font-size:12px">${b.sort_order || (idx + 1)}</td>
          <td>
            <strong style="font-size:14px;color:var(--text)">${escapeHtml(b.name)}</strong>
          </td>
          <td style="text-align:center">
            ${statusBadge}
          </td>
          <td style="text-align:right">
            <div style="display:inline-flex;gap:6px;align-items:center">
              ${toggleBtn}
              <button type="button" onclick="window.editEnrollmentBankModal(${b.id})" style="padding:4px 8px;font-size:12px;border-radius:6px;border:1px solid var(--border);background:var(--card);color:var(--text);cursor:pointer">✏️ Edit</button>
              <button type="button" onclick="window.deleteEnrollmentBank(${b.id}, '${escapeJs(b.name)}')" style="padding:4px 8px;font-size:12px;border-radius:6px;border:1px solid rgba(239,68,68,0.3);background:rgba(239,68,68,0.06);color:#ef4444;cursor:pointer">🗑️</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  window.toggleEnrollmentBank = async function (id, targetStatus) {
    try {
      setMsg('Updating bank visibility...');
      await api(`/admin/enrollment-banks/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ is_active: targetStatus })
      });
      setMsg(targetStatus ? 'Bank is now visible to users.' : 'Bank is now hidden from users.');
      await window.loadEnrollmentBanks();
    } catch (err) {
      alert('Error updating bank: ' + err.message);
      setMsg(err.message, true);
    }
  };

  window.openAddBankModal = function () {
    const modal = document.getElementById('modal');
    const title = document.getElementById('modalTitle');
    const body = document.getElementById('modalBody');
    const foot = document.getElementById('modalFoot');
    if (!modal || !title || !body || !foot) return;

    title.textContent = 'Add New Enrollment Bank';
    body.innerHTML = `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Bank Name *</label>
          <input id="newBankName" type="text" placeholder="e.g. Kuda Bank, OPay, Moniepoint" style="width:100%;height:38px;padding:0 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:14px" autofocus>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Display Order</label>
            <input id="newBankOrder" type="number" value="${banks.length + 1}" style="width:100%;height:38px;padding:0 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:14px">
          </div>
          <div style="display:flex;flex-direction:column;justify-content:center">
            <label style="font-size:13px;font-weight:600;margin-bottom:6px">Initial Status</label>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
              <input id="newBankActive" type="checkbox" checked style="width:16px;height:16px"> Visible to Users
            </label>
          </div>
        </div>
      </div>
    `;

    foot.innerHTML = `
      <button class="secondary" onclick="closeModal()">Cancel</button>
      <button class="primary" id="btnSaveNewBank" onclick="window.submitAddEnrollmentBank()">Add Bank</button>
    `;

    modal.classList.add('show');
    setTimeout(() => {
      const inp = document.getElementById('newBankName');
      if (inp) inp.focus();
    }, 100);
  };

  window.submitAddEnrollmentBank = async function () {
    const nameEl = document.getElementById('newBankName');
    const orderEl = document.getElementById('newBankOrder');
    const activeEl = document.getElementById('newBankActive');
    const btn = document.getElementById('btnSaveNewBank');

    const name = nameEl ? nameEl.value.trim() : '';
    if (!name) {
      alert('Please enter a bank name.');
      return;
    }

    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
      await api('/admin/enrollment-banks', {
        method: 'POST',
        body: JSON.stringify({
          name: name,
          sort_order: orderEl ? Number(orderEl.value) : banks.length + 1,
          is_active: activeEl && activeEl.checked ? 1 : 0
        })
      });
      closeModal();
      setMsg(`Bank "${name}" added successfully.`);
      await window.loadEnrollmentBanks();
    } catch (err) {
      alert('Failed to add bank: ' + err.message);
      if (btn) { btn.disabled = false; btn.textContent = 'Add Bank'; }
    }
  };

  window.editEnrollmentBankModal = function (id) {
    const bank = banks.find(b => Number(b.id) === Number(id));
    if (!bank) return;

    const modal = document.getElementById('modal');
    const title = document.getElementById('modalTitle');
    const body = document.getElementById('modalBody');
    const foot = document.getElementById('modalFoot');
    if (!modal || !title || !body || !foot) return;

    title.textContent = 'Edit Enrollment Bank';
    body.innerHTML = `
      <div style="display:flex;flex-direction:column;gap:14px">
        <div>
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Bank Name *</label>
          <input id="editBankName" type="text" value="${escapeHtml(bank.name)}" style="width:100%;height:38px;padding:0 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:14px">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Display Order</label>
            <input id="editBankOrder" type="number" value="${bank.sort_order || 0}" style="width:100%;height:38px;padding:0 12px;border-radius:8px;border:1px solid var(--border);background:var(--card);color:var(--text);font-size:14px">
          </div>
          <div style="display:flex;flex-direction:column;justify-content:center">
            <label style="font-size:13px;font-weight:600;margin-bottom:6px">Visibility</label>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
              <input id="editBankActive" type="checkbox" ${Number(bank.is_active) === 1 ? 'checked' : ''} style="width:16px;height:16px"> Visible to Users
            </label>
          </div>
        </div>
      </div>
    `;

    foot.innerHTML = `
      <button class="secondary" onclick="closeModal()">Cancel</button>
      <button class="primary" id="btnSaveEditBank" onclick="window.submitEditEnrollmentBank(${bank.id})">Save Changes</button>
    `;

    modal.classList.add('show');
  };

  window.submitEditEnrollmentBank = async function (id) {
    const nameEl = document.getElementById('editBankName');
    const orderEl = document.getElementById('editBankOrder');
    const activeEl = document.getElementById('editBankActive');
    const btn = document.getElementById('btnSaveEditBank');

    const name = nameEl ? nameEl.value.trim() : '';
    if (!name) {
      alert('Bank name cannot be empty.');
      return;
    }

    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
      await api(`/admin/enrollment-banks/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name: name,
          sort_order: orderEl ? Number(orderEl.value) : 0,
          is_active: activeEl && activeEl.checked ? 1 : 0
        })
      });
      closeModal();
      setMsg('Bank updated successfully.');
      await window.loadEnrollmentBanks();
    } catch (err) {
      alert('Failed to update bank: ' + err.message);
      if (btn) { btn.disabled = false; btn.textContent = 'Save Changes'; }
    }
  };

  window.deleteEnrollmentBank = async function (id, name) {
    if (!confirm(`Are you sure you want to permanently delete "${name}" from enrollment banks?`)) {
      return;
    }

    try {
      setMsg('Deleting bank...');
      await api(`/admin/enrollment-banks/${id}`, { method: 'DELETE' });
      setMsg(`Bank "${name}" deleted.`);
      await window.loadEnrollmentBanks();
    } catch (err) {
      alert('Cannot delete bank: ' + err.message);
      setMsg(err.message, true);
    }
  };

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeJs(str) {
    return String(str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  }

})();
