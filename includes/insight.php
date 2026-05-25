<?php
/**
 * AdvisorOS — Read-only client insight summaries.
 * Distils the borrowing-capability and tax-planning estimates into
 * compact structures + a one-block text brief for the AI proposal
 * generator. Reuses the shared pure maths; never writes.
 */

declare(strict_types=1);

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/tax_my.php';
require_once __DIR__ . '/settings.php';

/** @return array{status:string,dsr_pct:int,cap_pct:int,headroom_mo:float,add_principal:float,req_reduction:float,text:string}|null */
function capability_summary(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $st = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
    $st->execute([$clientId, $tid]);
    $fin = $st->fetch();
    if (!$fin || (float) $fin['monthly_income'] <= 0) {
        return null;
    }

    $in = setting_get_json('cap:' . $clientId, [
        'monthly_debt' => 0.0, 'avg_rate' => 4.5, 'remaining_years' => 20.0,
        'dsr_cap' => 60.0, 'new_rate' => 4.5, 'new_years' => 10.0,
        'refi_rate' => 3.5, 'lump_sum' => 0.0,
    ]);

    $income = (float) $fin['monthly_income'];
    $cap    = (float) $in['dsr_cap'] / 100;
    $dsr    = (float) $in['monthly_debt'] / $income;
    $maxDbt = $income * $cap;
    $head   = $maxDbt - (float) $in['monthly_debt'];

    if ($dsr <= $cap * 0.75)      { $status = 'Under-leveraged'; }
    elseif ($dsr <= $cap)         { $status = 'Healthy'; }
    else                          { $status = 'Over-leveraged'; }

    $add = $head > 0 ? loan_principal_from_payment($head, (float) $in['new_rate'], (float) $in['new_years']) : 0.0;
    $req = $head < 0 ? -$head : 0.0;

    $text = sprintf(
        'BORROWING CAPACITY (Malaysia DSR estimate): status %s; DSR %d%% vs %d%% cap; '
        . 'monthly debt-service %s RM %s; %s.',
        $status,
        (int) round($dsr * 100),
        (int) round($cap * 100),
        $head >= 0 ? 'headroom' : 'over-commitment',
        money(abs($head)),
        $head >= 0
            ? 'supportable additional loan principal ≈ RM ' . money($add)
            : 'requires ≈ RM ' . money($req) . '/mo reduction to meet the cap'
    );

    return ['status' => $status, 'dsr_pct' => (int) round($dsr * 100),
            'cap_pct' => (int) round($cap * 100), 'headroom_mo' => $head,
            'add_principal' => $add, 'req_reduction' => $req, 'text' => $text];
}

/** @return array{chargeable:float,tax_payable:float,marginal:float,eff_rate:float,top_label:string,max_saving:float,text:string}|null */
function tax_summary(PDO $pdo, ?int $tid, int $clientId): ?array
{
    $st = $pdo->prepare('SELECT * FROM financial_profiles WHERE client_id=? AND tenant_id=? ORDER BY snapshot_date DESC, id DESC LIMIT 1');
    $st->execute([$clientId, $tid]);
    $fin = $st->fetch();

    $cat = my_relief_catalogue();
    $def = ['annual_income' => $fin ? (float) $fin['monthly_income'] * 12 : 0.0,
            'other_income' => 0.0, 'children_u18' => 0];
    foreach ($cat as $k => $v) { $def['r_' . $k] = 0.0; }
    $in = setting_get_json('tax:' . $clientId, $def);
    if ((float) ($in['annual_income'] ?? 0) <= 0 && $fin) {
        $in['annual_income'] = (float) $fin['monthly_income'] * 12;
    }

    $gross = (float) ($in['annual_income'] ?? 0) + (float) ($in['other_income'] ?? 0);
    if ($gross <= 0) {
        return null;
    }

    $relief = MY_SELF_RELIEF + (int) ($in['children_u18'] ?? 0) * MY_CHILD_RELIEF
            + (int) ($in['children_tertiary'] ?? 0) * MY_CHILD_TERTIARY_RELIEF;
    foreach ($cat as $k => $v) { $relief += (float) ($in['r_' . $k] ?? 0); }
    $relief = min($relief, $gross);

    $chargeable = max(0.0, $gross - $relief);
    $payable    = max(0.0, my_tax_on($chargeable) - my_rebate($chargeable));
    $marginal   = my_marginal_rate($chargeable);
    $eff        = $gross > 0 ? $payable / $gross : 0.0;

    $topLabel = '';
    $maxSave  = 0.0;
    if ($marginal > 0) {
        $best = 0.0;
        foreach ($cat as $k => [$label, $capAmt]) {
            $room = min(max(0.0, (float) $capAmt - (float) ($in['r_' . $k] ?? 0)), $chargeable);
            $save = $room * $marginal;
            $maxSave += $save;
            if ($save > $best) { $best = $save; $topLabel = $label; }
        }
    }

    $text = sprintf(
        'TAX (Malaysia resident individual, YA2023+ estimate): chargeable income RM %s; '
        . 'estimated tax payable RM %s (effective %.1f%%, marginal %d%%); up to RM %s '
        . 'potentially saveable via unused reliefs%s.',
        money($chargeable), money($payable), $eff * 100,
        (int) round($marginal * 100), money($maxSave),
        $topLabel !== '' ? ' (largest lever: ' . $topLabel . ')' : ''
    );

    return ['chargeable' => $chargeable, 'tax_payable' => $payable,
            'marginal' => $marginal, 'eff_rate' => $eff, 'top_label' => $topLabel,
            'max_saving' => $maxSave, 'text' => $text];
}
