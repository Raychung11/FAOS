<?php
/** AdvisorOS — Create / edit a policy. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('policies.manage');

$tid = require_tenant();
$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$preClient = (int) ($_GET['client_id'] ?? 0);
$p = null;

if ($id) {
    $st = $pdo->prepare('SELECT * FROM policies WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $st->execute([$id, $tid]);
    $p = $st->fetch();
    if (!$p) { http_response_code(404); exit('Policy not found.'); }
}

$clients = tenant_clients_list();
$cats = ['insurance','takaful','medical_card','investment_linked','unit_trust','mortgage','estate_planning','prs'];
$freqs = ['monthly','quarterly','semi_annual','annual','single'];
$stats = ['active','lapsed','matured','surrendered','pending'];

if (is_post()) {
    csrf_check();
    $d = [
        'client_id'         => (int) input('client_id',0),
        'category'          => in_array(input('category'),$cats,true)?input('category'):'insurance',
        'provider'          => (string) input('provider',''),
        'policy_number'     => (string) input('policy_number',''),
        'premium'           => (float) input('premium',0),
        'payment_frequency' => in_array(input('payment_frequency'),$freqs,true)?input('payment_frequency'):'annual',
        'coverage_amount'   => (float) input('coverage_amount',0),
        'start_date'        => input('start_date') ?: null,
        'renewal_date'      => input('renewal_date') ?: null,
        'beneficiary'       => (string) input('beneficiary',''),
        'status'            => in_array(input('status'),$stats,true)?input('status'):'active',
        'review_notes'      => (string) input('review_notes',''),
    ];
    // Confirm the client belongs to this tenant.
    $chk = $pdo->prepare('SELECT id FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $chk->execute([$d['client_id'], $tid]);
    if (!$chk->fetchColumn()) {
        set_flash('danger','Please select a valid client.');
        redirect($id?"advisor/policy-edit.php?id=$id":'advisor/policy-edit.php');
    }
    if ($id) {
        $cols = implode('=?,', array_keys($d)).'=?';
        $pdo->prepare("UPDATE policies SET $cols WHERE id=? AND tenant_id=?")
            ->execute([...array_values($d), $id, $tid]);
        audit_log('update','policies',$id,'Policy updated');
        set_flash('success','Policy updated.');
    } else {
        $cols = implode(',', array_keys($d));
        $ph = rtrim(str_repeat('?,', count($d)),',');
        $pdo->prepare("INSERT INTO policies (tenant_id,$cols,created_by) VALUES (?,$ph,?)")
            ->execute([$tid, ...array_values($d), current_user()['id']]);
        $id = (int)$pdo->lastInsertId();
        audit_log('create','policies',$id,'Policy added');
        set_flash('success','Policy added.');
    }
    redirect('advisor/client-view.php?id=' . $d['client_id']);
}

$v = static fn (string $k,$d='') => e($p[$k] ?? $d);
$pageTitle = $id ? 'Edit Policy' : 'Add Policy';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:860px">
  <div class="card-os-head"><?= $id?'Edit Policy':'Add Policy' ?>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/policies.php')) ?>">Back</a></div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Client *</label>
          <select name="client_id" required>
            <option value="">— select —</option>
            <?php foreach ($clients as $cl): ?>
              <option value="<?= (int)$cl['id'] ?>" <?= (int)($p['client_id']??$preClient)===(int)$cl['id']?'selected':'' ?>><?= e($cl['full_name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row"><label>Category</label>
          <select name="category"><?php foreach ($cats as $cat): ?>
            <option value="<?= $cat ?>" <?= ($p['category']??'')===$cat?'selected':'' ?>><?= label($cat) ?></option>
          <?php endforeach; ?></select></div>
        <div class="form-row"><label>Provider</label><input name="provider" value="<?= $v('provider') ?>"></div>
        <div class="form-row"><label>Policy number</label><input name="policy_number" value="<?= $v('policy_number') ?>"></div>
        <div class="form-row"><label>Premium</label><input type="number" step="0.01" name="premium" value="<?= $v('premium','0') ?>"></div>
        <div class="form-row"><label>Payment frequency</label>
          <select name="payment_frequency"><?php foreach ($freqs as $fq): ?>
            <option value="<?= $fq ?>" <?= ($p['payment_frequency']??'annual')===$fq?'selected':'' ?>><?= label($fq) ?></option>
          <?php endforeach; ?></select></div>
        <div class="form-row"><label>Coverage amount</label><input type="number" step="0.01" name="coverage_amount" value="<?= $v('coverage_amount','0') ?>"></div>
        <div class="form-row"><label>Beneficiary</label><input name="beneficiary" value="<?= $v('beneficiary') ?>"></div>
        <div class="form-row"><label>Start date</label><input type="date" name="start_date" value="<?= $v('start_date') ?>"></div>
        <div class="form-row"><label>Renewal date</label><input type="date" name="renewal_date" value="<?= $v('renewal_date') ?>"></div>
        <div class="form-row"><label>Status</label>
          <select name="status"><?php foreach ($stats as $s): ?>
            <option value="<?= $s ?>" <?= ($p['status']??'active')===$s?'selected':'' ?>><?= label($s) ?></option>
          <?php endforeach; ?></select></div>
      </div>
      <div class="form-row"><label>Policy review notes</label><textarea name="review_notes" rows="3"><?= $v('review_notes') ?></textarea></div>
      <button class="btn-os"><?= $id?'Save changes':'Add policy' ?></button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
