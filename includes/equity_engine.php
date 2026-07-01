<?php
/**
 * AdvisorOS — Equity Structure Risk Assessment engine (Module I).
 *
 * Digital version of the 股权架构评估框架 / Equity Structure Risk
 * Assessment Framework for Malaysian SMEs — 50 indicators across 6
 * categories, each scored 0-5 with optional notes, rolled up into a
 * weighted 0-100 composite score with a Low/Moderate/High/Critical
 * risk band. Also captures a 13-item due-diligence checklist and
 * auto-detects a 10-item red-flag list.
 *
 * Persistence: tenant-scoped JSON at settings key `equity:{companyId}`.
 * No schema change.
 *
 * Framework reference: the assessment mirrors the source framework
 * document ("Equity Structure Risk Assessment Framework — SME") —
 * PDF cat A→F correspond to weighted buckets 1→6 respectively.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const EQUITY_KEY_PREFIX = 'equity:';

/** Category weights (sum = 100). */
function equity_category_weights(): array
{
    return ['A' => 20, 'B' => 20, 'C' => 15, 'D' => 15, 'E' => 15, 'F' => 15];
}

/** Category display metadata. */
function equity_categories(): array
{
    return [
        'A' => ['label' => 'Shareholder identity & equity clarity',
                'label_zh' => '股权清晰度',
                'blurb' => 'Who legally owns the company, who beneficially owns it, and are those consistent?'],
        'B' => ['label' => 'Control-structure stability',
                'label_zh' => '控制权稳定性',
                'blurb' => 'Who actually controls decisions — voting, board, veto rights, deadlock risk.'],
        'C' => ['label' => 'Role, contribution & equity fit',
                'label_zh' => '角色与贡献匹配',
                'blurb' => 'Does equity match who creates value — CEO, tech owner, capital, operator?'],
        'D' => ['label' => 'Shareholder agreement & exit',
                'label_zh' => '股东协议完整性',
                'blurb' => 'Is the SHA complete? ROFR, drag/tag, buy-sell, deadlock, leaver clauses.'],
        'E' => ['label' => 'Compliance & regulation',
                'label_zh' => '合规与监管',
                'blurb' => 'Foreign equity limits, Bumiputera, licensing, SSM BO, KYC, work permits.'],
        'F' => ['label' => 'Intangibles & IP ownership',
                'label_zh' => '无形资产与技术所有权',
                'blurb' => 'Is the brand, code, patents, customer data actually owned by the company?'],
    ];
}

/**
 * The 50 assessment indicators.
 * @return array<int,array{id:int,cat:string,label:string,label_zh:string,focus:string,risk:string}>
 */
