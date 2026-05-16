<?php
/** AdvisorOS — AI Assistant (Module O).
 *  Client/meeting summaries, follow-up & proposal drafting, risk and
 *  financial-gap explanations. Uses a live provider when configured,
 *  otherwise a data-driven draft. Every output carries the disclaimer. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai.php';
require_permission('clients.view');

$tid = require_tenant();
$pdo = db();

$features = [
    'client_summary'   => 'Client summary',
    'meeting_summary'  => 'Meeting summary (from your notes)',
    'followup_draft'   => 'Follow-up message draft',
    'risk_explanation' => 'Risk profile explanation',
    'gap_explanation'  => 'Financial gap explanation',
    'proposal_draft'   => 'Proposal narrative draft',
];

$clients = tenant_clients_list();
$result  = null;
$picked  = ['client_id' => 0, 'feature' => 'client_summary', 'context' => ''];

if (is_post()) {
    csrf_check();
    $clientId = (int) input('client_id', 0);
    $feature  = (string) input('feature', 'client_summary');
    if (!array_key_exists($feature, $features)) { $feature = 'client_summary'; }
    $context  = trim((string) input('context', ''));
    $picked   = ['client_id' => $clientId, 'feature' => $feature, 'context' => $context];

    $cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $cs->execute([$clientId, $tid]);
    $c = $cs->fetch();
    if (!$c) {
        set_flash('danger', 'Please choose a valid client.');
        redirect('advisor/ai-assistant.php');
    }
    if (has_role('financial_advisor') && (int) $c['advisor_id'] !== (int) current_user()['id']) {
        http_response_code(403); exit('This client is assigned to another advisor.');
    }

    // --- Gather client context -----------------------------------
    $fp = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
    $fp->execute([$clientId, $tid]);
    $fin = $fp->fetch() ?: null;

    $rp = $pdo->prepare('SELECT * FROM risk_profiles WHERE client_id=? AND tenant_id=? ORDER BY id DESC LIMIT 1');
    $rp->execute([$clientId, $tid]);
    $risk = $rp->fetch() ?: null;

    $pc = $pdo->prepare('SELECT COUNT(*) FROM policies WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL');
    $pc->execute([$clientId, $tid]);
    $polCount = (int) $pc->fetchColumn();

    $facts = "Client: {$c['full_name']}\n"
        . 'Occupation: ' . ($c['occupation'] ?: 'n/a') . "\n"
        . 'Marital status: ' . label($c['marital_status']) . ', dependents: ' . (int) $c['dependents'] . "\n"
        . 'Risk appetite: ' . label($risk['classification'] ?? $c['risk_appetite']) . "\n"
        . 'Goals: ' . ($c['financial_goals'] ?: 'n/a') . "\n"
        . 'Next review: ' . fmt_date($c['next_review_date']) . "\n"
        . "Active policies tracked: {$polCount}\n";
    if ($fin) {
        $facts .= 'Financial health score: ' . (int) $fin['health_score'] . "/100\n"
            . 'Monthly income/expenses: ' . money($fin['monthly_income']) . ' / ' . money($fin['monthly_expenses']) . "\n"
            . 'Assets/liabilities: ' . money($fin['total_assets']) . ' / ' . money($fin['total_liabilities']) . "\n"
            . 'Insurance coverage: ' . money($fin['insurance_coverage']) . "\n"
            . 'Emergency fund: ' . money($fin['emergency_fund']) . "\n";
    }

    // Heuristic gap list (also powers the offline draft).
    $gaps = [];
    if ($fin) {
        if ((int) $fin['health_score'] < 60) {
            $gaps[] = 'Financial health score is ' . (int) $fin['health_score'] . '/100 (below healthy).';
        }
        if ((float) $fin['emergency_fund'] < (float) $fin['monthly_expenses'] * 6) {
            $gaps[] = 'Emergency fund below the recommended 6 months of expenses.';
        }
        $annual = (float) $fin['monthly_income'] * 12;
        if ($annual > 0 && (float) $fin['insurance_coverage'] < $annual * 10) {
            $gaps[] = 'Protection coverage below ~10x annual income.';
        }
    } else {
        $gaps[] = 'No financial snapshot on record yet.';
    }
    $gapText = $gaps ? '- ' . implode("\n- ", $gaps) : '- No material gaps detected.';

    // --- Per-feature prompt + offline draft -----------------------
    $system = 'You are an assistant for a licensed financial advisory firm. '
        . 'Be concise, professional and factual. Do NOT give definitive '
        . 'financial advice or product recommendations as if final — frame '
        . 'everything as points for the licensed advisor to review. Never '
        . 'invent figures beyond the data provided.';

    switch ($feature) {
        case 'meeting_summary':
            $user = "Summarise these advisor meeting notes into a structured summary "
                . "(Discussion, Decisions, Action items, Follow-up date if any).\n\n"
                . "CLIENT CONTEXT:\n{$facts}\nMEETING NOTES:\n"
                . ($context !== '' ? $context : '(no notes provided)');
            $stub = "MEETING SUMMARY — {$c['full_name']}\n\n"
                . "Discussion:\n" . ($context !== '' ? $context : '(enter your meeting notes to populate this)')
                . "\n\nDecisions:\n- (to be completed by advisor)\n\n"
                . "Action items:\n{$gapText}\n\nNext review: " . fmt_date($c['next_review_date']);
            break;

        case 'followup_draft':
            $user = "Draft a short, warm, professional follow-up message to the client "
                . "after a servicing touchpoint. Keep it under 130 words.\n\n"
                . "CLIENT CONTEXT:\n{$facts}\nADVISOR CONTEXT:\n"
                . ($context !== '' ? $context : '(general check-in)');
            $stub = "Dear {$c['full_name']},\n\nThank you for your time. As discussed, "
                . "we will continue supporting your goals"
                . ($c['financial_goals'] ? " ({$c['financial_goals']})" : '')
                . ". I will follow up before your next review on "
                . fmt_date($c['next_review_date']) . ". Please reach out anytime in the meantime.\n\n"
                . "Warm regards,\n" . current_user()['name'];
            break;

        case 'risk_explanation':
            $cls = label($risk['classification'] ?? $c['risk_appetite']);
            $user = "Explain in plain language what a '{$cls}' risk profile means for "
                . "this client and what it implies for portfolio expectations. "
                . "2 short paragraphs.\n\nCONTEXT:\n{$facts}";
            $stub = "Risk profile: {$cls}\n\nA {$cls} profile indicates the client's "
                . "general comfort with investment volatility in pursuit of returns. "
                . "It should guide — not dictate — asset allocation, and must be "
                . "reconfirmed at each annual review against the client's goals and "
                . "circumstances.";
            break;

        case 'gap_explanation':
            $user = "Explain the following financial gaps to the advisor in clear, "
                . "neutral language and suggest themes to explore (not final advice).\n\n"
                . "CONTEXT:\n{$facts}\nGAPS:\n{$gapText}";
            $stub = "FINANCIAL GAP NOTES — {$c['full_name']}\n\n{$gapText}\n\n"
                . "These are areas for the advisor to review with the client and "
                . "validate before any recommendation.";
            break;

        case 'proposal_draft':
            $user = "Draft an executive summary and a recommendations section for an "
                . "advisory proposal. Frame recommendations as points for advisor "
                . "review.\n\nCONTEXT:\n{$facts}\nGAPS:\n{$gapText}";
            $stub = "EXECUTIVE SUMMARY\n{$c['full_name']} is being reviewed across "
                . "protection, savings and long-term goals. Key themes:\n{$gapText}\n\n"
                . "RECOMMENDATIONS (for advisor review)\n"
                . "- Address the gaps above in priority order.\n"
                . "- Reconfirm risk profile and goals at the next review ("
                . fmt_date($c['next_review_date']) . ").";
            break;

        case 'client_summary':
        default:
            $user = "Write a concise client servicing summary (profile, financial "
                . "position, risk, open items) for the advisor.\n\nCONTEXT:\n{$facts}\n"
                . "GAPS:\n{$gapText}";
            $stub = "CLIENT SUMMARY — {$c['full_name']}\n\n{$facts}\nOpen items:\n{$gapText}";
            break;
    }

    $result = ai_complete($system, $user, $feature, $stub);
    audit_log('ai_generate', 'ai', $clientId,
        $features[$feature] . ($result['stubbed'] ? ' (draft)' : ' (' . $result['model'] . ')'));
}

$pageTitle = 'AI Assistant';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">AI Assistant
    <span class="badge-os <?= ai_enabled() ? 'b-active' : 'b-warn' ?>">
      <?= ai_enabled() ? 'Live · ' . e(ai_provider()) : 'Offline draft mode' ?>
    </span>
  </div>
  <div class="card-os-body">
    <?php if (!ai_enabled()): ?>
      <div class="alert-os info">No AI provider configured. Outputs are
        generated from the client's own data. To go live, set
        <code>AI_PROVIDER</code>, <code>AI_API_KEY</code> and
        <code>AI_MODEL</code> in <code>.env</code> (Anthropic or OpenAI).</div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Client *</label>
          <select name="client_id" required>
            <option value="">— select a client —</option>
            <?php foreach ($clients as $cl): ?>
              <option value="<?= (int)$cl['id'] ?>" <?= $picked['client_id']===(int)$cl['id']?'selected':'' ?>><?= e($cl['full_name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row"><label>Task</label>
          <select name="feature">
            <?php foreach ($features as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= $picked['feature']===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="form-row"><label>Context / meeting notes (optional)</label>
        <textarea name="context" rows="4" placeholder="Paste meeting notes or extra instructions…"><?= e($picked['context']) ?></textarea></div>
      <button class="btn-os gold">Generate</button>
    </form>
  </div>
</div>

<?php if ($result): ?>
<div class="card-os">
  <div class="card-os-head">Result
    <span class="muted" style="font-size:12px;font-weight:400">
      <?= $result['stubbed'] ? 'data-driven draft' : 'model: ' . e($result['model'])
          . ($result['tokens'] ? ' · ' . (int)$result['tokens'] . ' tokens' : '') ?>
    </span>
  </div>
  <div class="card-os-body">
    <?php if (!empty($result['error'])): ?>
      <div class="alert-os warning"><?= e($result['error']) ?></div>
    <?php endif; ?>
    <textarea id="aiOut" rows="16" style="width:100%;font-family:inherit;
      padding:14px;border:1px solid var(--line);border-radius:10px"><?= e($result['text']) ?></textarea>
    <button type="button" class="btn-os ghost sm" style="margin-top:10px"
      onclick="navigator.clipboard.writeText(document.getElementById('aiOut').value);this.textContent='Copied';">
      Copy to clipboard</button>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
