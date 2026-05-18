<?php
/** AdvisorOS — Public landing & pricing page.
 *  Logged-in users are routed to their dashboard; everyone else sees
 *  the marketing page with live plans, an interactive value
 *  calculator, an illustrative case study and testimonials. */
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

// Reference plan (the "popular" one) drives the interactive calculator.
$popPlan = null;
foreach ($plans as $p) {
    if ($p['code'] === 'professional') { $popPlan = $p; break; }
}
if (!$popPlan && $plans) { $popPlan = $plans[0]; }
[$calcPrice, $calcQuota] = $popPlan
    ? array_values(plan_report_terms($popPlan))
    : [15.0, 20];

$featureLabels = [
    'leads' => 'Track new prospects', 'clients' => 'All clients in one place',
    'reviews' => 'Review reminders', 'proposals' => 'Proposals written for you',
    'commissions' => 'Commission tracking',
];

$features = [
    ['Personal & business wealth in one view', 'See the owner\'s personal money and their company side-by-side — net worth, what\'s tied up in the business, and how exposed the family really is.'],
    ['Spot the big risks early', 'A clear health-check across protection, cashflow, personal guarantees, key-person and succession — colour-coded so the danger areas jump out.'],
    ['Know what the business is worth', 'An indicative company valuation (asset, earnings and cash-flow based) and the owner\'s share — the starting point for selling, succession or raising money.'],
    ['"If I stop today, is my family OK?"', 'Answers the question every business owner worries about — with a clear funded / not-funded verdict and the exact shortfall.'],
    ['Smarter tax & financing', 'Salary-vs-dividend that keeps more in the owner\'s pocket, and whether the business can support more borrowing.'],
    ['One prioritised action plan', 'Everything above turns into a do-this-now / next / later plan you can track — and a polished report written for you.'],
];

$stats = [
    ['', '8', '', 'advisory engines built in'],
    ['', '1', '', 'view: personal + business wealth'],
    ['', '6', '×', 'quicker to produce a report'],
    ['', '100', '%', 'of changes recorded for you'],
];

$steps = [
    ['1', 'Add the owner & company', 'Personal details plus the business — shareholders, directors and a simple set of company numbers.'],
    ['2', 'Get the full picture', 'Wealth, risks, valuation, succession, tax and financing are worked out for you automatically.'],
    ['3', 'Get the action plan', 'One prioritised plan — do now / next / later — and a written report, ready to review.'],
    ['4', 'Track it through', 'Mark actions done as you go and never miss a client\'s review date.'],
];

$faqs = [
    ['Who is this built for?',
     'Advisers who serve business owners and entrepreneurs — financial advisers, tax and SME consultants, wealth planners. It handles the owner\'s personal money and their company together, not just one side.'],
    ['Do I need to be tech-savvy to use this?',
     'No. If you can use online banking, you can use this. There\'s nothing to install — it runs in your web browser.'],
    ['Is my clients\' information safe?',
     'Yes. Each firm\'s data is kept completely separate, advisers only see the clients they should, and every action is recorded automatically for compliance.'],
    ['Is this giving tax, legal or investment advice?',
     'No. It produces clear estimates and a draft plan to save you time. A licensed adviser still reviews and approves everything — the system never advises on its own.'],
    ['What does "per report" mean in the pricing?',
     'A report is one generated analysis — a proposal, valuation, risk diagnostic, succession review or the strategic plan. Your plan includes a monthly allowance; you only pay more if you go over.'],
    ['Will it work for a solo adviser or a small firm?',
     'Yes. Plans scale from a single adviser up to large agencies, so you only pay for what your team needs.'],
];

$testimonials = [
    ['Seeing a client\'s personal and business wealth on one screen changed the conversation completely — we\'re advising the owner, not selling a product.',
     'Wong S.', 'Practice Principal, KL'],
    ['The "if you stop today, is your family OK?" report lands every time. It turns a routine review into real planning work.',
     'Devi R.', 'Agency Leader'],
    ['Valuation, tax structuring and a prioritised action plan in minutes — work that used to need three different specialists.',
     'Tan W.K.', 'Licensed Financial Adviser'],
];

