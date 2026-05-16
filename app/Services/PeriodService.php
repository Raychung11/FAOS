<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Accounting period close/lock. A closed period freezes financially material
 * backdated activity within its date range.
 */
final class PeriodService
{
    public static function closedPeriod(int $company, string $date): ?array
    {
        $d = date('Y-m-d', strtotime($date) ?: time());
        return Database::first(
            "SELECT * FROM accounting_periods
             WHERE company_id = ? AND status = 'closed'
               AND ? BETWEEN period_start AND period_end
             ORDER BY period_end DESC LIMIT 1",
            [$company, $d]
        );
    }

    /** Throws if $date falls inside a closed period. */
    public static function assertOpen(int $company, string $date, string $action): void
    {
        $p = self::closedPeriod($company, $date);
        if ($p) {
            throw new \RuntimeException(sprintf(
                '%s: accounting period %s to %s is closed. Reopen it or post into the current period.',
                $action, $p['period_start'], $p['period_end']
            ));
        }
    }

    public static function list(int $company): array
    {
        return Database::all(
            'SELECT ap.*, cu.full_name AS closed_by_name, ru.full_name AS reopened_by_name
             FROM accounting_periods ap
             LEFT JOIN users cu ON cu.id = ap.closed_by
             LEFT JOIN users ru ON ru.id = ap.reopened_by
             WHERE ap.company_id = ?
             ORDER BY ap.period_start DESC',
            [$company]
        );
    }

    public static function close(int $company, string $start, string $end, ?int $userId, ?string $note): array
    {
        if (strtotime($end) < strtotime($start)) {
            throw new \RuntimeException('period_end must be on or after period_start');
        }
        $existing = Database::first(
            'SELECT id FROM accounting_periods WHERE company_id=? AND period_start=? AND period_end=?',
            [$company, $start, $end]
        );
        if ($existing) {
            Database::run(
                "UPDATE accounting_periods
                 SET status='closed', closed_by=?, closed_at=NOW(), note=?,
                     reopened_by=NULL, reopened_at=NULL
                 WHERE id=?",
                [$userId, $note, $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $id = Database::insert(
                'INSERT INTO accounting_periods
                   (company_id, period_start, period_end, status, note, closed_by, closed_at)
                 VALUES (?,?,?,?,?,?,NOW())',
                [$company, $start, $end, 'closed', $note, $userId]
            );
        }
        return Database::first('SELECT * FROM accounting_periods WHERE id=?', [$id]);
    }

    public static function reopen(int $company, int $id, ?int $userId): array
    {
        $p = Database::first(
            'SELECT * FROM accounting_periods WHERE id=? AND company_id=?',
            [$id, $company]
        );
        if (!$p) {
            throw new \RuntimeException('Period not found');
        }
        Database::run(
            "UPDATE accounting_periods
             SET status='open', reopened_by=?, reopened_at=NOW() WHERE id=?",
            [$userId, $id]
        );
        return Database::first('SELECT * FROM accounting_periods WHERE id=?', [$id]);
    }
}
