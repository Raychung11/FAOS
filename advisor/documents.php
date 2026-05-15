<?php
/** AdvisorOS — Document Vault: secure upload, tag, version, audit. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('documents.manage');

$tid = require_tenant();
$pdo = db();
$me  = current_user();
$preClient = (int) ($_GET['client_id'] ?? 0);

$categories = ['ic_passport','payslip','existing_policy','bank_statement','proposal','signed_form','consent_form','other'];
$allowedExt = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx'];
$allowedMime = [
    'application/pdf','image/jpeg','image/png','application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

if (is_post()) {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        $did = (int) ($_POST['id'] ?? 0);
        $s = $pdo->prepare('SELECT stored_name FROM documents WHERE id=? AND tenant_id=?');
        $s->execute([$did, $tid]);
        if ($row = $s->fetch()) {
            $pdo->prepare('UPDATE documents SET deleted_at=NOW() WHERE id=? AND tenant_id=?')
                ->execute([$did, $tid]);
            audit_log('delete','documents',$did,'Document removed');
            set_flash('success','Document removed.');
        }
        redirect('advisor/documents.php' . ($preClient ? "?client_id=$preClient" : ''));
    }

    // Upload
    $clientId = (int) input('client_id',0) ?: null;
    $category = in_array(input('category'),$categories,true) ? input('category') : 'other';
    $file = $_FILES['document'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        set_flash('danger','Please choose a file to upload.');
        redirect('advisor/documents.php' . ($preClient ? "?client_id=$preClient" : ''));
    }
    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        set_flash('danger','File exceeds the ' . MAX_UPLOAD_MB . ' MB limit.');
        redirect('advisor/documents.php' . ($preClient ? "?client_id=$preClient" : ''));
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
        set_flash('danger','Unsupported file type.');
        redirect('advisor/documents.php' . ($preClient ? "?client_id=$preClient" : ''));
    }
    if ($clientId) {
        $chk = $pdo->prepare('SELECT id FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
        $chk->execute([$clientId, $tid]);
        if (!$chk->fetchColumn()) { $clientId = null; }
    }

    $stored = 't' . $tid . '_' . random_token(16) . '.' . $ext;
    $dest   = UPLOAD_DIR . '/documents/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        set_flash('danger','Upload failed. Please try again.');
        redirect('advisor/documents.php' . ($preClient ? "?client_id=$preClient" : ''));
    }

    // Version = count of prior docs in same category for this client + 1
    $vq = $pdo->prepare(
        'SELECT COALESCE(MAX(version),0)+1 FROM documents
         WHERE tenant_id=? AND category=? AND (client_id <=> ?)'
    );
    $vq->execute([$tid, $category, $clientId]);
    $version = (int) $vq->fetchColumn();

    $ins = $pdo->prepare(
        'INSERT INTO documents
           (tenant_id,client_id,category,original_name,stored_name,mime_type,
            file_size,version,uploaded_by,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $ins->execute([$tid,$clientId,$category,
        substr($file['name'],0,255),$stored,$mime,(int)$file['size'],
        $version,$me['id'],$me['id']]);
    audit_log('upload','documents',(int)$pdo->lastInsertId(),"Uploaded {$file['name']}");
    set_flash('success','Document uploaded securely.');
    redirect('advisor/documents.php' . ($preClient ? "?client_id=$preClient" : ''));
}

$clients = tenant_clients_list();
$sql  = "SELECT d.*, c.full_name, u.name AS uploader FROM documents d
         LEFT JOIN clients c ON c.id=d.client_id
         LEFT JOIN users u ON u.id=d.uploaded_by
         WHERE d.tenant_id=? AND d.deleted_at IS NULL";
$args = [$tid];
if ($preClient) { $sql .= ' AND d.client_id=?'; $args[] = $preClient; }
$sql .= ' ORDER BY d.created_at DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($args);
$docs = $st->fetchAll();

$pageTitle = 'Document Vault';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Upload Document</div>
  <div class="card-os-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-grid">
        <div class="form-row"><label>Client (optional)</label>
          <select name="client_id"><option value="">— tenant-level —</option>
          <?php foreach ($clients as $cl): ?>
            <option value="<?= (int)$cl['id'] ?>" <?= $preClient===(int)$cl['id']?'selected':'' ?>><?= e($cl['full_name']) ?></option>
          <?php endforeach; ?></select></div>
        <div class="form-row"><label>Category</label>
          <select name="category"><?php foreach ($categories as $cat): ?>
            <option value="<?= $cat ?>"><?= label($cat) ?></option>
          <?php endforeach; ?></select></div>
      </div>
      <div class="form-row"><label>File (max <?= MAX_UPLOAD_MB ?> MB · PDF, image, Office)</label>
        <input type="file" name="document" required></div>
      <button class="btn-os">Upload securely</button>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">Documents</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>File</th><th>Client</th><th>Category</th><th>Ver</th>
        <th>Size</th><th>Uploaded by</th><th></th></tr></thead>
      <tbody>
      <?php if (!$docs): ?>
        <tr><td colspan="7" class="muted" style="padding:24px">No documents.</td></tr>
      <?php else: foreach ($docs as $d): ?>
        <tr>
          <td><?= e($d['original_name']) ?></td>
          <td><?= e($d['full_name'] ?: '—') ?></td>
          <td><?= label($d['category']) ?></td>
          <td>v<?= (int)$d['version'] ?></td>
          <td><?= number_format($d['file_size']/1024,1) ?> KB</td>
          <td><?= e($d['uploader'] ?: '—') ?><br><span class="muted" style="font-size:12px"><?= fmt_date($d['created_at']) ?></span></td>
          <td class="text-right" style="white-space:nowrap">
            <a class="btn-os ghost sm" href="<?= e(url('advisor/document-download.php?id='.(int)$d['id'])) ?>">Download</a>
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-os ghost sm" data-confirm="Remove document?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
