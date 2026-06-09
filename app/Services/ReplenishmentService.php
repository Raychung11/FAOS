<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Outlet replenishment: an outlet/restaurant raises a Purchase Request; once
 * approved it is fulfilled by an internal distribution (stock transfer) from a
 * source warehouse (typically the central kitchen) to the requesting outlet.
 */
final class ReplenishmentService
{
    public static function createPR(array $p): array
    {
        return Database::transaction(function () use ($p) {
            $ref = next_ref('PR', 'purchase_requests', 'pr_ref');
            $prId = Database::insert(
                'INSERT INTO purchase_requests
                   (company_id, outlet_id, pr_ref, status, requested_by)
                 VALUES (?,?,?,?,?)',
                [$p['company_id'], $p['outlet_id'], $ref, 'open', $p['user_id'] ?? null]
            );
            foreach ($p['items'] as $it) {
                Database::insert(
                    'INSERT INTO purchase_request_items (pr_id, product_id, qty) VALUES (?,?,?)',
                    [$prId, $it['product_id'], $it['qty']]
                );
            }
            return ['id' => $prId, 'pr_ref' => $ref];
        });
    }

    public static function setStatus(int $prId, string $status): void
    {
        Database::run('UPDATE purchase_requests SET status=? WHERE id=?', [$status, $prId]);
    }

    /**
     * Fulfil an approved PR by transferring each line from a source warehouse
     * to the requesting outlet. Blocks if the source is short on any line.
     */
    public static function fulfil(int $prId, int $sourceWarehouseId, int $userId): array
    {
        return Database::transaction(function () use ($prId, $sourceWarehouseId, $userId) {
            $pr = Database::first('SELECT * FROM purchase_requests WHERE id=?', [$prId]);
            if (!$pr) {
                throw new \RuntimeException('Purchase request not found');
            }
            if ($pr['status'] !== 'approved') {
                throw new \RuntimeException('PR must be approved before fulfilment');
            }
            $items = Database::all('SELECT * FROM purchase_request_items WHERE pr_id=?', [$prId]);

            // Pre-check availability so we never partially fulfil.
            foreach ($items as $it) {
                $have = StockService::balance((int) $it['product_id'], 'warehouse', $sourceWarehouseId);
                if ($have + 1e-6 < (float) $it['qty']) {
                    throw new \RuntimeException(
                        "Insufficient stock for product #{$it['product_id']} at source "
                        . '(need ' . qty((float) $it['qty']) . ', have ' . qty($have) . ')'
                    );
                }
            }

            $moves = [];
            foreach ($items as $it) {
                $mid = StockService::recordMovement([
                    'company_id'    => $pr['company_id'],
                    'product_id'    => (int) $it['product_id'],
                    'movement_type' => 'transfer_out',
                    'qty'           => (float) $it['qty'],
                    'from_type'     => 'warehouse',
                    'from_id'       => $sourceWarehouseId,
                    'to_type'       => 'outlet',
                    'to_id'         => (int) $pr['outlet_id'],
                    'ref_table'     => 'purchase_requests',
                    'ref_id'        => $prId,
                    'user_id'       => $userId,
                    'notes'         => 'Replenishment ' . $pr['pr_ref'],
                ]);
                $moves[] = ['product_id' => (int) $it['product_id'], 'qty' => (float) $it['qty'], 'movement_id' => $mid];
            }

            Database::run("UPDATE purchase_requests SET status='converted' WHERE id=?", [$prId]);
            return ['pr_ref' => $pr['pr_ref'], 'transfers' => $moves];
        });
    }
}
