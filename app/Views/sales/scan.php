<div id="offlineBar" class="offline-bar hidden">⚠ Offline — sales are queued locally (<span id="qCount">0</span> pending)</div>

<div class="grid col-2">
  <div class="card">
    <div class="hd">
      <span>Scan / Select Product</span>
      <span class="badge b-info" id="scanState">…</span>
    </div>
    <div class="bd">
      <div class="scanbox" id="scanWrap">
        <video id="video" playsinline muted></video>
        <div class="frame"></div>
      </div>
      <div class="row mt">
        <input id="manualRef" placeholder="Type QR ref / barcode and press Enter" autocomplete="off">
        <button class="btn" id="manualBtn" style="flex:0 0 auto">Add</button>
      </div>
      <div class="mt">
        <label>Quick tap (fast sale &lt; 5s)</label>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px" id="quickGrid"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="hd"><span>Cart</span><span id="cartTotal" class="badge b-ok">RM 0.00</span></div>
    <div class="bd">
      <div id="cart"><p class="muted center">Scan a product QR to begin</p></div>
      <label>Payment method</label>
      <select id="pay">
        <option value="cash">Cash</option>
        <option value="card">Card</option>
        <option value="ewallet">E-Wallet</option>
        <option value="transfer">Transfer</option>
      </select>
      <button class="btn lg ok mt" id="confirmBtn" disabled>✓ Confirm Sale</button>
      <button class="btn gray mt" id="clearBtn" style="width:100%">Clear</button>
    </div>
  </div>
</div>

<script>
const PRODUCTS = <?= json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'sku'=>$p['sku'],'barcode'=>$p['barcode'],'name'=>$p['name'],'price'=>(float)$p['sell_price']], $products)) ?>;
const byBarcode = {}, byId = {};
PRODUCTS.forEach(p => { byId[p.id] = p; if (p.barcode) byBarcode[p.barcode] = p; });

let cart = [];
const cartEl = document.getElementById('cart');
const totalEl = document.getElementById('cartTotal');
const confirmBtn = document.getElementById('confirmBtn');

function render() {
  if (!cart.length) { cartEl.innerHTML = '<p class="muted center">Scan a product QR to begin</p>'; }
  else {
    cartEl.innerHTML = cart.map((c, i) => `
      <div class="cart-item">
        <div class="nm">${FAOS.esc(c.name)}<br><small class="muted">${FAOS.money(c.price)}</small></div>
        <input class="qty" type="number" min="1" step="1" value="${c.qty}" data-i="${i}">
        <button class="btn danger" data-del="${i}" style="padding:6px 10px">✕</button>
      </div>`).join('');
  }
  const total = cart.reduce((s, c) => s + c.price * c.qty, 0);
  totalEl.textContent = FAOS.money(total);
  confirmBtn.disabled = cart.length === 0;
}
cartEl.addEventListener('input', e => {
  if (e.target.dataset.i !== undefined) { cart[e.target.dataset.i].qty = Math.max(1, parseInt(e.target.value) || 1); render(); }
});
cartEl.addEventListener('click', e => {
  if (e.target.dataset.del !== undefined) { cart.splice(e.target.dataset.del, 1); render(); }
});

function addProduct(p, qrLabelId) {
  const ex = cart.find(c => c.product_id === p.id);
  if (ex) ex.qty += 1;
  else cart.push({ product_id: p.id, name: p.name, price: p.price, qty: 1, qr_label_id: qrLabelId || null });
  render();
  if (navigator.vibrate) navigator.vibrate(40);
}

async function resolve(code) {
  code = String(code).trim();
  if (!code) return;
  if (byBarcode[code]) return addProduct(byBarcode[code]);
  if (/^STK-/.test(code)) {
    try {
      const r = await FAOS.get('/api/qr/' + encodeURIComponent(code) + '?context=sale');
      const p = byId[r.data.product_id] || { id: r.data.product_id, name: r.data.product_name, price: Number(r.data.sell_price || 0) };
      addProduct(p, r.data.id);
      FAOS.toast('Added: ' + p.name, 'ok');
    } catch (e) { FAOS.toast('QR not found', 'err'); }
    return;
  }
  const p = PRODUCTS.find(x => x.sku === code);
  if (p) addProduct(p); else FAOS.toast('Unknown code: ' + code, 'err');
}

// Quick-tap grid
document.getElementById('quickGrid').innerHTML = PRODUCTS.slice(0, 18)
  .map(p => `<button class="btn ghost" data-pid="${p.id}" style="padding:14px 8px;font-size:13px">${FAOS.esc(p.name)}<br><small>${FAOS.money(p.price)}</small></button>`).join('');
document.getElementById('quickGrid').addEventListener('click', e => {
  const b = e.target.closest('[data-pid]'); if (b) addProduct(byId[+b.dataset.pid]);
});

// Manual entry
const mref = document.getElementById('manualRef');
function manualAdd() { resolve(mref.value); mref.value = ''; mref.focus(); }
document.getElementById('manualBtn').addEventListener('click', manualAdd);
mref.addEventListener('keydown', e => { if (e.key === 'Enter') manualAdd(); });

// Camera scanner (native BarcodeDetector)
const scanner = new FAOSScanner(document.getElementById('video'), resolve);
const stateEl = document.getElementById('scanState');
if (scanner.supported()) {
  scanner.start().then(() => stateEl.textContent = 'Camera active — point at QR')
    .catch((e) => { stateEl.textContent = e.message || 'Camera unavailable — use input'; document.getElementById('scanWrap').classList.add('hidden'); document.getElementById('manualRef').focus(); });
} else {
  stateEl.textContent = 'Use manual entry';
  document.getElementById('scanWrap').classList.add('hidden');
}

// Confirm sale (offline-safe)
function refreshQueue() {
  const n = FAOSQueue.count();
  document.getElementById('qCount').textContent = n;
  document.getElementById('offlineBar').classList.toggle('hidden', navigator.onLine && n === 0);
}
document.addEventListener('faos:queue', refreshQueue);
window.addEventListener('online', refreshQueue);
window.addEventListener('offline', refreshQueue);

document.getElementById('clearBtn').addEventListener('click', () => { cart = []; render(); });
confirmBtn.addEventListener('click', async () => {
  if (!cart.length) return;
  confirmBtn.disabled = true;
  const sale = {
    client_uuid: FAOSUuid(),
    payment_type: document.getElementById('pay').value,
    sold_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    items: cart.map(c => ({ product_id: c.product_id, qty: c.qty, unit_price: c.price, qr_label_id: c.qr_label_id })),
  };
  try {
    if (navigator.onLine) {
      const r = await FAOS.post('/api/sales', sale);
      FAOS.toast('Sale recorded · ' + r.data.transaction.txn_ref, 'ok');
    } else {
      FAOSQueue.add(sale);
      FAOS.toast('Offline — sale queued', 'err');
      refreshQueue();
    }
    cart = []; render();
  } catch (e) {
    FAOSQueue.add(sale);
    FAOS.toast('Saved to queue (' + e.message + ')', 'err');
    refreshQueue();
    cart = []; render();
  } finally { confirmBtn.disabled = cart.length === 0; }
});

refreshQueue();
FAOSQueue.flush();
render();
</script>
