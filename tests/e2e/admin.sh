#!/bin/bash
# User & role administration. Self-contained; fresh seeded DB.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/_env.sh"

raw_login() { # jar user pass -> http code
  local J=$1 U=$2 P=$3
  rm -f "$J"; curl -s -c "$J" "$B/login" >/dev/null
  local C; C=$(curl -s -b "$J" "$B/login" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
  curl -s -b "$J" -c "$J" -o /dev/null -w '%{http_code}' -X POST "$B/login" \
    --data-urlencode "_csrf=$C" --data-urlencode "username=$U" --data-urlencode "password=$P"
}
pin_login() { # jar user pin -> http code
  local J=$1 U=$2 PIN=$3
  rm -f "$J"; curl -s -c "$J" "$B/login" >/dev/null
  local C; C=$(curl -s -b "$J" "$B/login" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
  curl -s -b "$J" -c "$J" -o /dev/null -w '%{http_code}' -X POST "$B/login" \
    --data-urlencode "_csrf=$C" --data-urlencode "username=$U" --data-urlencode "password=$PIN" \
    --data-urlencode "pin_mode=1"
}

ADM=$(login /tmp/ad_a.txt admin admin123); AC=$(echo "$ADM" | grep -oP '"csrf":"\K[^"]+')
H="-H Content-Type:application/json -H X-CSRF-Token:$AC -H Accept:application/json"
ckc "$ADM" '"role":"super_admin"' "admin login"
ADMID=$(dbq "SELECT id FROM users WHERE username='admin'")

LIST=$(curl -s -b /tmp/ad_a.txt $B/api/admin/users)
ckc "$LIST" '"username":"worker1"' "user list returns users"
echo "$LIST" | grep -q 'password_hash' && { echo "  FAIL  password_hash leaked"; FAIL=$((FAIL+1)); } || { echo "  PASS  no password_hash in payload"; PASS=$((PASS+1)); }

# Create a kiosk worker with a PIN.
NEW=$(curl -s -b /tmp/ad_a.txt $H -X POST $B/api/admin/users -d '{"username":"cashier9","full_name":"Cashier Nine","role_id":4,"password":"pass1234","outlet_id":1,"kiosk_id":1,"pin":"4321"}')
ckc "$NEW" '"message":"User created"' "create user"
NID=$(echo "$NEW" | grep -oP '"id":\K[0-9]+')
ck "$(dbq "SELECT COUNT(*) FROM workers WHERE user_id=$NID")" "1" "worker row auto-created for worker role"
ckc "$(curl -s -b /tmp/ad_a.txt $B/api/admin/users/$NID)" '"has_pin":1' "PIN stored (hashed)"

# Duplicate username rejected.
ckc "$(curl -s -b /tmp/ad_a.txt $H -X POST $B/api/admin/users -d '{"username":"cashier9","full_name":"Dup","role_id":4,"password":"pass1234"}')" 'Username already taken' "duplicate username blocked"
# Weak password rejected.
ckc "$(curl -s -b /tmp/ad_a.txt $H -X POST $B/api/admin/users -d '{"username":"weakpw","full_name":"W","role_id":4,"password":"123"}')" 'at least 6 characters' "weak (numeric) password rejected"

# New user can log in with password AND with PIN.
ck "$(raw_login /tmp/ad_n.txt cashier9 pass1234)" "302" "new user logs in with password"
ck "$(pin_login /tmp/ad_p.txt cashier9 4321)" "302" "new user logs in with PIN"

# Update: rename + deactivate -> login blocked; reactivate.
curl -s -b /tmp/ad_a.txt $H -X PUT $B/api/admin/users/$NID -d '{"full_name":"Cashier IX","is_active":0}' >/dev/null
ck "$(dbq "SELECT full_name FROM users WHERE id=$NID")" "Cashier IX" "profile updated"
ck "$(raw_login /tmp/ad_x.txt cashier9 pass1234)" "302" "inactive user cannot authenticate (redirected to login)"
ckc "$(curl -s -b /tmp/ad_x.txt $B/api/me)" '"ok":false' "inactive user has no session"
curl -s -b /tmp/ad_a.txt $H -X PUT $B/api/admin/users/$NID -d '{"is_active":1}' >/dev/null

# Reset password: new works, old fails.
curl -s -b /tmp/ad_a.txt $H -X POST $B/api/admin/users/$NID/password -d '{"password":"newpass99"}' >/dev/null
ck "$(raw_login /tmp/ad_r.txt cashier9 newpass99)" "302" "login with reset password works"
ckc "$(curl -s -b /tmp/ad_r.txt $B/api/me)" '"username":"cashier9"' "reset-password session valid"

# Self-protection safeguards.
ckc "$(curl -s -b /tmp/ad_a.txt $H -X PUT $B/api/admin/users/$ADMID -d '{"is_active":0}')" 'deactivate your own account' "cannot deactivate self"
ckc "$(curl -s -b /tmp/ad_a.txt $H -X PUT $B/api/admin/users/$ADMID -d '{"role_id":4}')" 'change your own role' "cannot change own role"

# Roles & permissions matrix.
RJ=$(curl -s -b /tmp/ad_a.txt $B/api/admin/roles)
ckc "$RJ" '"code":"super_admin"' "roles endpoint returns roles"
ckc "$RJ" '"code":"sales.void_refund"' "role permissions listed"

# RBAC: worker cannot access user admin.
login /tmp/ad_w.txt worker1 worker123 >/dev/null
ck "$(curl -s -b /tmp/ad_w.txt -o /dev/null -w '%{http_code}' -H 'Accept:application/json' $B/api/admin/users)" "403" "RBAC: worker blocked from user admin"

finish "ADMIN"