function equity_indicators(): array
{
    return [
        // ============ A — Shareholder identity & equity clarity (10)
        ['id' => 1,  'cat' => 'A', 'label' => 'Number of shareholders',
         'label_zh' => '股东人数', 'focus' => '1 / 2 / 3-5 / 6+',
         'risk' => 'More shareholders → slower decisions and signature complexity.'],
        ['id' => 2,  'cat' => 'A', 'label' => 'Shareholder type mix',
         'label_zh' => '股东类别', 'focus' => 'Individual / company / trust / VC / family',
         'risk' => 'Different types have different goals — leads to conflict.'],
        ['id' => 3,  'cat' => 'A', 'label' => 'Shareholder nationality',
         'label_zh' => '股东国籍', 'focus' => 'Malaysian / PR / foreign',
         'risk' => 'Foreign ownership triggers KYC, sector, tax and visa concerns.'],
        ['id' => 4,  'cat' => 'A', 'label' => 'Religious identity (Muslim / Non-Muslim)',
         'label_zh' => '股东宗教身份', 'focus' => 'Muslim shareholders present?',
         'risk' => 'Muslim shareholders → faraid / hibah / wasiat succession applies.'],
        ['id' => 5,  'cat' => 'A', 'label' => 'Bumiputera shareholding',
         'label_zh' => 'Bumiputera 股东', 'focus' => 'Any Bumiputera equity',
         'risk' => 'Affects government contracts, licences, and supplier eligibility.'],
        ['id' => 6,  'cat' => 'A', 'label' => 'Proxy / nominee shareholders',
         'label_zh' => 'Proxy / Nominee 股东', 'focus' => 'Any nominee arrangements?',
         'risk' => 'Unclear beneficial ownership → KYC, financing and M&A risk.'],
        ['id' => 7,  'cat' => 'A', 'label' => 'Ultimate Beneficial Owner clarity',
         'label_zh' => '实益拥有人', 'focus' => 'Is UBO clearly documented?',
         'risk' => 'Legal owner ≠ BO must be disclosed under SSM BO regime.'],
        ['id' => 8,  'cat' => 'A', 'label' => 'Shareholder record completeness',
         'label_zh' => '股东资料完整性', 'focus' => 'IC / passport / tax ID / address',
         'risk' => 'Incomplete records slow every due-diligence exercise.'],
        ['id' => 9,  'cat' => 'A', 'label' => 'Shareholder credit standing',
         'label_zh' => '股东信用状况', 'focus' => 'CTOS / CCRIS / bankruptcy search',
         'risk' => 'Personal credit issues affect bank financing and investor trust.'],
        ['id' => 10, 'cat' => 'A', 'label' => 'Active vs passive shareholders',
         'label_zh' => '股东是否活跃', 'focus' => 'Who actively runs vs passive holders',
         'risk' => 'Passive shareholders can still hold veto rights.'],

        // ============ B — Control-structure stability (10)
        ['id' => 11, 'cat' => 'B', 'label' => 'Largest shareholder %',
         'label_zh' => '最大股东持股比例', 'focus' => '>50% / >75% / <50%',
         'risk' => 'Excessive concentration can alienate minorities.'],
        ['id' => 12, 'cat' => 'B', 'label' => 'Controlling shareholder identity',
         'label_zh' => '是否有控股股东', 'focus' => 'Who has de facto control',
         'risk' => 'Unclear control blocks financing & M&A.'],
        ['id' => 13, 'cat' => 'B', 'label' => 'Minority shareholder concentration',
         'label_zh' => '少数股东比例', 'focus' => '5% / 10% / 20% / 30%',
         'risk' => 'Minorities may block special resolutions.'],
        ['id' => 14, 'cat' => 'B', 'label' => '50:50 structure',
         'label_zh' => '50:50 股权结构', 'focus' => 'Two shareholders at 50% each',
         'risk' => 'Deadlock — needs mechanism to break tie.'],
        ['id' => 15, 'cat' => 'B', 'label' => '51:49 structure',
         'label_zh' => '51:49 股权结构', 'focus' => 'Nominal control only',
         'risk' => '49% may become blocking shareholder.'],
        ['id' => 16, 'cat' => 'B', 'label' => '60:40 structure',
         'label_zh' => '60:40 股权结构', 'focus' => 'Common founder / partner split',
         'risk' => 'Disputes if contribution ≠ shareholding.'],
        ['id' => 17, 'cat' => 'B', 'label' => '70:30 or 80:20 structure',
         'label_zh' => '70:30 / 80:20', 'focus' => 'Dominant founder + partner',
         'risk' => 'Minor shareholder exit mechanism critical.'],
        ['id' => 18, 'cat' => 'B', 'label' => 'Fragmented (many small holdings)',
         'label_zh' => '分散股权', 'focus' => '>5 small holders',
         'risk' => 'Signature, voting and exit become complex.'],
        ['id' => 19, 'cat' => 'B', 'label' => 'Voting vs economic rights alignment',
         'label_zh' => '投票权与经济权是否一致', 'focus' => 'Any dual-class / non-voting',
         'risk' => 'Economic-vs-control divergence needs clear rationale.'],
        ['id' => 20, 'cat' => 'B', 'label' => 'Different share classes',
         'label_zh' => '是否有不同类别股份', 'focus' => 'Ordinary / preference / other',
         'risk' => 'Affects liquidation preference, dividends, voting.'],

        // ============ C — Role, contribution & equity fit (10)
        ['id' => 21, 'cat' => 'C', 'label' => 'CEO shareholding',
         'label_zh' => 'CEO 持股', 'focus' => 'Does CEO hold meaningful equity?',
         'risk' => 'CEO without equity lacks long-term incentive.'],
        ['id' => 22, 'cat' => 'C', 'label' => 'Founder shareholding retained',
         'label_zh' => '创始人持股', 'focus' => 'Post-dilution founder stake',
         'risk' => 'Over-dilution kills founder motivation.'],
        ['id' => 23, 'cat' => 'C', 'label' => 'Technology owner (CTO) shareholding',
         'label_zh' => '技术拥有者持股', 'focus' => 'Does tech lead hold equity?',
         'risk' => 'Tech leaving with no equity risks IP loss.'],
        ['id' => 24, 'cat' => 'C', 'label' => 'Sales lead shareholding',
         'label_zh' => '销售负责人持股', 'focus' => 'Revenue driver bound to company?',
         'risk' => 'Customers may follow the sales lead if they leave.'],
        ['id' => 25, 'cat' => 'C', 'label' => 'Capital contributor shareholding',
         'label_zh' => '资本方持股', 'focus' => 'Capital-only shareholder',
         'risk' => 'Over-controlling capital shareholder can conflict with operators.'],
        ['id' => 26, 'cat' => 'C', 'label' => 'Operating shareholder %',
         'label_zh' => '执行方持股', 'focus' => 'Day-to-day operator equity',
         'risk' => 'Too little operating stake → governance imbalance.'],
        ['id' => 27, 'cat' => 'C', 'label' => 'Resource contributor equity',
         'label_zh' => '资源方持股', 'focus' => 'Brings customers / licences / channels',
         'risk' => 'If the "resource" disappears, equity remains — misaligned.'],
        ['id' => 28, 'cat' => 'C', 'label' => 'Nominal vs actual directors',
         'label_zh' => '名义 vs 实际经营者', 'focus' => 'Who really runs the company?',
         'risk' => 'Legal liability vs real power misaligned.'],
        ['id' => 29, 'cat' => 'C', 'label' => 'Compensation vs equity relationship',
         'label_zh' => '薪酬与股权关系', 'focus' => 'Low salary + high equity trade-off',
         'risk' => 'Future changes create unfairness.'],
        ['id' => 30, 'cat' => 'C', 'label' => 'Contribution documented',
         'label_zh' => '贡献是否有记录', 'focus' => 'Cash / IP / customer / time recorded',
         'risk' => 'Undocumented contributions → equity disputes.'],

        // ============ D — Shareholder agreement & exit (10)
        ['id' => 31, 'cat' => 'D', 'label' => 'Shareholders\' Agreement in place',
         'label_zh' => '是否有 Shareholders\' Agreement', 'focus' => 'Signed SHA on file?',
         'risk' => 'No SHA is the single biggest SME risk.'],
        ['id' => 32, 'cat' => 'D', 'label' => 'Share transfer restrictions',
         'label_zh' => '股权转让限制', 'focus' => 'ROFR / pre-emption right',
         'risk' => 'No restriction → unwanted shareholders can appear.'],
        ['id' => 33, 'cat' => 'D', 'label' => 'Drag-along clause',
         'label_zh' => 'Drag Along', 'focus' => 'Can majority drag minorities?',
         'risk' => 'No drag → M&A blocked by small holder.'],
        ['id' => 34, 'cat' => 'D', 'label' => 'Tag-along clause',
         'label_zh' => 'Tag Along', 'focus' => 'Can minority follow majority sale?',
         'risk' => 'No tag → minority protection weak.'],
        ['id' => 35, 'cat' => 'D', 'label' => 'Buy-sell clause with valuation formula',
         'label_zh' => 'Buy-Sell Clause', 'focus' => 'Exit pricing mechanism',
         'risk' => 'No formula → valuation disputes on exit.'],
        ['id' => 36, 'cat' => 'D', 'label' => 'Deadlock clause',
         'label_zh' => 'Deadlock Clause', 'focus' => 'How is 50:50 impasse broken?',
         'risk' => 'No deadlock mechanism → company frozen.'],
        ['id' => 37, 'cat' => 'D', 'label' => 'Bad-leaver clause',
         'label_zh' => 'Bad Leaver', 'focus' => 'Default / competition penalty',
         'risk' => 'Bad leavers keep equity → ongoing damage.'],
        ['id' => 38, 'cat' => 'D', 'label' => 'Good-leaver clause',
         'label_zh' => 'Good Leaver', 'focus' => 'Retirement / health / death treatment',
         'risk' => 'Ambiguous exit → family disputes.'],
        ['id' => 39, 'cat' => 'D', 'label' => 'Non-compete / non-solicit',
         'label_zh' => 'Non-compete / Non-solicit', 'focus' => 'Post-exit restrictions',
         'risk' => 'Departing shareholder can steal customers / staff.'],
        ['id' => 40, 'cat' => 'D', 'label' => 'Dispute resolution mechanism',
         'label_zh' => 'Dispute Resolution', 'focus' => 'Arbitration / mediation clause',
         'risk' => 'Disputes without a mechanism can kill the company.'],

        // ============ E — Compliance & regulation (5)
        ['id' => 41, 'cat' => 'E', 'label' => 'Foreign shareholder % vs sector limit',
         'label_zh' => '外籍股东比例', 'focus' => 'Sector foreign-equity cap',
         'risk' => 'Sector / licence / govt-project restrictions.'],
        ['id' => 42, 'cat' => 'E', 'label' => 'Foreign equity sector restrictions',
         'label_zh' => '外资行业限制', 'focus' => 'Manufacturing / services / finance / etc.',
         'risk' => 'Each sector has its own foreign-equity ceiling.'],
        ['id' => 43, 'cat' => 'E', 'label' => 'Foreign director / signatory',
         'label_zh' => 'Foreign Director / Signatory', 'focus' => 'Bank signing authority',
         'risk' => 'KYC and signing chains need review.'],
        ['id' => 44, 'cat' => 'E', 'label' => 'Work permit / employment pass',
         'label_zh' => 'Work Permit / Employment Pass', 'focus' => 'Can foreign operators work?',
         'risk' => 'Shareholder status ≠ right to work in Malaysia.'],
        ['id' => 45, 'cat' => 'E', 'label' => 'Repatriation & dividend WHT',
         'label_zh' => 'Repatriation / Dividend WHT', 'focus' => 'Cross-border dividend flow',
         'risk' => 'Tax + FX arrangements need confirming.'],

        // ============ F — Intangibles & IP ownership (5)
        ['id' => 46, 'cat' => 'F', 'label' => 'Trademark ownership',
         'label_zh' => '商标所有权', 'focus' => 'Trademark on company name?',
         'risk' => 'Trademark on individual → company undervalued.'],
        ['id' => 47, 'cat' => 'F', 'label' => 'Software / source-code ownership',
         'label_zh' => '软件 / 源代码归属', 'focus' => 'Code assigned to company',
         'risk' => 'CTO leaving with code → loss of control.'],
        ['id' => 48, 'cat' => 'F', 'label' => 'Patents / know-how',
         'label_zh' => '专利 / know-how', 'focus' => 'Registered or NDA-protected',
         'risk' => 'Cannot prove tech value without protection.'],
        ['id' => 49, 'cat' => 'F', 'label' => 'Customer data ownership',
         'label_zh' => '客户资料归属', 'focus' => 'CRM belongs to company?',
         'risk' => 'Sales staff can walk with the customer list.'],
        ['id' => 50, 'cat' => 'F', 'label' => 'IP assignment agreements',
         'label_zh' => 'IP Assignment Agreement', 'focus' => 'Staff / contractor IP transfer',
         'risk' => 'DD will challenge unassigned IP hard.'],
    ];
}

