<?php
/** AdvisorOS — Tenant audit trail & company audit report.
 *  Filterable activity log with a date-range summary (totals,
 *  activity by module, by user and by action type). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('audit.view');

$tid = require_tenant();
$pdo = db();

$module   = (string) input('module', '');
$dateFrom = (string) input('date_from', date('Y-m-d', strtotime('-30 days')));
$dateTo   = (string) input('date_to', date('Y-m-d'));

// Validate dates; fall back silently on malformed input.
$validDate = static fn (string $d): bool =>
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
if (!$validDate($dateFrom)) { $dateFrom = date('Y-m-d', strtotime('-30 days')); }
if (!$validDate($dateTo))   { $dateTo   = date('Y-m-d'); }
if ($dateFrom > $dateTo)    { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

// Shared filter applied to every query in the report.
$where = 'a.tenant_id = ?
          AND a.created_at >= ?
          AND a.created_at <  (? + INTERVAL 1 DAY)';
$args  = [$tid, $dateFrom, $dateTo];
if ($module !== '') {
    $where .= ' AND a.module = ?';
    $args[]  = $module;
}

$run = static function (string $sql, array $a) use ($pdo) {
    $s = $pdo->prepare($sql);
    $s->execute($a);
    return $s;
};

// ---- Summary ------------------------------------------------------
$total       = (int) $run("SELECT COUNT(*) FROM audit_logs a WHERE $where", $args)->fetchColumn();
$activeUsers = (int) $run("SELECT COUNT(DISTINCT a.user_id) FROM audit_logs a WHERE $where", $args)->fetchColumn();
$modCount    = (int) $run("SELECT COUNT(DISTINCT a.module) FROM audit_logs a WHERE $where", $args)->fetchColumn();

$byModule = $run(
    "SELECT a.module, COUNT(*) c FROM audit_logs a
     WHERE $where GROUP BY a.module ORDER BY c DESC", $args
)->fetchAll();

$byAction = $run(
    "SELECT a.action, COUNT(*) c FROM audit_logs a
     WHERE $where GROUP BY a.action ORDER BY c DESC", $args
)->fetchAll();

$byUser = $run(
    "SELECT COALESCE(u.name,'—') name, COUNT(*) c FROM audit_logs a
     LEFT JOIN users u ON u.id=a.user_id
     WHERE $where GROUP BY a.user_id ORDER BY c DESC LIMIT 10", $args
)->fetchAll();

// ---- Detail (capped) ---------------------------------------------
$rows = $run(
    "SELECT a.*, u.name AS user_name FROM audit_logs a
     LEFT JOIN users u ON u.id=a.user_id
     WHERE $where ORDER BY a.created_at DESC LIMIT 500", $args
)->fetchAll();

$bar = static function (int $c, int $max): string {
    $pct = $max > 0 ? max(4, (int) round($c / $max * 100)) : 0;
    return '<div style="background:#eef2fb;border-radius:6px;height:8px;overflow:hidden">'
         . '<div style="width:' . $pct . '%;height:8px;background:var(--gold)"></div></div>';
};
$maxMod = $byModule ? (int) max(array_column($byModule, 'c')) : 0;
$maxUsr = $byUser   ? (int) max(array_column($byUser, 'c'))   : 0;

$tenant = current_tenant();
$pageTitle = 'Company Audit Report';
require __DIR__ . '/../includes/header.php';
?>
<style>@media print{.sidebar,.topbar,footer,.no-print{display:none!important}
.main{margin:0!important}.content{padding:0!important}}</style>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">
    Company Audit Report — <?= e($tenant['company_name'] ?? APP_NAME) ?>
    <button class="btn-os ghost sm no-print" onclick="window.print()">Print report</button>
  </div>
  <div class="card-os-body">
    <form method="get" class="no-print" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
      <div class="form-row" style="margin:0">
        <label>From</label>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
      </div>
      <div class="form-row" style="margin:0">
        <label>To</label>
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
      </div>
      <div class="form-row" style="margin:0">
        <label>Module</label>
        <input name="module" placeholder="All modules" value="<?= e($module) ?>">
      </div>
      <button class="btn-os sm">Apply</button>
      <a class="btn-os ghost sm" href="<?= e(url('tenant/audit.php')) ?>">Reset</a>
    </form>
    <p class="muted" style="margin:14px 0 0;font-size:13px">
      Reporting period <strong><?= fmt_date($dateFrom) ?></strong> to
      <strong><?= fmt_date($dateTo) ?></strong>
      <?= $module !== '' ? ' · module: <strong>' . e($module) . '</strong>' : '' ?>
      · generated <?= fmt_date(date('Y-m-d H:i:s'), 'd M Y H:i') ?>
    </p>
  </div>
</div>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Total Activity</div>
    <div class="stat-value"><?= $total ?></div><div class="stat-foot">Audited actions in period</div></div>
  <div class="stat accent"><div class="stat-label">Active Users</div>
    <div class="stat-value"><?= $activeUsers ?></div><div class="stat-foot">Users with recorded activity</div></div>
  <div class="stat accent"><div class="stat-label">Modules Touched</div>
    <div class="stat-value"><?= $modCount ?></div><div class="stat-foot">Distinct modules</div></div>
</div>

<div class="grid cols-2" style="margin-bottom:18px">
  <div class="card-os">
    <div class="card-os-head">Activity by Module</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>Module</th><th style="width:45%">Share</th><th>Count</th></tr></thead>
        <tbody>
        <?php if (!$byModule): ?>
          <tr><td colspan="3" class="muted" style="padding:20px">No activity in this period.</td></tr>
        <?php else: foreach ($byModule as $m): ?>
          <tr><td><?= e(label($m['module'])) ?></td>
              <td><?= $bar((int)$m['c'], $maxMod) ?></td>
              <td><strong><?= (int)$m['c'] ?></strong></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Most Active Users</div>
    <div class="card-os-body" style="padding:0">
      <table class="table-os">
        <thead><tr><th>User</th><th style="width:45%">Share</th><th>Count</th></tr></thead>
        <tbody>
        <?php if (!$byUser): ?>
          <tr><td colspan="3" class="muted" style="padding:20px">No activity in this period.</td></tr>
        <?php else: foreach ($byUser as $u): ?>
          <tr><td><?= e($u['name']) ?></td>
              <td><?= $bar((int)$u['c'], $maxUsr) ?></td>
              <td><strong><?= (int)$u['c'] ?></strong></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Activity by Action</div>
  <div class="card-os-body">
    <?php if (!$byAction): ?>
      <span class="muted">No activity in this period.</span>
    <?php else: foreach ($byAction as $ac): ?>
      <span class="badge-os b-new" style="margin:0 8px 8px 0;display:inline-block">
        <?= e(label($ac['action'])) ?> · <strong><?= (int)$ac['c'] ?></strong>
      </span>
    <?php endforeach; endif; ?>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Detailed Log
    <span class="muted" style="font-size:12px;font-weight:400">showing up to 500 most recent</span>
  </div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th>
        <th>Record</th><th>Description</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No audit entries in this period.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= fmt_date($r['created_at'],'d M Y H:i') ?></td>
          <td><?= e($r['user_name'] ?: '—') ?></td>
          <td><span class="badge-os b-new"><?= label($r['action']) ?></span></td>
          <td><?= e($r['module']) ?></td>
          <td><?= $r['record_id'] ? '#'.(int)$r['record_id'] : '—' ?></td>
          <td><?= e($r['description'] ?: '—') ?></td>
          <td class="muted"><?= e($r['ip_address'] ?: '—') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
