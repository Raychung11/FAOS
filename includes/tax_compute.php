<?php
/**
 * AdvisorOS — Itemised Malaysian individual income tax computation.
 *
 * Follows the ITA 1967 sequence: per-source statutory income →
 * aggregate income (less current-year business loss) → total income
 * (less approved donations, capped 10%) → less reliefs → chargeable
 * income → tax per the YA schedule → less rebate → tax payable.
 * Rates/reliefs come from the YA-versioned engine (tax_my.php).
 * Inputs persist per client in settings (taxcomp:{clientId}); no
 * schema change. Estimates only.
 */

declare(strict_types=1);

require_once __DIR__ . '/tax_my.php';
require_once __DIR__ . '/settings.php';

function taxcomp_get(int $clientId): array
{
    $d = setting_get_json('taxcomp:' . $clientId, []);
    return [
        'employment' => $d['employment'] ?? [],
        'businesses' => $d['businesses'] ?? [],
        'rental'     => $d['rental'] ?? [],
        'other'      => $d['other'] ?? [],
        'donations'  => (float) ($d['donations'] ?? 0),
        'zakat'      => (float) ($d['zakat'] ?? 0),
        'reliefs'    => $d['reliefs'] ?? [],
        'updated_at' => (string) ($d['updated_at'] ?? ''),
    ];
}

function taxcomp_save(int $clientId, array $in): void
{
    $in['updated_at'] = date('Y-m-d H:i');
    setting_put_json('taxcomp:' . $clientId, $in);
}

function taxcomp_exists(int $clientId): bool
{
    $d = taxcomp_get($clientId);
    return $d['employment'] || $d['businesses'] || $d['rental'] || $d['other'];
}

/** Reliefs sub-computation: returns [before, after, rows, self, child]. */
function tc_reliefs(array $r, float $total): array
{
    $cat = my_relief_catalogue();
    $child = (int) ($r['children_u18'] ?? 0) * my_child_relief()
           + (int) ($r['children_tertiary'] ?? 0) * my_child_tertiary_relief()
           + (int) ($r['disabled_u18'] ?? 0) * my_disabled_child_relief()
           + (int) ($r['disabled_tertiary'] ?? 0) * my_disabled_child_tertiary_relief();
    $base = my_self_relief() + $child;

    $used = $base; $max = $base; $rows = [];
    foreach ($cat as $k => [$label, $cap, $grp]) {
        $u = min((float) $cap, max(0.0, (float) ($r['r_' . $k] ?? 0)));
        $used += $u; $max += (float) $cap;
        $rows[] = ['key' => $k, 'label' => $label, 'group' => $grp,
                   'claimed' => $u, 'cap' => (float) $cap, 'room' => (float) $cap - $u];
    }
    return [min($total, $used), min($total, $max), $rows, my_self_relief(), $child];
}

/**
 * Full computation. $in is the taxcomp structure.
 * @return array
 */
