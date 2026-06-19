<?php
/**
 * AdvisorOS — Business Valuation Engine (Module F).
 *
 * Three transparent methods on the latest business-financial snapshot
 * — Net Asset Value, EBITDA multiple and a simplified capitalised
 * (Gordon-growth) DCF — blended into an indicative equity range, then
 * haircut by a key-person / marketability discount derived from the
 * Enterprise Risk Diagnostic. Per-company assumptions persist in the
 * settings table (key val:{companyId}); no schema change.
 * Indicative estimates for advisory discussion only.
 */

declare(strict_types=1);

require_once __DIR__ . '/business.php';
require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/settings.php';

function valuation_assumptions(int $companyId): array
{
    $d = setting_get_json('val:' . $companyId, []);
    return [
        'multiple'      => isset($d['multiple']) ? (float) $d['multiple'] : 4.0,
        'discount'      => isset($d['discount']) ? (float) $d['discount'] : 18.0,
        'growth'        => isset($d['growth'])   ? (float) $d['growth']   : 3.0,
        'weight_nav'    => isset($d['weight_nav'])    ? (float) $d['weight_nav']    : 20.0,
        'weight_ebitda' => isset($d['weight_ebitda']) ? (float) $d['weight_ebitda'] : 50.0,
        'weight_dcf'    => isset($d['weight_dcf'])    ? (float) $d['weight_dcf']    : 30.0,
    ];
}

function valuation_assumptions_save(int $companyId, array $in): void
{
    $wnav = max(0.0, (float) ($in['weight_nav']    ?? 20));
    $web  = max(0.0, (float) ($in['weight_ebitda'] ?? 50));
    $wdcf = max(0.0, (float) ($in['weight_dcf']    ?? 30));
    if ($wnav + $web + $wdcf <= 0) { $wnav = 20; $web = 50; $wdcf = 30; }

    setting_put_json('val:' . $companyId, [
        'multiple'      => min(20, max(0, (float) ($in['multiple'] ?? 4))),
        'discount'      => min(50, max(1, (float) ($in['discount'] ?? 18))),
        'growth'        => min(15, max(0, (float) ($in['growth'] ?? 3))),
        'weight_nav'    => min(100, $wnav),
        'weight_ebitda' => min(100, $web),
        'weight_dcf'    => min(100, $wdcf),
    ]);
}

/** Key-person / marketability discount from the worst risk score (0–3). */
function valuation_risk_discount(?int $worst): float
{
    return [0 => 0.05, 1 => 0.15, 2 => 0.25, 3 => 0.35][$worst] ?? 0.15;
}

/** Built-in industry EBITDA multiple bands (illustrative; admin-editable). */
function valuation_industries_defaults(): array
{
    return [
        ['code' => 'services',       'label' => 'Professional services',     'ebitda_low' => 4.0, 'ebitda_mid' => 5.0,  'ebitda_high' => 6.0],
        ['code' => 'wholesale',      'label' => 'Wholesale & distribution',  'ebitda_low' => 3.0, 'ebitda_mid' => 4.0,  'ebitda_high' => 5.0],
        ['code' => 'manufacturing',  'label' => 'Manufacturing',             'ebitda_low' => 3.0, 'ebitda_mid' => 4.5,  'ebitda_high' => 6.0],
        ['code' => 'tech',           'label' => 'Technology / SaaS',         'ebitda_low' => 6.0, 'ebitda_mid' => 8.0,  'ebitda_high' => 12.0],
        ['code' => 'fnb',            'label' => 'Food & beverage',           'ebitda_low' => 3.0, 'ebitda_mid' => 4.0,  'ebitda_high' => 5.5],
        ['code' => 'retail',         'label' => 'Retail',                    'ebitda_low' => 3.0, 'ebitda_mid' => 4.0,  'ebitda_high' => 5.0],
        ['code' => 'construction',   'label' => 'Construction',              'ebitda_low' => 2.5, 'ebitda_mid' => 3.5,  'ebitda_high' => 4.5],
        ['code' => 'logistics',      'label' => 'Logistics & transport',     'ebitda_low' => 4.0, 'ebitda_mid' => 5.0,  'ebitda_high' => 6.0],
        ['code' => 'healthcare',     'label' => 'Healthcare',                'ebitda_low' => 5.0, 'ebitda_mid' => 7.0,  'ebitda_high' => 10.0],
        ['code' => 'property',       'label' => 'Property & real estate',    'ebitda_low' => 5.0, 'ebitda_mid' => 7.0,  'ebitda_high' => 10.0],
        ['code' => 'other',          'label' => 'Other / unspecified',       'ebitda_low' => 3.0, 'ebitda_mid' => 4.0,  'ebitda_high' => 6.0],
    ];
}

