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
            'worker'         => '/worker',
            'outlet_manager' => '/outlet',
            default          => '/hq',
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
        $this->view('dashboard.outlet', [
            'title' => 'Outlet Dashboard',
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
        $this->view('reports.reconciliation', [
            'title'   => 'Sales Reconciliation',
            'outlets' => $this->outletList(),
        ]);
    }

    private function outletList(): array
    {
        $u = Auth::user();
        if ($u['outlet_id']) {
            return Database::all('SELECT id, name FROM outlets WHERE id = ?', [$u['outlet_id']]);
        }
        return Database::all(
            'SELECT id, name FROM outlets WHERE company_id = ? AND is_active = 1 ORDER BY name',
            [$u['company_id']]
        );
    }
}
