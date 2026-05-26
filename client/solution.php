<?php
/** AdvisorOS — Client portal: read-only view of a prepared solution. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';
require_once __DIR__ . '/../includes/solutions.php';

$c   = portal_client();
$key = preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['key'] ?? ''));
$cat = solution_catalog();

if (!isset($cat[$key]) || !$cat[$key]['active']) {
    http_response_code(404); exit('Solution not available.');
}
$s   = $cat[$key];
$eng = solution_get((int) $c['id'], $key);
[$slbl, $scls] = SOLUTION_STATUSES[$eng['status']];

$pageTitle = $s['name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)"><?= e($s['name']) ?>
      <span class="badge-os <?= $scls ?>" style="font-size:13px"><?= e($slbl) ?></span></h2>
    <span class="muted"><?= e($s['tagline']) ?></span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
</div>

<?php if ($eng['status'] === 'none'): ?>
  <div class="alert-os info">Your adviser hasn't prepared this solution yet.</div>
<?php else: ?>

<?php if ($eng['est_saving'] > 0): ?>
<div class="grid cols-2" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Estimated annual value</div>
    <div class="stat-value" style="color:var(--ok)">RM <?= money($eng['est_saving']) ?></div>
    <div class="stat-foot">per year, once implemented</div></div>
  <?php if ($eng['fee'] > 0): ?>
  <div class="stat accent"><div class="stat-label">Engagement fee</div>
    <div class="stat-value">RM <?= money($eng['fee']) ?></div>
    <div class="stat-foot">one-off, as quoted by your adviser</div></div>
  <?php endif; ?>
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
        <p class="muted" style="margin:0">Your adviser will confirm the detailed
          scope with you.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-body">
    <div class="disclaimer">Figures are indicative estimates prepared for
      discussion — not tax, legal or investment advice. Your licensed adviser
      will confirm the details and any implementation with you.</div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
