<?php
/**
 * AdvisorOS — Advisory Skills Library.
 * A tenant-scoped library of reusable advisory "skills" (playbooks /
 * recommendation snippets) that advisors author and the AI proposal
 * generator pulls in as house-style guidance. Stored as a JSON list
 * in the settings table — no schema migration required.
 */

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const SKILLS_KEY = 'advisory_skills';

/** All skills for the current tenant, newest first. */
function skills_all(): array
{
    $rows = setting_get_json(SKILLS_KEY, []);
    usort($rows, fn ($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
    return $rows;
}

/** Only active skills (used by the AI proposal generator). */
function skills_active(): array
{
    return array_values(array_filter(skills_all(), fn ($s) => !empty($s['active'])));
}

function skill_get(int $id): ?array
{
    foreach (skills_all() as $s) {
        if ((int) ($s['id'] ?? 0) === $id) {
            return $s;
        }
    }
    return null;
}

/**
 * Create (id=0) or update a skill. Returns the saved id.
 * Title/body are length-bounded; body feeds the AI prompt.
 */
function skill_save(int $id, string $title, string $category, string $body, bool $active, string $author): int
{
    $rows  = setting_get_json(SKILLS_KEY, []);
    $title = trim(mb_substr($title, 0, 120));
    $cat   = trim(mb_substr($category, 0, 40));
    $body  = trim(mb_substr($body, 0, 4000));

    if ($id > 0) {
        foreach ($rows as &$s) {
            if ((int) ($s['id'] ?? 0) === $id) {
                $s = ['id' => $id, 'title' => $title, 'category' => $cat,
                      'body' => $body, 'active' => $active, 'author' => $s['author'] ?? $author,
                      'updated_at' => date('Y-m-d H:i')];
                unset($s);
                setting_put_json(SKILLS_KEY, $rows);
                return $id;
            }
        }
        unset($s);
    }

    $nextId = 1;
    foreach ($rows as $s) {
        $nextId = max($nextId, (int) ($s['id'] ?? 0) + 1);
    }
    $rows[] = ['id' => $nextId, 'title' => $title, 'category' => $cat,
               'body' => $body, 'active' => $active, 'author' => $author,
               'updated_at' => date('Y-m-d H:i')];
    setting_put_json(SKILLS_KEY, $rows);
    return $nextId;
}

function skill_delete(int $id): void
{
    $rows = array_values(array_filter(
        setting_get_json(SKILLS_KEY, []),
        fn ($s) => (int) ($s['id'] ?? 0) !== $id
    ));
    setting_put_json(SKILLS_KEY, $rows);
}
