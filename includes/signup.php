<?php
/**
 * AdvisorOS — Public access-request (signup lead) store.
 * Self-serve signups are captured to an append-only JSON-Lines file
 * under the uploads dir (same persistence model as documents) so the
 * public funnel needs no schema change and no DB write path.
 */

declare(strict_types=1);

function signup_file(): string
{
    return UPLOAD_DIR . '/signups/requests.jsonl';
}

/** Append one access request. Best effort; never throws to the visitor. */
function signup_store(array $r): bool
{
    $dir = dirname(signup_file());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return false;
    }
    return @file_put_contents(signup_file(), $line . "\n", FILE_APPEND | LOCK_EX) !== false;
}

/** All requests, newest first. */
function signup_all(): array
{
    $f = signup_file();
    if (!is_file($f)) {
        return [];
    }
    $out = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $d = json_decode($ln, true);
        if (is_array($d)) {
            $out[] = $d;
        }
    }
    return array_reverse($out);
}

/** Remove a handled request by id (rewrites the file). */
function signup_dismiss(string $id): void
{
    $f = signup_file();
    if (!is_file($f)) {
        return;
    }
    $keep = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $d = json_decode($ln, true);
        if (is_array($d) && (string) ($d['id'] ?? '') !== $id) {
            $keep[] = $ln;
        }
    }
    @file_put_contents($f, $keep ? implode("\n", $keep) . "\n" : '', LOCK_EX);
}
