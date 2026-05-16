<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Central-kitchen production: a production order consumes recipe ingredients
 * (FEFO-tagged) from the kitchen warehouse and yields finished/semi-finished
 * stock back into it, with a computed unit cost.
 */
final class ProductionService
{
    public static function createOrder(array $p): array
    {
        $recipe = Database::first(
            'SELECT * FROM recipes WHERE product_id=? AND is_active=1',
            [$p['product_id']]
        );
        if (!$recipe) {
            throw new \RuntimeException('No active recipe/BOM for this product');
        }
        $ref = next_ref('PRD', 'production_orders', 'prod_ref');
        $id = Database::insert(
            'INSERT INTO production_orders
               (company_id, warehouse_id, product_id, recipe_id, prod_ref, planned_qty,
                status, planned_date, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $p['company_id'], $p['warehouse_id'], $p['product_id'], $recipe['id'],
                $ref, $p['planned_qty'], 'planned', $p['planned_date'] ?? null,
                $p['user_id'] ?? null,
            ]
        );
        return ['id' => $id, 'prod_ref' => $ref];
    }

    public static function start(int $id): void
    {
        Database::run(
            "UPDATE production_orders SET status='in_progress'
             WHERE id=? AND status='planned'",
            [$id]
        );
    }

    /**
     * Complete production: FEFO-consume ingredients, cost the output, and
     * receive the produced batch into the kitchen warehouse.
     */
    public static function complete(int $id, float $producedQty, int $userId): array
    {
        return Database::transaction(function () use ($id, $producedQty, $userId) {
            $po = Database::first('SELECT * FROM production_orders WHERE id=?', [$id]);
            if (!$po || $po['status'] === 'completed' || $po['status'] === 'cancelled') {
                throw new \RuntimeException('Production order not open');
            }
            $recipe = Database::first('SELECT * FROM recipes WHERE id=?', [$po['recipe_id']]);
            $items = Database::all('SELECT * FROM recipe_items WHERE recipe_id=?', [$po['recipe_id']]);
            $factor = $producedQty / max(0.0001, (float) $recipe['yield_qty']);

            $whId = (int) $po['warehouse_id'];
            $totalCost = 0.0;
            $consumed = [];

            foreach ($items as $ri) {
                $need = (float) $ri['qty'] * $factor;
                $onHand = StockService::balance((int) $ri['ingredient_id'], 'warehouse', $whId);
                if ($onHand + 1e-6 < $need) {
                    throw new \RuntimeException(
                        "Insufficient ingredient #{$ri['ingredient_id']} (need "
                        . qty($need) . ', have ' . qty($onHand) . ')'
                    );
                }
                // FEFO: tag the earliest-expiring batch for traceability.
                $batches = StockService::fefoBatches((int) $ri['ingredient_id']);
                $batchId = $batches[0]['id'] ?? null;
                $unitCost = (float) ($batches[0]['unit_cost']
                    ?? Database::scalar('SELECT cost_price FROM products WHERE id=?', [$ri['ingredient_id']]));
                $lineCost = $need * $unitCost;
                $totalCost += $lineCost;

                Database::insert(
                    'INSERT INTO production_consumption (production_id, ingredient_id, batch_id, qty)
                     VALUES (?,?,?,?)',
                    [$id, $ri['ingredient_id'], $batchId, $need]
                );
                StockService::recordMovement([
                    'company_id'    => $po['company_id'],
                    'product_id'    => (int) $ri['ingredient_id'],
                    'batch_id'      => $batchId,
                    'movement_type' => 'production_out',
                    'qty'           => $need,
                    'from_type'     => 'warehouse',
                    'from_id'       => $whId,
                    'to_type'       => 'none',
                    'unit_cost'     => $unitCost,
                    'ref_table'     => 'production_orders',
                    'ref_id'        => $id,
                    'user_id'       => $userId,
                    'notes'         => 'Production consumption ' . $po['prod_ref'],
                ]);
                $consumed[] = [
                    'ingredient_id' => (int) $ri['ingredient_id'],
                    'qty' => round($need, 4), 'unit_cost' => round($unitCost, 4),
                ];
            }

            $unitCostOut = $producedQty > 0 ? round($totalCost / $producedQty, 4) : 0.0;

            // Output batch for the produced (semi-)finished good.
            $shelf = Database::scalar('SELECT shelf_life_days FROM products WHERE id=?', [$po['product_id']]);
            $expiry = $shelf ? date('Y-m-d', strtotime('+' . (int) $shelf . ' days')) : null;
            $batchId = Database::insert(
                'INSERT INTO product_batches
                   (company_id, product_id, supplier_id, batch_no, mfg_date, expiry_date,
                    received_qty, unit_cost)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $po['company_id'], $po['product_id'], null,
                    'PRD-' . $po['prod_ref'], date('Y-m-d'), $expiry,
                    $producedQty, $unitCostOut,
                ]
            );
            StockService::recordMovement([
                'company_id'    => $po['company_id'],
                'product_id'    => (int) $po['product_id'],
                'batch_id'      => $batchId,
                'movement_type' => 'production_in',
                'qty'           => $producedQty,
                'from_type'     => 'none',
                'to_type'       => 'warehouse',
                'to_id'         => $whId,
                'unit_cost'     => $unitCostOut,
                'ref_table'     => 'production_orders',
                'ref_id'        => $id,
                'user_id'       => $userId,
                'notes'         => 'Production output ' . $po['prod_ref'],
            ]);

            Database::run(
                "UPDATE production_orders
                 SET status='completed', produced_qty=?, completed_at=NOW() WHERE id=?",
                [$producedQty, $id]
            );

            return [
                'produced_qty'   => $producedQty,
                'output_batch_id' => $batchId,
                'unit_cost'      => $unitCostOut,
                'total_cost'     => round($totalCost, 2),
                'consumed'       => $consumed,
            ];
        });
    }
}
