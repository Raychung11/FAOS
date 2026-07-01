<?php
/** AdvisorOS — Business Valuation (NAV / EBITDA / DCF, risk-adjusted). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/valuation.php';
require_once __DIR__ . '/../includes/valuation_kb.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Valuation”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    $action = (string) input('action', 'save');

    // Client-level: regenerate AI valuation commentary.
    if ($action === 'ai_commentary') {
        $res = valuation_ai_commentary_generate($pdo, $tid, $clientId, $client);
        setting_put_json('valuation_ai:' . $clientId, [
            'text'    => $res['text'],
            'model'   => $res['model'],
            'tokens'  => $res['tokens'],
            'stubbed' => $res['stubbed'],
            'updated_at' => date('Y-m-d H:i'),
            'updated_by' => current_user()['name'] ?? '',
        ]);
        meter_report('valuation');
        audit_log('ai_generate', 'valuation', $clientId,
            'AI valuation commentary' . ($res['stubbed'] ? ' (draft)' : ' (' . $res['model'] . ')'));
        set_flash($res['stubbed'] ? 'warning' : 'success',
            $res['stubbed']
                ? 'Data-driven commentary drafted (no AI provider configured).'
                : 'AI commentary generated — review before sharing.');
        redirect('advisor/valuation.php?client_id=' . $clientId);
    }
    if ($action === 'ai_clear') {
        setting_put_json('valuation_ai:' . $clientId, []);
        audit_log('delete', 'valuation', $clientId, 'AI valuation commentary cleared');
        set_flash('success', 'AI commentary cleared.');
        redirect('advisor/valuation.php?client_id=' . $clientId);
    }

    $cid = (int) input('company_id', 0);
    $co  = company_with_client($cid);
    if (!$co || (int) $co['owner_client_id'] !== $clientId) {
        http_response_code(403); exit('Invalid company.');
    }
    if ($action === 'save_snapshot') {
        $bf = bf_latest($cid);
        $a  = valuation_assumptions($cid);
        $worst = (risk_diagnostic($pdo, $tid, $clientId)['worst'] ?? null);
        $val = company_valuation($co, $bf, $a, $worst);
        if ($val['has_data']) {
            valuation_snapshot_save($cid, $val, $a);
            audit_log('create', 'valuation', $cid, 'Valuation snapshot saved');
            set_flash('success', 'Snapshot saved.');
        } else {
            set_flash('warning', 'No financial snapshot to value — capture one first.');
        }
        redirect('advisor/valuation.php?client_id=' . $clientId);
    }
    if ($action === 'apply_industry') {
        $hint = valuation_industry_lookup((string) ($co['industry'] ?? ''));
        if ($hint) {
            $cur = valuation_assumptions($cid);
            $cur['multiple'] = (float) $hint['ebitda_mid'];
            valuation_assumptions_save($cid, $cur);
            audit_log('update', 'valuation', $cid,
                'Applied industry multiple ' . $hint['ebitda_mid'] . 'x (' . $hint['label'] . ')');
            set_flash('success', 'Applied industry multiple: ' . $hint['ebitda_mid'] . '× (' . $hint['label'] . ').');
        } else {
            set_flash('warning', 'No industry match — set the multiple manually.');
        }
        redirect('advisor/valuation.php?client_id=' . $clientId);
    }

    // Parse the repeatable adjustments rows (label[] / amount[]).
    $labels  = (array) input('adj_label', []);
    $amounts = (array) input('adj_amount', []);
    $adjustments = [];
    foreach ($labels as $i => $lab) {
        $amt = (float) ($amounts[$i] ?? 0);
        $lab = trim((string) $lab);
        if ($lab === '' && abs($amt) < 0.01) { continue; }
        $adjustments[] = ['label' => $lab !== '' ? $lab : 'Adjustment', 'amount' => $amt];
    }

    valuation_assumptions_save($cid, [
        'multiple'      => input('multiple', 4),
        'discount'      => input('discount', 18),
        'growth'        => input('growth', 3),
        'weight_nav'    => input('weight_nav', 20),
        'weight_ebitda' => input('weight_ebitda', 50),
        'weight_dcf'    => input('weight_dcf', 30),
        'dlom_pct'      => input('dlom_pct', 25),
        'minority_pct'  => input('minority_pct', 0),
        'dcf_method'    => input('dcf_method', 'gordon'),
        'mdcf_years'    => input('mdcf_years', 5),
        'mdcf_revenue'  => input('mdcf_revenue', 0),
        'mdcf_growth'   => input('mdcf_growth', 5),
        'mdcf_margin'   => input('mdcf_margin', 15),
        'mdcf_tax_rate' => input('mdcf_tax_rate', 24),
        'mdcf_capex'    => input('mdcf_capex', 3),
        'mdcf_wc'       => input('mdcf_wc', 1),
        'mdcf_terminal_growth' => input('mdcf_terminal_growth', 3),
        'adjustments'   => $adjustments,
    ]);
    meter_report('valuation');
    audit_log('update', 'valuation', $cid, 'Valuation assumptions updated');
    set_flash('success', 'Valuation updated.');
    redirect('advisor/valuation.php?client_id=' . $clientId);
}

/**
 * Build the AI valuation commentary. The engine supplies the verified
 * figures (NAV, normalised EBITDA, multi-method blend, discount stack)
 * — the AI writes the narrative using the firm's Valuation Knowledge
 * Base. Falls back to a data-driven draft offline.
 */
