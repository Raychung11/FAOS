<?php
/**
 * AdvisorOS — Malaysian resident individual income tax (pure, no I/O).
 * Progressive band schedule effective YA 2023 onwards. Estimates only;
 * LHDN rules and reliefs change yearly — never tax advice.
 */

declare(strict_types=1);

/** [lower, upper, rate] — resident individual, YA2023+. */
function my_tax_bands(): array
{
    return [
        [0,        5000,    0.00],
        [5000,     20000,   0.01],
        [20000,    35000,   0.03],
        [35000,    50000,   0.06],
        [50000,    70000,   0.11],
        [70000,    100000,  0.19],
        [100000,   400000,  0.25],
        [400000,   600000,  0.26],
        [600000,   2000000, 0.28],
        [2000000,  PHP_FLOAT_MAX, 0.30],
    ];
}

/** Gross tax on a chargeable income (before rebate). */
function my_tax_on(float $chargeable): float
{
    $tax = 0.0;
    if ($chargeable <= 0) {
        return 0.0;
    }
    foreach (my_tax_bands() as [$lo, $hi, $rate]) {
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
    foreach (my_tax_bands() as [$lo, $hi, $rate]) {
        if ($chargeable > $lo && $chargeable <= $hi) {
            return $rate;
        }
    }
    return 0.30;
}

/** Rebate for low chargeable income (individual, YA2023+). */
function my_rebate(float $chargeable): float
{
    return $chargeable > 0 && $chargeable <= 35000 ? 400.0 : 0.0;
}

/** Editable relief catalogue: key => [label, statutory cap]. */
function my_relief_catalogue(): array
{
    // [label, cap, group] — Malaysian resident-individual reliefs (YA2024).
    return [
        'epf'             => ['EPF / approved provident fund', 4000, 'Retirement & insurance'],
        'life'            => ['Life insurance & takaful', 3000, 'Retirement & insurance'],
        'prs'             => ['Deferred annuity & PRS', 3000, 'Retirement & insurance'],
        'medins'          => ['Education & medical insurance', 3000, 'Retirement & insurance'],
        'socso'           => ['SOCSO / EIS contribution', 350, 'Retirement & insurance'],
        'medical'         => ['Serious illness, fertility, vaccination & check-up', 10000, 'Medical'],
        'parents_medical' => ['Parents — medical, special needs & carer', 8000, 'Medical'],
        'disabled_equip'  => ['Basic supporting equipment (disabled)', 6000, 'Medical'],
        'disabled_self'   => ['Disabled individual (self)', 6000, 'Medical'],
        'lifestyle'       => ['Lifestyle — books, devices, internet, courses', 2500, 'Lifestyle & education'],
        'lifestyle_sport' => ['Sports equipment, facilities & training', 1000, 'Lifestyle & education'],
        'education_self'  => ['Education fees (self, incl. upskilling)', 7000, 'Lifestyle & education'],
        'ev_charging'     => ['EV charging facilities', 2500, 'Lifestyle & education'],
        'spouse'          => ['Spouse / alimony (spouse no income)', 4000, 'Family'],
        'childcare'       => ['Childcare / kindergarten fees (child ≤6)', 3000, 'Family'],
        'breastfeed'      => ['Breastfeeding equipment (child ≤2)', 1000, 'Family'],
        'sspn'            => ['SSPN net deposit (education savings)', 8000, 'Family'],
    ];
}

/** Automatic self & dependent-relatives relief. */
const MY_SELF_RELIEF = 9000.0;
/** Child relief — per child under 18. */
const MY_CHILD_RELIEF = 2000.0;
/** Child relief — per unmarried child 18+ in full-time tertiary study. */
const MY_CHILD_TERTIARY_RELIEF = 8000.0;
