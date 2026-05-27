<?php
/** AdvisorOS — Tax Planning solution (per-client engagement). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/solutions.php';
require_once __DIR__ . '/../includes/insight.php';
require_once __DIR__ . '/../includes/corptax.php';
require_once __DIR__ . '/../includes/tax_kb.php';
require_once __DIR__ . '/../includes/tax_compute.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/billing.php';
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
    if (input('action') === 'ai_report' || input('action') === 'ai_plan') {
        $mode = input('action') === 'ai_plan' ? 'planning' : 'full';
        $res  = tax_report_generate($pdo, $tid, $clientId, $client, $mode);
        solution_save($clientId, 'tax', [($mode === 'planning' ? 'plan_report' : 'report') => $res['text']]);
        meter_report('tax');
        audit_log('ai_generate', 'solution', $clientId,
            ($mode === 'planning' ? 'AI tax planning report' : 'AI tax report')
            . ($res['stubbed'] ? ' (draft)' : ' (' . $res['model'] . ')'));
        set_flash($res['stubbed'] ? 'warning' : 'success',
            $res['stubbed'] ? 'Data-driven report generated (no AI provider configured).'
                            : 'AI report generated — review before sharing.');
        redirect('advisor/solution-tax.php?client_id=' . $clientId);
    }
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

/**
 * Build the AI tax-planning report. The system supplies the verified
 * figures; the AI writes the narrative + Part B using the firm's
 * knowledge base. Falls back to a data-driven draft offline.
 */
