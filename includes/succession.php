<?php
/**
 * AdvisorOS — Retirement & Succession Engine (Module G).
 *
 * Answers the document's core question — "if the founder stops today,
 * can the family survive and retain the business value?" — by combining
 * the personal snapshot, the indicative business valuation, beneficiary
 * structure and key-person risk into a 0–100 readiness score with a
 * plain survival verdict, the funding gap and recommendations.
 * Read-only; estimates for advisory discussion only.
 */

declare(strict_types=1);

require_once __DIR__ . '/valuation.php';

/** @return array|null  null when there is neither a personal snapshot nor a company */
function succession_report(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $fs = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
    $fs->execute([$clientId, $tid]);
    $fin = $fs->fetch() ?: null;

    $val = client_valuation($pdo, $tid, $clientId);
    if (!$fin && $val['companies'] === 0) {
        return null;
    }
    $rg = risk_diagnostic($pdo, $tid, $clientId);

    $monthlyExp = $fin ? (float) $fin['monthly_expenses'] : 0.0;
    $annualNeed = $monthlyExp > 0
        ? $monthlyExp * 12
        : ($fin ? (float) $fin['monthly_income'] * 12 * 0.7 : 0.0);
    $insurance  = $fin ? (float) $fin['insurance_coverage'] : 0.0;
    $emergency  = $fin ? (float) $fin['emergency_fund'] : 0.0;
    $personalLiab = $fin ? (float) $fin['total_liabilities'] : 0.0;

    // Personal-guarantee exposure from the risk/wealth layer.
    $pg = 0.0;
    foreach (($rg['dims'] ?? []) as $d) {
        if ($d['key'] === 'guarantee') { /* detail only — pg amount from wealth */ }
    }
    $w = wealth_report($pdo, $tid, $clientId);
    if ($w) { $pg = (float) $w['pg_total']; }

    // Keyman score (0 good … 3 critical) from the risk engine.
    $keyman = null;
    foreach (($rg['dims'] ?? []) as $d) {
        if ($d['key'] === 'keyman') { $keyman = $d['score']; break; }
    }

    // Beneficiary plan completeness across the client's companies.
    $benMax = 0.0; $benAny = false;
    foreach (companies_for_client($clientId) as $c) {
        $sum = 0.0;
        foreach (stakeholders_for_company((int) $c['id']) as $s) {
            if ((int) $s['is_beneficiary'] === 1) { $benAny = true; }
            $sum += (float) $s['benefit_pct'];
        }
        $benMax = max($benMax, $sum);
    }

    // Income-replacement capital the family needs if the founder stops:
    // ~10 years of living costs + clear personal debt + cover guarantees.
    $needCapital = $annualNeed * 10 + $personalLiab + $pg;
    $available   = $insurance + $emergency;          // immediately liquid on event
    $gap         = max(0.0, $needCapital - $available);
    $yearsCover  = $annualNeed > 0 ? $available / $annualNeed : 0.0;

    if ($available >= $needCapital && $needCapital > 0) {
        $verdict = 'Yes — the family is funded'; $vCls = 'b-active';
    } elseif ($available >= $annualNeed * 3 + $personalLiab) {
        $verdict = 'Partially — a funding gap remains'; $vCls = 'b-warn';
    } else {
        $verdict = 'No — immediate shortfall'; $vCls = 'b-overdue';
    }

    // --- Readiness score (0–100) ---------------------------------
    $clamp = fn (float $v) => max(0.0, min(1.0, $v));
    $cProtection = $needCapital > 0 ? $clamp($available / $needCapital) : 1.0;
    $cLiquidity  = $clamp(($insurance + $emergency) / max(1.0, $personalLiab + $pg));
    $cPlan       = !$benAny ? 0.0 : (($benMax >= 90 && $benMax <= 110) ? 1.0 : 0.5);
    $cContinuity = $keyman === null ? 0.5 : (1 - $keyman / 3);

    $score = (int) round(
        $cProtection * 35 + $cLiquidity * 25 + $cPlan * 25 + $cContinuity * 15
    );
    if ($score >= 80)      { $band = 'Strong';     $bCls = 'b-active'; }
    elseif ($score >= 60)  { $band = 'Developing'; $bCls = 'b-warn'; }
    elseif ($score >= 40)  { $band = 'Weak';       $bCls = 'b-warn'; }
    else                   { $band = 'Critical';   $bCls = 'b-overdue'; }

    $components = [
        ['Protection adequacy', $cProtection, 35,
         'Liquid cover (insurance + reserves) vs the family\'s income-replacement, debt and guarantee need.'],
        ['Estate liquidity', $cLiquidity, 25,
         'Whether liquid assets + insurance can clear personal debt and guarantees without selling the business.'],
        ['Ownership / beneficiary plan', $cPlan, 25,
         'Beneficiaries recorded and the benefit allocation reconciling to ~100%.'],
        ['Business continuity', $cContinuity, 15,
         'Inverse of key-person dependency — can the business run and hold value without the founder.'],
    ];

    $recs = [];
    if ($cProtection < 0.8) {
        $recs[] = 'Close the protection gap of ≈ RM ' . money($gap)
            . ' (life / CI / business loan cover) so the family is not forced to sell.';
    }
    if ($cLiquidity < 0.8) {
        $recs[] = 'Improve estate liquidity — personal debt + guarantees of ≈ RM '
            . money($personalLiab + $pg) . ' exceed available liquid cover.';
    }
    if ($cPlan < 1.0) {
        $recs[] = $benAny
            ? 'Complete the beneficiary allocation (currently ≈ ' . round($benMax)
              . '%) and fund a buy-sell agreement.'
            : 'Name beneficiaries, put a will / trust in place and fund a buy-sell agreement.';
    }
    if ($cContinuity < 0.7) {
        $recs[] = 'Reduce key-person dependency: second-line management, keyman cover and documented succession.';
    }
    if (!$recs) {
        $recs[] = 'Succession is broadly funded — formalise legal instruments and review annually.';
    }

    $text = sprintf(
        'SUCCESSION (estimate): "if the founder stops today" — %s. Readiness '
        . '%d/100 (%s). Family needs ≈ RM %s, liquid cover ≈ RM %s, gap ≈ RM %s '
        . '(≈ %s years of living costs covered).',
        $verdict, $score, $band, money($needCapital), money($available),
        money($gap), round($yearsCover, 1)
    );

    return ['score' => $score, 'band' => $band, 'band_cls' => $bCls,
            'verdict' => $verdict, 'verdict_cls' => $vCls,
            'need_capital' => $needCapital, 'available' => $available, 'gap' => $gap,
            'years_cover' => $yearsCover, 'business_stake' => $val['total_stake'],
            'components' => $components, 'recs' => $recs, 'text' => $text];
}
