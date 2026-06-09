<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReplenishmentService;

final class ReplenishmentController extends Controller
{
    public function list(Request $req): void
    {
        $u = Auth::user();
        $sql = 'SELECT pr.*, o.name AS outlet_name, u.full_name AS requested_by_name
                FROM purchase_requests pr
                JOIN outlets o ON o.id = pr.outlet_id
                LEFT JOIN users u ON u.id = pr.requested_by
                WHERE pr.company_id = ?';
        $args = [$this->companyId()];
        // Outlet/restaurant managers only see their own outlet's requests.
        if ($u['outlet_id'] && !Auth::can('procurement.manage')) {
            $sql .= ' AND pr.outlet_id = ?';
            $args[] = $u['outlet_id'];
        }
        if ($req->query('status')) {
            $sql .= ' AND pr.status = ?';
            $args[] = $req->query('status');
        }
        $sql .= ' ORDER BY pr.id DESC LIMIT 200';
        Response::ok(Database::all($sql, $args));
    }

    public function show(Request $req, array $p): void
    {
        $pr = Database::first(
            'SELECT pr.*, o.name AS outlet_name FROM purchase_requests pr
             JOIN outlets o ON o.id=pr.outlet_id
             WHERE pr.id=? AND pr.company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$pr) {
            Response::fail('Not found', 404);
        }
        $pr['items'] = Database::all(
            'SELECT pri.*, pd.sku, pd.name AS product_name
             FROM purchase_request_items pri JOIN products pd ON pd.id=pri.product_id
             WHERE pri.pr_id=?',
            [$p['id']]
        );
        Response::ok($pr);
    }

    public function create(Request $req): void
    {
        $u = Auth::user();
        $items = $req->input('items');
        if (!is_array($items) || !$items) {
            Response::fail('items[] required', 422);
        }
        // Managers raise PRs for their own outlet; HQ may target any outlet.
        $outletId = $u['outlet_id'] ?: $req->input('outlet_id');
        if (!$outletId) {
            Response::fail('outlet_id required', 422);
        }
        $res = ReplenishmentService::createPR([
            'company_id' => $this->companyId(),
            'outlet_id'  => (int) $outletId,
            'items'      => $items,
            'user_id'    => Auth::id(),
        ]);
        Audit::log('pr_create', 'purchase_requests', (string) $res['id'], null, $res);
        Response::ok($res, 'Purchase request raised');
    }

    public function approve(Request $req, array $p): void
    {
        ReplenishmentService::setStatus((int) $p['id'], 'approved');
        Audit::log('pr_approve', 'purchase_requests', $p['id']);
        Response::ok(null, 'PR approved');
    }

    public function reject(Request $req, array $p): void
    {
        ReplenishmentService::setStatus((int) $p['id'], 'rejected');
        Audit::log('pr_reject', 'purchase_requests', $p['id']);
        Response::ok(null, 'PR rejected');
    }

    public function fulfil(Request $req, array $p): void
    {
        $d = $this->validate($req, ['source_warehouse_id' => 'required|int']);
        try {
            $res = ReplenishmentService::fulfil(
                (int) $p['id'],
                (int) $d['source_warehouse_id'],
                (int) Auth::id()
            );
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('pr_fulfil', 'purchase_requests', $p['id'], null, $res);
        Response::ok($res, 'PR fulfilled; stock distributed to outlet');
    }
}
