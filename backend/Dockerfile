# Development image for the API: FrankenPHP in classic mode (no worker), one container without nginx.
# The same image runs the queue workers and the outbox relay (compose services worker and relay).
# redis is the client of the queue, the cache and the sessions.
# pcntl lets the billing:stress tool issue invoices from truly parallel processes, and lets the
# queue worker and the relay stop gracefully and enforce job timeouts.
# xmlsec1 lets dian:check verify XML signatures with a tool independent of our code.
# poppler-utils (pdfinfo, pdftotext) lets delivery:check read the invoice PDF, drawn by dompdf, with a
# tool independent of the one that wrote it.
FROM dunglas/frankenphp:1-php8.5-alpine

RUN install-php-extensions pdo_mysql intl zip pcntl redis \
    && apk add --no-cache xmlsec poppler-utils \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && chmod -R a+rwX /config /data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The container runs with the host user id, which has no home directory in the image.
ENV SERVER_NAME=:8000 \
    HOME=/tmp

WORKDIR /app

EXPOSE 8000
