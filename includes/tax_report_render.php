<?php
/**
 * AdvisorOS — Tax Report HTML renderer (print/PDF deliverable).
 * Produces a self-contained HTML document for the case-study style
 * tax computation + planning report. Used by advisor/tax-pdf.php to
 * stream a PDF (DomPDF when installed, browser Print-to-PDF otherwise).
 */

declare(strict_types=1);

require_once __DIR__ . '/tax_compute.php';
require_once __DIR__ . '/tax_planb.php';

/** Data-driven assumptions list — same conditions as the on-screen report. */
function tax_report_assumptions(array $r): array
{
    $a = ['Malaysian tax resident for YA ' . (int) $r['ya'] . '; resident graduated rates and reliefs apply.'];
    foreach ($r['rent_rows'] as $rr) {
        if ($rr['type'] === '4a') {
            $a[] = '"' . $rr['name'] . '" treated as business income (s.4(a)) — assumes hotel-like services.';
            break;
        }
    }
    foreach ($r['emp_rows'] as $er) {
        if ($er['exempt'] > 0) {
            $a[] = 'Leave-passage exemption (RM ' . money($er['exempt']) . ') applied — assumes employer-provided passage.';
            break;
        }
    }
    if (($r['child_counts']['disabled_u18'] ?? 0) + ($r['child_counts']['disabled_tertiary'] ?? 0) > 0) {
        $a[] = 'Disabled-child relief claimed — assumes valid OKU / medical certification on file.';
    }
    foreach ($r['relief_rows'] as $rr) {
        if (($rr['key'] ?? '') === 'childcare' && $rr['claimed'] > 0) {
            $a[] = 'Childcare / kindergarten relief claimed — assumes child aged 6 and below at registered TASKA / TADIKA.';
        }
        if (($rr['key'] ?? '') === 'spouse' && $rr['claimed'] > 0) {
            $a[] = 'Alimony / spouse relief claimed — assumes a formal / enforceable separation agreement or court order.';
        }
    }
    if (($r['zakat'] ?? 0) > 0) {
        $a[] = 'Zakat rebate claimed — assumes valid receipt from authorised collection centre.';
    }
    if (($r['assessment']['type'] ?? 'separate') === 'joint') {
        $a[] = 'Joint assessment elected under s.45(2) — spouse income combined with the principal.';
    } elseif (($r['assessment']['spouse_income'] ?? 0) > 0) {
        $a[] = 'Spouse has income; assessment is separate. Joint-vs-separate comparison is shown.';
    }
    return $a;
}

