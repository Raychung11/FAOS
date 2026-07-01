<?php
/** AdvisorOS — Create / edit a lead, with convert-to-client. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('leads.manage');

$tid = require_tenant();
$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$lead = null;

if ($id) {
    $st = $pdo->prepare('SELECT * FROM leads WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $st->execute([$id, $tid]);
    $lead = $st->fetch();
    if (!$lead) { http_response_code(404); exit('Lead not found.'); }
}

// Advisors in this tenant for assignment
$advStmt = $pdo->prepare(
    "SELECT u.id,u.name FROM users u JOIN roles r ON r.id=u.role_id
     WHERE u.tenant_id=? AND u.deleted_at IS NULL AND u.status='active'
       AND r.code IN ('financial_advisor','agency_leader','tenant_admin')
     ORDER BY u.name"
);
$advStmt->execute([$tid]);
$advisors = $advStmt->fetchAll();

$statuses = ['new','contacted','qualified','appointment_set','proposal_sent','converted','lost'];

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'convert' && $lead) {
        // Create a client from the lead and link them.
        $ins = $pdo->prepare(
            'INSERT INTO clients (tenant_id, full_name, phone, email, advisor_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, "active", ?)'
        );
        $ins->execute([$tid, $lead['name'], $lead['phone'], $lead['email'],
            $lead['assigned_to'] ?: current_user()['id'], current_user()['id']]);
        $clientId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE leads SET status="converted", converted_client_id=? WHERE id=? AND tenant_id=?')
            ->execute([$clientId, $id, $tid]);
        audit_log('convert', 'leads', $id, "Lead converted to client #$clientId");
        set_flash('success', 'Lead converted to a client.');
        redirect('advisor/client-edit.php?id=' . $clientId);
    }

    $data = [
        'name'             => (string) input('name', ''),
        'phone'            => (string) input('phone', ''),
        'email'            => (string) input('email', ''),
        'source'           => (string) input('source', ''),
        'product_interest' => (string) input('product_interest', ''),
        'budget_range'     => (string) input('budget_range', ''),
        'assigned_to'      => (int) input('assigned_to', 0) ?: null,
        'status'           => in_array(input('status'), $statuses, true) ? input('status') : 'new',
        'follow_up_date'   => input('follow_up_date') ?: null,
        'notes'            => (string) input('notes', ''),
    ];
    flash_old($_POST);

    if ($data['name'] === '') {
        set_flash('danger', 'Lead name is required.');
        redirect($id ? "advisor/lead-edit.php?id=$id" : 'advisor/lead-edit.php');
    }
    if ($data['email'] !== '' && !is_valid_email($data['email'])) {
        set_flash('danger', 'Please enter a valid email address.');
        redirect($id ? "advisor/lead-edit.php?id=$id" : 'advisor/lead-edit.php');
    }

    if ($id) {
        $st = $pdo->prepare(
            'UPDATE leads SET name=?,phone=?,email=?,source=?,product_interest=?,
             budget_range=?,assigned_to=?,status=?,follow_up_date=?,notes=?
             WHERE id=? AND tenant_id=?'
        );
        $st->execute([...array_values($data), $id, $tid]);
        audit_log('update', 'leads', $id, 'Lead updated');
        set_flash('success', 'Lead updated.');
    } else {
        $st = $pdo->prepare(
            'INSERT INTO leads (tenant_id,name,phone,email,source,product_interest,
             budget_range,assigned_to,status,follow_up_date,notes,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([$tid, ...array_values($data), current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        audit_log('create', 'leads', $id, 'Lead created');
        set_flash('success', 'Lead created.');
    }
    clear_old();
    redirect('advisor/leads.php');
}

$v = static fn (string $k, $d = '') => e($lead[$k] ?? old($k, $d));
$pageTitle = $id ? 'Edit Lead' : 'New Lead';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:860px">
  <div class="card-os-head"><?= $id ? 'Edit Lead' : 'New Lead' ?>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/leads.php')) ?>">Back</a>
  </div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Full name *</label><input name="name" value="<?= $v('name') ?>" required></div>
        <div class="form-row"><label>Phone</label><input name="phone" value="<?= $v('phone') ?>"></div>
        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
        <div class="form-row"><label>Source</label><input name="source" value="<?= $v('source') ?>" placeholder="Referral, WhatsApp, Event…"></div>
        <div class="form-row"><label>Product interest</label><input name="product_interest" value="<?= $v('product_interest') ?>"></div>
        <div class="form-row"><label>Budget range</label><input name="budget_range" value="<?= $v('budget_range') ?>"></div>
        <div class="form-row"><label>Assigned advisor</label>
          <select name="assigned_to">
            <option value="">Unassigned</option>
            <?php foreach ($advisors as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (int)($lead['assigned_to']??old('assigned_to'))===(int)$a['id']?'selected':'' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row"><label>Status</label>
          <select name="status">
            <?php foreach ($statuses as $s): ?>
              <option value="<?= e($s) ?>" <?= ($lead['status']??old('status','new'))===$s?'selected':'' ?>><?= label($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row"><label>Next follow-up</label><input type="date" name="follow_up_date" value="<?= $v('follow_up_date') ?>"></div>
      </div>
      <div class="form-row"><label>Notes</label><textarea name="notes" rows="4"><?= $v('notes') ?></textarea></div>
      <button class="btn-os" name="action" value="save"><?= $id ? 'Save changes' : 'Create lead' ?></button>
      <?php if ($id && ($lead['status'] ?? '') !== 'converted'): ?>
        <button class="btn-os gold" name="action" value="convert"
                data-confirm="Convert this lead into a client?">Convert to client</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
