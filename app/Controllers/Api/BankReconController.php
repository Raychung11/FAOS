<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\BankReconService;

final class BankReconController extends Controller
{
    /** Multipart fields arrive as strings; parse leniently. */
    private function boolish(mixed $v, bool $default = true): bool
    {
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    private function arrayish(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    private function uploadDir(): string
    {
        $dir = STORAGE_PATH . '/uploads/recon';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function takeUpload(): string
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            Response::fail('No file uploaded (field "file")', 422);
        }
        if ($f['size'] > 8 * 1024 * 1024) {
            Response::fail('File too large (max 8MB)', 422);
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            Response::fail('Only .csv / .txt files are accepted', 422);
        }
        $token = bin2hex(random_bytes(12));
        $dest = $this->uploadDir() . '/' . $token . '.csv';
        if (!move_uploaded_file($f['tmp_name'], $dest)
            && !rename($f['tmp_name'], $dest)) {       // rename: test harness fallback
            Response::fail('Failed to store uploaded file', 500);
        }
        $_SESSION['recon_upload'][$token] = ['path' => $dest, 'name' => $f['name']];
        return $token;
    }

    /** Step 1: upload + preview rows so the user can map columns. */
    public function preview(Request $req): void
    {
        $token = $this->takeUpload();
        $path  = $_SESSION['recon_upload'][$token]['path'];
        $p = BankReconService::preview(
            $path,
            (string) $req->input('delimiter', ','),
            $this->boolish($req->input('has_header'), true)
        );
        Response::ok([
            'token'   => $token,
            'name'    => $_SESSION['recon_upload'][$token]['name'],
            'headers' => $p['headers'],
            'rows'    => $p['rows'],
        ]);
    }

    // ---- Saved column mappings --------------------------------------------

    public function mappings(Request $req): void
    {
        $sql = 'SELECT * FROM recon_column_mappings WHERE company_id = ?';
        $args = [$this->companyId()];
        if ($req->query('source')) {
            $sql .= ' AND source_type = ?';
            $args[] = $req->query('source');
        }
        Response::ok(Database::all($sql . ' ORDER BY name', $args));
    }

    public function saveMapping(Request $req): void
    {
        $d = $this->validate($req, [
            'source_type' => 'required|in:terminal,bank',
            'name'        => 'required|string|max:96',
        ]);
        $columns = $this->arrayish($req->input('columns'));
        if (!$columns) {
            Response::fail('columns map required', 422);
        }
        $id = Database::insert(
            'INSERT INTO recon_column_mappings
               (company_id, source_type, name, delimiter, has_header, date_format, columns_json, created_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE delimiter=VALUES(delimiter), has_header=VALUES(has_header),
               date_format=VALUES(date_format), columns_json=VALUES(columns_json)',
            [
                $this->companyId(), $d['source_type'], $d['name'],
                $req->input('delimiter', ','), $this->boolish($req->input('has_header'), true) ? 1 : 0,
                $req->input('date_format', 'Y-m-d'), json_encode($columns), Auth::id(),
            ]
        );
        Audit::log('recon_mapping_save', 'recon_column_mappings', (string) $id, null, $d);
        Response::ok(['id' => $id], 'Mapping saved');
    }

    /** Step 2: parse the previously uploaded file with a mapping and persist. */
    public function import(Request $req): void
    {
        $token = $req->input('token');
        $info = $_SESSION['recon_upload'][$token] ?? null;
        if (!$info || !is_file($info['path'])) {
            Response::fail('Upload token expired - please re-upload', 422);
        }
        $sourceType = $req->input('source_type');
        if (!in_array($sourceType, ['terminal', 'bank'], true)) {
            Response::fail('source_type must be terminal|bank', 422);
        }

        $mapping = $this->resolveMapping($req);

        $importId = Database::insert(
            'INSERT INTO recon_imports
               (company_id, source_type, original_name, stored_path, bank_label, mapping_id, note, imported_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $this->companyId(), $sourceType, $info['name'], $info['path'],
                $req->input('bank_label'), $req->input('mapping_id'),
                $req->input('note'), Auth::id(),
            ]
        );

        $result = $sourceType === 'terminal'
            ? BankReconService::importTerminal($this->companyId(), $importId, $info['path'], $mapping)
            : BankReconService::importBank($this->companyId(), $importId, $info['path'], $mapping);

        unset($_SESSION['recon_upload'][$token]);
        Audit::log('recon_import', 'recon_imports', (string) $importId, null, ['type' => $sourceType] + $result);
        Response::ok(['import_id' => $importId] + $result, 'Imported');
    }

    private function resolveMapping(Request $req): array
    {
        if ($req->input('mapping_id')) {
            $m = Database::first(
                'SELECT * FROM recon_column_mappings WHERE id=? AND company_id=?',
                [$req->input('mapping_id'), $this->companyId()]
            );
            if (!$m) {
                Response::fail('Mapping not found', 404);
            }
            return [
                'delimiter'   => $m['delimiter'],
                'has_header'  => (bool) $m['has_header'],
                'date_format' => $m['date_format'],
                'columns'     => json_decode($m['columns_json'], true) ?: [],
            ];
        }
        $columns = $this->arrayish($req->input('columns'));
        if (!$columns) {
            Response::fail('Provide mapping_id or an inline columns map', 422);
        }
        return [
            'delimiter'   => $req->input('delimiter', ','),
            'has_header'  => $this->boolish($req->input('has_header'), true),
            'date_format' => $req->input('date_format', 'Y-m-d'),
            'columns'     => $columns,
        ];
    }

    // ---- Reconcile + views -------------------------------------------------

    public function run(Request $req): void
    {
        $res = BankReconService::run($this->companyId());
        Audit::log('recon_run', 'settlement_batches', null, null, $res);
        Response::ok($res, 'Reconciliation complete');
    }

    public function summary(Request $req): void
    {
        Response::ok(BankReconService::summary($this->companyId()));
    }

    public function batches(Request $req): void
    {
        $sql = "SELECT sb.*, ri.original_name AS source_file,
                       bt.txn_date AS bank_date, bt.description AS bank_desc
                FROM settlement_batches sb
                JOIN recon_imports ri ON ri.id = sb.terminal_import_id
                LEFT JOIN bank_transactions bt ON bt.id = sb.bank_transaction_id
                WHERE sb.company_id = ?";
        $args = [$this->companyId()];
        if ($req->query('status')) {
            $sql .= ' AND sb.status = ?';
            $args[] = $req->query('status');
        }
        $sql .= ' ORDER BY sb.batch_date DESC, sb.channel';
        Response::ok(Database::all($sql, $args));
    }

    public function exceptions(Request $req): void
    {
        Response::ok([
            'variance_batches' => Database::all(
                "SELECT * FROM settlement_batches
                 WHERE company_id=? AND status IN ('short','over','fee_variance','unmatched')
                 ORDER BY batch_date DESC",
                [$this->companyId()]
            ),
            'unmatched_bank' => Database::all(
                "SELECT * FROM bank_transactions
                 WHERE company_id=? AND match_status='unmatched' AND credit>0
                 ORDER BY txn_date DESC LIMIT 200",
                [$this->companyId()]
            ),
            'unmatched_terminal' => Database::all(
                "SELECT * FROM terminal_transactions
                 WHERE company_id=? AND match_status IN ('unmatched','batched')
                   AND channel IN ('bank_transfer','deposit','other')
                 ORDER BY txn_date DESC LIMIT 200",
                [$this->companyId()]
            ),
        ]);
    }

    public function imports(Request $req): void
    {
        Response::ok(Database::all(
            'SELECT ri.*, u.full_name AS imported_by_name
             FROM recon_imports ri LEFT JOIN users u ON u.id = ri.imported_by
             WHERE ri.company_id = ? ORDER BY ri.id DESC LIMIT 100',
            [$this->companyId()]
        ));
    }

    public function match(Request $req): void
    {
        $bankTxn = (int) $req->input('bank_txn_id');
        if (!$bankTxn) {
            Response::fail('bank_txn_id required', 422);
        }
        BankReconService::manualMatch(
            $this->companyId(),
            $req->input('batch_id') ? (int) $req->input('batch_id') : null,
            $req->input('terminal_txn_id') ? (int) $req->input('terminal_txn_id') : null,
            $bankTxn
        );
        Audit::log('recon_manual_match', 'settlement_batches', (string) ($req->input('batch_id') ?? ''));
        Response::ok(null, 'Matched');
    }
}
