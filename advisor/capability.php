<?php
/** AdvisorOS — Borrowing Capacity & Leverage Analysis (Malaysia / DSR).
 *  Turns the financial snapshot into capability insight: how much more
 *  the client could responsibly borrow, or — if over-leveraged — how to
 *  cut interest. Estimates for advisory discussion only. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Borrowing capability”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$key = 'cap:' . $clientId;

if (is_post()) {
    csrf_check();
    $in = [
        'monthly_debt'    => max(0.0, (float) input('monthly_debt', 0)),
        'avg_rate'        => max(0.0, (float) input('avg_rate', 0)),
        'remaining_years' => max(0.5, (float) input('remaining_years', 0)),
        'dsr_cap'         => min(80.0, max(30.0, (float) input('dsr_cap', 60))),
        'new_rate'        => max(0.0, (float) input('new_rate', 0)),
        'new_years'       => min(35.0, max(1.0, (float) input('new_years', 10))),
        'refi_rate'       => max(0.0, (float) input('refi_rate', 0)),
        'lump_sum'        => max(0.0, (float) input('lump_sum', 0)),
    ];
    setting_put_json($key, $in);
    meter_report('capability');
    audit_log('update', 'capability', $clientId, 'Borrowing capability inputs updated');
    set_flash('success', 'Capability analysis updated.');
    redirect('advisor/capability.php?client_id=' . $clientId);
}

// Latest financial snapshot drives income / liabilities.
$fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
$fp->execute([$clientId, $tid]);
$fin = $fp->fetch() ?: null;

$in = setting_get_json($key, [
    'monthly_debt'    => 0.0,
    'avg_rate'        => 4.5,
    'remaining_years' => 20.0,
    'dsr_cap'         => 60.0,
    'new_rate'        => 4.5,
    'new_years'       => 10.0,
    'refi_rate'       => 3.5,
    'lump_sum'        => 0.0,
]);

$income   = $fin ? (float) $fin['monthly_income']   : 0.0;
$expenses = $fin ? (float) $fin['monthly_expenses'] : 0.0;
$liab     = $fin ? (float) $fin['total_liabilities']: 0.0;

$cap        = $in['dsr_cap'] / 100;
$currentDsr = $income > 0 ? $in['monthly_debt'] / $income : 0.0;
$maxDebt    = $income * $cap;
$headroomMo = $maxDebt - $in['monthly_debt'];

$status = 'Insufficient data';
$statusClass = 'b-warn';
if ($income > 0) {
    if ($currentDsr <= $cap * 0.75) { $status = 'Under-leveraged'; $statusClass = 'b-active'; }
    elseif ($currentDsr <= $cap)    { $status = 'Healthy';         $statusClass = 'b-scheduled'; }
    else                            { $status = 'Over-leveraged';  $statusClass = 'b-overdue'; }
}

// Borrowing headroom -> supportable additional loan principal.
$addPrincipal = $headroomMo > 0
    ? loan_principal_from_payment($headroomMo, $in['new_rate'], $in['new_years'])
    : 0.0;
$reqReduction = $headroomMo < 0 ? -$headroomMo : 0.0;

// Interest position on current borrowing (estimated from liabilities).
$curMonthlyCalc = loan_monthly_payment($liab, $in['avg_rate'], $in['remaining_years']);
$curInterest    = loan_total_interest($liab, $in['avg_rate'], $in['remaining_years']);

// Scenario 1 — refinance current debt at a lower rate.
$refiMonthly  = loan_monthly_payment($liab, $in['refi_rate'], $in['remaining_years']);
$refiInterest = loan_total_interest($liab, $in['refi_rate'], $in['remaining_years']);
$refiSaving   = max(0.0, $curInterest - $refiInterest);

// Scenario 2 — lump-sum prepayment.
$prepP        = max(0.0, $liab - $in['lump_sum']);
$prepInterest = loan_total_interest($prepP, $in['avg_rate'], $in['remaining_years']);
$prepSaving   = max(0.0, $curInterest - $prepInterest);
$prepMonthly  = loan_monthly_payment($prepP, $in['avg_rate'], $in['remaining_years']);

// Scenario 3 — extend tenure by 5y (relieves cash flow, costs interest).
$extYears     = $in['remaining_years'] + 5;
$extMonthly   = loan_monthly_payment($liab, $in['avg_rate'], $extYears);
$extInterest  = loan_total_interest($liab, $in['avg_rate'], $extYears);
$extRelief    = max(0.0, $curMonthlyCalc - $extMonthly);
$extCost      = max(0.0, $extInterest - $curInterest);

$dsrPct  = (int) round($currentDsr * 100);
$capPct  = (int) round($cap * 100);
$barFill = min(100, $capPct > 0 ? (int) round($dsrPct / max(1, $capPct) * 100) : 0);

$pageTitle = 'Borrowing Capability — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Borrowing Capacity &amp; Leverage</h2>
    <span class="muted"><?= e($client['full_name']) ?> · Malaysia DSR model</span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
</div>

<?php if (!$fin): ?>
  <div class="alert-os warning">No financial snapshot on record. Add one on the
    client profile first — income and liabilities drive this analysis.
    <a href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Open client</a>.</div>
<?php endif; ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Leverage Status</div>
    <div class="stat-value" style="font-size:21px">
      <span class="badge-os <?= $statusClass ?>" style="font-size:14px"><?= e($status) ?></span></div>
    <div class="stat-foot">DSR <?= $dsrPct ?>% vs <?= $capPct ?>% cap</div></div>
  <div class="stat accent"><div class="stat-label">
      <?= $headroomMo >= 0 ? 'Monthly Borrowing Headroom' : 'Monthly Over-commitment' ?></div>
    <div class="stat-value">RM <?= money(abs($headroomMo)) ?></div>
    <div class="stat-foot"><?= $headroomMo >= 0 ? 'spare debt-service capacity' : 'above the DSR cap' ?></div></div>
  <div class="stat accent">
    <?php if ($headroomMo >= 0): ?>
      <div class="stat-label">Additional Loan Supportable</div>
      <div class="stat-value">RM <?= money($addPrincipal) ?></div>
      <div class="stat-foot">@ <?= e($in['new_rate']) ?>% over <?= e((int)$in['new_years']) ?>y</div>
    <?php else: ?>
      <div class="stat-label">Monthly Reduction Needed</div>
      <div class="stat-value" style="color:var(--danger)">RM <?= money($reqReduction) ?></div>
      <div class="stat-foot">to return within the DSR cap</div>
    <?php endif; ?>
  </div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Debt Service Ratio</div>
  <div class="card-os-body">
    <div style="background:#eef2fb;border-radius:8px;height:16px;position:relative;overflow:hidden">
      <div style="width:<?= $barFill ?>%;height:16px;background:<?= $headroomMo>=0?'var(--gold)':'var(--danger)' ?>"></div>
    </div>
    <div class="muted" style="margin-top:8px;font-size:13px">
      Current DSR <strong><?= $dsrPct ?>%</strong> · policy cap
      <strong><?= $capPct ?>%</strong> · monthly income RM <?= money($income) ?>
      · max serviceable debt RM <?= money($maxDebt) ?>
    </div>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Inputs</div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="form-row"><label>Current monthly debt repayment (RM)</label>
            <input type="number" step="0.01" name="monthly_debt" value="<?= e($in['monthly_debt']) ?>"></div>
          <div class="form-row"><label>Avg. current interest rate (%)</label>
            <input type="number" step="0.01" name="avg_rate" value="<?= e($in['avg_rate']) ?>"></div>
          <div class="form-row"><label>Remaining tenure (years)</label>
            <input type="number" step="0.5" name="remaining_years" value="<?= e($in['remaining_years']) ?>"></div>
          <div class="form-row"><label>DSR cap (%)</label>
            <input type="number" step="1" name="dsr_cap" value="<?= e($in['dsr_cap']) ?>"></div>
          <div class="form-row"><label>New-loan rate (%)</label>
            <input type="number" step="0.01" name="new_rate" value="<?= e($in['new_rate']) ?>"></div>
          <div class="form-row"><label>New-loan tenure (years)</label>
            <input type="number" step="1" name="new_years" value="<?= e($in['new_years']) ?>"></div>
          <div class="form-row"><label>Target refinance rate (%)</label>
            <input type="number" step="0.01" name="refi_rate" value="<?= e($in['refi_rate']) ?>"></div>
          <div class="form-row"><label>Prepayment lump sum (RM)</label>
            <input type="number" step="0.01" name="lump_sum" value="<?= e($in['lump_sum']) ?>"></div>
        </div>
        <button class="btn-os">Recalculate</button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Interest-Saving Scenarios</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <tbody>
          <tr><td class="muted">Current est. total interest</td>
              <td><strong>RM <?= money($curInterest) ?></strong>
              <span class="muted">(≈ RM <?= money($curMonthlyCalc) ?>/mo on RM <?= money($liab) ?>)</span></td></tr>
          <tr><td class="muted">Refinance @ <?= e($in['refi_rate']) ?>%</td>
              <td>Interest RM <?= money($refiInterest) ?> ·
                  <span style="color:var(--ok)">save RM <?= money($refiSaving) ?></span><br>
                  <span class="muted">new ≈ RM <?= money($refiMonthly) ?>/mo</span></td></tr>
          <tr><td class="muted">Prepay RM <?= money($in['lump_sum']) ?></td>
              <td>Interest RM <?= money($prepInterest) ?> ·
                  <span style="color:var(--ok)">save RM <?= money($prepSaving) ?></span><br>
                  <span class="muted">new ≈ RM <?= money($prepMonthly) ?>/mo</span></td></tr>
          <tr><td class="muted">Extend tenure +5y</td>
              <td>Relief ≈ RM <?= money($extRelief) ?>/mo ·
                  <span style="color:var(--danger)">extra interest RM <?= money($extCost) ?></span></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Advisor Talking Points</div>
  <div class="card-os-body">
    <ul style="margin:0 0 6px 18px">
      <?php if ($status === 'Under-leveraged'): ?>
        <li>Significant unused debt-service capacity — client may responsibly
            fund goals (property, business, education) with up to about
            <strong>RM <?= money($addPrincipal) ?></strong> of new financing.</li>
        <li>Confirm the borrowing aligns with goals and risk profile before recommending.</li>
      <?php elseif ($status === 'Healthy'): ?>
        <li>Leverage is within a healthy band. Modest headroom of about
            <strong>RM <?= money(max(0,$headroomMo)) ?>/month</strong> exists if needed.</li>
        <li>Focus on optimising rate (refinance saves ≈ RM <?= money($refiSaving) ?>).</li>
      <?php elseif ($status === 'Over-leveraged'): ?>
        <li>DSR exceeds the cap — prioritise reducing monthly commitments by
            about <strong>RM <?= money($reqReduction) ?></strong>.</li>
        <li>Refinancing could save ≈ RM <?= money($refiSaving) ?> in interest;
            a lump-sum prepayment of RM <?= money($in['lump_sum']) ?> saves
            ≈ RM <?= money($prepSaving) ?>.</li>
      <?php else: ?>
        <li>Add a financial snapshot (income &amp; liabilities) to generate insight.</li>
      <?php endif; ?>
    </ul>
    <div class="disclaimer">Estimates for advisory discussion only — not a loan
      offer, eligibility decision or financial advice. Lender DSR policy,
      product terms and approval criteria vary. Final recommendations must be
      reviewed and approved by a licensed financial advisor.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
