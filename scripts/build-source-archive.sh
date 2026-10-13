#!/usr/bin/env bash
# Build retained SynaAcc source zip (code + INSTALL-AR.md), excluding secrets and deps.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUT="${1:-$PROJECT_ROOT/storage-source-archive/03_كود-البرمجة-وطريقة-التثبيت_SynaAcc.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$(dirname "$OUT")"

# Prefer zip; fall back to tar.gz renamed if zip missing (Telegram expects .zip).
if ! command -v zip >/dev/null 2>&1; then
  echo "zip is required to build the source archive" >&2
  exit 1
fi

rsync -a \
  --exclude '.git/' \
  --exclude 'node_modules/' \
  --exclude 'vendor/' \
  --exclude 'frontend/dist/' \
  --exclude 'frontend/node_modules/' \
  --exclude 'backend/vendor/' \
  --exclude 'backend/node_modules/' \
  --exclude 'backend/storage/logs/' \
  --exclude 'backend/storage/framework/' \
  --exclude 'backend/storage/app/backups/' \
  --exclude 'backend/storage/app/backup-uploads/' \
  --exclude 'backend/bootstrap/cache/*.php' \
  --exclude '.env' \
  --exclude '.env.prod' \
  --exclude '.env.local' \
  --exclude '.idea/' \
  --exclude '.vscode/' \
  --exclude '.cursor/' \
  --exclude 'scripts/_tmp-*' \
  --exclude '*.dump' \
  --exclude 'company-stamp.tmp.png' \
  --exclude 'agent-transcripts/' \
  --exclude 'terminals/' \
  "$PROJECT_ROOT/" "$STAGE/SynaAcc/"

if [[ -f "$PROJECT_ROOT/docs/INSTALL-AR.md" ]]; then
  cp "$PROJECT_ROOT/docs/INSTALL-AR.md" "$STAGE/INSTALL-AR.md"
fi

rm -f "$OUT"
(
  cd "$STAGE"
  zip -qr "$OUT" .
)

echo "Wrote $OUT ($(du -h "$OUT" | awk '{print $1}'))"
