<?php
/**
 * AdvisorOS — Equity Assessment report renderer.
 * Produces both an in-app body (for client portal) and a full
 * standalone HTML page (for PDF export). Mirrors the
 * valuation_report_render.php pattern.
 */

declare(strict_types=1);

require_once __DIR__ . '/equity_engine.php';
require_once __DIR__ . '/settings.php';

/** Body markup only — used by the client portal inside the app shell. */
function render_equity_body(array $client, array $company, array $data, array $score): string
{
    $cats = equity_categories();
    ob_start();
    $ai = setting_get_json('equity_ai:' . (int) $company['id'], []);
    ?>
    <?php if (!empty($ai['text'])): ?>
      <div class="card-os" style="margin-bottom:14px"><div class="card-os-body">
        <div style="font-weight:700;color:var(--navy);margin-bottom:6px">Adviser commentary</div>
        <div style="white-space:pre-wrap;font-size:13.5px;line-height:1.6"><?= e($ai['text']) ?></div>
      </div></div>
    <?php endif; ?>

    <div class="grid cols-3" style="margin-bottom:14px">
      <div class="stat accent"><div class="stat-label">Composite score</div>
        <div class="stat-value"><?= round($score['total'], 1) ?><span style="font-size:14px;color:var(--muted)">/100</span></div>
        <div class="stat-foot"><?= e($score['band']) ?></div></div>
      <div class="stat accent"><div class="stat-label">Indicators answered</div>
        <div class="stat-value"><?= $score['answered'] ?><span style="font-size:14px;color:var(--muted)">/<?= $score['total_indicators'] ?></span></div>
        <div class="stat-foot">of 50 framework items</div></div>
      <div class="stat accent"><div class="stat-label">DD documents on file</div>
        <div class="stat-value"><?= $score['dd_done'] ?><span style="font-size:14px;color:var(--muted)">/<?= $score['dd_total'] ?></span></div>
        <div class="stat-foot">document checklist</div></div>
    </div>

    <h3>Category scores</h3>
    <table class="table-os">
      <thead><tr><th>Category</th><th style="text-align:right">Answered</th>
        <th style="text-align:right">Weight</th><th style="text-align:right">Score</th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c => $meta): $r = $score['by_cat'][$c]; ?>
        <tr><td><strong><?= e($meta['label']) ?></strong>
              <div class="muted" style="font-size:11.5px"><?= e($meta['blurb']) ?></div></td>
          <td style="text-align:right"><?= $r['answered'] ?></td>
          <td style="text-align:right"><?= $r['weight'] ?>%</td>
          <td style="text-align:right"><?= $r['answered'] > 0 ? round($r['pct'], 1) . '%' : '—' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($score['red_flags']): ?>
      <h3>Red flags (<?= count($score['red_flags']) ?>)</h3>
      <ul>
      <?php foreach ($score['red_flags'] as $rf): ?>
        <li><strong><?= e($rf['label']) ?></strong> — <span class="muted"><?= e($rf['impact']) ?></span></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

