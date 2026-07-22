#!/usr/bin/env bash
set -euo pipefail
cd /opt/future-account

set -a
# shellcheck disable=SC1091
source .env.prod
set +a

chmod +x scripts/*.sh
export DEPLOY_ENV=prod
export DEPLOY_SKIP_BACKUP_REMINDER=1
export DEPLOY_SMOKE_LOGIN=1
./scripts/deploy.sh

echo "==> Health checks..."
curl -sf "http://127.0.0.1:8080/" >/dev/null && echo "Frontend OK" || echo "Frontend FAILED"
curl -sf "http://127.0.0.1:8000/up" >/dev/null && echo "Backend OK" || echo "Backend FAILED"

HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' \
  -X POST "http://127.0.0.1:8000/api/auth/login" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"username":"admin","password":"password"}' || true)
echo "Login endpoint HTTP: ${HTTP_CODE}"
