<div class="card mb">
  <div class="hd">New Replenishment Request</div>
  <div class="bd">
    <div class="row">
      <div><label>Requesting outlet</label><select id="outlet">
        <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
      </select></div>
    </div>
    <table class="mt"><thead><tr><th>Product</th><th style="width:140px">Qty</th><th></th></tr></thead>
      <tbody id="lines"></tbody></table>
    <button class="btn ghost mt" id="addLine">+ Line</button>
    <button class="btn ok mt" id="save" style="margin-left:8px">Raise Request</button>
  </div>
</div>

<div class="card">
  <div class="hd"><span>Purchase Requests</span>
    <select id="fStat" style="max-width:150px"><option value="">All</option><option>open</option><option>approved</option><option>converted</option><option>rejected</option></select>
  </div>
  <div class="bd" style="overflow:auto"><table>
    <thead><tr><th>PR Ref</th><th>Outlet</th><th>Requested by</th><th>Status</th><th></th></tr></thead>
    <tbody id="body"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div>
</div>

<script>
const CAN_APPROVE=<?= json_encode($canApprove) ?>;
const PRODUCTS=<?= json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'label'=>$p['sku'].' · '.$p['name']],$products)) ?>;
const WH=<?= json_encode(array_map(fn($w)=>['id'=>(int)$w['id'],'name'=>$w['name']],$warehouses)) ?>;
const PB={open:'b-gray',approved:'b-info',converted:'b-ok',rejected:'b-dng'};
function addLine(){
  const tr=document.createElement('tr');
  tr.innerHTML=`<td><select class="pl">${PRODUCTS.map(p=>`<option value="${p.id}">${FAOS.esc(p.label)}</option>`).join('')}</select></td>
    <td><input class="pq" type="number" min="0.001" step="0.001" value="1"></td>
    <td><button class="btn danger" style="padding:5px 9px">✕</button></td>`;
  tr.querySelector('button').addEventListener('click',()=>tr.remove());
  document.getElementById('lines').appendChild(tr);
}
document.getElementById('addLine').addEventListener('click',addLine); addLine();

document.getElementById('save').addEventListener('click',async()=>{
  const items=[...document.querySelectorAll('#lines tr')].map(tr=>({
    product_id:+tr.querySelector('.pl').value, qty:parseFloat(tr.querySelector('.pq').value)||0,
  })).filter(i=>i.qty>0);
  if(!items.length) return FAOS.toast('Add at least one line','err');
  try{
    const r=await FAOS.post('/api/replenishment',{outlet_id:+document.getElementById('outlet').value,items});
    FAOS.toast('Request '+r.data.pr_ref+' raised','ok'); load();
  }catch(e){ FAOS.toast(e.message,'err'); }
});

async function load(){
  const st=document.getElementById('fStat').value;
  try{
    const {data}=await FAOS.get('/api/replenishment'+(st?'?status='+st:''));
    document.getElementById('body').innerHTML=data.length?data.map(p=>`
      <tr><td>${FAOS.esc(p.pr_ref)}</td><td>${FAOS.esc(p.outlet_name)}</td><td>${FAOS.esc(p.requested_by_name||'—')}</td>
      <td><span class="badge ${PB[p.status]||'b-gray'}">${FAOS.esc(p.status)}</span></td>
      <td class="right">
        ${CAN_APPROVE&&p.status==='open'?`<button class="btn ghost" data-ap="${p.id}" style="padding:5px 9px">Approve</button>
          <button class="btn danger" data-rj="${p.id}" style="padding:5px 9px">Reject</button>`:''}
        ${CAN_APPROVE&&p.status==='approved'?`<button class="btn ok" data-fl="${p.id}" style="padding:5px 9px">Fulfil</button>`:''}
      </td></tr>`).join(''):'<tr><td class="muted">No requests</td></tr>';
  }catch(e){ FAOS.toast(e.message,'err'); }
}
document.getElementById('body').addEventListener('click',async e=>{
  const id=e.target.dataset.ap||e.target.dataset.rj||e.target.dataset.fl; if(!id) return;
  try{
    if(e.target.dataset.ap){ await FAOS.post('/api/replenishment/'+id+'/approve'); FAOS.toast('Approved','ok'); }
    else if(e.target.dataset.rj){ await FAOS.post('/api/replenishment/'+id+'/reject'); FAOS.toast('Rejected','ok'); }
    else if(e.target.dataset.fl){
      const opts=WH.map(w=>w.id+'='+w.name).join(', ');
      const src=prompt('Source warehouse id ('+opts+'):',WH[0]?.id);
      if(src===null) return;
      const r=await FAOS.post('/api/replenishment/'+id+'/fulfil',{source_warehouse_id:+src});
      FAOS.toast('Fulfilled · '+r.data.transfers.length+' transfer(s)','ok');
    }
    load();
  }catch(x){ FAOS.toast(x.message,'err'); }
});
document.getElementById('fStat').addEventListener('change',load);
load();
</script>
