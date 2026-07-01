<?php
/**
 * AdvisorOS — Structured Part B (Tax planning & optimisation) builder.
 *
 * Renders the deterministic, data-driven sections of the Mr-Tan style
 * report: employment planning, business structuring, rental, relief
 * maximisation, family/estate, investment & advanced, documentation
 * checklist, references. The AI's role is to add commentary inside
 * the structured headings (see tax_planb_parse_ai), not produce the
 * structure itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/tax_compute.php';

const TAX_PLANB_SECTIONS = [
    'employment'  => 'Employment Tax Planning',
    'business'    => 'Business Structuring Recommendations',
    'rental'      => 'Airbnb & Rental Planning',
    'relief'      => 'Personal Relief Maximisation',
    'family'      => 'Family, Estate & Wealth Planning',
    'investment'  => 'Investment & Advanced Tax Planning',
    'doc'         => 'Documentation Checklist',
    'refs'        => 'References',
];

/** Heuristic adviser recommendation for an employment line item. */
function tax_planb_emp_rec(string $label): string
{
    $l = mb_strtolower($label);
    return match (true) {
        str_contains($l, 'leave') || str_contains($l, 'passage') => 'Distinguish business travel from holiday. Leave-passage exemption applies only to passages provided by the employer (one overseas trip, capped per LHDN). Keep itinerary, invitation and receipts.',
        str_contains($l, 'travel') || str_contains($l, 'allowance') && str_contains($l, 'travel') => 'Cash travel allowances are fully taxable. Convert genuine business travel to accountable reimbursements with receipts.',
        str_contains($l, 'car') || str_contains($l, 'mileage') || str_contains($l, 'driver') => 'Cash allowance is fully taxable. Convert genuine business mileage, tolls and parking to accountable reimbursements with a logbook. Consider company-provided car (BIK) if commercially justified.',
        str_contains($l, 'club') || str_contains($l, 'membership') || str_contains($l, 'golf') => 'Taxable perquisite if personal. If used for client entertainment, document the business purpose; otherwise consider more tax-efficient benefits.',
        str_contains($l, 'medical') || str_contains($l, 'dental') => 'Employer-provided medical/dental to the employee may be exempt within LHDN scope — verify (PR 2/2013).',
        str_contains($l, 'childcare') => 'Employer-supported childcare may be exempt within limits — verify conditions.',
        str_contains($l, 'phone') || str_contains($l, 'broadband') || str_contains($l, 'internet') => 'Employer-paid telephone / broadband to the employee may be exempt — verify scope.',
        str_contains($l, 'bonus') || str_contains($l, 'commission') => 'Taxable in the YA received. If discretionary, consider timing across YAs.',
        str_contains($l, 'salary') => 'Standard taxable employment income — ensure EA Form reconciles.',
        default => 'Confirm taxability and any exemption available under the relevant Public Ruling.',
    };
}

/**
 * Build the structured Part B sections from the computation.
 * Returns an ordered associative array: section_key => array.
 */
