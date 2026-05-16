<div class="card mb"><div class="bd" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
  <strong style="text-transform:capitalize"><?= e(str_replace('_',' ',$resource)) ?></strong>
  <input id="q" placeholder="Search…" style="max-width:260px">
  <button class="btn" id="addBtn" style="margin-left:auto">+ New</button>
</div></div>

<div class="card"><div class="bd" style="overflow:auto">
  <table><thead id="thead"></thead><tbody id="tbody"><tr><td class="muted">Loading…</td></tr></tbody></table>
</div></div>

<div id="modal" class="hidden" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;display:flex;align-items:center;justify-content:center;padding:16px">
  <div class="card" style="width:100%;max-width:520px;max-height:90vh;overflow:auto">
    <div class="hd"><span id="mTitle">Edit</span><button class="btn-icon" id="mClose">✕</button></div>
    <div class="bd"><form id="mForm"></form>
      <button class="btn lg ok mt" id="mSave">Save</button>
    </div>
  </div>
</div>

<script>
const RES = <?= json_encode($resource) ?>;
// Field config: [name, label, type, options?]
const FIELDS = {
  products: [['sku','SKU','text'],['barcode','Barcode','text'],['name','Name','text'],['uom','UOM','text'],
    ['type','Type','select',['raw','semi_finished','finished','consumable']],
    ['cost_price','Cost','number'],['sell_price','Sell Price','number'],['reorder_level','Reorder Level','number'],
    ['shelf_life_days','Shelf Life (days)','number'],['is_sellable','Sellable','bool'],['is_active','Active','bool']],
  outlets: [['code','Code','text'],['name','Name','text'],['hypermarket','Hypermarket','text'],['region','Region','text'],
    ['third_party_pos','3rd-party POS','bool'],['report_lag_days','Report lag (days)','number'],['is_active','Active','bool']],
  kiosks: [['outlet_id','Outlet ID','number'],['code','Code','text'],['name','Name','text'],['location_note','Location','text'],['is_active','Active','bool']],
  suppliers: [['code','Code','text'],['name','Name','text'],['contact_person','Contact','text'],['phone','Phone','text'],['email','Email','text'],['payment_terms','Terms','text'],['is_active','Active','bool']],
  recipes: [['product_id','Output Product ID','number'],['yield_qty','Yield Qty','number'],['yield_uom','Yield UOM','text'],['notes','Notes','text'],['is_active','Active','bool']],
};
const cfg = FIELDS[RES] || [['code','Code','text'],['name','Name','text']];
const cols = cfg.map(f => f[0]);
let rows = [], editing = null;

document.getElementById('thead').innerHTML = '<tr>' + cfg.map(f => `<th>${FAOS.esc(f[1])}</th>`).join('') + '<th></th></tr>';

async function load() {
  const q = document.getElementById('q').value;
  try {
    const { data } = await FAOS.get('/api/md/' + RES + (q ? '?q=' + encodeURIComponent(q) : ''));
    rows = data;
    document.getElementById('tbody').innerHTML = data.length ? data.map(r => '<tr>' +
      cols.map(c => `<td>${FAOS.esc(r[c])}</td>`).join('') +
      `<td class="right"><button class="btn ghost" data-edit="${r.id}" style="padding:5px 10px">Edit</button>
       <button class="btn danger" data-del="${r.id}" style="padding:5px 10px">Del</button></td></tr>`).join('')
      : '<tr><td class="muted">No records</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
function field(f, val) {
  const [n, l, t, opts] = f;
  if (t === 'bool') return `<label>${l}</label><select name="${n}"><option value="1"${val==1?' selected':''}>Yes</option><option value="0"${val==0?' selected':''}>No</option></select>`;
  if (t === 'select') return `<label>${l}</label><select name="${n}">${opts.map(o => `<option ${val===o?'selected':''}>${o}</option>`).join('')}</select>`;
  return `<label>${l}</label><input name="${n}" type="${t==='number'?'number':'text'}" ${t==='number'?'step="0.001"':''} value="${FAOS.esc(val==null?'':val)}">`;
}
function openModal(rec) {
  editing = rec ? rec.id : null;
  document.getElementById('mTitle').textContent = rec ? 'Edit' : 'New ' + RES;
  document.getElementById('mForm').innerHTML = cfg.map(f => field(f, rec ? rec[f[0]] : '')).join('');
  document.getElementById('modal').classList.remove('hidden');
}
document.getElementById('addBtn').addEventListener('click', () => openModal(null));
document.getElementById('mClose').addEventListener('click', () => document.getElementById('modal').classList.add('hidden'));
document.getElementById('tbody').addEventListener('click', e => {
  if (e.target.dataset.edit) openModal(rows.find(r => r.id == e.target.dataset.edit));
  if (e.target.dataset.del && confirm('Delete / deactivate this record?'))
    FAOS.del('/api/md/' + RES + '/' + e.target.dataset.del).then(() => { FAOS.toast('Deleted', 'ok'); load(); }).catch(x => FAOS.toast(x.message, 'err'));
});
document.getElementById('mSave').addEventListener('click', async () => {
  const fd = new FormData(document.getElementById('mForm'));
  const body = {}; fd.forEach((v, k) => body[k] = v);
  try {
    if (editing) await FAOS.put('/api/md/' + RES + '/' + editing, body);
    else await FAOS.post('/api/md/' + RES, body);
    FAOS.toast('Saved', 'ok');
    document.getElementById('modal').classList.add('hidden');
    load();
  } catch (e) { FAOS.toast(e.message + (e.payload && e.payload.errors ? ': ' + JSON.stringify(e.payload.errors) : ''), 'err'); }
});
let t; document.getElementById('q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(load, 300); });
load();
</script>
