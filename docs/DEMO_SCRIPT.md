# FAOS BOS — 5‑Minute Demo Script

> **Audience:** F&B operators, hypermarket partners, investors, internal stakeholders.
> **Duration:** 5–7 minutes (4 acts + close). **Goal:** show the end‑to‑end loop and the Malaysian compliance angle in one continuous story.

---

## Pre‑flight (do this 30 seconds before)

**Open these tabs (one per role) so you can switch by clicking the tab — no logging out mid‑demo:**

| Tab | URL | Sign in as |
|---|---|---|
| ① Worker | `/sales` | `worker1` / `worker123` (PIN `1234`) |
| ② Manager | `/sales-manage` | `manager` / `manager123` |
| ③ HQ Admin | `/hq` | `admin` / `admin123` |
| ④ AI | `/ai` | (same admin tab — switch URL) |
| ⑤ Accountant — Bank Recon | `/bank-recon` | `acc` / `acc123` |
| ⑥ Accountant — e‑Invoice | `/einvoicing` | (same acc tab) |

**Have these files in a folder on the desktop** (used in Act 4):
- `tests/e2e/fixtures/terminal.csv`
- `tests/e2e/fixtures/bank.csv`

**One sentence to open with:**
> *"This is FAOS BOS — built for Caffeinees, a Malaysian F&B chain that runs kiosks inside hypermarkets, restaurants and a central kitchen. The hypermarket doesn't share its POS, and the sales report arrives 3–4 weeks late. Let me show you how that's no longer a problem."*

---

## Act 1 · The worker rings a QR sale (90 s)

**Tab ①** — Worker / `/sales`

**DO:**
1. Hold up the phone screen → camera box is live with the scan frame.
2. Tap a product chip (e.g. **Caffeinees Latte**) twice → it's added to the cart.
3. Show **"Confirm Sale"** at the bottom — total e.g. **RM 19.80**.
4. (Optional, dramatic) Tap **airplane mode** on the phone → tap **Confirm Sale** → toast says *"Offline — sale queued"* → turn Wi‑Fi back on → toast says *"queued sale(s) synced"*.
5. Click **Confirm Sale** normally → green toast with the `SAL-…` reference.

**SAY:**
> *"Five seconds, no POS. Tax‑inclusive at 6 % SST — split out automatically. Stock at this kiosk just dropped by two. And critically — if the Wi‑Fi dies mid‑shift, sales queue locally with a unique key, so when it reconnects the server accepts each one once. No double‑rings, no lost sales."*

---

## Act 2 · The manager corrects a mistake (45 s)

**Tab ②** — Manager / `/sales-manage`

**DO:**
1. Click **Search** → top row is the sale you just rang.
2. Click **Open** → modal shows the line items.
3. Enter **Refund qty = 1** on the latte line → **Refund selected** → green toast.
4. Show the status changed to **partially_refunded**; the refund row appears.

**SAY:**
> *"Workers can't void or refund — that's a manager action, fraud control. The refund just reversed the finished‑good stock, recorded a refund record, and you'll see in a moment it nets through to revenue and SST. Every action is audit‑logged with the user, time and device."*

---

## Act 3 · HQ Dashboard + AI (60 s)

**Tab ③** — Admin / `/hq`

**DO:**
1. Point at the **Today's Sales** card — the sale you just rang is in there (refresh once if needed).
2. Point at the **14‑day trend** sparkline and the **outlet ranking** table.
3. Switch to **Tab ④** — `/ai` → click **Run Forecast**.

**SAY:**
> *"Real‑time multi‑outlet view — every QR sale lands here within seconds. The AI tab gives a 7‑day demand forecast per product plus a purchase recommendation — without an OpenAI key it uses a built‑in moving average with day‑of‑week seasonality. Add a key and it adds a natural‑language insight on top. Both work."*

---

## Act 4 · The accountant — the killer story (2 min)

This is the longest act because the **dual reconciliation** is the headline differentiator. Keep it punchy.

### 4a · Bank reconciliation (75 s)

**Tab ⑤** — Accountant / `/bank-recon`

**DO:**
1. Card **Card‑Terminal Settlement** → choose `terminal.csv` → **Preview & Map** → click **Import Terminal** (fields auto‑guessed).
2. Card **Bank Statement** → choose `bank.csv` → **Preview & Map** → **Import Bank**.
3. Click **▶ Run Reconciliation (batch + line match)**.

