#!/usr/bin/env bash
# Set Google Drive OAuth client credentials on the VPS (.env.prod) and recreate backend.
# Usage (on the server):
#   ./scripts/set-google-drive-oauth.sh 'CLIENT_ID' 'CLIENT_SECRET'
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

CLIENT_ID="${1:-}"
CLIENT_SECRET="${2:-}"
ENV_FILE="$PROJECT_ROOT/.env.prod"

if [[ -z "$CLIENT_ID" || -z "$CLIENT_SECRET" ]]; then
  echo "Usage: $0 <GOOGLE_DRIVE_OAUTH_CLIENT_ID> <GOOGLE_DRIVE_OAUTH_CLIENT_SECRET>" >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

APP_URL="$(grep -E '^APP_URL=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
APP_URL="${APP_URL:-https://synaacc.cloud}"
APP_URL="${APP_URL%/}"
REDIRECT="${APP_URL}/api/backups/destinations/google-drive/callback"

upsert() {
  local key="$1"
  local value="$2"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    # portable in-place replace without leaking value in process list longer than needed
    local tmp
    tmp="$(mktemp)"
    awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k{$0=k"="v} {print}' "$ENV_FILE" > "$tmp"
    mv "$tmp" "$ENV_FILE"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}

upsert "GOOGLE_DRIVE_OAUTH_CLIENT_ID" "$CLIENT_ID"
upsert "GOOGLE_DRIVE_OAUTH_CLIENT_SECRET" "$CLIENT_SECRET"
upsert "GOOGLE_DRIVE_OAUTH_REDIRECT_URI" "$REDIRECT"

echo "Updated .env.prod OAuth keys. Redirect URI: $REDIRECT"
echo "Recreating backend..."

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.prod)
"${COMPOSE[@]}" up -d --force-recreate backend

echo "Done. Open Settings → Backup → Connect Google Drive."
echo "Authorized redirect URI to paste in Google Cloud:"
echo "  $REDIRECT"
