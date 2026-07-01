<?php
/**
 * AdvisorOS — Equity Structure engine regression tests (CLI).
 * Zero-dependency runner. Run:  php tests/equity.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);

if (!function_exists('money')) {
    function money($n): string { return number_format((float) $n, 2); }
}
if (!function_exists('e')) {
    function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
}

require_once $ROOT . '/includes/equity_engine.php';

$PASS = 0; $FAIL = 0; $FAILED = [];
function _ok(string $label, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($cond) { $PASS++; echo "  \u{2714} {$label}\n"; return; }
    $FAIL++;
    $FAILED[] = $label . ($detail ? " — {$detail}" : '');
    echo "  \u{2718} {$label}" . ($detail ? " — {$detail}" : '') . "\n";
}
function _eq(string $label, $exp, $act, float $tol = 0.01): void
{
    $cond = is_numeric($exp) && is_numeric($act)
        ? abs((float) $exp - (float) $act) <= $tol
        : $exp === $act;
    _ok($label, $cond, $cond ? '' : 'expected ' . var_export($exp, true)
        . ', got ' . var_export($act, true));
}
function _section(string $h): void { echo "\n— {$h} —\n"; }

// ============================================================
// Framework shape
// ============================================================
_section('Framework shape');
_eq('50 indicators total', 50, count(equity_indicators()));
_eq('6 categories',        6,  count(equity_categories()));
_eq('Weights sum to 100',  100, array_sum(equity_category_weights()));
_eq('DD checklist has 13 items', 13, count(equity_dd_checklist()));
_eq('10 red-flag scenarios',     10, count(equity_red_flags()));

// Each category has expected item counts (A=10, B=10, C=10, D=10, E=5, F=5)
$byCat = [];
foreach (equity_indicators() as $i) { $byCat[$i['cat']] = ($byCat[$i['cat']] ?? 0) + 1; }
_eq('Category A has 10 items', 10, $byCat['A']);
_eq('Category B has 10 items', 10, $byCat['B']);
_eq('Category C has 10 items', 10, $byCat['C']);
_eq('Category D has 10 items', 10, $byCat['D']);
_eq('Category E has 5 items',   5, $byCat['E']);
_eq('Category F has 5 items',   5, $byCat['F']);

// ============================================================
// Empty state
// ============================================================
_section('Empty assessment');
$empty = equity_empty_assessment();
_eq('Empty state has 50 score slots', 50, count($empty['scores']));
$score = equity_score($empty);
_eq('Empty score total = 0',  0.0, $score['total'], 0.01);
_eq('Empty band = Not started', 'Not started', $score['band']);
_eq('Empty band code = unknown', 'unknown', $score['band_code']);
_eq('Empty answered = 0', 0, $score['answered']);
_eq('Empty red flags = 0', 0, count($score['red_flags']));

// ============================================================
// Perfect answers → Low Risk
// ============================================================
_section('All-5 assessment → Low Risk');
$perfect = equity_empty_assessment();
foreach ($perfect['scores'] as $id => $_) { $perfect['scores'][$id]['score'] = 5; }
$sPerf = equity_score($perfect);
_eq('All 5s → composite 100',    100.0, $sPerf['total'], 0.01);
_eq('All 5s → Low Risk band',    'Low Risk', $sPerf['band']);
_eq('All 5s → answered = 50',    50, $sPerf['answered']);
_eq('All 5s → 0 red flags',       0, count($sPerf['red_flags']));

// ============================================================
// All-1 answers → Critical Risk + red flags fire
// ============================================================
_section('All-1 assessment → Critical Risk');
$bad = equity_empty_assessment();
foreach ($bad['scores'] as $id => $_) { $bad['scores'][$id]['score'] = 1; }
$sBad = equity_score($bad);
_eq('All 1s → composite 20',  20.0, $sBad['total'], 0.01);
_eq('All 1s → Critical band', 'Critical Risk', $sBad['band']);
_ok('All 1s trigger many red flags (>= 5)', count($sBad['red_flags']) >= 5,
    'got ' . count($sBad['red_flags']));

// ============================================================
// Band boundaries
// ============================================================
_section('Band boundaries');
_eq('80 → Low',       'Low Risk',      equity_band(80.0, 50)[1]);
_eq('79.9 → Moderate','Moderate Risk', equity_band(79.9, 50)[1]);
_eq('65 → Moderate',  'Moderate Risk', equity_band(65.0, 50)[1]);
_eq('64.9 → High',    'High Risk',     equity_band(64.9, 50)[1]);
_eq('50 → High',      'High Risk',     equity_band(50.0, 50)[1]);
_eq('49.9 → Critical','Critical Risk', equity_band(49.9, 50)[1]);
_eq('answered=0 → Not started', 'Not started', equity_band(100.0, 0)[1]);

// ============================================================
// Auto red-flag heuristics
// ============================================================
_section('Auto red-flag detection');

// Proxy with no docs (indicator 6 low + no note)
$rf1 = equity_empty_assessment();
$rf1['scores'][6]['score'] = 1;
$sRf1 = equity_score($rf1);
_ok('Proxy without docs auto-triggers',
    !empty($sRf1['red_flags']['proxy_no_doc']));

// Proxy with "nominee agreement" note → should NOT auto-fire
$rf1b = equity_empty_assessment();
$rf1b['scores'][6]['score'] = 1;
$rf1b['scores'][6]['note']  = 'Signed nominee agreement on file, dated 2024.';
$sRf1b = equity_score($rf1b);
_ok('Proxy with nominee agreement note does NOT auto-fire',
    empty($sRf1b['red_flags']['proxy_no_doc']));

// 50:50 + no deadlock (14 & 36 low)
$rf2 = equity_empty_assessment();
$rf2['scores'][14]['score'] = 2; $rf2['scores'][36]['score'] = 1;
$sRf2 = equity_score($rf2);
_ok('50:50 without deadlock auto-triggers',
    !empty($sRf2['red_flags']['fifty_fifty_no_dead']));

// IP on individual (any of 46/47/48 low)
$rf3 = equity_empty_assessment();
$rf3['scores'][47]['score'] = 1;
$sRf3 = equity_score($rf3);
_ok('IP-on-individual auto-triggers',
    !empty($sRf3['red_flags']['ip_on_individual']));

// No SHA
$rf4 = equity_empty_assessment();
$rf4['scores'][31]['score'] = 0;
$sRf4 = equity_score($rf4);
_ok('No SHA auto-triggers', !empty($sRf4['red_flags']['no_sha']));

// Manual override — advisor ticks it even if score would not trigger
$rf5 = equity_empty_assessment();
$rf5['red_flags']['foreign_nominee'] = 1;
$sRf5 = equity_score($rf5);
_ok('Manual red flag surfaces even without score triggers',
    !empty($sRf5['red_flags']['foreign_nominee']));
_eq('Manual red flag marked as source=manual',
    'manual', $sRf5['red_flags']['foreign_nominee']['source']);

// ============================================================
// Category weight rollup — verify weighted average is correct
// ============================================================
_section('Category weighted rollup');
// Answer only category A, all 4s → cat A pct = 80%, but total should
// weight ONLY the answered category (A=20% weight)
$partial = equity_empty_assessment();
foreach ([1,2,3,4,5,6,7,8,9,10] as $id) { $partial['scores'][$id]['score'] = 4; }
$sPart = equity_score($partial);
_eq('Only A answered → cat A pct = 80', 80.0, $sPart['by_cat']['A']['pct'], 0.01);
_eq('Only A answered → composite = 80 (weighted by answered categories)',
    80.0, $sPart['total'], 0.01);
_eq('Only A answered → answered count = 10', 10, $sPart['answered']);

// Answer A(all 5s) and B(all 3s) — expect (100*20 + 60*20)/40 = 80
$twoCat = equity_empty_assessment();
foreach ([1,2,3,4,5,6,7,8,9,10] as $id)         { $twoCat['scores'][$id]['score'] = 5; }
foreach ([11,12,13,14,15,16,17,18,19,20] as $id){ $twoCat['scores'][$id]['score'] = 3; }
$sTwoCat = equity_score($twoCat);
_eq('A=5s + B=3s → composite = 80', 80.0, $sTwoCat['total'], 0.01);

// ============================================================
// Load / save round-trip (in-memory, no DB)
// ============================================================
_section('Data shape round-trip');
$fake = ['scores' => [1 => ['score' => 4, 'note' => 'clean'],
                       50 => ['score' => 2, 'note' => 'todo']],
         'dd' => ['sha' => 1, 'ip' => 1],
         'red_flags' => ['no_sha' => 1],
         'archetype' => 'founder_investor'];
// Simulate what equity_save would sanitise (without hitting the DB):
$mockSave = equity_empty_assessment();
foreach ($fake['scores'] as $id => $row) {
    if (!isset($mockSave['scores'][$id])) { continue; }
    $mockSave['scores'][$id] = ['score' => (int) $row['score'], 'note' => (string) $row['note']];
}
foreach ($fake['dd'] as $k => $v) {
    if (array_key_exists($k, $mockSave['dd'])) { $mockSave['dd'][$k] = 1; }
}
foreach ($fake['red_flags'] as $k => $v) {
    if (array_key_exists($k, $mockSave['red_flags'])) { $mockSave['red_flags'][$k] = 1; }
}
$mockSave['archetype'] = $fake['archetype'];

_eq('Indicator 1 preserved',   4, $mockSave['scores'][1]['score']);
_eq('Indicator 1 note preserved', 'clean', $mockSave['scores'][1]['note']);
_eq('Indicator 50 preserved',  2, $mockSave['scores'][50]['score']);
_eq('Archetype preserved', 'founder_investor', $mockSave['archetype']);
_eq('DD "sha" ticked', 1, $mockSave['dd']['sha']);
_eq('Manual red flag preserved', 1, $mockSave['red_flags']['no_sha']);

// ============================================================
$total = $PASS + $FAIL;
echo "\n— Summary —\n";
echo "Passed: {$PASS} · Failed: {$FAIL}\n";
if ($FAIL > 0) {
    echo "\nFailures:\n";
    foreach ($FAILED as $f) { echo "  • {$f}\n"; }
    exit(1);
}
echo "All tests passed.\n";
exit(0);
