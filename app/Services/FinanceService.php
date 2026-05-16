<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Operational finance (no double-entry GL):
 *  - P&L summary: revenue, COGS, wastage cost, gross profit
 *  - Accounts Payable: supplier invoices + payments
 *  - Accounts Receivable: hypermarket settlements from delayed reports
 */
final class FinanceService
{
    /** Profit & loss summary for a period, with account-group breakdown. */
    public static function pnl(int $company, string $from, string $to): array
    {
        $f = $from . ' 00:00:00';
        $t = $to . ' 23:59:59';

        $revenue = (float) Database::scalar(
            'SELECT COALESCE(SUM(si.line_amount),0)
             FROM sales_items si JOIN sales_transactions st ON st.id = si.transaction_id
             WHERE st.company_id = ? AND st.sold_at BETWEEN ? AND ?',
            [$company, $f, $t]
        );

        // COGS = finished-good sale movements (recipe "consume" moves carry no
        // unit cost, so there is no double counting).
        $cogs = (float) Database::scalar(
            "SELECT COALESCE(SUM(qty*unit_cost),0) FROM stock_movements
             WHERE company_id = ? AND movement_type = 'sale'
               AND created_at BETWEEN ? AND ?",
            [$company, $f, $t]
        );

        $wastage = (float) Database::scalar(
            "SELECT COALESCE(SUM(qty*unit_cost),0) FROM stock_movements
             WHERE company_id = ? AND movement_type = 'wastage'
               AND created_at BETWEEN ? AND ?",
            [$company, $f, $t]
        );

        $byGroup = Database::all(
            "SELECT COALESCE(ag.name,'(Unclassified)') AS account_group,
                    SUM(si.line_amount) AS revenue,
                    SUM(si.qty * p.cost_price) AS cogs
             FROM sales_items si
             JOIN sales_transactions st ON st.id = si.transaction_id
             JOIN products p ON p.id = si.product_id
             LEFT JOIN account_groups ag ON ag.id = p.account_group_id
             WHERE st.company_id = ? AND st.sold_at BETWEEN ? AND ?
             GROUP BY ag.id, ag.name
             ORDER BY revenue DESC",
            [$company, $f, $t]
        );
        foreach ($byGroup as &$g) {
            $g['gross_profit'] = round((float) $g['revenue'] - (float) $g['cogs'], 2);
        }
        unset($g);

        $daily = Database::all(
            "SELECT DATE(st.sold_at) d, SUM(si.line_amount) revenue
             FROM sales_items si JOIN sales_transactions st ON st.id = si.transaction_id
             WHERE st.company_id = ? AND st.sold_at BETWEEN ? AND ?
             GROUP BY d ORDER BY d",
            [$company, $f, $t]
        );

        $gross = round($revenue - $cogs, 2);
        return [
            'period'        => ['from' => $from, 'to' => $to],
            'revenue'       => round($revenue, 2),
            'cogs'          => round($cogs, 2),
            'gross_profit'  => $gross,
            'wastage_cost'  => round($wastage, 2),
            'gross_margin_pct' => $revenue > 0 ? round($gross / $revenue * 100, 1) : 0.0,
            'by_account_group' => $byGroup,
            'daily_revenue' => $daily,
        ];
    }

    public static function payables(int $company, ?string $status = null): array
    {
        $sql = "SELECT si.*, s.name AS supplier_name,
                       (si.total_amount - si.paid_amount) AS outstanding,
                       CASE WHEN si.status <> 'paid' AND si.due_date IS NOT NULL
                            THEN DATEDIFF(CURDATE(), si.due_date) ELSE 0 END AS days_overdue
                FROM supplier_invoices si
                JOIN suppliers s ON s.id = si.supplier_id
                WHERE si.company_id = ?";
        $args = [$company];
        if ($status) {
            $sql .= ' AND si.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY si.due_date IS NULL, si.due_date ASC';
        $rows = Database::all($sql, $args);
        $totals = [
            'total'       => round(array_sum(array_column($rows, 'total_amount')), 2),
            'paid'        => round(array_sum(array_column($rows, 'paid_amount')), 2),
            'outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
        ];
        return ['rows' => $rows, 'totals' => $totals];
    }

    public static function receivables(int $company, ?string $status = null): array
    {
        $sql = "SELECT a.*, o.name AS outlet_name,
                       (a.expected_amount - a.received_amount) AS outstanding
                FROM ar_settlements a
                JOIN outlets o ON o.id = a.outlet_id
                WHERE a.company_id = ?";
        $args = [$company];
        if ($status) {
            $sql .= ' AND a.status = ?';
            $args[] = $status;
        }
        $sql .= ' ORDER BY a.status = "settled", a.period_end ASC';
        $rows = Database::all($sql, $args);
        $totals = [
            'expected'    => round(array_sum(array_column($rows, 'expected_amount')), 2),
            'received'    => round(array_sum(array_column($rows, 'received_amount')), 2),
            'outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
        ];
        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Recognise/refresh a receivable when a delayed report is reconciled. */
    public static function upsertSettlementFromReport(
        int $company,
        int $outletId,
        int $reportId,
        string $periodStart,
        string $periodEnd,
        float $expected
    ): void {
        $existing = Database::first(
            'SELECT id FROM ar_settlements WHERE report_id = ?',
            [$reportId]
        );
        if ($existing) {
            Database::run(
                'UPDATE ar_settlements SET expected_amount = ? WHERE id = ?',
                [$expected, $existing['id']]
            );
            return;
        }
        Database::insert(
            'INSERT INTO ar_settlements
               (company_id, outlet_id, report_id, period_start, period_end, expected_amount)
             VALUES (?,?,?,?,?,?)',
            [$company, $outletId, $reportId, $periodStart, $periodEnd, $expected]
        );
    }

    public static function applyInvoicePayment(int $invoiceId, float $amount): void
    {
        $inv = Database::first('SELECT * FROM supplier_invoices WHERE id = ?', [$invoiceId]);
        $paid = round((float) $inv['paid_amount'] + $amount, 2);
        $status = $paid <= 0 ? 'unpaid'
            : ($paid + 0.001 >= (float) $inv['total_amount'] ? 'paid' : 'partial');
        Database::run(
            'UPDATE supplier_invoices SET paid_amount = ?, status = ? WHERE id = ?',
            [$paid, $status, $invoiceId]
        );
    }

    public static function applyReceipt(int $settlementId, float $amount): void
    {
        $s = Database::first('SELECT * FROM ar_settlements WHERE id = ?', [$settlementId]);
        $recv = round((float) $s['received_amount'] + $amount, 2);
        $status = $recv <= 0 ? 'pending'
            : ($recv + 0.001 >= (float) $s['expected_amount'] ? 'settled' : 'partial');
        Database::run(
            'UPDATE ar_settlements SET received_amount = ?, status = ? WHERE id = ?',
            [$recv, $status, $settlementId]
        );
    }
}
