<?php
/** AdvisorOS — Enterprise Risk Diagnostic (heatmap + alerts). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/risk_engine.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Risk diagnostic”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    meter_report('risk');
    audit_log('generate', 'risk_diagnostic', $clientId, 'Enterprise risk diagnostic generated');
    set_flash('success', 'Risk diagnostic refreshed and logged.');
    redirect('advisor/risk-diagnostic.php?client_id=' . $clientId);
}

$r = risk_diagnostic($pdo, $tid, $clientId);

$pageTitle = 'Risk Diagnostic — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';

$cats = ['Personal', 'Business', 'Director liability', 'Shareholder'];
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Enterprise Risk Diagnostic</h2>
    <span class="muted"><?= e($client['full_name']) ?> · personal · business · director · shareholder</span>
  </div>
  <div style="display:flex;gap:8px">
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <button class="btn-os sm">Refresh &amp; log</button>
    </form>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<?php if (!$r): ?>
  <div class="alert-os warning">No data yet. Add a personal financial snapshot
    and/or a company under
    <a href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Business profile</a>,
    then return here.</div>
<?php else: ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Overall</div>
    <div class="stat-value" style="font-size:21px">
      <span class="badge-os <?= $r['critical']?'b-overdue':($r['high']?'b-overdue':($r['overall_lbl']==='Low'?'b-active':'b-warn')) ?>" style="font-size:14px"><?= e($r['overall']) ?></span></div>
    <div class="stat-foot">across <?= count($r['dims']) ?> risk dimensions</div></div>
  <div class="stat accent"><div class="stat-label">Critical</div>
    <div class="stat-value" style="color:<?= $r['critical']?'var(--danger)':'inherit' ?>"><?= (int) $r['critical'] ?></div>
    <div class="stat-foot">need immediate attention</div></div>
  <div class="stat accent"><div class="stat-label">High</div>
    <div class="stat-value" style="color:<?= $r['high']?'var(--danger)':'inherit' ?>"><?= (int) $r['high'] ?></div>
    <div class="stat-foot">address this cycle</div></div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Risk heatmap</div>
  <div class="card-os-body">
    <div class="grid cols-4">
      <?php foreach ($cats as $cat): ?>
        <?php
          $catDims = array_values(array_filter($r['dims'], fn ($d) => $d['category'] === $cat));
          [$cl, $ccls, $ccol] = risk_level($r['cats'][$cat] ?? null);
        ?>
        <div>
          <div style="font-weight:700;color:var(--navy);font-size:13px;margin-bottom:8px">
            <?= e($cat) ?> <span class="badge-os <?= $ccls ?>" style="font-size:10px"><?= e($cl) ?></span></div>
          <?php foreach ($catDims as $d): [$lvl,$bcls,$col] = risk_level($d['score']); ?>
            <div style="background:<?= $col ?>1f;border-left:4px solid <?= $col ?>;
                        border-radius:7px;padding:9px 11px;margin-bottom:8px">
              <div style="font-size:13px;font-weight:600;color:var(--navy)"><?= e($d['label']) ?></div>
              <div style="font-size:11px;color:<?= $col ?>;font-weight:700"><?= e($lvl) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($r['alerts']): ?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Alerts &amp; recommended actions</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Risk</th><th>Level</th><th>Recommended action</th></tr></thead>
      <tbody>
      <?php foreach ($r['alerts'] as $d): [$lvl,$bcls] = risk_level($d['score']); ?>
        <tr>
          <td><strong><?= e($d['label']) ?></strong>
            <div class="muted" style="font-size:12px"><?= e($d['category']) ?> · <?= e($d['detail']) ?></div></td>
          <td><span class="badge-os <?= $bcls ?>"><?= e($lvl) ?></span></td>
          <td style="font-size:13px"><?= e($d['rec']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card-os">
  <div class="card-os-head">Full diagnostic</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Dimension</th><th>Category</th><th>Level</th><th>Finding</th><th>Recommendation</th></tr></thead>
      <tbody>
      <?php foreach ($r['dims'] as $d): [$lvl,$bcls] = risk_level($d['score']); ?>
        <tr>
          <td><strong><?= e($d['label']) ?></strong></td>
          <td class="muted" style="font-size:12px"><?= e($d['category']) ?></td>
          <td><span class="badge-os <?= $bcls ?>"><?= e($lvl) ?></span></td>
          <td style="font-size:13px"><?= e($d['detail']) ?></td>
          <td style="font-size:13px;color:var(--muted)"><?= e($d['rec']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-os-body">
    <div class="disclaimer">Diagnostic estimate from captured data using
      transparent rules — not an audit, legal, tax or insurance advice.
      Findings must be validated with the client and reviewed by a licensed
      advisor before any recommendation.</div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
