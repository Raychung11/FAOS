<?php
/**
 * AdvisorOS — CSRF protection.
 * A per-session token is embedded in every state-changing form and
 * verified on POST. Verification uses hash_equals (timing-safe).
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = random_token(32);
    }
    return $_SESSION['_csrf'];
}

/** Hidden input to drop into forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}

/**
 * Guard a POST request. Aborts with 419 when the token is missing or
 * invalid. Call at the top of every POST handler.
 */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (!csrf_verify($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        exit('Invalid or expired security token. Please reload the page and try again.');
    }
}
