<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\FinanceService;
use App\Services\PeriodService;

final class FinanceController extends Controller
{
    private function range(Request $req): array
    {
        $to = $req->query('to') ?: date('Y-m-d');
        $from = $req->query('from') ?: date('Y-m-01');
        return [$from, $to];
    }

    public function summary(Request $req): void
    {
        [$from, $to] = $this->range($req);
        Response::ok(FinanceService::pnl($this->companyId(), $from, $to));
    }

    /** SST output-tax summary for the period (SST-02 return prep). */
    public function sstSummary(Request $req): void
    {
        [$from, $to] = $this->range($req);
        $rows = Database::all(
            "SELECT COALESCE(tc.code,'NA') tax_code,
                    COALESCE(tc.name,'No tax') tax_name,
                    COALESCE(tc.rate,0) rate,
                    ROUND(SUM(si.line_amount - si.tax_amount),2) taxable_sales,
                    ROUND(SUM(si.tax_amount),2) output_tax
             FROM sales_items si
             JOIN sales_transactions st ON st.id = si.transaction_id
             LEFT JOIN tax_codes tc ON tc.id = si.tax_code_id
             WHERE st.company_id=? AND st.sold_at BETWEEN ? AND ?
               AND st.status <> 'voided'
             GROUP BY tc.id, tc.code, tc.name, tc.rate
             ORDER BY output_tax DESC",
            [$this->companyId(), $from . ' 00:00:00', $to . ' 23:59:59']
        );
        // Refunds reduce output tax payable.
        $refundTax = (float) Database::scalar(
            'SELECT COALESCE(SUM(tax_amount),0) FROM sales_refunds
             WHERE company_id=? AND created_at BETWEEN ? AND ?',
            [$this->companyId(), $from . ' 00:00:00', $to . ' 23:59:59']
        );
        $grossTax = round(array_sum(array_column($rows, 'output_tax')), 2);
        Response::ok([
            'period'          => ['from' => $from, 'to' => $to],
            'rows'            => $rows,
            'total_taxable'   => round(array_sum(array_column($rows, 'taxable_sales')), 2),
            'gross_output_tax' => $grossTax,
            'refund_tax'      => round($refundTax, 2),
            'total_output_tax' => round($grossTax - $refundTax, 2),
        ]);
    }

    public function inventoryValuation(Request $req): void
    {
        Response::ok(FinanceService::inventoryValuation($this->companyId()));
    }

    // ---- Accounting period close / lock -----------------------------------

    public function periods(Request $req): void
    {
        Response::ok(PeriodService::list($this->companyId()));
    }

