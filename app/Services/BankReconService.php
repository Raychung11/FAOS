<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Payment / bank reconciliation.
 *
 * Card terminals settle to the bank in batches (per day per scheme) net of
 * MDR/fees, often 1-3 days later, while bank transfers / deposits appear as
 * individual bank lines. So matching is:
 *   - BATCH level for card_visa/master/amex, atm_debit, duitnow_qr
 *   - LINE level  for bank_transfer, deposit
 *   - cash is informational (not expected as a bank credit)
 */
final class BankReconService
{
    private const BATCH_CHANNELS = ['card_visa', 'card_master', 'card_amex', 'atm_debit', 'duitnow_qr'];
    private const LINE_CHANNELS  = ['bank_transfer', 'deposit', 'other'];

    // ---- CSV parsing -------------------------------------------------------

    /** Read up to $sample data rows for the column-mapping UI. */
    public static function preview(string $path, string $delimiter = ',', bool $hasHeader = true, int $sample = 8): array
    {
        $rows = [];
        $headers = [];
        if (($h = fopen($path, 'r')) === false) {
            throw new \RuntimeException('Cannot open uploaded file');
        }
        $i = 0;
        while (($r = fgetcsv($h, 0, $delimiter)) !== false) {
            if ($r === [null] || $r === false) {
                continue;
            }
            if ($i === 0 && $hasHeader) {
                $headers = array_map('trim', $r);
            } elseif (count($rows) < $sample) {
                $rows[] = $r;
            }
            $i++;
            if (count($rows) >= $sample && $i > $sample + 2) {
                break;
            }
        }
        fclose($h);
        if (!$hasHeader) {
            $cols = $rows ? count($rows[0]) : 0;
            for ($c = 0; $c < $cols; $c++) {
                $headers[] = 'col' . $c;
            }
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @return array parsed records keyed by field name */
    public static function parse(string $path, array $mapping): array
    {
        $delim  = $mapping['delimiter'] ?? ',';
        $header = (bool) ($mapping['has_header'] ?? true);
        $cols   = $mapping['columns'] ?? [];
        $dateFmt = $mapping['date_format'] ?? 'Y-m-d';

        $h = fopen($path, 'r');
        if ($h === false) {
            throw new \RuntimeException('Cannot open file for parsing');
        }
        $headerRow = [];
        $out = [];
        $idx = 0;
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if ($r === [null]) {
                continue;
            }
            if ($idx === 0 && $header) {
                $headerRow = array_map('trim', $r);
                $idx++;
                continue;
            }
            $get = static function (string $field) use ($cols, $r, $headerRow, $header) {
                if (!isset($cols[$field]) || $cols[$field] === '' || $cols[$field] === null) {
                    return null;
                }
                $key = $cols[$field];
                if ($header && !is_numeric($key)) {
                    $pos = array_search($key, $headerRow, true);
                    return $pos === false ? null : ($r[$pos] ?? null);
                }
                return $r[(int) $key] ?? null;
            };
            $out[] = ['_get' => $get, 'fmt' => $dateFmt];
            $idx++;
        }
        fclose($h);
        return $out;
    }

    public static function parseDate(?string $v, string $fmt): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat($fmt, $v);
        if ($d instanceof \DateTime) {
            return $d->format('Y-m-d');
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    public static function parseAmount(?string $v): float
    {
        if ($v === null) {
            return 0.0;
        }
        $neg = str_contains($v, '(') || str_starts_with(trim($v), '-');
        $clean = preg_replace('/[^0-9.]/', '', str_replace(',', '', $v));
        $f = $clean === '' ? 0.0 : (float) $clean;
        return $neg ? -$f : $f;
    }

    public static function normalizeChannel(?string $raw): string
    {
        $s = strtolower((string) $raw);
        return match (true) {
            str_contains($s, 'cash')                        => 'cash',
            str_contains($s, 'atm')                         => 'atm_debit',
            str_contains($s, 'duitnow') || str_contains($s, 'qr') => 'duitnow_qr',
            str_contains($s, 'bank') || str_contains($s, 'transfer') => 'bank_transfer',
            str_contains($s, 'visa')                        => 'card_visa',
            str_contains($s, 'master')                      => 'card_master',
            str_contains($s, 'amex') || str_contains($s, 'american express') => 'card_amex',
            str_contains($s, 'deposit')                     => 'deposit',
            default                                         => 'other',
        };
    }

    // ---- Import ------------------------------------------------------------

    public static function importTerminal(int $company, int $importId, string $path, array $mapping): array
    {
        $records = self::parse($path, $mapping);
        $count = 0;
        $total = 0.0;
        $minD = null;
        $maxD = null;

        Database::transaction(function () use ($records, $company, $importId, &$count, &$total, &$minD, &$maxD) {
            foreach ($records as $rec) {
                $g = $rec['_get'];
                $fmt = $rec['fmt'];
                $amount = self::parseAmount($g('amount'));
                $date = self::parseDate($g('date'), $fmt);
                if ($date === null || $amount === 0.0) {
                    continue;
                }
                $channel = ($ch = $g('channel'))
                    ? self::normalizeChannel($ch)
                    : self::normalizeChannel($g('type'));
                Database::insert(
                    'INSERT INTO terminal_transactions
                       (company_id, import_id, txn_date, txn_time, txn_ref, raw_type,
                        channel, card_last4, payer, amount, fee)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $company, $importId, $date,
                        ($t = $g('time')) ? substr(trim($t), 0, 8) : null,
                        $g('ref'), $g('type'), $channel,
                        $g('card_last4'), $g('payer'),
                        $amount, self::parseAmount($g('fee')),
                    ]
                );
                $count++;
                $total += $amount;
                $minD = $minD === null ? $date : min($minD, $date);
                $maxD = $maxD === null ? $date : max($maxD, $date);
            }
            // Build settlement batches per (date, channel).
            $groups = Database::all(
                'SELECT txn_date, channel, COUNT(*) c, SUM(amount) g, SUM(fee) f
                 FROM terminal_transactions WHERE import_id = ?
                 GROUP BY txn_date, channel',
                [$importId]
            );
            foreach ($groups as $grp) {
                $ch = $grp['channel'];
                // Line-itemised channels (transfer/deposit/other) reconcile per
                // transaction, not as a batch -> no settlement_batch row.
                if (in_array($ch, self::LINE_CHANNELS, true)) {
                    continue;
                }
                $expected = round((float) $grp['g'] - (float) $grp['f'], 2);
                $bid = Database::insert(
                    'INSERT INTO settlement_batches
                       (company_id, terminal_import_id, batch_date, channel, txn_count,
                        gross_total, fee_total, expected_net, status)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [
                        $company, $importId, $grp['txn_date'], $ch,
                        (int) $grp['c'], (float) $grp['g'], (float) $grp['f'], $expected,
                        $ch === 'cash' ? 'not_expected' : 'pending',
                    ]
                );
                Database::run(
                    'UPDATE terminal_transactions SET batch_id = ?, match_status = "batched"
                     WHERE import_id = ? AND txn_date = ? AND channel = ?',
                    [$bid, $importId, $grp['txn_date'], $ch]
                );
            }
        });

        Database::run(
            'UPDATE recon_imports SET row_count=?, total_amount=?, period_start=?, period_end=? WHERE id=?',
            [$count, $total, $minD, $maxD, $importId]
        );
        return ['rows' => $count, 'total' => round($total, 2)];
    }

    public static function importBank(int $company, int $importId, string $path, array $mapping): array
    {
        $records = self::parse($path, $mapping);
        $count = 0;
        $totalCr = 0.0;
        $minD = null;
        $maxD = null;

        Database::transaction(function () use ($records, $company, $importId, &$count, &$totalCr, &$minD, &$maxD) {
            foreach ($records as $rec) {
                $g = $rec['_get'];
                $fmt = $rec['fmt'];
                $date = self::parseDate($g('date'), $fmt);
                if ($date === null) {
                    continue;
                }
                // Either a single signed "amount" column, or debit + credit.
                if ($g('amount') !== null && $g('amount') !== '') {
                    $a = self::parseAmount($g('amount'));
                    $credit = $a > 0 ? $a : 0.0;
                    $debit  = $a < 0 ? -$a : 0.0;
                } else {
                    $credit = self::parseAmount($g('credit'));
                    $debit  = self::parseAmount($g('debit'));
                }
                if ($credit == 0.0 && $debit == 0.0) {
                    continue;
                }
                Database::insert(
                    'INSERT INTO bank_transactions
                       (company_id, import_id, txn_date, description, reference, debit, credit, balance)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [
                        $company, $importId, $date,
                        $g('description'), $g('reference'),
                        $debit, $credit,
                        ($b = $g('balance')) !== null && $b !== '' ? self::parseAmount($b) : null,
                    ]
                );
                $count++;
                $totalCr += $credit;
                $minD = $minD === null ? $date : min($minD, $date);
                $maxD = $maxD === null ? $date : max($maxD, $date);
            }
        });

        Database::run(
            'UPDATE recon_imports SET row_count=?, total_amount=?, period_start=?, period_end=? WHERE id=?',
            [$count, $totalCr, $minD, $maxD, $importId]
        );
        return ['rows' => $count, 'credits_total' => round($totalCr, 2)];
    }

    // ---- Matching engine ---------------------------------------------------

    private static function setting(int $company, string $key, float $default): float
    {
        $v = Database::scalar(
            'SELECT svalue FROM settings WHERE skey=? AND (company_id=? OR company_id IS NULL)
             ORDER BY company_id IS NULL LIMIT 1',
            [$key, $company]
        );
        return $v !== false && $v !== null ? (float) $v : $default;
    }

    public static function run(int $company): array
    {
        $feePct  = self::setting($company, 'recon_fee_pct', 3.0);
        $window  = (int) self::setting($company, 'recon_date_window_days', 3);
        $eps     = self::setting($company, 'recon_epsilon', 0.50);

        $batchMatched = 0;
        $lineMatched = 0;

        // ---- Batch-level (card / QR / ATM) ----
        $batches = Database::all(
            "SELECT * FROM settlement_batches
             WHERE company_id = ? AND bank_transaction_id IS NULL
               AND channel IN ('card_visa','card_master','card_amex','atm_debit','duitnow_qr')
               AND status IN ('pending','unmatched','short','over','fee_variance')
             ORDER BY batch_date, id",
            [$company]
        );
        foreach ($batches as $b) {
            $expected = (float) $b['expected_net'];
            $gross = (float) $b['gross_total'];
            $cand = Database::all(
                "SELECT * FROM bank_transactions
                 WHERE company_id = ? AND match_status = 'unmatched' AND credit > 0
                   AND txn_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)
                 ORDER BY ABS(credit - ?) ASC, txn_date ASC",
                [$company, $b['batch_date'], $b['batch_date'], $window, $expected]
            );
            $pick = null;
            foreach ($cand as $c) {
                $cr = (float) $c['credit'];
                $maxFee = $gross * $feePct / 100 + $eps;
                if (abs($cr - $expected) <= $eps
                    || ($cr <= $gross + $eps && $gross - $cr <= $maxFee)) {
                    $pick = $c;
                    break;
                }
            }
            if (!$pick) {
                continue;
            }
            $cr = (float) $pick['credit'];
            $variance = round($cr - $expected, 2);
            $impliedFee = round(max(0, $gross - $cr), 2);
            $status = match (true) {
                abs($cr - $expected) <= $eps                       => 'matched',
                $cr < $expected && ($gross - $cr) <= $gross * $feePct / 100 + $eps => 'fee_variance',
                $cr > $gross + $eps                                => 'over',
                default                                            => 'short',
            };
            Database::transaction(function () use ($b, $pick, $cr, $variance, $impliedFee, $status) {
                Database::run(
                    'UPDATE settlement_batches SET bank_transaction_id=?, bank_credit=?,
                            variance=?, implied_fee=?, status=? WHERE id=?',
                    [$pick['id'], $cr, $variance, $impliedFee, $status, $b['id']]
                );
                Database::run(
                    "UPDATE bank_transactions SET match_status='matched', matched_batch_id=? WHERE id=?",
                    [$b['id'], $pick['id']]
                );
                Database::run(
                    "UPDATE terminal_transactions SET match_status='matched' WHERE batch_id=?",
                    [$b['id']]
                );
            });
            $batchMatched++;
        }

        // ---- Line-level (bank transfer / deposit / other) ----
        $lines = Database::all(
            "SELECT * FROM terminal_transactions
             WHERE company_id = ? AND match_status IN ('unmatched','batched')
               AND channel IN ('bank_transfer','deposit','other')
             ORDER BY txn_date, id",
            [$company]
        );
        foreach ($lines as $tt) {
            $amt = (float) $tt['amount'];
            $bank = Database::first(
                "SELECT * FROM bank_transactions
                 WHERE company_id = ? AND match_status='unmatched'
                   AND ABS(credit - ?) <= ?
                   AND txn_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)
                 ORDER BY ABS(credit - ?) ASC, txn_date ASC LIMIT 1",
                [$company, $amt, $eps, $tt['txn_date'], $tt['txn_date'], $window, $amt]
            );
            if (!$bank) {
                continue;
            }
            Database::transaction(function () use ($tt, $bank) {
                Database::run(
                    "UPDATE terminal_transactions SET match_status='matched', bank_txn_id=? WHERE id=?",
                    [$bank['id'], $tt['id']]
                );
                Database::run(
                    "UPDATE bank_transactions SET match_status='matched', matched_terminal_id=? WHERE id=?",
                    [$tt['id'], $bank['id']]
                );
            });
            $lineMatched++;
        }