function tax_planb_build(array $r, array $eng = [], array $aiCommentary = []): array
{
    $ya = (int) ($r['ya'] ?? tax_current_ya());
    $sections = [];

    /* --- §9 Employment Tax Planning -------------------------------- */
    $empRows = [];
    foreach ($r['emp_rows'] as $e) {
        $current = 'RM ' . money($e['amount'])
            . ($e['exempt'] > 0 ? ' (exempt RM ' . money($e['exempt']) . ')' : '');
        $empRows[] = ['area' => $e['label'], 'current' => $current,
                      'rec' => tax_planb_emp_rec((string) $e['label'])];
    }
    if ($empRows) {
        $sections['employment'] = [
            'title' => TAX_PLANB_SECTIONS['employment'], 'type' => 'table',
            'cols' => ['Area', 'Current position', 'Recommended planning'], 'rows' => $empRows,
        ];
    }

    /* --- §10 Business Structuring (conditional) -------------------- */
    if ($r['biz_rows']) {
        $tips = [];
        if ($r['biz_si'] >= 200000) {
            $tips[] = 'Total statutory business income is sizeable — assess incorporation (Sdn Bhd) for risk separation, SME corporate rates and structured extraction (salary + dividend).';
        } elseif ($r['biz_si'] >= 50000) {
            $tips[] = 'If profits continue to grow, evaluate incorporation when commercial benefits outweigh compliance costs.';
        }
        $tips[] = 'Maximise valid business deductions (repairs, cleaning, platform fees, advertising, utilities, accounting). Avoid private / domestic expenses.';
        $tips[] = 'Claim capital allowances on qualifying assets (Sch 3). Maintain a CA schedule per business.';
        if (count($r['biz_rows']) > 1) {
            $tips[] = 'Keep separate bank accounts and accounting records per business; do not co-mingle.';
        }
        $sections['business'] = ['title' => TAX_PLANB_SECTIONS['business'],
                                 'type' => 'bullets', 'items' => $tips];
    }

    /* --- §11 Airbnb & Rental (conditional) ------------------------- */
    if ($r['rent_rows']) {
        $tips = [];
        $has4a = false;
        foreach ($r['rent_rows'] as $rr) { if ($rr['type'] === '4a') { $has4a = true; break; } }
        if ($has4a) {
            $tips[] = 'Active rental (s.4(a)) — maintain a separate bank account and accounting records. Keep invoices for housekeeping, breakfast supplies, cleaning, linen, repairs, furniture and platform fees.';
            $tips[] = 'Check local licensing, service tax, tourism tax and council requirements if Airbnb operations scale.';
        }
        $tips[] = 'For long-term rental, retain annual statements for mortgage interest, fire insurance, assessment and quit rent. Claim repairs and maintenance — not capital improvements.';
        $tips[] = 'Distinguish pre-letting expenses (generally not deductible) from recurring rental expenses (deductible).';
        $sections['rental'] = ['title' => TAX_PLANB_SECTIONS['rental'],
                               'type' => 'bullets', 'items' => $tips];
    }

    /* --- §12 Personal Relief Maximisation -------------------------- */
    $relRows = $r['relief_rows'];
    usort($relRows, fn ($a, $b) => $b['room'] <=> $a['room']);
    $rRows = [];
    foreach ($relRows as $row) {
        $rec = $row['room'] > 0
            ? 'Room of RM ' . money($row['room']) . ' — consider top-up (subject to eligibility) before year end.'
            : 'Maximised — retain supporting documents for the claim.';
        $rRows[] = ['area' => $row['label'], 'claimed' => 'RM ' . money($row['claimed']),
                    'cap' => 'RM ' . money($row['cap']), 'rec' => $rec];
    }
    $sections['relief'] = ['title' => TAX_PLANB_SECTIONS['relief'], 'type' => 'table',
                           'cols' => ['Relief area', 'Claimed', 'Cap', 'Recommendation'], 'rows' => $rRows];

    /* --- §13 Family / Estate / Wealth ------------------------------ */
    $famTips = [];
    $cc = $r['child_counts'] ?? [];
    $hasDisabled = (($cc['disabled_u18'] ?? 0) + ($cc['disabled_tertiary'] ?? 0)) > 0;
    $hasChildren = (array_sum($cc) > 0);

    $hasAlimony = false;
    foreach ($r['relief_rows'] as $rr) {
        if (($rr['key'] ?? '') === 'spouse' && $rr['claimed'] > 0) { $hasAlimony = true; break; }
    }
    if ($hasDisabled) {
        $famTips[] = 'A disabled dependant is on file — consider a testamentary or insurance trust for long-term care, and ensure valid OKU / medical certification is retained.';
    }
    if ($hasAlimony) {
        $famTips[] = 'Alimony / spouse relief claimed — secure the relief by formalising the arrangement (court order or written agreement) and retain bank evidence of payments.';
    }
    if ($hasChildren) {
        $famTips[] = 'Review the will, guardianship and EPF/insurance nominations after material life events; ensure beneficiaries are current.';
    }
    if (!empty($r['assessment']) && ($r['assessment']['spouse_income'] > 0 || $r['assessment']['type'] === 'joint')) {
        $as = $r['assessment'];
        $famTips[] = sprintf('Assessment review — recommended election is %s (saving ≈ RM %s vs the other option). Re-test each year as incomes / reliefs change.',
            $as['recommended'], money($as['saving']));
    }
    $famTips[] = 'Review asset ownership and succession arrangements for the business and rental properties.';
    $sections['family'] = ['title' => TAX_PLANB_SECTIONS['family'],
                           'type' => 'bullets', 'items' => $famTips];

    /* --- §14 Investment & Advanced --------------------------------- */
    $invTips = [];
    if ($ya >= 2025) {
        $invTips[] = 'YA ' . $ya . ' onwards — Malaysian-source dividend income above the prescribed threshold may attract the new individual dividend tax. Review portfolio composition.';
    }
    $invTips[] = 'Use SSPN, PRS and approved retirement savings within relief caps, aligned with goals (not tax alone).';
    $invTips[] = 'Consider tax-efficient fixed-income instruments (sukuk, government securities, approved deposits where exemption applies).';
    $invTips[] = 'Avoid short-term property flipping where Real Property Gains Tax exposure arises.';
    $invTips[] = 'Income splitting only where family members genuinely work in the business and are paid market-based remuneration.';
    $invTips[] = 'Avoid artificial schemes — s.140 anti-avoidance applies where transactions lack commercial substance.';
    $sections['investment'] = ['title' => TAX_PLANB_SECTIONS['investment'],
                               'type' => 'bullets', 'items' => $invTips];

    /* --- §15 Documentation checklist (conditional items) ----------- */
    $doc = ['EA Form and employer benefit statements (employees)',
            'EPF (KWSP) annual statement'];
    if ($r['biz_rows']) {
        $doc[] = 'Business invoices, receipts, bank statements';
        $doc[] = 'Capital allowance schedule and asset invoices';
    }
    if ($r['rent_rows']) {
        $doc[] = 'Rental agreements, mortgage interest, fire insurance, assessment & quit rent statements';
    }
    foreach ($r['relief_rows'] as $rr) {
        if ($rr['claimed'] <= 0) { continue; }
        switch ($rr['key']) {
            case 'sspn':            $doc[] = 'SSPN net deposit statements'; break;
            case 'life':            $doc[] = 'Life insurance / takaful premium statements'; break;
            case 'medins':          $doc[] = 'Education / medical insurance premium statements'; break;
            case 'medical':         $doc[] = 'Receipts for serious medical / fertility / check-up'; break;
            case 'parents_medical': $doc[] = "Parents' medical bills and proof of payment"; break;
            case 'childcare':       $doc[] = 'Kindergarten / TASKA receipts and registration'; break;
            case 'spouse':          $doc[] = 'Former-spouse maintenance order / agreement and bank proof'; break;
            case 'prs':             $doc[] = 'PRS / deferred-annuity contribution statements'; break;
        }
    }
    if ($hasDisabled) { $doc[] = 'Disabled child OKU / medical certification'; }
    if (($r['zakat'] ?? 0) > 0) { $doc[] = 'Zakat receipt from authorised collection centre (PPZ / similar)'; }
    $sections['doc'] = ['title' => TAX_PLANB_SECTIONS['doc'], 'type' => 'bullets',
                        'items' => array_values(array_unique($doc))];

    /* --- §16 References ------------------------------------------- */
    $sections['refs'] = ['title' => TAX_PLANB_SECTIONS['refs'], 'type' => 'bullets', 'items' => [
        'Income Tax Act 1967 — Sections 4(a), 4(b), 4(d), 13(1), 33(1), 44(2), 140.',
        'LHDN Public Ruling on Perquisites from Employment.',
        'LHDN Public Ruling on Income from Letting of Real Property.',
        'LHDN YA ' . $ya . ' resident individual tax rates and relief schedule.',
    ]];

    /* --- Slot AI commentary into matching sections (if any) ------- */
    foreach ($sections as $k => &$sec) {
        if (!empty($aiCommentary[$k])) {
            $sec['commentary'] = trim((string) $aiCommentary[$k]);
        }
    }
    unset($sec);

    return $sections;
}

