# Development image for the API: FrankenPHP in classic mode (no worker), one container without nginx.
# pcntl lets the billing:stress tool issue invoices from truly parallel processes.
FROM dunglas/frankenphp:1-php8.5-alpine

RUN install-php-extensions pdo_mysql intl zip pcntl \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && chmod -R a+rwX /config /data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The container runs with the host user id, which has no home directory in the image.
ENV SERVER_NAME=:8000 \
    HOME=/tmp

WORKDIR /app

EXPOSE 8000
