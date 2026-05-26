<?php
/** AdvisorOS — Tax Planning solution (per-client engagement). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/solutions.php';
require_once __DIR__ . '/../includes/insight.php';
require_once __DIR__ . '/../includes/corptax.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Solutions”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    solution_save($clientId, 'tax', [
        'status'     => input('status', 'none'),
        'scope'      => input('scope', ''),
        'est_saving' => input('est_saving', 0),
        'fee'        => input('fee', 0),
        'notes'      => input('notes', ''),
    ]);
    audit_log('update', 'solution', $clientId, 'Tax planning engagement updated');
    set_flash('success', 'Tax planning engagement saved.');
    redirect('advisor/solution-tax.php?client_id=' . $clientId);
}

// --- Findings pulled from the existing analyses ------------------
$taxP = tax_summary($pdo, $tid, $clientId);
$persSaving = $taxP['max_saving'] ?? 0.0;

$corpSaving = 0.0; $corpLines = [];
foreach (companies_for_client($clientId) as $c) {
    $bf = bf_latest((int) $c['id']);
    $ti = corptax_inputs((int) $c['id'], $bf);
    if ($ti['pre_profit'] <= 0) { continue; }
    $sc = salary_dividend_scenarios($ti['pre_profit'], $ti['extraction'],
        $ti['other_income'], $ti['reliefs'], $ti['is_sme']);
    $sv = max($sc['all_salary']['total'] - $sc['optimal']['total'],
              $sc['all_dividend']['total'] - $sc['optimal']['total']);
    if ($sv > 0) { $corpSaving += $sv; $corpLines[] = [$c['name'], $sv]; }
}
$suggested = $persSaving + $corpSaving;

$eng = solution_get($clientId, 'tax');
$estDefault = $eng['est_saving'] > 0 ? $eng['est_saving'] : round($suggested, 2);
[$slbl, $scls] = SOLUTION_STATUSES[$eng['status']];

$pageTitle = 'Tax Planning — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Tax Planning <span class="badge-os <?= $scls ?>" style="font-size:13px"><?= e($slbl) ?></span></h2>
    <span class="muted"><?= e($client['full_name']) ?> · advanced solution engagement</span>
  </div>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/tax-report.php?client_id='.$clientId)) ?>">Before/After report</a>
  <a class="btn-os ghost sm" href="<?= e(url('advisor/solutions.php?client_id='.$clientId)) ?>">All solutions</a>
</div>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Personal tax saving</div>
    <div class="stat-value">RM <?= money($persSaving) ?></div>
    <div class="stat-foot">unused reliefs<?= $taxP && $taxP['top_label'] ? ' · '.e($taxP['top_label']) : '' ?></div></div>
  <div class="stat accent"><div class="stat-label">Corporate tax saving</div>
    <div class="stat-value">RM <?= money($corpSaving) ?></div>
    <div class="stat-foot">salary/dividend efficiency</div></div>
  <div class="stat accent"><div class="stat-label">Indicative total / year</div>
    <div class="stat-value" style="color:var(--ok)">RM <?= money($suggested) ?></div>
    <div class="stat-foot">the value of this engagement</div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">What we found</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <tbody>
          <tr><td class="muted">Personal income tax</td>
            <td><?= $taxP ? e($taxP['text']) : 'No personal tax data — add a financial snapshot.' ?></td></tr>
          <?php if ($corpLines): foreach ($corpLines as [$nm, $sv]): ?>
            <tr><td class="muted"><?= e($nm) ?></td>
              <td>Efficient salary/dividend split saves ≈ RM <?= money($sv) ?>/yr.</td></tr>
          <?php endforeach; else: ?>
            <tr><td class="muted">Corporate</td>
              <td>No company tax-structuring inputs yet — set them on each company.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="card-os-body">
        <a class="btn-os ghost sm" href="<?= e(url('advisor/tax.php?client_id='.$clientId)) ?>">Personal tax planner</a>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Company tax structuring</a>
      </div>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Engagement</div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="form-row"><label>Status</label>
            <select name="status">
              <?php foreach (SOLUTION_STATUSES as $k => [$lbl, $cls]): ?>
                <option value="<?= $k ?>" <?= $eng['status']===$k?'selected':'' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="form-row"><label>Estimated annual saving (RM)</label>
            <input type="number" step="0.01" name="est_saving" value="<?= e($estDefault) ?>"></div>
          <div class="form-row"><label>Engagement fee (RM)</label>
            <input type="number" step="0.01" name="fee" value="<?= e($eng['fee']) ?>"></div>
        </div>
        <div class="form-row"><label>Scope of work</label>
          <textarea name="scope" rows="4" placeholder="e.g. restructure director remuneration; maximise PRS/SSPN/insurance reliefs; review dividend timing…"><?= e($eng['scope']) ?></textarea></div>
        <div class="form-row"><label>Internal notes</label>
          <textarea name="notes" rows="2"><?= e($eng['notes']) ?></textarea></div>
        <button class="btn-os gold">Save engagement</button>
        <?php if ($eng['updated_at']): ?>
          <span class="muted" style="font-size:12px;margin-left:10px">Updated <?= e($eng['updated_at']) ?><?= $eng['owner']?' · '.e($eng['owner']):'' ?></span>
        <?php endif; ?>
      </form>
      <div class="disclaimer">Tax estimates are for scoping only — not tax
        advice or a filing. Implementation must be delivered and signed off by
        a licensed tax agent / financial adviser.</div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
