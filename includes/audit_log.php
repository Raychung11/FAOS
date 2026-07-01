<?php
/**
 * AdvisorOS — Audit trail.
 * Records who did what, in which module, against which record.
 */

declare(strict_types=1);

function audit_log(
    string $action,
    string $module,
    ?int $recordId = null,
    ?string $description = null
): void {
    try {
        $user = current_user();
        $stmt = db()->prepare(
            'INSERT INTO audit_logs
                (tenant_id, user_id, action, module, record_id, description, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['tenant_id'] ?? null,
            $user['id'] ?? null,
            $action,
            $module,
            $recordId,
            $description,
            client_ip(),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Auditing must never break the user-facing action.
        error_log('[AdvisorOS] audit_log failed: ' . $e->getMessage());
    }
}
