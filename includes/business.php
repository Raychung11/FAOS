<?php
/**
 * AdvisorOS — Entrepreneur business data model access layer.
 * Companies owned by an advisory client, their shareholders /
 * directors / beneficiaries, and dated business-financial snapshots.
 * Everything is tenant-scoped and parameterised; deletes are soft.
 */

declare(strict_types=1);

const COMPANY_ENTITY_TYPES = ['sole_proprietor', 'partnership', 'sdn_bhd', 'berhad', 'llp', 'other'];
const STAKEHOLDER_RELATIONSHIPS = ['self', 'spouse', 'child', 'parent', 'sibling', 'partner', 'other'];

/* ---------------------------------------------------------------- companies */

function companies_for_client(int $clientId): array
{
    $st = db()->prepare(
        'SELECT * FROM companies
         WHERE client_id = ? AND tenant_id = ? AND deleted_at IS NULL
         ORDER BY name'
    );
    $st->execute([$clientId, require_tenant()]);
    return $st->fetchAll();
}

/** Company joined with its owning client (for access checks). */
function company_with_client(int $id): ?array
{
    $st = db()->prepare(
        'SELECT c.*, cl.full_name AS client_name, cl.advisor_id AS client_advisor_id,
                cl.id AS owner_client_id
         FROM companies c
         JOIN clients cl ON cl.id = c.client_id AND cl.deleted_at IS NULL
         WHERE c.id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL'
    );
    $st->execute([$id, require_tenant()]);
    return $st->fetch() ?: null;
}

function company_save(array $in, int $id = 0): int
{
    $tid = require_tenant();
    $d = [
        'name'               => trim(mb_substr((string) ($in['name'] ?? ''), 0, 180)),
        'registration_no'    => trim(mb_substr((string) ($in['registration_no'] ?? ''), 0, 80)),
        'entity_type'        => in_array($in['entity_type'] ?? '', COMPANY_ENTITY_TYPES, true)
                                 ? $in['entity_type'] : 'sdn_bhd',
        'industry'           => trim(mb_substr((string) ($in['industry'] ?? ''), 0, 120)),
        'incorporation_date' => ($in['incorporation_date'] ?? '') !== '' ? $in['incorporation_date'] : null,
        'ownership_pct'      => min(100, max(0, (float) ($in['ownership_pct'] ?? 0))),
        'status'             => in_array($in['status'] ?? '', ['active', 'dormant', 'closed'], true)
                                 ? $in['status'] : 'active',
        'notes'              => trim(mb_substr((string) ($in['notes'] ?? ''), 0, 4000)),
    ];

    if ($id > 0) {
        db()->prepare(
            'UPDATE companies SET name=?, registration_no=?, entity_type=?, industry=?,
             incorporation_date=?, ownership_pct=?, status=?, notes=?
             WHERE id=? AND tenant_id=?'
        )->execute([...array_values($d), $id, $tid]);
        return $id;
    }
    db()->prepare(
        'INSERT INTO companies
         (tenant_id, client_id, name, registration_no, entity_type, industry,
          incorporation_date, ownership_pct, status, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$tid, (int) $in['client_id'], ...array_values($d), current_user()['id']]);
    return (int) db()->lastInsertId();
}

function company_delete(int $id): void
{
    db()->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=? AND tenant_id=?')
        ->execute([$id, require_tenant()]);
}

/* ----------------------------------------------------------- stakeholders */

function stakeholders_for_company(int $companyId): array
{
    $st = db()->prepare(
        'SELECT * FROM company_stakeholders
         WHERE company_id = ? AND tenant_id = ? AND deleted_at IS NULL
         ORDER BY is_shareholder DESC, shareholding_pct DESC, name'
    );
    $st->execute([$companyId, require_tenant()]);
    return $st->fetchAll();
}

function stakeholder_get(int $id): ?array
{
    $st = db()->prepare(
        'SELECT * FROM company_stakeholders WHERE id=? AND tenant_id=? AND deleted_at IS NULL'
    );
    $st->execute([$id, require_tenant()]);
    return $st->fetch() ?: null;
}

