<div class="grid cards" id="wstats">
  <div class="card stat pri"><div class="v" id="w-amount">—</div><div class="l">Today's Sales</div></div>
  <div class="card stat ok"><div class="v" id="w-txns">—</div><div class="l">Transactions</div></div>
  <div class="card stat warn"><div class="v" id="w-qty">—</div><div class="l">Items Sold</div></div>
  <div class="card stat"><div class="v" id="w-shift">—</div><div class="l">Shift Status</div></div>
</div>

<div class="grid col-2 mt">
  <div class="card">
    <div class="hd">My Top Products Today</div>
    <div class="bd"><table><tbody id="w-top"><tr><td class="muted">Loading…</td></tr></tbody></table></div>
  </div>
  <div class="card">
    <div class="hd">Stock Balance (my location)</div>
    <div class="bd" style="max-height:340px;overflow:auto">
      <table><thead><tr><th>Product</th><th class="right">Qty</th></tr></thead>
      <tbody id="w-stock"><tr><td class="muted">Loading…</td></tr></tbody></table>
    </div>
  </div>
</div>

<div class="mt row">
  <a class="btn lg" href="<?= base_url('/sales') ?>">📷 Start QR Sales</a>
  <button class="btn lg gray" id="shiftBtn">Shift…</button>
</div>

<script>
async function loadWorker() {
  try {
    const { data } = await FAOS.get('/api/dashboard/worker');
    document.getElementById('w-amount').textContent = FAOS.money(data.today.amount);
    document.getElementById('w-txns').textContent = data.today.txns;
    document.getElementById('w-qty').textContent = Number(data.today.qty || 0);
    document.getElementById('w-shift').textContent = data.shift ? 'OPEN' : 'Closed';
    document.getElementById('w-top').innerHTML = (data.top_products.length
      ? data.top_products.map(p => `<tr><td>${FAOS.esc(p.name)}</td><td class="right">${Number(p.qty)}</td></tr>`).join('')
      : '<tr><td class="muted">No sales yet</td></tr>');
    document.getElementById('w-stock').innerHTML = (data.stock.length
      ? data.stock.map(s => `<tr><td>${FAOS.esc(s.name)}</td><td class="right ${Number(s.qty)<=Number(s.reorder_level)?'':''}">${Number(s.qty)} ${FAOS.esc(s.uom)}</td></tr>`).join('')
      : '<tr><td class="muted">No stock</td></tr>');
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('shiftBtn').addEventListener('click', async () => {
  try {
    const s = await FAOS.get('/api/sales/shift');
    if (s.data.open) {
      if (confirm('Close current shift?')) {
        const r = await FAOS.post('/api/sales/shift/close');
        FAOS.toast('Shift closed: ' + FAOS.money(r.data.summary.amount), 'ok');
      }
    } else {
      await FAOS.post('/api/sales/shift/open', { opening_float: 0 });
      FAOS.toast('Shift opened', 'ok');
    }
    loadWorker();
  } catch (e) { FAOS.toast(e.message, 'err'); }
});
loadWorker();
setInterval(loadWorker, 20000);
</script>