function valuation_ai_commentary_generate(PDO $pdo, ?int $tid, int $clientId, array $client): array
{
    $cv = client_valuation($pdo, $tid, $clientId);
    $kp = (int) round(valuation_risk_discount($cv['worst_risk']) * 100);

    $facts  = "Client: {$client['full_name']} (Malaysia).\n";
    $facts .= 'Indicative equity value (all companies, weighted &amp; risk-adjusted): RM '
        . money($cv['total_mid']) . ".\n";
    $facts .= 'Client attributable stake (by ownership %): RM ' . money($cv['total_stake']) . ".\n";
    $facts .= "Key-person discount applied: {$kp}%.\n";
    $facts .= 'Companies valued: ' . count($cv['rows']) . ".\n\n";
    foreach ($cv['rows'] as $i => $row) {
        $c = $row['company']; $v = $row['val'];
        $facts .= ($i + 1) . '. ' . $c['name']
            . ' (ownership ' . rtrim(rtrim(number_format((float) $c['ownership_pct'], 2), '0'), '.') . '%)';
        if (!$v['has_data']) { $facts .= " — no financial snapshot.\n"; continue; }
        $facts .= "\n   NAV RM " . money($v['nav'])
            . '; raw EBITDA RM ' . money($v['raw_ebitda'])
            . '; normalised EBITDA RM ' . money($v['normalised_ebitda'])
            . ' (adjustments RM ' . money($v['adjustments_total']) . ').'
            . "\n   EBITDA-multiple equity RM " . money($v['ebitda_equity'])
            . ' (EV RM ' . money($v['ebitda_ev']) . ' − net debt RM ' . money($v['net_debt']) . ').'
            . "\n   DCF equity RM " . money($v['dcf_equity']) . ' (' . $v['dcf_method'] . ').'
            . "\n   Weighted pre-discount RM " . money($v['weighted_pre_discount'])
            . '; discount stack ' . (int) round($v['discount_keyperson'] * 100) . '% × '
            . (int) round($v['discount_dlom'] * 100) . '% × '
            . (int) round($v['discount_minority'] * 100) . '% = '
            . (int) round($v['discount'] * 100) . '%.'
            . "\n   Indicative mid RM " . money($v['mid'])
            . ' (range RM ' . money($v['low']) . ' – RM ' . money($v['high']) . ').'
            . "\n   Client stake RM " . money($v['client_stake']) . ".\n";
        if (!empty($v['adjustments'])) {
            foreach ($v['adjustments'] as $adj) {
                $facts .= '   • adjustment: ' . ($adj['label'] ?? 'Adjustment')
                    . ' RM ' . money((float) ($adj['amount'] ?? 0)) . "\n";
            }
        }
    }

    $kb = val_kb_prompt();
    $system = 'You are a Malaysian business valuation specialist preparing adviser-grade '
        . 'commentary for a private-company indicative valuation. CRITICAL: use ONLY '
        . 'the figures provided below — NEVER recompute, invent, "correct" or restate '
        . 'any NAV, EBITDA, multiple, DCF, discount or weighted-mid figure. All '
        . 'ringgit amounts come from the verified engine. Frame as adviser-grade '
        . 'narrative for the licensed adviser to review; flag assumptions to confirm. '
        . 'Output 5 sections with these EXACT headers (one per line), each 2-4 '
        . 'concise sentences that ADD insight without restating numbers:'
        . "\n## SECTION: methodology"
        . "\n## SECTION: ebitda"
        . "\n## SECTION: dcf"
        . "\n## SECTION: discounts"
        . "\n## SECTION: caveats"
        . ($kb !== '' ? "\n\nApply the firm's valuation methodology and house style:\n" . $kb : '');

    $user = 'Write the valuation commentary using these verified figures as context '
        . "(do not restate them as a table):\n\n" . $facts;

    $stub = "## SECTION: methodology\nThe blend across NAV, EBITDA-multiple and DCF "
        . "balances asset backing, earnings power and forward cash generation; weights "
        . "are calibrated to the maturity of the business and the reliability of the "
        . "earnings stream.\n\n"
        . "## SECTION: ebitda\nThe EBITDA-multiple approach anchors on a comparable-"
        . "transaction range. Normalising for owner remuneration, related-party items "
        . "and non-recurring entries gives a cleaner earnings base; confirm each "
        . "add-back is sustainable.\n\n"
        . "## SECTION: dcf\nThe capitalised / multi-year DCF reflects forward cash "
        . "generation. Sensitivity to the discount rate and terminal growth is high "
        . "— small assumption changes move the figure meaningfully.\n\n"
        . "## SECTION: discounts\nThe discount stack (key-person × DLOM × minority) "
        . "is applied sequentially. Calibrate against the worst-band risk score and "
        . "the marketability profile of an owner-managed private company.\n\n"
        . "## SECTION: caveats\nThis is an indicative estimate for advisory discussion "
        . "— not a formal/independent valuation, audit or fairness opinion. Engage a "
        . "licensed valuer for any binding purpose.";

    return ai_complete($system, $user, 'valuation_commentary', $stub);
}

