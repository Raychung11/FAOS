<?php
/** AdvisorOS — Business Valuation (NAV / EBITDA / DCF, risk-adjusted). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/valuation.php';
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
    $cid = (int) input('company_id', 0);
    $co  = company_with_client($cid);
    if (!$co || (int) $co['owner_client_id'] !== $clientId) {
        http_response_code(403); exit('Invalid company.');
    }
    $action = (string) input('action', 'save');
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
    valuation_assumptions_save($cid, [
        'multiple'      => input('multiple', 4),
        'discount'      => input('discount', 18),
        'growth'        => input('growth', 3),
        'weight_nav'    => input('weight_nav', 20),
        'weight_ebitda' => input('weight_ebitda', 50),
        'weight_dcf'    => input('weight_dcf', 30),
    ]);
    meter_report('valuation');
    audit_log('update', 'valuation', $cid, 'Valuation assumptions updated');
    set_flash('success', 'Valuation updated.');
    redirect('advisor/valuation.php?client_id=' . $clientId);
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
  <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
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

      <form method="post" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
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
        <button class="btn-os">Recalculate</button>
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
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

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
