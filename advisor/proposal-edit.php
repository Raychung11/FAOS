<?php
/** AdvisorOS — Create / edit an advisory proposal. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('proposals.manage');

$tid = require_tenant();
$pdo = db();
$id        = (int) ($_GET['id'] ?? 0);
$clientId  = (int) ($_GET['client_id'] ?? 0);
$proposal  = null;
$statuses  = ['draft','sent','accepted','rejected'];

if ($id) {
    $st = $pdo->prepare('SELECT * FROM proposals WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $st->execute([$id, $tid]);
    $proposal = $st->fetch();
    if (!$proposal) { http_response_code(404); exit('Proposal not found.'); }
    $clientId = (int) $proposal['client_id'];
    if (has_role('financial_advisor')
        && (int) $proposal['advisor_id'] !== (int) current_user()['id']) {
        http_response_code(403); exit('This proposal belongs to another advisor.');
    }
}

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Generate proposal”.'); }

/** Latest financial snapshot + risk profile for the snapshot capture. */
$fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
$fp->execute([$clientId, $tid]);
$fin = $fp->fetch() ?: null;

$rp = $pdo->prepare('SELECT * FROM risk_profiles WHERE client_id=? AND tenant_id=? ORDER BY id DESC LIMIT 1');
$rp->execute([$clientId, $tid]);
$risk = $rp->fetch() ?: null;

$existing = $proposal ? (json_decode((string) $proposal['content'], true) ?: []) : [];

// Suggested current-gaps text seeded from the data (advisor can edit).
$suggested = [];
if ($fin) {
    if ((int) $fin['health_score'] < 60) {
        $suggested[] = 'Financial health score is '
            . (int) $fin['health_score'] . '/100 — below a healthy threshold.';
    }
    if ((float) $fin['emergency_fund'] < (float) $fin['monthly_expenses'] * 6) {
        $suggested[] = 'Emergency fund is below the recommended 6 months of expenses.';
    }
    $annual = (float) $fin['monthly_income'] * 12;
    if ($annual > 0 && (float) $fin['insurance_coverage'] < $annual * 10) {
        $suggested[] = 'Protection coverage is below ~10× annual income.';
    }
} else {
    $suggested[] = 'No financial snapshot on record — capture one to strengthen this proposal.';
}
$suggestedGaps = implode("\n", $suggested);

if (is_post()) {
    csrf_check();
    $title  = (string) input('title','') ?: ('Advisory Proposal — ' . $client['full_name']);
    $status = in_array(input('status'),$statuses,true) ? input('status') : 'draft';

    // A sent/accepted/rejected proposal keeps its original point-in-time
    // snapshot; only a draft refreshes it from current client data.
    $keepSnapshot = $proposal
        && in_array($proposal['status'], ['sent','accepted','rejected'], true)
        && !empty($existing['snapshot']);

    $snapshot = $keepSnapshot ? $existing['snapshot'] : [
        'client' => [
            'full_name'      => $client['full_name'],
            'nric_passport'  => $client['nric_passport'],
            'dob'            => $client['dob'],
            'occupation'     => $client['occupation'],
            'employer'       => $client['employer'],
            'marital_status' => $client['marital_status'],
            'dependents'     => $client['dependents'],
        ],
        'financial' => $fin ? [
            'monthly_income'     => $fin['monthly_income'],
            'monthly_expenses'   => $fin['monthly_expenses'],
            'total_assets'       => $fin['total_assets'],
            'total_liabilities'  => $fin['total_liabilities'],
            'insurance_coverage' => $fin['insurance_coverage'],
            'investments_value'  => $fin['investments_value'],
            'emergency_fund'     => $fin['emergency_fund'],
            'retirement_target'  => $fin['retirement_target'],
            'health_score'       => $fin['health_score'],
        ] : null,
        'risk' => $risk ? [
            'classification' => $risk['classification'],
            'score'          => $risk['score'],
            'assessed_on'    => $risk['assessed_on'],
        ] : null,
        'goals' => $client['financial_goals'],
    ];

    $content = [
        'executive_summary' => (string) input('executive_summary',''),
        'current_gaps'      => (string) input('current_gaps',''),
        'recommendations'   => (string) input('recommendations',''),
        'action_plan'       => (string) input('action_plan',''),
        'snapshot'          => $snapshot,
        'generated_at'      => $existing['generated_at'] ?? date('Y-m-d H:i:s'),
        'advisor_name'      => current_user()['name'],
    ];
    $json = json_encode($content, JSON_UNESCAPED_UNICODE);

    if ($id) {
        $pdo->prepare(
            'UPDATE proposals SET title=?, content=?, status=? WHERE id=? AND tenant_id=?'
        )->execute([$title, $json, $status, $id, $tid]);
        audit_log('update','proposals',$id,'Proposal updated');
        set_flash('success','Proposal updated.');
    } else {
        $pdo->prepare(
            'INSERT INTO proposals (tenant_id,client_id,advisor_id,title,content,status,created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$tid,$clientId,current_user()['id'],$title,$json,$status,current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        audit_log('create','proposals',$id,'Proposal generated');
        set_flash('success','Proposal generated.');
    }
    redirect('advisor/proposal-view.php?id=' . $id);
}

$v = static fn (string $k, $d='') => e($existing[$k] ?? $d);
$pageTitle = $id ? 'Edit Proposal' : 'Generate Proposal';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:920px">
  <div class="card-os-head"><?= $id ? 'Edit Proposal' : 'Generate Proposal' ?> — <?= e($client['full_name']) ?>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
  <div class="card-os-body">
    <p class="muted" style="margin-top:0">Profile, financial snapshot and risk
      profile are captured automatically. Write the advisory narrative below.</p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-row"><label>Proposal title</label>
        <input name="title" value="<?= e($proposal['title'] ?? ('Advisory Proposal — ' . $client['full_name'])) ?>"></div>
      <div class="form-row"><label>1. Executive summary</label>
        <textarea name="executive_summary" rows="3"><?= $v('executive_summary') ?></textarea></div>
      <div class="form-row"><label>6. Current gaps
        <?php if ($suggestedGaps && !$id): ?><span class="muted" style="font-weight:400">(suggested below — edit freely)</span><?php endif; ?>
        </label>
        <textarea name="current_gaps" rows="4"><?= e($existing['current_gaps'] ?? $suggestedGaps) ?></textarea></div>
      <div class="form-row"><label>7. Recommendations</label>
        <textarea name="recommendations" rows="4"><?= $v('recommendations') ?></textarea></div>
      <div class="form-row"><label>8. Action plan</label>
        <textarea name="action_plan" rows="4"><?= $v('action_plan') ?></textarea></div>
      <div class="form-row" style="max-width:240px"><label>Status</label>
        <select name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= ($proposal['status'] ?? 'draft')===$s?'selected':'' ?>><?= label($s) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn-os gold"><?= $id ? 'Save proposal' : 'Generate proposal' ?></button>
    </form>
    <div class="disclaimer">This is not financial advice. Final recommendations
      must be reviewed and approved by a licensed financial advisor.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