function tax_compute(array $in): array
{
    // --- Employment (s.13) — each line: label, amount, exempt ---
    $empRows = []; $empSI = 0.0;
    foreach ($in['employment'] as $e) {
        $amt = (float) ($e['amount'] ?? 0);
        $ex  = min($amt, max(0.0, (float) ($e['exempt'] ?? 0)));
        $tx  = $amt - $ex;
        $empSI += $tx;
        $empRows[] = ['label' => (string) ($e['label'] ?? ''), 'amount' => $amt,
                      'exempt' => $ex, 'taxable' => $tx];
    }

    // --- Business (s.4(a)) — gross, expenses, capital allowance ---
    $bizRows = []; $bizSI = 0.0; $bizLoss = 0.0;
    foreach ($in['businesses'] as $b) {
        $gross = (float) ($b['gross'] ?? 0);
        $exp   = (float) ($b['expenses'] ?? 0);
        $ca    = (float) ($b['ca'] ?? 0);
        $adj   = $gross - $exp;
        if ($adj >= 0) {
            $si = max(0.0, $adj - $ca);
            $bizSI += $si;
        } else {
            $bizLoss += -$adj;            // current-year loss
            $si = 0.0;
        }
        $bizRows[] = ['name' => (string) ($b['name'] ?? ''), 'gross' => $gross,
                      'expenses' => $exp, 'adjusted' => $adj, 'ca' => $ca, 'statutory' => $si];
    }

    // --- Rental: 4(d) passive vs 4(a) active (with services) ---
    $rentRows = []; $rentPassive = 0.0; $rentActive = 0.0;
    foreach ($in['rental'] as $r) {
        $gross = (float) ($r['gross'] ?? 0);
        $exp   = (float) ($r['expenses'] ?? 0);
        $net   = max(0.0, $gross - $exp);   // rental losses not set off here
        $is4a  = ($r['type'] ?? '4d') === '4a';
        if ($is4a) { $rentActive += $net; } else { $rentPassive += $net; }
        $rentRows[] = ['name' => (string) ($r['name'] ?? ''), 'gross' => $gross,
                       'expenses' => $exp, 'net' => $net, 'type' => $is4a ? '4a' : '4d'];
    }

    // --- Other income (dividends, interest, etc.) ---
    $otherRows = []; $other = 0.0;
    foreach ($in['other'] as $o) {
        $amt = (float) ($o['amount'] ?? 0);
        $other += $amt;
        $otherRows[] = ['label' => (string) ($o['label'] ?? ''), 'amount' => $amt];
    }

    // --- Aggregate → total → chargeable ---
    $aggregateGross = $empSI + $bizSI + $rentActive + $rentPassive + $other;
    $aggregate = max(0.0, $aggregateGross - $bizLoss);     // s.44(2) loss set-off
    $donCap    = $aggregate * 0.10;
    $donations = min(max(0.0, (float) ($in['donations'] ?? 0)), $donCap);
    $total     = max(0.0, $aggregate - $donations);

    [$reliefBefore, $reliefAfter, $reliefRows, $self, $child] = tc_reliefs($in['reliefs'] ?? [], $total);

    $zakat = max(0.0, (float) ($in['zakat'] ?? 0));

    $chargeable      = max(0.0, $total - $reliefBefore);
    $grossTax        = my_tax_on($chargeable);
    $rebateAmt       = my_rebate($chargeable);
    $postRebate      = max(0.0, $grossTax - $rebateAmt);
    $zakatApplied    = min($zakat, $postRebate);
    $taxBefore       = max(0.0, $postRebate - $zakatApplied);

    $chargeableAfter = max(0.0, $total - $reliefAfter);
    $postRebateAfter = max(0.0, my_tax_on($chargeableAfter) - my_rebate($chargeableAfter));
    $zakatAppliedA   = min($zakat, $postRebateAfter);
    $taxAfter        = max(0.0, $postRebateAfter - $zakatAppliedA);

    return [
        'ya' => tax_current_ya(),
        'emp_rows' => $empRows, 'emp_si' => $empSI,
        'biz_rows' => $bizRows, 'biz_si' => $bizSI, 'biz_loss' => $bizLoss,
        'rent_rows' => $rentRows, 'rent_passive' => $rentPassive, 'rent_active' => $rentActive,
        'other_rows' => $otherRows, 'other' => $other,
        'aggregate' => $aggregate, 'donations' => $donations, 'don_cap' => $donCap,
        'zakat' => $zakat, 'zakat_applied' => $zakatApplied,
        'gross_tax' => $grossTax, 'rebate' => $rebateAmt,
        'total' => $total,
        'relief_rows' => $reliefRows, 'self_relief' => $self, 'child_relief' => $child,
        'child_counts' => [
            'u18'               => (int) ($in['reliefs']['children_u18'] ?? 0),
            'tertiary'          => (int) ($in['reliefs']['children_tertiary'] ?? 0),
            'disabled_u18'      => (int) ($in['reliefs']['disabled_u18'] ?? 0),
            'disabled_tertiary' => (int) ($in['reliefs']['disabled_tertiary'] ?? 0),
        ],
        'relief_before' => $reliefBefore, 'relief_after' => $reliefAfter,
        'chargeable' => $chargeable, 'tax_payable' => $taxBefore,
        'marginal' => my_marginal_rate($chargeable),
        'eff_rate' => $total > 0 ? $taxBefore / $total : 0.0,
        'chargeable_after' => $chargeableAfter, 'tax_after' => $taxAfter,
        'saving' => max(0.0, $taxBefore - $taxAfter),
    ];
}

