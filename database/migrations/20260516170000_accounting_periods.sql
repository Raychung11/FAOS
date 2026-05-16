-- Accounting period close/lock. Once a period is closed, financially
-- material backdated changes (new sale, void, refund dated in that period)
-- are refused. Default is no periods -> nothing locked (backward compatible).
-- NOTE: delayed hypermarket reconciliation imports are intentionally NOT
-- locked (official reports arrive weeks after a period may be closed).

CREATE TABLE accounting_periods (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id   BIGINT UNSIGNED NOT NULL,
  period_start DATE         NOT NULL,
  period_end   DATE         NOT NULL,
  status       ENUM('open','closed') NOT NULL DEFAULT 'closed',
  note         VARCHAR(255) NULL,
  closed_by    BIGINT UNSIGNED NULL,
  closed_at    DATETIME     NULL,
  reopened_by  BIGINT UNSIGNED NULL,
  reopened_at  DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_period (company_id, period_start, period_end),
  KEY idx_ap_company (company_id, status),
  CONSTRAINT fk_ap_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
