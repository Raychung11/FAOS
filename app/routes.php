<?php
declare(strict_types=1);

use App\Core\Router;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\PageController;
use App\Controllers\Api\MasterDataController;
use App\Controllers\Api\QrController;
use App\Controllers\Api\StockController;
use App\Controllers\Api\SalesController;
use App\Controllers\Api\DashboardController;
use App\Controllers\Api\ReportController;
use App\Controllers\Api\ReconciliationController;
use App\Controllers\Api\FinanceController;
use App\Controllers\Api\BankReconController;
use App\Controllers\Api\ProcurementController;
use App\Controllers\Api\ProductionController;
use App\Controllers\Api\ReplenishmentController;
use App\Controllers\Api\EInvoiceController;
use App\Controllers\Api\AdminUserController;
use App\Controllers\Api\ImportController;

/** @var Router $r */
$r = new Router();

// ---- Auth (web) ------------------------------------------------------------
$r->get('/login',  [AuthController::class, 'showLogin']);
$r->post('/login', [AuthController::class, 'login']);
$r->post('/logout',[AuthController::class, 'logout']);

// ---- Web pages -------------------------------------------------------------
$r->get('/',          [PageController::class, 'home']);
$r->get('/welcome',   [PageController::class, 'landing']);
$r->get('/worker',    [PageController::class, 'workerDashboard']);
$r->get('/outlet',    [PageController::class, 'outletDashboard']);
$r->get('/hq',        [PageController::class, 'hqDashboard']);
$r->get('/ai',        [PageController::class, 'aiDashboard']);
$r->get('/sales',     [PageController::class, 'salesScan']);
$r->get('/sales-manage', [PageController::class, 'salesManage']);
$r->get('/admin/users',  [PageController::class, 'adminUsers']);
$r->get('/import',       [PageController::class, 'importPage']);
$r->get('/stock',     [PageController::class, 'stockScan']);
$r->get('/qr-labels', [PageController::class, 'qrLabels']);
$r->get('/master/{resource}', [PageController::class, 'masterData']);
$r->get('/reports',   [PageController::class, 'reports']);
$r->get('/reconciliation', [PageController::class, 'reconciliation']);
$r->get('/finance',   [PageController::class, 'finance']);
$r->get('/bank-recon',[PageController::class, 'bankRecon']);
$r->get('/procurement',  [PageController::class, 'procurement']);
$r->get('/production',   [PageController::class, 'production']);
$r->get('/replenishment',[PageController::class, 'replenishment']);
$r->get('/einvoicing',   [PageController::class, 'einvoicing']);

// ---- API: session / identity ----------------------------------------------
$r->get('/api/me', [MasterDataController::class, 'me']);

// ---- API: user & role administration --------------------------------------
$r->get('/api/admin/users',              [AdminUserController::class, 'list'],          'admin.users');
$r->get('/api/admin/roles',              [AdminUserController::class, 'roles'],         'admin.users');
$r->get('/api/admin/users/{id}',         [AdminUserController::class, 'show'],          'admin.users');
$r->post('/api/admin/users',             [AdminUserController::class, 'create'],        'admin.users');
$r->put('/api/admin/users/{id}',         [AdminUserController::class, 'update'],        'admin.users');
$r->post('/api/admin/users/{id}/password',[AdminUserController::class, 'resetPassword'],'admin.users');
$r->post('/api/admin/users/{id}/pin',    [AdminUserController::class, 'setPin'],        'admin.users');
$r->post('/api/admin/users/{id}/unlock', [AdminUserController::class, 'unlock'],        'admin.users');

// ---- API: bulk import ------------------------------------------------------
$r->get('/api/import/products/template', [ImportController::class, 'productTemplate'], 'masterdata.manage');
$r->post('/api/import/products',         [ImportController::class, 'products'],        'masterdata.manage');
$r->get('/api/import/stock/template',    [ImportController::class, 'stockTemplate'],   'stock.manage');
$r->post('/api/import/stock',            [ImportController::class, 'stock'],           'stock.manage');
$r->get('/api/import/po/template',       [ImportController::class, 'poTemplate'],      'procurement.manage');
$r->post('/api/import/po',               [ImportController::class, 'purchaseOrder'],   'procurement.manage');

// ---- API: master data ------------------------------------------------------
$r->get('/api/md/{resource}',        [MasterDataController::class, 'index'],  'masterdata.manage');
$r->get('/api/md/{resource}/{id}',   [MasterDataController::class, 'show'],   'masterdata.manage');
$r->post('/api/md/{resource}',       [MasterDataController::class, 'store'],  'masterdata.manage');
$r->put('/api/md/{resource}/{id}',   [MasterDataController::class, 'update'], 'masterdata.manage');
$r->delete('/api/md/{resource}/{id}',[MasterDataController::class, 'destroy'],'masterdata.manage');

