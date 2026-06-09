<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ProcurementService;
use App\Services\StockService;

/**
 * Bulk onboarding: product catalogue + opening stock balances via CSV.
 *
 * Fixed, documented headers (downloadable template). Each row is independent:
 * valid rows are applied, bad rows are reported with row numbers — fix and
 * re-upload. Idempotent: products upsert by SKU; opening stock sets an
 * absolute on-hand level, so re-running is safe.
 */
final class ImportController extends Controller
{
    private const PRODUCT_COLS = [
        'sku', 'name', 'uom', 'type', 'cost_price', 'sell_price',
        'reorder_level', 'shelf_life_days', 'category_code', 'tax_code',
        'is_sellable',
    ];
    private const STOCK_COLS = ['sku', 'loc_type', 'loc_code', 'qty', 'unit_cost'];
    private const PO_COLS    = ['barcode', 'sku', 'description', 'qty', 'uom', 'unit_cost'];

    public function productTemplate(Request $req): never
    {
        $this->csvTemplate('faos_products_template', self::PRODUCT_COLS, [
            ['CF-NEW', 'New Latte 350ml', 'unit', 'finished', '3.20', '9.90',
             '20', '2', 'CAT-COFFEE', 'SR', '1'],
        ]);
    }

    public function stockTemplate(Request $req): never
    {
        $this->csvTemplate('faos_opening_stock_template', self::STOCK_COLS, [
            ['CF-LATTE', 'warehouse', 'CK01', '50', '3.20'],
            ['CF-LATTE', 'outlet', 'OUT01', '12', ''],
        ]);
    }

