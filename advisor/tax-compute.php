<?php
/** AdvisorOS — Full itemised individual tax computation (Part A). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tax_compute.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Full computation”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$cat = my_relief_catalogue();

if (is_post()) {
    csrf_check();
    $zip = function (array $keys) {
        $cols = [];
        foreach ($keys as $alias => $field) { $cols[$alias] = (array) ($_POST[$field] ?? []); }
        $n = max(array_map('count', $cols) ?: [0]);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [];
            foreach ($cols as $alias => $vals) { $row[$alias] = $vals[$i] ?? ''; }
            $rows[] = $row;
        }
        return $rows;
    };

    $employment = array_values(array_filter(
        $zip(['label' => 'emp_label', 'amount' => 'emp_amount', 'exempt' => 'emp_exempt']),
        fn ($r) => trim((string) $r['label']) !== '' || (float) $r['amount'] != 0
    ));
    $businesses = array_values(array_filter(
        $zip(['name' => 'biz_name', 'gross' => 'biz_gross', 'expenses' => 'biz_exp', 'ca' => 'biz_ca']),
        fn ($r) => trim((string) $r['name']) !== '' || (float) $r['gross'] != 0
    ));
    $rental = array_values(array_filter(
        $zip(['name' => 'rent_name', 'gross' => 'rent_gross', 'expenses' => 'rent_exp', 'type' => 'rent_type']),
        fn ($r) => trim((string) $r['name']) !== '' || (float) $r['gross'] != 0
    ));
    $other = array_values(array_filter(
        $zip(['label' => 'oth_label', 'amount' => 'oth_amount']),
        fn ($r) => trim((string) $r['label']) !== '' || (float) $r['amount'] != 0
    ));

    $reliefs = [
        'children_u18'      => max(0, (int) input('children_u18', 0)),
        'children_tertiary' => max(0, (int) input('children_tertiary', 0)),
        'disabled_u18'      => max(0, (int) input('disabled_u18', 0)),
        'disabled_tertiary' => max(0, (int) input('disabled_tertiary', 0)),
    ];
    foreach ($cat as $k => $v) { $reliefs['r_' . $k] = max(0.0, (float) input('r_' . $k, 0)); }

    taxcomp_save($clientId, [
        'employment' => $employment, 'businesses' => $businesses,
        'rental' => $rental, 'other' => $other,
        'donations' => max(0.0, (float) input('donations', 0)),
        'zakat'     => max(0.0, (float) input('zakat', 0)),
        'reliefs'   => $reliefs,
    ]);
    meter_report('tax');
    audit_log('update', 'tax', $clientId, 'Full tax computation updated');
    set_flash('success', 'Computation saved.');
    redirect('advisor/tax-compute.php?client_id=' . $clientId);
}

$in = taxcomp_get($clientId);
$r  = tax_compute($in);

/** Render stored rows + N blank rows. */
function blanks(array $rows, int $extra = 3): array
{
    for ($i = 0; $i < $extra; $i++) { $rows[] = []; }
    return $rows;
}
$grouped = [];
foreach ($cat as $k => [$label, $cap, $group]) { $grouped[$group][$k] = [$label, $cap]; }

$pageTitle = 'Full Tax Computation — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Full Tax Computation</h2>
    <span class="muted"><?= e($client['full_name']) ?> · itemised Part A · YA <?= (int) $r['ya'] ?></span>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn-os ghost sm" href="<?= e(url('advisor/solution-tax.php?client_id='.$clientId)) ?>">Tax solution</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<!-- ===================== RESULT ===================== -->
<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Total Income</div>
    <div class="stat-value">RM <?= money($r['total']) ?></div>
    <div class="stat-foot">aggregate RM <?= money($r['aggregate']) ?><?= $r['donations']>0?' · less donations RM '.money($r['donations']):'' ?></div></div>
  <div class="stat accent"><div class="stat-label">Chargeable Income</div>
    <div class="stat-value">RM <?= money($r['chargeable']) ?></div>
    <div class="stat-foot">reliefs RM <?= money($r['relief_before']) ?></div></div>
  <div class="stat accent"><div class="stat-label">Tax Payable</div>
    <div class="stat-value">RM <?= money($r['tax_payable']) ?></div>
    <div class="stat-foot">marginal <?= (int) round($r['marginal']*100) ?>% · if reliefs maxed: RM <?= money($r['tax_after']) ?></div></div>
</div>

