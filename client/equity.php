<?php
/** AdvisorOS — Client portal: read-only Equity Structure Assessment. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';
require_once __DIR__ . '/../includes/business.php';
require_once __DIR__ . '/../includes/equity_engine.php';
require_once __DIR__ . '/../includes/equity_report_render.php';

$c   = portal_client();
$pdo = db();
$tid = (int) $c['tenant_id'];

$companies = companies_for_client((int) $c['id']);

$pageTitle = 'Equity Structure Assessment';
require __DIR__ . '/../includes/header.php';
?>
<style>
  @media print { .sidebar, .topbar, .noprint { display:none !important; }
    .content, .main { margin:0 !important; padding:0 !important; } }
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Equity Structure Assessment</h2>
    <span class="muted" style="font-size:13px">Governance health check across your companies — prepared by your adviser.</span>
  </div>
  <div class="noprint" style="display:flex;gap:8px">
    <button class="btn-os sm" onclick="window.print()">Print / Save PDF</button>
    <a class="btn-os ghost sm" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
  </div>
</div>

<?php if (!$companies): ?>
  <div class="alert-os info">No companies recorded yet. Once your adviser
    adds them under Business profile, your equity assessment will appear here.</div>
<?php else: ?>
  <?php foreach ($companies as $co):
        $data  = equity_load((int) $co['id']);
        $score = equity_score($data);
        if ($score['answered'] === 0) { continue; } ?>
    <div class="card-os" style="margin-bottom:18px">
      <div class="card-os-head"><?= e($co['name']) ?></div>
      <div class="card-os-body">
        <?= render_equity_body($c, $co, $data, $score) ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="card-os"><div class="card-os-body">
    <div class="disclaimer">This assessment is a commercial / governance
      health check. It does not constitute legal, tax, Shariah or SSM
      compliance advice. Your adviser will confirm any next steps with you.</div>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
