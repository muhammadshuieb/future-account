#!/usr/bin/env bash
set -euo pipefail
cd /opt/future-account

IP="187.124.31.86"
sed -i "s|^APP_URL=.*|APP_URL=http://${IP}:8080|" .env.prod
sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=http://${IP}:8080|" .env.prod
sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${IP},synaacc.cloud,www.synaacc.cloud|" .env.prod

set -a
source .env.prod
set +a

docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --force-recreate backend

echo "Waiting for backend..."
for i in $(seq 1 30); do
  if docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan --version >/dev/null 2>&1; then
    echo "Backend ready"
    break
  fi
  sleep 2
done

docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan migrate --force --no-ansi

USER_COUNT=$(docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan tinker --execute="echo \\App\\Models\\User::count();" 2>/dev/null | tr -d '\r\n' || echo "0")
if [[ "${USER_COUNT:-0}" == "0" ]]; then
  docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan db:seed --class=AdminUserSeeder --force --no-ansi
fi

curl -sf "http://127.0.0.1:8000/up" && echo " Backend OK"
curl -sf "http://127.0.0.1:8080/" >/dev/null && echo "Frontend OK"
HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "http://127.0.0.1:8000/api/login" -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"email":"admin@future-account.test","password":"password"}')
echo "Login HTTP: ${HTTP_CODE}"
