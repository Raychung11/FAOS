#!/bin/bash
# Sales corrections: discounts, void (full stock reversal), partial refund,
# and the financial netting (P&L / SST). Self-contained; fresh seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

# --- Worker makes sales (product 1 = CF-LATTE @ RM9.90, SST 6% inclusive) ---
W=$(login /tmp/cor_w.txt worker1 worker123); WC=$(echo "$W" | grep -oP '"csrf":"\K[^"]+')
WH="-H Content-Type:application/json -H X-CSRF-Token:$WC -H Accept:application/json"
curl -s -b /tmp/cor_w.txt $WH -X POST $B/api/sales/shift/open -d '{}' >/dev/null

# Sale A: 10% line discount. gross 19.80 -> -1.98 = 17.82 (tax-inclusive split).
SA=$(curl -s -b /tmp/cor_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"COR-A","items":[{"product_id":1,"qty":2,"unit_price":9.90,"discount_pct":10}]}')
ckc "$SA" '"txn_ref":"SAL-' "discounted sale recorded"
ck "$(dbq "SELECT ROUND(total_amount,2) FROM sales_transactions WHERE client_uuid='COR-A'")" "17.82" "discount applied to total (19.80-1.98)"
ck "$(dbq "SELECT ROUND(discount_amount,2) FROM sales_transactions WHERE client_uuid='COR-A'")" "1.98" "discount_amount recorded"
ck "$(dbq "SELECT ROUND(tax_amount,2) FROM sales_transactions WHERE client_uuid='COR-A'")" "1.01" "SST recomputed on discounted price"

# Sale B: to be voided.
curl -s -b /tmp/cor_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"COR-B","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}' >/dev/null
TB=$(dbq "SELECT id FROM sales_transactions WHERE client_uuid='COR-B'")
# Sale C: to be partially refunded.
curl -s -b /tmp/cor_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"COR-C","items":[{"product_id":1,"qty":3,"unit_price":9.90}]}' >/dev/null
TC=$(dbq "SELECT id FROM sales_transactions WHERE client_uuid='COR-C'")
SIC=$(dbq "SELECT id FROM sales_items WHERE transaction_id=$TC LIMIT 1")

# --- RBAC: worker cannot void/refund ---
ck "$(curl -s -b /tmp/cor_w.txt -o /dev/null -w '%{http_code}' $WH -X POST $B/api/sales/$TB/void -d '{}')" "403" "RBAC: worker cannot void"

# --- Manager voids sale B ---
M=$(login /tmp/cor_m.txt manager manager123); MC=$(echo "$M" | grep -oP '"csrf":"\K[^"]+')
MH="-H Content-Type:application/json -H X-CSRF-Token:$MC -H Accept:application/json"
VD=$(curl -s -b /tmp/cor_m.txt $MH -X POST $B/api/sales/$TB/void -d '{"reason":"wrong entry"}')
ckc "$VD" '"status":"voided"' "manager voided sale B"
ck "$(dbq "SELECT COUNT(*) FROM stock_movements WHERE movement_type='void' AND ref_id=$TB")" "1" "void reversed stock (compensating movement)"
ckc "$(curl -s -b /tmp/cor_m.txt $MH -X POST $B/api/sales/$TB/void -d '{}')" 'Only a completed sale' "double-void blocked"

# --- Manager partial-refunds 1 of 3 units on sale C ---
RF=$(curl -s -b /tmp/cor_m.txt $MH -X POST $B/api/sales/$TC/refund -d "{\"reason\":\"1 spilled\",\"refund_method\":\"cash\",\"items\":[{\"sales_item_id\":$SIC,\"qty\":1}]}")
ckc "$RF" '"refund_ref":"REF-' "partial refund recorded"
ck "$(dbq "SELECT status FROM sales_transactions WHERE id=$TC")" "partially_refunded" "txn marked partially_refunded"
ck "$(dbq "SELECT ROUND(total_amount,2) FROM sales_refunds WHERE transaction_id=$TC")" "9.90" "refund amount = 1 x 9.90"
ck "$(dbq "SELECT COUNT(*) FROM stock_movements WHERE movement_type='refund' AND ref_table='sales_refunds'")" "1" "refund returned stock"
ckc "$(curl -s -b /tmp/cor_m.txt $MH -X POST $B/api/sales/$TC/refund -d "{\"items\":[{\"sales_item_id\":$SIC,\"qty\":5}]}")" 'exceeds remaining' "over-refund blocked"

# --- Accountant: P&L nets refunds, excludes voided ---
A=$(login /tmp/cor_a.txt acc acc123)
PNL=$(curl -s -b /tmp/cor_a.txt "$B/api/finance/summary?from=2026-05-01&to=2026-12-31")
ck "$(echo "$PNL" | grep -oP '"gross_sales":\K[0-9.]+')" "47.52" "gross_sales excludes voided B (17.82+29.70)"
ck "$(echo "$PNL" | grep -oP '"refunds":\K[0-9.]+')" "9.9" "refunds total = 9.90"
ck "$(echo "$PNL" | grep -oP '"revenue":\K[0-9.]+')" "37.62" "revenue = gross - refunds"

SST=$(curl -s -b /tmp/cor_a.txt "$B/api/finance/sst-summary?from=2026-05-01&to=2026-12-31")
ck "$(echo "$SST" | grep -oP '"gross_output_tax":\K[0-9.]+')" "2.69" "gross SST excludes voided (1.01+1.68)"
ck "$(echo "$SST" | grep -oP '"refund_tax":\K[0-9.]+')" "0.56" "refund tax netted"
ck "$(echo "$SST" | grep -oP '"total_output_tax":\K[0-9.]+')" "2.13" "net output tax = 2.69 - 0.56"

# --- e-Invoice must refuse a voided sale ---
ackc=$(echo "$A" | grep -oP '"csrf":"\K[^"]+')
ckc "$(curl -s -b /tmp/cor_a.txt -H "Content-Type:application/json" -H "X-CSRF-Token:$ackc" -H "Accept:application/json" -X POST $B/api/einvoice/transaction/$TB -d '{}')" 'voided' "e-invoice blocked for voided sale"

finish "CORRECTIONS"
