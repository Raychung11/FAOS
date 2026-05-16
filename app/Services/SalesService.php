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

                if ($taxRate > 0 && $taxInclusive) {
                    // Menu price already includes SST: split it out.
                    $netUnit  = $price / (1 + $taxRate / 100);
                    $line     = round($price * $qtyVal, 2);          // gross, unchanged
                    $netLine  = round($netUnit * $qtyVal, 2);
                    $taxLine  = round($line - $netLine, 2);
                } elseif ($taxRate > 0) {
                    // Tax added on top of the price.
                    $netLine  = round($price * $qtyVal, 2);
                    $taxLine  = round($netLine * $taxRate / 100, 2);
                    $line     = round($netLine + $taxLine, 2);       // gross paid
                } else {
                    $line = round($price * $qtyVal, 2);
                    $netLine = $line;
                    $taxLine = 0.0;
                }

                $totalQty += $qtyVal;
                $totalAmt += $line;
                $subTotal += $netLine;
                $taxTotal += $taxLine;

                Database::insert(
                    'INSERT INTO sales_items
                      (transaction_id, product_id, tax_code_id, qr_label_id, qty,
                       unit_price, line_amount, tax_rate, tax_amount)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$txnId, $product['id'], $taxCodeId, $it['qr_label_id'] ?? null,
                     $qtyVal, $price, $line, $taxRate, $taxLine]
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
                 SET total_qty = ?, total_amount = ?, subtotal_amount = ?, tax_amount = ?
                 WHERE id = ?',
                [$totalQty, $totalAmt, round($subTotal, 2), round($taxTotal, 2), $txnId]
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
}
