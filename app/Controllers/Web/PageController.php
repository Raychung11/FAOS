<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class PageController extends Controller
{
    public function home(Request $req): void
    {
        Auth::requireLogin($req);
        $role = Auth::user()['role_code'];
        $target = match ($role) {
            'worker'             => '/worker',
            'outlet_manager',
            'restaurant_manager' => '/outlet',
            'accountant'         => '/finance',
            default              => '/hq',
        };
        Response::redirect(base_url($target));
    }

    public function workerDashboard(Request $req): void
    {
        Auth::requirePermission($req, 'dashboard.worker');
        $this->view('dashboard.worker', ['title' => 'Worker Dashboard']);
    }

    public function outletDashboard(Request $req): void
    {
        Auth::requirePermission($req, 'dashboard.outlet');
        $isRestaurant = Auth::user()['role_code'] === 'restaurant_manager';
        $this->view('dashboard.outlet', [
            'title' => $isRestaurant ? 'Restaurant Dashboard' : 'Outlet Dashboard',
            'outlets' => $this->outletList(),
        ]);
    }

    public function hqDashboard(Request $req): void
    {
        Auth::requirePermission($req, 'dashboard.hq');
        $this->view('dashboard.hq', ['title' => 'HQ Dashboard']);
    }

    public function aiDashboard(Request $req): void
    {
        Auth::requirePermission($req, 'ai.view');
        $this->view('dashboard.ai', [
            'title'   => 'AI Forecasting',
            'outlets' => $this->outletList(),
        ]);
    }

    public function salesScan(Request $req): void
    {
        Auth::requirePermission($req, 'sales.scan');
        $u = Auth::user();
        $products = Database::all(
            'SELECT id, sku, barcode, name, sell_price FROM products
             WHERE company_id = ? AND is_sellable = 1 AND is_active = 1 ORDER BY name',
            [$u['company_id']]
        );
        $this->view('sales.scan', [
            'title' => 'QR Sales',
            'products' => $products,
        ]);
    }

    public function salesManage(Request $req): void
    {
        Auth::requirePermission($req, 'sales.void_refund');
        $this->view('sales.manage', ['title' => 'Sales Corrections']);
    }

    public function importPage(Request $req): void
    {
        Auth::requireLogin($req);
        if (!Auth::can('masterdata.manage') && !Auth::can('stock.manage')) {
            Auth::requirePermission($req, 'masterdata.manage');
        }
        $this->view('admin.import', [
            'title'       => 'Bulk Import',
            'canProducts' => Auth::can('masterdata.manage'),
            'canStock'    => Auth::can('stock.manage'),
        ]);
    }

    public function adminUsers(Request $req): void
    {
        Auth::requirePermission($req, 'admin.users');
        $this->view('admin.users', [
            'title'   => 'Users & Roles',
            'outlets' => Database::all(
                'SELECT id, name FROM outlets WHERE company_id=? AND is_active=1 ORDER BY name',
                [$this->companyId()]
            ),
            'kiosks'  => Database::all(
                'SELECT k.id, k.name, k.outlet_id FROM kiosks k
                 JOIN outlets o ON o.id=k.outlet_id WHERE o.company_id=? AND k.is_active=1',
                [$this->companyId()]
            ),
        ]);
    }

    public function stockScan(Request $req): void
    {
        Auth::requirePermission($req, 'stock.scan');
        $this->view('stock.scan', [
            'title' => 'QR Stock',
            'outlets' => $this->outletList(),
            'warehouses' => Database::all('SELECT id, name FROM warehouses WHERE company_id=? AND is_active=1', [$this->companyId()]),
            'kiosks' => Database::all(
                'SELECT k.id, k.name, k.outlet_id FROM kiosks k
                 JOIN outlets o ON o.id=k.outlet_id WHERE o.company_id=? AND k.is_active=1',
                [$this->companyId()]
            ),
        ]);
    }

    public function qrLabels(Request $req): void
    {
        Auth::requirePermission($req, 'qr.print');
        $this->view('stock.labels', [
            'title' => 'QR Labels',
            'products' => Database::all('SELECT id, sku, name FROM products WHERE company_id=? AND is_active=1 ORDER BY name', [$this->companyId()]),
        ]);
    }

    public function masterData(Request $req, array $params): void
    {
        Auth::requirePermission($req, 'masterdata.manage');
        $resource = $params['resource'] ?? 'products';
        $this->view('masterdata.index', [
            'title' => 'Master Data',
            'resource' => $resource,
        ]);
    }

    public function reports(Request $req): void
    {
        Auth::requirePermission($req, 'reports.view');
        $this->view('reports.index', [
            'title'   => 'Reports',
            'outlets' => $this->outletList(),
        ]);
    }

    public function reconciliation(Request $req): void
    {
        Auth::requirePermission($req, 'reconciliation.manage');
        // Only hypermarket kiosk hubs get delayed 3rd-party reports;
        // restaurants own their POS and need no reconciliation.
        $this->view('reports.reconciliation', [
            'title'   => 'Sales Reconciliation',
            'outlets' => $this->outletList(true),
        ]);
    }

    public function finance(Request $req): void
    {
        Auth::requirePermission($req, 'finance.view');
        $this->view('finance.index', [
            'title'     => 'Finance',
            'suppliers' => Database::all(
                'SELECT id, code, name FROM suppliers WHERE company_id = ? AND is_active = 1 ORDER BY name',
                [$this->companyId()]
            ),
        ]);
    }

    public function bankRecon(Request $req): void
    {
        Auth::requirePermission($req, 'finance.view');
        $this->view('finance.bankrecon', ['title' => 'Bank Reconciliation']);
    }

    public function einvoicing(Request $req): void
    {
        Auth::requirePermission($req, 'finance.view');
        $this->view('finance.einvoicing', [
            'title'     => 'e-Invoicing (MyInvois)',
            'canManage' => Auth::can('finance.manage'),
            'outlets'   => $this->outletList(),
        ]);
    }

    public function procurement(Request $req): void
    {
        Auth::requirePermission($req, 'procurement.manage');
        $this->view('supply.procurement', [
            'title'      => 'Procurement',
            'suppliers'  => Database::all('SELECT id, code, name FROM suppliers WHERE company_id=? AND is_active=1 ORDER BY name', [$this->companyId()]),
            'warehouses' => Database::all('SELECT id, name, type FROM warehouses WHERE company_id=? AND is_active=1 ORDER BY name', [$this->companyId()]),
            'products'   => Database::all('SELECT id, sku, name, cost_price FROM products WHERE company_id=? AND is_active=1 ORDER BY name', [$this->companyId()]),
        ]);
    }

    public function production(Request $req): void
    {
        Auth::requirePermission($req, 'kitchen.manage');
        $this->view('supply.production', [
            'title'      => 'Central Kitchen Production',
            'warehouses' => Database::all("SELECT id, name FROM warehouses WHERE company_id=? AND is_active=1 AND type='central_kitchen' ORDER BY name", [$this->companyId()]),
            'products'   => Database::all(
                "SELECT p.id, p.sku, p.name FROM products p
                 JOIN recipes r ON r.product_id = p.id AND r.is_active=1
                 WHERE p.company_id=? AND p.is_active=1 ORDER BY p.name",
                [$this->companyId()]
            ),
        ]);
    }

    public function replenishment(Request $req): void
    {
        Auth::requirePermission($req, 'stock.manage');
        $this->view('supply.replenishment', [
            'title'      => 'Outlet Replenishment',
            'canApprove' => Auth::can('procurement.manage'),
            'outlets'    => $this->outletList(),
            'warehouses' => Database::all('SELECT id, name FROM warehouses WHERE company_id=? AND is_active=1 ORDER BY name', [$this->companyId()]),
            'products'   => Database::all('SELECT id, sku, name FROM products WHERE company_id=? AND is_active=1 ORDER BY name', [$this->companyId()]),
        ]);
    }

    private function outletList(bool $kioskHubOnly = false): array
    {
        $u = Auth::user();
        if ($u['outlet_id']) {
            return Database::all('SELECT id, name FROM outlets WHERE id = ?', [$u['outlet_id']]);
        }
        $sql = 'SELECT id, name FROM outlets WHERE company_id = ? AND is_active = 1';
        if ($kioskHubOnly) {
            $sql .= " AND outlet_type = 'kiosk_hub'";
        }
        $sql .= ' ORDER BY name';
        return Database::all($sql, [$u['company_id']]);
    }
}
