<?php
/** AdvisorOS — Appointment scheduling. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('appointments.manage');

$tid = require_tenant();
$pdo = db();
$me  = current_user();
$types = ['first_consultation','policy_review','annual_review','investment_review','mortgage_consultation','claim_support'];
$stat  = ['scheduled','completed','cancelled','no_show'];

if (is_post()) {
    csrf_check();
    $act = $_POST['action'] ?? 'save';
    if ($act === 'delete') {
        $aid = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE appointments SET deleted_at=NOW() WHERE id=? AND tenant_id=?')->execute([$aid,$tid]);
        audit_log('delete','appointments',$aid,'Appointment removed');
        set_flash('success','Appointment removed.');
        redirect('advisor/appointments.php');
    }
    $d = [
        'client_id'    => (int) input('client_id',0) ?: null,
        'advisor_id'   => (int) input('advisor_id',0) ?: $me['id'],
        'type'         => in_array(input('type'),$types,true)?input('type'):'first_consultation',
        'title'        => (string) input('title',''),
        'scheduled_at' => input('scheduled_at') ?: date('Y-m-d H:i:s'),
        'location'     => (string) input('location',''),
        'notes'        => (string) input('notes',''),
        'status'       => in_array(input('status'),$stat,true)?input('status'):'scheduled',
    ];
    if ($d['title']==='') { set_flash('danger','Title is required.'); redirect('advisor/appointments.php'); }
    $eid = (int)($_POST['id'] ?? 0);
    if ($eid) {
        $cols = implode('=?,',array_keys($d)).'=?';
        $pdo->prepare("UPDATE appointments SET $cols WHERE id=? AND tenant_id=?")
            ->execute([...array_values($d),$eid,$tid]);
        audit_log('update','appointments',$eid,'Appointment updated');
        set_flash('success','Appointment updated.');
    } else {
        $cols=implode(',',array_keys($d)); $ph=rtrim(str_repeat('?,',count($d)),',');
        $pdo->prepare("INSERT INTO appointments (tenant_id,$cols,created_by) VALUES (?,$ph,?)")
            ->execute([$tid,...array_values($d),$me['id']]);
        audit_log('create','appointments',(int)$pdo->lastInsertId(),'Appointment scheduled');
        set_flash('success','Appointment scheduled.');
    }
    redirect('advisor/appointments.php');
}

$edit = null;
if ($eid = (int)($_GET['edit'] ?? 0)) {
    $s=$pdo->prepare('SELECT * FROM appointments WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $s->execute([$eid,$tid]); $edit=$s->fetch();
}
$clients = tenant_clients_list();
[$scope,$sp]=advisor_scope('a.advisor_id');
$st=$pdo->prepare(
  "SELECT a.*, c.full_name FROM appointments a LEFT JOIN clients c ON c.id=a.client_id
   WHERE a.tenant_id=? AND a.deleted_at IS NULL{$scope}
   ORDER BY a.scheduled_at DESC LIMIT 200");
$st->execute(array_merge([$tid],$sp));
$appts=$st->fetchAll();
$ev=static fn(string $k,$d='')=>e($edit[$k]??$d);

$pageTitle='Appointments';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= $edit?'Edit Appointment':'Schedule Appointment' ?></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-row"><label>Title *</label><input name="title" value="<?= $ev('title') ?>" required></div>
        <div class="form-row"><label>Type</label><select name="type">
          <?php foreach ($types as $t): ?><option value="<?= $t ?>" <?= ($edit['type']??'')===$t?'selected':'' ?>><?= label($t) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Client</label><select name="client_id">
          <option value="">—</option>
          <?php foreach ($clients as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= (int)($edit['client_id']??0)===(int)$cl['id']?'selected':'' ?>><?= e($cl['full_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>When *</label><input type="datetime-local" name="scheduled_at"
          value="<?= e($edit ? date('Y-m-d\TH:i', strtotime($edit['scheduled_at'])) : '') ?>" required></div>
        <div class="form-row"><label>Location</label><input name="location" value="<?= $ev('location') ?>"></div>
        <div class="form-row"><label>Status</label><select name="status">
          <?php foreach ($stat as $s): ?><option value="<?= $s ?>" <?= ($edit['status']??'scheduled')===$s?'selected':'' ?>><?= label($s) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="form-row"><label>Notes</label><textarea name="notes" rows="2"><?= $ev('notes') ?></textarea></div>
      <button class="btn-os"><?= $edit?'Save':'Schedule' ?></button>
      <?php if ($edit): ?><a class="btn-os ghost" href="<?= e(url('advisor/appointments.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Appointments</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>When</th><th>Title</th><th>Type</th><th>Client</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$appts): ?>
        <tr><td colspan="6" class="muted" style="padding:24px">No appointments.</td></tr>
      <?php else: foreach ($appts as $a): ?>
        <tr>
          <td><?= fmt_date($a['scheduled_at'],'d M Y H:i') ?></td>
          <td><?= e($a['title']) ?></td>
          <td><?= label($a['type']) ?></td>
          <td><?= e($a['full_name'] ?: '—') ?></td>
          <td><span class="badge-os b-<?= e($a['status']) ?>"><?= label($a['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os ghost sm" href="<?= e(url('advisor/appointments.php?edit='.(int)$a['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Remove appointment?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