    public function closePeriod(Request $req): void
    {
        $d = $this->validate($req, [
            'period_start' => 'required|date',
            'period_end'   => 'required|date',
        ]);
        try {
            $p = PeriodService::close(
                $this->companyId(),
                $d['period_start'],
                $d['period_end'],
                Auth::id(),
                $req->input('note')
            );
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('period_close', 'accounting_periods', (string) $p['id'], null, $d);
        Response::ok($p, 'Period closed');
    }

    public function reopenPeriod(Request $req, array $p): void
    {
        try {
            $row = PeriodService::reopen($this->companyId(), (int) $p['id'], Auth::id());
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('period_reopen', 'accounting_periods', $p['id']);
        Response::ok($row, 'Period reopened');
    }

    // ---- Accounts Payable --------------------------------------------------

    public function payables(Request $req): void
    {
        Response::ok(FinanceService::payables($this->companyId(), $req->query('status')));
    }

    public function createInvoice(Request $req): void
    {
        $d = $this->validate($req, [
            'supplier_id'  => 'required|int',
            'invoice_no'   => 'required|string|max:64',
            'invoice_date' => 'required|date',
            'total_amount' => 'required|numeric|min:0',
        ]);
        $amount = (float) ($req->input('amount') ?? $d['total_amount']);
        $tax = (float) ($req->input('tax_amount') ?? 0);
        $id = Database::insert(
            'INSERT INTO supplier_invoices
               (company_id, supplier_id, po_id, grn_id, invoice_no, invoice_date, due_date,
                amount, tax_amount, total_amount, note, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $this->companyId(), $d['supplier_id'],
                $req->input('po_id'), $req->input('grn_id'),
                $d['invoice_no'], $d['invoice_date'], $req->input('due_date'),
                $amount, $tax, $d['total_amount'],
                $req->input('note'), Auth::id(),
            ]
        );
        Audit::log('finance_invoice_create', 'supplier_invoices', (string) $id, null, $d);
        Response::ok(['id' => $id], 'Supplier invoice recorded');
    }

    public function payInvoice(Request $req, array $p): void
    {
        $invoiceId = (int) $p['id'];
        $inv = Database::first(
            'SELECT * FROM supplier_invoices WHERE id = ? AND company_id = ?',
            [$invoiceId, $this->companyId()]
        );
        if (!$inv) {
            Response::fail('Invoice not found', 404);
        }
        $d = $this->validate($req, ['amount' => 'required|numeric|min:0.01']);
        $amount = (float) $d['amount'];
        $outstanding = (float) $inv['total_amount'] - (float) $inv['paid_amount'];
        if ($amount > $outstanding + 0.001) {
            Response::fail("Payment exceeds outstanding balance ($outstanding)", 422);
        }
        Database::transaction(function () use ($invoiceId, $amount, $req) {
            Database::insert(
                'INSERT INTO supplier_payments
                   (company_id, supplier_invoice_id, amount, method, reference, user_id)
                 VALUES (?,?,?,?,?,?)',
                [
                    $this->companyId(), $invoiceId, $amount,
                    $req->input('method', 'bank'), $req->input('reference'), Auth::id(),
                ]
            );
            FinanceService::applyInvoicePayment($invoiceId, $amount);
        });
        Audit::log('finance_invoice_pay', 'supplier_invoices', (string) $invoiceId, null, ['amount' => $amount]);
        Response::ok(null, 'Payment recorded');
    }

    // ---- Accounts Receivable ----------------------------------------------

    public function receivables(Request $req): void
    {
        Response::ok(FinanceService::receivables($this->companyId(), $req->query('status')));
    }

    public function receiveSettlement(Request $req, array $p): void
    {
        $sid = (int) $p['id'];
        $s = Database::first(
            'SELECT * FROM ar_settlements WHERE id = ? AND company_id = ?',
            [$sid, $this->companyId()]
        );
        if (!$s) {
            Response::fail('Settlement not found', 404);
        }
        $d = $this->validate($req, ['amount' => 'required|numeric|min:0.01']);
        $amount = (float) $d['amount'];
        Database::transaction(function () use ($sid, $amount, $req) {
            Database::insert(
                'INSERT INTO ar_receipts
                   (company_id, settlement_id, amount, method, reference, user_id)
                 VALUES (?,?,?,?,?,?)',
                [
                    $this->companyId(), $sid, $amount,
                    $req->input('method', 'bank'), $req->input('reference'), Auth::id(),
                ]
            );
            FinanceService::applyReceipt($sid, $amount);
        });
        Audit::log('finance_settlement_receive', 'ar_settlements', (string) $sid, null, ['amount' => $amount]);
        Response::ok(null, 'Receipt recorded');
    }

    // ---- Printable P&L statement (browser -> PDF) + CSV --------------------

    public function statement(Request $req): never
    {
        [$from, $to] = $this->range($req);
        $pnl = FinanceService::pnl($this->companyId(), $from, $to);

        if ($req->query('export') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="pnl_' . $from . '_' . $to . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account Group', 'Revenue', 'COGS', 'Gross Profit']);
            foreach ($pnl['by_account_group'] as $g) {
                fputcsv($out, [$g['account_group'], $g['revenue'], $g['cogs'], $g['gross_profit']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Revenue', $pnl['revenue']]);
            fputcsv($out, ['COGS', $pnl['cogs']]);
            fputcsv($out, ['Gross Profit', $pnl['gross_profit']]);
            fputcsv($out, ['Wastage Cost', $pnl['wastage_cost']]);
            fclose($out);
            exit;
        }

        $sym = \App\Core\Config::get('CURRENCY_SYMBOL', 'RM');
        $rows = '';
        foreach ($pnl['by_account_group'] as $g) {
            $rows .= '<tr><td>' . e($g['account_group']) . '</td>'
                . '<td class="r">' . $sym . ' ' . number_format((float) $g['revenue'], 2) . '</td>'
                . '<td class="r">' . $sym . ' ' . number_format((float) $g['cogs'], 2) . '</td>'
                . '<td class="r">' . $sym . ' ' . number_format((float) $g['gross_profit'], 2) . '</td></tr>';
        }
        $company = Database::scalar('SELECT name FROM companies WHERE id = ?', [$this->companyId()]);
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>P&L Statement</title>'
            . '<style>body{font-family:Arial,sans-serif;margin:32px;color:#1f2d3d}'
            . 'h1{font-size:20px;margin:0}h2{font-size:14px;color:#666;font-weight:400;margin:4px 0 20px}'
            . 'table{width:100%;border-collapse:collapse;margin-top:14px}'
            . 'th,td{padding:8px 10px;border-bottom:1px solid #ddd;text-align:left}'
            . 'th{background:#f4f6f9}.r{text-align:right}'
            . '.tot{font-weight:700;border-top:2px solid #333}'
            . '@media print{.np{display:none}}</style></head><body>'
            . '<div class="np" style="margin-bottom:14px"><button onclick="print()">Print / Save PDF</button></div>'
            . '<h1>' . e((string) $company) . '</h1>'
            . '<h2>Profit &amp; Loss (Operational) · ' . e($from) . ' to ' . e($to) . '</h2>'
            . '<table><thead><tr><th>Account Group</th><th class="r">Revenue</th>'
            . '<th class="r">COGS</th><th class="r">Gross Profit</th></tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot>'
            . '<tr class="tot"><td>Total Revenue</td><td class="r" colspan="3">' . $sym . ' ' . number_format($pnl['revenue'], 2) . '</td></tr>'
            . '<tr><td>Less: COGS</td><td class="r" colspan="3">' . $sym . ' ' . number_format($pnl['cogs'], 2) . '</td></tr>'
            . '<tr class="tot"><td>Gross Profit (' . $pnl['gross_margin_pct'] . '%)</td><td class="r" colspan="3">' . $sym . ' ' . number_format($pnl['gross_profit'], 2) . '</td></tr>'
            . '<tr><td>Memo: Wastage Cost</td><td class="r" colspan="3">' . $sym . ' ' . number_format($pnl['wastage_cost'], 2) . '</td></tr>'
            . '</tfoot></table></body></html>';
        Response::html($html);
    }
}
