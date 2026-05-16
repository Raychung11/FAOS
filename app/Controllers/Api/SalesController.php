<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\SalesService;

final class SalesController extends Controller
{
    /** Record a QR sale (idempotent on client_uuid for offline retry). */
    public function record(Request $req): void
    {
        $u = Auth::user();
        $items = $req->input('items');
        if (!is_array($items) || $items === []) {
            Response::fail('At least one item is required', 422);
        }
        foreach ($items as $it) {
            if (empty($it['product_id']) || !isset($it['qty']) || (float) $it['qty'] <= 0) {
                Response::fail('Each item needs product_id and qty > 0', 422);
            }
        }
        $payment = $req->input('payment_type');
        if (!in_array($payment, ['cash', 'card', 'ewallet', 'transfer'], true)) {
            Response::fail('Invalid payment_type', 422);
        }

        $result = SalesService::record([
            'company_id'  => $u['company_id'],
            'outlet_id'   => $u['outlet_id'] ?? $req->input('outlet_id'),
            'kiosk_id'    => $u['kiosk_id'] ?? $req->input('kiosk_id'),
            'user_id'     => $u['id'],
            'payment_type' => $payment,
            'client_uuid' => $req->input('client_uuid'),
            'device_id'   => $req->deviceId(),
            'source'      => $req->input('source', 'qr_scan'),
            'sold_at'     => $req->input('sold_at'),
            'items'       => $items,
        ]);

        if ($result['duplicate']) {
            Response::ok(['transaction' => $result['transaction'], 'duplicate' => true], 'Already recorded');
        }
        Audit::log('sale', 'sales_transactions', (string) $result['transaction']['id']);
        Response::ok(['transaction' => $result['transaction']], 'Sale recorded');
    }

