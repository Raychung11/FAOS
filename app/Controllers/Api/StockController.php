<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\QrService;
use App\Services\StockService;

final class StockController extends Controller
{
    /** Receive stock against a QR label (Supplier -> Warehouse). */
    public function receive(Request $req): void
    {
        $d = $this->validate($req, [
            'qr_ref'   => 'required|string',
            'qty'      => 'required|numeric|min:0.001',
            'to_type'  => 'required|in:warehouse,outlet,kiosk',
            'to_id'    => 'required|int',
        ]);
        $label = QrService::find($d['qr_ref']);
        if (!$label) {
            Response::fail('Invalid QR reference', 404);
        }
        $id = StockService::recordMovement([
            'company_id'  => $this->companyId(),
            'product_id'  => (int) $label['product_id'],
            'qr_label_id' => (int) $label['id'],
            'batch_id'    => $label['batch_id'],
            'movement_type' => 'receive',
            'qty'         => (float) $d['qty'],
            'uom'         => $label['product_uom'],
            'from_type'   => 'supplier',
            'to_type'     => $d['to_type'],
            'to_id'       => (int) $d['to_id'],
            'user_id'     => Auth::id(),
            'device_id'   => $req->deviceId(),
        ]);
        $this->scanLog($d['qr_ref'], (int) $label['id'], 'receive', $req);
        Audit::log('stock_receive', 'stock_movements', (string) $id);
        Response::ok(['movement_id' => $id], 'Stock received');
    }

    /** Transfer between locations (Central Kitchen -> Warehouse -> Outlet -> Kiosk). */
    public function transfer(Request $req): void
    {
        $d = $this->validate($req, [
            'qr_ref'    => 'required|string',
            'qty'       => 'required|numeric|min:0.001',
            'from_type' => 'required|in:warehouse,outlet,kiosk',
            'from_id'   => 'required|int',
            'to_type'   => 'required|in:warehouse,outlet,kiosk',
            'to_id'     => 'required|int',
        ]);
        $label = QrService::find($d['qr_ref']);
        if (!$label) {
            Response::fail('Invalid QR reference', 404);
        }
        $onHand = StockService::balance((int) $label['product_id'], $d['from_type'], (int) $d['from_id']);
        if ($onHand < (float) $d['qty']) {
            Response::fail("Insufficient stock at source (on hand: $onHand)", 422);
        }
        $id = StockService::recordMovement([
            'company_id'  => $this->companyId(),
            'product_id'  => (int) $label['product_id'],
            'qr_label_id' => (int) $label['id'],
            'batch_id'    => $label['batch_id'],
            'movement_type' => 'transfer_out',
            'qty'         => (float) $d['qty'],
            'uom'         => $label['product_uom'],
            'from_type'   => $d['from_type'],
            'from_id'     => (int) $d['from_id'],
            'to_type'     => $d['to_type'],
            'to_id'       => (int) $d['to_id'],
            'user_id'     => Auth::id(),
            'device_id'   => $req->deviceId(),
        ]);
        Database::run(
            'UPDATE qr_labels SET current_location_type=?, current_location_id=? WHERE id=?',
            [$d['to_type'], $d['to_id'], $label['id']]
        );
        $this->scanLog($d['qr_ref'], (int) $label['id'], 'transfer', $req);
        Audit::log('stock_transfer', 'stock_movements', (string) $id);
        Response::ok(['movement_id' => $id], 'Stock transferred');
    }

    public function consume(Request $req): void
    {
        $this->simpleOut($req, 'consume', 'stock_consume');
    }

    public function wastage(Request $req): void
    {
        $this->simpleOut($req, 'wastage', 'stock_wastage');
    }