/** Score bands (0-5) with plain-English meaning. */
function equity_score_bands(): array
{
    return [
        5 => ['Excellent',  'Structure clear, documents complete, low risk.'],
        4 => ['Good',       'Basically clear, minor room for improvement.'],
        3 => ['Acceptable', 'Workable, but supporting docs or explanation needed.'],
        2 => ['Weak',       'Obvious risk — remediate before financing / M&A.'],
        1 => ['High Risk',  'Serious concern that may block a transaction.'],
        0 => ['Missing',    'No data / unverifiable / material hidden risk.'],
    ];
}

/** Due-diligence document checklist (13 categories). */
function equity_dd_checklist(): array
{
    return [
        'company_basics'    => 'Company basics — SSM Profile · Constitution · Form Section 14/17 · Registered Address',
        'shareholders'      => 'Shareholders — Register of Members · Share Certificates · Allotment records',
        'directors'         => 'Directors — Register of Directors · Board Resolutions · Director Service Agreement',
        'beneficial'        => 'Beneficial ownership — BO declaration · Nominee agreement · Declaration of trust',
        'sha'               => 'Shareholders\' Agreement · Subscription Agreement · Investment Agreement',
        'share_movements'   => 'Share movements — Share Transfer Forms · Allotment Forms · Stamping record',
        'investments'       => 'Investment records — Capital injection proof · bank-in slips · shareholder loans',
        'roles'             => 'Role & contribution — CEO / CTO JDs · employment contracts · consultancy agreements',
        'ip'                => 'IP — Trademark · patent · copyright · source-code ownership · IP assignment',
        'licences'          => 'Licences & foreign equity — Operating licences · sector approval · foreign conditions',
        'succession'        => 'Succession — Will · hibah · trust deed · keyman insurance · buy-sell agreement',
        'credit_legal'      => 'Credit & legal — CTOS · CCRIS · bankruptcy search · litigation search',
        'tax'               => 'Tax — filings · RPGT / stamp duty records · transfer pricing (if applicable)',
    ];
}

