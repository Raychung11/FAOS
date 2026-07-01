-- =====================================================================
--  AdvisorOS — Reference / seed data
--  Run AFTER schema.sql. Idempotent (INSERT IGNORE / ON DUPLICATE).
--  Tenant + user accounts are created by setup/install.php so that
--  passwords are hashed with PHP password_hash().
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Subscription plans
-- ---------------------------------------------------------------------
INSERT INTO subscription_plans (name, code, price_monthly, price_yearly, max_users, max_clients, storage_mb, features, is_active) VALUES
 ('Starter',      'starter',       49.00,  490.00,   5,  200,  2048, JSON_OBJECT('leads',true,'clients',true,'reviews',true), 1),
 ('Professional', 'professional', 149.00, 1490.00,  20, 1000, 10240, JSON_OBJECT('leads',true,'clients',true,'reviews',true,'proposals',true), 1),
 ('Agency',       'agency',       399.00, 3990.00, 100, 5000, 51200, JSON_OBJECT('leads',true,'clients',true,'reviews',true,'proposals',true,'commissions',true), 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------
INSERT INTO roles (code, name, description, is_system) VALUES
 ('super_admin',       'Super Admin',       'Manages the entire SaaS platform', 1),
 ('tenant_admin',      'Tenant Admin',      'Manages their advisory company',   1),
 ('agency_leader',     'Agency Leader',     'Manages a team of advisors',       1),
 ('financial_advisor', 'Financial Advisor', 'Manages assigned clients & leads', 1),
 ('compliance_officer','Compliance Officer','Compliance review and audit',      1),
 ('client',            'Client',            'Client self-service portal',       1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, module, description) VALUES
 ('platform.manage',   'platform',   'Manage platform, tenants, billing'),
 ('tenant.manage',     'tenant',     'Manage own tenant settings & branding'),
 ('users.manage',      'users',      'Manage users within tenant'),
 ('team.manage',       'team',       'Manage team advisors & performance'),
 ('leads.view',        'leads',      'View leads'),
 ('leads.manage',      'leads',      'Create / edit / assign leads'),
 ('clients.view',      'clients',    'View clients'),
 ('clients.manage',    'clients',    'Create / edit clients'),
 ('financial.manage',  'financial',  'Manage financial snapshot'),
 ('risk.manage',       'risk',       'Manage risk profiling'),
 ('policies.manage',   'policies',   'Manage policies & products'),
 ('reviews.manage',    'reviews',    'Manage annual reviews'),
 ('appointments.manage','appointments','Manage appointments & follow-ups'),
 ('cases.manage',      'cases',      'Manage advisory cases'),
 ('documents.manage',  'documents',  'Manage document vault'),
 ('proposals.manage',  'proposals',  'Manage proposals'),
 ('commissions.manage','commissions','Manage commissions'),
 ('compliance.view',   'compliance', 'View compliance dashboard & audit'),
 ('audit.view',        'audit',      'View audit trail'),
 ('portal.access',     'portal',     'Access client portal')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- ---------------------------------------------------------------------
-- Role -> Permission mapping
-- ---------------------------------------------------------------------
-- Super Admin: platform only (tenant data is isolated from platform staff)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'super_admin' AND p.code IN ('platform.manage','audit.view');

-- Tenant Admin: everything inside the tenant
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'tenant_admin' AND p.code IN (
 'tenant.manage','users.manage','team.manage','leads.view','leads.manage',
 'clients.view','clients.manage','financial.manage','risk.manage','policies.manage',
 'reviews.manage','appointments.manage','cases.manage','documents.manage',
 'proposals.manage','commissions.manage','compliance.view','audit.view');

-- Agency Leader: team + servicing
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'agency_leader' AND p.code IN (
 'team.manage','leads.view','leads.manage','clients.view','clients.manage',
 'financial.manage','risk.manage','policies.manage','reviews.manage',
 'appointments.manage','cases.manage','documents.manage','proposals.manage');

-- Financial Advisor: servicing of assigned records
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'financial_advisor' AND p.code IN (
 'leads.view','leads.manage','clients.view','clients.manage','financial.manage',
 'risk.manage','policies.manage','reviews.manage','appointments.manage',
 'cases.manage','documents.manage','proposals.manage');

-- Compliance Officer: compliance + audit + read access
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'compliance_officer' AND p.code IN (
 'compliance.view','audit.view','clients.view','documents.manage','reviews.manage');

-- Client: portal only
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code = 'client' AND p.code IN ('portal.access');
