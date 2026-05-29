<?php
/**
 * AdvisorOS — Tax engine regression tests (CLI).
 *
 * Lightweight: no framework, no Composer. Loads the engine directly
 * and uses the built-in YA defaults (no DB required). Run:
 *
 *     php tests/tax.php
 *
 * Exits with code 1 on any failure so it can gate a release.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);

// Helpers the engine uses at render-time (planner formats with money()).
if (!function_exists('money')) {
    function money($n): string { return number_format((float) $n, 2); }
}
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
}

require_once $ROOT . '/includes/tax_compute.php';
require_once $ROOT . '/includes/tax_planb.php';

$PASS = 0; $FAIL = 0; $FAILED = [];

function _ok(string $label, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($cond) {
        $PASS++;
        echo "  \u{2714} {$label}\n";
        return;
    }
    $FAIL++;
    $FAILED[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "  \u{2718} {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function _eq(string $label, $expected, $actual, float $tol = 0.5): void
{
    $cond = is_numeric($expected) && is_numeric($actual)
        ? abs((float) $expected - (float) $actual) <= $tol
        : $expected === $actual;
    _ok($label, $cond, $cond ? '' : 'expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true));
}

function _section(string $h): void { echo "\n— {$h} —\n"; }

/** Helper to locate a relief row by key. */
$find = static function (array $rows, string $key): ?array {
    foreach ($rows as $row) {
        if (($row['key'] ?? '') === $key) { return $row; }
    }
    return null;
};

// ============================================================
// 1. Resident graduated schedule
// ============================================================
_section('Resident graduated schedule (YA defaults)');
_eq('tax on RM 35,000',          600, my_tax_on(35000));
_eq('tax on RM 100,000',        9400, my_tax_on(100000));
_eq('tax on RM 147,000',       21150, my_tax_on(147000));
_eq('tax on RM 2,000,000',    528400, my_tax_on(2000000));
_eq('marginal at RM 147,000',   0.25, my_marginal_rate(147000), 1e-6);
_eq('rebate at RM 30,000',       400, my_rebate(30000));
_eq('rebate at RM 40,000 (above threshold)', 0, my_rebate(40000));

// ============================================================
// 2. Mr Tan baseline (corrected case study)
// ============================================================
_section('Mr Tan baseline');
$mrTan = [
    'employment' => [
        ['label' => 'Salary',                  'amount' => 300000, 'exempt' => 0],
        ['label' => 'Car allowance',           'amount' => 24000,  'exempt' => 0],
        ['label' => 'Golf club membership',    'amount' => 18000,  'exempt' => 0],
        ['label' => 'Overseas leave passage',  'amount' => 16000,  'exempt' => 3000],
    ],
    'businesses' => [
        ['name' => 'Consultancy', 'gross' => 50000,  'expenses' => 0,     'ca' => 0],
        ['name' => 'Laundry',     'gross' => 150000, 'expenses' => 90000, 'ca' => 10000],
    ],
    'rental' => [
        ['name' => 'Airbnb',         'gross' => 30000, 'expenses' => 12000, 'type' => '4a'],
        ['name' => 'Long-term',      'gross' => 24000, 'expenses' => 10000, 'type' => '4d'],
    ],
    'other' => [], 'donations' => 0, 'zakat' => 0,
    'reliefs' => [
        'children_u18' => 2, 'disabled_u18' => 1,
        'r_epf' => 33000, 'r_life' => 8000, 'r_medins' => 4000,
        'r_parents_medical' => 12000, 'r_sspn' => 10000,
        'r_spouse' => 36000, 'r_childcare' => 3000,
    ],
];
$r = tax_compute($mrTan);
_eq('Mr Tan employment SI',    355000, $r['emp_si']);
_eq('Mr Tan business SI',      100000, $r['biz_si']);
_eq('Mr Tan rental active',     18000, $r['rent_active']);
_eq('Mr Tan rental passive',    14000, $r['rent_passive']);
_eq('Mr Tan aggregate',        487000, $r['aggregate']);
_eq('Mr Tan total reliefs',     55000, $r['relief_before']);
_eq('Mr Tan chargeable',       432000, $r['chargeable']);
_eq('Mr Tan tax payable',       92720, $r['tax_payable']);

// ============================================================
// 3. Relief caps
// ============================================================
_section('Relief caps');
$capCase = [
    'employment' => [['label' => 'Salary', 'amount' => 200000, 'exempt' => 0]],
    'businesses' => [], 'rental' => [], 'other' => [], 'donations' => 0, 'zakat' => 0,
    'reliefs' => [
        'r_epf' => 50000, 'r_life' => 50000, 'r_medins' => 50000,
        'r_parents_medical' => 50000, 'r_sspn' => 50000, 'r_childcare' => 50000,
    ],
];
$cap = tax_compute($capCase);
_eq('EPF capped to RM 4,000',                   4000, $find($cap['relief_rows'], 'epf')['claimed']);
_eq('Life/takaful capped to RM 3,000',          3000, $find($cap['relief_rows'], 'life')['claimed']);
_eq('Education/medical insurance capped to RM 4,000 (YA2024)',
                                                4000, $find($cap['relief_rows'], 'medins')['claimed']);
_eq("Parents' medical capped to RM 8,000",      8000, $find($cap['relief_rows'], 'parents_medical')['claimed']);
_eq('SSPN capped to RM 8,000',                  8000, $find($cap['relief_rows'], 'sspn')['claimed']);
_eq('Childcare capped to RM 3,000',             3000, $find($cap['relief_rows'], 'childcare')['claimed']);