/** Industry list (platform-editable; falls back to built-in defaults). */
function valuation_industries(): array
{
    $stored = [];
    if (function_exists('platform_setting_get_json') && function_exists('db')) {
        try { $stored = platform_setting_get_json('valuation_multiples', []); }
        catch (Throwable $e) { /* fall through to defaults */ }
    }
    return (is_array($stored) && !empty($stored['industries']))
        ? $stored['industries']
        : valuation_industries_defaults();
}

/** Best-effort fuzzy match from a free-text industry string to a band. */
function valuation_industry_lookup(string $industry): ?array
{
    $industry = trim(mb_strtolower($industry));
    if ($industry === '') { return null; }
    $industries = valuation_industries();

    foreach ($industries as $i) {
        if (mb_strtolower((string) $i['code']) === $industry
            || mb_strtolower((string) $i['label']) === $industry) { return $i; }
    }

    $keywords = [
        'services'      => ['service', 'consult', 'professional', 'advisory', 'agency'],
        'wholesale'     => ['wholesale', 'distribut', 'trading', 'trade'],
        'manufacturing' => ['manufactur', 'factory', 'industr', 'production', 'plant'],
        'tech'          => ['tech', 'software', 'saas', 'digital', 'platform'],
        'fnb'           => ['food', 'beverage', 'restaurant', 'cafe', 'f&b', 'fnb', 'bakery'],
        'retail'        => ['retail', 'shop', 'store', 'e-commerce', 'ecommerce'],
        'construction'  => ['construct', 'contractor', 'build', 'developer'],
        'logistics'     => ['logistic', 'transport', 'shipping', 'courier', 'freight', 'laundry'],
        'healthcare'    => ['health', 'medical', 'clinic', 'pharma', 'dental'],
        'property'      => ['property', 'real estate', 'realty', 'land'],
    ];
    foreach ($keywords as $code => $kws) {
        foreach ($kws as $kw) {
            if (str_contains($industry, $kw)) {
                foreach ($industries as $i) {
                    if (($i['code'] ?? '') === $code) { return $i; }
                }
            }
        }
    }
    foreach ($industries as $i) {
        if (($i['code'] ?? '') === 'other') { return $i; }
    }
    return null;
}

/**
 * Pure valuation of one company. $bf may be null (no data).
 * Weighted blend across NAV / EBITDA-multiple / DCF (advisor-adjustable),
 * with low/high bounds still as min/max of the three methods × (1 - discount).
 * @return array
 */
