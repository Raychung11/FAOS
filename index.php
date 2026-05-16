<?php
/** AdvisorOS — Public landing & pricing page.
 *  Logged-in users are routed to their dashboard; everyone else sees
 *  the marketing page with live plans and the referral offer. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/billing.php';

if (is_logged_in() || attempt_remember_login()) {
    redirect(role_home(current_user()['role_code']));
}

try {
    $plans = db()->query(
        'SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY price_monthly'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[AdvisorOS] landing plans query failed: ' . $e->getMessage());
    $plans = [];
}

$featureLabels = [
    'leads' => 'Lead pipeline', 'clients' => 'Client 360 & reviews',
    'reviews' => 'Annual review scheduler', 'proposals' => 'AI proposal generator',
    'commissions' => 'Commission tracking',
];

$features = [
    ['Client servicing OS', 'A structured client 360 — profiles, policies, documents and a never-miss annual review scheduler.'],
    ['AI proposal generator', 'Draft full advisory proposals from the client\'s own data and your firm\'s reusable advisory skills library.'],
    ['Borrowing capability', 'Malaysia DSR model — instantly see headroom for new financing or how to cut interest when over-leveraged.'],
    ['Tax planning estimator', 'Resident-individual tax (YA2023+) with unused-relief planning opportunities ranked by saving.'],
    ['Advisory skills library', 'Capture your firm\'s house-style playbooks once; every advisor\'s proposals stay consistent.'],
    ['Compliance-ready', 'Multi-tenant isolation, role-based access and an audit trail on every action.'],
];

$pageTitle = 'AdvisorOS — The Client Servicing OS for Financial Advisors';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<style>
  body{background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif;margin:0}
  .lp-wrap{max-width:1080px;margin:0 auto;padding:0 22px}
  .lp-nav{display:flex;justify-content:space-between;align-items:center;padding:20px 0}
  .lp-logo{font-weight:800;font-size:22px;color:var(--navy)}
  .lp-logo span{color:var(--gold)}
  .lp-hero{text-align:center;padding:64px 0 48px}
  .lp-hero h1{font-size:42px;line-height:1.15;margin:0 0 16px;color:var(--navy);font-weight:800}
  .lp-hero p{font-size:18px;color:var(--muted);max-width:640px;margin:0 auto 28px}
  .lp-cta{display:inline-flex;gap:12px;flex-wrap:wrap;justify-content:center}
  .lp-btn{display:inline-block;padding:13px 26px;border-radius:12px;font-weight:600;
          text-decoration:none;border:1px solid transparent}
  .lp-btn.primary{background:var(--gold);color:#1a1405}
  .lp-btn.ghost{background:#fff;color:var(--navy);border-color:var(--line)}
  .lp-sec{padding:48px 0}
  .lp-sec h2{text-align:center;font-size:30px;color:var(--navy);margin:0 0 8px}
  .lp-sec .sub{text-align:center;color:var(--muted);margin:0 0 34px}
  .lp-feat{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .lp-feat .card-os{margin:0}
  .lp-feat h3{margin:0 0 6px;color:var(--navy);font-size:17px}
  .lp-feat p{margin:0;color:var(--muted);font-size:14px;line-height:1.55}
  .lp-price{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:start}
  .lp-plan{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
           padding:26px;box-shadow:var(--shadow);position:relative}
  .lp-plan.pop{border-color:var(--gold);box-shadow:0 10px 30px rgba(201,162,39,.18)}
  .lp-tag{position:absolute;top:-12px;left:50%;transform:translateX(-50%);
          background:var(--gold);color:#1a1405;font-size:12px;font-weight:700;
          padding:4px 12px;border-radius:20px}
  .lp-plan h3{margin:0;color:var(--navy);font-size:20px}
  .lp-amt{font-size:34px;font-weight:800;color:var(--navy);margin:12px 0 2px}
  .lp-amt small{font-size:14px;font-weight:500;color:var(--muted)}
  .lp-yr{color:var(--muted);font-size:13px;margin-bottom:16px}
  .lp-plan ul{list-style:none;padding:0;margin:0 0 22px;font-size:14px}
  .lp-plan li{padding:7px 0;border-bottom:1px solid var(--line);color:var(--ink)}
  .lp-plan li b{color:var(--navy)}
  .lp-refer{background:var(--navy);color:#fff;border-radius:var(--radius);
            padding:34px;text-align:center}
  .lp-refer h2{color:#fff;margin:0 0 8px}
  .lp-refer p{color:#c7d2e3;margin:0 auto;max-width:560px}
  .lp-foot{text-align:center;color:var(--muted);font-size:13px;padding:40px 0 60px}
  @media(max-width:820px){.lp-feat,.lp-price{grid-template-columns:1fr}.lp-hero h1{font-size:32px}}
</style>
</head>
<body>
<div class="lp-wrap">
  <nav class="lp-nav">
    <div class="lp-logo"><?= e(APP_NAME) ?><span>OS</span></div>
    <a class="lp-btn ghost" href="<?= e(url('login.php')) ?>">Sign in</a>
  </nav>

  <header class="lp-hero">
    <h1>The Client Servicing Operating System<br>for Financial Advisors</h1>
    <p>Move from scattered spreadsheets and memory-based follow-ups to a
       structured, compliant advisory operation — with AI-drafted proposals,
       borrowing-capacity and tax-planning insight built in.</p>
    <div class="lp-cta">
      <a class="lp-btn primary" href="<?= e(url('signup.php')) ?>">Get started</a>
      <a class="lp-btn ghost" href="#pricing">See pricing</a>
    </div>
  </header>

  <section class="lp-sec">
    <h2>Everything an advisory firm needs</h2>
    <p class="sub">One platform, from first lead to lifelong servicing.</p>
    <div class="lp-feat">
      <?php foreach ($features as [$t, $d]): ?>
        <div class="card-os"><div class="card-os-body">
          <h3><?= e($t) ?></h3><p><?= e($d) ?></p>
        </div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lp-sec" id="pricing">
    <h2>Simple, scalable pricing</h2>
    <p class="sub">Every plan includes a monthly report allowance; extra
      reports are billed only as you use them.</p>
    <?php if (!$plans): ?>
      <p class="sub"><a class="lp-btn ghost" href="<?= e(url('signup.php')) ?>">Contact us to get started</a></p>
    <?php else: ?>
    <div class="lp-price">
      <?php foreach ($plans as $p): ?>
        <?php
          [$rPrice, $rQuota] = array_values(plan_report_terms($p));
          $pf  = is_array($x = json_decode((string) ($p['features'] ?? ''), true)) ? $x : [];
          $pop = $p['code'] === 'professional';
        ?>
        <div class="lp-plan<?= $pop ? ' pop' : '' ?>">
          <?php if ($pop): ?><div class="lp-tag">Most popular</div><?php endif; ?>
          <h3><?= e($p['name']) ?></h3>
          <div class="lp-amt">RM <?= money($p['price_monthly']) ?><small>/mo</small></div>
          <div class="lp-yr">or RM <?= money($p['price_yearly']) ?> billed yearly</div>
          <ul>
            <li><b><?= (int) $p['max_users'] ?></b> users · <b><?= (int) $p['max_clients'] ?></b> clients</li>
            <li><b><?= (int) $rQuota ?></b> reports/mo included<br>
              <span class="muted">then RM <?= money($rPrice) ?> per extra report</span></li>
            <li><b><?= (int) round($p['storage_mb'] / 1024) ?> GB</b> document storage</li>
            <?php foreach ($featureLabels as $fk => $fl): ?>
              <?php if (!empty($pf[$fk])): ?><li><?= e($fl) ?></li><?php endif; ?>
            <?php endforeach; ?>
          </ul>
          <a class="lp-btn <?= $pop ? 'primary' : 'ghost' ?>" style="display:block;text-align:center"
             href="<?= e(url('signup.php')) ?>">Get started</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="lp-sec">
    <div class="lp-refer">
      <h2>Refer a firm — you both win</h2>
      <p>Invite another advisory firm to AdvisorOS. When they subscribe,
         your firm earns <b style="color:var(--gold)">RM <?= money(referral_credit_rm()) ?></b>
         in credit toward your next invoice. Your referral code is in your
         Billing dashboard once you sign in.</p>
    </div>
  </section>

  <footer class="lp-foot">
    AdvisorOS is a practice-management platform. It does not provide
    financial advice; all recommendations must be reviewed and approved by a
    licensed financial advisor.<br>
    &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
    · <a href="<?= e(url('login.php')) ?>" style="color:var(--muted)">Sign in</a>
  </footer>
</div>
</body>
</html>
