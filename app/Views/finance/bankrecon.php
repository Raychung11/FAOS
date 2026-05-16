<?php $canManage = \App\Core\Auth::can('finance.manage'); ?>
<div class="grid cards">
  <div class="card stat pri"><div class="v" id="s-gross">—</div><div class="l">Terminal Gross</div></div>
  <div class="card stat ok"><div class="v" id="s-bank">—</div><div class="l">Bank Credited</div></div>
  <div class="card stat warn"><div class="v" id="s-fees">—</div><div class="l">Implied Fees</div></div>
  <div class="card stat"><div class="v" id="s-matched">—</div><div class="l">Matched / Batches</div></div>
  <div class="card stat dng"><div class="v" id="s-exc">—</div><div class="l">Exceptions</div></div>
</div>

<?php if ($canManage): ?>
<div class="grid col-2 mt">
  <?php foreach (['terminal' => 'Card-Terminal Settlement', 'bank' => 'Bank Statement'] as $src => $label): ?>
  <div class="card">
    <div class="hd"><?= e($label) ?> — CSV Upload</div>
    <div class="bd">
      <div class="row">
        <div><label>File (.csv)</label><input type="file" accept=".csv,.txt" id="<?= $src ?>-file"></div>
        <div style="max-width:90px"><label>Delim</label><input id="<?= $src ?>-delim" value="," maxlength="2"></div>
        <div style="max-width:120px"><label>Header row</label>
          <select id="<?= $src ?>-hdr"><option value="1">Yes</option><option value="0">No</option></select></div>
      </div>
      <div class="row">
        <div><label>Saved mapping</label><select id="<?= $src ?>-map"><option value="">— new mapping —</option></select></div>
        <div style="display:flex;align-items:flex-end"><button class="btn ghost" data-prev="<?= $src ?>">Preview &amp; Map</button></div>
      </div>
      <div id="<?= $src ?>-mapwrap" class="hidden mt">
        <table style="font-size:13px"><thead><tr><th>Field</th><th>CSV column</th></tr></thead>
          <tbody id="<?= $src ?>-fields"></tbody></table>
        <div class="row mt">
          <input id="<?= $src ?>-mapname" placeholder="Save mapping as… (optional)">
          <input id="<?= $src ?>-label" placeholder="<?= $src==='bank'?'Bank/account label':'Note (optional)' ?>">
          <button class="btn ok" data-import="<?= $src ?>" style="flex:0 0 auto">Import <?= ucfirst($src) ?></button>
        </div>
        <div id="<?= $src ?>-sample" class="mt muted" style="font-size:12px;overflow:auto"></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div class="row mt"><button class="btn lg" id="runBtn">▶ Run Reconciliation (batch + line match)</button></div>
<?php endif; ?>

<div class="card mt">
  <div class="hd"><span>Settlement Batches</span>
    <select id="fStat" style="max-width:170px">
      <option value="">All</option><option>matched</option><option>fee_variance</option>
      <option>short</option><option>over</option><option>unmatched</option><option>not_expected</option>
    </select>
  </div>
  <div class="bd" style="overflow:auto"><table>
    <thead><tr><th>Date</th><th>Channel</th><th class="right">Txns</th><th class="right">Gross</th>
      <th class="right">Expected Net</th><th class="right">Bank Credit</th><th class="right">Implied Fee</th>
      <th class="right">Variance</th><th>Status</th></tr></thead>
    <tbody id="bBody"><tr><td class="muted">Import files, then Run Reconciliation.</td></tr></tbody>
  </table></div>
</div>

<div class="grid col-2 mt">
  <div class="card"><div class="hd">Unmatched Bank Credits</div>
    <div class="bd" style="max-height:340px;overflow:auto"><table><thead><tr><th>Date</th><th>Description</th><th class="right">Credit</th></tr></thead>
    <tbody id="ubBody"><tr><td class="muted">—</td></tr></tbody></table></div></div>
  <div class="card"><div class="hd">Unmatched Terminal (transfer/deposit)</div>
    <div class="bd" style="max-height:340px;overflow:auto"><table><thead><tr><th>Date</th><th>Channel</th><th>Payer/Ref</th><th class="right">Amount</th></tr></thead>
    <tbody id="utBody"><tr><td class="muted">—</td></tr></tbody></table></div></div>
