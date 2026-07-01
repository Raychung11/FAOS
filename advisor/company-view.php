<?php
/** AdvisorOS — Company detail: shareholders/directors & financials. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/business.php';
require_permission('financial.manage');

require_tenant();
$id = (int) ($_GET['id'] ?? 0);
$co = company_with_client($id);
if (!$co) { http_response_code(404); exit('Company not found.'); }
if (has_role('financial_advisor') && (int) $co['client_advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}
$clientId = (int) $co['owner_client_id'];

if (is_post()) {
    csrf_check();
    $action = (string) input('action', '');

    if ($action === 'stk_save') {
        if (trim((string) input('name', '')) === '') {
            set_flash('danger', 'Stakeholder name is required.');
            redirect('advisor/company-view.php?id=' . $id);
        }
        $sid = (int) input('stk_id', 0);
        // Ownership guard: keep the company in the same tenant scope.
        if ($sid) {
            $ex = stakeholder_get($sid);
            if (!$ex || (int) $ex['company_id'] !== $id) {
                http_response_code(403); exit('Invalid stakeholder.');
            }
        }
        stakeholder_save([
            'company_id'       => $id,
            'name'             => input('name', ''),
            'nric_passport'    => input('nric_passport', ''),
            'relationship'     => input('relationship', ''),
            'is_shareholder'   => input('is_shareholder', ''),
            'shareholding_pct' => input('shareholding_pct', 0),
            'is_director'      => input('is_director', ''),
            'is_beneficiary'   => input('is_beneficiary', ''),
            'benefit_pct'      => input('benefit_pct', 0),
            'email'            => input('email', ''),
            'phone'            => input('phone', ''),
            'notes'            => input('notes', ''),
        ], $sid);
        audit_log($sid ? 'update' : 'create', 'stakeholder', $id, 'Stakeholder saved');
        set_flash('success', 'Stakeholder saved.');
        redirect('advisor/company-view.php?id=' . $id);
    }

    if ($action === 'stk_delete') {
        $sid = (int) input('stk_id', 0);
        $ex  = stakeholder_get($sid);
        if ($ex && (int) $ex['company_id'] === $id) {
            stakeholder_delete($sid);
            audit_log('delete', 'stakeholder', $id, 'Stakeholder removed');
            set_flash('success', 'Stakeholder removed.');
        }
        redirect('advisor/company-view.php?id=' . $id);
    }

    if ($action === 'bf_save') {
        bf_save($id, [
            'snapshot_date' => input('snapshot_date', ''),
            ...array_combine(BF_FIELDS, array_map(
                fn ($f) => input($f, 0), BF_FIELDS
            )),
        ]);
        audit_log('create', 'business_financials', $id, 'Business financials snapshot added');
        set_flash('success', 'Financial snapshot added.');
        redirect('advisor/company-view.php?id=' . $id);
    }
}

$stk      = stakeholders_for_company($id);
$bf       = bf_latest($id);
$hist     = bf_list($id);
$editStk  = null;
$eid      = (int) ($_GET['stk'] ?? 0);
foreach ($stk as $s) { if ((int) $s['id'] === $eid) { $editStk = $s; break; } }
$shareTot = 0.0;
foreach ($stk as $s) { if ((int) $s['is_shareholder'] === 1) { $shareTot += (float) $s['shareholding_pct']; } }

$entityLabels = ['sole_proprietor' => 'Sole proprietor', 'partnership' => 'Partnership',
    'sdn_bhd' => 'Sdn Bhd', 'berhad' => 'Berhad', 'llp' => 'LLP', 'other' => 'Other'];
$bfLabels = ['revenue' => 'Revenue', 'ebitda' => 'EBITDA', 'net_profit' => 'Net profit',
    'total_assets' => 'Total assets', 'total_liabilities' => 'Total liabilities',
    'receivables' => 'Receivables', 'inventory' => 'Inventory', 'cash' => 'Cash',
    'bank_loans' => 'Bank loans', 'shareholder_loans' => 'Shareholder loans',
    'personal_guarantee' => 'Personal guarantee', 'owner_remuneration' => 'Owner remuneration',
    'dividends_paid' => 'Dividends paid'];

$pageTitle = $co['name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)"><?= e($co['name']) ?></h2>
    <span class="muted"><?= e($entityLabels[$co['entity_type']] ?? $co['entity_type']) ?>
      · <?= e($co['registration_no'] ?: 'no SSM no.') ?>
      · owned by <?= e($co['client_name']) ?></span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn-os ghost sm" href="<?= e(url('advisor/tax-structuring.php?company_id='.$id)) ?>">Tax structuring</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/sme-financing.php?company_id='.$id)) ?>">SME financing</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/companies.php?client_id='.$clientId.'&edit='.$id)) ?>">Edit company</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">All companies</a>
  </div>
</div>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Client owns</div>
    <div class="stat-value"><?= rtrim(rtrim(number_format((float)$co['ownership_pct'],2),'0'),'.') ?>%</div>
    <div class="stat-foot"><?= label($co['status']) ?></div></div>
  <div class="stat accent"><div class="stat-label">Latest revenue</div>
    <div class="stat-value">RM <?= money($bf['revenue'] ?? 0) ?></div>
    <div class="stat-foot"><?= $bf ? e($bf['snapshot_date']) : 'no snapshot yet' ?></div></div>
  <div class="stat accent"><div class="stat-label">Latest EBITDA</div>
    <div class="stat-value">RM <?= money($bf['ebitda'] ?? 0) ?></div>
    <div class="stat-foot">net profit RM <?= money($bf['net_profit'] ?? 0) ?></div></div>
  <div class="stat accent"><div class="stat-label">Personal guarantee</div>
    <div class="stat-value" style="color:<?= ($bf['personal_guarantee'] ?? 0) > 0 ? 'var(--danger)' : 'inherit' ?>">RM <?= money($bf['personal_guarantee'] ?? 0) ?></div>
    <div class="stat-foot">owner's exposure</div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head"><?= $editStk ? 'Edit stakeholder' : 'Add shareholder / director' ?>
      <?php if ($editStk): ?>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/company-view.php?id='.$id)) ?>">Cancel</a>
      <?php endif; ?>
    </div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="stk_save">
        <input type="hidden" name="stk_id" value="<?= (int) ($editStk['id'] ?? 0) ?>">
        <div class="form-grid">
          <div class="form-row"><label>Name *</label>
            <input name="name" required maxlength="150" value="<?= e($editStk['name'] ?? '') ?>"></div>
          <div class="form-row"><label>NRIC / passport</label>
            <input name="nric_passport" maxlength="60" value="<?= e($editStk['nric_passport'] ?? '') ?>"></div>
          <div class="form-row"><label>Relationship to client</label>
            <select name="relationship">
              <?php foreach (STAKEHOLDER_RELATIONSHIPS as $r): ?>
                <option value="<?= $r ?>" <?= ($editStk['relationship'] ?? 'other')===$r?'selected':'' ?>><?= label($r) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label>Shareholding (%)</label>
            <input type="number" step="0.01" name="shareholding_pct" value="<?= e($editStk['shareholding_pct'] ?? 0) ?>"></div>
          <div class="form-row"><label>Succession benefit (%)</label>
            <input type="number" step="0.01" name="benefit_pct" value="<?= e($editStk['benefit_pct'] ?? 0) ?>"></div>
          <div class="form-row"><label>Email</label>
            <input name="email" maxlength="150" value="<?= e($editStk['email'] ?? '') ?>"></div>
          <div class="form-row"><label>Phone</label>
            <input name="phone" maxlength="40" value="<?= e($editStk['phone'] ?? '') ?>"></div>
        </div>
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin:6px 0 12px">
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="is_shareholder" value="1" <?= !empty($editStk['is_shareholder'])?'checked':'' ?>> Shareholder</label>
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="is_director" value="1" <?= !empty($editStk['is_director'])?'checked':'' ?>> Director</label>
          <label style="display:flex;gap:6px;align-items:center;font-weight:400">
            <input type="checkbox" name="is_beneficiary" value="1" <?= !empty($editStk['is_beneficiary'])?'checked':'' ?>> Beneficiary</label>
        </div>
        <div class="form-row"><label>Notes</label>
          <input name="notes" maxlength="255" value="<?= e($editStk['notes'] ?? '') ?>"></div>
        <button class="btn-os gold"><?= $editStk ? 'Save stakeholder' : 'Add stakeholder' ?></button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Shareholders &amp; directors
      <span class="badge-os <?= abs($shareTot-100) < 0.01 || $shareTot==0 ? 'b-active' : 'b-warn' ?>">
        shareholding <?= rtrim(rtrim(number_format($shareTot,2),'0'),'.') ?>%</span>
    </div>
    <div class="card-os-body" style="padding:0">
      <?php if (!$stk): ?>
        <div style="padding:18px" class="muted">No stakeholders yet. Add the
          shareholders, directors and beneficiaries on the left.</div>
      <?php else: ?>
        <table class="table-os">
          <thead><tr><th>Name</th><th>Roles</th><th>Holding</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($stk as $s): ?>
            <tr>
              <td><strong><?= e($s['name']) ?></strong>
                <div class="muted" style="font-size:12px"><?= label($s['relationship']) ?><?= $s['nric_passport'] ? ' · '.e($s['nric_passport']) : '' ?></div></td>
              <td style="font-size:12px">
                <?= !empty($s['is_shareholder']) ? '<span class="badge-os b-scheduled">Shareholder</span> ' : '' ?>
                <?= !empty($s['is_director']) ? '<span class="badge-os b-active">Director</span> ' : '' ?>
                <?= !empty($s['is_beneficiary']) ? '<span class="badge-os b-warn">Beneficiary</span>' : '' ?></td>
              <td><?= rtrim(rtrim(number_format((float)$s['shareholding_pct'],2),'0'),'.') ?>%
                <?php if ((float)$s['benefit_pct']>0): ?><div class="muted" style="font-size:11px">benefit <?= rtrim(rtrim(number_format((float)$s['benefit_pct'],2),'0'),'.') ?>%</div><?php endif; ?></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-os ghost sm" href="<?= e(url('advisor/company-view.php?id='.$id.'&stk='.(int)$s['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this stakeholder?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="stk_delete">
                  <input type="hidden" name="stk_id" value="<?= (int) $s['id'] ?>">
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

<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Add a business financial snapshot</div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bf_save">
      <div class="form-grid">
        <div class="form-row"><label>Snapshot date</label>
          <input type="date" name="snapshot_date" value="<?= e(date('Y-m-d')) ?>"></div>
        <?php foreach ($bfLabels as $f => $lbl): ?>
          <div class="form-row"><label><?= e($lbl) ?> (RM)</label>
            <input type="number" step="0.01" name="<?= e($f) ?>" value="0"></div>
        <?php endforeach; ?>
      </div>
      <button class="btn-os gold">Add snapshot</button>
    </form>
    <div class="disclaimer">Figures are advisory inputs captured for analysis,
      not audited accounts. They feed the upcoming business-wealth, valuation,
      financing and succession engines.</div>
  </div>
</div>

<?php if ($hist): ?>
<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Financial history</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Date</th><th>Revenue</th><th>EBITDA</th><th>Net profit</th>
        <th>Assets</th><th>Liabilities</th><th>P. guarantee</th></tr></thead>
      <tbody>
      <?php foreach ($hist as $h): ?>
        <tr>
          <td><?= e($h['snapshot_date']) ?></td>
          <td>RM <?= money($h['revenue']) ?></td>
          <td>RM <?= money($h['ebitda']) ?></td>
          <td>RM <?= money($h['net_profit']) ?></td>
          <td>RM <?= money($h['total_assets']) ?></td>
          <td>RM <?= money($h['total_liabilities']) ?></td>
          <td>RM <?= money($h['personal_guarantee']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
