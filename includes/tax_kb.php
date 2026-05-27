<?php
/**
 * AdvisorOS — Tax Planning Knowledge Base.
 * Where the firm pastes its tax methodology, rules, traps and house
 * style as text entries. Active entries are injected into the AI tax
 * report as guidance (the "education"). Tenant-scoped, stored as a
 * JSON list in the settings table — no schema change. Mirrors the
 * advisory skills library.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const TAXKB_KEY = 'tax_kb';

function tax_kb_all(): array
{
    $rows = setting_get_json(TAXKB_KEY, []);
    usort($rows, fn ($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
    return $rows;
}

function tax_kb_active(): array
{
    return array_values(array_filter(tax_kb_all(), fn ($e) => !empty($e['active'])));
}

function tax_kb_get(int $id): ?array
{
    foreach (tax_kb_all() as $e) {
        if ((int) ($e['id'] ?? 0) === $id) { return $e; }
    }
    return null;
}

function tax_kb_save(int $id, string $title, string $category, string $body, bool $active): int
{
    $rows  = setting_get_json(TAXKB_KEY, []);
    $title = trim(mb_substr($title, 0, 140));
    $cat   = trim(mb_substr($category, 0, 50));
    $body  = trim(mb_substr($body, 0, 12000));

    if ($id > 0) {
        foreach ($rows as &$e) {
            if ((int) ($e['id'] ?? 0) === $id) {
                $e = ['id' => $id, 'title' => $title, 'category' => $cat, 'body' => $body,
                      'active' => $active, 'author' => $e['author'] ?? (current_user()['name'] ?? ''),
                      'updated_at' => date('Y-m-d H:i')];
                unset($e);
                setting_put_json(TAXKB_KEY, $rows);
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
    setting_put_json(TAXKB_KEY, $rows);
    return $nextId;
}

function tax_kb_delete(int $id): void
{
    $rows = array_values(array_filter(
        setting_get_json(TAXKB_KEY, []),
        fn ($e) => (int) ($e['id'] ?? 0) !== $id
    ));
    setting_put_json(TAXKB_KEY, $rows);
}

/** Active KB compiled into a prompt block (length-bounded). */
function tax_kb_prompt(int $maxChars = 9000): string
{
    $out = '';
    foreach (tax_kb_active() as $e) {
        $chunk = '## ' . ($e['title'] ?? 'Note')
            . (!empty($e['category']) ? ' [' . $e['category'] . ']' : '') . "\n"
            . ($e['body'] ?? '') . "\n\n";
        if (strlen($out) + strlen($chunk) > $maxChars) { break; }
        $out .= $chunk;
    }
    return trim($out);
}