</div>

<script>
const CANW = <?= json_encode($canManage) ?>;
const FIELDS = {
  terminal: [['date','Date *'],['time','Time'],['ref','Reference'],['type','Payment type'],
    ['channel','Channel (if explicit)'],['card_last4','Card last 4'],['payer','Payer'],
    ['amount','Amount *'],['fee','Fee']],
  bank: [['date','Date *'],['description','Description'],['reference','Reference'],
    ['amount','Amount (signed)'],['debit','Debit'],['credit','Credit'],['balance','Balance']],
};
const tokens = {};

async function loadMappings(src) {
  try {
    const { data } = await FAOS.get('/api/recon/mappings?source=' + src);
    const sel = document.getElementById(src + '-map');
    sel.innerHTML = '<option value="">— new mapping —</option>' +
      data.map(m => `<option value="${m.id}">${FAOS.esc(m.name)}</option>`).join('');
  } catch (e) { /* finance.view still ok */ }
}

document.querySelectorAll('[data-prev]').forEach(b => b.addEventListener('click', async () => {
  const src = b.dataset.prev;
  const f = document.getElementById(src + '-file').files[0];
  if (!f) return FAOS.toast('Choose a CSV file', 'err');
  try {
    const r = await FAOS.upload('/api/recon/preview', f, {
      delimiter: document.getElementById(src + '-delim').value || ',',
      has_header: document.getElementById(src + '-hdr').value,
    });
    tokens[src] = r.data.token;
    const hdrs = r.data.headers;
    document.getElementById(src + '-fields').innerHTML = FIELDS[src].map(([k, lbl]) => `
      <tr><td>${lbl}</td><td><select data-f="${k}">
        <option value="">—</option>
        ${hdrs.map(h => `<option value="${FAOS.esc(h)}">${FAOS.esc(h)}</option>`).join('')}
      </select></td></tr>`).join('');
    autoGuess(src, hdrs);
    document.getElementById(src + '-sample').innerHTML =
      '<b>Sample:</b><br>' + r.data.rows.slice(0, 4).map(row => FAOS.esc(row.join(' | '))).join('<br>');
    document.getElementById(src + '-mapwrap').classList.remove('hidden');
  } catch (e) { FAOS.toast(e.message, 'err'); }
}));

function autoGuess(src, hdrs) {
  const guess = { date:/date|tarikh/i, time:/time/i, ref:/ref|trace|rrn|txn|invoice/i, type:/type|method|mode|channel|tender/i,
    amount:/amount|value|gross|jumlah/i, fee:/fee|mdr|charge|comm/i, credit:/credit|cr\b|in\b/i,
    debit:/debit|dr\b|out\b/i, description:/desc|narration|particular|detail/i,
    reference:/ref|cheque|trace/i, balance:/balance|baki/i, card_last4:/card|pan|last/i, payer:/payer|name|from/i };
  document.querySelectorAll(`#${src}-fields select`).forEach(sel => {
    const g = guess[sel.dataset.f]; if (!g) return;
    const hit = hdrs.find(h => g.test(h)); if (hit) sel.value = hit;
  });
}