<?php if ($r['emp_rows'] || $r['biz_rows'] || $r['rent_rows'] || $r['other_rows']): ?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Part A — computation (YA <?= (int) $r['ya'] ?>)</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <tbody>
        <?php if ($r['emp_rows']): ?>
          <tr><td colspan="2"><strong>Employment (s.13)</strong></td></tr>
          <?php foreach ($r['emp_rows'] as $e): ?>
            <tr><td class="muted">&nbsp;&nbsp;<?= e($e['label']) ?><?= $e['exempt']>0?' (less exempt RM '.money($e['exempt']).')':'' ?></td>
              <td style="text-align:right">RM <?= money($e['taxable']) ?></td></tr>
          <?php endforeach; ?>
          <tr><td>Statutory employment income</td><td style="text-align:right"><strong>RM <?= money($r['emp_si']) ?></strong></td></tr>
        <?php endif; ?>
        <?php foreach ($r['biz_rows'] as $b): ?>
          <tr><td class="muted">Business — <?= e($b['name']) ?> (gross <?= money($b['gross']) ?> − exp <?= money($b['expenses']) ?> − CA <?= money($b['ca']) ?>)</td>
            <td style="text-align:right">RM <?= money($b['statutory']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($r['biz_si']>0): ?><tr><td>Statutory business income</td><td style="text-align:right"><strong>RM <?= money($r['biz_si']) ?></strong></td></tr><?php endif; ?>
        <?php foreach ($r['rent_rows'] as $rr): ?>
          <tr><td class="muted">Rental — <?= e($rr['name']) ?> (<?= $rr['type']==='4a'?'s.4(a) active':'s.4(d) passive' ?>)</td>
            <td style="text-align:right">RM <?= money($rr['net']) ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($r['other_rows'] as $o): ?>
          <tr><td class="muted">Other — <?= e($o['label']) ?></td><td style="text-align:right">RM <?= money($o['amount']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($r['biz_loss']>0): ?><tr><td class="muted">Less current-year business loss (s.44(2))</td><td style="text-align:right">−RM <?= money($r['biz_loss']) ?></td></tr><?php endif; ?>
        <tr><td><strong>Aggregate income</strong></td><td style="text-align:right"><strong>RM <?= money($r['aggregate']) ?></strong></td></tr>
        <?php if ($r['donations']>0): ?><tr><td class="muted">Less approved donations (cap 10% = RM <?= money($r['don_cap']) ?>)</td><td style="text-align:right">−RM <?= money($r['donations']) ?></td></tr><?php endif; ?>
        <tr><td><strong>Total income</strong></td><td style="text-align:right"><strong>RM <?= money($r['total']) ?></strong></td></tr>
        <tr><td>Less reliefs (self RM <?= money($r['self_relief']) ?> + child RM <?= money($r['child_relief']) ?> + claimed)</td>
          <td style="text-align:right">−RM <?= money($r['relief_before']) ?></td></tr>
        <tr><td><strong>Chargeable income</strong></td><td style="text-align:right"><strong>RM <?= money($r['chargeable']) ?></strong></td></tr>
        <tr><td class="muted">Tax per resident graduated schedule</td><td style="text-align:right">RM <?= money($r['gross_tax']) ?></td></tr>
        <?php if ($r['rebate'] > 0): ?><tr><td class="muted">Less: individual rebate (s.6A)</td><td style="text-align:right">−RM <?= money($r['rebate']) ?></td></tr><?php endif; ?>
        <?php if ($r['zakat_applied'] > 0): ?><tr><td class="muted">Less: zakat rebate (s.6A(3))</td><td style="text-align:right">−RM <?= money($r['zakat_applied']) ?></td></tr><?php endif; ?>
        <tr><td><strong>Tax payable</strong></td><td style="text-align:right"><strong>RM <?= money($r['tax_payable']) ?></strong></td></tr>
      </tbody>
    </table>
    <div class="card-os-body"><div class="disclaimer">Itemised estimate following
      the ITA 1967 sequence, using the system's YA <?= (int) $r['ya'] ?> rates &amp; relief caps.
      Verify classifications (perquisites/BIK, leave passage, s.4(a) vs s.4(d) rental,
      pre-letting expenses) and documents with a licensed tax agent.</div></div>
  </div>
</div>
<?php endif; ?>

<!-- ===================== FORM ===================== -->
<form method="post">
  <?= csrf_field() ?>
  <div class="grid cols-2">
    <div class="card-os">
      <div class="card-os-head">Employment income (s.13) — line items</div>
      <div class="card-os-body" style="padding:0">
        <table class="table-os"><thead><tr><th>Description</th><th>Amount</th><th>Exempt</th></tr></thead><tbody>
          <?php foreach (blanks($r['emp_rows']) as $e): ?>
            <tr><td><input name="emp_label[]" value="<?= e($e['label'] ?? '') ?>" placeholder="Salary / allowance / perquisite / BIK" style="width:100%"></td>
              <td><input type="number" step="0.01" name="emp_amount[]" value="<?= e($e['amount'] ?? '') ?>" style="width:110px"></td>
              <td><input type="number" step="0.01" name="emp_exempt[]" value="<?= e($e['exempt'] ?? '') ?>" style="width:100px"></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <p class="muted" style="font-size:12px;padding:8px 14px;margin:0">Cash allowances are taxable; put accountable reimbursements / leave-passage exemption in “Exempt”.</p>
      </div>
    </div>

    <div class="card-os">
      <div class="card-os-head">Business income (s.4(a))</div>
      <div class="card-os-body" style="padding:0">
        <table class="table-os"><thead><tr><th>Business</th><th>Gross</th><th>Expenses</th><th>Cap. allow.</th></tr></thead><tbody>
          <?php foreach (blanks($r['biz_rows']) as $b): ?>
            <tr><td><input name="biz_name[]" value="<?= e($b['name'] ?? '') ?>" placeholder="e.g. Consultancy" style="width:100%"></td>
              <td><input type="number" step="0.01" name="biz_gross[]" value="<?= e($b['gross'] ?? '') ?>" style="width:100px"></td>
              <td><input type="number" step="0.01" name="biz_exp[]" value="<?= e($b['expenses'] ?? '') ?>" style="width:100px"></td>
              <td><input type="number" step="0.01" name="biz_ca[]" value="<?= e($b['ca'] ?? '') ?>" style="width:100px"></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>

    <div class="card-os">
      <div class="card-os-head">Rental</div>
      <div class="card-os-body" style="padding:0">
        <table class="table-os"><thead><tr><th>Property</th><th>Gross</th><th>Expenses</th><th>Basis</th></tr></thead><tbody>
          <?php foreach (blanks($r['rent_rows']) as $rr): ?>
            <tr><td><input name="rent_name[]" value="<?= e($rr['name'] ?? '') ?>" placeholder="Property / Airbnb" style="width:100%"></td>
              <td><input type="number" step="0.01" name="rent_gross[]" value="<?= e($rr['gross'] ?? '') ?>" style="width:100px"></td>
              <td><input type="number" step="0.01" name="rent_exp[]" value="<?= e($rr['expenses'] ?? '') ?>" style="width:100px"></td>
              <td><select name="rent_type[]">
                <option value="4d" <?= ($rr['type'] ?? '4d')==='4d'?'selected':'' ?>>s.4(d) passive</option>
                <option value="4a" <?= ($rr['type'] ?? '')==='4a'?'selected':'' ?>>s.4(a) active</option>
              </select></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>

    <div class="card-os">
      <div class="card-os-head">Other income &amp; donations</div>
      <div class="card-os-body" style="padding:0">
        <table class="table-os"><thead><tr><th>Other income</th><th>Amount</th></tr></thead><tbody>
          <?php foreach (blanks($r['other_rows']) as $o): ?>
            <tr><td><input name="oth_label[]" value="<?= e($o['label'] ?? '') ?>" placeholder="Dividend / interest / royalty" style="width:100%"></td>
              <td><input type="number" step="0.01" name="oth_amount[]" value="<?= e($o['amount'] ?? '') ?>" style="width:120px"></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <div class="card-os-body">
          <div class="form-row"><label>Approved donations (RM) — auto-capped at 10% of aggregate</label>
            <input type="number" step="0.01" name="donations" value="<?= e($in['donations']) ?>"></div>
          <div class="form-row"><label>Zakat paid (RM) — rebate against tax payable (s.6A(3))</label>
            <input type="number" step="0.01" name="zakat" value="<?= e($in['zakat'] ?? 0) ?>"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card-os" style="margin-top:18px">
    <div class="card-os-head">Reliefs</div>
    <div class="card-os-body">
      <div class="form-grid">
        <div class="form-row"><label>Children under 18 (× RM<?= number_format(my_child_relief()) ?>)</label>
          <input type="number" name="children_u18" value="<?= e($in['reliefs']['children_u18'] ?? 0) ?>"></div>
        <div class="form-row"><label>Children 18+ tertiary (× RM<?= number_format(my_child_tertiary_relief()) ?>)</label>
          <input type="number" name="children_tertiary" value="<?= e($in['reliefs']['children_tertiary'] ?? 0) ?>"></div>
        <div class="form-row"><label>Disabled children (× RM<?= number_format(my_disabled_child_relief()) ?>)</label>
          <input type="number" name="disabled_u18" value="<?= e($in['reliefs']['disabled_u18'] ?? 0) ?>"></div>
        <div class="form-row"><label>Disabled children 18+ tertiary (× RM<?= number_format(my_disabled_child_tertiary_relief()) ?>)</label>
          <input type="number" name="disabled_tertiary" value="<?= e($in['reliefs']['disabled_tertiary'] ?? 0) ?>"></div>
      </div>
      <p class="muted" style="font-size:12px;margin:4px 0 8px">Self &amp; dependent relief RM<?= number_format(my_self_relief()) ?> applied automatically.</p>
      <?php foreach ($grouped as $group => $items): ?>
        <div class="muted" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:10px 0 6px;color:var(--navy)"><?= e($group) ?></div>
        <div class="form-grid">
          <?php foreach ($items as $k => [$label, $cap]): ?>
            <div class="form-row"><label><?= e($label) ?> <span class="muted">(cap RM<?= e($cap) ?>)</span></label>
              <input type="number" step="0.01" name="r_<?= e($k) ?>" value="<?= e($in['reliefs']['r_' . $k] ?? 0) ?>"></div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <button class="btn-os gold" style="margin-top:12px">Save &amp; recompute</button>
      <span class="muted" style="font-size:12px;margin-left:10px">Empty rows are ignored; re-save to add more lines.</span>
    </div>
  </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
