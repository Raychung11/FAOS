<?php
/** AdvisorOS — Tax Computation & Planning Report PDF.
 *  Uses DomPDF when installed (composer require dompdf/dompdf); otherwise
 *  falls back to the printable view (browser “Save as PDF”). Mirrors
 *  advisor/proposal-pdf.php. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/proposal_render.php';   // dompdf_available()
require_once __DIR__ . '/../includes/tax_report_render.php'; // render_tax_report_html()
require_once __DIR__ . '/../includes/solutions.php';
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
if (!taxcomp_exists($clientId)) {
    http_response_code(409);
    exit('No itemised tax computation yet — open Full computation and capture the figures first.');
}

$r     = tax_compute(taxcomp_get($clientId));
$eng   = solution_get($clientId, 'tax');
$brand = tenant_brand();
$html  = render_tax_report_html($client, $r, $eng, $brand);

$slug  = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $client['full_name']);
$fname = 'Tax-Report-' . $slug . '-YA' . (int) $r['ya'];

if (!dompdf_available()) {
    audit_log('export', 'tax', $clientId, 'Tax report opened for PDF (browser print)');

    $banner = '<div class="noprint" style="background:#C9A227;color:#2b2300;'
        . 'font:600 13px/1.5 sans-serif;padding:12px 18px;text-align:center">'
        . 'Your print dialog will open — choose <strong>“Save as PDF”</strong> '
        . 'as the destination. '
        . '<a href="' . e(url('advisor/solution-tax.php?client_id=' . $clientId)) . '" '
        . 'style="color:#2b2300;text-decoration:underline">Back to Tax solution</a></div>';
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

// Persist a copy under uploads so it can be re-served if needed.
$dir = UPLOAD_DIR . '/tax-reports';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
$abs = $dir . '/' . $fname . '_' . random_token(6) . '.pdf';
@file_put_contents($abs, $pdf);

audit_log('export', 'tax', $clientId, 'Tax report exported to PDF');

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $fname . '.pdf"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
exit;
