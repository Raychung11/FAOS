<?php
/** AdvisorOS — Platform revenue & referral program (super admin).
 *  Subscription MRR + metered per-report usage − referral credits. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/billing.php';
require_role('super_admin');
require_permission('platform.manage');

$pdo = db();

if (is_post()) {
    csrf_check();
    if (input('action') === 'attribute') {
        $r = referral_attribute((int) input('tenant_id', 0), (string) input('code', ''));
        set_flash($r['ok'] ? 'success' : 'danger', $r['msg']);
        if ($r['ok']) {
            audit_log('update', 'platform', (int) input('tenant_id', 0), 'Referral attributed');
        }
    }
    redirect('superadmin/revenue.php');
}

$ym  = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$rev = platform_revenue($ym);

$pageTitle = 'Platform Revenue';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Platform Revenue &amp; Marketing</h2>
    <span class="muted">Subscription + metered per-report usage − referral credits</span>
  </div>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <label class="muted" style="font-size:13px">Month</label>
    <input type="month" name="m" value="<?= e($ym) ?>" onchange="this.form.submit()">
  </form>
</div>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Subscription MRR</div>
    <div class="stat-value">RM <?= money($rev['mrr']) ?></div>
    <div class="stat-foot">recurring base across tenants</div></div>
  <div class="stat accent"><div class="stat-label">Usage Revenue</div>
    <div class="stat-value">RM <?= money($rev['usage']) ?></div>
    <div class="stat-foot"><?= (int) $rev['reports'] ?> reports metered</div></div>
  <div class="stat accent"><div class="stat-label">Referral Credits</div>
    <div class="stat-value" style="color:var(--danger)">− RM <?= money($rev['credits']) ?></div>
    <div class="stat-foot">applied this cycle</div></div>
  <div class="stat accent"><div class="stat-label">Net Billable</div>
    <div class="stat-value">RM <?= money($rev['net']) ?></div>
    <div class="stat-foot"><?= e($ym) ?></div></div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Per-tenant billing — <?= e($ym) ?></div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>Tenant</th><th>Plan</th><th>Base</th><th>Reports</th>
        <th>Overage</th><th>Usage RM</th><th>Credit</th><th>Net</th></tr></thead>
      <tbody>
      <?php foreach ($rev['rows'] as $r): ?>
        <tr>
          <td><strong><?= e($r['company_name']) ?></strong>
            <span class="badge-os b-<?= $r['subscription_status']==='active'?'active':'warn' ?>"
              style="font-size:11px"><?= e(label($r['subscription_status'])) ?></span></td>
          <td><?= e($r['plan_name'] ?? '—') ?></td>
          <td>RM <?= money($r['base']) ?></td>
          <td><?= (int) $r['used'] ?> / <?= (int) $r['quota'] ?></td>
          <td><?= (int) $r['overage'] ?> @ RM <?= money($r['price']) ?></td>
          <td>RM <?= money($r['usage_cost']) ?></td>
          <td><?= $r['credit_applied'] > 0 ? '− RM ' . money($r['credit_applied']) : '—' ?></td>
          <td><strong>RM <?= money($r['total']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Referral Program</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Tenant</th><th>Code</th><th>Signups</th><th>Credit earned</th><th>Referred by</th></tr></thead>
        <tbody>
        <?php foreach ($rev['rows'] as $r): $rs = referral_state((int) $r['id']); ?>
          <tr>
            <td><?= e($r['company_name']) ?></td>
            <td><code><?= e($rs['code']) ?></code></td>
            <td><?= count($rs['signups']) ?></td>
            <td>RM <?= money($rs['credit_rm']) ?></td>
            <td><?= $rs['referred_by'] !== '' ? '<code>' . e($rs['referred_by']) . '</code>' : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Attribute a Referral</div>
    <div class="card-os-body">
      <p class="muted" style="margin-top:0;font-size:13px">Record that a firm
        signed up via another firm's code. The referrer is credited
        RM <?= money(referral_credit_rm()) ?>. One attribution per firm.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="attribute">
        <div class="form-row"><label>New / referred firm</label>
          <select name="tenant_id" required>
            <option value="">— select tenant —</option>
            <?php foreach ($rev['rows'] as $r): ?>
              <option value="<?= (int) $r['id'] ?>"><?= e($r['company_name']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row"><label>Referrer's code</label>
          <input name="code" placeholder="FAOS-XXXXXX" required></div>
        <button class="btn-os">Apply referral credit</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
