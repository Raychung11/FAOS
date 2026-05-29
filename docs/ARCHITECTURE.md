# FAOS BOS — System Architecture

> Source document for presentations. Each `##` heading maps to a slide. Diagrams are kept as ASCII so they can be redrawn in Keynote/PowerPoint.

---

## 1. Executive summary

**FAOS BOS** is a self‑hosted, modular **Business Operating System** for
multi‑outlet F&B operators that own a **central kitchen, kiosks inside
hypermarkets, and restaurants** — but **do not own the hypermarket POS**.

- Captures every sale in real time via **QR scan**, weeks before the official hypermarket report arrives.
- Reconciles two delayed sources of truth: the **3‑week hypermarket report** and the **card‑terminal vs bank** flow.
- Closes the operational loop: **Procure → Produce → Distribute → Sell → Reconcile → Account → e‑Invoice → Close period**.
- Built for **Malaysia**: SST 6 % service tax + **LHDN MyInvois** e‑invoice.
- **Zero external runtime dependencies** (no CDN, no Composer) so it runs on shared hosting and survives kiosk connectivity gaps.

---

## 2. Business context

| Reality | Implication |
|---|---|
| Owns central kitchen + kiosks inside AEON/Lotus's/Mydin + restaurants | Multi‑tier inventory (warehouse → outlet/kiosk) |
| Doesn't own hypermarket POS — official report 3–4 weeks late | Cannot rely on POS; must **estimate in real time** |
| Cards settle in batches net of MDR 1–3 days after a sale | Bank reconciliation must allow **fee variance** |
| Cash collected on premises but deposited later | Separate bank vs cash flow |
| LHDN MyInvois is mandatory for SDN BHDs | Must generate compliant e‑invoices natively |
| Kiosks have flaky Wi‑Fi | Sales must work **offline** then sync |

---

## 3. Architectural goals & constraints

- **Self‑hostable** on shared hosting (Hostinger LAMP) — no Docker, no NPM at install.
- **Zero CDN / external runtime libs** — vendored only (jsQR for camera scan).
- **Multi‑outlet, multi‑role** with strict RBAC and tenant scoping.
- **Idempotent + tamper‑evident** — every state change auditable, every retry safe.
- **Forward‑only migrations** — never destructive rebuilds on live data.
- **Phased deliverability** — clean module boundaries so each phase ships green.

---

## 4. High‑level architecture

```
 ┌─────────────────────────────────────────────────────────────────┐
 │                     CLIENT  (browser, mobile-first)             │
 │  HTML + vanilla JS  ·  BarcodeDetector + jsQR  ·  offline queue │
 └───────────────▲─────────────────────────────────────────────────┘
                 │  HTTPS  (CSRF, session cookie)
 ┌───────────────┴─────────────────────────────────────────────────┐
 │     WEB / EDGE   Apache‑LiteSpeed  +  .htaccess  hardening      │
 │     Front controller:  public/index.php                         │
 └───────────────▲─────────────────────────────────────────────────┘
                 │
 ┌───────────────┴─────────────────────────────────────────────────┐
 │                    APPLICATION  (native PHP 8)                  │
 │  Router (CSRF + RBAC)  →  Controllers  →  Services  →  Data     │
 │  Audit log  ·  Scan log  ·  Period guard  ·  Offline sync       │
 └─┬───────────────────────────────────────────┬───────────────────┘
   │                                           │
   │ PDO prepared statements                   │ OAuth2 + HTTPS
   ▼                                           ▼
 ┌────────────────────┐   ┌──────────────────────────────────────┐
 │   MySQL 8  InnoDB  │   │  External integrations               │
 │  57 tables · audit │   │  • LHDN MyInvois (e‑Invoice)         │
 │  multi‑tenant      │   │  • Claude / OpenAI (forecast insight)│
 └────────────────────┘   └──────────────────────────────────────┘
```

---

## 5. Application layering

| Layer | Responsibility | Key files |
|---|---|---|
| **Core** | Routing, request/response, auth, CSRF, sessions, audit, DB, view, QR encoder, SQL runner, helpers | `app/Core/*` |
| **Controllers** | Map HTTP to use cases — `Web/*` (server‑rendered pages) and `Api/*` (JSON) | `app/Controllers/*` |
| **Services** | Business logic — stateless, transactional | `app/Services/*` |
| **Models** | Lightweight active‑table helper for master data CRUD | `app/Models/Model.php` |
| **Views** | Server‑rendered HTML with inline page scripts (no framework) | `app/Views/*` |
| **Routes** | Single declarative file with route → handler → permission gate | `app/routes.php` |

