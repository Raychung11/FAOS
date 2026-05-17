#!/bin/bash
# Bulk import: product catalogue (upsert by SKU) + opening stock balances.
# Self-contained; fresh seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"
TMP=$(mktemp -d)

A=$(login /tmp/im_a.txt admin admin123); AC=$(echo "$A" | grep -oP '"csrf":"\K[^"]+')
HC="-H X-CSRF-Token:$AC -H Accept:application/json"
ckc "$A" '"role":"super_admin"' "admin login"

# Templates.
ck "$(curl -s -b /tmp/im_a.txt -o /dev/null -w '%{content_type}' $B/api/import/products/template | cut -d';' -f1)" "text/csv" "product template downloads"
ckc "$(curl -s -b /tmp/im_a.txt $B/api/import/stock/template)" 'loc_type' "stock template has headers"

# --- Products CSV: 3 new (1 with bad tax warning), 1 update, 1 error row ---
cat > "$TMP/p.csv" <<'CSV'
sku,name,uom,type,cost_price,sell_price,reorder_level,shelf_life_days,category_code,tax_code,is_sellable
PX1,Test Product One,unit,finished,2.00,5.00,10,3,CAT-COFFEE,SR,1
PX2,Test Product Two,unit,raw,1.00,0,5,,,,0
CF-LATTE,Caffeinees Latte UPDATED,unit,finished,3.20,9.90,20,2,CAT-COFFEE,SR,1
,Missing SKU,unit,finished,1,1,0,,,,1
PX3,Bad Tax Row,unit,finished,1,2,0,,CAT-COFFEE,ZZZ,1
CSV
R=$(curl -s -b /tmp/im_a.txt $HC -F "file=@$TMP/p.csv" $B/api/import/products)
ck "$(echo "$R" | grep -oP '"created":\K[0-9]+')" "3" "products created = 3 (PX1,PX2,PX3)"
ck "$(echo "$R" | grep -oP '"updated":\K[0-9]+')" "1" "existing product updated (CF-LATTE)"
ckc "$R" 'sku and name are required' "missing-SKU row reported as error"
ckc "$R" 'Unknown tax code' "unknown tax code reported as warning"
ck "$(dbq "SELECT name FROM products WHERE sku='PX1' AND company_id=1")" "Test Product One" "new product persisted"
ck "$(dbq "SELECT name FROM products WHERE sku='CF-LATTE' AND company_id=1")" "Caffeinees Latte UPDATED" "existing product upserted"
ck "$(dbq "SELECT tax_code_id FROM products WHERE sku='PX3'")" "NULL" "bad tax code left blank (non-fatal)"

# Idempotent re-run: now all exist -> 0 created.
R2=$(curl -s -b /tmp/im_a.txt $HC -F "file=@$TMP/p.csv" $B/api/import/products)
ck "$(echo "$R2" | grep -oP '"created":\K[0-9]+')" "0" "re-run idempotent (0 created)"

# --- Opening stock ---
cat > "$TMP/s.csv" <<'CSV'
sku,loc_type,loc_code,qty,unit_cost
CF-LATTE,warehouse,CK01,50,3.20
CF-LATTE,outlet,OUT01,12,
NOPE,warehouse,CK01,5,
CF-LATTE,outlet,ZZZ,1,
CSV
S=$(curl -s -b /tmp/im_a.txt $HC -F "file=@$TMP/s.csv" $B/api/import/stock)
ck "$(echo "$S" | grep -oP '"applied":\K[0-9]+')" "2" "opening stock applied = 2"
ckc "$S" "Unknown product SKU 'NOPE'" "unknown SKU reported"
ckc "$S" "Unknown outlet code 'ZZZ'" "unknown location reported"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=(SELECT id FROM products WHERE sku='CF-LATTE' AND company_id=1) AND loc_type='warehouse' AND loc_id=1")" "50" "CK01 opening qty set to 50"
ck "$(dbq "SELECT ROUND(qty,0) FROM stock_balances WHERE product_id=(SELECT id FROM products WHERE sku='CF-LATTE' AND company_id=1) AND loc_type='outlet' AND loc_id=1")" "12" "OUT01 opening qty set to 12"
ck "$(dbq "SELECT ROUND(avg_cost,4) FROM products WHERE sku='CF-LATTE' AND company_id=1")" "3.2000" "AVCO seeded from opening unit_cost"

