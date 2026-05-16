<div class="grid col-2">
  <div class="card">
    <div class="hd">Generate QR Stock Labels</div>
    <div class="bd">
      <label>Product</label>
      <select id="prod">
        <?php foreach ($products as $p): ?>
          <option value="<?= (int) $p['id'] ?>"><?= e($p['sku']) ?> · <?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="row">
        <div><label>Label type</label>
          <select id="ltype"><option value="carton">Carton</option><option value="batch">Batch</option><option value="item">Item</option></select>
        </div>
        <div><label>Quantity per label</label><input id="iqty" type="number" value="1" min="0" step="0.001"></div>
        <div><label>How many labels</label><input id="count" type="number" value="6" min="1" max="500"></div>
      </div>
      <button class="btn lg mt" id="gen">Generate Labels</button>
      <p class="muted mt" style="font-size:13px">The QR encodes only a unique reference (e.g. STK-<?= date('Ymd') ?>-000001). All product / batch / expiry data is resolved server-side on scan.</p>
    </div>
  </div>
  <div class="card">
    <div class="hd"><span>Generated</span><button class="btn ghost hidden" id="printBtn">🖨 Print Sheet</button></div>
    <div class="bd">
      <div id="out" class="grid" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px">
        <p class="muted">No labels yet.</p>
      </div>
    </div>
  </div>
</div>

<script>
let lastRefs = [];
document.getElementById('gen').addEventListener('click', async () => {
  try {
    const r = await FAOS.post('/api/qr/labels', {
      product_id: +document.getElementById('prod').value,
      count: +document.getElementById('count').value,
      label_type: document.getElementById('ltype').value,
      init_qty: parseFloat(document.getElementById('iqty').value) || 0,
    });
    lastRefs = r.data.labels.map(l => l.qr_ref);
    document.getElementById('out').innerHTML = r.data.labels.map(l => `
      <div class="center" style="border:1px solid var(--border);border-radius:8px;padding:10px">
        <img src="/api/qr/${encodeURIComponent(l.qr_ref)}/image?fmt=svg&scale=5" style="width:100%;max-width:120px" alt="">
        <div style="font-size:11px;font-weight:700;margin-top:6px">${FAOS.esc(l.qr_ref)}</div>
        <div class="muted" style="font-size:10px">${FAOS.esc(l.product_name)}</div>
      </div>`).join('');
    document.getElementById('printBtn').classList.remove('hidden');
    FAOS.toast(lastRefs.length + ' labels generated', 'ok');
  } catch (e) { FAOS.toast(e.message, 'err'); }
});
document.getElementById('printBtn').addEventListener('click', () => {
  if (lastRefs.length) window.open('/api/qr/print?refs=' + encodeURIComponent(lastRefs.join(',')), '_blank');
});
</script>
