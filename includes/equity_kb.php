<?php
/**
 * AdvisorOS — Equity Structure Knowledge Base.
 * Where the firm pastes its equity-planning methodology, red-flag
 * playbook, sample SHA clauses and house style. Active entries are
 * injected into the AI equity commentary as guidance. Tenant-scoped,
 * stored as a JSON list in the settings table. Mirrors tax_kb.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const EQKB_KEY = 'equity_kb';

function eq_kb_all(): array
{
    $rows = setting_get_json(EQKB_KEY, []);
    usort($rows, fn ($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
    return $rows;
}

function eq_kb_active(): array
{
    return array_values(array_filter(eq_kb_all(), fn ($e) => !empty($e['active'])));
}

function eq_kb_get(int $id): ?array
{
    foreach (eq_kb_all() as $e) {
        if ((int) ($e['id'] ?? 0) === $id) { return $e; }
    }
    return null;
}

function eq_kb_save(int $id, string $title, string $category, string $body, bool $active): int
{
    $rows  = setting_get_json(EQKB_KEY, []);
    $title = trim(mb_substr($title, 0, 140));
    $cat   = trim(mb_substr($category, 0, 50));
    $body  = trim(mb_substr($body, 0, 12000));

    if ($id > 0) {
        foreach ($rows as &$e) {
            if ((int) ($e['id'] ?? 0) === $id) {
                $e = ['id' => $id, 'title' => $title, 'category' => $cat, 'body' => $body,
                      'active' => $active,
                      'author' => $e['author'] ?? (current_user()['name'] ?? ''),
                      'updated_at' => date('Y-m-d H:i')];
                unset($e);
                setting_put_json(EQKB_KEY, $rows);
                return $id;
            }
        }
        unset($e);
    }
    $nextId = 1;
    foreach ($rows as $e) { $nextId = max($nextId, (int) ($e['id'] ?? 0) + 1); }
    $rows[] = ['id' => $nextId, 'title' => $title, 'category' => $cat, 'body' => $body,
               'active' => $active, 'author' => current_user()['name'] ?? '',
               'updated_at' => date('Y-m-d H:i')];
    setting_put_json(EQKB_KEY, $rows);
    return $nextId;
}

function eq_kb_delete(int $id): void
{
    $rows = array_values(array_filter(
        setting_get_json(EQKB_KEY, []),
        fn ($e) => (int) ($e['id'] ?? 0) !== $id
    ));
    setting_put_json(EQKB_KEY, $rows);
}

/** Active KB compiled into a prompt block (length-bounded). */
function eq_kb_prompt(int $maxChars = 9000): string
{
    $out = '';
    foreach (eq_kb_active() as $e) {
        $chunk = '## ' . ($e['title'] ?? 'Note')
            . (!empty($e['category']) ? ' [' . $e['category'] . ']' : '') . "\n"
            . ($e['body'] ?? '') . "\n\n";
        if (strlen($out) + strlen($chunk) > $maxChars) { break; }
        $out .= $chunk;
    }
    return trim($out);
}