/** Plain-text computation brief for the AI report (verified figures). */
function tax_compute_facts(array $r, string $clientName): string
{
    $m = fn ($n) => 'RM ' . money($n);
    $s = "Year of Assessment {$r['ya']} — resident individual: {$clientName}.\n\n";

    $s .= "EMPLOYMENT (s.13):\n";
    foreach ($r['emp_rows'] as $e) {
        $s .= "- {$e['label']}: amount {$m($e['amount'])}"
            . ($e['exempt'] > 0 ? ", exempt {$m($e['exempt'])}, taxable {$m($e['taxable'])}" : '') . "\n";
    }
    $s .= "Statutory employment income: {$m($r['emp_si'])}.\n\n";

    if ($r['biz_rows']) {
        $s .= "BUSINESS (s.4(a)):\n";
        foreach ($r['biz_rows'] as $b) {
            $s .= "- {$b['name']}: gross {$m($b['gross'])}, expenses {$m($b['expenses'])}, "
                . "adjusted {$m($b['adjusted'])}, CA {$m($b['ca'])}, statutory {$m($b['statutory'])}\n";
        }
        $s .= "Statutory business income: {$m($r['biz_si'])}"
            . ($r['biz_loss'] > 0 ? "; current-year loss {$m($r['biz_loss'])}" : '') . ".\n\n";
    }
    if ($r['rent_rows']) {
        $s .= "RENTAL:\n";
        foreach ($r['rent_rows'] as $rr) {
            $s .= "- {$rr['name']} ({$rr['type']}): gross {$m($rr['gross'])}, "
                . "expenses {$m($rr['expenses'])}, net {$m($rr['net'])}\n";
        }
        $s .= "Passive rental (s.4(d)) {$m($r['rent_passive'])}; active rental (s.4(a)) {$m($r['rent_active'])}.\n\n";
    }
    if ($r['other_rows']) {
        $s .= "OTHER INCOME:\n";
        foreach ($r['other_rows'] as $o) { $s .= "- {$o['label']}: {$m($o['amount'])}\n"; }
        $s .= "\n";
    }

    $s .= "Aggregate income: {$m($r['aggregate'])}.\n"
        . ($r['donations'] > 0 ? "Less approved donations (capped 10% = {$m($r['don_cap'])}): {$m($r['donations'])}.\n" : '')
        . "Total income: {$m($r['total'])}.\n"
        . "Reliefs claimed: {$m($r['relief_before'])} (self {$m($r['self_relief'])}, child {$m($r['child_relief'])}).\n"
        . "Chargeable income: {$m($r['chargeable'])}.\n"
        . ($r['zakat_applied'] > 0 ? "Zakat rebate applied: {$m($r['zakat_applied'])} (of {$m($r['zakat'])} paid).\n" : '')
        . "Tax payable: {$m($r['tax_payable'])} (marginal " . (int) round($r['marginal'] * 100) . "%).\n";

    $room = array_values(array_filter($r['relief_rows'], fn ($x) => $x['room'] > 0));
    usort($room, fn ($a, $b) => $b['room'] <=> $a['room']);
    if ($room) {
        $s .= "\nUnused relief room: ";
        $s .= implode('; ', array_map(fn ($x) => $x['label'] . ' ' . $m($x['room']),
            array_slice($room, 0, 8)));
        $s .= ".\nIf reliefs fully utilised: chargeable {$m($r['chargeable_after'])}, "
            . "tax {$m($r['tax_after'])} (save {$m($r['saving'])}).\n";
    }
    return $s;
}