/** Ten most-important red-flag scenarios. */
function equity_red_flags(): array
{
    return [
        'proxy_no_doc'        => ['Proxy shareholder but no nominee/trust documentation',
            'BO risk · KYC risk · M&A risk'],
        'fifty_fifty_no_dead' => ['50:50 shareholding but no deadlock clause',
            'Company may become unable to decide'],
        'ip_on_individual'    => ['Core tech / IP owned by an individual (not the company)',
            'Company value materially discounted'],
        'muslim_no_succession'=> ['Muslim shareholder without succession arrangement (faraid / hibah / wasiat)',
            'Shares may enter estate administration on death'],
        'foreign_nominee'     => ['Foreign shareholder using local nominee',
            'Compliance · licensing · BO risk'],
        'no_sha'              => ['No signed Shareholders\' Agreement',
            'Exit · disputes · financing · M&A all difficult'],
        'many_small_holders'  => ['Many small shareholders with no representative mechanism',
            'M&A signature process becomes complex'],
        'passive_undocumented'=> ['Passive investors with equity but undefined rights & duties',
            'Future conflict likely'],
        'operator_low_equity' => ['Operating shareholder holds too little equity',
            'Long-term incentive insufficient'],
        'assets_not_owned'    => ['Customers / trademarks / systems / licences not held by company',
            'Business value cannot be fully realised'],
    ];
}

