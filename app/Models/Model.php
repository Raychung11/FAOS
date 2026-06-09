<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Lightweight active-table helper for master-data CRUD. */
class Model
{
    protected string $table;
    /** @var string[] mass-assignable columns */
    protected array $fillable = [];
    protected bool $companyScoped = true;

    public function all(int $companyId, int $limit = 500, int $offset = 0, ?string $search = null, array $searchCols = []): array
    {
        $where = $this->companyScoped ? 'company_id = ?' : '1=1';
        $args = $this->companyScoped ? [$companyId] : [];
        if ($search !== null && $search !== '' && $searchCols) {
            $like = '%' . $search . '%';
            $parts = [];
            foreach ($searchCols as $col) {
                $parts[] = "$col LIKE ?";
                $args[] = $like;
            }
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $args[] = $limit;
        $args[] = $offset;
        return Database::all(
            "SELECT * FROM {$this->table} WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?",
            $args
        );
    }

    public function find(int $id, ?int $companyId = null): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ?";
        $args = [$id];
        if ($this->companyScoped && $companyId !== null) {
            $sql .= ' AND company_id = ?';
            $args[] = $companyId;
        }
        return Database::first($sql . ' LIMIT 1', $args);
    }

    public function create(array $data): int
    {
        $data = $this->filter($data);
        $cols = array_keys($data);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        return Database::insert(
            "INSERT INTO {$this->table} (" . implode(',', $cols) . ") VALUES ($ph)",
            array_values($data)
        );
    }

    public function update(int $id, array $data): void
    {
        $data = $this->filter($data);
        if (!$data) {
            return;
        }
        $set = implode(',', array_map(fn ($c) => "$c = ?", array_keys($data)));
        $args = array_values($data);
        $args[] = $id;
        Database::run("UPDATE {$this->table} SET $set WHERE id = ?", $args);
    }

    public function delete(int $id): void
    {
        // Soft-deactivate when the column exists, else hard delete.
        $cols = Database::all("SHOW COLUMNS FROM {$this->table} LIKE 'is_active'");
        if ($cols) {
            Database::run("UPDATE {$this->table} SET is_active = 0 WHERE id = ?", [$id]);
        } else {
            Database::run("DELETE FROM {$this->table} WHERE id = ?", [$id]);
        }
    }

    protected function filter(array $data): array
    {
        return array_intersect_key($data, array_flip($this->fillable));
    }
}
