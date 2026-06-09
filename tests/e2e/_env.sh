#!/bin/bash
# Shared e2e helpers. Sourced by every suite. Honours env overrides; falls
# back to .env values; finally to local-dev defaults.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

_envval() {
  grep -E "^$1=" "$ROOT/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'\'
}

BASE_URL="${BASE_URL:-$(_envval APP_URL)}"
BASE_URL="${BASE_URL:-http://127.0.0.1:8080}"
B="$BASE_URL"

DB_HOST="${DB_HOST:-$(_envval DB_HOST)}"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-$(_envval DB_PORT)}"; DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-$(_envval DB_USER)}"; DB_USER="${DB_USER:-faos}"
DB_PASS="${DB_PASS:-$(_envval DB_PASS)}"; DB_PASS="${DB_PASS:-secret}"
DB_NAME="${DB_NAME:-$(_envval DB_NAME)}"; DB_NAME="${DB_NAME:-faos_bos}"

# Current-month bracket so suites stay green regardless of when CI runs.
# Reconciliation imports / P&L date ranges must always contain "today's"
# demo sales.
CURMONTH_START=$(date +%Y-%m-01)
CURMONTH_END=$(date -d "$CURMONTH_START +1 month -1 day" +%Y-%m-%d)
PREVMONTH_START=$(date -d "$CURMONTH_START -1 month" +%Y-%m-01)
PREVMONTH_END=$(date -d "$CURMONTH_START -1 day" +%Y-%m-%d)
PREVMONTH_MID=$(date -d "$PREVMONTH_START +9 days" +%Y-%m-%d)

PASS=0; FAIL=0

dbq() { MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -N -e "$1" "$DB_NAME"; }

ck()  { if [ "$1" = "$2" ]; then echo "  PASS  $3"; PASS=$((PASS+1));
        else echo "  FAIL  $3 (got '$1' want '$2')"; FAIL=$((FAIL+1)); fi; }
ckc() { if echo "$1" | grep -q "$2"; then echo "  PASS  $3"; PASS=$((PASS+1));
        else echo "  FAIL  $3"; FAIL=$((FAIL+1)); echo "    body: $(echo "$1" | head -c 300)"; fi; }

# login <jar> <user> <pass> -> echoes /api/me body (sets session cookie in jar)
login() {
  local J=$1 U=$2 P=$3
  rm -f "$J"
  curl -s -c "$J" "$B/login" >/dev/null
  local C
  C=$(curl -s -b "$J" "$B/login" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
  curl -s -b "$J" -c "$J" -o /dev/null -X POST "$B/login" \
    --data-urlencode "_csrf=$C" --data-urlencode "username=$U" --data-urlencode "password=$P"
  curl -s -b "$J" "$B/api/me"
}

finish() {
  echo ""
  echo "$1 RESULT: $PASS passed, $FAIL failed"
  exit $FAIL
}
