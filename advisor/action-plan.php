<?php
/** AdvisorOS — Strategic Report: visual analytics + tiered action plan. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/action_plan.php';
require_once __DIR__ . '/../includes/charts.php';
require_once __DIR__ . '/../includes/billing.php';
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found. Open a client and choose “Strategic plan”.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

if (is_post()) {
    csrf_check();
    if (input('action') === 'status') {
        action_status_save($clientId, (string) input('key', ''),
            (string) input('status', 'open'), (string) input('note', ''));
        set_flash('success', 'Action updated.');
    } elseif (input('action') === 'generate') {
        meter_report('actionplan');
        audit_log('generate', 'action_plan', $clientId, 'Strategic action plan generated');
        set_flash('success', 'Strategic report refreshed and logged.');
    }
    redirect('advisor/action-plan.php?client_id=' . $clientId);
}

$p   = action_plan_build($pdo, $tid, $clientId);
$st  = action_status_all($clientId);
$w   = $p['wealth'];
$suc = $p['succession'];

$vLow = $vMid = $vHigh = 0.0;
foreach ($p['valuation']['rows'] ?? [] as $row) {
    if (!empty($row['val']['has_data'])) {
        $vLow  += $row['val']['low'];  $vMid += $row['val']['mid'];  $vHigh += $row['val']['high'];
    }
}

$pageTitle = 'Strategic Report — ' . $client['full_name'];
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Strategic Report</h2>
    <span class="muted"><?= e($client['full_name']) ?> · visual summary &amp; prioritised action plan</span>
  </div>
  <div style="display:flex;gap:8px">
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="generate">
      <button class="btn-os sm">Refresh &amp; log</button>
    </form>
    <a class="btn-os ghost sm" href="<?= e(url('advisor/client-view.php?id='.$clientId)) ?>">Back to client</a>
  </div>
</div>

<?php if (!$p['has_data']): ?>
  <div class="alert-os warning">Not enough data yet. Capture a personal
    financial snapshot and/or a company under
    <a href="<?= e(url('advisor/companies.php?client_id='.$clientId)) ?>">Business profile</a>,
    then return here.</div>
<?php else: ?>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="card-os"><div class="card-os-head">Net worth split</div>
    <div class="card-os-body" style="text-align:center">
      <?php if ($w): ?>
        <?= svg_donut([
              ['Personal', max(0, $w['p_net']), 'var(--navy)'],
              ['Business', max(0, $w['biz_equity']), 'var(--gold)'],
            ], 150, 'RM ' . money($w['total_net'])) ?>
        <div class="muted" style="font-size:12px;margin-top:6px">
          <span style="color:var(--navy)">■</span> Personal RM <?= money($w['p_net']) ?>
          · <span style="color:var(--gold)">■</span> Business RM <?= money($w['biz_equity']) ?></div>
      <?php else: ?><div class="muted" style="padding:30px">No wealth data.</div><?php endif; ?>
    </div></div>

  <div class="card-os"><div class="card-os-head">Succession readiness</div>
    <div class="card-os-body" style="text-align:center">
      <?php if ($suc): $gc = $suc['band_cls']==='b-active'?'var(--ok)':($suc['band_cls']==='b-overdue'?'var(--danger)':'var(--gold)'); ?>
        <?= svg_gauge((float) $suc['score'], $gc) ?>
        <div style="margin-top:4px"><span class="badge-os <?= $suc['band_cls'] ?>"><?= e($suc['band']) ?></span>
          <span class="muted" style="font-size:12px">· <?= e($suc['verdict']) ?></span></div>
      <?php else: ?><div class="muted" style="padding:30px">No succession data.</div><?php endif; ?>
    </div></div>

  <div class="card-os"><div class="card-os-head">Plan summary</div>
    <div class="card-os-body">
      <div style="display:flex;gap:10px;text-align:center">
        <?php foreach (['immediate'=>'var(--danger)','mid'=>'var(--gold)','long'=>'var(--ok)'] as $k=>$col): ?>
          <div style="flex:1;background:<?= $col ?>14;border-radius:8px;padding:12px 6px">
            <div style="font-size:24px;font-weight:800;color:<?= $col ?>"><?= (int) $p['counts'][$k] ?></div>
            <div class="muted" style="font-size:11px"><?= e(explode(' ', ACTION_TIERS[$k])[0]) ?></div></div>
        <?php endforeach; ?>
      </div>
      <?php if ($vHigh > 0): ?>
        <div style="margin-top:14px">
          <div class="muted" style="font-size:12px;margin-bottom:4px">Indicative business value</div>
          <?= svg_range($vLow, $vMid, $vHigh, 300) ?>
          <div class="muted" style="font-size:11px">RM <?= money($vLow) ?> – <?= money($vHigh) ?> · mid RM <?= money($vMid) ?></div>
        </div>
      <?php endif; ?>
    </div></div>
</div>

<?php foreach (ACTION_TIERS as $tk => $tlabel):
  $items = $p['tiers'][$tk];
  $done = 0;
  foreach ($items as $it) { if (($st[$it['key']]['status'] ?? '') === 'done') { $done++; } }
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head"><?= e($tlabel) ?>
    <span class="muted" style="font-weight:400">· <?= $done ?>/<?= count($items) ?> done</span></div>
  <div class="card-os-body" style="padding:<?= $items ? '0' : '18px' ?>">
    <?php if (!$items): ?>
      <span class="muted">No actions in this horizon.</span>
    <?php else: ?>
      <table class="table-os">
        <tbody>
        <?php foreach ($items as $it): $cur = $st[$it['key']] ?? ['status'=>'open','note'=>'']; ?>
          <tr style="<?= in_array($cur['status'],['done','dismissed'],true)?'opacity:.55':'' ?>">
            <td style="width:42%">
              <strong><?= e($it['title']) ?></strong>
              <span class="badge-os b-scheduled" style="font-size:10px"><?= e($it['source']) ?></span>
              <div class="muted" style="font-size:12px"><?= e($it['why']) ?></div>
              <div style="font-size:13px;margin-top:4px"><?= e($it['action']) ?></div>
            </td>
            <td>
              <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="key" value="<?= e($it['key']) ?>">
                <select name="status">
                  <?php foreach (ACTION_STATUSES as $sk=>$sl): ?>
                    <option value="<?= $sk ?>" <?= ($cur['status']??'open')===$sk?'selected':'' ?>><?= e($sl) ?></option>
                  <?php endforeach; ?>
                </select>
                <input name="note" value="<?= e($cur['note'] ?? '') ?>" placeholder="note"
                       style="flex:1;min-width:120px">
                <button class="btn-os ghost sm">Save</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div class="card-os">
  <div class="card-os-body">
    <div class="disclaimer">This strategic report synthesises the platform's
      diagnostic estimates using transparent rules — it is not financial,
      tax, legal or investment advice. Every action must be validated with
      the client and reviewed by a licensed financial advisor before
      execution.</div>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