**Stateless, no DI container by design** — each request boots fresh; `Database::pdo()` is a per‑request singleton; sessions hold only the user id.

---

## 6. Domain modules (vertical slices)

```
Procure ──► Produce ──► Distribute ──► Sell (QR) ──► Sales recon ──► Account ──► e‑Invoice ──► Close period
   ▲                                                       ▼                                              ▲
   └────── auto AP invoice (NET 30) ◄──── GRN          AR settlement ◄── delayed hypermarket report ──────┘
                                                                                    Bank recon ◄──┘
```

| Module | Service(s) | Key endpoints | Permission gate |
|---|---|---|---|
| QR Stock | `StockService`, `QrService` | `/api/stock/{receive,transfer,consume,wastage,count}`, `/api/qr/*` | `stock.scan/manage`, `qr.print` |
| QR Sales | `SalesService` | `/api/sales`, `/api/sales/sync`, `/api/sales/shift/*` | `sales.scan/view` |
| Procurement | `ProcurementService` | `/api/procurement/po`, `/api/procurement/grn` | `procurement.manage` |
| Central Kitchen Production | `ProductionService` | `/api/production/*` | `kitchen.manage` |
| Replenishment | `ReplenishmentService` | `/api/replenishment/*` | `stock.manage`, `procurement.manage` |
| Sales Reconciliation | `ReconciliationService` | `/api/reconciliation/*` | `reconciliation.manage` |
| Bank Reconciliation | `BankReconService` | `/api/recon/*` | `finance.view/manage` |
| Finance (AP/AR/P&L) | `FinanceService` | `/api/finance/*` | `finance.view/manage` |
| SST + LHDN e‑Invoice | `EInvoiceService`, `MyInvoisClient` | `/api/einvoice/*` | `finance.view/manage` |
| Sales Corrections (void/refund/discount) | `SalesService` | `/api/sales/{id}/void,refund` | `sales.void_refund` |
| Period Close | `PeriodService` | `/api/finance/periods/*` | `finance.view/manage` |
| AI Forecasting | `ForecastService`, `AiClient` | `/api/dashboard/ai` | `ai.view` |
| Bulk Import | `ImportController` | `/api/import/{products,stock,po}` | `masterdata/stock/procurement.manage` |
| User Admin | `AdminUserController` | `/api/admin/users/*` | `admin.users` |

---

## 7. Data architecture

**57 InnoDB tables** in 10 logical clusters; every business row carries `company_id` for multi‑tenant isolation; FK‑enforced.

| Cluster | Representative tables |
|---|---|
| Org & RBAC | `companies, outlets, kiosks, warehouses, users, roles, permissions, role_permissions, workers` |
| Master data | `products, product_categories, stock_groups, account_groups, suppliers, tax_codes, recipes, recipe_items, product_batches` |
| QR Stock | `qr_labels, stock_movements, stock_balances, stock_adjustments` |
| QR Sales | `sales_transactions, sales_items, shifts, offline_sync_queue, scan_logs` |
| Procurement & Production | `purchase_orders, purchase_order_items, grn, grn_items, purchase_requests, production_orders, production_consumption` |
| Sales Reconciliation | `official_sales_reports, official_sales_lines, variance_reports` |
| Bank Reconciliation | `recon_imports, recon_column_mappings, terminal_transactions, bank_transactions, settlement_batches` |
| Finance | `supplier_invoices, supplier_payments, ar_settlements, ar_receipts` |
| Tax & e‑Invoice | `einvoices, einvoice_lines` |
| Integrity & ops | `accounting_periods, audit_logs, settings, sales_forecasts, ref_counters, schema_migrations` |

**Costing**: moving‑average cost on `products.avg_cost`, recomputed *before* every inbound (GRN, production output).

**Tamper‑evident**: `audit_logs(before_json, after_json, user, ip, device, ts)`; `scan_logs` for every QR scan.

---

## 8. Integration architecture

