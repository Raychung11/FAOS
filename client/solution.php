<?php
/** AdvisorOS — Client portal: read-only solution view (document-style for Tax). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';
require_once __DIR__ . '/../includes/solutions.php';
require_once __DIR__ . '/../includes/corptax.php';
require_once __DIR__ . '/../includes/tax_compute.php';
require_once __DIR__ . '/../includes/tax_planb.php';

$c   = portal_client();
$pdo = db();
$tid = (int) $c['tenant_id'];
$key = preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['key'] ?? ''));
$cat = solution_catalog();

if (!isset($cat[$key])) {
    http_response_code(404); exit('Solution not available.');
}
$s = $cat[$key];

$pageTitle = $s['name'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  @media print { .sidebar,.topbar,.noprint { display:none !important; }
    .content,.main { margin:0 !important; padding:0 !important; } }
  .rep h3 { color:var(--navy); margin:22px 0 8px; font-size:17px }
  .rep h4 { color:var(--navy); margin:14px 0 6px; font-size:14px; font-weight:600 }
  .rep p  { line-height:1.6; margin:0 0 8px; font-size:14px }
  .rep ul { margin:0 0 10px; padding-left:20px; line-height:1.7; font-size:14px }
  .rep .tnum { text-align:right; white-space:nowrap }
</style>

<?php if (!$s['active']): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0;color:var(--navy)"><?= e($s['name']) ?>
        <span class="badge-os b-scheduled" style="font-size:13px">Coming soon</span></h2>
      <span class="muted"><?= e($s['tagline']) ?></span>
    </div>
    <a class="btn-os ghost sm noprint" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
  </div>
  <div class="card-os"><div class="card-os-head">What this will do for you</div>
    <div class="card-os-body">
      <ul style="margin:0 0 12px;padding-left:18px;line-height:1.8">
        <?php foreach ($s['benefits'] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?>
      </ul>
      <div class="alert-os info">This solution is coming soon. Speak to your
        adviser if you'd like to be among the first to use it.</div>
    </div>
  </div>
<?php require __DIR__ . '/../includes/footer.php'; return; endif;

$eng = solution_get((int) $c['id'], $key);
[$slbl, $scls] = SOLUTION_STATUSES[$eng['status']];

if ($eng['status'] === 'none'):
?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
    <h2 style="margin:0;color:var(--navy)"><?= e($s['name']) ?></h2>
    <a class="btn-os ghost sm noprint" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
  </div>
  <div class="alert-os info">Your adviser hasn't prepared this solution yet.</div>
<?php require __DIR__ . '/../includes/footer.php'; return; endif;

/* ===================================================================
 * TAX — document-style report when an itemised computation exists.
 * =================================================================== */
if ($key === 'tax' && taxcomp_exists((int) $c['id'])):
    $r        = tax_compute(taxcomp_get((int) $c['id']));
    $rebate   = my_rebate($r['chargeable']);
    $grossTax = my_tax_on($r['chargeable']);

    // Data-driven assumptions list (informative + honest).
    $assumptions = ['Malaysian tax resident for YA ' . (int) $r['ya'] . '; resident graduated rates and reliefs apply.'];
    foreach ($r['rent_rows'] as $rr) {
        if ($rr['type'] === '4a') {
            $assumptions[] = '"' . $rr['name'] . '" treated as business income (s.4(a)) — assumes hotel-like services (housekeeping, breakfast, etc.).';
            break;
        }
    }
    foreach ($r['emp_rows'] as $er) {
        if ($er['exempt'] > 0) {
            $assumptions[] = 'Leave-passage exemption (RM ' . money($er['exempt']) . ') applied — assumes employer-provided passage.';
            break;
        }
    }
    $rIn = $r['relief_rows'];
    $find = fn (string $k) => array_values(array_filter($rIn, fn ($x) => $x['key'] === $k))[0] ?? null;
    if (taxcomp_get((int) $c['id'])['reliefs']['disabled_u18'] ?? 0) {
        $assumptions[] = 'Disabled-child relief claimed — assumes valid OKU / medical certification on file.';
    }
    if (($cc = $find('childcare')) && $cc['claimed'] > 0) {
        $assumptions[] = 'Childcare / kindergarten relief claimed — assumes the child is aged 6 and below at a registered TASKA / TADIKA.';
    }
    if (($sp = $find('spouse')) && $sp['claimed'] > 0) {
        $assumptions[] = 'Alimony / spouse relief claimed — assumes a formal / enforceable separation agreement or court order.';
    }
    if (($r['zakat'] ?? 0) > 0) {
        $assumptions[] = 'Zakat rebate claimed — assumes a valid receipt from an authorised collection centre (PPZ or state-equivalent).';
    }
