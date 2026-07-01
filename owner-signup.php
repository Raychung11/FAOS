<?php
/** AdvisorOS — Business-owner self-signup (public). */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/owner_auth.php';

if (is_logged_in() || attempt_remember_login()) {
    redirect(role_home(current_user()['role_code']));
}
if (owner_is_logged_in()) { redirect('owner-portal.php'); }

$errors = [];
$prefill = ['full_name' => '', 'email' => '', 'phone' => '', 'company_name' => ''];

if (is_post()) {
    csrf_check();

    // Honeypot.
    if (trim((string) ($_POST['company_url'] ?? '')) !== '') {
        redirect('owner-portal.php');
    }

    $fullName    = trim((string) input('full_name', ''));
    $email       = strtolower(trim((string) input('email', '')));
    $phone       = trim((string) input('phone', ''));
    $companyName = trim((string) input('company_name', ''));
    $password    = (string) ($_POST['password'] ?? '');
    $confirm     = (string) ($_POST['password_confirm'] ?? '');

    $prefill = compact('full_name', 'email', 'phone', 'company_name');

    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        [$ok, $msg] = owner_register($email, $password, $fullName, $phone, $companyName);
        if (!$ok) {
            $errors[] = $msg;
        } else {
            [$ok2, $msg2] = owner_login($email, $password);
            if (!$ok2) {
                $errors[] = $msg2;
            } else {
                // Carry any anonymous saves in the current session into the account.
                $carriedEquity = null;
                if (!empty($_GET['token'])) {
                    $tok = preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['token']);
                    if (strlen($tok) === 16) { $carriedEquity = $tok; }
                }
                if ($carriedEquity) {
                    require_once __DIR__ . '/includes/equity_engine.php';
                    $rec = platform_setting_get_json('public_eq:' . $carriedEquity, []);
                    if (!empty($rec['data'])) {
                        $score = equity_score($rec['data']);
                        owner_attach_report('equity', $carriedEquity, [
                            'label'      => ($companyName ?: 'My equity assessment'),
                            'answered'   => $score['answered'],
                            'total'      => $score['total_indicators'],
                            'score_pct'  => round($score['total'], 1),
                            'band'       => $score['band'],
                            'band_code'  => $score['band_code'],
                            'red_flags'  => count($score['red_flags']),
                        ]);
                    }
                }
                set_flash('success', 'Welcome — your account is ready.');
                redirect('owner-portal.php');
            }
        }
    }
}

$pageTitle = 'Create your account';
require __DIR__ . '/includes/auth_header.php';
?>
<h2>Create your account</h2>
<div class="sub">Save your equity assessment and valuation reports securely,
   come back any time to update them, and share them with your adviser.
   Free — takes about a minute.</div>

<?php if ($errors): ?>
  <div class="alert-os danger" style="margin-bottom:14px">
    <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>
  <div style="position:absolute;left:-9999px" aria-hidden="true">
    <label>Company URL<input type="text" name="company_url" tabindex="-1" autocomplete="off"></label>
  </div>
  <div class="form-row">
    <label for="full_name">Your name *</label>
    <input type="text" id="full_name" name="full_name" required autofocus maxlength="120"
           value="<?= e($prefill['full_name']) ?>">
  </div>
  <div class="form-row">
    <label for="email">Email *</label>
    <input type="email" id="email" name="email" required maxlength="190"
           value="<?= e($prefill['email']) ?>">
  </div>
  <div class="form-row">
    <label for="phone">Phone / WhatsApp</label>
    <input type="text" id="phone" name="phone" maxlength="40"
           value="<?= e($prefill['phone']) ?>"
           placeholder="e.g. +60 12 345 6789">
  </div>
  <div class="form-row">
    <label for="company_name">Company name</label>
    <input type="text" id="company_name" name="company_name" maxlength="150"
           value="<?= e($prefill['company_name']) ?>"
           placeholder="Your business (optional — helps us tailor the tools)">
  </div>
  <div class="form-row">
    <label for="password">Password * <span class="muted" style="font-weight:400;font-size:11.5px">(at least 8 characters)</span></label>
    <input type="password" id="password" name="password" required minlength="8" maxlength="128">
  </div>
  <div class="form-row">
    <label for="password_confirm">Confirm password *</label>
    <input type="password" id="password_confirm" name="password_confirm" required minlength="8" maxlength="128">
  </div>
  <button type="submit" class="btn-os gold" style="width:100%;justify-content:center">Create account</button>
</form>
<p class="muted mt-3" style="font-size:12.5px;text-align:center">
  Already have an account? <a href="<?= e(url('owner-login.php' . (!empty($_GET['token']) ? '?token=' . e($_GET['token']) : ''))) ?>">Sign in</a>
</p>
<p class="muted mt-3" style="font-size:11.5px;text-align:center;line-height:1.55">
  By creating an account you agree that this platform provides indicative
  advisory tools only — not legal, tax, Shariah or licensed valuation advice.
  For anything binding, engage the appropriate licensed professional.
</p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
