<?php
/** AdvisorOS — Super Admin tenant management. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('super_admin');
require_permission('platform.manage');

$pdo = db();
$subStatuses = ['trial','active','past_due','suspended','cancelled'];
$plans = $pdo->query("SELECT id,name FROM subscription_plans WHERE is_active=1 ORDER BY price_monthly")->fetchAll();

if (is_post()) {
    csrf_check();
    $act = $_POST['action'] ?? 'save';

    if ($act === 'toggle') {
        $id=(int)($_POST['id']??0);
        $pdo->prepare("UPDATE tenants SET status=IF(status='active','inactive','active') WHERE id=?")
            ->execute([$id]);
        audit_log('toggle_status','tenant',$id,'Tenant status toggled');
        set_flash('success','Tenant status updated.');
        redirect('superadmin/tenants.php');
    }

    $eid    = (int) ($_POST['id'] ?? 0);
    $name   = (string) input('company_name','');
    $slug   = strtolower(trim(preg_replace('/[^a-z0-9-]+/i','-', (string) input('slug','')), '-'));
    $reg    = (string) input('registration_no','');
    $email  = (string) input('email','');
    $planId = (int) input('plan_id',0) ?: null;
    $sub    = in_array(input('subscription_status'),$subStatuses,true)?input('subscription_status'):'trial';
    $exp    = input('expires_at') ?: null;

    if ($name === '' || $slug === '') {
        set_flash('danger','Company name and slug are required.');
        redirect('superadmin/tenants.php');
    }
    if ($eid) {
        try {
            $pdo->prepare(
                'UPDATE tenants SET company_name=?,slug=?,registration_no=?,email=?,
                 plan_id=?,subscription_status=?,expires_at=? WHERE id=?'
            )->execute([$name,$slug,$reg,$email,$planId,$sub,$exp,$eid]);
        } catch (PDOException $e) {
            set_flash('danger','That slug is already in use.');
            redirect('superadmin/tenants.php');
        }
        audit_log('update','tenant',$eid,'Tenant updated');
        set_flash('success','Tenant updated.');
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO tenants (company_name,slug,registration_no,email,plan_id,
                 subscription_status,expires_at,status)
                 VALUES (?,?,?,?,?,?,?,"active")'
            )->execute([$name,$slug,$reg,$email,$planId,$sub,$exp]);
        } catch (PDOException $e) {
            set_flash('danger','That slug is already in use.');
            redirect('superadmin/tenants.php');
        }
        audit_log('create','tenant',(int)$pdo->lastInsertId(),'Tenant created');
        set_flash('success','Tenant created.');
    }
    redirect('superadmin/tenants.php');
}

$edit=null;
if ($eid=(int)($_GET['edit']??0)) {
    $s=$pdo->prepare('SELECT * FROM tenants WHERE id=? AND deleted_at IS NULL');
    $s->execute([$eid]); $edit=$s->fetch();
}
$rows=$pdo->query(
  "SELECT t.*, sp.name AS plan_name,
     (SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id AND u.deleted_at IS NULL) users,
     (SELECT COUNT(*) FROM clients c WHERE c.tenant_id=t.id AND c.deleted_at IS NULL) clients
   FROM tenants t LEFT JOIN subscription_plans sp ON sp.id=t.plan_id
   WHERE t.deleted_at IS NULL ORDER BY t.created_at DESC")->fetchAll();
$ev=static fn(string $k,$d='')=>e($edit[$k]??$d);

$pageTitle='Tenant Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= $edit?'Edit Tenant':'Create Tenant' ?></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-row"><label>Company name *</label><input name="company_name" value="<?= $ev('company_name') ?>" required></div>
        <div class="form-row"><label>Slug *</label><input name="slug" value="<?= $ev('slug') ?>" placeholder="acme-advisory" required></div>
        <div class="form-row"><label>Registration no.</label><input name="registration_no" value="<?= $ev('registration_no') ?>"></div>
        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= $ev('email') ?>"></div>
        <div class="form-row"><label>Plan</label><select name="plan_id">
          <option value="">—</option>
          <?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($edit['plan_id']??0)===(int)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Subscription status</label><select name="subscription_status">
          <?php foreach ($subStatuses as $s): ?><option value="<?= $s ?>" <?= ($edit['subscription_status']??'trial')===$s?'selected':'' ?>><?= label($s) ?></option><?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Expires at</label><input type="date" name="expires_at" value="<?= $ev('expires_at') ?>"></div>
      </div>
      <button class="btn-os"><?= $edit?'Save tenant':'Create tenant' ?></button>
      <?php if ($edit): ?><a class="btn-os ghost" href="<?= e(url('superadmin/tenants.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Tenants</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Company</th><th>Plan</th><th>Users</th><th>Clients</th>
        <th>Subscription</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $t): ?>
        <tr>
          <td><strong><?= e($t['company_name']) ?></strong><br><span class="muted" style="font-size:12px"><?= e($t['slug']) ?></span></td>
          <td><?= e($t['plan_name'] ?: '—') ?></td>
          <td><?= (int)$t['users'] ?></td>
          <td><?= (int)$t['clients'] ?></td>
          <td><span class="badge-os b-<?= e($t['subscription_status']) ?>"><?= label($t['subscription_status']) ?></span></td>
          <td><span class="badge-os b-<?= $t['status']==='active'?'active':'suspended' ?>"><?= label($t['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os ghost sm" href="<?= e(url('superadmin/tenants.php?edit='.(int)$t['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn-os ghost sm"><?= $t['status']==='active'?'Suspend':'Activate' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
