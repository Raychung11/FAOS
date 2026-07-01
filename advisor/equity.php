<?php
/** AdvisorOS — Equity Structure Risk Assessment (per company). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/business.php';
require_once __DIR__ . '/../includes/equity_engine.php';
require_once __DIR__ . '/../includes/equity_kb.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId  = (int) ($_GET['client_id']  ?? 0);
$companyId = (int) ($_GET['company_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$companies = companies_for_client($clientId);
if (!$companies) {
    $pageTitle = 'Equity Assessment — ' . $client['full_name'];
    require __DIR__ . '/../includes/header.php';
    echo '<div class="alert-os warning">No companies recorded. Add one under
        <a href="' . e(url('advisor/companies.php?client_id='.$clientId)) . '">Business profile</a>
        first.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// Default to first company if not specified.
if (!$companyId) { $companyId = (int) $companies[0]['id']; }
$company = null;
foreach ($companies as $co) { if ((int) $co['id'] === $companyId) { $company = $co; break; } }
if (!$company) { http_response_code(404); exit('Company not found for this client.'); }

if (is_post()) {
    csrf_check();
    $action = (string) input('action', 'save');

    if ($action === 'ai_commentary') {
        $res = equity_ai_generate($clientId, $company);
        setting_put_json('equity_ai:' . $companyId, [
            'text'    => $res['text'],
            'model'   => $res['model'],
            'tokens'  => $res['tokens'],
            'stubbed' => $res['stubbed'],
            'updated_at' => date('Y-m-d H:i'),
            'updated_by' => current_user()['name'] ?? '',
        ]);
        meter_report('equity');
        audit_log('ai_generate', 'equity', $companyId,
            'AI equity commentary' . ($res['stubbed'] ? ' (draft)' : ' (' . $res['model'] . ')'));
        set_flash($res['stubbed'] ? 'warning' : 'success',
            $res['stubbed']
                ? 'Data-driven commentary drafted (no AI provider configured).'
                : 'AI commentary generated — review before sharing.');
        redirect('advisor/equity.php?client_id=' . $clientId . '&company_id=' . $companyId);
    }
    if ($action === 'ai_clear') {
        setting_put_json('equity_ai:' . $companyId, []);
        audit_log('delete', 'equity', $companyId, 'AI equity commentary cleared');
        set_flash('success', 'AI commentary cleared.');
        redirect('advisor/equity.php?client_id=' . $clientId . '&company_id=' . $companyId);
    }

    // Save assessment.
    $data = [
        'scores'   => (array) input('scores', []),
        'dd'       => (array) input('dd', []),
        'red_flags'=> (array) input('red_flags', []),
        'archetype'=> (string) input('archetype', ''),
    ];
    equity_save($companyId, $data);
    meter_report('equity');
    audit_log('update', 'equity', $companyId, 'Equity assessment saved');
    set_flash('success', 'Equity assessment saved.');
    redirect('advisor/equity.php?client_id=' . $clientId . '&company_id=' . $companyId);
}

/** Build the AI equity commentary — verified engine facts + KB. */
function equity_ai_generate(int $clientId, array $company): array
{
    $data  = equity_load((int) $company['id']);
    $score = equity_score($data);
    $cats  = equity_categories();

    $facts  = "Company: {$company['name']} (owner stake "
        . rtrim(rtrim(number_format((float) $company['ownership_pct'], 2), '0'), '.') . "%).\n";
    $facts .= "Composite equity-structure risk score: " . round($score['total'], 1)
        . "/100 — {$score['band']}.\n";
    $facts .= "Indicators answered: {$score['answered']}/{$score['total_indicators']}.\n";
    $facts .= "DD documents on file: {$score['dd_done']}/{$score['dd_total']}.\n";
    if ($data['archetype']) {
        $arch = equity_archetypes()[$data['archetype']]['label'] ?? $data['archetype'];
        $facts .= "Selected archetype: {$arch}.\n";
    }
    $facts .= "\nPer-category scores (0-100, higher = healthier):\n";
    foreach ($score['by_cat'] as $c => $r) {
        if ($r['answered'] === 0) { continue; }
        $facts .= "  • {$cats[$c]['label']}: " . round($r['pct'], 1)
            . "% ({$r['answered']} indicators, weight {$r['weight']}%).\n";
    }
    if ($score['red_flags']) {
        $facts .= "\nTriggered red flags:\n";
        foreach ($score['red_flags'] as $rf) {
            $facts .= "  • {$rf['label']} — impact: {$rf['impact']}.\n";
        }
    }
    // Include low-scoring indicators (score ≤ 2) so AI can name them.
    $low = [];
    foreach (equity_indicators() as $ind) {
        $s = $data['scores'][$ind['id']]['score'] ?? null;
        if ($s !== null && $s <= 2) {
            $low[] = "#{$ind['id']} {$ind['label']} — score {$s}"
                . ($data['scores'][$ind['id']]['note'] ? '; note: '
                    . mb_substr($data['scores'][$ind['id']]['note'], 0, 140) : '');
        }
    }
    if ($low) {
        $facts .= "\nLow-scoring indicators requiring remediation:\n  • "
            . implode("\n  • ", $low) . "\n";
    }

    $kb = eq_kb_prompt();
    $system = 'You are a Malaysian equity-structure & governance specialist preparing '
        . 'adviser-grade commentary on a private-company equity health check. CRITICAL: '
        . 'use ONLY the figures / scores / flags provided below — NEVER invent, restate '
        . 'or "correct" any score or band. Frame recommendations as adviser-grade points '
        . 'for the licensed adviser / company secretary / lawyer to review; flag Shariah, '
        . 'BO, foreign-equity and licensing items to the appropriate professional. Do NOT '
        . 'give legal advice — recommend engaging counsel where relevant. Output five '
        . 'sections with these EXACT headers, one per line, 3-5 sentences each:'
        . "\n## SECTION: overall"
        . "\n## SECTION: control"
        . "\n## SECTION: role_fit"
        . "\n## SECTION: compliance"
        . "\n## SECTION: succession"
        . ($kb !== '' ? "\n\nApply the firm's equity playbook and house style:\n" . $kb : '');

    $user = "Write the equity structure commentary from these verified figures "
        . "(do not restate them as a table):\n\n" . $facts;

    $stub = "## SECTION: overall\nThe composite score signals where remediation "
        . "should focus first. Highlight the two categories with the lowest weighted "
        . "contribution and connect them to the red flags actually triggered.\n\n"
        . "## SECTION: control\nAssess whether control is unambiguous — one clear "
        . "controller, no unresolved deadlock risk, and voting rights aligned with "
        . "economic exposure. Flag any 50:50 or blocking-minority situation.\n\n"
        . "## SECTION: role_fit\nAssess whether the equity split reflects who actually "
        . "creates value today (CEO, tech, sales, capital). Undocumented contributions "
        . "and unclear IP ownership are the most damaging in a DD.\n\n"
        . "## SECTION: compliance\nCheck foreign-equity sector limits, SSM BO filing, "
        . "nominee documentation, and licensing conditions. Muslim shareholders need "
        . "Shariah-planning support to avoid faraid-driven estate freezes.\n\n"
        . "## SECTION: succession\nRecommend the SHA / buy-sell / keyman-insurance / "
        . "hibah-wasiat stack the firm typically deploys. Sequence: cap-table clean-up "
        . "first, then documentation, then succession funding.";

    return ai_complete($system, $user, 'equity_commentary', $stub);
}

