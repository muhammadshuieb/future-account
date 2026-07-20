#!/usr/bin/env bash
set -euo pipefail
cd /opt/future-account
set -a
source .env.prod
set +a

docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-ansi
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan db:seed --class=AdminUserSeeder --force --no-ansi

echo "==> Login test via frontend proxy..."
curl -s -X POST "http://127.0.0.1:8080/api/auth/login" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"admin@future-account.test","password":"password"}'
