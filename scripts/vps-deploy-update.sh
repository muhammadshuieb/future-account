#!/usr/bin/env bash
set -euo pipefail
cd /opt/future-account
set -a
source .env.prod
set +a
export DEPLOY_ENV=prod
export DEPLOY_SKIP_BACKUP_REMINDER=1
export DEPLOY_SMOKE_LOGIN=1
./scripts/deploy.sh
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan db:seed --class=AdminUserSeeder --force --no-ansi
if [[ -x ./scripts/ensure-scheduler-cron.sh ]]; then
  bash ./scripts/ensure-scheduler-cron.sh || echo "WARN: could not ensure scheduler cron"
fi
curl -sf http://127.0.0.1:8080/ >/dev/null && echo "Frontend OK"
curl -sf http://127.0.0.1:8000/up >/dev/null && echo "Backend OK"
