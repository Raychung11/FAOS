<?php
/** AdvisorOS — Controlled document download.
 *  Enforces authentication, tenant isolation and audit logging.
 *  Files are never served directly from the web root. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('documents.manage');

$tid = require_tenant();
$id  = (int) ($_GET['id'] ?? 0);

$st = db()->prepare(
    'SELECT * FROM documents WHERE id=? AND tenant_id=? AND deleted_at IS NULL'
);
$st->execute([$id, $tid]);
$doc = $st->fetch();
if (!$doc) { http_response_code(404); exit('Document not found.'); }

// Defence in depth: keep the resolved path inside the documents dir.
$base = realpath(UPLOAD_DIR . '/documents');
$path = realpath(UPLOAD_DIR . '/documents/' . $doc['stored_name']);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('File unavailable.');
}

audit_log('download', 'documents', $id, 'Downloaded ' . $doc['original_name']);

header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . basename($doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
