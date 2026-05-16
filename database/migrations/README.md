# Migrations

Incremental, forward-only schema changes. Each file is applied **once** and
recorded in the `schema_migrations` table — `bin/migrate.php` only runs
*pending* files, so production data is never wiped.

- Baseline = `../schema.sql` (recorded as `00000000000000_baseline`).
- New change → add `YYYYMMDDHHMMSS_short_name.sql` here. Files apply in
  filename order. Keep them additive/safe (avoid destructive DDL).
- Apply: `php bin/migrate.php` · status: `php bin/migrate.php --status`
- `bin/install.php` wraps this (`--fresh` drops the DB for dev only).

Keep `../schema.sql` as the authoritative baseline for fresh installs; only
incremental deltas go here.