?>
<div class="rep">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
    <div>
      <h2 style="margin:0;color:var(--navy)">Tax Computation &amp; Planning Report</h2>
      <div class="muted" style="font-size:13px">Malaysia · Year of Assessment <?= (int) $r['ya'] ?> · Client: <strong><?= e($c['full_name']) ?></strong></div>
    </div>
    <div class="noprint" style="display:flex;gap:8px">
      <button class="btn-os sm" onclick="window.print()">Print / Save PDF</button>
      <a class="btn-os ghost sm" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
    </div>
  </div>

  <!-- Summary -->
  <div class="card-os" style="margin-bottom:18px">
    <div class="card-os-head">Summary</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os"><thead><tr><th>Summary item</th><th class="tnum">RM</th></tr></thead><tbody>
        <tr><td>Aggregate / total income</td><td class="tnum"><?= money($r['total']) ?></td></tr>
        <tr><td>Less: personal reliefs</td><td class="tnum">(<?= money($r['relief_before']) ?>)</td></tr>
        <tr><td>Chargeable income</td><td class="tnum"><?= money($r['chargeable']) ?></td></tr>
        <tr><td><strong>Estimated tax payable</strong></td><td class="tnum"><strong>RM <?= money($r['tax_payable']) ?></strong></td></tr>
      </tbody></table>
      <div class="card-os-body"><div class="muted" style="font-size:12.5px">
        <strong>Important.</strong> This report is prepared for advisory discussion using the
        figures captured by your adviser. Final filing must be supported by EA Form,
        receipts, contracts, insurance and SSPN statements, medical certificates and
        current LHDN guidance.
      </div></div>
    </div>
  </div>

  <!-- 1. Assumptions -->
  <h3>1. Assumptions</h3>
  <ul><?php foreach ($assumptions as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>

  <!-- 2. Framework -->
  <h3>2. Technical framework</h3>
  <p><strong>Employment.</strong> Taxed under ss.4(b) and 13(1) ITA 1967 — salary, allowances,
    perquisites and benefits-in-kind are taxable unless a specific exemption applies.</p>
  <p><strong>Business (s.4(a)).</strong> Deductions must be wholly and exclusively incurred
    (s.33(1)); capital expenditure is not deductible but qualifying assets may attract
    capital allowance. Current-year losses are set off against aggregate income (s.44(2)).</p>
  <p><strong>Rental.</strong> Passive letting is s.4(d). Where letting is conducted in a
    business-like manner with material services (housekeeping, breakfast), it may be
    treated as business income under s.4(a).</p>
  <p><strong>Reliefs &amp; sequence.</strong> Statutory income per source → aggregate income
    (less losses) → total income (less approved donations, capped 10%) → less reliefs →
    chargeable income → tax per schedule → less rebates → tax payable.</p>

  <h2 style="color:var(--navy);margin-top:24px">Part A — Full Tax Computation for YA <?= (int) $r['ya'] ?></h2>

  <?php if ($r['emp_rows']): ?>
  <h3>3. Employment income</h3>
  <table class="table-os">
    <thead><tr><th>Item</th><th class="tnum">RM</th><th>Treatment</th></tr></thead><tbody>
      <?php foreach ($r['emp_rows'] as $e): ?>
        <tr><td><?= e($e['label']) ?></td>
          <td class="tnum"><?= money($e['amount']) ?></td>
          <td><?= $e['exempt'] > 0 ? 'Partly exempt RM ' . money($e['exempt']) . '; taxable RM ' . money($e['taxable']) : 'Fully taxable' ?></td></tr>
      <?php endforeach; ?>
      <tr><td><strong>Statutory employment income</strong></td>
        <td class="tnum"><strong><?= money($r['emp_si']) ?></strong></td><td></td></tr>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($r['biz_rows']): ?>
  <h3>4. Business income (s.4(a))</h3>
  <?php foreach ($r['biz_rows'] as $i => $b): ?>
    <h4>4.<?= $i + 1 ?> <?= e($b['name']) ?></h4>
    <table class="table-os"><tbody>
      <tr><td>Gross business income</td><td class="tnum"><?= money($b['gross']) ?></td></tr>
      <tr><td>Less: deductible expenses</td><td class="tnum">(<?= money($b['expenses']) ?>)</td></tr>
      <tr><td>Adjusted income</td><td class="tnum"><?= money($b['adjusted']) ?></td></tr>
      <?php if ($b['ca'] > 0): ?><tr><td>Less: capital allowance</td><td class="tnum">(<?= money($b['ca']) ?>)</td></tr><?php endif; ?>
      <tr><td><strong>Statutory income</strong></td><td class="tnum"><strong><?= money($b['statutory']) ?></strong></td></tr>
    </tbody></table>
  <?php endforeach; ?>
  <table class="table-os"><tbody>
    <tr><td><strong>Total statutory business income</strong></td>
      <td class="tnum"><strong><?= money($r['biz_si']) ?></strong></td></tr>
    <?php if ($r['biz_loss'] > 0): ?><tr><td class="muted">Current-year business loss (set off below)</td>
      <td class="tnum">RM <?= money($r['biz_loss']) ?></td></tr><?php endif; ?>
  </tbody></table>
  <?php endif; ?>

  <?php if ($r['rent_rows']): ?>
  <h3>5. Rental income</h3>
  <table class="table-os">
    <thead><tr><th>Property</th><th>Basis</th><th class="tnum">Gross</th><th class="tnum">Expenses</th><th class="tnum">Net</th></tr></thead>
    <tbody>
      <?php foreach ($r['rent_rows'] as $rr): ?>
        <tr><td><?= e($rr['name']) ?></td>
          <td><?= $rr['type'] === '4a' ? 's.4(a) active business' : 's.4(d) passive' ?></td>
          <td class="tnum"><?= money($rr['gross']) ?></td>
          <td class="tnum">(<?= money($rr['expenses']) ?>)</td>
          <td class="tnum"><?= money($rr['net']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($r['other_rows']): ?>
  <h3>6. Other income</h3>
  <table class="table-os"><tbody>
    <?php foreach ($r['other_rows'] as $o): ?>
      <tr><td><?= e($o['label']) ?></td><td class="tnum"><?= money($o['amount']) ?></td></tr>
    <?php endforeach; ?>
    <tr><td><strong>Total other income</strong></td><td class="tnum"><strong><?= money($r['other']) ?></strong></td></tr>
  </tbody></table>
  <?php endif; ?>

  <h3>7. Aggregate &amp; total income</h3>
  <table class="table-os"><tbody>
    <?php if ($r['emp_si'] > 0): ?><tr><td>Statutory employment income</td><td class="tnum"><?= money($r['emp_si']) ?></td></tr><?php endif; ?>
    <?php if ($r['biz_si'] > 0): ?><tr><td>Statutory business income</td><td class="tnum"><?= money($r['biz_si']) ?></td></tr><?php endif; ?>
    <?php if ($r['rent_active'] > 0): ?><tr><td>Active rental income (s.4(a))</td><td class="tnum"><?= money($r['rent_active']) ?></td></tr><?php endif; ?>
    <?php if ($r['rent_passive'] > 0): ?><tr><td>Passive rental income (s.4(d))</td><td class="tnum"><?= money($r['rent_passive']) ?></td></tr><?php endif; ?>
    <?php if ($r['other'] > 0): ?><tr><td>Other income</td><td class="tnum"><?= money($r['other']) ?></td></tr><?php endif; ?>
    <?php if ($r['biz_loss'] > 0): ?><tr><td>Less: current-year business loss (s.44(2))</td><td class="tnum">(<?= money($r['biz_loss']) ?>)</td></tr><?php endif; ?>
    <tr><td><strong>Aggregate income</strong></td><td class="tnum"><strong><?= money($r['aggregate']) ?></strong></td></tr>
    <?php if ($r['donations'] > 0): ?><tr><td>Less: approved donations (capped 10% = RM <?= money($r['don_cap']) ?>)</td><td class="tnum">(<?= money($r['donations']) ?>)</td></tr><?php endif; ?>
    <tr><td><strong>Total income</strong></td><td class="tnum"><strong><?= money($r['total']) ?></strong></td></tr>
  </tbody></table>

  <h3>8. Personal reliefs</h3>
  <table class="table-os">
    <thead><tr><th>Item</th><th class="tnum">RM</th><th>Note</th></tr></thead><tbody>
      <tr><td>Individual &amp; dependent relatives</td><td class="tnum"><?= money($r['self_relief']) ?></td><td>Automatic</td></tr>
      <?php if ($r['child_relief'] > 0): ?>
        <tr><td>Child relief (per child counts)</td><td class="tnum"><?= money($r['child_relief']) ?></td><td>Ordinary / tertiary / disabled as applicable</td></tr>
      <?php endif; ?>
      <?php foreach ($r['relief_rows'] as $rr): if ($rr['claimed'] <= 0) { continue; } ?>
        <tr><td><?= e($rr['label']) ?></td>
          <td class="tnum"><?= money($rr['claimed']) ?></td>
          <td>cap RM <?= money($rr['cap']) ?><?= $rr['room'] > 0 ? '; room RM ' . money($rr['room']) : '; maxed' ?></td></tr>
      <?php endforeach; ?>
      <tr><td><strong>Total personal reliefs</strong></td><td class="tnum"><strong><?= money($r['relief_before']) ?></strong></td><td></td></tr>
    </tbody>
  </table>

  <h3>9. Chargeable income &amp; tax payable</h3>
  <table class="table-os"><tbody>
    <tr><td>Total income</td><td class="tnum"><?= money($r['total']) ?></td></tr>
    <tr><td>Less: personal reliefs</td><td class="tnum">(<?= money($r['relief_before']) ?>)</td></tr>
    <tr><td><strong>Chargeable income</strong></td><td class="tnum"><strong><?= money($r['chargeable']) ?></strong></td></tr>
    <tr><td>Tax per resident graduated schedule</td><td class="tnum"><?= money($grossTax) ?></td></tr>
    <?php if ($rebate > 0): ?><tr><td>Less: individual rebate (s.6A)</td><td class="tnum">(<?= money($rebate) ?>)</td></tr><?php endif; ?>
    <?php if (($r['zakat_applied'] ?? 0) > 0): ?><tr><td>Less: zakat rebate (s.6A(3))</td><td class="tnum">(<?= money($r['zakat_applied']) ?>)</td></tr><?php endif; ?>
    <tr><td><strong>Estimated tax payable</strong></td><td class="tnum"><strong>RM <?= money($r['tax_payable']) ?></strong></td></tr>
  </tbody></table>
  <p style="margin-top:10px"><strong>Conclusion (Part A).</strong> Your estimated Malaysian income
    tax payable for YA <?= (int) $r['ya'] ?> is <strong>RM <?= money($r['tax_payable']) ?></strong>,
    at an effective rate of <?= round($r['eff_rate'] * 100, 1) ?>%.
    If eligible reliefs are fully utilised, tax would be ≈ RM <?= money($r['tax_after']) ?>
    (annual saving ≈ <span style="color:var(--ok);font-weight:600">RM <?= money($r['saving']) ?></span>).</p>

  <h2 style="color:var(--navy);margin-top:24px">Part B — Tax Planning &amp; Optimisation</h2>

  <?php if ($eng['est_saving'] > 0 || $eng['fee'] > 0 || trim($eng['scope']) !== ''): ?>
  <div class="card-os" style="margin-bottom:14px"><div class="card-os-body" style="padding:14px 18px">
    <strong>Engagement:</strong> <?= e($slbl) ?>
    <?php if ($eng['est_saving'] > 0): ?> · <strong>Estimated annual value:</strong> RM <?= money($eng['est_saving']) ?><?php endif; ?>
    <?php if ($eng['fee'] > 0): ?> · <strong>Fee:</strong> RM <?= money($eng['fee']) ?><?php endif; ?>
    <?php if (trim($eng['scope']) !== ''): ?>
      <div style="margin-top:6px;white-space:pre-line"><strong>Scope of work.</strong> <?= e($eng['scope']) ?></div>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <?php
    $aiText = trim($eng['plan_report']) !== '' ? $eng['plan_report'] : $eng['report'];
    $aiCommentary = $aiText !== '' ? tax_planb_parse_ai($aiText) : [];
    $sections = tax_planb_build($r, $eng, $aiCommentary);
    $secNum = 9;
  ?>
  <?php foreach ($sections as $sec): ?>
    <h3><?= $secNum ?>. <?= e($sec['title']) ?></h3>
    <?php if ($sec['type'] === 'table'): ?>
      <table class="table-os">
        <thead><tr><?php foreach ($sec['cols'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($sec['rows'] as $row): ?>
            <tr><?php foreach ($row as $cell): ?><td><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <?php if (!$sec['rows']): ?><tr><td colspan="<?= count($sec['cols']) ?>" class="muted">Nothing to report in this section.</td></tr><?php endif; ?>
        </tbody>
      </table>
    <?php else: ?>
      <ul><?php foreach ($sec['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (!empty($sec['commentary']) && trim($sec['commentary']) !== '' && strcasecmp(trim($sec['commentary']), 'Not applicable.') !== 0): ?>
      <div class="card-os" style="margin:6px 0 14px;background:#fbfbf3"><div class="card-os-body" style="padding:12px 16px">
        <div class="muted" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--navy);margin-bottom:6px">Adviser commentary</div>
        <p style="margin:0;font-size:14px;line-height:1.6;white-space:pre-line"><?= e($sec['commentary']) ?></p>
      </div></div>
    <?php endif; ?>
    <?php $secNum++; endforeach; ?>

  <div class="card-os" style="margin-top:18px"><div class="card-os-body">
    <div class="disclaimer">This report is an indicative estimate for advisory
      discussion — not tax, legal or investment advice. Final implementation must
      be reviewed and approved by your licensed tax agent / financial adviser.</div>
  </div></div>
</div>
<?php else: /* Non-tax solutions, or tax without itemised computation: simpler view */ ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)"><?= e($s['name']) ?>
      <span class="badge-os <?= $scls ?>" style="font-size:13px"><?= e($slbl) ?></span></h2>
    <span class="muted"><?= e($s['tagline']) ?></span>
  </div>
  <a class="btn-os ghost sm noprint" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
</div>

<?php if ($key === 'tax'): $imp = tax_impact($pdo, $tid, (int) $c['id']);
      $afterW = $imp['before_total'] > 0 ? max(2, $imp['after_total'] / $imp['before_total'] * 100) : 0; ?>
  <div class="card-os" style="margin-bottom:18px">
    <div class="card-os-head">Your tax — before vs after our plan</div>
    <div class="card-os-body">
      <div class="grid cols-3" style="margin-bottom:16px">
        <div class="stat"><div class="stat-label">Tax now</div>
          <div class="stat-value">RM <?= money($imp['before_total']) ?></div>
          <div class="stat-foot">per year</div></div>
        <div class="stat"><div class="stat-label">Tax after our plan</div>
          <div class="stat-value">RM <?= money($imp['after_total']) ?></div>
          <div class="stat-foot">reliefs maximised + efficient extraction</div></div>
        <div class="stat accent"><div class="stat-label">You could save</div>
          <div class="stat-value" style="color:var(--ok)">RM <?= money($imp['saving']) ?></div>
          <div class="stat-foot"><?= round($imp['pct'], 1) ?>% lower, every year</div></div>
      </div>
      <div class="muted" style="font-size:12px;margin-bottom:4px">Before — RM <?= money($imp['before_total']) ?></div>
      <div style="height:26px;border-radius:8px;background:var(--danger);color:#fff;
           display:flex;align-items:center;padding:0 12px;font-weight:700;font-size:13px;margin-bottom:10px">RM <?= money($imp['before_total']) ?></div>
      <div class="muted" style="font-size:12px;margin-bottom:4px">After — RM <?= money($imp['after_total']) ?></div>
      <div style="height:26px;width:<?= $afterW ?>%;border-radius:8px;background:var(--ok);color:#fff;
           display:flex;align-items:center;padding:0 12px;font-weight:700;font-size:13px">RM <?= money($imp['after_total']) ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">What this does for you</div>
    <div class="card-os-body">
      <p style="margin:0 0 10px;color:var(--navy);font-weight:600"><?= e($s['tagline']) ?></p>
      <ul style="margin:0;padding-left:18px;line-height:1.8">
        <?php foreach ($s['benefits'] as $b): ?><li><?= e($b) ?></li><?php endforeach; ?>
      </ul>
    </div>
  </div>
  <div class="card-os">
    <div class="card-os-head">What we'll do</div>
    <div class="card-os-body">
      <?php if (trim($eng['scope']) !== ''): ?>
        <p style="white-space:pre-line;margin:0"><?= e($eng['scope']) ?></p>
      <?php else: ?>
        <p class="muted" style="margin:0">Your adviser will confirm the detailed scope with you.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px"><div class="card-os-body">
  <div class="disclaimer">Figures are indicative estimates prepared for
    discussion — not tax, legal or investment advice. Your licensed adviser
    will confirm the details and any implementation with you.</div>
</div></div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