/**
 * Empty assessment shape (defaults 3 = Acceptable per indicator).
 * @return array
 */
function equity_empty_assessment(): array
{
    $scores = [];
    foreach (equity_indicators() as $i) {
        $scores[$i['id']] = ['score' => null, 'note' => ''];
    }
    return [
        'scores'   => $scores,
        'dd'       => array_fill_keys(array_keys(equity_dd_checklist()), 0),
        'red_flags' => array_fill_keys(array_keys(equity_red_flags()), 0),
        'archetype' => '',
        'meta'      => ['updated_at' => '', 'updated_by' => ''],
    ];
}

/** Load a company's equity assessment (tenant-scoped). */
function equity_load(int $companyId): array
{
    $stored = setting_get_json(EQUITY_KEY_PREFIX . $companyId, []);
    $base   = equity_empty_assessment();
    // Deep merge — preserve missing keys.
    $out = $base;
    if (isset($stored['scores']) && is_array($stored['scores'])) {
        foreach ($stored['scores'] as $id => $row) {
            if (isset($out['scores'][(int) $id])) {
                $out['scores'][(int) $id] = [
                    'score' => isset($row['score']) && $row['score'] !== '' ? (int) $row['score'] : null,
                    'note'  => (string) ($row['note'] ?? ''),
                ];
            }
        }
    }
    foreach (['dd', 'red_flags'] as $k) {
        if (isset($stored[$k]) && is_array($stored[$k])) {
            foreach ($stored[$k] as $key => $v) {
                if (array_key_exists($key, $out[$k])) {
                    $out[$k][$key] = (int) (bool) $v;
                }
            }
        }
    }
    $out['archetype'] = (string) ($stored['archetype'] ?? '');
    $out['meta']      = (array) ($stored['meta'] ?? $out['meta']);
    return $out;
}

