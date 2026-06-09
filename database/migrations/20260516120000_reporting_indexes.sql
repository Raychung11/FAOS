-- Reporting / dashboard performance indexes (additive, safe).
-- Hot paths: HQ dashboard + P&L scope by company_id and date range; the
-- baseline schema only had outlet/user-scoped date indexes.

CREATE INDEX idx_sales_company_date
  ON sales_transactions (company_id, sold_at);

CREATE INDEX idx_sm_company_type_date
  ON stock_movements (company_id, movement_type, created_at);
