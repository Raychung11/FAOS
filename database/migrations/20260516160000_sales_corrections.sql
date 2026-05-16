-- Sales corrections: discounts, void, refunds. Additive; existing sales get
-- status 'completed' and zero discount so prior behaviour/totals are unchanged.

ALTER TABLE sales_transactions
  ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER tax_amount,
  ADD COLUMN status ENUM('completed','voided','refunded','partially_refunded')
        NOT NULL DEFAULT 'completed' AFTER source,
  ADD COLUMN voided_at  DATETIME     NULL,
  ADD COLUMN voided_by  BIGINT UNSIGNED NULL,
  ADD COLUMN void_reason VARCHAR(255) NULL;

ALTER TABLE sales_items
  ADD COLUMN discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER line_amount;

-- Ledger needs explicit semantics for reversals.
ALTER TABLE stock_movements
  MODIFY COLUMN movement_type
    ENUM('receive','transfer_out','transfer_in','sale','consume',
         'production_in','production_out','wastage','adjustment','count',
         'void','refund') NOT NULL;

CREATE TABLE sales_refunds (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id     BIGINT UNSIGNED NOT NULL,
  transaction_id BIGINT UNSIGNED NOT NULL,
  refund_ref     VARCHAR(40)  NOT NULL,
  reason         VARCHAR(255) NULL,
  refund_method  ENUM('cash','card','ewallet','transfer') NOT NULL DEFAULT 'cash',
  total_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_amount     DECIMAL(14,2) NOT NULL DEFAULT 0,
  user_id        BIGINT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refund_ref (refund_ref),
  KEY idx_ref_txn (transaction_id),
  CONSTRAINT fk_sr_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_sr_txn     FOREIGN KEY (transaction_id) REFERENCES sales_transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sales_refund_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_id     BIGINT UNSIGNED NOT NULL,
  sales_item_id BIGINT UNSIGNED NOT NULL,
  product_id    BIGINT UNSIGNED NOT NULL,
  qty           DECIMAL(14,3) NOT NULL,
  amount        DECIMAL(14,2) NOT NULL,
  tax_amount    DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sri_refund (refund_id),
  CONSTRAINT fk_sri_refund  FOREIGN KEY (refund_id)     REFERENCES sales_refunds(id) ON DELETE CASCADE,
  CONSTRAINT fk_sri_item    FOREIGN KEY (sales_item_id) REFERENCES sales_items(id),
  CONSTRAINT fk_sri_product FOREIGN KEY (product_id)    REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
