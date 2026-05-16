-- Atomic reference sequences. Replaces the racy MAX(col)+1 scheme in
-- next_ref() which could mint duplicate refs under concurrency and roll back
-- a fully-built sale/PO. One row per (prefix + date); incremented atomically.

CREATE TABLE ref_counters (
  scope      VARCHAR(64)     NOT NULL,
  seq        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