document.querySelectorAll('[data-import]').forEach(b => b.addEventListener('click', async () => {
  const src = b.dataset.import;
  if (!tokens[src]) return FAOS.toast('Preview first', 'err');
  const columns = {};
  document.querySelectorAll(`#${src}-fields select`).forEach(s => { if (s.value) columns[s.dataset.f] = s.value; });
  if (!columns.date || (src === 'terminal' && !columns.amount)) return FAOS.toast('Map at least Date (and Amount for terminal)', 'err');
  const mapName = document.getElementById(src + '-mapname').value.trim();
  try {
    if (mapName) {
      await FAOS.upload('/api/recon/mappings', null, {
        source_type: src, name: mapName, columns,
        delimiter: document.getElementById(src + '-delim').value || ',',
        has_header: document.getElementById(src + '-hdr').value,
      });
    }
    const r = await FAOS.upload('/api/recon/import', null, {
      token: tokens[src], source_type: src, columns,
      delimiter: document.getElementById(src + '-delim').value || ',',
      has_header: document.getElementById(src + '-hdr').value,
      [src === 'bank' ? 'bank_label' : 'note']: document.getElementById(src + '-label').value,
    });
    FAOS.toast(src + ' imported: ' + r.data.rows + ' rows', 'ok');
    document.getElementById(src + '-mapwrap').classList.add('hidden');
    refresh(); loadMappings(src);
  } catch (e) { FAOS.toast(e.message, 'err'); }
}));

const runBtn = document.getElementById('runBtn');
if (runBtn) runBtn.addEventListener('click', async () => {
  try { const r = await FAOS.post('/api/recon/run');
    FAOS.toast(`Matched ${r.data.batch_matched} batch + ${r.data.line_matched} line`, 'ok'); refresh();
  } catch (e) { FAOS.toast(e.message, 'err'); }
});

const SB = { matched:'b-ok', fee_variance:'b-warn', short:'b-dng', over:'b-warn', unmatched:'b-dng', pending:'b-gray', not_expected:'b-gray' };
async function refresh() {
  try {
    const s = (await FAOS.get('/api/recon/summary')).data;
    document.getElementById('s-gross').textContent = FAOS.money(s.gross_total);
    document.getElementById('s-bank').textContent = FAOS.money(s.bank_credited);
    document.getElementById('s-fees').textContent = FAOS.money(s.implied_fees);
    document.getElementById('s-matched').textContent = s.matched + ' / ' + s.batches;
    document.getElementById('s-exc').textContent = (s.fee_variance + s.variance + s.unmatched_batches + s.unmatched_bank_lines + s.unmatched_terminal_lines);

    const st = document.getElementById('fStat').value;
    const bs = (await FAOS.get('/api/recon/batches' + (st ? '?status=' + st : ''))).data;
    document.getElementById('bBody').innerHTML = bs.length ? bs.map(r => `
      <tr><td>${FAOS.esc(r.batch_date)}</td><td>${FAOS.esc(r.channel)}</td>
      <td class="right">${r.txn_count}</td><td class="right">${FAOS.money(r.gross_total)}</td>
      <td class="right">${FAOS.money(r.expected_net)}</td><td class="right">${FAOS.money(r.bank_credit)}</td>
      <td class="right">${FAOS.money(r.implied_fee)}</td>
      <td class="right">${FAOS.money(r.variance)}</td>
      <td><span class="badge ${SB[r.status]||'b-gray'}">${FAOS.esc(r.status)}</span></td></tr>`).join('')
      : '<tr><td class="muted">No batches</td></tr>';

    const ex = (await FAOS.get('/api/recon/exceptions')).data;
    document.getElementById('ubBody').innerHTML = ex.unmatched_bank.length ? ex.unmatched_bank.map(b =>
      `<tr><td>${FAOS.esc(b.txn_date)}</td><td>${FAOS.esc(b.description||'')}</td><td class="right">${FAOS.money(b.credit)}</td></tr>`).join('')
      : '<tr><td class="muted">All bank credits matched</td></tr>';
    document.getElementById('utBody').innerHTML = ex.unmatched_terminal.length ? ex.unmatched_terminal.map(t =>
      `<tr><td>${FAOS.esc(t.txn_date)}</td><td>${FAOS.esc(t.channel)}</td><td>${FAOS.esc(t.payer||t.txn_ref||'')}</td><td class="right">${FAOS.money(t.amount)}</td></tr>`).join('')
      : '<tr><td class="muted">All terminal lines matched</td></tr>';
  } catch (e) { FAOS.toast(e.message, 'err'); }
}
document.getElementById('fStat').addEventListener('change', refresh);
['terminal','bank'].forEach(loadMappings);
refresh();
</script>
