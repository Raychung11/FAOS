<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function write(string $level, string $msg, array $ctx = []): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $msg,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('error', $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('info', $msg, $ctx);
    }
}
