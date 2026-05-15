<?php
/** AdvisorOS — Compliance audit trail (tenant scoped). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('audit.view');

$tid = require_tenant();
$st = db()->prepare(
    "SELECT a.*, u.name AS user_name FROM audit_logs a
     LEFT JOIN users u ON u.id=a.user_id
     WHERE a.tenant_id=? ORDER BY a.created_at DESC LIMIT 400"
);
$st->execute([$tid]);
$rows = $st->fetchAll();

$pageTitle = 'Audit Trail';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os">
  <div class="card-os-head">Compliance Audit Trail</div>
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
