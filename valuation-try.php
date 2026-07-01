<?php
/**
 * AdvisorOS — Public "Try the Valuation" calculator.
 *
 * A no-signup teaser of the platform's valuation engine: visitor enters
 * 7 simple numbers + their industry and risk band, and gets back the
 * full NAV / EBITDA-multiple / DCF blend that paying customers see
 * inside the app. Capped at 3 calculations per visitor (session cookie
 * + IP-hash daily counter); after that, a "sign up to keep going" CTA
 * is shown.
 *
 * No DB writes other than the IP counter. No PII captured.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/valuation.php';
require_once __DIR__ . '/includes/owner_auth.php';

// Public page — but signed-in advisors should go to the real tool.
if (is_logged_in() || attempt_remember_login()) {
    redirect('advisor/clients.php');
}

$owner = owner_current();

const VTRY_MAX_PER_VISITOR = 3;

/** Hash the requester IP (with a daily salt) — never stored raw. */
function vtry_ip_key(): string
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $salt = (string) env('APP_KEY', 'advisoros');
    return 'vtry:' . substr(hash('sha256', $ip . '|' . $salt . '|' . date('Y-m-d')), 0, 16);
}

/** Read combined visitor count: max(session counter, daily IP counter). */
function vtry_count_used(): int
{
    $sess = (int) ($_SESSION['vtry_count'] ?? 0);
    $ip   = 0;
    try { $ip = (int) (platform_setting_get_json(vtry_ip_key(), ['n' => 0])['n'] ?? 0); }
    catch (Throwable $e) { /* DB optional — fall back to session-only */ }
    return max($sess, $ip);
}

/** Increment both counters after a successful calculation. */
function vtry_count_increment(): void
{
    $_SESSION['vtry_count'] = (int) ($_SESSION['vtry_count'] ?? 0) + 1;
    try {
        $key = vtry_ip_key();
        $cur = (int) (platform_setting_get_json($key, ['n' => 0])['n'] ?? 0);
        platform_setting_put_json($key, ['n' => $cur + 1]);
    } catch (Throwable $e) { /* best-effort */ }
}

$used    = vtry_count_used();
$locked  = !$owner && $used >= VTRY_MAX_PER_VISITOR;
$result  = null;
$inputs  = [
    'industry'      => '',
    'revenue'       => '',
    'ebitda'        => '',
    'total_assets'  => '',
    'total_liab'    => '',
    'net_debt'      => '',
    'ownership_pct' => 100,
    'risk_band'     => 1,
];
$errors  = [];

if (is_post() && !$locked) {
    csrf_check();

    foreach ($inputs as $k => $_) {
        $inputs[$k] = (string) input($k, '');
    }

    $industry      = (string) $inputs['industry'];
    $revenue       = (float) str_replace(',', '', $inputs['revenue']);
    $ebitda        = (float) str_replace(',', '', $inputs['ebitda']);
    $totalAssets   = (float) str_replace(',', '', $inputs['total_assets']);
    $totalLiab     = (float) str_replace(',', '', $inputs['total_liab']);
    $netDebt       = max(0.0, (float) str_replace(',', '', $inputs['net_debt']));
    $ownershipPct  = max(0.1, min(100.0, (float) $inputs['ownership_pct']));
    $riskBand      = max(0, min(3, (int) $inputs['risk_band']));

    if ($industry === '')                   { $errors[] = 'Pick the closest industry.'; }
    if ($revenue <= 0)                      { $errors[] = 'Revenue must be greater than zero.'; }
    if ($totalAssets <= 0)                  { $errors[] = 'Total assets must be greater than zero.'; }
    if ($ebitda > $revenue)                 { $errors[] = 'EBITDA can\'t exceed revenue — please re-check.'; }
    if ($totalLiab > $totalAssets * 5)      { $errors[] = 'Liabilities look unusually high vs assets — please re-check.'; }

    if (!$errors) {
        $hint     = valuation_industry_lookup($industry);
        $multiple = $hint ? (float) $hint['ebitda_mid'] : 4.0;

        $company = ['ownership_pct' => $ownershipPct, 'industry' => $industry, 'name' => 'Your business'];
        $bf = [
            'total_assets'      => $totalAssets,
            'total_liabilities' => $totalLiab,
            'ebitda'            => $ebitda,
            'net_profit'        => $ebitda * 0.72, // post-tax-ish, only used as DCF fallback
            'bank_loans'        => $netDebt,
            'shareholder_loans' => 0.0,
            'cash'              => 0.0,
        ];
        $assu = [
            'multiple'      => $multiple,
            'discount'      => 18.0,
            'growth'        => 3.0,
            'weight_nav'    => 20,
            'weight_ebitda' => 50,
            'weight_dcf'    => 30,
            'dlom_pct'      => 25,
            'minority_pct'  => $ownershipPct < 50 ? 15 : 0,
            'dcf_method'    => 'gordon',
            'adjustments'   => [],
        ];
        $v = company_valuation($company, $bf, $assu, $riskBand);

        $result = [
            'val'       => $v,
            'industry'  => $hint,
            'multiple'  => $multiple,
            'ownership' => $ownershipPct,
            'risk_band' => $riskBand,
        ];
        // Signed-in owners get unlimited calculations and every result is
        // pinned to their dashboard automatically.
        if ($owner) {
            $ref = 'vt_' . substr(hash('sha256',
                (string) microtime(true) . '|' . $owner['email']), 0, 16);
            owner_attach_report('valuation', $ref, [
                'label'      => ($industry ?: 'Business valuation'),
                'mid'        => (float) $v['mid'],
                'stake'      => (float) $v['client_stake'],
                'ownership'  => $ownershipPct,
                'multiple'   => $multiple,
                'inputs'     => $inputs,
            ]);
        } else {
            vtry_count_increment();
            $used   = vtry_count_used();
            $locked = $used >= VTRY_MAX_PER_VISITOR;
        }
    }
}