function tax_report_generate(PDO $pdo, ?int $tid, int $clientId, array $client, string $mode = 'full'): array
{
    $ya  = tax_current_ya();
    $imp = tax_impact($pdo, $tid, $clientId);

    if (taxcomp_exists($clientId)) {
        // Itemised Part A from the full computation engine.
        $facts = tax_compute_facts(tax_compute(taxcomp_get($clientId)), $client['full_name']);
    } else {
        // Summary-level facts from the quick tax planner.
        $sum = tax_summary($pdo, $tid, $clientId);
        $in  = setting_get_json('tax:' . $clientId, []);
        $reliefLines = [];
        foreach (my_relief_catalogue() as $k => [$label, $cap, $group]) {
            $claimed = min((float) $cap, max(0.0, (float) ($in['r_' . $k] ?? 0)));
            $room = $cap - $claimed;
            $reliefLines[] = sprintf('- %s: claimed RM %s of RM %s%s', $label,
                money($claimed), money($cap), $room > 0 ? ' (room RM ' . money($room) . ')' : ' (maxed)');
        }
        $facts = "Year of Assessment: {$ya} (Malaysia resident individual).\n"
            . "Client: {$client['full_name']}.\n"
            . 'Gross income: RM ' . money($imp['gross']) . ".\n"
            . ($sum ? 'Chargeable income: RM ' . money($sum['chargeable'])
                . '; estimated tax payable: RM ' . money($sum['tax_payable'])
                . ' (effective ' . round($sum['eff_rate'] * 100, 1) . '%, marginal '
                . (int) round($sum['marginal'] * 100) . "%).\n" : '')
            . 'Personal reliefs maximisation saving: RM ' . money($imp['pers_saving']) . ".\n"
            . "Reliefs (claimed / cap):\n" . implode("\n", $reliefLines) . "\n";
    }

    // Corporate extraction (if companies exist) appended in both modes.
    foreach ($imp['corp_rows'] as $cr) {
        $facts .= sprintf("Company %s: tax now RM %s -> efficient salary/dividend RM %s (save RM %s).\n",
            $cr['name'], money($cr['before']), money($cr['after']), money(max(0, $cr['before'] - $cr['after'])));
    }

    $kb = tax_kb_prompt();
    $common = 'You are a Malaysian individual income tax specialist (ITA 1967 / LHDN) '
        . 'preparing client work. CRITICAL: use ONLY the figures provided below — '
        . 'never recompute, invent or "correct" any rate, relief cap or amount. Frame '
        . 'recommendations as points for the licensed adviser / tax agent to review; '
        . 'flag any assumption to confirm. Be specific and use the ringgit figures given.'
        . ($kb !== '' ? "\n\nApply the firm's tax methodology and house style:\n" . $kb : '');

    if ($mode === 'planning') {
        $system = $common . "\n\nProduce a TAX PLANNING report — Part B ONLY: a "
            . 'prioritised, numbered set of tax-optimisation strategies (relief '
            . 'maximisation, employment package, business/structuring, family & estate, '
            . 'investments, documentation). Do NOT reproduce the computation table.';
        $user = "Write the Part B tax-optimisation plan. Use these verified figures as "
            . "context (do not restate them as a computation):\n\n" . $facts;
        $stub = "TAX PLANNING — RECOMMENDATIONS — {$client['full_name']} (YA {$ya})\n\n"
            . "1. Relief maximisation — top up the reliefs showing room (largest impact first).\n"
            . ($imp['corp_rows'] ? "2. Profit extraction — move to the efficient salary/dividend split.\n" : '')
            . "3. Employment package — convert taxable cash allowances to accountable reimbursements.\n"
            . "4. Family & estate — formalise arrangements; trust where a disabled dependant exists.\n"
            . "5. Documentation — retain receipts/statements for every claim.\n"
            . "Review with a licensed tax agent.";
    } else {
        $system = $common . "\n\nProduce: (A) a concise computation summary built from "
            . 'the given figures; (B) prioritised, practical tax-optimisation recommendations.';
        $user = "Write the tax planning report from these verified figures:\n\n" . $facts;
        $stub = "TAX PLANNING REPORT — {$client['full_name']} (YA {$ya})\n\n"
            . "PART A — POSITION\n" . $facts . "\n"
            . "PART B — RECOMMENDATIONS\n"
            . "- Maximise the reliefs showing room above (largest impact first).\n"
            . ($imp['corp_rows'] ? "- Restructure company profit extraction to the efficient salary/dividend split.\n" : '')
            . "- Confirm eligibility and documents for each relief before filing.\n"
            . "- Review with a licensed tax agent.";
    }

    return ai_complete($system, $user, 'tax_report', $stub);
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
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn-os ghost sm" href="<?= e(url('advisor/tax-compute.php?client_id='.$clientId)) ?>">Full computation</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/tax-report.php?client_id='.$clientId)) ?>">Before/After report</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/tax-knowledge.php')) ?>">Tax knowledge</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/solutions.php?client_id='.$clientId)) ?>">All solutions</a>
  </div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">AI Tax Planning Report
    <span class="badge-os <?= ai_enabled() ? 'b-active' : 'b-warn' ?>" style="font-size:11px">
      <?= ai_enabled() ? 'Live · ' . e(ai_provider()) : 'Offline draft' ?></span>
  </div>
  <div class="card-os-body">
    <p class="muted" style="margin-top:0;font-size:13px">The system supplies the
      verified figures; the AI writes the Part A summary &amp; Part B planning using
      your <a href="<?= e(url('advisor/tax-knowledge.php')) ?>">Tax Knowledge Base</a>
      (<?= count(tax_kb_active()) ?> active entr<?= count(tax_kb_active())===1?'y':'ies' ?>).</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px">
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ai_report">
        <button class="btn-os gold"><?= $eng['report'] !== '' ? 'Regenerate full report' : 'Full report (A + B)' ?></button>
      </form>
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ai_plan">
        <button class="btn-os"><?= $eng['plan_report'] !== '' ? 'Regenerate planning report' : 'Planning report (Part B)' ?></button>
      </form>
    </div>
    <?php if ($eng['report'] !== ''): ?>
      <div class="muted" style="font-size:12px;margin:12px 0 4px">Full report (Part A + B) · generated <?= e($eng['report_at']) ?></div>
      <textarea rows="16" readonly style="width:100%;font-family:inherit;padding:14px;
        border:1px solid var(--line);border-radius:10px;white-space:pre-wrap"><?= e($eng['report']) ?></textarea>
    <?php endif; ?>
    <?php if ($eng['plan_report'] !== ''): ?>
      <div class="muted" style="font-size:12px;margin:12px 0 4px">Planning report (Part B only) · generated <?= e($eng['plan_report_at']) ?></div>
      <textarea rows="14" readonly style="width:100%;font-family:inherit;padding:14px;
        border:1px solid var(--line);border-radius:10px;white-space:pre-wrap"><?= e($eng['plan_report']) ?></textarea>
    <?php endif; ?>
    <?php if ($eng['report'] !== '' || $eng['plan_report'] !== ''): ?>
      <div class="disclaimer">AI-assisted draft. Figures are system-computed
        estimates; narrative and recommendations must be reviewed and approved by a
        licensed tax agent / financial adviser before sharing with the client.</div>
    <?php endif; ?>
  </div>
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
