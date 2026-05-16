<?php
/**
 * AdvisorOS — Loan / amortisation maths (pure functions, no I/O).
 * Standard reducing-balance (annuity) model used by the borrowing
 * capacity & leverage analysis. All money values are plain floats.
 */

declare(strict_types=1);

/** Monthly instalment for a principal at an annual % rate over years. */
function loan_monthly_payment(float $principal, float $annualRatePct, float $years): float
{
    $n = max(1.0, $years * 12);
    if ($principal <= 0) {
        return 0.0;
    }
    $r = $annualRatePct / 100 / 12;
    if ($r <= 0) {
        return $principal / $n;
    }
    return $principal * $r / (1 - (1 + $r) ** (-$n));
}

/** Principal supportable by a given monthly instalment (present value). */
function loan_principal_from_payment(float $monthly, float $annualRatePct, float $years): float
{
    $n = max(1.0, $years * 12);
    if ($monthly <= 0) {
        return 0.0;
    }
    $r = $annualRatePct / 100 / 12;
    if ($r <= 0) {
        return $monthly * $n;
    }
    return $monthly * (1 - (1 + $r) ** (-$n)) / $r;
}

/** Total interest paid over the full tenure for a principal. */
function loan_total_interest(float $principal, float $annualRatePct, float $years): float
{
    if ($principal <= 0) {
        return 0.0;
    }
    $n = max(1.0, $years * 12);
    return max(0.0, loan_monthly_payment($principal, $annualRatePct, $years) * $n - $principal);
}
