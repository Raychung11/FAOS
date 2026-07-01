<?php
/** AdvisorOS — Client portal: read-only Business Valuation report. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';
require_once __DIR__ . '/../includes/valuation.php';
require_once __DIR__ . '/../includes/valuation_report_render.php';
require_once __DIR__ . '/../includes/risk_engine.php';

$c   = portal_client();
$pdo = db();
$tid = (int) $c['tenant_id'];
$v   = client_valuation($pdo, $tid, (int) $c['id']);

$pageTitle = 'Business Valuation';
require __DIR__ . '/../includes/header.php';
?>
<style>
  @media print { .sidebar, .topbar, .noprint { display:none !important; }
    .content, .main { margin:0 !important; padding:0 !important; } }
  .vbody h3 { color:var(--navy); margin:22px 0 8px; font-size:17px }
  .vbody h4 { color:var(--navy); margin:14px 0 6px; font-size:14px; font-weight:600 }
  .vbody p { line-height:1.6; margin:0 0 8px; font-size:14px }
  .vbody .tnum { text-align:right; white-space:nowrap }
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Business Valuation</h2>
    <span class="muted" style="font-size:13px">Indicative equity value across your companies — prepared by your adviser.</span>
  </div>
  <div class="noprint" style="display:flex;gap:8px">
    <button class="btn-os sm" onclick="window.print()">Print / Save PDF</button>
    <a class="btn-os ghost sm" href="<?= e(url('client/portal.php')) ?>">Back to portal</a>
  </div>
</div>

<div class="vbody">
  <?= render_valuation_body($c, $v) ?>
  <div class="card-os" style="margin-top:18px"><div class="card-os-body">
    <div class="disclaimer">This is an indicative estimate for advisory
      discussion only — not a formal/independent valuation, audit, fairness
      opinion or transaction document. Engage a licensed valuer for any binding
      purpose. Your adviser will confirm any next steps with you.</div>
  </div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
