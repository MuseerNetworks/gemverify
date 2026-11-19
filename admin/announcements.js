/* Global customer-login announcement management. */
(function () {
  'use strict';
  let announcements = [];
  let selectedId = null;

  const token = () => localStorage.getItem('gv_admin_token') || '';
  const api = async (path, options) => {
    const response = await fetch('../api' + path, Object.assign({ headers: { Authorization: 'Bearer ' + token(), 'Content-Type': 'application/json' } }, options || {}));
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'Request failed.');
    return json.data;
  };
  const byId = (id) => document.getElementById(id);
  const selected = () => announcements.find((item) => Number(item.id) === Number(selectedId)) || null;
  const setMessage = (message, error) => {
    const el = byId('gv-announcement-message');
    if (!el) return;
    el.textContent = message || '';
    el.style.color = error ? '#dc2626' : '#15803d';
  };
  const escapeText = (value) => String(value || '');

  function populateEditor() {
    const item = selected();
    byId('gv-announcement-title').value = item ? item.title : '';
    byId('gv-announcement-body').value = item ? item.body : '';
    byId('gv-announcement-cta-label').value = item ? (item.cta_label || '') : 'WhatsApp';
    byId('gv-announcement-cta-url').value = item ? (item.cta_url || '') : 'https://chat.whatsapp.com/HKAudY0A56n3b8yrBRI0y9?s=sh&p=a&iam=1';
    updateActionState();
  }

  function updateActionState() {
    const item = selected();
    const hasContent = Boolean(byId('gv-announcement-title').value.trim() && byId('gv-announcement-body').value.trim());
    byId('gv-announcement-pause').disabled = !item || item.status !== 'published';
    byId('gv-announcement-publish').disabled = !hasContent;
  }

  function renderList() {
    const list = byId('gv-announcements-list');
    if (!list) return;
    list.replaceChildren();
    if (!announcements.length) {
      const empty = document.createElement('p');
      empty.style.cssText = 'margin:0;color:var(--muted);font-size:13px';
      empty.textContent = 'No announcements created yet.';
      list.appendChild(empty);
      return;
    }
    announcements.forEach((item) => {
      const card = document.createElement('button');
      card.type = 'button';
      card.style.cssText = 'width:100%;text-align:left;border:1px solid ' + (Number(item.id) === Number(selectedId) ? '#2563eb' : 'var(--line)') + ';border-radius:10px;padding:12px;background:' + (Number(item.id) === Number(selectedId) ? '#eff6ff' : 'var(--card)') + ';cursor:pointer;margin-bottom:8px;color:var(--text)';
      const title = document.createElement('strong'); title.style.cssText = 'display:block;font-size:13px'; title.textContent = escapeText(item.title);
      const meta = document.createElement('small'); meta.style.cssText = 'display:block;margin-top:5px;color:var(--muted);text-transform:capitalize'; meta.textContent = item.status + (item.published_at ? ' · Published ' + item.published_at : '');
      card.append(title, meta);
      card.onclick = () => { selectedId = item.id; setMessage(''); populateEditor(); renderList(); };
      list.appendChild(card);
    });
  }

  async function reload(selectNewest) {
    const data = await api('/admin/announcements');
    announcements = data.announcements || [];
    if (selectNewest && announcements.length) selectedId = announcements[0].id;
    if (selectedId && !selected()) selectedId = null;
    populateEditor(); renderList();
  }

  async function save() {
    const title = byId('gv-announcement-title').value.trim();
    const body = byId('gv-announcement-body').value.trim();
    const cta_label = byId('gv-announcement-cta-label').value.trim();
    const cta_url = byId('gv-announcement-cta-url').value.trim();
    if (!title || !body) throw new Error('Enter both a title and a message.');
    if (selectedId) await api('/admin/announcements/' + selectedId, { method: 'PATCH', body: JSON.stringify({ title, body, cta_label, cta_url }) });
    else {
      const data = await api('/admin/announcements', { method: 'POST', body: JSON.stringify({ title, body, cta_label, cta_url }) });
      selectedId = data.id;
    }
    await reload(false);
  }

  function mount() {
    if (byId('page-announcements')) return;
    const nav = document.querySelector('.nav-group:last-of-type .nav-sub');
    if (nav) {
      const button = document.createElement('button');
      button.dataset.page = 'announcements'; button.textContent = '◉　Announcements';
      button.onclick = function () { go('announcements', button); };
      nav.insertBefore(button, nav.querySelector('.danger'));
    }
    const content = document.querySelector('.content');
    if (!content) return;
    const page = document.createElement('section');
    page.id = 'page-announcements'; page.className = 'page';
    page.innerHTML = '<div class="heading"><div><h1>Customer Announcements</h1><p>Published messages show once after login and remain as a WhatsApp button until paused.</p></div><button id="gv-announcement-new" class="secondary">+ New announcement</button></div><div style="display:grid;grid-template-columns:minmax(250px,.8fr) minmax(0,1.4fr);gap:18px;align-items:start" class="gv-announcement-grid"><div class="card"><div class="head"><h2>Messages</h2></div><div id="gv-announcements-list" style="padding:14px"></div></div><div class="card"><div class="head"><h2>Announcement editor</h2></div><div style="padding:18px"><label style="display:block;font-weight:700;font-size:12px;margin-bottom:6px">Title</label><input id="gv-announcement-title" maxlength="200" placeholder="Join our WhatsApp Community" style="width:100%;box-sizing:border-box;margin-bottom:14px"><label style="display:block;font-weight:700;font-size:12px;margin-bottom:6px">Message</label><textarea id="gv-announcement-body" maxlength="4000" rows="5" placeholder="Samu sabbin updates, offers, da support kai tsaye daga GemVerify." style="width:100%;box-sizing:border-box;resize:vertical"></textarea><label style="display:block;font-weight:700;font-size:12px;margin:14px 0 6px">Button label</label><input id="gv-announcement-cta-label" maxlength="80" placeholder="WhatsApp" style="width:100%;box-sizing:border-box;margin-bottom:14px"><label style="display:block;font-weight:700;font-size:12px;margin-bottom:6px">Button URL</label><input id="gv-announcement-cta-url" type="url" maxlength="255" placeholder="https://chat.whatsapp.com/..." style="width:100%;box-sizing:border-box"><p style="font-size:12px;color:var(--muted);margin:8px 0">Use a full HTTPS link. Users see a clean WhatsApp button, never the raw URL.</p><p id="gv-announcement-message" style="min-height:18px;font-size:12px;margin:10px 0"></p><div style="display:flex;gap:8px;flex-wrap:wrap"><button id="gv-announcement-save" class="secondary">Save draft</button><button id="gv-announcement-publish" class="primary">Publish</button><button id="gv-announcement-pause" class="secondary" style="color:#b45309">Pause</button></div></div></div></div>';
    content.appendChild(page);
    byId('gv-announcement-new').onclick = () => { selectedId = null; setMessage(''); populateEditor(); renderList(); byId('gv-announcement-title').focus(); };
    byId('gv-announcement-title').addEventListener('input', updateActionState);
    byId('gv-announcement-body').addEventListener('input', updateActionState);
    byId('gv-announcement-save').onclick = async () => { try { await save(); setMessage('Draft saved.'); } catch (error) { setMessage(error.message, true); } };
    byId('gv-announcement-publish').onclick = async () => { try { await save(); await api('/admin/announcements/' + selectedId + '/publish', { method: 'POST', body: '{}' }); await reload(false); setMessage('Announcement published.'); } catch (error) { setMessage(error.message, true); } };
    byId('gv-announcement-pause').onclick = async () => { try { if (!selectedId) return; await api('/admin/announcements/' + selectedId + '/pause', { method: 'POST', body: '{}' }); await reload(false); setMessage('Announcement paused.'); } catch (error) { setMessage(error.message, true); } };
  }

  window.loadAnnouncements = async function () {
    mount();
    try { await reload(!selectedId); setMessage(''); }
    catch (error) { setMessage(error.message, true); }
  };
  document.addEventListener('DOMContentLoaded', mount);
})();
