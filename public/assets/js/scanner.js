/* FAOS QR scanner.
   Uses the native BarcodeDetector API (Chrome on Android = the primary kiosk
   device) with a manual-entry fallback for unsupported browsers. Zero deps. */
(function () {
  'use strict';

  const FAOS = window.FAOS;

  function Scanner(videoEl, onResult) {
    this.video = videoEl;
    this.onResult = onResult;
    this.stream = null;
    this.timer = null;
    this.last = { code: '', t: 0 };
    this.detector = ('BarcodeDetector' in window)
      ? new window.BarcodeDetector({ formats: ['qr_code'] })
      : null;
  }

  Scanner.prototype.supported = function () { return !!this.detector; };

  Scanner.prototype.start = async function () {
    if (!this.detector) throw new Error('BarcodeDetector unsupported');
    this.stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment' },
    });
    this.video.srcObject = this.stream;
    await this.video.play();
    const loop = async () => {
      if (!this.stream) return;
      try {
        const codes = await this.detector.detect(this.video);
        if (codes && codes.length) {
          const val = codes[0].rawValue.trim();
          const now = Date.now();
          // De-dupe: ignore same code within 2.5s (duplicate scan prevention).
          if (val && !(val === this.last.code && now - this.last.t < 2500)) {
            this.last = { code: val, t: now };
            if (navigator.vibrate) navigator.vibrate(60);
            this.onResult(val);
          }
        }
      } catch (e) { /* frame skip */ }
      this.timer = setTimeout(loop, 250);
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
