<?php
/** AdvisorOS — Conduct / edit an annual review. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reviews.manage');

$tid = require_tenant();
$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);
$review = null;

if ($id) {
    $st = $pdo->prepare('SELECT * FROM annual_reviews WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
    $st->execute([$id, $tid]);
    $review = $st->fetch();
    if (!$review) { http_response_code(404); exit('Review not found.'); }
    $clientId = (int) $review['client_id'];
}

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found.'); }

$checklistItems = [
    'contact_updated'   => 'Contact details confirmed / updated',
    'financial_updated' => 'Financial situation reviewed',
    'policies_reviewed' => 'Policies & coverage reviewed',
    'goals_reviewed'    => 'Financial goals revisited',
    'risk_reviewed'     => 'Risk profile reconfirmed',
    'beneficiary_check' => 'Beneficiary nominations checked',
    'docs_complete'     => 'Compliance documents complete',
];

if (is_post()) {
    csrf_check();
    $checklist = [];
    foreach (array_keys($checklistItems) as $k) {
        $checklist[$k] = !empty($_POST['cl'][$k]);
    }
    $d = [
        'advisor_id'        => current_user()['id'],
        'review_date'       => input('review_date') ?: date('Y-m-d'),
        'next_review_date'  => input('next_review_date') ?: null,
        'checklist'         => json_encode($checklist),
        'financial_changes' => (string) input('financial_changes',''),
        'policy_changes'    => (string) input('policy_changes',''),
        'goal_updates'      => (string) input('goal_updates',''),
        'risk_review'       => (string) input('risk_review',''),
        'recommendation'    => (string) input('recommendation',''),
        'client_acknowledged'=> !empty($_POST['client_acknowledged']) ? 1 : 0,
        'acknowledged_at'   => !empty($_POST['client_acknowledged']) ? date('Y-m-d H:i:s') : null,
        'status'            => in_array(input('status'),['scheduled','in_progress','completed','overdue'],true)?input('status'):'in_progress',
    ];

    if ($id) {
        $cols = implode('=?,', array_keys($d)) . '=?';
        $st = $pdo->prepare("UPDATE annual_reviews SET $cols WHERE id=? AND tenant_id=?");
        $st->execute([...array_values($d), $id, $tid]);
        audit_log('update','reviews',$id,'Annual review updated');
    } else {
        $cols = implode(',', array_keys($d));
        $ph   = rtrim(str_repeat('?,', count($d)), ',');
        $st = $pdo->prepare(
            "INSERT INTO annual_reviews (tenant_id,client_id,$cols,created_by)
             VALUES (?,?,$ph,?)"
        );
        $st->execute([$tid, $clientId, ...array_values($d), current_user()['id']]);
        $id = (int) $pdo->lastInsertId();
        audit_log('create','reviews',$id,'Annual review conducted');
    }

    // Roll the client review dates forward.
    $pdo->prepare('UPDATE clients SET last_review_date=?, next_review_date=? WHERE id=? AND tenant_id=?')
        ->execute([$d['review_date'], $d['next_review_date'], $clientId, $tid]);

    set_flash('success','Annual review saved. Client servicing record updated.');
    redirect('advisor/client-view.php?id=' . $clientId);
}

$existing = $review ? json_decode((string)($review['checklist'] ?? '{}'), true) : [];
$rv = static fn (string $k, $d='') => e($review[$k] ?? $d);
$pageTitle = 'Annual Review — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="max-width:920px">
  <div class="card-os-head">Annual Review — <?= e($client['full_name']) ?>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
  <div class="card-os-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Review date</label>
          <input type="date" name="review_date" value="<?= $rv('review_date', date('Y-m-d')) ?>"></div>
        <div class="form-row"><label>Next review date</label>
          <input type="date" name="next_review_date" value="<?= $rv('next_review_date', date('Y-m-d', strtotime('+1 year'))) ?>"></div>
      </div>

      <h4 style="color:var(--navy);margin:8px 0 10px">Review checklist</h4>
      <div class="grid cols-2" style="gap:6px 18px;margin-bottom:18px">
        <?php foreach ($checklistItems as $k => $lbl): ?>
          <label style="display:flex;gap:9px;align-items:center;font-weight:500">
            <input type="checkbox" name="cl[<?= e($k) ?>]" value="1" style="width:auto"
              <?= !empty($existing[$k]) ? 'checked' : '' ?>> <?= e($lbl) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="form-row"><label>Updated financial situation</label>
        <textarea name="financial_changes" rows="2"><?= $rv('financial_changes') ?></textarea></div>
      <div class="form-row"><label>Policy changes</label>
        <textarea name="policy_changes" rows="2"><?= $rv('policy_changes') ?></textarea></div>
      <div class="form-row"><label>Goal updates</label>
        <textarea name="goal_updates" rows="2"><?= $rv('goal_updates') ?></textarea></div>
      <div class="form-row"><label>Risk profile review</label>
        <textarea name="risk_review" rows="2"><?= $rv('risk_review') ?></textarea></div>
      <div class="form-row"><label>Advisor recommendation</label>
        <textarea name="recommendation" rows="3"><?= $rv('recommendation') ?></textarea></div>

      <div class="form-grid">
        <div class="form-row"><label>Status</label>
          <select name="status">
            <?php foreach (['scheduled','in_progress','completed','overdue'] as $s): ?>
              <option value="<?= $s ?>" <?= ($review['status']??'in_progress')===$s?'selected':'' ?>><?= label($s) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-row" style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:9px;align-items:center;font-weight:500;margin:0">
            <input type="checkbox" name="client_acknowledged" value="1" style="width:auto"
              <?= !empty($review['client_acknowledged']) ? 'checked' : '' ?>>
            Client acknowledged this review
          </label>
        </div>
      </div>

      <button class="btn-os gold">Save annual review</button>
    </form>
    <div class="disclaimer">This is not financial advice. Final recommendations must be
      reviewed and approved by a licensed financial advisor.</div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
