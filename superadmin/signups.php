<?php
/** AdvisorOS — Access requests (public signup leads) for super admin. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/signup.php';
require_role('super_admin');
require_permission('platform.manage');

if (is_post()) {
    csrf_check();
    if (input('action') === 'dismiss') {
        signup_dismiss((string) input('id', ''));
        audit_log('delete', 'platform', 0, 'Access request dismissed');
        set_flash('success', 'Request dismissed.');
    }
    redirect('superadmin/signups.php');
}

$rows = signup_all();

$pageTitle = 'Access Requests';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Access Requests</h2>
    <span class="muted">Self-serve signups from the public site</span>
  </div>
  <span class="badge-os b-active"><?= count($rows) ?> open</span>
</div>

<div class="card-os">
  <div class="card-os-head">Incoming requests</div>
  <div class="card-os-body" style="padding:0">
    <?php if (!$rows): ?>
      <div style="padding:18px" class="muted">No open access requests.</div>
    <?php else: ?>
      <table class="table-os">
        <thead><tr><th>When</th><th>Firm / contact</th><th>Email / phone</th>
          <th>Interest</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="white-space:nowrap"><?= e($r['at'] ?? '') ?>
              <div class="muted" style="font-size:11px"><?= e($r['ip'] ?? '') ?></div></td>
            <td><strong><?= e($r['firm'] ?? '') ?></strong><br>
              <span class="muted" style="font-size:12px"><?= e($r['name'] ?? '') ?></span></td>
            <td><a href="mailto:<?= e($r['email'] ?? '') ?>"><?= e($r['email'] ?? '') ?></a>
              <div class="muted" style="font-size:12px"><?= e($r['phone'] ?? '—') ?></div></td>
            <td><?= e($r['plan'] ?: 'Undecided') ?>
              <?php if (!empty($r['advisors'])): ?>
                <div class="muted" style="font-size:12px"><?= (int) $r['advisors'] ?> advisors</div>
              <?php endif; ?>
              <?php if (!empty($r['referral'])): ?>
                <div class="muted" style="font-size:12px">ref <code><?= e($r['referral']) ?></code></div>
              <?php endif; ?></td>
            <td style="max-width:280px"><span class="muted" style="font-size:13px"><?= e($r['message'] ?? '') ?></span></td>
            <td style="text-align:right;white-space:nowrap">
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Dismiss this request?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="id" value="<?= e($r['id'] ?? '') ?>">
                <button class="btn-os ghost sm">Dismiss</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
