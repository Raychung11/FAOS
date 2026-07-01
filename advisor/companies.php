<?php
/** AdvisorOS — Client's companies (entrepreneur business profile). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/business.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Business profile”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    $action = (string) input('action', 'save');

    if ($action === 'delete') {
        company_delete((int) input('id', 0));
        audit_log('delete', 'company', (int) input('id', 0), 'Company removed');
        set_flash('success', 'Company removed.');
        redirect('advisor/companies.php?client_id=' . $clientId);
    }

    if (trim((string) input('name', '')) === '') {
        set_flash('danger', 'Company name is required.');
        redirect('advisor/companies.php?client_id=' . $clientId);
    }
    $sid = (int) input('id', 0);
    $newId = company_save([
        'client_id'          => $clientId,
        'name'               => input('name', ''),
        'registration_no'    => input('registration_no', ''),
        'entity_type'        => input('entity_type', ''),
        'industry'           => input('industry', ''),
        'incorporation_date' => input('incorporation_date', ''),
        'ownership_pct'      => input('ownership_pct', 0),
        'status'             => input('status', ''),
        'notes'              => input('notes', ''),
    ], $sid);
    audit_log($sid ? 'update' : 'create', 'company', $newId, 'Company saved');
    set_flash('success', 'Company saved.');
    redirect('advisor/companies.php?client_id=' . $clientId);
}

$companies = companies_for_client($clientId);
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
foreach ($companies as $c) {
    if ((int) $c['id'] === $editId) { $editing = $c; break; }
}
$entityLabels = ['sole_proprietor' => 'Sole proprietor', 'partnership' => 'Partnership',
    'sdn_bhd' => 'Sdn Bhd', 'berhad' => 'Berhad', 'llp' => 'LLP', 'other' => 'Other'];

$pageTitle = 'Business Profile — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Business Profile</h2>
    <span class="muted"><?= e($client['full_name']) ?> · companies, ownership &amp; business financials</span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head"><?= $editing ? 'Edit company' : 'Add a company' ?>
      <?php if ($editing): ?>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Cancel</a>
      <?php endif; ?>
    </div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <div class="form-grid">
          <div class="form-row"><label>Company name *</label>
            <input name="name" required maxlength="180" value="<?= e($editing['name'] ?? '') ?>"></div>
          <div class="form-row"><label>Registration no. (SSM)</label>
            <input name="registration_no" maxlength="80" value="<?= e($editing['registration_no'] ?? '') ?>"></div>
          <div class="form-row"><label>Entity type</label>
            <select name="entity_type">
              <?php foreach ($entityLabels as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= ($editing['entity_type'] ?? 'sdn_bhd')===$k?'selected':'' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label>Industry</label>
            <input name="industry" maxlength="120" value="<?= e($editing['industry'] ?? '') ?>"></div>
          <div class="form-row"><label>Incorporation date</label>
            <input type="date" name="incorporation_date" value="<?= e($editing['incorporation_date'] ?? '') ?>"></div>
          <div class="form-row"><label>Client's ownership (%)</label>
            <input type="number" step="0.01" name="ownership_pct" value="<?= e($editing['ownership_pct'] ?? 0) ?>"></div>
          <div class="form-row"><label>Status</label>
            <select name="status">
              <?php foreach (['active','dormant','closed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($editing['status'] ?? 'active')===$s?'selected':'' ?>><?= label($s) ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="form-row"><label>Notes</label>
          <textarea name="notes" rows="2" maxlength="4000"><?= e($editing['notes'] ?? '') ?></textarea></div>
        <button class="btn-os gold"><?= $editing ? 'Save company' : 'Add company' ?></button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Companies <span class="muted" style="font-weight:400">(<?= count($companies) ?>)</span></div>
    <div class="card-os-body" style="padding:0">
      <?php if (!$companies): ?>
        <div style="padding:18px" class="muted">No companies yet. Add the
          client's first business on the left — its shareholders, directors
          and financials are managed inside each company.</div>
      <?php else: ?>
        <table class="table-os">
          <thead><tr><th>Company</th><th>Type</th><th>Owns</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($companies as $c): ?>
            <tr>
              <td><strong><a href="<?= e(url('advisor/company-view.php?id='.(int)$c['id'])) ?>"><?= e($c['name']) ?></a></strong>
                <div class="muted" style="font-size:12px"><?= e($c['registration_no'] ?: '—') ?> · <?= e($c['industry'] ?: 'industry n/a') ?></div></td>
              <td><?= e($entityLabels[$c['entity_type']] ?? $c['entity_type']) ?></td>
              <td><?= rtrim(rtrim(number_format((float)$c['ownership_pct'],2),'0'),'.') ?>%</td>
              <td><span class="badge-os b-<?= $c['status']==='active'?'active':'warn' ?>"><?= label($c['status']) ?></span></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-os ghost sm" href="<?= e(url('advisor/company-view.php?id='.(int)$c['id'])) ?>">Open</a>
                <a class="btn-os ghost sm" href="<?= e(url('advisor/companies.php?client_id='.$clientId.'&edit='.(int)$c['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this company and its data?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn-os ghost sm" style="color:var(--danger)">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
