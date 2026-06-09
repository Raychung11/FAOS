<div class="card mb"><div class="bd row">
  <?php if (count($outlets) > 1): ?>
  <div><label>Outlet (blank = all)</label>
  <select id="aiOutlet"><option value="">All outlets</option>
  <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div style="display:flex;align-items:flex-end"><button class="btn" id="aiRun">Run Forecast</button></div>
</div></div>

<div id="aiNarr" class="card mb hidden"><div class="hd">🤖 AI Recommendation</div><div class="bd" id="aiNarrBody"></div></div>

<div class="grid col-2">
  <div class="card"><div class="hd">7-Day Demand Forecast (top products)</div>
    <div class="bd" style="max-height:420px;overflow:auto"><table><thead><tr><th>Product</th><th class="right">Avg/day</th><th class="right">Next 7d</th></tr></thead><tbody id="fcBody"><tr><td class="muted">Run a forecast…</td></tr></tbody></table></div></div>
  <div class="card"><div class="hd">Purchase Recommendation</div>
    <div class="bd" style="max-height:420px;overflow:auto"><table><thead><tr><th>Product</th><th class="right">On hand</th><th class="right">7d need</th><th class="right">Order</th></tr></thead><tbody id="poBody"><tr><td class="muted">—</td></tr></tbody></table></div></div>
</div>
<div class="card mt"><div class="hd">⚠ Abnormal Sales Detection (z-score)</div>
  <div class="bd"><table><thead><tr><th>Date</th><th>Product</th><th class="right">Qty</th><th class="right">Expected</th><th>Type</th></tr></thead><tbody id="anBody"><tr><td class="muted">—</td></tr></tbody></table></div></div>

<script>
async function runAI() {
  const sel = document.getElementById('aiOutlet');
  const q = sel && sel.value ? '?outlet_id=' + sel.value : '';
  try {
    FAOS.toast('Computing forecast…');
    const { data } = await FAOS.get('/api/dashboard/ai' + q);
    document.getElementById('fcBody').innerHTML = data.forecast.length
      ? data.forecast.map(f => `<tr><td>${FAOS.esc(f.name)}</td><td class="right">${f.avg_daily}</td><td class="right"><b>${f.next7_total}</b></td></tr>`).join('')
      : '<tr><td class="muted">Not enough sales history</td></tr>';
    document.getElementById('poBody').innerHTML = data.purchase_recommendation.length
      ? data.purchase_recommendation.map(p => `<tr><td>${FAOS.esc(p.name)}</td><td class="right">${p.on_hand}</td><td class="right">${p.forecast_7d}</td><td class="right"><span class="badge b-info">${p.recommend_qty}</span></td></tr>`).join('')
      : '<tr><td class="muted">Stock sufficient</td></tr>';
    document.getElementById('anBody').innerHTML = data.anomalies.length
      ? data.anomalies.map(a => `<tr><td>${FAOS.esc(a.date)}</td><td>#${a.product_id}</td><td class="right">${a.qty}</td><td class="right">${a.expected}</td><td><span class="badge ${a.direction==='spike'?'b-warn':'b-dng'}">${a.direction}</span></td></tr>`).join('')
      : '<tr><td class="muted">No anomalies</td></tr>';
    const nb = document.getElementById('aiNarr');
    if (data.narrative) { nb.classList.remove('hidden'); document.getElementById('aiNarrBody').textContent = data.narrative; }
    else { nb.classList.add('hidden'); }
    FAOS.toast('Forecast ready' + (data.ai_enabled ? ' (AI on)' : ' (built-in model)'), 'ok');
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('aiRun').addEventListener('click', runAI);
runAI();
</script>
