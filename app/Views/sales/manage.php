<div class="card mb"><div class="bd row">
  <div><label>From</label><input id="from" type="date"></div>
  <div><label>To</label><input id="to" type="date"></div>
  <div style="display:flex;align-items:flex-end"><button class="btn" id="run">Search</button></div>
</div></div>

<div class="card">
  <div class="hd">Recent Sales</div>
  <div class="bd" style="overflow:auto"><table>
    <thead><tr><th>Ref</th><th>When</th><th>Outlet</th><th>Worker</th><th class="right">Total</th><th>Pay</th><th>Status</th><th></th></tr></thead>
    <tbody id="body"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div>
</div>

<div id="modal" class="hidden" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;display:flex;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:560px;max-height:90vh;overflow:auto">
    <div class="hd"><span id="mTitle">Sale</span><button class="btn-icon" id="mClose">✕</button></div>
    <div class="bd" id="mBody"></div>
  </div>
</div>

<script>
const SB = { completed:'b-ok', voided:'b-dng', refunded:'b-warn', partially_refunded:'b-warn' };
let CUR = null;

async function load() {
  const p = new URLSearchParams();
  if (document.getElementById('from').value) p.set('from', document.getElementById('from').value);
  if (document.getElementById('to').value) p.set('to', document.getElementById('to').value);
  try {
    const { data } = await FAOS.get('/api/sales?' + p);
    document.getElementById('body').innerHTML = data.length ? data.map(s => `
      <tr><td>${FAOS.esc(s.txn_ref)}</td><td>${FAOS.esc(s.sold_at)}</td>
      <td>${FAOS.esc(s.outlet_name||'')}</td><td>${FAOS.esc(s.worker_name||'')}</td>
      <td class="right">${FAOS.money(s.total_amount)}</td><td>${FAOS.esc(s.payment_type)}</td>
      <td><span class="badge ${SB[s.status]||'b-gray'}">${FAOS.esc(s.status||'completed')}</span></td>
      <td class="right"><button class="btn ghost" data-id="${s.id}" style="padding:5px 10px">Open</button></td></tr>`).join('')
      : '<tr><td class="muted">No sales</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('run').addEventListener('click', load);
document.getElementById('mClose').addEventListener('click', () => document.getElementById('modal').classList.add('hidden'));

document.getElementById('body').addEventListener('click', async e => {
  const b = e.target.closest('[data-id]'); if (!b) return;
  try {
    const { data } = await FAOS.get('/api/sales/' + b.dataset.id);
    CUR = data;
    const canCorrect = data.status === 'completed' || data.status === 'partially_refunded';
    const rows = data.items.map(i => {
      const rem = Number(i.qty) - Number(i.refunded_qty || 0);
      return `<tr><td>${FAOS.esc(i.product_name)}</td><td class="right">${Number(i.qty)}</td>
        <td class="right">${FAOS.money(i.line_amount)}</td>
        <td class="right">${Number(i.refunded_qty||0)}</td>
        <td><input type="number" class="rq" data-si="${i.id}" min="0" step="1" max="${rem}" value="0"
            ${rem<=0||data.status==='voided'?'disabled':''} style="width:70px"></td></tr>`;
    }).join('');
    document.getElementById('mTitle').textContent = data.txn_ref + ' · ' + (data.status||'completed');
    document.getElementById('mBody').innerHTML = `
      <table><thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Amount</th><th class="right">Refunded</th><th>Refund qty</th></tr></thead>
      <tbody>${rows}</tbody></table>
      <label>Reason</label><input id="rsn" placeholder="Reason (optional)">
      <label>Refund method</label>
      <select id="rmethod"><option value="cash">Cash</option><option value="card">Card</option><option value="ewallet">E-Wallet</option><option value="transfer">Transfer</option></select>
      <div class="row mt">
        <button class="btn danger" id="voidBtn" ${data.status!=='completed'?'disabled':''}>Void entire sale</button>
        <button class="btn ok" id="refundBtn" ${!canCorrect?'disabled':''}>Refund selected</button>
      </div>
      ${data.refunds && data.refunds.length ? '<p class="muted mt" style="font-size:12px">Refunds: '+data.refunds.map(r=>FAOS.esc(r.refund_ref)+' '+FAOS.money(r.total_amount)).join(', ')+'</p>' : ''}`;
    document.getElementById('modal').classList.remove('hidden');

    document.getElementById('voidBtn').addEventListener('click', async () => {
      if (!confirm('Void the entire sale? Stock will be reversed.')) return;
      try { await FAOS.post('/api/sales/' + CUR.id + '/void', { reason: document.getElementById('rsn').value });
        FAOS.toast('Sale voided', 'ok'); document.getElementById('modal').classList.add('hidden'); load();
      } catch (x) { FAOS.toast(x.message, 'err'); }
    });
    document.getElementById('refundBtn').addEventListener('click', async () => {
      const items = [...document.querySelectorAll('.rq')].map(el => ({ sales_item_id: +el.dataset.si, qty: parseFloat(el.value) || 0 })).filter(i => i.qty > 0);
      if (!items.length) return FAOS.toast('Enter refund quantities', 'err');
      try { await FAOS.post('/api/sales/' + CUR.id + '/refund', { items, reason: document.getElementById('rsn').value, refund_method: document.getElementById('rmethod').value });
        FAOS.toast('Refund recorded', 'ok'); document.getElementById('modal').classList.add('hidden'); load();
      } catch (x) { FAOS.toast(x.message, 'err'); }
    });
  } catch (e) { FAOS.toast(e.message, 'err'); }
});
load();
</script>
