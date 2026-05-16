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

/** @var Router $r */
$r = new Router();

// ---- Auth (web) ------------------------------------------------------------
$r->get('/login',  [AuthController::class, 'showLogin']);
$r->post('/login', [AuthController::class, 'login']);
$r->post('/logout',[AuthController::class, 'logout']);

// ---- Web pages -------------------------------------------------------------
$r->get('/',          [PageController::class, 'home']);
$r->get('/worker',    [PageController::class, 'workerDashboard']);
$r->get('/outlet',    [PageController::class, 'outletDashboard']);
$r->get('/hq',        [PageController::class, 'hqDashboard']);
$r->get('/ai',        [PageController::class, 'aiDashboard']);
$r->get('/sales',     [PageController::class, 'salesScan']);
$r->get('/stock',     [PageController::class, 'stockScan']);
$r->get('/qr-labels', [PageController::class, 'qrLabels']);
$r->get('/master/{resource}', [PageController::class, 'masterData']);
$r->get('/reports',   [PageController::class, 'reports']);
$r->get('/reconciliation', [PageController::class, 'reconciliation']);
$r->get('/finance',   [PageController::class, 'finance']);
$r->get('/bank-recon',[PageController::class, 'bankRecon']);

// ---- API: session / identity ----------------------------------------------
$r->get('/api/me', [MasterDataController::class, 'me']);

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

// ---- API: reconciliation ---------------------------------------------------
$r->post('/api/reconciliation/import',  [ReconciliationController::class, 'import'],   'reconciliation.manage');
$r->get('/api/reconciliation/variance', [ReconciliationController::class, 'variance'], 'reconciliation.manage');
$r->get('/api/reconciliation/reports',  [ReconciliationController::class, 'reports'],  'reconciliation.manage');

return $r;
