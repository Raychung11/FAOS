<div class="card mb"><div class="bd row">
  <div><label>From</label><input id="from" type="date"></div>
  <div><label>To</label><input id="to" type="date"></div>
  <div style="display:flex;align-items:flex-end;gap:8px">
    <button class="btn" id="run">Apply</button>
    <button class="btn gray" id="pdf">Print / PDF</button>
    <button class="btn gray" id="csv">CSV</button>
  </div>
  <div style="display:flex;align-items:flex-end;gap:6px;margin-left:auto">
    <button class="btn ghost tab" data-tab="pnl">P&amp;L</button>
    <button class="btn ghost tab" data-tab="ap">Payables</button>
    <button class="btn ghost tab" data-tab="ar">Receivables</button>
    <button class="btn ghost tab" data-tab="stock">Stock Value</button>
  </div>
</div></div>

<section id="pnl">
  <div class="grid cards">
    <div class="card stat pri"><div class="v" id="f-rev">—</div><div class="l">Revenue</div></div>
    <div class="card stat warn"><div class="v" id="f-cogs">—</div><div class="l">COGS</div></div>
    <div class="card stat ok"><div class="v" id="f-gp">—</div><div class="l">Gross Profit</div></div>
    <div class="card stat dng"><div class="v" id="f-waste">—</div><div class="l">Wastage Cost</div></div>
    <div class="card stat"><div class="v" id="f-margin">—</div><div class="l">Gross Margin</div></div>
  </div>
  <div class="card mt"><div class="hd">By Account Group</div><div class="bd" style="overflow:auto">
    <table><thead><tr><th>Account Group</th><th class="right">Revenue</th><th class="right">COGS</th><th class="right">Gross Profit</th></tr></thead>
    <tbody id="f-grp"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div></div>
</section>

