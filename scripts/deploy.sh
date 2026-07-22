#!/usr/bin/env bash
# Deploy Future Account on a server (VPS / Linux).
# Called manually or by GitHub Actions over SSH.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

DEPLOY_ENV="${DEPLOY_ENV:-prod}"
BRANCH="${DEPLOY_BRANCH:-main}"
COMPOSE=(docker compose)
if [[ "$DEPLOY_ENV" == "prod" && -f docker-compose.prod.yml ]]; then
  COMPOSE+=(-f docker-compose.yml -f docker-compose.prod.yml)
  if [[ -f .env.prod ]]; then
    COMPOSE+=(--env-file .env.prod)
  fi
fi

log() { printf '==> %s\n' "$*"; }

log "Future Account deploy (env=$DEPLOY_ENV, branch=$BRANCH)"

if [[ "${DEPLOY_SKIP_BACKUP_REMINDER:-0}" != "1" ]]; then
  log "Reminder: create a DB backup from Settings → Backup before major upgrades."
  if [[ -x "$SCRIPT_DIR/pre-deploy-backup.sh" && "${RUN_PRE_DEPLOY_BACKUP:-0}" == "1" ]]; then
    log "Running optional pre-deploy backup hook..."
    bash "$SCRIPT_DIR/pre-deploy-backup.sh" || log "Pre-deploy backup hook failed (continuing)."
  fi
fi

log "Pulling latest from origin/$BRANCH..."
git fetch origin "$BRANCH"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"

log "Building containers..."
"${COMPOSE[@]}" build

log "Starting containers..."
"${COMPOSE[@]}" up -d

log "Waiting for backend..."
for _ in $(seq 1 30); do
  if "${COMPOSE[@]}" exec -T backend php artisan --version >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

log "Running migrations..."
"${COMPOSE[@]}" exec -T backend php artisan migrate --force --no-ansi

log "Checking whether admin user seed is needed..."
USER_COUNT="$("${COMPOSE[@]}" exec -T backend php artisan tinker --execute="echo \\App\\Models\\User::count();" 2>/dev/null | tr -d '\r\n' || echo "0")"
if [[ "${USER_COUNT:-0}" == "0" ]]; then
  log "No users in database — running AdminUserSeeder..."
  "${COMPOSE[@]}" exec -T backend php artisan db:seed --class=AdminUserSeeder --force --no-ansi
else
  log "Users exist ($USER_COUNT) — skipping AdminUserSeeder."
fi

BACKEND_PORT="${BACKEND_HEALTH_PORT:-8000}"
FRONTEND_PORT="${FRONTEND_HEALTH_PORT:-8080}"

log "Health check (backend /up)..."
if curl -sf "http://127.0.0.1:${BACKEND_PORT}/up" >/dev/null; then
  log "Backend health OK."
else
  log "WARN: Backend health check failed on port ${BACKEND_PORT}."
fi

if [[ "${DEPLOY_SMOKE_LOGIN:-0}" == "1" ]]; then
  log "Smoke login check..."
  HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' \
    -X POST "http://127.0.0.1:${BACKEND_PORT}/api/auth/login" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '{"username":"admin","password":"password"}' || true)"
  if [[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "422" ]]; then
    log "API login endpoint reachable (HTTP $HTTP_CODE)."
  else
    log "WARN: Smoke login returned HTTP ${HTTP_CODE:-unknown}."
  fi
fi

log "Frontend check (port ${FRONTEND_PORT})..."
if curl -sf "http://127.0.0.1:${FRONTEND_PORT}/" >/dev/null; then
  log "Frontend reachable."
else
  log "WARN: Frontend check failed on port ${FRONTEND_PORT}."
fi

log "Deploy finished successfully."
