<?php
/** AdvisorOS — Advisory case management. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('cases.manage');

$tid = require_tenant();
$pdo = db();
$me  = current_user();
$types = ['insurance_planning','retirement_planning','mortgage_advisory','education_fund','estate_planning','investment_review'];
$stats = ['open','in_review','proposal_drafted','presented','accepted','rejected','closed'];

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '')==='delete') {
        $cid=(int)($_POST['id']??0);
        $pdo->prepare('UPDATE advisory_cases SET deleted_at=NOW() WHERE id=? AND tenant_id=?')->execute([$cid,$tid]);
        set_flash('success','Case removed.');
        redirect('advisor/cases.php');
    }
    $d=[
        'client_id'      => (int) input('client_id',0),
        'advisor_id'     => (int) input('advisor_id',0) ?: $me['id'],
        'type'           => in_array(input('type'),$types,true)?input('type'):'investment_review',
        'objective'      => (string) input('objective',''),
        'current_issue'  => (string) input('current_issue',''),
        'recommendations'=> (string) input('recommendations',''),
        'priority'       => in_array(input('priority'),['low','medium','high'],true)?input('priority'):'medium',
        'status'         => in_array(input('status'),$stats,true)?input('status'):'open',
        'notes'          => (string) input('notes',''),
    ];
    $chk=$pdo->prepare('SELECT id FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $chk->execute([$d['client_id'],$tid]);
    if (!$chk->fetchColumn()) { set_flash('danger','Select a valid client.'); redirect('advisor/cases.php'); }
    $eid=(int)($_POST['id']??0);
    if ($eid) {
        $cols=implode('=?,',array_keys($d)).'=?';
        $pdo->prepare("UPDATE advisory_cases SET $cols WHERE id=? AND tenant_id=?")->execute([...array_values($d),$eid,$tid]);
        audit_log('update','cases',$eid,'Case updated');
        set_flash('success','Case updated.');
    } else {
        $cols=implode(',',array_keys($d)); $ph=rtrim(str_repeat('?,',count($d)),',');
        $pdo->prepare("INSERT INTO advisory_cases (tenant_id,$cols,created_by) VALUES (?,$ph,?)")
            ->execute([$tid,...array_values($d),$me['id']]);
        audit_log('create','cases',(int)$pdo->lastInsertId(),'Case opened');
        set_flash('success','Advisory case opened.');
    }
    redirect('advisor/cases.php');
}

$edit=null;
if ($eid=(int)($_GET['edit']??0)) {
    $s=$pdo->prepare('SELECT * FROM advisory_cases WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $s->execute([$eid,$tid]); $edit=$s->fetch();
}
$clients=tenant_clients_list();
[$scope,$sp]=advisor_scope('ac.advisor_id');
$st=$pdo->prepare(
  "SELECT ac.*, c.full_name FROM advisory_cases ac JOIN clients c ON c.id=ac.client_id
   WHERE ac.tenant_id=? AND ac.deleted_at IS NULL{$scope} ORDER BY ac.created_at DESC LIMIT 200");
$st->execute(array_merge([$tid],$sp));
$rows=$st->fetchAll();
$ev=static fn(string $k,$d='')=>e($edit[$k]??$d);

$pageTitle='Advisory Cases';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= $edit?'Edit Case':'Open Advisory Case' ?></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-row"><label>Client *</label><select name="client_id" required><option value="">—</option>
          <?php foreach ($clients as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= (int)($edit['client_id']??0)===(int)$cl['id']?'selected':'' ?>><?= e($cl['full_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Type</label><select name="type">
          <?php foreach ($types as $t): ?><option value="<?= $t ?>" <?= ($edit['type']??'')===$t?'selected':'' ?>><?= label($t) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Priority</label><select name="priority">
          <?php foreach (['low','medium','high'] as $pr): ?><option value="<?= $pr ?>" <?= ($edit['priority']??'medium')===$pr?'selected':'' ?>><?= label($pr) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Status</label><select name="status">
          <?php foreach ($stats as $s): ?><option value="<?= $s ?>" <?= ($edit['status']??'open')===$s?'selected':'' ?>><?= label($s) ?></option><?php endforeach; ?>
        </select></div>
      </div>
      <div class="form-row"><label>Objective</label><input name="objective" value="<?= $ev('objective') ?>"></div>
      <div class="form-row"><label>Current issue</label><textarea name="current_issue" rows="2"><?= $ev('current_issue') ?></textarea></div>
      <div class="form-row"><label>Recommendations</label><textarea name="recommendations" rows="2"><?= $ev('recommendations') ?></textarea></div>
      <div class="form-row"><label>Notes</label><textarea name="notes" rows="2"><?= $ev('notes') ?></textarea></div>
      <button class="btn-os"><?= $edit?'Save':'Open case' ?></button>
      <?php if ($edit): ?><a class="btn-os ghost" href="<?= e(url('advisor/cases.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Cases</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Client</th><th>Type</th><th>Objective</th><th>Priority</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="muted" style="padding:24px">No advisory cases.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['full_name']) ?></td>
          <td><?= label($r['type']) ?></td>
          <td><?= e($r['objective'] ?: '—') ?></td>
          <td><span class="badge-os b-<?= e($r['priority']) ?>"><?= label($r['priority']) ?></span></td>
          <td><span class="badge-os b-<?= e($r['status']) ?>"><?= label($r['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os ghost sm" href="<?= e(url('advisor/cases.php?edit='.(int)$r['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Remove case?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
