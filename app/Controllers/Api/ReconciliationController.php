<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ReconciliationService;

final class ReconciliationController extends Controller
{
    /**
     * Import an official (delayed 3-4 week) hypermarket sales report.
     * Lines may reference products by SKU; unknown SKUs are kept as text.
     */
    public function import(Request $req): void
    {
        $d = $this->validate($req, [
            'outlet_id'    => 'required|int',
            'period_start' => 'required|date',
            'period_end'   => 'required|date',
        ]);
        $lines = $req->input('lines');
        if (!is_array($lines) || $lines === []) {
            Response::fail('lines[] required', 422);
        }

        $reportId = Database::transaction(function () use ($d, $lines, $req) {
            $rid = Database::insert(
                'INSERT INTO official_sales_reports
                  (company_id, outlet_id, period_start, period_end, source_name, imported_by)
                 VALUES (?,?,?,?,?,?)',
                [$this->companyId(), $d['outlet_id'], $d['period_start'], $d['period_end'],
                 $req->input('source_name'), Auth::id()]
            );
            foreach ($lines as $ln) {
                $sku = $ln['sku'] ?? null;
                $pid = null;
                if ($sku) {
                    $pid = Database::scalar(
                        'SELECT id FROM products WHERE company_id=? AND sku=?',
                        [$this->companyId(), $sku]
                    ) ?: null;
                }
                Database::insert(
                    'INSERT INTO official_sales_lines (report_id, product_id, sku, qty, amount)
                     VALUES (?,?,?,?,?)',
                    [$rid, $pid, $sku, (float) ($ln['qty'] ?? 0), (float) ($ln['amount'] ?? 0)]
                );
            }
            return $rid;
        });

        $variance = ReconciliationService::generate($reportId);

        // The official amount becomes a receivable owed by the hypermarket
        // (Accounts Receivable for the Accountant).
        $officialTotal = (float) array_sum(array_map(
            static fn ($l) => (float) ($l['amount'] ?? 0),
            $lines
        ));
        \App\Services\FinanceService::upsertSettlementFromReport(
            $this->companyId(),
            (int) $d['outlet_id'],
            $reportId,
            $d['period_start'],
            $d['period_end'],
            $officialTotal
        );

        Audit::log('reconcile_import', 'official_sales_reports', (string) $reportId);
        Response::ok(['report_id' => $reportId, 'variance' => $variance], 'Report imported & reconciled');
    }

    public function variance(Request $req): void
    {
        $sql = 'SELECT vr.*, p.sku, p.name AS product_name, o.name AS outlet_name
                FROM variance_reports vr
                LEFT JOIN products p ON p.id = vr.product_id
                JOIN outlets o ON o.id = vr.outlet_id
                WHERE vr.company_id = ?';
        $args = [$this->companyId()];
        if ($req->query('report_id')) {
            $sql .= ' AND vr.report_id = ?';
            $args[] = (int) $req->query('report_id');
        }
        if ($req->query('status')) {
            $sql .= ' AND vr.status = ?';
            $args[] = $req->query('status');
        }
        $sql .= ' ORDER BY ABS(vr.amount_variance) DESC';
        Response::ok(Database::all($sql, $args));
    }

    public function reports(Request $req): void
    {
        Response::ok(Database::all(
            'SELECT r.*, o.name AS outlet_name,
                    (SELECT COUNT(*) FROM variance_reports v WHERE v.report_id=r.id) AS variance_lines
             FROM official_sales_reports r JOIN outlets o ON o.id = r.outlet_id
             WHERE r.company_id = ? ORDER BY r.id DESC',
            [$this->companyId()]
        ));
    }
}
