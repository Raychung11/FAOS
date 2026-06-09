/* FAOS QR scanner.
   Camera scanning that works on EVERY device:
   - Native BarcodeDetector when present (Chrome/Edge, fastest).
   - jsQR fallback (iOS Safari, Firefox, desktop) over a canvas.
   - Manual / hardware keyboard-wedge entry is always available in the page.
   Zero network deps (jsQR vendored locally). */
(function () {
  'use strict';

  const FAOS = window.FAOS;
  const hasCamera = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

  function Scanner(videoEl, onResult) {
    this.video = videoEl;
    this.onResult = onResult;
    this.stream = null;
    this.timer = null;
    this.canvas = null;
    this.last = { code: '', t: 0 };
    this.detector = ('BarcodeDetector' in window)
      ? new window.BarcodeDetector({ formats: ['qr_code'] })
      : null;
    this.mode = this.detector ? 'native' : (window.jsQR || hasCamera ? 'jsqr' : 'none');
  }

  // We can scan as long as a camera is reachable; jsQR covers browsers
  // without BarcodeDetector. Only false when there's no camera API at all.
  Scanner.prototype.supported = function () {
    return !!this.detector || hasCamera;
  };

  Scanner.prototype._emit = function (raw) {
    const val = (raw || '').trim();
    if (!val) return;
    const now = Date.now();
    if (val === this.last.code && now - this.last.t < 2500) return; // de-dupe
    this.last = { code: val, t: now };
    if (navigator.vibrate) navigator.vibrate(60);
    this.onResult(val);
  };

  Scanner.prototype._decodeFrame = function () {
    const v = this.video;
    if (!v || !v.videoWidth) return null;
    if (!window.jsQR) return null; // vendored lib still loading (deferred)
    const maxW = 640;
    const scale = Math.min(1, maxW / v.videoWidth);
    const w = Math.round(v.videoWidth * scale);
    const h = Math.round(v.videoHeight * scale);
    if (!this.canvas) this.canvas = document.createElement('canvas');
    this.canvas.width = w;
    this.canvas.height = h;
    const ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(v, 0, 0, w, h);
    const img = ctx.getImageData(0, 0, w, h);
    const r = window.jsQR(img.data, w, h, { inversionAttempts: 'attemptBoth' });
    return r && r.data ? r.data : null;
  };

  Scanner.prototype.start = async function () {
    if (!hasCamera) {
      throw new Error('Camera not available on this browser — use the input box / a USB scanner');
    }
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } }, audio: false,
      });
    } catch (e) {
      const m = {
        NotAllowedError: 'Camera permission denied — allow it or use the input box',
        NotFoundError: 'No camera found — use the input box / a USB scanner',
        NotReadableError: 'Camera busy (another app is using it)',
        SecurityError: 'Camera needs HTTPS',
      }[e && e.name] || ('Camera error: ' + (e && e.message || e));
      throw new Error(m);
    }
    this.video.srcObject = this.stream;
    this.video.setAttribute('playsinline', '');
    this.video.muted = true;
    try { await this.video.play(); } catch (e) { /* iOS sometimes rejects; stream still renders */ }

    const loop = async () => {
      if (!this.stream) return;
      try {
        if (this.detector) {
          const codes = await this.detector.detect(this.video);
          if (codes && codes.length) this._emit(codes[0].rawValue);
        } else {
          const v = this._decodeFrame();
          if (v) this._emit(v);
        }
      } catch (e) { /* skip frame */ }
      this.timer = setTimeout(loop, this.detector ? 250 : 180);
    };
    loop();
  };

  Scanner.prototype.stop = function () {
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    if (this.stream) {
      this.stream.getTracks().forEach((t) => t.stop());
      this.stream = null;
    }
  };

  // ---- Offline sales queue (localStorage) ----------------------------------
  const QKEY = 'faos_sale_queue';

  const Queue = {
    all() { try { return JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) { return []; } },
    save(list) { localStorage.setItem(QKEY, JSON.stringify(list)); },
    add(sale) { const l = this.all(); l.push(sale); this.save(l); return l.length; },
    count() { return this.all().length; },
    clear() { localStorage.removeItem(QKEY); },
    async flush() {
      const list = this.all();
      if (!list.length || !navigator.onLine) return { flushed: 0 };
      try {
        const r = await FAOS.post('/api/sales/sync', { sales: list });
        const okUuids = new Set(
          (r.data.results || []).filter((x) => x.ok).map((x) => x.client_uuid)
        );
        const remaining = list.filter((s) => !okUuids.has(s.client_uuid));
        this.save(remaining);
        const flushed = list.length - remaining.length;
        if (flushed) FAOS.toast(flushed + ' queued sale(s) synced', 'ok');
        document.dispatchEvent(new CustomEvent('faos:queue', { detail: { count: remaining.length } }));
        return { flushed };
      } catch (e) {
        return { flushed: 0, error: e.message };
      }
    },
  };

  function uuid() {
    return 'OFF-' + Date.now().toString(36) + '-' +
      Math.random().toString(36).slice(2, 10);
  }

  // Auto-flush on reconnect + every 30s.
  document.addEventListener('faos:online', () => Queue.flush());
  setInterval(() => { if (navigator.onLine && Queue.count()) Queue.flush(); }, 30000);

  window.FAOSScanner = Scanner;
  window.FAOSQueue = Queue;
  window.FAOSUuid = uuid;
})();