$v = client_valuation($pdo, $tid, $clientId);

$pageTitle = 'Valuation — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Business Valuation</h2>
    <span class="muted"><?= e($client['full_name']) ?> · NAV · EBITDA multiple · capitalised DCF</span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn-os sm" href="<?= e(url('advisor/valuation-pdf.php?client_id='.$clientId)) ?>">Download report PDF</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<?php if (!$v['rows']): ?>
  <div class="alert-os warning">No companies recorded. Add one under
    <a href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Business profile</a>
    (with a financial snapshot), then return here.</div>
<?php else: ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Indicative Equity Value</div>
    <div class="stat-value">RM <?= money($v['total_mid']) ?></div>
    <div class="stat-foot">all companies, blended &amp; risk-adjusted</div></div>
  <div class="stat accent"><div class="stat-label">Client's Attributable Stake</div>
    <div class="stat-value">RM <?= money($v['total_stake']) ?></div>
    <div class="stat-foot">by ownership %</div></div>
  <div class="stat accent"><div class="stat-label">Key-Person Discount</div>
    <div class="stat-value"><?= (int) round(valuation_risk_discount($v['worst_risk']) * 100) ?>%</div>
    <div class="stat-foot">from the risk diagnostic</div></div>
</div>

<?php foreach ($v['rows'] as $row): $c = $row['company']; $val = $row['val'];
      $a = valuation_assumptions((int) $c['id']); ?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= e($c['name']) ?>
    <span class="muted" style="font-weight:400">· client owns <?= rtrim(rtrim(number_format((float)$c['ownership_pct'],2),'0'),'.') ?>%</span>
  </div>
  <div class="card-os-body">
    <?php if (!$val['has_data']): ?>
      <div class="alert-os warning">No business financial snapshot — add one in
        <a href="<?= e(url('advisor/company-view.php?id='.(int)$c['id'])) ?>">the company</a> to value it.</div>
    <?php else: ?>
      <div class="grid cols-4" style="margin-bottom:14px">
        <div class="stat"><div class="stat-label">Net asset value</div>
          <div class="stat-value" style="font-size:20px">RM <?= money($val['nav']) ?></div>
          <div class="stat-foot">weight <?= (int) round($val['weights']['nav'] * 100) ?>%</div></div>
        <div class="stat"><div class="stat-label">EBITDA-multiple equity</div>
          <div class="stat-value" style="font-size:20px">RM <?= money($val['ebitda_equity']) ?></div>
          <div class="stat-foot">EV RM <?= money($val['ebitda_ev']) ?> − net debt · weight <?= (int) round($val['weights']['ebitda'] * 100) ?>%</div></div>
        <div class="stat"><div class="stat-label">Capitalised DCF equity</div>
          <div class="stat-value" style="font-size:20px">RM <?= money($val['dcf_equity']) ?></div>
          <div class="stat-foot">weight <?= (int) round($val['weights']['dcf'] * 100) ?>%</div></div>
        <div class="stat accent"><div class="stat-label">Indicative range</div>
          <div class="stat-value" style="font-size:18px">RM <?= money($val['low']) ?> – <?= money($val['high']) ?></div>
          <div class="stat-foot">weighted mid RM <?= money($val['mid']) ?> · stake RM <?= money($val['client_stake']) ?></div></div>
      </div>

      <?php $hint = valuation_industry_lookup((string) ($c['industry'] ?? '')); ?>
      <?php if ($hint): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;
             background:#f7f9fc;border-left:3px solid var(--gold);
             padding:8px 14px;margin-bottom:12px;font-size:13px;border-radius:6px">
          <span><strong><?= e($hint['label']) ?></strong> typically values at
            <strong><?= e($hint['ebitda_mid']) ?>×</strong> EBITDA
            (range <?= e($hint['ebitda_low']) ?>–<?= e($hint['ebitda_high']) ?>×) — illustrative.</span>
          <?php if (abs((float) $hint['ebitda_mid'] - (float) $a['multiple']) > 0.01): ?>
            <form method="post" style="margin:0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="apply_industry">
              <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
              <button class="btn-os ghost sm">Apply <?= e($hint['ebitda_mid']) ?>×</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" id="val-form-<?= (int) $c['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">

        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
          <div class="form-row" style="margin:0"><label>EBITDA multiple (×)</label>
            <input type="number" step="0.1" name="multiple" value="<?= e($a['multiple']) ?>" style="max-width:110px"></div>
          <div class="form-row" style="margin:0"><label>Discount rate (%)</label>
            <input type="number" step="0.1" name="discount" value="<?= e($a['discount']) ?>" style="max-width:110px"></div>
          <div class="form-row" style="margin:0"><label>Growth rate (%)</label>
            <input type="number" step="0.1" name="growth" value="<?= e($a['growth']) ?>" style="max-width:110px"></div>
          <div class="form-row" style="margin:0"><label>Weight: NAV (%)</label>
            <input type="number" step="1" name="weight_nav" value="<?= e($a['weight_nav']) ?>" style="max-width:90px"></div>
          <div class="form-row" style="margin:0"><label>Weight: EBITDA (%)</label>
            <input type="number" step="1" name="weight_ebitda" value="<?= e($a['weight_ebitda']) ?>" style="max-width:90px"></div>
          <div class="form-row" style="margin:0"><label>Weight: DCF (%)</label>
            <input type="number" step="1" name="weight_dcf" value="<?= e($a['weight_dcf']) ?>" style="max-width:90px"></div>
        </div>

        <div style="margin-top:14px;padding-top:10px;border-top:1px dashed #d4d9e0">
          <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">
            Sequential discount stack — applied after key-person haircut
          </div>
          <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-row" style="margin:0"><label>DLOM (marketability) %</label>
              <input type="number" step="1" name="dlom_pct" value="<?= e($a['dlom_pct']) ?>" style="max-width:120px">
              <small class="muted">Discount for lack of marketability</small></div>
            <div class="form-row" style="margin:0"><label>Minority discount %</label>
              <input type="number" step="1" name="minority_pct" value="<?= e($a['minority_pct']) ?>" style="max-width:120px">
              <small class="muted">0% for controlling stake</small></div>
            <div class="muted" style="font-size:12px;padding-bottom:6px">
              Total = 1 − (1 − key-person) × (1 − DLOM) × (1 − minority)
            </div>
          </div>
        </div>

        <div style="margin-top:14px;padding-top:10px;border-top:1px dashed #d4d9e0">
          <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">
            Normalised EBITDA — add-backs &amp; non-recurring adjustments
          </div>
          <div class="muted" style="font-size:12px;margin-bottom:8px">
            Raw EBITDA RM <?= money($val['raw_ebitda']) ?>
            <?php if (abs((float) $val['adjustments_total']) > 0.01): ?>
              · Normalised EBITDA RM <?= money($val['normalised_ebitda']) ?>
              (<?= ($val['adjustments_total'] >= 0 ? '+' : '') ?>RM <?= money($val['adjustments_total']) ?>)
            <?php endif; ?>
            · used in EBITDA-multiple &amp; DCF base.
          </div>
          <div id="adj-rows-<?= (int) $c['id'] ?>">
            <?php
              $adjList = $a['adjustments'] ?: [['label' => '', 'amount' => 0]];
              foreach ($adjList as $adj):
            ?>
              <div style="display:flex;gap:8px;margin-bottom:6px;align-items:center">
                <input name="adj_label[]" placeholder="e.g. Owner remuneration above market"
                       maxlength="120" value="<?= e($adj['label'] ?? '') ?>"
                       style="flex:1;max-width:420px">
                <input type="number" step="100" name="adj_amount[]"
                       placeholder="amount (RM)" value="<?= e((float) ($adj['amount'] ?? 0)) ?>"
                       style="max-width:140px">
                <button type="button" class="btn-os ghost sm"
                        onclick="this.parentNode.remove()">×</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn-os ghost sm"
                  onclick="(function(b){var w=document.createElement('div');w.style.cssText='display:flex;gap:8px;margin-bottom:6px;align-items:center';w.innerHTML='<input name=&quot;adj_label[]&quot; placeholder=&quot;label&quot; maxlength=&quot;120&quot; style=&quot;flex:1;max-width:420px&quot;><input type=&quot;number&quot; step=&quot;100&quot; name=&quot;adj_amount[]&quot; placeholder=&quot;amount (RM)&quot; style=&quot;max-width:140px&quot;><button type=&quot;button&quot; class=&quot;btn-os ghost sm&quot; onclick=&quot;this.parentNode.remove()&quot;>&times;</button>';document.getElementById('adj-rows-<?= (int) $c['id'] ?>').appendChild(w);})(this)">
            + Add adjustment
          </button>
          <div class="muted" style="font-size:11px;margin-top:4px">
            Sign convention: positive amount adds back to EBITDA (e.g. owner pay above market);
            negative amount removes a non-recurring boost.
          </div>
        </div>

        <div style="margin-top:14px;padding-top:10px;border-top:1px dashed #d4d9e0">
          <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">
            DCF method
          </div>
          <label style="display:inline-flex;gap:6px;align-items:center;margin-right:18px">
            <input type="radio" name="dcf_method" value="gordon"
                   <?= ($a['dcf_method'] === 'gordon') ? 'checked' : '' ?>>
            <span>Capitalised (Gordon growth)</span></label>
          <label style="display:inline-flex;gap:6px;align-items:center">
            <input type="radio" name="dcf_method" value="multiyear"
                   <?= ($a['dcf_method'] === 'multiyear') ? 'checked' : '' ?>>
            <span>Multi-year explicit DCF</span></label>

          <details <?= ($a['dcf_method'] === 'multiyear') ? 'open' : '' ?> style="margin-top:10px">
            <summary class="muted" style="cursor:pointer;font-size:12px">
              Multi-year DCF inputs (5-year explicit + Gordon-growth terminal)</summary>
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
              <div class="form-row" style="margin:0"><label>Years</label>
                <input type="number" step="1" name="mdcf_years" value="<?= e($a['mdcf_years']) ?>" style="max-width:80px"></div>
              <div class="form-row" style="margin:0"><label>Start revenue (RM)</label>
                <input type="number" step="1000" name="mdcf_revenue" value="<?= e($a['mdcf_revenue']) ?>" style="max-width:160px">
                <small class="muted">0 → derive from EBITDA / margin</small></div>
              <div class="form-row" style="margin:0"><label>Revenue growth %</label>
                <input type="number" step="0.1" name="mdcf_growth" value="<?= e($a['mdcf_growth']) ?>" style="max-width:110px"></div>
              <div class="form-row" style="margin:0"><label>EBITDA margin %</label>
                <input type="number" step="0.1" name="mdcf_margin" value="<?= e($a['mdcf_margin']) ?>" style="max-width:110px"></div>
              <div class="form-row" style="margin:0"><label>Tax rate %</label>
                <input type="number" step="0.1" name="mdcf_tax_rate" value="<?= e($a['mdcf_tax_rate']) ?>" style="max-width:110px"></div>
              <div class="form-row" style="margin:0"><label>Capex % of revenue</label>
                <input type="number" step="0.1" name="mdcf_capex" value="<?= e($a['mdcf_capex']) ?>" style="max-width:110px"></div>
              <div class="form-row" style="margin:0"><label>Δ working capital % of Δrev</label>
                <input type="number" step="0.1" name="mdcf_wc" value="<?= e($a['mdcf_wc']) ?>" style="max-width:140px"></div>
              <div class="form-row" style="margin:0"><label>Terminal growth %</label>
                <input type="number" step="0.1" name="mdcf_terminal_growth" value="<?= e($a['mdcf_terminal_growth']) ?>" style="max-width:120px"></div>
            </div>
          </details>
        </div>

        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          <button class="btn-os">Recalculate</button>
          <span class="muted" style="font-size:12px">
            Active method: <strong><?= e($val['dcf_method'] === 'multiyear' ? 'Multi-year DCF' : 'Capitalised DCF') ?></strong>
            · Combined discount <strong><?= (int) round($val['discount'] * 100) ?>%</strong>
            (<?= (int) round($val['discount_keyperson'] * 100) ?>% × <?= (int) round($val['discount_dlom'] * 100) ?>% × <?= (int) round($val['discount_minority'] * 100) ?>%)
          </span>
        </div>
      </form>

      <?php
        $bf = bf_latest((int) $c['id']);
        $sens = valuation_sensitivity($c, $bf, $a, $v['worst_risk']);
      ?>
      <div style="margin-top:18px">
        <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:6px">
          Sensitivity — indicative mid (RM) by multiple × discount
        </div>
        <table class="table-os" style="font-size:12px">
          <thead><tr><th style="width:90px">Multiple ↓ / Discount →</th>
            <?php foreach ($sens['discounts'] as $d): ?>
              <th style="text-align:right"><?= (float) $d ?>%</th>
            <?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($sens['multipliers'] as $i => $m): ?>
            <tr><td><strong><?= (float) $m ?>×</strong></td>
              <?php foreach ($sens['grid'][$i] as $j => $cell):
                $base = (abs((float) $m - $sens['base_multiple']) < 0.01
                      && abs((float) $sens['discounts'][$j] - $sens['base_discount']) < 0.01); ?>
                <td style="text-align:right;<?= $base ? 'background:var(--gold);color:#1a1405;font-weight:700' : '' ?>">RM <?= money($cell) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="muted" style="font-size:11px;margin-top:4px">Highlighted cell = current assumptions. Range: multiple ±2, discount ±10pp from base.</div>
      </div>

      <?php $hist = valuation_snapshot_history((int) $c['id'], 6); ?>
      <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <strong style="color:var(--navy);font-size:13px">Snapshots</strong>
        <form method="post" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_snapshot">
          <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
          <button class="btn-os ghost sm">Save snapshot</button>
        </form>
      </div>
      <?php if ($hist): ?>
        <table class="table-os" style="font-size:12px;margin-top:6px">
          <thead><tr><th>Date</th><th>EBITDA ×</th><th>Disc %</th>
            <th style="text-align:right">Mid (RM)</th>
            <th style="text-align:right">Stake (RM)</th><th>Saved by</th></tr></thead>
          <tbody>
          <?php foreach ($hist as $h): ?>
            <tr><td><?= e($h['date'] ?? '') ?><?= !empty($h['time']) ? ' ' . e($h['time']) : '' ?></td>
              <td><?= e((float) ($h['assumptions']['multiple'] ?? 0)) ?>×</td>
              <td><?= e((float) ($h['assumptions']['discount'] ?? 0)) ?>%</td>
              <td style="text-align:right"><?= money($h['summary']['mid'] ?? 0) ?></td>
              <td style="text-align:right"><?= money($h['summary']['client_stake'] ?? 0) ?></td>
              <td class="muted" style="font-size:11px"><?= e($h['saved_by'] ?? '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="muted" style="font-size:12px;margin-top:6px">No snapshots yet. Save the current state to track changes over time.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php $ai = setting_get_json('valuation_ai:' . $clientId, []); ?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">AI Valuation Commentary
    <?php if (!empty($ai['text'])): ?>
      <span class="muted" style="font-weight:400;font-size:12px">·
        last generated <?= e($ai['updated_at'] ?? '') ?>
        <?= !empty($ai['stubbed']) ? '· offline draft' : '· model ' . e($ai['model'] ?? '') ?>
      </span>
    <?php endif; ?>
  </div>
  <div class="card-os-body">
    <div class="muted" style="font-size:13px;margin-bottom:10px">
      The system computes every ringgit figure (NAV, EBITDA equity, DCF, weighted mid,
      discount stack). The AI writes the surrounding <em>narrative</em> only, using
      the active entries from your
      <a href="<?= e(url('advisor/valuation-knowledge.php')) ?>">Valuation Knowledge Base</a>.
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ai_commentary">
        <button class="btn-os gold sm">
          <?= !empty($ai['text']) ? 'Regenerate commentary' : 'Generate commentary' ?>
        </button>
      </form>
      <?php if (!empty($ai['text'])): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Clear AI commentary?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="ai_clear">
          <button class="btn-os ghost sm">Clear</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if (!empty($ai['text'])): ?>
      <div style="white-space:pre-wrap;font-size:13px;line-height:1.55;
                  background:#f7f9fc;border-left:3px solid var(--gold);
                  padding:12px 14px;border-radius:6px"><?= e($ai['text']) ?></div>
    <?php else: ?>
      <div class="muted" style="font-size:13px;font-style:italic">
        No commentary generated yet — click <strong>Generate commentary</strong> to
        produce an adviser-grade narrative based on the verified figures above.
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card-os">
  <div class="card-os-body">
    <div class="disclaimer">Indicative valuation for advisory discussion only —
      a blended estimate from captured book figures and assumptions, not a
      formal/independent valuation, audit or fairness opinion. A key-person /
      marketability discount from the risk diagnostic is applied. Engage a
      licensed valuer for any transaction.</div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
