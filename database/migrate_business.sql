-- ---------------------------------------------------------------------
-- AdvisorOS — Additive migration: Entrepreneur business data model.
--
-- Adds the company / shareholder-director / business-financials layer
-- that the Entrepreneur Wealth Advisory OS is built on.
--
-- SAFE TO RUN ON A LIVE DATABASE: only CREATE TABLE IF NOT EXISTS —
-- no DROP, no data change, fully idempotent. Apply with:
--     php setup/migrate.php          (CLI)
--     /setup/migrate.php             (web, super admin only)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS companies (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    client_id          BIGINT UNSIGNED NOT NULL,
    name               VARCHAR(180) NOT NULL,
    registration_no    VARCHAR(80)  NULL,
    entity_type        ENUM('sole_proprietor','partnership','sdn_bhd','berhad','llp','other')
                        NOT NULL DEFAULT 'sdn_bhd',
    industry           VARCHAR(120) NULL,
    incorporation_date DATE NULL,
    ownership_pct      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    status             ENUM('active','dormant','closed') NOT NULL DEFAULT 'active',
    notes              TEXT NULL,
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_company_tenant (tenant_id),
    KEY idx_company_client (client_id),
    CONSTRAINT fk_company_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_company_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_stakeholders (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    company_id       BIGINT UNSIGNED NOT NULL,
    name             VARCHAR(150) NOT NULL,
    nric_passport    VARCHAR(60)  NULL,
    relationship     ENUM('self','spouse','child','parent','sibling','partner','other')
                      NOT NULL DEFAULT 'other',
    is_shareholder   TINYINT(1) NOT NULL DEFAULT 0,
    shareholding_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    is_director      TINYINT(1) NOT NULL DEFAULT 0,
    is_beneficiary   TINYINT(1) NOT NULL DEFAULT 0,
    benefit_pct      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    email            VARCHAR(150) NULL,
    phone            VARCHAR(40)  NULL,
    notes            VARCHAR(255) NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_stk_tenant (tenant_id),
    KEY idx_stk_company (company_id),
    CONSTRAINT fk_stk_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_stk_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_financials (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    company_id         BIGINT UNSIGNED NOT NULL,
    snapshot_date      DATE NOT NULL,
    revenue            DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    ebitda             DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    net_profit         DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    total_assets       DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    total_liabilities  DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    receivables        DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    inventory          DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    cash               DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    bank_loans         DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    shareholder_loans  DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    personal_guarantee DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    owner_remuneration DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    dividends_paid     DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_bf_tenant (tenant_id),
    KEY idx_bf_company (company_id),
    CONSTRAINT fk_bf_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_bf_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
