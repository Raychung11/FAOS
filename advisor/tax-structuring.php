<?php
/** AdvisorOS — Corporate tax structuring (salary vs dividend). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/corptax.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

require_tenant();
$cid = (int) ($_GET['company_id'] ?? 0);
$co  = company_with_client($cid);
if (!$co) { http_response_code(404); exit('Company not found. Open it from a client\'s Business profile.'); }
if (has_role('financial_advisor') && (int) $co['client_advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}
$clientId = (int) $co['owner_client_id'];
$bf = bf_latest($cid);

if (is_post()) {
    csrf_check();
    corptax_inputs_save($cid, [
        'pre_profit'   => input('pre_profit', 0),
        'extraction'   => input('extraction', 0),
        'other_income' => input('other_income', 0),
        'reliefs'      => input('reliefs', 9000),
        'is_sme'       => input('is_sme', ''),
        'family_pay'   => input('family_pay', 0),
    ]);
    meter_report('tax');
    audit_log('update', 'tax', $cid, 'Corporate tax structuring updated');
    set_flash('success', 'Tax structuring updated.');
    redirect('advisor/tax-structuring.php?company_id=' . $cid);
}

$in = corptax_inputs($cid, $bf);
$sc = salary_dividend_scenarios($in['pre_profit'], $in['extraction'],
    $in['other_income'], $in['reliefs'], $in['is_sme']);

$ownerTaxable = max(0.0, $sc['optimal']['salary'] + $in['other_income'] - $in['reliefs']);
$ownerMarg    = my_marginal_rate($ownerTaxable);
$famPay       = (float) $in['family_pay'];
$famTax       = my_tax_on(max(0.0, $famPay - 9000));
$famSaving    = max(0.0, $famPay * $ownerMarg - $famTax);

$rowsView = [
    ['All salary', $sc['all_salary'], 'b-warn'],
    ['Optimal mix', $sc['optimal'], 'b-active'],
    ['All dividend', $sc['all_dividend'], 'b-scheduled'],
];

$pageTitle = 'Tax Structuring — ' . $co['name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Corporate Tax Structuring</h2>
    <span class="muted"><?= e($co['name']) ?> · salary vs dividend · owned by <?= e($co['client_name']) ?></span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/company-view.php?id='.$cid)) ?>">Back to company</a>
</div>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Efficient Split</div>
    <div class="stat-value" style="font-size:19px">RM <?= money($sc['optimal']['salary']) ?> <span class="muted" style="font-size:13px">salary</span></div>
    <div class="stat-foot">+ RM <?= money($sc['optimal']['dividend']) ?> dividend</div></div>
  <div class="stat accent"><div class="stat-label">Total Tax (optimal)</div>
    <div class="stat-value">RM <?= money($sc['optimal']['total']) ?></div>
    <div class="stat-foot">effective <?= round($sc['optimal']['etr'] * 100, 1) ?>% of profit</div></div>
  <div class="stat accent"><div class="stat-label">Potential Saving</div>
    <div class="stat-value" style="color:var(--ok)">RM <?= money(max(0.0, $sc['all_salary']['total'] - $sc['optimal']['total'])) ?></div>
    <div class="stat-foot">vs an all-salary draw</div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Assumptions</div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="form-row"><label>Pre-extraction profit (RM)</label>
            <input type="number" step="0.01" name="pre_profit" value="<?= e($in['pre_profit']) ?>"></div>
          <div class="form-row"><label>Cash to extract (RM)</label>
            <input type="number" step="0.01" name="extraction" value="<?= e($in['extraction']) ?>"></div>
          <div class="form-row"><label>Owner's other taxable income (RM)</label>
            <input type="number" step="0.01" name="other_income" value="<?= e($in['other_income']) ?>"></div>
          <div class="form-row"><label>Personal reliefs (RM)</label>
            <input type="number" step="0.01" name="reliefs" value="<?= e($in['reliefs']) ?>"></div>
          <div class="form-row"><label>Family member pay — illustration (RM)</label>
            <input type="number" step="0.01" name="family_pay" value="<?= e($in['family_pay']) ?>"></div>
          <div class="form-row" style="display:flex;align-items:flex-end">
            <label style="display:flex;gap:8px;align-items:center;font-weight:500;margin:0">
              <input type="checkbox" name="is_sme" value="1" <?= $in['is_sme']?'checked':'' ?> style="width:auto">
              SME rates (15/17/24%)</label></div>
        </div>
        <button class="btn-os gold">Recalculate</button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Extraction comparison</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Strategy</th><th>Salary / Dividend</th><th>Corp + Personal</th><th>Total tax</th><th>ETR</th></tr></thead>
        <tbody>
        <?php foreach ($rowsView as [$lbl, $r, $cls]): ?>
          <tr>
            <td><span class="badge-os <?= $cls ?>"><?= e($lbl) ?></span>
              <?php if (!$r['feasible']): ?><div class="muted" style="font-size:11px">extraction exceeds funds</div><?php endif; ?></td>
            <td>RM <?= money($r['salary']) ?> / RM <?= money($r['dividend']) ?></td>
            <td>RM <?= money($r['corp']) ?> + RM <?= money($r['personal']) ?></td>
            <td><strong>RM <?= money($r['total']) ?></strong></td>
            <td><?= round($r['etr'] * 100, 1) ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Structuring opportunities</div>
  <div class="card-os-body">
    <ul style="margin:0 0 6px 18px">
      <li><strong>Salary vs dividend.</strong> Draw salary up to roughly where
        the personal marginal rate meets the corporate rate, then take the
        balance as (single-tier, tax-exempt) dividend — the optimal split
        above saves ≈ <strong>RM <?= money(max(0.0, $sc['all_salary']['total'] - $sc['optimal']['total'])) ?></strong>.</li>
      <?php if ($famPay > 0): ?>
        <li><strong>Family remuneration.</strong> Paying RM <?= money($famPay) ?>
          to a family member with no other income is taxed at ≈ RM <?= money($famTax) ?>
          vs the owner's marginal rate of <?= (int) round($ownerMarg * 100) ?>% —
          an indicative shift of ≈ <strong>RM <?= money($famSaving) ?></strong>/yr.
          Must reflect genuine, commercially-justifiable services.</li>
      <?php endif; ?>
      <li><strong>Holding company / asset isolation.</strong> Where surplus
        profits are retained or property is held in the trading entity,
        a holding-company or separate property structure can defer tax and
        ring-fence assets — assess group structure with a tax agent.</li>
    </ul>
    <div class="disclaimer">Indicative estimate using Malaysian single-tier
      and SME/standard corporate rates plus the resident-individual personal
      bands — not tax advice, a computation or filing. EPF/SOCSO, stamp duty,
      transfer pricing and anti-avoidance rules are not modelled. Confirm with
      a licensed tax agent before acting.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
