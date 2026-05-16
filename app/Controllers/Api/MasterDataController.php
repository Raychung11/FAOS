<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Model;

/**
 * Generic, config-driven master-data REST resource.
 * GET    /api/md/{resource}
 * GET    /api/md/{resource}/{id}
 * POST   /api/md/{resource}
 * PUT    /api/md/{resource}/{id}
 * DELETE /api/md/{resource}/{id}
 */
final class MasterDataController extends Controller
{
    /** resource => [table, fillable[], searchCols[], companyScoped] */
    private const MAP = [
        'companies'    => ['companies', ['code','name','reg_no','currency','timezone','is_active'], ['code','name'], false],
        'warehouses'   => ['warehouses', ['company_id','code','name','type','address','is_active'], ['code','name'], true],
        'outlets'      => ['outlets', ['company_id','code','name','hypermarket','region','address','third_party_pos','report_lag_days','is_active'], ['code','name','hypermarket'], true],
        'kiosks'       => ['kiosks', ['outlet_id','code','name','location_note','is_active'], ['code','name'], false],
        'suppliers'    => ['suppliers', ['company_id','code','name','contact_person','phone','email','payment_terms','is_active'], ['code','name'], true],
        'stock_groups' => ['stock_groups', ['company_id','code','name'], ['code','name'], true],
        'account_groups' => ['account_groups', ['company_id','code','name'], ['code','name'], true],
        'product_categories' => ['product_categories', ['company_id','parent_id','code','name'], ['code','name'], true],
        'products'     => ['products', ['company_id','category_id','stock_group_id','account_group_id','tax_code_id','sku','barcode','name','uom','type','cost_price','sell_price','reorder_level','shelf_life_days','is_sellable','is_active'], ['sku','barcode','name'], true],
        'tax_codes'    => ['tax_codes', ['company_id','code','name','tax_type','rate','is_active'], ['code','name'], true],
        'recipes'      => ['recipes', ['product_id','yield_qty','yield_uom','notes','is_active'], [], false],
        'recipe_items' => ['recipe_items', ['recipe_id','ingredient_id','qty','uom'], [], false],
    ];

    private function model(string $resource): Model
    {
        if (!isset(self::MAP[$resource])) {
            Response::fail('Unknown resource', 404);
        }
        [$table, $fillable, , $scoped] = self::MAP[$resource];
        $m = new Model();
        (function () use ($table, $fillable, $scoped) {
            $this->table = $table;
            $this->fillable = $fillable;
            $this->companyScoped = $scoped;
        })->call($m);
        return $m;
    }

    public function index(Request $req, array $p): void
    {
        $resource = $p['resource'];
        $m = $this->model($resource);
        [, , $searchCols] = self::MAP[$resource];
        $rows = $m->all(
            $this->companyId(),
            min(1000, (int) $req->query('limit', 200)),
            (int) $req->query('offset', 0),
            $req->query('q'),
            $searchCols
        );
        Response::ok($rows);
    }

    public function show(Request $req, array $p): void
    {
        $m = $this->model($p['resource']);
        $row = $m->find((int) $p['id'], $this->companyId());
        $row ? Response::ok($row) : Response::fail('Not found', 404);
    }

    public function store(Request $req, array $p): void
    {
        $resource = $p['resource'];
        $m = $this->model($resource);
        $data = $req->all();
        // Any company-scoped resource (per the MAP) gets company_id injected
        // server-side — never trust a client-supplied tenant id.
        if ((self::MAP[$resource][3] ?? false) === true) {
            $data['company_id'] = $this->companyId();
        }
        $id = $m->create($data);
        Audit::log('create', $resource, (string) $id, null, $data);
        Response::ok(['id' => $id], 'Created');
    }

    public function update(Request $req, array $p): void
    {
        $resource = $p['resource'];
        $m = $this->model($resource);
        $before = $m->find((int) $p['id'], $this->companyId());
        if (!$before) {
            Response::fail('Not found', 404);
        }
        // Never let an update reassign the owning tenant.
        $data = $req->all();
        unset($data['company_id'], $data['id']);
        $m->update((int) $p['id'], $data);
        Audit::log('update', $resource, $p['id'], $before, $data);
        Response::ok(null, 'Updated');
    }

    public function destroy(Request $req, array $p): void
    {
        $resource = $p['resource'];
        $m = $this->model($resource);
        $before = $m->find((int) $p['id'], $this->companyId());
        if (!$before) {
            Response::fail('Not found', 404);
        }
        $m->delete((int) $p['id']);
        Audit::log('delete', $resource, $p['id'], $before, null);
        Response::ok(null, 'Deleted');
    }

    /** Current authenticated user + permissions (used by the SPA shell). */
    public function me(Request $req): void
    {
        $u = Auth::user();
        if (!$u) {
            Response::fail('Unauthenticated', 401);
        }
        Response::ok([
            'user' => [
                'id' => $u['id'], 'name' => $u['full_name'], 'username' => $u['username'],
                'role' => $u['role_code'], 'outlet_id' => $u['outlet_id'], 'kiosk_id' => $u['kiosk_id'],
            ],
            'permissions' => Auth::permissions(),
            'csrf' => \App\Core\Csrf::token(),
        ]);
    }
}