### 8.1 LHDN MyInvois (e‑Invoice)
- OAuth2 `client_credentials` against preprod/prod endpoints.
- UBL JSON document built from `EInvoiceService::buildDocument(...)`.
- **Graceful degradation**: when API keys aren't set, the compliant document is still generated and stored with status `pending_submission`; flipping it on requires only `.env` config.

### 8.2 AI provider (Claude / OpenAI compatible)
- `AiClient` is provider‑agnostic; `ForecastService` adds a natural‑language layer on top of an offline statistical forecaster (weighted moving avg + day‑of‑week seasonality).
- Works fully offline when `AI_PROVIDER=none`.

### 8.3 CSV ingestion (bank + terminal + bulk import)
- Two‑step upload: preview (parse headers + first rows) → import (apply mapping).
- Mappings persisted per company for reuse.
- Robust to noise: per‑row try/catch, warnings non‑fatal.

### 8.4 QR codes
- **Encode**: pure‑PHP `App\Core\Qr` (ISO/IEC 18004 byte mode v1–10 + Reed‑Solomon ECC) → PNG / SVG / printable A4 sheet.
- **Decode**: native `BarcodeDetector` on Android Chrome / Edge, **jsQR** fallback for iOS Safari & Firefox over a canvas, plus manual + USB keyboard‑wedge entry.

---

## 9. Security architecture

| Concern | Mitigation |
|---|---|
| Auth | Bcrypt password + optional PIN, 5‑attempt lockout, session regen every 30 min, HttpOnly + SameSite cookies, optional `SESSION_SECURE` |
| Authorization | Declarative RBAC at the route layer; permission string per route |
| Tenant isolation | Server injects `company_id` on create; strips it on update; never trusts client tenant id |
| CSRF | Token in session, required on every state‑changing route (header or `_csrf`) |
| SQL injection | Only prepared statements via PDO |
| File upload | Allowlist + 4–8 MB cap, stored under `storage/uploads/recon/` |
| Backdating | `accounting_periods` blocks backdated sale/void/refund in a closed period |
| Operational fraud | Workers cannot void/refund; gated by `sales.void_refund` |
| Deployment hardening | Root `.htaccess` blocks `app/ bin/ database/ .env`; `bin/*` scripts refuse non‑CLI execution |
| Audit | `audit_logs` (who, when, before, after) on every change |

---

## 10. Reliability & data integrity

- **Idempotent sales** by `client_uuid` (offline retry safe; concurrent dup race caught by the unique‑constraint handler).
- **Atomic reference numbers** via `ref_counters` (`INSERT … ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq+1)`) — no `MAX()+1` race.
- **Transactional services** — `Database::transaction()` is reentrant so nested service calls share the outer txn.
- **Negative‑stock guard** (configurable) on controlled internal moves; **sales remain allowed** so QR sales work without complete stock.
- **Movement ledger** is immutable; balances are denormalised totals derived from it.

---

## 11. Frontend architecture

- **Server‑rendered HTML** + small inline page scripts (no SPA framework).
- Two shared client modules: **`app.js`** (FAOS API helper, toast, upload) and **`scanner.js`** (camera scan with native + jsQR fallback). Both **loaded in `<head>`** so page inline code can use them.
- **jsQR (257 KB) only loaded on `/sales` and `/stock`** to keep other pages light.
- **Mobile‑first CSS** with one shared `app.css` — no external fonts/icons.
- **Offline sales queue** in localStorage; auto‑flushes on reconnect.

---

## 12. Deployment architecture

```
┌──────────────────────────── Production options ────────────────────────────┐
│                                                                            │
│  Shared hosting (Hostinger)              Cloud VPS / Docker                │
│  ──────────────────────────              ───────────────────               │
│  • Apache / LiteSpeed                    • Nginx + PHP-FPM                 │
│  • Doc root -> public/                   • Doc root -> public/             │
│  • phpMyAdmin import reset.sql           • bin/migrate.php --seed          │
│  • Edit .env via File Manager            • .env via secrets manager        │
│  • Browser-based admin                   • Horizontal scale-out possible   │
└────────────────────────────────────────────────────────────────────────────┘
```

**DB setup paths**:
1. **`database/reset.sql`** — one‑file import (drops + schema + demo seed).
2. **`schema.sql`** then **`seed.sql`** — two‑file manual.
3. **`php bin/migrate.php --seed`** — CLI; squashes folded migrations, applies only pending.

