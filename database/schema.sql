-- ============================================================================
-- FAOS - AI Central Kitchen + QR Kiosk Sales BOS
-- MySQL 8.0 relational schema (all modules / all phases)
-- Engine: InnoDB, utf8mb4, foreign keys enforced.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. MASTER DATA + ORG STRUCTURE
-- ----------------------------------------------------------------------------

CREATE TABLE companies (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  reg_no          VARCHAR(64)  NULL,
  currency        VARCHAR(8)   NOT NULL DEFAULT 'MYR',
  timezone        VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE warehouses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  type            ENUM('central_kitchen','warehouse','cold_room') NOT NULL DEFAULT 'warehouse',
  address         VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_warehouses_code (company_id, code),
  CONSTRAINT fk_warehouses_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE outlets (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  outlet_type     ENUM('kiosk_hub','restaurant') NOT NULL DEFAULT 'kiosk_hub',
  hypermarket     VARCHAR(160) NULL,           -- e.g. AEON, Lotus's, Mydin (kiosk_hub only)
  region          VARCHAR(96)  NULL,
  address         VARCHAR(255) NULL,
  third_party_pos TINYINT(1)   NOT NULL DEFAULT 1,  -- 1=delayed hypermarket report; 0=own POS (restaurant)
  report_lag_days SMALLINT     NOT NULL DEFAULT 21,  -- 3-4 weeks; 0 for restaurants
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outlets_code (company_id, code),
  CONSTRAINT fk_outlets_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE kiosks (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  location_note   VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kiosks_code (outlet_id, code),
  CONSTRAINT fk_kiosks_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE suppliers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(160) NOT NULL,
  contact_person  VARCHAR(120) NULL,
  phone           VARCHAR(40)  NULL,
  email           VARCHAR(160) NULL,
  payment_terms   VARCHAR(64)  NULL,           -- e.g. NET 30
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_code (company_id, code),
  CONSTRAINT fk_suppliers_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- RBAC -----------------------------------------------------------------------

CREATE TABLE roles (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(48)  NOT NULL,        -- super_admin, hq_manager, outlet_manager, worker
  name            VARCHAR(96)  NOT NULL,
  description     VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE permissions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(96)  NOT NULL,        -- e.g. masterdata.manage, sales.scan
  name            VARCHAR(160) NOT NULL,
  module          VARCHAR(48)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE role_permissions (
  role_id         BIGINT UNSIGNED NOT NULL,
  permission_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  role_id         BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NULL,         -- scope (NULL = HQ-wide)
  kiosk_id        BIGINT UNSIGNED NULL,
  username        VARCHAR(64)  NOT NULL,
  full_name       VARCHAR(160) NOT NULL,
  email           VARCHAR(160) NULL,
  phone           VARCHAR(40)  NULL,
  password_hash   VARCHAR(255) NOT NULL,
  pin_hash        VARCHAR(255) NULL,            -- fast kiosk PIN login
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at   DATETIME     NULL,
  failed_attempts SMALLINT     NOT NULL DEFAULT 0,
  locked_until    DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_outlet (outlet_id),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_users_role    FOREIGN KEY (role_id)    REFERENCES roles(id),
  CONSTRAINT fk_users_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id),
  CONSTRAINT fk_users_kiosk   FOREIGN KEY (kiosk_id)   REFERENCES kiosks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Workers are users with the 'worker' role; this view-like table keeps HR-ish
-- attributes + performance counters separate from auth.
CREATE TABLE workers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  kiosk_id        BIGINT UNSIGNED NULL,
  staff_no        VARCHAR(48)  NULL,
  hired_at        DATE         NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_workers_user (user_id),
  CONSTRAINT fk_workers_user   FOREIGN KEY (user_id)   REFERENCES users(id),
  CONSTRAINT fk_workers_outlet FOREIGN KEY (outlet_id) REFERENCES outlets(id),
  CONSTRAINT fk_workers_kiosk  FOREIGN KEY (kiosk_id)  REFERENCES kiosks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Product classification ------------------------------------------------------

CREATE TABLE stock_groups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_groups (company_id, code),
  CONSTRAINT fk_sg_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE account_groups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_account_groups (company_id, code),
  CONSTRAINT fk_ag_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_categories (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  parent_id       BIGINT UNSIGNED NULL,
  code            VARCHAR(32)  NOT NULL,
  name            VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pcat (company_id, code),
  CONSTRAINT fk_pcat_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_pcat_parent  FOREIGN KEY (parent_id)  REFERENCES product_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE products (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  category_id     BIGINT UNSIGNED NULL,
  stock_group_id  BIGINT UNSIGNED NULL,
  account_group_id BIGINT UNSIGNED NULL,
  sku             VARCHAR(48)  NOT NULL,
  barcode         VARCHAR(64)  NULL,
  name            VARCHAR(180) NOT NULL,
  uom             VARCHAR(16)  NOT NULL DEFAULT 'unit',  -- unit, kg, pack, box
  type            ENUM('raw','semi_finished','finished','consumable') NOT NULL DEFAULT 'finished',
  cost_price      DECIMAL(14,4) NOT NULL DEFAULT 0,
  sell_price      DECIMAL(14,4) NOT NULL DEFAULT 0,
  reorder_level   DECIMAL(14,3) NOT NULL DEFAULT 0,
  shelf_life_days SMALLINT     NULL,
  is_sellable     TINYINT(1)   NOT NULL DEFAULT 1,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (company_id, sku),
  KEY idx_products_barcode (barcode),
  CONSTRAINT fk_products_company  FOREIGN KEY (company_id)      REFERENCES companies(id),
  CONSTRAINT fk_products_cat      FOREIGN KEY (category_id)     REFERENCES product_categories(id),
  CONSTRAINT fk_products_sg       FOREIGN KEY (stock_group_id)  REFERENCES stock_groups(id),
  CONSTRAINT fk_products_ag       FOREIGN KEY (account_group_id) REFERENCES account_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE product_outlet_prices (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  sell_price      DECIMAL(14,4) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pop (product_id, outlet_id),
  CONSTRAINT fk_pop_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_pop_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recipes / BOM (central kitchen) --------------------------------------------

CREATE TABLE recipes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id      BIGINT UNSIGNED NOT NULL,     -- output product (finished/semi)
  yield_qty       DECIMAL(14,3) NOT NULL DEFAULT 1,
  yield_uom       VARCHAR(16)  NOT NULL DEFAULT 'unit',
  notes           VARCHAR(255) NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipes_product (product_id),
  CONSTRAINT fk_recipes_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recipe_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id       BIGINT UNSIGNED NOT NULL,
  ingredient_id   BIGINT UNSIGNED NOT NULL,     -- raw/semi product
  qty             DECIMAL(14,4) NOT NULL,
  uom             VARCHAR(16)  NOT NULL DEFAULT 'unit',
  PRIMARY KEY (id),
  KEY idx_ri_recipe (recipe_id),
  CONSTRAINT fk_ri_recipe     FOREIGN KEY (recipe_id)     REFERENCES recipes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_ingredient FOREIGN KEY (ingredient_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. QR + STOCK CONTROL
-- ----------------------------------------------------------------------------

-- A location is a polymorphic warehouse / outlet / kiosk reference.
CREATE TABLE product_batches (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  supplier_id     BIGINT UNSIGNED NULL,
  batch_no        VARCHAR(64)  NOT NULL,
  mfg_date        DATE         NULL,
  expiry_date     DATE         NULL,
  received_qty    DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost       DECIMAL(14,4) NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_batch (product_id, batch_no),
  KEY idx_batch_expiry (expiry_date),
  CONSTRAINT fk_batch_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_batch_product  FOREIGN KEY (product_id)  REFERENCES products(id),
  CONSTRAINT fk_batch_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The physical/logical unit a QR label is stuck on (carton/batch/item).
CREATE TABLE qr_labels (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  qr_ref          VARCHAR(40)  NOT NULL,        -- STK-YYYYMMDD-NNNNNN  (the ONLY thing in the QR)
  label_type      ENUM('carton','batch','item') NOT NULL DEFAULT 'carton',
  product_id      BIGINT UNSIGNED NOT NULL,
  batch_id        BIGINT UNSIGNED NULL,
  init_qty        DECIMAL(14,3) NOT NULL DEFAULT 0,
  uom             VARCHAR(16)  NOT NULL DEFAULT 'unit',
  current_location_type ENUM('warehouse','outlet','kiosk','consumed','void') NOT NULL DEFAULT 'warehouse',
  current_location_id   BIGINT UNSIGNED NULL,
  status          ENUM('active','depleted','void') NOT NULL DEFAULT 'active',
  printed_at      DATETIME     NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_ref (qr_ref),
  KEY idx_qr_product (product_id),
  CONSTRAINT fk_qr_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_qr_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_qr_batch   FOREIGN KEY (batch_id)   REFERENCES product_batches(id),
  CONSTRAINT fk_qr_user    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable stock movement ledger. Every movement is traceable here.
CREATE TABLE stock_movements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  qr_label_id     BIGINT UNSIGNED NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  batch_id        BIGINT UNSIGNED NULL,
  movement_type   ENUM('receive','transfer_out','transfer_in','sale','consume',
                       'production_in','production_out','wastage','adjustment','count') NOT NULL,
  qty             DECIMAL(14,3) NOT NULL,       -- signed: + into location, - out of location
  uom             VARCHAR(16)  NOT NULL DEFAULT 'unit',
  from_loc_type   ENUM('warehouse','outlet','kiosk','supplier','none') NOT NULL DEFAULT 'none',
  from_loc_id     BIGINT UNSIGNED NULL,
  to_loc_type     ENUM('warehouse','outlet','kiosk','customer','none') NOT NULL DEFAULT 'none',
  to_loc_id       BIGINT UNSIGNED NULL,
  ref_table       VARCHAR(40)  NULL,            -- e.g. sales_transactions
  ref_id          BIGINT UNSIGNED NULL,
  unit_cost       DECIMAL(14,4) NOT NULL DEFAULT 0,
  notes           VARCHAR(255) NULL,
  user_id         BIGINT UNSIGNED NULL,
  device_id       VARCHAR(64)  NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_product (product_id),
  KEY idx_sm_qr (qr_label_id),
  KEY idx_sm_type_date (movement_type, created_at),
  CONSTRAINT fk_sm_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_sm_qr      FOREIGN KEY (qr_label_id) REFERENCES qr_labels(id),
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_sm_batch   FOREIGN KEY (batch_id)   REFERENCES product_batches(id),
  CONSTRAINT fk_sm_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Denormalised running balance per product per location (fast dashboards).
CREATE TABLE stock_balances (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  loc_type        ENUM('warehouse','outlet','kiosk') NOT NULL,
  loc_id          BIGINT UNSIGNED NOT NULL,
  qty             DECIMAL(14,3) NOT NULL DEFAULT 0,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_balance (product_id, loc_type, loc_id),
  KEY idx_bal_loc (loc_type, loc_id),
  CONSTRAINT fk_bal_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_bal_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE stock_adjustments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  loc_type        ENUM('warehouse','outlet','kiosk') NOT NULL,
  loc_id          BIGINT UNSIGNED NOT NULL,
  adj_type        ENUM('count','damage','loss','correction','wastage') NOT NULL,
  qty_delta       DECIMAL(14,3) NOT NULL,       -- signed
  reason          VARCHAR(255) NULL,
  approved_by     BIGINT UNSIGNED NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_adj_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_adj_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_adj_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. QR SALES TRACKING
-- ----------------------------------------------------------------------------

CREATE TABLE shifts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  kiosk_id        BIGINT UNSIGNED NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  opened_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at       DATETIME     NULL,
  opening_float   DECIMAL(14,2) NOT NULL DEFAULT 0,
  closing_amount  DECIMAL(14,2) NULL,
  status          ENUM('open','closed') NOT NULL DEFAULT 'open',
  PRIMARY KEY (id),
  KEY idx_shift_user (user_id, status),
  CONSTRAINT fk_shift_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_shift_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id),
  CONSTRAINT fk_shift_kiosk   FOREIGN KEY (kiosk_id)   REFERENCES kiosks(id),
  CONSTRAINT fk_shift_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_transactions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  kiosk_id        BIGINT UNSIGNED NULL,
  shift_id        BIGINT UNSIGNED NULL,
  user_id         BIGINT UNSIGNED NOT NULL,     -- worker
  txn_ref         VARCHAR(40)  NOT NULL,        -- SAL-YYYYMMDD-NNNNNN
  client_uuid     VARCHAR(64)  NULL,            -- offline dedupe key
  payment_type    ENUM('cash','card','ewallet','transfer') NOT NULL,
  total_qty       DECIMAL(14,3) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  source          ENUM('qr_scan','manual','import') NOT NULL DEFAULT 'qr_scan',
  device_id       VARCHAR(64)  NULL,
  sold_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_txn_ref (txn_ref),
  UNIQUE KEY uq_client_uuid (client_uuid),
  KEY idx_sales_outlet_date (outlet_id, sold_at),
  KEY idx_sales_user_date (user_id, sold_at),
  CONSTRAINT fk_sales_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_sales_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id),
  CONSTRAINT fk_sales_kiosk   FOREIGN KEY (kiosk_id)   REFERENCES kiosks(id),
  CONSTRAINT fk_sales_shift   FOREIGN KEY (shift_id)   REFERENCES shifts(id),
  CONSTRAINT fk_sales_user    FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transaction_id  BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  qr_label_id     BIGINT UNSIGNED NULL,
  qty             DECIMAL(14,3) NOT NULL,
  unit_price      DECIMAL(14,4) NOT NULL,
  line_amount     DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_si_txn (transaction_id),
  KEY idx_si_product (product_id),
  CONSTRAINT fk_si_txn     FOREIGN KEY (transaction_id) REFERENCES sales_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_si_product FOREIGN KEY (product_id)     REFERENCES products(id),
  CONSTRAINT fk_si_qr      FOREIGN KEY (qr_label_id)    REFERENCES qr_labels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. PROCUREMENT
-- ----------------------------------------------------------------------------

CREATE TABLE purchase_orders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  supplier_id     BIGINT UNSIGNED NOT NULL,
  warehouse_id    BIGINT UNSIGNED NULL,
  po_ref          VARCHAR(40)  NOT NULL,
  status          ENUM('draft','approved','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  expected_date   DATE         NULL,
  total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by      BIGINT UNSIGNED NULL,
  approved_by     BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_ref (po_ref),
  CONSTRAINT fk_po_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_po_wh       FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_order_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id           BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  qty             DECIMAL(14,3) NOT NULL,
  unit_cost       DECIMAL(14,4) NOT NULL,
  received_qty    DECIMAL(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_poi_po (po_id),
  CONSTRAINT fk_poi_po      FOREIGN KEY (po_id)      REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE grn (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  po_id           BIGINT UNSIGNED NULL,
  supplier_id     BIGINT UNSIGNED NOT NULL,
  warehouse_id    BIGINT UNSIGNED NOT NULL,
  grn_ref         VARCHAR(40)  NOT NULL,
  received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by     BIGINT UNSIGNED NULL,
  invoice_no      VARCHAR(64)  NULL,
  total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grn_ref (grn_ref),
  CONSTRAINT fk_grn_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_grn_po       FOREIGN KEY (po_id)       REFERENCES purchase_orders(id),
  CONSTRAINT fk_grn_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_grn_wh       FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE grn_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_id          BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  batch_id        BIGINT UNSIGNED NULL,
  qty             DECIMAL(14,3) NOT NULL,
  unit_cost       DECIMAL(14,4) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_grni_grn (grn_id),
  CONSTRAINT fk_grni_grn     FOREIGN KEY (grn_id)     REFERENCES grn(id) ON DELETE CASCADE,
  CONSTRAINT fk_grni_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_grni_batch   FOREIGN KEY (batch_id)   REFERENCES product_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_requests (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NULL,
  pr_ref          VARCHAR(40)  NOT NULL,
  status          ENUM('open','approved','converted','rejected') NOT NULL DEFAULT 'open',
  requested_by    BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_ref (pr_ref),
  CONSTRAINT fk_pr_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_pr_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purchase_request_items (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pr_id           BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  qty             DECIMAL(14,3) NOT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_pri_pr      FOREIGN KEY (pr_id)      REFERENCES purchase_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_pri_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. CENTRAL KITCHEN PRODUCTION
-- ----------------------------------------------------------------------------

CREATE TABLE production_orders (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  warehouse_id    BIGINT UNSIGNED NOT NULL,     -- central kitchen
  product_id      BIGINT UNSIGNED NOT NULL,     -- output
  recipe_id       BIGINT UNSIGNED NULL,
  prod_ref        VARCHAR(40)  NOT NULL,
  planned_qty     DECIMAL(14,3) NOT NULL,
  produced_qty    DECIMAL(14,3) NOT NULL DEFAULT 0,
  status          ENUM('planned','in_progress','completed','cancelled') NOT NULL DEFAULT 'planned',
  planned_date    DATE         NULL,
  completed_at    DATETIME     NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prod_ref (prod_ref),
  CONSTRAINT fk_prod_company FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_prod_wh      FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_prod_product FOREIGN KEY (product_id)  REFERENCES products(id),
  CONSTRAINT fk_prod_recipe  FOREIGN KEY (recipe_id)   REFERENCES recipes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE production_consumption (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  production_id   BIGINT UNSIGNED NOT NULL,
  ingredient_id   BIGINT UNSIGNED NOT NULL,
  batch_id        BIGINT UNSIGNED NULL,
  qty             DECIMAL(14,4) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pc_prod (production_id),
  CONSTRAINT fk_pc_prod       FOREIGN KEY (production_id) REFERENCES production_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_ingredient FOREIGN KEY (ingredient_id) REFERENCES products(id),
  CONSTRAINT fk_pc_batch      FOREIGN KEY (batch_id)      REFERENCES product_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 6. RECONCILIATION (delayed 3rd-party hypermarket reports)
-- ----------------------------------------------------------------------------

CREATE TABLE official_sales_reports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  period_start    DATE         NOT NULL,
  period_end      DATE         NOT NULL,
  source_name     VARCHAR(120) NULL,            -- hypermarket name
  imported_by     BIGINT UNSIGNED NULL,
  imported_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_osr_outlet (outlet_id, period_start),
  CONSTRAINT fk_osr_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_osr_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE official_sales_lines (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id       BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NULL,
  sku             VARCHAR(48)  NULL,
  qty             DECIMAL(14,3) NOT NULL DEFAULT 0,
  amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_osl_report (report_id),
  CONSTRAINT fk_osl_report  FOREIGN KEY (report_id)  REFERENCES official_sales_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_osl_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE variance_reports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  report_id       BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  product_id      BIGINT UNSIGNED NULL,
  qr_qty          DECIMAL(14,3) NOT NULL DEFAULT 0,
  qr_amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
  official_qty    DECIMAL(14,3) NOT NULL DEFAULT 0,
  official_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  qty_variance    DECIMAL(14,3) NOT NULL DEFAULT 0,
  amount_variance DECIMAL(14,2) NOT NULL DEFAULT 0,
  status          ENUM('matched','shortage','over_reported','under_reported','missing_scans') NOT NULL,
  generated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_vr_report (report_id),
  CONSTRAINT fk_vr_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_vr_report  FOREIGN KEY (report_id)  REFERENCES official_sales_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_vr_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id),
  CONSTRAINT fk_vr_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 7. AI FORECASTING
-- ----------------------------------------------------------------------------

CREATE TABLE sales_forecasts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NULL,
  product_id      BIGINT UNSIGNED NOT NULL,
  forecast_date   DATE         NOT NULL,
  predicted_qty   DECIMAL(14,3) NOT NULL DEFAULT 0,
  lower_bound     DECIMAL(14,3) NOT NULL DEFAULT 0,
  upper_bound     DECIMAL(14,3) NOT NULL DEFAULT 0,
  method          VARCHAR(32)  NOT NULL DEFAULT 'moving_avg',  -- moving_avg | ai
  generated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fc (outlet_id, product_id, forecast_date),
  CONSTRAINT fk_fc_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_fc_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id),
  CONSTRAINT fk_fc_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 8. SECURITY / AUDIT / OPS
-- ----------------------------------------------------------------------------

CREATE TABLE scan_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  qr_ref          VARCHAR(40)  NOT NULL,
  qr_label_id     BIGINT UNSIGNED NULL,
  scan_context    ENUM('receive','transfer','consume','wastage','count','sale','lookup') NOT NULL,
  outlet_id       BIGINT UNSIGNED NULL,
  kiosk_id        BIGINT UNSIGNED NULL,
  device_id       VARCHAR(64)  NULL,
  ip_address      VARCHAR(45)  NULL,
  result          ENUM('ok','duplicate','invalid','denied') NOT NULL DEFAULT 'ok',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_scan_ref (qr_ref),
  KEY idx_scan_date (created_at),
  CONSTRAINT fk_scan_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NULL,
  user_id         BIGINT UNSIGNED NULL,
  action          VARCHAR(64)  NOT NULL,        -- create/update/delete/approve/login
  entity          VARCHAR(64)  NOT NULL,        -- table / module
  entity_id       VARCHAR(64)  NULL,
  outlet_id       BIGINT UNSIGNED NULL,
  ip_address      VARCHAR(45)  NULL,
  device_id       VARCHAR(64)  NULL,
  before_json     JSON         NULL,
  after_json      JSON         NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE offline_sync_queue (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  client_uuid     VARCHAR(64)  NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  payload_json    JSON         NOT NULL,
  status          ENUM('pending','processed','failed') NOT NULL DEFAULT 'pending',
  error_msg       VARCHAR(255) NULL,
  received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_osq_uuid (client_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NULL,
  skey            VARCHAR(96)  NOT NULL,
  svalue          TEXT         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings (company_id, skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 9. OPERATIONAL FINANCE (Accountant) - AP / AR. No double-entry GL.
-- ----------------------------------------------------------------------------

-- Accounts Payable: supplier invoices + payments against them.
CREATE TABLE supplier_invoices (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  supplier_id     BIGINT UNSIGNED NOT NULL,
  po_id           BIGINT UNSIGNED NULL,
  grn_id          BIGINT UNSIGNED NULL,
  invoice_no      VARCHAR(64)  NOT NULL,
  invoice_date    DATE         NOT NULL,
  due_date        DATE         NULL,
  amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  status          ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  note            VARCHAR(255) NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sinv (company_id, supplier_id, invoice_no),
  KEY idx_sinv_status (status, due_date),
  CONSTRAINT fk_sinv_company  FOREIGN KEY (company_id)  REFERENCES companies(id),
  CONSTRAINT fk_sinv_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_sinv_po       FOREIGN KEY (po_id)       REFERENCES purchase_orders(id),
  CONSTRAINT fk_sinv_grn      FOREIGN KEY (grn_id)      REFERENCES grn(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE supplier_payments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  supplier_invoice_id BIGINT UNSIGNED NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  method          ENUM('cash','bank','cheque','ewallet') NOT NULL DEFAULT 'bank',
  reference       VARCHAR(96)  NULL,
  paid_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id         BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_spay_inv (supplier_invoice_id),
  CONSTRAINT fk_spay_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_spay_inv     FOREIGN KEY (supplier_invoice_id) REFERENCES supplier_invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_spay_user    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accounts Receivable: amounts owed by hypermarkets, recognised when an
-- official (delayed) report is imported & reconciled, settled when paid.
CREATE TABLE ar_settlements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  outlet_id       BIGINT UNSIGNED NOT NULL,
  report_id       BIGINT UNSIGNED NULL,
  period_start    DATE         NOT NULL,
  period_end      DATE         NOT NULL,
  expected_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  received_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status          ENUM('pending','partial','settled') NOT NULL DEFAULT 'pending',
  note            VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ars_outlet (outlet_id, status),
  CONSTRAINT fk_ars_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_ars_outlet  FOREIGN KEY (outlet_id)  REFERENCES outlets(id),
  CONSTRAINT fk_ars_report  FOREIGN KEY (report_id)  REFERENCES official_sales_reports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ar_receipts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id      BIGINT UNSIGNED NOT NULL,
  settlement_id   BIGINT UNSIGNED NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  method          ENUM('cash','bank','cheque','ewallet') NOT NULL DEFAULT 'bank',
  reference       VARCHAR(96)  NULL,
  received_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id         BIGINT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_arr_set (settlement_id),
  CONSTRAINT fk_arr_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_arr_set     FOREIGN KEY (settlement_id) REFERENCES ar_settlements(id) ON DELETE CASCADE,
  CONSTRAINT fk_arr_user    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
