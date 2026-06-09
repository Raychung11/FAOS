#!/bin/bash
# Inventory & costing realism: moving-average (AVCO), valuation, negative-stock
# behaviour, made-to-order consumption. Self-contained; fresh seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

ADM=$(login /tmp/inv_a.txt admin admin123); ACSRF=$(echo "$ADM" | grep -oP '"csrf":"\K[^"]+')
H="-H Content-Type:application/json -H X-CSRF-Token:$ACSRF -H Accept:application/json"
ckc "$ADM" '"role":"super_admin"' "admin login"

# --- Moving-average cost (AVCO) ---
# GRN #1: 100 milk @ 5.00 into CK. On-hand was 0 -> avg = 5.00.
curl -s -b /tmp/inv_a.txt $H -X POST $B/api/procurement/grn \
  -d '{"supplier_id":1,"warehouse_id":1,"items":[{"product_id":4,"qty":100,"unit_cost":5.00}]}' >/dev/null
ck "$(dbq "SELECT ROUND(avg_cost,4) FROM products WHERE id=4")" "5.0000" "AVCO after 1st receipt = 5.00"

# GRN #2: 100 milk @ 7.00. (100*5 + 100*7)/200 = 6.00.
curl -s -b /tmp/inv_a.txt $H -X POST $B/api/procurement/grn \
  -d '{"supplier_id":1,"warehouse_id":1,"items":[{"product_id":4,"qty":100,"unit_cost":7.00}]}' >/dev/null
ck "$(dbq "SELECT ROUND(avg_cost,4) FROM products WHERE id=4")" "6.0000" "AVCO blended after 2nd receipt = 6.00"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=4 AND loc_type='warehouse' AND loc_id=1")" "200" "milk on hand = 200"

# --- Inventory valuation at moving-average cost ---
IV=$(curl -s -b /tmp/inv_a.txt $B/api/finance/inventory-valuation)
ckc "$IV" '"sku":"RM-MILK"' "valuation lists milk"
ck "$(echo "$IV" | grep -oP '"total_value":\K[0-9.]+')" "1200" "milk valued 200 x 6.00 = 1200 (AVCO)"

# --- Negative-stock guard: blocked for controlled internal moves ---
# Replenishment fulfil pulling more than on hand must be refused.
MGR=$(login /tmp/inv_m.txt manager manager123); MCSRF=$(echo "$MGR" | grep -oP '"csrf":"\K[^"]+')
MH="-H Content-Type:application/json -H X-CSRF-Token:$MCSRF -H Accept:application/json"
RQ=$(curl -s -b /tmp/inv_m.txt $MH -X POST $B/api/replenishment -d '{"items":[{"product_id":4,"qty":99999}]}')
PRID=$(echo "$RQ" | grep -oP '"id":\K[0-9]+' | head -1)
curl -s -b /tmp/inv_a.txt $H -X POST $B/api/replenishment/$PRID/approve >/dev/null
ckc "$(curl -s -b /tmp/inv_a.txt $H -X POST $B/api/replenishment/$PRID/fulfil -d '{"source_warehouse_id":1}')" 'Insufficient stock' "negative guard blocks over-distribution"

# --- Sales are NOT blocked (QR sales must work without complete stock) ---
WME=$(login /tmp/inv_w.txt worker1 worker123); WCSRF=$(echo "$WME" | grep -oP '"csrf":"\K[^"]+')
WH="-H Content-Type:application/json -H X-CSRF-Token:$WCSRF -H Accept:application/json"
curl -s -b /tmp/inv_w.txt $WH -X POST $B/api/sales/shift/open -d '{"opening_float":0}' >/dev/null
ckc "$(curl -s -b /tmp/inv_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"INV-1","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}')" '"txn_ref":"SAL-' "sale recorded with no stock-in (allowed)"
TXN=$(dbq "SELECT id FROM sales_transactions WHERE client_uuid='INV-1'")
# Made-to-order skip: kiosk 1 has no ingredients -> no consume movements.
ck "$(dbq "SELECT COUNT(*) FROM stock_movements WHERE movement_type='consume' AND ref_id=$TXN")" "0" "no recipe consumption when ingredients not stocked at location"
# Finished good still deducted; kiosk goes negative (surfaced, not blocked).
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=1 AND loc_type='kiosk' AND loc_id=1")" "-1" "finished-good deduction allowed to go negative"
# COGS basis: sale movement valued at effective cost (avg_cost 0 -> cost_price 3.20).
ck "$(dbq "SELECT ROUND(unit_cost,2) FROM stock_movements WHERE movement_type='sale' AND ref_id=$TXN")" "3.20" "sale costed at moving-average (fallback standard 3.20)"

# Negative surfaced in valuation.
ckc "$(curl -s -b /tmp/inv_a.txt $B/api/finance/inventory-valuation)" '"negative"' "valuation exposes negative-stock exceptions"

# RBAC.
ck "$(curl -s -b /tmp/inv_w.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/finance/inventory-valuation)" "403" "RBAC: worker blocked from valuation"

finish "INVENTORY"
