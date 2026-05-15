# AdvisorOS

**The Client Servicing Operating System for Financial Advisors.**

A multi-tenant SaaS platform that moves financial advisory firms from
WhatsApp-based servicing, spreadsheets and memory-based follow-ups into a
structured, professional, compliance-ready client servicing operation.

> *Never miss a client review again.*

This is **MVP Phase 1**. It implements the full foundation plus the core
servicing workflow modules.

---

## Tech stack

- PHP 8.1+ (no framework — plain, modular, secure)
- MySQL 8+ / MariaDB 10.5+ (InnoDB, `utf8mb4`)
- PDO with prepared statements everywhere
- Bootstrap-free custom CSS (premium navy / gold / white theme)
- Vanilla JS

## Architecture highlights

- **Multi-tenant isolation** — every tenant table carries `tenant_id`,
  `created_by`, `created_at`, `updated_at` and soft-delete `deleted_at`.
  All queries are tenant-scoped; `assert_tenant_owns()` adds defence in depth.
- **RBAC** — six roles (Super Admin, Tenant Admin, Agency Leader,
  Financial Advisor, Compliance Officer, Client) resolved from a
  `role_permissions` matrix.
- **Row-level scope** — financial advisors only see records assigned to them.
- **Security** — CSRF tokens, hardened sessions with idle timeout,
  `password_hash()`, brute-force lockout, controlled file downloads,
  XSS-escaped output, directory `.htaccess` hardening.
- **Audit trail** — who/what/module/record/IP on every state change.

## Implemented modules

Authentication · Tenant management · User management · Lead management
(+ convert to client) · Client 360° profile · Financial snapshot with
0–100 health score · Policy & product tracking · **Annual Review Engine
(hero feature)** · Appointments · Follow-ups · Advisory cases · Document
vault (validated upload, versioning, audited download) · Commissions ·
Role dashboards · Client portal · Compliance dashboard · Audit trail.

## Setup

1. Create a database and a user, then configure the environment:

   ```bash
   cp .env.example .env
   # edit .env — set DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_URL
   ```

2. Run the installer (applies schema + seed, creates accounts):

   ```bash
   php setup/install.php
   ```

3. Serve the project root (Apache/Nginx with PHP-FPM, or for local dev):

   ```bash
   php -S localhost:8080
   ```

4. Sign in at `/login.php`. Seeded accounts (default password
   `Admin@12345`):

   | Role          | Email                          |
   |---------------|--------------------------------|
   | Super Admin   | superadmin@advisoros.app       |
   | Tenant Admin  | admin@demo-advisory.test       |
   | Agency Leader | leader@demo-advisory.test      |
   | Advisor       | advisor@demo-advisory.test     |
   | Compliance    | compliance@demo-advisory.test  |

5. **Production:** change all seeded passwords, set `APP_DEBUG=false`,
   serve over HTTPS, and delete the `setup/` directory.

### Recovering from a partial / failed import

`schema.sql` is idempotent (`CREATE TABLE IF NOT EXISTS`), but if an
earlier run left the database half-built, re-running it will skip the
existing (incomplete) tables. Start clean instead:

1. Run `database/reset.sql` (drops all AdvisorOS tables — destructive,
   data-loss; only on an install with no real data).
2. Run `database/schema.sql`, then `database/seed.sql`
   (or just `php setup/install.php`).

In phpMyAdmin: open the database → SQL tab → paste each file's contents
in the order above.

## Roadmap

- **Phase 2** — Proposal generator + PDF export, AI summary tools,
  WhatsApp & email automation (PHPMailer / DomPDF scaffolded in
  `composer.json`).
- **Phase 3** — AI assistant, predictive analytics, financial scoring AI,
  marketplace integrations.

## Disclaimer

AdvisorOS is a servicing and workflow platform. It does **not** replace
licensed financial advisors. All advisory recommendations remain the
responsibility of licensed professionals. AI-generated output always
displays: *"This is not financial advice. Final recommendations must be
reviewed and approved by a licensed financial advisor."*
