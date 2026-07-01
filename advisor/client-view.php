<?php
/** AdvisorOS — 360° client view: profile, financial snapshot, risk,
 *  policies, annual reviews, documents. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('clients.view');

$tid = require_tenant();
$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare(
    'SELECT c.*, u.name AS advisor_name FROM clients c
     LEFT JOIN users u ON u.id=c.advisor_id
     WHERE c.id=? AND c.tenant_id=? AND c.deleted_at IS NULL'
);
$st->execute([$id, $tid]);
$c = $st->fetch();
if (!$c) { http_response_code(404); exit('Client not found.'); }

// Save financial snapshot (Module F)
if (is_post() && ($_POST['action'] ?? '') === 'snapshot') {
    csrf_check();
    require_permission('financial.manage');
    $f = [
        'monthly_income'    => (float) input('monthly_income',0),
        'monthly_expenses'  => (float) input('monthly_expenses',0),
        'total_assets'      => (float) input('total_assets',0),
        'total_liabilities' => (float) input('total_liabilities',0),
        'insurance_coverage'=> (float) input('insurance_coverage',0),
        'investments_value' => (float) input('investments_value',0),
        'emergency_fund'    => (float) input('emergency_fund',0),
        'retirement_target' => (float) input('retirement_target',0),
    ];
    $score = financial_health_score($f);
    $ins = $pdo->prepare(
        'INSERT INTO financial_profiles
            (tenant_id,client_id,monthly_income,monthly_expenses,total_assets,
             total_liabilities,insurance_coverage,investments_value,emergency_fund,
             retirement_target,health_score,snapshot_date,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?)'
    );
    $ins->execute([$tid,$id,...array_values($f),$score,current_user()['id']]);
    audit_log('create','financial',$id,"Financial snapshot (score $score)");
    set_flash('success',"Financial snapshot saved. Health score: $score/100.");
    redirect('advisor/client-view.php?id=' . $id);
}

$fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
$fp->execute([$id,$tid]);
$snap = $fp->fetch();

$rp = $pdo->prepare('SELECT * FROM risk_profiles WHERE client_id=? AND tenant_id=? ORDER BY id DESC LIMIT 1');
$rp->execute([$id,$tid]);
$risk = $rp->fetch();

$pol = $pdo->prepare('SELECT * FROM policies WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY renewal_date ASC');
$pol->execute([$id,$tid]);
$policies = $pol->fetchAll();

$rev = $pdo->prepare('SELECT * FROM annual_reviews WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY review_date DESC LIMIT 6');
$rev->execute([$id,$tid]);
$reviews = $rev->fetchAll();

$doc = $pdo->prepare('SELECT * FROM documents WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10');
$doc->execute([$id,$tid]);
$docs = $doc->fetchAll();

$pageTitle = $c['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)"><?= e($c['full_name']) ?></h2>
    <span class="muted"><?= e($c['occupation'] ?: '—') ?> · Advisor: <?= e($c['advisor_name'] ?: '—') ?></span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn-os ghost sm" href="<?= e(url('advisor/clients.php')) ?>">Back</a>
    <?php if (can('clients.manage')): ?>
      <a class="btn-os sm" href="<?= e(url('advisor/client-edit.php?id='.$id)) ?>">Edit profile</a>
    <?php endif; ?>
  </div>
</div>

<?php
$svc = can('reviews.manage') || can('proposals.manage') || can('risk.manage');
if ($svc || can('financial.manage')):
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-body" style="display:flex;flex-direction:column;gap:12px;padding:16px 18px">
    <style>
      .actgrp{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
      .actlbl{font-size:11px;font-weight:700;text-transform:uppercase;
              letter-spacing:.05em;color:var(--muted);min-width:104px}
    </style>
    <?php if ($svc): ?>
      <div class="actgrp">
        <span class="actlbl">Servicing</span>
        <?php if (can('reviews.manage')): ?>
          <a class="btn-os gold sm" href="<?= e(url('advisor/review-edit.php?client_id='.$id)) ?>">Start annual review</a>
        <?php endif; ?>
        <?php if (can('proposals.manage')): ?>
          <a class="btn-os sm" href="<?= e(url('advisor/proposal-edit.php?client_id='.$id)) ?>">Generate proposal</a>
        <?php endif; ?>
        <?php if (can('risk.manage')): ?>
          <a class="btn-os ghost sm" href="<?= e(url('advisor/risk-edit.php?client_id='.$id)) ?>">Assess risk profile</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (can('financial.manage')): ?>
      <div class="actgrp">
        <span class="actlbl">Advisory tools</span>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/wealth.php?client_id='.$id)) ?>">Wealth analysis</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/risk-diagnostic.php?client_id='.$id)) ?>">Risk diagnostic</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/companies.php?client_id='.$id)) ?>">Business profile</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/valuation.php?client_id='.$id)) ?>">Valuation</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/equity.php?client_id='.$id)) ?>">Equity Assessment</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/succession.php?client_id='.$id)) ?>">Succession</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/capability.php?client_id='.$id)) ?>">Borrowing capability</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/tax.php?client_id='.$id)) ?>">Tax planning</a>
      </div>
      <div class="actgrp">
        <span class="actlbl">Report</span>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/solutions.php?client_id='.$id)) ?>">Solutions</a>
        <a class="btn-os gold sm" href="<?= e(url('advisor/action-plan.php?client_id='.$id)) ?>">Strategic report</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Financial Health Score</div>
    <div class="stat-value"><?= $snap ? (int)$snap['health_score'] : '—' ?><?= $snap?'<span style="font-size:14px;color:var(--muted)">/100</span>':'' ?></div>
    <div class="stat-foot"><?= $snap ? 'As of '.fmt_date($snap['snapshot_date']) : 'No snapshot yet' ?></div>
  </div>
  <div class="stat accent"><div class="stat-label">Risk Profile</div>
    <div class="stat-value" style="font-size:20px"><?= label($risk['classification'] ?? $c['risk_appetite']) ?></div>
    <div class="stat-foot"><?= $risk ? 'Score '.(int)$risk['score'].' · '.fmt_date($risk['assessed_on']) : 'Not assessed' ?></div>
  </div>
  <div class="stat accent"><div class="stat-label">Next Annual Review</div>
    <div class="stat-value" style="font-size:20px"><?= fmt_date($c['next_review_date']) ?></div>
    <div class="stat-foot"><?= count($policies) ?> active policies tracked</div>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Profile</div>
    <div class="card-os-body">
      <table class="table-os">
        <tr><td class="muted">NRIC / Passport</td><td><?= e($c['nric_passport'] ?: '—') ?></td></tr>
        <tr><td class="muted">Date of birth</td><td><?= fmt_date($c['dob']) ?></td></tr>
        <tr><td class="muted">Gender / Marital</td><td><?= label($c['gender']) ?> · <?= label($c['marital_status']) ?></td></tr>
        <tr><td class="muted">Phone / Email</td><td><?= e($c['phone'] ?: '—') ?><br><?= e($c['email'] ?: '') ?></td></tr>
        <tr><td class="muted">Employer / Industry</td><td><?= e($c['employer'] ?: '—') ?> · <?= e($c['industry'] ?: '—') ?></td></tr>
        <tr><td class="muted">Dependents</td><td><?= (int)$c['dependents'] ?> · <?= e($c['children_info'] ?: '—') ?></td></tr>
        <tr><td class="muted">Financial goals</td><td><?= nl2br(e($c['financial_goals'] ?: '—')) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Financial Snapshot</div>
    <div class="card-os-body">
      <?php if ($snap): ?>
        <div class="grid cols-2" style="gap:8px 18px;margin-bottom:14px">
          <div class="muted">Monthly income</div><div><?= money($snap['monthly_income']) ?></div>
          <div class="muted">Monthly expenses</div><div><?= money($snap['monthly_expenses']) ?></div>
          <div class="muted">Total assets</div><div><?= money($snap['total_assets']) ?></div>
          <div class="muted">Total liabilities</div><div><?= money($snap['total_liabilities']) ?></div>
          <div class="muted">Insurance coverage</div><div><?= money($snap['insurance_coverage']) ?></div>
          <div class="muted">Investments</div><div><?= money($snap['investments_value']) ?></div>
          <div class="muted">Emergency fund</div><div><?= money($snap['emergency_fund']) ?></div>
          <div class="muted">Retirement target</div><div><?= money($snap['retirement_target']) ?></div>
        </div>
      <?php endif; ?>
      <?php if (can('financial.manage')): ?>
      <details <?= $snap?'':'open' ?>>
        <summary style="cursor:pointer;font-weight:600;color:var(--navy);margin-bottom:10px">Update snapshot</summary>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="snapshot">
          <div class="form-grid">
            <div class="form-row"><label>Monthly income</label><input type="number" step="0.01" name="monthly_income" value="<?= e($snap['monthly_income']??0) ?>"></div>
            <div class="form-row"><label>Monthly expenses</label><input type="number" step="0.01" name="monthly_expenses" value="<?= e($snap['monthly_expenses']??0) ?>"></div>
            <div class="form-row"><label>Total assets</label><input type="number" step="0.01" name="total_assets" value="<?= e($snap['total_assets']??0) ?>"></div>
            <div class="form-row"><label>Total liabilities</label><input type="number" step="0.01" name="total_liabilities" value="<?= e($snap['total_liabilities']??0) ?>"></div>
            <div class="form-row"><label>Insurance coverage</label><input type="number" step="0.01" name="insurance_coverage" value="<?= e($snap['insurance_coverage']??0) ?>"></div>
            <div class="form-row"><label>Investments value</label><input type="number" step="0.01" name="investments_value" value="<?= e($snap['investments_value']??0) ?>"></div>
            <div class="form-row"><label>Emergency fund</label><input type="number" step="0.01" name="emergency_fund" value="<?= e($snap['emergency_fund']??0) ?>"></div>
            <div class="form-row"><label>Retirement target</label><input type="number" step="0.01" name="retirement_target" value="<?= e($snap['retirement_target']??0) ?>"></div>
          </div>
          <button class="btn-os">Save snapshot &amp; recompute score</button>
        </form>
      </details>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Policies
      <?php if (can('policies.manage')): ?>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/policy-edit.php?client_id='.$id)) ?>">+ Add policy</a>
      <?php endif; ?>
    </div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Provider</th><th>Category</th><th>Premium</th><th>Renewal</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$policies): ?>
          <tr><td colspan="5" class="muted" style="padding:20px">No policies tracked.</td></tr>
        <?php else: foreach ($policies as $p): ?>
          <tr>
            <td><?= e($p['provider'] ?: '—') ?><br><span class="muted" style="font-size:12px"><?= e($p['policy_number']) ?></span></td>
            <td><?= label($p['category']) ?></td>
            <td><?= money($p['premium']) ?></td>
            <td><?= fmt_date($p['renewal_date']) ?></td>
            <td><span class="badge-os b-<?= e($p['status']) ?>"><?= label($p['status']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Annual Review History</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Review date</th><th>Status</th><th>Acknowledged</th><th></th></tr></thead>
        <tbody>
        <?php if (!$reviews): ?>
          <tr><td colspan="4" class="muted" style="padding:20px">No reviews recorded. This is the retention engine — schedule the first review.</td></tr>
        <?php else: foreach ($reviews as $r): ?>
          <tr>
            <td><?= fmt_date($r['review_date']) ?></td>
            <td><span class="badge-os b-<?= e($r['status']) ?>"><?= label($r['status']) ?></span></td>
            <td><?= $r['client_acknowledged'] ? '✓ '.fmt_date($r['acknowledged_at']) : '—' ?></td>
            <td class="text-right"><a class="btn-os ghost sm" href="<?= e(url('advisor/review-edit.php?id='.(int)$r['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card-os" style="margin-top:18px">
  <div class="card-os-head">Documents
    <?php if (can('documents.manage')): ?>
      <a class="btn-os ghost sm" href="<?= e(url('advisor/documents.php?client_id='.$id)) ?>">Manage documents</a>
    <?php endif; ?>
  </div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>File</th><th>Category</th><th>Size</th><th>Uploaded</th></tr></thead>
      <tbody>
      <?php if (!$docs): ?>
        <tr><td colspan="4" class="muted" style="padding:20px">No documents uploaded.</td></tr>
      <?php else: foreach ($docs as $d): ?>
        <tr>
          <td><?= e($d['original_name']) ?></td>
          <td><?= label($d['category']) ?></td>
          <td><?= number_format($d['file_size']/1024,1) ?> KB</td>
          <td><?= fmt_date($d['created_at']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
