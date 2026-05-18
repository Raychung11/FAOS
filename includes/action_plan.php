<?php
/**
 * AdvisorOS — Strategic Action Plan engine (Module H).
 *
 * The synthesis layer: turns every diagnostic (risk, succession,
 * corporate tax, SME financing, valuation, wealth concentration) into
 * one deduplicated, prioritised plan bucketed Immediate (0–3m) /
 * Mid-term (3–12m) / Long-term (12m+). Status is tracked per client
 * in the settings table (actplan:{clientId}); no schema change.
 * Estimates for advisory discussion only.
 */

declare(strict_types=1);

require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/succession.php';
require_once __DIR__ . '/corptax.php';
require_once __DIR__ . '/sme_finance.php';
require_once __DIR__ . '/settings.php';

const ACTION_TIERS = ['immediate' => 'Immediate · 0–3 months',
    'mid' => 'Mid-term · 3–12 months', 'long' => 'Long-term · 12 months+'];
const ACTION_STATUSES = ['open' => 'Open', 'in_progress' => 'In progress',
    'done' => 'Done', 'dismissed' => 'Dismissed'];

function action_status_all(int $clientId): array
{
    return setting_get_json('actplan:' . $clientId, []);
}

function action_status_save(int $clientId, string $key, string $status, string $note): void
{
    $all = action_status_all($clientId);
    if (!array_key_exists($status, ACTION_STATUSES)) { $status = 'open'; }
    $all[$key] = ['status' => $status, 'note' => mb_substr(trim($note), 0, 500)];
    setting_put_json('actplan:' . $clientId, $all);
}

/**
 * @return array{tiers:array,meta:array,has_data:bool,text:string,
 *               counts:array,wealth:?array,risk:?array,succession:?array,valuation:?array}
 */
function action_plan_build(PDO $pdo, ?int $tid, int $clientId): array
{
    $risk = risk_diagnostic($pdo, $tid, $clientId);
    $suc  = succession_report($pdo, $tid, $clientId);
    $val  = client_valuation($pdo, $tid, $clientId);
    $wlth = wealth_report($pdo, $tid, $clientId);

    $tiers = ['immediate' => [], 'mid' => [], 'long' => []];
    $push = function (string $tier, string $key, string $title, string $why,
                      string $action, string $src) use (&$tiers) {
        $tiers[$tier][] = ['key' => $key, 'title' => $title, 'why' => $why,
                           'action' => $action, 'source' => $src];
    };

    // --- Risk diagnostic dimensions ------------------------------
    if ($risk) {
        foreach ($risk['dims'] as $d) {
            if ($d['score'] === null || $d['score'] < 1) { continue; }
            if ($d['key'] === 'succession' && $suc) { continue; }   // superseded below
            $tier = $d['score'] >= 3 ? 'immediate' : ($d['score'] >= 2 ? 'mid' : 'long');
            $push($tier, 'risk:' . $d['key'], $d['label'], $d['detail'], $d['rec'], 'Risk');
        }
    }

    // --- Succession ----------------------------------------------
    if ($suc) {
        $tier = $suc['verdict_cls'] === 'b-overdue' ? 'immediate'
              : ($suc['verdict_cls'] === 'b-warn' ? 'mid' : 'long');
        foreach ($suc['recs'] as $i => $rec) {
            $push($i === 0 ? $tier : 'long', 'succ:' . $i,
                'Succession readiness (' . $suc['score'] . '/100 · ' . $suc['band'] . ')',
                $suc['verdict'] . ' — funding gap ≈ RM ' . money($suc['gap']), $rec, 'Succession');
        }
    }

    // --- Corporate tax + SME financing (per company) -------------
    foreach (companies_for_client($clientId) as $c) {
        $cid = (int) $c['id'];
        $bf  = bf_latest($cid);

        $ti = corptax_inputs($cid, $bf);
        if ($ti['pre_profit'] > 0) {
            $s = salary_dividend_scenarios($ti['pre_profit'], $ti['extraction'],
                $ti['other_income'], $ti['reliefs'], $ti['is_sme']);
            $save = max($s['all_salary']['total'] - $s['optimal']['total'],
                        $s['all_dividend']['total'] - $s['optimal']['total']);
            if ($save >= 500) {
                $push('mid', 'ctx:' . $cid, 'Tax-efficient extraction — ' . $c['name'],
                    'Optimal split ≈ RM ' . money($s['optimal']['salary']) . ' salary + RM '
                    . money($s['optimal']['dividend']) . ' dividend.',
                    'Restructure the salary/dividend mix (≈ RM ' . money($save)
                    . '/yr) — confirm with a tax agent.', 'Corporate tax');
            }
        }

        if ($bf) {
            $f = sme_financing($bf, sme_inputs($cid));
            if ($f['dscr'] < 1.0) {
                $push('immediate', 'sme:' . $cid, 'Debt service shortfall — ' . $c['name'],
                    'DSCR ≈ ' . number_format($f['dscr'], 2) . ' (below 1.0).',
                    'Restructure or extend the facility before any new borrowing.', 'SME financing');
            } elseif ($f['dscr'] < (float) sme_inputs($cid)['target_dscr']) {
                $push('mid', 'sme:' . $cid, 'Strengthen bankability — ' . $c['name'],
                    'DSCR ≈ ' . number_format($f['dscr'], 2) . ', below the bank target.',
                    'Lift EBITDA / reduce servicing before applying for facilities.', 'SME financing');
            }
        }
    }

    // --- Wealth concentration ------------------------------------
    if ($wlth && $wlth['concentration'] >= 0.4) {
        $tier = $wlth['concentration'] >= 0.7 ? 'mid' : 'long';
        $push($tier, 'wealth:conc', 'Diversify wealth outside the business',
            round($wlth['concentration'] * 100) . '% of net worth is tied to the business.',
            'Build liquid, non-correlated assets and a liquidity/exit plan.', 'Wealth');
    }

    // --- Valuation / exit readiness ------------------------------
    if ($val['companies'] > 0 && $val['total_mid'] > 0) {
        $push('long', 'val:exit', 'Independent valuation & exit readiness',
            'Indicative equity ≈ RM ' . money($val['total_mid']) . ' (stake RM '
            . money($val['total_stake']) . ').',
            'Commission a formal valuation and begin transition / exit planning.', 'Valuation');
    }

    $counts = ['immediate' => count($tiers['immediate']),
               'mid' => count($tiers['mid']), 'long' => count($tiers['long'])];
    $has = (bool) ($risk || $val['companies'] > 0 || $wlth);

    $topImm = array_slice(array_column($tiers['immediate'], 'title'), 0, 3);
    $text = sprintf(
        'STRATEGIC ACTION PLAN (estimate): %d immediate, %d mid-term, %d long-term.%s',
        $counts['immediate'], $counts['mid'], $counts['long'],
        $topImm ? ' Immediate priorities: ' . implode('; ', $topImm) . '.' : ''
    );

    return ['tiers' => $tiers, 'counts' => $counts, 'has_data' => $has,
            'text' => $text, 'risk' => $risk, 'succession' => $suc,
            'valuation' => $val, 'wealth' => $wlth];
}

/** Compact accessor for the AI proposal context (null when no data). */
function action_plan_summary(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $p = action_plan_build($pdo, $tid, $clientId);
    return $p['has_data'] ? ['text' => $p['text']] : null;
}
