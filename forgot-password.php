<?php
/** AdvisorOS — Request a password reset link. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$devLink = null;

if (is_post()) {
    csrf_check();
    $email = strtolower((string) input('email', ''));

    if ($email !== '' && is_valid_email($email)) {
        $user = find_user_by_email($email);
        if ($user) {
            $token = random_token(32);
            $stmt = db()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, (NOW() + INTERVAL 60 MINUTE))'
            );
            $stmt->execute([(int) $user['id'], hash('sha256', $token)]);
            audit_log('password_reset_requested', 'auth', (int) $user['id']);

            $link = url('reset-password.php?uid=' . (int) $user['id'] . '&token=' . $token);
            // Phase 2: dispatch via PHPMailer. In debug we surface the link.
            if (APP_DEBUG) {
                $devLink = $link;
            } else {
                error_log('[AdvisorOS] Password reset link for ' . $email . ': ' . $link);
            }
        }
    }
    // Always respond identically to avoid account enumeration.
    if ($devLink === null) {
        set_flash('info', 'If that email is registered, a reset link has been sent.');
        redirect('forgot-password.php');
    }
}

$pageTitle = 'Forgot password';
require __DIR__ . '/includes/auth_header.php';
?>
<h2>Reset your password</h2>
<div class="sub">Enter your account email and we'll send a reset link.</div>
<?php if ($devLink): ?>
  <div class="alert-os warning">Debug mode — reset link:<br>
    <a href="<?= e($devLink) ?>" style="word-break:break-all"><?= e($devLink) ?></a>
  </div>
<?php endif; ?>
<form method="post" action="<?= e(url('forgot-password.php')) ?>">
  <?= csrf_field() ?>
  <div class="form-row">
    <label for="email">Email address</label>
    <input type="email" id="email" name="email" required autofocus>
  </div>
  <button type="submit" class="btn-os" style="width:100%;justify-content:center">Send reset link</button>
</form>
<p class="mt-3" style="text-align:center"><a href="<?= e(url('login.php')) ?>">Back to sign in</a></p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
