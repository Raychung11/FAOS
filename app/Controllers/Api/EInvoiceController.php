<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Qr;
use App\Core\Request;
use App\Core\Response;
use App\Services\EInvoiceService;
use App\Services\MyInvoisClient;

final class EInvoiceController extends Controller
{
    public function list(Request $req): void
    {
        $sql = 'SELECT e.*, o.name AS outlet_name
                FROM einvoices e LEFT JOIN outlets o ON o.id=e.outlet_id
                WHERE e.company_id=?';
        $args = [$this->companyId()];
        foreach (['status', 'doc_type'] as $f) {
            if ($req->query($f)) {
                $sql .= " AND e.$f = ?";
                $args[] = $req->query($f);
            }
        }
        $sql .= ' ORDER BY e.id DESC LIMIT 200';
        Response::ok([
            'rows'       => Database::all($sql, $args),
            'provider_on' => MyInvoisClient::enabled(),
        ]);
    }

    public function show(Request $req, array $p): void
    {
        $e = Database::first(
            'SELECT * FROM einvoices WHERE id=? AND company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$e) {
            Response::fail('Not found', 404);
        }
        $e['lines'] = Database::all('SELECT * FROM einvoice_lines WHERE einvoice_id=?', [$p['id']]);
        Response::ok($e);
    }

    public function generateForTransaction(Request $req, array $p): void
    {
        try {
            $e = EInvoiceService::generateForTransaction(
                $this->companyId(),
                (int) $p['id'],
                [
                    'name'    => $req->input('buyer_name'),
                    'tin'     => $req->input('buyer_tin'),
                    'reg_no'  => $req->input('buyer_reg_no'),
                ],
                Auth::id()
            );
        } catch (\RuntimeException $ex) {
            Response::fail($ex->getMessage(), 422);
        }
        Audit::log('einvoice_generate', 'einvoices', (string) $e['id'], null, ['type' => 'standard']);
        Response::ok($e, 'e-Invoice generated (' . $e['status'] . ')');
    }

    public function generateConsolidated(Request $req): void
    {
        $d = $this->validate($req, [
            'outlet_id'    => 'required|int',
            'period_start' => 'required|date',
            'period_end'   => 'required|date',
        ]);
        try {
            $e = EInvoiceService::generateConsolidated(
                $this->companyId(),
                (int) $d['outlet_id'],
                $d['period_start'],
                $d['period_end'],
                Auth::id()
            );
        } catch (\RuntimeException $ex) {
            Response::fail($ex->getMessage(), 422);
        }
        Audit::log('einvoice_consolidated', 'einvoices', (string) $e['id'], null, $d);
        Response::ok($e, 'Consolidated e-Invoice generated (' . $e['status'] . ')');
    }

    public function submit(Request $req, array $p): void
    {
        $e = Database::first(
            'SELECT id FROM einvoices WHERE id=? AND company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$e) {
            Response::fail('Not found', 404);
        }
        $res = EInvoiceService::submitAndFinalize((int) $p['id']);
        Audit::log('einvoice_submit', 'einvoices', (string) $p['id'], null, ['status' => $res['status']]);
        Response::ok($res, 'Submission attempted (' . $res['status'] . ')');
    }

    public function qr(Request $req, array $p): never
    {
        $e = Database::first(
            'SELECT einvoice_no, validation_url FROM einvoices WHERE id=? AND company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$e) {
            Response::fail('Not found', 404);
        }
        header('Content-Type: image/svg+xml');
        echo Qr::svg($e['validation_url'] ?: $e['einvoice_no'], 5);
        exit;
    }

