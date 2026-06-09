<?php $cm = $canManage; ?>
<div class="card mb"><div class="bd" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
  <strong>LHDN MyInvois</strong>
  <span id="provBadge" class="badge b-gray">checking…</span>
  <div style="display:flex;gap:6px;margin-left:auto">
    <button class="btn ghost tab" data-tab="docs">e-Invoices</button>
    <button class="btn ghost tab" data-tab="sst">SST Summary</button>
  </div>
</div></div>

<section id="docs">
  <?php if ($cm): ?>
  <div class="grid col-2 mb">
    <div class="card"><div class="hd">Consolidated e-Invoice (B2C monthly)</div><div class="bd">
      <p class="muted" style="font-size:13px">Rolls up all receipts not individually e-invoiced for an outlet/period — the normal kiosk &amp; restaurant workflow.</p>
      <div class="row">
        <div><label>Outlet</label><select id="cOutlet">
          <?php foreach ($outlets as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Period start</label><input id="cFrom" type="date"></div>
        <div><label>Period end</label><input id="cTo" type="date"></div>
        <div style="display:flex;align-items:flex-end"><button class="btn ok" id="genCons">Generate</button></div>
      </div>
    </div></div>
    <div class="card"><div class="hd">Standard e-Invoice (per sale)</div><div class="bd">
      <p class="muted" style="font-size:13px">For a B2B buyer or when a customer requests an individual e-invoice.</p>
      <div class="row">
        <div><label>Sales transaction ID</label><input id="txnId" type="number" min="1"></div>
        <div><label>Buyer name (optional)</label><input id="bName" placeholder="General Public"></div>
        <div><label>Buyer TIN (optional)</label><input id="bTin"></div>
        <div style="display:flex;align-items:flex-end"><button class="btn ok" id="genTxn">Generate</button></div>
      </div>
    </div></div>
  </div>
  <?php endif; ?>

  <div class="card"><div class="hd"><span>e-Invoices</span>
    <select id="fStat" style="max-width:170px"><option value="">All</option><option>valid</option><option>pending_submission</option><option>submitted</option><option>rejected</option><option>draft</option></select>
  </div>
  <div class="bd" style="overflow:auto"><table>
    <thead><tr><th>No</th><th>Type</th><th>Buyer</th><th>Period/Txn</th><th class="right">Total</th><th>Status</th><th></th></tr></thead>
    <tbody id="eBody"><tr><td class="muted">Loading…</td></tr></tbody></table>
  </div></div>
</section>

<section id="sst" class="hidden">
  <div class="card mb"><div class="bd row">
    <div><label>From</label><input id="sFrom" type="date"></div>
    <div><label>To</label><input id="sTo" type="date"></div>
    <div style="display:flex;align-items:flex-end"><button class="btn" id="sRun">Compute</button></div>
  </div></div>
  <div class="card"><div class="hd"><span>SST Output Tax (SST-02 prep)</span><span id="sTot" class="badge b-info"></span></div>
    <div class="bd" style="overflow:auto"><table>
      <thead><tr><th>Tax code</th><th>Name</th><th class="right">Rate %</th><th class="right">Taxable sales</th><th class="right">Output tax</th></tr></thead>
      <tbody id="sBody"><tr><td class="muted">Compute a period…</td></tr></tbody></table>
    </div></div>
</section>

<script>
const CM=<?= json_encode($cm) ?>;
const SB={valid:'b-ok',submitted:'b-info',pending_submission:'b-warn',rejected:'b-dng',draft:'b-gray',cancelled:'b-gray'};
function tab(n){ ['docs','sst'].forEach(s=>document.getElementById(s).classList.toggle('hidden',s!==n)); if(n==='sst') sst(); }
document.querySelectorAll('.tab').forEach(b=>b.addEventListener('click',()=>tab(b.dataset.tab)));

async function load(){
  const st=document.getElementById('fStat').value;
  try{
    const r=await FAOS.get('/api/einvoice'+(st?'?status='+st:''));
    document.getElementById('provBadge').textContent = r.data.provider_on
      ? 'MyInvois: CONNECTED' : 'MyInvois: not configured (documents generated, pending submission)';
    document.getElementById('provBadge').className = 'badge '+(r.data.provider_on?'b-ok':'b-warn');
    document.getElementById('eBody').innerHTML = r.data.rows.length ? r.data.rows.map(e=>`
      <tr><td>${FAOS.esc(e.einvoice_no)}</td><td>${FAOS.esc(e.doc_type)}</td><td>${FAOS.esc(e.buyer_name)}</td>
      <td>${e.period_start?FAOS.esc(e.period_start+'→'+e.period_end):('txn '+(e.transaction_id||'—'))}</td>
      <td class="right">${FAOS.money(e.total)}</td>
      <td><span class="badge ${SB[e.status]||'b-gray'}">${FAOS.esc(e.status)}</span></td>
      <td class="right">
        <a class="btn ghost" style="padding:5px 9px" href="/api/einvoice/${e.id}/print" target="_blank">Print</a>
        ${CM&&(e.status==='pending_submission'||e.status==='rejected')?`<button class="btn ok" data-sub="${e.id}" style="padding:5px 9px">Submit</button>`:''}
      </td></tr>`).join('') : '<tr><td class="muted">No e-invoices yet</td></tr>';
  }catch(e){ FAOS.toast(e.message,'err'); }
}
document.getElementById('fStat').addEventListener('change',load);
document.getElementById('eBody').addEventListener('click',async e=>{
  if(e.target.dataset.sub){ try{ const r=await FAOS.post('/api/einvoice/'+e.target.dataset.sub+'/submit');
    FAOS.toast('Status: '+r.data.status,'ok'); load(); }catch(x){FAOS.toast(x.message,'err');} }
});

const gc=document.getElementById('genCons');
if(gc) gc.addEventListener('click',async()=>{
  try{ const r=await FAOS.post('/api/einvoice/consolidated',{outlet_id:+document.getElementById('cOutlet').value,
    period_start:document.getElementById('cFrom').value, period_end:document.getElementById('cTo').value});
    FAOS.toast('Consolidated '+r.data.einvoice_no+' ('+r.data.status+')','ok'); load();
  }catch(e){ FAOS.toast(e.message,'err'); }
});
const gt=document.getElementById('genTxn');
if(gt) gt.addEventListener('click',async()=>{
  const id=+document.getElementById('txnId').value; if(!id) return FAOS.toast('Enter a transaction ID','err');
  try{ const r=await FAOS.post('/api/einvoice/transaction/'+id,{buyer_name:document.getElementById('bName').value||null,
    buyer_tin:document.getElementById('bTin').value||null});
    FAOS.toast('e-Invoice '+r.data.einvoice_no+' ('+r.data.status+')','ok'); load();
  }catch(e){ FAOS.toast(e.message,'err'); }
});

async function sst(){
  const p=new URLSearchParams();
  if(document.getElementById('sFrom').value) p.set('from',document.getElementById('sFrom').value);
  if(document.getElementById('sTo').value) p.set('to',document.getElementById('sTo').value);
  try{
    const {data}=await FAOS.get('/api/finance/sst-summary?'+p);
    document.getElementById('sTot').textContent='Output tax '+FAOS.money(data.total_output_tax);
    document.getElementById('sBody').innerHTML=data.rows.length?data.rows.map(x=>`
      <tr><td>${FAOS.esc(x.tax_code)}</td><td>${FAOS.esc(x.tax_name)}</td><td class="right">${Number(x.rate)}</td>
      <td class="right">${FAOS.money(x.taxable_sales)}</td><td class="right">${FAOS.money(x.output_tax)}</td></tr>`).join('')
      :'<tr><td class="muted">No taxable sales in period</td></tr>';
  }catch(e){ FAOS.toast(e.message,'err'); }
}
document.getElementById('sRun').addEventListener('click',sst);
load();
</script>
