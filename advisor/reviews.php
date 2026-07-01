<?php
/** AdvisorOS — Annual Review Engine (the retention engine).
 *  Shows due / overdue reviews and review history. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reviews.manage');

$tid = require_tenant();
$pdo = db();
[$scope, $sp] = advisor_scope('c.advisor_id');

// Clients due / overdue for review
$due = $pdo->prepare(
    "SELECT c.id,c.full_name,c.next_review_date,c.last_review_date,u.name AS advisor_name
     FROM clients c LEFT JOIN users u ON u.id=c.advisor_id
     WHERE c.tenant_id=? AND c.deleted_at IS NULL AND c.status='active'
       AND (c.next_review_date IS NULL OR c.next_review_date <= (CURDATE() + INTERVAL 45 DAY)){$scope}
     ORDER BY (c.next_review_date IS NULL), c.next_review_date ASC LIMIT 100"
);
$due->execute(array_merge([$tid], $sp));
$dueList = $due->fetchAll();

// Recent reviews
[$scope2, $sp2] = advisor_scope('ar.advisor_id');
$hist = $pdo->prepare(
    "SELECT ar.*, c.full_name FROM annual_reviews ar
     JOIN clients c ON c.id=ar.client_id
     WHERE ar.tenant_id=? AND ar.deleted_at IS NULL{$scope2}
     ORDER BY ar.review_date DESC LIMIT 50"
);
$hist->execute(array_merge([$tid], $sp2));
$history = $hist->fetchAll();

$overdue = 0;
foreach ($dueList as $d) {
    if ($d['next_review_date'] && strtotime($d['next_review_date']) < time()) $overdue++;
}

$pageTitle = 'Annual Review Engine';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Due / Upcoming (45d)</div>
    <div class="stat-value"><?= count($dueList) ?></div><div class="stat-foot">Clients needing a review</div></div>
  <div class="stat accent"><div class="stat-label">Overdue</div>
    <div class="stat-value" style="color:var(--danger)"><?= $overdue ?></div><div class="stat-foot">Retention at risk</div></div>
  <div class="stat accent"><div class="stat-label">Reviews on record</div>
    <div class="stat-value"><?= count($history) ?></div><div class="stat-foot">Recent history</div></div>
</div>

<div class="alert-os info">Never miss a client review again. Reviews keep clients
engaged, surface new needs, and are the core of long-term retention.</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Reviews Due &amp; Overdue</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Client</th><th>Advisor</th><th>Last review</th><th>Next review</th><th></th></tr></thead>
      <tbody>
      <?php if (!$dueList): ?>
        <tr><td colspan="5" class="muted" style="padding:24px">No reviews due. Excellent servicing discipline.</td></tr>
      <?php else: foreach ($dueList as $d):
        $od = $d['next_review_date'] && strtotime($d['next_review_date']) < time();
        $unsched = empty($d['next_review_date']); ?>
        <tr>
          <td><strong><?= e($d['full_name']) ?></strong></td>
          <td><?= e($d['advisor_name'] ?: '—') ?></td>
          <td><?= fmt_date($d['last_review_date']) ?></td>
          <td><?php if ($unsched): ?><span class="badge-os b-warn">Not scheduled</span>
              <?php else: ?><span class="badge-os <?= $od?'b-overdue':'b-warn' ?>"><?= fmt_date($d['next_review_date']) ?></span><?php endif; ?></td>
          <td class="text-right"><a class="btn-os gold sm" href="<?= e(url('advisor/review-edit.php?client_id='.(int)$d['id'])) ?>">Start review</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Review History</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Client</th><th>Review date</th><th>Next</th><th>Status</th><th>Acknowledged</th><th></th></tr></thead>
      <tbody>
      <?php if (!$history): ?>
        <tr><td colspan="6" class="muted" style="padding:24px">No reviews recorded yet.</td></tr>
      <?php else: foreach ($history as $h): ?>
        <tr>
          <td><?= e($h['full_name']) ?></td>
          <td><?= fmt_date($h['review_date']) ?></td>
          <td><?= fmt_date($h['next_review_date']) ?></td>
          <td><span class="badge-os b-<?= e($h['status']) ?>"><?= label($h['status']) ?></span></td>
          <td><?= $h['client_acknowledged'] ? '✓' : '—' ?></td>
          <td class="text-right"><a class="btn-os ghost sm" href="<?= e(url('advisor/review-edit.php?id='.(int)$h['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
