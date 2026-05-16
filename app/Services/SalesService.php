<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * QR sales recording with automatic stock deduction.
 *
 * Idempotent on client_uuid so the offline queue can safely retry without
 * creating duplicate sales (Business Rule: duplicate scan prevention).
 */
final class SalesService
{
    /**
     * @param array $p {
     *   company_id, outlet_id, kiosk_id?, user_id, payment_type,
     *   client_uuid?, device_id?, source?, sold_at?,
     *   items: [ {product_id, qty, unit_price?}, ... ]
     * }
     */
    public static function record(array $p): array
    {
        // Idempotency: same offline UUID -> return the original sale.
        if (!empty($p['client_uuid'])) {
            $existing = Database::first(
                'SELECT * FROM sales_transactions WHERE client_uuid = ? LIMIT 1',
                [$p['client_uuid']]
            );
            if ($existing) {
                return ['duplicate' => true, 'transaction' => $existing];
            }
        }

        try {
            return self::insertSale($p);
        } catch (\PDOException $e) {
            // Concurrent retry with the same client_uuid raced past the check
            // above and hit uq_client_uuid -> treat as the idempotent dup.
            if ($e->getCode() === '23000' && !empty($p['client_uuid'])) {
                $existing = Database::first(
                    'SELECT * FROM sales_transactions WHERE client_uuid = ? LIMIT 1',
                    [$p['client_uuid']]
                );
                if ($existing) {
                    return ['duplicate' => true, 'transaction' => $existing];
                }
            }
            throw $e;
        }
    }

