<?php
/** AdvisorOS — Subscription plan management. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('super_admin');
require_permission('platform.manage');

$pdo = db();

if (is_post()) {
    csrf_check();
    $eid = (int) ($_POST['id'] ?? 0);
    $feat = [];
    if ($eid) {
        $fs = $pdo->prepare('SELECT features FROM subscription_plans WHERE id=?');
        $fs->execute([$eid]);
        $feat = is_array($x = json_decode((string) $fs->fetchColumn(), true)) ? $x : [];
    }
    $feat['report_price'] = max(0.0, (float) input('report_price', 15));
    $feat['report_quota'] = max(0,   (int) input('report_quota', 20));
    $d = [
        'name'         => (string) input('name',''),
        'code'         => strtolower(trim(preg_replace('/[^a-z0-9_]+/i','_', (string)input('code','')), '_')),
        'price_monthly'=> (float) input('price_monthly',0),
        'price_yearly' => (float) input('price_yearly',0),
        'max_users'    => (int) input('max_users',5),
        'max_clients'  => (int) input('max_clients',100),
        'storage_mb'   => (int) input('storage_mb',1024),
        'features'     => json_encode($feat),
        'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
    ];
    if ($d['name']==='' || $d['code']==='') {
        set_flash('danger','Name and code are required.');
        redirect('superadmin/subscriptions.php');
    }
    if ($eid) {
        $cols=implode('=?,',array_keys($d)).'=?';
        $pdo->prepare("UPDATE subscription_plans SET $cols WHERE id=?")
            ->execute([...array_values($d),$eid]);
        audit_log('update','platform',$eid,'Plan updated');
        set_flash('success','Plan updated.');
    } else {
        $cols=implode(',',array_keys($d)); $ph=rtrim(str_repeat('?,',count($d)),',');
        try {
            $pdo->prepare("INSERT INTO subscription_plans ($cols) VALUES ($ph)")
                ->execute(array_values($d));
        } catch (PDOException $e) {
            set_flash('danger','That plan code already exists.');
            redirect('superadmin/subscriptions.php');
        }
        audit_log('create','platform',(int)$pdo->lastInsertId(),'Plan created');
        set_flash('success','Plan created.');
    }
    redirect('superadmin/subscriptions.php');
}

$edit=null;
if ($eid=(int)($_GET['edit']??0)) {
    $s=$pdo->prepare('SELECT * FROM subscription_plans WHERE id=?');
    $s->execute([$eid]); $edit=$s->fetch();
}
$plans=$pdo->query("SELECT sp.*,
   (SELECT COUNT(*) FROM tenants t WHERE t.plan_id=sp.id AND t.deleted_at IS NULL) tenants
   FROM subscription_plans sp ORDER BY sp.price_monthly")->fetchAll();
$ev=static fn(string $k,$d='')=>e($edit[$k]??$d);
$ef=is_array($jf=json_decode((string)($edit['features']??''),true))?$jf:[];

$pageTitle='Subscription Plans';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= $edit?'Edit Plan':'Create Plan' ?></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-row"><label>Name *</label><input name="name" value="<?= $ev('name') ?>" required></div>
        <div class="form-row"><label>Code *</label><input name="code" value="<?= $ev('code') ?>" required></div>
        <div class="form-row"><label>Price / month</label><input type="number" step="0.01" name="price_monthly" value="<?= $ev('price_monthly','0') ?>"></div>
        <div class="form-row"><label>Price / year</label><input type="number" step="0.01" name="price_yearly" value="<?= $ev('price_yearly','0') ?>"></div>
        <div class="form-row"><label>Max users</label><input type="number" name="max_users" value="<?= $ev('max_users','5') ?>"></div>
        <div class="form-row"><label>Max clients</label><input type="number" name="max_clients" value="<?= $ev('max_clients','100') ?>"></div>
        <div class="form-row"><label>Storage (MB)</label><input type="number" name="storage_mb" value="<?= $ev('storage_mb','1024') ?>"></div>
        <div class="form-row"><label>Free reports / month</label><input type="number" name="report_quota" value="<?= e($ef['report_quota'] ?? 20) ?>"></div>
        <div class="form-row"><label>Price / extra report (RM)</label><input type="number" step="0.01" name="report_price" value="<?= e($ef['report_price'] ?? 15) ?>"></div>
        <div class="form-row" style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:8px;align-items:center;font-weight:500;margin:0">
            <input type="checkbox" name="is_active" value="1" style="width:auto" <?= ($edit['is_active']??1)?'checked':'' ?>> Active
          </label></div>
      </div>
      <button class="btn-os"><?= $edit?'Save plan':'Create plan' ?></button>
      <?php if ($edit): ?><a class="btn-os ghost" href="<?= e(url('superadmin/subscriptions.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Plans</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Plan</th><th>Monthly</th><th>Yearly</th><th>Limits</th>
        <th>Reports</th><th>Tenants</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): ?>
        <?php $pf = is_array($x = json_decode((string)($p['features'] ?? ''), true)) ? $x : []; ?>
        <tr>
          <td><strong><?= e($p['name']) ?></strong><br><span class="muted" style="font-size:12px"><?= e($p['code']) ?></span></td>
          <td><?= money($p['price_monthly']) ?></td>
          <td><?= money($p['price_yearly']) ?></td>
          <td><?= (int)$p['max_users'] ?> users · <?= (int)$p['max_clients'] ?> clients · <?= (int)$p['storage_mb'] ?> MB</td>
          <td><?= (int)($pf['report_quota'] ?? 20) ?> free · RM <?= money($pf['report_price'] ?? 15) ?>/extra</td>
          <td><?= (int)$p['tenants'] ?></td>
          <td><span class="badge-os b-<?= $p['is_active']?'active':'suspended' ?>"><?= $p['is_active']?'Active':'Inactive' ?></span></td>
          <td class="text-right"><a class="btn-os ghost sm" href="<?= e(url('superadmin/subscriptions.php?edit='.(int)$p['id'])) ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
