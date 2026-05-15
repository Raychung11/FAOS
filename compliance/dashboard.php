<?php
/** AdvisorOS — Compliance dashboard. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('compliance.view');

$tid = require_tenant();
$pdo = db();

// Overdue reviews
$ovr = $pdo->prepare(
    "SELECT id,full_name,next_review_date FROM clients
     WHERE tenant_id=? AND deleted_at IS NULL AND status='active'
       AND next_review_date IS NOT NULL AND next_review_date < CURDATE()
     ORDER BY next_review_date ASC LIMIT 50"
);
$ovr->execute([$tid]);
$overdue = $ovr->fetchAll();

// Clients missing a consent form
$mc = $pdo->prepare(
    "SELECT c.id,c.full_name FROM clients c
     WHERE c.tenant_id=? AND c.deleted_at IS NULL AND c.status='active'
       AND NOT EXISTS (
         SELECT 1 FROM documents d
         WHERE d.client_id=c.id AND d.tenant_id=c.tenant_id
           AND d.category='consent_form' AND d.deleted_at IS NULL)
     ORDER BY c.full_name LIMIT 50"
);
$mc->execute([$tid]);
$missingConsent = $mc->fetchAll();

// Expiring policies (60d)
$ep = $pdo->prepare(
    "SELECT p.id,p.provider,p.renewal_date,c.full_name FROM policies p
     JOIN clients c ON c.id=p.client_id
     WHERE p.tenant_id=? AND p.deleted_at IS NULL AND p.status='active'
       AND p.renewal_date IS NOT NULL
       AND p.renewal_date <= (CURDATE()+INTERVAL 60 DAY)
     ORDER BY p.renewal_date ASC LIMIT 50"
);
$ep->execute([$tid]);
$expiring = $ep->fetchAll();

// Reviews awaiting client acknowledgement
$na = $pdo->prepare(
    "SELECT ar.id,ar.review_date,c.full_name FROM annual_reviews ar
     JOIN clients c ON c.id=ar.client_id
     WHERE ar.tenant_id=? AND ar.deleted_at IS NULL
       AND ar.status='completed' AND ar.client_acknowledged=0
     ORDER BY ar.review_date DESC LIMIT 50"
);
$na->execute([$tid]);
$noAck = $na->fetchAll();

$pageTitle = 'Compliance Dashboard';
require __DIR__ . '/../includes/header.php';

$panel = static function (string $title, array $rows, callable $render, string $empty) {
    echo '<div class="card-os"><div class="card-os-head">' . e($title)
        . ' <span class="badge-os b-' . (count($rows) ? 'overdue' : 'active') . '">'
        . count($rows) . '</span></div><div class="card-os-body" style="padding:0">'
        . '<table class="table-os"><tbody>';
    if (!$rows) {
        echo '<tr><td class="muted" style="padding:20px">' . e($empty) . '</td></tr>';
    } else {
        foreach ($rows as $r) { echo '<tr><td>' . $render($r) . '</td></tr>'; }
    }
    echo '</tbody></table></div></div>';
};
?>
<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat accent"><div class="stat-label">Overdue Reviews</div>
    <div class="stat-value" style="color:var(--danger)"><?= count($overdue) ?></div></div>
  <div class="stat accent"><div class="stat-label">Missing Consent</div>
    <div class="stat-value" style="color:var(--warn)"><?= count($missingConsent) ?></div></div>
  <div class="stat accent"><div class="stat-label">Expiring Policies</div>
    <div class="stat-value"><?= count($expiring) ?></div></div>
  <div class="stat accent"><div class="stat-label">Awaiting Acknowledgement</div>
    <div class="stat-value"><?= count($noAck) ?></div></div>
</div>

<div class="grid cols-2">
  <?php
  $panel('Overdue Annual Reviews', $overdue, fn($r) =>
    e($r['full_name']) . ' <span class="muted">— due ' . fmt_date($r['next_review_date']) . '</span>',
    'No overdue reviews.');
  $panel('Clients Missing Consent Form', $missingConsent, fn($r) =>
    e($r['full_name']), 'All active clients have a consent form on file.');
  $panel('Policies Expiring (60 days)', $expiring, fn($r) =>
    e($r['full_name']) . ' <span class="muted">— ' . e($r['provider']) . ', '
      . fmt_date($r['renewal_date']) . '</span>', 'No policies expiring soon.');
  $panel('Reviews Awaiting Client Acknowledgement', $noAck, fn($r) =>
    e($r['full_name']) . ' <span class="muted">— ' . fmt_date($r['review_date']) . '</span>',
    'No reviews awaiting acknowledgement.');
  ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
