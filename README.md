# FAOS — AI Central Kitchen + QR Kiosk Sales BOS

A production-ready **Business Operating System** for multi-outlet kiosk +
central-kitchen F&B operations that **replaces POS dependency** with QR-based
sales and stock tracking, real-time dashboards, delayed-report reconciliation,
and AI demand forecasting.

Built for Malaysian SMEs / F&B businesses that run kiosks inside hypermarkets
and only receive official third-party sales reports **3–4 weeks late**.

---

## Why this is not a normal POS

The company does **not** own the hypermarket POS. So FAOS:

1. Estimates real-time business performance from **QR sales scans**.
2. Tracks every stock movement via **QR scan** (Central Kitchen → Warehouse →
   Outlet → Kiosk → Sale).
3. **Reconciles** later when the official hypermarket report finally arrives,
   classifying every line as `matched / shortage / over_reported /
   under_reported / missing_scans`.
4. Works even with **no POS integration and no internet** (offline sales queue).

---

## Tech stack

| Layer | Choice |
|------|--------|
| Backend | **Native PHP 8.3+** (no framework, no Composer deps) |
| Database | **MySQL 8** (InnoDB, FK-enforced, prepared statements only) |
| Frontend | Self-contained AdminLTE/Bootstrap-style CSS, mobile-first |
| QR generate | **Pure-PHP QR encoder** (`app/Core/Qr.php`) → PNG / SVG / A4 sheets |
| QR scan | Native browser **`BarcodeDetector`** + manual fallback (zero deps) |
| AI | Provider-agnostic client (Claude / OpenAI ready) + offline heuristic forecaster |

> **Zero external runtime dependencies by design** — kiosks frequently have poor
> connectivity, so nothing is loaded from a CDN and sales work offline.

---

## Quick start

```bash
cp .env.example .env          # set DB_* credentials
php bin/install.php           # create DB + run migrations + demo seed
php -S 0.0.0.0:8080 server.php
```

Open `http://localhost:8080`.

### Setup option B — manual SQL import (shared hosting / phpMyAdmin)

No CLI? `database/schema.sql` is the **complete current schema**, so importing
just these two files works standalone:

```
1. Create a utf8mb4 database, then import  database/schema.sql
2. Import  database/seed.sql   (demo data + default logins)
3. Point .env DB_* at it
```

### Database migrations (no data loss)

The schema evolves through **forward-only, applied-once** migrations — never a
destructive rebuild on a live system.

```bash
php bin/migrate.php           # baseline (if needed) + apply pending
php bin/migrate.php --seed    # also load demo data if the DB is empty
php bin/migrate.php --status  # show applied / pending, change nothing
php bin/install.php --fresh   # DEV ONLY: drop + recreate, then migrate + seed
```

`database/schema.sql` is the full schema and already contains every migration
up to `SCHEMA_BASELINE`; the runner records those as applied **without
re-executing** them (so a fresh DB never hits duplicate-column errors).
`database/migrations/` holds only deltas newer than that baseline, tracked in
`schema_migrations`, so re-running is a safe no-op. When `schema.sql` is
regenerated to fold in newer migrations, bump `SCHEMA_BASELINE` in
`bin/migrate.php`.

| User | Login | Role |
|------|-------|------|
| Super Admin | `admin` / `admin123` | full access |
| Kiosk-Hub Manager | `manager` / `manager123` | hypermarket kiosk ops + reports |
| Kiosk Worker | `worker1` / `worker123` (PIN `1234`) | QR sales + stock only |
| Restaurant Manager | `rmanager` / `manager123` | restaurant ops + reports |
| Restaurant Worker | `rworker` / `worker123` (PIN `1234`) | QR sales + stock only |
| Accountant | `acc` / `acc123` | company finance: AP / AR / P&L |

Caffeinees runs **both hypermarket kiosks and restaurants**. Kiosk hubs depend
on delayed 3rd-party POS reports (reconciled later); restaurants own their till
so their QR sales are real-time with no reconciliation. Both use the same QR
sales/stock screens.

### Tests & CI

```bash
php tests/qr_test.php          # QR encoder conformance (ISO/IEC 18004)
bash tests/e2e/run_all.sh      # full e2e regression (needs server + DB up)
```

`tests/e2e/` holds the suites (base, finance, bankrecon, supply); `run_all.sh`
resets the DB between isolation groups for determinism. **GitHub Actions**
(`.github/workflows/ci.yml`) runs `php -l`, migrations + idempotency check, the
QR test, and the full e2e regression against a MySQL 8 service on every push
and PR.

---

## Architecture

```
public/index.php         Front controller (routing, security headers)
server.php               Dev-server static+router shim
app/
  Core/                  Framework: Router, Database(PDO), Auth/RBAC,
                          Csrf, Session, Validator, Audit, View, Qr, Config
  Controllers/Api        REST API (JSON)
  Controllers/Web        Server-rendered pages
  Services/              Business logic (Stock, Sales, Qr, Reconciliation,
                          Forecast, AiClient)
  Models/Model.php       Generic company-scoped CRUD helper
  Views/                 Templates + self-contained JS/CSS
database/schema.sql      Full relational schema (all phases)
database/seed.sql        Demo data
bin/install.php          CLI installer
```

