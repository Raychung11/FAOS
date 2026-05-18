<?php
/**
 * AdvisorOS — Platform revenue model: subscription + metered
 * per-report usage + a B2B referral program.
 *
 * Revenue model
 *   monthly bill = plan base price
 *                + (reports over the plan's free quota) × per-report price
 *                − referral credit earned
 *
 * Migration-free: per-plan price/quota live in the existing
 * subscription_plans.features JSON; usage counters and referral state
 * live in the settings table keyed per tenant.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/** RM credit a firm earns each time a firm it referred subscribes. */
function referral_credit_rm(): float
{
    return (float) env('REFERRAL_CREDIT_RM', 200);
}

const REPORT_TYPES = ['proposal', 'capability', 'tax', 'wealth', 'risk'];

/** Per-report commercial terms for a plan row (price, free quota). */
function plan_report_terms(?array $plan): array
{
    $f = [];
    if ($plan && !empty($plan['features'])) {
        $d = json_decode((string) $plan['features'], true);
        if (is_array($d)) { $f = $d; }
    }
    return [
        'price' => (float) ($f['report_price'] ?? env('REPORT_PRICE_RM', 15)),
        'quota' => (int) ($f['report_quota'] ?? 20),
    ];
}

/** Record one billable report for the acting tenant (best effort). */
function meter_report(string $type): void
{
    if (!in_array($type, REPORT_TYPES, true)) {
        return;
    }
    $tid = current_tenant_id();
    if ($tid === null) {
        return;
    }
    try {
        $key = 'usage:' . date('Y-m');
        $u   = setting_get_json_for($tid, $key, []);
        foreach (array_merge(REPORT_TYPES, ['total']) as $k) {
            $u[$k] = (int) ($u[$k] ?? 0);
        }
        $u[$type]++;
        $u['total']++;
        setting_put_json_for($tid, $key, $u);
    } catch (Throwable $e) {
        error_log('[AdvisorOS] meter_report failed: ' . $e->getMessage());
    }
}

function tenant_usage(int $tid, string $ym): array
{
    $u = setting_get_json_for($tid, 'usage:' . $ym, []);
    $out = ['total' => (int) ($u['total'] ?? 0)];
    foreach (REPORT_TYPES as $t) {
        $out[$t] = (int) ($u[$t] ?? 0);
    }
    return $out;
}

/** Referral state for a tenant; lazily mints a stable code. */
function referral_state(int $tid): array
{
    $s = setting_get_json_for($tid, 'referral', []);
    if (empty($s['code'])) {
        $s = [
            'code'        => 'FAOS-' . strtoupper(substr(md5('faos-ref-' . $tid), 0, 6)),
            'credit_rm'   => (float) ($s['credit_rm'] ?? 0),
            'referred_by' => (string) ($s['referred_by'] ?? ''),
            'signups'     => $s['signups'] ?? [],
        ];
        setting_put_json_for($tid, 'referral', $s);
    }
    $s['credit_rm']   = (float) ($s['credit_rm'] ?? 0);
    $s['referred_by'] = (string) ($s['referred_by'] ?? '');
    $s['signups']     = $s['signups'] ?? [];
    return $s;
}

function referral_code(int $tid): string
{
    return (string) referral_state($tid)['code'];
}

/** Resolve a referral code to its owning tenant id. */
function referral_resolve(string $code): ?int
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    $rows = db()->query("SELECT tenant_id, setting_value FROM settings WHERE setting_key='referral'")->fetchAll();
    foreach ($rows as $r) {
        $d = json_decode((string) $r['setting_value'], true);
        if (is_array($d) && strtoupper((string) ($d['code'] ?? '')) === $code) {
            return (int) $r['tenant_id'];
        }
    }
    return null;
}

/**
 * Attribute a (new) tenant to a referrer's code and credit the referrer.
 * Idempotent: a tenant can only ever be attributed once.
 *
 * @return array{ok:bool,msg:string}
 */
function referral_attribute(int $newTid, string $code): array
{
    $code = strtoupper(trim($code));
    $self = referral_state($newTid);
    if ($self['referred_by'] !== '') {
        return ['ok' => false, 'msg' => 'This firm is already attributed to a referrer.'];
    }
    if ($code === '' || $code === $self['code']) {
        return ['ok' => false, 'msg' => 'Enter a valid referral code (not your own).'];
    }
    $refTid = referral_resolve($code);
    if ($refTid === null || $refTid === $newTid) {
        return ['ok' => false, 'msg' => 'Referral code not recognised.'];
    }

    $self['referred_by'] = $code;
    setting_put_json_for($newTid, 'referral', $self);

    $ref = referral_state($refTid);
    $ref['credit_rm'] = (float) $ref['credit_rm'] + referral_credit_rm();
    $ref['signups'][] = ['tenant_id' => $newTid, 'at' => date('Y-m-d')];
    setting_put_json_for($refTid, 'referral', $ref);

    return ['ok' => true, 'msg' => 'Referral applied — RM '
        . number_format(referral_credit_rm(), 2) . ' credited to the referrer.'];
}

/**
 * Monthly bill for one tenant.
 *
 * @param array $tenant tenants row joined with plan_* fields
 * @return array{base:float,used:int,quota:int,overage:int,price:float,usage_cost:float,credit:float,credit_applied:float,total:float}
 */
function tenant_bill(array $tenant, string $ym): array
{
    $plan = [
        'price_monthly' => $tenant['plan_price_monthly'] ?? 0,
        'features'      => $tenant['plan_features'] ?? null,
    ];
    [$price, $quota] = array_values(plan_report_terms($plan));

    $base    = (float) ($tenant['plan_price_monthly'] ?? 0);
    $used    = tenant_usage((int) $tenant['id'], $ym)['total'];
    $overage = max(0, $used - $quota);
    $usage   = $overage * $price;
    $credit  = (float) referral_state((int) $tenant['id'])['credit_rm'];

    $gross    = $base + $usage;
    $applied  = min($credit, $gross);

    return [
        'base' => $base, 'used' => $used, 'quota' => $quota,
        'overage' => $overage, 'price' => $price, 'usage_cost' => $usage,
        'credit' => $credit, 'credit_applied' => $applied,
        'total' => max(0.0, $gross - $applied),
    ];
}

/** Active tenants joined with their plan, for the platform rollup. */
function billing_tenants(): array
{
    return db()->query(
        "SELECT t.id, t.company_name, t.subscription_status,
                sp.name AS plan_name,
                sp.price_monthly AS plan_price_monthly,
                sp.features AS plan_features
         FROM tenants t
         LEFT JOIN subscription_plans sp ON sp.id = t.plan_id
         WHERE t.deleted_at IS NULL
         ORDER BY t.company_name"
    )->fetchAll();
}

/**
 * Platform-wide revenue rollup for a month.
 *
 * @return array{rows:array,mrr:float,usage:float,credits:float,net:float,reports:int}
 */
function platform_revenue(string $ym): array
{
    $rows = [];
    $mrr = $usage = $credits = $net = 0.0;
    $reports = 0;
    foreach (billing_tenants() as $t) {
        $b = tenant_bill($t, $ym);
        $rows[] = $t + $b;
        $mrr     += $b['base'];
        $usage   += $b['usage_cost'];
        $credits += $b['credit_applied'];
        $net     += $b['total'];
        $reports += $b['used'];
    }
    return ['rows' => $rows, 'mrr' => $mrr, 'usage' => $usage,
            'credits' => $credits, 'net' => $net, 'reports' => $reports];
}
