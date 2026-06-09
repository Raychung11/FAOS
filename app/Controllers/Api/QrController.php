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
use App\Services\QrService;

final class QrController extends Controller
{
    /** Generate a batch of QR stock labels. */
    public function create(Request $req): void
    {
        $d = $this->validate($req, [
            'product_id' => 'required|int',
            'count'      => 'required|int|min:1|max:500',
            'label_type' => 'in:carton,batch,item',
            'init_qty'   => 'numeric',
        ]);
        $labels = [];
        for ($i = 0, $n = (int) $d['count']; $i < $n; $i++) {
            $labels[] = QrService::createLabel([
                'company_id'    => $this->companyId(),
                'product_id'    => (int) $d['product_id'],
                'label_type'    => $d['label_type'] ?? 'carton',
                'init_qty'      => $d['init_qty'] ?? 0,
                'batch_id'      => $req->input('batch_id'),
                'location_type' => $req->input('location_type', 'warehouse'),
                'location_id'   => $req->input('location_id'),
                'created_by'    => Auth::id(),
            ]);
        }
        Audit::log('qr_generate', 'qr_labels', null, null, ['count' => count($labels)]);
        Response::ok(['labels' => $labels]);
    }

    /** Resolve a scanned QR reference into full traceable detail. */
    public function lookup(Request $req, array $p): void
    {
        $ref = $p['ref'];
        $label = QrService::find($ref);

        Database::run(
            'INSERT INTO scan_logs (company_id, user_id, qr_ref, qr_label_id, scan_context, result, ip_address, device_id)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $this->companyId(), Auth::id(), $ref, $label['id'] ?? null,
                $req->query('context', 'lookup'),
                $label ? 'ok' : 'invalid',
                $req->ip(), $req->deviceId(),
            ]
        );

        if (!$label) {
            Response::fail('QR reference not found: ' . $ref, 404);
        }
        $label['history'] = QrService::history((int) $label['id']);
        Response::ok($label);
    }

    /** QR image (?fmt=svg|png). */
    public function image(Request $req, array $p): never
    {
        $ref = $p['ref'];
        $scale = max(2, min(16, (int) $req->query('scale', 6)));
        if ($req->query('fmt', 'svg') === 'png') {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            echo Qr::png($ref, $scale);
            exit;
        }
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=86400');
        echo Qr::svg($ref, $scale);
        exit;
    }

    /** Printable A4 sheet of QR labels for a set of references. */
    public function printSheet(Request $req): never
    {
        $refs = array_filter(array_map('trim', explode(',', (string) $req->query('refs', ''))));
        if (!$refs) {
            Response::fail('No refs provided');
        }
        $cells = '';
        foreach ($refs as $ref) {
            $label = QrService::find($ref);
            $name = $label['product_name'] ?? '';
            $svg = Qr::svg($ref, 5);
            $cells .= '<div class="lbl">' . $svg
                . '<div class="ref">' . e($ref) . '</div>'
                . '<div class="nm">' . e($name) . '</div></div>';
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>QR Labels</title>'
            . '<style>@page{size:A4;margin:8mm}body{font-family:Arial,sans-serif;margin:0}'
            . '.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6mm}'
            . '.lbl{border:1px dashed #999;padding:4mm;text-align:center;break-inside:avoid}'
            . '.lbl svg{width:100%;height:auto;max-width:34mm}'
            . '.ref{font-size:10px;font-weight:bold;margin-top:2mm}'
            . '.nm{font-size:9px;color:#444}'
            . '@media print{.noprint{display:none}}</style></head><body>'
            . '<div class="noprint" style="padding:8px"><button onclick="print()">Print</button></div>'
            . '<div class="grid">' . $cells . '</div></body></html>';
        Response::html($html);
    }
}
