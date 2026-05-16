/* FAOS shared client runtime - no external deps. */
(function () {
  'use strict';

  const FAOS = window.FAOS = {};
  let CSRF = window.__CSRF__ || '';

  // ---- API helper ----------------------------------------------------------
  FAOS.api = async function (method, url, body) {
    const opt = {
      method,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    };
    if (body !== undefined) {
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = CSRF;
      opt.body = JSON.stringify(body);
    }
    const dev = FAOS.deviceId();
    if (dev) opt.headers['X-Device-Id'] = dev;
    const res = await fetch(url, opt);
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-json */ }
    if (!res.ok) {
      const msg = (json && json.message) || ('HTTP ' + res.status);
      const err = new Error(msg);
      err.status = res.status;
      err.payload = json;
      throw err;
    }
    return json;
  };
  FAOS.get = (u) => FAOS.api('GET', u);
  FAOS.post = (u, b) => FAOS.api('POST', u, b || {});
  FAOS.put = (u, b) => FAOS.api('PUT', u, b || {});
  FAOS.del = (u) => FAOS.api('DELETE', u, {});

  // Multipart upload (CSV import). `fields` are appended to FormData.
  FAOS.upload = async function (url, file, fields) {
    const fd = new FormData();
    if (file) fd.append('file', file);
    fd.append('_csrf', CSRF);
    Object.entries(fields || {}).forEach(([k, v]) =>
      fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v));
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF, 'X-Device-Id': FAOS.deviceId() },
      body: fd,
    });
    let json = null;
    try { json = await res.json(); } catch (e) { /* ignore */ }
    if (!res.ok) {
      const err = new Error((json && json.message) || ('HTTP ' + res.status));
      err.payload = json;
      throw err;
    }
    return json;
  };

  // ---- Device id (persistent per browser) ----------------------------------
  FAOS.deviceId = function () {
    let id = localStorage.getItem('faos_device');
    if (!id) {
      id = 'DEV-' + Math.random().toString(36).slice(2, 10).toUpperCase();
      localStorage.setItem('faos_device', id);
    }
    return id;
  };

  // ---- Toast ---------------------------------------------------------------
  FAOS.toast = function (msg, type) {
    let wrap = document.querySelector('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    const t = document.createElement('div');
    t.className = 'toast ' + (type || '');
    t.textContent = msg;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3200);
  };

  FAOS.esc = (s) => String(s == null ? '' : s)
    .replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  FAOS.money = (n) => (window.__CURRENCY__ || 'RM') + ' ' + Number(n || 0).toFixed(2);

  // ---- UI chrome -----------------------------------------------------------
  function initChrome() {
    const tgl = document.querySelector('.menu-toggle');
    if (tgl) tgl.addEventListener('click', () => document.querySelector('.sidebar').classList.toggle('open'));

    const theme = document.querySelector('[data-action="theme"]');
    const saved = localStorage.getItem('faos_theme');
    if (saved) document.documentElement.setAttribute('data-theme', saved);
    if (theme) theme.addEventListener('click', () => {
      const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? '' : 'dark';
      document.documentElement.setAttribute('data-theme', cur);
      localStorage.setItem('faos_theme', cur);
    });

    // Refresh CSRF + connectivity banner.
    FAOS.get('/api/me').then((r) => {
      if (r && r.data && r.data.csrf) { CSRF = r.data.csrf; window.__CSRF__ = CSRF; }
    }).catch(() => {});
  }

  FAOS.online = () => navigator.onLine;
  window.addEventListener('online', () => { FAOS.toast('Back online', 'ok'); document.dispatchEvent(new Event('faos:online')); });
  window.addEventListener('offline', () => FAOS.toast('Offline - sales will queue', 'err'));

  document.addEventListener('DOMContentLoaded', initChrome);
})();