        // Batches with no bank match remain flagged 'unmatched'.
        Database::run(
            "UPDATE settlement_batches SET status='unmatched'
             WHERE company_id=? AND bank_transaction_id IS NULL AND status='pending'",
            [$company]
        );

        return [
            'batch_matched' => $batchMatched,
            'line_matched'  => $lineMatched,
        ] + self::summary($company);
    }

    public static function summary(int $company): array
    {
        $b = Database::first(
            "SELECT
               COUNT(*) batches,
               SUM(status='matched') matched,
               SUM(status='fee_variance') fee_variance,
               SUM(status IN ('short','over')) variance,
               SUM(status='unmatched') unmatched,
               COALESCE(SUM(gross_total),0) gross,
               COALESCE(SUM(bank_credit),0) bank_credited,
               COALESCE(SUM(implied_fee),0) implied_fees
             FROM settlement_batches WHERE company_id=?",
            [$company]
        );
        $unbank = (int) Database::scalar(
            "SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND match_status='unmatched' AND credit>0",
            [$company]
        );
        $unterm = (int) Database::scalar(
            "SELECT COUNT(*) FROM terminal_transactions
             WHERE company_id=? AND match_status IN ('unmatched','batched')
               AND channel IN ('bank_transfer','deposit','other')",
            [$company]
        );
        return [
            'batches'              => (int) $b['batches'],
            'matched'              => (int) $b['matched'],
            'fee_variance'         => (int) $b['fee_variance'],
            'variance'             => (int) $b['variance'],
            'unmatched_batches'    => (int) $b['unmatched'],
            'unmatched_bank_lines' => $unbank,
            'unmatched_terminal_lines' => $unterm,
            'gross_total'          => round((float) $b['gross'], 2),
            'bank_credited'        => round((float) $b['bank_credited'], 2),
            'implied_fees'         => round((float) $b['implied_fees'], 2),
        ];
    }

    /** Manual override: link a batch (or terminal line) to a bank transaction. */
    public static function manualMatch(int $company, ?int $batchId, ?int $terminalId, int $bankTxnId): void
    {
        $bank = Database::first(
            'SELECT * FROM bank_transactions WHERE id=? AND company_id=?',
            [$bankTxnId, $company]
        );
        if (!$bank) {
            throw new \RuntimeException('Bank transaction not found');
        }
        if ($batchId) {
            $b = Database::first('SELECT * FROM settlement_batches WHERE id=? AND company_id=?', [$batchId, $company]);
            if (!$b) {
                throw new \RuntimeException('Batch not found');
            }
            $cr = (float) $bank['credit'];
            $expected = (float) $b['expected_net'];
            $gross = (float) $b['gross_total'];
            $variance = round($cr - $expected, 2);
            $impliedFee = round(max(0, $gross - $cr), 2);
            // Classify like the auto path so a manual link still surfaces
            // fee/short/over discrepancies instead of hiding them.
            $feePct = self::setting($company, 'recon_fee_pct', 3.0);
            $eps    = self::setting($company, 'recon_epsilon', 0.50);
            $status = match (true) {
                abs($cr - $expected) <= $eps                                  => 'matched',
                $cr < $expected && ($gross - $cr) <= $gross * $feePct / 100 + $eps => 'fee_variance',
                $cr > $gross + $eps                                           => 'over',
                default                                                       => 'short',
            };
            Database::transaction(function () use ($b, $bank, $cr, $variance, $impliedFee, $status) {
                Database::run(
                    "UPDATE settlement_batches SET bank_transaction_id=?, bank_credit=?, variance=?,
                            implied_fee=?, status=? WHERE id=?",
                    [$bank['id'], $cr, $variance, $impliedFee, $status, $b['id']]
                );
                Database::run(
                    "UPDATE bank_transactions SET match_status='manual', matched_batch_id=? WHERE id=?",
                    [$b['id'], $bank['id']]
                );
                Database::run("UPDATE terminal_transactions SET match_status='manual' WHERE batch_id=?", [$b['id']]);
            });
            return;
        }
        if ($terminalId) {
            Database::transaction(function () use ($terminalId, $bank, $company) {
                Database::run(
                    "UPDATE terminal_transactions SET match_status='manual', bank_txn_id=?
                     WHERE id=? AND company_id=?",
                    [$bank['id'], $terminalId, $company]
                );
                Database::run(
                    "UPDATE bank_transactions SET match_status='manual', matched_terminal_id=? WHERE id=?",
                    [$terminalId, $bank['id']]
                );
            });
        }
    }
}
