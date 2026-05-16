<div class="card mb">
  <div class="hd">New Purchase Order</div>
  <div class="bd">
    <div class="row">
      <div><label>Supplier</label><select id="sup">
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['code']) ?> · <?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Receiving warehouse</label><select id="wh">
        <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Expected date</label><input id="exp" type="date"></div>
    </div>
    <table class="mt"><thead><tr><th>Product</th><th style="width:120px">Qty</th><th style="width:140px">Unit cost</th><th></th></tr></thead>
      <tbody id="lines"></tbody></table>
    <button class="btn ghost mt" id="addLine">+ Line</button>
    <button class="btn ok mt" id="savePO" style="margin-left:8px">Create PO (draft)</button>
  </div>
</div>

<div class="card">
  <div class="hd"><span>Purchase Orders</span>
    <select id="fStat" style="max-width:160px"><option value="">All</option><option>draft</option><option>approved</option><option>partial</option><option>received</option></select>
  </div>
  <div class="bd" style="overflow:auto"><table>
    <thead><tr><th>PO Ref</th><th>Supplier</th><th>Warehouse</th><th class="right">Total</th><th>Status</th><th></th></tr></thead>
    <tbody id="poBody"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div>
</div>

<script>
const PRODUCTS = <?= json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'label'=>$p['sku'].' · '.$p['name'],'cost'=>(float)$p['cost_price']],$products)) ?>;
const SBADGE = { draft:'b-gray', approved:'b-info', partial:'b-warn', received:'b-ok', closed:'b-ok', cancelled:'b-dng' };
function optList(){ return PRODUCTS.map(p=>`<option value="${p.id}" data-cost="${p.cost}">${FAOS.esc(p.label)}</option>`).join(''); }
function addLine(){
  const tr=document.createElement('tr');
  tr.innerHTML=`<td><select class="pl">${optList()}</select></td>
    <td><input class="pq" type="number" min="0.001" step="0.001" value="1"></td>
    <td><input class="pc" type="number" min="0" step="0.0001"></td>
    <td><button class="btn danger" style="padding:5px 9px">✕</button></td>`;
  tr.querySelector('.pl').addEventListener('change',e=>{ tr.querySelector('.pc').value=e.target.selectedOptions[0].dataset.cost; });
  tr.querySelector('.pc').value=PRODUCTS[0]?.cost ?? 0;
  tr.querySelector('button').addEventListener('click',()=>tr.remove());
  document.getElementById('lines').appendChild(tr);
}
document.getElementById('addLine').addEventListener('click',addLine); addLine();

document.getElementById('savePO').addEventListener('click',async()=>{
  const items=[...document.querySelectorAll('#lines tr')].map(tr=>({
    product_id:+tr.querySelector('.pl').value,
    qty:parseFloat(tr.querySelector('.pq').value)||0,
    unit_cost:parseFloat(tr.querySelector('.pc').value)||0,
  })).filter(i=>i.qty>0);
  if(!items.length) return FAOS.toast('Add at least one line','err');
  try{
    const r=await FAOS.post('/api/procurement/po',{supplier_id:+document.getElementById('sup').value,
      warehouse_id:+document.getElementById('wh').value, expected_date:document.getElementById('exp').value||null, items});
    FAOS.toast('PO '+r.data.po_ref+' created','ok'); load();
  }catch(e){ FAOS.toast(e.message,'err'); }
});

async function receive(poId){
  try{
    const po=(await FAOS.get('/api/procurement/po/'+poId)).data;
    const items=po.items.filter(i=>(+i.qty)-(+i.received_qty)>0.0001)
      .map(i=>({product_id:+i.product_id, qty:(+i.qty)-(+i.received_qty), unit_cost:+i.unit_cost}));
    if(!items.length) return FAOS.toast('Nothing outstanding','err');
    const inv=prompt('Supplier invoice no (optional):','')||null;
    const r=await FAOS.post('/api/procurement/grn',{po_id:poId, supplier_id:+po.supplier_id,
      warehouse_id:+po.warehouse_id, invoice_no:inv, items});
    FAOS.toast('GRN '+r.data.grn_ref+' · stock in + AP invoice #'+r.data.invoice_id,'ok'); load();
  }catch(e){ FAOS.toast(e.message,'err'); }
}
async function load(){
  const st=document.getElementById('fStat').value;
  try{
    const {data}=await FAOS.get('/api/procurement/po'+(st?'?status='+st:''));
    document.getElementById('poBody').innerHTML=data.length?data.map(p=>`
      <tr><td>${FAOS.esc(p.po_ref)}</td><td>${FAOS.esc(p.supplier_name)}</td><td>${FAOS.esc(p.warehouse_name||'—')}</td>
      <td class="right">${FAOS.money(p.total_amount)}</td>
      <td><span class="badge ${SBADGE[p.status]||'b-gray'}">${FAOS.esc(p.status)}</span></td>
      <td class="right">
        ${p.status==='draft'?`<button class="btn ghost" data-ap="${p.id}" style="padding:5px 9px">Approve</button>`:''}
        ${(p.status==='approved'||p.status==='partial')?`<button class="btn ok" data-rc="${p.id}" style="padding:5px 9px">Receive</button>`:''}
      </td></tr>`).join(''):'<tr><td class="muted">No POs</td></tr>';
  }catch(e){ FAOS.toast(e.message,'err'); }
}
document.getElementById('poBody').addEventListener('click',async e=>{
  if(e.target.dataset.ap){ try{ await FAOS.post('/api/procurement/po/'+e.target.dataset.ap+'/approve'); FAOS.toast('Approved','ok'); load(); }catch(x){FAOS.toast(x.message,'err');} }
  if(e.target.dataset.rc){ receive(+e.target.dataset.rc); }
});
document.getElementById('fStat').addEventListener('change',load);
load();
</script>
