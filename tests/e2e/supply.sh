#!/bin/bash
# Supply chain: PO -> GRN -> AP, central-kitchen production, replenishment.
# Self-contained. Expects a freshly seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

ADM=$(login /tmp/sa.txt admin admin123); ACSRF=$(echo "$ADM" | grep -oP '"csrf":"\K[^"]+')
H="-H Content-Type:application/json -H X-CSRF-Token:$ACSRF -H Accept:application/json"
ckc "$ADM" '"role":"super_admin"' "admin login"

PO=$(curl -s -b /tmp/sa.txt $H -X POST $B/api/procurement/po -d '{"supplier_id":1,"warehouse_id":1,"items":[{"product_id":4,"qty":100,"unit_cost":5.50},{"product_id":5,"qty":20,"unit_cost":45},{"product_id":6,"qty":10,"unit_cost":18}]}')
ckc "$PO" '"po_ref":"PO-' "create PO"
POID=$(echo "$PO" | grep -oP '"id":\K[0-9]+' | head -1)
ck "$(echo "$PO" | grep -oP '"total":\K[0-9.]+')" "1630" "PO total = 1630"
curl -s -b /tmp/sa.txt $H -X POST $B/api/procurement/po/$POID/approve >/dev/null
ck "$(dbq "SELECT status FROM purchase_orders WHERE id=$POID")" "approved" "PO approved"

GRN=$(curl -s -b /tmp/sa.txt $H -X POST $B/api/procurement/grn -d "{\"po_id\":$POID,\"supplier_id\":1,\"warehouse_id\":1,\"invoice_no\":\"SINV-9001\",\"items\":[{\"product_id\":4,\"qty\":100,\"unit_cost\":5.50},{\"product_id\":5,\"qty\":20,\"unit_cost\":45},{\"product_id\":6,\"qty\":10,\"unit_cost\":18}]}")
ckc "$GRN" '"grn_ref":"GRN-' "receive GRN"
ck "$(dbq "SELECT status FROM purchase_orders WHERE id=$POID")" "received" "PO -> received"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=4 AND loc_type='warehouse' AND loc_id=1")" "100" "milk stocked at CK"
ck "$(dbq "SELECT status FROM supplier_invoices WHERE invoice_no='SINV-9001'")" "unpaid" "AP invoice auto-raised"
ck "$(dbq "SELECT ROUND(total_amount,0) FROM supplier_invoices WHERE invoice_no='SINV-9001'")" "1630" "AP invoice total = 1630"
ck "$(dbq "SELECT COUNT(*) FROM product_batches WHERE product_id=4")" "1" "batch created for milk"

PR=$(curl -s -b /tmp/sa.txt $H -X POST $B/api/production -d '{"product_id":1,"warehouse_id":1,"planned_qty":50}')
ckc "$PR" '"prod_ref":"PRD-' "create production order"
PRID=$(echo "$PR" | grep -oP '"id":\K[0-9]+' | head -1)
curl -s -b /tmp/sa.txt $H -X POST $B/api/production/$PRID/start >/dev/null
ckc "$(curl -s -b /tmp/sa.txt $H -X POST $B/api/production/$PRID/complete -d '{"produced_qty":50}')" '"message":"Production completed' "complete production"
ck "$(dbq "SELECT status FROM production_orders WHERE id=$PRID")" "completed" "production completed"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=4 AND loc_type='warehouse' AND loc_id=1")" "89" "milk consumed (100-11=89)"
ck "$(dbq "SELECT ROUND(qty,1) FROM stock_balances WHERE product_id=5 AND loc_type='warehouse' AND loc_id=1")" "19.1" "beans consumed (20-0.9)"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=1 AND loc_type='warehouse' AND loc_id=1")" "50" "latte output stocked (50)"
ck "$(dbq "SELECT COUNT(*) FROM stock_movements WHERE movement_type='production_out' AND ref_id=$PRID")" "3" "3 consumption movements"
ck "$(dbq "SELECT COUNT(*) FROM stock_movements WHERE movement_type='production_in' AND ref_id=$PRID")" "1" "1 output movement"

MGR=$(login /tmp/mg.txt manager manager123); MCSRF=$(echo "$MGR" | grep -oP '"csrf":"\K[^"]+')
MH="-H Content-Type:application/json -H X-CSRF-Token:$MCSRF -H Accept:application/json"
RQ=$(curl -s -b /tmp/mg.txt $MH -X POST $B/api/replenishment -d '{"items":[{"product_id":1,"qty":20}]}')
ckc "$RQ" '"pr_ref":"PR-' "outlet manager raises PR"
PRRID=$(echo "$RQ" | grep -oP '"id":\K[0-9]+' | head -1)
ck "$(curl -s -b /tmp/mg.txt -o /dev/null -w '%{http_code}' $MH -X POST $B/api/replenishment/$PRRID/approve)" "403" "RBAC: manager cannot approve PR"
curl -s -b /tmp/sa.txt $H -X POST $B/api/replenishment/$PRRID/approve >/dev/null
ck "$(dbq "SELECT status FROM purchase_requests WHERE id=$PRRID")" "approved" "admin approved PR"
ckc "$(curl -s -b /tmp/sa.txt $H -X POST $B/api/replenishment/$PRRID/fulfil -d '{"source_warehouse_id":1}')" '"message":"PR fulfilled' "fulfil PR"
ck "$(dbq "SELECT status FROM purchase_requests WHERE id=$PRRID")" "converted" "PR -> converted"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=1 AND loc_type='warehouse' AND loc_id=1")" "30" "CK 50-20=30 after distribution"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=1 AND loc_type='outlet' AND loc_id=1")" "20" "outlet 1 received 20"

RQ2=$(curl -s -b /tmp/mg.txt $MH -X POST $B/api/replenishment -d '{"items":[{"product_id":1,"qty":99999}]}')
PRR2=$(echo "$RQ2" | grep -oP '"id":\K[0-9]+' | head -1)
curl -s -b /tmp/sa.txt $H -X POST $B/api/replenishment/$PRR2/approve >/dev/null
ckc "$(curl -s -b /tmp/sa.txt $H -X POST $B/api/replenishment/$PRR2/fulfil -d '{"source_warehouse_id":1}')" 'Insufficient stock' "fulfil blocked when short"

login /tmp/wk.txt worker1 worker123 >/dev/null
ck "$(curl -s -b /tmp/wk.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/procurement/po)" "403" "RBAC: worker blocked from procurement"

finish "SUPPLY"