/** Full standalone HTML for the printable / PDF assessment report. */
function render_equity_report_html(array $client, array $company, array $data, array $score, array $brand): string
{
    $primary = $brand['primary'] ?? '#0B1F3A';
    $accent  = $brand['accent']  ?? '#C9A227';
    $firm    = $brand['name']    ?? APP_NAME;
    $cats    = equity_categories();
    $inds    = equity_indicators();
    $ai      = setting_get_json('equity_ai:' . (int) $company['id'], []);

    ob_start();
    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>Equity Structure Assessment — <?= e($company['name']) ?></title>
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
  .band { display:inline-block; padding: 1pt 8pt; border-radius: 8pt; font-size: 9pt; font-weight:700; }
  .band.low      { background:#dcf5e3; color:#1e6b3a; }
  .band.moderate { background:#fff2c9; color:#7a5b00; }
  .band.high     { background:#ffd5c9; color:#8a2f0f; }
  .band.critical { background:#f5c9c9; color:#7c1414; }
</style></head><body>

<table class="nb"><tr>
  <td><h1>Equity Structure Assessment</h1>
    <div class="muted">Malaysia SME · Client: <strong><?= e($client['full_name']) ?></strong>
      · Company: <strong><?= e($company['name']) ?></strong></div></td>
  <td style="text-align:right">
    <div style="font-weight:700;color:<?= $primary ?>;font-size:11pt"><?= e($firm) ?></div>
    <div class="muted">Prepared <?= e(date('d M Y')) ?></div></td>
</tr></table>

<h2>Summary</h2>
<table>
  <tr><th>Metric</th><th class="tnum">Value</th></tr>
  <tr><td>Composite equity-structure risk score</td>
    <td class="tnum"><?= round($score['total'], 1) ?> / 100
      <span class="band <?= e($score['band_code']) ?>"><?= e($score['band']) ?></span></td></tr>
  <tr><td>Indicators answered</td>
    <td class="tnum"><?= $score['answered'] ?> / <?= $score['total_indicators'] ?></td></tr>
  <tr><td>Due-diligence documents on file</td>
    <td class="tnum"><?= $score['dd_done'] ?> / <?= $score['dd_total'] ?></td></tr>
  <?php if ($data['archetype']): ?>
    <tr><td>Ownership archetype</td>
      <td class="tnum" style="text-align:left"><?= e(equity_archetypes()[$data['archetype']]['label'] ?? $data['archetype']) ?></td></tr>
  <?php endif; ?>
</table>
<div class="disclaimer"><strong>Important.</strong> This assessment is a
commercial / governance health-check framework only. It does not constitute
legal, tax, Shariah-planning or SSM compliance advice. Any actions touching
the Companies Act, foreign-equity conditions, nominee arrangements, faraid /
hibah / wasiat, or SHA drafting must be reviewed by a licensed lawyer, tax
agent, company secretary or Shariah planner.</div>

<?php if (!empty($ai['text'])): ?>
  <h2>Adviser commentary</h2>
  <div style="white-space:pre-wrap;font-size:10pt;line-height:1.55"><?= e($ai['text']) ?></div>
<?php endif; ?>

<h2>1. Category scores</h2>
<table>
  <tr><th>Category</th><th class="tnum">Weight</th><th class="tnum">Answered</th><th class="tnum">Score</th></tr>
  <?php foreach ($cats as $c => $meta): $r = $score['by_cat'][$c]; ?>
    <tr><td><strong><?= e($c) ?>. <?= e($meta['label']) ?></strong>
        <div class="muted"><?= e($meta['label_zh']) ?> — <?= e($meta['blurb']) ?></div></td>
      <td class="tnum"><?= $r['weight'] ?>%</td>
      <td class="tnum"><?= $r['answered'] ?></td>
      <td class="tnum"><?= $r['answered'] > 0 ? round($r['pct'], 1) . '%' : '—' ?></td></tr>
  <?php endforeach; ?>
</table>

<?php if ($score['red_flags']): ?>
  <h2>2. Red flags</h2>
  <table>
    <tr><th>Red flag</th><th>Impact</th><th class="tnum">Source</th></tr>
    <?php foreach ($score['red_flags'] as $rf): ?>
      <tr><td><strong><?= e($rf['label']) ?></strong></td>
        <td><?= e($rf['impact']) ?></td>
        <td class="tnum"><?= $rf['source'] === 'auto' ? 'auto-detected' : 'manual' ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<h2>3. Indicator detail (scored items only)</h2>
<?php foreach ($cats as $c => $meta):
  $catInds = array_filter($inds, fn ($i) => $i['cat'] === $c);
  $anyScored = false;
  foreach ($catInds as $ind) {
    if (($data['scores'][$ind['id']]['score'] ?? null) !== null) { $anyScored = true; break; }
  }
  if (!$anyScored) { continue; } ?>
  <h3><?= e($c) ?>. <?= e($meta['label']) ?>
    <span class="muted">· <?= e($meta['label_zh']) ?></span></h3>
  <table>
    <tr><th>#</th><th>Indicator</th><th class="tnum">Score</th><th>Note</th></tr>
    <?php foreach ($catInds as $ind):
      $s = $data['scores'][$ind['id']]['score'] ?? null;
      if ($s === null) { continue; }
      [$bandLabel, ] = equity_score_bands()[$s] ?? ['—', ''];
    ?>
      <tr><td><?= $ind['id'] ?></td>
        <td><?= e($ind['label']) ?>
          <div class="muted"><?= e($ind['label_zh']) ?></div></td>
        <td class="tnum"><?= $s ?> · <?= e($bandLabel) ?></td>
        <td><?= e($data['scores'][$ind['id']]['note'] ?? '') ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php endforeach; ?>

<h2>4. Due-diligence checklist</h2>
<table>
  <tr><th>Category</th><th class="tnum">On file</th></tr>
  <?php foreach (equity_dd_checklist() as $key => $label): ?>
    <tr><td><?= e($label) ?></td>
      <td class="tnum"><?= !empty($data['dd'][$key]) ? '✓' : '—' ?></td></tr>
  <?php endforeach; ?>
</table>

<h2>5. Limitations &amp; caveats</h2>
<ul>
  <li>Scoring reflects the advisor's judgement against the 50-indicator framework;
      it does not audit statutory records against SSM in real time.</li>
  <li>Auto-detected red flags use score patterns as a heuristic — the advisor
      should confirm each finding before acting on it.</li>
  <li>Any legal-instrument recommendation (SHA, buy-sell, nominee agreement, IP
      assignment, hibah / wasiat) must be drafted / signed off by qualified
      counsel or a Shariah planner as appropriate.</li>
</ul>

<div class="disclaimer">This report is prepared for advisory discussion only.
Final legal instruments and Shariah / tax / compliance implementation must be
prepared and signed off by the appropriate licensed professionals.</div>

</body></html>
    <?php
    return (string) ob_get_clean();
}
