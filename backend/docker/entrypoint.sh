#!/usr/bin/env sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  echo "Installing Composer dependencies..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# AES-256-CBC requires APP_KEY = "base64:" + base64(exactly 32 raw bytes).
ensure_app_key() {
  key="${APP_KEY:-}"
  if [ -z "$key" ]; then
    echo "APP_KEY is missing; generating a temporary key for this container..."
    export APP_KEY="$(php artisan key:generate --show --no-ansi)"
    return
  fi

  php -r '
    $key = getenv("APP_KEY") ?: "";
    if (!str_starts_with($key, "base64:")) {
      fwrite(STDERR, "APP_KEY must use the base64: prefix.\n");
      exit(1);
    }
    $raw = base64_decode(substr($key, 7), true);
    if ($raw === false || strlen($raw) !== 32) {
      fwrite(STDERR, "APP_KEY has incorrect length (need 32 decoded bytes for AES-256-CBC).\n");
      exit(1);
    }
  ' || {
    echo "Invalid APP_KEY detected; generating a temporary key for this container..."
    export APP_KEY="$(php artisan key:generate --show --no-ansi)"
  }
}

# `php artisan serve` only passes a small env allowlist to its PHP child when a
# .env file exists; the child then loads .env. Keep .env aligned with Docker
# compose environment so DB_HOST/APP_KEY are correct for HTTP requests.
write_dotenv_from_environ() {
  cat > .env <<EOF
APP_NAME="${APP_NAME:-Syna Co}"
APP_ENV="${APP_ENV:-local}"
APP_KEY="${APP_KEY}"
APP_DEBUG="${APP_DEBUG:-true}"
APP_URL="${APP_URL:-http://localhost:8000}"
APP_TIMEZONE="${APP_TIMEZONE:-Asia/Damascus}"
APP_LOCALE="${APP_LOCALE:-ar}"
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=ar_SA
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL="${LOG_CHANNEL:-stderr}"
LOG_LEVEL=debug
DB_CONNECTION="${DB_CONNECTION:-pgsql}"
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-future_account}"
DB_USERNAME="${DB_USERNAME:-future}"
DB_PASSWORD="${DB_PASSWORD:-secret}"
SESSION_DRIVER="${SESSION_DRIVER:-database}"
SESSION_LIFETIME=120
CACHE_STORE="${CACHE_STORE:-database}"
QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
SANCTUM_STATEFUL_DOMAINS="${SANCTUM_STATEFUL_DOMAINS:-localhost,localhost:8080,127.0.0.1,127.0.0.1:8080}"
FRONTEND_URL="${FRONTEND_URL:-http://localhost:8080}"
BACKUP_PATH="${BACKUP_PATH:-/var/www/html/storage/app/backups}"
EOF
}

ensure_app_key
write_dotenv_from_environ

echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
i=0
until php -r "try { new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:'5432').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0);} catch (Throwable \$e) { fwrite(STDERR, \$e->getMessage().PHP_EOL); exit(1);}"; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "PostgreSQL did not become ready in time."
    exit 1
  fi
  sleep 2
done
echo "PostgreSQL is ready."

php artisan config:clear --no-ansi || true
php artisan migrate --force --no-ansi

if [ "${SKIP_DB_SEED:-0}" = "1" ]; then
  echo "SKIP_DB_SEED=1 — skipping demo seeders (production / deploy mode)."
else
  php artisan db:seed --force --no-ansi
  echo "Ensuring demo admin account..."
  php artisan db:seed --class=AdminUserSeeder --force --no-ansi
fi

exec "$@"
