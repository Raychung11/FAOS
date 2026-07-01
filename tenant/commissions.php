<?php
/** AdvisorOS — Commission management. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('commissions.manage');

$tid = require_tenant();
$pdo = db();
$stats = ['pending','approved','paid','disputed'];

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '')==='status') {
        $cid=(int)($_POST['id']??0);
        $ns = in_array($_POST['status']??'',$stats,true)?$_POST['status']:'pending';
        $paid = $ns==='paid' ? date('Y-m-d') : null;
        $pdo->prepare('UPDATE commissions SET status=?, paid_at=? WHERE id=? AND tenant_id=?')
            ->execute([$ns,$paid,$cid,$tid]);
        audit_log('update','commissions',$cid,"Commission marked $ns");
        set_flash('success','Commission status updated.');
        redirect('tenant/commissions.php');
    }
    $d=[
        'advisor_id'    => (int) input('advisor_id',0) ?: null,
        'client_id'     => (int) input('client_id',0) ?: null,
        'sale_amount'   => (float) input('sale_amount',0),
        'premium'       => (float) input('premium',0),
        'commission'    => (float) input('commission',0),
        'override_amount'=> (float) input('override_amount',0),
        'status'        => in_array(input('status'),$stats,true)?input('status'):'pending',
    ];
    $cols=implode(',',array_keys($d)); $ph=rtrim(str_repeat('?,',count($d)),',');
    $pdo->prepare("INSERT INTO commissions (tenant_id,$cols,created_by) VALUES (?,$ph,?)")
        ->execute([$tid,...array_values($d),current_user()['id']]);
    audit_log('create','commissions',(int)$pdo->lastInsertId(),'Commission recorded');
    set_flash('success','Commission recorded.');
    redirect('tenant/commissions.php');
}

$advisors = $pdo->prepare(
  "SELECT u.id,u.name FROM users u JOIN roles r ON r.id=u.role_id
   WHERE u.tenant_id=? AND u.deleted_at IS NULL AND r.code IN
   ('financial_advisor','agency_leader','tenant_admin') ORDER BY u.name");
$advisors->execute([$tid]);
$adv=$advisors->fetchAll();
$clients=tenant_clients_list();

$st=$pdo->prepare(
  "SELECT cm.*, u.name AS advisor, c.full_name FROM commissions cm
   LEFT JOIN users u ON u.id=cm.advisor_id
   LEFT JOIN clients c ON c.id=cm.client_id
   WHERE cm.tenant_id=? AND cm.deleted_at IS NULL ORDER BY cm.created_at DESC LIMIT 200");
$st->execute([$tid]);
$rows=$st->fetchAll();

$totals=$pdo->prepare("SELECT
   SUM(commission) tot, SUM(IF(status='paid',commission,0)) paid,
   SUM(IF(status='pending',commission,0)) pend FROM commissions
   WHERE tenant_id=? AND deleted_at IS NULL");
$totals->execute([$tid]);
$tt=$totals->fetch();

$pageTitle='Commissions';
require __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Total Commission</div><div class="stat-value"><?= money($tt['tot']??0) ?></div></div>
  <div class="stat accent"><div class="stat-label">Paid</div><div class="stat-value"><?= money($tt['paid']??0) ?></div></div>
  <div class="stat accent"><div class="stat-label">Pending</div><div class="stat-value"><?= money($tt['pend']??0) ?></div></div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Record Commission</div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Advisor</label><select name="advisor_id"><option value="">—</option>
          <?php foreach ($adv as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Client</label><select name="client_id"><option value="">—</option>
          <?php foreach ($clients as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= e($cl['full_name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Sale amount</label><input type="number" step="0.01" name="sale_amount" value="0"></div>
        <div class="form-row"><label>Premium</label><input type="number" step="0.01" name="premium" value="0"></div>
        <div class="form-row"><label>Commission</label><input type="number" step="0.01" name="commission" value="0"></div>
        <div class="form-row"><label>Override</label><input type="number" step="0.01" name="override_amount" value="0"></div>
      </div>
      <button class="btn-os">Record</button>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Commission Records</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Advisor</th><th>Client</th><th>Premium</th><th>Commission</th>
        <th>Override</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No commissions recorded.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['advisor'] ?: '—') ?></td>
          <td><?= e($r['full_name'] ?: '—') ?></td>
          <td><?= money($r['premium']) ?></td>
          <td><?= money($r['commission']) ?></td>
          <td><?= money($r['override_amount']) ?></td>
          <td><span class="badge-os b-<?= e($r['status']) ?>"><?= label($r['status']) ?></span></td>
          <td class="text-right">
            <form method="post" style="display:flex;gap:6px;justify-content:flex-end">
              <?= csrf_field() ?><input type="hidden" name="action" value="status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <select name="status" style="padding:5px 8px;border:1px solid var(--line);border-radius:8px">
                <?php foreach ($stats as $s): ?><option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= label($s) ?></option><?php endforeach; ?>
              </select>
              <button class="btn-os sm">Update</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
