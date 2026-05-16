#!/bin/bash
# Orchestrates the full e2e regression. Requires the app server to be running
# at $BASE_URL (default http://127.0.0.1:8080) against the configured DB.
#
# DB is reset between isolation groups for determinism:
#   group A: base -> finance (finance depends on base's reconciliation)
#   group B: bankrecon (independent)
#   group C: supply (independent)
#   QR encoder unit test (no DB / no server)
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
. "$DIR/_env.sh"

echo "== e2e regression =="
echo "Target: $B  DB=$DB_USER@$DB_HOST:$DB_PORT/$DB_NAME"

# Wait for the server (up to ~30s).
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$B/login"; then break; fi
  [ "$i" = "30" ] && { echo "Server not reachable at $B"; exit 1; }
  sleep 1
done

reset_db() {
  php "$ROOT/bin/install.php" --fresh >/dev/null 2>&1 || { echo "DB reset failed"; exit 1; }
}

fails=0
run() { echo ""; echo ">>> $1"; bash "$DIR/$1" || fails=$((fails+1)); }

reset_db; run base.sh;      run finance.sh
reset_db; run bankrecon.sh
reset_db; run supply.sh
reset_db; run einvoice.sh
reset_db; run inventory.sh
reset_db; run corrections.sh
reset_db; run admin.sh
reset_db; run import.sh
reset_db; run period.sh

echo ""; echo ">>> qr_test.php"
php "$ROOT/tests/qr_test.php" || fails=$((fails+1))

echo ""
if [ "$fails" -eq 0 ]; then
  echo "ALL E2E SUITES PASSED"
  exit 0
fi
echo "E2E FAILED: $fails suite(s) had failures"
exit 1
