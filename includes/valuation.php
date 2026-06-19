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
        // Slice C — sequential discounts, normalised EBITDA, multi-year DCF.
        'dlom_pct'      => isset($d['dlom_pct'])     ? (float) $d['dlom_pct']     : 25.0,
        'minority_pct'  => isset($d['minority_pct']) ? (float) $d['minority_pct'] : 0.0,
        'dcf_method'    => in_array($d['dcf_method'] ?? '', ['gordon', 'multiyear'], true)
                            ? $d['dcf_method'] : 'gordon',
        'mdcf_years'    => isset($d['mdcf_years'])  ? max(1, (int) $d['mdcf_years']) : 5,
        'mdcf_revenue'  => isset($d['mdcf_revenue']) ? (float) $d['mdcf_revenue'] : 0.0,
        'mdcf_growth'   => isset($d['mdcf_growth'])  ? (float) $d['mdcf_growth']  : 5.0,
        'mdcf_margin'   => isset($d['mdcf_margin'])  ? (float) $d['mdcf_margin']  : 15.0,
        'mdcf_tax_rate' => isset($d['mdcf_tax_rate']) ? (float) $d['mdcf_tax_rate'] : 24.0,
        'mdcf_capex'    => isset($d['mdcf_capex'])    ? (float) $d['mdcf_capex']    : 3.0,
        'mdcf_wc'       => isset($d['mdcf_wc'])       ? (float) $d['mdcf_wc']       : 1.0,
        'mdcf_terminal_growth' => isset($d['mdcf_terminal_growth']) ? (float) $d['mdcf_terminal_growth'] : 3.0,
        'adjustments'   => isset($d['adjustments']) && is_array($d['adjustments']) ? $d['adjustments'] : [],
    ];
}

