#!/usr/bin/env bash
# Link a domain to Future Account on the VPS (nginx reverse proxy + SSL + env).
# Run on the server as root after DNS A/CNAME records point to this VPS.
#
# Usage:
#   DOMAIN=synaacc.cloud VPS_IP=187.124.31.86 ./scripts/vps-domain-setup.sh
set -euo pipefail

DOMAIN="${DOMAIN:-synaacc.cloud}"
VPS_IP="${VPS_IP:-187.124.31.86}"
DEPLOY_PATH="${DEPLOY_PATH:-/opt/future-account}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"

export DEBIAN_FRONTEND=noninteractive

log() { printf '==> %s\n' "$*"; }

log "Installing nginx and certbot..."
apt-get update -qq
apt-get install -y -qq nginx certbot python3-certbot-nginx

log "Writing nginx site for ${DOMAIN}..."
install -d /etc/nginx/sites-available /etc/nginx/sites-enabled
sed "s/synaacc.cloud/${DOMAIN}/g" "${DEPLOY_PATH}/docker/nginx/synaacc.cloud.conf" \
  > "/etc/nginx/sites-available/${DOMAIN}"
ln -sf "/etc/nginx/sites-available/${DOMAIN}" "/etc/nginx/sites-enabled/${DOMAIN}"
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl enable nginx
systemctl restart nginx

log "Updating .env.prod for ${DOMAIN}..."
cd "$DEPLOY_PATH"
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env.prod
sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=https://${DOMAIN}|" .env.prod
sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${DOMAIN},www.${DOMAIN},${VPS_IP}|" .env.prod

set -a
# shellcheck disable=SC1091
source .env.prod
set +a

docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.prod up -d --force-recreate backend frontend

log "Waiting for services..."
for _ in $(seq 1 30); do
  curl -sf "http://127.0.0.1:8080/" >/dev/null && curl -sf "http://127.0.0.1:8000/up" >/dev/null && break
  sleep 2
done

log "Requesting Let's Encrypt certificate..."
CERTBOT_ARGS=(--nginx -d "$DOMAIN" -d "www.${DOMAIN}" --non-interactive --agree-tos --redirect)
if [[ -n "$CERTBOT_EMAIL" ]]; then
  CERTBOT_ARGS+=(--email "$CERTBOT_EMAIL")
else
  CERTBOT_ARGS+=(--register-unsafely-without-email)
fi
certbot "${CERTBOT_ARGS[@]}"

log "Verification:"
curl -sfI "https://${DOMAIN}/" | head -1 || true
curl -sf "http://127.0.0.1:8000/up" >/dev/null && log "Backend health OK"
curl -sf "http://127.0.0.1:8080/" >/dev/null && log "Frontend OK"

log "Done. Open https://${DOMAIN} in your browser."
