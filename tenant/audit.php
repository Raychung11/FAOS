<?php
/** AdvisorOS — Tenant audit trail. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('audit.view');

$tid = require_tenant();
$module = (string) input('module','');

$sql = "SELECT a.*, u.name AS user_name FROM audit_logs a
        LEFT JOIN users u ON u.id=a.user_id
        WHERE a.tenant_id=?";
$args = [$tid];
if ($module !== '') { $sql .= ' AND a.module=?'; $args[] = $module; }
$sql .= ' ORDER BY a.created_at DESC LIMIT 300';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$pageTitle = 'Audit Trail';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os">
  <div class="card-os-head">Audit Trail
    <form method="get">
      <input name="module" placeholder="Filter by module" value="<?= e($module) ?>"
             style="padding:7px 10px;border:1px solid var(--line);border-radius:8px">
    </form>
  </div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th>
        <th>Record</th><th>Description</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No audit entries.</td></tr>
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
