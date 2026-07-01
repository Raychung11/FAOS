<?php
/** AdvisorOS — Client Management list. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('clients.view');

$tid = require_tenant();
$pdo = db();

if (is_post()) {
    csrf_check();
    require_permission('clients.manage');
    if (($_POST['action'] ?? '') === 'delete') {
        $cid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE clients SET deleted_at=NOW() WHERE id=? AND tenant_id=?')
            ->execute([$cid, $tid]);
        audit_log('delete', 'clients', $cid, 'Client archived');
        set_flash('success', 'Client archived.');
    }
    redirect('advisor/clients.php');
}

$q = (string) input('q', '');
$sql = "SELECT c.*, u.name AS advisor_name FROM clients c
        LEFT JOIN users u ON u.id=c.advisor_id
        WHERE c.tenant_id=? AND c.deleted_at IS NULL";
$args = [$tid];
[$scope, $sp] = advisor_scope('c.advisor_id');
$sql .= $scope; $args = array_merge($args, $sp);
if ($q !== '') {
    $sql .= ' AND (c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.nric_passport LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%");
}
$sql .= ' ORDER BY c.full_name ASC LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($args);
$clients = $st->fetchAll();

$pageTitle = 'Client Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os">
  <div class="card-os-head">
    Clients
    <?php if (can('clients.manage')): ?>
      <a class="btn-os gold sm" href="<?= e(url('advisor/client-edit.php')) ?>">+ New Client</a>
    <?php endif; ?>
  </div>
  <div class="card-os-body">
    <form method="get" style="margin-bottom:18px">
      <input type="text" name="q" placeholder="Search name, phone, email, NRIC" value="<?= e($q) ?>"
             style="width:320px;max-width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:10px">
      <button class="btn-os ghost sm">Search</button>
    </form>
    <table class="table-os">
      <thead><tr>
        <th>Client</th><th>Contact</th><th>Advisor</th><th>Risk</th>
        <th>Next Review</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$clients): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No clients yet.</td></tr>
      <?php else: foreach ($clients as $c):
        $od = $c['next_review_date'] && strtotime($c['next_review_date']) < time(); ?>
        <tr>
          <td><strong><?= e($c['full_name']) ?></strong><br>
              <span class="muted" style="font-size:12px"><?= e($c['occupation'] ?: '') ?></span></td>
          <td><?= e($c['phone'] ?: '—') ?><br>
              <span class="muted" style="font-size:12px"><?= e($c['email'] ?: '') ?></span></td>
          <td><?= e($c['advisor_name'] ?: '—') ?></td>
          <td><?= label($c['risk_appetite']) ?></td>
          <td><?php if ($c['next_review_date']): ?>
              <span class="badge-os <?= $od?'b-overdue':'b-warn' ?>"><?= fmt_date($c['next_review_date']) ?></span>
              <?php else: ?><span class="muted">Not scheduled</span><?php endif; ?></td>
          <td><span class="badge-os b-<?= e($c['status']) ?>"><?= label($c['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os sm" href="<?= e(url('advisor/client-view.php?id=' . (int)$c['id'])) ?>">View</a>
            <?php if (can('clients.manage')): ?>
              <a class="btn-os ghost sm" href="<?= e(url('advisor/client-edit.php?id=' . (int)$c['id'])) ?>">Edit</a>
              <form method="post" action="<?= e(url('advisor/clients.php')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn-os ghost sm" data-confirm="Archive this client?">Archive</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
