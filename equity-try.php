<?php
/**
 * AdvisorOS — Public "Equity Structure Assessment" — free tool.
 *
 * No signup required. Visitor completes the full 50-indicator
 * framework (paginated by category so it isn't intimidating), gets
 * an instant score + red-flag list, and can save the assessment
 * against a unique 16-char token URL to come back to later. Saves
 * are rate-limited (3 per visitor per day). Reports auto-expire
 * after 90 days of inactivity.
 *
 * Stored at platform-level settings (tenant_id=0) key
 * `public_eq:{token}`. No PII captured other than an optional email
 * the visitor may enter to receive their link (best-effort, logged
 * only — no email send yet).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/equity_engine.php';
require_once __DIR__ . '/includes/owner_auth.php';

if (is_logged_in() || attempt_remember_login()) {
    // Signed-in advisors / firm portal clients don't need the public teaser.
    redirect(role_home(current_user()['role_code']));
}

$owner = owner_current();

const EQ_TRY_MAX_SAVES_PER_DAY   = 3;
const EQ_TRY_REPORT_TTL_DAYS     = 90;
const EQ_TRY_KEY_PREFIX          = 'public_eq:';

// ---------- Token helpers -------------------------------------------------

function eq_try_new_token(): string
{
    // 16 chars alphanumeric — safe in a URL, easy to type.
    return substr(str_replace(['+', '/', '='], '',
        base64_encode(random_bytes(12))), 0, 16);
}

function eq_try_load(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{16}$/', $token)) { return null; }
    try {
        $rec = platform_setting_get_json(EQ_TRY_KEY_PREFIX . $token, []);
    } catch (Throwable $e) { return null; }
    if (!$rec || empty($rec['data'])) { return null; }
    // TTL check — expire silently if stale.
    $lastSeen = strtotime((string) ($rec['updated_at'] ?? '')) ?: 0;
    if ($lastSeen && (time() - $lastSeen) > EQ_TRY_REPORT_TTL_DAYS * 86400) { return null; }
    return $rec;
}

function eq_try_save(string $token, array $data, ?string $email): void
{
    platform_setting_put_json(EQ_TRY_KEY_PREFIX . $token, [
        'data'       => $data,
        'email'      => $email ? mb_substr($email, 0, 120) : null,
        'updated_at' => date('Y-m-d H:i:s'),
        'ip_hash'    => substr(hash('sha256',
            ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) env('APP_KEY', 'advisoros')), 0, 16),
    ]);
}

// ---------- Rate limiting -------------------------------------------------

function eq_try_saves_used(): int
{
    $sess = (int) ($_SESSION['eq_try_saves'] ?? 0);
    $ip   = 0;
    try {
        $key = 'eq_try_daily:' . substr(hash('sha256',
            ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) env('APP_KEY', 'advisoros')
            . '|' . date('Y-m-d')), 0, 16);
        $ip = (int) (platform_setting_get_json($key, ['n' => 0])['n'] ?? 0);
    } catch (Throwable $e) { /* best effort */ }
    return max($sess, $ip);
}

function eq_try_save_recorded(): void
{
    $_SESSION['eq_try_saves'] = (int) ($_SESSION['eq_try_saves'] ?? 0) + 1;
    try {
        $key = 'eq_try_daily:' . substr(hash('sha256',
            ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) env('APP_KEY', 'advisoros')
            . '|' . date('Y-m-d')), 0, 16);
        $cur = (int) (platform_setting_get_json($key, ['n' => 0])['n'] ?? 0);
        platform_setting_put_json($key, ['n' => $cur + 1]);
    } catch (Throwable $e) { /* best effort */ }
}

// ---------- Form processing -----------------------------------------------

$token = (string) ($_GET['token'] ?? '');
$rec   = $token !== '' ? eq_try_load($token) : null;
$data  = $rec['data'] ?? equity_empty_assessment();
$savedEmail = $rec['email'] ?? '';
$justSaved  = false;
$errors     = [];

