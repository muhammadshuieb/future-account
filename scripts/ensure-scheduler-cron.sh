#!/usr/bin/env bash
# Ensure Laravel scheduler cron exists on the VPS host via /etc/cron.d
# (works even when the crontab CLI package is missing).
# Safe to re-run; idempotent.
set -euo pipefail

CRON_FILE=/etc/cron.d/syna-scheduler
MARKER='syna-scheduler'

if [[ -f "$CRON_FILE" ]] && grep -qF "$MARKER" "$CRON_FILE"; then
  echo "Scheduler cron already installed ($CRON_FILE)."
  exit 0
fi

# Prefer cron package when available
if ! command -v cron >/dev/null 2>&1 && ! systemctl list-unit-files 2>/dev/null | grep -q '^cron'; then
  apt-get update -qq >/dev/null 2>&1 || true
  apt-get install -y cron >/dev/null 2>&1 || true
  systemctl enable --now cron 2>/dev/null || true
fi

cat > "$CRON_FILE" <<'EOF'
# syna-scheduler — Syna Co Laravel schedule:run (twice-daily backup + health checks)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root cd /opt/future-account && /usr/bin/docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.prod exec -T backend php artisan schedule:run >> /var/log/syna-scheduler.log 2>&1
EOF
chmod 644 "$CRON_FILE"
echo "Installed scheduler cron ($CRON_FILE)."
