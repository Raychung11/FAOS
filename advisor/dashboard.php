<?php
/** AdvisorOS — Advisor / Agency Leader dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_role('financial_advisor', 'agency_leader', 'tenant_admin');

$tid = require_tenant();
$pdo = db();
[$scopeC, $pc] = advisor_scope('advisor_id');
[$scopeF, $pf] = advisor_scope('assigned_to');

$one = static function (string $sql, array $args) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($args);
    return (int) $s->fetchColumn();
};

$myClients = $one(
    "SELECT COUNT(*) FROM clients WHERE tenant_id=? AND deleted_at IS NULL{$scopeC}",
    array_merge([$tid], $pc)
);
$reviewsDue = $one(
    "SELECT COUNT(*) FROM clients WHERE tenant_id=? AND deleted_at IS NULL
       AND next_review_date IS NOT NULL AND next_review_date <= (CURDATE() + INTERVAL 30 DAY){$scopeC}",
    array_merge([$tid], $pc)
);
$openFollowups = $one(
    "SELECT COUNT(*) FROM follow_ups WHERE tenant_id=? AND status='pending' AND deleted_at IS NULL{$scopeF}",
    array_merge([$tid], $pf)
);
[$scopeA, $pa] = advisor_scope('advisor_id');
$upcomingAppts = $one(
    "SELECT COUNT(*) FROM appointments WHERE tenant_id=? AND status='scheduled'
       AND scheduled_at >= NOW() AND deleted_at IS NULL{$scopeA}",
    array_merge([$tid], $pa)
);
$expiringPolicies = $one(
    "SELECT COUNT(*) FROM policies WHERE tenant_id=? AND status='active' AND deleted_at IS NULL
       AND renewal_date IS NOT NULL AND renewal_date <= (CURDATE() + INTERVAL 60 DAY)",
    [$tid]
);

// Reviews due list
$rs = $pdo->prepare(
    "SELECT id, full_name, next_review_date FROM clients
     WHERE tenant_id=? AND deleted_at IS NULL AND next_review_date IS NOT NULL
       AND next_review_date <= (CURDATE() + INTERVAL 45 DAY){$scopeC}
     ORDER BY next_review_date ASC LIMIT 8"
);
$rs->execute(array_merge([$tid], $pc));
$dueList = $rs->fetchAll();

// Today / upcoming follow-ups
$fs = $pdo->prepare(
    "SELECT f.id, f.title, f.due_date, f.priority, c.full_name
     FROM follow_ups f LEFT JOIN clients c ON c.id=f.client_id
     WHERE f.tenant_id=? AND f.status='pending' AND f.deleted_at IS NULL{$scopeF}
     ORDER BY f.due_date ASC LIMIT 8"
);
$fs->execute(array_merge([$tid], $pf));
$fuList = $fs->fetchAll();

$pageTitle = 'Advisor Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent">
    <div class="stat-label">My Clients</div>
    <div class="stat-value"><?= $myClients ?></div>
    <div class="stat-foot">Active relationships</div>
  </div>
  <div class="stat accent">
    <div class="stat-label">Reviews Due (30d)</div>
    <div class="stat-value"><?= $reviewsDue ?></div>
    <div class="stat-foot">Retention priority</div>
  </div>
  <div class="stat accent">
    <div class="stat-label">Open Follow-ups</div>
    <div class="stat-value"><?= $openFollowups ?></div>
    <div class="stat-foot">Tasks pending</div>
  </div>
  <div class="stat accent">
    <div class="stat-label">Upcoming Appointments</div>
    <div class="stat-value"><?= $upcomingAppts ?></div>
    <div class="stat-foot"><?= $expiringPolicies ?> policies expiring (60d)</div>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">
      Annual Reviews Due
      <a class="btn-os ghost sm" href="<?= e(url('advisor/reviews.php')) ?>">View all</a>
    </div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Client</th><th>Next Review</th><th></th></tr></thead>
        <tbody>
        <?php if (!$dueList): ?>
          <tr><td colspan="3" class="muted" style="padding:20px">No reviews due. Great job staying on top of servicing.</td></tr>
        <?php else: foreach ($dueList as $c):
            $overdue = strtotime($c['next_review_date']) < time(); ?>
          <tr>
            <td><?= e($c['full_name']) ?></td>
            <td><span class="badge-os <?= $overdue ? 'b-overdue' : 'b-warn' ?>"><?= fmt_date($c['next_review_date']) ?></span></td>
            <td class="text-right"><a class="btn-os sm" href="<?= e(url('advisor/review-edit.php?client_id=' . (int)$c['id'])) ?>">Start review</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">
      Follow-ups
      <a class="btn-os ghost sm" href="<?= e(url('advisor/followups.php')) ?>">View all</a>
    </div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Task</th><th>Client</th><th>Due</th><th>Priority</th></tr></thead>
        <tbody>
        <?php if (!$fuList): ?>
          <tr><td colspan="4" class="muted" style="padding:20px">No pending follow-ups.</td></tr>
        <?php else: foreach ($fuList as $f):
            $od = strtotime($f['due_date']) < strtotime('today'); ?>
          <tr>
            <td><?= e($f['title']) ?></td>
            <td><?= e($f['full_name'] ?? '—') ?></td>
            <td><span class="badge-os <?= $od ? 'b-overdue' : 'b-scheduled' ?>"><?= fmt_date($f['due_date']) ?></span></td>
            <td><span class="badge-os b-<?= e($f['priority']) ?>"><?= label($f['priority']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
