#!/bin/bash
# Core flow: auth/RBAC/CSRF, master data, QR labels/stock, sales, dashboards,
# reports, sales-vs-hypermarket reconciliation. Expects a freshly seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"
J=/tmp/cj_base.txt

LP=$(curl -s -c $J $B/login)
CSRF=$(echo "$LP" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
ckc "$LP" "FAOS BOS" "login page renders"
[ -n "$CSRF" ] && { echo "  PASS  csrf token present"; PASS=$((PASS+1)); } || { echo "  FAIL  csrf token"; FAIL=$((FAIL+1)); }

LOGIN=$(curl -s -b $J -c $J -o /dev/null -w "%{http_code}" -X POST $B/login \
  --data-urlencode "_csrf=$CSRF" --data-urlencode "username=admin" --data-urlencode "password=admin123")
ck "$LOGIN" "302" "admin login redirects"

ME=$(curl -s -b $J $B/api/me)
ckc "$ME" '"role":"super_admin"' "/api/me returns admin"
CSRF=$(echo "$ME" | grep -oP '"csrf":"\K[^"]+')
H="-H Content-Type:application/json -H X-CSRF-Token:$CSRF -H Accept:application/json"

ckc "$(curl -s -b $J $B/api/md/products)" '"sku":"CF-LATTE"' "list products"
ckc "$(curl -s -b $J $H -X POST $B/api/md/products -d '{"sku":"CF-MOCHA","name":"Mocha 350ml","uom":"unit","type":"finished","cost_price":3.5,"sell_price":10.9,"is_sellable":1,"is_active":1}')" '"message":"Created"' "create product"
# Bug fix regression: company-scoped resource create must inject company_id
# server-side (UI sends no company_id). Previously tax_codes 500'd.
ckc "$(curl -s -b $J $H -X POST $B/api/md/tax_codes -d '{"code":"SR8","name":"Service Tax 8%","tax_type":"service","rate":8,"is_active":1}')" '"message":"Created"' "create tax_code (company_id injected)"

QL=$(curl -s -b $J $H -X POST $B/api/qr/labels -d '{"product_id":1,"count":3,"label_type":"carton","init_qty":24}')
ckc "$QL" '"qr_ref":"STK-' "generate QR labels"
REF=$(echo "$QL" | grep -oP '"qr_ref":"\KSTK-[0-9]+-[0-9]+' | head -1)

ck "$(curl -s -b $J -o /dev/null -w '%{http_code}:%{content_type}' "$B/api/qr/$REF/image?fmt=png")" "200:image/png" "QR image endpoint"
ckc "$(curl -s -b $J "$B/api/qr/$REF")" '"product_name"' "QR lookup resolves product"

ckc "$(curl -s -b $J $H -X POST $B/api/stock/receive -d "{\"qr_ref\":\"$REF\",\"qty\":24,\"to_type\":\"warehouse\",\"to_id\":2}")" '"message":"Stock received"' "stock receive"
ckc "$(curl -s -b $J $H -X POST $B/api/stock/transfer -d "{\"qr_ref\":\"$REF\",\"qty\":10,\"from_type\":\"warehouse\",\"from_id\":2,\"to_type\":\"kiosk\",\"to_id\":1}")" '"message":"Stock transferred"' "stock transfer"
ckc "$(curl -s -b $J "$B/api/stock/balances?loc_type=kiosk&loc_id=1")" '"qty"' "stock balances query"

WME=$(login /tmp/cj_w.txt worker1 worker123); WCSRF=$(echo "$WME" | grep -oP '"csrf":"\K[^"]+')
ckc "$WME" '"role":"worker"' "worker login"
WH="-H Content-Type:application/json -H X-CSRF-Token:$WCSRF -H Accept:application/json"
curl -s -b /tmp/cj_w.txt $WH -X POST $B/api/sales/shift/open -d '{"opening_float":50}' >/dev/null
ckc "$(curl -s -b /tmp/cj_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"E2E-UUID-1","items":[{"product_id":1,"qty":2,"unit_price":9.90}]}')" '"txn_ref":"SAL-' "record sale"
ckc "$(curl -s -b /tmp/cj_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"E2E-UUID-1","items":[{"product_id":1,"qty":2}]}')" '"duplicate":true' "sale idempotent on client_uuid"
ckc "$(curl -s -b /tmp/cj_w.txt $B/api/dashboard/worker)" '"today"' "worker dashboard"

# Made-to-order consumption only fires when the location stocks the
# ingredient. Kiosk 1 has no raw milk/beans/syrup (those live at the central
# kitchen), so a pre-made finished good is sold WITHOUT consuming ingredients
# — and crucially without driving the kiosk negative.
NEG=$(dbq "SELECT COUNT(*) FROM stock_balances WHERE loc_type='kiosk' AND loc_id=1 AND qty < 0")
ck "${NEG:-x}" "0" "no negative kiosk ingredient stock (no double-count)"
ck "$(dbq "SELECT qty FROM stock_balances WHERE product_id=1 AND loc_type='kiosk' AND loc_id=1")" "8.000" "sale deducted kiosk stock (10-2=8)"

AME=$(login /tmp/cj_a.txt admin admin123); ACSRF=$(echo "$AME" | grep -oP '"csrf":"\K[^"]+')
AH="-H Content-Type:application/json -H X-CSRF-Token:$ACSRF -H Accept:application/json"
ckc "$(curl -s -b /tmp/cj_a.txt $B/api/dashboard/hq)" '"outlet_rank"' "HQ dashboard"
ckc "$(curl -s -b /tmp/cj_a.txt $B/api/dashboard/ai)" '"forecast"' "AI dashboard"
ckc "$(curl -s -b /tmp/cj_a.txt "$B/api/reports/sales?by=sku")" '"per_thousand"' "sales report per-thousand"
ckc "$(curl -s -b /tmp/cj_a.txt -w '%{content_type}' -o /dev/null "$B/api/reports/sales?by=outlet&export=csv")" "text/csv" "CSV export"
ckc "$(curl -s -b /tmp/cj_a.txt $AH -X POST $B/api/reconciliation/import -d '{"outlet_id":1,"period_start":"'$CURMONTH_START'","period_end":"'$CURMONTH_END'","source_name":"AEON","lines":[{"sku":"CF-LATTE","qty":5,"amount":49.5}]}')" '"variance"' "reconciliation import+variance"
ckc "$(curl -s -b /tmp/cj_a.txt "$B/api/reconciliation/variance")" 'under_reported' "variance classified under_reported"

ck "$(curl -s -b /tmp/cj_a.txt -o /dev/null -w '%{http_code}' -X POST $B/api/md/products -H 'Content-Type:application/json' -H 'Accept:application/json' -d '{"sku":"X"}')" "419" "CSRF enforced on POST"
login /tmp/cj_w2.txt worker1 worker123 >/dev/null
ck "$(curl -s -b /tmp/cj_w2.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/dashboard/hq)" "403" "RBAC blocks worker from HQ dashboard"

finish "BASE"
