-- Malaysian SST + LHDN MyInvois e-invoicing.
-- Additive only; existing rows keep tax_code_id NULL (= no tax) so prior
-- behaviour is unchanged until tax is configured.

ALTER TABLE companies
  ADD COLUMN tin             VARCHAR(32)  NULL AFTER reg_no,
  ADD COLUMN sst_no          VARCHAR(32)  NULL AFTER tin,
  ADD COLUMN msic_code       VARCHAR(8)   NULL AFTER sst_no,
  ADD COLUMN einvoice_enabled TINYINT(1)  NOT NULL DEFAULT 0 AFTER msic_code;

CREATE TABLE tax_codes (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  BIGINT UNSIGNED NOT NULL,
  code        VARCHAR(16)  NOT NULL,
  name        VARCHAR(96)  NOT NULL,
  tax_type    ENUM('service','sales','zero','exempt') NOT NULL DEFAULT 'service',
  rate        DECIMAL(6,3) NOT NULL DEFAULT 0,           -- percent, e.g. 6.000
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tax_codes (company_id, code),
  CONSTRAINT fk_tax_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE products
  ADD COLUMN tax_code_id BIGINT UNSIGNED NULL AFTER account_group_id,
  ADD CONSTRAINT fk_products_tax FOREIGN KEY (tax_code_id) REFERENCES tax_codes(id);

ALTER TABLE sales_transactions
  ADD COLUMN subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER total_qty,
  ADD COLUMN tax_amount      DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER subtotal_amount;

ALTER TABLE sales_items
  ADD COLUMN tax_code_id BIGINT UNSIGNED NULL AFTER product_id,
  ADD COLUMN tax_rate    DECIMAL(6,3)  NOT NULL DEFAULT 0 AFTER line_amount,
  ADD COLUMN tax_amount  DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER tax_rate;

-- MyInvois e-invoice documents (standard, B2C consolidated, credit note).
CREATE TABLE einvoices (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  einvoice_no    VARCHAR(40)  NOT NULL,
  doc_type       ENUM('standard','consolidated','credit_note','refund_note') NOT NULL DEFAULT 'standard',
  transaction_id BIGINT UNSIGNED NULL,
  outlet_id      BIGINT UNSIGNED NULL,
  period_start   DATE         NULL,
  period_end     DATE         NULL,
  buyer_name     VARCHAR(160) NOT NULL DEFAULT 'General Public',
  buyer_tin      VARCHAR(32)  NULL,
  buyer_reg_no   VARCHAR(32)  NULL,
  buyer_email    VARCHAR(160) NULL,
  currency       VARCHAR(8)   NOT NULL DEFAULT 'MYR',
  subtotal       DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total          DECIMAL(14,2) NOT NULL DEFAULT 0,
  status         ENUM('draft','pending_submission','submitted','valid','rejected','cancelled')
                   NOT NULL DEFAULT 'draft',
  irbm_uuid      VARCHAR(64)  NULL,           -- IRBM document UUID
  irbm_long_id   VARCHAR(96)  NULL,           -- long id for the validation link
  validation_url VARCHAR(255) NULL,
  payload_json   LONGTEXT     NULL,           -- MyInvois document we generated
  response_json  LONGTEXT     NULL,           -- IRBM response
  reject_reason  VARCHAR(255) NULL,
  submitted_at   DATETIME     NULL,
  validated_at   DATETIME     NULL,
  created_by     BIGINT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_einvoice_no (einvoice_no),
  KEY idx_einv_txn (transaction_id),
  KEY idx_einv_status (company_id, status),
  CONSTRAINT fk_einv_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_einv_txn     FOREIGN KEY (transaction_id) REFERENCES sales_transactions(id),
  CONSTRAINT fk_einv_outlet  FOREIGN KEY (outlet_id) REFERENCES outlets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE einvoice_lines (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  einvoice_id    BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NULL,
  classification VARCHAR(16)  NOT NULL DEFAULT '022',  -- MyInvois classification code
  description    VARCHAR(255) NOT NULL,
  qty            DECIMAL(14,3) NOT NULL DEFAULT 1,
  unit_price     DECIMAL(14,4) NOT NULL DEFAULT 0,
  line_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_rate       DECIMAL(6,3) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_einvl (einvoice_id),
  CONSTRAINT fk_einvl_einv FOREIGN KEY (einvoice_id) REFERENCES einvoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
