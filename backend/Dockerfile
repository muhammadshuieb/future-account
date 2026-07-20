FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates gnupg libpq-dev libzip-dev \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/pgdg.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
    && docker-php-ext-install pdo_pgsql pgsql zip bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-scripts --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache storage/app/backups \
    && chmod -R ug+rwx storage bootstrap/cache \
    && rm -f .env

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
# --no-reload: pass full process env to the PHP built-in server child.
# Entrypoint also writes .env from Docker env (serve otherwise drops DB_*).
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
