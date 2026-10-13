#!/usr/bin/env bash
# Build retained SynaAcc source zip (code + INSTALL-AR.md), excluding secrets and deps.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
OUT="${1:-$PROJECT_ROOT/storage-source-archive/03_كود-البرمجة-وطريقة-التثبيت_SynaAcc.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$(dirname "$OUT")"

has_zip=0
has_python=0
command -v zip >/dev/null 2>&1 && has_zip=1
command -v python3 >/dev/null 2>&1 && has_python=1
if [[ "$has_zip" -eq 0 && "$has_python" -eq 0 ]]; then
  echo "Need zip or python3 to build the source archive" >&2
  exit 1
fi

mkdir -p "$STAGE/SynaAcc"
if command -v rsync >/dev/null 2>&1; then
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
else
  tar -C "$PROJECT_ROOT" \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='frontend/dist' \
    --exclude='backend/storage/logs' \
    --exclude='backend/storage/framework' \
    --exclude='backend/storage/app/backups' \
    --exclude='backend/storage/app/backup-uploads' \
    --exclude='.env' \
    --exclude='.env.prod' \
    --exclude='.env.local' \
    --exclude='.idea' \
    --exclude='.vscode' \
    --exclude='.cursor' \
    --exclude='agent-transcripts' \
    --exclude='terminals' \
    -cf - . | tar -C "$STAGE/SynaAcc" -xf -
fi

if [[ -f "$PROJECT_ROOT/docs/INSTALL-AR.md" ]]; then
  cp "$PROJECT_ROOT/docs/INSTALL-AR.md" "$STAGE/INSTALL-AR.md"
fi

rm -f "$OUT"
if [[ "$has_zip" -eq 1 ]]; then
  (cd "$STAGE" && zip -qr "$OUT" .)
else
  python3 - "$STAGE" "$OUT" <<'PY'
import os, sys, zipfile
stage, out = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(stage):
        dirs[:] = [d for d in dirs if d not in {".git", "node_modules", "vendor"}]
        for name in files:
            path = os.path.join(root, name)
            arc = os.path.relpath(path, stage).replace("\\", "/")
            zf.write(path, arc)
PY
fi

echo "Wrote $OUT ($(du -h "$OUT" | awk '{print $1}'))"
