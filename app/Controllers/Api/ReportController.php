<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class ReportController extends Controller
{
    private const DIMENSIONS = [
        'outlet'        => ['o.name',  'outlets o ON o.id = st.outlet_id'],
        'kiosk'         => ['k.name',  'kiosks k ON k.id = st.kiosk_id'],
        'worker'        => ['us.full_name', 'users us ON us.id = st.user_id'],
        'sku'           => ['p.sku',   'products p ON p.id = si.product_id'],
        'category'      => ['pc.name', 'products p ON p.id = si.product_id LEFT JOIN product_categories pc ON pc.id = p.category_id'],
        'stock_group'   => ['sg.name', 'products p ON p.id = si.product_id LEFT JOIN stock_groups sg ON sg.id = p.stock_group_id'],
        'account_group' => ['ag.name', 'products p ON p.id = si.product_id LEFT JOIN account_groups ag ON ag.id = p.account_group_id'],
    ];

    /** Consolidated sales grouped by a chosen dimension. */
    public function sales(Request $req): void
    {
        $dim = $req->query('by', 'outlet');
        if (!isset(self::DIMENSIONS[$dim])) {
            Response::fail('Invalid dimension', 422);
        }
        [$label, $join] = self::DIMENSIONS[$dim];

        $where = "st.company_id = ? AND st.status <> 'voided'";
        $args = [$this->companyId()];
        $u = Auth::user();
        if ($u['outlet_id']) {
            $where .= ' AND st.outlet_id = ?';
            $args[] = $u['outlet_id'];
        }
        if ($req->query('from')) {
            $where .= ' AND st.sold_at >= ?';
            $args[] = $req->query('from') . ' 00:00:00';
        }
        if ($req->query('to')) {
            $where .= ' AND st.sold_at <= ?';
            $args[] = $req->query('to') . ' 23:59:59';
        }

        $rows = Database::all(
            "SELECT $label AS label,
                    SUM(si.qty) AS qty,
                    SUM(si.line_amount) AS gross,
                    COUNT(DISTINCT st.id) AS txns
             FROM sales_transactions st
             JOIN sales_items si ON si.transaction_id = st.id
             JOIN $join
             WHERE $where
             GROUP BY label
             ORDER BY gross DESC",
            $args
        );

        // Per-thousand sales analysis (industry KPI: value per 1,000 units).
        foreach ($rows as &$r) {
            $r['per_thousand'] = $r['qty'] > 0
                ? round(($r['gross'] / $r['qty']) * 1000, 2)
                : 0;
        }
        unset($r);

        $totals = [
            'qty'   => array_sum(array_column($rows, 'qty')),
            'gross' => array_sum(array_column($rows, 'gross')),
            'txns'  => array_sum(array_column($rows, 'txns')),
        ];

        if ($req->query('export') === 'csv') {
            $this->csv("sales_by_$dim", ['label', 'qty', 'gross', 'txns', 'per_thousand'], $rows);
        }
        Response::ok(['dimension' => $dim, 'rows' => $rows, 'totals' => $totals]);
    }

    public function stockMovement(Request $req): void
    {
        $rows = Database::all(
            "SELECT sm.created_at, p.sku, p.name, sm.movement_type, sm.qty,
                    sm.from_loc_type, sm.to_loc_type, u.full_name AS user_name
             FROM stock_movements sm
             JOIN products p ON p.id = sm.product_id
             LEFT JOIN users u ON u.id = sm.user_id
             WHERE sm.company_id = ?
               AND sm.created_at >= COALESCE(?, DATE_SUB(CURDATE(), INTERVAL 30 DAY))
             ORDER BY sm.id DESC LIMIT 1000",
            [$this->companyId(), $req->query('from')]
        );
        if ($req->query('export') === 'csv') {
            $this->csv('stock_movement', array_keys($rows[0] ?? ['created_at' => '']), $rows);
        }
        Response::ok($rows);
    }

    public function wastage(Request $req): void
    {
        $rows = Database::all(
            "SELECT DATE(sm.created_at) d, p.sku, p.name, SUM(sm.qty) qty,
                    SUM(sm.qty * sm.unit_cost) cost
             FROM stock_movements sm JOIN products p ON p.id = sm.product_id
             WHERE sm.company_id = ? AND sm.movement_type = 'wastage'
             GROUP BY d, p.id ORDER BY d DESC",
            [$this->companyId()]
        );
        if ($req->query('export') === 'csv') {
            $this->csv('wastage', ['d','sku','name','qty','cost'], $rows);
        }
        Response::ok($rows);
    }

    public function workerPerformance(Request $req): void
    {
        $rows = Database::all(
            "SELECT us.full_name worker, o.name outlet,
                    COUNT(st.id) txns, COALESCE(SUM(st.total_amount),0) amount,
                    COALESCE(SUM(st.total_qty),0) qty
             FROM users us
             JOIN sales_transactions st ON st.user_id = us.id
             JOIN outlets o ON o.id = st.outlet_id
             WHERE us.company_id = ?
               AND st.sold_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               AND st.status <> 'voided'
             GROUP BY us.id ORDER BY amount DESC",
            [$this->companyId()]
        );
        if ($req->query('export') === 'csv') {
            $this->csv('worker_performance', ['worker','outlet','txns','amount','qty'], $rows);
        }
        Response::ok($rows);
    }

    private function csv(string $name, array $cols, array $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn ($c) => $r[$c] ?? '', $cols));
        }
        fclose($out);
        exit;
    }
}
