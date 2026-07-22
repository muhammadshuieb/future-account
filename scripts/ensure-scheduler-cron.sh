#!/usr/bin/env bash
# Ensure Laravel scheduler cron exists on the VPS host.
# Safe to re-run; idempotent.
set -euo pipefail

CRON_LINE='* * * * * cd /opt/future-account && docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T backend php artisan schedule:run >> /var/log/syna-scheduler.log 2>&1'
MARKER='syna-scheduler'

if crontab -l 2>/dev/null | grep -qF "$MARKER"; then
  echo "Scheduler cron already installed ($MARKER)."
  exit 0
fi

TMP=$(mktemp)
crontab -l 2>/dev/null > "$TMP" || true
echo "# $MARKER — Syna Co Laravel schedule:run (twice-daily backup + health checks)" >> "$TMP"
echo "$CRON_LINE" >> "$TMP"
crontab "$TMP"
rm -f "$TMP"
echo "Installed scheduler cron ($MARKER)."