$data  = equity_load($companyId);
$score = equity_score($data);
$cats  = equity_categories();
$inds  = equity_indicators();
$ai    = setting_get_json('equity_ai:' . $companyId, []);

$pageTitle = 'Equity Assessment — ' . $company['name'];
require __DIR__ . '/../includes/header.php';
?>
<style>
  .eq-cat{border-left:3px solid var(--gold);padding:14px 18px;background:#f7f9fc;
          border-radius:6px;margin-bottom:14px}
  .eq-cat h3{margin:0 0 4px;color:var(--navy);font-size:15px}
  .eq-cat .blurb{color:var(--muted);font-size:12.5px;margin:0 0 12px}
  .eq-ind{display:grid;grid-template-columns:1fr 130px 2fr;gap:12px;
          padding:10px 0;border-bottom:1px dashed #d4d9e0;align-items:start}
  .eq-ind:last-child{border-bottom:none}
  .eq-ind .lbl{font-weight:600;color:var(--navy);font-size:13.5px}
  .eq-ind .lbl small{display:block;color:var(--muted);font-weight:400;
                     font-size:11.5px;margin-top:2px}
  .eq-ind .zh{color:#6b7686;font-weight:500;font-size:12px}
  .eq-ind select,.eq-ind input{width:100%;padding:6px 8px;border:1px solid var(--line);
                                border-radius:6px;font-size:13px;box-sizing:border-box}
  .eq-band{display:inline-block;padding:4px 12px;border-radius:14px;font-weight:700;font-size:13px}
  .eq-band-low{background:#dcf5e3;color:#1e6b3a}
  .eq-band-moderate{background:#fff2c9;color:#7a5b00}
  .eq-band-high{background:#ffd5c9;color:#8a2f0f}
  .eq-band-critical{background:#f5c9c9;color:#7c1414}
  .eq-band-unknown{background:#e9edf3;color:#5a6675}
  .eq-bar{height:8px;background:#e9edf3;border-radius:4px;overflow:hidden;margin-top:4px}
  .eq-bar-fill{height:100%;background:var(--gold);transition:width .3s}
  .eq-rf{background:#fdecea;border-left:3px solid #d84315;padding:10px 14px;
         border-radius:6px;margin-bottom:8px;font-size:13px}
  .eq-rf.auto{background:#fff7e6;border-left-color:#c9a227}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Equity Structure Assessment</h2>
    <span class="muted"><?= e($client['full_name']) ?> · 50-indicator framework for Malaysian SMEs</span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn-os sm" href="<?= e(url('advisor/equity-pdf.php?company_id='.$companyId)) ?>">Download PDF</a>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<?php if (count($companies) > 1): ?>
  <div class="card-os" style="margin-bottom:14px">
    <div class="card-os-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <strong style="color:var(--navy);font-size:13px">Company:</strong>
      <?php foreach ($companies as $co): ?>
        <a class="btn-os <?= (int) $co['id'] === $companyId ? '' : 'ghost' ?> sm"
           href="<?= e(url('advisor/equity.php?client_id='.$clientId.'&company_id='.(int)$co['id'])) ?>">
          <?= e($co['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Composite risk score</div>
    <div class="stat-value"><?= round($score['total'], 1) ?><span style="font-size:14px;color:var(--muted)">/100</span></div>
    <div class="stat-foot"><span class="eq-band eq-band-<?= e($score['band_code']) ?>"><?= e($score['band']) ?></span></div></div>
  <div class="stat accent"><div class="stat-label">Indicators answered</div>
    <div class="stat-value"><?= $score['answered'] ?><span style="font-size:14px;color:var(--muted)">/<?= $score['total_indicators'] ?></span></div>
    <div class="stat-foot">out of 50 framework indicators</div></div>
  <div class="stat accent"><div class="stat-label">DD documents on file</div>
    <div class="stat-value"><?= $score['dd_done'] ?><span style="font-size:14px;color:var(--muted)">/<?= $score['dd_total'] ?></span></div>
    <div class="stat-foot">due-diligence document checklist</div></div>
</div>

<div class="card-os" style="margin-bottom:14px">
  <div class="card-os-head">Category scores <span class="muted" style="font-weight:400;font-size:12px">· weighted 0-100, higher = healthier</span></div>
  <div class="card-os-body">
    <?php foreach ($cats as $c => $meta): $r = $score['by_cat'][$c]; ?>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span><strong><?= e($meta['label']) ?></strong>
            <span class="muted" style="font-size:11.5px">· <?= e($meta['label_zh']) ?> · weight <?= $r['weight'] ?>%</span></span>
          <span style="color:var(--navy);font-weight:600">
            <?= $r['answered'] > 0 ? round($r['pct'], 1) . '%' : '<span class="muted" style="font-weight:400">not started</span>' ?>
            <span class="muted" style="font-size:11.5px">· <?= $r['answered'] ?> answered</span>
          </span>
        </div>
        <div class="eq-bar"><div class="eq-bar-fill" style="width:<?= $r['answered'] > 0 ? (int) $r['pct'] : 0 ?>%"></div></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($score['red_flags']): ?>
  <div class="card-os" style="margin-bottom:14px">
    <div class="card-os-head">Triggered red flags <span class="muted" style="font-weight:400;font-size:12px">(<?= count($score['red_flags']) ?>)</span></div>
    <div class="card-os-body">
      <?php foreach ($score['red_flags'] as $rf): ?>
        <div class="eq-rf <?= $rf['source'] === 'auto' ? 'auto' : '' ?>">
          <strong><?= e($rf['label']) ?></strong>
          <div class="muted" style="font-size:12px">
            Impact: <?= e($rf['impact']) ?>
            · <?= $rf['source'] === 'auto' ? 'auto-detected from scores' : 'flagged manually by advisor' ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<form method="post" id="equity-form">
  <?= csrf_field() ?>

  <div class="card-os" style="margin-bottom:14px">
    <div class="card-os-head">Ownership archetype
      <span class="muted" style="font-weight:400;font-size:12px">· pick the closest match</span></div>
    <div class="card-os-body">
      <select name="archetype" style="max-width:520px">
        <option value="">— unclassified —</option>
        <?php foreach (equity_archetypes() as $k => $a): ?>
          <option value="<?= e($k) ?>"<?= $data['archetype'] === $k ? ' selected' : '' ?>>
            <?= e($a['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($data['archetype'] && isset(equity_archetypes()[$data['archetype']])): ?>
        <div class="muted" style="font-size:12.5px;margin-top:6px">
          <?= e(equity_archetypes()[$data['archetype']]['note']) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($cats as $c => $meta):
        $catIndicators = array_filter($inds, fn ($i) => $i['cat'] === $c); ?>
    <div class="card-os" style="margin-bottom:14px">
      <div class="card-os-head">
        <?= e($c) ?>. <?= e($meta['label']) ?>
        <span class="muted" style="font-weight:400;font-size:12px">
          · <?= e($meta['label_zh']) ?> · weight <?= equity_category_weights()[$c] ?>%
        </span>
      </div>
      <div class="card-os-body">
        <div class="muted" style="font-size:12.5px;margin-bottom:10px"><?= e($meta['blurb']) ?></div>
        <?php foreach ($catIndicators as $ind):
              $row = $data['scores'][$ind['id']] ?? ['score' => null, 'note' => '']; ?>
          <div class="eq-ind">
            <div class="lbl">
              #<?= $ind['id'] ?> <?= e($ind['label']) ?>
              <span class="zh">· <?= e($ind['label_zh']) ?></span>
              <small><?= e($ind['focus']) ?> — <em><?= e($ind['risk']) ?></em></small>
            </div>
            <div>
              <select name="scores[<?= $ind['id'] ?>][score]">
                <option value="">— not scored —</option>
                <?php foreach (equity_score_bands() as $band => [$lab, $desc]): ?>
                  <option value="<?= $band ?>"<?= $row['score'] === $band ? ' selected' : '' ?>>
                    <?= $band ?> · <?= e($lab) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <input name="scores[<?= $ind['id'] ?>][note]" maxlength="800"
                     placeholder="Note (evidence / context / remedy)"
                     value="<?= e($row['note']) ?>">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="card-os" style="margin-bottom:14px">
    <div class="card-os-head">Due-diligence document checklist</div>
    <div class="card-os-body">
      <div class="muted" style="font-size:12.5px;margin-bottom:10px">
        Tick each pack you have on file. This drives the DD-readiness figure at the top.
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px">
        <?php foreach (equity_dd_checklist() as $key => $label): ?>
          <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;padding:4px 0">
            <input type="checkbox" name="dd[<?= e($key) ?>]" value="1"<?= !empty($data['dd'][$key]) ? ' checked' : '' ?>
                   style="margin-top:3px">
            <span><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card-os" style="margin-bottom:14px">
    <div class="card-os-head">Manual red flags
      <span class="muted" style="font-weight:400;font-size:12px">
        · tick anything the scoring doesn't already catch
      </span></div>
    <div class="card-os-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px">
        <?php foreach (equity_red_flags() as $key => [$label, $impact]): ?>
          <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;padding:4px 0">
            <input type="checkbox" name="red_flags[<?= e($key) ?>]" value="1"<?= !empty($data['red_flags'][$key]) ? ' checked' : '' ?>
                   style="margin-top:3px">
            <span><strong><?= e($label) ?></strong>
              <div class="muted" style="font-size:11.5px">Impact: <?= e($impact) ?></div></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <button class="btn-os gold">Save assessment</button>
    <span class="muted" style="font-size:12px;align-self:center">
      Last saved: <?= e($data['meta']['updated_at'] ?: 'never') ?>
      <?= $data['meta']['updated_by'] ? '· by ' . e($data['meta']['updated_by']) : '' ?>
    </span>
  </div>
</form>

<div class="card-os" style="margin-bottom:14px">
  <div class="card-os-head">AI Equity Commentary
    <?php if (!empty($ai['text'])): ?>
      <span class="muted" style="font-weight:400;font-size:12px">·
        last generated <?= e($ai['meta']['updated_at'] ?? $ai['updated_at'] ?? '') ?>
        <?= !empty($ai['stubbed']) ? '· offline draft' : '· model ' . e($ai['model'] ?? '') ?>
      </span>
    <?php endif; ?>
  </div>
  <div class="card-os-body">
    <div class="muted" style="font-size:13px;margin-bottom:10px">
      Scores, categories, red flags and DD checklist come from the verified engine.
      The AI writes the surrounding <em>narrative</em> using active entries from your
      <a href="<?= e(url('advisor/equity-knowledge.php')) ?>">Equity Knowledge Base</a>.
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ai_commentary">
        <button class="btn-os gold sm">
          <?= !empty($ai['text']) ? 'Regenerate commentary' : 'Generate commentary' ?>
        </button>
      </form>
      <?php if (!empty($ai['text'])): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Clear AI commentary?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="ai_clear">
          <button class="btn-os ghost sm">Clear</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if (!empty($ai['text'])): ?>
      <div style="white-space:pre-wrap;font-size:13px;line-height:1.55;
                  background:#f7f9fc;border-left:3px solid var(--gold);
                  padding:12px 14px;border-radius:6px"><?= e($ai['text']) ?></div>
    <?php else: ?>
      <div class="muted" style="font-size:13px;font-style:italic">
        No commentary generated yet — save your assessment then click
        <strong>Generate commentary</strong> to produce an adviser-grade narrative.
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card-os">
  <div class="card-os-body">
    <div class="disclaimer">This equity structure assessment is a commercial /
      governance health-check framework. It does <strong>not</strong> constitute
      legal, tax, Shariah-planning or SSM compliance advice. Anything touching
      the Companies Act, foreign-equity conditions, nominee arrangements,
      faraid / hibah / wasiat, or SHA drafting must be reviewed by a licensed
      lawyer, tax agent, company secretary or Shariah planner as applicable.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
