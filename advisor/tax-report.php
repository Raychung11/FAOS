<?php
/** AdvisorOS — Tax Impact Report: before vs after our analysis. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/corptax.php';   // pulls tax_my, business, settings
require_once __DIR__ . '/../includes/solutions.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
$fp->execute([$clientId, $tid]);
$fin = $fp->fetch() ?: null;

// Before vs after (shared engine — see includes/corptax.php).
$imp = tax_impact($pdo, $tid, $clientId);
$gross       = $imp['gross'];
$persBefore  = $imp['pers_before']; $persAfter = $imp['pers_after']; $persSaving = $imp['pers_saving'];
$corpRows    = $imp['corp_rows'];
$beforeTotal = $imp['before_total']; $afterTotal = $imp['after_total'];
$saving      = $imp['saving']; $pct = $imp['pct'];
$topUps      = $imp['topups'];
$afterW      = $beforeTotal > 0 ? max(2, $afterTotal / $beforeTotal * 100) : 0;

$eng = solution_get($clientId, 'tax');

$pageTitle = 'Tax Impact Report — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  @media print { .sidebar, .topbar, .noprint { display:none !important; }
    .content, .main { margin:0 !important; padding:0 !important; } }
  .ba-bar{height:30px;border-radius:8px;display:flex;align-items:center;
          padding:0 12px;color:#fff;font-weight:700;font-size:14px}
</style>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Tax Impact Report</h2>
    <span class="muted"><?= e($client['full_name']) ?> · before vs after our analysis · <?= e(date('d M Y')) ?></span>
  </div>
  <div class="noprint" style="display:flex;gap:8px">
    <button class="btn-os sm" onclick="window.print()">Print / Save PDF</button>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/solution-tax.php?client_id='.$clientId)) ?>">Back</a>
  </div>
</div>

<?php if ($gross <= 0 && !$corpRows): ?>
  <div class="alert-os warning">No tax inputs yet. Capture them in
    <a href="<?= e(url('advisor/tax.php?client_id='.$clientId)) ?>">Tax planning</a> first.</div>
<?php else: ?>

<div class="grid cols-3" style="margin:14px 0 18px">
  <div class="stat accent"><div class="stat-label">Tax now (before)</div>
    <div class="stat-value">RM <?= money($beforeTotal) ?></div>
    <div class="stat-foot">personal + corporate, per year</div></div>
  <div class="stat accent"><div class="stat-label">Tax after our plan</div>
    <div class="stat-value">RM <?= money($afterTotal) ?></div>
    <div class="stat-foot">reliefs maximised + efficient extraction</div></div>
  <div class="stat accent"><div class="stat-label">You could save</div>
    <div class="stat-value" style="color:var(--ok)">RM <?= money($saving) ?></div>
    <div class="stat-foot"><?= round($pct, 1) ?>% lower · every year</div></div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Before vs after</div>
  <div class="card-os-body">
    <div class="muted" style="font-size:12px;margin-bottom:4px">Before — RM <?= money($beforeTotal) ?></div>
    <div class="ba-bar" style="width:100%;background:var(--danger);margin-bottom:12px">RM <?= money($beforeTotal) ?></div>
    <div class="muted" style="font-size:12px;margin-bottom:4px">After — RM <?= money($afterTotal) ?></div>
    <div class="ba-bar" style="width:<?= $afterW ?>%;background:var(--ok)">RM <?= money($afterTotal) ?></div>
  </div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Breakdown</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Area</th><th>Before</th><th>After</th><th>Annual saving</th></tr></thead>
      <tbody>
        <tr><td><strong>Personal income tax</strong>
          <div class="muted" style="font-size:12px">maximising eligible LHDN reliefs</div></td>
          <td>RM <?= money($persBefore) ?></td><td>RM <?= money($persAfter) ?></td>
          <td style="color:var(--ok)">RM <?= money($persSaving) ?></td></tr>
        <?php foreach ($corpRows as $r): ?>
          <tr><td><strong><?= e($r['name']) ?></strong>
            <div class="muted" style="font-size:12px">efficient salary / dividend split</div></td>
            <td>RM <?= money($r['before']) ?></td><td>RM <?= money($r['after']) ?></td>
            <td style="color:var(--ok)">RM <?= money(max(0, $r['before'] - $r['after'])) ?></td></tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid var(--line)">
          <td><strong>Total</strong></td>
          <td><strong>RM <?= money($beforeTotal) ?></strong></td>
          <td><strong>RM <?= money($afterTotal) ?></strong></td>
          <td><strong style="color:var(--ok)">RM <?= money($saving) ?></strong></td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php if ($topUps): ?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">How we get there — recommended relief top-ups</div>
  <div class="card-os-body">
    <ul style="margin:0 0 6px 18px">
      <?php foreach (array_slice($topUps, 0, 6) as $t): ?>
        <li><strong><?= e($t['label']) ?></strong> — room of RM <?= money($t['room']) ?> still available.</li>
      <?php endforeach; ?>
    </ul>
    <?php if ($corpRows): ?>
      <p style="margin:6px 0 0">Plus restructure the company salary/dividend mix to the efficient split.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card-os">
  <div class="card-os-body">
    <p style="margin:0 0 8px"><strong>Prepared by:</strong> <?= e(current_user()['name']) ?>
      <?php if ($eng['fee'] > 0): ?> · <strong>Engagement fee:</strong> RM <?= money($eng['fee']) ?><?php endif; ?></p>
    <div class="disclaimer">"Before" reflects the figures captured today; "after"
      assumes eligible LHDN reliefs are fully utilised and company profit is
      extracted via the efficient salary/dividend split. Indicative estimates
      for advisory discussion only — not a tax computation, filing or advice.
      LHDN rates, reliefs and eligibility change and depend on circumstances;
      implementation must be reviewed by a licensed tax agent / adviser.</div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
