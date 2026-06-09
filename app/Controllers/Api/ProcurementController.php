<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ProcurementService;

final class ProcurementController extends Controller
{
    public function listPO(Request $req): void
    {
        $sql = 'SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name
                FROM purchase_orders po
                JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN warehouses w ON w.id = po.warehouse_id
                WHERE po.company_id = ?';
        $args = [$this->companyId()];
        if ($req->query('status')) {
            $sql .= ' AND po.status = ?';
            $args[] = $req->query('status');
        }
        $sql .= ' ORDER BY po.id DESC LIMIT 200';
        Response::ok(Database::all($sql, $args));
    }

    public function showPO(Request $req, array $p): void
    {
        $po = Database::first(
            'SELECT po.*, s.name AS supplier_name FROM purchase_orders po
             JOIN suppliers s ON s.id=po.supplier_id
             WHERE po.id=? AND po.company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$po) {
            Response::fail('Not found', 404);
        }
        $po['items'] = Database::all(
            'SELECT poi.*, pr.sku, pr.name AS product_name
             FROM purchase_order_items poi JOIN products pr ON pr.id=poi.product_id
             WHERE poi.po_id=?',
            [$p['id']]
        );
        Response::ok($po);
    }

    public function createPO(Request $req): void
    {
        $items = $req->input('items');
        if (!is_array($items) || !$items) {
            Response::fail('items[] required', 422);
        }
        $d = $this->validate($req, ['supplier_id' => 'required|int']);
        $res = ProcurementService::createPO([
            'company_id'    => $this->companyId(),
            'supplier_id'   => (int) $d['supplier_id'],
            'warehouse_id'  => $req->input('warehouse_id'),
            'expected_date' => $req->input('expected_date'),
            'items'         => $items,
            'user_id'       => Auth::id(),
        ]);
        Audit::log('po_create', 'purchase_orders', (string) $res['id'], null, $res);
        Response::ok($res, 'PO created');
    }

    public function approvePO(Request $req, array $p): void
    {
        ProcurementService::approvePO((int) $p['id'], (int) Auth::id());
        Audit::log('po_approve', 'purchase_orders', $p['id']);
        Response::ok(null, 'PO approved');
    }

    public function receiveGRN(Request $req): void
    {
        $items = $req->input('items');
        if (!is_array($items) || !$items) {
            Response::fail('items[] required', 422);
        }
        $d = $this->validate($req, [
            'supplier_id'  => 'required|int',
            'warehouse_id' => 'required|int',
        ]);
        $res = ProcurementService::receiveGRN([
            'company_id'   => $this->companyId(),
            'po_id'        => $req->input('po_id'),
            'supplier_id'  => (int) $d['supplier_id'],
            'warehouse_id' => (int) $d['warehouse_id'],
            'invoice_no'   => $req->input('invoice_no'),
            'items'        => $items,
            'user_id'      => Auth::id(),
        ]);
        Audit::log('grn_receive', 'grn', (string) $res['grn_id'], null, $res);
        Response::ok($res, 'Goods received; stock + AP invoice raised');
    }

    public function listGRN(Request $req): void
    {
        Response::ok(Database::all(
            'SELECT g.*, s.name AS supplier_name, w.name AS warehouse_name
             FROM grn g JOIN suppliers s ON s.id=g.supplier_id
             JOIN warehouses w ON w.id=g.warehouse_id
             WHERE g.company_id=? ORDER BY g.id DESC LIMIT 200',
            [$this->companyId()]
        ));
    }
}
