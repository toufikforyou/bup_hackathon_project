# syntax=docker/dockerfile:1

FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --ignore-scripts
COPY resources ./resources
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

FROM dunglas/frankenphp:1-php8.4-alpine AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CACHE_STORE=file \
    SESSION_DRIVER=file \
    QUEUE_CONNECTION=sync \
    DB_CONNECTION=sqlite \
    SERVER_NAME=:8080 \
    GRIDWISE_LLM_DRIVER=gemini

RUN install-php-extensions opcache pcntl zip

WORKDIR /app

COPY --from=vendor /build/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build

RUN rm -rf ProblemStatement node_modules .git .env tests \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && mkdir -p storage/logs bootstrap/cache database \
    && touch database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD wget -qO- http://127.0.0.1:8080/health || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile"]
