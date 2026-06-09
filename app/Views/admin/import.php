<div class="grid col-2">
  <?php if ($canProducts): ?>
  <div class="card">
    <div class="hd">Product Catalogue</div>
    <div class="bd">
      <p class="muted" style="font-size:13px">Upsert by <b>SKU</b> — new SKUs are created, existing ones updated. Unknown category/tax codes are skipped with a warning (not fatal).</p>
      <a class="btn ghost" href="/api/import/products/template">⬇ Download template</a>
      <label class="mt">CSV file</label>
      <input type="file" id="pFile" accept=".csv,.txt">
      <button class="btn ok mt" id="pBtn">Import Products</button>
      <div id="pOut" class="mt"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($canStock): ?>
  <div class="card">
    <div class="hd">Opening Stock Balances</div>
    <div class="bd">
      <p class="muted" style="font-size:13px">Sets the <b>absolute</b> on-hand per product per location (by location <b>code</b>). Re-running is safe — unchanged rows are skipped. Optional <code>unit_cost</code> seeds moving-average cost.</p>
      <a class="btn ghost" href="/api/import/stock/template">⬇ Download template</a>
      <label class="mt">CSV file</label>
      <input type="file" id="sFile" accept=".csv,.txt">
      <button class="btn ok mt" id="sBtn">Import Opening Stock</button>
      <div id="sOut" class="mt"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($canPO)): ?>
  <div class="card">
    <div class="hd">Supplier Purchase Order</div>
    <div class="bd">
      <p class="muted" style="font-size:13px">Upload a supplier PO's lines → creates a <b>draft PO</b> (then approve → GRN → auto AP invoice). Matches product by SKU then barcode.</p>
      <a class="btn ghost" href="/api/import/po/template">⬇ Download template</a>
      <div class="row mt">
        <div><label>Supplier</label><select id="poSup">
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['code']) ?> · <?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Receiving warehouse</label><select id="poWh">
          <option value="">— none —</option>
          <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Expected date</label><input id="poDate" type="date"></div>
      </div>
      <label style="display:flex;gap:8px;align-items:center;color:var(--ink);margin-top:10px">
        <input type="checkbox" id="poCreate" checked style="width:auto"> Auto-create products that don't exist yet (initial onboarding)
      </label>
      <label class="mt">CSV file (barcode,sku,description,qty,uom,unit_cost)</label>
      <input type="file" id="poFile" accept=".csv,.txt">
      <button class="btn ok mt" id="poBtn">Create PO from CSV</button>
      <div id="poOut" class="mt"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
function renderResult(el, data) {
  const errs = (data.errors || []);
  const summary = Object.entries(data).filter(([k]) => k !== 'errors')
    .map(([k, v]) => `<span class="badge b-info" style="margin:2px">${FAOS.esc(k)}: ${FAOS.esc(v)}</span>`).join(' ');
  let body = summary;
  if (errs.length) {
    body += '<table class="mt"><thead><tr><th>Row</th><th>Issue</th></tr></thead><tbody>' +
      errs.map(e => `<tr><td>${e.row}</td><td class="${e.error?'':'muted'}">${FAOS.esc(e.error || e.warning)}</td></tr>`).join('') +
      '</tbody></table>';
  } else {
    body += '<p class="muted mt" style="font-size:13px">No issues.</p>';
  }
  el.innerHTML = body;
}

function wire(fileId, btnId, outId, url) {
  const btn = document.getElementById(btnId);
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const f = document.getElementById(fileId).files[0];
    if (!f) return FAOS.toast('Choose a CSV file', 'err');
    btn.disabled = true;
    try {
      const r = await FAOS.upload(url, f, {});
      FAOS.toast(r.message, 'ok');
      renderResult(document.getElementById(outId), r.data);
    } catch (e) {
      FAOS.toast(e.message, 'err');
    } finally { btn.disabled = false; }
  });
}
wire('pFile', 'pBtn', 'pOut', '/api/import/products');
wire('sFile', 'sBtn', 'sOut', '/api/import/stock');

const poBtn = document.getElementById('poBtn');
if (poBtn) poBtn.addEventListener('click', async () => {
  const f = document.getElementById('poFile').files[0];
  if (!f) return FAOS.toast('Choose a CSV file', 'err');
  poBtn.disabled = true;
  try {
    const r = await FAOS.upload('/api/import/po', f, {
      supplier_id: document.getElementById('poSup').value,
      warehouse_id: document.getElementById('poWh').value,
      expected_date: document.getElementById('poDate').value,
      create_missing: document.getElementById('poCreate').checked ? '1' : '0',
    });
    FAOS.toast(r.message, 'ok');
    renderResult(document.getElementById('poOut'), r.data);
  } catch (e) {
    FAOS.toast(e.message, 'err');
    if (e.payload && e.payload.errors) renderResult(document.getElementById('poOut'), { errors: e.payload.errors });
  } finally { poBtn.disabled = false; }
});
</script>
