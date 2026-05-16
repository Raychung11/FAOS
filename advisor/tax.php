<?php
/** AdvisorOS — Malaysian Income Tax Planning Estimator.
 *  Estimates resident individual tax from the financial snapshot plus
 *  editable reliefs, then surfaces unused-relief planning opportunities.
 *  Estimates for advisory discussion only — never tax advice. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tax_my.php';
require_once __DIR__ . '/../includes/settings.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Tax planning”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$cat = my_relief_catalogue();
$key = 'tax:' . $clientId;

if (is_post()) {
    csrf_check();
    $in = [
        'annual_income' => max(0.0, (float) input('annual_income', 0)),
        'other_income'  => max(0.0, (float) input('other_income', 0)),
        'children_u18'  => max(0, (int) input('children_u18', 0)),
    ];
    foreach ($cat as $k => [$label, $cap]) {
        $in['r_' . $k] = min((float) $cap, max(0.0, (float) input('r_' . $k, 0)));
    }
    setting_put_json($key, $in);
    audit_log('update', 'tax', $clientId, 'Tax planning inputs updated');
    set_flash('success', 'Tax estimate updated.');
    redirect('advisor/tax.php?client_id=' . $clientId);
}

$fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
$fp->execute([$clientId, $tid]);
$fin = $fp->fetch() ?: null;

$default = [
    'annual_income' => $fin ? round((float) $fin['monthly_income'] * 12, 2) : 0.0,
    'other_income'  => 0.0,
    'children_u18'  => 0,
];
foreach ($cat as $k => $v) { $default['r_' . $k] = 0.0; }
$in = setting_get_json($key, $default);
if (!isset($in['annual_income']) || (float) $in['annual_income'] <= 0) {
    $in['annual_income'] = $default['annual_income'];
}

$gross = (float) $in['annual_income'] + (float) $in['other_income'];

$childRelief = (int) $in['children_u18'] * MY_CHILD_RELIEF;
$reliefTotal = MY_SELF_RELIEF + $childRelief;
$reliefRows  = [];
foreach ($cat as $k => [$label, $cap]) {
    $used = (float) ($in['r_' . $k] ?? 0);
    $reliefTotal += $used;
    $reliefRows[$k] = ['label' => $label, 'cap' => (float) $cap, 'used' => $used,
                       'headroom' => max(0.0, (float) $cap - $used)];
}
$reliefTotal = min($reliefTotal, $gross); // can't exceed income

$chargeable = max(0.0, $gross - $reliefTotal);
$grossTax   = my_tax_on($chargeable);
$rebate     = my_rebate($chargeable);
$taxPayable = max(0.0, $grossTax - $rebate);
$marginal   = my_marginal_rate($chargeable);
$effRate    = $gross > 0 ? $taxPayable / $gross : 0.0;

// Planning: unused relief -> potential saving at marginal rate.
$tips = [];
foreach ($reliefRows as $r) {
    if ($r['headroom'] <= 0 || $marginal <= 0) { continue; }
    $room   = min($r['headroom'], $chargeable);
    $saving = $room * $marginal;
    if ($saving >= 1) {
        $tips[] = ['label' => $r['label'], 'room' => $room, 'saving' => $saving];
    }
}
usort($tips, fn($a, $b) => $b['saving'] <=> $a['saving']);
$maxSaving = array_sum(array_column($tips, 'saving'));

$pageTitle = 'Tax Planning — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Income Tax Planning</h2>
    <span class="muted"><?= e($client['full_name']) ?> · Malaysia resident individual · YA2023+ schedule</span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
</div>

<?php if (!$fin): ?>
  <div class="alert-os warning">No financial snapshot on record — income is
    pre-filled from it. Add one on the client profile, or enter income below.
    <a href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Open client</a>.</div>
<?php endif; ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Chargeable Income</div>
    <div class="stat-value">RM <?= money($chargeable) ?></div>
    <div class="stat-foot">gross RM <?= money($gross) ?> · reliefs RM <?= money($reliefTotal) ?></div></div>
  <div class="stat accent"><div class="stat-label">Estimated Tax Payable</div>
    <div class="stat-value">RM <?= money($taxPayable) ?></div>
    <div class="stat-foot">≈ RM <?= money($taxPayable / 12) ?>/mo · rebate RM <?= money($rebate) ?></div></div>
  <div class="stat accent"><div class="stat-label">Tax Rates</div>
    <div class="stat-value" style="font-size:21px">
      <?= round($effRate * 100, 1) ?>%<span class="muted" style="font-size:13px"> effective</span></div>
    <div class="stat-foot">marginal band <?= (int) round($marginal * 100) ?>%</div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Income &amp; Reliefs</div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="form-row"><label>Annual employment income (RM)</label>
            <input type="number" step="0.01" name="annual_income" value="<?= e($in['annual_income']) ?>"></div>
          <div class="form-row"><label>Other annual income (RM)</label>
            <input type="number" step="0.01" name="other_income" value="<?= e($in['other_income'] ?? 0) ?>"></div>
          <div class="form-row"><label>Children under 18 (× RM2,000)</label>
            <input type="number" step="1" name="children_u18" value="<?= e($in['children_u18'] ?? 0) ?>"></div>
          <?php foreach ($cat as $k => [$label, $cap]): ?>
            <div class="form-row">
              <label><?= e($label) ?> <span class="muted">(cap RM<?= e($cap) ?>)</span></label>
              <input type="number" step="0.01" name="r_<?= e($k) ?>"
                     value="<?= e($in['r_' . $k] ?? 0) ?>"></div>
          <?php endforeach; ?>
        </div>
        <p class="muted" style="font-size:12px;margin:4px 0 10px">
          Self &amp; dependent-relatives relief of RM<?= number_format(MY_SELF_RELIEF) ?>
          is applied automatically.</p>
        <button class="btn-os">Recalculate</button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Planning Opportunities</div>
    <div class="card-os-body" style="padding:0">
      <?php if ($tips): ?>
        <table class="table-os">
          <thead><tr><th>Unused relief</th><th>Top-up room</th><th>Est. tax saving</th></tr></thead>
          <tbody>
          <?php foreach ($tips as $t): ?>
            <tr><td><?= e($t['label']) ?></td>
                <td>RM <?= money($t['room']) ?></td>
                <td style="color:var(--ok)">RM <?= money($t['saving']) ?></td></tr>
          <?php endforeach; ?>
          <tr><td><strong>Total potential</strong></td><td></td>
              <td><strong style="color:var(--ok)">RM <?= money($maxSaving) ?></strong></td></tr>
          </tbody>
        </table>
      <?php else: ?>
        <div style="padding:18px" class="muted">
          No further relief headroom at the current marginal rate
          (<?= (int) round($marginal * 100) ?>%), or chargeable income is nil.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Advisor Talking Points</div>
  <div class="card-os-body">
    <ul style="margin:0 0 6px 18px">
      <?php if ($maxSaving >= 1): ?>
        <li>Up to <strong>RM <?= money($maxSaving) ?></strong> of estimated tax
            could be deferred/saved by fully utilising eligible reliefs — the
            largest lever is <strong><?= e($tips[0]['label']) ?></strong>.</li>
        <li>PRS &amp; SSPN top-ups also advance the client's retirement and
            education goals — align with the financial plan, not tax alone.</li>
      <?php elseif ($chargeable <= 0): ?>
        <li>Estimated chargeable income is nil after reliefs — no planning
            action needed; confirm filing obligations still met.</li>
      <?php else: ?>
        <li>Reliefs appear fully utilised. Focus on income timing and
            longer-term structuring with a licensed tax agent.</li>
      <?php endif; ?>
    </ul>
    <div class="disclaimer">Estimates use the resident-individual schedule
      (YA2023 onwards) and selected reliefs only — not a tax computation,
      filing or advice. LHDN rates, reliefs, rebates and eligibility change
      and depend on individual circumstances. Engage a licensed tax agent /
      financial advisor before acting.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
