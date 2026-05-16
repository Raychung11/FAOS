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
                $line = round($price * $qtyVal, 2);
                $totalQty += $qtyVal;
                $totalAmt += $line;

                Database::insert(
                    'INSERT INTO sales_items
                      (transaction_id, product_id, qr_label_id, qty, unit_price, line_amount)
                     VALUES (?,?,?,?,?,?)',
                    [$txnId, $product['id'], $it['qr_label_id'] ?? null, $qtyVal, $price, $line]
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
                    'unit_cost'   => $product['cost_price'],
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
                'UPDATE sales_transactions SET total_qty = ?, total_amount = ? WHERE id = ?',
                [$totalQty, $totalAmt, $txnId]
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
            StockService::recordMovement([
                'company_id'    => $company,
                'product_id'    => (int) $ri['ingredient_id'],
                'movement_type' => 'consume',
                'qty'           => (float) $ri['qty'] * $factor,
                'uom'           => $ri['uom'],
                'from_type'     => $locType,
                'from_id'       => $locId,
                'to_type'       => 'none',
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
}
