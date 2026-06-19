<?php
/**
 * AdvisorOS — Valuation Report HTML renderer.
 * Produces a self-contained HTML document of the case-study style
 * Business Valuation report. Used by advisor/valuation-pdf.php to
 * stream a PDF (DomPDF when installed, browser Print-to-PDF otherwise).
 * Mirrors the tax_report_render.php pattern.
 */

declare(strict_types=1);

require_once __DIR__ . '/valuation.php';

/** Body markup only — used by the client portal inside the app shell. */
function render_valuation_body(array $client, array $clientVal): string
{
    ob_start();
    $rows = $clientVal['rows'];
    ?>
    <?php if (!$rows): ?>
      <div class="alert-os info">No companies recorded yet. Once your adviser
        captures them under Business profile, your valuation will appear here.</div>
      <?php return (string) ob_get_clean(); ?>
    <?php endif; ?>
    <div class="grid cols-3" style="margin-bottom:18px">
      <div class="stat accent"><div class="stat-label">Indicative equity value</div>
        <div class="stat-value">RM <?= money($clientVal['total_mid']) ?></div>
        <div class="stat-foot">all companies, weighted &amp; risk-adjusted</div></div>
      <div class="stat accent"><div class="stat-label">Your attributable stake</div>
        <div class="stat-value">RM <?= money($clientVal['total_stake']) ?></div>
        <div class="stat-foot">by ownership %</div></div>
      <div class="stat accent"><div class="stat-label">Key-person discount</div>
        <div class="stat-value"><?= (int) round(valuation_risk_discount($clientVal['worst_risk']) * 100) ?>%</div>
        <div class="stat-foot">from the risk diagnostic</div></div>
    </div>

    <h3>Methodology</h3>
    <p>Three transparent methods are computed for each company on the latest
       business-financial snapshot — <strong>Net Asset Value (NAV)</strong>,
       <strong>EBITDA multiple</strong> (enterprise value less net debt) and a
       <strong>capitalised (Gordon-growth) DCF</strong> on EBITDA or net profit.
       These are blended into an indicative equity figure using
       advisor-configurable weights, then haircut by a
       <strong>key-person / marketability discount</strong> derived from the
       Enterprise Risk Diagnostic. The range (low–high) reflects the spread
       across the three methods after the discount.</p>

    <?php foreach ($rows as $i => $row): $c = $row['company']; $v = $row['val'];
          $hist = valuation_snapshot_history((int) $c['id'], 6); ?>
      <h3><?= $i + 1 ?>. <?= e($c['name']) ?>
        <span class="muted" style="font-weight:400">·
          you own <?= rtrim(rtrim(number_format((float) $c['ownership_pct'], 2), '0'), '.') ?>%</span></h3>

      <?php if (!$v['has_data']): ?>
        <p class="muted">No business financial snapshot on record — this company
           cannot be valued yet.</p>
        <?php continue; ?>
      <?php endif; ?>

      <table class="table-os">
        <thead><tr><th>Method</th><th style="text-align:right">Equity value (RM)</th><th style="text-align:right">Weight</th></tr></thead>
        <tbody>
          <tr><td>Net Asset Value</td>
            <td style="text-align:right"><?= money($v['nav']) ?></td>
            <td style="text-align:right"><?= (int) round($v['weights']['nav'] * 100) ?>%</td></tr>
          <tr><td>EBITDA × multiple (less net debt)</td>
            <td style="text-align:right"><?= money($v['ebitda_equity']) ?></td>
            <td style="text-align:right"><?= (int) round($v['weights']['ebitda'] * 100) ?>%</td></tr>
          <tr><td>Capitalised DCF</td>
            <td style="text-align:right"><?= money($v['dcf_equity']) ?></td>
            <td style="text-align:right"><?= (int) round($v['weights']['dcf'] * 100) ?>%</td></tr>
          <tr><td><strong>Weighted equity (pre-discount)</strong></td>
            <td style="text-align:right"><strong><?= money($v['weighted_pre_discount']) ?></strong></td>
            <td></td></tr>
          <tr><td>Less: key-person / marketability discount</td>
            <td style="text-align:right">−<?= (int) round($v['discount'] * 100) ?>%</td>
            <td></td></tr>
          <tr><td><strong>Indicative equity (mid)</strong></td>
            <td style="text-align:right"><strong>RM <?= money($v['mid']) ?></strong></td>
            <td></td></tr>
          <tr><td>Indicative range (low–high)</td>
            <td style="text-align:right">RM <?= money($v['low']) ?> – <?= money($v['high']) ?></td>
            <td></td></tr>
          <tr><td><strong>Your attributable stake</strong></td>
            <td style="text-align:right"><strong>RM <?= money($v['client_stake']) ?></strong></td>
            <td></td></tr>
        </tbody>
      </table>

      <?php if ($hist): ?>
        <h4>Valuation history</h4>
        <table class="table-os">
          <thead><tr><th>Date</th><th>EBITDA ×</th><th>Discount %</th>
            <th style="text-align:right">Mid (RM)</th>
            <th style="text-align:right">Your stake (RM)</th><th>Saved by</th></tr></thead>
          <tbody>
          <?php foreach ($hist as $h): ?>
            <tr><td><?= e($h['date'] ?? '') ?><?= !empty($h['time']) ? ' ' . e($h['time']) : '' ?></td>
              <td><?= e((float) ($h['assumptions']['multiple'] ?? 0)) ?>×</td>
              <td><?= e((float) ($h['assumptions']['discount'] ?? 0)) ?>%</td>
              <td style="text-align:right"><?= money($h['summary']['mid'] ?? 0) ?></td>
              <td style="text-align:right"><?= money($h['summary']['client_stake'] ?? 0) ?></td>
              <td class="muted" style="font-size:12px"><?= e($h['saved_by'] ?? '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php
    return (string) ob_get_clean();
}

/** Full standalone HTML for the printable / PDF valuation report. */
function render_valuation_report_html(array $client, array $clientVal, array $brand): string
{
    $primary = $brand['primary'] ?? '#0B1F3A';
    $accent  = $brand['accent']  ?? '#C9A227';
    $firm    = $brand['name']    ?? APP_NAME;

    ob_start();
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>Business Valuation — <?= e($client['full_name']) ?></title>
<style>
  @page { margin: 16mm 14mm; }
  body { font: 10.5pt/1.45 Helvetica, Arial, sans-serif; color:#1c2433; margin:0; }
  h1 { font-size: 20pt; color:<?= $primary ?>; margin:0 0 2pt; }
  h2 { font-size: 13.5pt; color:<?= $primary ?>; margin:14pt 0 4pt; border-bottom: 1.4pt solid <?= $accent ?>; padding-bottom:2pt; }
  h3 { font-size: 11.5pt; color:<?= $primary ?>; margin:10pt 0 3pt; }
  h4 { font-size: 10.5pt; color:<?= $primary ?>; margin:8pt 0 3pt; }
  p  { margin: 3pt 0 5pt; }
  table { width:100%; border-collapse: collapse; margin:3pt 0 6pt; font-size:10pt; }
  th, td { border:0.5pt solid #d4d9e0; padding:3.5pt 6pt; vertical-align: top; }
  th { background:#eef2fb; font-weight:600; text-align:left; }
  .tnum { text-align:right; white-space:nowrap; }
  .muted { color:#6b7686; font-size:9pt; }
  .disclaimer { font-size: 9pt; color:#525c70; background:#f7f9fc; border-left:2.5pt solid <?= $accent ?>; padding: 6pt 9pt; margin: 6pt 0; }
  ul { margin: 3pt 0 6pt 16pt; padding:0; }
  li { margin: 1.5pt 0; font-size: 10pt; }
  .nb { border:0; padding:0; } .nb td { border:0; padding:0; }
  .matrix th, .matrix td { font-size: 9pt; padding: 2.5pt 4pt; }
  .matrix .base { background:<?= $accent ?>; color:#1a1405; font-weight:700; }
</style></head><body>

<table class="nb"><tr>
  <td><h1>Business Valuation Report</h1>
    <div class="muted">Malaysia · Client: <strong><?= e($client['full_name']) ?></strong></div></td>
  <td style="text-align:right">
    <div style="font-weight:700;color:<?= $primary ?>;font-size:11pt"><?= e($firm) ?></div>
    <div class="muted">Prepared <?= e(date('d M Y')) ?></div></td>
</tr></table>

<h2>Summary</h2>
<table>
  <tr><th>Summary</th><th class="tnum">RM</th></tr>
  <tr><td>Indicative equity value (all companies, weighted &amp; risk-adjusted)</td>
    <td class="tnum"><?= money($clientVal['total_mid']) ?></td></tr>
  <tr><td>Client's attributable stake (by ownership %)</td>
    <td class="tnum"><?= money($clientVal['total_stake']) ?></td></tr>
  <tr><td>Key-person / marketability discount applied</td>
    <td class="tnum"><?= (int) round(valuation_risk_discount($clientVal['worst_risk']) * 100) ?>%</td></tr>
  <tr><td>Companies valued</td>
    <td class="tnum"><?= (int) $clientVal['companies'] ?></td></tr>
</table>
<div class="disclaimer"><strong>Important.</strong> This report is an indicative
valuation for advisory discussion only — not a formal/independent valuation,
audit, fairness opinion or transaction document. Engage a licensed valuer for any
binding purpose. Figures derived from captured book values and advisor-set
assumptions; methodology and limitations are explained below.</div>

<h2>1. Methodology</h2>
<p><strong>Three methods.</strong> Each company is valued under
<em>(i) Net Asset Value (NAV) </em>= total assets − total liabilities,
<em>(ii) EBITDA multiple</em> = EBITDA × industry-calibrated multiple, less net debt,
and <em>(iii) capitalised DCF</em> = base earnings × (1 + g) / (d − g), less net debt
(Gordon growth on EBITDA or net profit).</p>
<p><strong>Weighted blend.</strong> The three method values are combined using
advisor-configurable weights to produce a pre-discount equity figure. The low and
high range reflect the minimum and maximum method values.</p>
<p><strong>Key-person / marketability discount.</strong> A 5%–35% haircut is
applied to the blended value based on the worst-band score from the Enterprise
Risk Diagnostic (Low 5% → Critical 35%).</p>
<p><strong>Net debt.</strong> Bank loans + shareholder loans − cash, floored at
zero.</p>

<h2>2. Per-company valuation</h2>

<?php foreach ($clientVal['rows'] as $i => $row): $c = $row['company']; $v = $row['val'];
      $a = valuation_assumptions((int) $c['id']);
      $hist = valuation_snapshot_history((int) $c['id'], 8); ?>
<h3>2.<?= $i + 1 ?> <?= e($c['name']) ?>
  <span class="muted">· ownership <?= rtrim(rtrim(number_format((float) $c['ownership_pct'], 2), '0'), '.') ?>%</span>
</h3>

<?php if (!$v['has_data']): ?>
  <p><em>No business financial snapshot — cannot be valued.</em></p>
  <?php continue; ?>
<?php endif; ?>

<table>
  <tr><th>Method</th><th class="tnum">Equity value (RM)</th><th class="tnum">Weight</th></tr>
  <tr><td>Net Asset Value</td><td class="tnum"><?= money($v['nav']) ?></td>
    <td class="tnum"><?= (int) round($v['weights']['nav'] * 100) ?>%</td></tr>
  <tr><td>EBITDA × <?= e($a['multiple']) ?> (EV RM <?= money($v['ebitda_ev']) ?> − net debt RM <?= money($v['net_debt']) ?>)</td>
    <td class="tnum"><?= money($v['ebitda_equity']) ?></td>
    <td class="tnum"><?= (int) round($v['weights']['ebitda'] * 100) ?>%</td></tr>
  <tr><td>Capitalised DCF (d=<?= e($a['discount']) ?>%, g=<?= e($a['growth']) ?>%)</td>
    <td class="tnum"><?= money($v['dcf_equity']) ?></td>
    <td class="tnum"><?= (int) round($v['weights']['dcf'] * 100) ?>%</td></tr>
  <tr><td><strong>Weighted equity (pre-discount)</strong></td>
    <td class="tnum"><strong><?= money($v['weighted_pre_discount']) ?></strong></td><td></td></tr>
  <tr><td>Less: key-person / marketability discount</td>
    <td class="tnum">−<?= (int) round($v['discount'] * 100) ?>%</td><td></td></tr>
  <tr><td><strong>Indicative mid equity</strong></td>
    <td class="tnum"><strong>RM <?= money($v['mid']) ?></strong></td><td></td></tr>
  <tr><td>Indicative range (low–high)</td>
    <td class="tnum">RM <?= money($v['low']) ?> – <?= money($v['high']) ?></td><td></td></tr>
  <tr><td><strong>Attributable to client (× ownership)</strong></td>
    <td class="tnum"><strong>RM <?= money($v['client_stake']) ?></strong></td><td></td></tr>
</table>

<?php
  $bf = bf_latest((int) $c['id']);
  $sens = valuation_sensitivity($c, $bf, $a, $clientVal['worst_risk']);
?>
<h4>Sensitivity — indicative mid (RM) by multiple × discount</h4>
<table class="matrix">
  <thead><tr><th>Multiple ↓ / Discount →</th>
    <?php foreach ($sens['discounts'] as $d): ?><th class="tnum"><?= (float) $d ?>%</th><?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($sens['multipliers'] as $mi => $m): ?>
    <tr><td><strong><?= (float) $m ?>×</strong></td>
      <?php foreach ($sens['grid'][$mi] as $dj => $cell):
        $base = abs((float) $m - $sens['base_multiple']) < 0.01
             && abs((float) $sens['discounts'][$dj] - $sens['base_discount']) < 0.01; ?>
        <td class="tnum<?= $base ? ' base' : '' ?>"><?= money($cell) ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($hist): ?>
<h4>Valuation history (most recent <?= count($hist) ?>)</h4>
<table>
  <tr><th>Date</th><th>EBITDA ×</th><th>Discount %</th><th>Growth %</th>
    <th class="tnum">Mid (RM)</th><th class="tnum">Client stake (RM)</th><th>Saved by</th></tr>
  <?php foreach ($hist as $h): ?>
    <tr><td><?= e($h['date'] ?? '') ?><?= !empty($h['time']) ? ' ' . e($h['time']) : '' ?></td>
      <td><?= e((float) ($h['assumptions']['multiple'] ?? 0)) ?>×</td>
      <td><?= e((float) ($h['assumptions']['discount'] ?? 0)) ?>%</td>
      <td><?= e((float) ($h['assumptions']['growth'] ?? 0)) ?>%</td>
      <td class="tnum"><?= money($h['summary']['mid'] ?? 0) ?></td>
      <td class="tnum"><?= money($h['summary']['client_stake'] ?? 0) ?></td>
      <td class="muted"><?= e($h['saved_by'] ?? '') ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php endforeach; ?>

<h2>3. Limitations &amp; caveats</h2>
<ul>
  <li>Book values reflect the latest captured snapshot; significant assets
      (real property, brands, IP) may be undervalued at book.</li>
  <li>Industry multiples are illustrative; calibrate against comparable
      transactions in the local market for any transaction context.</li>
  <li>The DCF assumes perpetual constant growth — appropriate for steady-state
      businesses, less so for high-growth or cyclical operations.</li>
  <li>The key-person discount is single-band; consider separating control,
      marketability and key-person components for sale / succession scenarios.</li>
  <li>This report does <em>not</em> consider transaction costs, taxes on exit,
      or contingent liabilities (litigation, warranty, PG exposure).</li>
</ul>

<div class="disclaimer">This valuation report is prepared for advisory discussion
only and is not financial, tax, legal, audit or transaction advice. Final
implementation, transaction documentation and any binding valuation must be
prepared and signed off by the appropriate licensed professionals.</div>

</body></html>
    <?php
    return (string) ob_get_clean();
}
