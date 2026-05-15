<?php
/** AdvisorOS — Follow-up task tracking. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('appointments.manage');

$tid = require_tenant();
$pdo = db();
$me  = current_user();

if (is_post()) {
    csrf_check();
    $act = $_POST['action'] ?? 'save';
    if ($act === 'complete') {
        $fid=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE follow_ups SET status='done', completed_at=NOW() WHERE id=? AND tenant_id=?")
            ->execute([$fid,$tid]);
        audit_log('update','followups',$fid,'Follow-up completed');
        set_flash('success','Follow-up marked done.');
        redirect('advisor/followups.php');
    }
    if ($act === 'delete') {
        $fid=(int)($_POST['id']??0);
        $pdo->prepare('UPDATE follow_ups SET deleted_at=NOW() WHERE id=? AND tenant_id=?')->execute([$fid,$tid]);
        set_flash('success','Follow-up removed.');
        redirect('advisor/followups.php');
    }
    $d=[
        'client_id'  => (int) input('client_id',0) ?: null,
        'assigned_to'=> (int) input('assigned_to',0) ?: $me['id'],
        'title'      => (string) input('title',''),
        'description'=> (string) input('description',''),
        'due_date'   => input('due_date') ?: date('Y-m-d'),
        'priority'   => in_array(input('priority'),['low','medium','high'],true)?input('priority'):'medium',
        'status'     => in_array(input('status'),['pending','done','cancelled'],true)?input('status'):'pending',
    ];
    if ($d['title']==='') { set_flash('danger','Title is required.'); redirect('advisor/followups.php'); }
    $eid=(int)($_POST['id']??0);
    if ($eid) {
        $cols=implode('=?,',array_keys($d)).'=?';
        $pdo->prepare("UPDATE follow_ups SET $cols WHERE id=? AND tenant_id=?")->execute([...array_values($d),$eid,$tid]);
        audit_log('update','followups',$eid,'Follow-up updated');
        set_flash('success','Follow-up updated.');
    } else {
        $cols=implode(',',array_keys($d)); $ph=rtrim(str_repeat('?,',count($d)),',');
        $pdo->prepare("INSERT INTO follow_ups (tenant_id,$cols,created_by) VALUES (?,$ph,?)")
            ->execute([$tid,...array_values($d),$me['id']]);
        audit_log('create','followups',(int)$pdo->lastInsertId(),'Follow-up created');
        set_flash('success','Follow-up created.');
    }
    redirect('advisor/followups.php');
}

$edit=null;
if ($eid=(int)($_GET['edit']??0)) {
    $s=$pdo->prepare('SELECT * FROM follow_ups WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $s->execute([$eid,$tid]); $edit=$s->fetch();
}
$clients=tenant_clients_list();
[$scope,$sp]=advisor_scope('f.assigned_to');
$st=$pdo->prepare(
  "SELECT f.*, c.full_name FROM follow_ups f LEFT JOIN clients c ON c.id=f.client_id
   WHERE f.tenant_id=? AND f.deleted_at IS NULL{$scope}
   ORDER BY f.status='done', f.due_date ASC LIMIT 200");
$st->execute(array_merge([$tid],$sp));
$rows=$st->fetchAll();
$ev=static fn(string $k,$d='')=>e($edit[$k]??$d);

$pageTitle='Follow-ups';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= $edit?'Edit Follow-up':'New Follow-up' ?></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-row"><label>Title *</label><input name="title" value="<?= $ev('title') ?>" required></div>
        <div class="form-row"><label>Client</label><select name="client_id"><option value="">—</option>
          <?php foreach ($clients as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= (int)($edit['client_id']??0)===(int)$cl['id']?'selected':'' ?>><?= e($cl['full_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Due date</label><input type="date" name="due_date" value="<?= $ev('due_date', date('Y-m-d')) ?>"></div>
        <div class="form-row"><label>Priority</label><select name="priority">
          <?php foreach (['low','medium','high'] as $pr): ?><option value="<?= $pr ?>" <?= ($edit['priority']??'medium')===$pr?'selected':'' ?>><?= label($pr) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Status</label><select name="status">
          <?php foreach (['pending','done','cancelled'] as $s): ?><option value="<?= $s ?>" <?= ($edit['status']??'pending')===$s?'selected':'' ?>><?= label($s) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="form-row"><label>Description</label><textarea name="description" rows="2"><?= $ev('description') ?></textarea></div>
      <button class="btn-os"><?= $edit?'Save':'Add follow-up' ?></button>
      <?php if ($edit): ?><a class="btn-os ghost" href="<?= e(url('advisor/followups.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Follow-up Tasks</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Task</th><th>Client</th><th>Due</th><th>Priority</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted" style="padding:24px">No follow-ups.</td></tr>
      <?php else: foreach ($rows as $f):
        $od = $f['status']==='pending' && strtotime($f['due_date'])<strtotime('today'); ?>
        <tr>
          <td><?= e($f['title']) ?></td>
          <td><?= e($f['full_name'] ?: '—') ?></td>
          <td><span class="badge-os <?= $od?'b-overdue':'b-scheduled' ?>"><?= fmt_date($f['due_date']) ?></span></td>
          <td><span class="badge-os b-<?= e($f['priority']) ?>"><?= label($f['priority']) ?></span></td>
          <td><span class="badge-os b-<?= e($f['status']) ?>"><?= label($f['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <?php if ($f['status']==='pending'): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="complete">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn-os sm">Done</button>
            </form>
            <?php endif; ?>
            <a class="btn-os ghost sm" href="<?= e(url('advisor/followups.php?edit='.(int)$f['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Remove follow-up?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
