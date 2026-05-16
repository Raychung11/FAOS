<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Reconciles delayed (3-4 week) official hypermarket reports against
 * real-time QR-scanned sales and classifies the variance.
 */
final class ReconciliationService
{
    public static function generate(int $reportId): array
    {
        $report = Database::first('SELECT * FROM official_sales_reports WHERE id = ?', [$reportId]);
        if (!$report) {
            throw new \RuntimeException('Report not found');
        }

        Database::run('DELETE FROM variance_reports WHERE report_id = ?', [$reportId]);

        // QR-scanned sales for the same outlet + period, per product.
        $qr = Database::all(
            'SELECT si.product_id, SUM(si.qty) qty, SUM(si.line_amount) amount
             FROM sales_transactions st
             JOIN sales_items si ON si.transaction_id = st.id
             WHERE st.outlet_id = ? AND DATE(st.sold_at) BETWEEN ? AND ?
               AND st.status <> ?
             GROUP BY si.product_id',
            [$report['outlet_id'], $report['period_start'], $report['period_end'], 'voided']
        );
        $qrMap = [];
        foreach ($qr as $r) {
            $qrMap[(int) $r['product_id']] = $r;
        }

        $official = Database::all(
            'SELECT product_id, SUM(qty) qty, SUM(amount) amount
             FROM official_sales_lines WHERE report_id = ? GROUP BY product_id',
            [$reportId]
        );
        $offMap = [];
        foreach ($official as $r) {
            if ($r['product_id'] !== null) {
                $offMap[(int) $r['product_id']] = $r;
            }
        }

        $productIds = array_unique(array_merge(array_keys($qrMap), array_keys($offMap)));
        $rows = [];
        foreach ($productIds as $pid) {
            $qQty = (float) ($qrMap[$pid]['qty'] ?? 0);
            $qAmt = (float) ($qrMap[$pid]['amount'] ?? 0);
            $oQty = (float) ($offMap[$pid]['qty'] ?? 0);
            $oAmt = (float) ($offMap[$pid]['amount'] ?? 0);
            $qtyVar = round($qQty - $oQty, 3);
            $amtVar = round($qAmt - $oAmt, 2);

            $status = self::classify($qQty, $oQty);

            Database::insert(
                'INSERT INTO variance_reports
                  (company_id, report_id, outlet_id, product_id, qr_qty, qr_amount,
                   official_qty, official_amount, qty_variance, amount_variance, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $report['company_id'], $reportId, $report['outlet_id'], $pid,
                    $qQty, $qAmt, $oQty, $oAmt, $qtyVar, $amtVar, $status,
                ]
            );
            $rows[] = compact('pid') + [
                'qr_qty' => $qQty, 'official_qty' => $oQty,
                'qty_variance' => $qtyVar, 'amount_variance' => $amtVar, 'status' => $status,
            ];
        }
        return $rows;
    }

    private static function classify(float $qr, float $official): string
    {
        if (abs($qr - $official) < 0.001) {
            return 'matched';
        }
        if ($qr === 0.0 && $official > 0) {
            return 'missing_scans';   // sales happened but were never scanned
        }
        if ($qr > $official) {
            return 'over_reported';   // QR shows more than the hypermarket paid for
        }
        if ($official > $qr) {
            return 'under_reported';  // hypermarket reports more than we scanned
        }
        return 'shortage';
    }
}
