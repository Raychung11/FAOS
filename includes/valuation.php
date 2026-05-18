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
        'multiple' => isset($d['multiple']) ? (float) $d['multiple'] : 4.0,
        'discount' => isset($d['discount']) ? (float) $d['discount'] : 18.0,
        'growth'   => isset($d['growth'])   ? (float) $d['growth']   : 3.0,
    ];
}

function valuation_assumptions_save(int $companyId, array $in): void
{
    setting_put_json('val:' . $companyId, [
        'multiple' => min(20, max(0, (float) ($in['multiple'] ?? 4))),
        'discount' => min(50, max(1, (float) ($in['discount'] ?? 18))),
        'growth'   => min(15, max(0, (float) ($in['growth'] ?? 3))),
    ]);
}

/** Key-person / marketability discount from the worst risk score (0–3). */
function valuation_risk_discount(?int $worst): float
{
    return [0 => 0.05, 1 => 0.15, 2 => 0.25, 3 => 0.35][$worst] ?? 0.15;
}

/**
 * Pure valuation of one company. $bf may be null (no data).
 * @return array
 */
function company_valuation(array $company, ?array $bf, array $assu, ?int $worstRisk): array
{
    $share = min(1.0, max(0.0, (float) $company['ownership_pct'] / 100));
    $disc  = valuation_risk_discount($worstRisk);

    if (!$bf) {
        return ['has_data' => false, 'share' => $share, 'discount' => $disc,
                'nav' => 0.0, 'ebitda_equity' => 0.0, 'dcf_equity' => 0.0,
                'low' => 0.0, 'mid' => 0.0, 'high' => 0.0, 'client_stake' => 0.0];
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
    $f = 1 - $disc;
    $low  = min($methods) * $f;
    $high = max($methods) * $f;
    $mid  = (array_sum($methods) / 3) * $f;

    return ['has_data' => true, 'share' => $share, 'discount' => $disc,
            'nav' => $nav, 'ebitda_ev' => $ebitdaEv, 'ebitda_equity' => $ebitdaEquity,
            'dcf_equity' => $dcfEquity, 'net_debt' => $netDebt,
            'low' => $low, 'mid' => $mid, 'high' => $high,
            'client_stake' => $mid * $share];
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
