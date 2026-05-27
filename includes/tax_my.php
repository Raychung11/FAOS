<?php
/**
 * AdvisorOS — Malaysian income tax reference (resident individual + SME).
 *
 * Rates, rebate, reliefs and corporate bands are DATA, versioned by
 * assessment year. They are read from the platform "tax_rates" setting
 * (editable by a Super Admin under Platform → Tax Rates); the built-in
 * defaults below are used when nothing is configured, so behaviour is
 * unchanged out of the box. Estimates only — never tax advice.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** Built-in fallback configuration (current law as shipped, YA2024). */
function tax_defaults(): array
{
    return [
        'current_ya' => 2024,
        'years' => [
            '2024' => [
                'personal_bands' => [
                    [0, 5000, 0.00], [5000, 20000, 0.01], [20000, 35000, 0.03],
                    [35000, 50000, 0.06], [50000, 70000, 0.11], [70000, 100000, 0.19],
                    [100000, 400000, 0.25], [400000, 600000, 0.26],
                    [600000, 2000000, 0.28], [2000000, null, 0.30],
                ],
                'rebate_threshold' => 35000,
                'rebate_amount'    => 400,
                'self_relief'      => 9000,
                'child_relief'     => 2000,
                'child_tertiary_relief' => 8000,
                'disabled_child_relief' => 8000,
                'disabled_child_tertiary_relief' => 16000,
                'corp_sme_bands'   => [[0, 150000, 0.15], [150000, 600000, 0.17], [600000, null, 0.24]],
                'corp_flat'        => 0.24,
                'reliefs' => [
                    'epf'             => ['label' => 'EPF / approved provident fund', 'cap' => 4000, 'group' => 'Retirement & insurance'],
                    'life'            => ['label' => 'Life insurance & takaful', 'cap' => 3000, 'group' => 'Retirement & insurance'],
                    'prs'             => ['label' => 'Deferred annuity & PRS', 'cap' => 3000, 'group' => 'Retirement & insurance'],
                    'medins'          => ['label' => 'Education & medical insurance', 'cap' => 4000, 'group' => 'Retirement & insurance'],
                    'socso'           => ['label' => 'SOCSO / EIS contribution', 'cap' => 350, 'group' => 'Retirement & insurance'],
                    'medical'         => ['label' => 'Serious illness, fertility, vaccination & check-up', 'cap' => 10000, 'group' => 'Medical'],
                    'parents_medical' => ['label' => 'Parents — medical, special needs & carer', 'cap' => 8000, 'group' => 'Medical'],
                    'disabled_equip'  => ['label' => 'Basic supporting equipment (disabled)', 'cap' => 6000, 'group' => 'Medical'],
                    'disabled_self'   => ['label' => 'Disabled individual (self)', 'cap' => 6000, 'group' => 'Medical'],
                    'lifestyle'       => ['label' => 'Lifestyle — books, devices, internet, courses', 'cap' => 2500, 'group' => 'Lifestyle & education'],
                    'lifestyle_sport' => ['label' => 'Sports equipment, facilities & training', 'cap' => 1000, 'group' => 'Lifestyle & education'],
                    'education_self'  => ['label' => 'Education fees (self, incl. upskilling)', 'cap' => 7000, 'group' => 'Lifestyle & education'],
                    'ev_charging'     => ['label' => 'EV charging facilities', 'cap' => 2500, 'group' => 'Lifestyle & education'],
                    'spouse'          => ['label' => 'Spouse / alimony (spouse no income)', 'cap' => 4000, 'group' => 'Family'],
                    'childcare'       => ['label' => 'Childcare / kindergarten fees (child ≤6)', 'cap' => 3000, 'group' => 'Family'],
                    'breastfeed'      => ['label' => 'Breastfeeding equipment (child ≤2)', 'cap' => 1000, 'group' => 'Family'],
                    'sspn'            => ['label' => 'SSPN net deposit (education savings)', 'cap' => 8000, 'group' => 'Family'],
                ],
            ],
        ],
    ];
}

/** Full tax configuration (platform setting, else defaults). Cached per request. */
function tax_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $stored = [];
    if (function_exists('platform_setting_get_json') && function_exists('db')) {
        try {
            $stored = platform_setting_get_json('tax_rates', []);
        } catch (Throwable $e) {
            error_log('[AdvisorOS] tax_config read failed: ' . $e->getMessage());
        }
    }
    $cfg = (is_array($stored) && !empty($stored['years'])) ? $stored : tax_defaults();
    return $cfg;
}

function tax_current_ya(): int
{
    return (int) (tax_config()['current_ya'] ?? 2024);
}

/** The active assessment-year block (falls back to defaults). */
function tax_year(?int $ya = null): array
{
    $cfg = tax_config();
    $ya  = (string) ($ya ?? tax_current_ya());
    if (!empty($cfg['years'][$ya])) {
        return $cfg['years'][$ya];
    }
    $d = tax_defaults();
    return $cfg['years'][array_key_first($cfg['years'])]
        ?? $d['years'][(string) $d['current_ya']];
}

/** [lower, upper(|null=∞), rate] — resident individual. */
function my_tax_bands(): array
{
    return tax_year()['personal_bands'] ?? tax_defaults()['years']['2024']['personal_bands'];
}

/** Gross tax on a chargeable income (before rebate). */
function my_tax_on(float $chargeable): float
{
    if ($chargeable <= 0) {
        return 0.0;
    }
    $tax = 0.0;
    foreach (my_tax_bands() as [$lo, $hi, $rate]) {
        $hi = $hi ?? PHP_FLOAT_MAX;
        if ($chargeable <= $lo) {
            break;
        }
        $tax += (min($chargeable, $hi) - $lo) * $rate;
    }
    return $tax;
}

/** Marginal rate at a given chargeable income. */
function my_marginal_rate(float $chargeable): float
{
    if ($chargeable <= 0) {
        return 0.0;
    }
    $last = 0.30;
    foreach (my_tax_bands() as [$lo, $hi, $rate]) {
        $hi = $hi ?? PHP_FLOAT_MAX;
        $last = $rate;
        if ($chargeable > $lo && $chargeable <= $hi) {
            return $rate;
        }
    }
    return $last;
}

/** Rebate for low chargeable income. */
function my_rebate(float $chargeable): float
{
    $y = tax_year();
    return $chargeable > 0 && $chargeable <= (float) ($y['rebate_threshold'] ?? 35000)
        ? (float) ($y['rebate_amount'] ?? 400) : 0.0;
}

function my_self_relief(): float
{
    return (float) (tax_year()['self_relief'] ?? 9000);
}

function my_child_relief(): float
{
    return (float) (tax_year()['child_relief'] ?? 2000);
}

function my_child_tertiary_relief(): float
{
    return (float) (tax_year()['child_tertiary_relief'] ?? 8000);
}

function my_disabled_child_relief(): float
{
    return (float) (tax_year()['disabled_child_relief'] ?? 8000);
}

function my_disabled_child_tertiary_relief(): float
{
    return (float) (tax_year()['disabled_child_tertiary_relief'] ?? 16000);
}

/** Relief catalogue: key => [label, cap, group]. */
function my_relief_catalogue(): array
{
    $out = [];
    foreach (tax_year()['reliefs'] ?? [] as $k => $r) {
        $out[$k] = [(string) ($r['label'] ?? $k), (float) ($r['cap'] ?? 0),
                    (string) ($r['group'] ?? 'Other')];
    }
    return $out;
}
