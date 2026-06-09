<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Splits and executes a .sql file (no stored routines in our schema/seed/
 * migrations, so a line-aware splitter is sufficient and safe).
 */
final class SqlRunner
{
    /** @return int number of statements executed */
    public static function runFile(PDO $pdo, string $file): int
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read {$file}");
        }
        return self::runString($pdo, $raw);
    }

    public static function runString(PDO $pdo, string $raw): int
    {
        // Drop full-line "--" comments and blank lines, then split on a ";"
        // that terminates a line.
        $kept = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '--')) {
                continue;
            }
            $kept[] = $line;
        }

        $buffer = '';
        $count = 0;
        foreach ($kept as $line) {
            $buffer .= $line . "\n";
            if (preg_match('/;\s*$/', $line)) {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                    $count++;
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $pdo->exec(trim($buffer));
            $count++;
        }
        return $count;
    }
}