// ---- API: QR ---------------------------------------------------------------
$r->post('/api/qr/labels',          [QrController::class, 'create'],     'qr.print');
$r->get('/api/qr/print',            [QrController::class, 'printSheet'], 'qr.print');
$r->get('/api/qr/{ref}/image',      [QrController::class, 'image']);
$r->get('/api/qr/{ref}',            [QrController::class, 'lookup'],     'stock.scan');

// ---- API: stock ------------------------------------------------------------
$r->post('/api/stock/receive',   [StockController::class, 'receive'],  'stock.scan');
$r->post('/api/stock/transfer',  [StockController::class, 'transfer'], 'stock.scan');
$r->post('/api/stock/consume',   [StockController::class, 'consume'],  'stock.scan');
$r->post('/api/stock/wastage',   [StockController::class, 'wastage'],  'stock.scan');
$r->post('/api/stock/count',     [StockController::class, 'count'],    'stock.manage');
$r->get('/api/stock/balances',   [StockController::class, 'balances'], 'stock.scan');
$r->get('/api/stock/ledger',     [StockController::class, 'ledger'],   'stock.scan');

// ---- API: sales ------------------------------------------------------------
$r->post('/api/sales',             [SalesController::class, 'record'],       'sales.scan');
$r->post('/api/sales/sync',        [SalesController::class, 'sync'],         'sales.scan');
$r->get('/api/sales',              [SalesController::class, 'list'],         'sales.view');
$r->post('/api/sales/shift/open',  [SalesController::class, 'openShift'],    'sales.scan');
$r->post('/api/sales/shift/close', [SalesController::class, 'closeShift'],   'sales.scan');
$r->get('/api/sales/shift',        [SalesController::class, 'shiftSummary'], 'sales.scan');
$r->get('/api/sales/{id}',         [SalesController::class, 'show'],         'sales.view');
$r->post('/api/sales/{id}/void',   [SalesController::class, 'void'],         'sales.void_refund');
$r->post('/api/sales/{id}/refund', [SalesController::class, 'refund'],       'sales.void_refund');

// ---- API: dashboards -------------------------------------------------------
$r->get('/api/dashboard/worker', [DashboardController::class, 'worker'], 'dashboard.worker');
$r->get('/api/dashboard/outlet', [DashboardController::class, 'outlet'], 'dashboard.outlet');
$r->get('/api/dashboard/hq',     [DashboardController::class, 'hq'],     'dashboard.hq');
$r->get('/api/dashboard/ai',     [DashboardController::class, 'ai'],     'ai.view');

// ---- API: reports ----------------------------------------------------------
$r->get('/api/reports/sales',              [ReportController::class, 'sales'],            'reports.view');
$r->get('/api/reports/stock-movement',     [ReportController::class, 'stockMovement'],    'reports.view');
$r->get('/api/reports/wastage',            [ReportController::class, 'wastage'],          'reports.view');
$r->get('/api/reports/worker-performance', [ReportController::class, 'workerPerformance'],'reports.view');

// ---- API: finance (Accountant) --------------------------------------------
$r->get('/api/finance/summary',           [FinanceController::class, 'summary'],            'finance.view');
$r->get('/api/finance/statement',         [FinanceController::class, 'statement'],          'finance.view');
$r->get('/api/finance/sst-summary',       [FinanceController::class, 'sstSummary'],         'finance.view');
$r->get('/api/finance/inventory-valuation', [FinanceController::class, 'inventoryValuation'], 'finance.view');
$r->get('/api/finance/periods',            [FinanceController::class, 'periods'],            'finance.view');
$r->post('/api/finance/periods/close',     [FinanceController::class, 'closePeriod'],        'finance.manage');
$r->post('/api/finance/periods/{id}/reopen',[FinanceController::class, 'reopenPeriod'],       'finance.manage');
$r->get('/api/finance/payables',          [FinanceController::class, 'payables'],           'finance.view');
$r->post('/api/finance/payables',         [FinanceController::class, 'createInvoice'],      'finance.manage');
$r->post('/api/finance/payables/{id}/pay',[FinanceController::class, 'payInvoice'],         'finance.manage');
$r->get('/api/finance/receivables',       [FinanceController::class, 'receivables'],        'finance.view');
$r->post('/api/finance/receivables/{id}/receive', [FinanceController::class, 'receiveSettlement'], 'finance.manage');

