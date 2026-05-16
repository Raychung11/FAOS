#!/bin/bash
# Restaurant + Accountant operational finance. Run AFTER base.sh on the same
# DB (relies on base's reconciliation report creating an AR settlement).
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

RM=$(login /tmp/rm.txt rmanager manager123)
ckc "$RM" '"role":"restaurant_manager"' "restaurant_manager login"
ck "$(curl -s -b /tmp/rm.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/dashboard/outlet)" "200" "restaurant_manager sees outlet dashboard"
ck "$(curl -s -b /tmp/rm.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/finance/summary)" "403" "restaurant_manager blocked from finance"

RW=$(login /tmp/rw.txt rworker worker123); RWC=$(echo "$RW" | grep -oP '"csrf":"\K[^"]+')
ckc "$RW" '"role":"worker"' "restaurant worker login"
WH="-H Content-Type:application/json -H X-CSRF-Token:$RWC -H Accept:application/json"
curl -s -b /tmp/rw.txt $WH -X POST $B/api/sales/shift/open -d '{"opening_float":0}' >/dev/null
ckc "$(curl -s -b /tmp/rw.txt $WH -X POST $B/api/sales -d '{"payment_type":"card","client_uuid":"REST-1","items":[{"product_id":2,"qty":3,"unit_price":7.90}]}')" '"txn_ref":"SAL-' "restaurant QR sale recorded"
ck "$(dbq "SELECT outlet_id FROM sales_transactions WHERE client_uuid='REST-1'")" "3" "restaurant sale tagged to restaurant outlet (3)"

AC=$(login /tmp/ac.txt acc acc123); ACSRF=$(echo "$AC" | grep -oP '"csrf":"\K[^"]+')
ckc "$AC" '"role":"accountant"' "accountant login"
AH="-H Content-Type:application/json -H X-CSRF-Token:$ACSRF -H Accept:application/json"

PNL=$(curl -s -b /tmp/ac.txt "$B/api/finance/summary?from=2026-05-01&to=2026-05-31")
ckc "$PNL" '"gross_profit"' "P&L summary returns"
REV=$(echo "$PNL" | grep -oP '"revenue":\K[0-9.]+')
awk "BEGIN{exit !($REV>0)}" && { echo "  PASS  P&L revenue > 0 ($REV)"; PASS=$((PASS+1)); } || { echo "  FAIL  P&L revenue ($REV)"; FAIL=$((FAIL+1)); }
ckc "$PNL" 'Sales Revenue' "P&L by account group"

ARV=$(curl -s -b /tmp/ac.txt $B/api/finance/receivables)
ckc "$ARV" '"expected_amount":"49.50"' "AR settlement auto-created from delayed report"
SID=$(echo "$ARV" | grep -oP '"id":\K[0-9]+' | head -1)
ckc "$(curl -s -b /tmp/ac.txt $AH -X POST $B/api/finance/receivables/$SID/receive -d '{"amount":49.50,"method":"bank"}')" '"message":"Receipt recorded"' "record AR receipt"
ckc "$(curl -s -b /tmp/ac.txt $B/api/finance/receivables)" '"status":"settled"' "AR settled after full receipt"

INV=$(curl -s -b /tmp/ac.txt $AH -X POST $B/api/finance/payables -d '{"supplier_id":1,"invoice_no":"INV-2026-001","invoice_date":"2026-05-10","due_date":"2026-06-09","total_amount":200.00}')
ckc "$INV" '"message":"Supplier invoice recorded"' "create supplier invoice (AP)"
IID=$(echo "$INV" | grep -oP '"id":\K[0-9]+')
ckc "$(curl -s -b /tmp/ac.txt $B/api/finance/payables)" '"outstanding":"200.00"' "AP outstanding reflects invoice"
ckc "$(curl -s -b /tmp/ac.txt $AH -X POST $B/api/finance/payables/$IID/pay -d '{"amount":200.00,"method":"bank"}')" '"message":"Payment recorded"' "pay supplier invoice"
ckc "$(curl -s -b /tmp/ac.txt $B/api/finance/payables)" '"status":"paid"' "invoice marked paid"
ckc "$(curl -s -b /tmp/ac.txt $AH -X POST $B/api/finance/payables/$IID/pay -d '{"amount":50}')" 'exceeds outstanding' "overpayment rejected"

ckc "$(curl -s -b /tmp/ac.txt "$B/api/finance/statement?from=2026-05-01&to=2026-05-31")" 'Profit &amp; Loss' "printable P&L statement"
ckc "$(curl -s -b /tmp/ac.txt -o /dev/null -w '%{content_type}' "$B/api/finance/statement?export=csv&from=2026-05-01&to=2026-05-31")" "text/csv" "P&L CSV export"
ck "$(curl -s -b /tmp/ac.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/dashboard/hq)" "403" "accountant blocked from HQ dashboard"

finish "FINANCE"
