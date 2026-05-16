<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Single source of truth for stock movement.
 * Every change to physical stock goes through recordMovement(), which
 * writes an immutable ledger row AND updates the denormalised balance.
 */
final class StockService
{
    /**
     * @param array $p {
     *   company_id, product_id, movement_type, qty (signed magnitude>0),
     *   from_type, from_id, to_type, to_id, qr_label_id?, batch_id?,
     *   ref_table?, ref_id?, unit_cost?, notes?, user_id?, device_id?
     * }
     */
    public static function recordMovement(array $p): int
    {
        return Database::transaction(function () use ($p) {
            $qty = (float) $p['qty'];
            $fromType = $p['from_type'] ?? 'none';
            $type = $p['movement_type'];

            // Negative-stock guard for controlled internal movements only.
            // Sales/consume are intentionally exempt (QR sales must work even
            // without complete stock).
            if ($fromType !== 'none' && $fromType !== 'supplier'
                && self::isBlockedNegative((int) $p['company_id'], $type)) {
                $onHand = self::balance((int) $p['product_id'], $fromType, (int) $p['from_id']);
                if ($onHand + 1e-6 < $qty) {
                    throw new \RuntimeException(sprintf(
                        'Movement "%s" would make %s #%d negative for product #%d (on hand %s, needed %s)',
                        $type, $fromType, (int) $p['from_id'], (int) $p['product_id'],
                        qty($onHand), qty($qty)
                    ));
                }
            }

            $id = Database::insert(
                'INSERT INTO stock_movements
                  (company_id, qr_label_id, product_id, batch_id, movement_type, qty, uom,
                   from_loc_type, from_loc_id, to_loc_type, to_loc_id,
                   ref_table, ref_id, unit_cost, notes, user_id, device_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $p['company_id'],
                    $p['qr_label_id'] ?? null,
                    $p['product_id'],
                    $p['batch_id'] ?? null,
                    $p['movement_type'],
                    $qty,
                    $p['uom'] ?? 'unit',
                    $p['from_type'] ?? 'none',
                    $p['from_id'] ?? null,
                    $p['to_type'] ?? 'none',
                    $p['to_id'] ?? null,
                    $p['ref_table'] ?? null,
                    $p['ref_id'] ?? null,
                    $p['unit_cost'] ?? 0,
                    $p['notes'] ?? null,
                    $p['user_id'] ?? null,
                    $p['device_id'] ?? null,
                ]
            );

            if (($p['from_type'] ?? 'none') !== 'none' && $p['from_type'] !== 'supplier') {
                self::adjustBalance((int) $p['company_id'], (int) $p['product_id'], $p['from_type'], (int) $p['from_id'], -$qty);
            }
            if (($p['to_type'] ?? 'none') !== 'none' && $p['to_type'] !== 'customer') {
                self::adjustBalance((int) $p['company_id'], (int) $p['product_id'], $p['to_type'], (int) $p['to_id'], $qty);
            }
            return $id;
        });
    }

    public static function adjustBalance(int $company, int $product, string $locType, int $locId, float $delta): void
    {
        Database::run(
            'INSERT INTO stock_balances (company_id, product_id, loc_type, loc_id, qty)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)',
            [$company, $product, $locType, $locId, $delta]
        );
    }

    public static function balance(int $product, string $locType, int $locId): float
    {
        return (float) Database::scalar(
            'SELECT qty FROM stock_balances WHERE product_id=? AND loc_type=? AND loc_id=?',
            [$product, $locType, $locId]
        );
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

    private static function isBlockedNegative(int $company, string $movementType): bool
    {
        $list = self::setting($company, 'block_negative_movements', 'transfer_out,production_out');
        $set = array_filter(array_map('trim', explode(',', $list)));
        return in_array($movementType, $set, true);
    }

    /** Costing basis: maintained moving-average, falling back to standard cost. */
    public static function effectiveCost(array $product): float
    {
        $avg = (float) ($product['avg_cost'] ?? 0);
        return $avg > 0 ? $avg : (float) ($product['cost_price'] ?? 0);
    }

    public static function effectiveCostById(int $productId): float
    {
        $p = Database::first('SELECT avg_cost, cost_price FROM products WHERE id=?', [$productId]);
        return $p ? self::effectiveCost($p) : 0.0;
    }

    /**
     * Moving-average (AVCO) recompute on goods inwards:
     *   newAvg = (onHandQty * oldAvg + inQty * inUnitCost) / (onHandQty + inQty)
     * On-hand is the company-wide quantity across all locations.
     */
    public static function recomputeAvgCost(int $productId, float $inQty, float $inUnitCost): float
    {
        $p = Database::first('SELECT avg_cost, cost_price FROM products WHERE id=?', [$productId]);
        if (!$p || $inQty <= 0) {
            return self::effectiveCost($p ?? []);
        }
        $onHand = (float) Database::scalar(
            'SELECT COALESCE(SUM(qty),0) FROM stock_balances WHERE product_id=?',
            [$productId]
        );
        $oldAvg = self::effectiveCost($p);
        $denom = $onHand + $inQty;
        $newAvg = $denom > 0
            ? round(($onHand * $oldAvg + $inQty * $inUnitCost) / $denom, 4)
            : round($inUnitCost, 4);
        Database::run('UPDATE products SET avg_cost=? WHERE id=?', [$newAvg, $productId]);
        return $newAvg;
    }

    /** FEFO-ordered open batches for a product (FIFO fallback on null expiry). */
    public static function fefoBatches(int $product): array
    {
        return Database::all(
            'SELECT * FROM product_batches
             WHERE product_id = ?
             ORDER BY (expiry_date IS NULL), expiry_date ASC, created_at ASC',
            [$product]
        );
    }

    /** Products at/below reorder level for a location. */
    public static function lowStock(int $company, ?string $locType = null, ?int $locId = null): array
    {
        $join = 'LEFT JOIN stock_balances b ON b.product_id = p.id';
        $args = [];
        if ($locType !== null) {
            $join .= ' AND b.loc_type = ? AND b.loc_id = ?';
            $args[] = $locType;
            $args[] = $locId;
        }
        $args[] = $company;
        $sql = "SELECT * FROM (
                    SELECT p.id, p.sku, p.name, p.reorder_level, p.uom,
                           COALESCE(SUM(b.qty),0) AS on_hand
                    FROM products p
                    $join
                    WHERE p.company_id = ? AND p.is_active = 1
                    GROUP BY p.id, p.sku, p.name, p.reorder_level, p.uom
                ) t
                WHERE t.on_hand <= t.reorder_level
                ORDER BY (t.reorder_level - t.on_hand) DESC";
        return Database::all($sql, $args);
    }

    public static function expiringSoon(int $company, int $days = 3): array
    {
        return Database::all(
            'SELECT b.*, p.sku, p.name
             FROM product_batches b JOIN products p ON p.id = b.product_id
             WHERE b.company_id = ? AND b.expiry_date IS NOT NULL
               AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY b.expiry_date ASC',
            [$company, $days]
        );
    }
}
