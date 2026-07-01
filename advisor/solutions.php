<?php
/** AdvisorOS — Advanced advisory Solutions (per client). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/solutions.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Solutions”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$catalog = solution_catalog();

$pageTitle = 'Solutions — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Advanced Solutions</h2>
    <span class="muted"><?= e($client['full_name']) ?> · implement, don't just diagnose</span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
</div>

<div class="grid cols-2">
  <?php foreach ($catalog as $key => $s):
    $eng = solution_get($clientId, $key);
    [$slbl, $scls] = SOLUTION_STATUSES[$eng['status']]; ?>
    <div class="card-os">
      <div class="card-os-head"><?= e($s['name']) ?>
        <?php if ($s['active']): ?>
          <span class="badge-os <?= $scls ?>"><?= e($slbl) ?></span>
        <?php else: ?>
          <span class="badge-os b-scheduled">Coming soon</span>
        <?php endif; ?>
      </div>
      <div class="card-os-body">
        <p style="margin:0 0 4px;font-weight:600;color:var(--navy)"><?= e($s['tagline']) ?></p>
        <p class="muted" style="margin:0 0 14px;font-size:13.5px;line-height:1.55"><?= e($s['desc']) ?></p>
        <?php if ($s['active']): ?>
          <?php if ($eng['status'] !== 'none' && $eng['est_saving'] > 0): ?>
            <div class="muted" style="font-size:12px;margin-bottom:10px">
              Est. saving RM <?= money($eng['est_saving']) ?>
              <?php if ($eng['fee'] > 0): ?> · fee RM <?= money($eng['fee']) ?><?php endif; ?></div>
          <?php endif; ?>
          <a class="btn-os gold sm" href="<?= e(url($s['page'].'?client_id='.$clientId)) ?>">
            <?= $eng['status'] === 'none' ? 'Start engagement' : 'Open engagement' ?></a>
        <?php else: ?>
          <button class="btn-os ghost sm" disabled style="opacity:.55;cursor:not-allowed">Coming soon</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-body">
    <div class="disclaimer">Solutions turn the platform's estimates into a
      scoped, fee-based engagement. All work must be delivered and approved by
      the appropriate licensed professional (financial adviser, tax agent or
      lawyer as relevant).</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