    private function simpleOut(Request $req, string $type, string $audit): void
    {
        $d = $this->validate($req, [
            'qr_ref'    => 'required|string',
            'qty'       => 'required|numeric|min:0.001',
            'from_type' => 'required|in:warehouse,outlet,kiosk',
            'from_id'   => 'required|int',
        ]);
        $label = QrService::find($d['qr_ref']);
        if (!$label) {
            Response::fail('Invalid QR reference', 404);
        }
        $id = StockService::recordMovement([
            'company_id'  => $this->companyId(),
            'product_id'  => (int) $label['product_id'],
            'qr_label_id' => (int) $label['id'],
            'batch_id'    => $label['batch_id'],
            'movement_type' => $type,
            'qty'         => (float) $d['qty'],
            'uom'         => $label['product_uom'],
            // Wastage carries moving-average cost (feeds P&L wastage); recipe
            // "consume" does not, so finished-good COGS is never double counted.
            'unit_cost'   => $type === 'wastage'
                ? StockService::effectiveCostById((int) $label['product_id']) : 0,
            'from_type'   => $d['from_type'],
            'from_id'     => (int) $d['from_id'],
            'to_type'     => 'none',
            'notes'       => $req->input('notes'),
            'user_id'     => Auth::id(),
            'device_id'   => $req->deviceId(),
        ]);
        $this->scanLog($d['qr_ref'], (int) $label['id'], $type, $req);
        Audit::log($audit, 'stock_movements', (string) $id);
        Response::ok(['movement_id' => $id], ucfirst($type) . ' recorded');
    }

    /** Stock count / physical adjustment to an absolute counted qty. */
    public function count(Request $req): void
    {
        $d = $this->validate($req, [
            'product_id' => 'required|int',
            'loc_type'   => 'required|in:warehouse,outlet,kiosk',
            'loc_id'     => 'required|int',
            'counted_qty' => 'required|numeric',
        ]);
        $product = (int) $d['product_id'];
        $current = StockService::balance($product, $d['loc_type'], (int) $d['loc_id']);
        $delta = round((float) $d['counted_qty'] - $current, 3);

        $adjId = Database::insert(
            'INSERT INTO stock_adjustments
              (company_id, product_id, loc_type, loc_id, adj_type, qty_delta, reason, user_id)
             VALUES (?,?,?,?,?,?,?,?)',
            [$this->companyId(), $product, $d['loc_type'], $d['loc_id'], 'count',
             $delta, $req->input('reason', 'Stock take'), Auth::id()]
        );
        if (abs($delta) > 0.0001) {
            StockService::recordMovement([
                'company_id'  => $this->companyId(),
                'product_id'  => $product,
                'movement_type' => 'count',
                'qty'         => abs($delta),
                'from_type'   => $delta < 0 ? $d['loc_type'] : 'none',
                'from_id'     => $delta < 0 ? (int) $d['loc_id'] : null,
                'to_type'     => $delta > 0 ? $d['loc_type'] : 'none',
                'to_id'       => $delta > 0 ? (int) $d['loc_id'] : null,
                'ref_table'   => 'stock_adjustments',
                'ref_id'      => $adjId,
                'notes'       => 'Stock count adjustment',
                'user_id'     => Auth::id(),
            ]);
        }
        Audit::log('stock_count', 'stock_adjustments', (string) $adjId, null, ['delta' => $delta]);
        Response::ok(['adjustment_id' => $adjId, 'delta' => $delta], 'Count recorded');
    }

    public function balances(Request $req): void
    {
        $args = [$this->companyId()];
        $sql = 'SELECT b.*, p.sku, p.name, p.uom, p.reorder_level
                FROM stock_balances b JOIN products p ON p.id = b.product_id
                WHERE b.company_id = ?';
        if ($req->query('loc_type')) {
            $sql .= ' AND b.loc_type = ? AND b.loc_id = ?';
            $args[] = $req->query('loc_type');
            $args[] = (int) $req->query('loc_id');
        }
        $sql .= ' ORDER BY p.name';
        Response::ok(Database::all($sql, $args));
    }

    public function ledger(Request $req): void
    {
        $sql = 'SELECT sm.*, p.sku, p.name AS product_name, u.full_name AS user_name
                FROM stock_movements sm
                JOIN products p ON p.id = sm.product_id
                LEFT JOIN users u ON u.id = sm.user_id
                WHERE sm.company_id = ?';
        $args = [$this->companyId()];
        if ($req->query('product_id')) {
            $sql .= ' AND sm.product_id = ?';
            $args[] = (int) $req->query('product_id');
        }
        $sql .= ' ORDER BY sm.id DESC LIMIT 300';
        Response::ok(Database::all($sql, $args));
    }

    private function scanLog(string $ref, int $labelId, string $ctx, Request $req): void
    {
        $u = Auth::user();
        Database::run(
            'INSERT INTO scan_logs (company_id, user_id, qr_ref, qr_label_id, scan_context, outlet_id, device_id, ip_address)
             VALUES (?,?,?,?,?,?,?,?)',
            [$this->companyId(), $u['id'], $ref, $labelId, $ctx, $u['outlet_id'], $req->deviceId(), $req->ip()]
        );
    }
}