/**
 * Replace (or insert) the commentary for one section in an existing
 * plan_report blob and return the rebuilt blob in canonical section
 * order. Pure — uses the parser above.
 */
function tax_planb_merge_section(string $existing, string $key, string $text): string
{
    $key  = strtolower(trim($key));
    $secs = tax_planb_parse_ai($existing);
    $secs[$key] = trim($text);
    $out = [];
    foreach (array_keys(TAX_PLANB_SECTIONS) as $k) {
        if (isset($secs[$k])) {
            $out[] = "## SECTION: {$k}\n" . $secs[$k];
            unset($secs[$k]);
        }
    }
    foreach ($secs as $k => $v) {
        $out[] = "## SECTION: {$k}\n" . $v;
    }
    return implode("\n\n", $out);
}

/**
 * Parse AI output tagged with `## SECTION: <key>` blocks into a
 * section_key => text map. Tolerant of variants (##, ###, leading
 * numbers, optional colons).
 */
function tax_planb_parse_ai(string $text): array
{
    $keys = '(employment|business|rental|relief|family|investment|doc|refs)';
    $parts = preg_split(
        '/^[ \t]*#{0,6}[ \t]*\d*\.?[ \t]*SECTION[ \t]*:[ \t]*' . $keys . '[ \t]*:?[ \t]*$/im',
        $text, -1, PREG_SPLIT_DELIM_CAPTURE
    );
    $out = [];
    if (!$parts || count($parts) <= 1) {
        return $out;
    }
    for ($i = 1; $i < count($parts); $i += 2) {
        $key = strtolower(trim((string) $parts[$i]));
        $body = trim((string) ($parts[$i + 1] ?? ''));
        if ($body !== '') { $out[$key] = $body; }
    }
    return $out;
}
