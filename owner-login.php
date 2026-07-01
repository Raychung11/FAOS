<?php
/** AdvisorOS — Business-owner login (public). */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/owner_auth.php';

if (owner_is_logged_in()) { redirect('owner-portal.php'); }

$errors = [];
$email  = '';

if (is_post()) {
    csrf_check();
    $email    = strtolower(trim((string) input('email', '')));
    $password = (string) ($_POST['password'] ?? '');

    [$ok, $msg] = owner_login($email, $password);
    if (!$ok) {
        $errors[] = $msg;
    } else {
        set_flash('success', 'Signed in.');
        redirect('owner-portal.php');
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/includes/auth_header.php';
?>
<h2>Sign in</h2>
<div class="sub">Business-owner account for saving equity assessments and
   valuation reports on this platform.</div>

<?php if ($errors): ?>
  <div class="alert-os danger" style="margin-bottom:14px">
    <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-row">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus maxlength="190"
           value="<?= e($email) ?>">
  </div>
  <div class="form-row">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" required maxlength="128">
  </div>
  <button type="submit" class="btn-os gold" style="width:100%;justify-content:center">Sign in</button>
</form>
<p class="muted mt-3" style="font-size:12.5px;text-align:center">
  Don't have an account yet?
  <a href="<?= e(url('owner-signup.php' . (!empty($_GET['token']) ? '?token=' . e($_GET['token']) : ''))) ?>">Create one</a>
</p>
<p class="muted mt-3" style="font-size:11.5px;text-align:center">
  Advisers &amp; firm staff: use the <a href="<?= e(url('login.php')) ?>">advisor sign-in</a> instead.
</p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
