<?php
/** AdvisorOS — Policy & product tracking with renewal alerts. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('policies.manage');

$tid = require_tenant();
$pdo = db();

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        $pid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE policies SET deleted_at=NOW() WHERE id=? AND tenant_id=?')
            ->execute([$pid, $tid]);
        audit_log('delete','policies',$pid,'Policy removed');
        set_flash('success','Policy removed.');
    }
    redirect('advisor/policies.php');
}

[$scope, $sp] = advisor_scope('c.advisor_id');
$st = $pdo->prepare(
    "SELECT p.*, c.full_name FROM policies p
     JOIN clients c ON c.id=p.client_id
     WHERE p.tenant_id=? AND p.deleted_at IS NULL{$scope}
     ORDER BY p.renewal_date ASC LIMIT 300"
);
$st->execute(array_merge([$tid], $sp));
$policies = $st->fetchAll();

$pageTitle = 'Policy & Product Tracking';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os">
  <div class="card-os-head">Policies
    <a class="btn-os gold sm" href="<?= e(url('advisor/policy-edit.php')) ?>">+ Add Policy</a>
  </div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Client</th><th>Category</th><th>Provider / No.</th><th>Premium</th>
        <th>Renewal</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$policies): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No policies tracked yet.</td></tr>
      <?php else: foreach ($policies as $p):
        $soon = $p['renewal_date'] && strtotime($p['renewal_date']) <= strtotime('+60 days');
        $od   = $p['renewal_date'] && strtotime($p['renewal_date']) < time(); ?>
        <tr>
          <td><?= e($p['full_name']) ?></td>
          <td><?= label($p['category']) ?></td>
          <td><?= e($p['provider'] ?: '—') ?><br><span class="muted" style="font-size:12px"><?= e($p['policy_number']) ?></span></td>
          <td><?= money($p['premium']) ?> / <?= label($p['payment_frequency']) ?></td>
          <td><?php if ($p['renewal_date']): ?>
              <span class="badge-os <?= $od?'b-overdue':($soon?'b-warn':'b-scheduled') ?>"><?= fmt_date($p['renewal_date']) ?></span>
              <?php else: ?>—<?php endif; ?></td>
          <td><span class="badge-os b-<?= e($p['status']) ?>"><?= label($p['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os ghost sm" href="<?= e(url('advisor/policy-edit.php?id='.(int)$p['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Remove this policy?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
