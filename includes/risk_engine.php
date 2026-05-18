<?php
/**
 * AdvisorOS — Enterprise Risk Diagnostic Engine (Module C).
 *
 * Scores the entrepreneur across personal, business, director-liability
 * and shareholder risk using the data already captured (personal
 * snapshot + companies + business financials + stakeholders + the
 * integrated wealth engine). Every dimension is banded
 * Low / Medium / High / Critical with a plain recommendation, an
 * overall verdict, an alert list and a compact AI-context string.
 * Read-only; transparent rules; estimates for advisory use only.
 */

declare(strict_types=1);

require_once __DIR__ . '/wealth.php';

/** Severity label + badge class for a 0–3 score (or null = no data). */
function risk_level(?int $s): array
{
    return match (true) {
        $s === null => ['No data', 'b-warn', '#9aa6b8'],
        $s >= 3     => ['Critical', 'b-overdue', 'var(--danger)'],
        $s >= 2     => ['High', 'b-overdue', 'var(--danger)'],
        $s >= 1     => ['Medium', 'b-warn', 'var(--gold)'],
        default     => ['Low', 'b-active', 'var(--ok)'],
    };
}

/** @return array|null  null when there is no personal snapshot and no company */
function risk_diagnostic(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $w = wealth_report($pdo, $tid, $clientId);
    if (!$w) {
        return null;
    }

    $fs = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
    $fs->execute([$clientId, $tid]);
    $fin = $fs->fetch() ?: null;

    // Aggregate business figures + stakeholder structure.
    $cos = companies_for_client($clientId);
    $agg = ['revenue' => 0.0, 'ebitda' => 0.0, 'assets' => 0.0, 'liab' => 0.0,
            'cash' => 0.0, 'recv' => 0.0, 'bank' => 0.0];
    $hasBiz = false;
    $capIssue = 0;            // 0 ok, 1 minor, 2 major cap-table gap
    $shCompanies = 0;
    $beneficiaries = 0; $benefitPctMax = 0.0; $clientIsDirector = false;
    foreach ($cos as $c) {
        $bf = bf_latest((int) $c['id']);
        if ($bf) {
            $hasBiz = true;
            $agg['revenue'] += (float) $bf['revenue'];
            $agg['ebitda']  += (float) $bf['ebitda'];
            $agg['assets']  += (float) $bf['total_assets'];
            $agg['liab']    += (float) $bf['total_liabilities'];
            $agg['cash']    += (float) $bf['cash'];
            $agg['recv']    += (float) $bf['receivables'];
            $agg['bank']    += (float) $bf['bank_loans'];
        }
        $sh = 0.0; $shCount = 0; $benefit = 0.0;
        foreach (stakeholders_for_company((int) $c['id']) as $s) {
            if ((int) $s['is_shareholder'] === 1) { $sh += (float) $s['shareholding_pct']; $shCount++; }
            if ((int) $s['is_director'] === 1 && $s['relationship'] === 'self') { $clientIsDirector = true; }
            if ((int) $s['is_beneficiary'] === 1) { $beneficiaries++; }
            $benefit += (float) $s['benefit_pct'];
        }
        if ($shCount > 0) {
            $shCompanies++;
            $off = abs($sh - 100);
            $capIssue = max($capIssue, $off > 5 ? 2 : ($off > 0.5 ? 1 : 0));
        } elseif ($bf || $c['status'] === 'active') {
            $capIssue = max($capIssue, 2);          // active company, no cap table
        }
        $benefitPctMax = max($benefitPctMax, $benefit);
    }

    $annualIncome = $fin ? (float) $fin['monthly_income'] * 12 : 0.0;
    $cover = $fin ? (float) $fin['insurance_coverage'] : 0.0;
    $me    = $fin ? (float) $fin['monthly_expenses'] : 0.0;
    $ef    = $fin ? (float) $fin['emergency_fund'] : 0.0;

    $dims = [];
    $add = function (string $key, string $cat, string $label, ?int $score,
                     string $detail, string $rec) use (&$dims) {
        $dims[] = ['key' => $key, 'category' => $cat, 'label' => $label,
                   'score' => $score, 'detail' => $detail, 'rec' => $rec];
    };

    // --- Personal -------------------------------------------------
    if (!$fin || $annualIncome <= 0) {
        $add('protection', 'Personal', 'Protection gap', null,
            'No personal income/cover on record.', 'Capture a personal financial snapshot.');
    } else {
        $mult = $cover / $annualIncome;
        $sc = $mult >= 10 ? 0 : ($mult >= 5 ? 1 : ($mult >= 2 ? 2 : 3));
        $gap = max(0.0, $annualIncome * 10 - $cover);
        $add('protection', 'Personal', 'Protection gap', $sc,
            'Cover RM ' . money($cover) . ' ≈ ' . round($mult, 1) . '× annual income (target ≥10×).',
            $gap > 0 ? 'Close the ≈ RM ' . money($gap) . ' protection gap (life / CI / medical).'
                     : 'Protection broadly adequate — review at next cycle.');
    }
    if (!$fin || $me <= 0) {
        $add('liquidity', 'Personal', 'Emergency liquidity', null,
            'No expense data on record.', 'Capture monthly expenses.');
    } else {
        $months = $ef / $me;
        $sc = $months >= 6 ? 0 : ($months >= 3 ? 1 : ($months >= 1 ? 2 : 3));
        $need = max(0.0, $me * 6 - $ef);
        $add('liquidity', 'Personal', 'Emergency liquidity', $sc,
            'Emergency fund ≈ ' . round($months, 1) . ' months of expenses (target ≥6).',
            $need > 0 ? 'Build emergency reserve by ≈ RM ' . money($need) . '.'
                      : 'Liquidity buffer adequate.');
    }

    // --- Business -------------------------------------------------
    if (!$hasBiz) {
        $add('cashflow', 'Business', 'Cashflow risk', null,
            'No business financial snapshot.', 'Add a company financial snapshot.');
        $add('leverage', 'Business', 'Leverage / solvency', null,
            'No business financial snapshot.', 'Add a company financial snapshot.');
    } else {
        $margin = $agg['revenue'] > 0 ? $agg['ebitda'] / $agg['revenue'] : -1;
        $sc = $agg['ebitda'] <= 0 ? 3
            : ($margin >= 0.15 ? 0 : ($margin >= 0.08 ? 1 : ($margin >= 0.03 ? 2 : 3)));
        if ($agg['bank'] > 0 && ($agg['cash'] + $agg['recv']) < 0.25 * $agg['bank']) {
            $sc = min(3, $sc + 1);
        }
        $add('cashflow', 'Business', 'Cashflow risk', $sc,
            'EBITDA margin ≈ ' . round(max(0, $margin) * 100) . '%; liquid (cash+receivables) RM '
            . money($agg['cash'] + $agg['recv']) . ' vs bank loans RM ' . money($agg['bank']) . '.',
            'Tighten receivables, protect EBITDA margin and align facilities to cash conversion.');

        if ($agg['assets'] <= 0) {
            $add('leverage', 'Business', 'Leverage / solvency', null,
                'No balance-sheet data.', 'Capture company assets & liabilities.');
        } else {
            $d = $agg['liab'] / $agg['assets'];
            $sc2 = $d >= 0.8 ? 3 : ($d >= 0.6 ? 2 : ($d >= 0.4 ? 1 : 0));
            $add('leverage', 'Business', 'Leverage / solvency', $sc2,
                'Liabilities are ' . round($d * 100) . '% of assets.',
                $sc2 >= 2 ? 'De-leverage; restructure debt and rebuild equity buffer.'
                          : 'Gearing within a reasonable range — monitor.');
        }
    }

    $idep = (float) $w['income_dep']; $conc = (float) $w['concentration'];
    $kSc = ($idep >= 0.7 && $conc >= 0.7) ? 3
         : (($idep >= 0.7 || $conc >= 0.7) ? 2 : (($idep >= 0.4 || $conc >= 0.4) ? 1 : 0));
    $add('keyman', 'Business', 'Keyman / founder dependency', $kSc,
        'Income dependency ' . round($idep * 100) . '%, business is ' . round($conc * 100)
        . '% of net worth.',
        $kSc >= 2 ? 'Keyman cover, second-line management and wealth diversification are priorities.'
                  : 'Founder dependency manageable — keep succession current.');

    // --- Director liability --------------------------------------
    $pgR = (float) $w['pg_ratio']; $pg = (float) $w['pg_total'];
    $pSc = $pgR >= 1 ? 3 : ($pgR >= 0.5 ? 2 : ($pg > 0 ? 1 : 0));
    $add('guarantee', 'Director liability', 'Personal-guarantee exposure', $pSc,
        'Personal guarantees RM ' . money($pg) . ' ≈ '
        . ($pgR >= 9 ? '≥900' : round($pgR * 100)) . '% of personal net worth'
        . ($clientIsDirector ? '; client is a director.' : '.'),
        $pSc >= 2 ? 'Cap / stagger guarantees, add keyman & credit protection, ring-fence personal assets.'
                  : 'Track guarantee exposure as facilities change.');

    // --- Shareholder ---------------------------------------------
    if (!$cos) {
        $add('captable', 'Shareholder', 'Cap-table clarity', null,
            'No companies recorded.', 'Add the client\'s companies.');
        $add('succession', 'Shareholder', 'Succession readiness', null,
            'No companies recorded.', 'Add companies and beneficiaries.');
    } else {
        $cSc = $capIssue >= 2 ? 2 : ($capIssue === 1 ? 1 : 0);
        $add('captable', 'Shareholder', 'Cap-table clarity', $cSc,
            $cSc >= 2 ? 'One or more active companies have an unclear / incomplete shareholding.'
                      : ($cSc === 1 ? 'Minor shareholding rounding to reconcile.'
                                    : 'Shareholding recorded and reconciles to ~100%.'),
            $cSc >= 1 ? 'Document the cap table and put a shareholder / buy-sell agreement in place.'
                      : 'Maintain the cap table as ownership changes.');

        $sSc = $beneficiaries === 0 ? 3
             : (($benefitPctMax < 90 || $benefitPctMax > 110) ? 2 : 1);
        $add('succession', 'Shareholder', 'Succession readiness', $sSc,
            $beneficiaries === 0 ? 'No beneficiaries recorded for any company.'
                : $beneficiaries . ' beneficiar' . ($beneficiaries === 1 ? 'y' : 'ies')
                  . '; allocation ≈ ' . round($benefitPctMax) . '%.',
            $sSc >= 3 ? 'Establish a succession plan: will, beneficiaries and a funded buy-sell.'
                      : ($sSc >= 2 ? 'Complete the beneficiary allocation and fund the buy-sell.'
                                   : 'Formalise via will / trust and review periodically.'));
    }

    // --- Roll-up --------------------------------------------------
    $scored = array_filter(array_column($dims, 'score'), fn ($s) => $s !== null);
    $crit = count(array_filter($scored, fn ($s) => $s >= 3));
    $high = count(array_filter($scored, fn ($s) => $s === 2));
    $worst = $scored ? max($scored) : null;
    [$overallLbl] = risk_level($worst);
    $overall = $crit > 0 ? 'Critical exposure' : ($high > 0 ? 'High risk areas'
        : ($worst >= 1 ? 'Moderate — manageable' : 'Low overall risk'));

    $cats = [];
    foreach ($dims as $d) {
        if ($d['score'] === null) { continue; }
        $cats[$d['category']] = max($cats[$d['category']] ?? 0, $d['score']);
    }

    $alerts = array_values(array_filter($dims, fn ($d) => $d['score'] !== null && $d['score'] >= 2));
    usort($alerts, fn ($a, $b) => $b['score'] <=> $a['score']);

    $line = [];
    foreach ($dims as $d) {
        if ($d['score'] !== null && $d['score'] >= 1) {
            $line[] = $d['label'] . ' (' . risk_level($d['score'])[0] . ')';
        }
    }
    $text = 'ENTERPRISE RISK (estimate): overall ' . $overall
        . ($line ? '. Flagged: ' . implode(', ', $line) . '.' : '. No material risks flagged.');

    return ['dims' => $dims, 'cats' => $cats, 'overall' => $overall,
            'overall_lbl' => $overallLbl, 'worst' => $worst,
            'critical' => $crit, 'high' => $high,
            'alerts' => $alerts, 'text' => $text];
}
