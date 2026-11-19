# Image of the API: FrankenPHP in classic mode (no worker), one container without nginx. The same image
# runs the queue workers and the outbox relay (compose services worker and relay).
#
# Two targets. production: what the API, the workers and the relay need. development (the one compose
# builds): production plus php.ini-development and the tools of the local checks.
#
# redis is the client of the queue, the cache and the sessions. pcntl stays in production: queue:work
# needs it to enforce --timeout and to stop gracefully on SIGTERM, and the outbox relay to stop between
# batches; billing:stress and payments:check also use it to fork, and are development tools.
FROM dunglas/frankenphp:1-php8.5-alpine AS production

RUN install-php-extensions pdo_mysql intl zip pcntl redis \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && chmod -R a+rwX /config /data

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The container runs with the host user id, which has no home directory in the image.
ENV SERVER_NAME=:8000 \
    HOME=/tmp

WORKDIR /app

EXPOSE 8000

# Development only: xmlsec1 lets dian:check verify XML signatures with a tool independent of our code;
# poppler-utils (pdfinfo, pdftotext) lets delivery:check read the PDF drawn by dompdf with a tool
# independent of the one that wrote it.
FROM production AS development

RUN apk add --no-cache xmlsec poppler-utils \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
