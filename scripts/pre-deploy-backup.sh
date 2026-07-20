#!/usr/bin/env bash
# Optional hook: pg_dump backup before deploy (set RUN_PRE_DEPLOY_BACKUP=1 in deploy.sh).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

COMPOSE=(docker compose)
if [[ -f docker-compose.prod.yml ]]; then
  COMPOSE+=(-f docker-compose.yml -f docker-compose.prod.yml)
fi

STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="${DEPLOY_BACKUP_DIR:-$PROJECT_ROOT/backups}"
mkdir -p "$BACKUP_DIR"
OUT="$BACKUP_DIR/pre_deploy_${STAMP}.dump"

echo "Creating pre-deploy pg_dump -> $OUT"
"${COMPOSE[@]}" exec -T postgres pg_dump -U future -Fc future_account > "$OUT"
echo "Backup saved ($(du -h "$OUT" | cut -f1))."
