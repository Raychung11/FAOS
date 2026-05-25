<?php
/**
 * AdvisorOS — Advanced advisory Solutions.
 * The implementation layer the firm sells once diagnosis is done:
 * Tax planning, Business restructuring, Risk planning, Shareholding &
 * equity planning. Per-client engagements (scope, status, estimated
 * saving, fee) persist in the settings table (solutions:{clientId});
 * no schema change. Phase 1 ships Tax Planning; the rest are flagged
 * "coming soon".
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** Catalogue of advanced solutions. 'page' is set for built ones. */
function solution_catalog(): array
{
    return [
        'tax' => [
            'name' => 'Tax Planning', 'active' => true, 'page' => 'advisor/solution-tax.php',
            'tagline' => 'Keep more in the owner\'s hands — legally.',
            'desc' => 'Personal reliefs, corporate rates and a tax-efficient salary/dividend mix, turned into an implementation plan.',
        ],
        'restructuring' => [
            'name' => 'Business Restructuring', 'active' => false, 'page' => null,
            'tagline' => 'Hold the right assets in the right entity.',
            'desc' => 'Holding-company and group structures to defer tax, ring-fence assets and prepare for growth or exit.',
        ],
        'risk' => [
            'name' => 'Risk Planning', 'active' => false, 'page' => null,
            'tagline' => 'Protect the owner, the family and the business.',
            'desc' => 'Keyman cover, buy-sell funding, guarantee protection and contingency planning.',
        ],
        'equity' => [
            'name' => 'Shareholding & Equity Planning', 'active' => false, 'page' => null,
            'tagline' => 'Get the cap table and succession right.',
            'desc' => 'Shareholder agreements, share transfers, ESOS and a funded ownership-transition plan.',
        ],
    ];
}

const SOLUTION_STATUSES = [
    'none'        => ['Not started', 'b-scheduled'],
    'proposed'    => ['Proposed', 'b-warn'],
    'in_progress' => ['In progress', 'b-warn'],
    'implemented' => ['Implemented', 'b-active'],
    'declined'    => ['Declined', 'b-overdue'],
];

/** All engagements for a client, keyed by solution. */
function solution_engagements(int $clientId): array
{
    return setting_get_json('solutions:' . $clientId, []);
}

/** One engagement with defaults filled in. */
function solution_get(int $clientId, string $key): array
{
    $e = solution_engagements($clientId)[$key] ?? [];
    return [
        'status'     => array_key_exists($e['status'] ?? '', SOLUTION_STATUSES) ? $e['status'] : 'none',
        'scope'      => (string) ($e['scope'] ?? ''),
        'est_saving' => (float) ($e['est_saving'] ?? 0),
        'fee'        => (float) ($e['fee'] ?? 0),
        'notes'      => (string) ($e['notes'] ?? ''),
        'updated_at' => (string) ($e['updated_at'] ?? ''),
        'owner'      => (string) ($e['owner'] ?? ''),
    ];
}

function solution_save(int $clientId, string $key, array $in): void
{
    if (!array_key_exists($key, solution_catalog())) {
        return;
    }
    $all = solution_engagements($clientId);
    $all[$key] = [
        'status'     => array_key_exists($in['status'] ?? '', SOLUTION_STATUSES) ? $in['status'] : 'none',
        'scope'      => trim(mb_substr((string) ($in['scope'] ?? ''), 0, 4000)),
        'est_saving' => max(0.0, (float) ($in['est_saving'] ?? 0)),
        'fee'        => max(0.0, (float) ($in['fee'] ?? 0)),
        'notes'      => trim(mb_substr((string) ($in['notes'] ?? ''), 0, 2000)),
        'updated_at' => date('Y-m-d H:i'),
        'owner'      => current_user()['name'] ?? '',
    ];
    setting_put_json('solutions:' . $clientId, $all);
}
