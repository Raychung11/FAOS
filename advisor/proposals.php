<?php
/** AdvisorOS — Proposal list & management. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('proposals.manage');

$tid = require_tenant();
$pdo = db();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        $pid = (int) ($_POST['id'] ?? 0);
        $sql = 'UPDATE proposals SET deleted_at=NOW() WHERE id=? AND tenant_id=?';
        $args = [$pid, $tid];
        if (has_role('financial_advisor')) {
            $sql .= ' AND advisor_id=?';
            $args[] = (int) current_user()['id'];
        }
        $pdo->prepare($sql)->execute($args);
        audit_log('delete','proposals',$pid,'Proposal removed');
        set_flash('success','Proposal removed.');
    }
    redirect('advisor/proposals.php');
}

$clients = tenant_clients_list();

$sql = "SELECT p.*, c.full_name, u.name AS advisor FROM proposals p
        JOIN clients c ON c.id=p.client_id
        LEFT JOIN users u ON u.id=p.advisor_id
        WHERE p.tenant_id=? AND p.deleted_at IS NULL";
$args = [$tid];
[$scope, $sp] = advisor_scope('p.advisor_id');
$sql .= $scope . ' ORDER BY p.created_at DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute(array_merge($args, $sp));
$rows = $st->fetchAll();

$pageTitle = 'Proposals';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">New Proposal</div>
  <div class="card-os-body">
    <form method="get" action="<?= e(url('advisor/proposal-edit.php')) ?>"
          style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-row" style="margin:0;min-width:280px">
        <label>Client</label>
        <select name="client_id" required>
          <option value="">— select a client —</option>
          <?php foreach ($clients as $cl): ?>
            <option value="<?= (int)$cl['id'] ?>"><?= e($cl['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-os gold">Generate proposal</button>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Proposals</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Title</th><th>Client</th><th>Advisor</th>
        <th>Status</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted" style="padding:24px">No proposals yet. Pick a client above to generate one.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['title']) ?></strong></td>
          <td><?= e($r['full_name']) ?></td>
          <td><?= e($r['advisor'] ?: '—') ?></td>
          <td><span class="badge-os b-<?= e($r['status']) ?>"><?= label($r['status']) ?></span></td>
          <td><?= fmt_date($r['created_at']) ?></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os sm" href="<?= e(url('advisor/proposal-view.php?id='.(int)$r['id'])) ?>">View</a>
            <a class="btn-os ghost sm" href="<?= e(url('advisor/proposal-edit.php?id='.(int)$r['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Remove this proposal?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