/** Persist assessment (tenant-scoped). */
function equity_save(int $companyId, array $data): void
{
    $save = equity_empty_assessment();
    foreach ((array) ($data['scores'] ?? []) as $id => $row) {
        $id = (int) $id;
        if (!isset($save['scores'][$id])) { continue; }
        $sc = $row['score'] ?? null;
        $save['scores'][$id] = [
            'score' => ($sc === null || $sc === '') ? null : max(0, min(5, (int) $sc)),
            'note'  => trim(mb_substr((string) ($row['note'] ?? ''), 0, 800)),
        ];
    }
    foreach (array_keys($save['dd']) as $k) {
        $save['dd'][$k] = !empty($data['dd'][$k]) ? 1 : 0;
    }
    foreach (array_keys($save['red_flags']) as $k) {
        $save['red_flags'][$k] = !empty($data['red_flags'][$k]) ? 1 : 0;
    }
    $save['archetype'] = trim(mb_substr((string) ($data['archetype'] ?? ''), 0, 60));
    $save['meta'] = [
        'updated_at' => date('Y-m-d H:i'),
        'updated_by' => function_exists('current_user') && current_user()
            ? (string) (current_user()['name'] ?? '') : '',
    ];
    setting_put_json(EQUITY_KEY_PREFIX . $companyId, $save);
}

/**
 * Score an assessment.
 * @return array{by_cat:array,total:float,band:string,band_code:string,
 *               answered:int,total_indicators:int,dd_done:int,dd_total:int,
 *               red_flags:array<string,array>}
 */
function equity_score(array $data): array
{
    $catSum = $catCnt = [];
    foreach (array_keys(equity_category_weights()) as $c) { $catSum[$c] = 0; $catCnt[$c] = 0; }

    $answered = 0;
    foreach (equity_indicators() as $ind) {
        $s = $data['scores'][$ind['id']]['score'] ?? null;
        if ($s === null) { continue; }
        $catSum[$ind['cat']] += (int) $s;
        $catCnt[$ind['cat']] += 1;
        $answered++;
    }

    $weights = equity_category_weights();
    $byCat = []; $total = 0.0; $totalWeight = 0.0;
    foreach ($weights as $c => $w) {
        $avg = $catCnt[$c] > 0 ? $catSum[$c] / $catCnt[$c] : 0.0;   // 0-5
        $pct = $avg / 5.0 * 100.0;                                   // 0-100
        $byCat[$c] = ['answered' => $catCnt[$c], 'avg' => $avg, 'pct' => $pct, 'weight' => $w];
        if ($catCnt[$c] > 0) {
            $total       += $pct * $w;
            $totalWeight += $w;
        }
    }
    $totalPct = $totalWeight > 0 ? $total / $totalWeight : 0.0;

    [$bandCode, $bandLabel] = equity_band($totalPct, $answered);

    $ddDone = 0; $ddTotal = count(equity_dd_checklist());
    foreach ($data['dd'] as $v) { if ($v) { $ddDone++; } }

    // Compile triggered red flags (either manual tick or auto-derived).
    $auto = equity_auto_red_flags($data);
    $rfs  = [];
    foreach (equity_red_flags() as $key => [$label, $impact]) {
        $on = !empty($data['red_flags'][$key]) || !empty($auto[$key]);
        if ($on) { $rfs[$key] = ['label' => $label, 'impact' => $impact,
                                  'source' => !empty($data['red_flags'][$key]) ? 'manual' : 'auto']; }
    }

    return [
        'by_cat'    => $byCat, 'total' => $totalPct, 'band' => $bandLabel, 'band_code' => $bandCode,
        'answered'  => $answered, 'total_indicators' => count(equity_indicators()),
        'dd_done'   => $ddDone,   'dd_total'         => $ddTotal,
        'red_flags' => $rfs,
    ];
}

/** Convert composite score to a risk band. */
function equity_band(float $total, int $answered): array
{
    if ($answered === 0)   { return ['unknown',  'Not started']; }
    if ($total >= 80)      { return ['low',      'Low Risk']; }
    if ($total >= 65)      { return ['moderate', 'Moderate Risk']; }
    if ($total >= 50)      { return ['high',     'High Risk']; }
    return ['critical',    'Critical Risk'];
}

/**
 * Auto-detect red flags from score & note patterns.
 * A conservative heuristic — advisor can always override manually.
 */
