#!/bin/bash
# Accounting period close/lock. Self-contained; fresh seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

AC=$(login /tmp/pe_a.txt acc acc123); ACS=$(echo "$AC" | grep -oP '"csrf":"\K[^"]+')
AH="-H Content-Type:application/json -H X-CSRF-Token:$ACS -H Accept:application/json"
ckc "$AC" '"role":"accountant"' "accountant login"

ckc "$(curl -s -b /tmp/pe_a.txt $B/api/finance/periods)" '"data":\[\]' "no periods initially"

# Close April (a past period).
ckc "$(curl -s -b /tmp/pe_a.txt $AH -X POST $B/api/finance/periods/close -d '{"period_start":"2026-04-01","period_end":"2026-04-30","note":"April 2026"}')" '"status":"closed"' "close April period"
ckc "$(curl -s -b /tmp/pe_a.txt $B/api/finance/periods)" '"period_start":"2026-04-01"' "period listed as closed"

# Worker: backdated sale into closed April is rejected; today's sale is fine.
W=$(login /tmp/pe_w.txt worker1 worker123); WC=$(echo "$W" | grep -oP '"csrf":"\K[^"]+')
WH="-H Content-Type:application/json -H X-CSRF-Token:$WC -H Accept:application/json"
curl -s -b /tmp/pe_w.txt $WH -X POST $B/api/sales/shift/open -d '{}' >/dev/null
ckc "$(curl -s -b /tmp/pe_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"PER-BACK","sold_at":"2026-04-10 09:00:00","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}')" 'is closed' "backdated sale into closed period rejected"
ckc "$(curl -s -b /tmp/pe_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"PER-1","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}')" '"txn_ref":"SAL-' "current-period sale allowed"
TID=$(dbq "SELECT id FROM sales_transactions WHERE client_uuid='PER-1'")

# Reconciliation import for the CLOSED April period must still work
# (official reports arrive weeks after close — intentionally not locked).
ckc "$(curl -s -b /tmp/pe_a.txt $AH -X POST $B/api/reconciliation/import -d '{"outlet_id":1,"period_start":"2026-04-01","period_end":"2026-04-30","source_name":"AEON","lines":[{"sku":"CF-LATTE","qty":3,"amount":29.7}]}')" '"variance"' "delayed reconciliation NOT blocked by closed period"

# Close the CURRENT period (covers today).
curl -s -b /tmp/pe_a.txt $AH -X POST $B/api/finance/periods/close -d '{"period_start":"2026-05-01","period_end":"2026-05-31","note":"May 2026"}' >/dev/null
MID=$(dbq "SELECT id FROM accounting_periods WHERE period_start='2026-05-01' AND company_id=1")

# Now today's sale is blocked, and voiding the earlier sale is blocked.
ckc "$(curl -s -b /tmp/pe_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"PER-2","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}')" 'is closed' "sale blocked after current period closed"
M=$(login /tmp/pe_m.txt manager manager123); MC=$(echo "$M" | grep -oP '"csrf":"\K[^"]+')
MH="-H Content-Type:application/json -H X-CSRF-Token:$MC -H Accept:application/json"
ckc "$(curl -s -b /tmp/pe_m.txt $MH -X POST $B/api/sales/$TID/void -d '{"reason":"x"}')" 'is closed' "void blocked in closed period"

# Reopen May -> sale + void allowed again.
ckc "$(curl -s -b /tmp/pe_a.txt $AH -X POST $B/api/finance/periods/$MID/reopen -d '{}')" '"status":"open"' "reopen period"
ckc "$(curl -s -b /tmp/pe_w.txt $WH -X POST $B/api/sales -d '{"payment_type":"cash","client_uuid":"PER-3","items":[{"product_id":1,"qty":1,"unit_price":9.90}]}')" '"txn_ref":"SAL-' "sale allowed after reopen"
ckc "$(curl -s -b /tmp/pe_m.txt $MH -X POST $B/api/sales/$TID/void -d '{"reason":"ok now"}')" '"status":"voided"' "void allowed after reopen"

# RBAC: worker cannot close periods (finance.manage).
ck "$(curl -s -b /tmp/pe_w.txt -o /dev/null -w '%{http_code}' $WH -X POST $B/api/finance/periods/close -d '{"period_start":"2026-03-01","period_end":"2026-03-31"}')" "403" "RBAC: worker cannot close periods"

finish "PERIOD"
