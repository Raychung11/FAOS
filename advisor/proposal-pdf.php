<?php
/** AdvisorOS — Proposal PDF.
 *  Uses DomPDF when installed (composer require dompdf/dompdf); otherwise
 *  falls back to the printable view (browser “Save as PDF”). */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proposal_render.php';
require_permission('proposals.manage');

$tid = require_tenant();
$id  = (int) ($_GET['id'] ?? 0);

$st = db()->prepare('SELECT * FROM proposals WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$st->execute([$id, $tid]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('Proposal not found.'); }
if (has_role('financial_advisor') && (int) $p['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This proposal belongs to another advisor.');
}

$html = render_proposal_html($p, tenant_brand());

if (!dompdf_available()) {
    // No DomPDF on this host — serve the document and open the browser's
    // print dialog so the user can choose "Save as PDF" (identical output,
    // zero dependency). To enable a one-click server PDF instead, run:
    //   composer require dompdf/dompdf
    audit_log('export', 'proposals', (int) $p['id'], 'Proposal opened for PDF (print)');

    $banner = '<div class="noprint" style="background:#C9A227;color:#2b2300;'
        . 'font:600 13px/1.5 sans-serif;padding:12px 18px;text-align:center">'
        . 'Your print dialog will open — choose <strong>“Save as PDF”</strong> '
        . 'as the destination. '
        . '<a href="' . e(url('advisor/proposal-view.php?id=' . (int) $p['id'])) . '" '
        . 'style="color:#2b2300;text-decoration:underline">Back to proposal</a></div>';
    $script = "<script>window.addEventListener('load',function(){"
        . "setTimeout(function(){window.print();},400);});</script>";

    $page = str_replace('<body>', '<body>' . $banner, $html);
    $page = str_replace('</body>', $script . '</body>', $page);

    header('Content-Type: text/html; charset=utf-8');
    echo $page;
    exit;
}

$dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'Helvetica']);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdf = $dompdf->output();

// Persist a copy and record its path.
$fname = 'proposal_' . $tid . '_' . (int) $p['id'] . '_' . random_token(6) . '.pdf';
$abs   = UPLOAD_DIR . '/proposals/' . $fname;
if (@file_put_contents($abs, $pdf) !== false) {
    db()->prepare('UPDATE proposals SET pdf_path=? WHERE id=? AND tenant_id=?')
        ->execute([$fname, (int) $p['id'], $tid]);
}
audit_log('export', 'proposals', (int) $p['id'], 'Proposal exported to PDF');

$safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $p['title']);
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $safe . '.pdf"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