if (is_post()) {
    csrf_check();
    $action = (string) input('action', 'save');

    $submitted = [
        'scores'    => (array) input('scores', []),
        'dd'        => (array) input('dd', []),
        'red_flags' => (array) input('red_flags', []),
        'archetype' => (string) input('archetype', ''),
    ];

    // Normalise into engine shape (mirrors equity_save without a DB write).
    $data = equity_empty_assessment();
    foreach ((array) $submitted['scores'] as $id => $row) {
        $id = (int) $id;
        if (!isset($data['scores'][$id])) { continue; }
        $sc = $row['score'] ?? null;
        $data['scores'][$id] = [
            'score' => ($sc === null || $sc === '') ? null : max(0, min(5, (int) $sc)),
            'note'  => trim(mb_substr((string) ($row['note'] ?? ''), 0, 800)),
        ];
    }
    foreach (array_keys($data['dd']) as $k) {
        $data['dd'][$k] = !empty($submitted['dd'][$k]) ? 1 : 0;
    }
    foreach (array_keys($data['red_flags']) as $k) {
        $data['red_flags'][$k] = !empty($submitted['red_flags'][$k]) ? 1 : 0;
    }
    $data['archetype'] = trim(mb_substr((string) $submitted['archetype'], 0, 60));

    if ($action === 'save') {
        // Signed-in owners get unlimited saves and their reports attach
        // to their account automatically. Anonymous visitors are capped
        // at 3 new saves per day (updates to an existing token are free).
        $isOwner = owner_is_logged_in();
        if (!$isOwner && eq_try_saves_used() >= EQ_TRY_MAX_SAVES_PER_DAY && !$rec) {
            $errors[] = 'You\'ve used your 3 saves for today. '
                . 'Create a free account for unlimited saves, or come back tomorrow.';
        } else {
            if (!$token) { $token = eq_try_new_token(); }
            $email = trim((string) input('email', ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = ''; // silently drop invalid — not a hard error
            }
            $storedEmail = $email !== '' ? $email
                : ($savedEmail ?: ($isOwner ? $owner['email'] : null));
            eq_try_save($token, $data, $storedEmail);
            if (!$rec && !$isOwner) { eq_try_save_recorded(); }
            if ($isOwner) {
                owner_attach_report('equity', $token, [
                    'label'     => 'Equity assessment',
                    'answered'  => equity_score($data)['answered'],
                    'total'     => count(equity_indicators()),
                    'score_pct' => round(equity_score($data)['total'], 1),
                    'band'      => equity_score($data)['band'],
                    'band_code' => equity_score($data)['band_code'],
                    'red_flags' => count(equity_score($data)['red_flags']),
                ]);
            }
            $_SESSION['eq_try_just_saved'] = 1;
            redirect('equity-try.php?token=' . $token . '#result');
        }
    }
    // For plain "recalculate" action just fall through to render below.
}

if (!empty($_SESSION['eq_try_just_saved'])) {
    $justSaved = true;
    unset($_SESSION['eq_try_just_saved']);
}

$score      = equity_score($data);
$cats       = equity_categories();
$inds       = equity_indicators();
$scoreBands = equity_score_bands();

$publicUrl = ($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
$publicUrl .= ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok((string) $_SERVER['REQUEST_URI'], '?');
if ($token) { $publicUrl .= '?token=' . $token; }

$pageTitle = 'Free Equity Structure Assessment — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="Free 50-indicator equity structure health check for Malaysian SMEs. Save your report with a shareable link, come back any time.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<style>
  body{background:var(--bg);color:var(--ink);font-family:Inter,system-ui,sans-serif;margin:0}
  .eq-wrap{max-width:1000px;margin:0 auto;padding:0 22px}
  .eq-nav{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;
          border-bottom:1px solid var(--line);background:#fff}
  .eq-logo{font-weight:800;font-size:22px;color:var(--navy);text-decoration:none}
  .eq-logo span{color:var(--gold)}
  .eq-hero{padding:40px 0 22px;text-align:center}
  .eq-hero h1{font-size:34px;line-height:1.15;margin:0 0 14px;color:var(--navy);font-weight:800}
  .eq-hero p{font-size:16px;color:var(--muted);max-width:640px;margin:0 auto 14px}
  .eq-hero .badges{display:inline-flex;gap:8px;flex-wrap:wrap;justify-content:center}
  .eq-hero .badges span{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;
        border-radius:20px;background:#fff;border:1px solid var(--line);font-size:12.5px;color:var(--muted)}
  .eq-hero .badges strong{color:var(--navy)}
  .eq-card{background:#fff;border:1px solid var(--line);border-radius:14px;
           padding:22px 26px;margin:14px 0;box-shadow:0 4px 18px rgba(11,31,58,.04)}
  .eq-cat{border-top:1px solid var(--line)}
  .eq-cat summary{padding:14px 4px;cursor:pointer;list-style:none;
                   display:flex;justify-content:space-between;align-items:center;font-weight:700;color:var(--navy)}
  .eq-cat summary::before{content:'▸';margin-right:10px;color:var(--gold);
                           transition:transform .2s;display:inline-block}
  .eq-cat[open] summary::before{transform:rotate(90deg)}
  .eq-cat summary .zh{color:var(--muted);font-weight:500;font-size:12.5px;margin-left:8px}
  .eq-cat summary .pill{font-weight:600;font-size:12px;background:#f7f9fc;color:var(--navy);
                         padding:3px 10px;border-radius:10px;margin-left:auto}
  .eq-cat .body{padding:0 4px 10px}
  .eq-ind{display:grid;grid-template-columns:1fr 150px 1.6fr;gap:12px;
          padding:10px 0;border-bottom:1px dashed #d4d9e0;align-items:start}
  .eq-ind:last-child{border-bottom:none}
  .eq-ind .lbl{font-weight:600;color:var(--navy);font-size:13.5px}
  .eq-ind .lbl small{display:block;color:var(--muted);font-weight:400;font-size:11.5px;margin-top:2px}
  .eq-ind .zh{color:#6b7686;font-weight:500;font-size:12px}
  .eq-ind select,.eq-ind input{width:100%;padding:7px 9px;border:1px solid var(--line);
                                border-radius:6px;font-size:13px;box-sizing:border-box}
  .eq-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;
          border-radius:10px;font-weight:600;font-size:14px;text-decoration:none;
          border:none;cursor:pointer;font-family:inherit}
  .eq-btn.primary{background:var(--gold);color:#1a1405}
  .eq-btn.primary:hover{filter:brightness(.95)}
  .eq-btn.ghost{background:#fff;color:var(--navy);border:1px solid var(--line)}
  .eq-btn.dark{background:#0B1F3A;color:#fff}
  .eq-result-hero{text-align:center;padding:24px;background:linear-gradient(135deg,#0B1F3A 0%,#162e4a 100%);
                  color:#fff;border-radius:14px;margin:14px 0}
  .eq-result-hero .num{font-size:44px;font-weight:800;color:var(--gold);margin:6px 0}
  .eq-result-hero .band{display:inline-block;padding:4px 14px;border-radius:14px;font-weight:700;font-size:13px;margin-top:4px}
  .eq-result-hero .band.low{background:#dcf5e3;color:#1e6b3a}
  .eq-result-hero .band.moderate{background:#fff2c9;color:#7a5b00}
  .eq-result-hero .band.high{background:#ffd5c9;color:#8a2f0f}
  .eq-result-hero .band.critical{background:#f5c9c9;color:#7c1414}
  .eq-result-hero .band.unknown{background:#e9edf3;color:#5a6675}
  .eq-bar{height:8px;background:#e9edf3;border-radius:4px;overflow:hidden;margin-top:4px}
  .eq-bar-fill{height:100%;background:var(--gold);transition:width .3s}
  .eq-rf{background:#fdecea;border-left:3px solid #d84315;padding:10px 14px;
         border-radius:6px;margin-bottom:8px;font-size:13px}
  .eq-rf.auto{background:#fff7e6;border-left-color:#c9a227}
  .eq-savebox{background:linear-gradient(135deg,var(--gold) 0%,#b8901f 100%);color:#1a1405;
              padding:22px 26px;border-radius:14px;margin:16px 0}
  .eq-savebox h3{margin:0 0 8px;font-size:18px;font-weight:800}
  .eq-savebox p{margin:0 0 12px;font-size:14px;color:#3d3008}
  .eq-savebox .link{background:#fff;padding:10px 12px;border-radius:8px;font-family:monospace;font-size:12.5px;
                    word-break:break-all;margin:8px 0 12px;color:#0B1F3A;border:1px solid #b8901f}
  .eq-errs{background:#fdecea;border:1px solid #f0b4ad;color:#922b21;border-radius:8px;padding:12px 16px;margin:12px 0;font-size:14px}
  .eq-progress{position:sticky;top:0;background:rgba(255,255,255,.94);backdrop-filter:blur(6px);
               border-bottom:1px solid var(--line);padding:10px 22px;z-index:20}
  .eq-progress .row{max-width:1000px;margin:0 auto;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
  .eq-progress .bar{flex:1;min-width:200px;height:6px;background:#e9edf3;border-radius:3px;overflow:hidden}
  .eq-progress .fill{height:100%;background:var(--gold);transition:width .3s}
  .eq-disclaimer{font-size:12px;color:var(--muted);background:#f7f9fc;border-left:3px solid var(--gold);
                 padding:12px 16px;border-radius:6px;margin:16px 0;line-height:1.55}
  @media (max-width:640px){
    .eq-ind{grid-template-columns:1fr;gap:6px}
    .eq-hero h1{font-size:26px}
  }
</style>
</head>
<body>
<div class="eq-nav">
  <a href="<?= e(url('index.php')) ?>" class="eq-logo"><?= e(APP_NAME) ?></a>
  <div style="display:flex;gap:12px;align-items:center">
    <a href="<?= e(url('index.php')) ?>" style="font-size:14px;color:var(--ink);text-decoration:none;font-weight:500">← Home</a>
    <?php if ($owner): ?>
      <a class="eq-btn ghost" style="padding:8px 16px;font-size:13px" href="<?= e(url('owner-portal.php')) ?>">
        My dashboard
      </a>
      <span style="color:var(--muted);font-size:12.5px">
        <?= e($owner['name']) ?>
      </span>
    <?php else: ?>
      <a class="eq-btn ghost" style="padding:8px 16px;font-size:13px" href="<?= e(url('owner-login.php')) ?>">Sign in</a>
      <a class="eq-btn primary" style="padding:8px 16px;font-size:13px" href="<?= e(url('owner-signup.php')) ?>">Create account</a>
    <?php endif; ?>
  </div>
</div>

<div class="eq-progress">
  <div class="row">
    <strong style="color:var(--navy);font-size:13px">Progress</strong>
    <div class="bar"><div class="fill" style="width:<?= (int) ($score['answered'] / max(1, $score['total_indicators']) * 100) ?>%"></div></div>
    <span style="color:var(--muted);font-size:12.5px">
      <?= $score['answered'] ?> / <?= $score['total_indicators'] ?> answered
      <?php if ($score['answered'] > 0): ?>
        · score <strong style="color:var(--navy)"><?= round($score['total'], 0) ?></strong>/100
      <?php endif; ?>
    </span>
  </div>
</div>

<div class="eq-wrap">

<header class="eq-hero">
  <h1>Is your equity structure ready for financing, M&amp;A or IPO?</h1>
  <p>A free 50-indicator health check for Malaysian SMEs — the same framework
     our advisers use with paying clients. Answer at your own pace, save your
     report with a shareable link, come back any time.</p>
  <div class="badges">
    <span>✓ <strong>Free</strong> · no signup</span>
    <span>✓ <strong>Save &amp; return</strong> via unique link</span>
    <span>✓ <strong>50 indicators</strong> across 6 categories</span>
  </div>
</header>

<?php if ($justSaved): ?>
  <div class="eq-savebox">
    <h3>✓ Your report is saved.</h3>
    <p>Bookmark this link — you can come back any time in the next <?= EQ_TRY_REPORT_TTL_DAYS ?> days to update it or share it with your adviser:</p>
    <div class="link" id="save-link"><?= e($publicUrl) ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="eq-btn dark" onclick="navigator.clipboard.writeText(document.getElementById('save-link').textContent.trim()).then(()=>{this.textContent='Copied ✓';setTimeout(()=>this.textContent='Copy link',1500)})">Copy link</button>
      <a class="eq-btn ghost" href="<?= e(url('signup.php')) ?>">Get the full report + adviser consultation →</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="eq-errs">
    <strong>Please note:</strong>
    <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="<?= e(url('equity-try.php' . ($token ? '?token=' . $token : ''))) ?>#result">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <div class="eq-card">
    <div style="font-weight:700;color:var(--navy);margin-bottom:6px">Ownership archetype</div>
    <div class="muted" style="font-size:12.5px;margin-bottom:10px">Pick the closest match to your current shareholding.</div>
    <select name="archetype" style="max-width:520px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
      <option value="">— pick one (optional) —</option>
      <?php foreach (equity_archetypes() as $k => $a): ?>
        <option value="<?= e($k) ?>"<?= $data['archetype'] === $k ? ' selected' : '' ?>>
          <?= e($a['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($data['archetype'] && isset(equity_archetypes()[$data['archetype']])): ?>
      <div class="muted" style="font-size:12.5px;margin-top:8px">
        <?= e(equity_archetypes()[$data['archetype']]['note']) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php foreach ($cats as $c => $meta):
        $catInds = array_filter($inds, fn ($i) => $i['cat'] === $c);
        $catAnswered = 0;
        foreach ($catInds as $ci) { if (($data['scores'][$ci['id']]['score'] ?? null) !== null) { $catAnswered++; } } ?>
    <details class="eq-card eq-cat" <?= $catAnswered > 0 || $c === 'A' ? 'open' : '' ?>>
      <summary>
        <span><?= e($c) ?>. <?= e($meta['label']) ?>
          <span class="zh">· <?= e($meta['label_zh']) ?></span></span>
        <span class="pill"><?= $catAnswered ?> / <?= count($catInds) ?></span>
      </summary>
      <div class="body">
        <div class="muted" style="font-size:12.5px;margin:2px 0 10px"><?= e($meta['blurb']) ?></div>
        <?php foreach ($catInds as $ind):
              $row = $data['scores'][$ind['id']] ?? ['score' => null, 'note' => '']; ?>
          <div class="eq-ind">
            <div class="lbl">
              <?= e($ind['label']) ?>
              <span class="zh">· <?= e($ind['label_zh']) ?></span>
              <small><?= e($ind['focus']) ?></small>
            </div>
            <div>
              <select name="scores[<?= $ind['id'] ?>][score]">
                <option value="">— skip —</option>
                <?php foreach ($scoreBands as $band => [$lab, $desc]): ?>
                  <option value="<?= $band ?>"<?= $row['score'] === $band ? ' selected' : '' ?>>
                    <?= $band ?> · <?= e($lab) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <input name="scores[<?= $ind['id'] ?>][note]" maxlength="800"
                     placeholder="Optional note"
                     value="<?= e($row['note']) ?>">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>

  <div class="eq-card">
    <div style="font-weight:700;color:var(--navy);margin-bottom:6px">Due-diligence documents on file</div>
    <div class="muted" style="font-size:12.5px;margin-bottom:10px">Tick what you already have.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px">
      <?php foreach (equity_dd_checklist() as $key => $label): ?>
        <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;padding:4px 0">
          <input type="checkbox" name="dd[<?= e($key) ?>]" value="1"<?= !empty($data['dd'][$key]) ? ' checked' : '' ?>
                 style="margin-top:3px">
          <span><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="eq-card">
    <div style="font-weight:700;color:var(--navy);margin-bottom:6px">Save your progress</div>
    <div class="muted" style="font-size:12.5px;margin-bottom:10px">
      <?php if ($owner): ?>
        Saves attach to your account (<?= e($owner['email']) ?>) automatically —
        unlimited, accessible from your <a href="<?= e(url('owner-portal.php')) ?>">dashboard</a>.
      <?php else: ?>
        Get a unique link to come back to this report — kept for <?= EQ_TRY_REPORT_TTL_DAYS ?> days.
        Or <a href="<?= e(url('owner-signup.php' . ($token ? '?token=' . $token : ''))) ?>"><strong>create a free account</strong></a>
        to save unlimited reports and see them on your dashboard.
        <?php if (eq_try_saves_used() > 0 && !$rec): ?>
          <br>Anonymous saves used today: <strong><?= eq_try_saves_used() ?></strong> of <?= EQ_TRY_MAX_SAVES_PER_DAY ?>.
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?php if (!$owner): ?>
        <input name="email" type="email" placeholder="Email (optional — we'll show your link)"
               value="<?= e($savedEmail) ?>"
               style="flex:1;min-width:240px;max-width:360px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
      <?php endif; ?>
      <button type="submit" class="eq-btn primary">
        <?= $rec ? 'Update saved report' : ($owner ? 'Save to my account' : 'Save &amp; get link') ?>
      </button>
    </div>
  </div>
</form>

<?php if ($score['answered'] > 0): ?>
  <div id="result" class="eq-card">
    <div class="eq-result-hero">
      <div style="font-size:13px;color:#a8b6c9;text-transform:uppercase;letter-spacing:.06em;font-weight:600">
        Composite equity-structure risk score
      </div>
      <div class="num"><?= round($score['total'], 0) ?><span style="font-size:20px;color:#a8b6c9">/100</span></div>
      <div class="band <?= e($score['band_code']) ?>"><?= e($score['band']) ?></div>
      <div style="font-size:13px;color:#cdd6e4;margin-top:8px">
        Based on <?= $score['answered'] ?> of <?= $score['total_indicators'] ?> indicators answered.
      </div>
    </div>

    <h3 style="color:var(--navy);font-size:17px;margin:6px 0 10px">Category breakdown</h3>
    <?php foreach ($cats as $c => $meta): $r = $score['by_cat'][$c]; ?>
      <div style="margin-bottom:8px">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span><strong><?= e($meta['label']) ?></strong>
            <span class="muted" style="font-size:11.5px">weight <?= $r['weight'] ?>%</span></span>
          <span style="color:var(--navy);font-weight:600">
            <?= $r['answered'] > 0 ? round($r['pct'], 0) . '%' : '<span class="muted" style="font-weight:400">skipped</span>' ?>
          </span>
        </div>
        <div class="eq-bar"><div class="eq-bar-fill" style="width:<?= $r['answered'] > 0 ? (int) $r['pct'] : 0 ?>%"></div></div>
      </div>
    <?php endforeach; ?>

    <?php if ($score['red_flags']): ?>
      <h3 style="color:var(--navy);font-size:17px;margin:18px 0 10px">
        Red flags (<?= count($score['red_flags']) ?>)</h3>
      <?php foreach ($score['red_flags'] as $rf): ?>
        <div class="eq-rf <?= $rf['source'] === 'auto' ? 'auto' : '' ?>">
          <strong><?= e($rf['label']) ?></strong>
          <div class="muted" style="font-size:12px">Impact: <?= e($rf['impact']) ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="eq-disclaimer">
      <strong>This is an indicative health check for discussion only.</strong>
      It does not constitute legal, tax, Shariah or SSM compliance advice.
      Actions touching the Companies Act, foreign-equity conditions, nominee
      arrangements, faraid / hibah / wasiat or SHA drafting need a licensed
      lawyer, tax agent, company secretary or Shariah planner.
    </div>

    <div class="eq-savebox" style="margin-top:20px">
      <h3>Want an adviser to walk you through this?</h3>
      <p>Sign up free to book a session, get the AI-written commentary, and a
         polished PDF report you can share with a lawyer, tax agent or
         investor.</p>
      <a class="eq-btn dark" href="<?= e(url('signup.php')) ?>">Sign up free</a>
    </div>
  </div>

  <?php if ($justSaved): ?>
    <script>document.getElementById('result').scrollIntoView({behavior:'smooth',block:'start'});</script>
  <?php endif; ?>
<?php endif; ?>

<footer style="text-align:center;padding:30px 0;color:var(--muted);font-size:12.5px">
  © <?= date('Y') ?> <?= e(APP_NAME) ?> · Built for Malaysian SMEs ·
  <a href="<?= e(url('index.php')) ?>" style="color:var(--muted)">Home</a> ·
  <a href="<?= e(url('valuation-try.php')) ?>" style="color:var(--muted)">Free valuation tool</a> ·
  <a href="<?= e(url('signup.php')) ?>" style="color:var(--muted)">Sign up</a>
</footer>

</div>
</body>
</html>
