<?php
/** AdvisorOS — Client portal: appointments. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';

$c   = portal_client();
$st  = db()->prepare(
    'SELECT a.*, u.name AS advisor FROM appointments a
     LEFT JOIN users u ON u.id=a.advisor_id
     WHERE a.client_id=? AND a.tenant_id=? AND a.deleted_at IS NULL
     ORDER BY a.scheduled_at DESC LIMIT 100'
);
$st->execute([(int) $c['id'], (int) $c['tenant_id']]);
$rows = $st->fetchAll();

$pageTitle = 'My Appointments';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os">
  <div class="card-os-head">My Appointments</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>When</th><th>Title</th><th>Type</th><th>Advisor</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted" style="padding:24px">No appointments.</td></tr>
      <?php else: foreach ($rows as $a): ?>
        <tr>
          <td><?= fmt_date($a['scheduled_at'],'d M Y H:i') ?></td>
          <td><?= e($a['title']) ?></td>
          <td><?= label($a['type']) ?></td>
          <td><?= e($a['advisor'] ?: '—') ?></td>
          <td><span class="badge-os b-<?= e($a['status']) ?>"><?= label($a['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
