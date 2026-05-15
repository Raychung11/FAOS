<?php
/** AdvisorOS — Lead Management (list, filter, soft-delete). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('leads.view');

$tid = require_tenant();
$pdo = db();

// Soft-delete
if (is_post()) {
    csrf_check();
    require_permission('leads.manage');
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE leads SET deleted_at=NOW() WHERE id=? AND tenant_id=?');
        $stmt->execute([$id, $tid]);
        audit_log('delete', 'leads', $id, 'Lead removed');
        set_flash('success', 'Lead removed.');
    }
    redirect('advisor/leads.php');
}

$status = (string) input('status', '');
$q      = (string) input('q', '');

$sql  = "SELECT l.*, u.name AS advisor_name FROM leads l
         LEFT JOIN users u ON u.id=l.assigned_to
         WHERE l.tenant_id=? AND l.deleted_at IS NULL";
$args = [$tid];

[$scope, $sp] = advisor_scope('l.assigned_to');
$sql .= $scope; $args = array_merge($args, $sp);

if ($status !== '') { $sql .= ' AND l.status=?'; $args[] = $status; }
if ($q !== '') {
    $sql .= ' AND (l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
$sql .= ' ORDER BY l.created_at DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($args);
$leads = $st->fetchAll();

$statuses = ['new','contacted','qualified','appointment_set','proposal_sent','converted','lost'];

$pageTitle = 'Lead Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os">
  <div class="card-os-head">
    Leads
    <?php if (can('leads.manage')): ?>
      <a class="btn-os gold sm" href="<?= e(url('advisor/lead-edit.php')) ?>">+ New Lead</a>
    <?php endif; ?>
  </div>
  <div class="card-os-body">
    <form method="get" style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap">
      <input type="text" name="q" placeholder="Search name, phone, email" value="<?= e($q) ?>"
             style="flex:1;min-width:220px;padding:9px 12px;border:1px solid var(--line);border-radius:10px">
      <select name="status" style="padding:9px 12px;border:1px solid var(--line);border-radius:10px">
        <option value="">All statuses</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= label($s) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-os ghost sm">Filter</button>
    </form>

    <table class="table-os">
      <thead><tr>
        <th>Name</th><th>Contact</th><th>Interest</th><th>Advisor</th>
        <th>Status</th><th>Follow-up</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$leads): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No leads found. Add your first lead to get started.</td></tr>
      <?php else: foreach ($leads as $l): ?>
        <tr>
          <td><strong><?= e($l['name']) ?></strong><br><span class="muted" style="font-size:12px"><?= e(label($l['source'])) ?></span></td>
          <td><?= e($l['phone'] ?: '—') ?><br><span class="muted" style="font-size:12px"><?= e($l['email'] ?: '') ?></span></td>
          <td><?= e($l['product_interest'] ?: '—') ?></td>
          <td><?= e($l['advisor_name'] ?: 'Unassigned') ?></td>
          <td><span class="badge-os b-<?= e($l['status']) ?>"><?= label($l['status']) ?></span></td>
          <td><?= fmt_date($l['follow_up_date']) ?></td>
          <td class="text-right" style="white-space:nowrap">
            <?php if (can('leads.manage')): ?>
              <a class="btn-os ghost sm" href="<?= e(url('advisor/lead-edit.php?id=' . (int)$l['id'])) ?>">Edit</a>
              <form method="post" action="<?= e(url('advisor/leads.php')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <button class="btn-os ghost sm" data-confirm="Delete this lead?">Delete</button>
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