**Point at the result table line by line:**

| Channel | What it shows | Say |
|---|---|---|
| `duitnow_qr` | **matched** | *"Exact — 1,000 in, 1,000 out."* |
| `card_visa` | **fee_variance**, Implied Fee **RM 75** | *"Visa kept 1.5 % MDR — within tolerance — it computed the fee."* |
| `card_master` | **unmatched** + an unmatched bank line of 1,850 | *"The bank paid RM 150 less than the fee band allows. It refuses to silently absorb that. Exception list — chase it."* |
| `cash` | **not_expected** | *"Cash isn't a bank credit, correctly flagged."* |
| Bank transfers (200, 300) | line‑matched individually | *"Transfers reconcile per line, not per batch."* |

### 4b · e‑Invoice + SST (45 s)

**Tab ⑥** — Accountant / `/einvoicing`

**DO:**
1. Card **Consolidated e‑Invoice** → pick outlet 1, period start = first of month, end = today → **Generate**.
2. The new row appears with status **pending_submission**.
3. Click **Print** → the LHDN tax invoice opens with the **validation QR** and supplier TIN.
4. Switch to **SST Summary** tab → set this month → see the **output tax** broken down — net of the refund you just did.

**SAY:**
> *"For LHDN MyInvois, the system already builds the compliant UBL JSON. Without API keys it sits as pending_submission. Add the keys and it auto‑submits and stamps the validated UUID. Consolidated B2C is the actual kiosk workflow — one e‑invoice per outlet per month for all walk‑in receipts. The SST Summary is your SST‑02 figure."*

---

## Act 5 · Integrity — period close + audit (30 s)

**Same Tab ⑥**, navigate to `/finance` → **Periods** tab.

**DO:**
1. Enter `2026-01-01` to `2026-01-31` → **Close period**.
2. Switch back to Tab ② Manager → try to refund a January sale → 422 *"…period 2026‑01‑01 to 2026‑01‑31 is closed."*

**SAY:**
> *"Once a month closes, the books are locked. Backdated sales, voids and refunds all rejected — except hypermarket report imports, which by design arrive after close. Reopen if a real fix is needed; the reopen is itself audited."*

---

## Close (30 s)

Switch to `/exec-summary.html` (one‑pager) and read the stat strip:

> *"57 tables, 14 modules, 220+ automated tests, every push CI‑green. Less than 5 seconds per QR sale, the 3–4 week hypermarket lag is handled, zero CDN dependencies, runs on shared hosting. Built for Malaysian F&B. Ready for live LHDN submission the moment the keys are issued."*

**Call to action (pick one):**
- *"Want me to pilot this on one of your outlets for two weeks?"*
- *"Shall we line up a session with your accountant to migrate the catalogue?"*
- *"What's the one workflow you'd want to see next?"*

---

## If something goes wrong

| Issue | Quick recovery |
|---|---|
| A page stays on "Loading…" | **Ctrl+F5** to bust cache; if persistent, the worker phone needs the same. |
| Camera won't open | The status text tells you why (permission, HTTPS, busy). Use manual entry — the worker just types the code; no demo break. |
| Bank recon returns 0 matches | Check date format mapping = `d/m/Y` (matches the demo CSVs). |
| Login 500 | Bring up the running build's `/api/me` in another tab — if it 401s the session expired; re‑login. |
| Anything else | Open hPanel → Error Logs in a separate window during practice; in the real demo, just say *"I'll come back to that"* and move on. |

---

## Practice run checklist

- [ ] Tabs ①–⑥ pre‑loaded, all logged in
- [ ] Phone (or simulator) on the right network for the offline trick
- [ ] `terminal.csv` and `bank.csv` on the desktop
- [ ] One real QR label printed (`/qr-labels`) for the camera shot
- [ ] Browser zoom 110 % for screen visibility
- [ ] APP_DEBUG=false on live (no stack traces leaking)

**Timing target:** Act 1: 1:30 · Act 2: 0:45 · Act 3: 1:00 · Act 4a+4b: 2:00 · Act 5: 0:30 · Close: 0:30 → **6 minutes total**, including pauses.

---

*Companion docs: `docs/ARCHITECTURE.md` (full system), `docs/ACCOUNTANT_GUIDE.md` (deeper test script), `public/exec-summary.html` (leave‑behind), `public/quickstart-accountant.html` (printable).*
