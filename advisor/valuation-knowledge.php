<?php
/** AdvisorOS — Valuation Knowledge Base (educate the AI valuation commentary). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/valuation_kb.php';
require_permission('financial.manage');

require_tenant();
$editId = (int) ($_GET['edit'] ?? 0);

if (is_post()) {
    csrf_check();
    $action = (string) input('action', 'save');

    if ($action === 'delete') {
        val_kb_delete((int) input('id', 0));
        audit_log('delete', 'valuation_kb', (int) input('id', 0), 'Valuation knowledge entry removed');
        set_flash('success', 'Entry removed.');
        redirect('advisor/valuation-knowledge.php');
    }
    if ($action === 'toggle') {
        $e = val_kb_get((int) input('id', 0));
        if ($e) {
            val_kb_save((int) $e['id'], (string) $e['title'], (string) ($e['category'] ?? ''),
                (string) ($e['body'] ?? ''), empty($e['active']));
        }
        redirect('advisor/valuation-knowledge.php');
    }

    $title = trim((string) input('title', ''));
    $body  = trim((string) input('body', ''));
    if ($title === '' || $body === '') {
        set_flash('danger', 'Title and content are both required.');
        redirect('advisor/valuation-knowledge.php' . (($id = (int) input('id', 0)) ? '?edit=' . $id : ''));
    }
    $newId = val_kb_save((int) input('id', 0), $title, (string) input('category', ''),
        $body, input('active', '') !== '');
    audit_log((int) input('id', 0) ? 'update' : 'create', 'valuation_kb', $newId, 'Valuation knowledge saved');
    set_flash('success', 'Knowledge entry saved.');
    redirect('advisor/valuation-knowledge.php');
}

$entries = val_kb_all();
$editing = $editId ? val_kb_get($editId) : null;

$pageTitle = 'Valuation Knowledge Base';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Valuation Knowledge Base</h2>
    <span class="muted">Paste your valuation methodology, comparable-transaction notes, house style — the AI uses active entries when writing valuation commentary.</span>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head"><?= $editing ? 'Edit entry' : 'Add knowledge' ?>
      <?php if ($editing): ?>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/valuation-knowledge.php')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <div class="form-row"><label>Title *</label>
          <input name="title" required maxlength="140" value="<?= e($editing['title'] ?? '') ?>"
                 placeholder="e.g. SME multiple calibration · Marketability discount policy"></div>
        <div class="form-row"><label>Category</label>
          <input name="category" maxlength="50" value="<?= e($editing['category'] ?? '') ?>"
                 placeholder="Methodology / Comparables / Adjustments / Discounts / House style"></div>
        <div class="form-row"><label>Content * <span class="muted" style="font-weight:400">(paste from your documents — methodology, comparables, discount policy, traps)</span></label>
          <textarea name="body" rows="14" required maxlength="12000"
            placeholder="Paste the guidance you want the AI to follow when writing valuation commentary…"><?= e($editing['body'] ?? '') ?></textarea></div>
        <label style="display:flex;gap:8px;align-items:center;margin:6px 0 14px">
          <input type="checkbox" name="active" value="1" <?= (!$editing || !empty($editing['active'])) ? 'checked' : '' ?>>
          <span>Active — feed this to the AI valuation commentary</span></label>
        <button class="btn-os gold"><?= $editing ? 'Save entry' : 'Add entry' ?></button>
      </form>
      <div class="disclaimer">Valuation figures (NAV, EBITDA equity, DCF, weighted
        mid, discount stack) come from the system's verified engine, not the AI.
        The knowledge base steers the AI's <em>narrative</em> only.</div>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Knowledge entries <span class="muted" style="font-weight:400">(<?= count($entries) ?>)</span></div>
    <div class="card-os-body" style="padding:0">
      <?php if (!$entries): ?>
        <div style="padding:18px" class="muted">No entries yet. Paste your valuation
          methodology on the left — each active entry becomes context for the AI
          when it writes commentary for a client's valuation report.</div>
      <?php else: ?>
        <table class="table-os">
          <thead><tr><th>Entry</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($entries as $e): ?>
            <tr>
              <td><strong><?= e($e['title']) ?></strong>
                <?php if (!empty($e['category'])): ?><span class="badge-os b-scheduled" style="font-size:11px"><?= e($e['category']) ?></span><?php endif; ?>
                <div class="muted" style="font-size:12px"><?= e(mb_strimwidth((string) ($e['body'] ?? ''), 0, 90, '…')) ?></div></td>
              <td><span class="badge-os <?= !empty($e['active']) ? 'b-active' : 'b-warn' ?>"><?= !empty($e['active']) ? 'Active' : 'Inactive' ?></span></td>
              <td style="text-align:right;white-space:nowrap">
                <a class="btn-os ghost sm" href="<?= e(url('advisor/valuation-knowledge.php?edit='.(int)$e['id'])) ?>">Edit</a>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                  <button class="btn-os ghost sm"><?= !empty($e['active']) ? 'Disable' : 'Enable' ?></button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this entry?')"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
                  <button class="btn-os ghost sm" style="color:var(--danger)">Delete</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
