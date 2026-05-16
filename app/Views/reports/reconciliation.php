<div class="grid col-2">
  <div class="card">
    <div class="hd">Import Official Hypermarket Report</div>
    <div class="bd">
      <p class="muted mb" style="font-size:13px">Hypermarket reports arrive 3–4 weeks late. Paste lines as
        <code>SKU,qty,amount</code> (one per line). The engine compares them to QR-scanned sales for the same period.</p>
      <div class="row">
        <div><label>Outlet</label><select id="rcOutlet">
          <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="row">
        <div><label>Period start</label><input id="ps" type="date"></div>
        <div><label>Period end</label><input id="pe" type="date"></div>
      </div>
      <label>Source name</label><input id="src" placeholder="e.g. AEON Monthly Statement">
      <label>Lines (SKU,qty,amount)</label>
      <textarea id="lines" rows="7" placeholder="CF-LATTE,120,1188.00&#10;CF-AMER,95,750.50"></textarea>
      <button class="btn lg mt" id="imp">Import & Reconcile</button>
    </div>
  </div>
  <div class="card">
    <div class="hd"><span>Variance</span>
      <select id="fStatus" style="max-width:170px">
        <option value="">All statuses</option><option>matched</option><option>shortage</option>
        <option>over_reported</option><option>under_reported</option><option>missing_scans</option>
      </select>
    </div>
    <div class="bd" style="max-height:520px;overflow:auto">
      <table><thead><tr><th>Product</th><th class="right">QR</th><th class="right">Official</th><th class="right">Δ Qty</th><th>Status</th></tr></thead>
      <tbody id="vBody"><tr><td class="muted">Import a report to see variance.</td></tr></tbody></table>
    </div>
  </div>
</div>

<script>
const BADGE = { matched:'b-ok', shortage:'b-dng', over_reported:'b-warn', under_reported:'b-warn', missing_scans:'b-dng' };
async function loadVar(reportId) {
  const st = document.getElementById('fStatus').value;
  const p = new URLSearchParams(); if (reportId) p.set('report_id', reportId); if (st) p.set('status', st);
  try {
    const { data } = await FAOS.get('/api/reconciliation/variance?' + p.toString());
    document.getElementById('vBody').innerHTML = data.length ? data.map(v => `
      <tr><td>${FAOS.esc(v.product_name || v.sku || '#' + v.product_id)}</td>
      <td class="right">${Number(v.qr_qty)}</td><td class="right">${Number(v.official_qty)}</td>
      <td class="right">${Number(v.qty_variance)}</td>
      <td><span class="badge ${BADGE[v.status]||'b-gray'}">${FAOS.esc(v.status)}</span></td></tr>`).join('')
      : '<tr><td class="muted">No variance rows</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('fStatus').addEventListener('change', () => loadVar());
document.getElementById('imp').addEventListener('click', async () => {
  const lines = document.getElementById('lines').value.trim().split('\n').filter(Boolean).map(l => {
    const [sku, qty, amount] = l.split(',').map(s => s.trim());
    return { sku, qty: parseFloat(qty) || 0, amount: parseFloat(amount) || 0 };
  });
  if (!lines.length) return FAOS.toast('Add at least one line', 'err');
  try {
    const r = await FAOS.post('/api/reconciliation/import', {
      outlet_id: +document.getElementById('rcOutlet').value,
      period_start: document.getElementById('ps').value,
      period_end: document.getElementById('pe').value,
      source_name: document.getElementById('src').value,
      lines,
    });
    FAOS.toast('Reconciled — report #' + r.data.report_id, 'ok');
    loadVar(r.data.report_id);
  } catch (e) { FAOS.toast(e.message, 'err'); }
});
loadVar();
</script>
