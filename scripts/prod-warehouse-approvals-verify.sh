#!/usr/bin/env bash
# Read-only smoke check that the warehouse approval API and UI are live.
set -euo pipefail
cd /opt/future-account

API=http://127.0.0.1:8000
WEB=http://127.0.0.1:8080

TOKEN="$(curl -s -X POST "$API/api/auth/login" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"username\":\"${ADMIN_USER:-admin}\",\"password\":\"${ADMIN_PASS:-password}\"}" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); print((d.get("data") or {}).get("token") or d.get("token") or "")')"

if [[ -z "$TOKEN" ]]; then
  echo "LOGIN FAILED"
  exit 1
fi
echo "login OK"

echo -n "GET /api/warehouse-approvals -> "
curl -s -o /tmp/wa.json -w '%{http_code}\n' "$API/api/warehouse-approvals" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
head -c 300 /tmp/wa.json; echo

echo "--- /api/auth/me ---"
curl -s "$API/api/auth/me" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | head -c 1200; echo

echo -n "users payload exposes warehouse_ids: "
curl -s "$API/api/users" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  | python3 -c 'import json,sys; d=json.load(sys.stdin); rows=d.get("data") or []; print("yes" if rows and "warehouse_ids" in rows[0] else "no")'

echo -n "roles exposed by API: "
curl -s "$API/api/roles" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; print(", ".join(r["name"] for r in d["roles"]))'

echo -n "frontend bundle contains approvals route: "
INDEX="$(curl -s "$WEB/")"
ASSET="$(printf '%s' "$INDEX" | grep -o '/assets/index-[A-Za-z0-9_-]*\.js' | head -1)"
if curl -s "$WEB$ASSET" | grep -q 'warehouse-approvals'; then echo "yes ($ASSET)"; else echo "no ($ASSET)"; fi

curl -s -o /dev/null -w 'public site: %{http_code}\n' https://synaacc.cloud/
curl -s -o /dev/null -w 'public backend health: %{http_code}\n' https://synaacc.cloud/up
