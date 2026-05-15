<?php
/** AdvisorOS — Tenant Admin dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('tenant_admin');

$tid = require_tenant();
$pdo = db();
$one = static function (string $sql) use ($pdo, $tid) {
    $s = $pdo->prepare($sql);
    $s->execute([$tid]);
    return (int) $s->fetchColumn();
};

$clients   = $one("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND deleted_at IS NULL");
$advisors  = $one("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id
                    WHERE u.tenant_id=? AND u.deleted_at IS NULL AND r.code IN
                    ('financial_advisor','agency_leader')");
$reviewsDue= $one("SELECT COUNT(*) FROM clients WHERE tenant_id=? AND deleted_at IS NULL
                    AND next_review_date IS NOT NULL AND next_review_date <= (CURDATE()+INTERVAL 30 DAY)");
$leads     = $one("SELECT COUNT(*) FROM leads WHERE tenant_id=? AND deleted_at IS NULL");
$converted = $one("SELECT COUNT(*) FROM leads WHERE tenant_id=? AND deleted_at IS NULL AND status='converted'");
$rate = $leads > 0 ? round($converted / $leads * 100) : 0;

$t = current_tenant();

$pageTitle = 'Tenant Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Total Clients</div>
    <div class="stat-value"><?= $clients ?></div><div class="stat-foot">Across the firm</div></div>
  <div class="stat accent"><div class="stat-label">Active Advisors</div>
    <div class="stat-value"><?= $advisors ?></div><div class="stat-foot">Servicing team</div></div>
  <div class="stat accent"><div class="stat-label">Reviews Due (30d)</div>
    <div class="stat-value"><?= $reviewsDue ?></div><div class="stat-foot">Retention priority</div></div>
  <div class="stat accent"><div class="stat-label">Lead Conversion</div>
    <div class="stat-value"><?= $rate ?>%</div><div class="stat-foot"><?= $converted ?>/<?= $leads ?> leads converted</div></div>
</div>

<div class="card-os">
  <div class="card-os-head">Company</div>
  <div class="card-os-body">
    <table class="table-os">
      <tr><td class="muted">Company</td><td><?= e($t['company_name'] ?? '—') ?></td></tr>
      <tr><td class="muted">Registration no.</td><td><?= e($t['registration_no'] ?? '—') ?></td></tr>
      <tr><td class="muted">Subscription</td><td><span class="badge-os b-<?= e($t['subscription_status'] ?? 'trial') ?>"><?= label($t['subscription_status'] ?? 'trial') ?></span></td></tr>
      <tr><td class="muted">Expires</td><td><?= fmt_date($t['expires_at'] ?? $t['trial_ends_at'] ?? null) ?></td></tr>
    </table>
    <div style="margin-top:16px">
      <a class="btn-os ghost sm" href="<?= e(url('tenant/users.php')) ?>">Manage users</a>
      <a class="btn-os ghost sm" href="<?= e(url('tenant/settings.php')) ?>">Company settings</a>
      <a class="btn-os ghost sm" href="<?= e(url('tenant/audit.php')) ?>">Audit trail</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