/** Full standalone HTML for the printable / PDF tax report. */
function render_tax_report_html(array $client, array $r, array $eng, array $brand): string
{
    $primary = $brand['primary'] ?? '#0B1F3A';
    $accent  = $brand['accent']  ?? '#C9A227';
    $firm    = $brand['name']    ?? APP_NAME;
    $rebate  = (float) ($r['rebate'] ?? 0);

    $aiText = trim((string) ($eng['plan_report'] ?? ''));
    if ($aiText === '') { $aiText = (string) ($eng['report'] ?? ''); }
    $comm = $aiText !== '' ? tax_planb_parse_ai($aiText) : [];
    $sections = tax_planb_build($r, $eng, $comm);
    $assumptions = tax_report_assumptions($r);

    ob_start();
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>Tax Report — <?= e($client['full_name']) ?> (YA <?= (int) $r['ya'] ?>)</title>
<style>
  @page { margin: 16mm 14mm; }
  body { font: 10.5pt/1.45 Helvetica, Arial, sans-serif; color:#1c2433; margin:0; }
  h1 { font-size: 20pt; color:<?= $primary ?>; margin:0 0 2pt; }
  h2 { font-size: 13.5pt; color:<?= $primary ?>; margin:14pt 0 4pt; border-bottom: 1.4pt solid <?= $accent ?>; padding-bottom:2pt; }
  h3 { font-size: 11.5pt; color:<?= $primary ?>; margin:10pt 0 3pt; }
  h4 { font-size: 11pt; color:<?= $primary ?>; margin:8pt 0 3pt; }
  p  { margin: 3pt 0 5pt; }
  table { width:100%; border-collapse: collapse; margin:3pt 0 6pt; font-size:10pt; }
  th, td { border:0.5pt solid #d4d9e0; padding:3.5pt 6pt; vertical-align: top; }
  th { background:#eef2fb; font-weight:600; text-align:left; }
  .tnum { text-align:right; white-space:nowrap; }
  .muted { color:#6b7686; font-size:9pt; }
  .disclaimer { font-size: 9pt; color:#525c70; background:#f7f9fc; border-left:2.5pt solid <?= $accent ?>; padding: 6pt 9pt; margin: 6pt 0; }
  ul { margin: 3pt 0 6pt 16pt; padding:0; }
  li { margin: 1.5pt 0; font-size: 10pt; }
  .commentary { background:#fbfbf3; border-left:2.5pt solid <?= $accent ?>; padding:5pt 9pt; margin:2pt 0 7pt; font-size:9.5pt; }
  .commentary b { color:<?= $primary ?>; }
  .nb { border:0; padding:0; }
  table.nb td { border:0; padding:0; }
</style></head><body>

<table class="nb"><tr>
  <td><h1>Tax Computation &amp; Planning Report</h1>
    <div class="muted">Malaysia · YA <?= (int) $r['ya'] ?> · Client: <strong><?= e($client['full_name']) ?></strong></div></td>
  <td style="text-align:right">
    <div style="font-weight:700;color:<?= $primary ?>;font-size:11pt"><?= e($firm) ?></div>
    <div class="muted">Prepared <?= e(date('d M Y')) ?></div></td>
</tr></table>

<h2>Summary</h2>
<table>
  <tr><th>Summary item</th><th class="tnum">RM</th></tr>
  <tr><td>Aggregate / total income</td><td class="tnum"><?= money($r['total']) ?></td></tr>
  <tr><td>Less: personal reliefs</td><td class="tnum">(<?= money($r['relief_before']) ?>)</td></tr>
  <tr><td>Chargeable income</td><td class="tnum"><?= money($r['chargeable']) ?></td></tr>
  <tr><td><strong>Estimated tax payable</strong></td><td class="tnum"><strong>RM <?= money($r['tax_payable']) ?></strong></td></tr>
</table>
<div class="disclaimer"><strong>Important.</strong> This report is prepared for advisory discussion using the
figures captured by your adviser. Final filing must be supported by EA Form, receipts,
contracts, insurance and SSPN statements, medical certificates and current LHDN guidance.</div>

<h2>1. Assumptions</h2>
<ul><?php foreach ($assumptions as $a): ?><li><?= e($a) ?></li><?php endforeach; ?></ul>

<h2>2. Technical framework</h2>
<p><strong>Employment.</strong> Taxed under ss.4(b) and 13(1) ITA 1967 — salary, allowances, perquisites and benefits-in-kind are taxable unless a specific exemption applies.</p>
<p><strong>Business (s.4(a)).</strong> Deductions must be wholly and exclusively incurred (s.33(1)); capital expenditure is not deductible but qualifying assets may attract capital allowance. Current-year losses are set off against aggregate income (s.44(2)).</p>
<p><strong>Rental.</strong> Passive letting is s.4(d). Where letting is conducted in a business-like manner with material services, it may be treated as business income under s.4(a).</p>
<p><strong>Sequence.</strong> Statutory income → aggregate → total → less reliefs → chargeable → tax per schedule → less rebates → tax payable.</p>

<h2>Part A — Full Tax Computation for YA <?= (int) $r['ya'] ?></h2>

<?php if ($r['emp_rows']): ?>
<h3>3. Employment income</h3>
<table><tr><th>Item</th><th class="tnum">RM</th><th>Treatment</th></tr>
  <?php foreach ($r['emp_rows'] as $e): ?>
    <tr><td><?= e($e['label']) ?></td><td class="tnum"><?= money($e['amount']) ?></td>
      <td><?= $e['exempt'] > 0 ? 'Partly exempt RM ' . money($e['exempt']) . '; taxable RM ' . money($e['taxable']) : 'Fully taxable' ?></td></tr>
  <?php endforeach; ?>
  <tr><td><strong>Statutory employment income</strong></td><td class="tnum"><strong><?= money($r['emp_si']) ?></strong></td><td></td></tr>
</table>
<?php endif; ?>

<?php if ($r['biz_rows']): ?>
<h3>4. Business income (s.4(a))</h3>
<?php foreach ($r['biz_rows'] as $i => $b): ?>
  <h4>4.<?= $i + 1 ?> <?= e($b['name']) ?></h4>
  <table>
    <tr><td>Gross business income</td><td class="tnum"><?= money($b['gross']) ?></td></tr>
    <tr><td>Less: deductible expenses</td><td class="tnum">(<?= money($b['expenses']) ?>)</td></tr>
    <tr><td>Adjusted income</td><td class="tnum"><?= money($b['adjusted']) ?></td></tr>
    <?php if ($b['ca'] > 0): ?><tr><td>Less: capital allowance</td><td class="tnum">(<?= money($b['ca']) ?>)</td></tr><?php endif; ?>
    <tr><td><strong>Statutory income</strong></td><td class="tnum"><strong><?= money($b['statutory']) ?></strong></td></tr>
  </table>
<?php endforeach; ?>
<table>
  <tr><td><strong>Total statutory business income</strong></td><td class="tnum"><strong><?= money($r['biz_si']) ?></strong></td></tr>
  <?php if ($r['biz_loss'] > 0): ?><tr><td>Current-year business loss (set off below)</td><td class="tnum"><?= money($r['biz_loss']) ?></td></tr><?php endif; ?>
</table>
<?php endif; ?>

<?php if ($r['rent_rows']): ?>
<h3>5. Rental income</h3>
<table><tr><th>Property</th><th>Basis</th><th class="tnum">Gross</th><th class="tnum">Expenses</th><th class="tnum">Net</th></tr>
  <?php foreach ($r['rent_rows'] as $rr): ?>
    <tr><td><?= e($rr['name']) ?></td><td><?= $rr['type'] === '4a' ? 's.4(a) active' : 's.4(d) passive' ?></td>
      <td class="tnum"><?= money($rr['gross']) ?></td><td class="tnum">(<?= money($rr['expenses']) ?>)</td>
      <td class="tnum"><?= money($rr['net']) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($r['other_rows']): ?>
<h3>6. Other income</h3>
<table>
  <?php foreach ($r['other_rows'] as $o): ?>
    <tr><td><?= e($o['label']) ?></td><td class="tnum"><?= money($o['amount']) ?></td></tr>
  <?php endforeach; ?>
  <tr><td><strong>Total other income</strong></td><td class="tnum"><strong><?= money($r['other']) ?></strong></td></tr>
</table>
<?php endif; ?>

<h3>7. Aggregate &amp; total income</h3>
<table>
  <?php if ($r['emp_si'] > 0): ?><tr><td>Statutory employment income</td><td class="tnum"><?= money($r['emp_si']) ?></td></tr><?php endif; ?>
  <?php if ($r['biz_si'] > 0): ?><tr><td>Statutory business income</td><td class="tnum"><?= money($r['biz_si']) ?></td></tr><?php endif; ?>
  <?php if ($r['rent_active'] > 0): ?><tr><td>Active rental income (s.4(a))</td><td class="tnum"><?= money($r['rent_active']) ?></td></tr><?php endif; ?>
  <?php if ($r['rent_passive'] > 0): ?><tr><td>Passive rental income (s.4(d))</td><td class="tnum"><?= money($r['rent_passive']) ?></td></tr><?php endif; ?>
  <?php if ($r['other'] > 0): ?><tr><td>Other income</td><td class="tnum"><?= money($r['other']) ?></td></tr><?php endif; ?>
  <?php if ($r['biz_loss'] > 0): ?><tr><td>Less: current-year business loss (s.44(2))</td><td class="tnum">(<?= money($r['biz_loss']) ?>)</td></tr><?php endif; ?>
  <tr><td><strong>Aggregate income</strong></td><td class="tnum"><strong><?= money($r['aggregate']) ?></strong></td></tr>
  <?php if ($r['donations'] > 0): ?><tr><td>Less: approved donations (capped 10% = RM <?= money($r['don_cap']) ?>)</td><td class="tnum">(<?= money($r['donations']) ?>)</td></tr><?php endif; ?>
  <tr><td><strong>Total income</strong></td><td class="tnum"><strong><?= money($r['total']) ?></strong></td></tr>
</table>

<h3>8. Personal reliefs</h3>
<table><tr><th>Item</th><th class="tnum">RM</th><th>Note</th></tr>
  <tr><td>Individual &amp; dependent relatives</td><td class="tnum"><?= money($r['self_relief']) ?></td><td>Automatic</td></tr>
  <?php if ($r['child_relief'] > 0): ?>
    <tr><td>Child relief (per child counts)</td><td class="tnum"><?= money($r['child_relief']) ?></td><td>Ordinary / tertiary / disabled as applicable</td></tr>
  <?php endif; ?>
  <?php foreach ($r['relief_rows'] as $rr): if ($rr['claimed'] <= 0) { continue; } ?>
    <tr><td><?= e($rr['label']) ?></td><td class="tnum"><?= money($rr['claimed']) ?></td>
      <td>cap RM <?= money($rr['cap']) ?><?= $rr['room'] > 0 ? '; room RM ' . money($rr['room']) : '; maxed' ?></td></tr>
  <?php endforeach; ?>
  <tr><td><strong>Total personal reliefs</strong></td><td class="tnum"><strong><?= money($r['relief_before']) ?></strong></td><td></td></tr>
</table>

<h3>9. Chargeable income &amp; tax payable</h3>
<table>
  <tr><td>Total income</td><td class="tnum"><?= money($r['total']) ?></td></tr>
  <tr><td>Less: personal reliefs</td><td class="tnum">(<?= money($r['relief_before']) ?>)</td></tr>
  <tr><td><strong>Chargeable income</strong></td><td class="tnum"><strong><?= money($r['chargeable']) ?></strong></td></tr>
  <tr><td>Tax per resident graduated schedule</td><td class="tnum"><?= money($r['gross_tax']) ?></td></tr>
  <?php if ($rebate > 0): ?><tr><td>Less: individual rebate (s.6A)</td><td class="tnum">(<?= money($rebate) ?>)</td></tr><?php endif; ?>
  <?php if (($r['zakat_applied'] ?? 0) > 0): ?><tr><td>Less: zakat rebate (s.6A(3))</td><td class="tnum">(<?= money($r['zakat_applied']) ?>)</td></tr><?php endif; ?>
  <tr><td><strong>Estimated tax payable</strong></td><td class="tnum"><strong>RM <?= money($r['tax_payable']) ?></strong></td></tr>
</table>

<?php if ($r['assessment']['spouse_income'] > 0 || $r['assessment']['type'] === 'joint'): $as = $r['assessment']; ?>
<h3>Assessment — joint vs separate</h3>
<table><tr><th>Scenario</th><th class="tnum">Principal tax</th><th class="tnum">Spouse tax</th><th class="tnum">Total tax</th></tr>
  <tr><td>Separate filing</td><td class="tnum"><?= money($as['separate_principal_tax']) ?></td>
    <td class="tnum"><?= money($as['separate_spouse_tax']) ?></td>
    <td class="tnum"><strong><?= money($as['separate_total_tax']) ?></strong></td></tr>
  <tr><td>Joint (s.45(2)) — combined on principal</td><td class="tnum">—</td><td class="tnum">—</td>
    <td class="tnum"><strong><?= money($as['joint_tax']) ?></strong></td></tr>
  <tr><td><strong>Recommended</strong></td><td colspan="2"><?= ucfirst($as['recommended']) ?></td>
    <td class="tnum">save ≈ <strong>RM <?= money($as['saving']) ?></strong></td></tr>
</table>
<?php endif; ?>

<p><strong>Conclusion (Part A).</strong> Estimated Malaysian income tax payable for
YA <?= (int) $r['ya'] ?> is <strong>RM <?= money($r['tax_payable']) ?></strong>
(effective <?= round($r['eff_rate'] * 100, 1) ?>%). If eligible reliefs are fully
utilised, tax would be ≈ RM <?= money($r['tax_after']) ?> (annual saving ≈ RM <?= money($r['saving']) ?>).</p>

<h2>Part B — Tax Planning &amp; Optimisation</h2>
<?php $n = 9; foreach ($sections as $sec): ?>
  <h3><?= $n ?>. <?= e($sec['title']) ?></h3>
  <?php if ($sec['type'] === 'table'): ?>
    <table>
      <tr><?php foreach ($sec['cols'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr>
      <?php foreach ($sec['rows'] as $row): ?>
        <tr><?php foreach ($row as $cell): ?><td><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if (!$sec['rows']): ?><tr><td colspan="<?= count($sec['cols']) ?>"><span class="muted">Nothing to report.</span></td></tr><?php endif; ?>
    </table>
  <?php else: ?>
    <ul><?php foreach ($sec['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if (!empty($sec['commentary']) && strcasecmp(trim($sec['commentary']), 'Not applicable.') !== 0): ?>
    <div class="commentary"><b>Adviser commentary.</b> <?= nl2br(e($sec['commentary'])) ?></div>
  <?php endif; ?>
  <?php $n++; endforeach; ?>

<div class="disclaimer">This report is an indicative estimate for advisory discussion —
not tax, legal or investment advice. Final implementation must be reviewed and approved
by the licensed tax agent / financial adviser.</div>

</body></html>
    <?php
    return (string) ob_get_clean();
}
