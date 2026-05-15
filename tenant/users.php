<?php
/** AdvisorOS — Tenant user management (invite, edit, reset, deactivate). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('users.manage');

$tid = require_tenant();
$pdo = db();

// Roles assignable within a tenant (never super_admin).
$roleRows = $pdo->query(
    "SELECT id,code,name FROM roles WHERE code IN
     ('tenant_admin','agency_leader','financial_advisor','compliance_officer','client')
     ORDER BY id"
)->fetchAll();
$roleById = [];
foreach ($roleRows as $r) { $roleById[(int)$r['id']] = $r; }

if (is_post()) {
    csrf_check();
    $act = $_POST['action'] ?? 'save';

    if ($act === 'reset') {
        $uid = (int) ($_POST['id'] ?? 0);
        $tmp = 'Reset@' . random_int(10000,99999);
        $st = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND tenant_id=?');
        $st->execute([password_hash($tmp, PASSWORD_DEFAULT), $uid, $tid]);
        audit_log('password_reset','users',$uid,'Admin reset user password');
        set_flash('warning',"Temporary password set: {$tmp} — share securely and ask the user to change it.");
        redirect('tenant/users.php');
    }
    if ($act === 'toggle') {
        $uid = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare(
            "UPDATE users SET status = IF(status='active','inactive','active')
             WHERE id=? AND tenant_id=? AND id <> ?"
        );
        $st->execute([$uid, $tid, current_user()['id']]);
        audit_log('toggle_status','users',$uid,'User status toggled');
        set_flash('success','User status updated.');
        redirect('tenant/users.php');
    }

    // Create / update
    $eid   = (int) ($_POST['id'] ?? 0);
    $name  = (string) input('name','');
    $email = strtolower((string) input('email',''));
    $phone = (string) input('phone','');
    $roleId= (int) input('role_id',0);
    $team  = (string) input('team','');
    $branch= (string) input('branch','');

    if ($name === '' || !is_valid_email($email) || !isset($roleById[$roleId])) {
        set_flash('danger','Name, a valid email and a valid role are required.');
        redirect('tenant/users.php');
    }

    if ($eid) {
        // Ensure the target user is within this tenant.
        $chk = $pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=?');
        $chk->execute([$eid, $tid]);
        if (!$chk->fetchColumn()) { http_response_code(404); exit('User not found.'); }
        $st = $pdo->prepare(
            'UPDATE users SET name=?,email=?,phone=?,role_id=?,team=?,branch=?
             WHERE id=? AND tenant_id=?'
        );
        try {
            $st->execute([$name,$email,$phone,$roleId,$team,$branch,$eid,$tid]);
        } catch (PDOException $e) {
            set_flash('danger','That email is already in use.');
            redirect('tenant/users.php');
        }
        audit_log('update','users',$eid,'User updated');
        set_flash('success','User updated.');
    } else {
        $tmp = 'Welcome@' . random_int(10000,99999);
        $st = $pdo->prepare(
            'INSERT INTO users (tenant_id,role_id,name,email,phone,team,branch,
             password_hash,status,created_by)
             VALUES (?,?,?,?,?,?,?,?,"invited",?)'
        );
        try {
            $st->execute([$tid,$roleId,$name,$email,$phone,$team,$branch,
                password_hash($tmp,PASSWORD_DEFAULT),current_user()['id']]);
        } catch (PDOException $e) {
            set_flash('danger','That email is already in use.');
            redirect('tenant/users.php');
        }
        audit_log('create','users',(int)$pdo->lastInsertId(),'User invited');
        set_flash('success',"User invited. Temporary password: {$tmp}");
    }
    redirect('tenant/users.php');
}

$edit = null;
if ($eid = (int)($_GET['edit'] ?? 0)) {
    $s = $pdo->prepare('SELECT * FROM users WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $s->execute([$eid, $tid]); $edit = $s->fetch();
}

$st = $pdo->prepare(
    'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id
     WHERE u.tenant_id=? AND u.deleted_at IS NULL ORDER BY u.name'
);
$st->execute([$tid]);
$users = $st->fetchAll();
$ev = static fn(string $k,$d='') => e($edit[$k] ?? $d);

$pageTitle = 'User Management';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= $edit?'Edit User':'Invite User' ?></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-grid">
        <div class="form-row"><label>Name *</label><input name="name" value="<?= $ev('name') ?>" required></div>
        <div class="form-row"><label>Email *</label><input type="email" name="email" value="<?= $ev('email') ?>" required></div>
        <div class="form-row"><label>Phone</label><input name="phone" value="<?= $ev('phone') ?>"></div>
        <div class="form-row"><label>Role *</label><select name="role_id" required>
          <?php foreach ($roleRows as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= (int)($edit['role_id']??0)===(int)$r['id']?'selected':'' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div class="form-row"><label>Team</label><input name="team" value="<?= $ev('team') ?>"></div>
        <div class="form-row"><label>Branch</label><input name="branch" value="<?= $ev('branch') ?>"></div>
      </div>
      <button class="btn-os"><?= $edit?'Save user':'Invite user' ?></button>
      <?php if ($edit): ?><a class="btn-os ghost" href="<?= e(url('tenant/users.php')) ?>">Cancel</a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Users</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Team</th>
        <th>Last login</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><?= e($u['role_name']) ?></td>
          <td><?= e($u['team'] ?: '—') ?></td>
          <td><?= $u['last_login_at'] ? fmt_date($u['last_login_at'],'d M Y H:i') : '—' ?></td>
          <td><span class="badge-os b-<?= e($u['status']) ?>"><?= label($u['status']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os ghost sm" href="<?= e(url('tenant/users.php?edit='.(int)$u['id'])) ?>">Edit</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="reset">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Reset this user's password?">Reset PW</button>
            </form>
            <?php if ((int)$u['id'] !== (int)current_user()['id']): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <button class="btn-os ghost sm"><?= $u['status']==='active'?'Deactivate':'Activate' ?></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