// ============================================================
// 4. Donations capped at 10% of aggregate
// ============================================================
_section('Donations — 10% of aggregate cap');
$donCase = $capCase;
$donCase['donations'] = 999999;
$don = tax_compute($donCase);
_eq('Donations capped at 10% of aggregate',
    round($don['aggregate'] * 0.10, 2),
    round($don['donations'], 2),
    0.01);

// ============================================================
// 5. Zakat as a rebate (s.6A(3))
// ============================================================
_section('Zakat as a tax rebate (s.6A(3))');
$baseSalary = ['employment' => [['label' => 'Salary', 'amount' => 59000, 'exempt' => 0]],
               'businesses' => [], 'rental' => [], 'other' => [],
               'donations' => 0, 'reliefs' => []];
$z1 = tax_compute($baseSalary + ['zakat' => 1000]);
_eq('Chargeable RM 50,000 → gross tax RM 1,500', 1500, $z1['gross_tax']);
_eq('Zakat RM 1,000 applied in full',            1000, $z1['zakat_applied']);
_eq('Tax payable = gross − zakat = RM 500',       500, $z1['tax_payable']);

$z2 = tax_compute($baseSalary + ['zakat' => 5000]);
_eq('Zakat capped at tax otherwise payable',     1500, $z2['zakat_applied']);
_eq('Tax payable floored at RM 0',                  0, $z2['tax_payable']);

// ============================================================
// 6. Joint vs separate assessment
// ============================================================
_section('Joint vs separate assessment');
$jvs = [
    'employment' => [['label' => 'Salary', 'amount' => 100000, 'exempt' => 0]],
    'businesses' => [], 'rental' => [], 'other' => [], 'donations' => 0, 'zakat' => 0,
    'assessment_type' => 'separate', 'spouse_income' => 30000, 'reliefs' => [],
];
$jSep = tax_compute($jvs);
_ok('Spouse RM 30k → separate is recommended',
    $jSep['assessment']['recommended'] === 'separate');
_ok('Comparison shows positive saving',
    $jSep['assessment']['saving'] > 0);

$jvs['assessment_type'] = 'joint';
$jJoint = tax_compute($jvs);
_eq('Headline matches elected scenario (joint figure)',
    $jSep['assessment']['joint_tax'], $jJoint['tax_payable']);

// ============================================================
// 7. Current-year business loss set-off (s.44(2))
// ============================================================
_section('Business loss set-off (s.44(2))');
$loss = [
    'employment' => [['label' => 'Salary', 'amount' => 100000]],
    'businesses' => [['name' => 'Trader', 'gross' => 50000, 'expenses' => 80000, 'ca' => 0]],
    'rental' => [], 'other' => [], 'donations' => 0, 'zakat' => 0, 'reliefs' => [],
];
$ls = tax_compute($loss);
_eq('Current-year business loss surfaced', 30000, $ls['biz_loss']);
_eq('Aggregate = employment − loss',       70000, $ls['aggregate']);

// ============================================================
// 8. Active (s.4(a)) vs passive (s.4(d)) rental
// ============================================================
_section('Rental classification (s.4(a) vs s.4(d))');
$rt = [
    'employment' => [['label' => 'Salary', 'amount' => 100000]], 'businesses' => [],
    'rental' => [
        ['name' => 'Airbnb',    'gross' => 30000, 'expenses' => 10000, 'type' => '4a'],
        ['name' => 'LongTerm',  'gross' => 24000, 'expenses' => 10000, 'type' => '4d'],
    ],
    'other' => [], 'donations' => 0, 'zakat' => 0, 'reliefs' => [],
];
$rrr = tax_compute($rt);
_eq('Active rental aggregated separately',  20000, $rrr['rent_active']);
_eq('Passive rental aggregated separately', 14000, $rrr['rent_passive']);

// ============================================================
// 9. Disabled child base relief (YA2024 RM 8,000)
// ============================================================
_section('Disabled child relief');
$dc = [
    'employment' => [['label' => 'Salary', 'amount' => 100000]],
    'businesses' => [], 'rental' => [], 'other' => [],
    'donations' => 0, 'zakat' => 0,
    'reliefs' => ['children_u18' => 0, 'disabled_u18' => 1],
];
$d = tax_compute($dc);
_eq('Disabled child base RM 8,000 (YA2024 default)', 8000, $d['child_relief']);

// ============================================================
// 10. Part B builder + AI section parser/merger
// ============================================================
_section('Part B builder + AI section parser / merger');
$secs = tax_planb_build($r, []);
_eq('§9 Employment table row count = emp_rows', 4, count($secs['employment']['rows']));
_ok('§11 Rental section present (Airbnb in data)',  isset($secs['rental']));
_ok('§10 Business section present (biz rows)',      isset($secs['business']));
_ok('§13 Family tips include disabled dependant',
    in_array(true, array_map(
        fn ($t) => stripos((string) $t, 'disabled') !== false,
        $secs['family']['items']
    ), true));

$ai = "## SECTION: employment\nFocus on reimbursements.\n\n## SECTION: relief\nPRS top-up.";
$parsed = tax_planb_parse_ai($ai);
_ok('AI parser extracted employment block',  isset($parsed['employment']));
_ok('AI parser extracted relief block',      isset($parsed['relief']));
_eq('AI parser content (relief)', 'PRS top-up.', $parsed['relief']);

$merged = tax_planb_merge_section($ai, 'employment', 'NEW emp commentary.');
$re = tax_planb_parse_ai($merged);
_eq('Merge replaced employment only', 'NEW emp commentary.', $re['employment']);
_eq('Merge preserved relief',         'PRS top-up.',        $re['relief']);

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
