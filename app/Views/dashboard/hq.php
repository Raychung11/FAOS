<div class="grid cards">
  <div class="card stat pri"><div class="v" id="h-today">—</div><div class="l">Today's Sales</div></div>
  <div class="card stat ok"><div class="v" id="h-month">—</div><div class="l">Month to Date</div></div>
  <div class="card stat warn"><div class="v" id="h-low">—</div><div class="l">Low Stock Items</div></div>
  <div class="card stat dng"><div class="v" id="h-exp">—</div><div class="l">Expiring ≤ 3d</div></div>
  <div class="card stat"><div class="v" id="h-scan">—</div><div class="l">QR Scans Today</div></div>
</div>

<div class="card mt"><div class="hd">14-Day Sales Trend</div><div class="bd"><canvas id="trend" height="90"></canvas></div></div>

<div class="grid col-2 mt">
  <div class="card"><div class="hd">Outlet Ranking (30d)</div><div class="bd"><table><tbody id="h-out"></tbody></table></div></div>
  <div class="card"><div class="hd">Top Products (30d)</div><div class="bd"><table><tbody id="h-prod"></tbody></table></div></div>
</div>
<div class="grid col-2 mt">
  <div class="card"><div class="hd">Low Stock Alerts</div><div class="bd" style="max-height:280px;overflow:auto"><table><tbody id="h-lowtbl"></tbody></table></div></div>
  <div class="card"><div class="hd">Expiry Alerts</div><div class="bd" style="max-height:280px;overflow:auto"><table><tbody id="h-exptbl"></tbody></table></div></div>
</div>

<script>
function sparkline(c, points) {
  const ctx = c.getContext('2d'), W = c.width = c.offsetWidth, H = c.height;
  ctx.clearRect(0, 0, W, H);
  if (!points.length) return;
  const max = Math.max(...points.map(p => +p.amount), 1);
  ctx.strokeStyle = '#2563eb'; ctx.lineWidth = 2; ctx.beginPath();
  points.forEach((p, i) => {
    const x = (i / Math.max(1, points.length - 1)) * (W - 20) + 10;
    const y = H - 12 - (+p.amount / max) * (H - 24);
    i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
  });
  ctx.stroke();
  ctx.fillStyle = 'rgba(37,99,235,.12)';
  ctx.lineTo(W - 10, H - 12); ctx.lineTo(10, H - 12); ctx.closePath(); ctx.fill();
}
async function loadHQ() {
  try {
    const { data } = await FAOS.get('/api/dashboard/hq');
    document.getElementById('h-today').textContent = FAOS.money(data.today.amount);
    document.getElementById('h-month').textContent = FAOS.money(data.month_amount);
    document.getElementById('h-low').textContent = data.low_stock.length;
    document.getElementById('h-exp').textContent = data.expiring.length;
    document.getElementById('h-scan').textContent = (data.scan_activity && data.scan_activity.total) || 0;
    document.getElementById('h-out').innerHTML = data.outlet_rank.map((o, i) =>
      `<tr><td>${i + 1}. ${FAOS.esc(o.name)}</td><td class="right">${FAOS.money(o.amount)}</td></tr>`).join('') || '<tr><td class="muted">No data</td></tr>';
    document.getElementById('h-prod').innerHTML = data.product_rank.map(p =>
      `<tr><td>${FAOS.esc(p.name)}</td><td class="right">${Number(p.qty)}</td><td class="right">${FAOS.money(p.amount)}</td></tr>`).join('') || '<tr><td class="muted">No data</td></tr>';
    document.getElementById('h-lowtbl').innerHTML = data.low_stock.map(s =>
      `<tr><td>${FAOS.esc(s.name)}</td><td class="right"><span class="badge b-dng">${Number(s.on_hand)} / ${Number(s.reorder_level)}</span></td></tr>`).join('') || '<tr><td class="muted">All good</td></tr>';
    document.getElementById('h-exptbl').innerHTML = data.expiring.map(b =>
      `<tr><td>${FAOS.esc(b.name)}</td><td class="right"><span class="badge b-warn">${FAOS.esc(b.expiry_date)}</span></td></tr>`).join('') || '<tr><td class="muted">None</td></tr>';
    sparkline(document.getElementById('trend'), data.trend);
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
loadHQ(); setInterval(loadHQ, 30000);
window.addEventListener('resize', loadHQ);
</script>