    /** Bulk offline queue flush. */
    public function sync(Request $req): void
    {
        $batch = $req->input('sales');
        if (!is_array($batch)) {
            Response::fail('sales array required', 422);
        }
        $u = Auth::user();
        $results = [];
        foreach ($batch as $sale) {
            try {
                Database::run(
                    'INSERT IGNORE INTO offline_sync_queue (company_id, client_uuid, user_id, payload_json)
                     VALUES (?,?,?,?)',
                    [$u['company_id'], $sale['client_uuid'] ?? bin2hex(random_bytes(8)), $u['id'], json_encode($sale)]
                );
                $r = SalesService::record([
                    'company_id'  => $u['company_id'],
                    'outlet_id'   => $u['outlet_id'] ?? ($sale['outlet_id'] ?? null),
                    'kiosk_id'    => $u['kiosk_id'] ?? ($sale['kiosk_id'] ?? null),
                    'user_id'     => $u['id'],
                    'payment_type' => $sale['payment_type'] ?? 'cash',
                    'client_uuid' => $sale['client_uuid'] ?? null,
                    'device_id'   => $req->deviceId(),
                    'source'      => 'qr_scan',
                    'sold_at'     => $sale['sold_at'] ?? null,
                    'items'       => $sale['items'] ?? [],
                ]);
                Database::run(
                    "UPDATE offline_sync_queue SET status='processed', processed_at=NOW() WHERE client_uuid=?",
                    [$sale['client_uuid'] ?? '']
                );
                $results[] = ['client_uuid' => $sale['client_uuid'] ?? null, 'ok' => true, 'duplicate' => $r['duplicate']];
            } catch (\Throwable $e) {
                // Mark the queue row failed so it isn't left misleadingly
                // "pending"; the client keeps it and retries.
                if (!empty($sale['client_uuid'])) {
                    Database::run(
                        "UPDATE offline_sync_queue SET status='failed', error_msg=? WHERE client_uuid=?",
                        [substr($e->getMessage(), 0, 255), $sale['client_uuid']]
                    );
                }
                $results[] = ['client_uuid' => $sale['client_uuid'] ?? null, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        Response::ok(['results' => $results]);
    }

    public function void(Request $req, array $p): void
    {
        try {
            $txn = SalesService::void(
                $this->companyId(),
                (int) $p['id'],
                (int) Auth::id(),
                $req->input('reason')
            );
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('sale_void', 'sales_transactions', $p['id'], null, ['reason' => $req->input('reason')]);
        Response::ok($txn, 'Sale voided; stock reversed');
    }

    public function refund(Request $req, array $p): void
    {
        $items = $req->input('items');
        if (!is_array($items) || !$items) {
            Response::fail('items[] required (sales_item_id + qty)', 422);
        }
        $method = $req->input('refund_method', 'cash');
        if (!in_array($method, ['cash', 'card', 'ewallet', 'transfer'], true)) {
            Response::fail('Invalid refund_method', 422);
        }
        try {
            $res = SalesService::refund(
                $this->companyId(),
                (int) $p['id'],
                $items,
                $req->input('reason'),
                $method,
                (int) Auth::id()
            );
        } catch (\RuntimeException $e) {
            Response::fail($e->getMessage(), 422);
        }
        Audit::log('sale_refund', 'sales_refunds', (string) $res['refund']['id'], null, $res);
        Response::ok($res, 'Refund recorded; stock returned');
    }

    public function show(Request $req, array $p): void
    {
        $txn = Database::first(
            'SELECT st.*, o.name AS outlet_name, us.full_name AS worker_name
             FROM sales_transactions st
             JOIN outlets o ON o.id = st.outlet_id
             JOIN users us ON us.id = st.user_id
             WHERE st.id=? AND st.company_id=?',
            [$p['id'], $this->companyId()]
        );
        if (!$txn) {
            Response::fail('Not found', 404);
        }
        $txn['items'] = Database::all(
            'SELECT si.*, p.name AS product_name,
                    COALESCE((SELECT SUM(qty) FROM sales_refund_items WHERE sales_item_id=si.id),0) AS refunded_qty
             FROM sales_items si JOIN products p ON p.id=si.product_id
             WHERE si.transaction_id=?',
            [$p['id']]
        );
        $txn['refunds'] = Database::all(
            'SELECT * FROM sales_refunds WHERE transaction_id=? ORDER BY id DESC',
            [$p['id']]
        );
        Response::ok($txn);
    }

    public function list(Request $req): void
    {
        $u = Auth::user();
        $sql = 'SELECT st.*, o.name AS outlet_name, k.name AS kiosk_name, us.full_name AS worker_name
                FROM sales_transactions st
                JOIN outlets o ON o.id = st.outlet_id
                LEFT JOIN kiosks k ON k.id = st.kiosk_id
                JOIN users us ON us.id = st.user_id
                WHERE st.company_id = ?';
        $args = [$u['company_id']];
        if ($u['role_code'] === 'worker') {
            $sql .= ' AND st.user_id = ?';
            $args[] = $u['id'];
        } elseif ($u['outlet_id']) {
            $sql .= ' AND st.outlet_id = ?';
            $args[] = $u['outlet_id'];
        }
        if ($req->query('from')) {
            $sql .= ' AND st.sold_at >= ?';
            $args[] = $req->query('from') . ' 00:00:00';
        }
        if ($req->query('to')) {
            $sql .= ' AND st.sold_at <= ?';
            $args[] = $req->query('to') . ' 23:59:59';
        }
        $sql .= ' ORDER BY st.id DESC LIMIT 300';
        Response::ok(Database::all($sql, $args));
    }

    // ---- Shift management --------------------------------------------------

    public function openShift(Request $req): void
    {
        $u = Auth::user();
        $open = SalesService::activeShiftId((int) $u['id']);
        if ($open) {
            Response::ok(['shift_id' => $open], 'Shift already open');
        }
        $id = Database::insert(
            'INSERT INTO shifts (company_id, outlet_id, kiosk_id, user_id, opening_float)
             VALUES (?,?,?,?,?)',
            [$u['company_id'], $u['outlet_id'], $u['kiosk_id'], $u['id'], (float) $req->input('opening_float', 0)]
        );
        Audit::log('shift_open', 'shifts', (string) $id);
        Response::ok(['shift_id' => $id], 'Shift opened');
    }

    public function closeShift(Request $req): void
    {
        $u = Auth::user();
        $id = SalesService::activeShiftId((int) $u['id']);
        if (!$id) {
            Response::fail('No open shift', 422);
        }
        $summary = Database::first(
            'SELECT COUNT(*) txns, COALESCE(SUM(total_amount),0) amount, COALESCE(SUM(total_qty),0) qty
             FROM sales_transactions WHERE shift_id = ?',
            [$id]
        );
        Database::run(
            "UPDATE shifts SET status='closed', closed_at=NOW(), closing_amount=? WHERE id=?",
            [$summary['amount'], $id]
        );
        Audit::log('shift_close', 'shifts', (string) $id, null, $summary);
        Response::ok(['shift_id' => $id, 'summary' => $summary], 'Shift closed');
    }

    public function shiftSummary(Request $req): void
    {
        $u = Auth::user();
        $id = SalesService::activeShiftId((int) $u['id']);
        if (!$id) {
            Response::ok(['open' => false]);
        }
        $s = Database::first(
            'SELECT COUNT(*) txns, COALESCE(SUM(total_amount),0) amount,
                    COALESCE(SUM(total_qty),0) qty
             FROM sales_transactions WHERE shift_id = ?',
            [$id]
        );
        Response::ok(['open' => true, 'shift_id' => $id, 'summary' => $s]);
    }
}
