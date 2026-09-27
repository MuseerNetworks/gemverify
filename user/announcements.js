/* Global announcement shown once per signed-in browser session. */
(function () {
  'use strict';
  const seenKey = 'gv_announcement_seen';
  const token = () => localStorage.getItem('gv_token') || '';
  const close = () => document.getElementById('gv-announcement-popup')?.remove();

  function show(announcement) {
    if (!announcement || !announcement.id || sessionStorage.getItem(seenKey) === String(announcement.id)) return;
    close();
    const overlay = document.createElement('div');
    overlay.id = 'gv-announcement-popup';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(10,25,49,.55);font-family:system-ui,sans-serif';
    const dialog = document.createElement('section');
    dialog.setAttribute('role', 'dialog'); dialog.setAttribute('aria-modal', 'true');
    dialog.style.cssText = 'width:min(460px,100%);border-radius:18px;background:#fff;color:#102044;box-shadow:0 25px 70px rgba(0,0,0,.3);padding:24px';
    const title = document.createElement('h2'); title.style.cssText = 'margin:0 34px 10px 0;font-size:20px;line-height:1.3'; title.textContent = announcement.title;
    const body = document.createElement('p'); body.style.cssText = 'margin:0;color:#475569;font-size:14px;line-height:1.6;white-space:pre-wrap'; body.textContent = announcement.body;
    const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Close'; button.style.cssText = 'margin-top:20px;float:right;padding:9px 18px;border:0;border-radius:9px;background:#0050ff;color:#fff;font-weight:700;cursor:pointer';
    const dismiss = () => { sessionStorage.setItem(seenKey, String(announcement.id)); close(); };
    button.onclick = dismiss;
    overlay.onclick = (event) => { if (event.target === overlay) dismiss(); };
    dialog.append(title, body, button); overlay.appendChild(dialog); document.body.appendChild(overlay);
  }

  async function load() {
    if (!token()) return;
    try {
      const response = await fetch('../api/user/announcements/active', { headers: { Authorization: 'Bearer ' + token() } });
      const json = await response.json();
      if (response.ok && json.success) show(json.data?.announcement);
    } catch (_) {}
  }

  window.addEventListener('gv-auth-changed', () => { sessionStorage.removeItem(seenKey); setTimeout(load, 450); });
  window.addEventListener('load', () => { if (token() && !sessionStorage.getItem(seenKey)) setTimeout(load, 1200); });
})();
