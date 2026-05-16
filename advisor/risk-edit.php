<?php
/** AdvisorOS — Risk profiling questionnaire (Module G).
 *  Weighted assessment across the six required categories. Each save
 *  records a new assessment in risk_profiles (history) and updates the
 *  client's risk_appetite so the 360 view, lists and proposals stay in
 *  sync. Scoring is computed server-side from the chosen options only. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('risk.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Assess risk profile”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

/**
 * Questionnaire. Each question belongs to one of the six categories and
 * carries five options worth 1..5 points (5 = most risk-tolerant).
 */
$questions = [
    'experience' => [
        'category' => 'Investment experience',
        'label'    => 'How would you describe your investment experience?',
        'options'  => ['None at all','Limited (savings only)','Some (unit trusts/funds)',
                       'Experienced (shares, ILP)','Extensive (active investor)'],
    ],
    'horizon' => [
        'category' => 'Time horizon',
        'label'    => 'When will you need to access most of this money?',
        'options'  => ['Within 2 years','2–4 years','5–7 years','8–12 years','More than 12 years'],
    ],
    'tolerance' => [
        'category' => 'Risk tolerance',
        'label'    => 'If your portfolio dropped 20% in a year, you would:',
        'options'  => ['Sell everything','Sell some','Hold and wait',
                       'Buy a little more','Buy significantly more'],
    ],
    'liquidity' => [
        'category' => 'Liquidity needs',
        'label'    => 'How much of this money might you need within 12 months?',
        'options'  => ['Most of it','A significant portion','A moderate portion',
                       'A small portion','None of it'],
    ],
    'income' => [
        'category' => 'Income stability',
        'label'    => 'How stable is your income?',
        'options'  => ['Unstable / irregular','Variable','Stable single source',
                       'Very stable','Multiple secure sources'],
    ],
    'objective' => [
        'category' => 'Financial objective',
        'label'    => 'What is your primary objective for this money?',
        'options'  => ['Preserve capital','Generate income','Balanced growth & income',
                       'Long-term growth','Maximum growth'],
    ],
    'knowledge' => [
        'category' => 'Investment experience',
        'label'    => 'How would you rate your investment knowledge?',
        'options'  => ['Novice','Basic','Competent','Advanced','Expert'],
    ],
    'volatility' => [
        'category' => 'Risk tolerance',
        'label'    => 'What level of year-to-year fluctuation can you accept?',
        'options'  => ['Very low','Low','Moderate','High','Very high'],
    ],
];
$qCount = count($questions);
$minRaw = $qCount;          // every question = 1
$maxRaw = $qCount * 5;      // every question = 5

/** Map a 0–100 score to the required classification bands. */
function risk_classify(int $pct): string
{
    return match (true) {
        $pct <= 20 => 'conservative',
        $pct <= 40 => 'moderate_conservative',
        $pct <= 60 => 'balanced',
        $pct <= 80 => 'growth',
        default    => 'aggressive',
    };
}

// Pre-fill from the latest assessment (advisor can re-assess / tweak).
$prev = $pdo->prepare(
    'SELECT * FROM risk_profiles WHERE client_id=? AND tenant_id=? ORDER BY id DESC LIMIT 1'
);
$prev->execute([$clientId, $tid]);
$last = $prev->fetch();
$prevAnswers = $last ? (json_decode((string) $last['answers'], true) ?: []) : [];

if (is_post()) {
    csrf_check();
    $answers = [];
    $raw = 0;
    $unanswered = false;
    foreach ($questions as $key => $q) {
        $idx = $_POST['q'][$key] ?? null;
        if ($idx === null || !ctype_digit((string) $idx)
            || (int) $idx < 0 || (int) $idx > 4) {
            $unanswered = true;
            break;
        }
        $idx = (int) $idx;
        $answers[$key] = $idx;          // store chosen option index
        $raw += $idx + 1;               // option 0..4  ->  1..5 points
    }

    if ($unanswered) {
        set_flash('danger', 'Please answer every question.');
        redirect('advisor/risk-edit.php?client_id=' . $clientId);
    }

    $pct = (int) round(($raw - $minRaw) / ($maxRaw - $minRaw) * 100);
    $classification = risk_classify($pct);
    $notes = (string) input('advisor_notes', '');

    $pdo->prepare(
        'INSERT INTO risk_profiles
           (tenant_id,client_id,answers,score,classification,advisor_notes,
            assessed_on,created_by)
         VALUES (?,?,?,?,?,?,CURDATE(),?)'
    )->execute([$tid,$clientId,
        json_encode($answers),$pct,$classification,$notes,current_user()['id']]);

    // Keep the client record's risk appetite aligned with the assessment.
    $pdo->prepare('UPDATE clients SET risk_appetite=? WHERE id=? AND tenant_id=?')
        ->execute([$classification, $clientId, $tid]);

    audit_log('create','risk',(int) $pdo->lastInsertId(),
        "Risk assessed: {$classification} ({$pct}/100)");
    set_flash('success',
        'Risk profile saved — ' . label($classification) . " ({$pct}/100).");
    redirect('advisor/client-view.php?id=' . $clientId);
}

$pageTitle = 'Risk Profiling — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:880px">
  <div class="card-os-head">Risk Profiling — <?= e($client['full_name']) ?>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
  <div class="card-os-body">
    <?php if ($last): ?>
      <div class="alert-os info">Last assessed <?= fmt_date($last['assessed_on']) ?> —
        <strong><?= label($last['classification']) ?></strong>
        (<?= (int)$last['score'] ?>/100). Submitting records a new assessment.</div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <?php $n = 1; foreach ($questions as $key => $q): ?>
        <div class="form-row" style="border-bottom:1px solid var(--line);padding-bottom:14px">
          <label style="font-size:14px">
            <span class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em"><?= e($q['category']) ?></span><br>
            <?= $n++ ?>. <?= e($q['label']) ?>
          </label>
          <?php foreach ($q['options'] as $i => $opt): ?>
            <label style="display:flex;gap:9px;align-items:center;font-weight:500;margin:6px 0">
              <input type="radio" name="q[<?= e($key) ?>]" value="<?= $i ?>" style="width:auto"
                <?= (isset($prevAnswers[$key]) && (int)$prevAnswers[$key] === $i) ? 'checked' : '' ?> required>
              <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="form-row" style="margin-top:16px">
        <label>Advisor notes</label>
        <textarea name="advisor_notes" rows="3"><?= e($last['advisor_notes'] ?? '') ?></textarea>
      </div>

      <button class="btn-os gold">Save risk assessment</button>
    </form>
    <div class="disclaimer">This is not financial advice. Final recommendations
      must be reviewed and approved by a licensed financial advisor.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