function stakeholder_save(array $in, int $id = 0): int
{
    $tid = require_tenant();
    $d = [
        'name'             => trim(mb_substr((string) ($in['name'] ?? ''), 0, 150)),
        'nric_passport'    => trim(mb_substr((string) ($in['nric_passport'] ?? ''), 0, 60)),
        'relationship'     => in_array($in['relationship'] ?? '', STAKEHOLDER_RELATIONSHIPS, true)
                               ? $in['relationship'] : 'other',
        'is_shareholder'   => !empty($in['is_shareholder']) ? 1 : 0,
        'shareholding_pct' => min(100, max(0, (float) ($in['shareholding_pct'] ?? 0))),
        'is_director'      => !empty($in['is_director']) ? 1 : 0,
        'is_beneficiary'   => !empty($in['is_beneficiary']) ? 1 : 0,
        'benefit_pct'      => min(100, max(0, (float) ($in['benefit_pct'] ?? 0))),
        'email'            => trim(mb_substr((string) ($in['email'] ?? ''), 0, 150)),
        'phone'            => trim(mb_substr((string) ($in['phone'] ?? ''), 0, 40)),
        'notes'            => trim(mb_substr((string) ($in['notes'] ?? ''), 0, 255)),
    ];

    if ($id > 0) {
        db()->prepare(
            'UPDATE company_stakeholders SET name=?, nric_passport=?, relationship=?,
             is_shareholder=?, shareholding_pct=?, is_director=?, is_beneficiary=?,
             benefit_pct=?, email=?, phone=?, notes=? WHERE id=? AND tenant_id=?'
        )->execute([...array_values($d), $id, $tid]);
        return $id;
    }
    db()->prepare(
        'INSERT INTO company_stakeholders
         (tenant_id, company_id, name, nric_passport, relationship, is_shareholder,
          shareholding_pct, is_director, is_beneficiary, benefit_pct, email, phone,
          notes, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$tid, (int) $in['company_id'], ...array_values($d), current_user()['id']]);
    return (int) db()->lastInsertId();
}

function stakeholder_delete(int $id): void
{
    db()->prepare('UPDATE company_stakeholders SET deleted_at=NOW() WHERE id=? AND tenant_id=?')
        ->execute([$id, require_tenant()]);
}

/* ------------------------------------------------------ business financials */

const BF_FIELDS = ['revenue', 'ebitda', 'net_profit', 'total_assets', 'total_liabilities',
    'receivables', 'inventory', 'cash', 'bank_loans', 'shareholder_loans',
    'personal_guarantee', 'owner_remuneration', 'dividends_paid'];

function bf_latest(int $companyId): ?array
{
    $st = db()->prepare(
        'SELECT * FROM business_financials
         WHERE company_id=? AND tenant_id=? AND deleted_at IS NULL
         ORDER BY snapshot_date DESC, id DESC LIMIT 1'
    );
    $st->execute([$companyId, require_tenant()]);
    return $st->fetch() ?: null;
}

function bf_list(int $companyId): array
{
    $st = db()->prepare(
        'SELECT * FROM business_financials
         WHERE company_id=? AND tenant_id=? AND deleted_at IS NULL
         ORDER BY snapshot_date DESC, id DESC LIMIT 24'
    );
    $st->execute([$companyId, require_tenant()]);
    return $st->fetchAll();
}

function bf_save(int $companyId, array $in): int
{
    $tid = require_tenant();
    $vals = [$tid, $companyId,
        ($in['snapshot_date'] ?? '') !== '' ? $in['snapshot_date'] : date('Y-m-d')];
    foreach (BF_FIELDS as $f) {
        $vals[] = max(0, (float) ($in[$f] ?? 0));
    }
    $cols = 'tenant_id, company_id, snapshot_date, ' . implode(', ', BF_FIELDS);
    $ph   = rtrim(str_repeat('?,', count($vals)), ',');
    $vals[] = current_user()['id'];
    db()->prepare("INSERT INTO business_financials ($cols, created_by) VALUES ($ph,?)")
        ->execute($vals);
    return (int) db()->lastInsertId();
}

/* --------------------------------------------------------------- summary */

/** Headline business exposure for a client (feeds the client 360 + future engines). */
function business_summary(int $clientId): array
{
    $cos = companies_for_client($clientId);
    $sum = ['companies' => count($cos), 'revenue' => 0.0, 'ebitda' => 0.0,
            'personal_guarantee' => 0.0, 'owner_remuneration' => 0.0];
    foreach ($cos as $c) {
        $bf = bf_latest((int) $c['id']);
        if ($bf) {
            $sum['revenue']            += (float) $bf['revenue'];
            $sum['ebitda']             += (float) $bf['ebitda'];
            $sum['personal_guarantee'] += (float) $bf['personal_guarantee'];
            $sum['owner_remuneration'] += (float) $bf['owner_remuneration'];
        }
    }
    return $sum;
}
