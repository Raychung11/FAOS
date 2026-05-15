<?php
/** AdvisorOS — Super Admin platform dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('super_admin');

$pdo = db();
$scalar = static fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();

$tenants    = $scalar("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL");
$activeTen  = $scalar("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND status='active'");
$users      = $scalar("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
$mrr = (float) $pdo->query(
    "SELECT COALESCE(SUM(sp.price_monthly),0) FROM tenants t
     JOIN subscription_plans sp ON sp.id=t.plan_id
     WHERE t.deleted_at IS NULL AND t.subscription_status IN ('active','trial')"
)->fetchColumn();

$rows = $pdo->query(
    "SELECT t.*, sp.name AS plan_name FROM tenants t
     LEFT JOIN subscription_plans sp ON sp.id=t.plan_id
     WHERE t.deleted_at IS NULL ORDER BY t.created_at DESC LIMIT 20"
)->fetchAll();

$pageTitle = 'Platform Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Total Tenants</div>
    <div class="stat-value"><?= $tenants ?></div><div class="stat-foot"><?= $activeTen ?> active</div></div>
  <div class="stat accent"><div class="stat-label">Platform Users</div>
    <div class="stat-value"><?= $users ?></div></div>
  <div class="stat accent"><div class="stat-label">Est. MRR</div>
    <div class="stat-value"><?= money($mrr) ?></div><div class="stat-foot">Active + trial plans</div></div>
  <div class="stat accent"><div class="stat-label">Subscription Plans</div>
    <div class="stat-value"><?= $scalar("SELECT COUNT(*) FROM subscription_plans WHERE is_active=1") ?></div></div>
</div>

<div class="card-os">
  <div class="card-os-head">Recent Tenants
    <a class="btn-os ghost sm" href="<?= e(url('superadmin/tenants.php')) ?>">Manage all</a>
  </div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Company</th><th>Plan</th><th>Subscription</th><th>Status</th><th>Created</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted" style="padding:24px">No tenants yet.</td></tr>
      <?php else: foreach ($rows as $t): ?>
        <tr>
          <td><strong><?= e($t['company_name']) ?></strong><br><span class="muted" style="font-size:12px"><?= e($t['slug']) ?></span></td>
          <td><?= e($t['plan_name'] ?: '—') ?></td>
          <td><span class="badge-os b-<?= e($t['subscription_status']) ?>"><?= label($t['subscription_status']) ?></span></td>
          <td><span class="badge-os b-<?= $t['status']==='active'?'active':'suspended' ?>"><?= label($t['status']) ?></span></td>
          <td><?= fmt_date($t['created_at']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