# Idempotent: same file -> 0 applied, 2 unchanged.
S2=$(curl -s -b /tmp/im_a.txt $HC -F "file=@$TMP/s.csv" $B/api/import/stock)
ck "$(echo "$S2" | grep -oP '"applied":\K[0-9]+')" "0" "opening stock re-run idempotent (0 applied)"
ck "$(echo "$S2" | grep -oP '"unchanged":\K[0-9]+')" "2" "re-run reports 2 unchanged"

# --- Purchase Order from CSV ---
ck "$(curl -s -b /tmp/im_a.txt -o /dev/null -w '%{content_type}' $B/api/import/po/template | cut -d';' -f1)" "text/csv" "PO template downloads"
cat > "$TMP/po.csv" <<'CSV'
barcode,sku,description,qty,uom,unit_cost
,CF-LATTE,Existing latte,5,unit,3.20
RM710,,Prawn 2pcs for Salmon Fish Head,20,pkt,5.20
,,No identifier row,1,unit,1.00
CSV
PO=$(curl -s -b /tmp/im_a.txt $HC -F "file=@$TMP/po.csv" -F "supplier_id=1" -F "warehouse_id=1" -F "create_missing=1" $B/api/import/po)
ckc "$PO" '"po_ref":"PO-' "PO created from CSV"
ck "$(echo "$PO" | grep -oP '"products_matched":\K[0-9]+')" "1" "matched existing product by SKU"
ck "$(echo "$PO" | grep -oP '"products_created":\K[0-9]+')" "1" "auto-created missing product by barcode"
ck "$(echo "$PO" | grep -oP '"total":\K[0-9.]+')" "120" "PO total = 5*3.20 + 20*5.20 = 120.00"
ckc "$PO" 'barcode or sku is required' "row without identifier reported as error"
ck "$(dbq "SELECT barcode FROM products WHERE company_id=1 AND sku='RM710'")" "RM710" "auto-created product carries barcode"
POID=$(echo "$PO" | grep -oP '"po_id":\K[0-9]+')
ck "$(dbq "SELECT status FROM purchase_orders WHERE id=$POID")" "draft" "imported PO is a draft (flows to approve->GRN)"
ckc "$(curl -s -b /tmp/im_a.txt "$B/api/procurement/po")" "\"id\":$POID" "imported PO appears in Procurement list"
# create_missing off + unknown identifier -> all rows fail -> 422
cat > "$TMP/po2.csv" <<'CSV'
barcode,sku,description,qty,uom,unit_cost
ZZZNOPE,,Unknown thing,2,unit,1.00
CSV
ckc "$(curl -s -b /tmp/im_a.txt $HC -F "file=@$TMP/po2.csv" -F "supplier_id=1" -F "create_missing=0" $B/api/import/po)" 'No valid PO lines' "unknown product without create_missing rejected"

# --- RBAC ---
W=$(login /tmp/im_w.txt worker1 worker123); WC=$(echo "$W" | grep -oP '"csrf":"\K[^"]+')
ck "$(curl -s -b /tmp/im_w.txt -o /dev/null -w '%{http_code}' -H "X-CSRF-Token:$WC" -H 'Accept:application/json' -F "file=@$TMP/p.csv" $B/api/import/products)" "403" "RBAC: worker blocked from product import"
ck "$(curl -s -b /tmp/im_w.txt -o /dev/null -w '%{http_code}' -H "X-CSRF-Token:$WC" -H 'Accept:application/json' -F "file=@$TMP/s.csv" $B/api/import/stock)" "403" "RBAC: worker blocked from stock import"
ck "$(curl -s -b /tmp/im_w.txt -o /dev/null -w '%{http_code}' -H "X-CSRF-Token:$WC" -H 'Accept:application/json' -F "file=@$TMP/po.csv" -F "supplier_id=1" $B/api/import/po)" "403" "RBAC: worker blocked from PO import"

rm -rf "$TMP"
finish "IMPORT"
