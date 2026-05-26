<?php
/** AdvisorOS — Tax Rates editor (year-versioned LHDN reliefs & bands). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tax_my.php';
require_once __DIR__ . '/../includes/settings.php';
require_role('super_admin');
require_permission('platform.manage');

/** Parse a bands JSON textarea into [[lo, hi|null, rate], ...]. */
function tr_parse_bands(string $raw, array $fallback): array
{
    $d = json_decode($raw, true);
    if (!is_array($d)) { return $fallback; }
    $out = [];
    foreach ($d as $b) {
        if (!is_array($b) || count($b) < 3) { continue; }
        $out[] = [(float) $b[0], $b[1] === null ? null : (float) $b[1], (float) $b[2]];
    }
    return $out ?: $fallback;
}

$cfg = platform_setting_get_json('tax_rates', tax_defaults());
if (empty($cfg['years'])) { $cfg = tax_defaults(); }

if (is_post()) {
    csrf_check();
    $ya = (string) (int) input('ya', tax_current_ya());
    $def = tax_defaults()['years']['2024'];

    $reliefs = [];
    foreach ((array) ($_POST['reliefs'] ?? []) as $k => $r) {
        $k = preg_replace('/[^a-z0-9_]/i', '', (string) $k);
        if ($k === '') { continue; }
        $reliefs[$k] = [
            'label' => trim(mb_substr((string) ($r['label'] ?? $k), 0, 120)),
            'cap'   => max(0.0, (float) ($r['cap'] ?? 0)),
            'group' => trim(mb_substr((string) ($r['group'] ?? 'Other'), 0, 60)),
        ];
    }

    $flat = max(0.0, (float) input('corp_flat', 24));
    if ($flat > 1) { $flat /= 100; }

    $cfg['current_ya'] = (int) input('current_ya', $ya);
    $cfg['years'][$ya] = [
        'personal_bands' => tr_parse_bands((string) input('personal_bands', ''), $def['personal_bands']),
        'rebate_threshold' => max(0.0, (float) input('rebate_threshold', 35000)),
        'rebate_amount'    => max(0.0, (float) input('rebate_amount', 400)),
        'self_relief'      => max(0.0, (float) input('self_relief', 9000)),
        'child_relief'     => max(0.0, (float) input('child_relief', 2000)),
        'child_tertiary_relief' => max(0.0, (float) input('child_tertiary_relief', 8000)),
        'corp_sme_bands'   => tr_parse_bands((string) input('corp_sme_bands', ''), $def['corp_sme_bands']),
        'corp_flat'        => $flat,
        'reliefs'          => $reliefs ?: $def['reliefs'],
    ];
    platform_setting_put_json('tax_rates', $cfg);
    audit_log('update', 'platform', 0, 'Tax rates updated for YA ' . $ya);
    set_flash('success', 'Tax rates saved for YA ' . $ya . '.');
    redirect('superadmin/tax-rates.php?ya=' . $ya);
}

$years = array_keys($cfg['years']);
sort($years);
$ya = (string) (int) ($_GET['ya'] ?? ($cfg['current_ya'] ?? 2024));
$y  = $cfg['years'][$ya] ?? tax_defaults()['years']['2024'];
$bandsJson = json_encode($y['personal_bands'] ?? [], JSON_PRETTY_PRINT);
$corpJson  = json_encode($y['corp_sme_bands'] ?? [], JSON_PRETTY_PRINT);

$pageTitle = 'Tax Rates';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Tax Rates</h2>
    <span class="muted">LHDN reliefs &amp; bands, versioned by assessment year ·
      active YA <strong><?= (int) ($cfg['current_ya'] ?? 2024) ?></strong></span>
  </div>
  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
    <span class="muted" style="font-size:12px">Years:</span>
    <?php foreach ($years as $yr): ?>
      <a class="btn-os <?= (string)$yr===$ya?'':'ghost' ?> sm" href="<?= e(url('superadmin/tax-rates.php?ya='.(int)$yr)) ?>"><?= (int) $yr ?></a>
    <?php endforeach; ?>
  </div>
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="ya" value="<?= e($ya) ?>">

  <div class="grid cols-2">
    <div class="card-os">
      <div class="card-os-head">Editing assessment year <?= e($ya) ?></div>
      <div class="card-os-body">
        <div class="form-grid">
          <div class="form-row"><label>Active assessment year (used by the app)</label>
            <input type="number" name="current_ya" value="<?= (int) ($cfg['current_ya'] ?? 2024) ?>"></div>
          <div class="form-row"><label>Self &amp; dependent relief (RM)</label>
            <input type="number" step="0.01" name="self_relief" value="<?= e($y['self_relief'] ?? 9000) ?>"></div>
          <div class="form-row"><label>Child relief — under 18 (RM)</label>
            <input type="number" step="0.01" name="child_relief" value="<?= e($y['child_relief'] ?? 2000) ?>"></div>
          <div class="form-row"><label>Child relief — 18+ tertiary (RM)</label>
            <input type="number" step="0.01" name="child_tertiary_relief" value="<?= e($y['child_tertiary_relief'] ?? 8000) ?>"></div>
          <div class="form-row"><label>Rebate threshold (RM)</label>
            <input type="number" step="0.01" name="rebate_threshold" value="<?= e($y['rebate_threshold'] ?? 35000) ?>"></div>
          <div class="form-row"><label>Rebate amount (RM)</label>
            <input type="number" step="0.01" name="rebate_amount" value="<?= e($y['rebate_amount'] ?? 400) ?>"></div>
          <div class="form-row"><label>Corporate flat rate (%)</label>
            <input type="number" step="0.01" name="corp_flat" value="<?= e(round(($y['corp_flat'] ?? 0.24) * 100, 2)) ?>"></div>
        </div>
        <div class="form-row"><label>Personal tax bands — JSON [lower, upper or null, rate]</label>
          <textarea name="personal_bands" rows="8" style="font-family:monospace;font-size:12px"><?= e($bandsJson) ?></textarea></div>
        <div class="form-row"><label>Corporate SME bands — JSON</label>
          <textarea name="corp_sme_bands" rows="4" style="font-family:monospace;font-size:12px"><?= e($corpJson) ?></textarea></div>
        <p class="muted" style="font-size:12px">Rates are decimals (0.15 = 15%); the
          top band's upper bound is <code>null</code> for "and above". Enter a new
          year number in the URL (e.g. <code>?ya=2025</code>) then Save to create it.</p>
      </div>
    </div>

    <div class="card-os">
      <div class="card-os-head">Reliefs (cap per relief)</div>
      <div class="card-os-body" style="padding:0">
        <table class="table-os">
          <thead><tr><th>Relief</th><th>Group</th><th>Cap (RM)</th></tr></thead>
          <tbody>
          <?php foreach (($y['reliefs'] ?? []) as $k => $r): ?>
            <tr>
              <td><input name="reliefs[<?= e($k) ?>][label]" value="<?= e($r['label'] ?? $k) ?>" style="width:100%"></td>
              <td><input name="reliefs[<?= e($k) ?>][group]" value="<?= e($r['group'] ?? '') ?>" style="width:100%"></td>
              <td><input type="number" step="0.01" name="reliefs[<?= e($k) ?>][cap]" value="<?= e($r['cap'] ?? 0) ?>" style="width:110px"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div style="margin-top:16px"><button class="btn-os gold">Save YA <?= e($ya) ?></button></div>
  <div class="disclaimer" style="margin-top:14px">Changes apply immediately to the
    tax planner, impact report and Tax Planning solution for the active assessment
    year. Verify every figure against the official LHDN schedule before saving —
    these drive client-facing tax estimates.</div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