    /** Printable LHDN tax invoice (browser -> PDF) with validation QR. */
    public function printDoc(Request $req, array $p): never
    {
        $e = Database::first(
            'SELECT * FROM einvoices WHERE id=? AND company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$e) {
            Response::fail('Not found', 404);
        }
        $co = Database::first('SELECT * FROM companies WHERE id=?', [$this->companyId()]);
        $lines = Database::all('SELECT * FROM einvoice_lines WHERE einvoice_id=?', [$p['id']]);
        $sym = \App\Core\Config::get('CURRENCY_SYMBOL', 'RM');

        $rows = '';
        foreach ($lines as $l) {
            $rows .= '<tr><td>' . e($l['description']) . '</td>'
                . '<td class="r">' . e($l['classification']) . '</td>'
                . '<td class="r">' . qty($l['qty']) . '</td>'
                . '<td class="r">' . $sym . ' ' . number_format((float) $l['line_amount'], 2) . '</td>'
                . '<td class="r">' . $sym . ' ' . number_format((float) $l['tax_amount'], 2) . '</td></tr>';
        }
        $qr = Qr::svg($e['validation_url'] ?: $e['einvoice_no'], 4);
        $statusBadge = strtoupper($e['status']);
        $valid = $e['validation_url']
            ? '<p>Validated by IRBM · UUID ' . e($e['irbm_uuid']) . '<br><a href="' . e($e['validation_url']) . '">' . e($e['validation_url']) . '</a></p>'
            : '<p style="color:#b45309">Status: ' . e($statusBadge) . ' — not yet validated by IRBM (configure MyInvois to submit).</p>';

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . e($e['einvoice_no']) . '</title>'
            . '<style>body{font-family:Arial,sans-serif;margin:30px;color:#1f2d3d;font-size:13px}'
            . 'h1{font-size:18px;margin:0}.muted{color:#666}.r{text-align:right}'
            . 'table{width:100%;border-collapse:collapse;margin-top:14px}'
            . 'th,td{padding:7px 9px;border-bottom:1px solid #ddd;text-align:left}'
            . 'th{background:#f4f6f9}.tot td{font-weight:700;border-top:2px solid #333}'
            . '.hd{display:flex;justify-content:space-between;align-items:flex-start}'
            . '.qr{width:120px}.np{margin-bottom:12px}@media print{.np{display:none}}</style></head><body>'
            . '<div class="np"><button onclick="print()">Print / Save PDF</button></div>'
            . '<div class="hd"><div>'
            . '<h1>' . e($co['name']) . '</h1>'
            . '<div class="muted">TIN ' . e($co['tin']) . ' · SST ' . e($co['sst_no'] ?: 'NA')
            . ' · MSIC ' . e($co['msic_code']) . '</div>'
            . '<h2>' . ($e['doc_type'] === 'consolidated' ? 'Consolidated e-Invoice' : 'e-Invoice (Tax Invoice)') . '</h2>'
            . '<div>No: <b>' . e($e['einvoice_no']) . '</b> · Date: ' . e(substr((string) $e['created_at'], 0, 10)) . '</div>'
            . ($e['period_start'] ? '<div>Period: ' . e($e['period_start']) . ' to ' . e($e['period_end']) . '</div>' : '')
            . '<div>Buyer: ' . e($e['buyer_name']) . ($e['buyer_tin'] ? ' (TIN ' . e($e['buyer_tin']) . ')' : '') . '</div>'
            . '</div><div class="qr">' . $qr . '</div></div>'
            . '<table><thead><tr><th>Description</th><th class="r">Class</th><th class="r">Qty</th>'
            . '<th class="r">Amount</th><th class="r">SST</th></tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot>'
            . '<tr><td colspan="3"></td><td class="r">Subtotal</td><td class="r">' . $sym . ' ' . number_format((float) $e['subtotal'], 2) . '</td></tr>'
            . '<tr><td colspan="3"></td><td class="r">SST</td><td class="r">' . $sym . ' ' . number_format((float) $e['tax_total'], 2) . '</td></tr>'
            . '<tr class="tot"><td colspan="3"></td><td class="r">Total</td><td class="r">' . $sym . ' ' . number_format((float) $e['total'], 2) . '</td></tr>'
            . '</tfoot></table>' . $valid
            . '<p class="muted">Generated by FAOS BOS · MyInvois UBL 2.1</p></body></html>';
        Response::html($html);
    }
}
