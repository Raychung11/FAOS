<div class="card mb">
  <div class="hd">New Production Order</div>
  <div class="bd">
    <?php if (!$products): ?>
      <p class="muted">No products have an active recipe/BOM. Add a recipe under Master Data first.</p>
    <?php else: ?>
    <div class="row">
      <div><label>Output product (has recipe)</label><select id="prod">
        <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['sku']) ?> · <?= e($p['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Central kitchen</label><select id="wh">
        <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Planned qty</label><input id="qty" type="number" min="0.001" step="0.001" value="50"></div>
      <div><label>Planned date</label><input id="pdate" type="date"></div>
      <div style="display:flex;align-items:flex-end"><button class="btn ok" id="create">Create</button></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="hd"><span>Production Orders</span>
    <select id="fStat" style="max-width:160px"><option value="">All</option><option>planned</option><option>in_progress</option><option>completed</option></select>
  </div>
  <div class="bd" style="overflow:auto"><table>
    <thead><tr><th>Ref</th><th>Product</th><th>Kitchen</th><th class="right">Planned</th><th class="right">Produced</th><th>Status</th><th></th></tr></thead>
    <tbody id="body"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div>
</div>

<script>
const PB={planned:'b-gray',in_progress:'b-info',completed:'b-ok',cancelled:'b-dng'};
const createBtn=document.getElementById('create');
if(createBtn) createBtn.addEventListener('click',async()=>{
  try{
    const r=await FAOS.post('/api/production',{product_id:+document.getElementById('prod').value,
      warehouse_id:+document.getElementById('wh').value, planned_qty:parseFloat(document.getElementById('qty').value)||0,
      planned_date:document.getElementById('pdate').value||null});
    FAOS.toast('Production '+r.data.prod_ref+' created','ok'); load();
  }catch(e){ FAOS.toast(e.message,'err'); }
});
async function load(){
  const st=document.getElementById('fStat').value;
  try{
    const {data}=await FAOS.get('/api/production'+(st?'?status='+st:''));
    document.getElementById('body').innerHTML=data.length?data.map(p=>`
      <tr><td>${FAOS.esc(p.prod_ref)}</td><td>${FAOS.esc(p.product_name)}</td><td>${FAOS.esc(p.warehouse_name)}</td>
      <td class="right">${Number(p.planned_qty)}</td><td class="right">${Number(p.produced_qty)}</td>
      <td><span class="badge ${PB[p.status]||'b-gray'}">${FAOS.esc(p.status)}</span></td>
      <td class="right">
        ${p.status==='planned'?`<button class="btn ghost" data-st="${p.id}" style="padding:5px 9px">Start</button>`:''}
        ${(p.status==='planned'||p.status==='in_progress')?`<button class="btn ok" data-cp="${p.id}" data-q="${p.planned_qty}" style="padding:5px 9px">Complete</button>`:''}
      </td></tr>`).join(''):'<tr><td class="muted">No production orders</td></tr>';
  }catch(e){ FAOS.toast(e.message,'err'); }
}
document.getElementById('body').addEventListener('click',async e=>{
  if(e.target.dataset.st){ try{ await FAOS.post('/api/production/'+e.target.dataset.st+'/start'); FAOS.toast('Started','ok'); load(); }catch(x){FAOS.toast(x.message,'err');} }
  if(e.target.dataset.cp){
    const q=prompt('Produced quantity:',e.target.dataset.q); if(q===null) return;
    try{ const r=await FAOS.post('/api/production/'+e.target.dataset.cp+'/complete',{produced_qty:parseFloat(q)});
      FAOS.toast('Done · unit cost '+FAOS.money(r.data.unit_cost),'ok'); load();
    }catch(x){ FAOS.toast(x.message,'err'); }
  }
});
document.getElementById('fStat').addEventListener('change',load);
load();
</script>
