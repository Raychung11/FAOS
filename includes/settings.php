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
