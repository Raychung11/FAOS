<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AiClient;
use App\Services\ForecastService;
use App\Services\StockService;

final class DashboardController extends Controller
{
    public function worker(Request $req): void
    {
        $u = Auth::user();
        $today = Database::first(
            "SELECT COUNT(*) txns, COALESCE(SUM(total_amount),0) amount, COALESCE(SUM(total_qty),0) qty
             FROM sales_transactions
             WHERE user_id = ? AND DATE(sold_at) = CURDATE() AND status <> 'voided'",
            [$u['id']]
        );
        $top = Database::all(
            "SELECT p.name, SUM(si.qty) qty
             FROM sales_transactions st
             JOIN sales_items si ON si.transaction_id = st.id
             JOIN products p ON p.id = si.product_id
             WHERE st.user_id = ? AND DATE(st.sold_at) = CURDATE() AND st.status <> 'voided'
             GROUP BY p.id ORDER BY qty DESC LIMIT 5",
            [$u['id']]
        );
        $locType = $u['kiosk_id'] ? 'kiosk' : 'outlet';
        $locId = $u['kiosk_id'] ?: $u['outlet_id'];
        $stock = Database::all(
            'SELECT p.name, b.qty, p.uom, p.reorder_level
             FROM stock_balances b JOIN products p ON p.id = b.product_id
             WHERE b.loc_type = ? AND b.loc_id = ? ORDER BY b.qty ASC LIMIT 20',
            [$locType, $locId]
        );
        Response::ok([
            'today' => $today,
            'top_products' => $top,
            'stock' => $stock,
            'shift' => Database::first(
                "SELECT id, opened_at FROM shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1",
                [$u['id']]
            ),
        ]);
    }

    public function outlet(Request $req): void
    {
        $u = Auth::user();
        $outletId = (int) ($u['outlet_id'] ?: $req->query('outlet_id'));
        if (!$outletId) {
            Response::fail('outlet_id required', 422);
        }
        $sales = Database::first(
            "SELECT COUNT(*) txns, COALESCE(SUM(total_amount),0) amount
             FROM sales_transactions WHERE outlet_id=? AND DATE(sold_at)=CURDATE()
               AND status <> 'voided'",
            [$outletId]
        );
        $kioskPerf = Database::all(
            "SELECT k.name, COALESCE(SUM(st.total_amount),0) amount, COUNT(st.id) txns
             FROM kiosks k
             LEFT JOIN sales_transactions st ON st.kiosk_id=k.id
                  AND DATE(st.sold_at)=CURDATE() AND st.status <> 'voided'
             WHERE k.outlet_id=? GROUP BY k.id ORDER BY amount DESC",
            [$outletId]
        );
        Response::ok([
            'today'          => $sales,
            'kiosk_perf'     => $kioskPerf,
            'low_stock'      => StockService::lowStock((int) $u['company_id'], 'outlet', $outletId),
            'pending_replenishment' => StockService::lowStock((int) $u['company_id'], 'outlet', $outletId),
        ]);
    }

    public function hq(Request $req): void
    {
        $cid = $this->companyId();
        $today = Database::first(
            "SELECT COUNT(*) txns, COALESCE(SUM(total_amount),0) amount, COALESCE(SUM(total_qty),0) qty
             FROM sales_transactions WHERE company_id=? AND DATE(sold_at)=CURDATE()
               AND status <> 'voided'",
            [$cid]
        );
        $month = Database::first(
            "SELECT COALESCE(SUM(total_amount),0) amount
             FROM sales_transactions
             WHERE company_id=? AND sold_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')
               AND status <> 'voided'",
            [$cid]
        );
        $outletRank = Database::all(
            "SELECT o.name, COALESCE(SUM(st.total_amount),0) amount
             FROM outlets o
             LEFT JOIN sales_transactions st ON st.outlet_id=o.id
                  AND st.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND st.status <> 'voided'
             WHERE o.company_id=? GROUP BY o.id ORDER BY amount DESC",
            [$cid]
        );
        $productRank = Database::all(
            "SELECT p.name, SUM(si.qty) qty, SUM(si.line_amount) amount
             FROM sales_items si
             JOIN sales_transactions st ON st.id=si.transaction_id
             JOIN products p ON p.id=si.product_id
             WHERE st.company_id=? AND st.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               AND st.status <> 'voided'
             GROUP BY p.id ORDER BY amount DESC LIMIT 10",
            [$cid]
        );
        $trend = Database::all(
            "SELECT DATE(sold_at) d, SUM(total_amount) amount
             FROM sales_transactions
             WHERE company_id=? AND sold_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
               AND status <> 'voided'
             GROUP BY d ORDER BY d",
            [$cid]
        );
        Response::ok([
            'today'         => $today,
            'month_amount'  => $month['amount'],
            'outlet_rank'   => $outletRank,
            'product_rank'  => $productRank,
            'trend'         => $trend,
            'low_stock'     => StockService::lowStock($cid),
            'expiring'      => StockService::expiringSoon($cid, 3),
            'scan_activity' => Database::first(
                "SELECT COUNT(*) total,
                        SUM(result='ok') ok,
                        SUM(result<>'ok') problem
                 FROM scan_logs WHERE company_id=? AND DATE(created_at)=CURDATE()",
                [$cid]
            ),
        ]);
    }

    public function ai(Request $req): void
    {
        $cid = $this->companyId();
        $u = Auth::user();
        $outletId = $req->query('outlet_id') ? (int) $req->query('outlet_id') : ($u['outlet_id'] ? (int) $u['outlet_id'] : null);

        $forecast = ForecastService::forecast($cid, $outletId, 14);
        $purchase = ForecastService::purchaseRecommendation($cid, $outletId);
        $anomalies = ForecastService::anomalies($cid, $outletId);

        $narrative = null;
        if (AiClient::enabled()) {
            $top = array_slice($forecast, 0, 5);
            $prompt = "You are an F&B operations analyst. Given this 7-day demand forecast and "
                . "purchase recommendation for a Malaysian kiosk business, give 3 concise, "
                . "actionable bullet points (procurement + production focus).\n\n"
                . 'Forecast: ' . json_encode($top) . "\n"
                . 'Purchase: ' . json_encode(array_slice($purchase, 0, 8));
            $narrative = AiClient::insight($prompt);
        }

        Response::ok([
            'ai_enabled'   => AiClient::enabled(),
            'forecast'     => array_slice($forecast, 0, 20),
            'purchase_recommendation' => $purchase,
            'anomalies'    => $anomalies,
            'narrative'    => $narrative,
        ]);
    }
}
