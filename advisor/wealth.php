<?php
/** AdvisorOS — Personal & Business Wealth Engine (integrated view). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wealth.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Wealth analysis”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    meter_report('wealth');
    audit_log('generate', 'wealth', $clientId, 'Wealth analysis generated');
    set_flash('success', 'Wealth analysis refreshed and logged.');
    redirect('advisor/wealth.php?client_id=' . $clientId);
}

$w = wealth_report($pdo, $tid, $clientId);

$pageTitle = 'Wealth Analysis — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Personal &amp; Business Wealth</h2>
    <span class="muted"><?= e($client['full_name']) ?> · integrated net worth &amp; exposure</span>
  </div>
  <div style="display:flex;gap:8px">
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <button class="btn-os sm">Refresh &amp; log analysis</button>
    </form>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<?php if (!$w): ?>
  <div class="alert-os warning">No data yet. Add a personal financial snapshot
    on the client profile, and/or a company under
    <a href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Business profile</a>,
    then return here.</div>
<?php else: ?>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Total Net Worth</div>
    <div class="stat-value">RM <?= money($w['total_net']) ?></div>
    <div class="stat-foot">personal + attributable business</div></div>
  <div class="stat accent"><div class="stat-label">Business Equity (client share)</div>
    <div class="stat-value">RM <?= money($w['biz_equity']) ?></div>
    <div class="stat-foot"><?= (int) $w['companies'] ?> compan<?= $w['companies']==1?'y':'ies' ?> · EBITDA RM <?= money($w['ebitda']) ?></div></div>
  <div class="stat accent"><div class="stat-label">Personal Net Worth</div>
    <div class="stat-value">RM <?= money($w['p_net']) ?></div>
    <div class="stat-foot"><?= $w['has_personal'] ? 'from latest snapshot' : 'no personal snapshot' ?></div></div>
  <div class="stat accent"><div class="stat-label">Personal-Guarantee Exposure</div>
    <div class="stat-value" style="color:<?= $w['pg_total']>0?'var(--danger)':'inherit' ?>">RM <?= money($w['pg_total']) ?></div>
    <div class="stat-foot"><span class="badge-os <?= $w['pg_cls'] ?>"><?= e($w['pg_lbl']) ?></span> vs personal net worth</div></div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Concentration &amp; dependency · <span class="badge-os <?= $w['concentration_cls'] ?>"><?= e($w['overall']) ?></span></div>
  <div class="card-os-body">
    <?php
      $bar = function (string $label, float $v, string $lbl, string $cls) {
        $p = (int) round(min(1.0, max(0.0, $v)) * 100);
        $col = $cls === 'b-overdue' ? 'var(--danger)' : ($cls === 'b-warn' ? 'var(--gold)' : 'var(--ok)');
        echo '<div style="margin-bottom:14px">'
           . '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:5px">'
           . '<span class="muted">' . e($label) . '</span>'
           . '<strong>' . $p . '% · <span class="badge-os ' . $cls . '">' . e($lbl) . '</span></strong></div>'
           . '<div style="background:#eef2fb;border-radius:6px;height:12px;overflow:hidden">'
           . '<div style="width:' . $p . '%;height:12px;background:' . $col . '"></div></div></div>';
      };
      $bar('Business as a share of total net worth', $w['concentration'], $w['concentration_lbl'], $w['concentration_cls']);
      $bar('Single largest company concentration', $w['largest_conc'],
           $w['largest_conc']>=0.7?'High':($w['largest_conc']>=0.4?'Moderate':'Low'),
           $w['largest_conc']>=0.7?'b-overdue':($w['largest_conc']>=0.4?'b-warn':'b-active'));
      $bar('Household income dependent on the business', $w['income_dep'], $w['income_dep_lbl'], $w['income_dep_cls']);
    ?>
    <div class="muted" style="font-size:12px">Personal-guarantee exposure is
      <strong><?= $w['pg_ratio']>=9 ? '∞' : number_format($w['pg_ratio']*100) . '%' ?></strong>
      of personal net worth — if the business cannot pay, this is what the
      family is personally liable for.</div>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Where the wealth sits</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Company</th><th>Owns</th><th>Company equity</th><th>Client share</th><th>P. guarantee</th></tr></thead>
        <tbody>
          <?php foreach ($w['rows'] as $r): ?>
            <tr>
              <td><strong><?= e($r['name']) ?></strong>
                <?php if (!$r['has_bf']): ?><div class="muted" style="font-size:11px">no financial snapshot</div><?php endif; ?></td>
              <td><?= rtrim(rtrim(number_format($r['ownership'],2),'0'),'.') ?>%</td>
              <td>RM <?= money($r['equity']) ?></td>
              <td><strong>RM <?= money($r['attributable']) ?></strong></td>
              <td><?= $r['pg']>0 ? 'RM '.money($r['pg']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <tr><td class="muted">Personal (net of liabilities)</td><td>—</td>
              <td>—</td><td><strong>RM <?= money($w['p_net']) ?></strong></td><td>—</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Advisor talking points</div>
    <div class="card-os-body">
      <ul style="margin:0 0 6px 18px">
        <?php if ($w['concentration_lbl'] === 'High'): ?>
          <li>Most of the family's wealth is locked inside the business —
            diversification and an exit/liquidity plan should be priorities.</li>
        <?php endif; ?>
        <?php if ($w['pg_lbl'] === 'Critical' || $w['pg_lbl'] === 'High'): ?>
          <li>Personal guarantees of <strong>RM <?= money($w['pg_total']) ?></strong>
            could expose the family's personal assets — review guarantee
            limits, keyman cover and asset protection.</li>
        <?php endif; ?>
        <?php if ($w['income_dep_lbl'] === 'High'): ?>
          <li>Household income depends heavily on the business — protection
            and contingency planning matter if the founder cannot work.</li>
        <?php endif; ?>
        <?php if ($w['overall'] === 'Well diversified'): ?>
          <li>Wealth is reasonably balanced between personal and business —
            focus on optimisation and succession readiness.</li>
        <?php endif; ?>
        <li>Confirm figures with the client; book values are estimates, not
            a formal valuation.</li>
      </ul>
      <div class="disclaimer">Integrated estimate for advisory discussion only
        — book values, not an audited valuation or financial advice. Personal
        guarantees are typically joint &amp; several; confirm actual terms.
        Final recommendations must be reviewed by a licensed advisor.</div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
