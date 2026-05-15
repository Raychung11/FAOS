<?php
/** AdvisorOS — Client portal overview. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';

$c   = portal_client();
$pdo = db();
$tid = (int) $c['tenant_id'];

$pol = $pdo->prepare('SELECT * FROM policies WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY renewal_date');
$pol->execute([$c['id'],$tid]);
$policies = $pol->fetchAll();

$rev = $pdo->prepare('SELECT * FROM annual_reviews WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY review_date DESC LIMIT 5');
$rev->execute([$c['id'],$tid]);
$reviews = $rev->fetchAll();

$ap = $pdo->prepare("SELECT * FROM appointments WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL AND scheduled_at>=NOW() ORDER BY scheduled_at LIMIT 5");
$ap->execute([$c['id'],$tid]);
$appts = $ap->fetchAll();

$pageTitle = 'My Portal';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Active Policies</div>
    <div class="stat-value"><?= count(array_filter($policies, fn($p)=>$p['status']==='active')) ?></div></div>
  <div class="stat accent"><div class="stat-label">Next Review</div>
    <div class="stat-value" style="font-size:20px"><?= fmt_date($c['next_review_date']) ?></div></div>
  <div class="stat accent"><div class="stat-label">Upcoming Appointments</div>
    <div class="stat-value"><?= count($appts) ?></div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">My Policies</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Provider</th><th>Category</th><th>Renewal</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$policies): ?>
          <tr><td colspan="4" class="muted" style="padding:20px">No policies on record.</td></tr>
        <?php else: foreach ($policies as $p): ?>
          <tr><td><?= e($p['provider'] ?: '—') ?></td><td><?= label($p['category']) ?></td>
            <td><?= fmt_date($p['renewal_date']) ?></td>
            <td><span class="badge-os b-<?= e($p['status']) ?>"><?= label($p['status']) ?></span></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Upcoming Appointments</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>When</th><th>Title</th><th>Type</th></tr></thead>
        <tbody>
        <?php if (!$appts): ?>
          <tr><td colspan="3" class="muted" style="padding:20px">No upcoming appointments.</td></tr>
        <?php else: foreach ($appts as $a): ?>
          <tr><td><?= fmt_date($a['scheduled_at'],'d M Y H:i') ?></td>
            <td><?= e($a['title']) ?></td><td><?= label($a['type']) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Review Summary</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Review date</th><th>Status</th><th>Recommendation</th></tr></thead>
        <tbody>
        <?php if (!$reviews): ?>
          <tr><td colspan="3" class="muted" style="padding:20px">No reviews yet.</td></tr>
        <?php else: foreach ($reviews as $r): ?>
          <tr><td><?= fmt_date($r['review_date']) ?></td>
            <td><span class="badge-os b-<?= e($r['status']) ?>"><?= label($r['status']) ?></span></td>
            <td><?= e(mb_strimwidth((string)$r['recommendation'],0,80,'…')) ?: '—' ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Quick Links</div>
    <div class="card-os-body">
      <a class="btn-os ghost" href="<?= e(url('client/documents.php')) ?>">My documents</a>
      <a class="btn-os ghost" href="<?= e(url('client/appointments.php')) ?>">My appointments</a>
      <div class="disclaimer" style="margin-top:18px">This portal supports servicing
        and does not replace advice from your licensed financial advisor.</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
