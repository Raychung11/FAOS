<?php
/** AdvisorOS — Retirement & Succession readiness. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/succession.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Succession”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    meter_report('succession');
    audit_log('generate', 'succession', $clientId, 'Succession readiness generated');
    set_flash('success', 'Succession analysis refreshed and logged.');
    redirect('advisor/succession.php?client_id=' . $clientId);
}

$s = succession_report($pdo, $tid, $clientId);

$pageTitle = 'Succession — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Retirement &amp; Succession</h2>
    <span class="muted"><?= e($client['full_name']) ?> · “if you stop working today, can your family survive?”</span>
  </div>
  <div style="display:flex;gap:8px">
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <button class="btn-os sm">Refresh &amp; log</button>
    </form>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<?php if (!$s): ?>
  <div class="alert-os warning">No data yet. Add a personal financial snapshot
    and/or a company under
    <a href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Business profile</a>,
    then return here.</div>
<?php else: ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">If the founder stops today</div>
    <div class="stat-value" style="font-size:19px">
      <span class="badge-os <?= $s['verdict_cls'] ?>" style="font-size:13px"><?= e($s['verdict']) ?></span></div>
    <div class="stat-foot">≈ <?= round($s['years_cover'], 1) ?> yrs of living costs covered</div></div>
  <div class="stat accent"><div class="stat-label">Succession Readiness</div>
    <div class="stat-value"><?= (int) $s['score'] ?><span class="muted" style="font-size:14px">/100</span>
      <span class="badge-os <?= $s['band_cls'] ?>" style="font-size:12px"><?= e($s['band']) ?></span></div>
    <div class="stat-foot">weighted across 4 components</div></div>
  <div class="stat accent"><div class="stat-label">Funding Gap</div>
    <div class="stat-value" style="color:<?= $s['gap']>0?'var(--danger)':'var(--ok)' ?>">RM <?= money($s['gap']) ?></div>
    <div class="stat-foot">need RM <?= money($s['need_capital']) ?> · liquid RM <?= money($s['available']) ?></div></div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Readiness breakdown</div>
  <div class="card-os-body">
    <?php foreach ($s['components'] as [$label, $frac, $weight, $desc]):
      $p = (int) round($frac * 100);
      $col = $frac >= 0.8 ? 'var(--ok)' : ($frac >= 0.5 ? 'var(--gold)' : 'var(--danger)'); ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">
          <span class="muted"><?= e($label) ?> <span style="font-size:11px">· weight <?= (int) $weight ?>%</span></span>
          <strong><?= $p ?>%</strong></div>
        <div style="background:#eef2fb;border-radius:6px;height:12px;overflow:hidden">
          <div style="width:<?= $p ?>%;height:12px;background:<?= $col ?>"></div></div>
        <div class="muted" style="font-size:12px;margin-top:4px"><?= e($desc) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Recommended actions</div>
  <div class="card-os-body">
    <ul style="margin:0 0 6px 18px">
      <?php foreach ($s['recs'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?>
    </ul>
    <div class="disclaimer">Estimate for advisory discussion only — based on
      captured figures and standard assumptions (≈10-year income replacement,
      debts and guarantees cleared). Not legal, tax or insurance advice;
      confirm instruments (will, trust, buy-sell) and review with a licensed
      advisor.</div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
