<?php
/** AdvisorOS — Malaysian Income Tax Planning Estimator.
 *  Estimates resident individual tax from the financial snapshot plus
 *  editable reliefs, then surfaces unused-relief planning opportunities.
 *  Estimates for advisory discussion only — never tax advice. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tax_my.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/billing.php';
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
        'annual_income'     => max(0.0, (float) input('annual_income', 0)),
        'other_income'      => max(0.0, (float) input('other_income', 0)),
        'children_u18'      => max(0, (int) input('children_u18', 0)),
        'children_tertiary' => max(0, (int) input('children_tertiary', 0)),
    ];
    foreach ($cat as $k => [$label, $cap]) {
        $in['r_' . $k] = min((float) $cap, max(0.0, (float) input('r_' . $k, 0)));
    }
    setting_put_json($key, $in);
    meter_report('tax');
    audit_log('update', 'tax', $clientId, 'Tax planning inputs updated');
    set_flash('success', 'Tax estimate updated.');
    redirect('advisor/tax.php?client_id=' . $clientId);
}

$fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
$fp->execute([$clientId, $tid]);
$fin = $fp->fetch() ?: null;

$default = [
    'annual_income'     => $fin ? round((float) $fin['monthly_income'] * 12, 2) : 0.0,
    'other_income'      => 0.0,
    'children_u18'      => 0,
    'children_tertiary' => 0,
];
foreach ($cat as $k => $v) { $default['r_' . $k] = 0.0; }
$in = setting_get_json($key, $default);
if (!isset($in['annual_income']) || (float) $in['annual_income'] <= 0) {
    $in['annual_income'] = $default['annual_income'];
}

$gross = (float) $in['annual_income'] + (float) $in['other_income'];

$childRelief = (int) $in['children_u18'] * my_child_relief()
             + (int) ($in['children_tertiary'] ?? 0) * my_child_tertiary_relief();
$reliefTotal = my_self_relief() + $childRelief;
$reliefRows  = [];
foreach ($cat as $k => [$label, $cap, $group]) {
    $used = (float) ($in['r_' . $k] ?? 0);
    $reliefTotal += $used;
    $reliefRows[$k] = ['label' => $label, 'cap' => (float) $cap, 'group' => $group,
                       'used' => $used, 'headroom' => max(0.0, (float) $cap - $used)];
}
$reliefTotal = min($reliefTotal, $gross); // can't exceed income

$chargeable = max(0.0, $gross - $reliefTotal);
$grossTax   = my_tax_on($chargeable);
$rebate     = my_rebate($chargeable);
$taxPayable = max(0.0, $grossTax - $rebate);
$marginal   = my_marginal_rate($chargeable);
$effRate    = $gross > 0 ? $taxPayable / $gross : 0.0;

// Relief-by-relief advice: room left, est. saving at marginal rate, action.
$advice = [];
foreach ($reliefRows as $r) {
    $room   = $r['headroom'];
    $saving = ($room > 0 && $marginal > 0) ? min($room, $chargeable) * $marginal : 0.0;
    if ($room <= 0) {
        $status = 'Maximised'; $action = 'Fully claimed — no further room.';
    } elseif ($marginal <= 0) {
        $status = 'No tax benefit';
        $action = 'Room of RM ' . money($room) . ', but no tax is payable at this income.';
    } else {
        $status = 'Top up';
        $action = 'Top up RM ' . money($room) . ' to save ≈ RM ' . money($saving) . '.';
    }
    $advice[] = ['label' => $r['label'], 'group' => $r['group'], 'used' => $r['used'],
                 'cap' => $r['cap'], 'room' => $room, 'saving' => $saving,
                 'status' => $status, 'action' => $action];
}
usort($advice, fn ($a, $b) => $b['saving'] <=> $a['saving']);
$maxSaving = array_sum(array_column($advice, 'saving'));
$topLever  = $advice && $advice[0]['saving'] > 0 ? $advice[0]['label'] : '';

$grouped = [];
foreach ($cat as $k => [$label, $cap, $group]) { $grouped[$group][$k] = [$label, $cap]; }

$pageTitle = 'Tax Planning — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Income Tax Planning</h2>
    <span class="muted"><?= e($client['full_name']) ?> · follows LHDN individual reliefs (YA2024)</span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn-os sm" href="<?= e(url('advisor/tax-compute.php?client_id='.$clientId)) ?>">Full computation</a>
    <a class="btn-os sm" href="<?= e(url('advisor/tax-report.php?client_id='.$clientId)) ?>">Before/After report</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
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
    <div class="card-os-head">Update the client's tax information</div>
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
          <div class="form-row"><label>Children 18+ in tertiary study (× RM8,000)</label>
            <input type="number" step="1" name="children_tertiary" value="<?= e($in['children_tertiary'] ?? 0) ?>"></div>
        </div>
        <p class="muted" style="font-size:12px;margin:4px 0 10px">Enter how much
          the client has <em>already claimed/spent</em> against each LHDN relief —
          the system shows the room left and what to do. Self &amp;
          dependent-relatives relief of RM<?= number_format(my_self_relief()) ?>
          is applied automatically.</p>
        <?php foreach ($grouped as $group => $items): ?>
          <div class="muted" style="font-size:11px;font-weight:700;text-transform:uppercase;
               letter-spacing:.05em;margin:10px 0 6px;color:var(--navy)"><?= e($group) ?></div>
          <div class="form-grid">
            <?php foreach ($items as $k => [$label, $cap]): ?>
              <div class="form-row">
                <label><?= e($label) ?> <span class="muted">(cap RM<?= e($cap) ?>)</span></label>
                <input type="number" step="0.01" name="r_<?= e($k) ?>"
                       value="<?= e($in['r_' . $k] ?? 0) ?>"></div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button class="btn-os" style="margin-top:12px">Save &amp; advise</button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">What to do — relief-by-relief advice</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>LHDN relief</th><th>Claimed / cap</th><th>Advice</th></tr></thead>
        <tbody>
        <?php foreach ($advice as $a): ?>
          <tr>
            <td><strong><?= e($a['label']) ?></strong>
              <div class="muted" style="font-size:11px"><?= e($a['group']) ?></div></td>
            <td>RM <?= money($a['used']) ?> / <?= money($a['cap']) ?>
              <?php $cls = $a['status']==='Maximised'?'b-active':($a['status']==='Top up'?'b-warn':'b-scheduled'); ?>
              <div><span class="badge-os <?= $cls ?>" style="font-size:10px"><?= e($a['status']) ?></span></div></td>
            <td style="font-size:13px"><?= e($a['action']) ?>
              <?php if ($a['saving'] > 0): ?>
                <span style="color:var(--ok);font-weight:600">+RM <?= money($a['saving']) ?></span>
              <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><td colspan="2"><strong>Total potential annual saving</strong></td>
            <td><strong style="color:var(--ok)">RM <?= money($maxSaving) ?></strong></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Advisor Talking Points</div>
  <div class="card-os-body">
    <ul style="margin:0 0 6px 18px">
      <?php if ($maxSaving >= 1): ?>
        <li>Up to <strong>RM <?= money($maxSaving) ?></strong> of estimated tax
            could be deferred/saved by fully utilising eligible reliefs<?= $topLever ? ' — the largest lever is <strong>'.e($topLever).'</strong>' : '' ?>.</li>
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
