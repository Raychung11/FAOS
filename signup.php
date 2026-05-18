<?php
/** AdvisorOS — Public "Request access" signup form (lead capture). */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/signup.php';

if (is_logged_in()) {
    redirect(role_home(current_user()['role_code']));
}

if (is_post()) {
    csrf_check();

    // Honeypot — bots fill the hidden field; humans never see it.
    if (trim((string) ($_POST['company_url'] ?? '')) !== '') {
        redirect('signup.php?sent=1');
    }

    $firm  = trim((string) input('firm', ''));
    $name  = trim((string) input('name', ''));
    $email = strtolower(trim((string) input('email', '')));
    $phone = trim((string) input('phone', ''));
    $plan  = trim((string) input('plan', ''));
    $adv   = (int) input('advisors', 0);
    $ref   = strtoupper(trim((string) input('referral', '')));
    $msg   = trim((string) input('message', ''));

    flash_old(['firm' => $firm, 'name' => $name, 'email' => $email,
               'phone' => $phone, 'advisors' => $adv, 'referral' => $ref, 'message' => $msg]);

    if ($firm === '' || $name === '' || $email === '') {
        set_flash('danger', 'Firm, your name and email are required.');
        redirect('signup.php');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('danger', 'Please enter a valid email address.');
        redirect('signup.php');
    }

    $ok = signup_store([
        'id'       => 'sr_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
        'at'       => date('Y-m-d H:i'),
        'ip'       => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'firm'     => mb_substr($firm, 0, 150),
        'name'     => mb_substr($name, 0, 120),
        'email'    => mb_substr($email, 0, 190),
        'phone'    => mb_substr($phone, 0, 40),
        'plan'     => mb_substr($plan, 0, 40),
        'advisors' => max(0, min(100000, $adv)),
        'referral' => mb_substr($ref, 0, 40),
        'message'  => mb_substr($msg, 0, 1000),
    ]);

    set_flash($ok ? 'success' : 'danger', $ok
        ? 'Thanks — your request was received.'
        : 'We could not record your request. Please email us directly.');
    redirect('signup.php' . ($ok ? '?sent=1' : ''));
}

try {
    $plans = db()->query(
        'SELECT name, code FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly'
    )->fetchAll();
} catch (Throwable $e) {
    $plans = [];
}

$sent = isset($_GET['sent']);
$pageTitle = $sent ? 'Request received' : 'Request access';
require __DIR__ . '/includes/auth_header.php';
?>
<?php if ($sent): ?>
  <h2>You're on the list</h2>
  <div class="sub">Thanks for your interest in AdvisorOS. We'll review your
    request and reach out shortly to set up your workspace and walk you
    through advising business owners on the platform.</div>
  <a class="btn-os" style="width:100%;justify-content:center"
     href="<?= e(url('index.php')) ?>">Back to home</a>
  <p class="muted mt-3" style="font-size:12.5px;text-align:center">
    Already have an account? <a href="<?= e(url('login.php')) ?>">Sign in</a>
  </p>
<?php else: ?>
  <h2>Request access</h2>
  <div class="sub">The wealth advisory platform for business owners — personal
    and business wealth, risk, valuation, succession, tax and financing in one
    place. Tell us about your practice and we'll get you set up.</div>
  <form method="post" action="<?= e(url('signup.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div style="position:absolute;left:-9999px" aria-hidden="true">
      <label>Company URL<input type="text" name="company_url" tabindex="-1" autocomplete="off"></label>
    </div>
    <div class="form-row">
      <label for="firm">Firm / company name *</label>
      <input type="text" id="firm" name="firm" value="<?= e(old('firm')) ?>" required autofocus maxlength="150">
    </div>
    <div class="form-row">
      <label for="name">Your name *</label>
      <input type="text" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="120">
    </div>
    <div class="form-row">
      <label for="email">Work email *</label>
      <input type="email" id="email" name="email" value="<?= e(old('email')) ?>" required maxlength="190">
    </div>
    <div class="form-row">
      <label for="phone">Phone</label>
      <input type="text" id="phone" name="phone" value="<?= e(old('phone')) ?>" maxlength="40">
    </div>
    <div class="form-row">
      <label for="plan">Plan of interest</label>
      <select id="plan" name="plan">
        <option value="">Not sure yet</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= e($p['name']) ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <label for="advisors">Number of advisors</label>
      <input type="number" id="advisors" name="advisors" min="0" value="<?= e(old('advisors')) ?>">
    </div>
    <div class="form-row">
      <label for="referral">Referral code (optional)</label>
      <input type="text" id="referral" name="referral" value="<?= e(old('referral')) ?>"
             placeholder="FAOS-XXXXXX" maxlength="40">
    </div>
    <div class="form-row">
      <label for="message">Anything else?</label>
      <textarea id="message" name="message" rows="3" maxlength="1000"><?= e(old('message')) ?></textarea>
    </div>
    <button type="submit" class="btn-os" style="width:100%;justify-content:center">Request access</button>
  </form>
  <p class="muted mt-3" style="font-size:12.5px;text-align:center">
    Already have an account? <a href="<?= e(url('login.php')) ?>">Sign in</a>
  </p>
<?php endif; ?>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
