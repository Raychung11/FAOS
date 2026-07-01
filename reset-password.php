<?php
/** AdvisorOS — Complete a password reset. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$uid   = (int) ($_GET['uid'] ?? $_POST['uid'] ?? 0);
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

/** Validate the token and return the open reset row, or null. */
function valid_reset(int $uid, string $token): ?array
{
    if ($uid <= 0 || $token === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT * FROM password_resets
         WHERE user_id = ? AND token_hash = ? AND used_at IS NULL
           AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$uid, hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

$reset = valid_reset($uid, $token);

if (is_post()) {
    csrf_check();
    if (!$reset) {
        set_flash('danger', 'This reset link is invalid or has expired.');
        redirect('forgot-password.php');
    }
    $p1 = (string) ($_POST['password'] ?? '');
    $p2 = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($p1) < 8) {
        set_flash('danger', 'Password must be at least 8 characters.');
    } elseif ($p1 !== $p2) {
        set_flash('danger', 'Passwords do not match.');
    } else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET password_hash = ?, remember_token = NULL WHERE id = ?')
            ->execute([$hash, $uid]);
        db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
            ->execute([(int) $reset['id']]);
        audit_log('password_reset_completed', 'auth', $uid);
        set_flash('success', 'Password updated. Please sign in.');
        redirect('login.php');
    }
    redirect('reset-password.php?uid=' . $uid . '&token=' . urlencode($token));
}

$pageTitle = 'Set new password';
require __DIR__ . '/includes/auth_header.php';
?>
<h2>Set a new password</h2>
<?php if (!$reset): ?>
  <div class="alert-os danger">This reset link is invalid or has expired.</div>
  <p class="mt-3" style="text-align:center"><a href="<?= e(url('forgot-password.php')) ?>">Request a new link</a></p>
<?php else: ?>
  <div class="sub">Choose a strong password (minimum 8 characters).</div>
  <form method="post" action="<?= e(url('reset-password.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="uid" value="<?= (int) $uid ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="form-row">
      <label for="password">New password</label>
      <input type="password" id="password" name="password" required autofocus>
    </div>
    <div class="form-row">
      <label for="password_confirm">Confirm password</label>
      <input type="password" id="password_confirm" name="password_confirm" required>
    </div>
    <button type="submit" class="btn-os" style="width:100%;justify-content:center">Update password</button>
  </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
