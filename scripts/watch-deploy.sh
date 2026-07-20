#!/usr/bin/env bash
# Cron-friendly: pull and deploy only when origin/main has new commits.
# Example crontab (every 5 minutes):
#   */5 * * * * cd /opt/future-account && ./scripts/watch-deploy.sh >> /var/log/future-account-deploy.log 2>&1
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

BRANCH="${DEPLOY_BRANCH:-main}"

git fetch origin "$BRANCH" --quiet

LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse "origin/$BRANCH")"

if [[ "$LOCAL" == "$REMOTE" ]]; then
  echo "$(date -Is) No changes on origin/$BRANCH — skip deploy."
  exit 0
fi

echo "$(date -Is) New commits on origin/$BRANCH ($LOCAL -> $REMOTE) — deploying..."
exec "$SCRIPT_DIR/deploy.sh"
