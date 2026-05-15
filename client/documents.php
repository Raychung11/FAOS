<?php
/** AdvisorOS — Client portal: documents (view + upload). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';

$c   = portal_client();
$pdo = db();
$tid = (int) $c['tenant_id'];

$allowedExt  = ['pdf','jpg','jpeg','png','doc','docx'];
$allowedMime = ['application/pdf','image/jpeg','image/png','application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

if (is_post()) {
    csrf_check();
    $file = $_FILES['document'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        set_flash('danger','Please choose a file.');
        redirect('client/documents.php');
    }
    if ($file['size'] > MAX_UPLOAD_MB*1024*1024) {
        set_flash('danger','File exceeds the ' . MAX_UPLOAD_MB . ' MB limit.');
        redirect('client/documents.php');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($ext,$allowedExt,true) || !in_array($mime,$allowedMime,true)) {
        set_flash('danger','Unsupported file type.');
        redirect('client/documents.php');
    }
    $stored = 't' . $tid . '_' . random_token(16) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR.'/documents/'.$stored)) {
        set_flash('danger','Upload failed.');
        redirect('client/documents.php');
    }
    $pdo->prepare(
        'INSERT INTO documents (tenant_id,client_id,category,original_name,stored_name,
         mime_type,file_size,version,uploaded_by,created_by)
         VALUES (?,?,?,?,?,?,?,1,?,?)'
    )->execute([$tid,$c['id'],'other',substr($file['name'],0,255),$stored,$mime,
        (int)$file['size'],current_user()['id'],current_user()['id']]);
    audit_log('upload','documents',(int)$pdo->lastInsertId(),'Client uploaded a document');
    set_flash('success','Document uploaded.');
    redirect('client/documents.php');
}

$docs = $pdo->prepare('SELECT * FROM documents WHERE client_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY created_at DESC');
$docs->execute([$c['id'],$tid]);
$rows = $docs->fetchAll();

$pageTitle = 'My Documents';
require __DIR__ . '/../includes/header.php';
?>
<div class="card-os" style="margin-bottom:18px">
  <div class="card-os-head">Upload a Document</div>
  <div class="card-os-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-row"><label>File (max <?= MAX_UPLOAD_MB ?> MB · PDF, image, Word)</label>
        <input type="file" name="document" required></div>
      <button class="btn-os">Upload</button>
    </form>
  </div>
</div>

<div class="card-os">
  <div class="card-os-head">My Documents</div>
  <div class="card-os-body" style="padding:0">
    <table class="table-os">
      <thead><tr><th>File</th><th>Category</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted" style="padding:24px">No documents.</td></tr>
      <?php else: foreach ($rows as $d): ?>
        <tr>
          <td><?= e($d['original_name']) ?></td>
          <td><?= label($d['category']) ?></td>
          <td><?= fmt_date($d['created_at']) ?></td>
          <td class="text-right"><a class="btn-os ghost sm" href="<?= e(url('client/document-download.php?id='.(int)$d['id'])) ?>">Download</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
