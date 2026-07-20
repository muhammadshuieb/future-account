#!/usr/bin/env bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

echo "==> Updating packages..."
apt-get update -qq

echo "==> Installing Docker, git, ufw..."
apt-get install -y -qq git curl ca-certificates docker.io ufw

if ! docker compose version >/dev/null 2>&1; then
  echo "==> Installing Docker Compose plugin..."
  COMPOSE_VERSION="v2.27.1"
  mkdir -p /usr/lib/docker/cli-plugins
  curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-x86_64" \
    -o /usr/lib/docker/cli-plugins/docker-compose
  chmod +x /usr/lib/docker/cli-plugins/docker-compose
fi

echo "==> Enabling Docker..."
systemctl enable docker
systemctl start docker

echo "==> Docker versions:"
docker --version
docker compose version

echo "==> Configuring firewall..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8080/tcp
ufw allow 8000/tcp
ufw --force enable

echo "==> Cloning repository..."
DEPLOY_PATH="/opt/future-account"
if [[ -d "$DEPLOY_PATH/.git" ]]; then
  cd "$DEPLOY_PATH"
  git fetch origin main
  git checkout main
  git pull --ff-only origin main
else
  rm -rf "$DEPLOY_PATH"
  git clone https://github.com/muhammadshuieb/future-account.git "$DEPLOY_PATH"
  cd "$DEPLOY_PATH"
fi

echo "==> Setting up production env..."
chmod +x scripts/*.sh

if [[ ! -f .env.prod ]]; then
  cp .env.prod.example .env.prod
fi

# Generate secrets if placeholders remain
POSTGRES_PW="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
APP_KEY_VAL="base64:$(openssl rand -base64 32)"
VPS_IP="187.124.31.86"

sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY_VAL}|" .env.prod
sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=${POSTGRES_PW}|" .env.prod
sed -i "s|^APP_URL=.*|APP_URL=http://${VPS_IP}:8080|" .env.prod
sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=http://${VPS_IP}:8080|" .env.prod
sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${VPS_IP},synaacc.cloud,www.synaacc.cloud|" .env.prod
sed -i "s|^FRONTEND_PORT=.*|FRONTEND_PORT=8080|" .env.prod
sed -i "s|^BACKEND_PORT=.*|BACKEND_PORT=8000|" .env.prod

echo "==> Running deploy..."
set -a
# shellcheck disable=SC1091
source .env.prod
set +a
export DEPLOY_ENV=prod
export DEPLOY_SKIP_BACKUP_REMINDER=1
export DEPLOY_SMOKE_LOGIN=1
./scripts/deploy.sh

echo "==> Bootstrap complete."
curl -sf "http://127.0.0.1:8080/" >/dev/null && echo "Frontend OK" || echo "Frontend check failed"
curl -sf "http://127.0.0.1:8000/up" >/dev/null && echo "Backend OK" || echo "Backend check failed"
