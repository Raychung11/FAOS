<div class="login-wrap">
  <div class="login-card">
    <h2>FAOS BOS</h2>
    <p class="sub">AI Central Kitchen + QR Kiosk Sales</p>
    <?php if (!empty($error)): ?>
      <div class="badge b-dng" style="display:block;padding:10px;text-align:center;margin-bottom:14px">
        <?= e($error) ?>
      </div>
    <?php endif; ?>
    <form method="post" action="<?= base_url('/login') ?>">
      <?= csrf_field() ?>
      <label>Username</label>
      <input name="username" autocomplete="username" required autofocus>
      <label>Password / PIN</label>
      <input name="password" type="password" autocomplete="current-password" required>
      <label style="display:flex;align-items:center;gap:8px;font-weight:400;color:var(--ink)">
        <input type="checkbox" name="pin_mode" value="1" style="width:auto"> Login with kiosk PIN
      </label>
      <button class="btn lg mt" type="submit">Sign in</button>
    </form>
    <p class="muted center" style="font-size:12px;margin-top:18px">
      Demo: admin/admin123 · manager/manager123 · worker1/worker123
    </p>
  </div>
</div>
