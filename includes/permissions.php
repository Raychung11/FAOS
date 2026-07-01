<?php
/**
 * AdvisorOS — Role-based access control.
 * Permissions are resolved from role_permissions and cached in session.
 */

declare(strict_types=1);

/** All permission codes granted to the current user's role. */
function user_permissions(): array
{
    if (!is_logged_in()) {
        return [];
    }
    if (isset($_SESSION['_permissions'])) {
        return $_SESSION['_permissions'];
    }
    $stmt = db()->prepare(
        'SELECT p.code FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?'
    );
    $stmt->execute([(int) current_user()['role_id']]);
    $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $_SESSION['_permissions'] = $perms;
    return $perms;
}

function has_permission(string $code): bool
{
    return in_array($code, user_permissions(), true);
}

function has_role(string ...$codes): bool
{
    return is_logged_in()
        && in_array(current_user()['role_code'], $codes, true);
}

/** Hard guard — abort the request unless the permission is held. */
function require_permission(string $code): void
{
    require_login();
    if (!has_permission($code)) {
        http_response_code(403);
        exit('You do not have permission to access this resource.');
    }
}

/** Hard guard — abort unless the user holds one of the given roles. */
function require_role(string ...$codes): void
{
    require_login();
    if (!has_role(...$codes)) {
        http_response_code(403);
        exit('You do not have permission to access this resource.');
    }
}

/** Convenience for templates: render a block only if permitted. */
function can(string $code): bool
{
    return has_permission($code);
}