$riskLabels = [0 => 'Low (well-diversified, strong #2)',
               1 => 'Medium (typical owner-managed SME)',
               2 => 'High (founder-dependent)',
               3 => 'Critical (single point of failure)'];

$pageTitle = 'Try the Business Valuation — AdvisorOS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="Free indicative business valuation — three transparent methods, an instant range, no signup. Built for Malaysian SMEs.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<style>
  body{background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif;margin:0}
  .vt-wrap{max-width:980px;margin:0 auto;padding:0 22px}
  .vt-nav{display:flex;justify-content:space-between;align-items:center;padding:18px 0;
          border-bottom:1px solid var(--line);background:#fff}
  .vt-logo{font-weight:800;font-size:22px;color:var(--navy)}
  .vt-logo span{color:var(--gold)}
  .vt-hero{padding:50px 0 24px;text-align:center}
  .vt-hero h1{font-size:36px;line-height:1.15;margin:0 0 14px;color:var(--navy);font-weight:800}
  .vt-hero p{font-size:17px;color:var(--muted);max-width:600px;margin:0 auto 18px}
  .vt-quota{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:20px;
            background:#fff;border:1px solid var(--line);font-size:13px;color:var(--muted);font-weight:500}
  .vt-quota strong{color:var(--navy)}
  .vt-card{background:#fff;border:1px solid var(--line);border-radius:14px;
           padding:28px 30px;margin:18px 0;box-shadow:0 4px 18px rgba(11,31,58,.04)}
  .vt-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .vt-grid .vt-row.full{grid-column:1/-1}
  .vt-row label{display:block;font-size:13px;font-weight:600;color:var(--navy);margin-bottom:6px}
  .vt-row .hint{display:block;font-size:11.5px;color:var(--muted);margin-top:4px;font-weight:400}
  .vt-row input,.vt-row select{width:100%;padding:11px 13px;border:1px solid var(--line);
            border-radius:9px;font-size:14px;background:#fff;color:var(--ink);box-sizing:border-box}
  .vt-row input:focus,.vt-row select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,162,39,.18)}
  .vt-prefix{position:relative}
  .vt-prefix input{padding-left:42px}
  .vt-prefix::before{content:'RM';position:absolute;left:13px;top:50%;transform:translateY(-50%);
                     font-size:13px;color:var(--muted);font-weight:600;pointer-events:none}
  .vt-suffix{position:relative}
  .vt-suffix input{padding-right:32px}
  .vt-suffix::after{content:'%';position:absolute;right:13px;top:50%;transform:translateY(-50%);
                    font-size:13px;color:var(--muted);font-weight:600;pointer-events:none}
  .vt-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 28px;
          border-radius:10px;font-weight:600;font-size:15px;text-decoration:none;
          border:none;cursor:pointer;font-family:inherit}
  .vt-btn.primary{background:var(--gold);color:#1a1405}
  .vt-btn.primary:hover{filter:brightness(.95)}
  .vt-btn.ghost{background:#fff;color:var(--navy);border:1px solid var(--line)}
  .vt-errs{background:#fdecea;border:1px solid #f0b4ad;color:#922b21;border-radius:8px;
           padding:12px 16px;margin-bottom:18px;font-size:14px}
  .vt-errs ul{margin:4px 0 0 18px;padding:0}
  .vt-result-hero{text-align:center;padding:18px 12px 24px;background:linear-gradient(135deg,#0B1F3A 0%,#162e4a 100%);
                  color:#fff;border-radius:14px;margin-bottom:18px}
  .vt-result-hero .label{font-size:13px;color:#a8b6c9;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
  .vt-result-hero .value{font-size:36px;font-weight:800;margin:8px 0 6px;color:var(--gold)}
  .vt-result-hero .range{font-size:14px;color:#cdd6e4}
  .vt-table{width:100%;border-collapse:collapse;margin:8px 0}
  .vt-table th,.vt-table td{padding:10px 14px;border-bottom:1px solid var(--line);font-size:14px;text-align:left}
  .vt-table th{background:#f7f9fc;color:var(--navy);font-weight:600;font-size:12.5px;
               text-transform:uppercase;letter-spacing:.04em}
  .vt-table .tnum{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  .vt-table tr.total td{font-weight:700;color:var(--navy);background:#f7f9fc}
  .vt-cta{background:linear-gradient(135deg,var(--gold) 0%,#b8901f 100%);color:#1a1405;
          padding:28px 30px;border-radius:14px;text-align:center;margin-top:22px}
  .vt-cta h3{margin:0 0 8px;font-size:22px;font-weight:800}
  .vt-cta p{margin:0 0 18px;font-size:15px;color:#3d3008;max-width:520px;margin-left:auto;margin-right:auto}
  .vt-cta .vt-btn{background:#0B1F3A;color:#fff}
  .vt-disclaimer{font-size:12px;color:var(--muted);background:#f7f9fc;border-left:3px solid var(--gold);
                 padding:12px 16px;border-radius:6px;margin:18px 0;line-height:1.55}
  .vt-lockout{text-align:center;padding:40px 30px}
  .vt-lockout h2{font-size:24px;color:var(--navy);margin:0 0 8px}
  .vt-lockout p{color:var(--muted);margin:0 0 20px;font-size:15px}
  @media (max-width:640px){
    .vt-grid{grid-template-columns:1fr}
    .vt-hero h1{font-size:28px}
    .vt-result-hero .value{font-size:30px}
  }
</style>
</head>
<body>
<div class="vt-nav">
  <div class="vt-wrap" style="display:flex;justify-content:space-between;align-items:center;width:100%;padding:18px 22px">
    <a href="<?= e(url('index.php')) ?>" class="vt-logo" style="text-decoration:none"><?= e(APP_NAME) ?><span>OS</span></a>
    <div style="display:flex;gap:12px;align-items:center">
      <a href="<?= e(url('index.php')) ?>" style="font-size:14px;color:var(--ink);text-decoration:none;font-weight:500">← Back to home</a>
      <?php if ($owner): ?>
        <a class="vt-btn ghost" style="padding:8px 16px;font-size:13px" href="<?= e(url('owner-portal.php')) ?>">My dashboard</a>
        <span style="color:var(--muted);font-size:12.5px"><?= e($owner['name']) ?></span>
      <?php else: ?>
        <a class="vt-btn ghost" style="padding:8px 16px;font-size:13px" href="<?= e(url('owner-login.php')) ?>">Sign in</a>
        <a class="vt-btn primary" style="padding:8px 16px;font-size:13px" href="<?= e(url('owner-signup.php')) ?>">Create account</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="vt-wrap">

<header class="vt-hero">
  <h1>What's your business worth?</h1>
  <p>A free, indicative valuation using the same three-method engine we
     use inside the platform — Net Asset Value, EBITDA multiple, and
     a discounted cash flow. Takes about a minute.</p>
  <?php if ($owner): ?>
    <span class="vt-quota">✓ Unlimited saves · pinned to
      <strong><?= e($owner['name']) ?>'s</strong> dashboard</span>
  <?php else: ?>
    <span class="vt-quota">
      <strong><?= max(0, VTRY_MAX_PER_VISITOR - $used) ?></strong>
      of <?= VTRY_MAX_PER_VISITOR ?> free attempts remaining ·
      <a href="<?= e(url('owner-signup.php')) ?>" style="color:var(--navy);font-weight:600">Create a free account</a>
      for unlimited
    </span>
  <?php endif; ?>
</header>

<?php if ($locked && !$result): ?>
  <div class="vt-card vt-lockout">
    <h2>You've used your free attempts</h2>
    <p>Sign up free to run unlimited valuations, save scenarios, and get
       the full report with normalised EBITDA, multi-year DCF and AI
       commentary.</p>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <a class="vt-btn primary" href="<?= e(url('signup.php')) ?>">Sign up free</a>
      <a class="vt-btn ghost" href="<?= e(url('index.php')) ?>">See plans &amp; pricing</a>
    </div>
  </div>
<?php else: ?>

<?php if ($errors): ?>
  <div class="vt-errs">
    <strong>Please fix:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="vt-card">
  <?= csrf_field() ?>
  <div class="vt-grid">

    <div class="vt-row full">
      <label for="industry">Industry</label>
      <select id="industry" name="industry" required>
        <option value="">Pick the closest match…</option>
        <?php foreach (valuation_industries() as $ind): ?>
          <option value="<?= e($ind['code']) ?>"<?= $inputs['industry'] === $ind['code'] ? ' selected' : '' ?>>
            <?= e($ind['label']) ?> — typically <?= e($ind['ebitda_mid']) ?>× EBITDA
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint">The industry's typical EBITDA multiple is applied automatically.</span>
    </div>

    <div class="vt-row">
      <label for="revenue">Annual revenue</label>
      <div class="vt-prefix">
        <input id="revenue" name="revenue" type="number" min="0" step="1000" required
               value="<?= e($inputs['revenue']) ?>" placeholder="e.g. 2,400,000">
      </div>
    </div>

    <div class="vt-row">
      <label for="ebitda">EBITDA (annual)</label>
      <div class="vt-prefix">
        <input id="ebitda" name="ebitda" type="number" step="1000" required
               value="<?= e($inputs['ebitda']) ?>" placeholder="e.g. 420,000">
      </div>
      <span class="hint">Profit before interest, tax, depreciation &amp; amortisation.</span>
    </div>

    <div class="vt-row">
      <label for="total_assets">Total assets</label>
      <div class="vt-prefix">
        <input id="total_assets" name="total_assets" type="number" min="0" step="1000" required
               value="<?= e($inputs['total_assets']) ?>" placeholder="e.g. 1,800,000">
      </div>
    </div>

    <div class="vt-row">
      <label for="total_liab">Total liabilities</label>
      <div class="vt-prefix">
        <input id="total_liab" name="total_liab" type="number" min="0" step="1000" required
               value="<?= e($inputs['total_liab']) ?>" placeholder="e.g. 900,000">
      </div>
    </div>

    <div class="vt-row">
      <label for="net_debt">Net debt</label>
      <div class="vt-prefix">
        <input id="net_debt" name="net_debt" type="number" min="0" step="1000"
               value="<?= e($inputs['net_debt']) ?>" placeholder="e.g. 500,000">
      </div>
      <span class="hint">Bank + shareholder loans, less cash. Used in the EBITDA &amp; DCF methods.</span>
    </div>

    <div class="vt-row">
      <label for="ownership_pct">Your ownership</label>
      <div class="vt-suffix">
        <input id="ownership_pct" name="ownership_pct" type="number" min="1" max="100" step="0.1" required
               value="<?= e($inputs['ownership_pct']) ?>">
      </div>
      <span class="hint">Stakes under 50% get a minority-discount adjustment.</span>
    </div>

    <div class="vt-row full">
      <label for="risk_band">Key-person risk</label>
      <select id="risk_band" name="risk_band">
        <?php foreach ($riskLabels as $k => $lab): ?>
          <option value="<?= $k ?>"<?= (int) $inputs['risk_band'] === $k ? ' selected' : '' ?>>
            <?= e($lab) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Drives the 5–35% haircut for owner dependence and marketability.</span>
    </div>

  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:22px;gap:12px;flex-wrap:wrap">
    <button type="submit" class="vt-btn primary">Calculate my valuation</button>
    <span style="font-size:12.5px;color:var(--muted)">
      No signup. No data stored. <strong>Indicative only.</strong>
    </span>
  </div>
</form>

<?php if ($result): $v = $result['val']; ?>
  <div class="vt-card" id="result">
    <div class="vt-result-hero">
      <div class="label">Indicative equity value (your stake)</div>
      <div class="value">RM <?= money($v['client_stake']) ?></div>
      <div class="range">
        Whole-company range: RM <?= money($v['low']) ?> &nbsp;–&nbsp; RM <?= money($v['high']) ?>
        &nbsp;·&nbsp; mid RM <?= money($v['mid']) ?>
      </div>
    </div>

    <h3 style="margin:0 0 4px;color:var(--navy);font-size:17px">How we got there</h3>
    <p class="hint" style="margin:0 0 12px;color:var(--muted);font-size:13px">
      Three transparent methods, weighted 20% / 50% / 30%, then haircut for
      key-person risk and marketability.
    </p>
    <table class="vt-table">
      <thead><tr><th>Method</th><th class="tnum">Equity value (RM)</th><th class="tnum">Weight</th></tr></thead>
      <tbody>
        <tr><td>Net Asset Value (assets − liabilities)</td>
          <td class="tnum"><?= money($v['nav']) ?></td>
          <td class="tnum">20%</td></tr>
        <tr><td>EBITDA × <?= e($result['multiple']) ?>×
          <?php if ($result['industry']): ?>
            <span class="hint" style="font-size:11.5px;color:var(--muted)">
              (<?= e($result['industry']['label']) ?> band)</span>
          <?php endif; ?>
          </td>
          <td class="tnum"><?= money($v['ebitda_equity']) ?></td>
          <td class="tnum">50%</td></tr>
        <tr><td>Capitalised DCF (Gordon growth on EBITDA)</td>
          <td class="tnum"><?= money($v['dcf_equity']) ?></td>
          <td class="tnum">30%</td></tr>
        <tr><td>Weighted equity (pre-discount)</td>
          <td class="tnum"><?= money($v['weighted_pre_discount']) ?></td>
          <td class="tnum"></td></tr>
        <tr><td>Less: combined discount
          (<?= (int) round($v['discount_keyperson'] * 100) ?>% key-person ×
          <?= (int) round($v['discount_dlom'] * 100) ?>% DLOM
          <?php if ($v['discount_minority'] > 0): ?>
            × <?= (int) round($v['discount_minority'] * 100) ?>% minority
          <?php endif; ?>)</td>
          <td class="tnum">−<?= (int) round($v['discount'] * 100) ?>%</td>
          <td class="tnum"></td></tr>
        <tr class="total"><td>Indicative mid (whole company)</td>
          <td class="tnum">RM <?= money($v['mid']) ?></td>
          <td class="tnum"></td></tr>
        <tr class="total"><td>Your stake (× <?= rtrim(rtrim(number_format($result['ownership'], 2), '0'), '.') ?>%)</td>
          <td class="tnum">RM <?= money($v['client_stake']) ?></td>
          <td class="tnum"></td></tr>
      </tbody>
    </table>

    <div class="vt-disclaimer">
      <strong>Indicative estimate for discussion only.</strong> This is not a
      formal valuation, audit, fairness opinion or transaction document.
      The figure is sensitive to the industry multiple, the discount rate
      and the marketability assumption. For any binding purpose, engage a
      licensed valuer.
    </div>

    <div class="vt-cta">
      <h3>Want the full report?</h3>
      <p>Sign up free to add normalised EBITDA adjustments, a multi-year
         explicit DCF, AI commentary tailored to your firm's house style,
         and a polished PDF you can share with the owner.</p>
      <a class="vt-btn primary" href="<?= e(url('signup.php')) ?>">Sign up free</a>
    </div>
  </div>

  <?php if ($result): ?>
    <script>document.getElementById('result').scrollIntoView({behavior:'smooth',block:'start'});</script>
  <?php endif; ?>
<?php endif; ?>

<?php endif; // !locked-no-result ?>

<footer style="text-align:center;padding:30px 0;color:var(--muted);font-size:12.5px">
  © <?= date('Y') ?> <?= e(APP_NAME) ?>OS · Built for Malaysian SMEs ·
  <a href="<?= e(url('index.php')) ?>" style="color:var(--muted)">Home</a> ·
  <a href="<?= e(url('signup.php')) ?>" style="color:var(--muted)">Sign up</a>
</footer>

</div>
</body>
</html>
