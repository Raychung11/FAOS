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

/** Generate a sequential reference like STK-20260516-000001. */
function next_ref(string $prefix, string $table, string $column): string
{
    $date = date('Ymd');
    $like = "{$prefix}-{$date}-%";
    $max = App\Core\Database::scalar(
        "SELECT MAX($column) FROM $table WHERE $column LIKE ?",
        [$like]
    );
    $seq = 1;
    if ($max && preg_match('/-(\d+)$/', (string) $max, $m)) {
        $seq = (int) $m[1] + 1;
    }
    return sprintf('%s-%s-%06d', $prefix, $date, $seq);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
