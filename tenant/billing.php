<?php
/** AdvisorOS — Firm billing & referrals (tenant admin).
 *  Shows the firm its plan, metered report usage, estimated invoice
 *  and its referral code / earned credit. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('tenant.manage');

$tid = require_tenant();
$pdo = db();

if (is_post()) {
    csrf_check();
    if (input('action') === 'claim') {
        $r = referral_attribute($tid, (string) input('code', ''));
        set_flash($r['ok'] ? 'success' : 'danger', $r['msg']);
    }
    redirect('tenant/billing.php');
}

$ym  = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$row = $pdo->prepare(
    "SELECT t.id, t.company_name, t.subscription_status,
            sp.name AS plan_name,
            sp.price_monthly AS plan_price_monthly,
            sp.features AS plan_features
     FROM tenants t
     LEFT JOIN subscription_plans sp ON sp.id = t.plan_id
     WHERE t.id = ? AND t.deleted_at IS NULL"
);
$row->execute([$tid]);
$tenant = $row->fetch() ?: ['id' => $tid, 'company_name' => '', 'subscription_status' => 'trial',
    'plan_name' => null, 'plan_price_monthly' => 0, 'plan_features' => null];

$bill  = tenant_bill($tenant, $ym);
$usage = tenant_usage($tid, $ym);
$ref   = referral_state($tid);
$share = 'Join us on AdvisorOS — use referral code ' . $ref['code']
    . ' when you subscribe and we both earn RM ' . number_format(referral_credit_rm(), 2) . ' credit.';

$pageTitle = 'Billing & Referrals';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Billing &amp; Referrals</h2>
    <span class="muted"><?= e($tenant['plan_name'] ?? 'No plan') ?> ·
      status <?= e(label($tenant['subscription_status'])) ?></span>
  </div>
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <label class="muted" style="font-size:13px">Month</label>
    <input type="month" name="m" value="<?= e($ym) ?>" onchange="this.form.submit()">
  </form>
</div>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Plan Base</div>
    <div class="stat-value">RM <?= money($bill['base']) ?></div>
    <div class="stat-foot">per month</div></div>
  <div class="stat accent"><div class="stat-label">Reports Used</div>
    <div class="stat-value"><?= (int) $bill['used'] ?> <span class="muted" style="font-size:13px">/ <?= (int) $bill['quota'] ?> free</span></div>
    <div class="stat-foot"><?= (int) $usage['proposal'] ?> proposal · <?= (int) $usage['capability'] ?> capability · <?= (int) $usage['tax'] ?> tax · <?= (int) $usage['wealth'] ?> wealth · <?= (int) $usage['risk'] ?> risk</div></div>
  <div class="stat accent"><div class="stat-label">Usage Charges</div>
    <div class="stat-value">RM <?= money($bill['usage_cost']) ?></div>
    <div class="stat-foot"><?= (int) $bill['overage'] ?> over quota @ RM <?= money($bill['price']) ?></div></div>
  <div class="stat accent"><div class="stat-label">Estimated Invoice</div>
    <div class="stat-value">RM <?= money($bill['total']) ?></div>
    <div class="stat-foot"><?= $bill['credit_applied'] > 0 ? 'after − RM ' . money($bill['credit_applied']) . ' credit' : e($ym) ?></div></div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head">Invoice breakdown — <?= e($ym) ?></div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <tbody>
          <tr><td>Subscription (<?= e($tenant['plan_name'] ?? '—') ?>)</td>
              <td style="text-align:right">RM <?= money($bill['base']) ?></td></tr>
          <tr><td><?= (int) $bill['used'] ?> reports · <?= (int) $bill['quota'] ?> included free</td>
              <td style="text-align:right">RM 0.00</td></tr>
          <tr><td><?= (int) $bill['overage'] ?> extra reports @ RM <?= money($bill['price']) ?></td>
              <td style="text-align:right">RM <?= money($bill['usage_cost']) ?></td></tr>
          <tr><td>Referral credit applied</td>
              <td style="text-align:right;color:var(--ok)">− RM <?= money($bill['credit_applied']) ?></td></tr>
          <tr><td><strong>Estimated total</strong></td>
              <td style="text-align:right"><strong>RM <?= money($bill['total']) ?></strong></td></tr>
        </tbody>
      </table>
      <div class="disclaimer">Estimate based on metered usage to date this
        month. Final invoice is issued by the platform operator.</div>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Refer a firm, earn RM <?= money(referral_credit_rm()) ?></div>
    <div class="card-os-body">
      <p class="muted" style="margin-top:0;font-size:13px">Share your code.
        When a firm subscribes using it, you earn
        RM <?= money(referral_credit_rm()) ?> off your next invoice.</p>
      <div class="form-row"><label>Your referral code</label>
        <input id="refc" value="<?= e($ref['code']) ?>" readonly
          style="font-family:monospace;font-weight:600"></div>
      <button type="button" class="btn-os ghost sm"
        onclick="navigator.clipboard.writeText(document.getElementById('refc').value);this.textContent='Copied';">
        Copy code</button>
      <button type="button" class="btn-os ghost sm"
        onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($share), ENT_QUOTES) ?>);this.textContent='Copied';">
        Copy invite message</button>

      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
        <div class="muted" style="font-size:13px">Credit earned:
          <strong>RM <?= money($ref['credit_rm']) ?></strong> ·
          <?= count($ref['signups']) ?> referred signup(s)</div>
        <?php if ($ref['referred_by'] === ''): ?>
          <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:flex-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="claim">
            <div class="form-row" style="margin:0;flex:1">
              <label>Were you referred? Enter their code</label>
              <input name="code" placeholder="FAOS-XXXXXX"></div>
            <button class="btn-os sm">Apply</button>
          </form>
        <?php else: ?>
          <div class="muted" style="font-size:13px;margin-top:8px">
            Referred by <code><?= e($ref['referred_by']) ?></code>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
