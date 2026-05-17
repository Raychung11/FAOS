# Migrations

> ⚠️ **Do NOT import these files by hand (phpMyAdmin / `mysql <`).** They are
> incremental deltas for the CLI migrator only, and every one up to
> `SCHEMA_BASELINE` is **already folded into `../schema.sql`**. Importing them
> manually fails with `#1061 Duplicate key` / `#1054 Unknown column`.
> Manual setup uses `../reset.sql` **or** `../schema.sql` + `../seed.sql`.

Incremental, forward-only schema changes for **CLI deployments**. Each file is
applied **once** and recorded in `schema_migrations`; `bin/migrate.php` only
runs files newer than `SCHEMA_BASELINE`, so production data is never wiped.

- `../schema.sql` is the COMPLETE current schema. Files here ≤ `SCHEMA_BASELINE`
  (set in `bin/migrate.php`) are recorded as applied **without re-running**.
- New change → add `YYYYMMDDHHMMSS_short_name.sql`. Additive/safe DDL only.
- Apply: `php bin/migrate.php` · status: `php bin/migrate.php --status`
- `bin/install.php --fresh` drops + rebuilds (dev only).
- When `schema.sql` is regenerated to include newer migrations, bump
  `SCHEMA_BASELINE` in `bin/migrate.php`.