function valuation_assumptions_save(int $companyId, array $in): void
{
    $wnav = max(0.0, (float) ($in['weight_nav']    ?? 20));
    $web  = max(0.0, (float) ($in['weight_ebitda'] ?? 50));
    $wdcf = max(0.0, (float) ($in['weight_dcf']    ?? 30));
    if ($wnav + $web + $wdcf <= 0) { $wnav = 20; $web = 50; $wdcf = 30; }

    // Normalise / sanitise adjustments list (label + signed amount).
    $adjustments = [];
    foreach ((array) ($in['adjustments'] ?? []) as $a) {
        $label  = trim(mb_substr((string) ($a['label'] ?? ''), 0, 120));
        $amount = (float) ($a['amount'] ?? 0);
        if ($label === '' && abs($amount) < 0.01) { continue; }
        $adjustments[] = ['label' => $label !== '' ? $label : 'Adjustment', 'amount' => $amount];
    }

    setting_put_json('val:' . $companyId, [
        'multiple'      => min(20, max(0, (float) ($in['multiple'] ?? 4))),
        'discount'      => min(50, max(1, (float) ($in['discount'] ?? 18))),
        'growth'        => min(15, max(0, (float) ($in['growth'] ?? 3))),
        'weight_nav'    => min(100, $wnav),
        'weight_ebitda' => min(100, $web),
        'weight_dcf'    => min(100, $wdcf),
        'dlom_pct'      => min(90, max(0, (float) ($in['dlom_pct']     ?? 25))),
        'minority_pct'  => min(90, max(0, (float) ($in['minority_pct'] ?? 0))),
        'dcf_method'    => in_array($in['dcf_method'] ?? '', ['gordon', 'multiyear'], true)
                            ? $in['dcf_method'] : 'gordon',
        'mdcf_years'    => max(1, min(10, (int) ($in['mdcf_years'] ?? 5))),
        'mdcf_revenue'  => max(0.0, (float) ($in['mdcf_revenue']  ?? 0)),
        'mdcf_growth'   => min(50, (float) ($in['mdcf_growth']  ?? 5)),
        'mdcf_margin'   => max(0.0, min(100, (float) ($in['mdcf_margin']  ?? 15))),
        'mdcf_tax_rate' => max(0.0, min(50, (float) ($in['mdcf_tax_rate'] ?? 24))),
        'mdcf_capex'    => max(0.0, min(50, (float) ($in['mdcf_capex']    ?? 3))),
        'mdcf_wc'       => min(50, (float) ($in['mdcf_wc']       ?? 1)),
        'mdcf_terminal_growth' => min(15, max(0, (float) ($in['mdcf_terminal_growth'] ?? 3))),
        'adjustments'   => $adjustments,
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
 * Multi-year explicit DCF: 5-year (default) forecast of EBITDA from
 * a base revenue + growth + margin, less tax + capex + working capital,
 * discounted at the cost of capital + Gordon-growth terminal value.
 * Returns equity value (EV − net debt, floored at 0).
 */
function valuation_multiyear_dcf(array $assu, float $startEbitda, float $netDebt): float
{
    $years  = max(1, (int) ($assu['mdcf_years'] ?? 5));
    $margin = max(0.0001, (float) ($assu['mdcf_margin'] ?? 15) / 100);
    $rev    = (float) ($assu['mdcf_revenue'] ?? 0);
    if ($rev <= 0) {
        // Derive from current EBITDA / forecast margin.
        $rev = $startEbitda > 0 ? $startEbitda / $margin : 0.0;
    }
    if ($rev <= 0) { return 0.0; }

    $g    = (float) ($assu['mdcf_growth']   ?? 5) / 100;
    $tax  = max(0.0, (float) ($assu['mdcf_tax_rate'] ?? 24) / 100);
    $cap  = max(0.0, (float) ($assu['mdcf_capex']    ?? 3) / 100);
    $wcp  = (float) ($assu['mdcf_wc']       ?? 1) / 100;
    $d    = (float) ($assu['discount']      ?? 18) / 100;
    $tg   = (float) ($assu['mdcf_terminal_growth'] ?? 3) / 100;
    if ($d <= $tg) { $d = $tg + 0.05; }

    $sumPv  = 0.0;
    $prev   = $rev;
    $lastFcf = 0.0;
    for ($t = 1; $t <= $years; $t++) {
        $thisRev = $prev * (1 + $g);
        $ebitda  = $thisRev * $margin;
        $deltaRev = $thisRev - $prev;
        $fcf = $ebitda - ($ebitda * $tax) - ($thisRev * $cap) - ($deltaRev * $wcp);
        $sumPv += $fcf / pow(1 + $d, $t);
        $lastFcf = $fcf;
        $prev = $thisRev;
    }
    $terminal   = ($lastFcf * (1 + $tg)) / ($d - $tg);
    $terminalPv = $terminal / pow(1 + $d, $years);

    return max(0.0, ($sumPv + $terminalPv) - $netDebt);
}

/**
 * Pure valuation of one company. $bf may be null (no data).
 * Weighted blend across NAV / EBITDA-multiple / DCF (advisor-adjustable),
 * with low/high bounds still as min/max of the three methods × (1 - discount).
 * Slice C: applies a normalised EBITDA (reported + adjustments), supports
 * the multi-year explicit DCF method, and stacks key-person × DLOM ×
 * minority discounts sequentially.
 * @return array
 */
function company_valuation(array $company, ?array $bf, array $assu, ?int $worstRisk): array
{
    $share     = min(1.0, max(0.0, (float) $company['ownership_pct'] / 100));
    $keyperson = valuation_risk_discount($worstRisk);

    if (!$bf) {
        return ['has_data' => false, 'share' => $share, 'discount' => $keyperson,
                'discount_keyperson' => $keyperson, 'discount_dlom' => 0.0,
                'discount_minority' => 0.0,
                'nav' => 0.0, 'ebitda_equity' => 0.0, 'dcf_equity' => 0.0,
                'low' => 0.0, 'mid' => 0.0, 'high' => 0.0, 'client_stake' => 0.0,
                'weights' => ['nav' => 0.0, 'ebitda' => 0.0, 'dcf' => 0.0],
                'weighted_pre_discount' => 0.0,
                'raw_ebitda' => 0.0, 'normalised_ebitda' => 0.0,
                'adjustments_total' => 0.0, 'adjustments' => [],
                'dcf_method' => $assu['dcf_method'] ?? 'gordon'];
    }

    $assets  = (float) $bf['total_assets'];
    $liab    = (float) $bf['total_liabilities'];
    $rawEbitda = (float) $bf['ebitda'];
    $netP    = (float) $bf['net_profit'];
    $netDebt = max(0.0, (float) $bf['bank_loans'] + (float) $bf['shareholder_loans'] - (float) $bf['cash']);

    // Normalised EBITDA = raw + sum of adjustments.
    $adjustments = (array) ($assu['adjustments'] ?? []);
    $adjustmentsTotal = 0.0;
    foreach ($adjustments as $adj) { $adjustmentsTotal += (float) ($adj['amount'] ?? 0); }
    $normalisedEbitda = $rawEbitda + $adjustmentsTotal;

    $nav = $assets - $liab;

    $ebitdaEv     = max(0.0, $normalisedEbitda) * (float) $assu['multiple'];
    $ebitdaEquity = max(0.0, $ebitdaEv - $netDebt);

    $dcfMethod = ($assu['dcf_method'] ?? 'gordon') === 'multiyear' ? 'multiyear' : 'gordon';
    if ($dcfMethod === 'multiyear') {
        $dcfEquity = valuation_multiyear_dcf($assu, $normalisedEbitda, $netDebt);
    } else {
        $base = $normalisedEbitda > 0 ? $normalisedEbitda : $netP;
        if ($base <= 0) {
            $dcfEquity = max(0.0, $nav);
        } else {
            $d = (float) $assu['discount'] / 100;
            $g = (float) $assu['growth'] / 100;
            if ($d <= $g) { $d = $g + 0.05; }
            $dcfEquity = max(0.0, $base * (1 + $g) / ($d - $g) - $netDebt);
        }
    }

    $methods = [max(0.0, $nav), $ebitdaEquity, $dcfEquity];
    $weights = [
        (float) ($assu['weight_nav']    ?? 20),
        (float) ($assu['weight_ebitda'] ?? 50),
        (float) ($assu['weight_dcf']    ?? 30),
    ];
    $wsum = max(1e-9, array_sum($weights));
    $weightedPre = ($methods[0] * $weights[0] + $methods[1] * $weights[1] + $methods[2] * $weights[2]) / $wsum;

    // Sequential haircuts: key-person × DLOM × minority.
    $dlom     = max(0.0, min(0.9, (float) ($assu['dlom_pct']     ?? 25) / 100));
    $minority = max(0.0, min(0.9, (float) ($assu['minority_pct'] ?? 0)  / 100));
    $totalDiscount = 1 - (1 - $keyperson) * (1 - $dlom) * (1 - $minority);
    $f = 1 - $totalDiscount;

    $low  = min($methods) * $f;
    $high = max($methods) * $f;
    $mid  = $weightedPre * $f;

    return ['has_data' => true, 'share' => $share,
            'discount' => $totalDiscount,
            'discount_keyperson' => $keyperson,
            'discount_dlom'      => $dlom,
            'discount_minority'  => $minority,
            'nav' => $nav, 'ebitda_ev' => $ebitdaEv, 'ebitda_equity' => $ebitdaEquity,
            'dcf_equity' => $dcfEquity, 'net_debt' => $netDebt,
            'low' => $low, 'mid' => $mid, 'high' => $high,
            'client_stake' => $mid * $share,
            'weights' => ['nav' => $weights[0] / $wsum,
                          'ebitda' => $weights[1] / $wsum,
                          'dcf' => $weights[2] / $wsum],
            'weighted_pre_discount' => $weightedPre,
            'raw_ebitda' => $rawEbitda, 'normalised_ebitda' => $normalisedEbitda,
            'adjustments_total' => $adjustmentsTotal, 'adjustments' => $adjustments,
            'dcf_method' => $dcfMethod];
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
 * Append a dated snapshot of the current valuation for one company.
 * Stored in settings (val_history:{companyId}); no schema change.
 * History is capped at the last 24 entries.
 */
function valuation_snapshot_save(int $companyId, array $val, array $assu): void
{
    $hist = setting_get_json('val_history:' . $companyId, []);
    $hist[] = [
        'date'    => date('Y-m-d'),
        'time'    => date('H:i'),
        'ya'      => function_exists('tax_current_ya') ? tax_current_ya() : null,
        'assumptions' => [
            'multiple'      => (float) ($assu['multiple']      ?? 0),
            'discount'      => (float) ($assu['discount']      ?? 0),
            'growth'        => (float) ($assu['growth']        ?? 0),
            'weight_nav'    => (float) ($assu['weight_nav']    ?? 0),
            'weight_ebitda' => (float) ($assu['weight_ebitda'] ?? 0),
            'weight_dcf'    => (float) ($assu['weight_dcf']    ?? 0),
        ],
        'summary' => [
            'nav'             => (float) ($val['nav']           ?? 0),
            'ebitda_equity'   => (float) ($val['ebitda_equity'] ?? 0),
            'dcf_equity'      => (float) ($val['dcf_equity']    ?? 0),
            'low'             => (float) ($val['low']           ?? 0),
            'mid'             => (float) ($val['mid']           ?? 0),
            'high'            => (float) ($val['high']          ?? 0),
            'client_stake'    => (float) ($val['client_stake']  ?? 0),
            'discount_applied' => (float) ($val['discount']     ?? 0),
            'discount_keyperson' => (float) ($val['discount_keyperson'] ?? 0),
            'discount_dlom'      => (float) ($val['discount_dlom']      ?? 0),
            'discount_minority'  => (float) ($val['discount_minority']  ?? 0),
            'normalised_ebitda'  => (float) ($val['normalised_ebitda']  ?? 0),
            'adjustments_total'  => (float) ($val['adjustments_total']  ?? 0),
            'dcf_method'         => (string) ($val['dcf_method']        ?? 'gordon'),
            'ownership_pct'      => (float) ($val['share']              ?? 0) * 100,
        ],
        'saved_by' => function_exists('current_user') && current_user()
            ? (string) (current_user()['name'] ?? '') : '',
    ];
    if (count($hist) > 24) { $hist = array_slice($hist, -24); }
    setting_put_json('val_history:' . $companyId, $hist);
}

/** Most-recent snapshots for a company, newest first. */
function valuation_snapshot_history(int $companyId, int $limit = 8): array
{
    $hist = setting_get_json('val_history:' . $companyId, []);
    return array_slice(array_reverse($hist), 0, max(1, $limit));
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
