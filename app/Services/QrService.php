<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Issues QR stock labels. The QR payload is ONLY the reference string. */
final class QrService
{
    public static function createLabel(array $p): array
    {
        $ref = next_ref('STK', 'qr_labels', 'qr_ref');
        $id = Database::insert(
            'INSERT INTO qr_labels
              (company_id, qr_ref, label_type, product_id, batch_id, init_qty, uom,
               current_location_type, current_location_id, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $p['company_id'],
                $ref,
                $p['label_type'] ?? 'carton',
                $p['product_id'],
                $p['batch_id'] ?? null,
                $p['init_qty'] ?? 0,
                $p['uom'] ?? 'unit',
                $p['location_type'] ?? 'warehouse',
                $p['location_id'] ?? null,
                $p['created_by'] ?? null,
            ]
        );
        return self::find($ref);
    }

    public static function find(string $ref): ?array
    {
        return Database::first(
            'SELECT q.*, p.sku, p.name AS product_name, p.uom AS product_uom,
                    p.cost_price AS product_cost,
                    b.batch_no, b.expiry_date, s.name AS supplier_name
             FROM qr_labels q
             JOIN products p ON p.id = q.product_id
             LEFT JOIN product_batches b ON b.id = q.batch_id
             LEFT JOIN suppliers s ON s.id = b.supplier_id
             WHERE q.qr_ref = ? LIMIT 1',
            [$ref]
        );
    }

    /** Full traceability: every movement that touched this label. */
    public static function history(int $labelId): array
    {
        return Database::all(
            'SELECT sm.*, u.full_name AS user_name
             FROM stock_movements sm
             LEFT JOIN users u ON u.id = sm.user_id
             WHERE sm.qr_label_id = ?
             ORDER BY sm.created_at ASC, sm.id ASC',
            [$labelId]
        );
    }
}
