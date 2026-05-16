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
 (17,'finance.manage','Manage invoices / payments / receipts','finance');

-- super_admin: everything
INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;
-- hq_manager (+ finance read)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (2,1),(2,3),(2,5),(2,6),(2,7),(2,8),(2,9),(2,10),(2,11),(2,13),(2,15),(2,16);
-- outlet_manager (hypermarket kiosk hub)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (3,2),(3,3),(3,4),(3,5),(3,8),(3,11),(3,12),(3,15);
-- worker
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (4,2),(4,4),(4,12);
-- restaurant_manager (same operational scope, restaurant outlet)
INSERT INTO role_permissions (role_id, permission_id) VALUES
 (5,2),(5,3),(5,4),(5,5),(5,8),(5,11),(5,12),(5,15);
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
