<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, string $msg = 'OK'): never
    {
        self::json(['ok' => true, 'message' => $msg, 'data' => $data]);
    }

    public static function fail(string $msg, int $status = 400, mixed $errors = null): never
    {
        self::json(['ok' => false, 'message' => $msg, 'errors' => $errors], $status);
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header("Referrer-Policy: same-origin");
        echo $html;
        exit;
    }
}
