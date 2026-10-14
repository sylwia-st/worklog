# syntax=docker/dockerfile:1

# --- wspólna baza: PHP 8.5 (pdo_sqlite, mbstring, ctype, xml i curl są wbudowane w oficjalny obraz) ---
FROM php:8.5-cli-alpine AS base
RUN apk add --no-cache tzdata \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-worklog.ini"
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    TZ=Europe/Warsaw \
    APP_TIMEZONE=Europe/Warsaw \
    DATABASE_URL="sqlite:////data/worklog.db" \
    WORKLOG_LISTEN=0.0.0.0:8080
WORKDIR /app

# --- zależności Composera (tylko w czasie budowania; composer nie trafia do obrazu końcowego) ---
FROM base AS vendor
RUN apk add --no-cache git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-autoloader --no-scripts --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# --- obraz końcowy ---
FROM base
RUN addgroup -g 1000 app \
 && adduser -D -H -u 1000 -G app app \
 && mkdir -p /data /app/var \
 && chown app:app /data /app/var
COPY --from=vendor --chown=app:app /app /app
# CRLF w skrypcie startowym (kopia rozpakowana/edytowana na Windowsie) dawałby niejasny błąd „no such file or directory”
RUN sed -i 's/\r$//' /app/docker/entrypoint.sh \
 && chmod +x /app/docker/entrypoint.sh /app/bin/console
USER app
RUN APP_SECRET=build-only php bin/console cache:warmup --no-interaction
# wersja w stopce strony – podaje ją ./worklog up (commit i data); puste przy budowaniu bez gita
ARG WORKLOG_VERSION=""
ENV WORKLOG_VERSION=$WORKLOG_VERSION

VOLUME ["/data"]
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null "http://127.0.0.1:${WORKLOG_LISTEN##*:}/" || exit 1
ENTRYPOINT ["/app/docker/entrypoint.sh"]
