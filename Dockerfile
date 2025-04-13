FROM composer:2.8 AS composer_runner
WORKDIR /build
COPY composer.json composer.lock* ./
RUN composer install --optimize-autoloader --no-interaction --no-progress --no-scripts

FROM php:8.4-cli

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) intl zip sockets bcmath

COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

RUN groupadd --force -g $APP_GID appgroup || true \
    && useradd -u $APP_UID -g $APP_GID -m -s /bin/bash appuser

COPY --from=composer_runner /build/vendor/ ./vendor/

COPY --chown=appuser:appgroup config ./config
COPY --chown=appuser:appgroup .env.example ./
COPY --chown=appuser:appgroup app ./app
COPY --chown=appuser:appgroup app.php ./
COPY --chown=appuser:appgroup composer.json ./

RUN mkdir -p /app/cache && chown appuser:appgroup /app/cache

USER appuser

CMD ["php", "app.php"]