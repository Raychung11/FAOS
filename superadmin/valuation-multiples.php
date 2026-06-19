<?php
/** AdvisorOS — Valuation multiples library editor (per-industry EBITDA bands). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/valuation.php';
require_once __DIR__ . '/../includes/settings.php';
require_role('super_admin');
require_permission('platform.manage');

if (is_post()) {
    csrf_check();
    if (input('action') === 'reset') {
        platform_setting_put_json('valuation_multiples',
            ['industries' => valuation_industries_defaults()]);
        audit_log('update', 'platform', 0, 'Valuation multiples reset to built-in defaults');
        set_flash('success', 'Reverted to built-in defaults.');
        redirect('superadmin/valuation-multiples.php');
    }

    $codes  = (array) ($_POST['code']  ?? []);
    $labels = (array) ($_POST['label'] ?? []);
    $lows   = (array) ($_POST['low']   ?? []);
    $mids   = (array) ($_POST['mid']   ?? []);
    $highs  = (array) ($_POST['high']  ?? []);
    $rows = [];
    $n = max(count($codes), count($labels));
    for ($i = 0; $i < $n; $i++) {
        $code  = preg_replace('/[^a-z0-9_]/i', '_', trim(mb_strtolower((string) ($codes[$i] ?? ''))));
        $label = trim(mb_substr((string) ($labels[$i] ?? ''), 0, 80));
        if ($code === '' || $label === '') { continue; }
        $rows[] = [
            'code'        => $code,
            'label'       => $label,
            'ebitda_low'  => max(0.0, (float) ($lows[$i] ?? 0)),
            'ebitda_mid'  => max(0.0, (float) ($mids[$i] ?? 0)),
            'ebitda_high' => max(0.0, (float) ($highs[$i] ?? 0)),
        ];
    }
    if (!$rows) { $rows = valuation_industries_defaults(); }
    platform_setting_put_json('valuation_multiples', ['industries' => $rows]);
    audit_log('update', 'platform', 0, 'Valuation multiples updated (' . count($rows) . ' industries)');
    set_flash('success', 'Saved · ' . count($rows) . ' industries.');
    redirect('superadmin/valuation-multiples.php');
}

$industries = valuation_industries();
// Always render two blank rows at the bottom for new entries.
$blank = ['code' => '', 'label' => '', 'ebitda_low' => '', 'ebitda_mid' => '', 'ebitda_high' => ''];
$rows  = array_merge($industries, [$blank, $blank]);

$pageTitle = 'Valuation Multiples';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Valuation Multiples</h2>
    <span class="muted">Industry EBITDA multiple library — used to suggest a starting multiple on each company's valuation.</span>
  </div>
  <form method="post" style="margin:0" onsubmit="return confirm('Revert all industries to the built-in defaults?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <button class="btn-os ghost sm">Reset to defaults</button>
  </form>
</div>

<form method="post">
  <?= csrf_field() ?>
  <div class="card-os">
    <div class="card-os-head">Industries · <?= count($industries) ?> entries (edit, add or remove)</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr>
          <th>Code</th><th>Label</th>
          <th style="text-align:right">Low ×</th>
          <th style="text-align:right">Mid ×</th>
          <th style="text-align:right">High ×</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input name="code[]" value="<?= e($r['code']) ?>" style="width:120px"
                       placeholder="e.g. logistics"></td>
            <td><input name="label[]" value="<?= e($r['label']) ?>" style="width:100%"
                       placeholder="Industry display label"></td>
            <td><input type="number" step="0.1" name="low[]"  value="<?= e($r['ebitda_low']) ?>"  style="width:80px;text-align:right"></td>
            <td><input type="number" step="0.1" name="mid[]"  value="<?= e($r['ebitda_mid']) ?>"  style="width:80px;text-align:right"></td>
            <td><input type="number" step="0.1" name="high[]" value="<?= e($r['ebitda_high']) ?>" style="width:80px;text-align:right"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-os-body">
      <button class="btn-os gold">Save industries</button>
      <span class="muted" style="font-size:12px;margin-left:10px">Blank rows are ignored. Re-save to add more rows.</span>
      <div class="disclaimer">Multiples are <em>illustrative</em> — calibrate against
        local market evidence (comparable transactions, sector reports). The
        suggestion appears next to each company's EBITDA multiple input on the
        Valuation page.</div>
    </div>
  </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
