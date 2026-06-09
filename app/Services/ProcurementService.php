<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Inbound procurement: Supplier PO -> GRN (stock in + batch) -> auto AP invoice.
 */
final class ProcurementService
{
    public static function createPO(array $p): array
    {
        return Database::transaction(function () use ($p) {
            $ref = next_ref('PO', 'purchase_orders', 'po_ref');
            $total = 0.0;
            foreach ($p['items'] as $it) {
                $total += (float) $it['qty'] * (float) $it['unit_cost'];
            }
            $poId = Database::insert(
                'INSERT INTO purchase_orders
                   (company_id, supplier_id, warehouse_id, po_ref, status, expected_date,
                    total_amount, created_by)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $p['company_id'], $p['supplier_id'], $p['warehouse_id'] ?? null,
                    $ref, 'draft', $p['expected_date'] ?? null, round($total, 2),
                    $p['user_id'] ?? null,
                ]
            );
            foreach ($p['items'] as $it) {
                Database::insert(
                    'INSERT INTO purchase_order_items (po_id, product_id, qty, unit_cost)
                     VALUES (?,?,?,?)',
                    [$poId, $it['product_id'], $it['qty'], $it['unit_cost']]
                );
            }
            return ['id' => $poId, 'po_ref' => $ref, 'total' => round($total, 2)];
        });
    }

    public static function approvePO(int $poId, int $userId): void
    {
        Database::run(
            "UPDATE purchase_orders SET status='approved', approved_by=?
             WHERE id=? AND status='draft'",
            [$userId, $poId]
        );
    }

    private static function dueDateFromTerms(?string $terms, string $invoiceDate): ?string
    {
        if ($terms && preg_match('/(\d+)/', $terms, $m)) {
            return date('Y-m-d', strtotime($invoiceDate . ' +' . (int) $m[1] . ' days'));
        }
        return date('Y-m-d', strtotime($invoiceDate . ' +30 days'));
    }

    private static function getOrCreateBatch(
        int $company,
        int $productId,
        ?int $supplierId,
        ?string $batchNo,
        ?string $expiry,
        float $qty,
        float $unitCost
    ): int {
        $product = Database::first('SELECT shelf_life_days FROM products WHERE id=?', [$productId]);
        if (!$batchNo) {
            $batchNo = 'B' . date('Ymd') . '-' . $productId . '-' . substr(uniqid(), -4);
        }
        if ($expiry === null && $product && $product['shelf_life_days']) {
            $expiry = date('Y-m-d', strtotime('+' . (int) $product['shelf_life_days'] . ' days'));
        }
        $existing = Database::first(
            'SELECT id FROM product_batches WHERE product_id=? AND batch_no=?',
            [$productId, $batchNo]
        );
        if ($existing) {
            Database::run(
                'UPDATE product_batches SET received_qty = received_qty + ? WHERE id=?',
                [$qty, $existing['id']]
            );
            return (int) $existing['id'];
        }
        return Database::insert(
            'INSERT INTO product_batches
               (company_id, product_id, supplier_id, batch_no, mfg_date, expiry_date,
                received_qty, unit_cost)
             VALUES (?,?,?,?,?,?,?,?)',
            [$company, $productId, $supplierId, $batchNo, date('Y-m-d'), $expiry, $qty, $unitCost]
        );
    }

    /**
     * Receive goods. Creates GRN, batches, stock-in movements, updates the PO,
     * and auto-raises the supplier invoice (Accounts Payable).
     */
    public static function receiveGRN(array $p): array
    {
        return Database::transaction(function () use ($p) {
            $grnRef = next_ref('GRN', 'grn', 'grn_ref');
            $total = 0.0;
            foreach ($p['items'] as $it) {
                $total += (float) $it['qty'] * (float) $it['unit_cost'];
            }
            $grnId = Database::insert(
                'INSERT INTO grn
                   (company_id, po_id, supplier_id, warehouse_id, grn_ref, received_by,
                    invoice_no, total_amount)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $p['company_id'], $p['po_id'] ?? null, $p['supplier_id'],
                    $p['warehouse_id'], $grnRef, $p['user_id'] ?? null,
                    $p['invoice_no'] ?? null, round($total, 2),
                ]
            );

            foreach ($p['items'] as $it) {
                $batchId = self::getOrCreateBatch(
                    (int) $p['company_id'], (int) $it['product_id'],
                    (int) $p['supplier_id'],
                    $it['batch_no'] ?? null, $it['expiry_date'] ?? null,
                    (float) $it['qty'], (float) $it['unit_cost']
                );
                Database::insert(
                    'INSERT INTO grn_items (grn_id, product_id, batch_id, qty, unit_cost)
                     VALUES (?,?,?,?,?)',
                    [$grnId, $it['product_id'], $batchId, $it['qty'], $it['unit_cost']]
                );
                // AVCO must be recomputed BEFORE the receipt hits the balance.
                StockService::recomputeAvgCost(
                    (int) $it['product_id'], (float) $it['qty'], (float) $it['unit_cost']
                );
                StockService::recordMovement([
                    'company_id'    => $p['company_id'],
                    'product_id'    => (int) $it['product_id'],
                    'batch_id'      => $batchId,
                    'movement_type' => 'receive',
                    'qty'           => (float) $it['qty'],
                    'from_type'     => 'supplier',
                    'to_type'       => 'warehouse',
                    'to_id'         => (int) $p['warehouse_id'],
                    'unit_cost'     => (float) $it['unit_cost'],
                    'ref_table'     => 'grn',
                    'ref_id'        => $grnId,
                    'user_id'       => $p['user_id'] ?? null,
                ]);

                if (!empty($p['po_id'])) {
                    Database::run(
                        'UPDATE purchase_order_items
                         SET received_qty = received_qty + ?
                         WHERE po_id=? AND product_id=?',
                        [$it['qty'], $p['po_id'], $it['product_id']]
                    );
                }
            }

            if (!empty($p['po_id'])) {
                $open = (int) Database::scalar(
                    'SELECT COUNT(*) FROM purchase_order_items
                     WHERE po_id=? AND received_qty < qty',
                    [$p['po_id']]
                );
                Database::run(
                    'UPDATE purchase_orders SET status=? WHERE id=?',
                    [$open === 0 ? 'received' : 'partial', $p['po_id']]
                );
            }

            // Auto-raise the supplier invoice (Accounts Payable).
            $supplier = Database::first('SELECT payment_terms FROM suppliers WHERE id=?', [$p['supplier_id']]);
            $invDate = date('Y-m-d');
            $invoiceNo = $p['invoice_no'] ?: $grnRef;
            $invoiceId = Database::insert(
                'INSERT INTO supplier_invoices
                   (company_id, supplier_id, po_id, grn_id, invoice_no, invoice_date,
                    due_date, amount, tax_amount, total_amount, status, note, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $p['company_id'], $p['supplier_id'], $p['po_id'] ?? null, $grnId,
                    $invoiceNo, $invDate,
                    self::dueDateFromTerms($supplier['payment_terms'] ?? null, $invDate),
                    round($total, 2), 0, round($total, 2), 'unpaid',
                    'Auto-raised from ' . $grnRef, $p['user_id'] ?? null,
                ]
            );

            return [
                'grn_id'     => $grnId,
                'grn_ref'    => $grnRef,
                'invoice_id' => $invoiceId,
                'total'      => round($total, 2),
            ];
        });
    }
}
