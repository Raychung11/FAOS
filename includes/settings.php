<?php
/**
 * AdvisorOS — Tenant-scoped key/value settings.
 * Backed by the `settings` table (UNIQUE tenant_id + setting_key) so
 * small per-tenant / per-client state can be persisted without a
 * schema change.
 */

declare(strict_types=1);

function setting_get(string $key, ?string $default = null): ?string
{
    $tid = current_tenant_id();
    $st = db()->prepare(
        'SELECT setting_value FROM settings WHERE tenant_id <=> ? AND setting_key = ?'
    );
    $st->execute([$tid, $key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function setting_put(string $key, string $value): void
{
    $tid = current_tenant_id();
    db()->prepare(
        'INSERT INTO settings (tenant_id, setting_key, setting_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$tid, $key, $value]);
}

/** JSON convenience accessors. */
function setting_get_json(string $key, array $default = []): array
{
    $raw = setting_get($key);
    if ($raw === null) {
        return $default;
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
}

function setting_put_json(string $key, array $value): void
{
    setting_put($key, json_encode($value, JSON_UNESCAPED_UNICODE));
}

/**
 * Explicit-tenant accessors. Used where the acting session's tenant is
 * not the target — e.g. the platform revenue rollup reading each
 * tenant's usage, or crediting a referrer in another tenant. The
 * tenant id is always a concrete (NOT NULL) value so the upsert is
 * reliable on the UNIQUE(tenant_id, setting_key) key.
 */
function setting_get_json_for(int $tid, string $key, array $default = []): array
{
    $st = db()->prepare('SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = ?');
    $st->execute([$tid, $key]);
    $raw = $st->fetchColumn();
    if ($raw === false) {
        return $default;
    }
    $d = json_decode((string) $raw, true);
    return is_array($d) ? $d : $default;
}

function setting_put_json_for(int $tid, string $key, array $value): void
{
    db()->prepare(
        'INSERT INTO settings (tenant_id, setting_key, setting_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$tid, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Platform-wide (tenant-independent) settings — e.g. tax rates that are
 * the same for every tenant. Stored under the reserved tenant id 0 so
 * the UNIQUE(tenant_id, setting_key) upsert is reliable (NULL would not
 * dedupe). No tenant has id 0.
 */
function platform_setting_get_json(string $key, array $default = []): array
{
    return setting_get_json_for(0, $key, $default);
}

function platform_setting_put_json(string $key, array $value): void
{
    setting_put_json_for(0, $key, $value);
}
