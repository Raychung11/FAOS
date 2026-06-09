# FAOS BOS — Accountant's Guide & Test Script

A hands‑on guide for the **Accountant** to test and use the finance side of
FAOS BOS. No technical knowledge needed — just follow the steps.

---

## 1. Sign in

- Open: **https://skyblue-stork-968585.hostingersite.com/**
- Click **Sign in** → username **`acc`**, password **`acc123`**
  *(please change this password after your first login — ask the admin, or
  Administration is handled by the super‑admin.)*
- You'll land on the **Finance** page. Your left menu shows only what an
  accountant needs:
  **Finance · Bank Reconciliation · e‑Invoicing · Reports · Reconciliation.**

> What you can do: company finance (AP/AR/P&L), tax (SST + e‑Invoice),
> reconciliations, and reports. You **cannot** ring sales, void/refund, edit
> products or manage users — those belong to workers, managers and the admin.

---

## 2. Quick map of your screens

| Menu | What it's for |
|------|----------------|
| **Finance** | Tabs: **P&L**, **Payables (AP)**, **Receivables (AR)**, **Stock Value**, **Periods** |
| **e‑Invoicing** | LHDN MyInvois e‑invoices + the **SST summary** (for SST‑02) |
| **Bank Reconciliation** | Match the card‑terminal report against the bank statement |
| **Reconciliation** | Match QR sales against the delayed hypermarket report |
| **Reports** | Sales by outlet / kiosk / worker / SKU / category, CSV export |

Everything you do is **audit‑logged** (who, when).

---

## 3. Test script — do these in order

The system ships with demo data (Caffeinees, 3 outlets, sample products,
some sales). The steps below let you exercise every finance feature.

### A. Profit & Loss (Finance → P&L)
1. Set **From / To** to cover this month → **Apply**.
2. Read: **Gross sales, Refunds, Revenue (net), COGS, Gross profit, Margin %,
   Wastage cost**, and the **by account‑group** breakdown.
3. Click **Print / PDF** → a clean P&L statement opens (use the browser's
   Print → Save as PDF). Click **CSV** to download the figures.
   - ✅ *Check:* Revenue = Gross sales − Refunds. COGS uses moving‑average cost.

### B. Accounts Payable (Finance → Payables)
1. **Record Supplier Invoice**: pick a supplier, enter an invoice no, date,
   due date and total → **Add Invoice**.
2. The invoice appears as **unpaid** with an **aging** (days overdue once past
   due date).
3. Click **Pay**, enter an amount → status becomes **partial** or **paid**;
   overpaying is rejected.
   - ✅ *Check:* Outstanding total at the top updates as you pay.
   - 💡 Invoices from **Goods Received (GRN)** in Procurement appear here
     automatically with the supplier's payment terms.

### C. Accounts Receivable (Finance → Receivables)
- These are amounts **owed by the hypermarket**. A settlement row appears
  **automatically** when a delayed official report is imported (see step F).
- For a row, click **Receive**, enter the amount the bank received → status
  goes **partial** → **settled**.
  - ✅ *Check:* Outstanding = Expected − Received.

### D. Inventory Valuation (Finance → Stock Value)
- Shows on‑hand stock valued at **moving‑average cost**, per product/location,
  with a total. **Negative rows are flagged** (stock sold without a recorded
  receipt — flag these to the manager for a stock‑take).

### E. Period Close (Finance → Periods)
1. **Close a period**: pick last month's start/end → **Close period**.
2. Now any **backdated sale, void or refund** inside that period is **rejected**
   automatically — protecting your closed books.
3. You can **Reopen** a period if a correction is genuinely needed.
   - 💡 *Important:* delayed **hypermarket reconciliation imports are NOT
     blocked** by a closed period — official reports arrive weeks late by design.

### F. Sales Reconciliation (Reconciliation menu)
1. **Import Official Hypermarket Report**: choose outlet, period, paste lines
   as `SKU,qty,amount` (one per line) → **Import & Reconcile**.
2. Read the **variance** table — each line is classified:
   **matched / shortage / over‑reported / under‑reported / missing scans**.
   - ✅ *Check:* an Accounts Receivable settlement is created for this report
     (see step C).

### G. Bank Reconciliation (Bank Reconciliation menu)
1. Export two CSVs from your card terminal and your bank, then:
   **Card‑Terminal Settlement** → choose file → **Preview & Map** (tell it
   which columns are date / amount / type) → **Import**.
2. Do the same for the **Bank Statement** CSV.
3. Click **Run Reconciliation**. Read the **settlement batches**:
   - **matched** — terminal total = bank credit
   - **fee_variance** — bank paid less by the card fee (MDR shown)
   - **short / over / unmatched** — investigate in the **Exceptions** list
   - **not_expected** — cash (not a bank credit)
   - 💡 Templates: use the on‑screen **Download template** if unsure of format.

### H. SST & e‑Invoicing (e‑Invoicing menu)
1. **SST Summary tab**: set a period → see **output tax** by tax code
   (Service Tax 6%), net of refunds — this is your SST‑02 figure.
2. **Generate a consolidated e‑Invoice**: pick an outlet + month →
   **Generate**. This rolls up all walk‑in (B2C) receipts not already
   individually invoiced — the normal kiosk/restaurant workflow.
3. **Generate a per‑sale e‑Invoice**: enter a sales transaction ID (+ buyer
   name/TIN for B2B) → **Generate**.
4. Click **Print** on any e‑invoice → a tax invoice with the validation QR.
   - Status shows **pending_submission** until MyInvois API keys are
     configured by the admin; the compliant document is still generated.

### I. Reports (Reports menu)
- Pick a report (Sales consolidation, Wastage, Worker performance), a date
  range and a **Group by** dimension → **Run**. Click **CSV** to export.

---

## 4. Tester's checklist

- [ ] Signed in as `acc`, landed on Finance
- [ ] P&L shows revenue/COGS/gross profit; CSV + PDF export work
- [ ] Created a supplier invoice and recorded a payment (AP)
- [ ] Recorded a receipt against a hypermarket settlement (AR)
- [ ] Viewed inventory valuation; noted any negative‑stock flags
- [ ] Closed a period; confirmed a backdated change is blocked; reopened it
- [ ] Imported a hypermarket report; reviewed the variance classes
- [ ] Imported terminal + bank CSVs; ran bank reconciliation; reviewed exceptions
- [ ] Viewed SST summary; generated a consolidated + a per‑sale e‑Invoice; printed one
- [ ] Ran a sales report and exported CSV

---

## 5. Good to know

- **Prices are tax‑inclusive** (Malaysian F&B norm). The system splits out the
  6% Service Tax automatically; the customer total doesn't change.
- **Where the numbers come from:** revenue = QR sales (real‑time); COGS &
  inventory = stock movements at moving‑average cost; AR = imported hypermarket
  reports; AP = supplier invoices (manual or auto from GRN).
- **Refunds & voids** are done by **managers**, not the accountant — but they
  automatically flow into your P&L and SST figures.
- **This is test data.** When you're ready to go live, the admin will replace
  the demo company/outlets/products with your real data.

## 6. If something looks wrong

- A figure seems off → check the **date range** first (most screens are
  period‑filtered).
- A page won't load → press **Ctrl+F5** (hard refresh).
- Still stuck → note the screen + what you clicked and send it to the admin;
  every action is logged so it's easy to trace.
