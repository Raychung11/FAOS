<?php
/** AdvisorOS — Business Valuation Report PDF.
 *  Uses DomPDF when installed; otherwise falls back to the printable view
 *  (browser "Save as PDF"). Mirrors advisor/tax-pdf.php. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proposal_render.php';        // dompdf_available()
require_once __DIR__ . '/../includes/valuation_report_render.php'; // render_valuation_report_html()
require_permission('financial.manage');

$tid = require_tenant();
$pdo = db();
$clientId = (int) ($_GET['client_id'] ?? 0);

$cs = $pdo->prepare('SELECT * FROM clients WHERE id=? AND tenant_id=? AND deleted_at IS NULL');
$cs->execute([$clientId, $tid]);
$client = $cs->fetch();
if (!$client) { http_response_code(404); exit('Client not found.'); }
if (has_role('financial_advisor') && (int) $client['advisor_id'] !== (int) current_user()['id']) {
    http_response_code(403); exit('This client is assigned to another advisor.');
}

$v = client_valuation($pdo, $tid, $clientId);
if (!$v['rows']) {
    http_response_code(409);
    exit('No companies recorded yet — add at least one under Business profile before exporting a valuation report.');
}

$brand = tenant_brand();
$html  = render_valuation_report_html($client, $v, $brand);

$slug  = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $client['full_name']);
$fname = 'Valuation-Report-' . $slug;

if (!dompdf_available()) {
    audit_log('export', 'valuation', $clientId, 'Valuation report opened for PDF (browser print)');

    $banner = '<div class="noprint" style="background:#C9A227;color:#2b2300;'
        . 'font:600 13px/1.5 sans-serif;padding:12px 18px;text-align:center">'
        . 'Your print dialog will open — choose <strong>"Save as PDF"</strong> '
        . 'as the destination. '
        . '<a href="' . e(url('advisor/valuation.php?client_id=' . $clientId)) . '" '
        . 'style="color:#2b2300;text-decoration:underline">Back to Valuation</a></div>';
    $script = "<script>window.addEventListener('load',function(){"
        . "setTimeout(function(){window.print();},400);});</script>";
    $style  = '<style>@media print { .noprint { display:none !important } }</style>';

    $page = str_replace('</head>', $style . '</head>', $html);
    $page = str_replace('<body>', '<body>' . $banner, $page);
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

$dir = UPLOAD_DIR . '/valuation-reports';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
$abs = $dir . '/' . $fname . '_' . random_token(6) . '.pdf';
@file_put_contents($abs, $pdf);

audit_log('export', 'valuation', $clientId, 'Valuation report exported to PDF');

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $fname . '.pdf"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
