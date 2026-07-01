<?php
/** AdvisorOS — Advisory Skills Library.
 *  Firm-wide reusable advisory playbooks / recommendation snippets the
 *  AI proposal generator pulls in as house-style guidance. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/skills.php';
require_permission('proposals.manage');

require_tenant();
$editId = (int) ($_GET['edit'] ?? 0);

if (is_post()) {
    csrf_check();
    $action = (string) input('action', 'save');

    if ($action === 'delete') {
        $did = (int) input('id', 0);
        skill_delete($did);
        audit_log('delete', 'skill', $did, 'Advisory skill removed');
        set_flash('success', 'Skill removed.');
        redirect('advisor/skills.php');
    }

    if ($action === 'toggle') {
        $tid = (int) input('id', 0);
        $s = skill_get($tid);
        if ($s) {
            skill_save($tid, (string) $s['title'], (string) ($s['category'] ?? ''),
                (string) ($s['body'] ?? ''), empty($s['active']), (string) ($s['author'] ?? ''));
        }
        redirect('advisor/skills.php');
    }

    $sid   = (int) input('id', 0);
    $title = trim((string) input('title', ''));
    $body  = trim((string) input('body', ''));
    if ($title === '' || $body === '') {
        set_flash('danger', 'Title and guidance body are both required.');
        redirect('advisor/skills.php' . ($sid ? '?edit=' . $sid : ''));
    }
    $newId = skill_save(
        $sid, $title, (string) input('category', ''), $body,
        input('active', '') !== '', current_user()['name']
    );
    audit_log($sid ? 'update' : 'create', 'skill', $newId, 'Advisory skill saved');
    set_flash('success', 'Skill saved.');
    redirect('advisor/skills.php');
}

$skills  = skills_all();
$editing = $editId ? skill_get($editId) : null;

$pageTitle = 'Advisory Skills Library';
require __DIR__ . '/../includes/header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:var(--navy)">Advisory Skills Library</h2>
    <span class="muted">Reusable playbooks the AI proposal generator applies as house-style guidance</span>
  </div>
</div>

<div class="grid cols-2">
  <div class="card-os">
    <div class="card-os-head"><?= $editing ? 'Edit skill' : 'Add a skill' ?>
      <?php if ($editing): ?>
        <a class="btn-os ghost sm" href="<?= e(url('advisor/skills.php')) ?>">Cancel</a>
      <?php endif; ?>
    </div>
    <div class="card-os-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <div class="form-row"><label>Title *</label>
          <input name="title" required maxlength="120"
                 value="<?= e($editing['title'] ?? '') ?>"
                 placeholder="e.g. Protection-gap closing approach"></div>
        <div class="form-row"><label>Category</label>
          <input name="category" maxlength="40"
                 value="<?= e($editing['category'] ?? '') ?>"
                 placeholder="Protection / Retirement / Tax / Estate…"></div>
        <div class="form-row"><label>Guidance body * <span class="muted"
            style="font-weight:400">(injected into the AI prompt)</span></label>
          <textarea name="body" rows="9" required maxlength="4000"
            placeholder="Describe the advisory approach, talking points, sequencing and house rules the AI should follow when this skill applies…"><?= e($editing['body'] ?? '') ?></textarea></div>
        <label style="display:flex;gap:8px;align-items:center;margin:6px 0 14px">
          <input type="checkbox" name="active" value="1"
            <?= (!$editing || !empty($editing['active'])) ? 'checked' : '' ?>>
          <span>Active — available to the AI proposal generator</span></label>
        <button class="btn-os gold"><?= $editing ? 'Save skill' : 'Add skill' ?></button>
      </form>
    </div>
  </div>

  <div class="card-os">
    <div class="card-os-head">Library <span class="muted"
      style="font-weight:400">(<?= count($skills) ?>)</span></div>
    <div class="card-os-body" style="padding:0">
      <?php if (!$skills): ?>
        <div style="padding:18px" class="muted">No skills yet. Add your first
          advisory playbook on the left — it becomes selectable when
          generating a proposal.</div>
      <?php else: ?>
        <table class="table-os">
          <thead><tr><th>Skill</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($skills as $s): ?>
            <tr>
              <td><strong><?= e($s['title']) ?></strong>
                <?php if (!empty($s['category'])): ?>
                  <span class="badge-os b-scheduled" style="font-size:11px"><?= e($s['category']) ?></span>
                <?php endif; ?>
                <div class="muted" style="font-size:12px">
                  <?= e(mb_strimwidth((string) ($s['body'] ?? ''), 0, 90, '…')) ?></div>
                <div class="muted" style="font-size:11px">by <?= e($s['author'] ?? '—') ?> · <?= e($s['updated_at'] ?? '') ?></div>
              </td>
              <td><span class="badge-os <?= !empty($s['active']) ? 'b-active' : 'b-warn' ?>">
                <?= !empty($s['active']) ? 'Active' : 'Inactive' ?></span></td>
              <td style="white-space:nowrap;text-align:right">
                <a class="btn-os ghost sm" href="<?= e(url('advisor/skills.php?edit='.(int)$s['id'])) ?>">Edit</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn-os ghost sm"><?= !empty($s['active']) ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('Remove this skill?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn-os ghost sm" style="color:var(--danger)">Delete</button>
                </form>
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
