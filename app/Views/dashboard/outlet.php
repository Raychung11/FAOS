<?php $multi = count($outlets) > 1; ?>
<?php if ($multi): ?>
<div class="card mb"><div class="bd row">
  <div><label>Outlet</label>
  <select id="outletSel"><?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
</div></div>
<?php endif; ?>

<div class="grid cards">
  <div class="card stat pri"><div class="v" id="o-amount">—</div><div class="l">Today's Sales</div></div>
  <div class="card stat ok"><div class="v" id="o-txns">—</div><div class="l">Transactions</div></div>
  <div class="card stat dng"><div class="v" id="o-low">—</div><div class="l">Low / Replenish</div></div>
</div>

<div class="grid col-2 mt">
  <div class="card"><div class="hd">Kiosk Performance (today)</div>
    <div class="bd"><table><thead><tr><th>Kiosk</th><th class="right">Txns</th><th class="right">Sales</th></tr></thead>
    <tbody id="o-kiosk"><tr><td class="muted">Loading…</td></tr></tbody></table></div></div>
  <div class="card"><div class="hd">Pending Replenishment</div>
    <div class="bd" style="max-height:340px;overflow:auto"><table><thead><tr><th>Product</th><th class="right">On hand</th><th class="right">Reorder</th></tr></thead>
    <tbody id="o-rep"><tr><td class="muted">Loading…</td></tr></tbody></table></div></div>
</div>

<script>
async function loadOutlet() {
  const sel = document.getElementById('outletSel');
  const q = sel ? '?outlet_id=' + sel.value : '';
  try {
    const { data } = await FAOS.get('/api/dashboard/outlet' + q);
    document.getElementById('o-amount').textContent = FAOS.money(data.today.amount);
    document.getElementById('o-txns').textContent = data.today.txns;
    document.getElementById('o-low').textContent = data.low_stock.length;
    document.getElementById('o-kiosk').innerHTML = data.kiosk_perf.length
      ? data.kiosk_perf.map(k => `<tr><td>${FAOS.esc(k.name)}</td><td class="right">${k.txns}</td><td class="right">${FAOS.money(k.amount)}</td></tr>`).join('')
      : '<tr><td class="muted">No kiosks</td></tr>';
    document.getElementById('o-rep').innerHTML = data.pending_replenishment.length
      ? data.pending_replenishment.map(p => `<tr><td>${FAOS.esc(p.name)}</td><td class="right">${Number(p.on_hand)}</td><td class="right">${Number(p.reorder_level)}</td></tr>`).join('')
      : '<tr><td class="muted">All stocked</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('outletSel')?.addEventListener('change', loadOutlet);
loadOutlet(); setInterval(loadOutlet, 30000);
</script>
