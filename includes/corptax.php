<?php
/**
 * AdvisorOS — Corporate Tax Structuring (Module D, business layer).
 *
 * Malaysian SME resident-company tax + a salary-vs-dividend extraction
 * optimiser. Single-tier system: dividends are tax-exempt to the
 * shareholder, so the only levers are corporate tax on retained profit
 * and personal tax on salary/director fees (reuses the personal bands
 * in tax_my.php). Per-company assumptions persist in settings
 * (taxstruct:{companyId}); no schema change. Estimates, not tax advice.
 */

declare(strict_types=1);

require_once __DIR__ . '/tax_my.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/settings.php';

/** Malaysian corporate tax on chargeable income (SME tiered vs flat 24%). */
function corp_tax(float $chargeable, bool $isSme): float
{
    if ($chargeable <= 0) {
        return 0.0;
    }
    $y = tax_year();
    if (!$isSme) {
        return $chargeable * (float) ($y['corp_flat'] ?? 0.24);
    }
    $bands = $y['corp_sme_bands'] ?? [[0, 150000, 0.15], [150000, 600000, 0.17], [600000, null, 0.24]];
    $t = 0.0;
    foreach ($bands as [$lo, $hi, $r]) {
        $hi = $hi ?? PHP_FLOAT_MAX;
        if ($chargeable <= $lo) { break; }
        $t += (min($chargeable, $hi) - $lo) * $r;
    }
    return $t;
}

function corptax_inputs(int $companyId, ?array $bf): array
{
    $d = setting_get_json('taxstruct:' . $companyId, []);
    $defProfit = $bf
        ? max(0.0, (float) $bf['net_profit'] + (float) $bf['owner_remuneration'])
        : 0.0;
    return [
        'pre_profit'  => isset($d['pre_profit'])  ? (float) $d['pre_profit']  : round($defProfit, 2),
        'extraction'  => isset($d['extraction'])  ? (float) $d['extraction']  : round($defProfit * 0.6, 2),
        'other_income'=> isset($d['other_income'])? (float) $d['other_income']: 0.0,
        'reliefs'     => isset($d['reliefs'])     ? (float) $d['reliefs']     : 9000.0,
        'is_sme'      => !isset($d['is_sme']) || (bool) $d['is_sme'],
        'family_pay'  => isset($d['family_pay'])  ? (float) $d['family_pay']  : 0.0,
    ];
}

function corptax_inputs_save(int $companyId, array $in): void
{
    setting_put_json('taxstruct:' . $companyId, [
        'pre_profit'   => max(0.0, (float) ($in['pre_profit'] ?? 0)),
        'extraction'   => max(0.0, (float) ($in['extraction'] ?? 0)),
        'other_income' => max(0.0, (float) ($in['other_income'] ?? 0)),
        'reliefs'      => max(0.0, (float) ($in['reliefs'] ?? 9000)),
        'is_sme'       => !empty($in['is_sme']),
        'family_pay'   => max(0.0, (float) ($in['family_pay'] ?? 0)),
    ]);
}

/**
 * Salary-vs-dividend extraction comparison.
 * Tax(S) = corp_tax(P − S) + personal_tax(S + other − reliefs); dividends
 * are single-tier exempt so they carry no further tax. Sweeps the salary
 * portion to find the lowest-total-tax split.
 */
function salary_dividend_scenarios(float $P, float $E, float $other, float $reliefs, bool $isSme): array
{
    $P = max(0.0, $P);
    $sMax = min($E, $P);

    $calc = static function (float $S) use ($P, $E, $other, $reliefs, $isSme): array {
        $corp = corp_tax(max(0.0, $P - $S), $isSme);
        $pers = my_tax_on(max(0.0, $S + $other - $reliefs));
        $afterTax = max(0.0, ($P - $S) - $corp);
        $div = min(max(0.0, $E - $S), $afterTax);
        $total = $corp + $pers;
        return [
            'salary' => $S, 'dividend' => $div, 'corp' => $corp, 'personal' => $pers,
            'total' => $total, 'etr' => $P > 0 ? $total / $P : 0.0,
            'net' => $S - $pers + $div,
            'feasible' => $div >= max(0.0, $E - $S) - 0.5,
        ];
    };

    $allDiv = $calc(0.0);
    $allSal = $calc($sMax);

    $best = $allDiv; $bestS = 0.0;
    $steps = 40;
    for ($i = 1; $i <= $steps; $i++) {
        $S = $sMax * $i / $steps;
        $r = $calc($S);
        if ($r['total'] < $best['total'] - 0.01) { $best = $r; $bestS = $S; }
    }

    return ['all_dividend' => $allDiv, 'all_salary' => $allSal,
            'optimal' => $best, 'optimal_salary' => $bestS,
            'p' => $P, 'extraction' => $E, 'sme' => $isSme];
}

/** One-line AI-context summary across the client's companies. */
function corptax_client_summary(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $lines = [];
    foreach (companies_for_client($clientId) as $c) {
        $bf = bf_latest((int) $c['id']);
        $in = corptax_inputs((int) $c['id'], $bf);
        if ($in['pre_profit'] <= 0) { continue; }
        $sc = salary_dividend_scenarios($in['pre_profit'], $in['extraction'],
            $in['other_income'], $in['reliefs'], $in['is_sme']);
        $saving = max(0.0, $sc['all_salary']['total'] - $sc['optimal']['total']);
        $altSave = max(0.0, $sc['all_dividend']['total'] - $sc['optimal']['total']);
        $lines[] = sprintf(
            '%s: efficient extraction ≈ RM %s salary + RM %s dividend; total tax '
            . 'RM %s (ETR %.0f%%), up to ≈ RM %s saved vs an all-salary draw.',
            $c['name'], money($sc['optimal']['salary']), money($sc['optimal']['dividend']),
            money($sc['optimal']['total']), $sc['optimal']['etr'] * 100,
            money(max($saving, $altSave))
        );
    }
    if (!$lines) { return null; }
    return ['text' => 'CORPORATE TAX STRUCTURING (estimate): ' . implode(' ', $lines)];
}