    /** Upsert products by SKU. */
    public function products(Request $req): void
    {
        $rows = $this->readCsv();
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $n => $r) {
            try {
                $sku = trim((string) ($r['sku'] ?? ''));
                $name = trim((string) ($r['name'] ?? ''));
                if ($sku === '' || $name === '') {
                    throw new \RuntimeException('sku and name are required');
                }
                $type = in_array($r['type'] ?? '', ['raw', 'semi_finished', 'finished', 'consumable'], true)
                    ? $r['type'] : 'finished';

                $catId = $this->lookupId('product_categories', 'code', $r['category_code'] ?? '');
                $taxId = $this->lookupId('tax_codes', 'code', $r['tax_code'] ?? '');
                if (!empty($r['category_code']) && $catId === null) {
                    $errors[] = ['row' => $n + 2, 'warning' => "Unknown category '{$r['category_code']}' — left blank"];
                }
                if (!empty($r['tax_code']) && $taxId === null) {
                    $errors[] = ['row' => $n + 2, 'warning' => "Unknown tax code '{$r['tax_code']}' — left blank"];
                }

                $fields = [
                    'name'            => $name,
                    'uom'             => trim((string) ($r['uom'] ?? 'unit')) ?: 'unit',
                    'type'            => $type,
                    'cost_price'      => (float) ($r['cost_price'] ?? 0),
                    'sell_price'      => (float) ($r['sell_price'] ?? 0),
                    'reorder_level'   => (float) ($r['reorder_level'] ?? 0),
                    'shelf_life_days' => ($r['shelf_life_days'] ?? '') === '' ? null : (int) $r['shelf_life_days'],
                    'category_id'     => $catId,
                    'tax_code_id'     => $taxId,
                    'is_sellable'     => (int) ((bool) ($r['is_sellable'] ?? 1)),
                ];

                $existing = Database::first(
                    'SELECT id FROM products WHERE company_id = ? AND sku = ?',
                    [$this->companyId(), $sku]
                );
                if ($existing) {
                    $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($fields)));
                    Database::run(
                        "UPDATE products SET $set WHERE id = ?",
                        [...array_values($fields), $existing['id']]
                    );
                    $updated++;
                } else {
                    $cols = array_merge(['company_id', 'sku'], array_keys($fields));
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    Database::insert(
                        'INSERT INTO products (' . implode(',', $cols) . ") VALUES ($ph)",
                        [$this->companyId(), $sku, ...array_values($fields)]
                    );
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => $n + 2, 'error' => $e->getMessage()];
            }
        }
        Audit::log('import_products', 'products', null, null,
            ['created' => $created, 'updated' => $updated, 'errors' => count($errors)]);
        Response::ok(['created' => $created, 'updated' => $updated, 'errors' => $errors],
            "Products imported: $created new, $updated updated");
    }

    /** Set absolute opening on-hand per product per location. */
    public function stock(Request $req): void
    {
        $rows = $this->readCsv();
        $applied = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $n => $r) {
            try {
                $sku = trim((string) ($r['sku'] ?? ''));
                $locType = trim((string) ($r['loc_type'] ?? ''));
                $locCode = trim((string) ($r['loc_code'] ?? ''));
                if (!in_array($locType, ['warehouse', 'outlet', 'kiosk'], true)) {
                    throw new \RuntimeException("loc_type must be warehouse|outlet|kiosk");
                }
                $product = Database::first(
                    'SELECT id FROM products WHERE company_id = ? AND sku = ?',
                    [$this->companyId(), $sku]
                );
                if (!$product) {
                    throw new \RuntimeException("Unknown product SKU '$sku'");
                }
                $locId = $this->resolveLocation($locType, $locCode);
                if ($locId === null) {
                    throw new \RuntimeException("Unknown $locType code '$locCode'");
                }
                $target = (float) ($r['qty'] ?? 0);
                $current = StockService::balance((int) $product['id'], $locType, $locId);
                $delta = round($target - $current, 3);
                if (abs($delta) < 0.0001) {
                    $skipped++;
                    continue;
                }
                $unitCost = ($r['unit_cost'] ?? '') === '' ? null : (float) $r['unit_cost'];

                Database::transaction(function () use ($product, $locType, $locId, $delta, $target, $unitCost) {
                    $adjId = Database::insert(
                        'INSERT INTO stock_adjustments
                          (company_id, product_id, loc_type, loc_id, adj_type, qty_delta, reason, user_id)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [$this->companyId(), $product['id'], $locType, $locId, 'count',
                         $delta, 'Opening balance import', Auth::id()]
                    );
                    if ($unitCost !== null && $delta > 0) {
                        StockService::recomputeAvgCost((int) $product['id'], $delta, $unitCost);
                    }
                    StockService::recordMovement([
                        'company_id'    => $this->companyId(),
                        'product_id'    => (int) $product['id'],
                        'movement_type' => 'count',
                        'qty'           => abs($delta),
                        'from_type'     => $delta < 0 ? $locType : 'none',
                        'from_id'       => $delta < 0 ? $locId : null,
                        'to_type'       => $delta > 0 ? $locType : 'none',
                        'to_id'         => $delta > 0 ? $locId : null,
                        'unit_cost'     => $unitCost ?? 0,
                        'ref_table'     => 'stock_adjustments',
                        'ref_id'        => $adjId,
                        'notes'         => 'Opening balance import (set to ' . qty($target) . ')',
                        'user_id'       => Auth::id(),
                    ]);
                });
                $applied++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $n + 2, 'error' => $e->getMessage()];
            }
        }
        Audit::log('import_stock', 'stock_adjustments', null, null,
            ['applied' => $applied, 'skipped' => $skipped, 'errors' => count($errors)]);
        Response::ok(['applied' => $applied, 'unchanged' => $skipped, 'errors' => $errors],
            "Opening stock: $applied applied, $skipped unchanged");
    }

    public function poTemplate(Request $req): never
    {
        $this->csvTemplate('faos_purchase_order_template', self::PO_COLS, [
            ['RM710', '', 'Prawn 2pcs for Salmon Fish Head', '20', 'pkt', '5.20'],
            ['RM684', '', 'Butane Gas 230g', '4', 'tin', '4.95'],
            ['INT744456', '', 'Roasted Chicken Wing', '5', 'pkt', '8.60'],
        ]);
    }

    /**
     * Create a draft supplier PO from CSV lines. Matches a product by SKU then
     * barcode; can auto-create missing ones (handy for initial onboarding).
     * The PO then flows through the normal approve -> GRN -> AP invoice path.
     */
    public function purchaseOrder(Request $req): void
    {
        $supplierId = (int) $req->input('supplier_id');
        $supplier = Database::first(
            'SELECT * FROM suppliers WHERE id = ? AND company_id = ?',
            [$supplierId, $this->companyId()]
        );
        if (!$supplier) {
            Response::fail('Valid supplier_id required', 422);
        }
        $warehouseId = $req->input('warehouse_id') ? (int) $req->input('warehouse_id') : null;
        if ($warehouseId !== null && !Database::scalar(
            'SELECT id FROM warehouses WHERE id = ? AND company_id = ?',
            [$warehouseId, $this->companyId()]
        )) {
            Response::fail('Warehouse not in your company', 422);
        }
        $createMissing = filter_var($req->input('create_missing', false), FILTER_VALIDATE_BOOL);

        $rows = $this->readCsv();
        $items = [];
        $created = 0;
        $matched = 0;
        $errors = [];

        foreach ($rows as $n => $r) {
            try {
                $barcode = trim((string) ($r['barcode'] ?? ''));
                $sku     = trim((string) ($r['sku'] ?? ''));
                $desc    = trim((string) ($r['description'] ?? ''));
                $qty     = (float) ($r['qty'] ?? 0);
                $cost    = (float) ($r['unit_cost'] ?? 0);
                $uom     = trim((string) ($r['uom'] ?? 'unit')) ?: 'unit';
                if ($sku === '' && $barcode === '') {
                    throw new \RuntimeException('barcode or sku is required');
                }
                if ($qty <= 0) {
                    throw new \RuntimeException('qty must be > 0');
                }

                $p = null;
                if ($sku !== '') {
                    $p = Database::first(
                        'SELECT id FROM products WHERE company_id = ? AND sku = ?',
                        [$this->companyId(), $sku]
                    );
                }
                if (!$p && $barcode !== '') {
                    $p = Database::first(
                        'SELECT id FROM products WHERE company_id = ? AND barcode = ?',
                        [$this->companyId(), $barcode]
                    );
                }

                if ($p) {
                    $productId = (int) $p['id'];
                    $matched++;
                } elseif ($createMissing) {
                    $newSku = $sku !== '' ? $sku : $barcode;
                    if (Database::scalar(
                        'SELECT id FROM products WHERE company_id = ? AND sku = ?',
                        [$this->companyId(), $newSku]
                    )) {
                        throw new \RuntimeException("SKU '$newSku' exists but didn't match — fix the row");
                    }
                    $productId = Database::insert(
                        'INSERT INTO products
                          (company_id, sku, barcode, name, uom, type, cost_price,
                           sell_price, is_sellable, is_active)
                         VALUES (?,?,?,?,?,?,?,?,0,1)',
                        [
                            $this->companyId(), $newSku,
                            $barcode !== '' ? $barcode : null,
                            $desc !== '' ? $desc : $newSku,
                            $uom, 'consumable', $cost, 0,
                        ]
                    );
                    $created++;
                } else {
                    throw new \RuntimeException(
                        "No product for " . ($sku ?: $barcode)
                        . " (enable 'create missing' to add it)"
                    );
                }

                $items[] = ['product_id' => $productId, 'qty' => $qty, 'unit_cost' => $cost];
            } catch (\Throwable $e) {
                $errors[] = ['row' => $n + 2, 'error' => $e->getMessage()];
            }
        }

        if (!$items) {
            Response::fail('No valid PO lines (see errors)', 422, $errors);
        }

        $po = ProcurementService::createPO([
            'company_id'    => $this->companyId(),
            'supplier_id'   => $supplierId,
            'warehouse_id'  => $warehouseId,
            'expected_date' => $req->input('expected_date') ?: null,
            'items'         => $items,
            'user_id'       => Auth::id(),
        ]);
        Audit::log('import_po', 'purchase_orders', (string) $po['id'], null,
            ['lines' => count($items), 'created' => $created, 'matched' => $matched]);
        Response::ok([
            'po_id'              => $po['id'],
            'po_ref'             => $po['po_ref'],
            'total'              => $po['total'],
            'lines'              => count($items),
            'products_matched'   => $matched,
            'products_created'   => $created,
            'errors'             => $errors,
        ], "Draft PO {$po['po_ref']} created ({$po['total']})");
    }

    // ---- helpers -----------------------------------------------------------

    private function lookupId(string $table, string $col, string $val): ?int
    {
        $val = trim($val);
        if ($val === '') {
            return null;
        }
        $id = Database::scalar(
            "SELECT id FROM $table WHERE company_id = ? AND $col = ?",
            [$this->companyId(), $val]
        );
        return $id ? (int) $id : null;
    }

    private function resolveLocation(string $type, string $code): ?int
    {
        if ($type === 'kiosk') {
            $id = Database::scalar(
                'SELECT k.id FROM kiosks k JOIN outlets o ON o.id = k.outlet_id
                 WHERE o.company_id = ? AND k.code = ?',
                [$this->companyId(), $code]
            );
        } else {
            $tbl = $type === 'warehouse' ? 'warehouses' : 'outlets';
            $id = Database::scalar(
                "SELECT id FROM $tbl WHERE company_id = ? AND code = ?",
                [$this->companyId(), $code]
            );
        }
        return $id ? (int) $id : null;
    }

    /** @return array<int,array<string,string>> rows keyed by header */
    private function readCsv(): array
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            Response::fail('No file uploaded (field "file")', 422);
        }
        if ($f['size'] > 4 * 1024 * 1024) {
            Response::fail('File too large (max 4MB)', 422);
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            Response::fail('Only .csv / .txt files are accepted', 422);
        }
        $h = fopen($f['tmp_name'], 'r');
        if ($h === false) {
            Response::fail('Cannot read uploaded file', 500);
        }
        $header = null;
        $rows = [];
        while (($line = fgetcsv($h, 0, ',')) !== false) {
            if ($line === [null]) {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn ($c) => strtolower(trim((string) $c)), $line);
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }
            if (implode('', $row) === '') {
                continue; // blank line
            }
            $rows[] = $row;
        }
        fclose($h);
        if (!$rows) {
            Response::fail('No data rows found', 422);
        }
        return $rows;
    }

    private function csvTemplate(string $name, array $cols, array $sampleRows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $cols);
        foreach ($sampleRows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}