<section id="ap" class="hidden">
  <div class="card mb"><div class="hd">Record Supplier Invoice</div><div class="bd row">
    <div><label>Supplier</label><select id="ap-sup">
      <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['code']) ?> · <?= e($s['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div><label>Invoice No</label><input id="ap-no"></div>
    <div><label>Invoice Date</label><input id="ap-date" type="date"></div>
    <div><label>Due Date</label><input id="ap-due" type="date"></div>
    <div><label>Total Amount</label><input id="ap-amt" type="number" step="0.01" min="0"></div>
    <div style="display:flex;align-items:flex-end"><button class="btn" id="ap-add">Add Invoice</button></div>
  </div></div>
  <div class="card"><div class="hd"><span>Accounts Payable</span><span class="badge b-dng" id="ap-tot"></span></div>
    <div class="bd" style="overflow:auto"><table>
      <thead><tr><th>Supplier</th><th>Invoice</th><th>Due</th><th class="right">Total</th><th class="right">Paid</th><th class="right">Outstanding</th><th>Status</th><th></th></tr></thead>
      <tbody id="ap-body"><tr><td class="muted">Loading…</td></tr></tbody></table></div>
  </div>
</section>

<section id="ar" class="hidden">
  <div class="card"><div class="hd"><span>Hypermarket Receivables (from delayed reports)</span><span class="badge b-warn" id="ar-tot"></span></div>
    <div class="bd" style="overflow:auto"><table>
      <thead><tr><th>Outlet</th><th>Period</th><th class="right">Expected</th><th class="right">Received</th><th class="right">Outstanding</th><th>Status</th><th></th></tr></thead>
      <tbody id="ar-body"><tr><td class="muted">Loading…</td></tr></tbody></table></div>
  </div>
  <p class="muted mt" style="font-size:13px">Receivables are created automatically when an official hypermarket report is imported in <a href="<?= base_url('/reconciliation') ?>">Reconciliation</a>.</p>
</section>

<section id="stock" class="hidden">
  <div class="card"><div class="hd"><span>Inventory Valuation (moving-average cost)</span><span class="badge b-info" id="iv-tot"></span></div>
    <div class="bd" style="overflow:auto;max-height:520px"><table>
      <thead><tr><th>SKU</th><th>Product</th><th>Location</th><th class="right">Qty</th><th class="right">Unit cost</th><th class="right">Value</th></tr></thead>
      <tbody id="iv-body"><tr><td class="muted">Loading…</td></tr></tbody></table></div>
  </div>
  <p class="muted mt" style="font-size:13px">Negative rows indicate stock issued without recorded receipts (QR sales without stock-in) — investigate or stock-take.</p>
</section>

<script>
const CANW = <?= json_encode(\App\Core\Auth::can('finance.manage')) ?>;
function qs() {
  const p = new URLSearchParams();
  const f = document.getElementById('from').value, t = document.getElementById('to').value;
  if (f) p.set('from', f); if (t) p.set('to', t);
  return p;
}
function tab(name) {
  ['pnl','ap','ar','stock'].forEach(s => document.getElementById(s).classList.toggle('hidden', s !== name));
  if (name === 'ap') loadAP(); if (name === 'ar') loadAR();
  if (name === 'pnl') loadPnl(); if (name === 'stock') loadStock();
}
async function loadStock() {
  try {
    const { data } = await FAOS.get('/api/finance/inventory-valuation');
    document.getElementById('iv-tot').textContent = 'Total ' + FAOS.money(data.total_value);
    document.getElementById('iv-body').innerHTML = data.rows.length
      ? data.rows.map(r => `<tr${Number(r.qty)<0?' style="color:var(--danger)"':''}>
          <td>${FAOS.esc(r.sku)}</td><td>${FAOS.esc(r.name)}</td>
          <td>${FAOS.esc(r.loc_type)} #${r.loc_id}</td>
          <td class="right">${Number(r.qty)} ${FAOS.esc(r.uom)}</td>
          <td class="right">${FAOS.money(r.unit_cost)}</td>
          <td class="right">${FAOS.money(r.value)}</td></tr>`).join('')
      : '<tr><td class="muted">No stock on hand</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.querySelectorAll('.tab').forEach(b => b.addEventListener('click', () => tab(b.dataset.tab)));

async function loadPnl() {
  try {
    const { data } = await FAOS.get('/api/finance/summary?' + qs());
    document.getElementById('f-rev').textContent = FAOS.money(data.revenue);
    document.getElementById('f-cogs').textContent = FAOS.money(data.cogs);
    document.getElementById('f-gp').textContent = FAOS.money(data.gross_profit);
    document.getElementById('f-waste').textContent = FAOS.money(data.wastage_cost);
    document.getElementById('f-margin').textContent = data.gross_margin_pct + '%';
    document.getElementById('f-grp').innerHTML = data.by_account_group.length
      ? data.by_account_group.map(g => `<tr><td>${FAOS.esc(g.account_group)}</td>
         <td class="right">${FAOS.money(g.revenue)}</td><td class="right">${FAOS.money(g.cogs)}</td>
         <td class="right">${FAOS.money(g.gross_profit)}</td></tr>`).join('')
      : '<tr><td class="muted">No sales in period</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
async function loadAP() {
  try {
    const { data } = await FAOS.get('/api/finance/payables');
    document.getElementById('ap-tot').textContent = 'Outstanding ' + FAOS.money(data.totals.outstanding);
    document.getElementById('ap-body').innerHTML = data.rows.length ? data.rows.map(r => `
      <tr><td>${FAOS.esc(r.supplier_name)}</td><td>${FAOS.esc(r.invoice_no)}</td>
      <td>${FAOS.esc(r.due_date || '—')}${r.days_overdue > 0 ? ' <span class="badge b-dng">'+r.days_overdue+'d</span>' : ''}</td>
      <td class="right">${FAOS.money(r.total_amount)}</td><td class="right">${FAOS.money(r.paid_amount)}</td>
      <td class="right">${FAOS.money(r.outstanding)}</td>
      <td><span class="badge ${r.status==='paid'?'b-ok':r.status==='partial'?'b-warn':'b-dng'}">${FAOS.esc(r.status)}</span></td>
      <td class="right">${CANW && r.status!=='paid' ? `<button class="btn ghost" data-pay="${r.id}" data-out="${r.outstanding}" style="padding:5px 10px">Pay</button>`:''}</td></tr>`).join('')
      : '<tr><td class="muted">No invoices</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
async function loadAR() {
  try {
    const { data } = await FAOS.get('/api/finance/receivables');
    document.getElementById('ar-tot').textContent = 'Outstanding ' + FAOS.money(data.totals.outstanding);
    document.getElementById('ar-body').innerHTML = data.rows.length ? data.rows.map(r => `
      <tr><td>${FAOS.esc(r.outlet_name)}</td><td>${FAOS.esc(r.period_start)} → ${FAOS.esc(r.period_end)}</td>
      <td class="right">${FAOS.money(r.expected_amount)}</td><td class="right">${FAOS.money(r.received_amount)}</td>
      <td class="right">${FAOS.money(r.outstanding)}</td>
      <td><span class="badge ${r.status==='settled'?'b-ok':r.status==='partial'?'b-warn':'b-dng'}">${FAOS.esc(r.status)}</span></td>
      <td class="right">${CANW && r.status!=='settled' ? `<button class="btn ghost" data-recv="${r.id}" data-out="${r.outstanding}" style="padding:5px 10px">Receive</button>`:''}</td></tr>`).join('')
      : '<tr><td class="muted">No receivables yet</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}

document.getElementById('ap-body').addEventListener('click', async e => {
  const b = e.target.closest('[data-pay]'); if (!b) return;
  const amt = prompt('Payment amount (outstanding ' + b.dataset.out + '):', b.dataset.out);
  if (amt === null) return;
  try { await FAOS.post('/api/finance/payables/' + b.dataset.pay + '/pay', { amount: parseFloat(amt), method: 'bank' }); FAOS.toast('Payment recorded', 'ok'); loadAP(); }
  catch (x) { FAOS.toast(x.message, 'err'); }
});
document.getElementById('ar-body').addEventListener('click', async e => {
  const b = e.target.closest('[data-recv]'); if (!b) return;
  const amt = prompt('Amount received (outstanding ' + b.dataset.out + '):', b.dataset.out);
  if (amt === null) return;
  try { await FAOS.post('/api/finance/receivables/' + b.dataset.recv + '/receive', { amount: parseFloat(amt), method: 'bank' }); FAOS.toast('Receipt recorded', 'ok'); loadAR(); }
  catch (x) { FAOS.toast(x.message, 'err'); }
});
document.getElementById('ap-add').addEventListener('click', async () => {
  try {
    await FAOS.post('/api/finance/payables', {
      supplier_id: +document.getElementById('ap-sup').value,
      invoice_no: document.getElementById('ap-no').value,
      invoice_date: document.getElementById('ap-date').value,
      due_date: document.getElementById('ap-due').value || null,
      total_amount: parseFloat(document.getElementById('ap-amt').value) || 0,
    });
    FAOS.toast('Invoice recorded', 'ok'); loadAP();
  } catch (e) { FAOS.toast(e.message, 'err'); }
});
document.getElementById('run').addEventListener('click', loadPnl);
document.getElementById('pdf').addEventListener('click', () => window.open('/api/finance/statement?' + qs(), '_blank'));
document.getElementById('csv').addEventListener('click', () => { const p = qs(); p.set('export','csv'); window.open('/api/finance/statement?' + p, '_blank'); });
loadPnl();
</script>