// Optional hero photo: drop a file at assets/img/hero.(webp|jpg|png).
// If none exists the text hero shows on its own — never a broken image.
$heroImg = null;
foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
    if (is_file(APP_ROOT . '/assets/img/hero.' . $ext)) {
        $heroImg = 'assets/img/hero.' . $ext;
        break;
    }
}

$pageTitle = 'AdvisorOS — The Entrepreneur Wealth Advisory Platform';
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
  html{scroll-behavior:smooth}
  body{background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif;margin:0}
  .lp-wrap{max-width:1080px;margin:0 auto;padding:0 22px}
  .lp-navbar{position:sticky;top:0;z-index:30;background:rgba(245,247,250,.85);
             backdrop-filter:blur(8px);border-bottom:1px solid transparent;transition:.25s}
  .lp-navbar.scrolled{background:rgba(255,255,255,.92);border-bottom-color:var(--line)}
  .lp-nav{display:flex;justify-content:space-between;align-items:center;padding:16px 0}
  .lp-logo{font-weight:800;font-size:22px;color:var(--navy)}
  .lp-logo span{color:var(--gold)}
  .lp-links{display:flex;gap:26px;align-items:center}
  .lp-links a{color:var(--ink);text-decoration:none;font-size:14px;font-weight:500}
  .lp-links a:hover{color:var(--gold)}
  .lp-sec{padding:54px 0;scroll-margin-top:74px}
  .lp-hero{text-align:center;padding:70px 0 40px}
  .lp-hero h1{font-size:42px;line-height:1.15;margin:0 0 16px;color:var(--navy);font-weight:800}
  .lp-hero p{font-size:18px;color:var(--muted);max-width:640px;margin:0 auto 28px}
  .lp-cta{display:inline-flex;gap:12px;flex-wrap:wrap;justify-content:center}
  .lp-hero-img{margin:42px auto 0;max-width:920px}
  .lp-hero-img img{width:100%;height:auto;display:block;border-radius:16px;
                   border:1px solid var(--line);box-shadow:0 18px 50px rgba(11,31,58,.16)}
  .lp-btn{display:inline-block;padding:13px 26px;border-radius:12px;font-weight:600;
          text-decoration:none;border:1px solid transparent;cursor:pointer}
  .lp-btn.primary{background:var(--gold);color:#1a1405}
  .lp-btn.ghost{background:#fff;color:var(--navy);border-color:var(--line)}
  .lp-sec h2{text-align:center;font-size:30px;color:var(--navy);margin:0 0 8px}
  .lp-sec .sub{text-align:center;color:var(--muted);margin:0 0 34px}
  .lp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;
            background:var(--navy);border-radius:var(--radius);padding:30px 24px}
  .lp-stat{text-align:center;color:#fff}
  .lp-stat .n{font-size:38px;font-weight:800;color:var(--gold);line-height:1}
  .lp-stat .l{font-size:13px;color:#c7d2e3;margin-top:8px}
  .lp-feat{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .lp-feat .card-os{margin:0;height:100%}
  .lp-feat h3{margin:0 0 6px;color:var(--navy);font-size:17px}
  .lp-feat p{margin:0;color:var(--muted);font-size:14px;line-height:1.55}
  .lp-split{display:grid;grid-template-columns:1.05fr .95fr;gap:26px;align-items:stretch}
  .lp-case{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
           padding:28px;box-shadow:var(--shadow)}
  .lp-case h3{color:var(--navy);margin:0 0 4px;font-size:19px}
  .lp-case .who{color:var(--muted);font-size:13px;margin-bottom:16px}
  .lp-case p{font-size:14px;line-height:1.6;color:var(--ink);margin:0 0 12px}
  .lp-case .lbl{font-weight:700;color:var(--navy)}
  .lp-chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}
  .lp-chip{background:var(--bg);border:1px solid var(--line);border-radius:10px;
           padding:10px 14px;font-size:13px;flex:1;min-width:130px}
  .lp-chip b{display:block;color:var(--ok);font-size:18px;font-weight:800}
  .lp-chip s{color:var(--danger);text-decoration:none;opacity:.7;font-size:12px}
  .lp-calc{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
           padding:28px;box-shadow:var(--shadow)}
  .lp-calc h3{color:var(--navy);margin:0 0 16px;font-size:19px}
  .lp-calc label{display:block;font-size:13px;font-weight:600;color:var(--navy);
                 margin:14px 0 6px;display:flex;justify-content:space-between}
  .lp-calc input[type=range]{width:100%;accent-color:var(--gold)}
  .lp-calc .out{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:20px}
  .lp-calc .o{background:var(--bg);border-radius:10px;padding:14px;text-align:center}
  .lp-calc .o b{display:block;font-size:24px;font-weight:800;color:var(--navy)}
  .lp-calc .o span{font-size:12px;color:var(--muted)}
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
  .lp-tst{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
  .lp-quote{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
            padding:24px;box-shadow:var(--shadow)}
  .lp-quote p{font-size:14.5px;line-height:1.6;color:var(--ink);margin:0 0 16px}
  .lp-quote .by{font-weight:700;color:var(--navy);font-size:14px}
  .lp-quote .ro{color:var(--muted);font-size:12.5px}
  .lp-note{text-align:center;color:var(--muted);font-size:12px;margin-top:18px}
  .lp-refer{background:var(--navy);color:#fff;border-radius:var(--radius);
            padding:34px;text-align:center}
  .lp-refer h2{color:#fff;margin:0 0 8px}
  .lp-refer p{color:#c7d2e3;margin:0 auto;max-width:560px}
  .lp-foot{text-align:center;color:var(--muted);font-size:13px;padding:40px 0 60px}
  .lp-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
  .lp-step{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
           padding:22px;box-shadow:var(--shadow)}
  .lp-step .num{width:34px;height:34px;border-radius:50%;background:var(--navy);color:#fff;
                font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:12px}
  .lp-step h3{margin:0 0 6px;color:var(--navy);font-size:16px}
  .lp-step p{margin:0;color:var(--muted);font-size:13.5px;line-height:1.55}
  .lp-faq{max-width:760px;margin:0 auto}
  .lp-q{background:var(--card);border:1px solid var(--line);border-radius:12px;
        margin-bottom:12px;overflow:hidden}
  .lp-q button{all:unset;display:flex;justify-content:space-between;align-items:center;
               width:100%;box-sizing:border-box;padding:18px 20px;cursor:pointer;
               font-weight:600;color:var(--navy);font-size:15px}
  .lp-q button:focus-visible{outline:2px solid var(--gold);outline-offset:-2px}
  .lp-q .ic{color:var(--gold);font-size:22px;line-height:1;transition:transform .25s;
            flex:none;margin-left:14px}
  .lp-q.open .ic{transform:rotate(45deg)}
  .lp-q .a{max-height:0;overflow:hidden;transition:max-height .3s ease}
  .lp-q .a p{margin:0;padding:0 20px 18px;color:var(--muted);font-size:14px;line-height:1.6}
  .reveal{opacity:0;transform:translateY(22px);transition:opacity .6s ease,transform .6s ease}
  .reveal.in{opacity:1;transform:none}
  @media(max-width:820px){
    .lp-feat,.lp-price,.lp-tst,.lp-split,.lp-steps{grid-template-columns:1fr}
    .lp-stats{grid-template-columns:repeat(2,1fr)}
    .lp-hero h1{font-size:32px}.lp-links a:not(.lp-btn){display:none}
  }
</style>
</head>
<body>
<div class="lp-navbar" id="nav">
  <div class="lp-wrap lp-nav">
    <div class="lp-logo"><?= e(APP_NAME) ?><span>OS</span></div>
    <div class="lp-links">
      <a href="#features">Features</a>
      <a href="#how">How it works</a>
      <a href="#pricing">Pricing</a>
      <a href="#faq">FAQ</a>
      <a class="lp-btn ghost" href="<?= e(url('login.php')) ?>">Sign in</a>
    </div>
  </div>
</div>

<div class="lp-wrap">
  <header class="lp-hero">
    <h1>The wealth advisory platform<br>for business owners</h1>
    <p>AdvisorOS shows an entrepreneur's personal <em>and</em> business wealth
       in one place — risks, company value, succession, tax and financing —
       then turns it into a prioritised plan and a written report. Built for
       Malaysia. Less admin, deeper advice.</p>
    <div class="lp-cta">
      <a class="lp-btn primary" href="<?= e(url('signup.php')) ?>">Get started</a>
      <a class="lp-btn ghost" href="#how">See how it works</a>
    </div>
    <?php if ($heroImg): ?>
      <div class="lp-hero-img">
        <img src="<?= e(url($heroImg)) ?>" alt="AdvisorOS in use"
             loading="lazy" decoding="async">
      </div>
    <?php endif; ?>
  </header>

  <section class="lp-sec reveal" style="padding-top:0">
    <div class="lp-stats">
      <?php foreach ($stats as [$pre, $tgt, $suf, $lbl]): ?>
        <div class="lp-stat">
          <div class="n"><span class="count" data-target="<?= e($tgt) ?>"
            data-prefix="<?= e($pre) ?>" data-suffix="<?= e($suf) ?>"><?= e($pre) ?>0<?= e($suf) ?></span></div>
          <div class="l"><?= e($lbl) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lp-sec reveal" id="features">
    <h2>Everything you need to advise a business owner</h2>
    <p class="sub">Personal and business wealth, risk, valuation, succession, tax — one platform.</p>
    <div class="lp-feat">
      <?php foreach ($features as [$t, $d]): ?>
        <div class="card-os"><div class="card-os-body">
          <h3><?= e($t) ?></h3><p><?= e($d) ?></p>
        </div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lp-sec reveal">
    <h2>Up and running in four steps</h2>
    <p class="sub">No training course required.</p>
    <div class="lp-steps">
      <?php foreach ($steps as [$n, $h, $d]): ?>
        <div class="lp-step">
          <div class="num"><?= e($n) ?></div>
          <h3><?= e($h) ?></h3>
          <p><?= e($d) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lp-sec reveal" id="how">
    <h2>See it in action</h2>
    <p class="sub">A real-world style example, plus a quick calculator to size it for your firm.</p>
    <div class="lp-split">
      <div class="lp-case">
        <h3>Advising an SME owner</h3>
        <div class="who">Illustrative scenario · founder of a Sdn Bhd, Kuala Lumpur</div>
        <p><span class="lbl">The problem.</span> The owner's money and the
          company's were all mixed up. Nobody could say what the business was
          worth, how exposed the family was, or whether they could survive if
          the founder stopped.</p>
        <p><span class="lbl">With AdvisorOS.</span> The adviser captures the
          person and the company once. In minutes they see combined net worth,
          the big risks, an indicative business value, the succession answer,
          and a tax-smarter way to take money out.</p>
        <p><span class="lbl">Outcome.</span></p>
        <div class="lp-chips">
          <div class="lp-chip">Full picture <b>Minutes</b><s>was weeks &amp; 3 specialists</s></div>
          <div class="lp-chip">"Family OK if I stop?" <b>Answered</b><s>was guesswork</s></div>
          <div class="lp-chip">Next steps <b>One plan</b><s>was scattered notes</s></div>
        </div>
      </div>

      <div class="lp-calc">
        <h3>Estimate it for your firm</h3>
        <label>Advisors <span id="cA">6</span></label>
        <input type="range" id="rA" min="1" max="50" value="6">
        <label>Proposals per advisor / month <span id="cP">8</span></label>
        <input type="range" id="rP" min="1" max="40" value="8">
        <div class="out">
          <div class="o"><b id="oH">—</b><span>hours saved / month</span></div>
          <div class="o"><b id="oR">—</b><span>reports / month</span></div>
          <div class="o"><b id="oI">—</b><span>included in plan</span></div>
          <div class="o"><b id="oC">—</b><span>est. usage charge</span></div>
        </div>
        <p class="lp-note" style="margin-top:14px">Assumes ~50 min saved per
          AI-drafted proposal. Usage based on the most-popular plan.</p>
      </div>
    </div>
  </section>

  <section class="lp-sec reveal" id="pricing">
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

  <section class="lp-sec reveal">
    <h2>What advisory firms say</h2>
    <p class="sub">How teams put AdvisorOS to work.</p>
    <div class="lp-tst">
      <?php foreach ($testimonials as [$q, $by, $ro]): ?>
        <div class="lp-quote">
          <p>&ldquo;<?= e($q) ?>&rdquo;</p>
          <div class="by"><?= e($by) ?></div>
          <div class="ro"><?= e($ro) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="lp-note">Illustrative quotes representing typical workflows,
      not specific client endorsements.</div>
  </section>

  <section class="lp-sec reveal" id="faq">
    <h2>Questions, answered simply</h2>
    <p class="sub">Plain answers — no jargon.</p>
    <div class="lp-faq">
      <?php foreach ($faqs as $i => [$q, $a]): ?>
        <div class="lp-q">
          <button type="button" aria-expanded="false" aria-controls="fa<?= (int) $i ?>">
            <span><?= e($q) ?></span><span class="ic" aria-hidden="true">+</span>
          </button>
          <div class="a" id="fa<?= (int) $i ?>" role="region"><p><?= e($a) ?></p></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lp-sec reveal">
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

<script>
(function () {
  // Sticky-nav style on scroll
  var nav = document.getElementById('nav');
  var onScroll = function () {
    nav.classList.toggle('scrolled', window.scrollY > 8);
  };
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  // Scroll-reveal + one-shot counter animation
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (!en.isIntersecting) return;
      en.target.classList.add('in');
      en.target.querySelectorAll('.count').forEach(animateCount);
      io.unobserve(en.target);
    });
  }, { threshold: 0.18 });
  document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });

  function animateCount(el) {
    var target = parseFloat(el.getAttribute('data-target')) || 0;
    var pre = el.getAttribute('data-prefix') || '';
    var suf = el.getAttribute('data-suffix') || '';
    var t0 = null, dur = 1100;
    function step(ts) {
      if (t0 === null) t0 = ts;
      var k = Math.min(1, (ts - t0) / dur);
      el.textContent = pre + Math.round(target * (0.5 - Math.cos(Math.PI * k) / 2)) + suf;
      if (k < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  // Interactive value calculator (uses the real popular-plan terms)
  var CALC = {
    price: <?= json_encode((float) $calcPrice) ?>,
    quota: <?= json_encode((int) $calcQuota) ?>,
    minPer: 50
  };
  var rA = document.getElementById('rA'), rP = document.getElementById('rP');
  function recalc() {
    var a = +rA.value, p = +rP.value, reports = a * p;
    var extra = Math.max(0, reports - CALC.quota);
    document.getElementById('cA').textContent = a;
    document.getElementById('cP').textContent = p;
    document.getElementById('oH').textContent = Math.round(reports * CALC.minPer / 60) + ' h';
    document.getElementById('oR').textContent = reports;
    document.getElementById('oI').textContent = CALC.quota;
    document.getElementById('oC').textContent = 'RM ' + (extra * CALC.price)
      .toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  if (rA && rP) {
    rA.addEventListener('input', recalc);
    rP.addEventListener('input', recalc);
    recalc();
  }

  // FAQ accordion
  document.querySelectorAll('.lp-q button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var q = btn.parentElement, a = q.querySelector('.a');
      var open = q.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      a.style.maxHeight = open ? a.scrollHeight + 'px' : '0px';
    });
  });
})();
</script>
</body>
</html>
