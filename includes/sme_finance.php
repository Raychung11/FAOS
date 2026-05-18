<?php
/**
 * AdvisorOS — SME Financing Readiness (Module E, business layer).
 *
 * The business analogue of personal borrowing capacity: a Debt-Service
 * Coverage Ratio on the company's bank debt, additional facility
 * headroom at a target DSCR, and a readiness verdict factoring credit
 * conduct (CCRIS/CTOS captured as an advisor input — no external
 * integration). Reuses the loan maths in finance.php. Per-company
 * assumptions persist in settings (fin:{companyId}). Estimates only.
 */

declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/settings.php';

const SME_CREDIT_CONDUCT = ['clean' => 'Clean record',
    'minor' => 'Minor late payments', 'adverse' => 'Adverse / default history'];

function sme_inputs(int $companyId): array
{
    $d = setting_get_json('fin:' . $companyId, []);
    return [
        'interest'       => isset($d['interest'])       ? (float) $d['interest']       : 6.5,
        'tenure'         => isset($d['tenure'])         ? (float) $d['tenure']         : 7.0,
        'target_dscr'    => isset($d['target_dscr'])    ? (float) $d['target_dscr']    : 1.25,
        'new_rate'       => isset($d['new_rate'])       ? (float) $d['new_rate']       : 6.5,
        'new_tenure'     => isset($d['new_tenure'])     ? (float) $d['new_tenure']     : 7.0,
        'credit_conduct' => in_array($d['credit_conduct'] ?? '', array_keys(SME_CREDIT_CONDUCT), true)
                            ? $d['credit_conduct'] : 'clean',
    ];
}

function sme_inputs_save(int $companyId, array $in): void
{
    setting_put_json('fin:' . $companyId, [
        'interest'       => min(30, max(0, (float) ($in['interest'] ?? 6.5))),
        'tenure'         => min(35, max(0.5, (float) ($in['tenure'] ?? 7))),
        'target_dscr'    => min(3, max(1, (float) ($in['target_dscr'] ?? 1.25))),
        'new_rate'       => min(30, max(0, (float) ($in['new_rate'] ?? 6.5))),
        'new_tenure'     => min(35, max(0.5, (float) ($in['new_tenure'] ?? 7))),
        'credit_conduct' => in_array($in['credit_conduct'] ?? '', array_keys(SME_CREDIT_CONDUCT), true)
                            ? $in['credit_conduct'] : 'clean',
    ]);
}

/**
 * @return array{has_data:bool,...}
 */
function sme_financing(array $bf, array $in): array
{
    if (!$bf) {
        return ['has_data' => false];
    }
    $ebitda = (float) $bf['ebitda'];
    $loans  = (float) $bf['bank_loans'];

    $annualService = loan_monthly_payment($loans, (float) $in['interest'], (float) $in['tenure']) * 12;
    $dscr = $annualService > 0
        ? $ebitda / $annualService
        : ($ebitda > 0 ? 99.0 : 0.0);

    if ($dscr >= 1.5)       { $band = 'Strong';    $bCls = 'b-active'; $base = 100; }
    elseif ($dscr >= 1.25)  { $band = 'Adequate';  $bCls = 'b-active'; $base = 80; }
    elseif ($dscr >= 1.0)   { $band = 'Tight';     $bCls = 'b-warn';   $base = 55; }
    else                    { $band = 'Shortfall'; $bCls = 'b-overdue';$base = 25; }

    $penalty = ['clean' => 0, 'minor' => 15, 'adverse' => 35][$in['credit_conduct']] ?? 0;
    $score = max(0, min(100, $base - $penalty));
    if ($score >= 80)      { $verdict = 'Bankable';            $vCls = 'b-active'; }
    elseif ($score >= 55)  { $verdict = 'Bankable with terms'; $vCls = 'b-warn'; }
    else                   { $verdict = 'Not yet bankable';    $vCls = 'b-overdue'; }

    $maxService   = $ebitda / max(1.0, (float) $in['target_dscr']);
    $headroomSvc  = max(0.0, $maxService - $annualService);
    $addFacility  = loan_principal_from_payment($headroomSvc / 12,
        (float) $in['new_rate'], (float) $in['new_tenure']);

    return ['has_data' => true, 'ebitda' => $ebitda, 'loans' => $loans,
            'annual_service' => $annualService, 'dscr' => $dscr,
            'band' => $band, 'band_cls' => $bCls, 'score' => $score,
            'verdict' => $verdict, 'verdict_cls' => $vCls,
            'max_service' => $maxService, 'headroom_service' => $headroomSvc,
            'add_facility' => $addFacility];
}

/** One-line AI-context summary across the client's companies. */
function sme_client_summary(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $lines = [];
    foreach (companies_for_client($clientId) as $c) {
        $bf = bf_latest((int) $c['id']);
        if (!$bf) { continue; }
        $r = sme_financing($bf, sme_inputs((int) $c['id']));
        $lines[] = sprintf(
            '%s: DSCR ≈ %.2f (%s, %s); additional supportable facility ≈ RM %s.',
            $c['name'], $r['dscr'], $r['band'], $r['verdict'], money($r['add_facility'])
        );
    }
    if (!$lines) { return null; }
    return ['text' => 'SME FINANCING READINESS (estimate): ' . implode(' ', $lines)];
}
