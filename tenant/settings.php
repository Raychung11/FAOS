<?php
/** AdvisorOS — Company settings & branding. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('tenant.manage');

$tid = require_tenant();
$pdo = db();

if (is_post()) {
    csrf_check();
    $name   = (string) input('company_name','');
    $reg    = (string) input('registration_no','');
    $email  = (string) input('email','');
    $phone  = (string) input('phone','');
    $addr   = (string) input('address','');
    $primary= preg_match('/^#[0-9A-Fa-f]{6}$/', (string)input('brand_primary')) ? input('brand_primary') : '#0B1F3A';
    $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)input('brand_accent')) ? input('brand_accent') : '#C9A227';

    if ($name === '') {
        set_flash('danger','Company name is required.');
        redirect('tenant/settings.php');
    }
    $st = $pdo->prepare(
        'UPDATE tenants SET company_name=?,registration_no=?,email=?,phone=?,
         address=?,brand_primary=?,brand_accent=? WHERE id=?'
    );
    $st->execute([$name,$reg,$email,$phone,$addr,$primary,$accent,$tid]);
    audit_log('update','tenant',$tid,'Company settings updated');
    set_flash('success','Company settings saved.');
    redirect('tenant/settings.php');
}

$t = current_tenant();
$v = static fn(string $k,$d='') => e($t[$k] ?? $d);

$pageTitle = 'Company Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:760px">
  <div class="card-os-head">Company &amp; Branding</div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Company name *</label><input name="company_name" value="<?= $v('company_name') ?>" required></div>
        <div class="form-row"><label>Registration no.</label><input name="registration_no" value="<?= $v('registration_no') ?>"></div>
        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
        <div class="form-row"><label>Phone</label><input name="phone" value="<?= $v('phone') ?>"></div>
        <div class="form-row"><label>Brand primary (navy)</label><input type="color" name="brand_primary" value="<?= $v('brand_primary','#0B1F3A') ?>"></div>
        <div class="form-row"><label>Brand accent (gold)</label><input type="color" name="brand_accent" value="<?= $v('brand_accent','#C9A227') ?>"></div>
      </div>
      <div class="form-row"><label>Address</label><input name="address" value="<?= $v('address') ?>"></div>
      <button class="btn-os">Save settings</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
