<?php
/**
 * AdvisorOS — Tenant context & isolation.
 *
 * Every tenant-scoped query MUST filter by the current tenant id. These
 * helpers centralise that rule so a user from one tenant can never read
 * or write another tenant's data.
 */

declare(strict_types=1);

/** The tenant id bound to the current session (null for super admin). */
function current_tenant_id(): ?int
{
    $u = current_user();
    return $u['tenant_id'] ?? null;
}

function is_super_admin(): bool
{
    $u = current_user();
    return ($u['role_code'] ?? null) === 'super_admin';
}

/**
 * Require a tenant-bound session. Super admin staff intentionally have
 * no tenant context and must use the platform area instead.
 */
function require_tenant(): int
{
    $tid = current_tenant_id();
    if ($tid === null) {
        http_response_code(403);
        exit('No tenant context for this account.');
    }
    return $tid;
}

/** Fetch the active tenant record (cached per request). */
function current_tenant(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache ?: null;
    }
    $tid = current_tenant_id();
    if ($tid === null) {
        $cache = false;
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM tenants WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$tid]);
    $cache = $stmt->fetch() ?: false;
    return $cache ?: null;
}

/**
 * Assert that a freshly fetched record belongs to the current tenant.
 * Use after any SELECT that loads a single record by primary key as a
 * defence-in-depth check on top of the WHERE tenant_id clause.
 */
function assert_tenant_owns(?array $record): array
{
    if ($record === null
        || (int) ($record['tenant_id'] ?? -1) !== (int) require_tenant()) {
        http_response_code(404);
        exit('Record not found.');
    }
    return $record;
}

/**
 * Row-level scope inside a tenant. Financial advisors only see records
 * assigned to them; tenant admins / agency leaders / compliance see the
 * whole tenant. Returns [sqlFragment, params] to append to a WHERE.
 *
 *   [$frag, $p] = advisor_scope('assigned_to');
 *   $sql .= $frag;  $args = array_merge($args, $p);
 */
function advisor_scope(string $column): array
{
    $u = current_user();
    if (($u['role_code'] ?? '') === 'financial_advisor') {
        return [" AND {$column} = ?", [(int) $u['id']]];
    }
    return ['', []];
}

/** Active clients in the current tenant (id => full_name) for selects. */
function tenant_clients_list(): array
{
    $tid = require_tenant();
    $sql = 'SELECT id, full_name FROM clients
            WHERE tenant_id=? AND deleted_at IS NULL';
    $args = [$tid];
    [$scope, $sp] = advisor_scope('advisor_id');
    $sql .= $scope . ' ORDER BY full_name';
    $st = db()->prepare($sql);
    $st->execute(array_merge($args, $sp));
    return $st->fetchAll();
}

/** Brand colours for the current tenant (falls back to platform palette). */
function tenant_brand(): array
{
    $t = current_tenant();
    return [
        'primary' => $t['brand_primary'] ?? '#0B1F3A',
        'accent'  => $t['brand_accent']  ?? '#C9A227',
        'name'    => $t['company_name']  ?? APP_NAME,
        'logo'    => $t['logo_path']     ?? null,
    ];
}
