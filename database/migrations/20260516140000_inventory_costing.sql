-- Inventory & costing realism: moving-average cost (AVCO) + negative-stock
-- guard config. Additive; existing rows fall back to cost_price until the
-- first receipt recomputes avg_cost.

ALTER TABLE products
  ADD COLUMN avg_cost DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER cost_price;

-- Seed avg_cost from the standard cost for any existing catalogue.
UPDATE products SET avg_cost = cost_price WHERE avg_cost = 0;

-- Global default: which movement types are blocked from driving a location
-- negative. Sales/consume are intentionally NOT blocked (QR sales must work
-- even without complete stock); controlled internal moves are.
INSERT INTO settings (company_id, skey, svalue)
VALUES (NULL, 'block_negative_movements', 'transfer_out,production_out');
