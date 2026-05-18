<?php
/** AdvisorOS — Login. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(role_home(current_user()['role_code']));
}

if (is_post()) {
    csrf_check();
    $email    = strtolower((string) input('email', ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    flash_old(['email' => $email]);

    if ($email === '' || $password === '') {
        set_flash('danger', 'Please enter your email and password.');
        redirect('login.php');
    }

    [$ok, $msg] = attempt_login($email, $password, $remember);

    if ($ok) {
        audit_log('login', 'auth', (int) current_user()['id'], 'User signed in');
        clear_old();
        redirect(role_home(current_user()['role_code']));
    }

    set_flash('danger', $msg);
    redirect('login.php');
}

$pageTitle = 'Sign in';
require __DIR__ . '/includes/auth_header.php';
?>
<h2>Welcome back</h2>
<div class="sub">Sign in to your advisory workspace.</div>
<form method="post" action="<?= e(url('login.php')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="form-row">
    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required autofocus>
  </div>
  <div class="form-row">
    <label for="password">Password</label>
    <div style="position:relative">
      <input type="password" id="password" name="password" required
             style="width:100%;padding-right:62px;box-sizing:border-box">
      <button type="button" id="pwToggle" aria-label="Show password" aria-pressed="false"
        style="position:absolute;top:50%;right:10px;transform:translateY(-50%);
               background:none;border:0;cursor:pointer;font-size:12.5px;
               font-weight:600;color:var(--navy-600)">Show</button>
    </div>
  </div>
  <div class="form-row" style="display:flex;justify-content:space-between;align-items:center">
    <label style="font-weight:500;display:flex;gap:8px;align-items:center;margin:0">
      <input type="checkbox" name="remember" value="1" style="width:auto"> Remember me
    </label>
    <a href="<?= e(url('forgot-password.php')) ?>" style="font-size:13px">Forgot password?</a>
  </div>
  <button type="submit" class="btn-os" style="width:100%;justify-content:center">Sign in</button>
</form>
<a class="btn-os ghost" href="<?= e(url('index.php')) ?>"
   style="width:100%;justify-content:center;margin-top:10px">← Back to home</a>
<p class="muted mt-3" style="font-size:12.5px;text-align:center">
  Protected by session security, CSRF protection and brute-force throttling.
</p>
<script>
(function () {
  var b = document.getElementById('pwToggle'), p = document.getElementById('password');
  if (!b || !p) return;
  b.addEventListener('click', function () {
    var show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    b.textContent = show ? 'Hide' : 'Show';
    b.setAttribute('aria-pressed', show ? 'true' : 'false');
    b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    p.focus();
  });
})();
</script>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
