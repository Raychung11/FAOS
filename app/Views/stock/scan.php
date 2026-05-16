<div class="grid col-2">
  <div class="card">
    <div class="hd"><span>Scan Stock QR</span><span class="badge b-info" id="ss">…</span></div>
    <div class="bd">
      <div class="scanbox" id="sw"><video id="v" playsinline muted></video><div class="frame"></div></div>
      <div class="row mt">
        <input id="ref" placeholder="Enter STK- reference">
        <button class="btn" id="look" style="flex:0 0 auto">Lookup</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="hd">Stock Action</div>
    <div class="bd">
      <div id="info" class="muted">Scan or enter a QR reference.</div>
      <div id="form" class="hidden">
        <label>Action</label>
        <select id="act">
          <option value="receive">Receive (Supplier → Location)</option>
          <option value="transfer">Transfer (Location → Location)</option>
          <option value="consume">Consume</option>
          <option value="wastage">Wastage</option>
        </select>
        <div class="row">
          <div id="fromWrap" class="hidden">
            <label>From</label>
            <select id="fromLoc"></select>
          </div>
          <div id="toWrap">
            <label>To</label>
            <select id="toLoc"></select>
          </div>
        </div>
        <label>Quantity</label>
        <input id="qty" type="number" min="0.001" step="0.001" value="1">
        <label>Notes (optional)</label>
        <input id="notes">
        <button class="btn lg ok mt" id="submit">Submit Movement</button>
      </div>
      <div id="hist" class="mt"></div>
    </div>
  </div>
</div>

<script>
const LOCS = {
  warehouse: <?= json_encode(array_map(fn($w)=>['id'=>(int)$w['id'],'name'=>$w['name']], $warehouses)) ?>,
  outlet: <?= json_encode(array_map(fn($o)=>['id'=>(int)$o['id'],'name'=>$o['name']], $outlets)) ?>,
  kiosk: <?= json_encode(array_map(fn($k)=>['id'=>(int)$k['id'],'name'=>$k['name']], $kiosks)) ?>,
};
let current = null;

function locOptions(includeType) {
  let html = '';
  ['warehouse', 'outlet', 'kiosk'].forEach(t => {
    LOCS[t].forEach(l => { html += `<option value="${t}:${l.id}">${FAOS.esc(t)} · ${FAOS.esc(l.name)}</option>`; });
  });
  return html;
}
document.getElementById('toLoc').innerHTML = locOptions();
document.getElementById('fromLoc').innerHTML = locOptions();

document.getElementById('act').addEventListener('change', e => {
  const a = e.target.value;
  document.getElementById('fromWrap').classList.toggle('hidden', a === 'receive');
  document.getElementById('toWrap').classList.toggle('hidden', a === 'consume' || a === 'wastage');
});

async function lookup(ref) {
  ref = String(ref).trim(); if (!ref) return;
  try {
    const r = await FAOS.get('/api/qr/' + encodeURIComponent(ref) + '?context=lookup');
    current = r.data;
    document.getElementById('info').innerHTML = `
      <div class="badge b-info">${FAOS.esc(current.qr_ref)}</div>
      <h3 style="margin:8px 0">${FAOS.esc(current.product_name)} <small class="muted">(${FAOS.esc(current.sku)})</small></h3>
      <div class="muted">Batch: ${FAOS.esc(current.batch_no || '—')} · Expiry: ${FAOS.esc(current.expiry_date || '—')}
      · Loc: ${FAOS.esc(current.current_location_type)}</div>`;
    document.getElementById('form').classList.remove('hidden');
    document.getElementById('hist').innerHTML = '<div class="hd" style="padding:10px 0">Movement History</div>' +
      '<table><thead><tr><th>When</th><th>Type</th><th class="right">Qty</th><th>By</th></tr></thead><tbody>' +
      (current.history.length ? current.history.map(h =>
        `<tr><td>${FAOS.esc(h.created_at)}</td><td><span class="badge b-gray">${FAOS.esc(h.movement_type)}</span></td>
         <td class="right">${Number(h.qty)}</td><td>${FAOS.esc(h.user_name || '—')}</td></tr>`).join('')
        : '<tr><td class="muted">No movements</td></tr>') + '</tbody></table>';
    if (navigator.vibrate) navigator.vibrate(40);
  } catch (e) { FAOS.toast(e.message, 'err'); current = null; }
}

document.getElementById('look').addEventListener('click', () => lookup(document.getElementById('ref').value));
document.getElementById('ref').addEventListener('keydown', e => { if (e.key === 'Enter') lookup(e.target.value); });

document.getElementById('submit').addEventListener('click', async () => {
  if (!current) return;
  const act = document.getElementById('act').value;
  const qv = parseFloat(document.getElementById('qty').value);
  if (!(qv > 0)) return FAOS.toast('Enter quantity', 'err');
  const body = { qr_ref: current.qr_ref, qty: qv, notes: document.getElementById('notes').value };
  const to = (document.getElementById('toLoc').value || '').split(':');
  const from = (document.getElementById('fromLoc').value || '').split(':');
  let url = '/api/stock/' + act;
  if (act === 'receive') { body.to_type = to[0]; body.to_id = +to[1]; }
  else if (act === 'transfer') { body.from_type = from[0]; body.from_id = +from[1]; body.to_type = to[0]; body.to_id = +to[1]; }
  else { body.from_type = from[0]; body.from_id = +from[1]; }
  try {
    const r = await FAOS.post(url, body);
    FAOS.toast(r.message, 'ok');
    lookup(current.qr_ref);
  } catch (e) { FAOS.toast(e.message, 'err'); }
});

const sc = new FAOSScanner(document.getElementById('v'), lookup);
if (sc.supported()) {
  sc.start().then(() => document.getElementById('ss').textContent = 'Camera active')
    .catch(() => { document.getElementById('ss').textContent = 'Manual entry'; document.getElementById('sw').classList.add('hidden'); });
} else { document.getElementById('ss').textContent = 'Manual entry'; document.getElementById('sw').classList.add('hidden'); }
</script>
