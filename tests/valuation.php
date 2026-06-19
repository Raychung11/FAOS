<?php
/**
 * AdvisorOS — Valuation engine regression tests (CLI).
 *
 * Zero-dependency runner — no framework, no DB. Loads the engine
 * directly and uses the built-in industry library defaults. Run:
 *
 *     php tests/valuation.php
 *
 * Exits with code 1 on any failure.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);

// Render-time helpers the engine touches.
if (!function_exists('money')) {
    function money($n): string { return number_format((float) $n, 2); }
}
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
}

// Suppress the risk_engine / business deps by stubbing the small surface
// company_valuation actually uses — we are unit-testing the math here.
// company_valuation reads $bf directly and $company['ownership_pct']; no DB.
require_once $ROOT . '/includes/valuation.php';

$PASS = 0; $FAIL = 0; $FAILED = [];

function _ok(string $label, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($cond) { $PASS++; echo "  \u{2714} {$label}\n"; return; }
    $FAIL++;
    $FAILED[] = $label . ($detail ? " — {$detail}" : '');
    echo "  \u{2718} {$label}" . ($detail ? " — {$detail}" : '') . "\n";
}

function _eq(string $label, $exp, $act, float $tol = 0.5): void
{
    $cond = is_numeric($exp) && is_numeric($act)
        ? abs((float) $exp - (float) $act) <= $tol
        : $exp === $act;
    _ok($label, $cond, $cond ? '' : 'expected ' . var_export($exp, true)
        . ', got ' . var_export($act, true));
}

function _section(string $h): void { echo "\n— {$h} —\n"; }

// Helpers
$mkCompany = static fn (float $ownership = 70.0, string $industry = '')
    => ['id' => 1, 'ownership_pct' => $ownership, 'industry' => $industry, 'name' => 'Test'];

$mkBf = static fn (array $over = []) => array_merge([
    'total_assets'      => 2800000,
    'total_liabilities' => 1500000,
    'ebitda'            => 560000,
    'net_profit'        => 410000,
    'bank_loans'        => 900000,
    'shareholder_loans' => 250000,
    'cash'              => 310000,
], $over);

$defAssu = [
    'multiple' => 4.0, 'discount' => 18.0, 'growth' => 3.0,
    'weight_nav' => 20, 'weight_ebitda' => 50, 'weight_dcf' => 30,
];

// ============================================================
// 1. NAV and net debt
// ============================================================
_section('Net asset value & net debt');
$v = company_valuation($mkCompany(), $mkBf(), $defAssu, 2);
_eq('NAV = assets − liabilities', 1300000, $v['nav']);
_eq('Net debt = max(0, bank + shloans − cash)', 840000, $v['net_debt']);

$v2 = company_valuation($mkCompany(), $mkBf(['cash' => 2000000]), $defAssu, 2);
_eq('Net debt floored at 0 when cash exceeds debt', 0, $v2['net_debt']);

// ============================================================
// 2. EBITDA-multiple equity
// ============================================================
_section('EBITDA-multiple equity');
_eq('EBITDA EV = ebitda × multiple',           2240000, $v['ebitda_ev']);
_eq('EBITDA equity = EV − net debt',           1400000, $v['ebitda_equity']);
$vNeg = company_valuation($mkCompany(), $mkBf(['ebitda' => -50000]), $defAssu, 2);
_eq('EBITDA equity floored at 0 when EBITDA ≤ 0', 0, $vNeg['ebitda_equity']);

// ============================================================
// 3. Capitalised DCF
// ============================================================
_section('Capitalised DCF (Gordon growth)');
// base = 560,000; g = 0.03; d = 0.18 → 560,000 × 1.03 / 0.15 = 3,845,333; − net_debt 840,000 = 3,005,333
_eq('DCF equity', 3005333, $v['dcf_equity']);
// Guard: discount ≤ growth → engine bumps d to g + 0.05
$vGuard = company_valuation($mkCompany(), $mkBf(),
    array_merge($defAssu, ['discount' => 3.0, 'growth' => 5.0]), 2);
// d = 0.05 + 0.05 = 0.10; base 560k × 1.05 / 0.05 = 11,760,000; − 840k = 10,920,000
_eq('DCF when discount ≤ growth uses guarded d = g + 0.05', 10920000, $vGuard['dcf_equity']);

$vFallback = company_valuation($mkCompany(),
    $mkBf(['ebitda' => 0, 'net_profit' => 0]), $defAssu, 2);
_eq('DCF falls back to NAV when no earnings base', 1300000, $vFallback['dcf_equity']);

// ============================================================
// 4. Risk-derived discount
// ============================================================
_section('Risk-derived key-person discount');
_eq('Low risk (0) → 5%',      0.05, valuation_risk_discount(0), 1e-9);
_eq('Medium risk (1) → 15%',  0.15, valuation_risk_discount(1), 1e-9);
_eq('High risk (2) → 25%',    0.25, valuation_risk_discount(2), 1e-9);
_eq('Critical risk (3) → 35%',0.35, valuation_risk_discount(3), 1e-9);
_eq('Unknown risk (null) → default 15%', 0.15, valuation_risk_discount(null), 1e-9);

// ============================================================
// 5. Weighted blend
// ============================================================
_section('Weighted method blend');
// methods: nav 1,300,000 · ebitda 1,400,000 · dcf 3,005,333
// default weights 20/50/30: (1,300,000×20 + 1,400,000×50 + 3,005,333×30) / 100
//   = (26,000,000 + 70,000,000 + 90,160,000) / 100 = 1,861,600
// then ×(1-discount 0.25) = 1,396,200
_eq('Weighted pre-discount default',  1861600, $v['weighted_pre_discount']);
_eq('Weighted mid (after 25% discount)', 1396200, $v['mid']);

// Custom weights — 100% EBITDA should give exactly ebitda_equity × (1-discount)
$vEbitdaOnly = company_valuation($mkCompany(), $mkBf(),
    array_merge($defAssu, ['weight_nav' => 0, 'weight_ebitda' => 100, 'weight_dcf' => 0]), 2);
_eq('Weight 100% EBITDA → mid = EBITDA equity × (1-disc)',
    1400000 * 0.75, $vEbitdaOnly['mid']);

// Low/high bounds remain min/max of methods × (1-disc), regardless of weights
_eq('Low bound = min(method) × (1-disc)',  975000,  $v['low']);
_eq('High bound = max(method) × (1-disc)', 2254000, $v['high']);

// Client stake = weighted mid × ownership
_eq('Client stake = weighted mid × ownership', round(1396200 * 0.70), round($v['client_stake']));

// ============================================================
// 6. Industry multiple lookup
// ============================================================
_section('Industry multiple lookup');
$serv = valuation_industry_lookup('Professional services');
_ok('Exact label match (Professional services)', $serv !== null && $serv['code'] === 'services');
_eq('Professional services mid = 5×', 5.0, (float) ($serv['ebitda_mid'] ?? 0));

$tech = valuation_industry_lookup('SaaS platform');
_ok('Fuzzy keyword match (SaaS → tech)', $tech !== null && $tech['code'] === 'tech');

$logi = valuation_industry_lookup('Laundry & cleaning');
_ok('Fuzzy keyword match (laundry → logistics)', $logi !== null && $logi['code'] === 'logistics');

$other = valuation_industry_lookup('Unicorn breeding cooperative');
_ok('Unrecognised industry falls back to "other"',
    $other !== null && $other['code'] === 'other');

_ok('Empty industry returns null', valuation_industry_lookup('') === null);

// ============================================================
// 7. Sensitivity matrix
// ============================================================
_section('Sensitivity matrix');
$sens = valuation_sensitivity($mkCompany(), $mkBf(), $defAssu, 2);
_eq('5×5 grid by default · rows', 5, count($sens['multipliers']));
_eq('5×5 grid by default · cols', 5, count($sens['discounts']));
_eq('Base multiple in centre',   4.0, $sens['multipliers'][2], 1e-9);
_eq('Base discount in centre',  18.0, $sens['discounts'][2],   1e-9);
// Centre cell should equal the engine's mid for the same assumptions
_eq('Centre cell matches company_valuation mid', $v['mid'], $sens['grid'][2][2]);
// Top-left = smaller multiple AND smaller discount → larger ringgit (less haircut, but smaller multiple)
// We at least verify ordering: larger multiple at same discount column should produce >= value
_ok('Mid increases with multiple at fixed discount',
    $sens['grid'][0][2] <= $sens['grid'][4][2]);
_ok('Mid decreases with larger discount at fixed multiple',
    $sens['grid'][2][0] >= $sens['grid'][2][4]);

// ============================================================
// Summary
// ============================================================
echo "\n— Summary —\n";
echo "Passed: {$PASS} · Failed: {$FAIL}\n";
if ($FAIL > 0) {
    echo "\nFailures:\n";
    foreach ($FAILED as $f) { echo "  • {$f}\n"; }
    exit(1);
}
echo "All tests passed.\n";
exit(0);
