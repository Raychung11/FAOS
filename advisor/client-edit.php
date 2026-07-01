<?php
/** AdvisorOS — Create / edit a full client profile. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('clients.manage');

$tid = require_tenant();
$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$c   = null;

if ($id) {
    $st = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $st->execute([$id, $tid]);
    $c = $st->fetch();
    if (!$c) { http_response_code(404); exit('Client not found.'); }
}

$advStmt = $pdo->prepare(
    "SELECT u.id,u.name FROM users u JOIN roles r ON r.id=u.role_id
     WHERE u.tenant_id=? AND u.deleted_at IS NULL AND u.status='active'
       AND r.code IN ('financial_advisor','agency_leader','tenant_admin') ORDER BY u.name"
);
$advStmt->execute([$tid]);
$advisors = $advStmt->fetchAll();

$risks = ['conservative','moderate_conservative','balanced','growth','aggressive'];

if (is_post()) {
    csrf_check();
    $d = [
        'full_name'      => (string) input('full_name',''),
        'nric_passport'  => (string) input('nric_passport',''),
        'dob'            => input('dob') ?: null,
        'gender'         => in_array(input('gender'),['male','female','other'],true)?input('gender'):null,
        'marital_status' => in_array(input('marital_status'),['single','married','divorced','widowed'],true)?input('marital_status'):null,
        'phone'          => (string) input('phone',''),
        'email'          => (string) input('email',''),
        'address'        => (string) input('address',''),
        'occupation'     => (string) input('occupation',''),
        'employer'       => (string) input('employer',''),
        'industry'       => (string) input('industry',''),
        'dependents'     => (int) input('dependents',0),
        'spouse_name'    => (string) input('spouse_name',''),
        'children_info'  => (string) input('children_info',''),
        'risk_appetite'  => in_array(input('risk_appetite'),$risks,true)?input('risk_appetite'):null,
        'financial_goals'=> (string) input('financial_goals',''),
        'advisor_id'     => (int) input('advisor_id',0) ?: null,
        'next_review_date'=> input('next_review_date') ?: null,
        'status'         => input('status')==='inactive'?'inactive':'active',
    ];
    flash_old($_POST);

    if ($d['full_name'] === '') {
        set_flash('danger','Client full name is required.');
        redirect($id?"advisor/client-edit.php?id=$id":'advisor/client-edit.php');
    }
    if ($d['email'] !== '' && !is_valid_email($d['email'])) {
        set_flash('danger','Please enter a valid email address.');
        redirect($id?"advisor/client-edit.php?id=$id":'advisor/client-edit.php');
    }

    if ($id) {
        $cols = implode('=?,', array_keys($d)) . '=?';
        $st = $pdo->prepare("UPDATE clients SET $cols WHERE id=? AND tenant_id=?");
        $st->execute([...array_values($d), $id, $tid]);
        audit_log('update','clients',$id,'Client profile updated');
        set_flash('success','Client profile updated.');
    } else {
        $cols = implode(',', array_keys($d));
        $ph   = rtrim(str_repeat('?,', count($d)), ',');
        $st = $pdo->prepare(
            "INSERT INTO clients (tenant_id,$cols,created_by) VALUES (?,$ph,?)"
        );
        $st->execute([$tid, ...array_values($d), current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        audit_log('create','clients',$id,'Client created');
        set_flash('success','Client created.');
    }
    clear_old();
    redirect('advisor/client-view.php?id=' . $id);
}

$v = static fn (string $k, $d='') => e($c[$k] ?? old($k,$d));
$sel = static fn (string $k, string $val) => (($c[$k] ?? old($k))===$val?'selected':'');
$pageTitle = $id ? 'Edit Client' : 'New Client';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:1000px">
  <div class="card-os-head"><?= $id?'Edit Client':'New Client' ?>
    <a class="btn-os ghost sm" href="<?= e(url($id?'advisor/client-view.php?id='.$id:'advisor/clients.php')) ?>">Back</a>
  </div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <h4 style="color:var(--navy);margin:0 0 12px">Personal</h4>
      <div class="form-grid">
        <div class="form-row"><label>Full name *</label><input name="full_name" value="<?= $v('full_name') ?>" required></div>
        <div class="form-row"><label>NRIC / Passport</label><input name="nric_passport" value="<?= $v('nric_passport') ?>"></div>
        <div class="form-row"><label>Date of birth</label><input type="date" name="dob" value="<?= $v('dob') ?>"></div>
        <div class="form-row"><label>Gender</label>
          <select name="gender"><option value="">—</option>
            <option value="male" <?= $sel('gender','male') ?>>Male</option>
            <option value="female" <?= $sel('gender','female') ?>>Female</option>
            <option value="other" <?= $sel('gender','other') ?>>Other</option>
          </select></div>
        <div class="form-row"><label>Marital status</label>
          <select name="marital_status"><option value="">—</option>
            <?php foreach (['single','married','divorced','widowed'] as $m): ?>
              <option value="<?= $m ?>" <?= $sel('marital_status',$m) ?>><?= label($m) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row"><label>Dependents</label><input type="number" min="0" name="dependents" value="<?= $v('dependents','0') ?>"></div>
      </div>

      <h4 style="color:var(--navy);margin:18px 0 12px">Contact</h4>
      <div class="form-grid">
        <div class="form-row"><label>Phone</label><input name="phone" value="<?= $v('phone') ?>"></div>
        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      </div>
      <div class="form-row"><label>Address</label><input name="address" value="<?= $v('address') ?>"></div>

      <h4 style="color:var(--navy);margin:18px 0 12px">Career &amp; Family</h4>
      <div class="form-grid">
        <div class="form-row"><label>Occupation</label><input name="occupation" value="<?= $v('occupation') ?>"></div>
        <div class="form-row"><label>Employer</label><input name="employer" value="<?= $v('employer') ?>"></div>
        <div class="form-row"><label>Industry</label><input name="industry" value="<?= $v('industry') ?>"></div>
        <div class="form-row"><label>Spouse name</label><input name="spouse_name" value="<?= $v('spouse_name') ?>"></div>
      </div>
      <div class="form-row"><label>Children / dependents info</label><input name="children_info" value="<?= $v('children_info') ?>"></div>

      <h4 style="color:var(--navy);margin:18px 0 12px">Advisory</h4>
      <div class="form-grid">
        <div class="form-row"><label>Risk appetite</label>
          <select name="risk_appetite"><option value="">—</option>
            <?php foreach ($risks as $r): ?>
              <option value="<?= $r ?>" <?= $sel('risk_appetite',$r) ?>><?= label($r) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row"><label>Advisor in charge</label>
          <select name="advisor_id"><option value="">—</option>
            <?php foreach ($advisors as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (int)($c['advisor_id']??old('advisor_id'))===(int)$a['id']?'selected':'' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row"><label>Next review date</label><input type="date" name="next_review_date" value="<?= $v('next_review_date') ?>"></div>
        <div class="form-row"><label>Status</label>
          <select name="status">
            <option value="active" <?= $sel('status','active') ?>>Active</option>
            <option value="inactive" <?= $sel('status','inactive') ?>>Inactive</option>
          </select></div>
      </div>
      <div class="form-row"><label>Financial goals</label><textarea name="financial_goals" rows="3"><?= $v('financial_goals') ?></textarea></div>

      <button class="btn-os"><?= $id?'Save changes':'Create client' ?></button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
