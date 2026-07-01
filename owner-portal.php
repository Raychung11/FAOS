<?php
/** AdvisorOS — Business-owner portal (logged-in dashboard). */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/owner_auth.php';
require_once __DIR__ . '/includes/equity_engine.php';

$me  = owner_require_login();
$rec = owner_current_record();
if (!$rec) {
    owner_logout();
    redirect('owner-login.php');
}

$reports = (array) ($rec['reports'] ?? []);
usort($reports, fn ($a, $b) => strcmp($b['saved_at'] ?? '', $a['saved_at'] ?? ''));

$pageTitle = 'Your dashboard — ' . APP_NAME;
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
  .op-wrap{max-width:1000px;margin:0 auto;padding:0 22px 40px}
  .op-nav{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;
          border-bottom:1px solid var(--line);background:#fff}
  .op-logo{font-weight:800;font-size:22px;color:var(--navy);text-decoration:none}
  .op-logo span{color:var(--gold)}
  .op-hero{padding:32px 0 18px}
  .op-hero h1{font-size:28px;margin:0 0 6px;color:var(--navy)}
  .op-hero .muted{font-size:14px}
  .op-card{background:#fff;border:1px solid var(--line);border-radius:12px;
           padding:22px;margin:12px 0;box-shadow:0 3px 14px rgba(11,31,58,.04)}
  .op-report{display:grid;grid-template-columns:1fr auto;gap:14px;
             padding:14px;border-radius:10px;background:#f7f9fc;margin-bottom:10px;align-items:center}
  .op-report h4{margin:0 0 4px;color:var(--navy);font-size:15px}
  .op-report .muted{font-size:12px}
  .op-band{display:inline-block;padding:3px 10px;border-radius:10px;font-weight:700;font-size:11.5px}
  .op-band-low{background:#dcf5e3;color:#1e6b3a}
  .op-band-moderate{background:#fff2c9;color:#7a5b00}
  .op-band-high{background:#ffd5c9;color:#8a2f0f}
  .op-band-critical{background:#f5c9c9;color:#7c1414}
  .op-band-unknown{background:#e9edf3;color:#5a6675}
  .op-tools{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  @media (max-width:640px){.op-tools{grid-template-columns:1fr}}
  .op-tool{background:#fff;border:1px solid var(--line);border-radius:12px;padding:20px}
  .op-tool h3{margin:0 0 6px;color:var(--navy);font-size:16px}
  .op-tool p{color:var(--muted);font-size:13px;margin:0 0 12px}
  .op-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 18px;
          border-radius:8px;font-weight:600;font-size:13.5px;text-decoration:none;border:none;cursor:pointer}
  .op-btn.primary{background:var(--gold);color:#1a1405}
  .op-btn.ghost{background:#fff;color:var(--navy);border:1px solid var(--line)}
</style>
</head>
<body>
<div class="op-nav">
  <a href="<?= e(url('index.php')) ?>" class="op-logo"><?= e(APP_NAME) ?><span>OS</span></a>
  <div style="display:flex;gap:12px;align-items:center">
    <span style="color:var(--muted);font-size:13px">Signed in as
      <strong style="color:var(--navy)"><?= e($me['name']) ?></strong></span>
    <form method="post" action="<?= e(url('owner-logout.php')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <button class="op-btn ghost">Sign out</button>
    </form>
  </div>
</div>

<div class="op-wrap">
  <?php foreach (['success', 'warning', 'danger'] as $level): $m = get_flash($level); ?>
    <?php if ($m): ?>
      <div class="alert-os <?= $level ?>" style="margin:14px 0"><?= e($m) ?></div>
    <?php endif; ?>
  <?php endforeach; ?>

  <header class="op-hero">
    <h1>Welcome back, <?= e(explode(' ', $me['name'])[0] ?? $me['name']) ?>.</h1>
    <div class="muted">Your saved reports and available tools.
      <?php if (!empty($rec['company_name'])): ?>
        · <?= e($rec['company_name']) ?>
      <?php endif; ?></div>
  </header>

  <div class="op-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div>
        <h2 style="margin:0;color:var(--navy);font-size:19px">Your reports</h2>
        <div class="muted" style="font-size:12.5px">
          <?= count($reports) ?> saved
          <?php if ($reports): ?>· newest first<?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$reports): ?>
      <div style="text-align:center;padding:24px;color:var(--muted);font-size:14px">
        No reports saved yet. Try one of the tools below and click
        <strong>Save</strong> to keep it here.
      </div>
    <?php else: ?>
      <?php foreach ($reports as $r): $snap = (array) ($r['snapshot'] ?? []); ?>
        <div class="op-report">
          <div>
            <h4>
              <?php if (($r['kind'] ?? '') === 'equity'): ?>
                Equity Structure Assessment
              <?php elseif (($r['kind'] ?? '') === 'valuation'): ?>
                Business Valuation
              <?php else: ?>
                Report
              <?php endif; ?>
              <?php if (!empty($snap['label']) && $snap['label'] !== 'My equity assessment'): ?>
                <span class="muted" style="font-size:12.5px">· <?= e($snap['label']) ?></span>
              <?php endif; ?>
            </h4>
            <div class="muted">
              Saved <?= e(date('d M Y H:i', strtotime((string) ($r['saved_at'] ?? 'now')))) ?>
              <?php if (isset($snap['score_pct'])): ?>
                · score <strong style="color:var(--navy)"><?= round((float) $snap['score_pct'], 0) ?>/100</strong>
              <?php endif; ?>
              <?php if (isset($snap['answered'], $snap['total'])): ?>
                · <?= (int) $snap['answered'] ?>/<?= (int) $snap['total'] ?> answered
              <?php endif; ?>
              <?php if (!empty($snap['red_flags'])): ?>
                · <strong style="color:#8a2f0f"><?= (int) $snap['red_flags'] ?> red flag<?= (int) $snap['red_flags'] === 1 ? '' : 's' ?></strong>
              <?php endif; ?>
              <?php if (!empty($snap['band'])): ?>
                <span class="op-band op-band-<?= e($snap['band_code'] ?? 'unknown') ?>"><?= e($snap['band']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if (($r['kind'] ?? '') === 'equity'): ?>
              <a class="op-btn primary" href="<?= e(url('equity-try.php?token=' . urlencode((string) $r['ref']))) ?>">Open</a>
            <?php elseif (($r['kind'] ?? '') === 'valuation'): ?>
              <a class="op-btn primary" href="<?= e(url('valuation-try.php')) ?>">Rerun</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <h2 style="margin:22px 0 10px;color:var(--navy);font-size:19px">Tools</h2>
  <div class="op-tools">
    <div class="op-tool">
      <h3>Equity Structure Assessment</h3>
      <p>50-indicator health check for Malaysian SME cap tables — auto-detects red flags for
         proxy, SHA gaps, IP ownership and succession.</p>
      <a class="op-btn primary" href="<?= e(url('equity-try.php')) ?>">Start / continue →</a>
    </div>
    <div class="op-tool">
      <h3>Business Valuation</h3>
      <p>Three-method blend (NAV / EBITDA × industry multiple / DCF) with a sequential
         discount stack. Indicative equity value in about a minute.</p>
      <a class="op-btn primary" href="<?= e(url('valuation-try.php')) ?>">Value my business →</a>
    </div>
  </div>

  <div class="op-card" style="margin-top:22px;background:#f7f9fc">
    <h3 style="margin:0 0 6px;color:var(--navy);font-size:16px">Ready to work with an adviser?</h3>
    <p style="margin:0 0 14px;color:var(--muted);font-size:13.5px">
      Get the full experience: AI-written commentary tailored to your business,
      polished PDF reports, and a live conversation with a licensed adviser to walk you through the findings.
    </p>
    <a class="op-btn primary" href="<?= e(url('signup.php')) ?>">Talk to us →</a>
  </div>

  <p style="text-align:center;color:var(--muted);font-size:12px;margin-top:26px">
    Account: <?= e($me['email']) ?> · created <?= e(substr((string) ($me['created_at'] ?? ''), 0, 10)) ?>
  </p>
</div>
</body>
</html>
