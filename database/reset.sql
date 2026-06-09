-- ============================================================================
-- FAOS - FULL DATABASE RESET (idempotent).
-- Import this ONE file (phpMyAdmin: select your DB -> Import) to wipe & rebuild
-- with demo data. = drop all tables + complete schema + seed.
-- Logins after reset: admin/admin123 · manager/manager123 · worker1/worker123
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `accounting_periods`;
DROP TABLE IF EXISTS `account_groups`;
DROP TABLE IF EXISTS `ar_receipts`;
DROP TABLE IF EXISTS `ar_settlements`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `bank_transactions`;
DROP TABLE IF EXISTS `companies`;
DROP TABLE IF EXISTS `einvoices`;
DROP TABLE IF EXISTS `einvoice_lines`;
DROP TABLE IF EXISTS `grn`;
DROP TABLE IF EXISTS `grn_items`;
DROP TABLE IF EXISTS `kiosks`;
DROP TABLE IF EXISTS `official_sales_lines`;
DROP TABLE IF EXISTS `official_sales_reports`;
DROP TABLE IF EXISTS `offline_sync_queue`;
DROP TABLE IF EXISTS `outlets`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `production_consumption`;
DROP TABLE IF EXISTS `production_orders`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `product_batches`;
DROP TABLE IF EXISTS `product_categories`;
DROP TABLE IF EXISTS `product_outlet_prices`;
DROP TABLE IF EXISTS `purchase_orders`;
DROP TABLE IF EXISTS `purchase_order_items`;
DROP TABLE IF EXISTS `purchase_requests`;
DROP TABLE IF EXISTS `purchase_request_items`;
DROP TABLE IF EXISTS `qr_labels`;
DROP TABLE IF EXISTS `recipes`;
DROP TABLE IF EXISTS `recipe_items`;
DROP TABLE IF EXISTS `recon_column_mappings`;
DROP TABLE IF EXISTS `recon_imports`;
DROP TABLE IF EXISTS `ref_counters`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `sales_forecasts`;
DROP TABLE IF EXISTS `sales_items`;
DROP TABLE IF EXISTS `sales_refunds`;
DROP TABLE IF EXISTS `sales_refund_items`;
DROP TABLE IF EXISTS `sales_transactions`;
DROP TABLE IF EXISTS `scan_logs`;
DROP TABLE IF EXISTS `schema_migrations`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `settlement_batches`;
DROP TABLE IF EXISTS `shifts`;
DROP TABLE IF EXISTS `stock_adjustments`;
DROP TABLE IF EXISTS `stock_balances`;
DROP TABLE IF EXISTS `stock_groups`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `supplier_invoices`;
DROP TABLE IF EXISTS `supplier_payments`;
DROP TABLE IF EXISTS `tax_codes`;
DROP TABLE IF EXISTS `terminal_transactions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `variance_reports`;
DROP TABLE IF EXISTS `warehouses`;
DROP TABLE IF EXISTS `workers`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---- schema ----
-- ============================================================================
-- FAOS - COMPLETE current schema (single source of truth for a fresh DB).
-- Auto-generated from the migrated database. Importing this file followed by
-- seed.sql works standalone (e.g. phpMyAdmin). Incremental changes for
-- existing deployments go in database/migrations/ and are squashed into
-- this baseline by bin/migrate.php.
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `account_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_groups` (`company_id`,`code`),
  CONSTRAINT `fk_ag_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `accounting_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'closed',
  `note` varchar(255) DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `reopened_by` bigint(20) unsigned DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_period` (`company_id`,`period_start`,`period_end`),
  KEY `idx_ap_company` (`company_id`,`status`),
  CONSTRAINT `fk_ap_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `ar_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `settlement_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `method` enum('cash','bank','cheque','ewallet') NOT NULL DEFAULT 'bank',
  `reference` varchar(96) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_arr_set` (`settlement_id`),
  KEY `fk_arr_company` (`company_id`),
  KEY `fk_arr_user` (`user_id`),
  CONSTRAINT `fk_arr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_arr_set` FOREIGN KEY (`settlement_id`) REFERENCES `ar_settlements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `ar_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `report_id` bigint(20) unsigned DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `expected_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `received_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','partial','settled') NOT NULL DEFAULT 'pending',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ars_outlet` (`outlet_id`,`status`),
  KEY `fk_ars_company` (`company_id`),
  KEY `fk_ars_report` (`report_id`),
  CONSTRAINT `fk_ars_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_ars_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_ars_report` FOREIGN KEY (`report_id`) REFERENCES `official_sales_reports` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `entity` varchar(64) NOT NULL,
  `entity_id` varchar(64) DEFAULT NULL,
  `outlet_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity`,`entity_id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `bank_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `import_id` bigint(20) unsigned NOT NULL,
  `txn_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference` varchar(96) DEFAULT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(16,2) DEFAULT NULL,
  `match_status` enum('unmatched','matched','manual','exception') NOT NULL DEFAULT 'unmatched',
  `matched_batch_id` bigint(20) unsigned DEFAULT NULL,
  `matched_terminal_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bt_match` (`company_id`,`txn_date`,`match_status`),
  KEY `idx_bt_credit` (`company_id`,`credit`),
  KEY `fk_bt_import` (`import_id`),
  CONSTRAINT `fk_bt_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_bt_import` FOREIGN KEY (`import_id`) REFERENCES `recon_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(160) NOT NULL,
  `reg_no` varchar(64) DEFAULT NULL,
  `tin` varchar(32) DEFAULT NULL,
  `sst_no` varchar(32) DEFAULT NULL,
  `msic_code` varchar(8) DEFAULT NULL,
  `einvoice_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `currency` varchar(8) NOT NULL DEFAULT 'MYR',
  `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_companies_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `einvoice_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `einvoice_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `classification` varchar(16) NOT NULL DEFAULT '022',
  `description` varchar(255) NOT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 1.000,
  `unit_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `line_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_einvl` (`einvoice_id`),
  CONSTRAINT `fk_einvl_einv` FOREIGN KEY (`einvoice_id`) REFERENCES `einvoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `einvoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `einvoice_no` varchar(40) NOT NULL,
  `doc_type` enum('standard','consolidated','credit_note','refund_note') NOT NULL DEFAULT 'standard',
  `transaction_id` bigint(20) unsigned DEFAULT NULL,
  `outlet_id` bigint(20) unsigned DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `buyer_name` varchar(160) NOT NULL DEFAULT 'General Public',
  `buyer_tin` varchar(32) DEFAULT NULL,
  `buyer_reg_no` varchar(32) DEFAULT NULL,
  `buyer_email` varchar(160) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'MYR',
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','pending_submission','submitted','valid','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `irbm_uuid` varchar(64) DEFAULT NULL,
  `irbm_long_id` varchar(96) DEFAULT NULL,
  `validation_url` varchar(255) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `response_json` longtext DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_einvoice_no` (`einvoice_no`),
  KEY `idx_einv_txn` (`transaction_id`),
  KEY `idx_einv_status` (`company_id`,`status`),
  KEY `fk_einv_outlet` (`outlet_id`),
  CONSTRAINT `fk_einv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_einv_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_einv_txn` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `grn` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `grn_ref` varchar(40) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `invoice_no` varchar(64) DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grn_ref` (`grn_ref`),
  KEY `fk_grn_company` (`company_id`),
  KEY `fk_grn_po` (`po_id`),
  KEY `fk_grn_supplier` (`supplier_id`),
  KEY `fk_grn_wh` (`warehouse_id`),
  CONSTRAINT `fk_grn_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_grn_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `fk_grn_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_grn_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `grn_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_cost` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_grni_grn` (`grn_id`),
  KEY `fk_grni_product` (`product_id`),
  KEY `fk_grni_batch` (`batch_id`),
  CONSTRAINT `fk_grni_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`id`),
  CONSTRAINT `fk_grni_grn` FOREIGN KEY (`grn_id`) REFERENCES `grn` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grni_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `kiosks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(160) NOT NULL,
  `location_note` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kiosks_code` (`outlet_id`,`code`),
  CONSTRAINT `fk_kiosks_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `official_sales_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(48) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_osl_report` (`report_id`),
  KEY `fk_osl_product` (`product_id`),
  CONSTRAINT `fk_osl_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_osl_report` FOREIGN KEY (`report_id`) REFERENCES `official_sales_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `official_sales_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `source_name` varchar(120) DEFAULT NULL,
  `imported_by` bigint(20) unsigned DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_osr_outlet` (`outlet_id`,`period_start`),
  KEY `fk_osr_company` (`company_id`),
  CONSTRAINT `fk_osr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_osr_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `offline_sync_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `client_uuid` varchar(64) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `status` enum('pending','processed','failed') NOT NULL DEFAULT 'pending',
  `error_msg` varchar(255) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_osq_uuid` (`client_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `outlets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(160) NOT NULL,
  `outlet_type` enum('kiosk_hub','restaurant') NOT NULL DEFAULT 'kiosk_hub',
  `hypermarket` varchar(160) DEFAULT NULL,
  `region` varchar(96) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `third_party_pos` tinyint(1) NOT NULL DEFAULT 1,
  `report_lag_days` smallint(6) NOT NULL DEFAULT 21,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outlets_code` (`company_id`,`code`),
  CONSTRAINT `fk_outlets_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(96) NOT NULL,
  `name` varchar(160) NOT NULL,
  `module` varchar(48) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `product_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(64) NOT NULL,
  `mfg_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `received_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch` (`product_id`,`batch_no`),
  KEY `idx_batch_expiry` (`expiry_date`),
  KEY `fk_batch_company` (`company_id`),
  KEY `fk_batch_supplier` (`supplier_id`),
  CONSTRAINT `fk_batch_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_batch_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_batch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `product_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pcat` (`company_id`,`code`),
  KEY `fk_pcat_parent` (`parent_id`),
  CONSTRAINT `fk_pcat_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_pcat_parent` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `product_outlet_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `sell_price` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pop` (`product_id`,`outlet_id`),
  KEY `fk_pop_outlet` (`outlet_id`),
  CONSTRAINT `fk_pop_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pop_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `production_consumption` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `production_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(14,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pc_prod` (`production_id`),
  KEY `fk_pc_ingredient` (`ingredient_id`),
  KEY `fk_pc_batch` (`batch_id`),
  CONSTRAINT `fk_pc_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`id`),
  CONSTRAINT `fk_pc_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_pc_prod` FOREIGN KEY (`production_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `production_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `recipe_id` bigint(20) unsigned DEFAULT NULL,
  `prod_ref` varchar(40) NOT NULL,
  `planned_qty` decimal(14,3) NOT NULL,
  `produced_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `status` enum('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
  `planned_date` date DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_ref` (`prod_ref`),
  KEY `fk_prod_company` (`company_id`),
  KEY `fk_prod_wh` (`warehouse_id`),
  KEY `fk_prod_product` (`product_id`),
  KEY `fk_prod_recipe` (`recipe_id`),
  CONSTRAINT `fk_prod_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_prod_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_prod_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`),
  CONSTRAINT `fk_prod_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `stock_group_id` bigint(20) unsigned DEFAULT NULL,
  `account_group_id` bigint(20) unsigned DEFAULT NULL,
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(48) NOT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `uom` varchar(16) NOT NULL DEFAULT 'unit',
  `type` enum('raw','semi_finished','finished','consumable') NOT NULL DEFAULT 'finished',
  `cost_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `avg_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `sell_price` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `reorder_level` decimal(14,3) NOT NULL DEFAULT 0.000,
  `shelf_life_days` smallint(6) DEFAULT NULL,
  `is_sellable` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`company_id`,`sku`),
  KEY `idx_products_barcode` (`barcode`),
  KEY `fk_products_cat` (`category_id`),
  KEY `fk_products_sg` (`stock_group_id`),
  KEY `fk_products_ag` (`account_group_id`),
  KEY `fk_products_tax` (`tax_code_id`),
  CONSTRAINT `fk_products_ag` FOREIGN KEY (`account_group_id`) REFERENCES `account_groups` (`id`),
  CONSTRAINT `fk_products_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`),
  CONSTRAINT `fk_products_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_products_sg` FOREIGN KEY (`stock_group_id`) REFERENCES `stock_groups` (`id`),
  CONSTRAINT `fk_products_tax` FOREIGN KEY (`tax_code_id`) REFERENCES `tax_codes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_cost` decimal(14,4) NOT NULL,
  `received_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  KEY `idx_poi_po` (`po_id`),
  KEY `fk_poi_product` (`product_id`),
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `po_ref` varchar(40) NOT NULL,
  `status` enum('draft','approved','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  `expected_date` date DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_po_ref` (`po_ref`),
  KEY `fk_po_company` (`company_id`),
  KEY `fk_po_supplier` (`supplier_id`),
  KEY `fk_po_wh` (`warehouse_id`),
  CONSTRAINT `fk_po_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_po_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `purchase_request_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pr_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_pri_pr` (`pr_id`),
  KEY `fk_pri_product` (`product_id`),
  CONSTRAINT `fk_pri_pr` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `purchase_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned DEFAULT NULL,
  `pr_ref` varchar(40) NOT NULL,
  `status` enum('open','approved','converted','rejected') NOT NULL DEFAULT 'open',
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_ref` (`pr_ref`),
  KEY `fk_pr_company` (`company_id`),
  KEY `fk_pr_outlet` (`outlet_id`),
  CONSTRAINT `fk_pr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_pr_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `qr_labels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `qr_ref` varchar(40) NOT NULL,
  `label_type` enum('carton','batch','item') NOT NULL DEFAULT 'carton',
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `init_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `uom` varchar(16) NOT NULL DEFAULT 'unit',
  `current_location_type` enum('warehouse','outlet','kiosk','consumed','void') NOT NULL DEFAULT 'warehouse',
  `current_location_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','depleted','void') NOT NULL DEFAULT 'active',
  `printed_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_qr_ref` (`qr_ref`),
  KEY `idx_qr_product` (`product_id`),
  KEY `fk_qr_company` (`company_id`),
  KEY `fk_qr_batch` (`batch_id`),
  KEY `fk_qr_user` (`created_by`),
  CONSTRAINT `fk_qr_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`id`),
  CONSTRAINT `fk_qr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_qr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_qr_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `recipe_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipe_id` bigint(20) unsigned NOT NULL,
  `ingredient_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(14,4) NOT NULL,
  `uom` varchar(16) NOT NULL DEFAULT 'unit',
  PRIMARY KEY (`id`),
  KEY `idx_ri_recipe` (`recipe_id`),
  KEY `fk_ri_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_ri_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_ri_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `recipes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `yield_qty` decimal(14,3) NOT NULL DEFAULT 1.000,
  `yield_uom` varchar(16) NOT NULL DEFAULT 'unit',
  `notes` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipes_product` (`product_id`),
  CONSTRAINT `fk_recipes_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `recon_column_mappings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `source_type` enum('terminal','bank') NOT NULL,
  `name` varchar(96) NOT NULL,
  `delimiter` varchar(4) NOT NULL DEFAULT ',',
  `has_header` tinyint(1) NOT NULL DEFAULT 1,
  `date_format` varchar(32) NOT NULL DEFAULT 'Y-m-d',
  `columns_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`columns_json`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rcm` (`company_id`,`source_type`,`name`),
  CONSTRAINT `fk_rcm_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `recon_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `source_type` enum('terminal','bank') NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(255) DEFAULT NULL,
  `bank_label` varchar(120) DEFAULT NULL,
  `mapping_id` bigint(20) unsigned DEFAULT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `imported_by` bigint(20) unsigned DEFAULT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ri_company` (`company_id`,`source_type`),
  KEY `fk_ri_mapping` (`mapping_id`),
  CONSTRAINT `fk_ri_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_ri_mapping` FOREIGN KEY (`mapping_id`) REFERENCES `recon_column_mappings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `ref_counters` (
  `scope` varchar(64) NOT NULL,
  `seq` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_rp_perm` (`permission_id`),
  CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(48) NOT NULL,
  `name` varchar(96) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `sales_forecasts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `forecast_date` date NOT NULL,
  `predicted_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `lower_bound` decimal(14,3) NOT NULL DEFAULT 0.000,
  `upper_bound` decimal(14,3) NOT NULL DEFAULT 0.000,
  `method` varchar(32) NOT NULL DEFAULT 'moving_avg',
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fc` (`outlet_id`,`product_id`,`forecast_date`),
  KEY `fk_fc_company` (`company_id`),
  KEY `fk_fc_product` (`product_id`),
  CONSTRAINT `fk_fc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_fc_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_fc_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `sales_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `tax_code_id` bigint(20) unsigned DEFAULT NULL,
  `qr_label_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL,
  `unit_price` decimal(14,4) NOT NULL,
  `line_amount` decimal(14,2) NOT NULL,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_si_txn` (`transaction_id`),
  KEY `idx_si_product` (`product_id`),
  KEY `fk_si_qr` (`qr_label_id`),
  CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_si_qr` FOREIGN KEY (`qr_label_id`) REFERENCES `qr_labels` (`id`),
  CONSTRAINT `fk_si_txn` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `sales_refund_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `refund_id` bigint(20) unsigned NOT NULL,
  `sales_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `tax_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_sri_refund` (`refund_id`),
  KEY `fk_sri_item` (`sales_item_id`),
  KEY `fk_sri_product` (`product_id`),
  CONSTRAINT `fk_sri_item` FOREIGN KEY (`sales_item_id`) REFERENCES `sales_items` (`id`),
  CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sri_refund` FOREIGN KEY (`refund_id`) REFERENCES `sales_refunds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `sales_refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `refund_ref` varchar(40) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `refund_method` enum('cash','card','ewallet','transfer') NOT NULL DEFAULT 'cash',
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refund_ref` (`refund_ref`),
  KEY `idx_ref_txn` (`transaction_id`),
  KEY `fk_sr_company` (`company_id`),
  CONSTRAINT `fk_sr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_sr_txn` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `sales_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `kiosk_id` bigint(20) unsigned DEFAULT NULL,
  `shift_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `txn_ref` varchar(40) NOT NULL,
  `client_uuid` varchar(64) DEFAULT NULL,
  `payment_type` enum('cash','card','ewallet','transfer') NOT NULL,
  `total_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `subtotal_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `source` enum('qr_scan','manual','import') NOT NULL DEFAULT 'qr_scan',
  `status` enum('completed','voided','refunded','partially_refunded') NOT NULL DEFAULT 'completed',
  `device_id` varchar(64) DEFAULT NULL,
  `sold_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `voided_at` datetime DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_txn_ref` (`txn_ref`),
  UNIQUE KEY `uq_client_uuid` (`client_uuid`),
  KEY `idx_sales_outlet_date` (`outlet_id`,`sold_at`),
  KEY `idx_sales_user_date` (`user_id`,`sold_at`),
  KEY `fk_sales_kiosk` (`kiosk_id`),
  KEY `fk_sales_shift` (`shift_id`),
  KEY `idx_sales_company_date` (`company_id`,`sold_at`),
  CONSTRAINT `fk_sales_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_sales_kiosk` FOREIGN KEY (`kiosk_id`) REFERENCES `kiosks` (`id`),
  CONSTRAINT `fk_sales_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_sales_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`),
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `scan_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `qr_ref` varchar(40) NOT NULL,
  `qr_label_id` bigint(20) unsigned DEFAULT NULL,
  `scan_context` enum('receive','transfer','consume','wastage','count','sale','lookup') NOT NULL,
  `outlet_id` bigint(20) unsigned DEFAULT NULL,
  `kiosk_id` bigint(20) unsigned DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `result` enum('ok','duplicate','invalid','denied') NOT NULL DEFAULT 'ok',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scan_ref` (`qr_ref`),
  KEY `idx_scan_date` (`created_at`),
  KEY `fk_scan_company` (`company_id`),
  CONSTRAINT `fk_scan_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `skey` varchar(96) NOT NULL,
  `svalue` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings` (`company_id`,`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `settlement_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `terminal_import_id` bigint(20) unsigned NOT NULL,
  `batch_date` date NOT NULL,
  `channel` enum('cash','atm_debit','bank_transfer','duitnow_qr','card_visa','card_master','card_amex','deposit','other') NOT NULL,
  `txn_count` int(11) NOT NULL DEFAULT 0,
  `gross_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `fee_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expected_net` decimal(14,2) NOT NULL DEFAULT 0.00,
  `bank_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `bank_credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `implied_fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `variance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','matched','fee_variance','short','over','unmatched','not_expected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sb_company` (`company_id`,`batch_date`,`channel`),
  KEY `fk_sb_import` (`terminal_import_id`),
  CONSTRAINT `fk_sb_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_sb_import` FOREIGN KEY (`terminal_import_id`) REFERENCES `recon_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `kiosk_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  `opening_float` decimal(14,2) NOT NULL DEFAULT 0.00,
  `closing_amount` decimal(14,2) DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  KEY `idx_shift_user` (`user_id`,`status`),
  KEY `fk_shift_company` (`company_id`),
  KEY `fk_shift_outlet` (`outlet_id`),
  KEY `fk_shift_kiosk` (`kiosk_id`),
  CONSTRAINT `fk_shift_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_shift_kiosk` FOREIGN KEY (`kiosk_id`) REFERENCES `kiosks` (`id`),
  CONSTRAINT `fk_shift_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_shift_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `stock_adjustments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `loc_type` enum('warehouse','outlet','kiosk') NOT NULL,
  `loc_id` bigint(20) unsigned NOT NULL,
  `adj_type` enum('count','damage','loss','correction','wastage') NOT NULL,
  `qty_delta` decimal(14,3) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_adj_company` (`company_id`),
  KEY `fk_adj_product` (`product_id`),
  KEY `fk_adj_user` (`user_id`),
  CONSTRAINT `fk_adj_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_adj_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_adj_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `stock_balances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `loc_type` enum('warehouse','outlet','kiosk') NOT NULL,
  `loc_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_balance` (`product_id`,`loc_type`,`loc_id`),
  KEY `idx_bal_loc` (`loc_type`,`loc_id`),
  KEY `fk_bal_company` (`company_id`),
  CONSTRAINT `fk_bal_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_bal_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `stock_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stock_groups` (`company_id`,`code`),
  CONSTRAINT `fk_sg_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `qr_label_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `movement_type` enum('receive','transfer_out','transfer_in','sale','consume','production_in','production_out','wastage','adjustment','count','void','refund') NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `uom` varchar(16) NOT NULL DEFAULT 'unit',
  `from_loc_type` enum('warehouse','outlet','kiosk','supplier','none') NOT NULL DEFAULT 'none',
  `from_loc_id` bigint(20) unsigned DEFAULT NULL,
  `to_loc_type` enum('warehouse','outlet','kiosk','customer','none') NOT NULL DEFAULT 'none',
  `to_loc_id` bigint(20) unsigned DEFAULT NULL,
  `ref_table` varchar(40) DEFAULT NULL,
  `ref_id` bigint(20) unsigned DEFAULT NULL,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `notes` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sm_product` (`product_id`),
  KEY `idx_sm_qr` (`qr_label_id`),
  KEY `idx_sm_type_date` (`movement_type`,`created_at`),
  KEY `fk_sm_batch` (`batch_id`),
  KEY `fk_sm_user` (`user_id`),
  KEY `idx_sm_company_type_date` (`company_id`,`movement_type`,`created_at`),
  CONSTRAINT `fk_sm_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`id`),
  CONSTRAINT `fk_sm_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sm_qr` FOREIGN KEY (`qr_label_id`) REFERENCES `qr_labels` (`id`),
  CONSTRAINT `fk_sm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `supplier_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `grn_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_no` varchar(64) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sinv` (`company_id`,`supplier_id`,`invoice_no`),
  KEY `idx_sinv_status` (`status`,`due_date`),
  KEY `fk_sinv_supplier` (`supplier_id`),
  KEY `fk_sinv_po` (`po_id`),
  KEY `fk_sinv_grn` (`grn_id`),
  CONSTRAINT `fk_sinv_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_sinv_grn` FOREIGN KEY (`grn_id`) REFERENCES `grn` (`id`),
  CONSTRAINT `fk_sinv_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `fk_sinv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `supplier_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `supplier_invoice_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `method` enum('cash','bank','cheque','ewallet') NOT NULL DEFAULT 'bank',
  `reference` varchar(96) DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_spay_inv` (`supplier_invoice_id`),
  KEY `fk_spay_company` (`company_id`),
  KEY `fk_spay_user` (`user_id`),
  CONSTRAINT `fk_spay_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_spay_inv` FOREIGN KEY (`supplier_invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_spay_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(160) NOT NULL,
  `contact_person` varchar(120) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `payment_terms` varchar(64) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suppliers_code` (`company_id`,`code`),
  CONSTRAINT `fk_suppliers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `tax_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(96) NOT NULL,
  `tax_type` enum('service','sales','zero','exempt') NOT NULL DEFAULT 'service',
  `rate` decimal(6,3) NOT NULL DEFAULT 0.000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_codes` (`company_id`,`code`),
  CONSTRAINT `fk_tax_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `terminal_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `import_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `txn_date` date NOT NULL,
  `txn_time` time DEFAULT NULL,
  `txn_ref` varchar(96) DEFAULT NULL,
  `raw_type` varchar(64) DEFAULT NULL,
  `channel` enum('cash','atm_debit','bank_transfer','duitnow_qr','card_visa','card_master','card_amex','deposit','other') NOT NULL,
  `card_last4` varchar(8) DEFAULT NULL,
  `payer` varchar(120) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `fee` decimal(14,2) NOT NULL DEFAULT 0.00,
  `match_status` enum('unmatched','batched','matched','manual') NOT NULL DEFAULT 'unmatched',
  `bank_txn_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tt_match` (`company_id`,`txn_date`,`channel`,`match_status`),
  KEY `idx_tt_amount` (`company_id`,`amount`),
  KEY `fk_tt_import` (`import_id`),
  KEY `fk_tt_batch` (`batch_id`),
  CONSTRAINT `fk_tt_batch` FOREIGN KEY (`batch_id`) REFERENCES `settlement_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tt_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_tt_import` FOREIGN KEY (`import_id`) REFERENCES `recon_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned DEFAULT NULL,
  `kiosk_id` bigint(20) unsigned DEFAULT NULL,
  `username` varchar(64) NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `email` varchar(160) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `failed_attempts` smallint(6) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_outlet` (`outlet_id`),
  KEY `fk_users_company` (`company_id`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_kiosk` (`kiosk_id`),
  CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_users_kiosk` FOREIGN KEY (`kiosk_id`) REFERENCES `kiosks` (`id`),
  CONSTRAINT `fk_users_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `variance_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `report_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `qr_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `qr_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `official_qty` decimal(14,3) NOT NULL DEFAULT 0.000,
  `official_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `qty_variance` decimal(14,3) NOT NULL DEFAULT 0.000,
  `amount_variance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('matched','shortage','over_reported','under_reported','missing_scans') NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vr_report` (`report_id`),
  KEY `fk_vr_company` (`company_id`),
  KEY `fk_vr_outlet` (`outlet_id`),
  KEY `fk_vr_product` (`product_id`),
  CONSTRAINT `fk_vr_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`),
  CONSTRAINT `fk_vr_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_vr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_vr_report` FOREIGN KEY (`report_id`) REFERENCES `official_sales_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `warehouses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(160) NOT NULL,
  `type` enum('central_kitchen','warehouse','cold_room') NOT NULL DEFAULT 'warehouse',
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_warehouses_code` (`company_id`,`code`),
  CONSTRAINT `fk_warehouses_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `workers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `outlet_id` bigint(20) unsigned NOT NULL,
  `kiosk_id` bigint(20) unsigned DEFAULT NULL,
  `staff_no` varchar(48) DEFAULT NULL,
  `hired_at` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workers_user` (`user_id`),
  KEY `fk_workers_outlet` (`outlet_id`),
  KEY `fk_workers_kiosk` (`kiosk_id`),
  CONSTRAINT `fk_workers_kiosk` FOREIGN KEY (`kiosk_id`) REFERENCES `kiosks` (`id`),
  CONSTRAINT `fk_workers_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  CONSTRAINT `fk_workers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---- seed / demo data ----
-- ============================================================================
-- FAOS - seed / demo data
-- Default logins:
--   admin    / admin123    (super_admin, HQ)
--   manager  / manager123  (outlet_manager, AEON kiosk hub)
--   worker1  / worker123    PIN 1234  (worker, Kiosk 1)
--   rmanager / manager123  (restaurant_manager, Bangsar restaurant)
--   rworker  / worker123    PIN 1234  (worker, Bangsar restaurant)
--   acc      / acc123       (accountant, company finance)
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO companies (id, code, name, reg_no, currency, timezone) VALUES
 (1, 'C001', 'Caffeinees F&B Sdn Bhd', '202301000001', 'MYR', 'Asia/Kuala_Lumpur');

INSERT INTO roles (id, code, name, description) VALUES
 (1, 'super_admin',       'Super Admin',        'Full system access'),
 (2, 'hq_manager',        'HQ Manager',         'HQ dashboards + master data'),
 (3, 'outlet_manager',    'Outlet Manager',     'Kiosk-hub operations + reports'),
 (4, 'worker',            'Kiosk Worker',       'QR sales + stock scan only'),
 (5, 'restaurant_manager','Restaurant Manager', 'Restaurant operations + reports'),
 (6, 'accountant',        'Accountant',         'Company finance: AP, AR, P&L');

INSERT INTO permissions (id, code, name, module) VALUES
 (1,'masterdata.manage','Manage master data','masterdata'),
 (2,'stock.scan','Scan / move stock','stock'),
 (3,'stock.manage','Manage inventory','stock'),
 (4,'sales.scan','Scan sales','sales'),
 (5,'sales.view','View sales','sales'),
 (6,'procurement.manage','Manage procurement','procurement'),
 (7,'kitchen.manage','Manage central kitchen','kitchen'),
 (8,'reports.view','View reports','reports'),
 (9,'reconciliation.manage','Manage reconciliation','reconciliation'),
 (10,'dashboard.hq','HQ dashboard','dashboard'),
 (11,'dashboard.outlet','Outlet dashboard','dashboard'),
 (12,'dashboard.worker','Worker dashboard','dashboard'),
 (13,'ai.view','AI dashboard','ai'),
 (14,'admin.users','Manage users/roles','admin'),
 (15,'qr.print','Generate / print QR labels','qr'),
 (16,'finance.view','View finance (AP/AR/P&L)','finance'),
 (17,'finance.manage','Manage invoices / payments / receipts','finance'),
 (18,'sales.void_refund','Void / refund sales','sales');

-- super_admin: everything
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;
-- hq_manager (+ finance read)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (2,1),(2,3),(2,5),(2,6),(2,7),(2,8),(2,9),(2,10),(2,11),(2,13),(2,15),(2,16),(2,18);
-- outlet_manager (hypermarket kiosk hub)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (3,2),(3,3),(3,4),(3,5),(3,8),(3,11),(3,12),(3,15),(3,18);
-- worker (no void/refund: fraud prevention)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (4,2),(4,4),(4,12);
-- restaurant_manager (same operational scope, restaurant outlet)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (5,2),(5,3),(5,4),(5,5),(5,8),(5,11),(5,12),(5,15),(5,18);
-- accountant (company finance + reports + reconciliation/AR)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (6,8),(6,9),(6,16),(6,17);

INSERT INTO warehouses (id, company_id, code, name, type) VALUES
 (1,1,'CK01','Central Kitchen - Shah Alam','central_kitchen'),
 (2,1,'WH01','Main Warehouse','warehouse');

INSERT INTO outlets (id, company_id, code, name, outlet_type, hypermarket, region, third_party_pos, report_lag_days) VALUES
 (1,1,'OUT01','AEON Mid Valley Kiosk Hub','kiosk_hub','AEON','Klang Valley',1,21),
 (2,1,'OUT02','Lotus''s Cheras Kiosk Hub','kiosk_hub','Lotus''s','Klang Valley',1,28),
 (3,1,'OUT03','Caffeinees Restaurant - Bangsar','restaurant',NULL,'Klang Valley',0,0);

INSERT INTO kiosks (id, outlet_id, code, name, location_note) VALUES
 (1,1,'K001','AEON MV - Ground Floor','Near main entrance'),
 (2,1,'K002','AEON MV - Food Court',NULL),
 (3,2,'K003','Lotus''s Cheras - Entrance',NULL);

INSERT INTO suppliers (id, company_id, code, name, contact_person, phone, payment_terms) VALUES
 (1,1,'SUP01','Fresh Dairy Supply','Mr Tan','0312345678','NET 30'),
 (2,1,'SUP02','Premium Coffee Beans Co','Ms Lim','0398765432','NET 14');

INSERT INTO stock_groups (id, company_id, code, name) VALUES
 (1,1,'SG-BEV','Beverages'),
 (2,1,'SG-RAW','Raw Materials'),
 (3,1,'SG-PACK','Packaging');

INSERT INTO account_groups (id, company_id, code, name) VALUES
 (1,1,'AG-SALES','Sales Revenue'),
 (2,1,'AG-COGS','Cost of Goods Sold');

INSERT INTO product_categories (id, company_id, parent_id, code, name) VALUES
 (1,1,NULL,'CAT-COFFEE','Coffee'),
 (2,1,NULL,'CAT-TEA','Tea'),
 (3,1,NULL,'CAT-ING','Ingredients');

INSERT INTO products
 (id, company_id, category_id, stock_group_id, account_group_id, sku, barcode, name, uom, type, cost_price, sell_price, reorder_level, shelf_life_days, is_sellable) VALUES
 (1,1,1,1,1,'CF-LATTE','9551234500011','Caffeinees Latte 350ml','unit','finished',3.20,9.90,20,2,1),
 (2,1,1,1,1,'CF-AMER','9551234500028','Caffeinees Americano 350ml','unit','finished',2.10,7.90,20,2,1),
 (3,1,2,1,1,'CF-MILKTEA','9551234500035','Signature Milk Tea 350ml','unit','finished',2.80,8.90,20,2,1),
 (4,1,3,2,2,'RM-MILK','9551234500042','Fresh Milk 1L','unit','raw',5.50,0,30,7,0),
 (5,1,3,2,2,'RM-BEAN','9551234500059','Arabica Coffee Beans 1kg','kg','raw',45.00,0,10,180,0),
 (6,1,3,2,2,'RM-SYRUP','9551234500066','Vanilla Syrup 750ml','unit','raw',18.00,0,8,365,0);

INSERT INTO recipes (id, product_id, yield_qty, yield_uom, notes) VALUES
 (1,1,1,'unit','Latte recipe'),
 (2,2,1,'unit','Americano recipe');

INSERT INTO recipe_items (recipe_id, ingredient_id, qty, uom) VALUES
 (1,4,0.220,'unit'),   -- 220ml milk
 (1,5,0.018,'kg'),     -- 18g beans
 (1,6,0.020,'unit'),   -- 20ml syrup
 (2,5,0.020,'kg');     -- 20g beans

-- Users (passwords noted in header) -----------------------------------------
INSERT INTO users (id, company_id, role_id, outlet_id, kiosk_id, username, full_name, email, password_hash, pin_hash) VALUES
 (1,1,1,NULL,NULL,'admin','System Administrator','admin@caffeinees.test','$2y$12$.9/K0Ycds16/XuKwYtXrcegfkZQKka2tb5WHePqas5tx9vxbr3pbG',NULL),
 (2,1,3,1,NULL,'manager','Kiosk-Hub Manager','mgr1@caffeinees.test','$2y$12$rFMU//EDqiwWW/6N5Ol0Nu/roNq94d.QinW/aveXhGPBRoOVjaksy',NULL),
 (3,1,4,1,1,'worker1','Kiosk Worker 1','worker1@caffeinees.test','$2y$12$iwd.35R8CCBbuK8mTM6D0.cyOZfpwSuT6k91xfYtDxWD3uZOcUcU2','$2y$12$J2XST2jNSAm05N136.QkOuRtQxgFhJwaAyq9wMp6S1bOqKG5ITOMS'),
 (4,1,5,3,NULL,'rmanager','Restaurant Manager','rmgr@caffeinees.test','$2y$12$rFMU//EDqiwWW/6N5Ol0Nu/roNq94d.QinW/aveXhGPBRoOVjaksy',NULL),
 (5,1,6,NULL,NULL,'acc','Company Accountant','acc@caffeinees.test','$2y$12$MogqB1ZImVSzb01EKASBmeZPfvzjVnrU0YWYyYM1ZIc5qR83AMTZ.',NULL),
 (6,1,4,3,NULL,'rworker','Restaurant Worker 1','rworker@caffeinees.test','$2y$12$iwd.35R8CCBbuK8mTM6D0.cyOZfpwSuT6k91xfYtDxWD3uZOcUcU2','$2y$12$J2XST2jNSAm05N136.QkOuRtQxgFhJwaAyq9wMp6S1bOqKG5ITOMS');

INSERT INTO workers (id, user_id, outlet_id, kiosk_id, staff_no, hired_at) VALUES
 (1,3,1,1,'EMP-0001','2025-01-15'),
 (2,6,3,NULL,'EMP-0002','2025-03-01');

INSERT INTO settings (company_id, skey, svalue) VALUES
 (1,'low_stock_threshold_pct','20'),
 (1,'expiry_alert_days','3'),
 (1,'forecast_window_days','14'),
 (1,'recon_fee_pct','3.0'),            -- max acceptable MDR/fee band (% of gross)
 (1,'recon_date_window_days','3'),     -- bank settles 0-3 days after terminal batch
 (1,'recon_epsilon','0.50'),           -- rounding tolerance (currency)
 (1,'tax_inclusive','1'),              -- menu prices include SST (MY F&B norm)
 (1,'einvoice_classification','022');  -- MyInvois default item classification

-- Malaysian SST + e-invoice demo setup ---------------------------------------
UPDATE companies
   SET tin='C12345678901', sst_no='W10-1808-31000123', msic_code='56103',
       einvoice_enabled=1
 WHERE id=1;

INSERT INTO tax_codes (id, company_id, code, name, tax_type, rate) VALUES
 (1,1,'SR','Service Tax 6%','service',6.000),
 (2,1,'ZR','Zero-rated','zero',0.000),
 (3,1,'EX','Exempt','exempt',0.000);

-- Prepared F&B sold to consumers is service-taxable; raw materials are not.
UPDATE products SET tax_code_id=1 WHERE company_id=1 AND is_sellable=1;

SET FOREIGN_KEY_CHECKS = 1;
