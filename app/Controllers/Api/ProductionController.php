<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ProductionService;

final class ProductionController extends Controller
{
    public function list(Request $req): void
    {
        $sql = 'SELECT po.*, p.sku, p.name AS product_name, w.name AS warehouse_name
                FROM production_orders po
                JOIN products p ON p.id = po.product_id
                JOIN warehouses w ON w.id = po.warehouse_id
                WHERE po.company_id = ?';
        $args = [$this->companyId()];
        if ($req->query('status')) {
            $sql .= ' AND po.status = ?';
            $args[] = $req->query('status');
        }
        $sql .= ' ORDER BY po.id DESC LIMIT 200';
        Response::ok(Database::all($sql, $args));
    }

    public function show(Request $req, array $p): void
    {
        $po = Database::first(
            'SELECT * FROM production_orders WHERE id=? AND company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$po) {
            Response::fail('Not found', 404);
        }
        $po['consumption'] = Database::all(
            'SELECT pc.*, pr.sku, pr.name AS ingredient_name
             FROM production_consumption pc JOIN products pr ON pr.id=pc.ingredient_id
             WHERE pc.production_id=?',
            [$p['id']]
        );
        Response::ok($po);
    }

    public function create(Request $req): void
    {
        $d = $this->validate($req, [
            'product_id'   => 'required|int',
            'warehouse_id' => 'required|int',
            'planned_qty'  => 'required|numeric|min:0.001',
        ]);
        try {
            $res = ProductionService::createOrder([
                'company_id'   => $this->companyId(),
                'product_id'   => (int) $d['product_id'],
                'warehouse_id' => (int) $d['warehouse_id'],
                'planned_qty'  => (float) $d['planned_qty'],
                'planned_date' => $req->input('planned_date'),
                'user_id'      => Auth::id(),
            ]);
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('production_create', 'production_orders', (string) $res['id'], null, $res);
        Response::ok($res, 'Production order created');
    }

    public function start(Request $req, array $p): void
    {
        ProductionService::start((int) $p['id']);
        Audit::log('production_start', 'production_orders', $p['id']);
        Response::ok(null, 'Production started');
    }

    public function complete(Request $req, array $p): void
    {
        $d = $this->validate($req, ['produced_qty' => 'required|numeric|min:0.001']);
        try {
            $res = ProductionService::complete((int) $p['id'], (float) $d['produced_qty'], (int) Auth::id());
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('production_complete', 'production_orders', $p['id'], null, $res);
        Response::ok($res, 'Production completed; output stocked');
    }
}
