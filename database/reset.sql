-- =====================================================================
--  AdvisorOS — Database reset.
--  Drops every AdvisorOS table so schema.sql can be re-applied cleanly.
--  USE ONLY on an install with no real data (e.g. recovering from a
--  partially-applied schema). This is destructive and irreversible.
--
--  Recovery order:
--    1. Run database/reset.sql
--    2. Run database/schema.sql
--    3. Run database/seed.sql   (or: php setup/install.php)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS ai_logs;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS commissions;
DROP TABLE IF EXISTS proposals;
DROP TABLE IF EXISTS documents;
DROP TABLE IF EXISTS annual_reviews;
DROP TABLE IF EXISTS advisory_cases;
DROP TABLE IF EXISTS follow_ups;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS policies;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS risk_profiles;
DROP TABLE IF EXISTS financial_profiles;
DROP TABLE IF EXISTS leads;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS subscription_plans;

SET FOREIGN_KEY_CHECKS = 1;