// ---- API: bank / payment reconciliation -----------------------------------
$r->get('/api/recon/mappings',    [BankReconController::class, 'mappings'],   'finance.view');
$r->post('/api/recon/mappings',   [BankReconController::class, 'saveMapping'],'finance.manage');
$r->post('/api/recon/preview',    [BankReconController::class, 'preview'],    'finance.manage');
$r->post('/api/recon/import',     [BankReconController::class, 'import'],     'finance.manage');
$r->post('/api/recon/run',        [BankReconController::class, 'run'],        'finance.manage');
$r->get('/api/recon/summary',     [BankReconController::class, 'summary'],    'finance.view');
$r->get('/api/recon/batches',     [BankReconController::class, 'batches'],    'finance.view');
$r->get('/api/recon/exceptions',  [BankReconController::class, 'exceptions'], 'finance.view');
$r->get('/api/recon/imports',     [BankReconController::class, 'imports'],    'finance.view');
$r->post('/api/recon/match',      [BankReconController::class, 'match'],      'finance.manage');

// ---- API: procurement (Supplier PO -> GRN -> stock + AP) ------------------
$r->get('/api/procurement/po',            [ProcurementController::class, 'listPO'],     'procurement.manage');
$r->post('/api/procurement/po',           [ProcurementController::class, 'createPO'],   'procurement.manage');
$r->get('/api/procurement/po/{id}',       [ProcurementController::class, 'showPO'],     'procurement.manage');
$r->post('/api/procurement/po/{id}/approve', [ProcurementController::class, 'approvePO'], 'procurement.manage');
$r->post('/api/procurement/grn',          [ProcurementController::class, 'receiveGRN'], 'procurement.manage');
$r->get('/api/procurement/grn',           [ProcurementController::class, 'listGRN'],    'procurement.manage');

// ---- API: central-kitchen production --------------------------------------
$r->get('/api/production',                [ProductionController::class, 'list'],     'kitchen.manage');
$r->post('/api/production',               [ProductionController::class, 'create'],   'kitchen.manage');
$r->get('/api/production/{id}',           [ProductionController::class, 'show'],     'kitchen.manage');
$r->post('/api/production/{id}/start',    [ProductionController::class, 'start'],    'kitchen.manage');
$r->post('/api/production/{id}/complete', [ProductionController::class, 'complete'], 'kitchen.manage');

// ---- API: outlet replenishment (PR -> distribution) -----------------------
$r->get('/api/replenishment',              [ReplenishmentController::class, 'list'],    'stock.manage');
$r->post('/api/replenishment',             [ReplenishmentController::class, 'create'],  'stock.manage');
$r->get('/api/replenishment/{id}',         [ReplenishmentController::class, 'show'],    'stock.manage');
$r->post('/api/replenishment/{id}/approve',[ReplenishmentController::class, 'approve'], 'procurement.manage');
$r->post('/api/replenishment/{id}/reject', [ReplenishmentController::class, 'reject'],  'procurement.manage');
$r->post('/api/replenishment/{id}/fulfil', [ReplenishmentController::class, 'fulfil'],  'procurement.manage');

// ---- API: e-invoicing (LHDN MyInvois) -------------------------------------
$r->get('/api/einvoice',                    [EInvoiceController::class, 'list'],     'finance.view');
$r->get('/api/einvoice/{id}',               [EInvoiceController::class, 'show'],     'finance.view');
$r->get('/api/einvoice/{id}/qr',            [EInvoiceController::class, 'qr'],       'finance.view');
$r->get('/api/einvoice/{id}/print',         [EInvoiceController::class, 'printDoc'], 'finance.view');
$r->post('/api/einvoice/transaction/{id}',  [EInvoiceController::class, 'generateForTransaction'], 'finance.manage');
$r->post('/api/einvoice/consolidated',      [EInvoiceController::class, 'generateConsolidated'],   'finance.manage');
$r->post('/api/einvoice/{id}/submit',       [EInvoiceController::class, 'submit'],   'finance.manage');

// ---- API: reconciliation ---------------------------------------------------
$r->post('/api/reconciliation/import',  [ReconciliationController::class, 'import'],   'reconciliation.manage');
$r->get('/api/reconciliation/variance', [ReconciliationController::class, 'variance'], 'reconciliation.manage');
$r->get('/api/reconciliation/reports',  [ReconciliationController::class, 'reports'],  'reconciliation.manage');

return $r;
