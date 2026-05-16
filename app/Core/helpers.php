<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $base = rtrim((string) Config::get('APP_URL', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function csrf_field(): string
{
    return Csrf::field();
}

function money(float|int|string $n): string
{
    return Config::get('CURRENCY_SYMBOL', 'RM') . ' ' . number_format((float) $n, 2);
}

function qty(float|int|string $n): string
{
    return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
}

/**
 * Generate a sequential reference like STK-20260516-000001.
 *
 * Atomic per (prefix + date): the INSERT ... ON DUPLICATE KEY UPDATE takes a
 * row lock so concurrent callers can never mint the same ref. ($table/$column
 * are kept for call-site readability but no longer used.)
 */
function next_ref(string $prefix, ?string $table = null, ?string $column = null): string
{
    $date  = date('Ymd');
    $scope = "{$prefix}-{$date}";
    App\Core\Database::run(
        'INSERT INTO ref_counters (scope, seq) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)',
        [$scope]
    );
    $seq = (int) App\Core\Database::scalar('SELECT LAST_INSERT_ID()');
    return sprintf('%s-%s-%06d', $prefix, $date, $seq);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
