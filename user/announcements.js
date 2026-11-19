/* Global announcement shown once per signed-in browser session. */
(function () {
  'use strict';
  const seenKey = 'gv_announcement_seen';
  const token = () => localStorage.getItem('gv_token') || '';
  const close = () => document.getElementById('gv-announcement-popup')?.remove();
  const isSafeUrl = (value) => {
    try { return new URL(value).protocol === 'https:'; } catch (_) { return false; }
  };

  function makeCta(announcement, style) {
    if (!announcement.cta_label || !isSafeUrl(announcement.cta_url)) return null;
    const link = document.createElement('a');
    link.href = announcement.cta_url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 32 32');
    icon.setAttribute('fill', 'currentColor');
    icon.setAttribute('focusable', 'false');
    icon.setAttribute('aria-hidden', 'true');
    icon.style.cssText = 'width:19px;height:19px;flex:none';
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M16 3a13 13 0 0 0-11.1 19.75L3 29l6.4-1.76A13 13 0 1 0 16 3Zm0 23.65a10.56 10.56 0 0 1-5.38-1.48l-.39-.23-3.8 1.04 1.02-3.7-.25-.39A10.65 10.65 0 1 1 16 26.65Zm5.84-7.96c-.32-.16-1.9-.94-2.2-1.05-.29-.1-.5-.16-.71.16-.2.32-.8 1.04-.97 1.25-.18.21-.36.24-.68.08-1.87-.93-3.1-1.66-4.33-3.78-.33-.56.33-.52.94-1.72.1-.21.05-.39-.03-.55-.08-.16-.71-1.72-.98-2.35-.26-.62-.53-.54-.71-.55h-.61c-.21 0-.55.08-.84.39-.29.32-1.1 1.08-1.1 2.63 0 1.55 1.13 3.05 1.29 3.26.16.21 2.23 3.4 5.4 4.77.75.32 1.34.52 1.8.67.76.24 1.46.21 2.01.13.61-.09 1.9-.78 2.17-1.54.27-.76.27-1.41.19-1.54-.08-.13-.29-.21-.61-.37Z');
    icon.appendChild(path);
    const label = document.createElement('span');
    label.textContent = announcement.cta_label || 'WhatsApp';
    link.append(icon, label);
    link.style.cssText = style;
    return link;
  }

  function appendBodyWithLinks(container, message) {
    const urlPattern = /https:\/\/[^\s<]+/g;
    const text = String(message || '');
    let cursor = 0;
    let match;

    while ((match = urlPattern.exec(text)) !== null) {
      container.appendChild(document.createTextNode(text.slice(cursor, match.index)));

      try {
        const url = new URL(match[0]);
        if (!isSafeUrl(url.href)) throw new Error('Unsafe URL');

        const link = document.createElement('a');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = match[0];
        link.style.cssText = 'color:#0050ff;font-weight:700;text-decoration:underline;overflow-wrap:anywhere';
        container.appendChild(link);
      } catch (_) {
        container.appendChild(document.createTextNode(match[0]));
      }

      cursor = match.index + match[0].length;
    }

    container.appendChild(document.createTextNode(text.slice(cursor)));
  }

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
    const body = document.createElement('p'); body.style.cssText = 'margin:0;color:#475569;font-size:14px;line-height:1.6;white-space:pre-wrap'; appendBodyWithLinks(body, announcement.body);
    const actions = document.createElement('div'); actions.style.cssText = 'margin-top:20px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap';
    const cta = makeCta(announcement, 'display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;background:#25d366;color:#fff;font-weight:800;text-decoration:none;text-align:center;box-shadow:0 5px 12px rgba(37,211,102,.25)');
    const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Close'; button.style.cssText = 'padding:9px 18px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#102044;font-weight:700;cursor:pointer';
    const dismiss = () => { sessionStorage.setItem(seenKey, String(announcement.id)); close(); };
    button.onclick = dismiss;
    overlay.onclick = (event) => { if (event.target === overlay) dismiss(); };
    if (cta) actions.appendChild(cta);
    actions.appendChild(button);
    dialog.append(title, body, actions); overlay.appendChild(dialog); document.body.appendChild(overlay);
  }

  function renderDashboardBanner(announcement) {
    document.getElementById('gv-announcement-banner')?.remove();
    if (!announcement || !announcement.id || !makeCta(announcement, '')) return;

    const banner = document.createElement('aside');
    banner.id = 'gv-announcement-banner';
    banner.setAttribute('aria-label', 'WhatsApp community link');
    banner.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:9997;font-family:system-ui,sans-serif';
    const cta = makeCta(announcement, 'display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:#25d366;color:#fff;text-decoration:none;box-shadow:0 10px 24px rgba(37,211,102,.36);transition:transform .18s ease,box-shadow .18s ease');
    cta.setAttribute('aria-label', announcement.cta_label || 'Open WhatsApp community');
    cta.title = announcement.cta_label || 'WhatsApp';
    cta.querySelector('span:last-child').style.display = 'none';
    cta.querySelector('svg').style.cssText = 'width:29px;height:29px;flex:none';
    banner.append(cta);
    document.body.appendChild(banner);
  }

  async function load() {
    if (!token()) return;
    try {
      const response = await fetch('../api/user/announcements/active', { headers: { Authorization: 'Bearer ' + token() } });
      const json = await response.json();
      if (response.ok && json.success) {
        const announcement = json.data?.announcement;
        show(announcement);
        renderDashboardBanner(announcement);
      }
    } catch (_) {}
  }

  window.addEventListener('gv-auth-changed', () => { sessionStorage.removeItem(seenKey); setTimeout(load, 450); });
  window.addEventListener('load', () => { if (token() && !sessionStorage.getItem(seenKey)) setTimeout(load, 1200); });
})();
