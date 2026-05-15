-- =====================================================================
--  AdvisorOS — The Client Servicing Operating System for Financial Advisors
--  Multi-tenant SaaS database schema
--  Engine: MySQL 8+ / MariaDB 10.5+  (InnoDB, utf8mb4)
--
--  Conventions:
--   * Every tenant-scoped table carries tenant_id, created_by,
--     created_at, updated_at and a soft-delete (deleted_at) column.
--   * Foreign keys enforce tenant data integrity.
--   * Indexes are added on tenant_id + common lookup columns.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Platform: subscription plans (global, not tenant scoped)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscription_plans (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(80)  NOT NULL,
    code            VARCHAR(40)  NOT NULL,
    price_monthly   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    price_yearly    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_users       INT UNSIGNED  NOT NULL DEFAULT 5,
    max_clients     INT UNSIGNED  NOT NULL DEFAULT 100,
    storage_mb      INT UNSIGNED  NOT NULL DEFAULT 1024,
    features        JSON          NULL,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_plan_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tenants (financial advisory companies)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_name        VARCHAR(150) NOT NULL,
    slug                VARCHAR(80)  NOT NULL,
    registration_no     VARCHAR(80)  NULL,
    email               VARCHAR(150) NULL,
    phone               VARCHAR(40)  NULL,
    address             VARCHAR(255) NULL,
    logo_path           VARCHAR(255) NULL,
    brand_primary       VARCHAR(9)   NOT NULL DEFAULT '#0B1F3A',
    brand_accent        VARCHAR(9)   NOT NULL DEFAULT '#C9A227',
    plan_id             BIGINT UNSIGNED NULL,
    subscription_status ENUM('trial','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trial',
    trial_ends_at       DATE NULL,
    expires_at          DATE NULL,
    status              ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_slug (slug),
    KEY idx_tenant_status (status),
    CONSTRAINT fk_tenant_plan FOREIGN KEY (plan_id) REFERENCES subscription_plans (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Roles (system-defined, referenced by code)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(40)  NOT NULL,
    name        VARCHAR(80)  NOT NULL,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_role_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permissions catalogue
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(80)  NOT NULL,
    module      VARCHAR(60)  NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permission_code (code),
    KEY idx_permission_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Role <-> Permission mapping
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Users (super admin has tenant_id = NULL)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED NULL,
    role_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    phone           VARCHAR(40)  NULL,
    password_hash   VARCHAR(255) NOT NULL,
    team            VARCHAR(80)  NULL,
    branch          VARCHAR(80)  NULL,
    manager_id      BIGINT UNSIGNED NULL,
    status          ENUM('active','inactive','invited') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME NULL,
    last_login_ip   VARCHAR(45) NULL,
    remember_token  VARCHAR(100) NULL,
    created_by      BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_email (email),
    KEY idx_user_tenant (tenant_id),
    KEY idx_user_role (role_id),
    KEY idx_user_manager (manager_id),
    CONSTRAINT fk_user_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_role   FOREIGN KEY (role_id)   REFERENCES roles (id),
    CONSTRAINT fk_user_manager FOREIGN KEY (manager_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Subscriptions (billing history per tenant)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    BIGINT UNSIGNED NOT NULL,
    plan_id      BIGINT UNSIGNED NOT NULL,
    billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    amount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status       ENUM('active','past_due','cancelled','expired') NOT NULL DEFAULT 'active',
    started_at   DATE NOT NULL,
    ends_at      DATE NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sub_tenant (tenant_id),
    CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_plan   FOREIGN KEY (plan_id)   REFERENCES subscription_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Clients
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    full_name        VARCHAR(150) NOT NULL,
    nric_passport    VARCHAR(60)  NULL,
    dob              DATE NULL,
    gender           ENUM('male','female','other') NULL,
    marital_status   ENUM('single','married','divorced','widowed') NULL,
    phone            VARCHAR(40)  NULL,
    email            VARCHAR(150) NULL,
    address          VARCHAR(255) NULL,
    occupation       VARCHAR(120) NULL,
    employer         VARCHAR(120) NULL,
    industry         VARCHAR(120) NULL,
    dependents       INT UNSIGNED NULL DEFAULT 0,
    spouse_name      VARCHAR(120) NULL,
    children_info    VARCHAR(255) NULL,
    risk_appetite    ENUM('conservative','moderate_conservative','balanced','growth','aggressive') NULL,
    financial_goals  TEXT NULL,
    advisor_id       BIGINT UNSIGNED NULL,
    portal_user_id   BIGINT UNSIGNED NULL,
    last_review_date DATE NULL,
    next_review_date DATE NULL,
    status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_client_tenant (tenant_id),
    KEY idx_client_advisor (advisor_id),
    KEY idx_client_review (tenant_id, next_review_date),
    CONSTRAINT fk_client_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_client_advisor FOREIGN KEY (advisor_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_client_portal  FOREIGN KEY (portal_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Leads
--   converted_client_id references clients; clients is created above so
--   the FK is defined inline (keeps the whole script idempotent and
--   safe to re-run — no standalone ALTER that would clash on rerun).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    name             VARCHAR(120) NOT NULL,
    phone            VARCHAR(40)  NULL,
    email            VARCHAR(150) NULL,
    source           VARCHAR(80)  NULL,
    product_interest VARCHAR(120) NULL,
    budget_range     VARCHAR(80)  NULL,
    assigned_to      BIGINT UNSIGNED NULL,
    status           ENUM('new','contacted','qualified','appointment_set','proposal_sent','converted','lost') NOT NULL DEFAULT 'new',
    follow_up_date   DATE NULL,
    notes            TEXT NULL,
    converted_client_id BIGINT UNSIGNED NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_lead_tenant (tenant_id),
    KEY idx_lead_status (tenant_id, status),
    KEY idx_lead_assigned (assigned_to),
    KEY idx_lead_followup (tenant_id, follow_up_date),
    KEY idx_lead_client (converted_client_id),
    CONSTRAINT fk_lead_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_lead_assigned FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_lead_client   FOREIGN KEY (converted_client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Financial profiles / snapshot
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS financial_profiles (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    client_id          BIGINT UNSIGNED NOT NULL,
    monthly_income     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monthly_expenses   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_assets       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    total_liabilities  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    insurance_coverage DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    investments_value  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    emergency_fund     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    retirement_target  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    health_score       TINYINT UNSIGNED NULL,
    snapshot_date      DATE NOT NULL,
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_fp_tenant (tenant_id),
    KEY idx_fp_client (client_id),
    CONSTRAINT fk_fp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Risk profiles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS risk_profiles (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    client_id      BIGINT UNSIGNED NOT NULL,
    answers        JSON NULL,
    score          INT UNSIGNED NULL,
    classification ENUM('conservative','moderate_conservative','balanced','growth','aggressive') NULL,
    advisor_notes  TEXT NULL,
    assessed_on    DATE NOT NULL,
    created_by     BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_rp_tenant (tenant_id),
    KEY idx_rp_client (client_id),
    CONSTRAINT fk_rp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Products (tenant catalogue) & Policies
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    category    ENUM('insurance','takaful','medical_card','investment_linked','unit_trust','mortgage','estate_planning','prs') NOT NULL,
    provider    VARCHAR(120) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_product_tenant (tenant_id),
    CONSTRAINT fk_product_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS policies (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    client_id         BIGINT UNSIGNED NOT NULL,
    product_id        BIGINT UNSIGNED NULL,
    category          ENUM('insurance','takaful','medical_card','investment_linked','unit_trust','mortgage','estate_planning','prs') NOT NULL,
    provider          VARCHAR(120) NULL,
    policy_number     VARCHAR(120) NULL,
    premium           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_frequency ENUM('monthly','quarterly','semi_annual','annual','single') NOT NULL DEFAULT 'annual',
    coverage_amount   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    start_date        DATE NULL,
    renewal_date      DATE NULL,
    beneficiary       VARCHAR(150) NULL,
    status            ENUM('active','lapsed','matured','surrendered','pending') NOT NULL DEFAULT 'active',
    review_notes      TEXT NULL,
    created_by        BIGINT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_policy_tenant (tenant_id),
    KEY idx_policy_client (client_id),
    KEY idx_policy_renewal (tenant_id, renewal_date),
    CONSTRAINT fk_policy_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_policy_client  FOREIGN KEY (client_id)  REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_policy_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Appointments & Follow-ups
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    client_id   BIGINT UNSIGNED NULL,
    lead_id     BIGINT UNSIGNED NULL,
    advisor_id  BIGINT UNSIGNED NULL,
    type        ENUM('first_consultation','policy_review','annual_review','investment_review','mortgage_consultation','claim_support') NOT NULL DEFAULT 'first_consultation',
    title       VARCHAR(150) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    location    VARCHAR(150) NULL,
    notes       TEXT NULL,
    status      ENUM('scheduled','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled',
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_appt_tenant (tenant_id),
    KEY idx_appt_advisor (advisor_id),
    KEY idx_appt_when (tenant_id, scheduled_at),
    CONSTRAINT fk_appt_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_appt_client  FOREIGN KEY (client_id)  REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_lead    FOREIGN KEY (lead_id)    REFERENCES leads (id) ON DELETE SET NULL,
    CONSTRAINT fk_appt_advisor FOREIGN KEY (advisor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS follow_ups (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    client_id   BIGINT UNSIGNED NULL,
    lead_id     BIGINT UNSIGNED NULL,
    assigned_to BIGINT UNSIGNED NULL,
    title       VARCHAR(150) NOT NULL,
    description TEXT NULL,
    due_date    DATE NOT NULL,
    priority    ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status      ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_fu_tenant (tenant_id),
    KEY idx_fu_due (tenant_id, due_date, status),
    KEY idx_fu_assigned (assigned_to),
    CONSTRAINT fk_fu_tenant   FOREIGN KEY (tenant_id)   REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_fu_client   FOREIGN KEY (client_id)   REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_lead     FOREIGN KEY (lead_id)     REFERENCES leads (id) ON DELETE SET NULL,
    CONSTRAINT fk_fu_assigned FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Advisory cases
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS advisory_cases (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    client_id     BIGINT UNSIGNED NOT NULL,
    advisor_id    BIGINT UNSIGNED NULL,
    type          ENUM('insurance_planning','retirement_planning','mortgage_advisory','education_fund','estate_planning','investment_review') NOT NULL,
    objective     VARCHAR(255) NULL,
    current_issue TEXT NULL,
    recommendations TEXT NULL,
    priority      ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    status        ENUM('open','in_review','proposal_drafted','presented','accepted','rejected','closed') NOT NULL DEFAULT 'open',
    notes         TEXT NULL,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_case_tenant (tenant_id),
    KEY idx_case_client (client_id),
    CONSTRAINT fk_case_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_case_client  FOREIGN KEY (client_id)  REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_case_advisor FOREIGN KEY (advisor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Annual reviews (HERO feature — retention engine)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS annual_reviews (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    client_id          BIGINT UNSIGNED NOT NULL,
    advisor_id         BIGINT UNSIGNED NULL,
    review_date        DATE NOT NULL,
    next_review_date   DATE NULL,
    checklist          JSON NULL,
    financial_changes  TEXT NULL,
    policy_changes     TEXT NULL,
    goal_updates       TEXT NULL,
    risk_review        TEXT NULL,
    recommendation     TEXT NULL,
    client_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    acknowledged_at    DATETIME NULL,
    status             ENUM('scheduled','in_progress','completed','overdue') NOT NULL DEFAULT 'scheduled',
    created_by         BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at         DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_ar_tenant (tenant_id),
    KEY idx_ar_client (client_id),
    KEY idx_ar_status (tenant_id, status, next_review_date),
    CONSTRAINT fk_ar_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_client  FOREIGN KEY (client_id)  REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_advisor FOREIGN KEY (advisor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Documents (vault)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    client_id      BIGINT UNSIGNED NULL,
    category       VARCHAR(60) NOT NULL,
    original_name  VARCHAR(255) NOT NULL,
    stored_name    VARCHAR(255) NOT NULL,
    mime_type      VARCHAR(120) NULL,
    file_size      INT UNSIGNED NOT NULL DEFAULT 0,
    version        INT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_by    BIGINT UNSIGNED NULL,
    created_by     BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_doc_tenant (tenant_id),
    KEY idx_doc_client (client_id),
    CONSTRAINT fk_doc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Proposals
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proposals (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    client_id   BIGINT UNSIGNED NOT NULL,
    advisor_id  BIGINT UNSIGNED NULL,
    title       VARCHAR(150) NOT NULL,
    content     LONGTEXT NULL,
    pdf_path    VARCHAR(255) NULL,
    status      ENUM('draft','sent','accepted','rejected') NOT NULL DEFAULT 'draft',
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_prop_tenant (tenant_id),
    KEY idx_prop_client (client_id),
    CONSTRAINT fk_prop_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_prop_client  FOREIGN KEY (client_id)  REFERENCES clients (id) ON DELETE CASCADE,
    CONSTRAINT fk_prop_advisor FOREIGN KEY (advisor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Commissions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS commissions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    advisor_id    BIGINT UNSIGNED NULL,
    client_id     BIGINT UNSIGNED NULL,
    policy_id     BIGINT UNSIGNED NULL,
    sale_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    premium       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    commission    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    override_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status        ENUM('pending','approved','paid','disputed') NOT NULL DEFAULT 'pending',
    paid_at       DATE NULL,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_comm_tenant (tenant_id),
    KEY idx_comm_advisor (advisor_id),
    CONSTRAINT fk_comm_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_comm_advisor FOREIGN KEY (advisor_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_comm_client  FOREIGN KEY (client_id)  REFERENCES clients (id) ON DELETE SET NULL,
    CONSTRAINT fk_comm_policy  FOREIGN KEY (policy_id)  REFERENCES policies (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    module      VARCHAR(60) NOT NULL,
    record_id   BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_tenant (tenant_id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_module (module),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AI logs (AI-ready architecture)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NULL,
    feature     VARCHAR(60) NOT NULL,
    prompt      MEDIUMTEXT NULL,
    response    MEDIUMTEXT NULL,
    model       VARCHAR(80) NULL,
    tokens_used INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_tenant (tenant_id),
    CONSTRAINT fk_ai_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notifications
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(60) NOT NULL,
    title       VARCHAR(150) NOT NULL,
    body        VARCHAR(500) NULL,
    link        VARCHAR(255) NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user (user_id, is_read),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings (key/value, optionally tenant scoped)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NULL,
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setting (tenant_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Auth security: login attempts & password resets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(150) NOT NULL,
    ip_address  VARCHAR(45) NOT NULL,
    successful  TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_lookup (email, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