**CI** (GitHub Actions): `php -l` lint → migrate + idempotency assertion → QR conformance test → full e2e regression against a **MySQL 8 service container**.

---

## 13. Observability

- `storage/logs/app-YYYY-MM-DD.log` — application + DB error log.
- `audit_logs` table — every state change with before/after JSON.
- `scan_logs` table — every QR scan with context (receive/transfer/sale/lookup), device id, IP, result.
- `APP_DEBUG=true` surfaces precise DB connection errors (host, db, user) — for setup diagnostics; never leave on in production.

---

## 14. Performance characteristics

| Metric | At seed scale |
|---|---|
| Median endpoint time (server‑side) | **1–4 ms** |
| Page weight excluding scan pages | < 25 KB JS |
| Scan‑page extra (jsQR) | 257 KB, cached |
| Index strategy | `(company_id, sold_at)`, `(company_id, movement_type, created_at)`; all date filters sargable |
| Ref generation | Atomic counter, no `MAX()+1` race |
| DB connection | One PDO per request; persistent off (shared‑hosting friendly) |

---

## 15. Non‑functional commitments

- **Privacy** — no third‑party telemetry; data stays in your DB.
- **Compliance** — SST 6 % service tax on prepared F&B; LHDN MyInvois UBL JSON ready.
- **Portability** — pure PHP 8 + MySQL 8; no native extensions beyond PDO, GD, mbstring, cURL.
- **Backups** — `database/reset.sql` round‑trippable; phpMyAdmin export trivially.

---

## 16. Test & quality strategy

- **Encoder conformance**: 34 ISO/IEC 18004 format‑info & structural tests for the QR encoder.
- **End‑to‑end suites** (curl + DB assertions): `base, finance, bankrecon, supply, einvoice, inventory, corrections, admin, import, period` — **220+ assertions**, orchestrated by `tests/e2e/run_all.sh`, run in CI against MySQL 8.
- **Migration safety**: CI also runs `migrate --seed` twice and asserts the second run is a no‑op (`No pending migrations`).
- **PHP lint**: every source file every push.

---

## 17. Key design decisions & rationale

| Decision | Why |
|---|---|
| **Native PHP 8, no framework** | Drop‑in on shared hosting, no Composer/CDN dependence |
| **Vendored jsQR** | iOS Safari camera scan without paid SDKs or live CDN |
| **Tax‑inclusive default** | Matches Malaysian F&B menu prices; customer totals never change |
| **Moving‑average costing (AVCO)** | Standard for F&B; computed before every inbound to avoid double counting |
| **Batch‑level + line‑level bank match** | Real card schemes settle in batches net of MDR; transfers/DuitNow are itemised |
| **Period close *excludes* hypermarket recon imports** | Official reports always arrive after close — blocking them would break the workflow |
| **Forward‑only migrations + baseline squash** | `schema.sql` stays the single source of truth for fresh installs; deltas ship to existing deployments without re‑running |
| **Idempotency on `client_uuid`** | Offline queue can retry without duplicate sales |

---

## 18. Roadmap / future phases

- Per‑location per‑batch FEFO depletion + batch‑accurate valuation
- UOM conversions (kg ↔ pack ↔ unit)
- Stocktake approval workflow
- Native PWA (service worker + offline shell so sales work even on cold page loads)
- WhatsApp AI assistant for owners
- RFID / IoT weighing‑scale integration
- Supplier portal (suppliers acknowledge POs, upload invoices)

---

## 19. Suggested slide pack outline

| # | Slide | Source section |
|---|---|---|
| 1 | Title + tagline | §1 |
| 2 | The problem | §2 |
| 3 | Goals & constraints | §3 |
| 4 | High‑level diagram | §4 |
| 5 | Daily flow diagram | §6 |
| 6 | Module map (table) | §6 |
| 7 | Data model overview | §7 |
| 8 | Integration map | §8 |
| 9 | Security model | §9 |
| 10 | Reliability / integrity | §10 |
| 11 | Frontend | §11 |
| 12 | Deployment | §12 |
| 13 | Observability + perf | §13, §14 |
| 14 | Test & CI | §16 |
| 15 | Key design trade‑offs | §17 |
| 16 | Roadmap | §18 |
| 17 | Thank you + demo URL + logins | landing |