Every request: `index.php` → `Router` (CSRF + RBAC) → Controller → Service →
`Database` (PDO prepared statements) → `Audit` log.

---

## Modules

**Phase 1 (implemented & functional)**
- Master Data — companies, outlets, kiosks, warehouses, suppliers, products,
  stock/account groups, categories, recipes (generic REST CRUD + UI)
- QR Stock Control — label generation (PNG/SVG/print sheet), receive /
  transfer / consume / wastage / count, full traceable movement ledger
- QR Sales Tracking — camera scan, quick-tap, cart, payment types, shifts,
  **offline queue with idempotent sync**, automatic stock + recipe deduction
- Dashboards — Worker / Outlet / HQ / AI
- Auth & RBAC, audit logging, scan logging

**Phase 2 (implemented — the core operating loop)**
- **Procurement**: Supplier PO → approve → GRN. GRN creates product batches
  (expiry from shelf life), posts `receive` stock movements, updates the PO
  (partial/received), and **auto-raises the supplier invoice in AP**.
- **Central Kitchen production**: production order against a recipe/BOM →
  start → complete. FEFO-tagged ingredient consumption from the kitchen
  warehouse (blocks on insufficient stock), computes the output unit cost,
  and receives the produced batch back into the kitchen.
- **Outlet replenishment**: outlet/restaurant raises a Purchase Request →
  approve → fulfil, which distributes stock (warehouse → outlet transfer);
  blocks if the source is short so it never partially fulfils.

**Phase 3 (implemented services)** — AI forecasting (weighted moving average +
day-of-week seasonality, AI narrative when a provider key is set), delayed-report
**reconciliation engine**, anomaly detection, purchase recommendation.

**Operational Finance (Accountant)** — Accounts Payable (supplier invoices +
payments with aging), Accounts Receivable (hypermarket settlements auto-created
when a delayed report is reconciled), and a P&L summary (revenue, COGS, wastage
cost, gross profit/margin) by period and account group, with CSV + printable
(browser→PDF) statement. No double-entry ledger by design.

**Payment / Bank Reconciliation (Accountant)** — fixes the card-terminal vs
bank-statement mismatch. Upload any terminal Z-reading CSV and any bank CSV;
a column-mapping step (auto-guessed, savable) handles arbitrary layouts. The
engine groups terminal txns into settlement batches per day per scheme and
matches them to bank credits with an MDR/fee tolerance (**batch level** for
card/DuitNow/ATM), while bank transfers & deposits match **line level** by
amount/date. Each batch is classified `matched / fee_variance (implied MDR) /
short / over / unmatched / not_expected`, with manual-match override and an
exceptions worklist.

---

## Key business rules enforced

- Every stock movement writes an immutable `stock_movements` row **and** updates
  `stock_balances` in one transaction → fully traceable.
- Every sale auto-deducts the finished product, and (if a recipe exists)
  its ingredients (made-to-order consumption).
- QR payload is **only** the reference (`STK-YYYYMMDD-NNNNNN`); all
  product/batch/expiry/supplier data is resolved server-side on scan.
- Sales are idempotent on `client_uuid` → safe offline retry, duplicate-scan
  prevention.
- FEFO batch selection with FIFO fallback (`StockService::fefoBatches`).

---

## REST API (selected)

```
GET    /api/me
GET    /api/md/{resource}            POST/PUT/DELETE for master data
POST   /api/qr/labels                Generate QR labels
GET    /api/qr/{ref}                 Resolve scan → full traceability
GET    /api/qr/{ref}/image?fmt=png   QR image
POST   /api/stock/{receive|transfer|consume|wastage|count}
POST   /api/sales                    Record sale (idempotent)
POST   /api/sales/sync               Flush offline queue
GET    /api/dashboard/{worker|outlet|hq|ai}
GET    /api/reports/sales?by=outlet|kiosk|worker|sku|category|stock_group|account_group&export=csv
POST   /api/reconciliation/import    Import delayed official report → variance
```

All state-changing routes require a valid CSRF token and the route's RBAC
permission.

---

## Security

Prepared statements everywhere · CSRF tokens · session fixation rotation +
hardened cookies · bcrypt passwords/PINs · login lockout · per-action audit log
(user, time, outlet, device, before/after JSON) · scan log for every QR scan.

---

## Configuring AI

Set in `.env`:

```
AI_PROVIDER=claude        # or openai, or none
AI_API_KEY=sk-...
AI_MODEL=claude-opus-4-7
```

With `AI_PROVIDER=none` the system still forecasts using the built-in
statistical model — AI only adds a natural-language recommendation layer.

---

## Future phases (architecture-ready)

WhatsApp AI assistant · facial recognition · RFID · IoT weighing scale ·
supplier portal · native mobile app · multi-company (schema already
company-scoped).
