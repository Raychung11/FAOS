<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Builds, stores and submits LHDN MyInvois e-invoices.
 *
 *  - standard      : one e-invoice for a specific sale (B2B / buyer requested)
 *  - consolidated  : monthly B2C roll-up of receipts not individually invoiced
 *                    (the normal kiosk/restaurant workflow)
 */
final class EInvoiceService
{
    private static function company(int $id): array
    {
        return Database::first('SELECT * FROM companies WHERE id=?', [$id]) ?: [];
    }

    private static function classification(int $company): string
    {
        $v = Database::scalar(
            'SELECT svalue FROM settings WHERE skey=? AND (company_id=? OR company_id IS NULL)
             ORDER BY company_id IS NULL LIMIT 1',
            ['einvoice_classification', $company]
        );
        return $v ?: '022';
    }

    /** MyInvois UBL-2.1 JSON document (key required fields). */
    private static function buildDocument(array $co, array $einv, array $lines): array
    {
        $supplier = [
            'TIN'        => $co['tin'] ?? '',
            'SST'        => $co['sst_no'] ?? 'NA',
            'MSIC'       => $co['msic_code'] ?? '00000',
            'Name'       => $co['name'] ?? '',
            'Currency'   => $co['currency'] ?? 'MYR',
        ];
        $invLines = [];
        $i = 1;
        foreach ($lines as $l) {
            $invLines[] = [
                'ID' => [['_' => (string) $i++]],
                'Item' => [[
                    'Description' => [['_' => $l['description']]],
                    'CommodityClassification' => [[
                        'ItemClassificationCode' => [[
                            '_' => $l['classification'], 'listID' => 'CLASS',
                        ]],
                    ]],
                ]],
                'InvoicedQuantity' => [['_' => (float) $l['qty']]],
                'LineExtensionAmount' => [['_' => round((float) $l['line_amount'], 2), 'currencyID' => 'MYR']],
                'TaxTotal' => [[
                    'TaxAmount' => [['_' => round((float) $l['tax_amount'], 2), 'currencyID' => 'MYR']],
                    'TaxSubtotal' => [[
                        'TaxableAmount' => [['_' => round((float) $l['line_amount'] - (float) $l['tax_amount'], 2), 'currencyID' => 'MYR']],
                        'TaxAmount' => [['_' => round((float) $l['tax_amount'], 2), 'currencyID' => 'MYR']],
                        'Percent' => [['_' => (float) $l['tax_rate']]],
                    ]],
                ]],
            ];
        }

        return [
            '_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            '_A' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            '_B' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'Invoice' => [[
                'ID' => [['_' => $einv['einvoice_no']]],
                'IssueDate' => [['_' => date('Y-m-d')]],
                'IssueTime' => [['_' => gmdate('H:i:s') . 'Z']],
                'InvoiceTypeCode' => [[
                    '_' => $einv['doc_type'] === 'credit_note' ? '02' : '01',
                    'listVersionID' => '1.0',
                ]],
                'DocumentCurrencyCode' => [['_' => 'MYR']],
                'AccountingSupplierParty' => [['Party' => [[
                    'PartyLegalEntity' => [['RegistrationName' => [['_' => $supplier['Name']]]]],
                    'PartyTaxScheme' => [['CompanyID' => [['_' => $supplier['SST']]]]],
                    'PartyIdentification' => [
                        ['ID' => [['_' => $supplier['TIN'], 'schemeID' => 'TIN']]],
                        ['ID' => [['_' => $supplier['MSIC'], 'schemeID' => 'MSIC']]],
                    ],
                ]]]],
                'AccountingCustomerParty' => [['Party' => [[
                    'PartyLegalEntity' => [['RegistrationName' => [['_' => $einv['buyer_name']]]]],
                    'PartyIdentification' => [
                        ['ID' => [['_' => $einv['buyer_tin'] ?: 'EI00000000010', 'schemeID' => 'TIN']]],
                    ],
                ]]]],
                'InvoiceLine' => $invLines,
                'TaxTotal' => [[
                    'TaxAmount' => [['_' => round((float) $einv['tax_total'], 2), 'currencyID' => 'MYR']],
                ]],
                'LegalMonetaryTotal' => [[
                    'TaxExclusiveAmount' => [['_' => round((float) $einv['subtotal'], 2), 'currencyID' => 'MYR']],
                    'TaxInclusiveAmount' => [['_' => round((float) $einv['total'], 2), 'currencyID' => 'MYR']],
                    'PayableAmount' => [['_' => round((float) $einv['total'], 2), 'currencyID' => 'MYR']],
                ]],
            ]],
        ];
    }

    private static function persist(array $einv, array $lines): int
    {
        return Database::transaction(function () use ($einv, $lines) {
            $id = Database::insert(
                'INSERT INTO einvoices
                   (company_id, einvoice_no, doc_type, transaction_id, outlet_id,
                    period_start, period_end, buyer_name, buyer_tin, buyer_reg_no,
                    currency, subtotal, tax_total, total, status, payload_json, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $einv['company_id'], $einv['einvoice_no'], $einv['doc_type'],
                    $einv['transaction_id'] ?? null, $einv['outlet_id'] ?? null,
                    $einv['period_start'] ?? null, $einv['period_end'] ?? null,
                    $einv['buyer_name'], $einv['buyer_tin'] ?? null, $einv['buyer_reg_no'] ?? null,
                    'MYR', $einv['subtotal'], $einv['tax_total'], $einv['total'],
                    'draft', $einv['payload_json'], $einv['created_by'] ?? null,
                ]
            );
            foreach ($lines as $l) {
                Database::insert(
                    'INSERT INTO einvoice_lines
                       (einvoice_id, product_id, classification, description, qty,
                        unit_price, line_amount, tax_rate, tax_amount)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$id, $l['product_id'] ?? null, $l['classification'], $l['description'],
                     $l['qty'], $l['unit_price'], $l['line_amount'], $l['tax_rate'], $l['tax_amount']]
                );
            }
            return $id;
        });
    }

    public static function generateForTransaction(int $company, int $txnId, array $buyer = [], ?int $userId = null): array
    {
        $txn = Database::first(
            'SELECT * FROM sales_transactions WHERE id=? AND company_id=?',
            [$txnId, $company]
        );
        if (!$txn) {
            throw new \RuntimeException('Sales transaction not found');
        }
        if ($txn['status'] === 'voided') {
            throw new \RuntimeException('Cannot e-invoice a voided sale');
        }
        if (Database::scalar('SELECT id FROM einvoices WHERE transaction_id=?', [$txnId])) {
            throw new \RuntimeException('An e-invoice already exists for this sale');
        }
        $cls = self::classification($company);
        $items = Database::all(
            'SELECT si.*, p.name AS product_name FROM sales_items si
             JOIN products p ON p.id=si.product_id WHERE si.transaction_id=?',
            [$txnId]
        );
        $lines = [];
        $sub = 0.0;
        $tax = 0.0;
        foreach ($items as $it) {
            $taxAmt = (float) $it['tax_amount'];
            $line = (float) $it['line_amount'];
            $sub += $line - $taxAmt;
            $tax += $taxAmt;
            $lines[] = [
                'product_id' => (int) $it['product_id'],
                'classification' => $cls,
                'description' => $it['product_name'],
                'qty' => (float) $it['qty'],
                'unit_price' => (float) $it['unit_price'],
                'line_amount' => $line,
                'tax_rate' => (float) $it['tax_rate'],
                'tax_amount' => $taxAmt,
            ];
        }
        $einv = [
            'company_id' => $company,
            'einvoice_no' => next_ref('EI', 'einvoices', 'einvoice_no'),
            'doc_type' => 'standard',
            'transaction_id' => $txnId,
            'outlet_id' => (int) $txn['outlet_id'],
            'buyer_name' => $buyer['name'] ?? 'General Public',
            'buyer_tin' => $buyer['tin'] ?? null,
            'buyer_reg_no' => $buyer['reg_no'] ?? null,
            'subtotal' => round($sub, 2),
            'tax_total' => round($tax, 2),
            'total' => round((float) $txn['total_amount'], 2),
            'created_by' => $userId,
        ];
        $einv['payload_json'] = json_encode(self::buildDocument(self::company($company), $einv, $lines));
        $id = self::persist($einv, $lines);
        return self::submitAndFinalize($id);
    }

    public static function generateConsolidated(
        int $company,
        int $outletId,
        string $periodStart,
        string $periodEnd,
        ?int $userId = null
    ): array {
        // Block ANY overlapping consolidated period for this outlet, otherwise
        // the same receipts get reported to LHDN twice (consolidated invoices
        // don't tag the receipts they cover).
        $overlap = Database::first(
            "SELECT einvoice_no, period_start, period_end FROM einvoices
             WHERE doc_type='consolidated' AND outlet_id=? AND status<>'cancelled'
               AND period_start <= ? AND period_end >= ?
             LIMIT 1",
            [$outletId, $periodEnd, $periodStart]
        );
        if ($overlap) {
            throw new \RuntimeException(sprintf(
                'Period overlaps existing consolidated %s (%s to %s)',
                $overlap['einvoice_no'], $overlap['period_start'], $overlap['period_end']
            ));
        }
        // Receipts not individually e-invoiced (B2C consolidation).
        $rows = Database::all(
            "SELECT DATE(st.sold_at) d,
                    SUM(st.total_amount) total,
                    SUM(st.subtotal_amount) sub,
                    SUM(st.tax_amount) tax,
                    COUNT(*) cnt
             FROM sales_transactions st
             LEFT JOIN einvoices e ON e.transaction_id = st.id
             WHERE st.company_id=? AND st.outlet_id=?
               AND DATE(st.sold_at) BETWEEN ? AND ?
               AND e.id IS NULL AND st.status <> 'voided'
             GROUP BY DATE(st.sold_at) ORDER BY d",
            [$company, $outletId, $periodStart, $periodEnd]
        );
        if (!$rows) {
            throw new \RuntimeException('No un-invoiced receipts in this period');
        }
        $cls = self::classification($company);
        $lines = [];
        $sub = 0.0;
        $tax = 0.0;
        $tot = 0.0;
        foreach ($rows as $r) {
            $lsub = (float) $r['sub'];
            $ltax = (float) $r['tax'];
            $ltot = (float) $r['total'];
            // Older receipts may pre-date SST tracking: treat total as net.
            if ($lsub == 0.0 && $ltax == 0.0) {
                $lsub = $ltot;
            }
            $sub += $lsub;
            $tax += $ltax;
            $tot += $ltot;
            $lines[] = [
                'product_id' => null,
                'classification' => $cls,
                'description' => 'Consolidated receipts ' . $r['d'] . ' (' . $r['cnt'] . ' txns)',
                'qty' => 1,
                'unit_price' => round($ltot, 2),
                'line_amount' => round($ltot, 2),
                'tax_rate' => 0.0,
                'tax_amount' => round($ltax, 2),
            ];
        }
        $einv = [
            'company_id' => $company,
            'einvoice_no' => next_ref('EIC', 'einvoices', 'einvoice_no'),
            'doc_type' => 'consolidated',
            'transaction_id' => null,
            'outlet_id' => $outletId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'buyer_name' => 'General Public',
            'buyer_tin' => 'EI00000000010',
            'subtotal' => round($sub, 2),
            'tax_total' => round($tax, 2),
            'total' => round($tot, 2),
            'created_by' => $userId,
        ];
        $einv['payload_json'] = json_encode(self::buildDocument(self::company($company), $einv, $lines));
        $id = self::persist($einv, $lines);
        return self::submitAndFinalize($id);
    }

    /** Submit to MyInvois (graceful when unconfigured) and finalise status. */
    public static function submitAndFinalize(int $einvoiceId): array
    {
        $e = Database::first('SELECT * FROM einvoices WHERE id=?', [$einvoiceId]);
        $doc = json_decode($e['payload_json'], true) ?: [];
        $res = MyInvoisClient::submit($doc);

        if ($res['ok']) {
            $url = MyInvoisClient::validationUrl($res['uuid'] ?? null, $res['long_id'] ?? null);
            Database::run(
                "UPDATE einvoices SET status='valid', irbm_uuid=?, irbm_long_id=?,
                        validation_url=?, response_json=?, submitted_at=NOW(), validated_at=NOW()
                 WHERE id=?",
                [$res['uuid'] ?? null, $res['long_id'] ?? null, $url,
                 json_encode($res['raw']), $einvoiceId]
            );
        } else {
            $status = $res['status'] ?? 'pending_submission';
            Database::run(
                'UPDATE einvoices SET status=?, response_json=?, reject_reason=? WHERE id=?',
                [$status, json_encode($res['raw'] ?? []),
                 $status === 'rejected' ? json_encode($res['raw'] ?? []) : null, $einvoiceId]
            );
        }
        return Database::first('SELECT * FROM einvoices WHERE id=?', [$einvoiceId]);
    }
}
