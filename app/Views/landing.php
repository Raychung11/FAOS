<style>
.lp{--ink:#0f172a;--mute:#475569;--soft:#f1f5f9;--line:#e2e8f0;--pri:#2563eb;--pri-d:#1d4ed8;--ok:#16a34a;color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.lp,.lp *{box-sizing:border-box;margin:0;padding:0}
.lp{background:#fff}
.lp .container{max-width:1120px;margin:0 auto;padding:0 22px}
.lp nav{position:sticky;top:0;background:rgba(255,255,255,.92);backdrop-filter:saturate(180%) blur(8px);border-bottom:1px solid var(--line);z-index:50}
.lp nav .container{display:flex;align-items:center;gap:24px;padding-top:14px;padding-bottom:14px}
.lp .brand{font-weight:800;font-size:18px;display:flex;align-items:center;gap:8px}
.lp .brand .dot{width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#2563eb,#1e40af);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:14px}
.lp nav a{color:var(--mute);text-decoration:none;font-size:14px;font-weight:500}
.lp nav a:hover{color:var(--ink)}
.lp .spacer{flex:1}
.lp .btn{display:inline-flex;align-items:center;gap:8px;background:var(--pri);color:#fff!important;border:none;border-radius:10px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:.15s}
.lp .btn:hover{background:var(--pri-d)}
.lp .btn.ghost{background:transparent;color:var(--pri)!important;border:1px solid var(--pri)}
.lp .btn.lg{padding:14px 22px;font-size:15px}
.lp section{padding:84px 0;border-bottom:1px solid var(--line)}
.lp .hero{padding:90px 0 70px;background:radial-gradient(1100px 500px at 50% -100px,#dbeafe 0,transparent 60%),#fff}
.lp .hero .grid{display:grid;grid-template-columns:1.3fr 1fr;gap:48px;align-items:center}
.lp .eyebrow{display:inline-block;background:#dbeafe;color:#1e40af;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;margin-bottom:18px}
.lp h1{font-size:48px;line-height:1.1;letter-spacing:-.02em;font-weight:800;margin-bottom:18px}
.lp h1 .accent{background:linear-gradient(135deg,#2563eb,#7c3aed);-webkit-background-clip:text;background-clip:text;color:transparent}
.lp .lead{font-size:18px;color:var(--mute);line-height:1.55;max-width:560px;margin-bottom:28px}
.lp .cta{display:flex;gap:12px;flex-wrap:wrap}
.lp .hero .visual{background:#0f172a;border-radius:18px;padding:28px;color:#cbd5e1;box-shadow:0 30px 60px -20px rgba(15,23,42,.4)}
.lp .hero .visual .qrwrap{background:#fff;border-radius:12px;padding:18px;text-align:center}
.lp .hero .visual .qrwrap img{width:100%;max-width:240px}
.lp .hero .visual .meta{font-size:12px;color:#94a3b8;margin-top:14px;text-align:center;font-family:ui-monospace,Menlo,monospace}
.lp .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;margin-top:60px;padding:24px;background:#fff;border:1px solid var(--line);border-radius:16px}
.lp .stats div{text-align:center}
.lp .stats .v{font-size:28px;font-weight:800;color:var(--pri)}
.lp .stats .l{font-size:12px;color:var(--mute);margin-top:4px}
.lp h2{font-size:34px;line-height:1.2;letter-spacing:-.01em;font-weight:800;margin-bottom:14px;text-align:center}
.lp .sub{font-size:17px;color:var(--mute);text-align:center;max-width:680px;margin:0 auto 48px}
.lp .pain{background:var(--soft)}
.lp .pain .row{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.lp .pain .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px}
.lp .pain .card h3{font-size:16px;font-weight:700;margin-bottom:8px}
.lp .pain .card p{color:var(--mute);font-size:14px;line-height:1.6}
.lp .feat .row{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.lp .feat .card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px;transition:.15s}
.lp .feat .card:hover{border-color:var(--pri);transform:translateY(-2px);box-shadow:0 12px 28px -16px rgba(37,99,235,.4)}
.lp .feat .icon{width:40px;height:40px;border-radius:10px;background:#dbeafe;color:var(--pri);display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:14px}
.lp .feat h3{font-size:16px;font-weight:700;margin-bottom:6px}
.lp .feat p{color:var(--mute);font-size:14px;line-height:1.55}
.lp .flow{background:var(--soft)}
.lp .flow .steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.lp .flow .step{background:#fff;border:1px solid var(--line);border-radius:14px;padding:22px;position:relative}
.lp .flow .num{position:absolute;top:-12px;left:22px;width:28px;height:28px;border-radius:50%;background:var(--pri);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
.lp .flow h3{font-size:15px;margin:10px 0 6px;font-weight:700}
.lp .flow p{font-size:13px;color:var(--mute);line-height:1.55}
.lp .roles .row{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.lp .roles .role{border:1px solid var(--line);border-radius:14px;padding:20px}
.lp .roles .role b{display:block;font-size:14px;margin-bottom:6px}
.lp .roles .role span{color:var(--mute);font-size:13px}
.lp .my{background:#0f172a;color:#cbd5e1}
.lp .my h2{color:#fff}.lp .my .sub{color:#94a3b8}
.lp .my .row{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}
.lp .my .card{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:24px}
.lp .my .card h3{color:#fff;font-size:16px;margin-bottom:8px}
.lp .my .card p{color:#94a3b8;font-size:14px;line-height:1.55}
.lp .cta-section{background:linear-gradient(135deg,#2563eb,#1e3a8a);color:#fff;text-align:center;padding:70px 0}
.lp .cta-section h2{color:#fff}.lp .cta-section .sub{color:#dbeafe}
.lp .cta-section .demo{display:inline-block;margin-top:18px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:14px 18px;font-family:ui-monospace,Menlo,monospace;font-size:13px;color:#fff}
.lp footer{padding:30px 0;color:var(--mute);font-size:13px;text-align:center}
@media(max-width:880px){
  .lp .hero .grid,.lp .stats,.lp .pain .row,.lp .feat .row,.lp .flow .steps,.lp .roles .row,.lp .my .row{grid-template-columns:1fr}
  .lp h1{font-size:34px}.lp h2{font-size:26px}
  .lp section{padding:60px 0}
  .lp .hero{padding:60px 0 40px}
}
</style>

<div class="lp">
  <nav><div class="container">
    <div class="brand"><span class="dot">☕</span> FAOS BOS</div>
    <div class="spacer"></div>
    <a href="#features">Features</a>
    <a href="#how">How it works</a>
    <a href="#malaysia">For Malaysia</a>
    <a class="btn" href="<?= base_url('/login') ?>">Sign in →</a>
  </div></nav>

  <header class="hero"><div class="container">
    <div class="grid">
      <div>
        <span class="eyebrow">AI Central Kitchen · QR Kiosk Sales BOS</span>
        <h1>Run F&amp;B kiosks <span class="accent">without owning the POS</span>.</h1>
        <p class="lead">FAOS BOS lets multi-outlet kiosk and restaurant operators track sales by QR scan in real time, manage central-kitchen production, reconcile delayed hypermarket reports — and stay on top of SST &amp; LHDN e-invoicing — all in one self-hosted system.</p>
        <div class="cta">
          <a class="btn lg" href="<?= base_url('/login') ?>">Sign in to the demo</a>
          <a class="btn ghost lg" href="#features">See what it does</a>
        </div>
      </div>
      <div class="visual">
        <div class="qrwrap"><img alt="QR sample" src="/api/qr/FAOS-BOS-DEMO/image?fmt=svg&amp;scale=8"></div>
        <div class="meta">STK-2026-DEMO · scan to ring a sale</div>
      </div>
    </div>
    <div class="stats">
      <div><div class="v">3–4 wk</div><div class="l">Hypermarket report lag — handled</div></div>
      <div><div class="v">&lt; 5s</div><div class="l">Per QR sale at the kiosk</div></div>
      <div><div class="v">Zero</div><div class="l">External JS/CSS dependencies</div></div>
      <div><div class="v">100%</div><div class="l">Self-hosted, PHP 8 + MySQL 8</div></div>
    </div>
  </div></header>

  <section class="pain"><div class="container">
    <h2>Built for the actual problem</h2>
    <p class="sub">Most POS systems assume you own the till. Hypermarket kiosks don't — and the reports arrive weeks late. FAOS BOS was built around that reality.</p>
    <div class="row">
      <div class="card"><h3>No POS dependency</h3><p>Workers scan product QR codes on a phone — every sale is captured in real time even when the hypermarket's POS report arrives 3–4 weeks late.</p></div>
      <div class="card"><h3>Reconciles automatically</h3><p>When the official report does arrive, the engine matches it line-by-line against your QR sales and flags <em>shortage / over‑reported / missing scans</em>.</p></div>
      <div class="card"><h3>Works offline</h3><p>Sales are queued in the browser and sync the moment the kiosk is back online — idempotent, no double-counts.</p></div>
    </div>
  </div></section>

  <section class="feat" id="features"><div class="container">
    <h2>Everything one F&amp;B operator needs</h2>
    <p class="sub">One system from supplier PO to kitchen to kiosk till to LHDN e-invoice — built for multi-outlet, multi-role operations.</p>
    <div class="row">
      <div class="card"><div class="icon">📷</div><h3>QR Sales Tracking</h3><p>Phone-camera scan (works on iPhone/Android via jsQR), instant stock deduction, recipe-aware consumption, offline queue.</p></div>
      <div class="card"><div class="icon">📦</div><h3>QR Stock Control</h3><p>Receive, transfer, consume, wastage, stock-count — every movement traceable from supplier to sale.</p></div>
      <div class="card"><div class="icon">🍳</div><h3>Central Kitchen</h3><p>Recipe/BOM production orders, FEFO-tagged ingredient consumption, moving-average cost on the output.</p></div>
      <div class="card"><div class="icon">🚚</div><h3>Procurement &amp; PO</h3><p>Supplier PO → approve → GRN with batch + expiry → auto-raised AP invoice. Import POs from CSV.</p></div>
      <div class="card"><div class="icon">📊</div><h3>HQ &amp; AI Dashboards</h3><p>Sales by outlet/kiosk/worker/SKU, low-stock + expiry alerts, demand forecast + purchase recommendations.</p></div>
      <div class="card"><div class="icon">🔁</div><h3>Hypermarket Reconciliation</h3><p>Import the delayed official report → variance matrix per line (matched / shortage / over / missing scans).</p></div>
      <div class="card"><div class="icon">🏦</div><h3>Bank Reconciliation</h3><p>Upload card-terminal Z-reading + bank statement → batch/line match with MDR-fee tolerance, exceptions worklist.</p></div>
      <div class="card"><div class="icon">💰</div><h3>Operational Finance</h3><p>Accounts Payable, Accounts Receivable, P&amp;L (revenue / COGS / gross profit), inventory valuation, SST output tax.</p></div>
      <div class="card"><div class="icon">🧾</div><h3>SST + LHDN e-Invoice</h3><p>Service Tax 6% on prepared F&amp;B, individual or consolidated B2C e-invoice with validation QR — MyInvois ready.</p></div>
      <div class="card"><div class="icon">↩️</div><h3>Sales Corrections</h3><p>Discounts, voids and refunds with full stock reversal; nets through to P&amp;L and SST. Fraud-controlled (manager+).</p></div>
      <div class="card"><div class="icon">🔒</div><h3>Period Close</h3><p>Freeze a closed accounting period — backdated sales/voids/refunds are rejected automatically.</p></div>
      <div class="card"><div class="icon">👥</div><h3>Roles &amp; Audit</h3><p>RBAC for super-admin, HQ, outlet manager, restaurant manager, accountant, worker. Every action audited.</p></div>
    </div>
  </div></section>

  <section class="flow" id="how"><div class="container">
    <h2>How it flows, every day</h2>
    <p class="sub">From the supplier truck to the till to the accountant's SST-02 return — one system, no spreadsheets in between.</p>
    <div class="steps">
      <div class="step"><span class="num">1</span><h3>Buy &amp; produce</h3><p>Raise PO → receive at warehouse → central kitchen produces finished goods against a recipe.</p></div>
      <div class="step"><span class="num">2</span><h3>Distribute</h3><p>Outlets raise a replenishment request → approved → stock transfers from CK to kiosks/restaurants.</p></div>
      <div class="step"><span class="num">3</span><h3>Sell by QR</h3><p>Worker scans the product QR on a phone → sale recorded, stock deducted, e-invoice ready.</p></div>
      <div class="step"><span class="num">4</span><h3>Reconcile &amp; close</h3><p>Match delayed hypermarket report + bank statement → P&amp;L, SST &amp; AR all in one place.</p></div>
    </div>
  </div></section>

  <section class="roles"><div class="container">
    <h2>One system, every role</h2>
    <p class="sub">From the worker's phone to the accountant's monthly close.</p>
    <div class="row">
      <div class="role"><b>👤 Worker</b><span>QR sales scan, shift open/close, offline-safe queue, today's earnings.</span></div>
      <div class="role"><b>🏪 Outlet / Restaurant Manager</b><span>Outlet dashboard, replenishment requests, sales corrections (void/refund), reports.</span></div>
      <div class="role"><b>📊 HQ Manager</b><span>Multi-outlet ranking, products top sellers, expiry alerts, master data, procurement.</span></div>
      <div class="role"><b>💰 Accountant</b><span>AP, AR, P&amp;L, SST summary, bank reconciliation, e-invoices, period close.</span></div>
      <div class="role"><b>🍳 Kitchen Staff</b><span>Production orders, ingredient consumption, batch &amp; expiry tracking.</span></div>
      <div class="role"><b>🛠 Super Admin</b><span>Users &amp; roles, master data, bulk import, full audit log.</span></div>
    </div>
  </div></section>

  <section class="my" id="malaysia"><div class="container">
    <h2>Built for Malaysian F&amp;B</h2>
    <p class="sub">SST handling, e-invoice format, hypermarket workflows — out of the box. Deployable on Hostinger or any LAMP host.</p>
    <div class="row">
      <div class="card"><h3>🇲🇾 SST 6% &amp; MyInvois e-Invoice</h3><p>Service Tax on prepared F&amp;B; per-sale e-invoice for B2B, monthly consolidated B2C e-invoice for kiosks/restaurants. LHDN UBL JSON ready; flip on with API keys.</p></div>
      <div class="card"><h3>🏬 Hypermarket-native</h3><p>Designed for AEON, Lotus's, Mydin, Giant — outlets with delayed third-party POS reports and consignment-style settlement.</p></div>
      <div class="card"><h3>🖥 Self-hosted on Hostinger</h3><p>Pure PHP 8 + MySQL 8, zero CDN dependencies. Import <code>database/reset.sql</code> in phpMyAdmin and you're live.</p></div>
      <div class="card"><h3>📱 Mobile-first kiosk UX</h3><p>QR scan with the phone camera (works on iPhone/Android), &lt; 5-second sale, USB barcode-gun friendly, dark mode.</p></div>
    </div>
  </div></section>

  <section class="cta-section"><div class="container">
    <h2>Try the live demo</h2>
    <p class="sub">Sign in with any of the demo accounts to explore each role end-to-end.</p>
    <div><a class="btn lg" style="background:#fff;color:var(--pri)!important" href="<?= base_url('/login') ?>">Open the app →</a></div>
    <div class="demo">admin / admin123 &nbsp;·&nbsp; manager / manager123 &nbsp;·&nbsp; worker1 / worker123 (PIN 1234) &nbsp;·&nbsp; acc / acc123</div>
  </div></section>

  <footer><div class="container">
    © <?= date('Y') ?> FAOS BOS · Caffeinees Palace Sdn Bhd · Built in Malaysia.
  </div></footer>
</div>