function company_valuation(array $company, ?array $bf, array $assu, ?int $worstRisk): array
{
    $share = min(1.0, max(0.0, (float) $company['ownership_pct'] / 100));
    $disc  = valuation_risk_discount($worstRisk);

    if (!$bf) {
        return ['has_data' => false, 'share' => $share, 'discount' => $disc,
                'nav' => 0.0, 'ebitda_equity' => 0.0, 'dcf_equity' => 0.0,
                'low' => 0.0, 'mid' => 0.0, 'high' => 0.0, 'client_stake' => 0.0,
                'weights' => ['nav' => 0.0, 'ebitda' => 0.0, 'dcf' => 0.0],
                'weighted_pre_discount' => 0.0];
    }

    $assets  = (float) $bf['total_assets'];
    $liab    = (float) $bf['total_liabilities'];
    $ebitda  = (float) $bf['ebitda'];
    $netP    = (float) $bf['net_profit'];
    $netDebt = max(0.0, (float) $bf['bank_loans'] + (float) $bf['shareholder_loans'] - (float) $bf['cash']);

    $nav = $assets - $liab;

    $ebitdaEv     = max(0.0, $ebitda) * (float) $assu['multiple'];
    $ebitdaEquity = max(0.0, $ebitdaEv - $netDebt);

    $base = $ebitda > 0 ? $ebitda : $netP;
    if ($base <= 0) {
        $dcfEquity = max(0.0, $nav);
    } else {
        $d = (float) $assu['discount'] / 100;
        $g = (float) $assu['growth'] / 100;
        if ($d <= $g) { $d = $g + 0.05; }
        $dcfEquity = max(0.0, $base * (1 + $g) / ($d - $g) - $netDebt);
    }

    $methods = [max(0.0, $nav), $ebitdaEquity, $dcfEquity];
    $weights = [
        (float) ($assu['weight_nav']    ?? 20),
        (float) ($assu['weight_ebitda'] ?? 50),
        (float) ($assu['weight_dcf']    ?? 30),
    ];
    $wsum = max(1e-9, array_sum($weights));
    $weightedPre = ($methods[0] * $weights[0] + $methods[1] * $weights[1] + $methods[2] * $weights[2]) / $wsum;

    $f = 1 - $disc;
    $low  = min($methods) * $f;
    $high = max($methods) * $f;
    $mid  = $weightedPre * $f;

    return ['has_data' => true, 'share' => $share, 'discount' => $disc,
            'nav' => $nav, 'ebitda_ev' => $ebitdaEv, 'ebitda_equity' => $ebitdaEquity,
            'dcf_equity' => $dcfEquity, 'net_debt' => $netDebt,
            'low' => $low, 'mid' => $mid, 'high' => $high,
            'client_stake' => $mid * $share,
            'weights' => ['nav' => $weights[0] / $wsum,
                          'ebitda' => $weights[1] / $wsum,
                          'dcf' => $weights[2] / $wsum],
            'weighted_pre_discount' => $weightedPre];
}

/**
 * What-if matrix: indicative mid-value across multiple × discount sweeps.
 * @return array{multipliers:array,discounts:array,grid:array}
 */
function valuation_sensitivity(array $company, ?array $bf, array $assu, ?int $worstRisk,
    int $steps = 5, float $multStep = 0.5, float $discStep = 2.5): array
{
    $half = (int) floor($steps / 2);
    $baseMult = (float) $assu['multiple'];
    $baseDisc = (float) $assu['discount'];

    $multipliers = $discounts = [];
    for ($i = -$half; $i <= $half; $i++) { $multipliers[] = max(0.5, round($baseMult + $i * $multStep, 2)); }
    for ($i = -$half; $i <= $half; $i++) { $discounts[]   = max(1.0, round($baseDisc + $i * $discStep, 2)); }

    $grid = [];
    foreach ($multipliers as $m) {
        $row = [];
        foreach ($discounts as $d) {
            $a = $assu; $a['multiple'] = $m; $a['discount'] = $d;
            $v = company_valuation($company, $bf, $a, $worstRisk);
            $row[] = $v['mid'];
        }
        $grid[] = $row;
    }
    return ['multipliers' => $multipliers, 'discounts' => $discounts,
            'grid' => $grid, 'base_multiple' => $baseMult, 'base_discount' => $baseDisc];
}

/**
 * Client-level valuation across all companies.
 * @return array{rows:array,total_mid:float,total_stake:float,companies:int,text:string}
 */
function client_valuation(PDO $pdo, ?int $tid, int $clientId): array
{
    $rg    = risk_diagnostic($pdo, $tid, $clientId);
    $worst = $rg['worst'] ?? null;

    $rows = []; $totalMid = 0.0; $totalStake = 0.0;
    foreach (companies_for_client($clientId) as $c) {
        $bf = bf_latest((int) $c['id']);
        $v  = company_valuation($c, $bf, valuation_assumptions((int) $c['id']), $worst);
        $rows[] = ['company' => $c, 'val' => $v];
        $totalMid   += $v['mid'];
        $totalStake += $v['client_stake'];
    }

    $text = $rows
        ? sprintf(
            'BUSINESS VALUATION (indicative estimate): %d compan%s, indicative '
            . 'equity value RM %s; client\'s attributable stake ≈ RM %s '
            . '(NAV / EBITDA-multiple / capitalised-DCF blend, key-person '
            . 'discount applied).',
            count($rows), count($rows) === 1 ? 'y' : 'ies',
            money($totalMid), money($totalStake))
        : 'BUSINESS VALUATION: no companies recorded.';

    return ['rows' => $rows, 'total_mid' => $totalMid, 'total_stake' => $totalStake,
            'companies' => count($rows), 'worst_risk' => $worst, 'text' => $text];
}