    private static function insertSale(array $p): array
    {
        return Database::transaction(function () use ($p) {
            $shiftId = self::activeShiftId((int) $p['user_id']);
            $txnRef = next_ref('SAL', 'sales_transactions', 'txn_ref');

            $txnId = Database::insert(
                'INSERT INTO sales_transactions
                  (company_id, outlet_id, kiosk_id, shift_id, user_id, txn_ref, client_uuid,
                   payment_type, source, device_id, sold_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $p['company_id'], $p['outlet_id'], $p['kiosk_id'] ?? null, $shiftId,
                    $p['user_id'], $txnRef, $p['client_uuid'] ?? null,
                    $p['payment_type'], $p['source'] ?? 'qr_scan',
                    $p['device_id'] ?? null, $p['sold_at'] ?? now(),
                ]
            );

            $totalQty = 0.0;
            $totalAmt = 0.0;
            $subTotal = 0.0;
            $taxTotal = 0.0;
            $discTotal = 0.0;
            $taxInclusive = self::setting((int) $p['company_id'], 'tax_inclusive', '1') !== '0';
            $locType = !empty($p['kiosk_id']) ? 'kiosk' : 'outlet';
            $locId = !empty($p['kiosk_id']) ? (int) $p['kiosk_id'] : (int) $p['outlet_id'];

            foreach ($p['items'] as $it) {
                $product = Database::first('SELECT * FROM products WHERE id = ?', [$it['product_id']]);
                if (!$product) {
                    throw new \RuntimeException('Unknown product ' . $it['product_id']);
                }
                $qtyVal = (float) $it['qty'];
                $price = isset($it['unit_price'])
                    ? (float) $it['unit_price']
                    : self::resolvePrice((int) $product['id'], (int) $p['outlet_id'], (float) $product['sell_price']);

                // SST: resolve the product's tax code (NULL = no tax).
                $taxRate = 0.0;
                $taxCodeId = $product['tax_code_id'] ?? null;
                if ($taxCodeId) {
                    $taxRate = (float) Database::scalar(
                        'SELECT rate FROM tax_codes WHERE id=? AND is_active=1',
                        [$taxCodeId]
                    );
                }

                // Optional line discount: percent or fixed amount off gross.
                $grossBefore = round($price * $qtyVal, 2);
                $disc = 0.0;
                if (isset($it['discount_pct'])) {
                    $disc = round($grossBefore * (float) $it['discount_pct'] / 100, 2);
                } elseif (isset($it['discount_amount'])) {
                    $disc = round((float) $it['discount_amount'], 2);
                }
                $disc = max(0.0, min($disc, $grossBefore));
                $gross = round($grossBefore - $disc, 2);

                if ($taxRate > 0 && $taxInclusive) {
                    // Discounted price already includes SST: split it out.
                    $netLine = round($gross / (1 + $taxRate / 100), 2);
                    $line    = $gross;
                    $taxLine = round($line - $netLine, 2);
                } elseif ($taxRate > 0) {
                    // Tax added on top of the discounted price.
                    $netLine = $gross;
                    $taxLine = round($netLine * $taxRate / 100, 2);
                    $line    = round($netLine + $taxLine, 2);
                } else {
                    $line = $gross;
                    $netLine = $gross;
                    $taxLine = 0.0;
                }

                $totalQty += $qtyVal;
                $totalAmt += $line;
                $subTotal += $netLine;
                $taxTotal += $taxLine;
                $discTotal += $disc;

                Database::insert(
                    'INSERT INTO sales_items
                      (transaction_id, product_id, tax_code_id, qr_label_id, qty,
                       unit_price, line_amount, discount_amount, tax_rate, tax_amount)
                     VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$txnId, $product['id'], $taxCodeId, $it['qr_label_id'] ?? null,
                     $qtyVal, $price, $line, $disc, $taxRate, $taxLine]
                );

                // Deduct the finished product from the selling location.
                StockService::recordMovement([
                    'company_id'  => $p['company_id'],
                    'product_id'  => $product['id'],
                    'qr_label_id' => $it['qr_label_id'] ?? null,
                    'movement_type' => 'sale',
                    'qty'         => $qtyVal,
                    'uom'         => $product['uom'],
                    'from_type'   => $locType,
                    'from_id'     => $locId,
                    'to_type'     => 'customer',
                    'ref_table'   => 'sales_transactions',
                    'ref_id'      => $txnId,
                    'unit_cost'   => StockService::effectiveCost($product),
                    'user_id'     => $p['user_id'],
                    'device_id'   => $p['device_id'] ?? null,
                ]);

                // Made-to-order: if a recipe exists, consume ingredients too.
                self::consumeRecipe(
                    (int) $product['id'], $qtyVal, (int) $p['company_id'],
                    $locType, $locId, $txnId, (int) $p['user_id']
                );
            }

            Database::run(
                'UPDATE sales_transactions
                 SET total_qty = ?, total_amount = ?, subtotal_amount = ?,
                     tax_amount = ?, discount_amount = ?
                 WHERE id = ?',
                [$totalQty, $totalAmt, round($subTotal, 2), round($taxTotal, 2),
                 round($discTotal, 2), $txnId]
            );

            return [
                'duplicate'   => false,
                'transaction' => Database::first('SELECT * FROM sales_transactions WHERE id = ?', [$txnId]),
            ];
        });
    }

    private static function consumeRecipe(
        int $productId, float $multiplier, int $company,
        string $locType, int $locId, int $txnId, int $userId
    ): void {
        $recipe = Database::first(
            'SELECT * FROM recipes WHERE product_id = ? AND is_active = 1',
            [$productId]
        );
        if (!$recipe) {
            return;
        }
        $items = Database::all('SELECT * FROM recipe_items WHERE recipe_id = ?', [$recipe['id']]);
        $factor = $multiplier / max(0.0001, (float) $recipe['yield_qty']);
        foreach ($items as $ri) {
            $need = (float) $ri['qty'] * $factor;
            // Made-to-order ON SITE only: consume an ingredient when the
            // selling location actually stocks it. If it doesn't, the item is
            // a pre-made finished good produced upstream (central kitchen) —
            // its ingredients were already consumed during production, so we
            // must not double count or drive the kiosk negative.
            if (StockService::balance((int) $ri['ingredient_id'], $locType, $locId) + 1e-6 < $need) {
                continue;
            }
            StockService::recordMovement([
                'company_id'    => $company,
                'product_id'    => (int) $ri['ingredient_id'],
                'movement_type' => 'consume',
                'qty'           => $need,
                'uom'           => $ri['uom'],
                'from_type'     => $locType,
                'from_id'       => $locId,
                'to_type'       => 'none',
                'unit_cost'     => StockService::effectiveCostById((int) $ri['ingredient_id']),
                'ref_table'     => 'sales_transactions',
                'ref_id'        => $txnId,
                'user_id'       => $userId,
                'notes'         => 'Auto ingredient consumption',
            ]);
        }
    }

    private static function resolvePrice(int $productId, int $outletId, float $default): float
    {
        $p = Database::scalar(
            'SELECT sell_price FROM product_outlet_prices WHERE product_id=? AND outlet_id=?',
            [$productId, $outletId]
        );
        return $p !== false && $p !== null ? (float) $p : $default;
    }

    public static function activeShiftId(int $userId): ?int
    {
        $s = Database::scalar(
            "SELECT id FROM shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        return $s ? (int) $s : null;
    }

    private static function setting(int $company, string $key, string $default): string
    {
        $v = Database::scalar(
            'SELECT svalue FROM settings WHERE skey=? AND (company_id=? OR company_id IS NULL)
             ORDER BY company_id IS NULL LIMIT 1',
            [$key, $company]
        );
        return $v !== false && $v !== null ? (string) $v : $default;
    }

    private static function txnLocation(array $txn): array
    {
        return !empty($txn['kiosk_id'])
            ? ['kiosk', (int) $txn['kiosk_id']]
            : ['outlet', (int) $txn['outlet_id']];
    }

    /**
     * Void an entire mistaken sale: fully reverse every stock movement it made
     * (finished good AND any on-site recipe consumption) and mark it voided.
     */
    public static function void(int $company, int $txnId, int $userId, ?string $reason): array
    {
        $txn = Database::first(
            'SELECT * FROM sales_transactions WHERE id=? AND company_id=?',
            [$txnId, $company]
        );
        if (!$txn) {
            throw new \RuntimeException('Sale not found');
        }
        if ($txn['status'] !== 'completed') {
            throw new \RuntimeException('Only a completed sale can be voided (status: ' . $txn['status'] . ')');
        }

        return Database::transaction(function () use ($txn, $txnId, $company, $userId, $reason) {
            $moves = Database::all(
                "SELECT * FROM stock_movements
                 WHERE ref_table='sales_transactions' AND ref_id=?
                   AND movement_type IN ('sale','consume')",
                [$txnId]
            );
            foreach ($moves as $m) {
                // Put the quantity back where it was taken from.
                StockService::recordMovement([
                    'company_id'    => $company,
                    'product_id'    => (int) $m['product_id'],
                    'batch_id'      => $m['batch_id'],
                    'movement_type' => 'void',
                    'qty'           => (float) $m['qty'],
                    'from_type'     => 'none',
                    'to_type'       => $m['from_loc_type'],
                    'to_id'         => $m['from_loc_id'],
                    'unit_cost'     => (float) $m['unit_cost'],
                    'ref_table'     => 'sales_transactions',
                    'ref_id'        => $txnId,
                    'user_id'       => $userId,
                    'notes'         => 'Void: ' . ($reason ?? ''),
                ]);
            }
            Database::run(
                "UPDATE sales_transactions
                 SET status='voided', voided_at=NOW(), voided_by=?, void_reason=?
                 WHERE id=?",
                [$userId, $reason, $txnId]
            );
            return Database::first('SELECT * FROM sales_transactions WHERE id=?', [$txnId]);
        });
    }

    /**
     * Refund returned goods (full or partial by line). The finished product
     * goes back to the selling location; ingredient consumption is NOT
     * reversed (the made item is returned whole, not decomposed).
     *
     * @param array $items [{sales_item_id, qty}, ...]
     */
    public static function refund(
        int $company,
        int $txnId,
        array $items,
        ?string $reason,
        string $method,
        int $userId
    ): array {
        $txn = Database::first(
            'SELECT * FROM sales_transactions WHERE id=? AND company_id=?',
            [$txnId, $company]
        );
        if (!$txn) {
            throw new \RuntimeException('Sale not found');
        }
        if (!in_array($txn['status'], ['completed', 'partially_refunded'], true)) {
            throw new \RuntimeException('Cannot refund a ' . $txn['status'] . ' sale');
        }
        if (!$items) {
            throw new \RuntimeException('Nothing to refund');
        }
        [$locType, $locId] = self::txnLocation($txn);

        return Database::transaction(function () use ($txn, $txnId, $company, $items, $reason, $method, $userId, $locType, $locId) {
            $refundId = Database::insert(
                'INSERT INTO sales_refunds
                   (company_id, transaction_id, refund_ref, reason, refund_method, user_id)
                 VALUES (?,?,?,?,?,?)',
                [$company, $txnId, next_ref('REF', 'sales_refunds', 'refund_ref'),
                 $reason, $method, $userId]
            );

            $refAmt = 0.0;
            $refTax = 0.0;
            foreach ($items as $line) {
                $si = Database::first(
                    'SELECT * FROM sales_items WHERE id=? AND transaction_id=?',
                    [$line['sales_item_id'] ?? 0, $txnId]
                );
                if (!$si) {
                    throw new \RuntimeException('Invalid sales item for this sale');
                }
                $soldQty = (float) $si['qty'];
                $already = (float) Database::scalar(
                    'SELECT COALESCE(SUM(qty),0) FROM sales_refund_items WHERE sales_item_id=?',
                    [$si['id']]
                );
                $rQty = (float) ($line['qty'] ?? 0);
                if ($rQty <= 0 || $rQty + $already > $soldQty + 1e-6) {
                    throw new \RuntimeException(
                        'Refund qty exceeds remaining for item #' . $si['id']
                        . ' (sold ' . qty($soldQty) . ', already refunded ' . qty($already) . ')'
                    );
                }
                $unit   = $soldQty > 0 ? (float) $si['line_amount'] / $soldQty : 0.0;
                $unitTax = $soldQty > 0 ? (float) $si['tax_amount'] / $soldQty : 0.0;
                $amt = round($unit * $rQty, 2);
                $tax = round($unitTax * $rQty, 2);
                $refAmt += $amt;
                $refTax += $tax;

                Database::insert(
                    'INSERT INTO sales_refund_items
                       (refund_id, sales_item_id, product_id, qty, amount, tax_amount)
                     VALUES (?,?,?,?,?,?)',
                    [$refundId, $si['id'], $si['product_id'], $rQty, $amt, $tax]
                );
                // Returned finished goods go back to the selling location.
                StockService::recordMovement([
                    'company_id'    => $company,
                    'product_id'    => (int) $si['product_id'],
                    'movement_type' => 'refund',
                    'qty'           => $rQty,
                    'from_type'     => 'none',
                    'to_type'       => $locType,
                    'to_id'         => $locId,
                    'unit_cost'     => StockService::effectiveCostById((int) $si['product_id']),
                    'ref_table'     => 'sales_refunds',
                    'ref_id'        => $refundId,
                    'user_id'       => $userId,
                    'notes'         => 'Refund: ' . ($reason ?? ''),
                ]);
            }

            Database::run(
                'UPDATE sales_refunds SET total_amount=?, tax_amount=? WHERE id=?',
                [round($refAmt, 2), round($refTax, 2), $refundId]
            );

            // Fully refunded when every sold unit has been returned.
            $soldUnits = (float) Database::scalar(
                'SELECT COALESCE(SUM(qty),0) FROM sales_items WHERE transaction_id=?',
                [$txnId]
            );
            $refundedUnits = (float) Database::scalar(
                'SELECT COALESCE(SUM(sri.qty),0)
                 FROM sales_refund_items sri
                 JOIN sales_refunds sr ON sr.id = sri.refund_id
                 WHERE sr.transaction_id=?',
                [$txnId]
            );
            $status = $refundedUnits + 1e-6 >= $soldUnits ? 'refunded' : 'partially_refunded';
            Database::run('UPDATE sales_transactions SET status=? WHERE id=?', [$status, $txnId]);

            return [
                'refund' => Database::first('SELECT * FROM sales_refunds WHERE id=?', [$refundId]),
                'transaction_status' => $status,
            ];
        });
    }
}
