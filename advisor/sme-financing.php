<?php
/** AdvisorOS — SME financing readiness (DSCR & facility headroom). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/sme_finance.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

require_tenant();
$cid = (int) ($_GET['company_id'] ?? 0);
$co  = company_with_client($cid);
if (!$co) { http_response_code(404); exit('Company not found. Open it from a client\'s Business profile.'); }
if (has_role('financial_advisor') && (int) $co['client_advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}
$cid = (int) $co['id'];
$bf  = bf_latest($cid);

if (is_post()) {
    csrf_check();
    sme_inputs_save($cid, [
        'interest'       => input('interest', 6.5),
        'tenure'         => input('tenure', 7),
        'target_dscr'    => input('target_dscr', 1.25),
        'new_rate'       => input('new_rate', 6.5),
        'new_tenure'     => input('new_tenure', 7),
        'credit_conduct' => input('credit_conduct', 'clean'),
    ]);
    meter_report('capability');
    audit_log('update', 'capability', $cid, 'SME financing readiness updated');
    set_flash('success', 'Financing readiness updated.');
    redirect('advisor/sme-financing.php?company_id=' . $cid);
}

$in = sme_inputs($cid);
$r  = sme_financing($bf ?: [], $in);

$pageTitle = 'SME Financing — ' . $co['name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">SME Financing Readiness</h2>
    <span class="muted"><?= e($co['name']) ?> · DSCR &amp; facility headroom · owned by <?= e($co['client_name']) ?></span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/company-view.php?id='.$cid)) ?>">Back to company</a>
</div>

<?php if (!$r['has_data']): ?>
  <div class="alert-os warning">No business financial snapshot. Add one in
    <a href="<?= e(url('advisor/company-view.php?id='.$cid)) ?>">the company</a>
    (EBITDA &amp; bank loans drive DSCR), then return here.</div>
<?php else: ?>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">DSCR</div>
    <div class="stat-value"><?= $r['dscr'] >= 99 ? '∞' : number_format($r['dscr'], 2) ?>
      <span class="badge-os <?= $r['band_cls'] ?>" style="font-size:12px"><?= e($r['band']) ?></span></div>
    <div class="stat-foot">EBITDA RM <?= money($r['ebitda']) ?> ÷ debt service</div></div>
  <div class="stat accent"><div class="stat-label">Annual Debt Service</div>
    <div class="stat-value">RM <?= money($r['annual_service']) ?></div>
    <div class="stat-foot">on RM <?= money($r['loans']) ?> bank loans</div></div>
  <div class="stat accent"><div class="stat-label">Bankability</div>
    <div class="stat-value" style="font-size:19px">
      <span class="badge-os <?= $r['verdict_cls'] ?>" style="font-size:13px"><?= e($r['verdict']) ?></span></div>
    <div class="stat-foot">score <?= (int) $r['score'] ?>/100</div></div>
  <div class="stat accent"><div class="stat-label">Additional Facility</div>
    <div class="stat-value">RM <?= money($r['add_facility']) ?></div>
    <div class="stat-foot">at target DSCR <?= number_format((float) $in['target_dscr'], 2) ?></div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Assumptions</div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="form-row"><label>Current loan rate (%)</label>
            <input type="number" step="0.01" name="interest" value="<?= e($in['interest']) ?>"></div>
          <div class="form-row"><label>Remaining tenure (years)</label>
            <input type="number" step="0.5" name="tenure" value="<?= e($in['tenure']) ?>"></div>
          <div class="form-row"><label>Target DSCR (bank)</label>
            <input type="number" step="0.05" name="target_dscr" value="<?= e($in['target_dscr']) ?>"></div>
          <div class="form-row"><label>New-facility rate (%)</label>
            <input type="number" step="0.01" name="new_rate" value="<?= e($in['new_rate']) ?>"></div>
          <div class="form-row"><label>New-facility tenure (years)</label>
            <input type="number" step="0.5" name="new_tenure" value="<?= e($in['new_tenure']) ?>"></div>
          <div class="form-row"><label>Credit conduct (CCRIS/CTOS)</label>
            <select name="credit_conduct">
              <?php foreach (SME_CREDIT_CONDUCT as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $in['credit_conduct']===$k?'selected':'' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <button class="btn-os gold">Recalculate</button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Readiness &amp; recommendations</div>
    <div class="card-os-body">
      <ul style="margin:0 0 6px 18px">
        <?php if ($r['dscr'] < 1.0): ?>
          <li>EBITDA does not cover current debt service — restructure or
            extend tenure before seeking new facilities.</li>
        <?php elseif ($r['dscr'] < (float) $in['target_dscr']): ?>
          <li>DSCR is below the bank's target — improve EBITDA or reduce
            servicing before applying; limited new-facility headroom.</li>
        <?php else: ?>
          <li>DSCR supports an additional facility of ≈
            <strong>RM <?= money($r['add_facility']) ?></strong> at the bank's
            target coverage.</li>
        <?php endif; ?>
        <?php if ($in['credit_conduct'] !== 'clean'): ?>
          <li>Credit conduct (<?= e(SME_CREDIT_CONDUCT[$in['credit_conduct']]) ?>)
            will weigh on approval — remediate CCRIS/CTOS records and show
            6–12 months of clean conduct.</li>
        <?php endif; ?>
        <li>Strengthen the file: management accounts, tightened receivables
          and a clear use-of-funds and repayment story.</li>
      </ul>
      <div class="disclaimer">Indicative estimate from captured figures —
        not a credit decision, bank offer or advice. Actual approval depends
        on the lender's policy, full CCRIS/CTOS, security and cash flow.</div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
