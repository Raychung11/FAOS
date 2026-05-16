<div class="card mb"><div class="bd row">
  <div><label>Report</label>
    <select id="rep">
      <option value="sales">Sales Consolidation</option>
      <option value="stock-movement">Stock Movement</option>
      <option value="wastage">Wastage</option>
      <option value="worker-performance">Worker Performance</option>
    </select>
  </div>
  <div id="dimWrap"><label>Group by</label>
    <select id="dim">
      <option value="outlet">Outlet</option><option value="kiosk">Kiosk</option>
      <option value="worker">Worker</option><option value="sku">SKU</option>
      <option value="category">Category</option><option value="stock_group">Stock Group</option>
      <option value="account_group">Account Group</option>
    </select>
  </div>
  <div><label>From</label><input id="from" type="date"></div>
  <div><label>To</label><input id="to" type="date"></div>
  <div style="display:flex;align-items:flex-end;gap:8px">
    <button class="btn" id="run">Run</button>
    <button class="btn gray" id="csv">CSV</button>
  </div>
</div></div>

<div class="card"><div class="hd"><span id="rtitle">Sales Consolidation</span><span class="badge b-info" id="rtot"></span></div>
  <div class="bd" style="overflow:auto"><table><thead id="rhead"></thead><tbody id="rbody"><tr><td class="muted">Run a report…</td></tr></tbody></table></div>
</div>

<script>
function params() {
  const p = new URLSearchParams();
  const f = document.getElementById('from').value, t = document.getElementById('to').value;
  if (f) p.set('from', f); if (t) p.set('to', t);
  if (document.getElementById('rep').value === 'sales') p.set('by', document.getElementById('dim').value);
  return p;
}
document.getElementById('rep').addEventListener('change', e =>
  document.getElementById('dimWrap').style.display = e.target.value === 'sales' ? '' : 'none');

async function run() {
  const rep = document.getElementById('rep').value;
  try {
    const r = await FAOS.get('/api/reports/' + rep + '?' + params().toString());
    const data = rep === 'sales' ? r.data.rows : r.data;
    if (rep === 'sales') document.getElementById('rtot').textContent =
      'Total ' + FAOS.money(r.data.totals.gross) + ' · ' + r.data.totals.qty + ' units';
    else document.getElementById('rtot').textContent = data.length + ' rows';
    if (!data.length) { document.getElementById('rhead').innerHTML = ''; document.getElementById('rbody').innerHTML = '<tr><td class="muted">No data</td></tr>'; return; }
    const keys = Object.keys(data[0]);
    document.getElementById('rhead').innerHTML = '<tr>' + keys.map(k => `<th>${FAOS.esc(k)}</th>`).join('') + '</tr>';
    document.getElementById('rbody').innerHTML = data.map(row =>
      '<tr>' + keys.map(k => `<td>${FAOS.esc(row[k])}</td>`).join('') + '</tr>').join('');
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('run').addEventListener('click', run);
document.getElementById('csv').addEventListener('click', () => {
  const p = params(); p.set('export', 'csv');
  window.open('/api/reports/' + document.getElementById('rep').value + '?' + p.toString(), '_blank');
});
run();
</script>
