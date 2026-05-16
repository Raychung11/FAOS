<?php
/** AdvisorOS — Create / edit an advisory proposal. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/insight.php';
require_once __DIR__ . '/../includes/skills.php';
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

// Current field values: existing proposal, AI draft, or seeded gaps.
$draft = [
    'executive_summary' => $existing['executive_summary'] ?? '',
    'current_gaps'      => $existing['current_gaps'] ?? $suggestedGaps,
    'recommendations'   => $existing['recommendations'] ?? '',
    'action_plan'       => $existing['action_plan'] ?? '',
];
$title       = $proposal['title'] ?? ('Advisory Proposal — ' . $client['full_name']);
$activeSkills = skills_active();
$pickedSkills = [];
$aiResult    = null;

// --- "Draft with AI": fill the narrative, do NOT save ------------
if (is_post() && isset($_POST['ai_draft'])) {
    csrf_check();
    $title        = (string) input('title', '') ?: $title;
    $pickedSkills = array_map('intval', (array) ($_POST['skills'] ?? []));

    $cap = capability_summary($pdo, $tid, $clientId);
    $tax = tax_summary($pdo, $tid, $clientId);

    $facts = "Client: {$client['full_name']}\n"
        . 'Occupation: ' . ($client['occupation'] ?: 'n/a')
        . ', marital: ' . label($client['marital_status'])
        . ', dependents: ' . (int) $client['dependents'] . "\n"
        . 'Risk appetite: ' . label($risk['classification'] ?? $client['risk_appetite']) . "\n"
        . 'Goals: ' . ($client['financial_goals'] ?: 'n/a') . "\n";
    if ($fin) {
        $facts .= 'Financial health: ' . (int) $fin['health_score'] . "/100; "
            . 'income/expenses RM ' . money($fin['monthly_income']) . ' / RM ' . money($fin['monthly_expenses']) . "; "
            . 'assets/liabilities RM ' . money($fin['total_assets']) . ' / RM ' . money($fin['total_liabilities']) . "; "
            . 'insurance RM ' . money($fin['insurance_coverage']) . "; "
            . 'emergency fund RM ' . money($fin['emergency_fund']) . "\n";
    }
    if ($cap) { $facts .= $cap['text'] . "\n"; }
    if ($tax) { $facts .= $tax['text'] . "\n"; }

    $skillBlock = '';
    foreach ($activeSkills as $s) {
        if (in_array((int) $s['id'], $pickedSkills, true)) {
            $skillBlock .= "\n• {$s['title']}"
                . (!empty($s['category']) ? " [{$s['category']}]" : '') . ":\n"
                . $s['body'] . "\n";
        }
    }

    $system = 'You are an assistant for a licensed financial advisory firm in '
        . 'Malaysia. Be concise, professional and factual. Frame all '
        . 'recommendations as points for the licensed advisor to review — never '
        . 'as final advice. Never invent figures beyond those provided. Output '
        . 'EXACTLY these four sections, each on its own line as a header with no '
        . 'extra commentary before or after:\n'
        . "## EXECUTIVE SUMMARY\n## CURRENT GAPS\n## RECOMMENDATIONS\n## ACTION PLAN"
        . ($skillBlock !== ''
            ? "\n\nApply the firm's advisory skills below where relevant:\n" . $skillBlock
            : '');

    $user = "Draft an advisory proposal narrative for this client using the four "
        . "required sections.\n\nCLIENT CONTEXT:\n{$facts}\n"
        . "SUGGESTED GAPS (validate, refine, expand):\n{$suggestedGaps}";

    $stub = "## EXECUTIVE SUMMARY\n{$client['full_name']} is reviewed across "
        . "protection, cash flow, leverage and long-term goals based on the "
        . "latest snapshot.\n\n## CURRENT GAPS\n{$suggestedGaps}\n"
        . ($cap ? $cap['text'] . "\n" : '') . ($tax ? $tax['text'] . "\n" : '')
        . "\n## RECOMMENDATIONS\n- Address the gaps above in priority order "
        . "(for advisor review).\n"
        . ($cap && $cap['status'] === 'Over-leveraged'
            ? "- Reduce monthly debt commitments toward the DSR cap.\n"
            : ($cap && $cap['status'] === 'Under-leveraged'
                ? "- Unused borrowing capacity may responsibly fund priority goals.\n" : ''))
        . ($tax && $tax['max_saving'] >= 1
            ? "- Optimise eligible tax reliefs (largest lever: {$tax['top_label']}).\n" : '')
        . "- Reconfirm risk profile and goals.\n\n## ACTION PLAN\n"
        . "- Advisor to validate findings with the client.\n"
        . "- Implement agreed recommendations before the next review ("
        . fmt_date($client['next_review_date']) . ").";

    $aiResult = ai_complete($system, $user, 'proposal_draft', $stub);
    $sections = ai_split_sections($aiResult['text']);
    foreach ($sections as $k => $val) {
        if (trim((string) $val) !== '') { $draft[$k] = $val; }
    }
    audit_log('ai_generate', 'proposals', $id,
        'Proposal draft' . ($aiResult['stubbed'] ? ' (data-driven)' : ' (' . $aiResult['model'] . ')'));
}

if (is_post() && !isset($_POST['ai_draft'])) {
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

$pageTitle = $id ? 'Edit Proposal' : 'Generate Proposal';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:920px">
  <div class="card-os-head"><?= $id ? 'Edit Proposal' : 'Generate Proposal' ?> — <?= e($client['full_name']) ?>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
  <div class="card-os-body">
    <p class="muted" style="margin-top:0">Profile, financial snapshot, risk,
      borrowing-capability and tax estimates are captured automatically. Use
      “Draft with AI” to generate the narrative, then review and edit.</p>

    <?php if ($aiResult): ?>
      <div class="alert-os <?= !empty($aiResult['error']) ? 'warning' : 'info' ?>">
        <?= !empty($aiResult['error'])
              ? e($aiResult['error'])
              : ($aiResult['stubbed']
                  ? 'Data-driven draft inserted (no AI provider configured).'
                  : 'AI draft inserted · model ' . e($aiResult['model'])
                    . ($aiResult['tokens'] ? ' · ' . (int) $aiResult['tokens'] . ' tokens' : '')) ?>
        — review and edit every section before saving.
      </div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="form-row"><label>Proposal title</label>
        <input name="title" value="<?= e($title) ?>"></div>

      <div class="form-row">
        <label>AI drafting
          <span class="badge-os <?= ai_enabled() ? 'b-active' : 'b-warn' ?>"
            style="font-size:11px"><?= ai_enabled() ? 'Live · ' . e(ai_provider()) : 'Offline draft' ?></span>
        </label>
        <?php if ($activeSkills): ?>
          <div class="muted" style="font-size:12px;margin:2px 0 6px">Apply firm advisory skills:</div>
          <div style="display:flex;flex-wrap:wrap;gap:8px 16px">
            <?php foreach ($activeSkills as $s): ?>
              <label style="display:flex;gap:6px;align-items:center;font-weight:400">
                <input type="checkbox" name="skills[]" value="<?= (int) $s['id'] ?>"
                  <?= in_array((int) $s['id'], $pickedSkills, true) ? 'checked' : '' ?>>
                <span><?= e($s['title']) ?></span></label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="muted" style="font-size:12px">No active skills —
            <a href="<?= e(url('advisor/skills.php')) ?>">build your skills library</a>
            to steer the AI with house-style guidance.</div>
        <?php endif; ?>
        <button class="btn-os ghost sm" type="submit" name="ai_draft" value="1"
          style="margin-top:10px">✦ Draft with AI</button>
      </div>

      <div class="form-row"><label>1. Executive summary</label>
        <textarea name="executive_summary" rows="3"><?= e($draft['executive_summary']) ?></textarea></div>
      <div class="form-row"><label>6. Current gaps
        <?php if ($suggestedGaps && !$id): ?><span class="muted" style="font-weight:400">(seeded from data — edit freely)</span><?php endif; ?>
        </label>
        <textarea name="current_gaps" rows="4"><?= e($draft['current_gaps']) ?></textarea></div>
      <div class="form-row"><label>7. Recommendations</label>
        <textarea name="recommendations" rows="5"><?= e($draft['recommendations']) ?></textarea></div>
      <div class="form-row"><label>8. Action plan</label>
        <textarea name="action_plan" rows="4"><?= e($draft['action_plan']) ?></textarea></div>
      <div class="form-row" style="max-width:240px"><label>Status</label>
        <select name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= ($proposal['status'] ?? 'draft')===$s?'selected':'' ?>><?= label($s) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn-os gold"><?= $id ? 'Save proposal' : 'Generate proposal' ?></button>
    </form>
    <div class="disclaimer">This is not financial advice. AI output is a draft
      from the client's own data and the firm's advisory skills; final
      recommendations must be reviewed and approved by a licensed financial
      advisor.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
