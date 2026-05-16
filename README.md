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
php bin/install.php           # creates DB, schema, demo seed (use --fresh to reset)
php -S 0.0.0.0:8080 server.php
```

Open `http://localhost:8080`.

| User | Login | Role |
|------|-------|------|
| Super Admin | `admin` / `admin123` | full access |
| Outlet Manager | `manager` / `manager123` | outlet ops + reports |
| Kiosk Worker | `worker1` / `worker123` (PIN `1234`) | QR sales + stock only |

Run the QR encoder conformance test: `php tests/qr_test.php`.

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

**Phase 2 (schema + APIs)** — Central Kitchen production orders, Procurement
(PR/PO/GRN), Inventory adjustments & balances ledger.

**Phase 3 (implemented services)** — AI forecasting (weighted moving average +
day-of-week seasonality, AI narrative when a provider key is set), delayed-report
**reconciliation engine**, anomaly detection, purchase recommendation.

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
