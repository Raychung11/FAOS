<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(
        string $action,
        string $entity,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        try {
            $u = Auth::user();
            Database::run(
                'INSERT INTO audit_logs
                  (company_id, user_id, action, entity, entity_id, outlet_id, ip_address, device_id, before_json, after_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $u['company_id'] ?? null,
                    $u['id'] ?? null,
                    $action,
                    $entity,
                    $entityId,
                    $u['outlet_id'] ?? null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_X_DEVICE_ID'] ?? null,
                    $before !== null ? json_encode($before) : null,
                    $after !== null ? json_encode($after) : null,
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('Audit log failed', ['msg' => $e->getMessage()]);
        }
    }
}
