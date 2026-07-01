<?php
/**
 * AdvisorOS — Personal & Business Wealth Engine.
 *
 * Combines the personal financial snapshot with the entrepreneur's
 * companies (latest business-financial snapshot each) into one
 * integrated picture, and derives the risk ratios the document calls
 * for: business wealth concentration, founder/single-asset
 * concentration, personal-guarantee exposure and business-income
 * dependency. Read-only; estimates for advisory discussion only.
 */

declare(strict_types=1);

require_once __DIR__ . '/business.php';

/** @return array|null  null when there is neither a personal snapshot nor a company */
function wealth_report(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $fs = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
    $fs->execute([$clientId, $tid]);
    $fin = $fs->fetch() ?: null;

    $cos = companies_for_client($clientId);
    if (!$fin && !$cos) {
        return null;
    }

    // Personal side
    $pAssets = $fin ? (float) $fin['total_assets'] : 0.0;
    $pLiab   = $fin ? (float) $fin['total_liabilities'] : 0.0;
    $pNet    = $pAssets - $pLiab;
    $annualPersonalIncome = $fin ? (float) $fin['monthly_income'] * 12 : 0.0;

    // Business side (client's attributable share)
    $bizEquity = 0.0; $revenue = 0.0; $ebitda = 0.0; $pgTotal = 0.0;
    $bizIncome = 0.0; $largestEquity = 0.0;
    $rows = [];
    foreach ($cos as $c) {
        $bf    = bf_latest((int) $c['id']);
        $share = min(1.0, max(0.0, (float) $c['ownership_pct'] / 100));
        $equity = $bf ? ((float) $bf['total_assets'] - (float) $bf['total_liabilities']) : 0.0;
        $attrib = $equity * $share;
        $pg     = $bf ? (float) $bf['personal_guarantee'] : 0.0;          // joint/several — full
        $income = $bf ? (float) $bf['owner_remuneration']
                        + (float) $bf['dividends_paid'] * $share : 0.0;

        $bizEquity += $attrib;
        $revenue   += $bf ? (float) $bf['revenue'] : 0.0;
        $ebitda    += $bf ? (float) $bf['ebitda'] : 0.0;
        $pgTotal   += $pg;
        $bizIncome += $income;
        $largestEquity = max($largestEquity, $attrib);

        $rows[] = ['name' => $c['name'], 'ownership' => (float) $c['ownership_pct'],
                   'equity' => $equity, 'attributable' => $attrib,
                   'pg' => $pg, 'has_bf' => (bool) $bf];
    }

    $totalNet = $pNet + $bizEquity;
    $denNet   = $totalNet > 0 ? $totalNet : 0.0;

    $concentration = $denNet > 0 ? $bizEquity / $denNet : ($bizEquity > 0 ? 1.0 : 0.0);
    $largestConc   = $denNet > 0 ? $largestEquity / $denNet : 0.0;
    $pgRatio       = $pNet > 0 ? $pgTotal / $pNet : ($pgTotal > 0 ? 9.99 : 0.0);
    $household     = max($annualPersonalIncome, $bizIncome);
    $incomeDep     = $household > 0 ? $bizIncome / $household : 0.0;

    $band = static function (float $v, float $hi, float $mid): array {
        if ($v >= $hi)  { return ['High', 'b-overdue']; }
        if ($v >= $mid) { return ['Moderate', 'b-warn']; }
        return ['Low', 'b-active'];
    };
    [$concLbl, $concCls] = $band($concentration, 0.70, 0.40);
    [$depLbl,  $depCls]  = $band($incomeDep,     0.70, 0.40);
    if ($pgRatio >= 1.0)      { $pgLbl = 'Critical'; $pgCls = 'b-overdue'; }
    elseif ($pgRatio >= 0.5)  { $pgLbl = 'High';     $pgCls = 'b-warn'; }
    elseif ($pgTotal > 0)     { $pgLbl = 'Moderate'; $pgCls = 'b-warn'; }
    else                      { $pgLbl = 'None';     $pgCls = 'b-active'; }

    $worst = 0;
    foreach ([[$concLbl], [$depLbl], [$pgLbl]] as $x) {
        $worst = max($worst, in_array($x[0], ['High', 'Critical'], true) ? 2
                            : ($x[0] === 'Moderate' ? 1 : 0));
    }
    $overall = [2 => 'Concentrated / exposed', 1 => 'Some concentration', 0 => 'Well diversified'][$worst];

    $pct = static fn (float $v) => round(min(1.0, max(0.0, $v)) * 100);
    $text = sprintf(
        'WEALTH (integrated estimate): total net worth RM %s (personal RM %s + '
        . 'attributable business equity RM %s). Business is %d%% of net worth '
        . '(%s concentration). Personal-guarantee exposure RM %s (%s vs personal '
        . 'net worth). Business-income dependency %d%% (%s). Overall: %s.',
        money($totalNet), money($pNet), money($bizEquity),
        $pct($concentration), strtolower($concLbl),
        money($pgTotal), strtolower($pgLbl),
        $pct($incomeDep), strtolower($depLbl), $overall
    );

    return [
        'has_personal' => (bool) $fin, 'companies' => count($cos),
        'p_assets' => $pAssets, 'p_liab' => $pLiab, 'p_net' => $pNet,
        'biz_equity' => $bizEquity, 'revenue' => $revenue, 'ebitda' => $ebitda,
        'pg_total' => $pgTotal, 'biz_income' => $bizIncome,
        'annual_personal_income' => $annualPersonalIncome,
        'total_net' => $totalNet,
        'concentration' => $concentration, 'concentration_lbl' => $concLbl, 'concentration_cls' => $concCls,
        'largest_conc' => $largestConc,
        'pg_ratio' => $pgRatio, 'pg_lbl' => $pgLbl, 'pg_cls' => $pgCls,
        'income_dep' => $incomeDep, 'income_dep_lbl' => $depLbl, 'income_dep_cls' => $depCls,
        'overall' => $overall, 'rows' => $rows, 'text' => $text,
    ];
}

/** Compact accessor for the AI proposal context (null when no data). */
function wealth_summary(PDO $pdo, ?int $tid, int $clientId): ?array
{
    return wealth_report($pdo, $tid, $clientId);
}