function equity_auto_red_flags(array $data): array
{
    $s = static fn (int $id): ?int => $data['scores'][$id]['score'] ?? null;
    $t = static fn (int $id): string => mb_strtolower((string) ($data['scores'][$id]['note'] ?? ''));

    $auto = [];
    // Proxy w/o docs → indicator 6 low and no mention of "nominee agreement" or "trust"
    if (($s(6) !== null && $s(6) <= 2)
        && !str_contains($t(6), 'nominee agreement') && !str_contains($t(6), 'trust deed')) {
        $auto['proxy_no_doc'] = 1;
    }
    // 50:50 with no deadlock → indicator 14 flagged AND indicator 36 low
    if ($s(14) !== null && $s(14) <= 3 && $s(36) !== null && $s(36) <= 2) {
        $auto['fifty_fifty_no_dead'] = 1;
    }
    // IP on individual → any of 46-48 low
    foreach ([46, 47, 48] as $id) {
        if ($s($id) !== null && $s($id) <= 2) { $auto['ip_on_individual'] = 1; break; }
    }
    // Muslim shareholder without succession
    if ($s(4) !== null && $s(4) <= 3 && $s(38) !== null && $s(38) <= 2) {
        $auto['muslim_no_succession'] = 1;
    }
    // Foreign nominee → indicator 3 or 41 flagged AND 6 low
    if (($s(3) !== null && $s(3) <= 3 || $s(41) !== null && $s(41) <= 3)
        && $s(6) !== null && $s(6) <= 2) {
        $auto['foreign_nominee'] = 1;
    }
    // No SHA
    if ($s(31) !== null && $s(31) <= 1) { $auto['no_sha'] = 1; }
    // Many small holders
    if ($s(18) !== null && $s(18) <= 2) { $auto['many_small_holders'] = 1; }
    // Passive undocumented
    if ($s(10) !== null && $s(10) <= 2 && $s(30) !== null && $s(30) <= 2) {
        $auto['passive_undocumented'] = 1;
    }
    // Operator low equity
    if ($s(26) !== null && $s(26) <= 2) { $auto['operator_low_equity'] = 1; }
    // Assets not owned
    foreach ([46, 47, 49, 50] as $id) {
        if ($s($id) !== null && $s($id) <= 2) { $auto['assets_not_owned'] = 1; break; }
    }
    return $auto;
}

/** Ten common architecture archetypes from the framework. */
function equity_archetypes(): array
{
    return [
        'sole'        => ['label' => 'Single shareholder (100%)',
            'note' => 'Fast decisions; high dependency; needs will / succession plan.'],
        'fifty_fifty' => ['label' => '50:50 co-founders',
            'note' => 'Deadlock risk — needs deadlock / shotgun / casting-vote mechanism.'],
        'fifty_one'   => ['label' => '51:49 dominant + partner',
            'note' => 'Nominal control only; 49% can block 75% special resolutions.'],
        'founder_investor' => ['label' => 'Founder + passive investor (e.g. 70:30)',
            'note' => 'Investor exit / dividend policy essential.'],
        'investor_operator' => ['label' => 'Investor majority + operator minority',
            'note' => 'Give operator ESOP / performance equity with vesting.'],
        'tech_ip_ext' => ['label' => 'Tech-owner shareholder but IP outside company',
            'note' => 'Must complete IP assignment before financing or exit.'],
        'proxy'       => ['label' => 'Proxy / nominee arrangement',
            'note' => 'Clean up cap table before financing / M&A.'],
        'muslim_mix'  => ['label' => 'Muslim + non-Muslim shareholder mix',
            'note' => 'Add hibah / wasiat / trust + buy-sell + keyman insurance.'],
        'foreign'     => ['label' => 'Foreign shareholders (100% or majority foreign)',
            'note' => 'Check sector foreign-equity limits, licences, work permits.'],
        'fragmented'  => ['label' => 'Fragmented (many small holders + ESOP)',
            'note' => 'Needs SHA, board committee, shareholder representative.'],
    ];
}

/** Client-level roll-up: worst company band + list. */
function equity_client_summary(PDO $pdo, ?int $tid, int $clientId): array
{
    require_once __DIR__ . '/business.php';
    $rows = [];
    $worstCode = 'unknown'; $order = ['critical' => 4, 'high' => 3, 'moderate' => 2, 'low' => 1, 'unknown' => 0];
    foreach (companies_for_client($clientId) as $co) {
        $data  = equity_load((int) $co['id']);
        $score = equity_score($data);
        $rows[] = ['company' => $co, 'data' => $data, 'score' => $score];
        if (($order[$score['band_code']] ?? 0) > ($order[$worstCode] ?? 0)) {
            $worstCode = $score['band_code'];
        }
    }
    return ['rows' => $rows, 'worst_band' => $worstCode, 'companies' => count($rows)];
}
