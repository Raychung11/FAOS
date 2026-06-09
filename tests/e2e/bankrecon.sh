#!/bin/bash
# Payment / bank reconciliation: flexible CSV import + batch/line matching.
# Self-contained (only touches recon tables). Expects a freshly seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"
FX="$(dirname "${BASH_SOURCE[0]}")/fixtures"

AC=$(login /tmp/rc.txt acc acc123); CSRF=$(echo "$AC" | grep -oP '"csrf":"\K[^"]+')
ckc "$AC" '"role":"accountant"' "accountant login"
HC="-H X-CSRF-Token:$CSRF -H Accept:application/json"

TP=$(curl -s -b /tmp/rc.txt $HC -F "file=@$FX/terminal.csv" -F "delimiter=," -F "has_header=1" $B/api/recon/preview)
ckc "$TP" '"headers"' "terminal preview returns headers"
TTOK=$(echo "$TP" | grep -oP '"token":"\K[^"]+')
TM='{"date":"Date","ref":"Ref","type":"Type","payer":"Payer","amount":"Amount"}'
TI=$(curl -s -b /tmp/rc.txt $HC -F "token=$TTOK" -F "source_type=terminal" -F "columns=$TM" -F "delimiter=," -F "has_header=1" -F "date_format=d/m/Y" $B/api/recon/import)
ckc "$TI" '"rows":8' "terminal import 8 rows"

BP=$(curl -s -b /tmp/rc.txt $HC -F "file=@$FX/bank.csv" -F "delimiter=," -F "has_header=1" $B/api/recon/preview)
BTOK=$(echo "$BP" | grep -oP '"token":"\K[^"]+')
BM='{"date":"Date","description":"Description","amount":"Amount"}'
BI=$(curl -s -b /tmp/rc.txt $HC -F "token=$BTOK" -F "source_type=bank" -F "columns=$BM" -F "delimiter=," -F "has_header=1" -F "date_format=d/m/Y" -F "bank_label=Maybank 5141" $B/api/recon/import)
ckc "$BI" '"rows":8' "bank import 8 rows (1 debit filtered)"

RUN=$(curl -s -b /tmp/rc.txt $HC -X POST $B/api/recon/run)
ckc "$RUN" '"message":"Reconciliation complete"' "run reconciliation"

SUM=$(curl -s -b /tmp/rc.txt $B/api/recon/summary)
ck "$(dbq "SELECT status FROM settlement_batches WHERE channel='duitnow_qr'")" "matched" "DuitNow exact-matched"
ck "$(dbq "SELECT status FROM settlement_batches WHERE channel='card_visa'")" "fee_variance" "Visa -> fee_variance"
ck "$(dbq "SELECT status FROM settlement_batches WHERE channel='card_master'")" "fee_variance" "Mastercard -> fee_variance"
ck "$(dbq "SELECT status FROM settlement_batches WHERE channel='cash'")" "not_expected" "Cash -> not_expected"
ck "$(dbq "SELECT COUNT(*) FROM settlement_batches WHERE channel='bank_transfer'")" "0" "no batch for line-itemised transfers"
ck "$(dbq "SELECT COUNT(*) FROM terminal_transactions WHERE channel='bank_transfer' AND match_status='matched'")" "3" "3 transfers matched line-level"
ck "$(dbq "SELECT COUNT(*) FROM bank_transactions WHERE match_status='unmatched' AND credit>0")" "1" "1 unmatched bank credit"
ck "$(dbq "SELECT ROUND(implied_fee,2) FROM settlement_batches WHERE channel='card_visa'")" "151.00" "Visa implied MDR = 151.00"
ckc "$SUM" '"matched":1' "summary matched=1"
ckc "$SUM" '"fee_variance":2' "summary fee_variance=2"

curl -s -b /tmp/rc.txt $HC -X POST $B/api/recon/run >/dev/null
ck "$(dbq "SELECT COUNT(*) FROM bank_transactions WHERE match_status='matched'")" "6" "re-run idempotent (6 matched)"

ckc "$(curl -s -b /tmp/rc.txt $HC -F "source_type=terminal" -F "name=MyTerminal" -F 'columns={"date":"Date","amount":"Amount"}' -F "delimiter=," -F "has_header=1" $B/api/recon/mappings)" '"message":"Mapping saved"' "save column mapping"
ckc "$(curl -s -b /tmp/rc.txt "$B/api/recon/mappings?source=terminal")" 'MyTerminal' "saved mapping listed"

RM=$(login /tmp/rcm.txt rmanager manager123); RCSRF=$(echo "$RM" | grep -oP '"csrf":"\K[^"]+')
ck "$(curl -s -b /tmp/rcm.txt -o /dev/null -w '%{http_code}' -H "X-CSRF-Token:$RCSRF" -H 'Accept:application/json' -X POST $B/api/recon/run)" "403" "RBAC: restaurant_manager blocked from recon"

finish "BANK-RECON"
