FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    bash \
    curl \
    git \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    unzip \
    zip \
    && docker-php-ext-install intl mbstring pdo pdo_pgsql opcache zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/staging/entrypoint.sh /usr/local/bin/staging-entrypoint
COPY docker/staging/worker-entrypoint.sh /usr/local/bin/staging-worker
RUN chmod +x /usr/local/bin/staging-entrypoint /usr/local/bin/staging-worker

CMD ["php-fpm"]

