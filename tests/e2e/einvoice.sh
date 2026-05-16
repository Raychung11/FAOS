#!/bin/bash
# Malaysian SST + LHDN MyInvois e-invoicing. Self-contained; fresh seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

# Worker makes two SST-inclusive sales (product 1 @ RM9.90 incl 6% ST).
WME=$(login /tmp/ei_w.txt worker1 worker123); WCSRF=$(echo "$WME" | grep -oP '"csrf":"\K[^"]+')
WH="-H Content-Type:application/json -H X-CSRF-Token:$WCSRF -H Accept:application/json"
curl -s -b /tmp/ei_w.txt $WH -X POST $B/api/sales/shift/open -d '{"opening_float":0}' >/dev/null
S1=$(curl -s -b /tmp/ei_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"EI-S1","items":[{"product_id":1,"qty":2,"unit_price":9.90}]}')
ckc "$S1" '"txn_ref":"SAL-' "SST sale #1 recorded"
TXN1=$(dbq "SELECT id FROM sales_transactions WHERE client_uuid='EI-S1'")
curl -s -b /tmp/ei_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"EI-S2","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}' >/dev/null

# Tax split: total unchanged (backward compatible), tax broken out.
ck "$(dbq "SELECT ROUND(total_amount,2) FROM sales_transactions WHERE client_uuid='EI-S1'")" "19.80" "total unchanged (tax-inclusive, backward compatible)"
ck "$(dbq "SELECT ROUND(tax_amount,2) FROM sales_transactions WHERE client_uuid='EI-S1'")" "1.12" "SST 6% extracted from inclusive price"
ck "$(dbq "SELECT ROUND(subtotal_amount,2) FROM sales_transactions WHERE client_uuid='EI-S1'")" "18.68" "net subtotal computed"

AC=$(login /tmp/ei_a.txt acc acc123); ACSRF=$(echo "$AC" | grep -oP '"csrf":"\K[^"]+')
AH="-H Content-Type:application/json -H X-CSRF-Token:$ACSRF -H Accept:application/json"
ckc "$AC" '"role":"accountant"' "accountant login"

# Standard e-invoice for sale #1 (B2B-style buyer).
GEN=$(curl -s -b /tmp/ei_a.txt $AH -X POST $B/api/einvoice/transaction/$TXN1 -d '{"buyer_name":"Acme Sdn Bhd","buyer_tin":"C99988877766"}')
ckc "$GEN" '"einvoice_no":"EI-' "standard e-invoice generated"
ckc "$GEN" '"status":"pending_submission"' "graceful: pending_submission (MyInvois not configured)"
ck "$(dbq "SELECT ROUND(total,2) FROM einvoices WHERE transaction_id=$TXN1")" "19.80" "e-invoice total matches sale"
EID=$(dbq "SELECT id FROM einvoices WHERE transaction_id=$TXN1")
# Payload is valid MyInvois JSON carrying the supplier TIN.
ckc "$(dbq "SELECT payload_json FROM einvoices WHERE id=$EID")" 'C12345678901' "MyInvois payload carries supplier TIN"

# Duplicate guard.
ckc "$(curl -s -b /tmp/ei_a.txt $AH -X POST $B/api/einvoice/transaction/$TXN1 -d '{}')" 'already exists' "duplicate e-invoice blocked"

# Consolidated B2C e-invoice for outlet 1 this month -> only the uninvoiced sale #2.
CON=$(curl -s -b /tmp/ei_a.txt $AH -X POST $B/api/einvoice/consolidated -d '{"outlet_id":1,"period_start":"2026-05-01","period_end":"2026-05-31"}')
ckc "$CON" '"doc_type":"consolidated"' "consolidated e-invoice generated"
ck "$(dbq "SELECT ROUND(total,2) FROM einvoices WHERE doc_type='consolidated'")" "9.90" "consolidated excludes already-invoiced sale (only S2 = 9.90)"
ckc "$(curl -s -b /tmp/ei_a.txt $AH -X POST $B/api/einvoice/consolidated -d '{"outlet_id":1,"period_start":"2026-05-01","period_end":"2026-05-31"}')" 'already exists' "duplicate consolidated blocked"

# Printable tax invoice + validation QR.
ckc "$(curl -s -b /tmp/ei_a.txt "$B/api/einvoice/$EID/print")" 'C12345678901' "printable tax invoice shows TIN"
ck "$(curl -s -b /tmp/ei_a.txt -o /dev/null -w '%{content_type}' "$B/api/einvoice/$EID/qr")" "image/svg+xml" "validation QR (SVG)"

# SST summary (SST-02 prep).
SST=$(curl -s -b /tmp/ei_a.txt "$B/api/finance/sst-summary?from=2026-05-01&to=2026-05-31")
ckc "$SST" '"tax_code":"SR"' "SST summary lists Service Tax code"
OT=$(echo "$SST" | grep -oP '"total_output_tax":\K[0-9.]+')
awk "BEGIN{exit !($OT>0)}" && { echo "  PASS  SST output tax > 0 ($OT)"; PASS=$((PASS+1)); } || { echo "  FAIL  SST output tax ($OT)"; FAIL=$((FAIL+1)); }

# RBAC.
login /tmp/ei_wk.txt worker1 worker123 >/dev/null
ck "$(curl -s -b /tmp/ei_wk.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/einvoice)" "403" "RBAC: worker blocked from e-invoicing"

finish "EINVOICE"
