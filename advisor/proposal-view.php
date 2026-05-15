<?php
/** AdvisorOS — Render a proposal as a clean, printable document. */
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

$controls = '<div class="noprint" style="position:sticky;top:0;display:flex;'
    . 'gap:8px;justify-content:flex-end;padding:12px 16px;background:#0B1F3A">'
    . '<a href="' . e(url('advisor/proposals.php')) . '" '
    . 'style="background:#fff;color:#0B1F3A;padding:8px 14px;border-radius:8px;'
    . 'font:600 13px sans-serif;text-decoration:none">All proposals</a>'
    . '<a href="' . e(url('advisor/proposal-edit.php?id=' . (int) $p['id'])) . '" '
    . 'style="background:#fff;color:#0B1F3A;padding:8px 14px;border-radius:8px;'
    . 'font:600 13px sans-serif;text-decoration:none">Edit</a>'
    . '<a href="' . e(url('advisor/proposal-pdf.php?id=' . (int) $p['id'])) . '" '
    . 'style="background:#C9A227;color:#2b2300;padding:8px 14px;border-radius:8px;'
    . 'font:600 13px sans-serif;text-decoration:none">Download PDF</a>'
    . '<button onclick="window.print()" '
    . 'style="background:#C9A227;color:#2b2300;padding:8px 14px;border:0;'
    . 'border-radius:8px;font:600 13px sans-serif;cursor:pointer">Print / Save as PDF</button>'
    . '</div>';

echo str_replace('<body>', '<body>' . $controls, $html);
