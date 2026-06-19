<?php
/**
 * AdvisorOS — Business Valuation demo seeder.
 *
 * Populates the demo tenant with valuation-module reference data so the
 * advisor UI, PDF report and client portal all render a worked example
 * immediately. Idempotent: detects each artefact by its settings key /
 * NRIC and refreshes the value rather than inserting duplicates.
 *
 * Seeds:
 *   • 5 active Valuation Knowledge Base entries (tenant-scoped)
 *   • Slice C valuation assumptions for Sarah Lim's company
 *     (DLOM, minority, adjustments, DCF method + multi-year inputs)
 *   • 2 dated valuation snapshots (history view)
 *   • Persisted AI valuation commentary for Sarah's portal
 *   • A second company + business-financial snapshot + assumptions for
 *     Mr Tan (case-study client) so his portal also shows a valuation
 *
 * CLI:  php setup/valuation_seed.php
 * Web:  /setup/valuation_seed.php   (Super Admin only)
 *
 * Re-run safely any time; existing rows are upserted, not duplicated.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/valuation.php';
require_once __DIR__ . '/../includes/valuation_kb.php';

$isCli = PHP_SAPI === 'cli';
$out = static function (string $l) use ($isCli): void {
    echo $isCli ? $l . "\n" : nl2br(htmlspecialchars($l, ENT_QUOTES)) . "<br>\n";
};
if (!$isCli) {
    require_login();
    require_permission('platform.manage');
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font-family:monospace;background:#0B1F3A;color:#cdd6e4;padding:20px">';
}

try {
    $pdo = db();

    // --- Resolve demo tenant + users -----------------------------
    $tenantId = (int) $pdo->query(
        "SELECT id FROM tenants WHERE slug='demo-advisory' AND deleted_at IS NULL"
    )->fetchColumn();
    if (!$tenantId) {
        throw new RuntimeException(
            'Demo tenant not found. Run setup/install.php then setup/demo_seed.php first.'
        );
    }

    $userId = static function (string $email) use ($pdo): ?int {
        $s = $pdo->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL');
        $s->execute([$email]);
        $v = $s->fetchColumn();
        return $v ? (int) $v : null;
    };
    $adminId   = $userId('admin@demo-advisory.test');
    $advisorId = $userId('advisor@demo-advisory.test');
    $creator   = $adminId ?: ($advisorId ?: 0);

    // Required: tenant context for the settings helpers below.
    // valuation_kb_* + setting_put_json all call current_tenant_id(),
    // which is populated from the logged-in user's tenant. In CLI we
    // fake it by stuffing $_ENV / $GLOBALS so the helpers work.
    $GLOBALS['__seed_tenant_id'] = $tenantId;

    // setting_put_json scopes by tenant — emulate it directly with a
    // shared upsert so the seeder works in CLI without a session.
    $up = $pdo->prepare(
        'INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    // =============================================================
    // 1. Valuation Knowledge Base — 5 active entries
    // =============================================================
    $kb = [
        [
            'id' => 1, 'active' => true,
            'category' => 'Methodology',
            'title'    => 'House methodology — three-method weighted blend',
            'body'     => "We always present three transparent methods (NAV, EBITDA × multiple "
                . "less net debt, DCF) and a weighted indicative range. Default weights for an "
                . "owner-managed SME are NAV 20% / EBITDA 50% / DCF 30%; shift toward NAV for "
                . "asset-heavy businesses and toward DCF for steady cash generators with low capex.\n\n"
                . "The weighted-mid is for discussion only; the range (min–max of the three methods) "
                . "anchors the conversation about how sensitive the answer is to method choice.",
        ],
        [
            'id' => 2, 'active' => true,
            'category' => 'Comparables',
            'title'    => 'SME multiple calibration — Malaysia',
            'body'     => "Illustrative EBITDA bands we have seen in private SME transactions in "
                . "Malaysia (use as a starting point — calibrate against the specific deal):\n"
                . "• Wholesale & distribution: 3–5×\n"
                . "• Professional services: 4–6×\n"
                . "• Manufacturing: 3–6×\n"
                . "• F&B (chain, profitable): 3–5×\n"
                . "• Healthcare / clinics: 5–10×\n"
                . "• Tech / SaaS (recurring revenue): 6–12× ARR or 8–15× EBITDA\n\n"
                . "Discount the upper band for: customer concentration above 25%, founder-dependent "
                . "rainmaker, no audited accounts, undocumented related-party flows.",
        ],
        [
            'id' => 3, 'active' => true,
            'category' => 'Adjustments',
            'title'    => 'Normalised EBITDA — standard add-back checklist',
            'body'     => "Always normalise EBITDA before applying a multiple or feeding the DCF. "
                . "Standard add-backs to consider:\n"
                . "• Owner remuneration above arms-length market rate (+)\n"
                . "• Family payroll without commercial role (+)\n"
                . "• Related-party rent / management fees below market (+ to bring to market)\n"
                . "• One-off litigation, restructuring or settlement costs (+)\n"
                . "• Pandemic-era grants / wage subsidies (−)\n"
                . "• Below-the-line owner expenses (vehicles, travel, club memberships) — only if "
                . "the buyer will not bear them (+)\n\n"
                . "Each add-back needs a documentary basis (industry pay survey, lease comparable, "
                . "invoice). Never net adjustments in a single \"normalisation\" line — itemise.",
        ],
        [
            'id' => 4, 'active' => true,
            'category' => 'Discounts',
            'title'    => 'Discount stack — key-person × DLOM × minority',
            'body'     => "We apply three discounts sequentially, not additively:\n"
                . "combined = 1 − (1 − key-person)(1 − DLOM)(1 − minority).\n\n"
                . "Key-person (5–35%): driven by the worst-band Enterprise Risk Diagnostic score. "
                . "Use the lower end when the founder has a credible #2 and a documented playbook.\n\n"
                . "DLOM (15–35%): private-company marketability. Default 25%. Move toward 15% when "
                . "there is a credible buyer pipeline; toward 35% for unaudited accounts or "
                . "first-generation ownership.\n\n"
                . "Minority (0–25%): apply only when the interest is non-controlling AND lacks "
                . "drag-along, tag-along or put rights. Apply 0% for a controlling stake.",
        ],
        [
            'id' => 5, 'active' => true,
            'category' => 'House style',
            'title'    => 'Commentary house style — what to say (and not say)',
            'body'     => "When writing valuation commentary:\n"
                . "• Never restate the ringgit figures from the tables — the system renders them.\n"
                . "• Explain WHY the result is what it is (industry tailwind, customer concentration, "
                . "founder dependence, etc.).\n"
                . "• Always flag the top three sensitivities (usually multiple, discount rate, and DLOM).\n"
                . "• Frame as ranges, not point estimates.\n"
                . "• Use the phrase \"indicative estimate for advisory discussion\" — never "
                . "\"fair market value\", \"appraisal\" or \"opinion of value\".\n"
                . "• Close with a reminder that a binding valuation needs a licensed valuer.",
        ],
    ];
    foreach ($kb as &$e) {
        $e['author']     = 'Demo Advisor';
        $e['updated_at'] = date('Y-m-d H:i');
    }
    unset($e);
    $up->execute([$tenantId, 'valuation_kb', json_encode($kb)]);
    $out(' Seeded Valuation Knowledge Base (5 active entries).');

    // =============================================================
    // 2. Sarah Lim's company — Slice C assumptions + snapshots + AI
    // =============================================================
    $sarahId = (int) $pdo->query(
        "SELECT id FROM clients WHERE tenant_id={$tenantId}
           AND nric_passport='DEMO-CLIENT-001'"
    )->fetchColumn();
    if (!$sarahId) {
        $out(' Sarah Lim (demo client) not found — run setup/demo_seed.php first.');
    } else {
        $coStmt = $pdo->prepare(
            'SELECT id FROM companies WHERE client_id=? AND name=? AND deleted_at IS NULL LIMIT 1'
        );
        $coStmt->execute([$sarahId, 'Lim Trading Sdn Bhd']);
        $limCoId = (int) $coStmt->fetchColumn();
        if (!$limCoId) {
            $out(' Lim Trading Sdn Bhd not found — run setup/demo_seed.php first.');
        } else {
            // Full Slice C valuation assumptions: multi-year DCF + adjustments
            // + DLOM + minority. Sarah owns 70% → controlling, so minority = 0.
            $assu = [
                'multiple'      => 4.5,   // wholesale band, mid-to-upper
                'discount'      => 18.0,
                'growth'        => 3.5,
                'weight_nav'    => 15,
                'weight_ebitda' => 55,
                'weight_dcf'    => 30,
                'dlom_pct'      => 25,    // private SME
                'minority_pct'  => 0,     // 70% stake = controlling
                'dcf_method'    => 'multiyear',
                'mdcf_years'    => 5,
                'mdcf_revenue'  => 3200000,
                'mdcf_growth'   => 6,
                'mdcf_margin'   => 17,
                'mdcf_tax_rate' => 24,
                'mdcf_capex'    => 3,
                'mdcf_wc'       => 1,
                'mdcf_terminal_growth' => 3,
                'adjustments'   => [
                    ['label' => 'Founder remuneration above market', 'amount' => 90000],
                    ['label' => 'Related-party rent below market',   'amount' => -24000],
                    ['label' => 'One-off legal settlement (FY-1)',   'amount' => 45000],
                ],
            ];
            $up->execute([$tenantId, 'val:' . $limCoId, json_encode($assu)]);
            $out(" Seeded valuation assumptions for company #{$limCoId} (Lim Trading).");

            // Dated snapshot history — two prior snapshots for the trend table.
            $hist = [
                [
                    'date' => date('Y-m-d', strtotime('-9 months')),
                    'time' => '10:14',
                    'ya'   => 2024,
                    'assumptions' => [
                        'multiple' => 4.0, 'discount' => 18.0, 'growth' => 3.0,
                        'weight_nav' => 20, 'weight_ebitda' => 50, 'weight_dcf' => 30,
                    ],
                    'summary' => [
                        'nav' => 1300000, 'ebitda_equity' => 1400000, 'dcf_equity' => 2150000,
                        'low' => 975000, 'mid' => 1395000, 'high' => 1612500,
                        'client_stake' => 976500,
                        'discount_applied' => 0.4375,
                        'discount_keyperson' => 0.25,
                        'discount_dlom' => 0.25,
                        'discount_minority' => 0.0,
                        'normalised_ebitda' => 560000,
                        'adjustments_total' => 0,
                        'dcf_method' => 'gordon',
                        'ownership_pct' => 70.0,
                    ],
                    'saved_by' => 'Demo Advisor',
                ],
                [
                    'date' => date('Y-m-d', strtotime('-3 months')),
                    'time' => '15:42',
                    'ya'   => 2024,
                    'assumptions' => [
                        'multiple' => 4.5, 'discount' => 18.0, 'growth' => 3.5,
                        'weight_nav' => 15, 'weight_ebitda' => 55, 'weight_dcf' => 30,
                    ],
                    'summary' => [
                        'nav' => 1300000, 'ebitda_equity' => 2179500, 'dcf_equity' => 2880000,
                        'low' => 731250, 'mid' => 1268437, 'high' => 1620000,
                        'client_stake' => 887906,
                        'discount_applied' => 0.4375,
                        'discount_keyperson' => 0.25,
                        'discount_dlom' => 0.25,
                        'discount_minority' => 0.0,
                        'normalised_ebitda' => 671000,
                        'adjustments_total' => 111000,
                        'dcf_method' => 'multiyear',
                        'ownership_pct' => 70.0,
                    ],
                    'saved_by' => 'Demo Advisor',
                ],
            ];
            $up->execute([$tenantId, 'val_history:' . $limCoId, json_encode($hist)]);
            $out(' Seeded 2 dated valuation snapshots (history view).');

            // Persisted AI commentary so the panel renders immediately.
            $commentary = "## SECTION: methodology\n"
                . "Lim Trading is a steady-state wholesale business with reliable margins, so the "
                . "blend leans on the EBITDA-multiple method (55%) anchored to a 4.5× distribution-"
                . "sector comparable. NAV (15%) gives an asset-backed floor; the 5-year explicit DCF "
                . "(30%) tests whether the multiple is consistent with a discounted view of future "
                . "cash. The three methods cluster tightly, which is reassuring.\n\n"
                . "## SECTION: ebitda\n"
                . "The normalisation worksheet adds back founder remuneration above market, "
                . "subtracts a related-party rent that is below market, and adds back a one-off "
                . "legal settlement. The resulting sustainable EBITDA is roughly 20% above reported, "
                . "which is in line with what we typically see for a founder-managed SME. Confirm "
                . "each add-back has documentary support before a buyer would accept it.\n\n"
                . "## SECTION: dcf\n"
                . "The multi-year DCF assumes 6% revenue growth tapering to a 3% perpetual, a 17% "
                . "EBITDA margin, and 3% capex / 1% Δwc. Sensitivity to the discount rate is the "
                . "biggest single driver — every 1pp on the discount rate moves the indicative mid "
                . "by roughly RM 80–100k. Stress-test before relying on the figure.\n\n"
                . "## SECTION: discounts\n"
                . "Key-person discount is 25% (medium-risk diagnostic — founder remains operationally "
                . "central). DLOM is 25% for a privately held, unaudited SME. Minority is 0% — Sarah's "
                . "70% stake is controlling. The sequential combination is 43.75%, which is "
                . "appropriate for an owner-managed wholesaler with no near-term exit pipeline.\n\n"
                . "## SECTION: caveats\n"
                . "This is an indicative estimate for advisory discussion only — not a formal/"
                . "independent valuation, audit or fairness opinion. The figure is meaningfully "
                . "sensitive to the EBITDA multiple, the discount rate and the DLOM assumption. "
                . "Any transaction or binding use should engage a licensed valuer.";
            $up->execute([$tenantId, 'valuation_ai:' . $sarahId, json_encode([
                'text'       => $commentary,
                'model'      => 'stub',
                'tokens'     => null,
                'stubbed'    => true,
                'updated_at' => date('Y-m-d H:i'),
                'updated_by' => 'Demo Advisor',
            ])]);
            $out(' Seeded AI valuation commentary for Sarah Lim.');
        }
    }

    // =============================================================
    // 3. Mr Tan (case study) — second company + financial snapshot
    //    + Slice C valuation assumptions, so his portal also shows
    //    a worked valuation example.
    // =============================================================
    $tanId = (int) $pdo->query(
        "SELECT id FROM clients WHERE tenant_id={$tenantId}
           AND nric_passport='DEMO-CLIENT-TAN'"
    )->fetchColumn();
    if (!$tanId) {
        $out(' Mr Tan (case-study client) not found — run setup/demo_seed.php first.');
    } else {
        $coStmt = $pdo->prepare(
            'SELECT id FROM companies WHERE client_id=? AND name=? AND deleted_at IS NULL LIMIT 1'
        );
        $coStmt->execute([$tanId, 'DIY Laundry Sdn Bhd']);
        $tanCoId = (int) $coStmt->fetchColumn();
        if (!$tanCoId) {
            $pdo->prepare(
                'INSERT INTO companies
                  (tenant_id,client_id,name,registration_no,entity_type,industry,
                   incorporation_date,ownership_pct,status,notes,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$tenantId, $tanId, 'DIY Laundry Sdn Bhd', '201801098765',
                'sdn_bhd', 'Logistics & transport', '2018-06-01', 100.00,
                'active', 'Self-service laundromat chain (3 outlets).', $creator]);
            $tanCoId = (int) $pdo->lastInsertId();
            $out(" Seeded company DIY Laundry Sdn Bhd (#{$tanCoId}) for Mr Tan.");
        }

        // Upsert business-financials snapshot — keyed by company + date.
        $hasBf = $pdo->prepare(
            'SELECT id FROM business_financials WHERE company_id=? AND snapshot_date=? LIMIT 1'
        );
        $today = date('Y-m-d');
        $hasBf->execute([$tanCoId, $today]);
        if (!(int) $hasBf->fetchColumn()) {
            $pdo->prepare(
                'INSERT INTO business_financials
                  (tenant_id,company_id,snapshot_date,revenue,ebitda,net_profit,
                   total_assets,total_liabilities,receivables,inventory,cash,
                   bank_loans,shareholder_loans,personal_guarantee,owner_remuneration,
                   dividends_paid,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$tenantId, $tanCoId, $today,
                900000,  // revenue
                225000,  // ebitda
                132000,  // net_profit
                1450000, // total_assets (machines, deposits, fit-out)
                620000,  // total_liabilities
                40000,   // receivables (mostly cash business)
                15000,   // inventory (detergents)
                180000,  // cash
                380000,  // bank_loans
                90000,   // shareholder_loans
                500000,  // personal_guarantee
                60000,   // owner_remuneration
                24000,   // dividends_paid
                $creator,
            ]);
            $out(' Seeded business-financial snapshot for DIY Laundry (today).');
        }

        // Slice C assumptions — Mr Tan is sole owner so minority = 0.
        // Asset-heavy laundromat business → NAV weighted higher.
        $tanAssu = [
            'multiple'      => 4.0,
            'discount'      => 20.0,
            'growth'        => 4.0,
            'weight_nav'    => 35,
            'weight_ebitda' => 45,
            'weight_dcf'    => 20,
            'dlom_pct'      => 30,    // single-outlet operator, harder to sell
            'minority_pct'  => 0,     // 100% owner
            'dcf_method'    => 'gordon',
            'mdcf_years'    => 5,
            'mdcf_revenue'  => 0,
            'mdcf_growth'   => 4,
            'mdcf_margin'   => 25,
            'mdcf_tax_rate' => 24,
            'mdcf_capex'    => 6,     // capex-heavy: machines wear out
            'mdcf_wc'       => 0,
            'mdcf_terminal_growth' => 2,
            'adjustments'   => [
                ['label' => 'Owner pay above market',           'amount' => 24000],
                ['label' => 'One-off store-fit refurbishment',   'amount' => 18000],
            ],
        ];
        $up->execute([$tenantId, 'val:' . $tanCoId, json_encode($tanAssu)]);
        $out(" Seeded valuation assumptions for DIY Laundry (#{$tanCoId}).");
    }

    $out('Done. Valuation seed complete — open Advisor → Clients → Valuation '
        . 'or the client portal to see the worked example.');
    if (!$isCli) { echo '</pre>'; }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    $out('FAILED: ' . $e->getMessage());
    if (!$isCli) { echo '</pre>'; }
    exit(1);
}
