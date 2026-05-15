<?php
/** AdvisorOS — Client portal: download own document only. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_client.php';

$c   = portal_client();
$tid = (int) $c['tenant_id'];
$id  = (int) ($_GET['id'] ?? 0);

$st = db()->prepare(
    'SELECT * FROM documents WHERE id=? AND tenant_id=? AND client_id=? AND deleted_at IS NULL'
);
$st->execute([$id, $tid, (int) $c['id']]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

$base = realpath(UPLOAD_DIR . '/documents');
$path = realpath(UPLOAD_DIR . '/documents/' . $doc['stored_name']);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('File unavailable.');
}

audit_log('download','documents',$id,'Client downloaded ' . $doc['original_name']);
header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . basename($doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
