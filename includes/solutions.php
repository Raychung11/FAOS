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
            'benefits' => [
                'See your tax now vs after — and the ringgit you could save each year',
                'Use every LHDN relief you\'re entitled to',
                'A tax-smart way to pay yourself from the company',
            ],
        ],
        'restructuring' => [
            'name' => 'Business Restructuring', 'active' => false, 'page' => null,
            'tagline' => 'Hold the right assets in the right entity.',
            'desc' => 'Holding-company and group structures to defer tax, ring-fence assets and prepare for growth or exit.',
            'benefits' => [
                'Protect personal and family assets from business risk',
                'Defer tax and prepare the group for growth or sale',
                'A cleaner structure investors and bankers trust',
            ],
        ],
        'risk' => [
            'name' => 'Risk Planning', 'active' => false, 'page' => null,
            'tagline' => 'Protect the owner, the family and the business.',
            'desc' => 'Keyman cover, buy-sell funding, guarantee protection and contingency planning.',
            'benefits' => [
                'Make sure the family is provided for, whatever happens',
                'Keep the business running if a key person is lost',
                'Cap your exposure on personal guarantees',
            ],
        ],
        'equity' => [
            'name' => 'Shareholding & Equity Planning', 'active' => false, 'page' => null,
            'tagline' => 'Get the cap table and succession right.',
            'desc' => 'Shareholder agreements, share transfers, ESOS and a funded ownership-transition plan.',
            'benefits' => [
                'A clear, fair ownership and succession plan',
                'Reward key people with equity the right way',
                'A funded buy-sell so transitions go smoothly',
            ],
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
        'report'     => (string) ($e['report'] ?? ''),
        'report_at'  => (string) ($e['report_at'] ?? ''),
        'updated_at' => (string) ($e['updated_at'] ?? ''),
        'owner'      => (string) ($e['owner'] ?? ''),
    ];
}

/** Merge-save an engagement: only the supplied fields change. */
function solution_save(int $clientId, string $key, array $in): void
{
    if (!array_key_exists($key, solution_catalog())) {
        return;
    }
    $cur = solution_get($clientId, $key);
    $g = fn (string $f, $clean) => array_key_exists($f, $in) ? $clean : $cur[$f];

    $all = solution_engagements($clientId);
    $all[$key] = [
        'status'     => array_key_exists($in['status'] ?? '', SOLUTION_STATUSES) ? $in['status'] : $cur['status'],
        'scope'      => $g('scope', trim(mb_substr((string) ($in['scope'] ?? ''), 0, 4000))),
        'est_saving' => $g('est_saving', max(0.0, (float) ($in['est_saving'] ?? 0))),
        'fee'        => $g('fee', max(0.0, (float) ($in['fee'] ?? 0))),
        'notes'      => $g('notes', trim(mb_substr((string) ($in['notes'] ?? ''), 0, 2000))),
        'report'     => $g('report', mb_substr((string) ($in['report'] ?? ''), 0, 20000)),
        'report_at'  => array_key_exists('report', $in) ? date('Y-m-d H:i') : $cur['report_at'],
        'updated_at' => date('Y-m-d H:i'),
        'owner'      => current_user()['name'] ?? $cur['owner'],
    ];
    setting_put_json('solutions:' . $clientId, $all);
}
